-- =====================================================================
-- 034 · Tipos de asiento que faltaban
-- =====================================================================
--
-- `asientos.tipo` es un ENUM y MySQL, por omisión, trunca en silencio
-- lo que no está en la lista: el asiento se guardaba con el tipo vacío
-- en lugar de fallar. Un asiento sin tipo no se puede clasificar
-- después en ningún reporte.
--
-- Hacen falta tres entradas nuevas:
--
--   arreglo_pago    lo que se cobra al aplicar un arreglo, junto con la
--                   parte que se condona.
--   condonacion     una quita suelta, sin arreglo de por medio.
--   compensacion    el barrido automático que toma saldo de la cuenta
--                   del cliente para cubrir su mora.
-- ---------------------------------------------------------------------

ALTER TABLE asientos
    MODIFY COLUMN tipo ENUM(
        'transferencia','deposito','retiro','pago_cuota','consumo_tarjeta',
        'pago_tarjeta','interes','mora','comision','ajuste',
        'nota_abono','nota_cargo','reverso',
        'arreglo_pago','condonacion','compensacion'
    ) NOT NULL;
