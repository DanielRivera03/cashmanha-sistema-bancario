-- =====================================================================
-- 032 · Arreglos de pago, enlaces de cobro y compensación automática
-- =====================================================================
--
-- Tres cosas que faltaban para que cobranza pueda hacer su trabajo sin
-- salirse del sistema:
--
-- 1. ARREGLOS DE PAGO. Un expediente con 53 cuotas vencidas no se cobra
--    cuota por cuota: se negocia. Las dos formas que se usan de verdad
--    son «pague un porcentaje de lo vencido y queda al día» y «pague
--    una cantidad única y finiquitamos». Las dos implican condonar
--    parte de la deuda, y eso no puede quedar como un apunte suelto:
--    tiene que constar quién lo propuso, quién lo autorizó, cuánto se
--    perdonó y contra qué asiento se registró la pérdida.
--
-- 2. ENLACES DE COBRO. El personal de cobranza no puede —ni debe—
--    debitar la cuenta de un cliente para cubrir su mora. Lo que sí
--    puede es mandarle un enlace con la cantidad ya calculada para que
--    pague él con su tarjeta. El enlace lleva un token de un solo uso
--    y caduca.
--
-- 3. COMPENSACIÓN AUTOMÁTICA. Si el cliente tiene saldo en su cuenta y
--    cuotas vencidas, el dinero debe ir a cubrir la mora en lugar de
--    quedarse parado. Se registra cada aplicación para poder explicarle
--    después por qué su saldo bajó.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- Arreglos de pago
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS arreglos_pago (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36)        NOT NULL,
    folio               VARCHAR(24)     NOT NULL,
    credito_id          INT UNSIGNED    NOT NULL,
    caso_id             BIGINT UNSIGNED NULL,
    usuario_id          INT UNSIGNED    NOT NULL,

    -- `al_dia`     paga un % de lo vencido y las cuotas vencidas se dan
    --              por cubiertas; el crédito sigue vivo con su plan.
    -- `finiquito`  paga una cantidad única y el crédito se cancela.
    tipo                ENUM('al_dia','finiquito') NOT NULL,

    -- Fotografía de la deuda en el momento de proponer. Si el cliente
    -- tarda una semana en pagar, la mora habrá subido: lo que se le
    -- ofreció es esto, no lo que diga la tabla el día que pague.
    deuda_vencida_cent  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mora_cent           BIGINT UNSIGNED NOT NULL DEFAULT 0,
    capital_cent        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    exigible_cent       BIGINT UNSIGNED NOT NULL DEFAULT 0,

    porcentaje_bp       SMALLINT UNSIGNED NOT NULL,
    monto_requerido_cent BIGINT UNSIGNED NOT NULL,
    condonado_cent      BIGINT UNSIGNED NOT NULL DEFAULT 0,

    cuotas_cubiertas    SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    estado              ENUM('propuesto','autorizado','pagado','vencido','anulado')
                        NOT NULL DEFAULT 'propuesto',
    vence_el            DATE            NOT NULL,

    propuesto_por       INT UNSIGNED    NOT NULL,
    autorizado_por      INT UNSIGNED    NULL,
    autorizado_en       DATETIME        NULL,
    pagado_en           DATETIME        NULL,
    asiento_id          BIGINT UNSIGNED NULL,
    anulado_por         INT UNSIGNED    NULL,

    nota                VARCHAR(500)    NULL,
    creado_en           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_arreglo_uuid  (uuid),
    UNIQUE KEY uq_arreglo_folio (folio),
    KEY ix_arreglo_credito (credito_id, estado),
    KEY ix_arreglo_caso    (caso_id),
    KEY ix_arreglo_estado  (estado, vence_el),

    CONSTRAINT fk_arreglo_credito FOREIGN KEY (credito_id)
        REFERENCES creditos (id) ON DELETE CASCADE,
    CONSTRAINT fk_arreglo_caso FOREIGN KEY (caso_id)
        REFERENCES cobranza_casos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Qué cuotas quedan cubiertas por el arreglo. Se guarda al proponer,
-- no al pagar: si entre medias vence otra cuota, no entra en el trato
-- que se le ofreció al cliente.
CREATE TABLE IF NOT EXISTS arreglo_cuotas (
    arreglo_id  BIGINT UNSIGNED NOT NULL,
    cuota_id    BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (arreglo_id, cuota_id),
    KEY ix_arrcuota_cuota (cuota_id),
    CONSTRAINT fk_arrcuota_arreglo FOREIGN KEY (arreglo_id)
        REFERENCES arreglos_pago (id) ON DELETE CASCADE,
    CONSTRAINT fk_arrcuota_cuota FOREIGN KEY (cuota_id)
        REFERENCES cuotas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Enlaces de cobro
-- ---------------------------------------------------------------------
--
-- El token se guarda hasheado. Un enlace de pago es una credencial: si
-- alguien lee la base, no debe poder abrir los enlaces vigentes.
CREATE TABLE IF NOT EXISTS enlaces_pago (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)        NOT NULL,
    token_hash      CHAR(64)        NOT NULL,
    usuario_id      INT UNSIGNED    NOT NULL,
    credito_id      INT UNSIGNED    NULL,
    arreglo_id      BIGINT UNSIGNED NULL,

    concepto        VARCHAR(160)    NOT NULL,
    importe_cent    BIGINT UNSIGNED NOT NULL,

    estado          ENUM('vigente','pagado','caducado','anulado')
                    NOT NULL DEFAULT 'vigente',
    vence_el        DATETIME        NOT NULL,

    creado_por      INT UNSIGNED    NOT NULL,
    enviado_a       VARCHAR(190)    NULL,
    enviado_en      DATETIME        NULL,
    abierto_en      DATETIME        NULL,
    pagado_en       DATETIME        NULL,
    pago_pasarela_id BIGINT UNSIGNED NULL,

    creado_en       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_enlace_uuid  (uuid),
    UNIQUE KEY uq_enlace_token (token_hash),
    KEY ix_enlace_estado (estado, vence_el),
    KEY ix_enlace_credito (credito_id),

    CONSTRAINT fk_enlace_arreglo FOREIGN KEY (arreglo_id)
        REFERENCES arreglos_pago (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Compensación automática
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS compensaciones (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id      INT UNSIGNED    NOT NULL,
    cuenta_id       INT UNSIGNED    NOT NULL,
    credito_id      INT UNSIGNED    NULL,
    tarjeta_id      INT UNSIGNED    NULL,
    cuota_id        BIGINT UNSIGNED NULL,

    importe_cent    BIGINT UNSIGNED NOT NULL,
    mora_cent       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    saldo_antes_cent BIGINT         NOT NULL DEFAULT 0,
    saldo_despues_cent BIGINT       NOT NULL DEFAULT 0,

    -- `abono`     se disparó porque entró dinero a la cuenta.
    -- `barrido`   se disparó en la pasada diaria.
    -- `manual`    alguien la ejecutó desde el panel.
    origen          ENUM('abono','barrido','manual') NOT NULL DEFAULT 'abono',

    asiento_id      BIGINT UNSIGNED NULL,
    creado_en       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ix_comp_usuario (usuario_id, creado_en),
    KEY ix_comp_cuenta  (cuenta_id, creado_en),
    KEY ix_comp_credito (credito_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quién tiene activada la compensación y con qué alcance.
--
-- Por omisión no se toca la cuenta de nadie: la compensación se pacta,
-- y la columna `autorizada` guarda esa decisión. El porcentaje permite
-- el caso intermedio —tomar la mitad de lo que entre, no todo— y el
-- mínimo evita dejar la cuenta en cero.
ALTER TABLE cuentas
    ADD COLUMN compensacion_autorizada TINYINT(1) NOT NULL DEFAULT 0
        AFTER estado,
    ADD COLUMN compensacion_bp SMALLINT UNSIGNED NOT NULL DEFAULT 10000
        AFTER compensacion_autorizada,
    ADD COLUMN compensacion_minimo_cent INT UNSIGNED NOT NULL DEFAULT 0
        AFTER compensacion_bp;

-- ---------------------------------------------------------------------
-- Permisos
-- ---------------------------------------------------------------------
INSERT INTO permisos (clave, descripcion) VALUES
  ('arreglo.proponer',   'Proponer un arreglo de pago sobre cuotas vencidas'),
  ('arreglo.autorizar',  'Autorizar arreglos que condonan por encima del tope'),
  ('arreglo.anular',     'Anular un arreglo propuesto o autorizado'),
  ('cobro.enlace',       'Generar y enviar enlaces de pago a clientes'),
  ('compensacion.ver',   'Consultar las compensaciones aplicadas'),
  ('compensacion.configurar', 'Activar la compensacion y fijar su alcance'),
  ('compensacion.ejecutar',   'Ejecutar la pasada de compensacion a mano')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- El Departamento Juridico propone arreglos y manda enlaces de cobro,
-- pero NO autoriza sus propias condonaciones por encima del tope: eso
-- es de gerencia, que es lo que hace util la regla de los cuatro ojos.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 12, p.id FROM permisos p
 WHERE p.clave IN ('arreglo.proponer','arreglo.anular','cobro.enlace','compensacion.ver')
ON DUPLICATE KEY UPDATE rol_id = rol_id;

-- Gerencia autoriza las condonaciones y configura la compensacion.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 3, p.id FROM permisos p
 WHERE p.clave IN ('arreglo.proponer','arreglo.autorizar','arreglo.anular',
                   'cobro.enlace','compensacion.ver','compensacion.configurar',
                   'compensacion.ejecutar')
ON DUPLICATE KEY UPDATE rol_id = rol_id;

-- Administracion, todo.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 1, p.id FROM permisos p
 WHERE p.clave IN ('arreglo.proponer','arreglo.autorizar','arreglo.anular',
                   'cobro.enlace','compensacion.ver','compensacion.configurar',
                   'compensacion.ejecutar')
ON DUPLICATE KEY UPDATE rol_id = rol_id;

-- Presidencia autoriza tambien, por si gerencia es quien propone.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 2, p.id FROM permisos p
 WHERE p.clave IN ('arreglo.autorizar','compensacion.ver')
ON DUPLICATE KEY UPDATE rol_id = rol_id;

-- Ventanilla cobra arreglos ya autorizados, pero no los pacta.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 11, p.id FROM permisos p WHERE p.clave IN ('compensacion.ver')
ON DUPLICATE KEY UPDATE rol_id = rol_id;

-- ---------------------------------------------------------------------
-- Parámetros
-- ---------------------------------------------------------------------
INSERT INTO parametros (clave, valor, descripcion) VALUES
  ('arreglo.quita_maxima_bp', '3000',
   'Condonacion maxima que puede conceder cobranza sin autorizacion superior (bp)'),
  ('arreglo.quita_tope_bp', '7000',
   'Condonacion maxima absoluta, ni con autorizacion se pasa de aqui (bp)'),
  ('arreglo.dias_vigencia', '8',
   'Dias que se mantiene en pie la oferta de arreglo'),
  ('cobro.enlace_dias', '7',
   'Dias que vive un enlace de pago antes de caducar'),
  ('compensacion.activa', '1',
   'Aplicar compensacion automatica cuando entra dinero a una cuenta con mora'),
  ('compensacion.minimo_cent', '100',
   'Por debajo de esta cantidad no se aplica compensacion')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
