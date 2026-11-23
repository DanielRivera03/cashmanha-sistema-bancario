-- ---------------------------------------------------------------------
-- El recorrido de una solicitud de tarjeta.
--
-- La solicitud guardaba su etapa actual y nada más. Con eso se puede
-- decir «está en revisión», pero no CUÁNDO entró en revisión, ni cuánto
-- lleva ahí, ni qué pasó antes. El cliente veía una barra a medias y un
-- texto fijo, y la única forma de saber si algo se había movido era
-- volver a mirar la misma barra.
--
-- Para el cliente eso es lo que más importa de un trámite: no tanto en
-- qué paso va como que SE ESTÁ MOVIENDO. Una solicitud parada cuatro
-- días y una que avanzó ayer se veían exactamente igual.
--
-- Cada paso queda con su fecha y con quién lo dio. Para la institución
-- es la trazabilidad de una decisión de crédito —quién revisó qué y
-- cuándo—, que es justamente lo que hay que poder enseñar si alguien
-- pregunta por qué se aprobó o se negó una línea.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS solicitud_tarjeta_historial (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  solicitud_id  INT UNSIGNED NOT NULL,

  etapa_anterior ENUM('capturada','revision_atencion','revision_gerencia',
                      'aprobada','rechazada','emitida') NULL
                 COMMENT 'NULL en el primer paso: antes no había nada',
  etapa_nueva    ENUM('capturada','revision_atencion','revision_gerencia',
                      'aprobada','rechazada','emitida') NOT NULL,

  -- Quién lo movió. NULL si lo movió el propio sistema.
  actor_id      INT UNSIGNED NULL,

  -- Lo que se le enseña al CLIENTE de este paso. No es la observación
  -- interna: eso vive en `solicitudes_tarjeta.observaciones` y no todo
  -- lo que el personal anota es para el titular.
  nota          VARCHAR(300) NULL,

  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_hist_solicitud
    FOREIGN KEY (solicitud_id) REFERENCES solicitudes_tarjeta (id)
    ON DELETE CASCADE ON UPDATE CASCADE,

  CONSTRAINT fk_hist_actor
    FOREIGN KEY (actor_id) REFERENCES usuarios (id)
    ON DELETE SET NULL ON UPDATE CASCADE,

  INDEX ix_hist_solicitud (solicitud_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Lo que ya pasó ----------------------------------------------------
--
-- Las solicitudes que existen no tienen historial porque nadie lo
-- guardaba. Se les pone al menos su primer paso, con la fecha real de
-- creación: es el único hecho del que hay constancia cierta.
--
-- NO se inventan los pasos intermedios. Una solicitud que hoy está en
-- «revisión de gerencia» pasó por atención en algún momento, pero no
-- sabemos cuándo, y ponerle una fecha supuesta a un paso de un trámite
-- de crédito sería fabricar trazabilidad.

INSERT INTO solicitud_tarjeta_historial (solicitud_id, etapa_anterior, etapa_nueva, nota, creado_en)
SELECT s.id, NULL, 'capturada', 'Solicitud recibida.', s.creado_en
  FROM solicitudes_tarjeta s
 WHERE NOT EXISTS (
         SELECT 1 FROM solicitud_tarjeta_historial h WHERE h.solicitud_id = s.id
       );

-- Y la etapa en la que están hoy, si no es la primera, con la fecha de
-- su última actualización, que sí consta.
INSERT INTO solicitud_tarjeta_historial (solicitud_id, etapa_anterior, etapa_nueva, nota, creado_en)
SELECT s.id, NULL, s.etapa,
       'Estado al momento de habilitarse el seguimiento.', s.actualizado_en
  FROM solicitudes_tarjeta s
 WHERE s.etapa <> 'capturada'
   AND NOT EXISTS (
         SELECT 1 FROM solicitud_tarjeta_historial h
          WHERE h.solicitud_id = s.id AND h.etapa_nueva = s.etapa
       );
