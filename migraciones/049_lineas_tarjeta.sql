-- ---------------------------------------------------------------------
-- Más líneas de tarjeta, y la marca que le corresponde a cada una.
--
-- El catálogo tenía cuatro escalones de crédito y uno de débito. Para
-- un banco eso deja dos huecos evidentes: no hay nada por debajo de la
-- Clásica para quien empieza, y no hay nada por encima de la Signature
-- para banca privada. Tampoco había manera de decir que una línea se
-- emite en American Express y no en Visa, que es una decisión del
-- producto y no del cliente.
-- ---------------------------------------------------------------------

-- La marca con la que se emite cada línea.
--
-- Hasta ahora la elegía quien solicitaba, de una lista fija. Pero una
-- línea de entrada no se emite en Amex, y la de banca privada no se
-- emite en cualquier cosa: es una condición del producto, igual que su
-- tasa o su membresía.
ALTER TABLE productos
  ADD COLUMN marca_emision VARCHAR(20) NULL DEFAULT NULL
      COMMENT 'Marca en la que se emite esta línea: visa, mastercard o amex'
      AFTER nivel;

UPDATE productos SET marca_emision = 'visa'       WHERE codigo = 'TAR-CLA';
UPDATE productos SET marca_emision = 'mastercard' WHERE codigo = 'TAR-CAS';
UPDATE productos SET marca_emision = 'visa'       WHERE codigo = 'TAR-ORO';
UPDATE productos SET marca_emision = 'mastercard' WHERE codigo = 'TAR-PLA';
UPDATE productos SET marca_emision = 'amex'       WHERE codigo = 'TAR-SIG';
UPDATE productos SET marca_emision = 'visa'       WHERE codigo = 'TAR-DEB';

-- --- Las líneas nuevas -------------------------------------------------

-- Nivel 1 por debajo de la Clásica: para quien empieza y todavía no
-- acredita ingreso para la Clásica. Línea corta y sin membresía, que es
-- como se construye historial sin exponer a la institución.
INSERT INTO productos
  (codigo, nombre, tipo, descripcion, tasa_interes_bp, tasa_min_bp, tasa_max_bp,
   saldo_minimo_cent, ingreso_minimo_cent, linea_desde_cent, membresia_anual_cent,
   beneficios, nivel, marca_emision, activo, publico, creado_en)
SELECT 'TAR-INI', 'Tarjeta Inicia', 'tarjeta',
  'Para quien está construyendo su historial de crédito. Línea corta, sin membresía anual y con acompañamiento para usarla bien.',
  4800, 4200, 5400, 0, 30000, 20000, 0,
  'Sin membresía anual\nAumento de línea automático a los 12 meses de buen uso\nAlertas de consumo en cada movimiento\nCompras a cuotas sin intereses en comercios afiliados',
  1, 'visa', 1, 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM productos p WHERE p.codigo = 'TAR-INI');

-- Nivel 5: por encima de la Signature. Banca privada.
INSERT INTO productos
  (codigo, nombre, tipo, descripcion, tasa_interes_bp, tasa_min_bp, tasa_max_bp,
   saldo_minimo_cent, ingreso_minimo_cent, linea_desde_cent, membresia_anual_cent,
   beneficios, nivel, marca_emision, activo, publico, creado_en)
SELECT 'TAR-INF', 'Tarjeta Infinite', 'tarjeta',
  'Banca privada. Línea desde $30,000, atención dedicada y los beneficios de viaje de la red Infinite.',
  2700, 2200, 3400, 0, 1000000, 3000000, 39500,
  'Banquero privado asignado\nSalas VIP de aeropuerto sin límite de visitas\nSeguro de viaje para el titular y su familia\nProtección de compras y garantía extendida\nSin comisión por consumo en el exterior',
  5, 'visa', 1, 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM productos p WHERE p.codigo = 'TAR-INF');

-- Nivel 6: por invitación. No sale en el catálogo público.
INSERT INTO productos
  (codigo, nombre, tipo, descripcion, tasa_interes_bp, tasa_min_bp, tasa_max_bp,
   saldo_minimo_cent, ingreso_minimo_cent, linea_desde_cent, membresia_anual_cent,
   beneficios, nivel, marca_emision, activo, publico, creado_en)
SELECT 'TAR-OBS', 'Tarjeta Obsidiana', 'tarjeta',
  'Por invitación. Sin límite preestablecido de consumo, con línea revisada caso por caso.',
  2400, 1800, 3000, 0, 2500000, 7500000, 79500,
  'Por invitación de la institución\nSin límite preestablecido de consumo\nConcierge personal 24/7 en todo el mundo\nAcceso a salas VIP para el titular y un acompañante\nSeguro médico internacional de viaje',
  6, 'amex', 1, 0, NOW()
WHERE NOT EXISTS (SELECT 1 FROM productos p WHERE p.codigo = 'TAR-OBS');

-- Una línea para quien factura a nombre de su empresa.
INSERT INTO productos
  (codigo, nombre, tipo, descripcion, tasa_interes_bp, tasa_min_bp, tasa_max_bp,
   saldo_minimo_cent, ingreso_minimo_cent, linea_desde_cent, membresia_anual_cent,
   beneficios, nivel, marca_emision, activo, publico, creado_en)
SELECT 'TAR-EMP', 'Tarjeta Empresarial', 'tarjeta',
  'Para el gasto de la empresa, separado del personal. Tarjetas adicionales por colaborador y estado de cuenta consolidado.',
  3300, 2800, 3900, 0, 400000, 500000, 12500,
  'Tarjetas adicionales por colaborador, con límite propio cada una\nEstado de cuenta consolidado y por tarjeta\nCategorización del gasto para contabilidad\nPlazo de pago extendido a 45 días',
  3, 'mastercard', 1, 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM productos p WHERE p.codigo = 'TAR-EMP');
