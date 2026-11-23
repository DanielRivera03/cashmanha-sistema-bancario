-- =====================================================================
-- 081. La tarea que mueve las transferencias interbancarias
-- =====================================================================
--
-- Cada diez minutos, porque es lo que la operación exige:
--
--   · La ventana de Transfer365 abre a las 00:00:01. Una transferencia
--     que entró a las 22:30 tiene que salir en cuanto abra, no cuando
--     a alguien se le ocurra entrar al sistema por la mañana.
--
--   · El acuse de la red cierra el estado y, con él, el dinero deja de
--     estar en tránsito. Mientras no se cierre, el saldo vive en la
--     cámara, que es un pasivo del banco: cuanto menos tiempo, mejor.
--
--   · Los enfriamientos de la agenda vencen a cualquier hora. Que la
--     pantalla diga «en espera» cuando ya pasó la espera es decirle al
--     cliente algo que no es cierto.
--
-- Sin `hora_preferida`: esta no es una tarea de madrugada, es una que
-- tiene que correr todo el día.
-- =====================================================================
USE cashmanha_v2;

INSERT INTO tareas_sistema (clave, descripcion, cada_minutos, hora_preferida)
SELECT 'despachar-interbancarias',
       'Saca las transferencias que esperaban la apertura de Transfer365, cierra las que ya viajaron y madura los enfriamientos de la agenda. Sin esto, lo creado fuera de horario se queda quieto y el dinero sigue retenido en la camara.',
       10,
       NULL
  FROM DUAL
 WHERE NOT EXISTS (
       SELECT 1 FROM tareas_sistema WHERE clave = 'despachar-interbancarias'
 );
