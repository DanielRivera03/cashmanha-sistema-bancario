-- -----------------------------------------------------------------
-- 075. La inhabilitación por atraso, y sus excepciones
-- -----------------------------------------------------------------
--
-- QUÉ RESUELVE
--
-- Un cliente que se atrasó no vuelve a optar a productos nuevos al
-- día siguiente de ponerse al día. La ley de historial de crédito
-- permite que el dato negativo pese un tiempo —hasta tres años, menos
-- si canceló la deuda entera— y el banco se da ese mismo tiempo antes
-- de volver a prestarle.
--
-- El período NO se guarda: se calcula del historial, que es la única
-- fuente que no se puede desincronizar. Guardarlo obligaría a
-- recalcular una fila cada vez que entra un pago, y el día que ese
-- recálculo fallara —como falló el cierre de expedientes durante
-- meses— la tabla diría una cosa y los hechos otra.
--
-- LO QUE SÍ SE GUARDA
--
-- Las excepciones. Un banco puede decidir atender a un cliente
-- inhabilitado: el que acaba de liquidar trescientos mil dólares de
-- una vez es justo con quien se quiere volver a hablar. Pero esa
-- decisión es de una persona, lleva su nombre, su motivo y su fecha,
-- y caduca. No es un interruptor que se queda encendido.
--
-- POR QUÉ IMPORTA QUE QUEDE ESCRITO
--
-- Porque la pregunta «¿por qué se le prestó a este cliente que estaba
-- inhabilitado?» tiene que poder responderse dentro de dos años, con
-- un nombre y un motivo, y no con un encogimiento de hombros.
-- -----------------------------------------------------------------

CREATE TABLE IF NOT EXISTS elegibilidad_excepciones (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid           CHAR(36) NOT NULL,
  usuario_id     INT UNSIGNED NOT NULL,

  -- A qué le abre la puerta. 'todo' vale para cualquier producto.
  alcance        ENUM('todo','credito','tarjeta','extrafinanciamiento')
                 NOT NULL DEFAULT 'todo',

  -- Por qué se decide. Es obligatorio y es lo que se lee después.
  motivo         VARCHAR(500) NOT NULL,

  -- Quién la autoriza. Sin esto la excepción no vale nada.
  autorizada_por INT UNSIGNED NOT NULL,

  -- Hasta cuándo. Una excepción sin fecha de fin deja de ser una
  -- excepción y pasa a ser una regla distinta para una persona.
  vigente_desde  DATE NOT NULL,
  vigente_hasta  DATE NOT NULL,

  revocada_en    DATETIME NULL,
  revocada_por   INT UNSIGNED NULL,
  revocacion_motivo VARCHAR(400) NULL,

  creada_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_exc_uuid (uuid),
  KEY ix_exc_usuario (usuario_id, vigente_hasta),

  CONSTRAINT fk_exc_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE CASCADE,
  CONSTRAINT fk_exc_autoriza FOREIGN KEY (autorizada_por)
    REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------
-- El permiso para autorizarlas
-- -----------------------------------------------------------------
--
-- Levantar una inhabilitación es decidir prestarle a alguien a quien
-- las reglas de la casa le dicen que no. No puede poder hacerlo
-- cualquiera que atienda una ventanilla.

INSERT INTO permisos (clave, descripcion) VALUES
  ('elegibilidad.excepcionar',
   'Autorizar que un cliente inhabilitado por atraso opte a productos nuevos')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- A quien ya decide sobre crédito: presidencia, gerencia y la
-- administración. A atención al cliente y a caja, no.
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'elegibilidad.excepcionar'
 WHERE r.clave IN ('administrador', 'presidencia', 'gerencia');
