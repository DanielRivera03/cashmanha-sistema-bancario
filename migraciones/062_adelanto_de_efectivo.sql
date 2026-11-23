-- -----------------------------------------------------------------
-- Adelanto de efectivo y los cargos que conlleva.
--
-- Disponer de la línea en dinero contante es de lo que hace una
-- tarjeta de crédito desde siempre, y aquí faltaba: solo se podía
-- comprar con ella.
--
-- No es una compra. En una compra el comercio le paga al banco; en un
-- adelanto el banco entrega dinero y no cobra a nadie más, así que lo
-- cobra al cliente: comisión sobre lo dispuesto con un mínimo, y un
-- gasto administrativo fijo. El traslado de saldo lleva los suyos, más
-- bajos porque al banco le interesa traer deuda de otra tarjeta.
--
-- Las tarifas son parámetros, no números escritos en el código: una
-- comisión la decide el negocio y cambia sin tocar nada.
-- -----------------------------------------------------------------

INSERT INTO permisos (clave, descripcion) VALUES
  ('tarjeta.adelanto', 'Adelantar efectivo de una tarjeta a una cuenta')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- Quien ya podía trasladar saldo puede adelantar: es la misma decisión
-- sobre su propia línea.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT rp.rol_id, (SELECT id FROM permisos WHERE clave = 'tarjeta.adelanto')
  FROM rol_permiso rp
  JOIN permisos p ON p.id = rp.permiso_id
 WHERE p.clave = 'tarjeta.trasladar'
ON DUPLICATE KEY UPDATE rol_id = rol_permiso.rol_id;

INSERT INTO parametros (clave, valor, descripcion) VALUES
  ('tarjeta.adelanto_comision_bp',          '500',
   'Comisión del adelanto de efectivo, en puntos base (500 = 5%)'),
  ('tarjeta.adelanto_comision_minima_cent', '500',
   'Comisión mínima del adelanto, en centavos'),
  ('tarjeta.adelanto_gasto_admin_cent',     '300',
   'Gasto administrativo fijo por adelanto, en centavos'),
  ('tarjeta.traslado_comision_bp',          '300',
   'Comisión del traslado de saldo, en puntos base (300 = 3%)'),
  ('tarjeta.traslado_comision_minima_cent', '300',
   'Comisión mínima del traslado, en centavos'),
  ('tarjeta.traslado_gasto_admin_cent',     '200',
   'Gasto administrativo fijo por traslado, en centavos')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
