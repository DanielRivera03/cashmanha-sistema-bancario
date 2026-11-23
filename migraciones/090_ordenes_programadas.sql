-- =====================================================================
-- 090. Transferencias programadas y débito automático
-- =====================================================================
--
-- LO QUE HAY QUE DECIR PRIMERO
--
-- `tarjetas.pago_automatico` existe desde hace tiempo. La pantalla de
-- la tarjeta lo ofrece, 35 clientes lo tienen configurado y el sistema
-- les dice que su tarjeta se paga sola.
--
-- NADIE LO EJECUTA. Ni un guion, ni una tarea, ni nada. El cliente
-- confía en que su tarjeta se paga, no la paga, y le corre la mora.
--
-- Una promesa en pantalla que nada cumple es peor que no ofrecer nada:
-- quien no ve la opción busca cómo pagar; quien la ve, no.
--
-- Esta migración trae la maquinaria que faltaba, y el débito de
-- tarjetas pasa a ejecutarse con ella.
--
-- POR QUÉ UNA TABLA DE ÓRDENES Y NO UN CAMPO POR PRODUCTO
--
-- Porque son la misma cosa: «saca X de esta cuenta, cada tanto, y
-- mándalo allá». Un campo en tarjetas, otro en créditos y otro en
-- servicios acabaría en tres ejecutores distintos que se comportan
-- distinto ante el mismo problema —que no haya fondos el día del
-- cargo—, y eso es exactamente donde se pierde el dinero.
--
-- POR QUÉ CADA EJECUCIÓN DEJA FILA
--
-- Porque lo que el cliente pregunta no es «¿está activa mi orden?»,
-- es «¿por qué no se pagó el mes pasado?». Sin el registro de cada
-- intento —el que salió y el que no, y por qué— esa pregunta no tiene
-- respuesta.
--
-- Y porque es lo que impide cobrar dos veces: la pareja de orden y
-- fecha prevista es única.
-- =====================================================================
USE cashmanha_v2;

-- ---------------------------------------------------------------------
-- 1. Las órdenes
-- ---------------------------------------------------------------------
CREATE TABLE ordenes_programadas (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36) NOT NULL UNIQUE,
  folio         VARCHAR(24) NOT NULL UNIQUE,

  usuario_id    INT UNSIGNED NOT NULL,
  alias         VARCHAR(60) NOT NULL DEFAULT ''
                COMMENT 'Como la llama el cliente: "Alquiler", "Tarjeta"',

  tipo          ENUM('transferencia','interbancaria','servicio','tarjeta','credito')
                NOT NULL,

  cuenta_origen_id INT UNSIGNED NOT NULL,

  -- El destino, según el tipo. Solo uno se usa en cada caso; los
  -- demás quedan en NULL. Partirlo en cinco tablas habría hecho que
  -- consultar «mis órdenes» necesitara cinco consultas.
  cuenta_destino_id INT UNSIGNED NULL,
  beneficiario_id   INT UNSIGNED NULL,
  convenio_id       SMALLINT UNSIGNED NULL,
  referencia        VARCHAR(48) NOT NULL DEFAULT '',
  tarjeta_id        INT UNSIGNED NULL,
  credito_id        INT UNSIGNED NULL,

  -- CUÁNTO. Un monto fijo, o lo que se deba ese día.
  --
  -- Para una tarjeta, «total» es el saldo y «minimo» el pago mínimo
  -- del corte. Para un crédito, «total» es la cuota del mes. Esa
  -- diferencia importa: una orden por monto fijo sobre una tarjeta
  -- deja mora si el mínimo sube.
  modo_monto    ENUM('fijo','total','minimo') NOT NULL DEFAULT 'fijo',
  monto_cent    BIGINT NOT NULL DEFAULT 0,

  -- Un techo para los modos variables. Sin esto, una orden «paga el
  -- total» puede vaciar una cuenta el mes que el cliente hizo una
  -- compra grande. Cero significa sin techo.
  tope_cent     BIGINT NOT NULL DEFAULT 0,

  concepto      VARCHAR(160) NOT NULL DEFAULT '',

  -- CUÁNDO
  frecuencia    ENUM('unica','semanal','quincenal','mensual','bimestral',
                     'trimestral','semestral','anual') NOT NULL DEFAULT 'mensual',

  dia_mes       TINYINT UNSIGNED NULL
                COMMENT 'Día del mes, 1-31. Si el mes no lo tiene, se usa el último.',
  dia_semana    TINYINT UNSIGNED NULL COMMENT '1 lunes … 7 domingo',

  fecha_inicio  DATE NOT NULL,
  fecha_fin     DATE NULL COMMENT 'Sin fecha, hasta que la cancelen',
  proxima_en    DATE NOT NULL,

  estado        ENUM('activa','pausada','terminada','cancelada')
                NOT NULL DEFAULT 'activa',

  -- REINTENTOS
  --
  -- Que no haya fondos el día 5 no significa que no los haya el 6. Un
  -- banco reintenta unos días antes de rendirse; rendirse al primer
  -- intento convierte un descuido de un día en una mora de un mes.
  reintentos_max      TINYINT UNSIGNED NOT NULL DEFAULT 3,
  reintentos_hechos   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  fallos_consecutivos SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  ejecuciones   INT UNSIGNED NOT NULL DEFAULT 0,
  ultima_en     DATETIME NULL,

  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelada_en  DATETIME NULL,
  motivo_fin    VARCHAR(255) NOT NULL DEFAULT '',

  CONSTRAINT fk_ord_usuario  FOREIGN KEY (usuario_id)        REFERENCES usuarios(id),
  CONSTRAINT fk_ord_origen   FOREIGN KEY (cuenta_origen_id)  REFERENCES cuentas(id),
  CONSTRAINT fk_ord_destino  FOREIGN KEY (cuenta_destino_id) REFERENCES cuentas(id),
  CONSTRAINT fk_ord_benef    FOREIGN KEY (beneficiario_id)   REFERENCES beneficiarios(id),
  CONSTRAINT fk_ord_convenio FOREIGN KEY (convenio_id)       REFERENCES convenios_servicio(id),
  CONSTRAINT fk_ord_tarjeta  FOREIGN KEY (tarjeta_id)        REFERENCES tarjetas(id),
  CONSTRAINT fk_ord_credito  FOREIGN KEY (credito_id)        REFERENCES creditos(id),

  CONSTRAINT chk_ord_monto CHECK (modo_monto <> 'fijo' OR monto_cent > 0),

  INDEX idx_pendientes (estado, proxima_en),
  INDEX idx_usuario (usuario_id, estado)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. Cada intento
-- ---------------------------------------------------------------------
CREATE TABLE ordenes_ejecuciones (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  orden_id      BIGINT UNSIGNED NOT NULL,

  -- La fecha en que TOCABA, no la de hoy. Es lo que hace que un
  -- reintento no cuente como una ejecución nueva.
  prevista_para DATE NOT NULL,

  intentada_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  resultado     ENUM('exitosa','sin_fondos','rechazada','omitida','error')
                NOT NULL,

  monto_cent    BIGINT NOT NULL DEFAULT 0,
  detalle       VARCHAR(255) NOT NULL DEFAULT '',

  -- A qué dio lugar: un asiento, un folio de transferencia, un pago
  -- de servicio. Es lo que permite ir del «se pagó» al comprobante.
  asiento_id    BIGINT UNSIGNED NULL,
  referencia    VARCHAR(40) NOT NULL DEFAULT '',

  CONSTRAINT fk_ordej_orden   FOREIGN KEY (orden_id)   REFERENCES ordenes_programadas(id),
  CONSTRAINT fk_ordej_asiento FOREIGN KEY (asiento_id) REFERENCES asientos(id),

  -- UNA ejecución EXITOSA por orden y fecha prevista. Es lo único que
  -- impide pagar dos veces el recibo del mismo mes si el barrido corre
  -- dos veces o si alguien lo lanza a mano.
  --
  -- Los intentos fallidos sí se repiten: son los reintentos, y hay que
  -- poder verlos todos.
  UNIQUE KEY uq_exito (orden_id, prevista_para, resultado),

  INDEX idx_orden (orden_id, prevista_para)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. El tipo de asiento
-- ---------------------------------------------------------------------
ALTER TABLE asientos
  MODIFY COLUMN tipo ENUM('transferencia','deposito','retiro','pago_cuota',
                          'consumo_tarjeta','pago_tarjeta','interes','mora',
                          'comision','ajuste','nota_abono','nota_cargo',
                          'reverso','arreglo_pago','condonacion','compensacion',
                          'interbancaria','pago_servicio','remesa',
                          'plazo_fijo','cheque','programada') NOT NULL;

-- ---------------------------------------------------------------------
-- 4. La tarea
-- ---------------------------------------------------------------------
--
-- Dos veces al día: a primera hora para lo del día, y a media tarde
-- para reintentar lo que no pudo. Un reintento al día siguiente llega
-- tarde cuando la fecha límite es hoy.
-- ---------------------------------------------------------------------
INSERT INTO tareas_sistema (clave, descripcion, cada_minutos, hora_preferida)
SELECT 'ejecutar-programadas',
       'Ejecuta las transferencias programadas y los debitos automaticos que tocan hoy, y reintenta los que no pudieron por falta de fondos. Sin esto, el pago automatico de tarjeta que el cliente configuro no se ejecuta nunca y le corre la mora.',
       720,
       '07:30:00'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM tareas_sistema WHERE clave = 'ejecutar-programadas');

-- ---------------------------------------------------------------------
-- 5. Permisos
-- ---------------------------------------------------------------------
INSERT INTO permisos (clave, descripcion)
SELECT * FROM (
  SELECT 'programada.administrar' AS clave,
         'Crear, pausar y cancelar ordenes programadas propias' AS descripcion
  UNION ALL SELECT 'programada.ver_todas',
         'Ver las ordenes programadas de cualquier cliente'
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave);

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'programada.administrar'
 WHERE r.clave IN ('cliente', 'administrador', 'presidencia', 'gerencia',
                   'atencionclientes', 'banquero')
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'programada.ver_todas'
 WHERE r.clave IN ('administrador', 'presidencia', 'gerencia')
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );
