-- =====================================================================
-- 017 — Cada tarjeta pertenece a un producto
-- ---------------------------------------------------------------------
-- Una tarjeta de crédito no cuelga de una cuenta de depósito —su
-- `cuenta_id` es NULL, y así debe ser—, pero tampoco tenía forma de
-- saber a qué producto pertenece. Consecuencia: no había de dónde sacar
-- su tasa.
--
-- El sitio público anuncia 44 % para la Clásica y 38 % para la Oro, y el
-- corte mensual necesita esa tasa para calcular intereses. Sin este
-- vínculo, el corte tendría que inventársela o usar la tasa de pago
-- diferido (18 %), que es otra cosa distinta: esa es la de comprar a
-- cuotas, no la de financiar el saldo.
--
-- La columna queda NULL-able porque hay tarjetas emitidas antes de
-- existir, y el proceso de corte sabe recurrir a un parámetro cuando
-- falta.
-- =====================================================================

ALTER TABLE tarjetas
    ADD COLUMN producto_id SMALLINT(5) UNSIGNED NULL
        COMMENT 'Producto del catálogo al que pertenece la tarjeta' AFTER cuenta_id,
    ADD CONSTRAINT fk_tarjeta_producto
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- Asignación de las tarjetas ya emitidas
-- ---------------------------------------------------------------------
-- Se reparten por límite, que es como se escalonan los productos de
-- tarjeta en la práctica: a mayor límite, mejor perfil y mejor tasa.
-- Es un criterio explícito y revisable, no una suposición escondida:
-- Gerencia lo corrige con un UPDATE si alguna tarjeta va en otro
-- producto.

UPDATE tarjetas
   SET producto_id = (SELECT id FROM productos WHERE codigo = 'TAR-ORO')
 WHERE tipo = 'credito' AND producto_id IS NULL AND limite_cent >= 500000;

UPDATE tarjetas
   SET producto_id = (SELECT id FROM productos WHERE codigo = 'TAR-CLA')
 WHERE tipo = 'credito' AND producto_id IS NULL;

-- ---------------------------------------------------------------------
-- Tasa de respaldo
-- ---------------------------------------------------------------------
-- Para una tarjeta sin producto: antes de inventar un número, el corte
-- usa este parámetro. Arranca en la tasa de la Clásica, que es la base
-- publicada.

INSERT INTO parametros (clave, valor, descripcion) VALUES
    ('tarjeta.tasa_revolvente_bp', '4400',
     'Tasa anual de respaldo para financiar el saldo, si la tarjeta no tiene producto asignado')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
