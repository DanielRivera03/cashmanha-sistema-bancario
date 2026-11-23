-- ---------------------------------------------------------------------
-- 021 · La vista de tarjetas enseña las opciones nuevas
--
-- `v_tarjetas_detalle` lista sus columnas una a una, así que añadir
-- campos a `tarjetas` no basta: la pantalla de detalle lee de la vista y
-- las opciones de sobregiro y pago automático no llegaban.
--
-- Se mantiene `limite_diario_cent` en la vista aunque ya no se use en
-- ninguna pantalla: hay tarjetas con un valor guardado y quitarlo de
-- golpe rompería cualquier consulta que todavía lo lea.
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

    -- Opciones que sustituyen al tope diario de consumo.
    t.sobregiro_permitido,
    t.sobregiro_tolerancia_bp,
    t.pago_automatico,
    t.pago_automatico_cuenta_id,
    t.alerta_consumo_cent,

    -- Cuánto se puede gastar de más si el titular autorizó sobregiro.
    CASE WHEN t.sobregiro_permitido = 1
         THEN ROUND(t.limite_cent * t.sobregiro_tolerancia_bp / 10000)
         ELSE 0 END AS sobregiro_cent,

    GREATEST(0, t.limite_cent - t.saldo_usado_cent) AS disponible_cent,

    CASE WHEN t.limite_cent > 0
         THEN ROUND(t.saldo_usado_cent * 100.0 / t.limite_cent, 1)
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
