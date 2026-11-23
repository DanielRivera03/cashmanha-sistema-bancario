-- =====================================================================
-- CashMan H.A. v2 — Esquema base
-- Principios:
--   1. El dinero son ENTEROS en centavos (BIGINT). Nunca flotantes.
--   2. El saldo es una PROYECCIÓN del libro mayor, no un dato editable.
--   3. Los asientos y movimientos son INMUTABLES (append-only).
--   4. Toda tabla sensible lleva rastro de auditoría.
-- =====================================================================

DROP DATABASE IF EXISTS cashmanha_v2;
CREATE DATABASE cashmanha_v2
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
USE cashmanha_v2;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- IDENTIDAD Y ACCESO
-- =====================================================================

CREATE TABLE roles (
  id              TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  clave           VARCHAR(40)  NOT NULL UNIQUE,
  nombre          VARCHAR(80)  NOT NULL,
  descripcion     VARCHAR(255) NOT NULL DEFAULT '',
  nivel           TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Mayor nivel = más privilegio'
) ENGINE=InnoDB;

CREATE TABLE permisos (
  id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(80)  NOT NULL UNIQUE,
  descripcion     VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

CREATE TABLE rol_permiso (
  rol_id          TINYINT UNSIGNED  NOT NULL,
  permiso_id      SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (rol_id, permiso_id),
  CONSTRAINT fk_rp_rol     FOREIGN KEY (rol_id)     REFERENCES roles(id)    ON DELETE CASCADE,
  CONSTRAINT fk_rp_permiso FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE usuarios (
  id                        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                      CHAR(36)     NOT NULL UNIQUE,
  usuario                   VARCHAR(64)  NOT NULL UNIQUE,
  correo                    VARCHAR(190) NOT NULL UNIQUE,
  nombres                   VARCHAR(120) NOT NULL,
  apellidos                 VARCHAR(120) NOT NULL,

  -- Argon2id. ~97 chars; 255 deja margen para futuros parámetros.
  password_hash             VARCHAR(255) NOT NULL,
  password_algoritmo        VARCHAR(20)  NOT NULL DEFAULT 'argon2id',
  password_actualizado_en   DATETIME     NULL,
  requiere_cambio_password  TINYINT(1)   NOT NULL DEFAULT 0,

  -- Defensa contra fuerza bruta
  intentos_fallidos         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta           DATETIME     NULL,

  -- Segundo factor (TOTP, RFC 6238)
  totp_secreto_cifrado      VARBINARY(512) NULL,
  totp_activo               TINYINT(1)   NOT NULL DEFAULT 0,
  totp_confirmado_en        DATETIME     NULL,

  rol_id                    TINYINT UNSIGNED NOT NULL,
  estado                    ENUM('activo','inactivo','bloqueado','pendiente') NOT NULL DEFAULT 'pendiente',
  foto_perfil               VARCHAR(255) NULL,
  telefono                  VARCHAR(20)  NULL,
  documento_identidad       VARCHAR(20)  NULL,

  ultimo_acceso_en          DATETIME     NULL,
  ultimo_acceso_ip          VARBINARY(16) NULL,

  creado_en                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_usuario_rol FOREIGN KEY (rol_id) REFERENCES roles(id),
  INDEX idx_estado (estado),
  INDEX idx_rol (rol_id)
) ENGINE=InnoDB;

-- Sesiones en base de datos: permite revocación real e inventario de dispositivos.
CREATE TABLE sesiones (
  id                CHAR(64)     NOT NULL PRIMARY KEY COMMENT 'hash del id de sesión, nunca el id en claro',
  usuario_id        INT UNSIGNED NOT NULL,
  huella_cliente    CHAR(64)     NOT NULL COMMENT 'hash de user-agent + sal',
  ip                VARBINARY(16) NOT NULL,
  user_agent        VARCHAR(255) NOT NULL DEFAULT '',
  creada_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultima_actividad  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en         DATETIME     NOT NULL,
  revocada_en       DATETIME     NULL,
  motivo_revocacion VARCHAR(60)  NULL,
  CONSTRAINT fk_sesion_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_usuario_activa (usuario_id, revocada_en),
  INDEX idx_expira (expira_en)
) ENGINE=InnoDB;

-- Desafíos de un solo uso: OTP de transferencia, recuperación, confirmaciones.
CREATE TABLE desafios (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id     INT UNSIGNED NOT NULL,
  proposito      ENUM('transferencia','recuperacion','alta_tarjeta','cambio_correo','pago_tarjeta') NOT NULL,
  -- Nunca el código en claro: se guarda su hash.
  codigo_hash    CHAR(64)     NOT NULL,
  intentos       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_intentos   TINYINT UNSIGNED NOT NULL DEFAULT 3,
  -- Ata el desafío a una operación concreta: impide reusarlo en otra.
  contexto_hash  CHAR(64)     NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en      DATETIME     NOT NULL,
  consumido_en   DATETIME     NULL,
  CONSTRAINT fk_desafio_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_usuario_proposito (usuario_id, proposito, consumido_en),
  INDEX idx_expira (expira_en)
) ENGINE=InnoDB;

-- Límite de tasa genérico (login, OTP, API).
CREATE TABLE limites_tasa (
  clave          VARCHAR(160) NOT NULL PRIMARY KEY,
  contador       INT UNSIGNED NOT NULL DEFAULT 0,
  ventana_inicio DATETIME     NOT NULL,
  bloqueado_hasta DATETIME    NULL,
  INDEX idx_ventana (ventana_inicio)
) ENGINE=InnoDB;

-- =====================================================================
-- PRODUCTOS Y CUENTAS
-- =====================================================================

CREATE TABLE productos (
  id                 SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codigo             VARCHAR(40)  NOT NULL UNIQUE,
  nombre             VARCHAR(120) NOT NULL,
  tipo               ENUM('ahorro','corriente','credito','tarjeta') NOT NULL,
  descripcion        VARCHAR(500) NOT NULL DEFAULT '',
  tasa_interes_bp    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Puntos base: 550 = 5.50%',
  saldo_minimo_cent  BIGINT       NOT NULL DEFAULT 0,
  activo             TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE cuentas (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid               CHAR(36)     NOT NULL UNIQUE,
  numero_cuenta      VARCHAR(20)  NOT NULL UNIQUE,
  usuario_id         INT UNSIGNED NOT NULL,
  producto_id        SMALLINT UNSIGNED NOT NULL,
  moneda             CHAR(3)      NOT NULL DEFAULT 'USD',

  -- Proyección del libro mayor. Solo la escribe el servicio transaccional.
  -- El CHECK es la última red: la base rechaza un saldo negativo.
  saldo_cent         BIGINT       NOT NULL DEFAULT 0,
  saldo_retenido_cent BIGINT      NOT NULL DEFAULT 0 COMMENT 'Fondos comprometidos aún no liquidados',

  estado             ENUM('activa','bloqueada','cerrada','congelada') NOT NULL DEFAULT 'activa',
  apertura_en        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cierre_en          DATETIME     NULL,

  CONSTRAINT fk_cuenta_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id),
  CONSTRAINT fk_cuenta_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
  CONSTRAINT chk_saldo_no_negativo   CHECK (saldo_cent >= 0),
  CONSTRAINT chk_retenido_no_negativo CHECK (saldo_retenido_cent >= 0),
  INDEX idx_usuario (usuario_id),
  INDEX idx_estado (estado)
) ENGINE=InnoDB;

-- =====================================================================
-- LIBRO MAYOR — partida doble, inmutable
-- =====================================================================

CREATE TABLE asientos (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                CHAR(36)     NOT NULL UNIQUE,

  -- Idempotencia: un reintento con la misma clave NO duplica la operación.
  clave_idempotencia  VARCHAR(80)  NOT NULL UNIQUE,

  tipo                ENUM('transferencia','deposito','retiro','pago_cuota',
                           'consumo_tarjeta','pago_tarjeta','interes','mora',
                           'comision','ajuste','reverso') NOT NULL,
  referencia          VARCHAR(64)  NOT NULL UNIQUE,
  descripcion         VARCHAR(255) NOT NULL DEFAULT '',

  creado_en           DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  creado_por          INT UNSIGNED NOT NULL,
  ip_origen           VARBINARY(16) NOT NULL,

  -- Un reverso apunta al asiento que corrige. Nunca se borra ni edita el original.
  reversa_a           BIGINT UNSIGNED NULL,

  -- Cadena de integridad: hash_actual = SHA256(hash_anterior || contenido).
  -- Alterar una fila rompe la cadena de todas las siguientes.
  hash_anterior       CHAR(64)     NOT NULL,
  hash_actual         CHAR(64)     NOT NULL,

  CONSTRAINT fk_asiento_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id),
  CONSTRAINT fk_asiento_reversa FOREIGN KEY (reversa_a)  REFERENCES asientos(id),
  INDEX idx_tipo_fecha (tipo, creado_en),
  INDEX idx_creado_en (creado_en)
) ENGINE=InnoDB;

CREATE TABLE movimientos (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asiento_id            BIGINT UNSIGNED NOT NULL,
  cuenta_id             INT UNSIGNED NOT NULL,

  -- Negativo = débito, positivo = crédito.
  -- La suma de todos los movimientos de un asiento DEBE ser 0.
  importe_cent          BIGINT       NOT NULL,
  moneda                CHAR(3)      NOT NULL DEFAULT 'USD',

  -- Foto del saldo tras aplicar el movimiento: permite conciliar sin recalcular todo.
  saldo_posterior_cent  BIGINT       NOT NULL,

  descripcion           VARCHAR(255) NOT NULL DEFAULT '',
  creado_en             DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  CONSTRAINT fk_mov_asiento FOREIGN KEY (asiento_id) REFERENCES asientos(id),
  CONSTRAINT fk_mov_cuenta  FOREIGN KEY (cuenta_id)  REFERENCES cuentas(id),
  CONSTRAINT chk_importe_no_cero CHECK (importe_cent <> 0),
  INDEX idx_cuenta_fecha (cuenta_id, creado_en),
  INDEX idx_asiento (asiento_id)
) ENGINE=InnoDB;

-- Límites operativos por rol y producto. Sustituye a los topes en JavaScript.
CREATE TABLE limites_operacion (
  id                     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rol_id                 TINYINT UNSIGNED NULL COMMENT 'NULL = aplica a todos',
  tipo_operacion         VARCHAR(40)  NOT NULL,
  max_por_operacion_cent BIGINT       NOT NULL,
  max_diario_cent        BIGINT       NOT NULL,
  max_mensual_cent       BIGINT       NOT NULL,
  max_ops_diarias        SMALLINT UNSIGNED NOT NULL DEFAULT 50,
  requiere_otp           TINYINT(1)   NOT NULL DEFAULT 1,
  -- Por encima de este monto hace falta aprobación de un segundo usuario.
  umbral_doble_control_cent BIGINT    NULL,
  activo                 TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_limite_rol FOREIGN KEY (rol_id) REFERENCES roles(id),
  UNIQUE KEY uq_rol_operacion (rol_id, tipo_operacion)
) ENGINE=InnoDB;

-- Maker-checker: operaciones de alto valor necesitan segunda firma.
CREATE TABLE aprobaciones (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36)     NOT NULL UNIQUE,
  tipo_operacion VARCHAR(40)  NOT NULL,
  payload_json   JSON         NOT NULL,
  importe_cent   BIGINT       NOT NULL,
  solicitada_por INT UNSIGNED NOT NULL,
  solicitada_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  estado         ENUM('pendiente','aprobada','rechazada','expirada') NOT NULL DEFAULT 'pendiente',
  resuelta_por   INT UNSIGNED NULL,
  resuelta_en    DATETIME     NULL,
  motivo         VARCHAR(255) NULL,
  expira_en      DATETIME     NOT NULL,
  asiento_id     BIGINT UNSIGNED NULL COMMENT 'Asiento generado al aprobarse',
  CONSTRAINT fk_apro_solicitante FOREIGN KEY (solicitada_por) REFERENCES usuarios(id),
  CONSTRAINT fk_apro_resolutor   FOREIGN KEY (resuelta_por)   REFERENCES usuarios(id),
  CONSTRAINT fk_apro_asiento     FOREIGN KEY (asiento_id)     REFERENCES asientos(id),
  INDEX idx_estado (estado, expira_en)
) ENGINE=InnoDB;

-- =====================================================================
-- TARJETAS — tokenizadas, compatibles con PCI-DSS
-- Nunca se almacena el PAN en claro ni el CVV, en ninguna circunstancia.
-- =====================================================================

CREATE TABLE tarjetas (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid               CHAR(36)     NOT NULL UNIQUE,
  usuario_id         INT UNSIGNED NOT NULL,
  cuenta_id          INT UNSIGNED NULL COMMENT 'Cuenta de débito asociada',

  -- Token opaco: es lo único que circula por la aplicación y las URLs.
  token              CHAR(40)     NOT NULL UNIQUE,

  -- PAN cifrado con AES-256-GCM. La clave vive fuera de la base.
  pan_cifrado        VARBINARY(512) NOT NULL,
  -- Hash con pepper: permite detectar duplicados sin poder revertir el PAN.
  pan_hash           CHAR(64)     NOT NULL UNIQUE,
  -- Lo único mostrable.
  ultimos4           CHAR(4)      NOT NULL,
  bin6               CHAR(6)      NOT NULL,
  marca              ENUM('visa','mastercard','amex','discover','desconocida') NOT NULL,
  tipo               ENUM('debito','credito','prepago') NOT NULL,

  titular            VARCHAR(120) NOT NULL,
  expira_mes         TINYINT UNSIGNED NOT NULL,
  expira_anio        SMALLINT UNSIGNED NOT NULL,

  -- Solo para tarjetas de crédito
  limite_cent        BIGINT       NOT NULL DEFAULT 0,
  saldo_usado_cent   BIGINT       NOT NULL DEFAULT 0,
  dia_corte          TINYINT UNSIGNED NOT NULL DEFAULT 25,
  dias_para_pago     TINYINT UNSIGNED NOT NULL DEFAULT 20,

  estado             ENUM('activa','bloqueada','vencida','cancelada','pendiente_activacion')
                     NOT NULL DEFAULT 'pendiente_activacion',
  pin_hash           VARCHAR(255) NULL COMMENT 'Argon2id, nunca el PIN',

  -- Referencia en la pasarela externa (Stripe u otra), si aplica
  pasarela           VARCHAR(30)  NULL,
  pasarela_token     VARCHAR(190) NULL,

  emitida_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activada_en        DATETIME     NULL,
  cancelada_en       DATETIME     NULL,

  CONSTRAINT fk_tarjeta_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_tarjeta_cuenta  FOREIGN KEY (cuenta_id)  REFERENCES cuentas(id),
  CONSTRAINT chk_limite_no_negativo CHECK (limite_cent >= 0),
  CONSTRAINT chk_usado_no_negativo  CHECK (saldo_usado_cent >= 0),
  CONSTRAINT chk_mes_valido CHECK (expira_mes BETWEEN 1 AND 12),
  INDEX idx_usuario (usuario_id),
  INDEX idx_estado (estado)
) ENGINE=InnoDB;

CREATE TABLE tarjeta_movimientos (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tarjeta_id      INT UNSIGNED NOT NULL,
  asiento_id      BIGINT UNSIGNED NULL,
  tipo            ENUM('consumo','pago','reverso','interes','comision','mora') NOT NULL,
  importe_cent    BIGINT       NOT NULL,
  comercio        VARCHAR(120) NOT NULL DEFAULT '',
  categoria       VARCHAR(60)  NOT NULL DEFAULT '',
  referencia      VARCHAR(64)  NOT NULL UNIQUE,
  estado          ENUM('autorizada','liquidada','rechazada','reversada') NOT NULL DEFAULT 'autorizada',
  estado_cuenta_id BIGINT UNSIGNED NULL,
  creado_en       DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_tmov_tarjeta FOREIGN KEY (tarjeta_id) REFERENCES tarjetas(id),
  CONSTRAINT fk_tmov_asiento FOREIGN KEY (asiento_id) REFERENCES asientos(id),
  INDEX idx_tarjeta_fecha (tarjeta_id, creado_en),
  INDEX idx_estado_cuenta (estado_cuenta_id)
) ENGINE=InnoDB;

-- =====================================================================
-- ESTADOS DE CUENTA
-- =====================================================================

CREATE TABLE estados_cuenta (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                CHAR(36)     NOT NULL UNIQUE,
  usuario_id          INT UNSIGNED NOT NULL,
  cuenta_id           INT UNSIGNED NULL,
  tarjeta_id          INT UNSIGNED NULL,
  tipo                ENUM('cuenta','tarjeta','credito') NOT NULL,

  periodo_inicio      DATE         NOT NULL,
  periodo_fin         DATE         NOT NULL,
  fecha_corte         DATE         NOT NULL,
  fecha_limite_pago   DATE         NULL,

  saldo_inicial_cent  BIGINT       NOT NULL DEFAULT 0,
  total_cargos_cent   BIGINT       NOT NULL DEFAULT 0,
  total_abonos_cent   BIGINT       NOT NULL DEFAULT 0,
  saldo_final_cent    BIGINT       NOT NULL DEFAULT 0,
  pago_minimo_cent    BIGINT       NOT NULL DEFAULT 0,
  interes_cent        BIGINT       NOT NULL DEFAULT 0,
  mora_cent           BIGINT       NOT NULL DEFAULT 0,

  cantidad_movimientos INT UNSIGNED NOT NULL DEFAULT 0,
  ruta_pdf            VARCHAR(255) NULL,
  hash_documento      CHAR(64)     NULL COMMENT 'SHA256 del PDF: prueba de no alteración',

  generado_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enviado_en          DATETIME     NULL,
  visto_en            DATETIME     NULL,

  CONSTRAINT fk_ec_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_ec_cuenta  FOREIGN KEY (cuenta_id)  REFERENCES cuentas(id),
  CONSTRAINT fk_ec_tarjeta FOREIGN KEY (tarjeta_id) REFERENCES tarjetas(id),
  UNIQUE KEY uq_periodo (usuario_id, tipo, cuenta_id, tarjeta_id, periodo_fin),
  INDEX idx_usuario_periodo (usuario_id, periodo_fin)
) ENGINE=InnoDB;

-- =====================================================================
-- CRÉDITOS Y CARTERA MOROSA
-- =====================================================================

CREATE TABLE creditos (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid               CHAR(36)     NOT NULL UNIQUE,
  usuario_id         INT UNSIGNED NOT NULL,
  producto_id        SMALLINT UNSIGNED NOT NULL,
  cuenta_desembolso_id INT UNSIGNED NULL,

  monto_cent         BIGINT       NOT NULL,
  saldo_capital_cent BIGINT       NOT NULL,
  tasa_bp            INT UNSIGNED NOT NULL COMMENT 'Puntos base anuales',
  plazo_meses        SMALLINT UNSIGNED NOT NULL,
  cuota_cent         BIGINT       NOT NULL,

  estado             ENUM('solicitado','en_revision','aprobado','desembolsado',
                          'vigente','cancelado','rechazado','castigado') NOT NULL DEFAULT 'solicitado',
  clasificacion      ENUM('A','B','C','D','E') NOT NULL DEFAULT 'A'
                     COMMENT 'Clasificación de riesgo: A=normal … E=irrecuperable',

  aprobado_por       INT UNSIGNED NULL,
  aprobado_en        DATETIME     NULL,
  desembolsado_en    DATETIME     NULL,
  creado_en          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_credito_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id),
  CONSTRAINT fk_credito_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
  CONSTRAINT fk_credito_aprobador FOREIGN KEY (aprobado_por) REFERENCES usuarios(id),
  CONSTRAINT chk_saldo_capital CHECK (saldo_capital_cent >= 0),
  INDEX idx_usuario_estado (usuario_id, estado),
  INDEX idx_clasificacion (clasificacion)
) ENGINE=InnoDB;

CREATE TABLE cuotas (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  credito_id         INT UNSIGNED NOT NULL,
  numero             SMALLINT UNSIGNED NOT NULL,
  capital_cent       BIGINT       NOT NULL,
  interes_cent       BIGINT       NOT NULL,
  mora_cent          BIGINT       NOT NULL DEFAULT 0,
  total_cent         BIGINT       NOT NULL,
  pagado_cent        BIGINT       NOT NULL DEFAULT 0,
  fecha_vencimiento  DATE         NOT NULL,
  pagada_en          DATETIME     NULL,
  estado             ENUM('pendiente','parcial','pagada','vencida','condonada') NOT NULL DEFAULT 'pendiente',
  dias_mora          SMALLINT     NOT NULL DEFAULT 0,
  CONSTRAINT fk_cuota_credito FOREIGN KEY (credito_id) REFERENCES creditos(id) ON DELETE CASCADE,
  UNIQUE KEY uq_credito_numero (credito_id, numero),
  INDEX idx_vencimiento_estado (fecha_vencimiento, estado)
) ENGINE=InnoDB;

-- =====================================================================
-- NOTIFICACIONES — alimentan el canal SSE en tiempo real
-- =====================================================================

CREATE TABLE notificaciones (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT UNSIGNED NOT NULL,
  tipo          VARCHAR(50)  NOT NULL,
  titulo        VARCHAR(160) NOT NULL,
  mensaje       VARCHAR(500) NOT NULL,
  datos_json    JSON         NULL,
  severidad     ENUM('info','exito','alerta','critico') NOT NULL DEFAULT 'info',
  url_destino   VARCHAR(255) NULL,
  leida_en      DATETIME     NULL,
  creada_en     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_notif_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  -- El canal SSE pagina por id: índice crítico para el rendimiento.
  INDEX idx_usuario_id_desc (usuario_id, id),
  INDEX idx_no_leidas (usuario_id, leida_en)
) ENGINE=InnoDB;

-- =====================================================================
-- AUDITORÍA — append-only, encadenada por hash
-- =====================================================================

CREATE TABLE auditoria (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id     INT UNSIGNED NULL COMMENT 'NULL en eventos anónimos (login fallido)',
  accion         VARCHAR(80)  NOT NULL,
  entidad        VARCHAR(60)  NOT NULL DEFAULT '',
  entidad_id     VARCHAR(64)  NOT NULL DEFAULT '',
  resultado      ENUM('exito','fallo','denegado') NOT NULL,
  detalle_json   JSON         NULL,
  ip             VARBINARY(16) NOT NULL,
  user_agent     VARCHAR(255) NOT NULL DEFAULT '',
  creado_en      DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  hash_anterior  CHAR(64)     NOT NULL,
  hash_actual    CHAR(64)     NOT NULL,
  INDEX idx_usuario_fecha (usuario_id, creado_en),
  INDEX idx_accion_fecha (accion, creado_en),
  INDEX idx_resultado (resultado, creado_en)
) ENGINE=InnoDB;

-- =====================================================================
-- VISTAS DE CONSULTA (solo lectura, nunca se actualizan)
-- =====================================================================

-- Verificación de la invariante contable: debe devolver CERO filas siempre.
CREATE OR REPLACE VIEW v_asientos_descuadrados AS
SELECT a.id, a.uuid, a.referencia, a.tipo, SUM(m.importe_cent) AS descuadre_cent
FROM asientos a
JOIN movimientos m ON m.asiento_id = a.id
GROUP BY a.id, a.uuid, a.referencia, a.tipo
HAVING SUM(m.importe_cent) <> 0;

-- Contraste entre el saldo proyectado y la suma real del libro mayor.
CREATE OR REPLACE VIEW v_conciliacion_saldos AS
SELECT
  c.id            AS cuenta_id,
  c.numero_cuenta,
  c.saldo_cent    AS saldo_proyectado_cent,
  COALESCE(SUM(m.importe_cent), 0) AS saldo_libro_cent,
  c.saldo_cent - COALESCE(SUM(m.importe_cent), 0) AS diferencia_cent
FROM cuentas c
LEFT JOIN movimientos m ON m.cuenta_id = c.id
GROUP BY c.id, c.numero_cuenta, c.saldo_cent;

-- Cartera morosa, con días de atraso y clasificación de riesgo.
CREATE OR REPLACE VIEW v_cartera_morosa AS
SELECT
  u.id                AS usuario_id,
  u.uuid              AS usuario_uuid,
  CONCAT(u.nombres, ' ', u.apellidos) AS cliente,
  u.correo,
  u.telefono,
  cr.id               AS credito_id,
  cr.uuid             AS credito_uuid,
  cr.clasificacion,
  COUNT(q.id)         AS cuotas_vencidas,
  SUM(q.total_cent - q.pagado_cent) AS deuda_vencida_cent,
  SUM(q.mora_cent)    AS mora_acumulada_cent,
  MIN(q.fecha_vencimiento) AS vencimiento_mas_antiguo,
  DATEDIFF(CURDATE(), MIN(q.fecha_vencimiento)) AS dias_mora_max,
  CASE
    WHEN DATEDIFF(CURDATE(), MIN(q.fecha_vencimiento)) <= 30  THEN 'A'
    WHEN DATEDIFF(CURDATE(), MIN(q.fecha_vencimiento)) <= 60  THEN 'B'
    WHEN DATEDIFF(CURDATE(), MIN(q.fecha_vencimiento)) <= 90  THEN 'C'
    WHEN DATEDIFF(CURDATE(), MIN(q.fecha_vencimiento)) <= 180 THEN 'D'
    ELSE 'E'
  END AS clasificacion_sugerida
FROM usuarios u
JOIN creditos cr ON cr.usuario_id = u.id AND cr.estado = 'vigente'
JOIN cuotas  q  ON q.credito_id = cr.id
WHERE q.estado IN ('pendiente','parcial','vencida')
  AND q.fecha_vencimiento < CURDATE()
GROUP BY u.id, u.uuid, u.nombres, u.apellidos, u.correo, u.telefono,
         cr.id, cr.uuid, cr.clasificacion;

-- Resumen financiero por cliente, para el panel principal.
CREATE OR REPLACE VIEW v_resumen_cliente AS
SELECT
  u.id AS usuario_id,
  u.uuid,
  CONCAT(u.nombres, ' ', u.apellidos) AS nombre_completo,
  (SELECT COALESCE(SUM(saldo_cent),0) FROM cuentas WHERE usuario_id = u.id AND estado = 'activa')
    AS total_ahorros_cent,
  (SELECT COUNT(*) FROM cuentas WHERE usuario_id = u.id AND estado = 'activa')
    AS cuentas_activas,
  (SELECT COUNT(*) FROM tarjetas WHERE usuario_id = u.id AND estado = 'activa')
    AS tarjetas_activas,
  (SELECT COALESCE(SUM(saldo_usado_cent),0) FROM tarjetas WHERE usuario_id = u.id AND estado = 'activa')
    AS deuda_tarjetas_cent,
  (SELECT COALESCE(SUM(saldo_capital_cent),0) FROM creditos WHERE usuario_id = u.id AND estado = 'vigente')
    AS deuda_creditos_cent,
  (SELECT COUNT(*) FROM notificaciones WHERE usuario_id = u.id AND leida_en IS NULL)
    AS notificaciones_sin_leer
FROM usuarios u;
