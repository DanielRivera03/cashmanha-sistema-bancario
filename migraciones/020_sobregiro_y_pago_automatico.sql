-- ---------------------------------------------------------------------
-- 020 · Sobregiro de cupo y pago automático de tarjeta
--
-- Sale el «límite diario de consumo» y entran las opciones que de verdad
-- usa alguien con una tarjeta de crédito.
--
-- El límite diario no tenía sentido aquí: la tarjeta ya tiene un cupo
-- que no se puede pasar, y un tope por día no protege de nada que el
-- cupo no proteja ya. Lo que sí hace falta es decidir qué pasa cuando el
-- consumo toca el cupo, y no tener que acordarse de pagar cada mes.
--
--   · Sobregiro de cupo: permitir o no pasarse del límite, con una
--     tolerancia y su comisión. Es lo que en la práctica decide si una
--     compra en el supermercado se rechaza por $3 o pasa.
--
--   · Pago automático: domiciliar el pago del mínimo o del total a una
--     cuenta, para no depender de la memoria del cliente. La mora se
--     evita sola, que es mucho mejor que cobrarla.
--
-- `limite_diario_cent` NO se borra: hay tarjetas con un valor guardado y
-- borrar una columna es irreversible. Se deja de usar y de mostrar.
-- ---------------------------------------------------------------------

ALTER TABLE tarjetas
  ADD COLUMN sobregiro_permitido TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'Si se admite consumir por encima del cupo'
      AFTER limite_diario_cent,
  ADD COLUMN sobregiro_tolerancia_bp INT UNSIGNED NOT NULL DEFAULT 0
      COMMENT 'Cuanto se admite pasarse, en puntos base del limite (1000 = 10%)'
      AFTER sobregiro_permitido,
  ADD COLUMN pago_automatico ENUM('no','minimo','total') NOT NULL DEFAULT 'no'
      COMMENT 'Domiciliacion del pago al vencimiento'
      AFTER sobregiro_tolerancia_bp,
  ADD COLUMN pago_automatico_cuenta_id INT UNSIGNED NULL
      COMMENT 'Cuenta de la que se debita el pago automatico'
      AFTER pago_automatico,
  ADD COLUMN alerta_consumo_cent BIGINT NOT NULL DEFAULT 0
      COMMENT 'Avisar al titular cuando una compra supere este monto (0 = no avisar)'
      AFTER pago_automatico_cuenta_id;

ALTER TABLE tarjetas
  ADD CONSTRAINT fk_tarjetas_pago_auto_cuenta
      FOREIGN KEY (pago_automatico_cuenta_id) REFERENCES cuentas (id)
      ON DELETE SET NULL;

-- Comisión por sobregiro: un parámetro, no un número escondido en el
-- código. Si mañana cambia, cambia en un sitio y queda el rastro de
-- quién lo cambió.
INSERT INTO parametros (clave, valor, descripcion)
VALUES
  ('tarjeta.sobregiro_comision_cent', '500',
   'Comision fija que se cobra cuando un consumo excede el cupo'),
  ('tarjeta.sobregiro_tolerancia_max_bp', '1500',
   'Tolerancia maxima de sobregiro configurable (puntos base del limite)'),
  ('tarjeta.dia_corte', '25',
   'Dia de corte con el que nacen las tarjetas nuevas')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
