-- =====================================================================
-- 010 — Caducidad de sesión por rol
-- ---------------------------------------------------------------------
-- Hasta ahora la caducidad era un único valor para todos:
-- `SESION_INACTIVIDAD_SEG=600` en .env, diez minutos para cualquiera.
--
-- Eso trata igual dos situaciones que no lo son:
--
--   * Un cliente entra desde su casa o desde un equipo prestado, hace
--     una transferencia y se va. Cada minuto que su sesión siga abierta
--     es una ventana para quien se siente después en esa máquina. La
--     banca en línea real cierra entre 5 y 15 minutos.
--
--   * Un cajero o un gerente pasa la jornada dentro del sistema, en una
--     máquina de la institución, atendiendo a una persona delante.
--     Expulsarlo cada diez minutos no añade seguridad: lo que consigue
--     es que deje una ventana abierta con la sesión viva para no tener
--     que volver a entrar, o que apunte la contraseña.
--
-- El tiempo pasa a ser una propiedad del rol, configurable desde la
-- pantalla de roles, igual que los permisos.
-- =====================================================================

ALTER TABLE roles
    ADD COLUMN inactividad_seg MEDIUMINT UNSIGNED NOT NULL DEFAULT 600
        COMMENT 'Segundos de inactividad antes de cerrar la sesión'
        AFTER nivel,
    ADD COLUMN sesion_maxima_seg MEDIUMINT UNSIGNED NOT NULL DEFAULT 28800
        COMMENT 'Duración máxima absoluta de la sesión, se haya usado o no'
        AFTER inactividad_seg,
    ADD COLUMN aviso_previo_seg SMALLINT UNSIGNED NOT NULL DEFAULT 60
        COMMENT 'Antelación del aviso antes de cerrar por inactividad'
        AFTER sesion_maxima_seg;

-- ---------------------------------------------------------------------
-- Valores iniciales
-- ---------------------------------------------------------------------
-- Personal interno: jornada completa dentro del sistema, en equipos de
-- la institución y con custodia física del puesto.

UPDATE roles SET inactividad_seg = 2700,  -- 45 minutos
                 sesion_maxima_seg = 43200, -- 12 horas
                 aviso_previo_seg = 120
 WHERE clave IN ('administrador', 'presidencia', 'gerencia');

-- Atención a clientes maneja datos de terceros en un mostrador: algo
-- más corto que el resto del personal.
UPDATE roles SET inactividad_seg = 1800,  -- 30 minutos
                 sesion_maxima_seg = 36000, -- 10 horas
                 aviso_previo_seg = 120
 WHERE clave = 'atencionclientes';

-- Cliente: equipo propio o ajeno, sesión corta. Ocho minutos está
-- dentro de lo que usa la banca en línea, con dos minutos de aviso
-- para que pueda continuar si sigue delante.
UPDATE roles SET inactividad_seg = 480,   -- 8 minutos
                 sesion_maxima_seg = 7200,  -- 2 horas
                 aviso_previo_seg = 120
 WHERE clave = 'cliente';

-- ---------------------------------------------------------------------
-- Motivo de cierre
-- ---------------------------------------------------------------------
-- `motivo_revocacion` es texto libre; se deja constancia de los valores
-- que usa el sistema para que la bitácora sea legible.

ALTER TABLE sesiones
    MODIFY COLUMN motivo_revocacion VARCHAR(60) NULL
        COMMENT 'cierre_por_usuario, inactividad, caducidad_absoluta, cambio_de_rol, cambio_de_permisos, restablecimiento_password';
