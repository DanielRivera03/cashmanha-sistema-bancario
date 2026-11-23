-- =====================================================================
-- 079. Transferencias a otros bancos: Transfer365 y UNI
-- =====================================================================
--
-- EL HUECO QUE CIERRA
--
-- Hasta aquí el dinero solo podía moverse entre cuentas de CashManHA.
-- Eso no es una banca en línea salvadoreña: es un sistema cerrado. En
-- El Salvador todas las instituciones ofrecen Transfer365, el sistema
-- de pagos del Banco Central de Reserva, que mueve dinero entre bancos
-- distintos los siete días de la semana; y Transfer365 Móvil, que lo
-- hace contra un número de celular asociado a una cuenta, sin que el
-- que envía tenga que saber el número de cuenta del que recibe.
--
-- LA DIFERENCIA CONTABLE, QUE NO ES MENOR
--
-- Una transferencia interna tiene las dos patas del asiento dentro de
-- casa: se debita una cuenta y se acredita otra, y la suma es cero.
--
-- Una transferencia a otro banco NO. La contrapartida está fuera: es
-- otra institución, que no vive en nuestro libro mayor. Si se debitara
-- al cliente y no se acreditara nada, el asiento no sumaría cero y la
-- partida doble se rompería en la primera operación.
--
-- Por eso se crea una CUENTA TÉCNICA DE CÁMARA. Al enviar, el dinero
-- sale del cliente y entra a la cámara: mientras está ahí, es una
-- deuda del banco frente al sistema de pagos. Al recibir, sale de la
-- cámara y entra al cliente. El saldo de la cámara es, en todo
-- momento, lo que este banco le debe o le deben los demás.
--
-- Esto no es un rodeo para cuadrar: es exactamente como funciona una
-- cámara de compensación de verdad.
--
-- LO QUE PUEDE SALIR MAL, Y ESTÁ PREVISTO
--
-- Una transferencia a otro banco puede ser RECHAZADA (la cuenta
-- destino no existe, está cerrada, el titular no coincide) o DEVUELTA
-- más tarde por la institución receptora. En ambos casos el dinero
-- tiene que volver, y volver DEJANDO RASTRO: no se borra el asiento
-- original —nunca se borra nada— sino que se registra uno de reverso
-- que apunta al primero.
--
-- Por eso la transferencia interbancaria es una ENTIDAD con estados
-- propios, y no un simple asiento: un asiento ocurre o no ocurre, pero
-- una transferencia a otro banco vive en el tiempo.
-- =====================================================================
USE cashmanha_v2;

-- ---------------------------------------------------------------------
-- 1. El catálogo de instituciones del sistema financiero salvadoreño
-- ---------------------------------------------------------------------
--
-- Los nombres son los reales de las instituciones que participan en
-- Transfer365 según el Banco Central de Reserva y el listado del SIPA.
--
-- SOBRE EL CÓDIGO: el BCR asigna a cada participante un código de
-- enrutamiento oficial. Ese listado no es público en una forma que se
-- pueda verificar desde aquí, así que `codigo` se siembra con un
-- código interno estable y queda `codigo_bcr` en NULL, para cargarlo
-- cuando se tenga el oficial. Preferir un hueco honesto a un número
-- inventado que parezca real.
-- ---------------------------------------------------------------------
CREATE TABLE bancos_participantes (
  id                SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codigo            VARCHAR(12)  NOT NULL UNIQUE
                    COMMENT 'Código interno de enrutamiento',
  codigo_bcr        VARCHAR(12)  NULL UNIQUE
                    COMMENT 'Código oficial del BCR, cuando se disponga',
  nombre            VARCHAR(120) NOT NULL,
  nombre_corto      VARCHAR(40)  NOT NULL,

  tipo              ENUM('banco','banco_cooperativo','sac','sociedad_digital')
                    NOT NULL DEFAULT 'banco'
                    COMMENT 'SAC = sociedad de ahorro y crédito',

  -- Un participante puede estar en un servicio y no en el otro.
  transfer365      TINYINT(1) NOT NULL DEFAULT 1,
  transfer365_movil TINYINT(1) NOT NULL DEFAULT 1,
  uni               TINYINT(1) NOT NULL DEFAULT 0,

  -- El propio CashManHA está en la tabla: así el selector de bancos
  -- es uno solo y "mi banco" no es un caso aparte en la interfaz.
  es_propio         TINYINT(1) NOT NULL DEFAULT 0,

  activo            TINYINT(1) NOT NULL DEFAULT 1,
  creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_activo (activo, nombre_corto)
) ENGINE=InnoDB;

INSERT INTO bancos_participantes
  (codigo, nombre, nombre_corto, tipo, uni, es_propio) VALUES
  ('CMHA',  'CashMan H.A.',                                   'CashMan H.A.', 'banco', 1, 1),
  ('AGRI',  'Banco Agrícola, S.A.',                           'Agrícola',     'banco', 1, 0),
  ('BAC',   'Banco de América Central, S.A.',                 'BAC',          'banco', 1, 0),
  ('CUSC',  'Banco Cuscatlán de El Salvador, S.A.',           'Cuscatlán',    'banco', 1, 0),
  ('DAVI',  'Banco Davivienda Salvadoreño, S.A.',             'Davivienda',   'banco', 1, 0),
  ('PROM',  'Banco Promerica, S.A.',                          'Promerica',    'banco', 1, 0),
  ('HIPO',  'Banco Hipotecario de El Salvador, S.A.',         'Hipotecario',  'banco', 1, 0),
  ('BFA',   'Banco de Fomento Agropecuario',                  'BFA',          'banco', 1, 0),
  ('ATLA',  'Banco Atlántida El Salvador, S.A.',              'Atlántida',    'banco', 1, 0),
  ('INDU',  'Banco Industrial El Salvador, S.A.',             'Industrial',   'banco', 1, 0),
  ('AZUL',  'Banco Azul de El Salvador, S.A.',                'Azul',         'banco', 1, 0),
  ('ABNK',  'ABANK, S.A.',                                    'ABANK',        'banco', 1, 0),
  ('CITI',  'Citibank, N.A., Sucursal El Salvador',           'Citibank',     'banco', 0, 0),
  ('FEDE',  'Federación de Cajas de Crédito y Bancos de los Trabajadores',
                                                              'Fedecrédito',  'banco_cooperativo', 1, 0),
  ('BTS',   'Banco de los Trabajadores Salvadoreños, S.C. de R.L. de C.V.',
                                                              'BTS',          'banco_cooperativo', 0, 0),
  ('PBTS',  'Primer Banco de los Trabajadores, S.C. de R.L. de C.V.',
                                                              'Primer Banco', 'banco_cooperativo', 0, 0),
  ('COME',  'Banco Cooperativo COMEDICA de R.L.',             'COMEDICA',     'banco_cooperativo', 0, 0),
  ('BCVI',  'Banco Cooperativo Visionario de R.L.',           'Visionario',   'banco_cooperativo', 0, 0),
  ('BANV',  'BANCOVI de R.L.',                                'BANCOVI',      'banco_cooperativo', 0, 0),
  ('MIBA',  'Multi Inversiones Mi Banco, S.A.',               'Mi Banco',     'banco', 0, 0),
  ('CRED',  'CREDICOMER, S.A.',                               'CREDICOMER',   'sac', 0, 0),
  ('MULT',  'Multimoney, S.A. de C.V.',                       'Multimoney',   'sac', 0, 0),
  ('KOIP',  'Koipay El Salvador, S.A. de C.V.',               'Koipay',       'sociedad_digital', 0, 0);

-- ---------------------------------------------------------------------
-- 2. La cámara de compensación: producto y cuenta técnica
-- ---------------------------------------------------------------------
-- `publico = 0`: no es un producto que nadie pueda contratar, es una
-- cuenta de la mecánica interna del banco.
INSERT INTO productos (codigo, nombre, tipo, descripcion, activo, publico)
VALUES ('TEC-CAMARA', 'Cuenta técnica de cámara interbancaria', 'corriente',
        'Contrapartida de las transferencias enviadas y recibidas por Transfer365 y UNI.',
        1, 0);

SET @producto_camara := LAST_INSERT_ID();

INSERT INTO cuentas (uuid, numero_cuenta, usuario_id, producto_id, moneda,
                     saldo_cent, estado, es_tecnica)
VALUES (UUID(), '0000000006', 1, @producto_camara, 'USD', 0, 'activa', 1);

-- ---------------------------------------------------------------------
-- 3. La agenda de beneficiarios
-- ---------------------------------------------------------------------
--
-- POR QUÉ NO ES SOLO COMODIDAD
--
-- Dar de alta a quién se le puede mandar dinero, y separarlo del acto
-- de mandárselo, es un control antifraude de primer orden. Quien entra
-- con credenciales robadas no puede vaciar una cuenta hacia donde
-- quiera: primero tiene que registrar un destino, ese registro avisa
-- al titular por sus canales, y el destino no sirve hasta que pasa un
-- período de enfriamiento. Son horas en las que el dueño legítimo
-- puede darse cuenta.
--
-- Por eso `enfriamiento_hasta` no es un adorno y la verificación no es
-- opcional: son la razón de ser de la tabla.
-- ---------------------------------------------------------------------
CREATE TABLE beneficiarios (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid              CHAR(36) NOT NULL UNIQUE,
  usuario_id        INT UNSIGNED NOT NULL,

  alias             VARCHAR(60)  NOT NULL
                    COMMENT 'Como lo llama el cliente: "Mamá", "Alquiler"',

  banco_id          SMALLINT UNSIGNED NOT NULL,
  numero_cuenta     VARCHAR(34)  NOT NULL,
  tipo_cuenta       ENUM('ahorro','corriente') NOT NULL DEFAULT 'ahorro',

  titular_nombre    VARCHAR(140) NOT NULL,
  titular_documento VARCHAR(20)  NOT NULL DEFAULT ''
                    COMMENT 'DUI o NIT del titular destino',

  celular           VARCHAR(15)  NOT NULL DEFAULT ''
                    COMMENT 'Para Transfer365 Móvil, en lugar de la cuenta',

  estado            ENUM('en_enfriamiento','activo','bloqueado') NOT NULL
                    DEFAULT 'en_enfriamiento',

  -- Hasta este instante el beneficiario existe pero NO se le puede
  -- enviar dinero. Es la ventana en la que el titular legítimo puede
  -- darse cuenta de un alta que no hizo.
  enfriamiento_hasta DATETIME NOT NULL,

  verificado_en     DATETIME NULL
                    COMMENT 'Cuando el banco destino confirmó el titular',

  ultima_uso_en     DATETIME NULL,
  veces_usado       INT UNSIGNED NOT NULL DEFAULT 0,

  creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  creado_desde_ip   VARBINARY(16) NOT NULL,

  CONSTRAINT fk_benef_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_benef_banco   FOREIGN KEY (banco_id)   REFERENCES bancos_participantes(id),

  -- Un mismo destino no se registra dos veces para el mismo cliente.
  UNIQUE KEY uq_destino (usuario_id, banco_id, numero_cuenta),

  INDEX idx_usuario_estado (usuario_id, estado)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. Transfer365 Móvil: el celular asociado a una cuenta
-- ---------------------------------------------------------------------
--
-- El servicio del BCR permite recibir dinero con solo dar el número de
-- celular. Para que eso funcione, el número tiene que apuntar a UNA
-- cuenta y a una sola, en todo el sistema financiero.
--
-- Aquí se garantiza lo que nos toca: un celular, una cuenta, en este
-- banco. El UNIQUE sobre `celular` es lo que lo sostiene, y por eso
-- está sobre la columna y no solo en el código.
-- ---------------------------------------------------------------------
CREATE TABLE celulares_transfer365 (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  celular        VARCHAR(15)  NOT NULL UNIQUE
                 COMMENT 'Ocho dígitos, sin código de país',
  usuario_id     INT UNSIGNED NOT NULL,
  cuenta_id      INT UNSIGNED NOT NULL,

  verificado_en  DATETIME NULL,
  activo         TINYINT(1) NOT NULL DEFAULT 1,

  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  baja_en        DATETIME NULL,

  CONSTRAINT fk_cel_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_cel_cuenta  FOREIGN KEY (cuenta_id)  REFERENCES cuentas(id),

  INDEX idx_usuario (usuario_id, activo)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. La transferencia interbancaria
-- ---------------------------------------------------------------------
--
-- Vive en el tiempo: se crea, se envía, y más tarde se aplica, se
-- rechaza o se devuelve. Por eso es una tabla con estados y no un
-- asiento suelto.
--
-- Guarda DOS asientos: el del envío y, si lo hubo, el del reverso. El
-- original jamás se toca.
-- ---------------------------------------------------------------------
CREATE TABLE transferencias_interbancarias (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid               CHAR(36) NOT NULL UNIQUE,

  -- El folio es lo que el cliente ve y dicta por teléfono al reclamar.
  folio              VARCHAR(24) NOT NULL UNIQUE,

  sentido            ENUM('enviada','recibida') NOT NULL,
  canal              ENUM('transfer365','transfer365_movil','uni') NOT NULL,

  usuario_id         INT UNSIGNED NOT NULL,
  cuenta_id          INT UNSIGNED NOT NULL
                     COMMENT 'La cuenta del cliente: origen si envía, destino si recibe',

  beneficiario_id    INT UNSIGNED NULL
                     COMMENT 'De la agenda; NULL si fue un envío de una sola vez',

  banco_contraparte_id SMALLINT UNSIGNED NOT NULL,
  cuenta_contraparte VARCHAR(34)  NOT NULL DEFAULT '',
  titular_contraparte VARCHAR(140) NOT NULL DEFAULT '',
  celular_contraparte VARCHAR(15)  NOT NULL DEFAULT '',

  monto_cent         BIGINT NOT NULL,
  comision_cent      BIGINT NOT NULL DEFAULT 0,
  moneda             CHAR(3) NOT NULL DEFAULT 'USD',
  concepto           VARCHAR(160) NOT NULL DEFAULT '',

  estado             ENUM('creada','enviada','aplicada','rechazada','devuelta')
                     NOT NULL DEFAULT 'creada',

  motivo             VARCHAR(160) NOT NULL DEFAULT ''
                     COMMENT 'Por qué se rechazó o se devolvió',

  -- La referencia que devuelve el sistema de pagos. Es por la que
  -- pregunta el otro banco cuando hay que rastrear un envío.
  referencia_externa VARCHAR(40) NOT NULL DEFAULT '',

  asiento_envio_id   BIGINT UNSIGNED NULL,
  asiento_reverso_id BIGINT UNSIGNED NULL,

  creada_en          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  enviada_en         DATETIME NULL,
  resuelta_en        DATETIME NULL,

  CONSTRAINT fk_inter_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_inter_cuenta  FOREIGN KEY (cuenta_id)  REFERENCES cuentas(id),
  CONSTRAINT fk_inter_banco   FOREIGN KEY (banco_contraparte_id)
                              REFERENCES bancos_participantes(id),
  CONSTRAINT fk_inter_benef   FOREIGN KEY (beneficiario_id) REFERENCES beneficiarios(id),
  CONSTRAINT fk_inter_asiento FOREIGN KEY (asiento_envio_id) REFERENCES asientos(id),
  CONSTRAINT fk_inter_reverso FOREIGN KEY (asiento_reverso_id) REFERENCES asientos(id),

  CONSTRAINT chk_inter_monto CHECK (monto_cent > 0),

  INDEX idx_usuario_fecha (usuario_id, creada_en),
  INDEX idx_estado (estado, creada_en),
  INDEX idx_cuenta (cuenta_id, creada_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. Límites por canal, que el cliente puede bajar
-- ---------------------------------------------------------------------
--
-- Los límites que ya existían (`limites_operacion`) son del BANCO y van
-- por rol: son el techo institucional. Éstos son del CLIENTE.
--
-- La regla es que el cliente puede BAJAR su límite tanto como quiera,
-- pero no subirlo por encima del techo del banco. Quien casi no
-- transfiere puede dejarlo en cien dólares y dormir tranquilo; el que
-- lo necesita alto lo tiene alto, pero nunca más que el tope.
--
-- Esto es lo que ofrecen los bancos del país bajo el nombre de
-- «administrar mis límites», y es también una defensa: un límite bajo
-- convierte el robo de credenciales en un susto en lugar de una ruina.
-- ---------------------------------------------------------------------
CREATE TABLE limites_canal_cliente (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id     INT UNSIGNED NOT NULL,

  canal          ENUM('interna','transfer365','transfer365_movil','uni','servicios')
                 NOT NULL,

  max_operacion_cent BIGINT NOT NULL,
  max_diario_cent    BIGINT NOT NULL,
  max_ops_diarias    SMALLINT UNSIGNED NOT NULL DEFAULT 20,

  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP,
  actualizado_por INT UNSIGNED NULL,

  CONSTRAINT fk_limcan_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  UNIQUE KEY uq_usuario_canal (usuario_id, canal),

  CONSTRAINT chk_limcan_positivo
    CHECK (max_operacion_cent > 0 AND max_diario_cent >= max_operacion_cent)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. El tipo de asiento nuevo
-- ---------------------------------------------------------------------
ALTER TABLE asientos
  MODIFY COLUMN tipo ENUM('transferencia','deposito','retiro','pago_cuota',
                          'consumo_tarjeta','pago_tarjeta','interes','mora',
                          'comision','ajuste','nota_abono','nota_cargo',
                          'reverso','arreglo_pago','condonacion','compensacion',
                          'interbancaria') NOT NULL;

-- ---------------------------------------------------------------------
-- 8. El techo institucional de los canales nuevos
-- ---------------------------------------------------------------------
--
-- Transfer365 no impone un tope de monto: el BCR permite transferir
-- hasta el saldo disponible. El tope de aquí es prudencial, del banco,
-- y es el que el cliente no puede superar.
-- ---------------------------------------------------------------------
INSERT INTO limites_operacion
  (tipo_operacion, rol_id, max_por_operacion_cent, max_diario_cent,
   max_mensual_cent, max_ops_diarias, requiere_otp,
   umbral_doble_control_cent, activo)
VALUES
  ('transfer365',       NULL, 1000000, 2500000, 50000000, 15, 1, 500000, 1),
  ('transfer365_movil', NULL,  300000,  600000, 10000000, 10, 1, 300000, 1);
