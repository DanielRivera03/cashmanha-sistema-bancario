-- -----------------------------------------------------------------
-- El despacho: quién lo lleva, con qué guía y cuándo.
--
-- El pedido solo tenía un estado —pagado, preparando, enviado,
-- entregado— y nada más. Para quien compró, «enviado» a secas no
-- responde ninguna de las preguntas que de verdad se hace: cuándo
-- salió, quién lo lleva, con qué número lo rastrea, cuándo llega.
-- Y para la tienda tampoco queda registro de lo que despachó.
--
-- Se agregan las tres cosas que convierten «enviado» en información:
--
--   transportista   Quién lo lleva. Texto libre a propósito: aquí
--                   reparte desde una empresa de encomiendas hasta el
--                   motorista del propio comercio, y encerrarlo en una
--                   lista fija obligaría a migrar cada vez que una
--                   tienda cambia de repartidor.
--
--   guia            El número con el que el cliente lo rastrea en la
--                   página del transportista. Puede no haber: un
--                   motorista propio no da guía, y eso es normal.
--
--   entrega_estimada  La fecha que la tienda promete. Es lo primero
--                   que se pregunta y lo único que no se podía
--                   contestar.
--
-- Y las marcas de tiempo de cada paso, que antes se perdían: el
-- estado se sobrescribía y no quedaba cuándo pasó a cada cosa. Sin
-- eso no se puede responder «¿cuánto tardaron en despacharlo?», que
-- es la pregunta con la que empieza todo reclamo.
-- -----------------------------------------------------------------

ALTER TABLE tienda_pedidos
    ADD COLUMN transportista VARCHAR(120) NULL DEFAULT NULL
        COMMENT 'Quien lleva el pedido' AFTER direccion,
    ADD COLUMN guia VARCHAR(60) NULL DEFAULT NULL
        COMMENT 'Numero de guia para rastrear con el transportista' AFTER transportista,
    ADD COLUMN entrega_estimada DATE NULL DEFAULT NULL
        COMMENT 'Fecha que la tienda promete' AFTER guia,
    ADD COLUMN nota_despacho VARCHAR(300) NULL DEFAULT NULL
        COMMENT 'Lo que la tienda quiera decirle al cliente' AFTER entrega_estimada,
    ADD COLUMN preparando_en DATETIME NULL DEFAULT NULL AFTER nota_despacho,
    ADD COLUMN enviado_en    DATETIME NULL DEFAULT NULL AFTER preparando_en,
    ADD COLUMN entregado_en  DATETIME NULL DEFAULT NULL AFTER enviado_en,
    ADD INDEX idx_pedidos_estado_comercio (comercio_id, estado);

-- Lo que ya estaba entregado o enviado no tiene marca de cuándo: se
-- le pone la de la última actualización, que es lo más cercano que
-- hay. Es una aproximación y por eso solo se aplica donde no hay dato
-- ninguno; inventarla sobre un pedido que sí la tenga sería peor.
UPDATE tienda_pedidos
   SET enviado_en = actualizado_en
 WHERE enviado_en IS NULL AND estado IN ('enviado', 'entregado');

UPDATE tienda_pedidos
   SET entregado_en = actualizado_en
 WHERE entregado_en IS NULL AND estado = 'entregado';

UPDATE tienda_pedidos
   SET preparando_en = actualizado_en
 WHERE preparando_en IS NULL AND estado IN ('preparando', 'enviado', 'entregado');
