-- =====================================================================
-- 007 — roles.id con auto-incremento
-- ---------------------------------------------------------------------
-- La tabla `roles` se creó sin AUTO_INCREMENT porque los cinco roles
-- iniciales se insertaron con su id escrito a mano (1 a 5). Mientras no
-- hubo pantalla para crear roles no se notó; en cuanto la hay, cualquier
-- alta falla con:
--
--     General error: 1364 Field 'id' doesn't have a default value
--
-- Las demás tablas de catálogo (permisos, productos, limites_operacion)
-- sí lo tienen, así que esto era la excepción, no el criterio.
--
-- tinyint unsigned llega a 255 roles, de sobra para una institución.
-- =====================================================================

ALTER TABLE roles
    MODIFY COLUMN id TINYINT(3) UNSIGNED NOT NULL AUTO_INCREMENT;

-- El contador arranca después del último id ocupado, para que el
-- próximo rol no choque con los existentes.
ALTER TABLE roles AUTO_INCREMENT = 10;
