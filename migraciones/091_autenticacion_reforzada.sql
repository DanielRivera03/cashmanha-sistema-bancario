-- =====================================================================
-- 091. Autenticación reforzada por operación
-- =====================================================================
--
-- QUÉ EXIGE LA NORMA
--
-- El proyecto de normas técnicas de ciberseguridad y canales digitales
-- pide que el cliente use un factor de categoría 2 —algo que solo él
-- sabe: su contraseña— MÁS un factor adicional que depende de la
-- TRANSACCIÓN. No de la sesión: de la transacción.
--
-- Esa distinción es la que falta en casi todos los sistemas. Entrar
-- con un segundo factor y después poder vaciar la cuenta sin volver a
-- demostrar nada es exactamente el escenario que la norma intenta
-- evitar: la sesión robada.
--
-- LO QUE HABÍA
--
-- Cada servicio decidía por su cuenta. La transferencia interna pedía
-- código, el pago de servicios no, el alta de beneficiario sí, la
-- subida de límites sí. Cada decisión estaba bien razonada POR
-- SEPARADO, pero no había una política: había siete criterios, y
-- nadie podía responder «¿qué operaciones piden código y desde qué
-- monto?» sin leer siete archivos.
--
-- LO QUE HAY AHORA
--
-- Una tabla. Cada operación, con sus tramos de monto, dice qué factor
-- exige. Se puede leer de un vistazo, auditar, y enseñársela a un
-- examinador de la Superintendencia sin abrir el código.
--
-- POR QUÉ POR TRAMOS DE MONTO
--
-- Porque pedir un código para transferir cinco dólares entre cuentas
-- propias enseña al cliente a teclear códigos sin leer para qué, y ese
-- hábito es el que explota la ingeniería social. El factor tiene que
-- doler donde importa y desaparecer donde no.
--
-- EL DISPOSITIVO TAMBIÉN CUENTA
--
-- La misma operación no es igual de riesgosa desde el teléfono de
-- siempre que desde uno que el banco no ha visto nunca. Por eso la
-- política puede exigir un factor extra cuando el dispositivo es
-- desconocido, aunque el monto sea bajo.
-- =====================================================================
USE cashmanha_v2;

-- ---------------------------------------------------------------------
-- 1. Los dispositivos que el banco reconoce
-- ---------------------------------------------------------------------
--
-- No es un registro de dispositivos «de confianza» que el cliente
-- aprueba: es la memoria de desde dónde ha entrado. La primera vez que
-- se ve uno se le avisa, y a partir de ahí deja de ser una novedad.
--
-- La huella NO identifica al dispositivo de forma infalible —se puede
-- falsificar— y por eso no sirve como factor de autenticación. Sirve
-- para lo que sí hace bien: notar que algo es nuevo.
-- ---------------------------------------------------------------------
CREATE TABLE dispositivos_conocidos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT UNSIGNED NOT NULL,

  huella        CHAR(64) NOT NULL COMMENT 'La misma que calcula Sesion::huellaCliente()',

  -- Para que el cliente lo reconozca al verlo en su lista. No se
  -- guarda el user agent entero: es largo, cambia con cada
  -- actualización del navegador y no dice nada legible.
  descripcion   VARCHAR(120) NOT NULL DEFAULT '',
  ip_primera    VARBINARY(16) NULL,
  ip_ultima     VARBINARY(16) NULL,

  primera_vez_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultima_vez_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  veces          INT UNSIGNED NOT NULL DEFAULT 1,

  -- El cliente puede decir «éste no fui yo». Entonces deja de ser
  -- conocido y vuelve a pedir factor cada vez.
  bloqueado     TINYINT(1) NOT NULL DEFAULT 0,
  bloqueado_en  DATETIME NULL,

  CONSTRAINT fk_disp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),

  UNIQUE KEY uq_disp (usuario_id, huella),
  INDEX idx_usuario (usuario_id, ultima_vez_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. La política
-- ---------------------------------------------------------------------
CREATE TABLE politicas_factor (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  operacion     VARCHAR(40) NOT NULL,

  -- El tramo. `desde_cent` = 0 es el tramo base.
  desde_cent    BIGINT UNSIGNED NOT NULL DEFAULT 0,

  -- Qué se exige ADEMÁS de la contraseña con la que ya entró.
  --
  --   ninguno   nada más: la sesión basta
  --   otp       un código de un solo uso al correo
  --   totp      el código de la app autenticadora
  --   fuerte    totp si lo tiene activo; si no, otp
  factor        ENUM('ninguno','otp','totp','fuerte') NOT NULL DEFAULT 'otp',

  -- Si el dispositivo es desconocido, se sube a esto. Nunca se baja:
  -- un dispositivo nuevo solo puede hacer la operación MÁS exigente.
  factor_desconocido ENUM('ninguno','otp','totp','fuerte') NULL,

  descripcion   VARCHAR(255) NOT NULL DEFAULT '',
  activa        TINYINT(1) NOT NULL DEFAULT 1,

  UNIQUE KEY uq_tramo (operacion, desde_cent),
  INDEX idx_operacion (operacion, activa, desde_cent)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Los tramos, con su porqué
-- ---------------------------------------------------------------------
INSERT INTO politicas_factor (operacion, desde_cent, factor, factor_desconocido, descripcion) VALUES

  -- Entre cuentas PROPIAS el dinero no sale del cliente. Pedir código
  -- para moverse dinero a sí mismo es el ruido que enseña a teclear
  -- códigos sin mirar.
  ('transferencia_propia', 0,      'ninguno', 'otp',
   'Entre cuentas del mismo titular: el dinero no sale de su patrimonio.'),

  -- A terceros dentro del banco: desde cien dólares.
  ('transferencia',        0,      'ninguno', 'otp',
   'Montos pequeños a terceros del mismo banco, desde un dispositivo conocido.'),
  ('transferencia',        10000,  'otp',     'fuerte',
   'Desde $100 a terceros: codigo de un solo uso.'),
  ('transferencia',        100000, 'fuerte',  'fuerte',
   'Desde $1,000: el factor mas fuerte que el cliente tenga.'),

  -- A otros bancos el dinero sale del alcance del banco. Siempre pide.
  ('interbancaria',        0,      'otp',     'fuerte',
   'A otros bancos el dinero sale de nuestro alcance: siempre pide factor.'),
  ('interbancaria',        50000,  'fuerte',  'fuerte',
   'Desde $500 a otros bancos: factor fuerte.'),

  -- Dar de alta un destino es lo que necesita quien roba una sesion.
  ('beneficiario',         0,      'fuerte',  'fuerte',
   'Registrar un destino es el paso previo a vaciar una cuenta.'),

  -- Subir un limite es lo primero que haria un atacante.
  ('limites_subir',        0,      'fuerte',  'fuerte',
   'Subirse el limite propio: bajarlo no pide nada.'),

  -- Pagar servicios va a una empresa con convenio, aplicado a un
  -- servicio concreto. Quien pagara la luz de la victima no le roba.
  ('servicios',            0,      'ninguno', 'otp',
   'El dinero va a una empresa con convenio y queda aplicado a un servicio.'),
  ('servicios',            50000,  'otp',     'fuerte',
   'Desde $500 en servicios: codigo.'),

  -- Tocar la seguridad de la cuenta, siempre con el factor mas fuerte.
  ('cambio_clave',         0,      'fuerte',  'fuerte',
   'Cambiar la contrasena.'),
  ('alta_2fa',             0,      'fuerte',  'fuerte',
   'Activar o desactivar el segundo factor.'),

  -- Un plazo fijo inmoviliza dinero del cliente por meses.
  ('plazo_constituir',     100000, 'otp',     'fuerte',
   'Desde $1,000 inmovilizados: conviene confirmarlo.'),

  -- Una orden programada cobra sola durante meses: el alta pesa mas
  -- que cada cobro.
  ('programada_crear',     0,      'otp',     'fuerte',
   'Una orden que cobrara sola durante meses.');

-- ---------------------------------------------------------------------
-- 3. El rastro de lo exigido
-- ---------------------------------------------------------------------
--
-- Para poder responder «¿se le pidió factor a esta operación?» sin
-- deducirlo. Un examinador pregunta exactamente eso.
-- ---------------------------------------------------------------------
CREATE TABLE factor_exigido (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT UNSIGNED NOT NULL,
  operacion     VARCHAR(40) NOT NULL,
  monto_cent    BIGINT NOT NULL DEFAULT 0,

  factor_pedido ENUM('ninguno','otp','totp','fuerte') NOT NULL,
  dispositivo_conocido TINYINT(1) NOT NULL DEFAULT 1,

  resultado     ENUM('cumplido','fallido','exento') NOT NULL,
  detalle       VARCHAR(160) NOT NULL DEFAULT '',

  ip            VARBINARY(16) NULL,
  creado_en     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  CONSTRAINT fk_fex_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),

  INDEX idx_usuario (usuario_id, creado_en),
  INDEX idx_operacion (operacion, creado_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. Los dispositivos que ya se conocen
-- ---------------------------------------------------------------------
--
-- Se siembran desde las sesiones que ya existen. Sin esto, el día que
-- esto entre en servicio TODOS los dispositivos serían nuevos y todos
-- los clientes recibirían una alerta de acceso desconocido el mismo
-- día: la alerta se volvería ruido antes de servir para nada.
-- ---------------------------------------------------------------------
INSERT INTO dispositivos_conocidos
  (usuario_id, huella, descripcion, primera_vez_en, ultima_vez_en, veces)
SELECT s.usuario_id,
       s.huella_cliente,
       'Dispositivo reconocido al poner en marcha la vigilancia',
       MIN(s.creada_en),
       MAX(s.ultima_actividad),
       COUNT(*)
  FROM sesiones s
 WHERE s.huella_cliente IS NOT NULL AND s.huella_cliente <> ''
 GROUP BY s.usuario_id, s.huella_cliente;

-- ---------------------------------------------------------------------
-- 5. El propósito nuevo para los desafíos
-- ---------------------------------------------------------------------
ALTER TABLE desafios
  MODIFY COLUMN proposito ENUM('transferencia','recuperacion','alta_tarjeta',
                               'cambio_correo','pago_tarjeta',
                               'beneficiario','interbancaria','limites',
                               'celular_t365','reforzada') NOT NULL;
