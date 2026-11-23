-- ---------------------------------------------------------------------
-- 027 · Cómo se contabiliza una compra de la tienda
--
-- Una compra pagada por la pasarela no es un depósito a nadie: el dinero
-- entra a la institución y queda debiendo al comercio afiliado hasta que
-- se le liquide. Si se abonara a la cuenta del cliente —que es lo que
-- hace el resto de conceptos de la pasarela— el saldo del cliente subiría
-- por una compra que acaba de hacer, que es justo al revés de la verdad.
--
-- Así que la partida doble es entre dos cuentas técnicas:
--
--   · 0000000001 «Cobros de pasarela» se carga, porque la procesadora
--     todavía no ha liquidado.
--   · 0000000002 «Comercios afiliados por pagar» se abona, porque eso es
--     lo que la institución le debe al comercio.
--
-- Ninguna de las dos es de un cliente, y la suma da cero. El día que se
-- le pague al comercio, el asiento se revierte contra el banco.
--
-- Además: un pedido que espera confirmación de la pasarela no está
-- pagado. Se le da su propio estado para no contar como venta algo que
-- todavía puede caerse, y para poder devolver las existencias si el
-- cobro no llega.
-- ---------------------------------------------------------------------

ALTER TABLE tienda_pedidos
  MODIFY COLUMN estado ENUM('pago_pendiente','pagado','preparando','enviado',
                            'entregado','anulado')
         NOT NULL DEFAULT 'pago_pendiente';

-- La cuenta de comercios por pagar. Se cuelga del mismo producto técnico
-- y del mismo usuario del sistema que la de pasarela.
INSERT INTO cuentas (uuid, numero_cuenta, usuario_id, producto_id, moneda,
                     saldo_cent, saldo_retenido_cent, estado, es_tecnica, apertura_en)
SELECT UUID(), '0000000002', c.usuario_id, c.producto_id, c.moneda,
       0, 0, 'activa', 1, NOW()
  FROM cuentas c
 WHERE c.numero_cuenta = '0000000001'
   AND NOT EXISTS (SELECT 1 FROM cuentas x WHERE x.numero_cuenta = '0000000002');

INSERT INTO parametros (clave, valor, descripcion) VALUES
  ('tienda.cuenta_comercios', '0000000002',
   'Cuenta tecnica donde se acumula lo que se debe a los comercios afiliados')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
