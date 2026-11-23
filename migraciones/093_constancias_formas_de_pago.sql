-- =====================================================================
-- 093. Cómo se paga una constancia
-- =====================================================================
--
-- LO QUE FALTABA
--
-- El arancel se cobraba siempre debitando una cuenta. Eso deja fuera
-- dos caminos que existen de verdad en una agencia:
--
--   1. El cliente llega a ATENCIÓN AL CLIENTE, pide su constancia y la
--      paga EN EFECTIVO. Atención no cobra efectivo —eso es de caja—
--      así que genera la solicitud y la manda a caja; el cliente pasa,
--      paga, y hasta entonces se emite.
--
--   2. Desde la banca en línea, con TARJETA. Y aquí hay una regla del
--      banco que no es técnica sino comercial: solo se aceptan
--      tarjetas CashMan H.A. Cobrarse a sí mismo con la tarjeta de
--      otro banco significa pagarle a ese banco una comisión de
--      adquirencia por cobrar su propio arancel.
--
-- POR QUÉ LA CONSTANCIA NO SE EMITE HASTA QUE SE PAGA
--
-- Porque la constancia ES el producto. Emitirla y después cobrar
-- dejaría al banco con un documento verificable circulando y un cobro
-- pendiente; y anularla después, con el cliente ya enseñándola.
--
-- Por eso existe la SOLICITUD: el trámite vive antes que el documento.
-- =====================================================================
USE cashmanha_v2;

-- ---------------------------------------------------------------------
-- 1. La forma de pago en la constancia emitida
-- ---------------------------------------------------------------------
ALTER TABLE constancias
  ADD COLUMN forma_pago ENUM('cuenta','efectivo','tarjeta','exenta')
      NOT NULL DEFAULT 'cuenta'
      COMMENT 'Como se pago el arancel'
      AFTER comision_cent,

  ADD COLUMN solicitud_id BIGINT UNSIGNED NULL
      COMMENT 'La solicitud que la origino, cuando se pago en caja'
      AFTER forma_pago;

-- ---------------------------------------------------------------------
-- 2. Las solicitudes pendientes de pago
-- ---------------------------------------------------------------------
--
-- Lo que atención al cliente manda a caja. Vive hasta que se paga —y
-- entonces nace la constancia— o hasta que caduca.
-- ---------------------------------------------------------------------
CREATE TABLE constancia_solicitudes (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  folio         VARCHAR(24) NOT NULL UNIQUE,

  usuario_id    INT UNSIGNED NOT NULL,

  tipo          ENUM('saldo','referencia_bancaria','solvencia','no_adeudo','ingresos')
                NOT NULL,

  objeto_tipo   ENUM('cuenta','credito','tarjeta','global') NOT NULL DEFAULT 'global',
  objeto_id     BIGINT UNSIGNED NULL,

  dirigida_a    VARCHAR(160) NOT NULL DEFAULT '',
  proposito     VARCHAR(255) NOT NULL DEFAULT '',

  comision_cent BIGINT NOT NULL DEFAULT 0,

  estado        ENUM('pendiente_pago','pagada','emitida','anulada','vencida')
                NOT NULL DEFAULT 'pendiente_pago',

  -- Quién la generó en atención al cliente.
  generada_por  INT UNSIGNED NOT NULL,
  generada_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Una solicitud sin pagar no puede quedarse abierta para siempre:
  -- el saldo que certificaría sería de hace semanas.
  vence_en      DATETIME NOT NULL,

  -- Quién la cobró, en qué caja.
  pagada_en     DATETIME NULL,
  cobrada_por   INT UNSIGNED NULL,
  caja_sesion_id BIGINT UNSIGNED NULL,

  constancia_id BIGINT UNSIGNED NULL,
  asiento_id    BIGINT UNSIGNED NULL,

  motivo_anulacion VARCHAR(255) NOT NULL DEFAULT '',

  CONSTRAINT fk_csol_usuario  FOREIGN KEY (usuario_id)    REFERENCES usuarios(id),
  CONSTRAINT fk_csol_genera   FOREIGN KEY (generada_por)  REFERENCES usuarios(id),
  CONSTRAINT fk_csol_cobra    FOREIGN KEY (cobrada_por)   REFERENCES usuarios(id),
  CONSTRAINT fk_csol_const    FOREIGN KEY (constancia_id) REFERENCES constancias(id),
  CONSTRAINT fk_csol_asiento  FOREIGN KEY (asiento_id)    REFERENCES asientos(id),

  INDEX idx_pendientes (estado, vence_en),
  INDEX idx_usuario (usuario_id, generada_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. La caja tiene que poder anotar este cobro
-- ---------------------------------------------------------------------
--
-- Sin esto, el efectivo que entra por una constancia no aparecería en
-- el arqueo del cajero y su corte no cuadraría al final del día.
-- ---------------------------------------------------------------------
ALTER TABLE caja_movimientos
  MODIFY COLUMN tipo ENUM('fondo_inicial','deposito','retiro','pago_credito',
                          'pago_tarjeta','nota_abono','nota_cargo',
                          'remesa_entrada','remesa_salida','ajuste',
                          'constancia') NOT NULL;

-- ---------------------------------------------------------------------
-- 4. Cuánto vive una solicitud sin pagar
-- ---------------------------------------------------------------------
CREATE TABLE constancia_config (
  clave       VARCHAR(40)  NOT NULL PRIMARY KEY,
  valor       VARCHAR(40)  NOT NULL,
  descripcion VARCHAR(300) NOT NULL
) ENGINE=InnoDB;

INSERT INTO constancia_config (clave, valor, descripcion) VALUES
  ('horas_vigencia_solicitud', '24',
   'Cuantas horas vive una solicitud sin pagar. Pasado ese plazo caduca: el saldo que certificaria ya no seria el de hoy y habria que volver a pedirla.'),

  ('solo_tarjeta_propia', '1',
   'Si vale 1, el arancel solo se puede pagar con tarjetas CashMan H.A. Cobrarse a si mismo con la tarjeta de otro banco significa pagarle a ese banco una comision por cobrar el propio arancel.');

-- ---------------------------------------------------------------------
-- 5. La tarea que caduca las solicitudes
-- ---------------------------------------------------------------------
INSERT INTO tareas_sistema (clave, descripcion, cada_minutos, hora_preferida)
SELECT 'vencer-solicitudes-constancia',
       'Caduca las solicitudes de constancia que nadie pago dentro de su plazo. Una solicitud vieja certificaria un saldo que ya no es el de hoy.',
       360,
       NULL
  FROM DUAL
 WHERE NOT EXISTS (
       SELECT 1 FROM tareas_sistema WHERE clave = 'vencer-solicitudes-constancia'
 );

-- ---------------------------------------------------------------------
-- 6. Permiso para cobrarlas en caja
-- ---------------------------------------------------------------------
INSERT INTO permisos (clave, descripcion)
SELECT 'constancia.cobrar',
       'Cobrar en caja una solicitud de constancia y emitirla'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'constancia.cobrar');

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'constancia.cobrar'
 WHERE r.clave IN ('cajero', 'administrador', 'presidencia', 'gerencia')
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );
