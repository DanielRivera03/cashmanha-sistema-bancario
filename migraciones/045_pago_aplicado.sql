-- ---------------------------------------------------------------------
-- 045 · Cobrado y aplicado no son lo mismo
--
-- `pagos_pasarela.estado` solo llegaba hasta 'cobrado', que significa
-- que la procesadora tomo el dinero. Lo que NO decia es si ese cobro
-- llego a asentarse en nuestros libros.
--
-- La diferencia no es teorica: dos pagos de tarjeta figuraban como
-- 'cobrado' habiendose acreditado a la cuenta de ahorro en vez de
-- bajar la deuda de la tarjeta. Sin un estado que distinga «el dinero
-- entro» de «el dinero se aplico donde debia», un cobro mal aplicado
-- es indistinguible de uno correcto.
-- ---------------------------------------------------------------------

ALTER TABLE pagos_pasarela
  MODIFY COLUMN estado
    ENUM('iniciado','procesando','cobrado','aplicado','fallido','cancelado','devuelto')
    NOT NULL DEFAULT 'iniciado';
