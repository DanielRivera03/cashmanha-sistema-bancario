-- ---------------------------------------------------------------------
-- La cuota pagada recuerda con qué asiento se pagó.
--
-- El cliente ve «Pagada» en su tabla de amortización y no tiene cómo
-- sacar el comprobante de ese pago: el asiento existe, pero nada en la
-- cuota apunta a él, así que había que ir a buscarlo a los movimientos
-- de la cuenta por fecha e importe. Con un cobro por tarjeta externa
-- ni eso, porque ese asiento no toca ninguna cuenta del cliente.
--
-- Se guarda el asiento en la propia cuota. Es el dato que convierte
-- «Pagada» en «Pagada, y aquí está la constancia».
-- ---------------------------------------------------------------------

ALTER TABLE cuotas
  ADD COLUMN asiento_id BIGINT UNSIGNED NULL DEFAULT NULL
      COMMENT 'Asiento con el que se pagó: de ahí sale el comprobante'
      AFTER pagada_en,
  ADD KEY idx_cuotas_asiento (asiento_id);

-- Lo que ya estaba pagado, enlazado hacia atrás sin adivinar.
--
-- La clave de idempotencia del asiento ya traía dentro el id de la
-- cuota —«cuota:274:9e53ea…»—, así que el emparejamiento es exacto y
-- no hay que recurrir a cuadrar fechas e importes, que es como se
-- enlazan dos pagos del mismo día al asiento equivocado.
UPDATE cuotas q
  JOIN asientos a
    ON a.tipo = 'pago_cuota'
   AND a.clave_idempotencia LIKE CONCAT('cuota:', q.id, ':%')
   SET q.asiento_id = a.id
 WHERE q.asiento_id IS NULL;

-- Las que se pagaron con tarjeta de otro banco. Ahí el asiento no
-- toca ninguna cuenta del cliente —el dinero vino de fuera—, y el
-- único hilo que las une es el cobro de la pasarela.
UPDATE cuotas q
  JOIN pagos_pasarela p
    ON p.cuota_id = q.id
   AND p.concepto = 'cuota_credito'
   AND p.asiento_id IS NOT NULL
   SET q.asiento_id = p.asiento_id
 WHERE q.asiento_id IS NULL
   AND q.estado = 'pagada';
