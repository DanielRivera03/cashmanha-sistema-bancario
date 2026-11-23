-- ---------------------------------------------------------------------
-- 044 · Extrafinanciamiento sobre la tarjeta de credito
--
-- Es efectivo contra la linea de la tarjeta: se desembolsa en la cuenta
-- de ahorro del titular y se devuelve en cuotas fijas que aparecen en
-- el estado de cuenta de la tarjeta, mes a mes, hasta liquidarlo.
--
-- No es para todos, y por eso se preaprueba. Los criterios estan en
-- parametros, no en el codigo, porque son politica de credito: los
-- mueve quien dirige la cartera.
--
-- Por que cada criterio, que es lo que no se puede deducir de la tabla:
--
--   ANTIGUEDAD   Hacen falta cortes emitidos, no dias de vida. Una
--                tarjeta de tres meses sin usar no dice nada; tres
--                cortes con movimiento si.
--
--   SIN MORA     Ofrecer efectivo a quien viene pagando tarde es
--                convertir un atraso en una deuda mayor.
--
--   USO BAJO     Si la linea ya esta casi consumida, prestar mas es
--                empujar al cliente contra su propio techo. Se pide
--                margen: por debajo del 70 % de uso.
--
--   UNO A LA VEZ Dos extrafinanciamientos corriendo a la vez hacen que
--                el estado de cuenta deje de entenderse, y que la
--                cuota mensual crezca sin que nadie lo decida de una
--                sola vez.
--
--   CAPACIDAD    La cuota nueva tiene que caber en su capacidad de
--                pago REAL, contando lo que ya paga cada mes. Se vuelve
--                a comprobar al aceptar, no solo al ofrecer.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS tarjeta_extrafinanciamientos (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)     NOT NULL,
    folio          VARCHAR(24)  NOT NULL,
    tarjeta_id     INT UNSIGNED NOT NULL,
    usuario_id     INT UNSIGNED NOT NULL,
    cuenta_id      INT UNSIGNED NULL,            -- donde se desembolso

    monto_cent     BIGINT       NOT NULL,        -- lo entregado
    plazo_meses    SMALLINT UNSIGNED NOT NULL,
    tasa_bp        INT UNSIGNED NOT NULL,
    cuota_cent     BIGINT       NOT NULL,        -- cuota mensual fija
    interes_total_cent BIGINT   NOT NULL DEFAULT 0,
    total_cent     BIGINT       NOT NULL,        -- monto + intereses

    pagado_cent    BIGINT       NOT NULL DEFAULT 0,
    cuotas_pagadas SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    estado         ENUM('vigente','liquidado','anulado') NOT NULL DEFAULT 'vigente',

    oferta_id      INT UNSIGNED NULL,
    asiento_id     INT UNSIGNED NULL,

    otorgado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    liquidado_en   DATETIME     NULL,
    actualizado_en TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_extra_uuid  (uuid),
    UNIQUE KEY uq_extra_folio (folio),
    KEY ix_extra_tarjeta (tarjeta_id, estado),
    KEY ix_extra_usuario (usuario_id, estado),

    CONSTRAINT fk_extra_tarjeta FOREIGN KEY (tarjeta_id) REFERENCES tarjetas(id),
    CONSTRAINT fk_extra_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT fk_extra_cuenta  FOREIGN KEY (cuenta_id)  REFERENCES cuentas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El calendario, cuota por cuota: es lo que el estado de cuenta
-- desglosa en capital, intereses y saldo al corte.
CREATE TABLE IF NOT EXISTS tarjeta_extra_cuotas (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    extra_id      INT UNSIGNED NOT NULL,
    numero        SMALLINT UNSIGNED NOT NULL,
    capital_cent  BIGINT       NOT NULL,
    interes_cent  BIGINT       NOT NULL,
    total_cent    BIGINT       NOT NULL,
    saldo_posterior_cent BIGINT NOT NULL,
    fecha_corte   DATE         NOT NULL,
    cobrada_en    DATETIME     NULL,
    corte_id      INT UNSIGNED NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_extra_cuota (extra_id, numero),
    KEY ix_extra_cuota_fecha (extra_id, fecha_corte),

    CONSTRAINT fk_extra_cuota_extra FOREIGN KEY (extra_id)
        REFERENCES tarjeta_extrafinanciamientos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El corte guarda aparte lo que viene del extrafinanciamiento, para
-- poder desglosarlo en el estado de cuenta como hace la banca.
ALTER TABLE tarjeta_cortes
  ADD COLUMN extra_saldo_cent  BIGINT NOT NULL DEFAULT 0 AFTER mora_cent,
  ADD COLUMN extra_cuota_cent  BIGINT NOT NULL DEFAULT 0 AFTER extra_saldo_cent;

-- La oferta preaprobada admite este tercer tipo.
ALTER TABLE ofertas_preaprobadas
  MODIFY COLUMN tipo ENUM('credito','tarjeta','extrafinanciamiento') NOT NULL;

-- Sobre que tarjeta se hizo la oferta, cuando es de extrafinanciamiento.
ALTER TABLE ofertas_preaprobadas
  ADD COLUMN tarjeta_origen_id INT UNSIGNED NULL AFTER producto_id,
  ADD CONSTRAINT fk_oferta_tarjeta_origen
      FOREIGN KEY (tarjeta_origen_id) REFERENCES tarjetas(id);

-- --- Los criterios, como parametros -----------------------------------
INSERT INTO parametros (clave, valor, descripcion) VALUES
  ('extra.min_cortes', '3',
   'Cortes emitidos que la tarjeta debe acumular para calificar a extrafinanciamiento.'),
  ('extra.max_uso_bp', '7000',
   'Uso maximo de la linea para calificar, en puntos base. 7000 = 70%.'),
  ('extra.pct_linea_bp', '5000',
   'Porcion de la linea aprobada que se puede ofrecer como efectivo. 5000 = 50%.'),
  ('extra.plazo_meses', '12',
   'Plazo en meses del extrafinanciamiento preaprobado.'),
  ('extra.tasa_bp', '2400',
   'Tasa anual del extrafinanciamiento, en puntos base. 2400 = 24%.'),
  ('extra.monto_minimo_cent', '20000',
   'Monto minimo para que una oferta de extrafinanciamiento valga la pena.'),
  ('extra.max_vigentes', '1',
   'Extrafinanciamientos vigentes que puede tener una misma tarjeta.')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
