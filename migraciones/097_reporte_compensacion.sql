-- -----------------------------------------------------------------
-- 097. Reporte interno de compensación
-- -----------------------------------------------------------------
--
-- Un correo que NO va al cliente, sino a una casilla del banco: cuánto
-- se recuperó por compensación en el periodo, a cuántos clientes, y el
-- reparto entre crédito y tarjeta. Mide recuperación de cartera.
--
-- El destino es configurable (`reporte.correo_interno`). Arranca con el
-- buzón de monitoreo; cámbielo a la casilla interna que corresponda.
-- Corre ~mensual; reporta los últimos 30 días.
-- -----------------------------------------------------------------

INSERT INTO parametros (clave, valor, descripcion)
VALUES
  ('reporte.correo_interno', 'reportes@demo.cashmanha.sv',
   'Casilla interna del banco que recibe los reportes (no es un cliente)')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

INSERT INTO tareas_sistema (clave, descripcion, cada_minutos, hora_preferida)
SELECT 'reporte-compensacion',
       'Envia a la casilla interna el resumen de lo recuperado por compensacion en el periodo. No va a clientes.',
       43200,
       '08:00:00'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM tareas_sistema WHERE clave = 'reporte-compensacion');
