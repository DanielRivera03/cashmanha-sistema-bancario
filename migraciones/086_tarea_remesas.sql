-- =====================================================================
-- 086. La tarea que vence las remesas no cobradas
-- =====================================================================
--
-- Una remesa que nadie cobró vuelve a la remesadora pasado su plazo.
-- Si el sistema la dejara como «disponible», alguien iría a ventanilla
-- a cobrar algo que ya no está, y se enteraría ahí.
--
-- Vale más avisarle al beneficiario el día que vence —para que llame a
-- quien se la envió— que dejar que lo descubra en la fila.
-- =====================================================================
USE cashmanha_v2;

INSERT INTO tareas_sistema (clave, descripcion, cada_minutos, hora_preferida)
SELECT 'vencer-remesas',
       'Marca como devueltas las remesas que nadie cobro dentro de su plazo y avisa al beneficiario. Sin esto figurarian como disponibles y alguien iria a cobrar algo que la remesadora ya reclamo.',
       1440,
       '05:45:00'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM tareas_sistema WHERE clave = 'vencer-remesas');
