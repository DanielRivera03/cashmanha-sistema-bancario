-- -----------------------------------------------------------------
-- 077. El plazo de financiamiento, uno por producto
-- -----------------------------------------------------------------
--
-- QUÉ SE CORRIGE
--
-- La migración 076 puso 36 meses a todas las tarjetas. Ese número no
-- sale de ninguna norma: lo elegí yo, y estaba mal elegido. La Ley del
-- Sistema de Tarjetas de Crédito no fija un plazo; lo fija el emisor
-- en el contrato de cada producto. Por eso en el mercado se ven 48, 60,
-- 66 y 72 meses según la tarjeta.
--
-- POR QUÉ IMPORTA Y NO ES UN DATO DECORATIVO
--
-- Porque es el DIVISOR del pago mínimo. Las normas técnicas lo dicen:
--
--     pago mínimo = capital ÷ plazo + intereses + saldos vencidos
--
-- Un plazo corto sube la cuota mensual de todos los tarjetahabientes.
-- Con los 36 de la migración anterior, la tarjeta ····3708 pasó de
-- $808.65 a $1,015.30 de pago mínimo: doscientos dólares más al mes
-- por una cifra que nadie decidió.
--
-- EL CRITERIO DE ESTOS VALORES
--
-- A mayor categoría, plazo más largo: es lo que hace el mercado,
-- porque la línea es mayor y la cuota se vuelve impagable si se
-- divide entre pocos meses. No son valores sagrados — son un punto de
-- partida razonable que se edita desde el catálogo de productos sin
-- tocar código, que es justo lo que faltaba.
-- -----------------------------------------------------------------

UPDATE productos SET plazo_financiamiento_meses = CASE codigo
    -- Entrada: líneas pequeñas, plazo corto. Dividir $300 entre 72
    -- meses daría una cuota de cuatro dólares: el saldo no se movería
    -- nunca y el cliente pagaría intereses toda la vida.
    WHEN 'TAR-INI' THEN 36
    WHEN 'TAR-CLA' THEN 36

    -- Intermedias.
    WHEN 'TAR-CAS' THEN 48
    WHEN 'TAR-ORO' THEN 48

    -- Altas: línea grande, plazo largo para que la cuota sea pagable.
    WHEN 'TAR-PLA' THEN 60
    WHEN 'TAR-EMP' THEN 60
    WHEN 'TAR-SIG' THEN 72
    WHEN 'TAR-INF' THEN 72
    WHEN 'TAR-OBS' THEN 72

    ELSE plazo_financiamiento_meses
END
WHERE tipo = 'tarjeta';

-- Las de débito no financian nada: no tienen plazo que declarar.
UPDATE productos
   SET plazo_financiamiento_meses = NULL
 WHERE tipo = 'tarjeta' AND codigo = 'TAR-DEB';
