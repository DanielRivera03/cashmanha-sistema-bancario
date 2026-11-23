-- =====================================================================
-- 018 — Capacidad de pago y consulta a buró de crédito
-- ---------------------------------------------------------------------
-- Dos cosas:
--
-- 1. Que el monto y el límite se ajusten solos a lo que el cliente puede
--    pagar, en vez de que alguien pruebe cifras a mano hasta que la
--    validación deje pasar una.
--
-- 2. La consulta al buró de crédito.
--
-- Sobre el buró, conviene ser claro con lo que hay y lo que no:
--
--   · En El Salvador operan Equifax, TransUnion e INFORED. Ninguno
--     publica un entorno de pruebas abierto: hay que contratar, y la
--     ley exige que el buró esté domiciliado en el país.
--
--   · La consulta requiere AUTORIZACIÓN ESCRITA del cliente, en
--     documento separado, nombrando al buró concreto que se va a
--     consultar. No es un detalle administrativo: consultar sin ese
--     respaldo es ilegal.
--
-- Por eso aquí se construye el puerto y el registro —incluida la
-- autorización, que es el requisito que nadie puede saltarse— y un
-- proveedor simulado para desarrollo. El día que exista contrato con un
-- buró real, se implementa su proveedor y se cambia un parámetro.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Parámetros de capacidad
-- ---------------------------------------------------------------------

INSERT INTO parametros (clave, valor, descripcion) VALUES
    ('credito.relacion_cuota_ingreso_bp', '4000',
     'Porcentaje máximo del ingreso disponible que puede ir a la cuota, en puntos base (4000 = 40 %)'),
    ('tarjeta.limite_veces_ingreso', '3',
     'Límite de tarjeta como múltiplo del ingreso mensual disponible'),
    ('buro.proveedor', 'simulado',
     'Proveedor de buró de crédito: simulado | equifax | transunion | infored'),
    ('buro.score_minimo', '550',
     'Puntaje por debajo del cual la solicitud se marca para revisión manual'),
    ('buro.vigencia_dias', '30',
     'Días que se considera vigente una consulta antes de repetirla')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- ---------------------------------------------------------------------
-- Autorización del cliente
-- ---------------------------------------------------------------------
-- Sin una fila aquí no se consulta. Guarda a quién se autorizó consultar,
-- cuándo y desde dónde se firmó.

CREATE TABLE IF NOT EXISTS buro_autorizaciones (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id   INT(10) UNSIGNED NOT NULL,
    buro         VARCHAR(40)     NOT NULL COMMENT 'Buró concreto que el cliente autorizó',
    otorgada_en  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    vence_en     DATE            NULL COMMENT 'NULL = sin vencimiento declarado',
    revocada_en  DATETIME        NULL,
    documento    VARCHAR(255)    NULL COMMENT 'Referencia del documento firmado',
    ip           VARBINARY(16)   NULL,
    registrada_por INT(10) UNSIGNED NULL,

    PRIMARY KEY (id),
    KEY idx_buro_aut_usuario (usuario_id, buro),

    CONSTRAINT fk_buro_aut_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_buro_aut_actor   FOREIGN KEY (registrada_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Consultas realizadas
-- ---------------------------------------------------------------------
-- Cada consulta queda registrada: el buró las factura y el cliente tiene
-- derecho a saber quién miró su historial y cuándo.

CREATE TABLE IF NOT EXISTS buro_consultas (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id    INT(10) UNSIGNED NOT NULL,
    solicitud_id  INT(10) UNSIGNED NULL,
    buro          VARCHAR(40)     NOT NULL,
    autorizacion_id BIGINT UNSIGNED NULL,

    score         SMALLINT(5) UNSIGNED NULL COMMENT 'Puntaje devuelto',
    categoria     VARCHAR(30)     NULL COMMENT 'A, B, C, D, E o la escala del buró',
    deuda_total_cent    BIGINT UNSIGNED NULL,
    cuota_mensual_cent  BIGINT UNSIGNED NULL COMMENT 'Compromisos mensuales reportados',
    creditos_activos    SMALLINT(5) UNSIGNED NULL,
    moras_12m           SMALLINT(5) UNSIGNED NULL COMMENT 'Atrasos en los últimos 12 meses',
    peor_mora_dias      SMALLINT(5) UNSIGNED NULL,

    respuesta_json LONGTEXT       NULL,
    resultado     ENUM('exito','sin_historial','sin_autorizacion','error') NOT NULL DEFAULT 'exito',
    detalle_error VARCHAR(400)    NULL,

    consultada_por INT(10) UNSIGNED NULL,
    ip            VARBINARY(16)   NULL,
    consultada_en DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    PRIMARY KEY (id),
    KEY idx_buro_con_usuario (usuario_id, consultada_en),
    KEY idx_buro_con_solicitud (solicitud_id),

    CONSTRAINT fk_buro_con_usuario   FOREIGN KEY (usuario_id)   REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_buro_con_solicitud FOREIGN KEY (solicitud_id) REFERENCES solicitudes_credito(id) ON DELETE SET NULL,
    CONSTRAINT fk_buro_con_aut       FOREIGN KEY (autorizacion_id) REFERENCES buro_autorizaciones(id) ON DELETE SET NULL,
    CONSTRAINT fk_buro_con_actor     FOREIGN KEY (consultada_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- La solicitud recuerda qué consulta la respaldó
-- ---------------------------------------------------------------------

ALTER TABLE solicitudes_credito
    ADD COLUMN buro_consulta_id BIGINT UNSIGNED NULL
        COMMENT 'Consulta de buró que respaldó la evaluación' AFTER ingreso_cent,
    ADD COLUMN capacidad_maxima_cent BIGINT UNSIGNED NULL
        COMMENT 'Monto máximo que admitía la capacidad de pago al capturar' AFTER buro_consulta_id,
    ADD CONSTRAINT fk_solicitud_buro
        FOREIGN KEY (buro_consulta_id) REFERENCES buro_consultas(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- Permisos
-- ---------------------------------------------------------------------

INSERT IGNORE INTO permisos (clave, descripcion) VALUES
    ('buro.consultar',  'Consultar el historial crediticio de un cliente en el buró'),
    ('buro.autorizar',  'Registrar la autorización del cliente para consultar su buró'),
    ('buro.ver',        'Ver las consultas de buró realizadas');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
 WHERE p.clave IN ('buro.consultar', 'buro.autorizar', 'buro.ver')
   AND r.clave IN ('administrador', 'presidencia', 'gerencia');

-- Atención al cliente registra la autorización —es quien atiende al
-- cliente y le hace firmar— pero no consulta ni ve el historial.
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
 WHERE p.clave = 'buro.autorizar'
   AND r.clave = 'atencionclientes';
