-- =====================================================================
-- 013 — Tasa ajustable al otorgar un crédito
-- ---------------------------------------------------------------------
-- El sistema anterior dejaba mover la tasa con un deslizador al momento
-- de armar el crédito, con un rango distinto por tipo de producto
-- (`<input type="range" id="rangointereses">` con min/max por producto),
-- y recalculaba la cuota en vivo. Aquí la tasa era fija: la del
-- producto, sin margen para negociar.
--
-- Esto devuelve esa posibilidad, pero con el límite guardado en la base
-- en lugar de escrito en el HTML. La razón es sencilla: el deslizador lo
-- controla el navegador, y un `min`/`max` en el marcado no impide que
-- alguien envíe la tasa que quiera. El servidor compara contra estas
-- columnas antes de aceptar nada.
--
-- Los rangos de abajo enmarcan la tasa vigente de cada producto. No
-- pretenden ser política de crédito: son parámetros, y Gerencia los
-- ajusta con un UPDATE sin tocar código.
-- =====================================================================

ALTER TABLE productos
    ADD COLUMN tasa_min_bp INT(10) UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Tasa anual mínima en puntos base' AFTER tasa_interes_bp,
    ADD COLUMN tasa_max_bp INT(10) UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Tasa anual máxima en puntos base' AFTER tasa_min_bp;

-- Por defecto, sin margen: la tasa del producto y nada más. Así, un
-- producto nuevo no queda con un rango abierto por descuido.
UPDATE productos SET tasa_min_bp = tasa_interes_bp, tasa_max_bp = tasa_interes_bp;

UPDATE productos SET tasa_min_bp = 1200, tasa_max_bp = 3000 WHERE codigo = 'CRE-PER';
UPDATE productos SET tasa_min_bp =  600, tasa_max_bp = 1500 WHERE codigo = 'CRE-HIP';
UPDATE productos SET tasa_min_bp = 1800, tasa_max_bp = 3600 WHERE codigo = 'TAR-CLA';
UPDATE productos SET tasa_min_bp = 1500, tasa_max_bp = 3300 WHERE codigo = 'TAR-ORO';

-- ---------------------------------------------------------------------
-- La tasa pactada viaja con la solicitud
-- ---------------------------------------------------------------------
-- Si no se guardara aquí, la tasa que se vio al simular se perdería
-- entre la captura y el desembolso, y el crédito acabaría con la del
-- producto —otra distinta de la que se le enseñó al cliente—.
--
-- NULL significa "la del producto", que es el comportamiento que tenían
-- las solicitudes ya existentes.

ALTER TABLE solicitudes_credito
    ADD COLUMN tasa_bp INT(10) UNSIGNED NULL
        COMMENT 'Tasa anual pactada en puntos base; NULL = la del producto'
        AFTER plazo_meses;

-- ---------------------------------------------------------------------
-- Permiso
-- ---------------------------------------------------------------------
-- Mover la tasa no es capturar una solicitud: es una concesión
-- comercial. Quien solo captura usa la del producto.

INSERT IGNORE INTO permisos (clave, descripcion) VALUES
    ('credito.ajustar_tasa', 'Ajustar la tasa de interés dentro del rango del producto');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
 WHERE p.clave = 'credito.ajustar_tasa'
   AND r.clave IN ('administrador', 'presidencia', 'gerencia');
