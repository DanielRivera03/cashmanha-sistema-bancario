-- ---------------------------------------------------------------------
-- 039 · El saldo diferido ocupa la linea
--
-- `disponible_cent` era `limite - saldo_usado`. Una compra a cuotas
-- sale de `saldo_usado_cent` y pasa a un plan, asi que al diferirla
-- dejaba de descontar: una tarjeta con $440.49 en dos planes vigentes
-- mostraba el cupo intacto, «disponible $5,000.00» sobre un limite de
-- $5,000.00, y el titular podia volver a gastar la linea entera
-- teniendo ya deuda viva.
--
-- `uso_pct` arrastraba el mismo error y marcaba «0% usado» con la
-- deuda encima.
--
-- Se ocupa la linea con el pendiente total del plan —principal mas
-- intereses por devengar—: es el criterio prudente y, sobre todo, es
-- el mismo numero que la ficha ya rotula «Saldo diferido». Que la
-- cifra que se enseña y la que autoriza sean la misma importa tanto
-- como acertar al centimo.
-- ---------------------------------------------------------------------

CREATE OR REPLACE VIEW v_tarjetas_detalle AS
SELECT
    t.id, t.uuid, t.usuario_id, t.ultimos4, t.bin6, t.marca, t.tipo,
    t.titular, t.alias, t.expira_mes, t.expira_anio, t.estado,
    t.limite_cent, t.saldo_usado_cent, t.limite_diario_cent,
    t.dia_corte, t.dias_para_pago, t.tasa_diferido_bp,
    t.compras_internet, t.compras_exterior, t.contactless,
    t.congelada_hasta, t.proximo_corte, t.emitida_en,
    t.producto_id,

    t.sobregiro_permitido,
    t.sobregiro_tolerancia_bp,
    t.pago_automatico,
    t.pago_automatico_cuenta_id,
    t.alerta_consumo_cent,

    CASE WHEN t.sobregiro_permitido = 1
         THEN ROUND(t.limite_cent * t.sobregiro_tolerancia_bp / 10000)
         ELSE 0 END AS sobregiro_cent,

    -- Lo que de verdad queda libre: el limite menos todo lo que lo ocupa.
    GREATEST(0, t.limite_cent - t.saldo_usado_cent
             - (SELECT COALESCE(SUM(p.total_cent - p.pagado_cent), 0)
                  FROM tarjeta_planes p
                 WHERE p.tarjeta_id = t.id AND p.estado = 'vigente')
    ) AS disponible_cent,

    CASE WHEN t.limite_cent > 0
         THEN LEAST(100, ROUND(
                (t.saldo_usado_cent
                 + (SELECT COALESCE(SUM(p.total_cent - p.pagado_cent), 0)
                      FROM tarjeta_planes p
                     WHERE p.tarjeta_id = t.id AND p.estado = 'vigente')
                ) * 100.0 / t.limite_cent, 1))
         ELSE 0 END AS uso_pct,

    CONCAT(u.nombres, ' ', u.apellidos) AS propietario,
    u.correo AS propietario_correo,

    (SELECT COUNT(0) FROM tarjeta_planes p
      WHERE p.tarjeta_id = t.id AND p.estado = 'vigente') AS planes_vigentes,

    (SELECT COALESCE(SUM(p.total_cent - p.pagado_cent), 0) FROM tarjeta_planes p
      WHERE p.tarjeta_id = t.id AND p.estado = 'vigente') AS saldo_diferido_cent,

    (SELECT COUNT(0) FROM tarjeta_movimientos m
      WHERE m.tarjeta_id = t.id AND m.tipo = 'consumo' AND m.plan_id IS NULL
        AND m.diferible = 1
        AND m.creado_en > CURRENT_TIMESTAMP() - INTERVAL 90 DAY) AS consumos_diferibles

FROM tarjetas t
JOIN usuarios u ON u.id = t.usuario_id;
