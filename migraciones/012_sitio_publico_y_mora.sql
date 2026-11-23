-- =====================================================================
-- 012 — Sitio público y recargo por mora
-- ---------------------------------------------------------------------
-- Tres cosas que no tenían dónde vivir:
--
-- 1. Qué productos puede ver cualquiera. El sitio público lista el
--    catálogo, y en él hay cuentas técnicas —la de la pasarela— que no
--    son un producto que se contrate: son contabilidad interna. Filtrar
--    por el prefijo del código funcionaría hoy y fallaría en cuanto
--    alguien cree otra cuenta técnica con otro nombre, así que la
--    distinción se marca de forma explícita.
--
-- 2. El recargo por mora. El sistema anterior lo calculaba como
--    días_de_atraso × 5.99, y el contrato modelo lo describe como una
--    penalización diaria de $5.99 que además tiene preferencia sobre
--    capital e intereses al imputar un pago. Aquí los dos números
--    quedan como parámetros, no incrustados en el código.
--
--    Nota sobre la discrepancia: la cláusula penal del contrato concede
--    diez días de gracia ("dentro de los diez días siguientes a la fecha
--    estipulada"), pero la implementación anterior cobraba desde el
--    primer día de atraso. Se deja el valor por defecto en 0 para
--    reproducir lo que el sistema hacía de verdad; quien quiera aplicar
--    el contrato al pie de la letra solo tiene que poner 10.
--
-- 3. La corrección de un destrozo propio: la migración 011 insertó
--    "Cuenta técnica de pasarela" con la codificación cambiada y en la
--    tabla quedó "Cuenta t├®cnica".
-- =====================================================================

-- ---------------------------------------------------------------------
-- Productos visibles en el sitio público
-- ---------------------------------------------------------------------

ALTER TABLE productos
    ADD COLUMN publico TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Se muestra en el sitio público' AFTER activo;

-- Las cuentas técnicas no se contratan: no salen en el catálogo.
UPDATE productos SET publico = 0 WHERE codigo LIKE 'TEC-%';

-- Reparación del nombre que la 011 dejó mal codificado.
UPDATE productos
   SET nombre      = 'Cuenta técnica de pasarela',
       descripcion = 'Fondos cobrados por la pasarela pendientes de liquidación'
 WHERE codigo = 'TEC-PASARELA';

-- ---------------------------------------------------------------------
-- Parámetros del recargo por mora
-- ---------------------------------------------------------------------
-- Van en la tabla de parámetros para poder cambiarlos sin tocar código
-- ni volver a desplegar. Los importes, como en todo el sistema, en
-- centavos enteros.

CREATE TABLE IF NOT EXISTS parametros (
    clave       VARCHAR(60)  NOT NULL,
    valor       VARCHAR(200) NOT NULL,
    descripcion VARCHAR(300) NOT NULL DEFAULT '',
    actualizado_en DATETIME   NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO parametros (clave, valor, descripcion) VALUES
    ('mora.cargo_diario_cent', '599',
     'Penalización diaria por cuota en mora, en centavos (cláusula penal: $5.99)'),
    ('mora.dias_gracia', '0',
     'Días de atraso que no generan recargo. El contrato dice 10; el sistema anterior cobraba desde el día 1'),
    ('mora.tope_por_cuota_cent', '0',
     'Recargo máximo acumulable por cuota, en centavos. 0 = sin tope');

-- ---------------------------------------------------------------------
-- Rastro del devengo
-- ---------------------------------------------------------------------
-- Sin esto no se puede saber por qué una cuota tiene el recargo que
-- tiene, ni evitar devengar dos veces el mismo día si el proceso se
-- ejecuta repetido.

CREATE TABLE IF NOT EXISTS mora_devengos (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuota_id     BIGINT UNSIGNED NOT NULL,
    credito_id   INT(10) UNSIGNED NOT NULL,
    fecha        DATE            NOT NULL COMMENT 'Día devengado',
    dias_atraso  INT(10) UNSIGNED NOT NULL,
    cargo_cent   BIGINT UNSIGNED NOT NULL,
    acumulado_cent BIGINT UNSIGNED NOT NULL,
    creado_en    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    PRIMARY KEY (id),
    -- Una cuota devenga como mucho una vez por día: si el proceso se
    -- lanza dos veces, la segunda no duplica el cargo.
    UNIQUE KEY uq_devengo_dia (cuota_id, fecha),
    KEY idx_devengo_credito (credito_id),

    CONSTRAINT fk_devengo_cuota   FOREIGN KEY (cuota_id)   REFERENCES cuotas(id)   ON DELETE CASCADE,
    CONSTRAINT fk_devengo_credito FOREIGN KEY (credito_id) REFERENCES creditos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Permisos
-- ---------------------------------------------------------------------

INSERT IGNORE INTO permisos (clave, descripcion) VALUES
    ('mora.devengar',  'Ejecutar el devengo del recargo por mora'),
    ('mora.condonar',  'Condonar total o parcialmente el recargo de una cuota'),
    ('parametro.editar', 'Modificar los parámetros del sistema');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
 WHERE p.clave IN ('mora.devengar', 'parametro.editar')
   AND r.clave IN ('administrador');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
 WHERE p.clave = 'mora.condonar'
   AND r.clave IN ('administrador', 'presidencia', 'gerencia');
