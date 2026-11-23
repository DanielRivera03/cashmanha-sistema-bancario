-- -----------------------------------------------------------------
-- Las tareas que faltaban en el planificador propio.
--
-- El sistema ya corre sus barridos solo, sin depender del Programador
-- de tareas de Windows: cada petición web mira si hay algo vencido y
-- lo ejecuta después de haber respondido al usuario. Lo que faltaba
-- era dar de alta tres trabajos que existían como guiones pero que
-- nadie disparaba.
--
--   compensar           La compensación se aplica sola cuando entra
--                       dinero a una cuenta. Este barrido recoge lo
--                       que ese disparo no alcanza: saldo que entró
--                       cuando aún no había cuota vencida, y abonos
--                       que entraron por una vía que no pasa por los
--                       servicios. Sin él, ese dinero se queda quieto
--                       mientras al cliente le sigue corriendo el
--                       recargo.
--
--   cerrar-inactivas    Cuentas en cero y sin movimiento: avisa, y a
--                       los treinta días cierra. Son dos pasadas de
--                       la misma tarea, así que a diario basta.
--
--   conciliar-pasarela  Cobros que quedaron a medias. Va cada quince
--                       minutos y no una vez al día, porque un pedido
--                       pagado y sin confirmar tiene a alguien
--                       esperando su compra.
--
-- EL ORDEN DE LAS HORAS NO ES CAPRICHO
--
-- `devengar-mora` corre a las 06:15 y `compensar` a las 06:45. Así la
-- compensación trabaja sobre una mora ya devengada del día, y no
-- sobre la de ayer. Al revés, el abono se calcularía contra un
-- recargo desactualizado.
--
-- `cerrar-inactivas` va de última, a las 07:30: cerrar una cuenta es
-- lo más definitivo que hace el sistema solo, y conviene que ocurra
-- cuando todo lo demás del día ya pasó —incluida una compensación que
-- podría haber movido el saldo de esa misma cuenta—.
-- -----------------------------------------------------------------

INSERT INTO tareas_sistema
  (clave, descripcion, cada_minutos, hora_preferida, activa, proxima_corrida)
VALUES
  ('compensar',
   'Aplica el saldo de las cuentas a las cuotas vencidas de sus creditos',
   1440, '06:45:00', 1, NULL),

  ('cerrar-inactivas',
   'Avisa y cierra las cuentas en cero sin movimiento, y retira su numero',
   1440, '07:30:00', 1, NULL),

  ('conciliar-pasarela',
   'Cierra los cobros de pasarela que quedaron a medias',
   15, NULL, 1, NULL)
ON DUPLICATE KEY UPDATE
  descripcion    = VALUES(descripcion),
  cada_minutos   = VALUES(cada_minutos),
  hora_preferida = VALUES(hora_preferida),
  activa         = VALUES(activa);
