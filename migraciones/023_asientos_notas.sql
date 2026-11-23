-- ---------------------------------------------------------------------
-- 023 · Los asientos saben distinguir una nota
--
-- Las notas de abono y de cargo podrían registrarse como «ajuste», que
-- ya existía, pero entonces en los reportes serían indistinguibles de
-- cualquier otro ajuste. Y son justo lo contrario de indistinguibles:
-- una nota lleva folio, motivo y firma de quien la autorizó, y es de las
-- primeras cosas que mira una auditoría.
--
-- Darles su propio tipo permite contarlas, filtrarlas y cuadrarlas
-- aparte sin tener que cruzar con `notas_contables` en cada consulta.
-- ---------------------------------------------------------------------

ALTER TABLE asientos
  MODIFY COLUMN tipo ENUM(
      'transferencia',
      'deposito',
      'retiro',
      'pago_cuota',
      'consumo_tarjeta',
      'pago_tarjeta',
      'interes',
      'mora',
      'comision',
      'ajuste',
      'nota_abono',
      'nota_cargo',
      'reverso'
  ) NOT NULL;
