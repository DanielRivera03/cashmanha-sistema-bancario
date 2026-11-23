-- =====================================================================
-- 009 — Las referencias pertenecen al expediente del cliente
-- ---------------------------------------------------------------------
-- Problema que corrige:
--
-- Las referencias colgaban del crédito o de la solicitud con ON DELETE
-- CASCADE. Es decir: al cancelar una solicitud o dar de baja un crédito,
-- las referencias del cliente desaparecían, y en el siguiente trámite
-- había que volver a pedírselas y a verificarlas por teléfono. Un
-- trabajo que ya estaba hecho se perdía por un borrado ajeno.
--
-- Una referencia es de la PERSONA, no del trámite. Alguien que responde
-- por un cliente lo sigue haciendo en la solicitud siguiente. Por eso
-- pasa a ser parte de su expediente:
--
--   * `usuario_id` obligatorio: el cliente al que pertenece.
--   * `credito_id` y `solicitud_id` quedan como rastro de dónde se
--     capturó o se usó, con ON DELETE SET NULL. Si el trámite
--     desaparece, la referencia sigue en el expediente.
--
-- De paso se quita una clave foránea duplicada sobre `credito_id`
-- (`fk_ref_credito` y `fk_referencias_credito` apuntaban a lo mismo:
-- la segunda se añadió en la migración 008 sin advertir la primera).
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. El dueño
-- ---------------------------------------------------------------------

ALTER TABLE referencias
    ADD COLUMN usuario_id INT(10) UNSIGNED NULL AFTER id;

-- Se deduce de dónde colgaba cada una.
UPDATE referencias r
   JOIN creditos c ON c.id = r.credito_id
   SET r.usuario_id = c.usuario_id
 WHERE r.usuario_id IS NULL;

UPDATE referencias r
   JOIN solicitudes_credito s ON s.id = r.solicitud_id
   SET r.usuario_id = s.usuario_id
 WHERE r.usuario_id IS NULL;

-- Si alguna quedó sin dueño es que ya estaba huérfana: se elimina
-- porque no hay forma de saber de quién era.
DELETE FROM referencias WHERE usuario_id IS NULL;

ALTER TABLE referencias
    MODIFY COLUMN usuario_id INT(10) UNSIGNED NOT NULL;

-- ---------------------------------------------------------------------
-- 2. Los enlaces dejan de arrastrar la referencia al borrarse
-- ---------------------------------------------------------------------

ALTER TABLE referencias DROP FOREIGN KEY IF EXISTS fk_ref_credito;
ALTER TABLE referencias DROP FOREIGN KEY IF EXISTS fk_referencias_credito;
ALTER TABLE referencias DROP FOREIGN KEY IF EXISTS fk_referencias_solicitud;

-- El CHECK de la 008 exigía que colgara de un trámite; ahora cuelga del
-- cliente, así que sobra.
ALTER TABLE referencias DROP CONSTRAINT IF EXISTS chk_referencia_origen;

ALTER TABLE referencias
    ADD CONSTRAINT fk_referencias_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE;

ALTER TABLE referencias
    ADD CONSTRAINT fk_referencias_credito
        FOREIGN KEY (credito_id) REFERENCES creditos(id) ON DELETE SET NULL;

ALTER TABLE referencias
    ADD CONSTRAINT fk_referencias_solicitud
        FOREIGN KEY (solicitud_id) REFERENCES solicitudes_credito(id) ON DELETE SET NULL;

CREATE INDEX idx_referencias_usuario ON referencias(usuario_id);

-- ---------------------------------------------------------------------
-- 3. La misma persona no se repite en un expediente
-- ---------------------------------------------------------------------
-- Antes la comprobación era por solicitud, de modo que la misma
-- referencia podía duplicarse entre trámites del mismo cliente.

ALTER TABLE referencias
    ADD UNIQUE KEY uq_referencia_persona (usuario_id, nombres, apellidos);

-- ---------------------------------------------------------------------
-- 4. Vista del expediente
-- ---------------------------------------------------------------------

CREATE OR REPLACE VIEW v_referencias_expediente AS
SELECT r.id,
       r.usuario_id,
       r.tipo,
       r.nombres,
       r.apellidos,
       CONCAT(r.nombres, ' ', r.apellidos) AS nombre_completo,
       r.empresa,
       r.profesion,
       r.telefono,
       r.celular,
       r.correo,
       r.direccion,
       r.parentesco,
       r.anios_conocer,
       r.verificada,
       r.verificada_en,
       r.notas,
       r.creado_en,
       CONCAT(v.nombres, ' ', v.apellidos) AS verificador,
       -- Dónde se capturó y en cuántos trámites se ha usado: lo que
       -- justifica conservarla.
       r.solicitud_id,
       r.credito_id,
       s.folio AS solicitud_folio,
       c.folio AS credito_folio
  FROM referencias r
  LEFT JOIN usuarios v           ON v.id = r.verificada_por
  LEFT JOIN solicitudes_credito s ON s.id = r.solicitud_id
  LEFT JOIN creditos c            ON c.id = r.credito_id;
