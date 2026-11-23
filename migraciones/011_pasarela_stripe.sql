-- =====================================================================
-- 011 — Pasarela de pago (Stripe)
-- ---------------------------------------------------------------------
-- Permite que un cliente pague desde fuera —con una tarjeta de otro
-- banco— su cuota de crédito o el saldo de su tarjeta.
--
-- Dos decisiones que conviene dejar explicadas:
--
-- 1. El dinero no entra directo a la cuenta del cliente. Stripe
--    confirma el cobro en el momento pero liquida días después, así que
--    hay un periodo en que el banco ya abonó al cliente y todavía no ha
--    recibido los fondos. Ese desfase vive en una cuenta técnica
--    —"Stripe en tránsito"— en lugar de quedar invisible. Es lo que
--    permite cuadrar después contra lo que Stripe deposita de verdad.
--
-- 2. Cada intento de pago se registra aquí antes de hablar con Stripe.
--    Si la respuesta se pierde —se cae la red, el cliente cierra el
--    navegador— queda constancia de que ese intento existió, y el
--    webhook lo encuentra por su identificador en vez de crear un cobro
--    huérfano.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Cuenta técnica de la pasarela
-- ---------------------------------------------------------------------

INSERT IGNORE INTO productos (codigo, nombre, tipo, descripcion, tasa_interes_bp, saldo_minimo_cent, activo)
VALUES ('TEC-PASARELA', 'Cuenta técnica de pasarela', 'corriente',
        'Fondos cobrados por la pasarela pendientes de liquidación', 0, 0, 1);

INSERT IGNORE INTO cuentas
    (uuid, numero_cuenta, usuario_id, producto_id, saldo_cent, saldo_retenido_cent,
     moneda, estado, es_tecnica, apertura_en)
SELECT UUID(), '0000000001',
       (SELECT id FROM usuarios WHERE rol_id = 1 ORDER BY id LIMIT 1),
       (SELECT id FROM productos WHERE codigo = 'TEC-PASARELA'),
       0, 0, 'USD', 'activa', 1, NOW()
 WHERE NOT EXISTS (SELECT 1 FROM cuentas WHERE numero_cuenta = '0000000001');

-- ---------------------------------------------------------------------
-- Intentos de pago
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pagos_pasarela (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)        NOT NULL,
    usuario_id      INT(10) UNSIGNED NOT NULL,

    -- Qué se está pagando.
    concepto        ENUM('cuota_credito','saldo_tarjeta','deposito') NOT NULL,
    credito_id      INT(10) UNSIGNED NULL,
    cuota_id        BIGINT UNSIGNED  NULL,
    tarjeta_id      INT(10) UNSIGNED NULL,
    cuenta_destino_id INT(10) UNSIGNED NULL,

    importe_cent    BIGINT UNSIGNED NOT NULL,
    moneda          CHAR(3)         NOT NULL DEFAULT 'USD',

    -- Lo que devuelve la pasarela.
    pasarela        VARCHAR(20)     NOT NULL DEFAULT 'stripe',
    referencia      VARCHAR(255)    NULL COMMENT 'PaymentIntent id (pi_...)',
    secreto_cliente VARCHAR(255)    NULL COMMENT 'client_secret, solo para el navegador',

    estado          ENUM('iniciado','procesando','cobrado','fallido','cancelado','devuelto')
                    NOT NULL DEFAULT 'iniciado',
    detalle_error   VARCHAR(500)    NULL,

    -- Asiento contable, cuando el cobro se confirma.
    asiento_id      BIGINT UNSIGNED NULL,

    -- Trazabilidad.
    ip              VARBINARY(16)   NULL,
    creado_en       DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    confirmado_en   DATETIME        NULL,
    actualizado_en  DATETIME        NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_pago_uuid (uuid),
    -- La referencia de la pasarela es única: evita contabilizar dos
    -- veces el mismo cobro si el webhook llega repetido, que Stripe
    -- advierte expresamente que puede pasar.
    UNIQUE KEY uq_pago_referencia (referencia),
    KEY idx_pago_usuario (usuario_id),
    KEY idx_pago_estado (estado),

    CONSTRAINT fk_pago_usuario  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_pago_credito  FOREIGN KEY (credito_id) REFERENCES creditos(id) ON DELETE SET NULL,
    CONSTRAINT fk_pago_cuota    FOREIGN KEY (cuota_id)   REFERENCES cuotas(id)   ON DELETE SET NULL,
    CONSTRAINT fk_pago_tarjeta  FOREIGN KEY (tarjeta_id) REFERENCES tarjetas(id) ON DELETE SET NULL,
    CONSTRAINT fk_pago_cuenta   FOREIGN KEY (cuenta_destino_id) REFERENCES cuentas(id) ON DELETE SET NULL,
    CONSTRAINT fk_pago_asiento  FOREIGN KEY (asiento_id) REFERENCES asientos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Eventos recibidos de la pasarela
-- ---------------------------------------------------------------------
-- Stripe reenvía un evento hasta que el servidor responde 2xx, y puede
-- entregar el mismo más de una vez aunque ya se haya respondido bien.
-- Guardar su identificador es lo que permite reconocerlo y no repetir
-- el asiento contable.

CREATE TABLE IF NOT EXISTS pasarela_eventos (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    evento_id     VARCHAR(255)    NOT NULL COMMENT 'evt_... de Stripe',
    tipo          VARCHAR(80)     NOT NULL,
    pago_id       BIGINT UNSIGNED NULL,
    cuerpo        LONGTEXT        NOT NULL,
    procesado_en  DATETIME        NULL,
    resultado     VARCHAR(200)    NULL,
    recibido_en   DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    PRIMARY KEY (id),
    UNIQUE KEY uq_evento (evento_id),
    KEY idx_evento_pago (pago_id),

    CONSTRAINT fk_evento_pago FOREIGN KEY (pago_id) REFERENCES pagos_pasarela(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Permisos
-- ---------------------------------------------------------------------

INSERT IGNORE INTO permisos (clave, descripcion) VALUES
    ('pago.pasarela',       'Pagar con tarjeta externa a través de la pasarela'),
    ('pago.ver_pasarela',   'Consultar los cobros de la pasarela'),
    ('pago.conciliar',      'Conciliar la cuenta técnica de la pasarela');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
 WHERE p.clave = 'pago.pasarela'
   AND r.clave IN ('administrador', 'cliente', 'atencionclientes');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
 WHERE p.clave IN ('pago.ver_pasarela', 'pago.conciliar')
   AND r.clave IN ('administrador', 'presidencia', 'gerencia');

-- ---------------------------------------------------------------------
-- Vista de conciliación
-- ---------------------------------------------------------------------
-- Lo cobrado por la pasarela frente a lo que hay en su cuenta técnica.
-- Si no cuadran, hay cobros contabilizados que Stripe no liquidó, o al
-- revés.

CREATE OR REPLACE VIEW v_pasarela_conciliacion AS
SELECT
    (SELECT COALESCE(SUM(importe_cent), 0)
       FROM pagos_pasarela WHERE estado = 'cobrado')      AS cobrado_cent,
    (SELECT COALESCE(SUM(importe_cent), 0)
       FROM pagos_pasarela WHERE estado = 'devuelto')     AS devuelto_cent,
    (SELECT COALESCE(SUM(importe_cent), 0)
       FROM pagos_pasarela WHERE estado IN ('iniciado','procesando')) AS en_curso_cent,
    (SELECT COUNT(*) FROM pagos_pasarela WHERE estado = 'fallido')    AS fallidos,
    (SELECT COALESCE(saldo_cent, 0)
       FROM cuentas WHERE numero_cuenta = '0000000001')    AS saldo_tecnica_cent;
