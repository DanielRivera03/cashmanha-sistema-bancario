-- =====================================================================
-- CashMan H.A. v2 — Segundo factor TOTP y documentos del expediente
-- =====================================================================
USE cashmanha_v2;

-- ---------------------------------------------------------------------
-- TOTP
--
-- El secreto va cifrado con AES-256-GCM: si alguien obtiene un volcado
-- de la base sin la clave de la aplicación, no puede generar códigos.
-- ---------------------------------------------------------------------
ALTER TABLE usuarios
  ADD COLUMN totp_ultimo_contador BIGINT UNSIGNED NULL
      COMMENT 'Periodo ya consumido: impide reutilizar un código'
      AFTER totp_confirmado_en;

CREATE TABLE totp_respaldo (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  -- Solo el hash: quien lea la tabla no puede usar los códigos.
  codigo_hash CHAR(64)     NOT NULL,
  usado_en    DATETIME     NULL,
  usado_ip    VARBINARY(16) NULL,
  creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_resp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_usuario (usuario_id, usado_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- DOCUMENTOS DEL EXPEDIENTE
--
-- En v1 las fotos del DUI, NIT y firma se subían a vista/images/,
-- servidas directamente por Apache: cualquiera con la URL podía
-- descargar la cédula de un cliente, y un .php subido se ejecutaba.
--
-- Aquí solo se guarda el nombre generado por el servidor; el archivo
-- vive en storage/uploads/, fuera del webroot, y se sirve por PHP
-- previa verificación de permiso. Cada descarga queda registrada.
-- ---------------------------------------------------------------------
CREATE TABLE documentos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36)     NOT NULL UNIQUE,
  usuario_id    INT UNSIGNED NOT NULL COMMENT 'Titular del documento',
  subido_por    INT UNSIGNED NOT NULL,
  tipo          ENUM('dui_frontal','dui_reverso','nit','firma','comprobante_ingreso',
                     'recibo_servicio','foto_perfil','otro') NOT NULL,
  nombre_archivo VARCHAR(80) NOT NULL UNIQUE,
  mime          VARCHAR(60)  NOT NULL,
  tamano_bytes  INT UNSIGNED NOT NULL,
  hash_sha256   CHAR(64)     NOT NULL,
  descripcion   VARCHAR(255) NOT NULL DEFAULT '',
  verificado    TINYINT(1)   NOT NULL DEFAULT 0,
  verificado_por INT UNSIGNED NULL,
  verificado_en DATETIME     NULL,
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_doc_usuario  FOREIGN KEY (usuario_id)     REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_subidor  FOREIGN KEY (subido_por)     REFERENCES usuarios(id),
  CONSTRAINT fk_doc_verifica FOREIGN KEY (verificado_por) REFERENCES usuarios(id),
  INDEX idx_usuario_tipo (usuario_id, tipo),
  INDEX idx_hash (hash_sha256)
) ENGINE=InnoDB;

CREATE TABLE documento_accesos (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  documento_id BIGINT UNSIGNED NOT NULL,
  usuario_id  INT UNSIGNED NOT NULL,
  ip          VARBINARY(16) NOT NULL,
  creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_da_doc     FOREIGN KEY (documento_id) REFERENCES documentos(id) ON DELETE CASCADE,
  CONSTRAINT fk_da_usuario FOREIGN KEY (usuario_id)   REFERENCES usuarios(id),
  INDEX idx_doc (documento_id, creado_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- PERMISOS
-- ---------------------------------------------------------------------
INSERT IGNORE INTO permisos (clave, descripcion) VALUES
  ('seguridad.totp',        'Activar y administrar su segundo factor'),
  ('documento.subir',       'Subir documentos a su expediente'),
  ('documento.ver_propio',  'Ver sus propios documentos'),
  ('documento.ver_todos',   'Ver documentos de cualquier cliente'),
  ('documento.verificar',   'Marcar documentos como verificados'),
  ('documento.eliminar',    'Eliminar documentos');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) SELECT 1, id FROM permisos;

-- Todos los roles pueden usar segundo factor y gestionar lo suyo.
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
 WHERE p.clave IN ('seguridad.totp','documento.subir','documento.ver_propio');

-- El personal de ventanilla y gerencia revisa documentos de clientes.
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
 WHERE r.id IN (2,3,4) AND p.clave IN ('documento.ver_todos','documento.verificar');

-- ---------------------------------------------------------------------
-- VISTA
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_expediente_documentos AS
SELECT
  d.id, d.uuid, d.usuario_id, d.tipo, d.nombre_archivo, d.mime,
  d.tamano_bytes, d.hash_sha256, d.verificado, d.creado_en,
  CONCAT(u.nombres,' ',u.apellidos)  AS titular,
  CONCAT(s.nombres,' ',s.apellidos)  AS subido_por_nombre,
  CONCAT(v.nombres,' ',v.apellidos)  AS verificado_por_nombre,
  (SELECT COUNT(*) FROM documento_accesos a WHERE a.documento_id = d.id) AS veces_visto
FROM documentos d
JOIN usuarios u ON u.id = d.usuario_id
JOIN usuarios s ON s.id = d.subido_por
LEFT JOIN usuarios v ON v.id = d.verificado_por;
