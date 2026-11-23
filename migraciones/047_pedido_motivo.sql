-- ---------------------------------------------------------------------
-- Un pedido que no prosperó dice por qué.
--
-- «Anulado» a secas no le explica nada a nadie: el cliente no sabe si
-- le rechazaron la tarjeta, si se le pasó el tiempo o si alguien de la
-- institución lo canceló. Y «pago_pendiente» para siempre es peor,
-- porque además mantiene apartadas las existencias de un pedido que
-- nunca se va a cobrar.
-- ---------------------------------------------------------------------

ALTER TABLE tienda_pedidos
  ADD COLUMN cerrado_motivo VARCHAR(240) NULL DEFAULT NULL
      COMMENT 'Por qué se anuló: rechazo, caducidad o decisión interna'
      AFTER estado,
  ADD COLUMN cerrado_en DATETIME NULL DEFAULT NULL
      AFTER cerrado_motivo;

-- Lo que ya estaba anulado por un cobro rechazado, explicado.
UPDATE tienda_pedidos p
  JOIN pagos_pasarela g ON g.pedido_id = p.id
   SET p.cerrado_motivo = COALESCE(NULLIF(g.detalle_error, ''),
                                   'El cobro no se completó.'),
       p.cerrado_en = COALESCE(p.cerrado_en, p.actualizado_en)
 WHERE p.estado = 'anulado'
   AND p.cerrado_motivo IS NULL
   AND g.estado IN ('fallido', 'cancelado');
