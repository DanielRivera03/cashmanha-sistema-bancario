-- -----------------------------------------------------------------
-- La contrapartida del dinero que entra por una ventanilla.
--
-- Hasta aquí una cuota solo se podía cobrar debitando la cuenta del
-- cliente. En la pantalla se leía «solo con el consentimiento del
-- cliente y el efectivo ya recibido en ventanilla», que describe el
-- apaño: el cajero tomaba los billetes y después debitaba la cuenta,
-- de modo que en el estado de cuenta del cliente aparecía un cargo que
-- él no hizo y el efectivo no quedaba registrado en ninguna parte.
--
-- Un pago en efectivo no toca la cuenta de ahorro. El dinero entra a
-- la caja y liquida la cuota, y eso son dos asientos entre cuentas del
-- banco, no un débito al cliente.
--
-- Esta cuenta es esa contrapartida. Lleva el mismo signo que la cuenta
-- técnica cuando el banco recibe dinero de fuera —se vuelve negativa—,
-- que es la convención que ya usan el depósito y el cobro por
-- pasarela. Tenerla aparte permite distinguir en el libro lo cobrado
-- por ventanilla de lo cobrado de las cuentas.
-- -----------------------------------------------------------------

INSERT INTO cuentas
    (uuid, numero_cuenta, usuario_id, producto_id, moneda, saldo_cent,
     saldo_retenido_cent, estado, es_tecnica, apertura_en)
SELECT UUID(), '0000000005', c.usuario_id, c.producto_id, 'USD', 0,
       0, 'activa', 1, NOW()
  FROM cuentas c
 WHERE c.es_tecnica = 1
 ORDER BY c.id
 LIMIT 1;

INSERT INTO parametros (clave, valor, descripcion) VALUES
  ('caja.cuenta_efectivo', '0000000005',
   'Cuenta técnica que hace de contrapartida del efectivo recibido en ventanilla')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
