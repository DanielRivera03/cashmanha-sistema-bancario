-- =====================================================================
-- CashMan H.A. v2 — Módulos completos del sistema base
--
-- Añade todo lo que el sistema original tenía y faltaba en v2:
--   detalle de clientes, bitácora de accesos, mensajería interna,
--   tickets de soporte, referencias personales, datos de vehículos,
--   históricos, depósitos/retiros y recuperación de cuentas.
--
-- Todo con las correcciones de fondo: dinero en centavos enteros,
-- claves foráneas reales, índices donde importan, y sin columnas
-- que guarden datos sensibles en claro.
-- =====================================================================
USE cashmanha_v2;

-- ---------------------------------------------------------------------
-- DETALLE DE CLIENTES (KYC)
-- ---------------------------------------------------------------------
CREATE TABLE detalle_usuarios (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id          INT UNSIGNED NOT NULL UNIQUE,
  dui                 VARCHAR(12)  NULL,
  nit                 VARCHAR(20)  NULL,
  telefono            VARCHAR(20)  NULL,
  celular             VARCHAR(20)  NULL,
  telefono_trabajo    VARCHAR(20)  NULL,
  direccion           VARCHAR(255) NOT NULL DEFAULT '',
  empresa             VARCHAR(255) NOT NULL DEFAULT '',
  cargo               VARCHAR(255) NOT NULL DEFAULT '',
  direccion_trabajo   VARCHAR(255) NOT NULL DEFAULT '',
  ingreso_mensual_cent BIGINT      NOT NULL DEFAULT 0,
  fecha_nacimiento    DATE         NULL,
  genero              CHAR(1)      NULL,
  estado_civil        VARCHAR(30)  NULL,
  -- Rutas a storage/, FUERA del webroot. Se sirven por PHP con
  -- verificación de permiso, nunca directamente por Apache.
  foto_dui_frontal    VARCHAR(255) NULL,
  foto_dui_reverso    VARCHAR(255) NULL,
  foto_nit            VARCHAR(255) NULL,
  foto_firma          VARCHAR(255) NULL,
  perfil_completo     TINYINT(1)   NOT NULL DEFAULT 0,
  actualizado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_det_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_dui (dui)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- BITÁCORA DE ACCESOS
-- El sistema viejo guardaba php_uname(), el nombre del SERVIDOR,
-- idéntico en las 158 filas. Aquí se registra el cliente real.
-- ---------------------------------------------------------------------
CREATE TABLE accesos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT UNSIGNED NOT NULL,
  ip            VARBINARY(16) NOT NULL,
  user_agent    VARCHAR(255) NOT NULL DEFAULT '',
  navegador     VARCHAR(60)  NOT NULL DEFAULT '',
  sistema       VARCHAR(60)  NOT NULL DEFAULT '',
  dispositivo   VARCHAR(40)  NOT NULL DEFAULT '',
  exitoso       TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_acc_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_usuario_fecha (usuario_id, creado_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- MENSAJERÍA INTERNA
-- ---------------------------------------------------------------------
CREATE TABLE mensajes (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36)     NOT NULL UNIQUE,
  remitente_id    INT UNSIGNED NOT NULL,
  destinatario_id INT UNSIGNED NOT NULL,
  asunto          VARCHAR(160) NOT NULL,
  cuerpo          TEXT         NOT NULL,
  leido_en        DATETIME     NULL,
  archivado_rem   TINYINT(1)   NOT NULL DEFAULT 0,
  archivado_dest  TINYINT(1)   NOT NULL DEFAULT 0,
  responde_a      BIGINT UNSIGNED NULL,
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_msg_rem  FOREIGN KEY (remitente_id)    REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_dest FOREIGN KEY (destinatario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_hilo FOREIGN KEY (responde_a)      REFERENCES mensajes(id) ON DELETE SET NULL,
  INDEX idx_bandeja (destinatario_id, archivado_dest, leido_en),
  INDEX idx_enviados (remitente_id, archivado_rem)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- TICKETS DE SOPORTE
-- ---------------------------------------------------------------------
CREATE TABLE tickets (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36)     NOT NULL UNIQUE,
  folio           VARCHAR(24)  NOT NULL UNIQUE,
  usuario_id      INT UNSIGNED NOT NULL,
  asignado_a      INT UNSIGNED NULL,
  categoria       ENUM('acceso','transaccion','tarjeta','credito','sistema','otro') NOT NULL DEFAULT 'otro',
  prioridad       ENUM('baja','media','alta','critica') NOT NULL DEFAULT 'media',
  titulo          VARCHAR(160) NOT NULL,
  descripcion     TEXT         NOT NULL,
  estado          ENUM('abierto','en_proceso','resuelto','no_resuelto','cerrado') NOT NULL DEFAULT 'abierto',
  resolucion      TEXT         NULL,
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultima_actividad DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  cerrado_en      DATETIME     NULL,
  CONSTRAINT fk_tic_usuario  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_tic_asignado FOREIGN KEY (asignado_a) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_estado (estado, prioridad),
  INDEX idx_usuario (usuario_id)
) ENGINE=InnoDB;

CREATE TABLE ticket_respuestas (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ticket_id  BIGINT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  cuerpo     TEXT         NOT NULL,
  interna    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Nota visible solo para el personal',
  creado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tr_ticket  FOREIGN KEY (ticket_id)  REFERENCES tickets(id)  ON DELETE CASCADE,
  CONSTRAINT fk_tr_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_ticket (ticket_id, creado_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- REFERENCIAS PERSONALES (expediente crediticio)
-- El sistema viejo las tenía como columnas repetidas en una fila
-- (nombres_referencia1, nombres_referencia2...). Aquí es una tabla,
-- que permite N referencias sin tocar el esquema.
-- ---------------------------------------------------------------------
CREATE TABLE referencias (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  credito_id  INT UNSIGNED NOT NULL,
  tipo        ENUM('personal','laboral','familiar','comercial') NOT NULL DEFAULT 'personal',
  nombres     VARCHAR(120) NOT NULL,
  apellidos   VARCHAR(120) NOT NULL,
  empresa     VARCHAR(160) NOT NULL DEFAULT '',
  profesion   VARCHAR(120) NOT NULL DEFAULT '',
  telefono    VARCHAR(20)  NOT NULL DEFAULT '',
  parentesco  VARCHAR(60)  NOT NULL DEFAULT '',
  verificada  TINYINT(1)   NOT NULL DEFAULT 0,
  CONSTRAINT fk_ref_credito FOREIGN KEY (credito_id) REFERENCES creditos(id) ON DELETE CASCADE,
  INDEX idx_credito (credito_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- GARANTÍAS (vehículos, hipotecas)
-- ---------------------------------------------------------------------
CREATE TABLE garantias (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  credito_id      INT UNSIGNED NOT NULL,
  tipo            ENUM('vehiculo','inmueble','fiduciaria','prendaria') NOT NULL,
  descripcion     VARCHAR(255) NOT NULL DEFAULT '',
  valor_cent      BIGINT       NOT NULL DEFAULT 0,
  -- Vehículos
  marca           VARCHAR(80)  NULL,
  modelo          VARCHAR(80)  NULL,
  anio            SMALLINT     NULL,
  placa           VARCHAR(20)  NULL,
  chasis          VARCHAR(40)  NULL,
  motor           VARCHAR(40)  NULL,
  color           VARCHAR(40)  NULL,
  -- Inmuebles
  matricula       VARCHAR(60)  NULL,
  ubicacion       VARCHAR(255) NULL,
  area_m2         DECIMAL(10,2) NULL,
  CONSTRAINT fk_gar_credito FOREIGN KEY (credito_id) REFERENCES creditos(id) ON DELETE CASCADE,
  INDEX idx_credito (credito_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SOLICITUDES DE CRÉDITO (flujo de aprobación por etapas)
-- El sistema viejo manejaba "primer paso gerencia" y "segundo paso
-- presidencia" como casos sueltos del controlador. Aquí es un flujo
-- con estados explícitos y trazabilidad de quién hizo qué.
-- ---------------------------------------------------------------------
CREATE TABLE solicitudes_credito (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid               CHAR(36)     NOT NULL UNIQUE,
  folio              VARCHAR(24)  NOT NULL UNIQUE,
  usuario_id         INT UNSIGNED NOT NULL,
  producto_id        SMALLINT UNSIGNED NOT NULL,
  monto_cent         BIGINT       NOT NULL,
  plazo_meses        SMALLINT UNSIGNED NOT NULL,
  destino            VARCHAR(255) NOT NULL DEFAULT '',
  ingreso_cent       BIGINT       NOT NULL DEFAULT 0,
  etapa              ENUM('capturada','revision_atencion','revision_gerencia',
                          'revision_presidencia','aprobada','rechazada','desembolsada')
                     NOT NULL DEFAULT 'capturada',
  revisado_atencion_por   INT UNSIGNED NULL,
  revisado_gerencia_por   INT UNSIGNED NULL,
  revisado_presidencia_por INT UNSIGNED NULL,
  observaciones      TEXT         NULL,
  motivo_rechazo     VARCHAR(500) NULL,
  credito_id         INT UNSIGNED NULL COMMENT 'Crédito generado al aprobarse',
  creado_en          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sol_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id),
  CONSTRAINT fk_sol_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
  CONSTRAINT fk_sol_credito  FOREIGN KEY (credito_id)  REFERENCES creditos(id) ON DELETE SET NULL,
  INDEX idx_etapa (etapa, creado_en),
  INDEX idx_usuario (usuario_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- REESTRUCTURACIONES
-- ---------------------------------------------------------------------
CREATE TABLE reestructuraciones (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid                CHAR(36)     NOT NULL UNIQUE,
  credito_id          INT UNSIGNED NOT NULL,
  saldo_anterior_cent BIGINT       NOT NULL,
  saldo_nuevo_cent    BIGINT       NOT NULL,
  plazo_anterior      SMALLINT UNSIGNED NOT NULL,
  plazo_nuevo         SMALLINT UNSIGNED NOT NULL,
  tasa_anterior_bp    INT UNSIGNED NOT NULL,
  tasa_nueva_bp       INT UNSIGNED NOT NULL,
  cuota_anterior_cent BIGINT       NOT NULL,
  cuota_nueva_cent    BIGINT       NOT NULL,
  motivo              VARCHAR(500) NOT NULL DEFAULT '',
  estado              ENUM('solicitada','aprobada','rechazada','aplicada') NOT NULL DEFAULT 'solicitada',
  solicitada_por      INT UNSIGNED NOT NULL,
  aprobada_por        INT UNSIGNED NULL,
  creado_en           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aplicada_en         DATETIME     NULL,
  CONSTRAINT fk_ree_credito FOREIGN KEY (credito_id)     REFERENCES creditos(id),
  CONSTRAINT fk_ree_sol     FOREIGN KEY (solicitada_por) REFERENCES usuarios(id),
  CONSTRAINT fk_ree_apr     FOREIGN KEY (aprobada_por)   REFERENCES usuarios(id),
  INDEX idx_credito (credito_id),
  INDEX idx_estado (estado)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- RECUPERACIÓN DE CUENTAS
-- El sistema viejo guardaba el token y el código EN CLARO. Aquí solo
-- el hash: quien lea la tabla no puede usar los tokens.
-- ---------------------------------------------------------------------
CREATE TABLE recuperaciones (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NULL,
  correo      VARCHAR(190) NOT NULL,
  token_hash  CHAR(64)     NOT NULL UNIQUE,
  codigo_hash CHAR(64)     NOT NULL,
  intentos    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ip          VARBINARY(16) NOT NULL,
  creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en   DATETIME     NOT NULL,
  usado_en    DATETIME     NULL,
  CONSTRAINT fk_rec_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_expira (expira_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- HISTÓRICO DE CRÉDITOS CANCELADOS
-- ---------------------------------------------------------------------
CREATE TABLE historico_creditos (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  credito_id      INT UNSIGNED NOT NULL,
  usuario_id      INT UNSIGNED NOT NULL,
  producto_id     SMALLINT UNSIGNED NOT NULL,
  monto_cent      BIGINT       NOT NULL,
  tasa_bp         INT UNSIGNED NOT NULL,
  plazo_meses     SMALLINT UNSIGNED NOT NULL,
  cuota_cent      BIGINT       NOT NULL,
  total_pagado_cent BIGINT     NOT NULL DEFAULT 0,
  total_interes_cent BIGINT    NOT NULL DEFAULT 0,
  total_mora_cent BIGINT       NOT NULL DEFAULT 0,
  cuotas_pagadas  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  cuotas_tardias  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  motivo_cierre   ENUM('cancelado','castigado','reestructurado','rechazado') NOT NULL,
  clasificacion_final CHAR(1)  NOT NULL DEFAULT 'A',
  cerrado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hcr_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  INDEX idx_usuario (usuario_id),
  INDEX idx_credito (credito_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CONTRATOS (documentos firmados)
-- ---------------------------------------------------------------------
CREATE TABLE contratos (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36)     NOT NULL UNIQUE,
  credito_id     INT UNSIGNED NULL,
  cuenta_id      INT UNSIGNED NULL,
  usuario_id     INT UNSIGNED NOT NULL,
  tipo           ENUM('credito','cuenta','tarjeta','reestructuracion') NOT NULL,
  ruta_pdf       VARCHAR(255) NULL,
  hash_documento CHAR(64)     NULL,
  firma_cliente  VARCHAR(255) NULL COMMENT 'Ruta a la firma capturada',
  firmado_en     DATETIME     NULL,
  ip_firma       VARBINARY(16) NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_con_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_con_credito FOREIGN KEY (credito_id) REFERENCES creditos(id) ON DELETE CASCADE,
  CONSTRAINT fk_con_cuenta  FOREIGN KEY (cuenta_id)  REFERENCES cuentas(id)  ON DELETE CASCADE,
  INDEX idx_usuario (usuario_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- AMPLIACIONES A TABLAS EXISTENTES
-- ---------------------------------------------------------------------
ALTER TABLE creditos
  ADD COLUMN folio VARCHAR(24) NULL UNIQUE AFTER uuid,
  ADD COLUMN solicitud_id INT UNSIGNED NULL AFTER producto_id,
  ADD COLUMN total_pagado_cent BIGINT NOT NULL DEFAULT 0 AFTER saldo_capital_cent,
  ADD COLUMN cuotas_pagadas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN cuotas_tardias SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN proxima_cuota DATE NULL,
  ADD COLUMN destino VARCHAR(255) NOT NULL DEFAULT '';

ALTER TABLE cuotas
  ADD COLUMN saldo_posterior_cent BIGINT NOT NULL DEFAULT 0 AFTER total_cent,
  ADD COLUMN pagada_tarde TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE usuarios
  ADD COLUMN perfil_completo TINYINT(1) NOT NULL DEFAULT 0 AFTER estado,
  ADD COLUMN registrado_por INT UNSIGNED NULL AFTER rol_id,
  ADD COLUMN primer_ingreso TINYINT(1) NOT NULL DEFAULT 1;

-- ---------------------------------------------------------------------
-- PERMISOS ADICIONALES
-- ---------------------------------------------------------------------
INSERT IGNORE INTO permisos (clave, descripcion) VALUES
  ('mensaje.enviar',        'Enviar mensajes internos'),
  ('mensaje.ver',           'Ver su bandeja de mensajes'),
  ('ticket.crear',          'Reportar un problema'),
  ('ticket.gestionar',      'Atender y resolver tickets'),
  ('solicitud.capturar',    'Capturar solicitudes de crédito'),
  ('solicitud.revisar_atencion',   'Revisión inicial de solicitudes'),
  ('solicitud.revisar_gerencia',   'Revisión de gerencia'),
  ('solicitud.revisar_presidencia','Revisión de presidencia'),
  ('reestructuracion.solicitar',   'Solicitar reestructuración'),
  ('reestructuracion.aprobar',     'Aprobar reestructuración'),
  ('contrato.generar',      'Generar contratos'),
  ('contrato.firmar',       'Firmar contratos'),
  ('acceso.ver_bitacora',   'Consultar la bitácora de accesos'),
  ('cliente.ver_expediente','Ver el expediente completo del cliente'),
  ('cliente.editar_expediente','Editar el expediente del cliente');

-- Administrador: todo
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) SELECT 1, id FROM permisos;

-- Presidencia
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) SELECT 2, id FROM permisos
 WHERE clave IN ('mensaje.enviar','mensaje.ver','ticket.gestionar',
                 'solicitud.revisar_presidencia','reestructuracion.aprobar',
                 'contrato.generar','acceso.ver_bitacora','cliente.ver_expediente');

-- Gerencia
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) SELECT 3, id FROM permisos
 WHERE clave IN ('mensaje.enviar','mensaje.ver','ticket.gestionar',
                 'solicitud.revisar_gerencia','reestructuracion.solicitar',
                 'reestructuracion.aprobar','contrato.generar',
                 'acceso.ver_bitacora','cliente.ver_expediente');

-- Atención a clientes
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) SELECT 4, id FROM permisos
 WHERE clave IN ('mensaje.enviar','mensaje.ver','ticket.gestionar',
                 'solicitud.capturar','solicitud.revisar_atencion',
                 'contrato.generar','cliente.ver_expediente','cliente.editar_expediente');

-- Cliente
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) SELECT 5, id FROM permisos
 WHERE clave IN ('mensaje.enviar','mensaje.ver','ticket.crear','contrato.firmar');

-- ---------------------------------------------------------------------
-- VISTAS DE CONSULTA
-- ---------------------------------------------------------------------

CREATE OR REPLACE VIEW v_bandeja_entrada AS
SELECT m.id, m.uuid, m.destinatario_id, m.asunto,
       LEFT(m.cuerpo, 180) AS extracto, m.leido_en, m.creado_en,
       u.usuario AS remitente_usuario,
       CONCAT(u.nombres,' ',u.apellidos) AS remitente,
       u.foto_perfil AS remitente_foto
FROM mensajes m
JOIN usuarios u ON u.id = m.remitente_id
WHERE m.archivado_dest = 0;

CREATE OR REPLACE VIEW v_tickets_abiertos AS
SELECT t.*, CONCAT(u.nombres,' ',u.apellidos) AS solicitante,
       u.correo AS solicitante_correo,
       CONCAT(a.nombres,' ',a.apellidos) AS responsable,
       TIMESTAMPDIFF(HOUR, t.creado_en, NOW()) AS horas_abierto,
       (SELECT COUNT(*) FROM ticket_respuestas r WHERE r.ticket_id = t.id) AS respuestas
FROM tickets t
JOIN usuarios u ON u.id = t.usuario_id
LEFT JOIN usuarios a ON a.id = t.asignado_a
WHERE t.estado NOT IN ('cerrado');

CREATE OR REPLACE VIEW v_expediente_cliente AS
SELECT u.id AS usuario_id, u.uuid, u.usuario, u.correo, u.nombres, u.apellidos,
       u.estado, u.foto_perfil, u.creado_en AS registrado_en, u.ultimo_acceso_en,
       d.dui, d.nit, d.telefono, d.celular, d.direccion, d.empresa, d.cargo,
       d.fecha_nacimiento, d.genero, d.estado_civil, d.ingreso_mensual_cent,
       d.perfil_completo,
       (SELECT COUNT(*) FROM cuentas  c WHERE c.usuario_id = u.id AND c.es_tecnica = 0) AS cuentas,
       (SELECT COALESCE(SUM(saldo_cent),0) FROM cuentas WHERE usuario_id = u.id AND es_tecnica = 0) AS saldo_total_cent,
       (SELECT COUNT(*) FROM tarjetas t WHERE t.usuario_id = u.id AND t.estado <> 'cancelada') AS tarjetas,
       (SELECT COUNT(*) FROM creditos cr WHERE cr.usuario_id = u.id AND cr.estado = 'vigente') AS creditos_vigentes,
       (SELECT COALESCE(SUM(saldo_capital_cent),0) FROM creditos WHERE usuario_id = u.id AND estado='vigente') AS deuda_cent
FROM usuarios u
LEFT JOIN detalle_usuarios d ON d.usuario_id = u.id
WHERE u.rol_id = 5;

CREATE OR REPLACE VIEW v_amortizacion AS
SELECT q.id, q.credito_id, q.numero, q.capital_cent, q.interes_cent, q.mora_cent,
       q.total_cent, q.pagado_cent, (q.total_cent - q.pagado_cent) AS pendiente_cent,
       q.fecha_vencimiento, q.estado, q.pagada_en, q.pagada_tarde,
       DATEDIFF(CURDATE(), q.fecha_vencimiento) AS dias_vencida,
       cr.folio AS credito_folio, cr.usuario_id,
       CONCAT(u.nombres,' ',u.apellidos) AS cliente, p.nombre AS producto
FROM cuotas q
JOIN creditos cr ON cr.id = q.credito_id
JOIN usuarios u  ON u.id = cr.usuario_id
JOIN productos p ON p.id = cr.producto_id;

CREATE OR REPLACE VIEW v_indicadores_institucion AS
SELECT
  (SELECT COUNT(*) FROM usuarios WHERE rol_id = 5 AND estado='activo')                      AS clientes_activos,
  (SELECT COUNT(*) FROM cuentas WHERE estado='activa' AND es_tecnica=0)                     AS cuentas_activas,
  (SELECT COALESCE(SUM(saldo_cent),0) FROM cuentas WHERE es_tecnica=0)                      AS captaciones_cent,
  (SELECT COUNT(*) FROM creditos WHERE estado='vigente')                                    AS creditos_vigentes,
  (SELECT COALESCE(SUM(saldo_capital_cent),0) FROM creditos WHERE estado='vigente')         AS cartera_cent,
  (SELECT COUNT(*) FROM tarjetas WHERE estado='activa')                                     AS tarjetas_activas,
  (SELECT COALESCE(SUM(saldo_usado_cent),0) FROM tarjetas WHERE estado='activa')            AS saldo_tarjetas_cent,
  (SELECT COUNT(*) FROM solicitudes_credito WHERE etapa NOT IN ('aprobada','rechazada','desembolsada')) AS solicitudes_pendientes,
  (SELECT COUNT(*) FROM tickets WHERE estado NOT IN ('cerrado'))                            AS tickets_abiertos,
  (SELECT COUNT(*) FROM asientos WHERE DATE(creado_en)=CURDATE())                           AS operaciones_hoy,
  (SELECT COUNT(DISTINCT usuario_id) FROM v_cartera_morosa)                                 AS clientes_mora,
  (SELECT COALESCE(SUM(deuda_vencida_cent),0) FROM v_cartera_morosa)                        AS mora_cent;
