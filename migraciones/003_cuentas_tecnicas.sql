-- =====================================================================
-- Distingue cuentas de cliente de cuentas internas del banco.
--
-- Una cuenta de cliente NUNCA puede quedar en negativo: el CHECK es la
-- última red contra el sobregiro.
--
-- Una cuenta técnica (contrapartida, caja, patrimonio) tiene por
-- naturaleza saldo acreedor: es el pasivo del banco frente a sus
-- clientes. Si los depósitos suman $96,300, la contrapartida vale
-- -$96,300. Forzarla a cero rompería la partida doble.
-- =====================================================================
USE cashmanha_v2;

ALTER TABLE cuentas
  ADD COLUMN es_tecnica TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'Cuenta interna del banco, no de cliente'
      AFTER estado;

-- Sustituir el CHECK para que solo rija sobre cuentas de cliente.
ALTER TABLE cuentas DROP CONSTRAINT chk_saldo_no_negativo;
ALTER TABLE cuentas
  ADD CONSTRAINT chk_saldo_cliente_no_negativo
      CHECK (es_tecnica = 1 OR saldo_cent >= 0);

-- Marcar la contrapartida y proyectar su saldo real.
UPDATE cuentas SET es_tecnica = 1 WHERE numero_cuenta = '0000000000';

UPDATE cuentas c
   SET c.saldo_cent = (
         SELECT COALESCE(SUM(m.importe_cent), 0)
           FROM movimientos m WHERE m.cuenta_id = c.id
       )
 WHERE c.es_tecnica = 1;

-- La conciliación ahora distingue el tipo de cuenta.
CREATE OR REPLACE VIEW v_conciliacion_saldos AS
SELECT
  c.id            AS cuenta_id,
  c.numero_cuenta,
  c.es_tecnica,
  c.saldo_cent    AS saldo_proyectado_cent,
  COALESCE(SUM(m.importe_cent), 0) AS saldo_libro_cent,
  c.saldo_cent - COALESCE(SUM(m.importe_cent), 0) AS diferencia_cent
FROM cuentas c
LEFT JOIN movimientos m ON m.cuenta_id = c.id
GROUP BY c.id, c.numero_cuenta, c.es_tecnica, c.saldo_cent;

-- Balance global: el activo de los clientes debe igualar el pasivo del
-- banco. La suma de TODAS las cuentas tiene que dar exactamente cero.
CREATE OR REPLACE VIEW v_balance_general AS
SELECT
  SUM(CASE WHEN es_tecnica = 0 THEN saldo_cent ELSE 0 END) AS total_clientes_cent,
  SUM(CASE WHEN es_tecnica = 1 THEN saldo_cent ELSE 0 END) AS total_tecnicas_cent,
  SUM(saldo_cent)                                          AS descuadre_cent
FROM cuentas;
