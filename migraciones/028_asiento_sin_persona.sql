-- ---------------------------------------------------------------------
-- 028 · Un asiento puede no tener persona detrás
--
-- `asientos.creado_por` era obligatorio, y eso obliga a mentir cuando
-- nadie lo creó. Hay operaciones que de verdad no las hace una persona:
--
--   · Una compra de la tienda pagada por un invitado. No hay usuario, y
--     anotar al administrador de turno diría que él hizo la compra.
--   · El devengo diario de mora, que corre de madrugada por tarea.
--   · El corte de tarjetas.
--   · Un reverso automático por una tarjeta rechazada.
--
-- Hasta ahora había que pasar un id cualquiera —normalmente el del
-- primer administrador— y la bitácora quedaba diciendo que esa persona
-- registró cosas que nunca tocó. En un sistema donde la auditoría es el
-- argumento principal, eso es peor que un hueco: es un dato falso.
--
-- Nulo significa «el sistema», y se lee igual de claro en los reportes.
-- ---------------------------------------------------------------------

ALTER TABLE asientos
  MODIFY COLUMN creado_por INT UNSIGNED NULL
      COMMENT 'Nulo cuando el asiento lo origina el sistema y no una persona';
