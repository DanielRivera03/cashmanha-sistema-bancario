-- ---------------------------------------------------------------------
-- 026 · La tienda sale al público
--
-- La tienda nació dentro de la banca en línea, y ahí no sirve de mucho:
-- para comprar había que ser cliente y haber iniciado sesión. Una tienda
-- con comercios afiliados tiene sentido justamente al revés —cualquiera
-- entra, ve el catálogo y compra—, y si además resulta ser cliente, mejor:
-- se le reconoce y puede pagar con la tarjeta que ya tiene.
--
-- Lo que cambia aquí:
--
--   · `usuario_id` deja de ser obligatorio en los pedidos. Un invitado
--     compra con sus datos de contacto y paga por la pasarela; no hay
--     motivo para obligarle a abrir una cuenta antes de comprar una
--     camisa.
--
--   · Cada artículo tiene foto. Una tienda sin fotos no es una tienda,
--     es un listado de precios. Se guarda el nombre del archivo, no la
--     imagen: los archivos subidos viven FUERA del directorio público y
--     se sirven por una ruta propia, de modo que nada de lo que suba
--     alguien pueda ejecutarse como código.
--
--   · Se anota con qué se pagó. Un pedido pagado con tarjeta emitida
--     aquí y uno pagado por la pasarela se cobran distinto y se concilian
--     distinto; mezclarlos obligaría a adivinar.
-- ---------------------------------------------------------------------

-- --- Las fotos -------------------------------------------------------
ALTER TABLE articulos
  ADD COLUMN imagen VARCHAR(190) NULL
      COMMENT 'Archivo subido (fuera del webroot) o ruta de un SVG de assets'
      AFTER descripcion,
  ADD COLUMN imagen_alt VARCHAR(190) NULL
      COMMENT 'Texto alternativo, para quien navega con lector de pantalla'
      AFTER imagen;

-- --- Pedidos de invitados --------------------------------------------
ALTER TABLE tienda_pedidos
  MODIFY COLUMN usuario_id INT UNSIGNED NULL
      COMMENT 'Nulo cuando compra un invitado sin cuenta';

ALTER TABLE tienda_pedidos
  ADD COLUMN medio_pago ENUM('tarjeta_emitida','pasarela') NOT NULL DEFAULT 'tarjeta_emitida'
      COMMENT 'Con que se cobro: una tarjeta de la casa o la pasarela'
      AFTER tarjeta_id,
  ADD COLUMN pago_pasarela_id BIGINT UNSIGNED NULL
      COMMENT 'El cobro en la pasarela que respalda este pedido'
      AFTER movimiento_id,

  -- Los datos de quien compra sin tener cuenta. Son los mínimos para
  -- poder entregar el pedido y avisar de su estado.
  ADD COLUMN invitado_nombre   VARCHAR(140) NULL AFTER direccion,
  ADD COLUMN invitado_correo   VARCHAR(190) NULL AFTER invitado_nombre,
  ADD COLUMN invitado_telefono VARCHAR(30)  NULL AFTER invitado_correo,
  ADD COLUMN invitado_documento VARCHAR(20) NULL AFTER invitado_telefono,

  -- Clave de seguimiento: con el folio solo no basta para dejar que
  -- cualquiera consulte un pedido ajeno. Se exige folio + clave.
  ADD COLUMN clave_consulta CHAR(12) NULL AFTER invitado_documento;

ALTER TABLE tienda_pedidos
  ADD KEY ix_pedidos_invitado (invitado_correo),
  ADD CONSTRAINT fk_pedidos_pasarela
      FOREIGN KEY (pago_pasarela_id) REFERENCES pagos_pasarela (id);

-- --- La pasarela acepta compras de la tienda -------------------------
ALTER TABLE pagos_pasarela
  MODIFY COLUMN concepto ENUM('cuota_credito','saldo_tarjeta','deposito','tienda') NOT NULL;

-- El cobro de un invitado no tiene usuario: la pasarela tiene que
-- admitirlo igual que lo admite el pedido.
ALTER TABLE pagos_pasarela
  MODIFY COLUMN usuario_id INT UNSIGNED NULL
      COMMENT 'Nulo en los cobros de invitados de la tienda';

ALTER TABLE pagos_pasarela
  ADD COLUMN pedido_id BIGINT UNSIGNED NULL
      COMMENT 'El pedido de la tienda que origino el cobro'
      AFTER cuenta_destino_id;

-- --- Parámetros -------------------------------------------------------
INSERT INTO parametros (clave, valor, descripcion) VALUES
  ('tienda.publica', '1',
   'Si el catalogo de la tienda se muestra a cualquier visitante'),
  ('tienda.compra_invitados', '1',
   'Si se admite comprar sin tener cuenta, pagando por la pasarela'),
  ('tienda.max_unidades_articulo', '20',
   'Tope de unidades del mismo articulo en un pedido')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
