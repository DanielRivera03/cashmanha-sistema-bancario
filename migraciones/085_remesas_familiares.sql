-- =====================================================================
-- 085. Remesas familiares
-- =====================================================================
--
-- POR QUÉ ESTO NO ES UN MÓDULO MÁS
--
-- Las remesas familiares son cerca de una cuarta parte del producto
-- interno bruto de El Salvador. Para una parte enorme de los clientes
-- de un banco salvadoreño, la remesa NO es un ingreso más: es EL
-- ingreso. Es con lo que se paga el alquiler, la escuela y la comida.
--
-- Un banco del país que no las maneje no es un banco con una función
-- menos: es un banco al que esa parte de la población no tiene por qué
-- acercarse.
--
-- CÓMO LLEGA EL DINERO
--
-- Alguien en el extranjero deposita en una remesadora —Western Union,
-- MoneyGram, Ria, Intermex— y recibe un código de control. Ese código
-- viaja por mensaje de texto o por llamada al beneficiario, que lo
-- presenta con su documento de identidad.
--
-- El dinero no sale de este banco: viene de la remesadora, y el banco
-- solo lo entrega. Mientras no se entrega, es una deuda de la
-- remesadora frente al banco, no al revés. Por eso la contrapartida es
-- una cuenta técnica igual que la cámara interbancaria.
--
-- DOS FORMAS DE COBRARLA, Y LAS DOS IMPORTAN
--
--   A CUENTA   El beneficiario tiene cuenta y se le acredita. Es lo
--              que el banco quiere, porque bancariza.
--
--   EN EFECTIVO  Se paga en ventanilla contra documento. Es como
--              cobra la mayoría, y fingir que no existe dejaría fuera
--              justamente a quien más usa el servicio.
--
-- EL CÓDIGO DE CONTROL ES UNA LLAVE
--
-- Quien tiene el código y un documento cobra. Por eso se guarda
-- CIFRADO y nunca se muestra entero: en pantalla van los últimos
-- cuatro dígitos, igual que con un número de cuenta. Un listado que
-- mostrara códigos completos sería un listado de llaves.
-- =====================================================================
USE cashmanha_v2;

-- ---------------------------------------------------------------------
-- 1. Las remesadoras
-- ---------------------------------------------------------------------
CREATE TABLE remesadoras (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codigo        VARCHAR(16)  NOT NULL UNIQUE,
  nombre        VARCHAR(120) NOT NULL,
  nombre_corto  VARCHAR(40)  NOT NULL,

  -- Cada una llama de una forma a su código de control, y el
  -- beneficiario llega diciendo el nombre que le dieron por teléfono.
  etiqueta_codigo VARCHAR(40) NOT NULL DEFAULT 'Código de control',
  codigo_largo_min TINYINT UNSIGNED NOT NULL DEFAULT 8,
  codigo_largo_max TINYINT UNSIGNED NOT NULL DEFAULT 16,

  activa        TINYINT(1) NOT NULL DEFAULT 1,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO remesadoras (codigo, nombre, nombre_corto, etiqueta_codigo,
                         codigo_largo_min, codigo_largo_max) VALUES
  ('WU',     'Western Union',        'Western Union', 'MTCN',               10, 10),
  ('MG',     'MoneyGram',            'MoneyGram',     'Número de referencia', 8, 8),
  ('RIA',    'Ria Money Transfer',   'Ria',           'PIN',                11, 11),
  ('INTER',  'Intermex',             'Intermex',      'Folio',               8, 12),
  ('VIAM',   'Viamericas',           'Viamericas',    'Folio',               8, 12),
  ('DOLEX',  'Dolex Dollar Express', 'Dolex',         'Folio',               8, 12),
  ('SIGUE',  'Sigue Money Transfer', 'Sigue',         'Folio',               8, 12),
  ('OTRA',   'Otra remesadora con convenio', 'Otra',  'Código de control',   6, 20);

-- ---------------------------------------------------------------------
-- 2. La cuenta técnica de remesas
-- ---------------------------------------------------------------------
INSERT INTO productos (codigo, nombre, tipo, descripcion, activo, publico)
VALUES ('TEC-REMESAS', 'Cuenta técnica de remesas', 'corriente',
        'Contrapartida de las remesas familiares, hasta que se liquidan con cada remesadora.',
        1, 0);

SET @producto_remesa := LAST_INSERT_ID();

INSERT INTO cuentas (uuid, numero_cuenta, usuario_id, producto_id, moneda,
                     saldo_cent, estado, es_tecnica)
VALUES (UUID(), '0000000008', 1, @producto_remesa, 'USD', 0, 'activa', 1);

-- ---------------------------------------------------------------------
-- 3. Las remesas
-- ---------------------------------------------------------------------
CREATE TABLE remesas (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36) NOT NULL UNIQUE,
  folio           VARCHAR(24) NOT NULL UNIQUE,

  remesadora_id   SMALLINT UNSIGNED NOT NULL,

  -- El código de control, CIFRADO. Quien lo tiene, cobra: guardarlo en
  -- claro sería guardar una llave en claro.
  codigo_cifrado  VARBINARY(255) NOT NULL,

  -- Para buscar sin descifrar toda la tabla: un hash del código, que
  -- no se puede revertir pero sí comparar.
  codigo_hash     CHAR(64) NOT NULL,

  -- Los últimos cuatro, en claro, para poder identificarla en pantalla
  -- sin enseñar la llave entera.
  codigo_cola     CHAR(4) NOT NULL,

  -- A quién va dirigida. Puede llegar antes de que la persona sea
  -- cliente, así que el beneficiario se identifica por su documento.
  beneficiario_nombre    VARCHAR(140) NOT NULL,
  beneficiario_documento VARCHAR(20)  NOT NULL,
  usuario_id      INT UNSIGNED NULL
                  COMMENT 'Si el beneficiario ya es cliente del banco',

  remitente_nombre VARCHAR(140) NOT NULL DEFAULT '',
  remitente_pais   CHAR(2)      NOT NULL DEFAULT 'US',

  -- El país de origen importa para la vigilancia de lavado: una remesa
  -- de un país de riesgo no se trata igual que una de Estados Unidos.
  monto_cent      BIGINT NOT NULL COMMENT 'Lo que recibe el beneficiario, en dólares',
  moneda_origen   CHAR(3) NOT NULL DEFAULT 'USD',
  monto_origen    DECIMAL(14,2) NOT NULL DEFAULT 0,
  tipo_cambio     DECIMAL(12,6) NOT NULL DEFAULT 1,

  estado          ENUM('disponible','pagada','devuelta','retenida') NOT NULL DEFAULT 'disponible',

  -- Cómo se cobró. Hasta que se cobra, no se sabe.
  forma_pago      ENUM('cuenta','efectivo') NULL,
  cuenta_id       INT UNSIGNED NULL,
  caja_sesion_id  BIGINT UNSIGNED NULL,
  pagada_por      INT UNSIGNED NULL,

  motivo          VARCHAR(160) NOT NULL DEFAULT '',

  asiento_id      BIGINT UNSIGNED NULL,

  disponible_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en       DATE NULL COMMENT 'Pasado este plazo la remesadora la reclama de vuelta',
  pagada_en       DATETIME NULL,

  creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_remesa_remesadora FOREIGN KEY (remesadora_id) REFERENCES remesadoras(id),
  CONSTRAINT fk_remesa_usuario    FOREIGN KEY (usuario_id)    REFERENCES usuarios(id),
  CONSTRAINT fk_remesa_cuenta     FOREIGN KEY (cuenta_id)     REFERENCES cuentas(id),
  CONSTRAINT fk_remesa_cajero     FOREIGN KEY (pagada_por)    REFERENCES usuarios(id),
  CONSTRAINT fk_remesa_asiento    FOREIGN KEY (asiento_id)    REFERENCES asientos(id),

  CONSTRAINT chk_remesa_monto CHECK (monto_cent > 0),

  -- Un mismo código no puede entrar dos veces: sería pagar dos veces
  -- la misma remesa.
  UNIQUE KEY uq_codigo (remesadora_id, codigo_hash),

  INDEX idx_busqueda (codigo_hash),
  INDEX idx_documento (beneficiario_documento, estado),
  INDEX idx_usuario (usuario_id, estado),
  INDEX idx_estado (estado, disponible_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. El tipo de asiento
-- ---------------------------------------------------------------------
ALTER TABLE asientos
  MODIFY COLUMN tipo ENUM('transferencia','deposito','retiro','pago_cuota',
                          'consumo_tarjeta','pago_tarjeta','interes','mora',
                          'comision','ajuste','nota_abono','nota_cargo',
                          'reverso','arreglo_pago','condonacion','compensacion',
                          'interbancaria','pago_servicio','remesa') NOT NULL;

-- ---------------------------------------------------------------------
-- 5. Permisos
-- ---------------------------------------------------------------------
--
-- Pagar una remesa en ventanilla es entregar efectivo contra un código
-- y un documento: es trabajo de caja. Registrar la llegada de una
-- remesa es trabajo de quien concilia con la remesadora.
-- ---------------------------------------------------------------------
INSERT INTO permisos (clave, descripcion)
SELECT * FROM (
  SELECT 'remesa.ver' AS clave,
         'Consultar remesas por codigo o documento' AS descripcion
  UNION ALL SELECT 'remesa.pagar',
         'Entregar una remesa en ventanilla o acreditarla a cuenta'
  UNION ALL SELECT 'remesa.registrar',
         'Registrar la llegada de remesas desde la remesadora'
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave);

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave IN ('remesa.ver', 'remesa.pagar', 'remesa.registrar')
 WHERE r.clave IN ('administrador', 'presidencia', 'gerencia')
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );

-- Caja y atención: consultan y pagan, pero no registran llegadas.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave IN ('remesa.ver', 'remesa.pagar')
 WHERE r.clave IN ('cajero', 'atencionclientes', 'banquero')
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );

-- El cliente ve LAS SUYAS. El permiso existe para poder distinguir en
-- el controlador entre «ver todas» y «ver las mías».
INSERT INTO permisos (clave, descripcion)
SELECT 'remesa.ver_propia', 'Ver las remesas dirigidas a uno mismo'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'remesa.ver_propia');

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'remesa.ver_propia'
 WHERE r.clave = 'cliente'
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );
