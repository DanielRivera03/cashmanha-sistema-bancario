-- =====================================================================
-- 037 · El cobro por enlace, registrado como los demás
-- =====================================================================
--
-- El enlace de pago creaba el intento en Stripe y nada más. Cuando el
-- cliente pagaba, el webhook buscaba el cobro en `pagos_pasarela`, no
-- encontraba nada y respondía «cobro sin intento registrado en este
-- sistema»: el dinero entraba en la pasarela y el arreglo se quedaba
-- sin aplicar, el crédito sin cancelar y el finiquito sin emitir.
--
-- Con el cobro registrado, el enlace entra por el mismo camino que el
-- resto: se asienta, se aplica y se avisa.
-- ---------------------------------------------------------------------

ALTER TABLE pagos_pasarela
    MODIFY COLUMN concepto ENUM(
        'cuota_credito','saldo_tarjeta','deposito','tienda','enlace_cobro'
    ) NOT NULL;

ALTER TABLE pagos_pasarela
    ADD COLUMN enlace_id BIGINT UNSIGNED NULL AFTER cuota_id,
    ADD KEY ix_pago_enlace (enlace_id);
