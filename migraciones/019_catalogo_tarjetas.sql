-- =====================================================================
-- 019 — Catálogo de tarjetas y solicitudes
-- ---------------------------------------------------------------------
-- Había dos tarjetas en el catálogo —Clásica y Oro— sin requisitos, sin
-- beneficios y sin forma de que un cliente las pidiera: la pantalla le
-- decía "escríbanos a soporte". Quien emitía tarjetas era el personal,
-- a mano, desde una pantalla interna.
--
-- Esto añade:
--
--   · Un catálogo con cinco tarjetas escalonadas, con su requisito de
--     ingreso, su línea de arranque, su membresía y sus beneficios.
--   · Solicitudes de tarjeta, con etapas y seguimiento, igual que las
--     de crédito.
--
-- Los escalones siguen lo que pide la banca salvadoreña (consultado en
-- noviembre de 2026):
--
--   Clásica    ingreso desde ~$450–500, línea desde $500, sin membresía
--   Oro        ingreso desde ~$900–1,600, línea desde $2,000, con puntos
--   Platinum   ingreso desde ~$1,800–4,000, línea desde $5,000
--
-- Las tasas ya venían fijadas en la migración 014 según el rango real
-- del mercado (24 %–48 % anual).
-- =====================================================================

-- ---------------------------------------------------------------------
-- Campos de producto propios de una tarjeta
-- ---------------------------------------------------------------------

ALTER TABLE productos
    ADD COLUMN ingreso_minimo_cent BIGINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Ingreso mensual mínimo para optar al producto' AFTER saldo_minimo_cent,
    ADD COLUMN linea_desde_cent    BIGINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Línea de crédito inicial de referencia' AFTER ingreso_minimo_cent,
    ADD COLUMN membresia_anual_cent BIGINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Membresía anual; 0 = sin membresía' AFTER linea_desde_cent,
    ADD COLUMN beneficios          TEXT NULL
        COMMENT 'Beneficios, uno por línea' AFTER membresia_anual_cent,
    ADD COLUMN nivel               TINYINT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'Escalón dentro de su familia: 1 el más básico' AFTER beneficios;

-- ---------------------------------------------------------------------
-- Catálogo
-- ---------------------------------------------------------------------

UPDATE productos SET
    descripcion = 'Su primera tarjeta, para el día a día y sin costo de membresía.',
    ingreso_minimo_cent = 45000,
    linea_desde_cent    = 50000,
    membresia_anual_cent = 0,
    nivel = 1,
    beneficios = 'Sin membresía anual, de por vida
Hasta 25 días sin intereses pagando el total
Compras en línea y en el exterior
Bloqueo inmediato desde la banca en línea
Comprobante de cada compra'
 WHERE codigo = 'TAR-CLA';

UPDATE productos SET
    descripcion = 'Para quien ya maneja un presupuesto y quiere que sus compras le devuelvan algo.',
    ingreso_minimo_cent = 100000,
    linea_desde_cent    = 200000,
    membresia_anual_cent = 3500,
    nivel = 2,
    beneficios = 'Primer año sin membresía
Acumulación de puntos por cada compra
Hasta 25 días sin intereses pagando el total
Seguro de protección de compras
Atención telefónica preferente
Tarjeta adicional sin costo'
 WHERE codigo = 'TAR-ORO';

INSERT INTO productos
    (codigo, nombre, tipo, descripcion, tasa_interes_bp, tasa_min_bp, tasa_max_bp,
     saldo_minimo_cent, ingreso_minimo_cent, linea_desde_cent, membresia_anual_cent,
     nivel, beneficios, activo, publico)
VALUES
    ('TAR-PLA', 'Tarjeta Platinum', 'tarjeta',
     'Pensada para viajar: salas VIP, seguros de viaje y asistencia donde esté.',
     3400, 2800, 4200, 0, 250000, 500000, 9500, 3,
     'Primer año sin membresía
Acceso a salas VIP en aeropuertos
Seguro de viaje y de accidentes en vuelo
Asistencia en carretera y asistencia médica en el exterior
Protección de compras y garantía extendida
Hasta 25 días sin intereses pagando el total
Dos tarjetas adicionales sin costo', 1, 1),

    ('TAR-SIG', 'Tarjeta Signature', 'tarjeta',
     'Nuestra tarjeta de mayor nivel, con atención personal y los límites más altos.',
     3000, 2400, 3800, 0, 500000, 1500000, 19500, 4,
     'Ejecutivo de cuenta asignado
Servicio de concierge 24 horas
Acceso ilimitado a salas VIP
Seguros de viaje con cobertura ampliada
Alquiler de vehículos con cobertura incluida
Hasta 25 días sin intereses pagando el total
Tarjetas adicionales sin costo', 1, 1),

    ('TAR-CAS', 'Tarjeta Cashback', 'tarjeta',
     'Le devuelve dinero en lo que más compra: supermercado, combustible y farmacia.',
     4200, 3600, 5000, 0, 45000, 75000, 0, 1,
     '5 % de devolución en supermercados
3 % en combustible y farmacias
1 % en el resto de sus compras
Sin membresía anual
La devolución se abona a su estado de cuenta
Hasta 25 días sin intereses pagando el total', 1, 1)
ON DUPLICATE KEY UPDATE
    descripcion = VALUES(descripcion),
    beneficios  = VALUES(beneficios);

-- ---------------------------------------------------------------------
-- Solicitudes de tarjeta
-- ---------------------------------------------------------------------
-- Mismo criterio que las de crédito: etapas, quién revisó cada una y
-- constancia de lo decidido. Una tarjeta de crédito es una línea de
-- crédito, y no debería otorgarse con menos ceremonia que un préstamo.

CREATE TABLE IF NOT EXISTS solicitudes_tarjeta (
    id            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid          CHAR(36)        NOT NULL,
    folio         VARCHAR(24)     NOT NULL,
    usuario_id    INT(10) UNSIGNED NOT NULL,
    producto_id   SMALLINT(5) UNSIGNED NOT NULL,

    marca         ENUM('visa','mastercard') NOT NULL DEFAULT 'visa',
    limite_solicitado_cent BIGINT UNSIGNED NOT NULL DEFAULT 0,
    limite_aprobado_cent   BIGINT UNSIGNED NULL,
    ingreso_cent  BIGINT UNSIGNED NOT NULL DEFAULT 0,

    -- Capacidad y buró en el momento de capturar.
    capacidad_maxima_cent BIGINT UNSIGNED NULL,
    buro_consulta_id      BIGINT UNSIGNED NULL,

    etapa         ENUM('capturada','revision_atencion','revision_gerencia',
                       'aprobada','rechazada','emitida') NOT NULL DEFAULT 'capturada',

    revisado_atencion_por  INT(10) UNSIGNED NULL,
    revisado_gerencia_por  INT(10) UNSIGNED NULL,
    observaciones  TEXT         NULL,
    motivo_rechazo VARCHAR(500) NULL,

    tarjeta_id    INT(10) UNSIGNED NULL COMMENT 'Tarjeta emitida, si llegó a emitirse',

    creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_sol_tar_uuid (uuid),
    UNIQUE KEY uq_sol_tar_folio (folio),
    KEY idx_sol_tar_usuario (usuario_id, etapa),

    CONSTRAINT fk_sol_tar_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_sol_tar_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
    CONSTRAINT fk_sol_tar_tarjeta  FOREIGN KEY (tarjeta_id)  REFERENCES tarjetas(id) ON DELETE SET NULL,
    CONSTRAINT fk_sol_tar_buro     FOREIGN KEY (buro_consulta_id) REFERENCES buro_consultas(id) ON DELETE SET NULL,
    CONSTRAINT fk_sol_tar_aten     FOREIGN KEY (revisado_atencion_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_sol_tar_ger      FOREIGN KEY (revisado_gerencia_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Permisos
-- ---------------------------------------------------------------------

INSERT IGNORE INTO permisos (clave, descripcion) VALUES
    ('tarjeta.solicitar',      'Solicitar una tarjeta de crédito del catálogo'),
    ('tarjeta.ver_solicitudes','Consultar las solicitudes de tarjeta'),
    ('tarjeta.revisar_atencion','Revisar una solicitud de tarjeta en atención al cliente'),
    ('tarjeta.revisar_gerencia','Aprobar o rechazar una solicitud de tarjeta en gerencia');

-- El cliente solicita; también el personal puede capturar por él.
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
 WHERE p.clave = 'tarjeta.solicitar'
   AND r.clave IN ('cliente', 'atencionclientes', 'administrador');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
 WHERE p.clave = 'tarjeta.ver_solicitudes'
   AND r.clave IN ('atencionclientes', 'gerencia', 'presidencia', 'administrador');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
 WHERE p.clave = 'tarjeta.revisar_atencion'
   AND r.clave IN ('atencionclientes', 'administrador');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
 WHERE p.clave = 'tarjeta.revisar_gerencia'
   AND r.clave IN ('gerencia', 'presidencia', 'administrador');
