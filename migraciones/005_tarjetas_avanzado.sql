-- =====================================================================
-- CashMan H.A. v2 — Funcionalidades avanzadas de tarjetas
--
--   · Revelación temporal de datos sensibles mediante token de un uso
--   · Compras diferidas a cuotas (incluida la modalidad tasa cero)
--   · Traslado de saldo entre tarjetas
--   · Cortes mensuales y control de límites
-- =====================================================================
USE cashmanha_v2;

-- ---------------------------------------------------------------------
-- TOKENS DE REVELACIÓN
--
-- Para mostrar el PAN completo hace falta un token de un solo uso,
-- con vida muy corta, atado a la tarjeta, al usuario y a la sesión.
-- Así, ver los datos de una tarjeta queda registrado y es irrepetible:
-- un token filtrado no sirve ni en otra sesión ni dos veces.
-- ---------------------------------------------------------------------
CREATE TABLE tarjeta_revelaciones (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tarjeta_id    INT UNSIGNED NOT NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  token_hash    CHAR(64)     NOT NULL UNIQUE,
  sesion_hash   CHAR(64)     NOT NULL,
  ip            VARBINARY(16) NOT NULL,
  campos        VARCHAR(60)  NOT NULL DEFAULT 'pan',
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en     DATETIME     NOT NULL,
  usado_en      DATETIME     NULL,
  CONSTRAINT fk_rev_tarjeta FOREIGN KEY (tarjeta_id) REFERENCES tarjetas(id) ON DELETE CASCADE,
  CONSTRAINT fk_rev_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_expira (expira_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- COMPRAS DIFERIDAS A CUOTAS
--
-- Un consumo se convierte en un plan de pagos. La modalidad "tasa
-- cero" reparte el monto sin interés; las demás aplican la tasa
-- pactada al momento de diferir, que queda congelada en el plan.
-- ---------------------------------------------------------------------
CREATE TABLE tarjeta_planes (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid               CHAR(36)     NOT NULL UNIQUE,
  folio              VARCHAR(24)  NOT NULL UNIQUE,
  tarjeta_id         INT UNSIGNED NOT NULL,
  movimiento_id      BIGINT UNSIGNED NULL COMMENT 'Consumo que se difirió',
  tipo               ENUM('tasa_cero','con_interes','traslado') NOT NULL DEFAULT 'tasa_cero',
  monto_cent         BIGINT       NOT NULL,
  interes_total_cent BIGINT       NOT NULL DEFAULT 0,
  total_cent         BIGINT       NOT NULL,
  pagado_cent        BIGINT       NOT NULL DEFAULT 0,
  cuotas             TINYINT UNSIGNED NOT NULL,
  cuotas_pagadas     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  cuota_cent         BIGINT       NOT NULL,
  tasa_bp            INT UNSIGNED NOT NULL DEFAULT 0,
  comercio           VARCHAR(120) NOT NULL DEFAULT '',
  estado             ENUM('vigente','cancelado','liquidado') NOT NULL DEFAULT 'vigente',
  creado_en          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  liquidado_en       DATETIME     NULL,
  CONSTRAINT fk_plan_tarjeta FOREIGN KEY (tarjeta_id)    REFERENCES tarjetas(id) ON DELETE CASCADE,
  CONSTRAINT fk_plan_mov     FOREIGN KEY (movimiento_id) REFERENCES tarjeta_movimientos(id) ON DELETE SET NULL,
  CONSTRAINT chk_plan_cuotas CHECK (cuotas BETWEEN 2 AND 48),
  INDEX idx_tarjeta_estado (tarjeta_id, estado)
) ENGINE=InnoDB;

CREATE TABLE tarjeta_plan_cuotas (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id           BIGINT UNSIGNED NOT NULL,
  numero            TINYINT UNSIGNED NOT NULL,
  capital_cent      BIGINT       NOT NULL,
  interes_cent      BIGINT       NOT NULL DEFAULT 0,
  total_cent        BIGINT       NOT NULL,
  pagado_cent       BIGINT       NOT NULL DEFAULT 0,
  fecha_corte       DATE         NOT NULL,
  estado            ENUM('pendiente','pagada','vencida') NOT NULL DEFAULT 'pendiente',
  pagada_en         DATETIME     NULL,
  CONSTRAINT fk_pc_plan FOREIGN KEY (plan_id) REFERENCES tarjeta_planes(id) ON DELETE CASCADE,
  UNIQUE KEY uq_plan_numero (plan_id, numero),
  INDEX idx_corte (fecha_corte, estado)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CORTES MENSUALES DE TARJETA
-- ---------------------------------------------------------------------
CREATE TABLE tarjeta_cortes (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                CHAR(36)     NOT NULL UNIQUE,
  tarjeta_id          INT UNSIGNED NOT NULL,
  periodo_inicio      DATE         NOT NULL,
  periodo_fin         DATE         NOT NULL,
  fecha_corte         DATE         NOT NULL,
  fecha_limite_pago   DATE         NOT NULL,
  saldo_anterior_cent BIGINT       NOT NULL DEFAULT 0,
  consumos_cent       BIGINT       NOT NULL DEFAULT 0,
  pagos_cent          BIGINT       NOT NULL DEFAULT 0,
  intereses_cent      BIGINT       NOT NULL DEFAULT 0,
  comisiones_cent     BIGINT       NOT NULL DEFAULT 0,
  mora_cent           BIGINT       NOT NULL DEFAULT 0,
  saldo_final_cent    BIGINT       NOT NULL DEFAULT 0,
  pago_minimo_cent    BIGINT       NOT NULL DEFAULT 0,
  pago_contado_cent   BIGINT       NOT NULL DEFAULT 0,
  movimientos         INT UNSIGNED NOT NULL DEFAULT 0,
  estado              ENUM('abierto','cerrado','pagado','vencido') NOT NULL DEFAULT 'cerrado',
  generado_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_corte_tarjeta FOREIGN KEY (tarjeta_id) REFERENCES tarjetas(id) ON DELETE CASCADE,
  UNIQUE KEY uq_tarjeta_periodo (tarjeta_id, periodo_fin),
  INDEX idx_estado (estado, fecha_limite_pago)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- AMPLIACIONES A tarjetas
-- ---------------------------------------------------------------------
ALTER TABLE tarjetas
  ADD COLUMN alias VARCHAR(40) NULL AFTER titular,
  ADD COLUMN limite_diario_cent BIGINT NOT NULL DEFAULT 100000 AFTER limite_cent,
  ADD COLUMN compras_internet TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN compras_exterior TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN contactless TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN congelada_hasta DATETIME NULL COMMENT 'Bloqueo temporal por el titular',
  ADD COLUMN tasa_diferido_bp INT UNSIGNED NOT NULL DEFAULT 1800,
  ADD COLUMN ultimo_corte DATE NULL,
  ADD COLUMN proximo_corte DATE NULL;

ALTER TABLE tarjeta_movimientos
  ADD COLUMN plan_id BIGINT UNSIGNED NULL AFTER asiento_id,
  ADD COLUMN corte_id BIGINT UNSIGNED NULL,
  ADD COLUMN diferible TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN ciudad VARCHAR(80) NOT NULL DEFAULT '',
  ADD CONSTRAINT fk_tmov_plan  FOREIGN KEY (plan_id)  REFERENCES tarjeta_planes(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_tmov_corte FOREIGN KEY (corte_id) REFERENCES tarjeta_cortes(id) ON DELETE SET NULL;

-- Inicializar fechas de corte de las tarjetas ya emitidas
UPDATE tarjetas
   SET proximo_corte = DATE_ADD(
         DATE_FORMAT(CURDATE(), '%Y-%m-01'),
         INTERVAL (LEAST(dia_corte, DAY(LAST_DAY(CURDATE()))) - 1) DAY)
 WHERE proximo_corte IS NULL;

UPDATE tarjetas
   SET proximo_corte = DATE_ADD(proximo_corte, INTERVAL 1 MONTH)
 WHERE proximo_corte < CURDATE();

-- ---------------------------------------------------------------------
-- PERMISOS
-- ---------------------------------------------------------------------
INSERT IGNORE INTO permisos (clave, descripcion) VALUES
  ('tarjeta.ver_datos',    'Revelar los datos completos de su tarjeta'),
  ('tarjeta.diferir',      'Diferir compras a cuotas'),
  ('tarjeta.trasladar',    'Trasladar saldo entre tarjetas'),
  ('tarjeta.configurar',   'Cambiar límites y controles de la tarjeta'),
  ('tarjeta.corte',        'Generar cortes de tarjeta');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) SELECT 1, id FROM permisos;

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) SELECT 5, id FROM permisos
 WHERE clave IN ('tarjeta.ver_datos','tarjeta.diferir','tarjeta.trasladar','tarjeta.configurar');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) SELECT 4, id FROM permisos
 WHERE clave IN ('tarjeta.corte','tarjeta.configurar');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) SELECT 3, id FROM permisos
 WHERE clave IN ('tarjeta.corte','tarjeta.configurar');

-- ---------------------------------------------------------------------
-- VISTAS
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_tarjetas_detalle AS
SELECT
  t.id, t.uuid, t.usuario_id, t.ultimos4, t.bin6, t.marca, t.tipo, t.titular, t.alias,
  t.expira_mes, t.expira_anio, t.estado, t.limite_cent, t.saldo_usado_cent,
  t.limite_diario_cent, t.dia_corte, t.dias_para_pago, t.tasa_diferido_bp,
  t.compras_internet, t.compras_exterior, t.contactless, t.congelada_hasta,
  t.proximo_corte, t.emitida_en,
  GREATEST(0, t.limite_cent - t.saldo_usado_cent) AS disponible_cent,
  CASE WHEN t.limite_cent > 0
       THEN ROUND(t.saldo_usado_cent * 100.0 / t.limite_cent, 1) ELSE 0 END AS uso_pct,
  CONCAT(u.nombres,' ',u.apellidos) AS propietario,
  u.correo AS propietario_correo,
  (SELECT COUNT(*) FROM tarjeta_planes p
    WHERE p.tarjeta_id = t.id AND p.estado = 'vigente')          AS planes_vigentes,
  (SELECT COALESCE(SUM(p.total_cent - p.pagado_cent),0) FROM tarjeta_planes p
    WHERE p.tarjeta_id = t.id AND p.estado = 'vigente')          AS saldo_diferido_cent,
  (SELECT COUNT(*) FROM tarjeta_movimientos m
    WHERE m.tarjeta_id = t.id AND m.tipo = 'consumo'
      AND m.plan_id IS NULL AND m.diferible = 1
      AND m.creado_en > DATE_SUB(NOW(), INTERVAL 90 DAY))        AS consumos_diferibles
FROM tarjetas t
JOIN usuarios u ON u.id = t.usuario_id;

CREATE OR REPLACE VIEW v_planes_diferidos AS
SELECT
  p.*, t.ultimos4, t.marca, t.usuario_id,
  (p.total_cent - p.pagado_cent) AS pendiente_cent,
  (SELECT MIN(c.fecha_corte) FROM tarjeta_plan_cuotas c
    WHERE c.plan_id = p.id AND c.estado = 'pendiente') AS proxima_cuota,
  (p.cuotas - p.cuotas_pagadas) AS cuotas_restantes
FROM tarjeta_planes p
JOIN tarjetas t ON t.id = p.tarjeta_id;
