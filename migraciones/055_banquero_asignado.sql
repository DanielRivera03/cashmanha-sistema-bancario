-- ---------------------------------------------------------------------
-- El banquero asignado, y a quién puede escribir un cliente.
--
-- La pantalla de banca preferente le dice al cliente patrimonial:
-- «Tiene una persona asignada que lleva su relación con la institución.
-- Escríbale desde mensajería y le responde directamente, sin pasar por
-- la cola general». Era una frase: no había ninguna persona asignada,
-- ni columna donde anotarla, y el botón llevaba a la mensajería
-- general.
--
-- Y la mensajería general tenía su propio problema: al cliente se le
-- ofrecía como destinatario CUALQUIER empleado activo. Con dos
-- usuarios de personal pasaba desapercibido; al poblar la red con diez
-- agencias y veintisiete cajeros, el desplegable de «Redactar mensaje»
-- se convirtió en el directorio completo de la institución, con nombre
-- y apellidos, a disposición de cualquier cliente.
--
-- Un cliente se dirige a un departamento, no a una persona que ha
-- elegido de una lista. La excepción es su banquero, que sí es una
-- persona concreta y precisamente por eso se le asigna.
-- ---------------------------------------------------------------------

-- --- El rol ------------------------------------------------------------

INSERT INTO roles (clave, nombre, descripcion, nivel, inactividad_seg, sesion_maxima_seg, aviso_previo_seg)
SELECT 'banquero', 'Banquero',
       'Banca privada: lleva la relación de una cartera de clientes asignados',
       -- Mismo nivel que Atención a Clientes: ve expedientes, no administra.
       (SELECT nivel FROM (SELECT nivel FROM roles WHERE clave = 'atencionclientes') x),
       (SELECT inactividad_seg FROM (SELECT inactividad_seg FROM roles WHERE clave = 'atencionclientes') x),
       (SELECT sesion_maxima_seg FROM (SELECT sesion_maxima_seg FROM roles WHERE clave = 'atencionclientes') x),
       (SELECT aviso_previo_seg FROM (SELECT aviso_previo_seg FROM roles WHERE clave = 'atencionclientes') x)
 WHERE NOT EXISTS (SELECT 1 FROM roles r WHERE r.clave = 'banquero');

-- Los permisos del banquero. Ve el expediente de SUS clientes, les
-- escribe y gestiona sus tickets; no toca caja, ni contabilidad, ni
-- parámetros. La restricción a «sus» clientes la aplica la aplicación,
-- que es donde se sabe quién tiene asignado a quién.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave IN (
        'mensaje.ver', 'mensaje.enviar',
        'ticket.ver', 'ticket.gestionar',
        'cliente.ver_expediente',
        'cuenta.ver_todas', 'tarjeta.ver_todas', 'credito.ver_todos',
        'tarjeta.ver_solicitudes'
       )
 WHERE r.nombre = 'Banquero'
   AND NOT EXISTS (
         SELECT 1 FROM rol_permiso rp
          WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
       );

-- --- La asignación -----------------------------------------------------

ALTER TABLE usuarios
  ADD COLUMN banquero_id INT UNSIGNED NULL DEFAULT NULL
      COMMENT 'El empleado que lleva la relación de este cliente'
      AFTER segmento_desde,
  ADD COLUMN banquero_desde DATETIME NULL DEFAULT NULL
      AFTER banquero_id;

ALTER TABLE usuarios
  ADD CONSTRAINT fk_usuarios_banquero
      FOREIGN KEY (banquero_id) REFERENCES usuarios (id)
      ON DELETE SET NULL ON UPDATE CASCADE;

CREATE INDEX ix_usuarios_banquero ON usuarios (banquero_id);

-- --- Las gestiones ------------------------------------------------------
--
-- Un ticket abierto por un cliente de cartera lo atiende su banquero y
-- no la cola general. Se marca en el propio ticket para que el reparto
-- quede por escrito: saber QUIÉN debía atender algo es la mitad de
-- poder revisar después por qué no se atendió.

ALTER TABLE tickets
  ADD COLUMN via ENUM('general','banquero','prioritaria') NOT NULL DEFAULT 'general'
      COMMENT 'Por dónde entró: cola general, banquero asignado o atención prioritaria'
      AFTER prioridad;

CREATE INDEX ix_tickets_via ON tickets (via, estado);
