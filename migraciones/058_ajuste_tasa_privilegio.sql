-- ---------------------------------------------------------------------
-- El ajuste de tasa por categoría, con su constancia.
--
-- Un cliente que asciende a banca preferencial recibe, entre otras
-- cosas, un descuento en la tasa. Hasta ahora eso solo alcanzaba a lo
-- que contratara DESPUÉS: sus créditos vigentes seguían con la tasa
-- con la que nacieron, y la pantalla le prometía «se descuentan de la
-- tasa de cualquier crédito que se le otorgue» mientras pagaba la de
-- catálogo en el que ya tenía.
--
-- Bajarle la tasa a un crédito vivo cambia su cuota y su tabla de
-- amortización. Eso NO puede pasar sin dejar constancia: el cliente
-- tiene que poder saber por qué su cuota cambió, y la institución
-- tiene que poder enseñar quién lo autorizó y con qué regla.
--
-- No es una reestructuración. Una reestructuración se pide, se aprueba
-- y suele alargar el plazo porque el cliente no puede pagar; esto es un
-- beneficio que se aplica solo, mantiene el plazo intacto y solo puede
-- bajar la cuota. Mezclarlos en la misma tabla acabaría con un informe
-- de cartera reestructurada que incluye clientes que nunca pidieron
-- nada y que están al día.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS credito_ajustes_tasa (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  credito_id     INT UNSIGNED NOT NULL,

  tasa_anterior_bp SMALLINT UNSIGNED NOT NULL,
  tasa_nueva_bp    SMALLINT UNSIGNED NOT NULL,

  cuota_anterior_cent BIGINT NOT NULL,
  cuota_nueva_cent    BIGINT NOT NULL,

  -- Cuánto deja de pagar en total por lo que le queda. Es la cifra por
  -- la que pregunta el cliente, y calcularla dos veces en dos sitios
  -- distintos es como acaban discrepando.
  ahorro_cent    BIGINT NOT NULL DEFAULT 0,

  -- Por qué le corresponde: el segmento y la versión de la regla que
  -- lo decidió. Sin la versión, dentro de un año nadie puede
  -- reconstruir con qué criterio se le aplicó.
  segmento       VARCHAR(16)  NOT NULL,
  regla_version  VARCHAR(16)  NOT NULL,
  descuento_bp   SMALLINT UNSIGNED NOT NULL,

  -- Quién lo corrió. NULL cuando lo aplica el proceso automático.
  actor_id       INT UNSIGNED NULL,

  cuotas_rehechas SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_ajuste_credito
    FOREIGN KEY (credito_id) REFERENCES creditos (id)
    ON DELETE CASCADE ON UPDATE CASCADE,

  CONSTRAINT fk_ajuste_actor
    FOREIGN KEY (actor_id) REFERENCES usuarios (id)
    ON DELETE SET NULL ON UPDATE CASCADE,

  INDEX ix_ajuste_credito (credito_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
