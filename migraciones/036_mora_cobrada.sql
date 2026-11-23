-- =====================================================================
-- 036 · `cuotas.mora_cent` guarda solo el recargo COBRADO
-- =====================================================================
--
-- La columna tenía dos significados mezclados y eso se vio en pantalla:
-- una cuota sin pagar nada aparecía con $5.99 en la columna «abonado»,
-- y una cuota ya pagada por compensación aparecía debiendo justo el
-- recargo que acababa de cobrársele.
--
-- El criterio queda así:
--
--   `cuotas.mora_cent`  recargo efectivamente COBRADO sobre esa cuota.
--                       Cero mientras no se haya cobrado nada.
--
--   mora devengada      NO se guarda: se calcula al vuelo con `Mora`
--                       sobre el saldo pendiente y los días de atraso.
--                       Guardarla obligaría a recalcularla cada día en
--                       todas las cuotas vencidas del sistema.
--
-- Lo que se limpia aquí son 69 cuotas de los datos migrados que traían
-- un devengo guardado sin que se hubiera cobrado un centavo: $413.31 de
-- recargo que el sistema contaba como abonado sin estarlo.
-- ---------------------------------------------------------------------

-- Nada pagado: no se cobró recargo alguno.
UPDATE cuotas
   SET mora_cent = 0
 WHERE pagado_cent = 0
   AND estado NOT IN ('pagada', 'condonada')
   AND mora_cent > 0;

-- Las cuotas que SÍ recibieron un abono por compensación: el recargo
-- cobrado es el que consta en `compensaciones`, no lo que hubiera
-- quedado de un devengo anterior.
UPDATE cuotas q
  JOIN (
      SELECT co.cuota_id, SUM(co.mora_cent) AS cobrada
        FROM compensaciones co
       GROUP BY co.cuota_id
  ) x ON x.cuota_id = q.id
   SET q.mora_cent = x.cobrada
 WHERE q.estado = 'parcial';
