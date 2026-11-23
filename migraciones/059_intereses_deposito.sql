-- ---------------------------------------------------------------------
-- El interés de las cuentas de ahorro.
--
-- Los productos de depósito declaran su tasa desde el primer día:
-- 4.50% anual la Cuenta de Ahorro Personal, 3.75% la Empresarial. La
-- pantalla de caja la enseña al abrir una cuenta y el sitio público la
-- anuncia. Y no había nada que la pagara: ni un proceso, ni un asiento,
-- ni una línea en el estado de cuenta. La tasa era un rótulo.
--
-- Esto es lo que falta para que exista de verdad:
--
--   · una cuenta técnica contra la que se carga el gasto, porque el
--     abono al cliente tiene que tener contrapartida o el asiento no
--     cuadra;
--   · el registro de cada devengo, con la base sobre la que se calculó,
--     para poder explicarle a un cliente de dónde salieron sus $3.47;
--   · el beneficio de segmento, que es lo que se pidió encima.
--
-- Se calcula sobre el SALDO DIARIO PROMEDIO del período, que es como lo
-- hace un banco: pagar sobre el saldo final premiaría a quien deposita
-- el día 30 y retira el día 2, y pagar sobre el saldo mínimo castigaría
-- a quien usa su cuenta.
-- ---------------------------------------------------------------------

-- --- La cuenta técnica del gasto ---------------------------------------
--
-- Se carga con lo que se le abona a los clientes. Su saldo negativo es
-- el gasto acumulado por intereses de depósito, igual que las otras
-- técnicas del sistema.

INSERT INTO cuentas
  (uuid, numero_cuenta, usuario_id, producto_id, moneda, saldo_cent,
   saldo_retenido_cent, estado, es_tecnica, apertura_en)
SELECT UUID(), '0000000004',
       (SELECT id FROM usuarios ORDER BY id LIMIT 1),
       (SELECT id FROM productos WHERE tipo = 'ahorro' ORDER BY id LIMIT 1),
       'USD', 0, 0, 'activa', 1, NOW()
 WHERE NOT EXISTS (SELECT 1 FROM cuentas WHERE numero_cuenta = '0000000004');

INSERT INTO parametros (clave, valor, descripcion)
SELECT 'contabilidad.cuenta_intereses_deposito', '0000000004',
       'Cuenta tecnica que recoge el gasto por intereses pagados a los depositantes'
 WHERE NOT EXISTS (
         SELECT 1 FROM parametros WHERE clave = 'contabilidad.cuenta_intereses_deposito'
       );

-- --- Las reglas del devengo --------------------------------------------

INSERT INTO parametros (clave, valor, descripcion)
SELECT * FROM (
  SELECT 'deposito.saldo_minimo_para_interes_cent' AS clave, '10000' AS valor,
         'Saldo diario promedio minimo para generar interes. Por debajo no se abona nada: la prima seria de centavos y el asiento cuesta mas que el abono.' AS descripcion
  UNION ALL SELECT 'deposito.base_dias_anio', '360',
         'Base de dias del anio para el calculo (360 comercial, como la banca local)'
  UNION ALL SELECT 'deposito.retencion_bp', '1000',
         'Retencion sobre intereses, en puntos base. 1000 = 10%.'
  UNION ALL SELECT 'deposito.retencion_exenta_cent', '5000',
         'Interes mensual por debajo del cual no se retiene nada'
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM parametros p WHERE p.clave = nuevos.clave);

-- --- El registro de cada devengo ---------------------------------------

CREATE TABLE IF NOT EXISTS cuenta_intereses (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cuenta_id      INT UNSIGNED NOT NULL,

  periodo_desde  DATE NOT NULL,
  periodo_hasta  DATE NOT NULL,
  dias           SMALLINT UNSIGNED NOT NULL,

  -- La base del cálculo. Guardarla es lo que permite contestar «¿y de
  -- dónde salieron mis $3.47?» sin rehacer el período entero.
  saldo_promedio_cent BIGINT NOT NULL,

  tasa_producto_bp SMALLINT UNSIGNED NOT NULL,
  tasa_extra_bp    SMALLINT UNSIGNED NOT NULL DEFAULT 0
                   COMMENT 'Puntos base que suma su categoria de banca preferencial',
  tasa_aplicada_bp SMALLINT UNSIGNED NOT NULL,
  segmento         VARCHAR(16) NOT NULL DEFAULT 'estandar',

  interes_bruto_cent BIGINT NOT NULL,
  retencion_cent     BIGINT NOT NULL DEFAULT 0,
  interes_neto_cent  BIGINT NOT NULL,

  asiento_id     BIGINT UNSIGNED NULL,
  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_interes_cuenta
    FOREIGN KEY (cuenta_id) REFERENCES cuentas (id)
    ON DELETE CASCADE ON UPDATE CASCADE,

  -- Una cuenta no puede devengar dos veces el mismo período. Es la
  -- garantía de que correr el proceso dos veces no paga dos veces.
  UNIQUE KEY uq_interes_periodo (cuenta_id, periodo_hasta),

  INDEX ix_interes_cuenta (cuenta_id, periodo_hasta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- El beneficio de segmento ------------------------------------------
--
-- Los puntos extra que cada escalón suma a la tasa del depósito viven
-- en `Segmento::BENEFICIOS` como `tasa_deposito_extra_bp`, junto a los
-- demás. No se ponen aquí como parámetro suelto: tenerlos separados del
-- resto de beneficios es como acaban diciendo cosas distintas la
-- pantalla y el proceso.
