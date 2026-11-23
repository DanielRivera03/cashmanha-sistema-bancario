-- =====================================================================
-- 082. Prevención de lavado de dinero y de activos
-- =====================================================================
--
-- POR QUÉ ESTO NO ES OPCIONAL
--
-- Todo lo demás que lleva este sistema son decisiones de producto: se
-- pueden hacer mejor o peor, antes o después. Esto no. La Ley Contra
-- el Lavado de Dinero y de Activos impone obligaciones concretas a las
-- instituciones financieras, y un banco que no las cumple no es un
-- banco con una función menos: es un banco que opera fuera de la ley.
--
-- LO QUE MANDA LA LEY, Y DE DÓNDE SALE CADA NÚMERO
--
-- Artículo 9 de la LCLDA, con su reforma: hay que informar a la Unidad
-- de Investigación Financiera de la Fiscalía General de la República
-- las operaciones EN EFECTIVO, individuales o múltiples, de un mismo
-- cliente, que superen los US$10,000.00 en un día o en un mes
-- calendario. Por cualquier otro medio, el umbral es US$25,000.00.
--
-- El plazo es de cinco días hábiles contados a partir del día
-- siguiente de realizada la operación; cuando lo que se reporta es la
-- acumulación de un mes, los cinco días cuentan desde el día siguiente
-- al cierre de ese mes.
--
-- Artículo 9-A: el reporte de operación sospechosa no tiene umbral. Lo
-- que lo dispara es la SOSPECHA, no el monto. El encargado de
-- cumplimiento dispone de quince días hábiles para analizarla,
-- prorrogables una sola vez por otros quince previa solicitud a la
-- UIF, y cinco días hábiles para remitir el reporte desde que concluye
-- que la operación es irregular.
--
-- LO QUE ESTE ESQUEMA NO HACE
--
-- No manda nada a la UIF. El envío es por los canales de la Fiscalía,
-- con credenciales que un sistema no se da a sí mismo. Lo que hace es
-- DETECTAR, DEJAR CONSTANCIA y ARMAR el reporte con todo lo que la ley
-- pide, de modo que el encargado de cumplimiento tenga delante lo que
-- necesita y el banco pueda demostrar que vigiló.
--
-- Esa distinción importa: un sistema que dijera «reportado a la UIF»
-- sin haber reportado nada sería peor que no tener nada, porque daría
-- por cumplida una obligación que seguiría pendiente.
-- =====================================================================
USE cashmanha_v2;

-- ---------------------------------------------------------------------
-- 1. El perfil transaccional declarado
-- ---------------------------------------------------------------------
--
-- Lo que el cliente dice que va a mover, dicho por él al abrir. Sin
-- esto, «operación inusual» no significa nada: inusual es lo que se
-- sale de lo esperado, y lo esperado hay que haberlo preguntado.
--
-- Es también lo que convierte una alerta en algo defendible. «Movió
-- $9,000» no es un hallazgo. «Movió $9,000 cuando declaró ingresos de
-- $600 al mes» sí lo es.
-- ---------------------------------------------------------------------
CREATE TABLE perfil_transaccional (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id         INT UNSIGNED NOT NULL,

  actividad_economica VARCHAR(120) NOT NULL DEFAULT '',
  origen_fondos      VARCHAR(160) NOT NULL DEFAULT '',

  ingreso_mensual_cent       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  egreso_mensual_cent        BIGINT UNSIGNED NOT NULL DEFAULT 0,

  -- Lo que espera mover por el banco, que no es lo mismo que lo que gana.
  monto_mensual_esperado_cent BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ops_mensuales_esperadas     SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  -- Si dice que no va a usar efectivo y lo usa, eso también es una señal.
  usa_efectivo       TINYINT(1) NOT NULL DEFAULT 1,
  opera_con_exterior TINYINT(1) NOT NULL DEFAULT 0,

  declarado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  vigente_hasta      DATE NULL COMMENT 'Un perfil viejo deja de servir de referencia',

  CONSTRAINT fk_perfil_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  UNIQUE KEY uq_perfil_usuario (usuario_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. La ficha de cumplimiento del cliente
-- ---------------------------------------------------------------------
--
-- El nivel de riesgo no es una etiqueta decorativa: decide con qué
-- frecuencia hay que revisar al cliente y cuánta documentación se le
-- exige. Una persona expuesta políticamente lleva debida diligencia
-- intensificada por mandato legal, no por criterio del banco.
-- ---------------------------------------------------------------------
CREATE TABLE cumplimiento_cliente (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id         INT UNSIGNED NOT NULL,

  nivel_riesgo       ENUM('bajo','medio','alto') NOT NULL DEFAULT 'bajo',

  -- Persona expuesta políticamente. `pep_relacionado` cubre al familiar
  -- o socio cercano, que la ley trata igual y que es por donde se
  -- escapa quien quiere evitar el escrutinio.
  pep                TINYINT(1) NOT NULL DEFAULT 0,
  pep_relacionado    TINYINT(1) NOT NULL DEFAULT 0,
  pep_cargo          VARCHAR(160) NOT NULL DEFAULT '',
  pep_desde          DATE NULL,
  pep_hasta          DATE NULL,

  pais_riesgo        TINYINT(1) NOT NULL DEFAULT 0,

  -- Debida diligencia: cuándo se hizo y cuándo toca la siguiente. Un
  -- expediente que nadie revisa desde hace tres años no es diligencia.
  diligencia_en      DATE NULL,
  diligencia_proxima DATE NULL,
  diligencia_por     INT UNSIGNED NULL,

  -- Marcado por coincidir con una lista restrictiva. Se guarda aparte
  -- del nivel de riesgo porque una coincidencia exige decisión humana:
  -- los nombres se repiten y un homónimo no es un criminal.
  en_lista           TINYINT(1) NOT NULL DEFAULT 0,
  lista_resuelta_en  DATETIME NULL,
  lista_nota         VARCHAR(255) NOT NULL DEFAULT '',

  notas              TEXT NULL,

  actualizado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_cumpl_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_cumpl_dilig   FOREIGN KEY (diligencia_por) REFERENCES usuarios(id),
  UNIQUE KEY uq_cumpl_usuario (usuario_id),

  INDEX idx_riesgo (nivel_riesgo),
  INDEX idx_revision (diligencia_proxima)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. Las listas restrictivas
-- ---------------------------------------------------------------------
--
-- OFAC, Naciones Unidas y las locales. El sistema no puede descargarlas
-- solo —cada una tiene su formato y su licencia— así que la tabla
-- existe para que se carguen, y el cotejo corre contra lo que haya.
--
-- `nombre_normalizado` guarda el nombre sin tildes y en minúsculas,
-- porque es contra eso que se compara: buscar «Peña» y no encontrar
-- «PENA» sería un filtro que no filtra.
-- ---------------------------------------------------------------------
CREATE TABLE listas_restrictivas (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  lista              ENUM('ofac','onu','ue','local','interna') NOT NULL,
  nombre             VARCHAR(200) NOT NULL,
  nombre_normalizado VARCHAR(200) NOT NULL,

  documento          VARCHAR(40) NOT NULL DEFAULT '',
  pais               CHAR(2) NOT NULL DEFAULT '',
  programa           VARCHAR(120) NOT NULL DEFAULT ''
                     COMMENT 'El motivo por el que figura en la lista',

  vigente            TINYINT(1) NOT NULL DEFAULT 1,
  cargado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_normalizado (nombre_normalizado),
  INDEX idx_documento (documento),
  INDEX idx_lista (lista, vigente)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. Las alertas
-- ---------------------------------------------------------------------
--
-- Una alerta NO es un reporte. Es una señal que alguien tiene que
-- mirar, y la mayoría se descartan con una explicación razonable: le
-- pagaron el aguinaldo, vendió el carro, recibió una herencia.
--
-- Confundir las dos cosas tiene un costo en los dos sentidos: reportar
-- todo es no reportar nada, y no dejar constancia de lo que se
-- descartó es no poder demostrar que se miró.
-- ---------------------------------------------------------------------
CREATE TABLE alertas_cumplimiento (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  folio           VARCHAR(24) NOT NULL UNIQUE,

  usuario_id      INT UNSIGNED NOT NULL,

  tipo            ENUM('umbral_efectivo_diario','umbral_efectivo_mensual',
                       'umbral_otros_medios','fraccionamiento',
                       'fuera_de_perfil','pep_movimiento_alto',
                       'lista_restrictiva','cuenta_nueva_activa',
                       'interbancaria_inusual') NOT NULL,

  -- Qué tan fuerte es la señal. No todas pesan igual y tratarlas igual
  -- ahoga lo importante en lo rutinario.
  severidad       ENUM('baja','media','alta') NOT NULL DEFAULT 'media',

  periodo_desde   DATE NOT NULL,
  periodo_hasta   DATE NOT NULL,

  monto_cent      BIGINT NOT NULL DEFAULT 0,
  operaciones     INT UNSIGNED NOT NULL DEFAULT 0,

  detalle         TEXT NOT NULL COMMENT 'Qué se vio, en palabras',
  datos_json      TEXT NULL COMMENT 'Las operaciones que la dispararon',

  estado          ENUM('nueva','en_analisis','descartada','escalada','reportada')
                  NOT NULL DEFAULT 'nueva',

  -- El plazo del artículo 9-A: quince días hábiles para analizar,
  -- prorrogables una vez. Se guarda la fecha límite para que el panel
  -- pueda ordenar por lo que de verdad corre prisa.
  analisis_vence  DATE NULL,
  prorrogada      TINYINT(1) NOT NULL DEFAULT 0,

  analista_id     INT UNSIGNED NULL,
  resolucion      TEXT NULL,
  resuelta_en     DATETIME NULL,

  creada_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_alerta_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id),
  CONSTRAINT fk_alerta_analista FOREIGN KEY (analista_id) REFERENCES usuarios(id),

  -- Una misma señal, para un mismo cliente y un mismo periodo, se
  -- levanta UNA vez. Sin esto, un barrido que corre cada día levantaría
  -- la misma alerta cada día y el panel sería inservible en una semana.
  UNIQUE KEY uq_senal (usuario_id, tipo, periodo_desde, periodo_hasta),

  INDEX idx_estado (estado, severidad, creada_en),
  INDEX idx_vence (analisis_vence)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. Los reportes a la UIF
-- ---------------------------------------------------------------------
--
-- Dos clases, y la diferencia es de fondo:
--
--   RTE  Reporte de transacción en efectivo. Es OBJETIVO: se pasó del
--        umbral, se reporta. No implica sospecha de nada y el cliente
--        no tiene por qué enterarse de nada malo, porque no lo hay.
--
--   ROS  Reporte de operación sospechosa. Es un JUICIO del encargado
--        de cumplimiento. Y lleva una obligación que no se puede
--        incumplir: no se le avisa al cliente, ni se le insinúa. La ley
--        lo prohíbe expresamente y avisarle es delito.
--
-- Por eso `confidencial` está en la tabla y vale 1 en los ROS: para que
-- ninguna pantalla, correo o notificación del sistema lo saque por
-- descuido hacia el cliente.
-- ---------------------------------------------------------------------
CREATE TABLE reportes_uif (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  folio           VARCHAR(24) NOT NULL UNIQUE,

  tipo            ENUM('rte','ros','rtos') NOT NULL
                  COMMENT 'RTE efectivo, ROS sospechosa, RTOS tentativa',

  usuario_id      INT UNSIGNED NOT NULL,
  alerta_id       BIGINT UNSIGNED NULL,

  periodo_desde   DATE NOT NULL,
  periodo_hasta   DATE NOT NULL,

  monto_cent      BIGINT NOT NULL DEFAULT 0,
  operaciones     INT UNSIGNED NOT NULL DEFAULT 0,

  relato          TEXT NOT NULL COMMENT 'La descripción de los hechos',
  datos_json      TEXT NULL,

  -- El plazo legal: cinco días hábiles. Se calcula al crear el reporte
  -- y se guarda, para que nadie tenga que recalcularlo ni discutirlo.
  vence_en        DATE NOT NULL,

  estado          ENUM('borrador','listo','enviado','anulado') NOT NULL DEFAULT 'borrador',

  -- Lo que el sistema NO hace es enviar. Estos dos campos los llena
  -- quien envía de verdad, por los canales de la Fiscalía.
  enviado_en      DATETIME NULL,
  enviado_por     INT UNSIGNED NULL,
  acuse           VARCHAR(80) NOT NULL DEFAULT '',

  confidencial    TINYINT(1) NOT NULL DEFAULT 0
                  COMMENT 'Un ROS no se le comunica al cliente: la ley lo prohibe',

  creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  creado_por      INT UNSIGNED NULL,

  CONSTRAINT fk_ruif_usuario FOREIGN KEY (usuario_id)  REFERENCES usuarios(id),
  CONSTRAINT fk_ruif_alerta  FOREIGN KEY (alerta_id)   REFERENCES alertas_cumplimiento(id),
  CONSTRAINT fk_ruif_envio   FOREIGN KEY (enviado_por) REFERENCES usuarios(id),
  CONSTRAINT fk_ruif_creador FOREIGN KEY (creado_por)  REFERENCES usuarios(id),

  INDEX idx_estado (estado, vence_en),
  INDEX idx_usuario (usuario_id, creado_en)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. La ficha de cumplimiento para quien ya es cliente
-- ---------------------------------------------------------------------
--
-- Se crea en nivel bajo y sin PEP, que es lo que corresponde mientras
-- nadie haya dicho otra cosa. Lo importante es que EXISTA la fila: un
-- cliente sin ficha es un cliente a quien nadie ha evaluado, y eso es
-- distinto de un cliente evaluado como de riesgo bajo.
-- ---------------------------------------------------------------------
INSERT INTO cumplimiento_cliente (usuario_id, nivel_riesgo, diligencia_proxima)
SELECT u.id, 'bajo', DATE_ADD(CURDATE(), INTERVAL 1 YEAR)
  FROM usuarios u
 WHERE NOT EXISTS (SELECT 1 FROM cumplimiento_cliente c WHERE c.usuario_id = u.id);

-- ---------------------------------------------------------------------
-- 7. La tarea de vigilancia
-- ---------------------------------------------------------------------
--
-- Una vez al día, de madrugada. El monitoreo de lavado no corre dentro
-- de la transacción que mueve el dinero, y eso es deliberado: si la
-- vigilancia fallara, no puede llevarse por delante una operación
-- legítima del cliente. Se vigila sobre lo ya ocurrido, que es como
-- funcionan los sistemas de monitoreo de verdad.
-- ---------------------------------------------------------------------
INSERT INTO tareas_sistema (clave, descripcion, cada_minutos, hora_preferida)
SELECT 'vigilar-cumplimiento',
       'Revisa las operaciones del dia contra los umbrales de la Ley Contra el Lavado de Dinero: efectivo sobre $10,000, otros medios sobre $25,000, fraccionamiento, perfil transaccional y listas restrictivas. Levanta alertas para el encargado de cumplimiento.',
       1440,
       '05:30:00'
  FROM DUAL
 WHERE NOT EXISTS (
       SELECT 1 FROM tareas_sistema WHERE clave = 'vigilar-cumplimiento'
 );
