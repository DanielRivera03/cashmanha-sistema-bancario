-- -----------------------------------------------------------------
-- Política de contraseñas y perfil declarado del cliente.
--
-- 1. HISTORIAL DE CONTRASEÑAS
--
--    Obligar a cambiar la contraseña cada dos meses no sirve de nada
--    si se puede volver a poner la misma de siempre: el que no quiere
--    cambiarla alterna entre dos y la política queda de adorno. Con
--    el historial, una clave ya usada se rechaza.
--
--    Se guarda el HASH, nunca la contraseña. Para saber si una nueva
--    coincide con una vieja se verifica contra cada hash guardado;
--    no hace falta —ni se puede— leer las anteriores.
--
--    Se conservan las últimas; `limpiarHistorial()` borra el resto.
--    Guardarlas todas para siempre es acumular material sensible que
--    ya no cumple ninguna función.
--
-- 2. CADUCIDAD
--
--    `password_actualizado_en` ya existía pero nadie la miraba. Con
--    el parámetro `password.dias_vigencia` el sistema calcula si ya
--    venció y exige el cambio antes de dejar entrar a ninguna otra
--    pantalla.
--
-- 3. EL PERFIL DECLARADO
--
--    Un banco no puede dar productos a quien no ha declarado de qué
--    vive. `detalle_usuarios` ya tenía `empresa`, `cargo` e
--    `ingreso_mensual_cent`, pero nada obligaba a llenarlos y nada
--    los exigía antes de prestar.
--
--    Se agrega de dónde viene el ingreso y desde cuándo, y la marca
--    de cuándo lo declaró. Esa marca es la que cierra el formulario:
--    se declara UNA vez al entrar, y a partir de ahí los cambios se
--    hacen en agencia, con el cliente presente y su documento sobre
--    el mostrador.
-- -----------------------------------------------------------------

CREATE TABLE IF NOT EXISTS password_historial (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id    INT UNSIGNED NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    algoritmo     VARCHAR(20) NOT NULL DEFAULT 'argon2id',
    creado_en     DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_hist_usuario (usuario_id, id),
    CONSTRAINT fk_hist_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Hashes de contrasenas anteriores, para no permitir reutilizarlas';

-- La contraseña con la que cada quien entra hoy cuenta como usada.
-- Sin esto, el primer cambio podría volver a poner la actual.
INSERT INTO password_historial (usuario_id, password_hash, algoritmo, creado_en)
SELECT id, password_hash, COALESCE(password_algoritmo, 'argon2id'),
       COALESCE(password_actualizado_en, NOW())
  FROM usuarios
 WHERE password_hash IS NOT NULL AND password_hash <> '';

ALTER TABLE detalle_usuarios
    ADD COLUMN fuente_ingreso ENUM('empleo','negocio_propio','honorarios',
                                   'remesas','pension','rentas','otro')
        NULL DEFAULT NULL COMMENT 'De donde viene el ingreso declarado'
        AFTER ingreso_mensual_cent,
    ADD COLUMN ocupacion VARCHAR(120) NULL DEFAULT NULL
        COMMENT 'A que se dedica, en sus palabras' AFTER fuente_ingreso,
    ADD COLUMN antiguedad_meses SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Cuanto lleva en esa actividad' AFTER ocupacion,
    ADD COLUMN perfil_declarado_en DATETIME NULL DEFAULT NULL
        COMMENT 'Cuando el cliente declaro sus datos en linea. Despues, solo en agencia'
        AFTER antiguedad_meses;

INSERT INTO parametros (clave, valor, descripcion, actualizado_en) VALUES
  ('password.dias_vigencia', '60',
   'Dias que vale una contrasena antes de exigir el cambio. 0 la deja sin vencimiento.', NOW()),
  ('password.historial', '12',
   'Cuantas contrasenas anteriores se recuerdan para no dejar repetirlas.', NOW()),
  ('registro.exigir_cambio_primer_ingreso', '1',
   'Exigir cambio de contrasena la primera vez que entra un usuario nuevo.', NOW()),
  ('registro.exigir_perfil', '1',
   'Pedir ocupacion e ingresos la primera vez que entra un cliente.', NOW())
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
