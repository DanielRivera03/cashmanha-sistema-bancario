-- =====================================================================
-- 088. Depósitos a plazo fijo
-- =====================================================================
--
-- QUÉ FALTABA
--
-- El producto de ahorro más básico que tiene cualquier banco, y el
-- único de captación que este sistema no ofrecía: el cliente entrega
-- una suma, se compromete a no tocarla durante un plazo, y el banco le
-- paga una tasa mejor que la de una cuenta de ahorro a cambio de esa
-- certeza.
--
-- CÓMO SE MODELA, Y POR QUÉ ASÍ
--
-- Cada depósito a plazo ES UNA CUENTA. No una tabla aparte con un
-- saldo propio: una fila en `cuentas`, con un producto de tipo
-- `plazo`.
--
-- La tentación era guardar el monto en la tabla del depósito y
-- llevarlo por fuera del libro mayor. Habría sido un error: todo el
-- sistema de cuadre —los asientos, la conciliación, las
-- comprobaciones 7 y 8 del verificador— trabaja sobre `cuentas`, y un
-- saldo que vive fuera de ahí es un saldo que nadie comprueba.
--
-- Así, constituir un plazo fijo es una transferencia como cualquier
-- otra, y el capital queda vigilado por la misma maquinaria que todo
-- lo demás.
--
-- LO QUE NO SE PUEDE HACER CON ESA CUENTA
--
-- Transferir desde ella, pagar servicios o retirar en ventanilla. Si
-- se pudiera, no sería un plazo fijo. El bloqueo no se hace
-- escondiéndola de los desplegables —eso lo salta cualquiera cambiando
-- un identificador— sino en `Autorizacion::exigirCuentaOperable()`,
-- que es por donde pasan todas las operaciones.
--
-- LA RETENCIÓN DE RENTA
--
-- El artículo 159 del Código Tributario obliga a las instituciones
-- financieras a retener el 10% de los intereses pagados por depósitos
-- a personas domiciliadas en El Salvador. Para personas naturales esa
-- retención es PAGO DEFINITIVO: no vuelven a declarar ese ingreso.
--
-- Hay exención cuando el saldo promedio del depósito no supera los
-- US$25,000. Está recogida abajo y es configurable, porque los
-- umbrales tributarios cambian y un número clavado en el código no se
-- puede auditar.
--
-- ADVERTENCIA HONESTA: estos valores se tomaron del Código Tributario
-- y de las guías publicadas. Antes de operar de verdad hay que
-- cotejarlos contra el texto vigente: una retención mal aplicada es un
-- problema con Hacienda, no un error de pantalla.
-- =====================================================================
USE cashmanha_v2;

-- ---------------------------------------------------------------------
-- 1. El tipo de producto
-- ---------------------------------------------------------------------
ALTER TABLE productos
  MODIFY COLUMN tipo ENUM('ahorro','corriente','credito','tarjeta','plazo') NOT NULL;

-- ---------------------------------------------------------------------
-- 2. El tarifario por plazo
-- ---------------------------------------------------------------------
--
-- A más plazo, mejor tasa: es lo que el banco paga por la certeza de
-- disponer del dinero. Las tasas son verosímiles para el mercado
-- salvadoreño y se dejan en tabla, no en código, porque cambian.
-- ---------------------------------------------------------------------
CREATE TABLE plazo_tasas (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dias_min      SMALLINT UNSIGNED NOT NULL,
  dias_max      SMALLINT UNSIGNED NOT NULL,
  tasa_bp       INT UNSIGNED NOT NULL COMMENT 'Tasa anual en puntos base: 450 = 4.50%',
  monto_minimo_cent BIGINT UNSIGNED NOT NULL DEFAULT 10000,
  activa        TINYINT(1) NOT NULL DEFAULT 1,

  CONSTRAINT chk_plazo_rango CHECK (dias_max >= dias_min),
  INDEX idx_rango (dias_min, dias_max, activa)
) ENGINE=InnoDB;

INSERT INTO plazo_tasas (dias_min, dias_max, tasa_bp, monto_minimo_cent) VALUES
  (30,   59,  225, 10000),   -- 2.25% a 30 días, desde $100
  (60,   89,  275, 10000),
  (90,  179,  350, 10000),
  (180, 269,  425, 10000),
  (270, 359,  475, 10000),
  (360, 539,  550, 10000),   -- 5.50% a un año
  (540, 719,  600, 50000),
  (720, 1080, 650, 50000);   -- 6.50% a dos años, desde $500

-- ---------------------------------------------------------------------
-- 3. Los parámetros tributarios y de penalidad
-- ---------------------------------------------------------------------
CREATE TABLE plazo_parametros (
  clave         VARCHAR(40)  NOT NULL PRIMARY KEY,
  valor         VARCHAR(40)  NOT NULL,
  descripcion   VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

INSERT INTO plazo_parametros (clave, valor, descripcion) VALUES
  ('retencion_bp', '1000',
   'Retencion de renta sobre intereses: 10% segun el art. 159 del Codigo Tributario.'),

  ('retencion_exento_cent', '2500000',
   'Saldo promedio hasta el cual la persona natural esta exenta de la retencion: US$25,000.'),

  ('penalidad_bp', '5000',
   'Parte de los intereses devengados que se pierde al cancelar antes del vencimiento: 50%.'),

  ('dias_base', '360',
   'Base de dias del ano para el calculo de intereses. La banca usa 360.');

-- ---------------------------------------------------------------------
-- 4. Los depósitos
-- ---------------------------------------------------------------------
CREATE TABLE depositos_plazo (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36) NOT NULL UNIQUE,
  folio         VARCHAR(24) NOT NULL UNIQUE,

  usuario_id    INT UNSIGNED NOT NULL,

  -- LA cuenta del depósito: aquí vive el capital, vigilado por el
  -- mismo libro mayor que todo lo demás.
  cuenta_id     INT UNSIGNED NOT NULL UNIQUE,

  -- De dónde salió y a dónde vuelve. Pueden ser distintas.
  cuenta_origen_id INT UNSIGNED NOT NULL,
  cuenta_abono_id  INT UNSIGNED NOT NULL
                   COMMENT 'Donde se abonan intereses y capital al vencer',

  monto_cent    BIGINT NOT NULL,
  tasa_bp       INT UNSIGNED NOT NULL,
  plazo_dias    SMALLINT UNSIGNED NOT NULL,

  fecha_inicio  DATE NOT NULL,
  fecha_vence   DATE NOT NULL,

  -- Al vencer: devolver todo, renovar solo el capital, o renovar
  -- capital más intereses. Es la decisión que el cliente toma al
  -- abrirlo y la que más valor tiene preguntar.
  renovacion    ENUM('no','capital','capital_intereses') NOT NULL DEFAULT 'no',
  renovado_de   BIGINT UNSIGNED NULL COMMENT 'El depósito del que viene, si se renovó',
  veces_renovado SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  estado        ENUM('vigente','vencido','liquidado','cancelado','renovado')
                NOT NULL DEFAULT 'vigente',

  -- Lo que de verdad se pagó al liquidar. Se guarda porque es lo que
  -- va en el certificado y en la constancia tributaria del cliente.
  intereses_cent BIGINT NOT NULL DEFAULT 0,
  retencion_cent BIGINT NOT NULL DEFAULT 0,
  penalidad_cent BIGINT NOT NULL DEFAULT 0,

  asiento_apertura_id    BIGINT UNSIGNED NULL,
  asiento_liquidacion_id BIGINT UNSIGNED NULL,

  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  liquidado_en  DATETIME NULL,

  CONSTRAINT fk_dpf_usuario FOREIGN KEY (usuario_id)       REFERENCES usuarios(id),
  CONSTRAINT fk_dpf_cuenta  FOREIGN KEY (cuenta_id)        REFERENCES cuentas(id),
  CONSTRAINT fk_dpf_origen  FOREIGN KEY (cuenta_origen_id) REFERENCES cuentas(id),
  CONSTRAINT fk_dpf_abono   FOREIGN KEY (cuenta_abono_id)  REFERENCES cuentas(id),
  CONSTRAINT fk_dpf_padre   FOREIGN KEY (renovado_de)      REFERENCES depositos_plazo(id),
  CONSTRAINT fk_dpf_as_ap   FOREIGN KEY (asiento_apertura_id)    REFERENCES asientos(id),
  CONSTRAINT fk_dpf_as_liq  FOREIGN KEY (asiento_liquidacion_id) REFERENCES asientos(id),

  CONSTRAINT chk_dpf_monto CHECK (monto_cent > 0),
  CONSTRAINT chk_dpf_fechas CHECK (fecha_vence > fecha_inicio),

  INDEX idx_usuario (usuario_id, estado),
  INDEX idx_vence (estado, fecha_vence)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. El producto
-- ---------------------------------------------------------------------
INSERT INTO productos (codigo, nombre, tipo, descripcion, activo, publico,
                       saldo_minimo_cent, monto_minimo_cent)
VALUES ('DPF', 'Depósito a Plazo Fijo', 'plazo',
        'El cliente entrega una suma por un plazo acordado y recibe una tasa mejor que la de una cuenta de ahorro.',
        1, 1, 0, 10000);

-- ---------------------------------------------------------------------
-- 6. El tipo de asiento
-- ---------------------------------------------------------------------
ALTER TABLE asientos
  MODIFY COLUMN tipo ENUM('transferencia','deposito','retiro','pago_cuota',
                          'consumo_tarjeta','pago_tarjeta','interes','mora',
                          'comision','ajuste','nota_abono','nota_cargo',
                          'reverso','arreglo_pago','condonacion','compensacion',
                          'interbancaria','pago_servicio','remesa',
                          'plazo_fijo') NOT NULL;

-- ---------------------------------------------------------------------
-- 7. La tarea de vencimientos
-- ---------------------------------------------------------------------
--
-- Un plazo fijo que vence y nadie toca no puede quedarse quieto: o se
-- renueva como pidió el cliente, o el capital y los intereses vuelven
-- a su cuenta. Dejarlo vencido sin hacer nada sería retenerle el
-- dinero a alguien que ya cumplió su parte.
-- ---------------------------------------------------------------------
INSERT INTO tareas_sistema (clave, descripcion, cada_minutos, hora_preferida)
SELECT 'vencer-plazos',
       'Liquida o renueva los depositos a plazo que vencen hoy, segun lo que pidio el cliente al abrirlos, y avisa con antelacion a los que estan por vencer.',
       1440,
       '06:45:00'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM tareas_sistema WHERE clave = 'vencer-plazos');

-- ---------------------------------------------------------------------
-- 8. Permisos
-- ---------------------------------------------------------------------
INSERT INTO permisos (clave, descripcion)
SELECT * FROM (
  SELECT 'plazo.abrir_propio' AS clave,
         'Constituir un deposito a plazo con fondos propios' AS descripcion
  UNION ALL SELECT 'plazo.ver_todos',
         'Ver los depositos a plazo de cualquier cliente'
  UNION ALL SELECT 'plazo.cancelar_terceros',
         'Cancelar anticipadamente el deposito de un cliente'
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave);

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'plazo.abrir_propio'
 WHERE r.clave IN ('cliente', 'administrador', 'presidencia', 'gerencia',
                   'atencionclientes', 'banquero')
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave IN ('plazo.ver_todos', 'plazo.cancelar_terceros')
 WHERE r.clave IN ('administrador', 'presidencia', 'gerencia', 'banquero')
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );
