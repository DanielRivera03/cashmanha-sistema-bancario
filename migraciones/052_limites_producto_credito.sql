-- ---------------------------------------------------------------------
-- Cada producto de crédito tiene su propio rango.
--
-- El formulario ofrecía el mismo techo para todo —cincuenta mil— y los
-- mismos doce plazos, desde seis meses hasta veinte años. Eso no es un
-- catálogo: un hipotecario a seis meses no existe, y un consumo a tres
-- millones tampoco. El rango es una condición del producto, como su
-- tasa, y vivía en una línea del HTML.
--
-- Con esto el deslizador se mueve entre lo que ESE producto admite, y
-- los plazos que se ofrecen son los que ese producto tiene. Lo que el
-- cliente pueda pedir de verdad, dentro de ese rango, sigue saliendo de
-- su capacidad de pago y de su banda de riesgo.
-- ---------------------------------------------------------------------

ALTER TABLE productos
  ADD COLUMN monto_minimo_cent BIGINT UNSIGNED NOT NULL DEFAULT 0
      COMMENT 'Monto mínimo que se concede en este producto'
      AFTER linea_desde_cent,
  ADD COLUMN monto_maximo_cent BIGINT UNSIGNED NOT NULL DEFAULT 0
      COMMENT 'Techo del producto. 0 = sin tope propio'
      AFTER monto_minimo_cent,
  ADD COLUMN plazo_minimo SMALLINT UNSIGNED NOT NULL DEFAULT 6
      AFTER monto_maximo_cent,
  ADD COLUMN plazo_maximo SMALLINT UNSIGNED NOT NULL DEFAULT 60
      AFTER plazo_minimo;

-- Consumo: montos cortos y plazos cortos.
UPDATE productos
   SET monto_minimo_cent =     30000,   -- $300
       monto_maximo_cent =   7500000,   -- $75,000
       plazo_minimo = 6,
       plazo_maximo = 72
 WHERE codigo = 'CRE-PER';

-- Vivienda: el de monto mayor y plazo más largo de la casa.
UPDATE productos
   SET monto_minimo_cent =   1500000,   -- $15,000
       monto_maximo_cent = 300000000,   -- $3,000,000
       plazo_minimo = 60,
       plazo_maximo = 360
 WHERE codigo = 'CRE-HIP';

-- --- Líneas de crédito nuevas -----------------------------------------
--
-- Con un solo consumo y un solo hipotecario, todo lo que no fuera una
-- casa o un gasto personal no tenía dónde entrar: un auto se pedía
-- como consumo al 22% y a 72 meses, que no es el producto.

INSERT INTO productos
  (codigo, nombre, tipo, descripcion, tasa_interes_bp, tasa_min_bp, tasa_max_bp,
   saldo_minimo_cent, ingreso_minimo_cent, linea_desde_cent,
   monto_minimo_cent, monto_maximo_cent, plazo_minimo, plazo_maximo,
   membresia_anual_cent, beneficios, nivel, activo, publico, creado_en)
SELECT 'CRE-VEH', 'Crédito de Vehículo', 'credito',
  'Para comprar su vehículo, nuevo o usado. El vehículo queda en garantía, y por eso la tasa es menor que la de un consumo.',
  1500, 1200, 2000, 0, 60000, 0,
  500000, 7500000, 12, 96, 0,
  'Tasa menor que un crédito de consumo\nHasta 8 años de plazo\nSeguro del vehículo financiable con el mismo crédito\nSin penalidad por pago anticipado',
  2, 1, 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM productos p WHERE p.codigo = 'CRE-VEH');

INSERT INTO productos
  (codigo, nombre, tipo, descripcion, tasa_interes_bp, tasa_min_bp, tasa_max_bp,
   saldo_minimo_cent, ingreso_minimo_cent, linea_desde_cent,
   monto_minimo_cent, monto_maximo_cent, plazo_minimo, plazo_maximo,
   membresia_anual_cent, beneficios, nivel, activo, publico, creado_en)
SELECT 'CRE-PYM', 'Crédito para Negocio', 'credito',
  'Capital de trabajo o inversión para su negocio. Se evalúa con los ingresos del negocio, no solo con el salario.',
  1900, 1500, 2600, 0, 80000, 0,
  200000, 50000000, 12, 120, 0,
  'Se evalúa con los ingresos del negocio\nPeriodo de gracia de hasta 3 meses en inversión\nDesembolso en una o varias entregas\nSin penalidad por pago anticipado',
  3, 1, 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM productos p WHERE p.codigo = 'CRE-PYM');

INSERT INTO productos
  (codigo, nombre, tipo, descripcion, tasa_interes_bp, tasa_min_bp, tasa_max_bp,
   saldo_minimo_cent, ingreso_minimo_cent, linea_desde_cent,
   monto_minimo_cent, monto_maximo_cent, plazo_minimo, plazo_maximo,
   membresia_anual_cent, beneficios, nivel, activo, publico, creado_en)
SELECT 'CRE-EDU', 'Crédito Educativo', 'credito',
  'Para estudios propios o de su familia. Durante los estudios se paga solo el interés, y el capital empieza al terminar.',
  1200, 900, 1600, 0, 40000, 0,
  50000, 5000000, 12, 120, 0,
  'Durante los estudios paga solo intereses\nTasa preferente, de las más bajas de la casa\nDesembolso por ciclo o por año\nSin penalidad por pago anticipado',
  2, 1, 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM productos p WHERE p.codigo = 'CRE-EDU');
