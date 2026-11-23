-- =====================================================================
-- 087. Constancias, solvencias y referencias bancarias
-- =====================================================================
--
-- QUÉ SON Y POR QUÉ IMPORTAN
--
-- Son los documentos que un banco emite para que los lea OTRO: una
-- embajada que estudia una visa, un patrono, otro banco que evalúa un
-- préstamo, un juzgado.
--
-- En El Salvador se piden constantemente, y hoy este sistema no puede
-- emitir ninguno. El cliente que necesita una constancia de saldo para
-- su visa tiene que ir a una agencia a que se la hagan a mano.
--
-- LO QUE LAS HACE VALER ALGO
--
-- No es el papel ni el sello: es que QUIEN LA RECIBE pueda comprobar
-- que es auténtica. Un PDF se falsifica en diez minutos.
--
-- Por eso cada constancia lleva un CÓDIGO DE VERIFICACIÓN y existe una
-- página pública donde cualquiera lo teclea y ve si el documento es
-- real, de quién es y qué decía. Esa página es el documento; el PDF es
-- solo su copia impresa.
--
-- Y por eso se guarda una FOTO de los datos en el momento de emitirla.
-- Una constancia de saldo dice lo que el saldo era ESE día; si al
-- verificarla se recalculara, diría otra cosa y la embajada pensaría
-- que el cliente mintió.
--
-- LO QUE SE CUIDA AL VERIFICAR
--
-- La página pública confirma que el documento existe y qué decía, pero
-- NO es un buscador de clientes: no se puede teclear un nombre y ver
-- sus saldos. Hace falta el código exacto, que solo tiene quien recibió
-- el documento de manos del cliente.
-- =====================================================================
USE cashmanha_v2;

CREATE TABLE constancias (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36) NOT NULL UNIQUE,
  folio         VARCHAR(24) NOT NULL UNIQUE,

  -- El código que se teclea en la página pública. Corto para poder
  -- dictarlo por teléfono, y con suficiente entropía para que no se
  -- pueda adivinar probando.
  codigo_verificacion CHAR(12) NOT NULL UNIQUE,

  usuario_id    INT UNSIGNED NOT NULL,

  tipo          ENUM('saldo','referencia_bancaria','solvencia','no_adeudo','ingresos')
                NOT NULL,

  -- Sobre qué se emite. `global` para las que hablan del cliente
  -- entero, como la de no adeudo.
  objeto_tipo   ENUM('cuenta','credito','tarjeta','global') NOT NULL DEFAULT 'global',
  objeto_id     BIGINT UNSIGNED NULL,

  -- A quién va dirigida. Una constancia «a quien interese» vale menos
  -- que una dirigida a la embajada que la pidió, y muchas
  -- instituciones exigen que las nombre.
  dirigida_a    VARCHAR(160) NOT NULL DEFAULT '',
  proposito     VARCHAR(255) NOT NULL DEFAULT '',

  -- La FOTO de los datos en el momento de emitir. Es lo que dice el
  -- documento, y no se recalcula nunca.
  datos_json    TEXT NOT NULL,

  -- El texto exacto que salió impreso. Guardarlo permite que la página
  -- de verificación enseñe LO MISMO que tiene en la mano quien
  -- verifica, en lugar de una reconstrucción parecida.
  texto         TEXT NOT NULL,

  comision_cent BIGINT NOT NULL DEFAULT 0,
  asiento_id    BIGINT UNSIGNED NULL,

  estado        ENUM('vigente','anulada') NOT NULL DEFAULT 'vigente',
  motivo_anulacion VARCHAR(255) NOT NULL DEFAULT '',

  -- Las constancias caducan: un saldo de hace ocho meses no dice nada
  -- de hoy, y una institución que la acepte estaría engañándose.
  emitida_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  vigente_hasta DATE NOT NULL,

  emitida_por   INT UNSIGNED NULL
                COMMENT 'NULL cuando la pidió el propio cliente desde su banca',

  veces_verificada INT UNSIGNED NOT NULL DEFAULT 0,
  ultima_verificacion DATETIME NULL,

  CONSTRAINT fk_const_usuario FOREIGN KEY (usuario_id)  REFERENCES usuarios(id),
  CONSTRAINT fk_const_emisor  FOREIGN KEY (emitida_por) REFERENCES usuarios(id),
  CONSTRAINT fk_const_asiento FOREIGN KEY (asiento_id)  REFERENCES asientos(id),

  INDEX idx_usuario (usuario_id, emitida_en),
  INDEX idx_verificacion (codigo_verificacion, estado),
  INDEX idx_tipo (tipo, emitida_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- El arancel
-- ---------------------------------------------------------------------
--
-- Los bancos del país cobran por emitirlas, entre tres y diez dólares
-- según el tipo. Se deja configurable por tipo y en parámetros, no
-- clavado en el código: un arancel es una decisión comercial que
-- cambia, no una regla del sistema.
--
-- La de NO ADEUDO va gratis a propósito: se pide justamente cuando se
-- termina de pagar, y cobrarle a alguien por certificar que ya no le
-- debe nada al banco es de mal gusto.
-- ---------------------------------------------------------------------
CREATE TABLE constancia_aranceles (
  tipo          ENUM('saldo','referencia_bancaria','solvencia','no_adeudo','ingresos')
                NOT NULL PRIMARY KEY,
  nombre        VARCHAR(80)  NOT NULL,
  descripcion   VARCHAR(255) NOT NULL,
  comision_cent BIGINT UNSIGNED NOT NULL DEFAULT 0,
  dias_vigencia SMALLINT UNSIGNED NOT NULL DEFAULT 30,

  -- Quién puede pedirla por su cuenta desde la banca en línea. Las que
  -- exigen criterio de un oficial se emiten en agencia.
  autoservicio  TINYINT(1) NOT NULL DEFAULT 1,
  activa        TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT INTO constancia_aranceles
  (tipo, nombre, descripcion, comision_cent, dias_vigencia, autoservicio) VALUES

  ('saldo', 'Constancia de saldo',
   'Certifica el saldo de una cuenta a la fecha de emision. La piden embajadas, otros bancos y patronos.',
   500, 30, 1),

  ('referencia_bancaria', 'Referencia bancaria',
   'Certifica desde cuando es cliente y como ha manejado sus productos. Es la que mas piden para tramites.',
   700, 60, 1),

  ('solvencia', 'Solvencia de credito',
   'Certifica que un credito concreto esta al dia o totalmente cancelado.',
   500, 30, 1),

  ('no_adeudo', 'Constancia de no adeudo',
   'Certifica que el cliente no tiene ninguna obligacion pendiente con el banco.',
   0, 30, 1),

  ('ingresos', 'Constancia de ingresos',
   'Certifica los abonos recibidos en un periodo. Requiere revision de un oficial.',
   700, 30, 0);

-- ---------------------------------------------------------------------
-- Permisos
-- ---------------------------------------------------------------------
INSERT INTO permisos (clave, descripcion)
SELECT * FROM (
  SELECT 'constancia.emitir_propia' AS clave,
         'Emitir constancias sobre los productos de uno mismo' AS descripcion
  UNION ALL SELECT 'constancia.emitir_terceros',
         'Emitir constancias a nombre de cualquier cliente'
  UNION ALL SELECT 'constancia.anular',
         'Anular una constancia emitida'
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave);

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'constancia.emitir_propia'
 WHERE r.clave IN ('cliente', 'administrador', 'presidencia', 'gerencia',
                   'atencionclientes', 'banquero', 'cajero')
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave IN ('constancia.emitir_terceros', 'constancia.anular')
 WHERE r.clave IN ('administrador', 'presidencia', 'gerencia',
                   'atencionclientes', 'banquero')
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );
