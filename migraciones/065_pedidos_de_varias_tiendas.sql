-- -----------------------------------------------------------------
-- Un cobro por pasarela, varios pedidos.
--
-- El carrito ya permite artículos de varias tiendas, y la compra con
-- tarjeta emitida por el banco abre un pedido por comercio. El camino
-- de la pasarela —el de quien paga con una tarjeta de otro banco— se
-- quedó atrás: todavía rechazaba el carrito mezclado con «Cada pedido
-- es de un solo comercio».
--
-- Se arregla abriendo también ahí un pedido por comercio, pero con UN
-- solo cobro a la tarjeta: a quien compra se le cobra una vez, aunque
-- por detrás haya dos tiendas que despachan por separado y dos folios
-- que seguir.
--
-- `grupo_pago` es lo que los mantiene unidos. Cuando la pasarela
-- confirma el cobro, se dan por pagados TODOS los pedidos del grupo;
-- cuando la rechaza, se liberan todos. Sin esta columna, una tarjeta
-- rechazada dejaría apartadas para siempre las existencias de la
-- segunda tienda, porque `pagos_pasarela` solo guarda un `pedido_id`.
--
-- Es nula en todo lo anterior: un pedido suelto no pertenece a ningún
-- grupo y sigue funcionando igual que siempre.
-- -----------------------------------------------------------------

ALTER TABLE tienda_pedidos
    ADD COLUMN grupo_pago CHAR(16) NULL DEFAULT NULL
        COMMENT 'Pedidos cobrados juntos en un solo pago de pasarela'
        AFTER referencia,
    ADD INDEX idx_pedidos_grupo (grupo_pago);
