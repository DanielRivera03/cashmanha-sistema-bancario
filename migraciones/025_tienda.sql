-- ---------------------------------------------------------------------
-- 025 · Tienda en línea: comercios afiliados y compras con tarjeta
--
-- Hasta ahora las tarjetas se emitían y no se podían usar: la única
-- forma de que una tarjeta tuviera saldo era «simular consumo» desde la
-- pantalla de administración, que sirve para probar pero no se parece
-- en nada a comprar. Sin compras de verdad no hay estado de cuenta que
-- revisar, ni compras a cuotas que diferir, ni corte que cuadrar.
--
-- Con esto el cliente entra a la tienda, elige artículos de un comercio
-- afiliado, paga con una tarjeta emitida aquí y el consumo aparece en
-- su tarjeta como cualquier otro: con su comercio, su categoría, su
-- referencia y la posibilidad de diferirlo a cuotas.
--
-- Sobre los nombres: los comercios son de CashMan H.A., no copias de
-- cadenas reales. Reproducir el nombre y el logo de un comercio ajeno
-- en un sistema que no es suyo es usar una marca que no nos pertenece,
-- y para probar la funcionalidad da exactamente igual que el almacén se
-- llame de una forma o de otra. Las categorías sí son las reales del
-- comercio salvadoreño, que es lo que importa para el ciclo.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS comercios (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo      VARCHAR(30)  NOT NULL,
    nombre      VARCHAR(120) NOT NULL,
    rubro       VARCHAR(60)  NOT NULL,
    -- La categoría con la que entra el consumo a la tarjeta.
    categoria   VARCHAR(40)  NOT NULL DEFAULT 'varios',
    descripcion VARCHAR(400) NULL,

    -- El monograma: dos letras y un color. Se dibuja con CSS en lugar de
    -- guardar imágenes, así el catálogo no depende de archivos que
    -- alguien tenga que subir ni de enlaces que se rompan.
    iniciales   CHAR(2)      NOT NULL DEFAULT 'CM',
    color       CHAR(7)      NOT NULL DEFAULT '#6418c3',

    -- Meses sin intereses que ofrece el comercio. Es lo que de verdad
    -- diferencia a un almacén de otro a la hora de comprar.
    cuotas_sin_intereses TINYINT UNSIGNED NOT NULL DEFAULT 0,
    envio_cent  BIGINT UNSIGNED NOT NULL DEFAULT 0,

    activo      TINYINT(1)   NOT NULL DEFAULT 1,
    orden       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_comercios_codigo (codigo),
    KEY ix_comercios_activo (activo, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS articulos (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    comercio_id  SMALLINT UNSIGNED NOT NULL,
    sku          VARCHAR(40)  NOT NULL,
    nombre       VARCHAR(160) NOT NULL,
    descripcion  VARCHAR(500) NULL,
    marca        VARCHAR(60)  NULL,
    categoria    VARCHAR(60)  NOT NULL DEFAULT 'general',

    precio_cent  BIGINT UNSIGNED NOT NULL,
    -- Precio tachado, para las ofertas. En cero no hay oferta.
    antes_cent   BIGINT UNSIGNED NOT NULL DEFAULT 0,

    existencias  INT UNSIGNED NOT NULL DEFAULT 0,
    activo       TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_articulos_sku (sku),
    KEY ix_articulos_comercio (comercio_id, activo),
    KEY ix_articulos_categoria (categoria, activo),
    CONSTRAINT fk_articulos_comercio FOREIGN KEY (comercio_id) REFERENCES comercios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tienda_pedidos (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid          CHAR(36)     NOT NULL,
    folio         VARCHAR(24)  NOT NULL,

    usuario_id    INT UNSIGNED NOT NULL,
    comercio_id   SMALLINT UNSIGNED NOT NULL,
    tarjeta_id    INT UNSIGNED NULL,

    subtotal_cent BIGINT UNSIGNED NOT NULL DEFAULT 0,
    envio_cent    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_cent    BIGINT UNSIGNED NOT NULL DEFAULT 0,

    -- Si se compró a cuotas, cuántas. Cero es de contado.
    cuotas        TINYINT UNSIGNED NOT NULL DEFAULT 0,

    -- El movimiento que generó en la tarjeta: es lo que ata la compra
    -- al estado de cuenta.
    movimiento_id BIGINT UNSIGNED NULL,
    referencia    VARCHAR(40)  NOT NULL,

    estado        ENUM('pagado','preparando','enviado','entregado','anulado')
                  NOT NULL DEFAULT 'pagado',

    direccion     VARCHAR(400) NULL,
    creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME    NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_pedidos_uuid (uuid),
    UNIQUE KEY uq_pedidos_folio (folio),
    UNIQUE KEY uq_pedidos_referencia (referencia),
    KEY ix_pedidos_usuario (usuario_id, id),
    CONSTRAINT fk_pedidos_usuario  FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT fk_pedidos_comercio FOREIGN KEY (comercio_id) REFERENCES comercios (id),
    CONSTRAINT fk_pedidos_tarjeta  FOREIGN KEY (tarjeta_id) REFERENCES tarjetas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tienda_pedido_lineas (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pedido_id   BIGINT UNSIGNED NOT NULL,
    articulo_id INT UNSIGNED NOT NULL,
    -- El nombre y el precio se copian a propósito: el catálogo cambia y
    -- un pedido tiene que seguir diciendo qué se compró y a cuánto.
    nombre      VARCHAR(160) NOT NULL,
    precio_cent BIGINT UNSIGNED NOT NULL,
    cantidad    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY ix_lineas_pedido (pedido_id),
    CONSTRAINT fk_lineas_pedido   FOREIGN KEY (pedido_id) REFERENCES tienda_pedidos (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_lineas_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Permisos
-- ---------------------------------------------------------------------
INSERT INTO permisos (clave, descripcion) VALUES
  ('tienda.comprar',  'Comprar en la tienda con una tarjeta emitida'),
  ('tienda.gestionar','Administrar comercios afiliados y su catalogo'),
  ('tienda.ver_pedidos', 'Consultar los pedidos de cualquier cliente')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.clave = 'tienda.comprar'
 WHERE r.clave IN ('cliente', 'administrador', 'presidencia', 'gerencia')
ON DUPLICATE KEY UPDATE rol_id = rol_permiso.rol_id;

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r
  JOIN permisos p ON p.clave IN ('tienda.gestionar', 'tienda.ver_pedidos')
 WHERE r.clave IN ('administrador', 'presidencia', 'gerencia')
ON DUPLICATE KEY UPDATE rol_id = rol_permiso.rol_id;

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.clave = 'tienda.ver_pedidos'
 WHERE r.clave IN ('atencionclientes', 'cajero')
ON DUPLICATE KEY UPDATE rol_id = rol_permiso.rol_id;

-- ---------------------------------------------------------------------
-- Los comercios afiliados
-- ---------------------------------------------------------------------
INSERT INTO comercios (codigo, nombre, rubro, categoria, descripcion, iniciales, color, cuotas_sin_intereses, envio_cent, orden) VALUES
('ALM-MIRASOL', 'Almacenes Mirasol', 'Tienda por departamentos', 'departamental',
 'Ropa, calzado, perfumeria, hogar y juguetes. El almacen de toda la familia, con doce meses sin intereses en compras desde $100.',
 'AM', '#6418c3', 12, 0, 10),

('CASA-LEMPA', 'Casa Lempa Electrodomesticos', 'Linea blanca y electronica', 'electronica',
 'Refrigeradoras, cocinas, lavadoras, televisores y aire acondicionado, con instalacion incluida en el area metropolitana.',
 'CL', '#b42318', 18, 2500, 20),

('SUPER-IZALCO', 'Super Izalco', 'Supermercado', 'supermercado',
 'La despensa completa: abarrotes, carnes, frutas y verduras, limpieza y cuidado personal. Entrega el mismo dia.',
 'SI', '#0f7a49', 0, 350, 30),

('TEC-CUSCATLAN', 'TecnoCuscatlan', 'Computacion y celulares', 'tecnologia',
 'Telefonos, computadoras, tabletas, consolas y accesorios, con garantia local de un ano.',
 'TC', '#1570ef', 15, 0, 40),

('FARM-SAN-JORGE', 'Farmacias San Jorge', 'Farmacia', 'farmacia',
 'Medicamentos de marca y genericos, cuidado del bebe, vitaminas y equipo medico basico.',
 'SJ', '#039855', 0, 200, 50),

('MUEBLES-CONCHAGUA', 'Muebles Conchagua', 'Muebleria', 'hogar',
 'Salas, comedores, dormitorios y colchones fabricados en el pais, con entrega y armado.',
 'MC', '#b54708', 24, 3500, 60),

('DEPOR-ILOPANGO', 'Deportes Ilopango', 'Articulos deportivos', 'deportes',
 'Calzado y ropa deportiva, bicicletas, equipo de gimnasio y suplementos.',
 'DI', '#7a5af8', 10, 500, 70),

('LIB-CUZCATLAN', 'Libreria Cuzcatlan', 'Libreria y papeleria', 'libreria',
 'Utiles escolares, libros de texto, papeleria de oficina y manualidades.',
 'LC', '#dd2590', 0, 250, 80)

ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion);

-- ---------------------------------------------------------------------
-- El catálogo
-- ---------------------------------------------------------------------
INSERT INTO articulos (comercio_id, sku, nombre, descripcion, marca, categoria, precio_cent, antes_cent, existencias) VALUES

-- Almacenes Mirasol
((SELECT id FROM comercios WHERE codigo='ALM-MIRASOL'), 'MIR-0001', 'Camisa formal de vestir manga larga', 'Algodon, corte clasico, disponible en blanco, celeste y gris.', 'Linea Mirasol', 'ropa', 2499, 3299, 120),
((SELECT id FROM comercios WHERE codigo='ALM-MIRASOL'), 'MIR-0002', 'Pantalon de vestir corte recto', 'Tela con elastano, no requiere planchado.', 'Linea Mirasol', 'ropa', 3299, 0, 90),
((SELECT id FROM comercios WHERE codigo='ALM-MIRASOL'), 'MIR-0003', 'Zapato de cuero para caballero', 'Suela de goma antideslizante, horma comoda.', 'Paso Firme', 'calzado', 5999, 7499, 45),
((SELECT id FROM comercios WHERE codigo='ALM-MIRASOL'), 'MIR-0004', 'Juego de sabanas matrimonial 400 hilos', 'Cuatro piezas, algodon peinado.', 'Casa Mirasol', 'hogar', 4250, 0, 60),
((SELECT id FROM comercios WHERE codigo='ALM-MIRASOL'), 'MIR-0005', 'Maleta de viaje rigida 24 pulgadas', 'Cuatro ruedas giratorias y candado integrado.', 'Rumbo', 'equipaje', 8999, 11999, 25),
((SELECT id FROM comercios WHERE codigo='ALM-MIRASOL'), 'MIR-0006', 'Perfume floral 100 ml', 'Fragancia de larga duracion.', 'Azahar', 'perfumeria', 4599, 0, 70),

-- Casa Lempa
((SELECT id FROM comercios WHERE codigo='CASA-LEMPA'), 'LEM-0001', 'Refrigeradora de 14 pies con dispensador', 'Dos puertas, congelador superior, bajo consumo.', 'Nortec', 'linea_blanca', 79900, 94900, 14),
((SELECT id FROM comercios WHERE codigo='CASA-LEMPA'), 'LEM-0002', 'Lavadora automatica de 18 libras', 'Doce ciclos, tapa de vidrio templado.', 'Nortec', 'linea_blanca', 44900, 0, 20),
((SELECT id FROM comercios WHERE codigo='CASA-LEMPA'), 'LEM-0003', 'Cocina de 30 pulgadas con horno', 'Cuatro quemadores y encendido electronico.', 'Nortec', 'linea_blanca', 52900, 59900, 12),
((SELECT id FROM comercios WHERE codigo='CASA-LEMPA'), 'LEM-0004', 'Televisor LED 55 pulgadas 4K', 'Sistema inteligente y tres entradas HDMI.', 'Vistavision', 'television', 48900, 0, 18),
((SELECT id FROM comercios WHERE codigo='CASA-LEMPA'), 'LEM-0005', 'Aire acondicionado minisplit 12000 BTU', 'Inverter, incluye instalacion basica.', 'Brisa', 'climatizacion', 64900, 72900, 10),
((SELECT id FROM comercios WHERE codigo='CASA-LEMPA'), 'LEM-0006', 'Microondas de 0.9 pies', 'Diez niveles de potencia.', 'Nortec', 'cocina', 11900, 0, 35),

-- Super Izalco
((SELECT id FROM comercios WHERE codigo='SUPER-IZALCO'), 'IZA-0001', 'Canasta basica familiar', 'Arroz, frijol, azucar, aceite, maseca, cafe y pastas para el mes.', 'Super Izalco', 'abarrotes', 4875, 0, 200),
((SELECT id FROM comercios WHERE codigo='SUPER-IZALCO'), 'IZA-0002', 'Carne de res molida, libra', 'Molida del dia, empacada al vacio.', 'Carniceria Izalco', 'carnes', 425, 0, 300),
((SELECT id FROM comercios WHERE codigo='SUPER-IZALCO'), 'IZA-0003', 'Cafe molido de altura, libra', 'Tueste medio, cosecha nacional.', 'Cumbres', 'abarrotes', 575, 699, 150),
((SELECT id FROM comercios WHERE codigo='SUPER-IZALCO'), 'IZA-0004', 'Detergente en polvo 5 kg', 'Rinde hasta 50 lavadas.', 'Blanca', 'limpieza', 1299, 0, 120),
((SELECT id FROM comercios WHERE codigo='SUPER-IZALCO'), 'IZA-0005', 'Leche entera, caja de 12 litros', 'Larga vida, ultrapasteurizada.', 'Pradera', 'lacteos', 2340, 2640, 80),

-- TecnoCuscatlan
((SELECT id FROM comercios WHERE codigo='TEC-CUSCATLAN'), 'TEC-0001', 'Telefono inteligente 128 GB', 'Pantalla de 6.5 pulgadas, camara triple, bateria de larga duracion.', 'Nexo', 'celulares', 29900, 34900, 40),
((SELECT id FROM comercios WHERE codigo='TEC-CUSCATLAN'), 'TEC-0002', 'Laptop 15.6 pulgadas 16 GB RAM', 'Procesador de ultima generacion y disco solido de 512 GB.', 'Volcan', 'computacion', 74900, 0, 22),
((SELECT id FROM comercios WHERE codigo='TEC-CUSCATLAN'), 'TEC-0003', 'Tableta de 10 pulgadas', 'Ideal para estudio y lectura, incluye funda.', 'Nexo', 'computacion', 19900, 23900, 30),
((SELECT id FROM comercios WHERE codigo='TEC-CUSCATLAN'), 'TEC-0004', 'Audifonos inalambricos con cancelacion de ruido', 'Hasta 30 horas de reproduccion.', 'Sonido Claro', 'accesorios', 8900, 0, 55),
((SELECT id FROM comercios WHERE codigo='TEC-CUSCATLAN'), 'TEC-0005', 'Impresora multifuncional con tinta continua', 'Imprime, escanea y copia.', 'Volcan', 'oficina', 17900, 19900, 25),

-- Farmacias San Jorge
((SELECT id FROM comercios WHERE codigo='FARM-SAN-JORGE'), 'SJO-0001', 'Acetaminofen 500 mg, 50 tabletas', 'Analgesico y antipiretico.', 'Generico', 'medicamentos', 325, 0, 300),
((SELECT id FROM comercios WHERE codigo='FARM-SAN-JORGE'), 'SJO-0002', 'Multivitaminico, 60 capsulas', 'Complejo B, hierro y zinc.', 'Vitalis', 'vitaminas', 1250, 1550, 140),
((SELECT id FROM comercios WHERE codigo='FARM-SAN-JORGE'), 'SJO-0003', 'Panales etapa 3, paquete de 60', 'Hipoalergenicos, con banda elastica.', 'Nubecita', 'bebe', 1899, 0, 90),
((SELECT id FROM comercios WHERE codigo='FARM-SAN-JORGE'), 'SJO-0004', 'Tensiometro digital de brazo', 'Memoria para 90 mediciones.', 'MediCheck', 'equipo', 4499, 4999, 35),

-- Muebles Conchagua
((SELECT id FROM comercios WHERE codigo='MUEBLES-CONCHAGUA'), 'CON-0001', 'Juego de sala de tres piezas', 'Sofa de tres puestos, dos sillones y mesa de centro.', 'Conchagua', 'sala', 89900, 109900, 8),
((SELECT id FROM comercios WHERE codigo='MUEBLES-CONCHAGUA'), 'CON-0002', 'Comedor de seis sillas', 'Mesa de madera solida con vidrio templado.', 'Conchagua', 'comedor', 64900, 0, 10),
((SELECT id FROM comercios WHERE codigo='MUEBLES-CONCHAGUA'), 'CON-0003', 'Colchon ortopedico matrimonial', 'Resortes embolsados y pillow top.', 'Descanso Real', 'dormitorio', 39900, 47900, 15),
((SELECT id FROM comercios WHERE codigo='MUEBLES-CONCHAGUA'), 'CON-0004', 'Ropero de tres puertas con espejo', 'Incluye cajonera y barra para colgar.', 'Conchagua', 'dormitorio', 42900, 0, 12),

-- Deportes Ilopango
((SELECT id FROM comercios WHERE codigo='DEPOR-ILOPANGO'), 'ILO-0001', 'Zapatillas para correr', 'Amortiguacion de gel y malla transpirable.', 'Ritmo', 'calzado', 6999, 8499, 60),
((SELECT id FROM comercios WHERE codigo='DEPOR-ILOPANGO'), 'ILO-0002', 'Bicicleta de montana rodado 29', 'Veintiun cambios y frenos de disco.', 'Cumbre', 'ciclismo', 32900, 38900, 16),
((SELECT id FROM comercios WHERE codigo='DEPOR-ILOPANGO'), 'ILO-0003', 'Juego de mancuernas 40 libras', 'Discos recubiertos y barra roscada.', 'Fuerza', 'gimnasio', 8900, 0, 28),
((SELECT id FROM comercios WHERE codigo='DEPOR-ILOPANGO'), 'ILO-0004', 'Balon de futbol numero 5', 'Cosido a mano, uso profesional.', 'Ritmo', 'futbol', 2499, 2999, 80),

-- Libreria Cuzcatlan
((SELECT id FROM comercios WHERE codigo='LIB-CUZCATLAN'), 'CUZ-0001', 'Paquete escolar completo', 'Cuadernos, lapices, colores, reglas y mochila.', 'Cuzcatlan', 'escolar', 4999, 5999, 110),
((SELECT id FROM comercios WHERE codigo='LIB-CUZCATLAN'), 'CUZ-0002', 'Resma de papel bond carta', 'Quinientas hojas, 75 gramos.', 'Oficina', 'papeleria', 575, 0, 200),
((SELECT id FROM comercios WHERE codigo='LIB-CUZCATLAN'), 'CUZ-0003', 'Calculadora cientifica', 'Doscientas cuarenta funciones.', 'Exacta', 'escolar', 1899, 2299, 65),
((SELECT id FROM comercios WHERE codigo='LIB-CUZCATLAN'), 'CUZ-0004', 'Silla ergonomica de oficina', 'Respaldo de malla y altura regulable.', 'Oficina', 'mobiliario', 14900, 17900, 18)

ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), precio_cent = VALUES(precio_cent);
