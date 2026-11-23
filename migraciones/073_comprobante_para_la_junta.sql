-- -----------------------------------------------------------------
-- La Junta puede abrir el comprobante de una operación.
--
-- EL PROBLEMA
--
-- El reporte de transacciones —que la Junta sí ve, con `reporte.ver`—
-- lista cada movimiento con su referencia, su importe, el NÚMERO DE
-- CUENTA y el NOMBRE DEL TITULAR, y ofrece el enlace al comprobante.
-- Pero el comprobante exigía ser dueño de la operación o tener
-- `cuenta.ver_todas`, así que al pulsarlo salía «Operación no
-- encontrada».
--
-- Un enlace que se ofrece y no funciona es peor que no ofrecerlo: el
-- director concluye que el sistema está roto, y no se equivoca.
--
-- POR QUÉ NO SE RESUELVE DANDO `cuenta.ver_todas`
--
-- Porque ese permiso abre MUCHO más: el listado de todas las cuentas
-- de todos los clientes, sus saldos y sus movimientos, pantalla por
-- pantalla. La Junta no necesita eso para gobernar, y dárselo por
-- arreglar un enlace sería agrandar un acceso por la puerta de atrás.
--
-- POR QUÉ SÍ ES CORRECTO DARLE EL COMPROBANTE
--
-- Porque no añade ni un dato que no tenga ya delante. El comprobante
-- es la misma información del reporte —referencia, importe, cuenta,
-- titular— puesta en PDF. Negárselo no protegía nada: solo rompía el
-- enlace.
--
-- Y porque poder verificar una operación concreta que llama la
-- atención en un reporte es justo lo que hace un consejo. Va con
-- `auditoria.ver`, que ya tenía.
--
-- Cada emisión queda en la auditoría, como todas.
-- -----------------------------------------------------------------

INSERT INTO permisos (clave, descripcion) VALUES
  ('comprobante.ver_todos',
   'Abrir el comprobante de cualquier operacion, sin ver el resto del expediente')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- A la Junta.
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'comprobante.ver_todos'
 WHERE r.clave = 'junta';

-- Y a quien ya podía por la vía de `cuenta.ver_todas`, para que el
-- permiso nuevo describa lo que ya ocurría y no cambie el acceso de
-- nadie al introducirlo.
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT rp.rol_id, p2.id
  FROM rol_permiso rp
  JOIN permisos p1 ON p1.id = rp.permiso_id AND p1.clave = 'cuenta.ver_todas'
  JOIN permisos p2 ON p2.clave = 'comprobante.ver_todos';
