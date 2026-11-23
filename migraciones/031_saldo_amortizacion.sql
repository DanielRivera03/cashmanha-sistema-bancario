-- =====================================================================
-- 031 · El saldo de la tabla de amortización
-- =====================================================================
--
-- En el contrato de CR-000011 la columna «Saldo» del plan de pagos
-- salía en $0.00 en las 180 filas. No era un fallo de impresión: la
-- columna `cuotas.saldo_posterior_cent` estaba en cero en 271 de las
-- 294 cuotas de la base.
--
-- El código que crea un crédito hoy sí la llena. Los que estaban en
-- cero son los que vinieron migrados del sistema anterior, donde esa
-- columna no existía: se insertaron con el valor por omisión y nadie
-- volvió a tocarlos.
--
-- El dato es reconstruible sin ambigüedad. En un plan francés, el saldo
-- después de la cuota N es el monto otorgado menos todo el capital
-- amortizado hasta esa cuota, que es justo lo que calcula la subconsulta.
-- La última cuota da cero porque el capital suma el monto completo, y
-- eso es correcto: después de pagarla no se debe nada.
--
-- Se usa una tabla derivada porque MySQL no permite leer de la misma
-- tabla que se está actualizando en una subconsulta directa.
-- ---------------------------------------------------------------------

UPDATE cuotas q
  JOIN (
    SELECT q2.id,
           GREATEST(0, c.monto_cent - (
               SELECT COALESCE(SUM(q3.capital_cent), 0)
                 FROM cuotas q3
                WHERE q3.credito_id = q2.credito_id
                  AND q3.numero    <= q2.numero
           )) AS saldo
      FROM cuotas   q2
      JOIN creditos c ON c.id = q2.credito_id
  ) calc ON calc.id = q.id
   SET q.saldo_posterior_cent = calc.saldo
 WHERE q.saldo_posterior_cent = 0
   AND calc.saldo             > 0;
