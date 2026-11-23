-- =====================================================================
-- 015 — Seguros obligatorios en créditos y tarjetas
-- ---------------------------------------------------------------------
-- Faltaban las dos deducciones que en la práctica lleva todo crédito de
-- consumo en El Salvador y que el cliente ve descontadas en su cuota:
--
--  · Seguro de vida sobre saldo deudor. Si el deudor fallece, la
--    aseguradora cancela el saldo pendiente y la deuda no pasa a la
--    familia. Es el que exige el contrato al hablar de mantener vigente
--    la póliza durante el plazo del préstamo.
--
--  · Seguro de desempleo. Cubre un número de cuotas si el deudor pierde
--    su empleo de forma involuntaria.
--
-- Los dos se calculan sobre el SALDO, no sobre el monto original: bajan
-- conforme se amortiza, que es como los cobra una aseguradora de saldo
-- deudor. Un cargo fijo sobre el monto inicial haría pagar lo mismo en la
-- última cuota que en la primera.
--
-- Las tarifas van en la tabla de parámetros —expresadas por cada $1,000
-- de saldo al mes— porque las renegocia la institución con la
-- aseguradora y no deberían vivir dentro del código.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Desglose en cada cuota
-- ---------------------------------------------------------------------
-- Sin columnas propias, el seguro quedaría sumado dentro del total y el
-- cliente no podría ver cuánto le cobran por cada concepto, que es justo
-- lo que un estado de cuenta tiene que poder explicar.

ALTER TABLE cuotas
    ADD COLUMN seguro_vida_cent   BIGINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Seguro de vida sobre saldo deudor' AFTER interes_cent,
    ADD COLUMN seguro_empleo_cent BIGINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Seguro de desempleo' AFTER seguro_vida_cent;

-- ---------------------------------------------------------------------
-- Tarifas
-- ---------------------------------------------------------------------
-- Centavos por cada $1,000 de saldo, al mes. 90 = $0.90 por millar.

INSERT INTO parametros (clave, valor, descripcion) VALUES
    ('seguro.vida_por_mil_cent', '90',
     'Seguro de vida: centavos por cada $1,000 de saldo deudor al mes'),
    ('seguro.empleo_por_mil_cent', '110',
     'Seguro de desempleo: centavos por cada $1,000 de saldo deudor al mes'),
    ('seguro.vida_tarjeta_por_mil_cent', '120',
     'Seguro de vida en tarjeta de crédito: centavos por cada $1,000 de saldo usado'),
    ('seguro.empleo_tarjeta_por_mil_cent', '140',
     'Seguro de desempleo en tarjeta de crédito: centavos por cada $1,000 de saldo usado'),
    ('seguro.minimo_cent', '50',
     'Cargo mínimo por póliza cuando el saldo es muy pequeño')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- ---------------------------------------------------------------------
-- Créditos ya vigentes
-- ---------------------------------------------------------------------
-- A los créditos en curso NO se les añade el seguro: su cuota ya fue
-- pactada y firmada sin él. Cambiar el total de una cuota existente
-- sería cobrarle al cliente algo que no aceptó, y además descuadraría lo
-- ya contabilizado contra el libro mayor.
--
-- Las columnas quedan en cero para esos créditos, que es la verdad: no
-- lo llevan. Aplica de aquí en adelante.
