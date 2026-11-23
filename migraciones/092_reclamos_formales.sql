-- =====================================================================
-- 092. Reclamos formales
-- =====================================================================
--
-- POR QUÉ NO BASTAN LOS TICKETS QUE YA EXISTEN
--
-- `tickets` es soporte: «no me carga la pantalla», «cómo activo mi
-- tarjeta». Se atiende lo antes posible y se cierra.
--
-- Un RECLAMO es otra cosa. Es un acto con consecuencias jurídicas: el
-- cliente dice que el banco le cobró algo que no debía, que una
-- operación no la hizo él, que le aplicaron mal un pago. Eso tiene
-- PLAZO LEGAL de respuesta, tiene derecho de escalamiento a la
-- Defensoría del Consumidor, y lo que se resuelva puede devolver
-- dinero.
--
-- Meterlos en la misma tabla habría tenido un costo concreto: un
-- reclamo con plazo legal se habría perdido entre cincuenta
-- preguntas de «cómo cambio mi clave», y nadie habría podido
-- contestar «¿cuántos reclamos tenemos fuera de plazo?».
--
-- LOS PLAZOS, Y LO QUE NO PUDE VERIFICAR
--
-- La Ley de Protección al Consumidor dice que sus términos se cuentan
-- en DÍAS HÁBILES. Eso está claro.
--
-- Sobre el plazo de resolución, las fuentes que pude consultar se
-- contradicen: un documento del Banco Central habla de treinta días
-- prorrogables por quince, y en el mismo documento se afirma que ni
-- la LPC ni la NPB4-46 fijan un plazo máximo para las entidades.
--
-- Así que el plazo va en una TABLA, no clavado en el código, con su
-- valor por defecto y la advertencia escrita: hay que cotejarlo con
-- la norma vigente antes de operar de verdad. Un plazo inventado y
-- presentado como legal sería peor que no tener ninguno.
--
-- EL DERECHO A NO ESPERARNOS
--
-- El consumidor puede acudir a la Defensoría del Consumidor SIN
-- agotar antes el proceso con el proveedor. El sistema no puede dar a
-- entender lo contrario, y por eso la pantalla lo dice.
-- =====================================================================
USE cashmanha_v2;

-- ---------------------------------------------------------------------
-- 1. Los reclamos
-- ---------------------------------------------------------------------
CREATE TABLE reclamos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36) NOT NULL UNIQUE,
  folio         VARCHAR(24) NOT NULL UNIQUE,

  usuario_id    INT UNSIGNED NOT NULL,

  canal         ENUM('banca_en_linea','agencia','telefono','correo','defensoria')
                NOT NULL DEFAULT 'banca_en_linea',

  categoria     ENUM('cargo_indebido','operacion_no_reconocida','pago_mal_aplicado',
                     'cobro_de_intereses','comisiones','tarjeta','credito',
                     'cuenta','atencion','informacion_crediticia','otro')
                NOT NULL DEFAULT 'otro',

  asunto        VARCHAR(160) NOT NULL,
  relato        TEXT NOT NULL COMMENT 'Lo que el cliente cuenta, en sus palabras',

  -- Cuánto reclama. Cero cuando no reclama dinero sino un trato.
  monto_cent    BIGINT NOT NULL DEFAULT 0,

  -- Sobre qué producto. Permite ir del reclamo al movimiento.
  objeto_tipo   ENUM('cuenta','tarjeta','credito','ninguno') NOT NULL DEFAULT 'ninguno',
  objeto_id     BIGINT UNSIGNED NULL,
  referencia    VARCHAR(64) NOT NULL DEFAULT ''
                COMMENT 'Folio del movimiento reclamado, si lo hay',

  estado        ENUM('recibido','en_analisis','espera_cliente','resuelto_favorable',
                     'resuelto_desfavorable','desistido','escalado')
                NOT NULL DEFAULT 'recibido',

  -- EL PLAZO. Se calcula al recibirlo y se guarda, para que nadie
  -- tenga que recalcularlo ni discutirlo después.
  recibido_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  vence_en      DATE NOT NULL,

  prorrogado    TINYINT(1) NOT NULL DEFAULT 0,
  prorroga_motivo VARCHAR(255) NOT NULL DEFAULT '',

  -- El tiempo que el cliente tarda en contestar NO cuenta contra el
  -- banco: si se le pidió un documento, el plazo se suspende. Pero
  -- hay que registrarlo, o sería una excusa para no responder nunca.
  dias_suspendidos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  suspendido_desde DATE NULL,

  asignado_a    INT UNSIGNED NULL,

  resolucion    TEXT NULL,
  resuelto_en   DATETIME NULL,
  resuelto_por  INT UNSIGNED NULL,

  -- Lo que se le devolvió, si se le devolvió algo.
  compensacion_cent BIGINT NOT NULL DEFAULT 0,
  asiento_id    BIGINT UNSIGNED NULL,

  -- Si el cliente lo llevó a la Defensoría.
  escalado_en   DATETIME NULL,
  referencia_externa VARCHAR(60) NOT NULL DEFAULT '',

  CONSTRAINT fk_recl_usuario  FOREIGN KEY (usuario_id)   REFERENCES usuarios(id),
  CONSTRAINT fk_recl_asignado FOREIGN KEY (asignado_a)   REFERENCES usuarios(id),
  CONSTRAINT fk_recl_resuelto FOREIGN KEY (resuelto_por) REFERENCES usuarios(id),
  CONSTRAINT fk_recl_asiento  FOREIGN KEY (asiento_id)   REFERENCES asientos(id),

  INDEX idx_usuario (usuario_id, recibido_en),
  INDEX idx_abiertos (estado, vence_en),
  INDEX idx_categoria (categoria, recibido_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. La conversación
-- ---------------------------------------------------------------------
--
-- Un reclamo no es un formulario: es un ida y vuelta. Y cada mensaje
-- importa, porque la resolución se sostiene en lo que se dijo.
-- ---------------------------------------------------------------------
CREATE TABLE reclamo_mensajes (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reclamo_id    BIGINT UNSIGNED NOT NULL,

  de            ENUM('cliente','banco') NOT NULL,
  autor_id      INT UNSIGNED NULL,

  texto         TEXT NOT NULL,

  -- Un mensaje interno no lo ve el cliente. Sirve para que el
  -- analista deje notas sin que parezcan respuestas oficiales.
  interno       TINYINT(1) NOT NULL DEFAULT 0,

  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_rmsg_reclamo FOREIGN KEY (reclamo_id) REFERENCES reclamos(id),
  CONSTRAINT fk_rmsg_autor   FOREIGN KEY (autor_id)   REFERENCES usuarios(id),

  INDEX idx_reclamo (reclamo_id, creado_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. Los plazos, configurables y con su advertencia
-- ---------------------------------------------------------------------
CREATE TABLE reclamo_parametros (
  clave       VARCHAR(40)  NOT NULL PRIMARY KEY,
  valor       VARCHAR(40)  NOT NULL,
  descripcion VARCHAR(400) NOT NULL
) ENGINE=InnoDB;

INSERT INTO reclamo_parametros (clave, valor, descripcion) VALUES
  ('dias_habiles_respuesta', '30',
   'Dias habiles para resolver un reclamo. VERIFICAR contra la norma vigente: las fuentes consultadas se contradicen. Un documento del BCR habla de 30 dias prorrogables por 15, y en el mismo texto se afirma que ni la LPC ni la NPB4-46 fijan un plazo maximo. Lo que si esta claro es que los terminos de la LPC se cuentan en dias habiles.'),

  ('dias_habiles_prorroga', '15',
   'Dias habiles adicionales cuando el caso lo amerita. Se usa UNA vez y hay que justificarla.'),

  ('dias_aviso_previo', '5',
   'Con cuantos dias habiles de antelacion se avisa al area de que un reclamo esta por vencer.'),

  ('dias_espera_cliente', '10',
   'Cuanto se espera una respuesta del cliente antes de resolver con lo que haya. Pasado ese plazo no se puede tener el reclamo abierto indefinidamente.');

-- ---------------------------------------------------------------------
-- 4. La tarea de vigilancia de plazos
-- ---------------------------------------------------------------------
--
-- Un reclamo que vence sin que nadie lo note es exactamente lo que
-- esta tabla existe para evitar. La tarea avisa ANTES, no después.
-- ---------------------------------------------------------------------
INSERT INTO tareas_sistema (clave, descripcion, cada_minutos, hora_preferida)
SELECT 'vigilar-reclamos',
       'Avisa de los reclamos que estan por vencer su plazo legal de respuesta y de los que ya vencieron, y cierra los que llevan demasiado esperando informacion del cliente.',
       1440,
       '08:00:00'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM tareas_sistema WHERE clave = 'vigilar-reclamos');

-- ---------------------------------------------------------------------
-- 5. Permisos
-- ---------------------------------------------------------------------
INSERT INTO permisos (clave, descripcion)
SELECT * FROM (
  SELECT 'reclamo.presentar' AS clave,
         'Presentar un reclamo formal sobre productos propios' AS descripcion
  UNION ALL SELECT 'reclamo.atender',
         'Atender reclamos: analizarlos, pedir informacion y responderlos'
  UNION ALL SELECT 'reclamo.resolver',
         'Resolver un reclamo, con o sin compensacion'
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave);

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'reclamo.presentar'
 WHERE r.clave IN ('cliente', 'administrador', 'presidencia', 'gerencia',
                   'atencionclientes', 'banquero', 'cajero')
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'reclamo.atender'
 WHERE r.clave IN ('administrador', 'presidencia', 'gerencia',
                   'atencionclientes', 'juridico')
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );

-- Resolver con compensación mueve dinero: va más arriba.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'reclamo.resolver'
 WHERE r.clave IN ('administrador', 'presidencia', 'gerencia', 'juridico')
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );
