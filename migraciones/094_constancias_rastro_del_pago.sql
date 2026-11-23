-- =====================================================================
-- 094. El rastro del pago de la constancia
-- =====================================================================
--
-- La 093 dejó dicho CÓMO se pagó. Falta CON QUÉ.
--
-- Sin esto, una constancia pagada con tarjeta guarda `forma_pago =
-- 'tarjeta'` y nada más: no se sabe con cuál ni queda forma de atar el
-- cobro al consumo que aparece en el estado de cuenta. Si el cliente
-- reclama ese cargo —y un arancel de $7.00 que no se reconoce es
-- justo la clase de cargo que se reclama—, atención al cliente no
-- tiene con qué responderle.
--
-- `asiento_id` no sirve para eso. Un consumo con tarjeta de crédito no
-- genera asiento en el momento: la línea es del banco hasta que se
-- liquida, y lo que queda es el movimiento de la tarjeta. Por eso hace
-- falta guardar la referencia del cobro, sea de la tarjeta o de la
-- caja.
-- =====================================================================
USE cashmanha_v2;

ALTER TABLE constancias
  ADD COLUMN tarjeta_id INT UNSIGNED NULL
      COMMENT 'La tarjeta CashMan con la que se pago el arancel'
      AFTER solicitud_id,

  ADD COLUMN pago_referencia VARCHAR(40) NOT NULL DEFAULT ''
      COMMENT 'Referencia del cobro: el movimiento de la tarjeta o el de caja'
      AFTER tarjeta_id,

  ADD CONSTRAINT fk_constancia_tarjeta
      FOREIGN KEY (tarjeta_id) REFERENCES tarjetas(id),

  ADD CONSTRAINT fk_constancia_solicitud
      FOREIGN KEY (solicitud_id) REFERENCES constancia_solicitudes(id);

ALTER TABLE constancia_solicitudes
  ADD COLUMN caja_movimiento_id BIGINT UNSIGNED NULL
      COMMENT 'La anotacion en la gaveta del cajero que la cobro'
      AFTER caja_sesion_id;
