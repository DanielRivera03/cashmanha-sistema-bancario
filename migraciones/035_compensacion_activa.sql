-- =====================================================================
-- 035 · Activar la compensación
-- =====================================================================
--
-- La compensación se pidió automática: si el cliente tiene saldo y
-- cuotas vencidas, el dinero cubre la mora sin que nadie tenga que
-- hacer un segundo trámite.
--
-- La tabla se creó con la columna en cero por prudencia —tomar dinero
-- de la cuenta de alguien no es algo que deba empezar a pasar por una
-- migración sin que nadie lo decida— y aquí se enciende de forma
-- explícita, que es la decisión que se tomó.
--
-- Lo que NO cambia:
--
--   - El saldo mínimo del producto sigue siendo intocable.
--   - Solo se cubren cuotas VENCIDAS, y enteras: nunca un abono parcial
--     que deje la mora corriendo igual.
--   - Cada aplicación avisa al cliente diciendo cuánto, por qué y a qué
--     cuotas fue.
--   - Queda registrada en `compensaciones` y en el libro mayor.
--
-- Se puede apagar en conjunto con el parámetro `compensacion.activa`, o
-- cuenta por cuenta desde el panel.
-- ---------------------------------------------------------------------

-- Las cuentas que ya existen.
UPDATE cuentas
   SET compensacion_autorizada = 1
 WHERE es_tecnica = 0;

-- Y las que se abran de aquí en adelante.
ALTER TABLE cuentas
    MODIFY COLUMN compensacion_autorizada TINYINT(1) NOT NULL DEFAULT 1;

-- Un colchón: por debajo de esta cantidad no se deja nunca la cuenta.
-- Sin él, alguien que deposita su sueldo para vivir el mes se queda a
-- cero el mismo día, y eso no se arregla con una notificación.
UPDATE cuentas
   SET compensacion_minimo_cent = 2500
 WHERE es_tecnica = 0 AND compensacion_minimo_cent = 0;

INSERT INTO parametros (clave, valor, descripcion) VALUES
  ('compensacion.minimo_cuenta_cent', '2500',
   'Colchon que se deja siempre en la cuenta al compensar (centavos)')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
