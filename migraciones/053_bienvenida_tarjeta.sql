-- ---------------------------------------------------------------------
-- La bienvenida de cada tarjeta, una sola vez.
--
-- Cuando se emite una tarjeta, el cliente se entera por una línea en
-- el listado. Para la institución es una fila más; para quien recibe
-- su primera tarjeta de crédito no lo es, y el sistema tiene con qué
-- distinguir las dos cosas.
--
-- Hace falta recordar que ya se le dio la bienvenida, o se la daría en
-- cada carga de pantalla, que es exactamente lo contrario de celebrar
-- algo: lo que se repite deja de significar.
--
-- Se marca en la propia tarjeta y no en el usuario porque el hecho es
-- de la tarjeta: alguien con tres tarjetas tiene tres bienvenidas, una
-- por cada una, y la de la tercera no debe suprimirla la de la primera.
-- ---------------------------------------------------------------------

ALTER TABLE tarjetas
  ADD COLUMN bienvenida_vista_en DATETIME NULL DEFAULT NULL
      COMMENT 'Cuando se le dio la bienvenida al titular. NULL = pendiente'
      AFTER activada_en;

-- Las tarjetas que ya existen no reciben bienvenida: el cliente lleva
-- meses usándolas y felicitarlo ahora por algo viejo sería ruido.
UPDATE tarjetas SET bienvenida_vista_en = emitida_en WHERE bienvenida_vista_en IS NULL;

-- Para que la consulta de cada carga de pantalla no recorra la tabla.
CREATE INDEX ix_tarjetas_bienvenida ON tarjetas (usuario_id, bienvenida_vista_en);
