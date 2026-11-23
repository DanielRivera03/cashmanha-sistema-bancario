-- =====================================================================
-- 089. Chequeras y cheques
-- =====================================================================
--
-- LO QUE HABÍA
--
-- El producto «Cuenta corriente con chequera» existía en el catálogo
-- desde el primer día. Lo que no existía era la chequera: ni cómo se
-- pide, ni cómo se entrega, ni qué pasa con cada cheque.
--
-- Un banco que ofrece cuentas corrientes y no puede emitir una
-- chequera está vendiendo algo que no tiene.
--
-- LA PIEZA QUE LO ORDENA TODO: EL NÚMERO DE CHEQUE
--
-- Un cheque no es una transferencia con otro nombre. Es un TÍTULO
-- VALOR: un papel numerado que circula fuera del banco, que puede
-- endosarse, que se presenta semanas después de firmarse y que puede
-- aparecer dos veces.
--
-- Por eso cada cheque existe en la base DESDE QUE SE IMPRIME, mucho
-- antes de que nadie lo llene. Un cheque que se presenta y no está en
-- esta tabla no es un cheque: es un papel.
--
-- LOS ESTADOS, Y POR QUÉ CADA UNO
--
--   en_blanco   Impreso y entregado. Todavía no se ha usado.
--   emitido     El cliente lo llenó y lo entregó a alguien.
--   pagado      Se presentó al cobro y se pagó.
--   rechazado   Se presentó y no se pudo pagar. Esto tiene
--               consecuencias legales, no solo contables.
--   anulado     El cliente lo echó a perder y lo devolvió.
--   suspendido  El cliente pidió NO pagarlo. Es la orden de no pago,
--               y existe porque los cheques se pierden y se roban.
--
-- EL CHEQUE RECHAZADO NO ES UN ERROR DEL SISTEMA
--
-- Es un hecho con consecuencias: en El Salvador librar un cheque sin
-- fondos puede constituir delito, y el banco tiene que dejar
-- constancia de la razón exacta del rechazo y de la fecha. Por eso el
-- motivo se guarda en una lista cerrada y no en un campo libre: «sin
-- fondos» y «firma no coincide» no son lo mismo ante un juez.
-- =====================================================================
USE cashmanha_v2;

-- ---------------------------------------------------------------------
-- 1. Las chequeras
-- ---------------------------------------------------------------------
CREATE TABLE chequeras (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36) NOT NULL UNIQUE,
  folio         VARCHAR(24) NOT NULL UNIQUE,

  cuenta_id     INT UNSIGNED NOT NULL,
  usuario_id    INT UNSIGNED NOT NULL,

  -- La serie y el rango. Dos chequeras de la misma cuenta no pueden
  -- compartir números: un cheque se identifica por cuenta y número, y
  -- si se repitieran no se sabría cuál se está pagando.
  serie         VARCHAR(8)  NOT NULL DEFAULT 'A',
  desde_numero  INT UNSIGNED NOT NULL,
  hasta_numero  INT UNSIGNED NOT NULL,
  cantidad      SMALLINT UNSIGNED NOT NULL,

  estado        ENUM('solicitada','impresa','entregada','anulada')
                NOT NULL DEFAULT 'solicitada',

  comision_cent BIGINT NOT NULL DEFAULT 0,
  asiento_id    BIGINT UNSIGNED NULL,

  solicitada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  impresa_en    DATETIME NULL,
  entregada_en  DATETIME NULL,
  entregada_a   VARCHAR(140) NOT NULL DEFAULT ''
                COMMENT 'Quien la retiró, que no siempre es el titular',
  entregada_por INT UNSIGNED NULL,

  motivo_anulacion VARCHAR(255) NOT NULL DEFAULT '',

  CONSTRAINT fk_cheqra_cuenta  FOREIGN KEY (cuenta_id)     REFERENCES cuentas(id),
  CONSTRAINT fk_cheqra_usuario FOREIGN KEY (usuario_id)    REFERENCES usuarios(id),
  CONSTRAINT fk_cheqra_entrega FOREIGN KEY (entregada_por) REFERENCES usuarios(id),
  CONSTRAINT fk_cheqra_asiento FOREIGN KEY (asiento_id)    REFERENCES asientos(id),

  CONSTRAINT chk_cheqra_rango CHECK (hasta_numero >= desde_numero),

  INDEX idx_cuenta (cuenta_id, estado),
  INDEX idx_usuario (usuario_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. Los cheques
-- ---------------------------------------------------------------------
--
-- Uno por cada papel impreso. Existen desde la impresión, en blanco.
-- ---------------------------------------------------------------------
CREATE TABLE cheques (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chequera_id   INT UNSIGNED NOT NULL,
  cuenta_id     INT UNSIGNED NOT NULL,

  serie         VARCHAR(8)  NOT NULL,
  numero        INT UNSIGNED NOT NULL,

  estado        ENUM('en_blanco','emitido','pagado','rechazado','anulado','suspendido')
                NOT NULL DEFAULT 'en_blanco',

  -- Lo que escribió el cliente, cuando lo escribió.
  beneficiario  VARCHAR(160) NOT NULL DEFAULT '',
  monto_cent    BIGINT NOT NULL DEFAULT 0,
  fecha_emision DATE NULL,

  -- Un cheque posdatado no se paga antes de su fecha. Se guarda
  -- aparte de `fecha_emision` porque son cosas distintas: uno puede
  -- escribirlo hoy con fecha del mes que viene.
  fecha_cobro   DATE NULL,

  concepto      VARCHAR(190) NOT NULL DEFAULT '',

  -- Cuando se presenta al cobro.
  presentado_en DATETIME NULL,
  presentado_por VARCHAR(140) NOT NULL DEFAULT ''
                 COMMENT 'Quien lo llevó a cobrar, o el banco que lo remitió',
  pagado_en     DATETIME NULL,

  -- La razón EXACTA del rechazo. Lista cerrada: «sin fondos» y
  -- «firma no coincide» no son lo mismo ante un juez.
  motivo_rechazo ENUM('sin_fondos','fondos_insuficientes','firma_no_coincide',
                      'cuenta_cerrada','cuenta_bloqueada','suspendido_por_titular',
                      'caducado','posdatado','mal_llenado','alterado') NULL,

  rechazado_en  DATETIME NULL,

  -- La orden de no pago. Existe porque los cheques se pierden y se
  -- roban, y el titular tiene derecho a frenar uno.
  suspendido_en DATETIME NULL,
  motivo_suspension VARCHAR(255) NOT NULL DEFAULT '',

  asiento_id    BIGINT UNSIGNED NULL,

  CONSTRAINT fk_cheque_chequera FOREIGN KEY (chequera_id) REFERENCES chequeras(id),
  CONSTRAINT fk_cheque_cuenta   FOREIGN KEY (cuenta_id)   REFERENCES cuentas(id),
  CONSTRAINT fk_cheque_asiento  FOREIGN KEY (asiento_id)  REFERENCES asientos(id),

  -- Dos cheques de la misma cuenta NO pueden tener el mismo número.
  -- Es lo que impide pagar dos veces el mismo papel.
  UNIQUE KEY uq_cheque (cuenta_id, serie, numero),

  INDEX idx_chequera (chequera_id, numero),
  INDEX idx_estado (estado, presentado_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. Los parámetros
-- ---------------------------------------------------------------------
CREATE TABLE chequera_parametros (
  clave       VARCHAR(40)  NOT NULL PRIMARY KEY,
  valor       VARCHAR(40)  NOT NULL,
  descripcion VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

INSERT INTO chequera_parametros (clave, valor, descripcion) VALUES
  ('comision_cent', '500',
   'Lo que cuesta una chequera. Los bancos del pais cobran por imprimirlas.'),

  ('cheques_por_chequera', '25',
   'Cuantos cheques trae una chequera.'),

  ('dias_caducidad', '180',
   'Un cheque presentado despues de este plazo desde su emision se rechaza por caducado. Son los seis meses que manda el Codigo de Comercio.'),

  ('comision_rechazo_cent', '1000',
   'Lo que se le cobra al librador por un cheque rechazado por falta de fondos.');

-- ---------------------------------------------------------------------
-- 4. El tipo de asiento
-- ---------------------------------------------------------------------
ALTER TABLE asientos
  MODIFY COLUMN tipo ENUM('transferencia','deposito','retiro','pago_cuota',
                          'consumo_tarjeta','pago_tarjeta','interes','mora',
                          'comision','ajuste','nota_abono','nota_cargo',
                          'reverso','arreglo_pago','condonacion','compensacion',
                          'interbancaria','pago_servicio','remesa',
                          'plazo_fijo','cheque') NOT NULL;

-- ---------------------------------------------------------------------
-- 5. Permisos
-- ---------------------------------------------------------------------
INSERT INTO permisos (clave, descripcion)
SELECT * FROM (
  SELECT 'chequera.solicitar' AS clave,
         'Solicitar una chequera para una cuenta corriente propia' AS descripcion
  UNION ALL SELECT 'chequera.administrar',
         'Imprimir, entregar y anular chequeras'
  UNION ALL SELECT 'cheque.pagar',
         'Presentar cheques al cobro, pagarlos o rechazarlos'
  UNION ALL SELECT 'cheque.suspender',
         'Dar orden de no pago sobre un cheque propio'
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave);

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave IN ('chequera.solicitar', 'cheque.suspender')
 WHERE r.clave IN ('cliente', 'administrador', 'presidencia', 'gerencia',
                   'atencionclientes', 'banquero')
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave IN ('chequera.administrar', 'cheque.pagar')
 WHERE r.clave IN ('administrador', 'presidencia', 'gerencia',
                   'atencionclientes', 'cajero')
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );
