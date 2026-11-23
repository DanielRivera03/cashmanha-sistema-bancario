-- -----------------------------------------------------------------
-- 074. El objeto del crédito: qué se está financiando, en concreto
-- -----------------------------------------------------------------
--
-- EL PROBLEMA QUE RESUELVE
--
-- `creditos.destino` es un campo de texto libre de 255 caracteres que
-- nadie contrasta con nada. El resultado salió impreso en un contrato
-- de verdad: la cláusula PRIMERA decía «bajo la modalidad de crédito
-- de vehículo» y la SEGUNDA, dos líneas más abajo, «se obliga a
-- invertir el crédito única y exclusivamente en estudios
-- universitarios». Dos cláusulas del mismo documento diciendo cosas
-- distintas sobre lo mismo.
--
-- Un contrato así no es un error de redacción: es un documento que no
-- se sostiene si alguien lo lee con atención, y menos si hay que
-- ejecutarlo.
--
-- QUÉ SE GUARDA AQUÍ
--
-- Los datos del bien o del fin financiado, con el detalle que pide
-- cada modalidad:
--
--   VEHÍCULO   marca, modelo, año, color, placa, motor, chasis y VIN.
--              Son los datos con los que se identifica un vehículo en
--              el Registro: «un carro usado» no se puede embargar, un
--              VIN sí. Los modelos de prenda que se usan en el país
--              piden exactamente esto.
--
--   VIVIENDA   dirección, municipio, departamento, matrícula del
--              Centro Nacional de Registros, área y tipo de inmueble.
--              La matrícula es lo que identifica el inmueble ante el
--              Registro de la Propiedad; sin ella la hipoteca no se
--              puede inscribir.
--
--   ESTUDIOS   institución, carrera, nivel y duración. Es lo que
--              convierte «estudios universitarios» en algo que el
--              banco puede verificar, que es justo lo que la cláusula
--              del destino le faculta a hacer.
--
--   NEGOCIO    actividad económica, NRC y dirección del negocio.
--
--   PERSONAL   el detalle que se declare, sin más.
--
-- POR QUÉ UNA TABLA APARTE Y NO COLUMNAS EN `creditos`
--
-- Porque son veinte columnas de las que cada crédito usa cinco. En
-- `creditos` quedarían quince columnas vacías en cada fila y la tabla
-- que más se consulta del sistema cargaría con ellas en cada lectura.
--
-- Y POR QUÉ NO REUSAR `garantias`
--
-- Porque no son lo mismo, aunque en el crédito de vehículo coincidan.
-- El objeto es lo que se financia; la garantía es lo que responde si
-- no se paga. Se puede financiar un vehículo y garantizarlo con una
-- hipoteca, o financiar estudios sin garantía ninguna. Mezclarlos
-- obligaría a inventar una garantía para poder decir qué se compró.
-- -----------------------------------------------------------------

CREATE TABLE IF NOT EXISTS credito_objeto (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Cuelga de la solicitud o del crédito ya desembolsado. De la
  -- solicitud porque los datos se capturan ANTES de aprobar: el
  -- vehículo que se va a comprar es parte de lo que se evalúa.
  solicitud_id      INT UNSIGNED NULL,
  credito_id        INT UNSIGNED NULL,

  -- La modalidad manda qué columnas tienen sentido. Se guarda aquí y
  -- no se deduce del producto cada vez: si mañana se renombra un
  -- producto, el contrato ya emitido sigue diciendo lo que decía.
  modalidad         ENUM('vehiculo','vivienda','estudios','negocio','personal') NOT NULL,

  -- El destino, ya normalizado contra la modalidad.
  destino           VARCHAR(255) NOT NULL DEFAULT '',

  -- ---------- Vehículo ----------
  veh_marca         VARCHAR(60)  NULL,
  veh_modelo        VARCHAR(60)  NULL,
  veh_anio          SMALLINT UNSIGNED NULL,
  veh_color         VARCHAR(40)  NULL,
  veh_placa         VARCHAR(20)  NULL,
  veh_motor         VARCHAR(40)  NULL,
  veh_chasis        VARCHAR(40)  NULL,
  veh_vin           VARCHAR(25)  NULL,
  veh_clase         VARCHAR(40)  NULL,  -- automovil, pick-up, motocicleta…
  veh_combustible   VARCHAR(20)  NULL,
  veh_condicion     ENUM('nuevo','usado') NULL,
  veh_vendedor      VARCHAR(160) NULL,

  -- ---------- Vivienda ----------
  inm_tipo          VARCHAR(40)  NULL,  -- casa, apartamento, lote…
  inm_direccion     VARCHAR(255) NULL,
  inm_municipio     VARCHAR(80)  NULL,
  inm_departamento  VARCHAR(80)  NULL,
  inm_matricula     VARCHAR(60)  NULL,  -- la del CNR
  inm_area_m2       DECIMAL(10,2) NULL,
  inm_construccion_m2 DECIMAL(10,2) NULL,
  inm_vendedor      VARCHAR(160) NULL,
  inm_tasacion_cent BIGINT NULL,
  inm_tasacion_en   DATE NULL,          -- la norma pide que no pase de 2 años

  -- ---------- Estudios ----------
  edu_institucion   VARCHAR(160) NULL,
  edu_carrera       VARCHAR(160) NULL,
  edu_nivel         VARCHAR(60)  NULL,  -- tecnico, grado, maestria…
  edu_duracion      VARCHAR(60)  NULL,
  edu_modalidad     VARCHAR(40)  NULL,  -- presencial, en linea…

  -- ---------- Negocio ----------
  neg_actividad     VARCHAR(160) NULL,
  neg_nrc           VARCHAR(30)  NULL,
  neg_direccion     VARCHAR(255) NULL,
  neg_aplicacion    VARCHAR(255) NULL,  -- capital de trabajo, maquinaria…

  -- ---------- Lo que no encaja en ningún molde ----------
  detalle           VARCHAR(500) NULL,

  creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en    DATETIME NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_objeto_credito (credito_id),
  UNIQUE KEY uq_objeto_solicitud (solicitud_id),
  KEY ix_objeto_modalidad (modalidad),

  CONSTRAINT fk_objeto_credito FOREIGN KEY (credito_id)
    REFERENCES creditos (id) ON DELETE CASCADE,
  CONSTRAINT fk_objeto_solicitud FOREIGN KEY (solicitud_id)
    REFERENCES solicitudes_credito (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------
-- Y lo que le faltaba a `garantias`
-- -----------------------------------------------------------------
--
-- El VIN no estaba. Es el número que identifica un vehículo de forma
-- única en el mundo, y el que piden los formularios de inscripción:
-- con marca, modelo y placa se describe un vehículo, con el VIN se
-- identifica ese y no otro.
--
-- El estado registral tampoco. Una garantía «presentada» y una
-- «inscrita» no valen lo mismo —la primera todavía no es oponible a
-- terceros— y es un dato que se reporta al historial crediticio.

ALTER TABLE garantias
  ADD COLUMN vin VARCHAR(25) NULL AFTER chasis,
  ADD COLUMN registro_estado ENUM('sin_registro','presentada','inscrita')
      NOT NULL DEFAULT 'sin_registro' AFTER matricula,
  ADD COLUMN registro_referencia VARCHAR(60) NULL AFTER registro_estado,
  ADD COLUMN tasacion_cent BIGINT NULL AFTER valor_cent,
  ADD COLUMN tasacion_en DATE NULL AFTER tasacion_cent;
