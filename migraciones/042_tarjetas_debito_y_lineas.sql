-- ---------------------------------------------------------------------
-- 042 · Lineas de tarjeta, American Express y tarjetas de debito
--
-- Tres cosas que faltaban:
--
--   AMEX        La columna `marca` ya admitia 'amex' y el generador de
--               numeros sabe hacer PAN de 15 digitos con BIN 37, pero
--               el emisor tenia una lista blanca de dos marcas y lo
--               rechazaba. Era una linea entera inaccesible.
--
--   DEBITO      `tipo` admite 'debito' desde el principio, pero no
--               habia producto, ni forma de pedirla, ni —lo peor— modo
--               de pagar con ella: el camino de consumo solo comprueba
--               el cupo cuando la tarjeta es de credito, y para las
--               demas no comprobaba NADA y sumaba igual al saldo. Una
--               tarjeta de debito habria autorizado cualquier importe
--               sin tocar la cuenta del cliente.
--
--   LA CUENTA   Una tarjeta de debito sin cuenta atada no es una
--               tarjeta de debito. El indice evita que se emita suelta.
-- ---------------------------------------------------------------------

-- La linea de debito, con su propio producto: asi tiene nombre, diseno
-- y condiciones propias en vez de heredar las de una de credito.
INSERT INTO productos
    (codigo, nombre, tipo, descripcion, tasa_interes_bp, nivel, activo, publico)
VALUES
    ('TAR-DEB', 'Tarjeta de Débito', 'tarjeta',
     'Tarjeta ligada a su cuenta de ahorro. Paga con su propio dinero: '
     || 'no hay linea de credito, ni intereses, ni fecha de corte.',
     0, 0, 1, 1)
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion), nivel = VALUES(nivel);

-- Una tarjeta de debito tiene que apuntar a una cuenta.
ALTER TABLE tarjetas
  ADD INDEX ix_tarjeta_cuenta (cuenta_id);

-- --- Parametros -------------------------------------------------------
INSERT INTO parametros (clave, valor, descripcion) VALUES
  ('tarjeta.debito_max_por_cuenta', '2',
   'Tarjetas de debito activas que se pueden emitir sobre una misma cuenta.'),
  ('tarjeta.marcas_disponibles', 'visa,mastercard,amex',
   'Marcas que la institucion emite, separadas por coma.')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- --- Permiso: el cliente pide su propia tarjeta de debito -------------
INSERT INTO permisos (clave, descripcion) VALUES
  ('tarjeta.debito_propia', 'Solicitar una tarjeta de débito para su propia cuenta')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'tarjeta.debito_propia'
 WHERE r.nombre IN ('Cliente','Administrador','Presidencia','Gerencia','Atención a Clientes','Cajero')
ON DUPLICATE KEY UPDATE rol_id = rol_id;
