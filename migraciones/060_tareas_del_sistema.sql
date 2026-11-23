-- ---------------------------------------------------------------------
-- Las tareas periódicas, dentro del propio sistema.
--
-- Hasta ahora dependían del Programador de tareas de Windows. Eso
-- funciona, pero ata el sistema a una máquina concreta y a que alguien
-- se acuerde de registrarlas: si se mueve a otro servidor, o se
-- restaura una copia, o se despliega en otro sitio, las tareas se
-- quedan atrás sin que nada avise. Y lo que se queda atrás no es
-- menor: sin `revisar-segmentos` los clientes dejan de recibir los
-- beneficios que su pantalla les promete.
--
-- Con esto el sistema se encarga solo. Cada petición web comprueba si
-- hay algo vencido —una consulta con índice— y, si lo hay, lo corre
-- DESPUÉS de haber respondido al usuario, con el mismo mecanismo que
-- ya usa el envío de correo.
--
-- La contrapartida, dicha sin adornos: esto corre cuando alguien usa
-- el sistema. Un domingo sin visitas no corre nada, y el lunes se
-- pone al día. Para una banca en línea con uso diario es suficiente, y
-- cada tarea está escrita para tolerar que se retrase. Si algún día
-- hace falta puntualidad de reloj, la tarea de Windows sigue siendo
-- válida y las dos conviven sin estorbarse: el bloqueo impide que se
-- ejecuten a la vez.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS tareas_sistema (
  id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clave          VARCHAR(40)  NOT NULL UNIQUE,
  descripcion    VARCHAR(255) NOT NULL,

  -- Cada cuánto debe correr. En minutos, que es suficiente: nada de
  -- esto necesita precisión de segundos.
  cada_minutos   INT UNSIGNED NOT NULL DEFAULT 1440,

  -- La hora a la que se prefiere, para las diarias. NULL = en cuanto
  -- hayan pasado los minutos.
  hora_preferida TIME NULL DEFAULT NULL,

  activa         TINYINT(1) NOT NULL DEFAULT 1,

  -- El bloqueo. Dos peticiones simultáneas no pueden correr la misma
  -- tarea: la primera toma la marca y la segunda ve que está corriendo
  -- y sigue de largo. `corriendo_desde` permite soltar un bloqueo que
  -- quedó colgado porque el proceso murió a medias.
  corriendo_desde DATETIME NULL DEFAULT NULL,

  ultima_corrida  DATETIME NULL DEFAULT NULL,
  proxima_corrida DATETIME NULL DEFAULT NULL,

  ultimo_resultado VARCHAR(500) NULL,
  ultimo_estado    ENUM('ok','fallo','omitida') NULL,
  corridas         INT UNSIGNED NOT NULL DEFAULT 0,
  fallos           INT UNSIGNED NOT NULL DEFAULT 0,

  INDEX ix_tareas_pendientes (activa, proxima_corrida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Las cinco ---------------------------------------------------------
--
-- `proxima_corrida` arranca en NULL a propósito: así la primera visita
-- después de aplicar esto las pone en marcha y se ve enseguida si
-- funcionan, en vez de esperar un día para descubrir que no.

INSERT INTO tareas_sistema (clave, descripcion, cada_minutos, hora_preferida)
SELECT * FROM (
  SELECT 'revisar-segmentos' AS clave,
         'Recalcula el segmento de cada cliente. De ese valor dependen la tasa de la tarjeta en cada corte, el multiplo de linea, el diferido y la cortesia de sobregiro: sin esto, quien ya cumple las condiciones no recibe sus beneficios.' AS descripcion,
         1440 AS cada_minutos, '06:00:00' AS hora_preferida
  UNION ALL SELECT 'devengar-mora',
         'Anota una fila por dia de atraso. No decide lo que se cobra —eso lo calcula Mora::calcular() en vivo— pero mantiene el rastro que permite explicarle al cliente de donde sale su recargo.',
         1440, '06:15:00'
  UNION ALL SELECT 'cortar-tarjetas',
         'Cierra el periodo de las tarjetas cuyo dia de corte es hoy. Sin esto no hay estado de cuenta ni fecha limite de pago.',
         1440, '06:30:00'
  UNION ALL SELECT 'abonar-intereses',
         'Paga el interes de las cuentas de ahorro. Se niega a abonar un periodo que no ha cerrado, asi que intentarlo a diario es inofensivo: paga una vez al mes y los demas dias no hace nada.',
         1440, '02:00:00'
  UNION ALL SELECT 'verificar-cuadre',
         'Comprueba diez igualdades de dinero que el libro mayor no cubre. No cambia nada. Va la ultima, para revisar el resultado de las demas.',
         1440, '07:00:00'
) AS nuevas
WHERE NOT EXISTS (SELECT 1 FROM tareas_sistema t WHERE t.clave = nuevas.clave);
