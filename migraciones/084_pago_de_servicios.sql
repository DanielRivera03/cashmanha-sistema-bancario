-- =====================================================================
-- 084. Pago de servicios y colecturías
-- =====================================================================
--
-- POR QUÉ ESTO PESA TANTO
--
-- Es lo que la gente hace todos los meses: la luz, el agua, el
-- teléfono, el colegio, el seguro, el ISSS, los impuestos. Una banca en
-- línea salvadoreña sin pago de servicios obliga al cliente a salir de
-- ella justo cuando más la usaría.
--
-- CÓMO FUNCIONA DE VERDAD, QUE NO ES COMO PARECE
--
-- El banco NO sabe cuánto debe el cliente. Esa deuda vive en la
-- empresa que cobra —la distribuidora de energía, la ANDA, el colegio—
-- y el banco la consulta: manda la referencia y el colector responde
-- con el titular, el monto, el periodo y la fecha límite.
--
-- Por eso el flujo tiene DOS pasos y no uno: primero consultar, que no
-- mueve dinero, y después pagar lo que el colector dijo. Un sistema
-- que dejara al cliente teclear el monto a mano estaría inventando una
-- deuda, y el colector rechazaría el pago o lo aplicaría mal.
--
-- LA REFERENCIA NO ES UNA SOLA COSA
--
-- Cada colector usa la suya: el NPE del mandamiento de Hacienda, el
-- NIS del contador de la luz, el número de contrato del agua, el
-- número de teléfono, el carné del estudiante. Por eso el catálogo
-- guarda QUÉ pedir y CÓMO se llama, en lugar de un campo genérico
-- llamado «referencia» que no le dice nada a nadie.
--
-- LA CONTRAPARTIDA
--
-- El dinero sale del cliente pero todavía no es del colector: el banco
-- se lo debe hasta que liquida con él. Eso es una cuenta técnica de
-- colecturía, igual que la cámara interbancaria de la migración 079.
-- No es un rodeo para cuadrar: es lo que de verdad ocurre.
-- =====================================================================
USE cashmanha_v2;

-- ---------------------------------------------------------------------
-- 1. El catálogo de convenios
-- ---------------------------------------------------------------------
CREATE TABLE convenios_servicio (
  id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codigo          VARCHAR(16)  NOT NULL UNIQUE,
  nombre          VARCHAR(120) NOT NULL,
  nombre_corto    VARCHAR(40)  NOT NULL,

  categoria       ENUM('energia','agua','telefonia','internet_cable','educacion',
                       'seguros','gobierno','salud','credito','otros')
                  NOT NULL DEFAULT 'otros',

  -- Qué se le pide al cliente y cómo se llama en SU factura. Pedir
  -- «referencia» a secas obliga a adivinar; pedir «NIS (aparece arriba
  -- a la derecha de su factura)» no.
  tipo_referencia ENUM('npe','nis','nic','contrato','telefono','carnet','poliza','nit')
                  NOT NULL DEFAULT 'contrato',
  etiqueta_referencia VARCHAR(60) NOT NULL DEFAULT 'Número de referencia',
  ayuda_referencia    VARCHAR(160) NOT NULL DEFAULT '',

  ref_largo_min   TINYINT UNSIGNED NOT NULL DEFAULT 4,
  ref_largo_max   TINYINT UNSIGNED NOT NULL DEFAULT 40,
  ref_solo_digitos TINYINT(1) NOT NULL DEFAULT 1,

  -- Hay colectores que aceptan abonos y otros que exigen el total.
  -- Permitir un parcial donde no se acepta deja al cliente creyendo
  -- que pagó algo que le van a devolver.
  permite_parcial TINYINT(1) NOT NULL DEFAULT 0,

  -- El banco puede cobrar por recaudar. Casi nunca se le cobra al
  -- cliente por pagar su luz, así que por defecto es cero.
  comision_cent   BIGINT UNSIGNED NOT NULL DEFAULT 0,

  activo          TINYINT(1) NOT NULL DEFAULT 1,
  orden           SMALLINT UNSIGNED NOT NULL DEFAULT 100,

  creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_categoria (categoria, activo, orden)
) ENGINE=InnoDB;

-- Los colectores reales del país, con la referencia que pide cada uno.
INSERT INTO convenios_servicio
  (codigo, nombre, nombre_corto, categoria, tipo_referencia,
   etiqueta_referencia, ayuda_referencia, ref_largo_min, ref_largo_max,
   permite_parcial, orden) VALUES

  -- Energía eléctrica
  ('CAESS', 'CAESS', 'CAESS', 'energia', 'nis',
   'NIS', 'Aparece en su factura como NIS, arriba a la derecha.', 6, 12, 1, 10),
  ('DELSUR', 'DELSUR', 'DELSUR', 'energia', 'nic',
   'NIC', 'El número de cuenta que aparece en su recibo de energía.', 6, 12, 1, 11),
  ('CLESA', 'CLESA', 'CLESA', 'energia', 'nis',
   'NIS', 'Aparece en su factura como NIS.', 6, 12, 1, 12),
  ('EEO', 'Empresa Eléctrica de Oriente', 'EEO', 'energia', 'nis',
   'NIS', 'Aparece en su factura como NIS.', 6, 12, 1, 13),
  ('DEUSEM', 'DEUSEM', 'DEUSEM', 'energia', 'nis',
   'NIS', 'Aparece en su factura como NIS.', 6, 12, 1, 14),

  -- Agua
  ('ANDA', 'Administración Nacional de Acueductos y Alcantarillados', 'ANDA',
   'agua', 'contrato',
   'Número de cuenta o NIS', 'Está impreso en su recibo de agua.', 6, 14, 0, 20),

  -- Telefonía e internet
  ('CLAROM', 'Claro Telefonía Móvil', 'Claro Móvil', 'telefonia', 'telefono',
   'Número de teléfono', 'Los ocho dígitos de la línea, sin guiones.', 8, 8, 1, 30),
  ('CLAROF', 'Claro Telefonía Fija', 'Claro Fija', 'telefonia', 'telefono',
   'Número de teléfono', 'Los ocho dígitos de la línea, sin guiones.', 8, 8, 1, 31),
  ('TIGO', 'Tigo El Salvador', 'Tigo', 'telefonia', 'contrato',
   'Número de cuenta', 'Aparece en su factura como número de cuenta.', 6, 16, 1, 32),
  ('DIGICEL', 'Digicel', 'Digicel', 'telefonia', 'telefono',
   'Número de teléfono', 'Los ocho dígitos de la línea, sin guiones.', 8, 8, 1, 33),

  -- Gobierno
  ('MH-NPE', 'Ministerio de Hacienda', 'Hacienda', 'gobierno', 'npe',
   'NPE', 'El Número de Pago Electrónico de su mandamiento de pago.', 10, 40, 0, 40),
  ('ISSS', 'Instituto Salvadoreño del Seguro Social', 'ISSS', 'gobierno', 'npe',
   'NPE', 'El NPE que le envió el ISSS por correo.', 10, 40, 0, 41),
  ('DUICENTRO', 'DUI y documentos de identidad', 'DUI', 'gobierno', 'npe',
   'NPE', 'El NPE que aparece en su comprobante de trámite.', 10, 40, 0, 42),

  -- Educación y salud
  ('COLEGIO', 'Colegios y universidades con convenio', 'Colegios',
   'educacion', 'carnet',
   'Carné del estudiante', 'El código que le asignó la institución.', 4, 20, 0, 50),

  -- Seguros
  ('SEGUROS', 'Aseguradoras con convenio', 'Seguros', 'seguros', 'poliza',
   'Número de póliza', 'Aparece en la carátula de su póliza.', 4, 24, 0, 60);

-- ---------------------------------------------------------------------
-- 2. La cuenta técnica de colecturía
-- ---------------------------------------------------------------------
--
-- Entre que el cliente paga y el banco liquida con el colector, ese
-- dinero es una deuda del banco. Vive aquí, igual que lo que viaja por
-- la cámara interbancaria.
-- ---------------------------------------------------------------------
INSERT INTO productos (codigo, nombre, tipo, descripcion, activo, publico)
VALUES ('TEC-COLECTURIA', 'Cuenta técnica de colecturía', 'corriente',
        'Contrapartida de los pagos de servicios, hasta que se liquidan con cada colector.',
        1, 0);

SET @producto_colect := LAST_INSERT_ID();

INSERT INTO cuentas (uuid, numero_cuenta, usuario_id, producto_id, moneda,
                     saldo_cent, estado, es_tecnica)
VALUES (UUID(), '0000000007', 1, @producto_colect, 'USD', 0, 'activa', 1);

-- ---------------------------------------------------------------------
-- 3. Las referencias guardadas del cliente
-- ---------------------------------------------------------------------
--
-- Nadie se sabe de memoria el NIS de su contador. Guardarlo con un
-- nombre propio —«Casa», «Casa de mi mamá»— es la diferencia entre
-- pagar en diez segundos y tener que ir a buscar la factura.
--
-- A diferencia de la agenda de beneficiarios, esto NO lleva período de
-- enfriamiento: el dinero va a una empresa con convenio y queda
-- aplicado a un servicio concreto, no a una cuenta que alguien pueda
-- vaciar. El riesgo no es el mismo y el estorbo tampoco se justifica.
-- ---------------------------------------------------------------------
CREATE TABLE servicios_guardados (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36) NOT NULL UNIQUE,
  usuario_id    INT UNSIGNED NOT NULL,
  convenio_id   SMALLINT UNSIGNED NOT NULL,

  alias         VARCHAR(60) NOT NULL,
  referencia    VARCHAR(48) NOT NULL,

  -- El último titular que devolvió el colector. Sirve para que la
  -- pantalla muestre a nombre de quién está el servicio sin tener que
  -- consultar otra vez.
  titular       VARCHAR(140) NOT NULL DEFAULT '',

  ultimo_pago_en   DATETIME NULL,
  veces_pagado     INT UNSIGNED NOT NULL DEFAULT 0,

  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_servguard_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id),
  CONSTRAINT fk_servguard_convenio FOREIGN KEY (convenio_id) REFERENCES convenios_servicio(id),

  UNIQUE KEY uq_servicio (usuario_id, convenio_id, referencia),
  INDEX idx_usuario (usuario_id, ultimo_pago_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. Los pagos
-- ---------------------------------------------------------------------
--
-- Un pago de servicio no viaja como una transferencia interbancaria:
-- se aplica en el acto contra el colector. Pero sí puede REVERSARSE
-- cuando el colector lo rechaza al liquidar —la referencia dejó de
-- existir, el servicio se dio de baja— y por eso tiene estados y
-- guarda sus dos asientos.
-- ---------------------------------------------------------------------
CREATE TABLE pagos_servicio (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36) NOT NULL UNIQUE,
  folio           VARCHAR(24) NOT NULL UNIQUE,

  usuario_id      INT UNSIGNED NOT NULL,
  cuenta_id       INT UNSIGNED NOT NULL,
  convenio_id     SMALLINT UNSIGNED NOT NULL,
  guardado_id     INT UNSIGNED NULL,

  referencia      VARCHAR(48)  NOT NULL,
  titular         VARCHAR(140) NOT NULL DEFAULT '',

  -- Lo que el colector dijo que se debía, y lo que de verdad se pagó.
  -- Guardar los dos permite distinguir un abono parcial de un pago
  -- total sin tener que deducirlo.
  monto_cent      BIGINT NOT NULL,
  deuda_cent      BIGINT NOT NULL DEFAULT 0,
  comision_cent   BIGINT NOT NULL DEFAULT 0,

  periodo         VARCHAR(40) NOT NULL DEFAULT ''
                  COMMENT 'El periodo facturado, tal como lo da el colector',
  vence_el        DATE NULL,

  estado          ENUM('aplicado','liquidado','reversado') NOT NULL DEFAULT 'aplicado',
  motivo          VARCHAR(160) NOT NULL DEFAULT '',

  -- El comprobante que devuelve el colector: es lo que el cliente
  -- enseña si la empresa dice que no le pagaron.
  autorizacion    VARCHAR(40) NOT NULL DEFAULT '',

  asiento_id          BIGINT UNSIGNED NULL,
  asiento_reverso_id  BIGINT UNSIGNED NULL,

  creado_en       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  liquidado_en    DATETIME NULL,

  CONSTRAINT fk_pagoserv_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id),
  CONSTRAINT fk_pagoserv_cuenta   FOREIGN KEY (cuenta_id)   REFERENCES cuentas(id),
  CONSTRAINT fk_pagoserv_convenio FOREIGN KEY (convenio_id) REFERENCES convenios_servicio(id),
  CONSTRAINT fk_pagoserv_guardado FOREIGN KEY (guardado_id) REFERENCES servicios_guardados(id),
  CONSTRAINT fk_pagoserv_asiento  FOREIGN KEY (asiento_id)  REFERENCES asientos(id),
  CONSTRAINT fk_pagoserv_reverso  FOREIGN KEY (asiento_reverso_id) REFERENCES asientos(id),

  CONSTRAINT chk_pagoserv_monto CHECK (monto_cent > 0),

  INDEX idx_usuario_fecha (usuario_id, creado_en),
  INDEX idx_estado (estado, creado_en),
  INDEX idx_convenio (convenio_id, creado_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. El tipo de asiento
-- ---------------------------------------------------------------------
ALTER TABLE asientos
  MODIFY COLUMN tipo ENUM('transferencia','deposito','retiro','pago_cuota',
                          'consumo_tarjeta','pago_tarjeta','interes','mora',
                          'comision','ajuste','nota_abono','nota_cargo',
                          'reverso','arreglo_pago','condonacion','compensacion',
                          'interbancaria','pago_servicio') NOT NULL;

-- ---------------------------------------------------------------------
-- 6. El techo institucional del canal
-- ---------------------------------------------------------------------
--
-- Más alto que el de transferencias y con más operaciones al día: pagar
-- servicios es una actividad rutinaria y de bajo riesgo —el dinero va a
-- una empresa con convenio, aplicado a un servicio concreto— y quien
-- paga la luz de tres casas y dos colegios el mismo día no está
-- haciendo nada raro.
--
-- `requiere_otp` en 0 por lo mismo, y porque es lo que hacen los bancos
-- del país: nadie pide un token para pagar el recibo del agua. El
-- segundo factor se guarda para donde el dinero puede acabar en manos
-- de cualquiera.
-- ---------------------------------------------------------------------
INSERT INTO limites_operacion
  (tipo_operacion, rol_id, max_por_operacion_cent, max_diario_cent,
   max_mensual_cent, max_ops_diarias, requiere_otp,
   umbral_doble_control_cent, activo)
VALUES ('servicios', NULL, 500000, 1500000, 30000000, 30, 0, 1000000, 1);

-- ---------------------------------------------------------------------
-- 7. La liquidación con los colectores
-- ---------------------------------------------------------------------
INSERT INTO tareas_sistema (clave, descripcion, cada_minutos, hora_preferida)
SELECT 'liquidar-colecturia',
       'Liquida con cada colector los pagos de servicios del dia y vacia la cuenta tecnica de colecturia. Sin esto, el dinero de los clientes se queda indefinidamente como deuda del banco frente a las empresas recaudadoras.',
       1440,
       '07:00:00'
  FROM DUAL
 WHERE NOT EXISTS (
       SELECT 1 FROM tareas_sistema WHERE clave = 'liquidar-colecturia'
 );
