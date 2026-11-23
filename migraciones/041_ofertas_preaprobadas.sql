-- ---------------------------------------------------------------------
-- 041 · Ofertas preaprobadas
--
-- Un cliente que lleva anios pagando a tiempo tenia que pedir un
-- credito desde cero: llenar la solicitud, esperar la revision y que
-- alguien evaluara otra vez lo que su propio historial ya demuestra.
--
-- La oferta preaprobada invierte el orden. La institucion evalua la
-- cartera, decide a quien ofrece y cuanto, y se lo manda. El cliente
-- solo acepta, y en ese momento se le otorga.
--
-- Dos cosas que esta tabla tiene que sostener, y por eso guarda lo que
-- guarda:
--
--   POR QUE SE OFRECIO   `criterios_json` deja por escrito con que
--                        numeros se decidio: cuotas pagadas, mora
--                        maxima, antiguedad. Una preaprobacion sin
--                        rastro de su propio criterio no se puede
--                        auditar ni defender.
--
--   CON QUE CAPACIDAD    `ingreso_cent`, `compromisos_cent` y
--                        `capacidad_cent` son los del dia en que se
--                        genero. Al aceptar se vuelven a calcular: una
--                        oferta de hace tres semanas no puede otorgar
--                        hoy un credito que el cliente ya no aguanta.
--                        Guardar los de entonces permite comparar y
--                        explicar por que cambio.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ofertas_preaprobadas (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid             CHAR(36)     NOT NULL,
    folio            VARCHAR(24)  NOT NULL,
    usuario_id       INT UNSIGNED NOT NULL,

    tipo             ENUM('credito','tarjeta') NOT NULL,
    producto_id      SMALLINT UNSIGNED NULL,

    -- Credito: el monto ofrecido. Tarjeta: la linea ofrecida.
    monto_cent       BIGINT       NOT NULL DEFAULT 0,
    plazo_meses      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tasa_bp          INT UNSIGNED NOT NULL DEFAULT 0,
    cuota_cent       BIGINT       NOT NULL DEFAULT 0,

    -- La foto de la capacidad el dia de la oferta.
    ingreso_cent     BIGINT       NOT NULL DEFAULT 0,
    compromisos_cent BIGINT       NOT NULL DEFAULT 0,
    capacidad_cent   BIGINT       NOT NULL DEFAULT 0,

    motivo           VARCHAR(255) NOT NULL DEFAULT '',
    criterios_json   JSON         NULL,

    estado           ENUM('vigente','aceptada','rechazada','caducada','anulada')
                     NOT NULL DEFAULT 'vigente',
    vence_el         DATE         NOT NULL,

    enviada_en       DATETIME     NULL,
    aceptada_en      DATETIME     NULL,
    cerrada_motivo   VARCHAR(255) NOT NULL DEFAULT '',

    credito_id       INT UNSIGNED NULL,
    tarjeta_id       INT UNSIGNED NULL,

    creada_por       INT UNSIGNED NULL,
    creada_en        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_oferta_uuid  (uuid),
    UNIQUE KEY uq_oferta_folio (folio),
    KEY ix_oferta_cliente (usuario_id, estado),
    KEY ix_oferta_vence   (estado, vence_el),

    CONSTRAINT fk_oferta_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id),
    CONSTRAINT fk_oferta_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
    CONSTRAINT fk_oferta_credito  FOREIGN KEY (credito_id)  REFERENCES creditos(id),
    CONSTRAINT fk_oferta_tarjeta  FOREIGN KEY (tarjeta_id)  REFERENCES tarjetas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Los criterios de «buena paga», como parametros ------------------
--
-- Van en parametros y no en el codigo porque son politica de credito:
-- la cambia quien dirige la cartera, no quien programa.

INSERT INTO parametros (clave, valor, descripcion) VALUES
  ('oferta.vigencia_dias', '30',
   'Dias que una oferta preaprobada queda vigente antes de caducar.'),
  ('oferta.min_cuotas_pagadas', '6',
   'Cuotas pagadas que un cliente debe acumular para calificar a una oferta preaprobada.'),
  ('oferta.max_dias_mora', '5',
   'Dias de atraso maximos tolerados en el historial para seguir considerandose buena paga.'),
  ('oferta.max_vigentes', '2',
   'Ofertas preaprobadas que un cliente puede tener vigentes a la vez.'),
  ('oferta.plazo_credito', '36',
   'Plazo en meses con el que se arma la oferta de credito preaprobado.'),
  ('oferta.margen_bp', '8000',
   'Porcion de la capacidad de pago que se ofrece, en puntos base. 8000 = 80%.')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- --- Permisos --------------------------------------------------------
INSERT INTO permisos (clave, descripcion) VALUES
  ('oferta.ver',     'Ver las ofertas preaprobadas de la cartera'),
  ('oferta.generar', 'Evaluar la cartera y generar ofertas preaprobadas'),
  ('oferta.anular',  'Anular una oferta preaprobada antes de que se acepte')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- Presidencia, Gerencia y Atencion a Clientes gestionan la campania.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave IN ('oferta.ver','oferta.generar','oferta.anular')
 WHERE r.nombre IN ('Administrador','Presidencia','Gerencia')
ON DUPLICATE KEY UPDATE rol_id = rol_id;

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'oferta.ver'
 WHERE r.nombre = 'Atención a Clientes'
ON DUPLICATE KEY UPDATE rol_id = rol_id;
