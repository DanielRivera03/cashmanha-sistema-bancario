-- =====================================================================
-- 016 — Corte mensual de tarjetas de crédito
-- ---------------------------------------------------------------------
-- La tabla `tarjeta_cortes` existía desde la migración 005 y estaba
-- vacía: nunca hubo un proceso que la llenara. Sin corte no hay estado
-- de cuenta de tarjeta, no hay pago mínimo y no se cobran ni intereses
-- ni seguros. El saldo se quedaba quieto hasta que alguien pagara.
--
-- Esta migración solo añade el desglose de los seguros y los parámetros
-- del corte. El proceso vive en `ServicioCorteTarjeta`.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Desglose de seguros en el corte
-- ---------------------------------------------------------------------
-- Igual que en las cuotas de crédito: sumarlos dentro de `comisiones` los
-- volvería invisibles, y el estado de cuenta tiene que poder explicar
-- cada cargo por separado.

ALTER TABLE tarjeta_cortes
    ADD COLUMN seguro_vida_cent   BIGINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Seguro de vida sobre saldo deudor' AFTER intereses_cent,
    ADD COLUMN seguro_empleo_cent BIGINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Seguro de desempleo' AFTER seguro_vida_cent;

-- ---------------------------------------------------------------------
-- Parámetros del corte
-- ---------------------------------------------------------------------

INSERT INTO parametros (clave, valor, descripcion) VALUES
    ('tarjeta.pago_minimo_pct', '500',
     'Pago mínimo como porcentaje del saldo, en puntos base (500 = 5 %)'),
    ('tarjeta.pago_minimo_piso_cent', '1000',
     'Pago mínimo absoluto cuando el porcentaje da menos de esta cantidad'),
    ('tarjeta.dias_para_pago', '20',
     'Días entre la fecha de corte y la fecha límite de pago')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- ---------------------------------------------------------------------
-- Permiso
-- ---------------------------------------------------------------------

INSERT IGNORE INTO permisos (clave, descripcion) VALUES
    ('tarjeta.cortar', 'Ejecutar el corte mensual de tarjetas de crédito');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p
 WHERE p.clave = 'tarjeta.cortar'
   AND r.clave IN ('administrador');
