-- -----------------------------------------------------------------
-- 096. Avisos automáticos: recordatorio de pago y sobregiro
-- -----------------------------------------------------------------
--
-- QUÉ RESUELVE
--
-- Dos correos que ahora salen SOLOS, a diario, como el resto de las
-- tareas del sistema:
--
--   RECORDATORIO DE PAGO. Al cliente con una cuota de crédito o un
--   corte de tarjeta por vencer en los próximos días, para que pague
--   antes y no caiga en mora.
--
--   AVISO DE SOBREGIRO. Al cliente cuya tarjeta ya pasó del límite
--   pero todavía no se inhabilita, para que cubra el excedente antes
--   de la fecha de pago y no se le bloquee.
--
-- POR QUÉ NO SPAMEA
--
-- Una tarea diaria que mande el mismo aviso cada día es ruido que el
-- cliente aprende a ignorar. Esta tabla recuerda qué aviso ya salió y
-- sobre qué —una cuota, un corte—, de modo que cada uno se manda UNA
-- vez. Cuando nace una cuota o un corte nuevo, hay una referencia
-- nueva y el aviso vuelve a tener sentido.
-- -----------------------------------------------------------------

CREATE TABLE IF NOT EXISTS avisos_enviados (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clave       VARCHAR(40)  NOT NULL,   -- 'recordatorio-pago' | 'sobregiro'
    referencia  VARCHAR(80)  NOT NULL,   -- cuota:<id> | corte:<id> | sobregiro:<corteId>
    usuario_id  INT UNSIGNED NOT NULL,
    enviado_en  DATETIME     NOT NULL,
    UNIQUE KEY uq_aviso (clave, referencia),
    KEY idx_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cuántos días antes del vencimiento se recuerda.
INSERT INTO parametros (clave, valor, descripcion)
VALUES
  ('aviso.dias_previos_pago', '7',
   'Dias antes del vencimiento en que se recuerda el pago')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- Las dos tareas diarias.
INSERT INTO tareas_sistema (clave, descripcion, cada_minutos, hora_preferida)
SELECT 'recordar-pagos',
       'Recuerda por correo las cuotas de credito y cortes de tarjeta que vencen en los proximos dias, una sola vez por cuota o corte.',
       1440,
       '07:00:00'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM tareas_sistema WHERE clave = 'recordar-pagos');

INSERT INTO tareas_sistema (clave, descripcion, cada_minutos, hora_preferida)
SELECT 'avisar-sobregiro',
       'Avisa por correo a quien tiene una tarjeta sobre el limite aun no inhabilitada, una vez por corte, para que cubra el excedente antes de la fecha de pago.',
       1440,
       '07:15:00'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM tareas_sistema WHERE clave = 'avisar-sobregiro');
