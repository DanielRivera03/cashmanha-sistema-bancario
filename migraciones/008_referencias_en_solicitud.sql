-- =====================================================================
-- 008 — Referencias asociadas a la solicitud, no solo al crédito
-- ---------------------------------------------------------------------
-- `referencias.credito_id` estaba como NOT NULL, de modo que una
-- referencia solo podía registrarse una vez el crédito existía, es
-- decir, después de aprobarlo.
--
-- Eso invierte el orden real: las referencias personales y laborales
-- son parte de lo que se evalúa PARA decidir si se aprueba. El sistema
-- anterior las pedía durante la captura de la solicitud
-- (`gestor-referencias-personales-clientes`), y tenía razón.
--
-- Con este cambio una referencia pertenece a una solicitud, a un
-- crédito, o a ambos —cuando la solicitud se aprueba y genera crédito,
-- las referencias se arrastran sin tener que volver a pedirlas.
-- =====================================================================

-- La clave foránea impide cambiar la columna: se suelta primero.
ALTER TABLE referencias
    DROP FOREIGN KEY IF EXISTS fk_referencias_credito;

ALTER TABLE referencias
    MODIFY COLUMN credito_id INT(10) UNSIGNED NULL;

ALTER TABLE referencias
    ADD COLUMN solicitud_id INT(10) UNSIGNED NULL AFTER credito_id;

-- Datos de contacto que el sistema anterior pedía y aquí faltaban.
ALTER TABLE referencias
    ADD COLUMN celular   VARCHAR(20)  NULL AFTER telefono,
    ADD COLUMN correo    VARCHAR(190) NULL AFTER celular,
    ADD COLUMN direccion VARCHAR(255) NULL AFTER correo,
    ADD COLUMN anios_conocer TINYINT UNSIGNED NULL AFTER parentesco,
    ADD COLUMN verificada_por INT(10) UNSIGNED NULL AFTER verificada,
    ADD COLUMN verificada_en  DATETIME NULL AFTER verificada_por,
    ADD COLUMN notas VARCHAR(500) NULL AFTER verificada_en,
    ADD COLUMN creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- Una referencia tiene que colgar de algo: de una solicitud o de un
-- crédito. Si no, queda huérfana y nadie la vuelve a encontrar.
ALTER TABLE referencias
    ADD CONSTRAINT chk_referencia_origen
        CHECK (credito_id IS NOT NULL OR solicitud_id IS NOT NULL);

ALTER TABLE referencias
    ADD CONSTRAINT fk_referencias_credito
        FOREIGN KEY (credito_id) REFERENCES creditos(id) ON DELETE CASCADE;

ALTER TABLE referencias
    ADD CONSTRAINT fk_referencias_solicitud
        FOREIGN KEY (solicitud_id) REFERENCES solicitudes_credito(id) ON DELETE CASCADE;

ALTER TABLE referencias
    ADD CONSTRAINT fk_referencias_verificador
        FOREIGN KEY (verificada_por) REFERENCES usuarios(id) ON DELETE SET NULL;

CREATE INDEX idx_referencias_solicitud ON referencias(solicitud_id);

-- =====================================================================
-- Permisos para gestionarlas
-- =====================================================================

INSERT IGNORE INTO permisos (clave, descripcion) VALUES
    ('referencia.registrar', 'Registrar referencias de una solicitud'),
    ('referencia.verificar', 'Verificar referencias por contacto directo');

-- Quien captura la solicitud registra las referencias; quien las
-- verifica es el área que llama por teléfono para comprobarlas.
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  CROSS JOIN permisos p
 WHERE p.clave = 'referencia.registrar'
   AND r.clave IN ('administrador', 'presidencia', 'gerencia', 'atencionclientes', 'cliente');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  CROSS JOIN permisos p
 WHERE p.clave = 'referencia.verificar'
   AND r.clave IN ('administrador', 'presidencia', 'gerencia', 'atencionclientes');
