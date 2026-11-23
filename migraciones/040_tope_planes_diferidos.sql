-- ---------------------------------------------------------------------
-- 040 · Hasta cinco compras diferidas a la vez
--
-- No habia tope: un cliente podia diferir cada consumo y acabar con
-- veinte planes corriendo a la vez, cada uno con su calendario y su
-- cuota. Eso no lo entiende nadie —ni el cliente al leer su estado de
-- cuenta ni quien le llama a cobrar— y deja la linea comprometida a
-- anios vista sin que ninguna decision explicita lo haya autorizado.
--
-- Cinco es el tope. Se guarda como parametro y no en el codigo para
-- poder subirlo o bajarlo sin tocar nada.
--
-- Se cuentan los planes VIGENTES: al liquidar uno se libera su hueco,
-- que es lo que hace que el tope sea un limite y no una cuota de por
-- vida.
-- ---------------------------------------------------------------------

INSERT INTO parametros (clave, valor, descripcion)
VALUES ('tarjeta.planes_maximos', '5',
        'Compras diferidas que un titular puede tener vigentes a la vez en una misma tarjeta.')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
