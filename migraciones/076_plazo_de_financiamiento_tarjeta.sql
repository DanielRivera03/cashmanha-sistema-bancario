-- -----------------------------------------------------------------
-- 076. El plazo de financiamiento de la tarjeta, dicho en el estado
-- -----------------------------------------------------------------
--
-- QUÉ EXIGE LA LEY
--
-- La Ley del Sistema de Tarjetas de Crédito (Decreto 181) pide dos
-- cosas que el sistema no estaba diciendo:
--
--   · En el CONTRATO: «el plazo del contrato y del financiamiento».
--   · En el ESTADO DE CUENTA: «la fecha de vencimiento del pago, el
--     monto del pago mínimo estimado y el plazo al vencimiento».
--
-- El estado de cuenta decía el pago mínimo y la fecha límite, pero no
-- el plazo. Y sin el plazo, el pago mínimo es un número suelto: el
-- cliente no sabe si pagando eso termina en dos años o en doce.
--
-- QUÉ SE GUARDA
--
-- `plazo_financiamiento_meses` es el máximo en que la línea revolvente
-- debe quedar amortizada. No es un adorno informativo: el pago mínimo
-- se calcula para que el saldo se liquide dentro de ese plazo, de modo
-- que lo que el documento promete sea lo que la cuenta hace.
--
-- POR QUÉ 36 POR DEFECTO
--
-- Porque es el plazo con el que el pago mínimo del 5% que ya aplica
-- esta casa amortiza un saldo típico sin dejarlo eterno. El material
-- educativo de la propia Superintendencia advierte del caso contrario:
-- pagando solo el mínimo, mil dólares al 30% tardan unos 129 meses en
-- liquidarse. Un plazo declarado impide que eso ocurra en silencio.
--
-- Cada producto puede llevar el suyo; esto es el punto de partida.
-- -----------------------------------------------------------------

ALTER TABLE productos
  ADD COLUMN plazo_financiamiento_meses SMALLINT UNSIGNED NULL
      COMMENT 'Tarjetas: meses máximos para amortizar el saldo revolvente'
      AFTER plazo_maximo;

UPDATE productos
   SET plazo_financiamiento_meses = 36
 WHERE tipo = 'tarjeta' AND plazo_financiamiento_meses IS NULL;

-- Y en el corte queda constancia de con qué plazo se emitió, para que
-- un estado de cuenta de hace un año siga diciendo lo que decía
-- aunque el producto cambie de condiciones después.
ALTER TABLE tarjeta_cortes
  ADD COLUMN plazo_financiamiento_meses SMALLINT UNSIGNED NULL
      COMMENT 'Plazo declarado en este estado de cuenta'
      AFTER pago_contado_cent,
  ADD COLUMN meses_si_paga_minimo SMALLINT UNSIGNED NULL
      COMMENT 'Cuánto tardaría en liquidar pagando solo el mínimo'
      AFTER plazo_financiamiento_meses;
