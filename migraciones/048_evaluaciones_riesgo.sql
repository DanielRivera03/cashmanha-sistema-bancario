-- ---------------------------------------------------------------------
-- La bitácora de las decisiones de crédito.
--
-- Hasta ahora una solicitud se aceptaba o se rechazaba y de la decisión
-- quedaba, como mucho, una línea de observaciones. Eso no basta: a un
-- cliente hay que poder decirle exactamente qué pesó en su contra, y a
-- un supervisor hay que poder reconstruirle una decisión de hace años
-- con los datos de entonces y la regla de entonces.
--
-- Por eso se guarda todo: los hechos de entrada, el puntaje, la banda,
-- los códigos de razón y la versión del modelo. Cambiar los pesos del
-- motor no debe poder reescribir el pasado.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS evaluaciones_riesgo (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid           CHAR(36)        NOT NULL,
  usuario_id     INT UNSIGNED    NOT NULL,
  solicitud_id   INT UNSIGNED    NULL DEFAULT NULL,
  producto       VARCHAR(30)     NOT NULL
                 COMMENT 'credito, tarjeta, extrafinanciamiento, oferta',

  -- La regla con la que se decidió. Sin esto, una evaluación vieja se
  -- leería con los pesos de hoy y no cuadraría con lo que se resolvió.
  version_modelo VARCHAR(10)     NOT NULL,

  puntaje        SMALLINT UNSIGNED NOT NULL,
  banda          CHAR(1)         NOT NULL COMMENT 'A excelente … E alto riesgo',
  aprueba        TINYINT(1)      NOT NULL DEFAULT 0,

  -- Los códigos de razón con sus puntos: es lo que hace explicable la
  -- decisión. Una narrativa sin ellos se puede escribir de cualquier
  -- manera; con ellos, tiene que cuadrar.
  razones_json   LONGTEXT        NULL,
  vetos_json     LONGTEXT        NULL,
  hechos_json    LONGTEXT        NULL,
  narrativa      VARCHAR(1500)   NULL,

  creado_en      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_evaluacion_uuid (uuid),
  KEY idx_evaluacion_usuario (usuario_id, id),
  KEY idx_evaluacion_solicitud (solicitud_id),
  KEY idx_evaluacion_banda (banda, creado_en),

  CONSTRAINT fk_evaluacion_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La solicitud guarda a qué evaluación respondió.
ALTER TABLE solicitudes_credito
  ADD COLUMN evaluacion_id BIGINT UNSIGNED NULL DEFAULT NULL
      COMMENT 'La evaluación de riesgo con la que se resolvió'
      AFTER buro_consulta_id,
  ADD KEY idx_solicitud_evaluacion (evaluacion_id);
