-- ---------------------------------------------------------------------
-- El segmento del cliente.
--
-- No es una etiqueta que alguien ponga a mano: sale de lo que el
-- cliente tiene depositado, la línea que maneja, su ingreso, su
-- antigüedad y su banda de riesgo. Se guarda aquí para que un listado
-- de mil clientes no tenga que recalcularlo mil veces, y con su fecha
-- para poder responder «¿desde cuándo soy preferente?».
--
-- El riesgo manda sobre el patrimonio: por mucho saldo que tenga, un
-- cliente con una cuota vencida no sube de segmento. Premiar el saldo
-- ignorando el comportamiento es cómo se construye una cartera que se
-- ve bien hasta que deja de verse.
-- ---------------------------------------------------------------------

ALTER TABLE usuarios
  ADD COLUMN segmento VARCHAR(16) NOT NULL DEFAULT 'estandar'
      COMMENT 'estandar, preferente o patrimonial: se deriva, no se asigna'
      AFTER estado,
  ADD COLUMN segmento_desde DATETIME NULL DEFAULT NULL
      COMMENT 'Desde cuándo está en este segmento'
      AFTER segmento,
  ADD KEY idx_usuario_segmento (segmento);
