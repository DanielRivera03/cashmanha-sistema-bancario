-- ---------------------------------------------------------------------
-- 043 · La vista de tarjetas dice de que linea es cada una
--
-- El diseno de una tarjeta sale de su LINEA —el producto contratado—,
-- no de la marca: una Signature es negra la procese quien la procese.
-- La vista traia `producto_id` pero no el nivel ni el codigo, asi que
-- la pantalla no tenia con que decidir y todas se pintaban igual.
--
-- El JOIN es LEFT a proposito: una tarjeta puede no tener producto
-- —las emitidas a mano antes del catalogo— y no por eso debe
-- desaparecer del listado.
-- ---------------------------------------------------------------------

CREATE OR REPLACE VIEW v_tarjetas_detalle AS
SELECT
    t.id, t.uuid, t.usuario_id, t.ultimos4, t.bin6, t.marca, t.tipo,
    t.titular, t.alias, t.expira_mes, t.expira_anio, t.estado,
    t.limite_cent, t.saldo_usado_cent, t.limite_diario_cent,
    t.dia_corte, t.dias_para_pago, t.tasa_diferido_bp,
    t.compras_internet, t.compras_exterior, t.contactless,
    t.congelada_hasta, t.proximo_corte, t.emitida_en,
    t.producto_id, t.cuenta_id,

    t.sobregiro_permitido,
    t.sobregiro_tolerancia_bp,
    t.pago_automatico,
    t.pago_automatico_cuenta_id,
    t.alerta_consumo_cent,

    CASE WHEN t.sobregiro_permitido = 1
         THEN ROUND(t.limite_cent * t.sobregiro_tolerancia_bp / 10000)
         ELSE 0 END AS sobregiro_cent,

    GREATEST(0, t.limite_cent - t.saldo_usado_cent) AS disponible_cent,

    CASE WHEN t.limite_cent > 0
         THEN LEAST(100, ROUND(t.saldo_usado_cent * 100.0 / t.limite_cent, 1))
         ELSE 0 END AS uso_pct,

    CONCAT(u.nombres, ' ', u.apellidos) AS propietario,
    u.correo AS propietario_correo,

    -- Nombre y apellidos por separado: el plastico los necesita asi para
    -- acortar por palabras y no partir la cadena a la mitad.
    u.nombres, u.apellidos,

    -- La linea de la tarjeta.
    pr.nivel  AS producto_nivel,
    pr.codigo AS producto_codigo,
    pr.nombre AS producto_nombre,

    -- La cuenta ligada, para las de debito.
    cu.numero_cuenta AS cuenta_numero,
    cu.saldo_cent    AS cuenta_saldo_cent,

    (SELECT COUNT(0) FROM tarjeta_planes p
      WHERE p.tarjeta_id = t.id AND p.estado = 'vigente') AS planes_vigentes,

    (SELECT COALESCE(SUM(p.total_cent - p.pagado_cent), 0) FROM tarjeta_planes p
      WHERE p.tarjeta_id = t.id AND p.estado = 'vigente') AS saldo_diferido_cent,

    (SELECT COUNT(0) FROM tarjeta_movimientos m
      WHERE m.tarjeta_id = t.id AND m.tipo = 'consumo' AND m.plan_id IS NULL
        AND m.diferible = 1
        AND m.creado_en > CURRENT_TIMESTAMP() - INTERVAL 90 DAY) AS consumos_diferibles

FROM tarjetas t
JOIN usuarios u       ON u.id = t.usuario_id
LEFT JOIN productos pr ON pr.id = t.producto_id
LEFT JOIN cuentas cu   ON cu.id = t.cuenta_id;
