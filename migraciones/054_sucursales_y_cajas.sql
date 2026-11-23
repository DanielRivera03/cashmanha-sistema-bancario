-- ---------------------------------------------------------------------
-- Las sucursales, de verdad.
--
-- Hasta ahora la red de agencias vivía en dos sitios y ninguno era una
-- tabla: seis agencias escritas a mano dentro de la vista pública, y
-- una columna de texto libre `cajas.agencia` donde alguien había
-- tecleado «Oficina central» dos veces. La vista lo decía sin rodeos en
-- su cabecera: «las agencias están escritas aquí porque el sistema no
-- tiene tabla de sucursales».
--
-- Con dos cajas en una sola oficina el módulo de caja no se puede ni
-- probar: no hay dos agencias que comparar, ni un cierre que cuadrar
-- contra otro, ni forma de ver qué pasa cuando un cajero abre en una
-- ventanilla que no es la suya.
--
-- Esto crea la tabla, cuelga las cajas de ella, y puebla diez agencias
-- con sus ventanillas. Los usuarios cajeros NO se crean aquí: una
-- contraseña pasa por un pepper fuera de la base antes del hash, y un
-- INSERT de SQL no puede aplicarlo. Van en `bin/sembrar-cajeros.php`.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sucursales (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codigo        VARCHAR(12)  NOT NULL UNIQUE COMMENT 'SUC-01, SUC-02...',
  nombre        VARCHAR(90)  NOT NULL,
  direccion     VARCHAR(190) NOT NULL,
  departamento  VARCHAR(60)  NOT NULL,
  telefono      VARCHAR(20)  NULL,
  horario       VARCHAR(120) NOT NULL,

  -- Si tiene cajero automático en el local. Es lo primero que pregunta
  -- quien busca una agencia un domingo.
  tiene_atm     TINYINT(1)   NOT NULL DEFAULT 0,

  -- La central es donde se concentra la bóveda y a donde se remiten
  -- los excedentes de efectivo de las demás.
  es_central    TINYINT(1)   NOT NULL DEFAULT 0,

  activa        TINYINT(1)   NOT NULL DEFAULT 1,
  creada_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX ix_sucursales_activa (activa, departamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Las diez agencias -------------------------------------------------
--
-- Direcciones y horarios realistas de El Salvador. Las seis primeras
-- son las que ya anunciaba la vista pública, para que quien las haya
-- visto no vea desaparecer la suya.

INSERT INTO sucursales
  (codigo, nombre, direccion, departamento, telefono, horario, tiene_atm, es_central, activa)
VALUES
  ('SUC-01', 'Agencia Central',      'Calle Rubén Darío y 9ª Avenida Sur, Centro de San Salvador', 'San Salvador', '2222-0001', 'Lun a Vie 8:00–17:00 · Sáb 8:00–12:00', 1, 1, 1),
  ('SUC-02', 'Agencia Metrocentro',  'Centro Comercial Metrocentro, local 212, Bulevar de los Héroes', 'San Salvador', '2222-0002', 'Lun a Dom 9:00–19:00', 1, 0, 1),
  ('SUC-03', 'Agencia Santa Tecla',  'Paseo El Carmen, 2ª Avenida Norte', 'La Libertad', '2222-0003', 'Lun a Vie 8:00–16:30 · Sáb 8:00–12:00', 1, 0, 1),
  ('SUC-04', 'Agencia Santa Ana',    'Avenida Independencia Sur y 7ª Calle Poniente', 'Santa Ana', '2222-0004', 'Lun a Vie 8:00–16:30', 1, 0, 1),
  ('SUC-05', 'Agencia San Miguel',   'Avenida Roosevelt Sur, Barrio La Merced', 'San Miguel', '2222-0005', 'Lun a Vie 8:00–16:30', 1, 0, 1),
  ('SUC-06', 'Agencia Soyapango',    'Centro Comercial Plaza Mundo, etapa 2', 'San Salvador', '2222-0006', 'Lun a Dom 9:00–19:00', 1, 0, 1),
  ('SUC-07', 'Agencia Antiguo Cuscatlán', 'Multiplaza, Carretera Panamericana', 'La Libertad', '2222-0007', 'Lun a Dom 9:00–19:00', 1, 0, 1),
  ('SUC-08', 'Agencia Sonsonate',    '4ª Calle Oriente, Barrio El Ángel', 'Sonsonate', '2222-0008', 'Lun a Vie 8:00–16:00', 0, 0, 1),
  ('SUC-09', 'Agencia Usulután',     'Calle Grimaldi, Barrio La Parroquia', 'Usulután', '2222-0009', 'Lun a Vie 8:00–16:00', 0, 0, 1),
  ('SUC-10', 'Agencia Apopa',        'Bulevar del Ejército, kilómetro 12½', 'San Salvador', '2222-0010', 'Lun a Vie 8:00–16:30 · Sáb 8:00–12:00', 1, 0, 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- --- Las cajas, colgadas de su sucursal --------------------------------

ALTER TABLE cajas
  ADD COLUMN sucursal_id SMALLINT UNSIGNED NULL DEFAULT NULL AFTER nombre;

-- Las dos que ya existían decían «Oficina central» en texto libre.
UPDATE cajas c
   JOIN sucursales s ON s.codigo = 'SUC-01'
   SET c.sucursal_id = s.id
 WHERE c.sucursal_id IS NULL;

ALTER TABLE cajas
  ADD CONSTRAINT fk_cajas_sucursal
      FOREIGN KEY (sucursal_id) REFERENCES sucursales (id)
      ON DELETE RESTRICT ON UPDATE CASCADE;

CREATE INDEX ix_cajas_sucursal ON cajas (sucursal_id, activa);

-- --- Las ventanillas ---------------------------------------------------
--
-- Cuántas tiene cada agencia no es un número redondo: la central y las
-- de centro comercial mueven mucha más gente que una de cabecera
-- departamental, y poner tres en todas sería inventarse una red que no
-- se parece a ninguna.
--
-- El tope de efectivo también cambia: una ventanilla de la central
-- admite más acumulado antes de tener que remesar a bóveda que una de
-- Usulután.
--
-- El código es CJ-<sucursal>-<n>, único por construcción: las dos
-- originales se quedan con su CJ-01 y CJ-02 y no chocan con estas.

INSERT INTO cajas (codigo, nombre, sucursal_id, agencia, tope_efectivo_cent, activa, creada_en)
SELECT CONCAT('CJ-', SUBSTRING(s.codigo, 5), '-', n.i),
       CONCAT('Ventanilla ', n.i),
       s.id,
       s.nombre,
       CASE WHEN s.es_central = 1 THEN 5000000
            WHEN s.tiene_atm  = 1 THEN 3000000
            ELSE 2000000 END,
       1,
       NOW()
  FROM sucursales s
  JOIN (SELECT 1 AS i UNION SELECT 2 UNION SELECT 3 UNION SELECT 4) n
    ON n.i <= CASE
                WHEN s.es_central = 1 THEN 4   -- la central
                WHEN s.codigo IN ('SUC-02','SUC-07') THEN 3   -- centros comerciales
                WHEN s.codigo IN ('SUC-04','SUC-05','SUC-06') THEN 3   -- cabeceras grandes
                ELSE 2
              END
 WHERE NOT EXISTS (
         SELECT 1 FROM cajas c
          WHERE c.codigo = CONCAT('CJ-', SUBSTRING(s.codigo, 5), '-', n.i)
       );

-- Las dos originales quedan con el nombre de su sucursal en el campo
-- de texto, que se conserva por compatibilidad con lo que ya consulta
-- por ahí; la relación de verdad es `sucursal_id`.
UPDATE cajas c JOIN sucursales s ON s.id = c.sucursal_id
   SET c.agencia = s.nombre
 WHERE c.agencia <> s.nombre;
