-- ---------------------------------------------------------------------
-- 030 · Los nombres de la tienda, bien escritos
--
-- El catálogo se insertó sin tildes para no pelear con la codificación
-- del cliente de MySQL en la línea de comandos, y el resultado está a la
-- vista en la tienda: «Casa Lempa Electrodomesticos», «Super Izalco» con
-- «Supermercado» bien pero «Libreria y papeleria» mal, «Computacion y
-- celulares», «Articulos deportivos».
--
-- En una tienda de cara al público eso se lee como descuido, porque lo
-- es. Las tildes se ponen aquí con el juego de caracteres declarado, que
-- es como había que haberlo hecho desde el principio.
-- ---------------------------------------------------------------------

SET NAMES utf8mb4;

-- --- Comercios --------------------------------------------------------
UPDATE comercios SET
    nombre = 'Casa Lempa Electrodomésticos',
    rubro  = 'Línea blanca y electrónica',
    descripcion = 'Refrigeradoras, cocinas, lavadoras, televisores y aire acondicionado, con instalación incluida en el área metropolitana.'
  WHERE codigo = 'CASA-LEMPA';

UPDATE comercios SET
    nombre = 'TecnoCuscatlán',
    rubro  = 'Computación y celulares',
    descripcion = 'Teléfonos, computadoras, tabletas, consolas y accesorios, con garantía local de un año.'
  WHERE codigo = 'TEC-CUSCATLAN';

UPDATE comercios SET
    rubro  = 'Mueblería',
    descripcion = 'Salas, comedores, dormitorios y colchones fabricados en el país, con entrega y armado.'
  WHERE codigo = 'MUEBLES-CONCHAGUA';

UPDATE comercios SET
    rubro  = 'Artículos deportivos',
    descripcion = 'Calzado y ropa deportiva, bicicletas, equipo de gimnasio y suplementos.'
  WHERE codigo = 'DEPOR-ILOPANGO';

UPDATE comercios SET
    nombre = 'Librería Cuzcatlán',
    rubro  = 'Librería y papelería',
    descripcion = 'Útiles escolares, libros de texto, papelería de oficina y manualidades.'
  WHERE codigo = 'LIB-CUZCATLAN';

UPDATE comercios SET
    descripcion = 'Ropa, calzado, perfumería, hogar y juguetes. El almacén de toda la familia, con doce meses sin intereses en compras desde $100.'
  WHERE codigo = 'ALM-MIRASOL';

UPDATE comercios SET
    descripcion = 'La despensa completa: abarrotes, carnes, frutas y verduras, limpieza y cuidado personal. Entrega el mismo día.'
  WHERE codigo = 'SUPER-IZALCO';

UPDATE comercios SET
    descripcion = 'Medicamentos de marca y genéricos, cuidado del bebé, vitaminas y equipo médico básico.'
  WHERE codigo = 'FARM-SAN-JORGE';

-- --- Artículos --------------------------------------------------------
UPDATE articulos SET nombre = 'Zapato de cuero para caballero',
    descripcion = 'Suela de goma antideslizante, horma cómoda.' WHERE sku = 'MIR-0003';
UPDATE articulos SET nombre = 'Juego de sábanas matrimonial 400 hilos',
    descripcion = 'Cuatro piezas, algodón peinado.' WHERE sku = 'MIR-0004';
UPDATE articulos SET descripcion = 'Cuatro ruedas giratorias y candado integrado.' WHERE sku = 'MIR-0005';
UPDATE articulos SET descripcion = 'Fragancia de larga duración.' WHERE sku = 'MIR-0006';
UPDATE articulos SET descripcion = 'Algodón, corte clásico, disponible en blanco, celeste y gris.' WHERE sku = 'MIR-0001';
UPDATE articulos SET descripcion = 'Tela con elastano, no requiere planchado.' WHERE sku = 'MIR-0002';

UPDATE articulos SET descripcion = 'Dos puertas, congelador superior, bajo consumo.' WHERE sku = 'LEM-0001';
UPDATE articulos SET nombre = 'Lavadora automática de 18 libras',
    descripcion = 'Doce ciclos, tapa de vidrio templado.' WHERE sku = 'LEM-0002';
UPDATE articulos SET descripcion = 'Cuatro quemadores y encendido electrónico.' WHERE sku = 'LEM-0003';
UPDATE articulos SET descripcion = 'Sistema inteligente y tres entradas HDMI.' WHERE sku = 'LEM-0004';
UPDATE articulos SET nombre = 'Aire acondicionado minisplit 12,000 BTU',
    descripcion = 'Inverter, incluye instalación básica.' WHERE sku = 'LEM-0005';
UPDATE articulos SET descripcion = 'Diez niveles de potencia.' WHERE sku = 'LEM-0006';

UPDATE articulos SET nombre = 'Canasta básica familiar',
    descripcion = 'Arroz, frijol, azúcar, aceite, maseca, café y pastas para el mes.' WHERE sku = 'IZA-0001';
UPDATE articulos SET descripcion = 'Molida del día, empacada al vacío.' WHERE sku = 'IZA-0002';
UPDATE articulos SET nombre = 'Café molido de altura, libra',
    descripcion = 'Tueste medio, cosecha nacional.' WHERE sku = 'IZA-0003';
UPDATE articulos SET descripcion = 'Rinde hasta 50 lavadas.' WHERE sku = 'IZA-0004';
UPDATE articulos SET descripcion = 'Larga vida, ultrapasteurizada.' WHERE sku = 'IZA-0005';

UPDATE articulos SET nombre = 'Teléfono inteligente 128 GB',
    descripcion = 'Pantalla de 6.5 pulgadas, cámara triple, batería de larga duración.' WHERE sku = 'TEC-0001';
UPDATE articulos SET descripcion = 'Procesador de última generación y disco sólido de 512 GB.' WHERE sku = 'TEC-0002';
UPDATE articulos SET descripcion = 'Ideal para estudio y lectura, incluye funda.' WHERE sku = 'TEC-0003';
UPDATE articulos SET nombre = 'Audífonos inalámbricos con cancelación de ruido',
    descripcion = 'Hasta 30 horas de reproducción.' WHERE sku = 'TEC-0004';
UPDATE articulos SET descripcion = 'Imprime, escanea y copia.' WHERE sku = 'TEC-0005';

UPDATE articulos SET nombre = 'Acetaminofén 500 mg, 50 tabletas',
    descripcion = 'Analgésico y antipirético.' WHERE sku = 'SJO-0001';
UPDATE articulos SET nombre = 'Multivitamínico, 60 cápsulas',
    descripcion = 'Complejo B, hierro y zinc.' WHERE sku = 'SJO-0002';
UPDATE articulos SET nombre = 'Pañales etapa 3, paquete de 60',
    descripcion = 'Hipoalergénicos, con banda elástica.' WHERE sku = 'SJO-0003';
UPDATE articulos SET nombre = 'Tensiómetro digital de brazo',
    descripcion = 'Memoria para 90 mediciones.' WHERE sku = 'SJO-0004';

UPDATE articulos SET descripcion = 'Sofá de tres puestos, dos sillones y mesa de centro.' WHERE sku = 'CON-0001';
UPDATE articulos SET descripcion = 'Mesa de madera sólida con vidrio templado.' WHERE sku = 'CON-0002';
UPDATE articulos SET nombre = 'Colchón ortopédico matrimonial',
    descripcion = 'Resortes embolsados y pillow top.' WHERE sku = 'CON-0003';
UPDATE articulos SET descripcion = 'Incluye cajonera y barra para colgar.' WHERE sku = 'CON-0004';

UPDATE articulos SET descripcion = 'Amortiguación de gel y malla transpirable.' WHERE sku = 'ILO-0001';
UPDATE articulos SET nombre = 'Bicicleta de montaña rodado 29',
    descripcion = 'Veintiún cambios y frenos de disco.' WHERE sku = 'ILO-0002';
UPDATE articulos SET descripcion = 'Discos recubiertos y barra roscada.' WHERE sku = 'ILO-0003';
UPDATE articulos SET nombre = 'Balón de fútbol número 5',
    descripcion = 'Cosido a mano, uso profesional.' WHERE sku = 'ILO-0004';

UPDATE articulos SET descripcion = 'Cuadernos, lápices, colores, reglas y mochila.' WHERE sku = 'CUZ-0001';
UPDATE articulos SET descripcion = 'Quinientas hojas, 75 gramos.' WHERE sku = 'CUZ-0002';
UPDATE articulos SET nombre = 'Calculadora científica',
    descripcion = 'Doscientas cuarenta funciones.' WHERE sku = 'CUZ-0003';
UPDATE articulos SET nombre = 'Silla ergonómica de oficina',
    descripcion = 'Respaldo de malla y altura regulable.' WHERE sku = 'CUZ-0004';

-- Las categorías también se leen en el panel.
UPDATE articulos SET categoria = 'línea blanca' WHERE categoria = 'linea_blanca';
UPDATE articulos SET categoria = 'televisión'   WHERE categoria = 'television';
UPDATE articulos SET categoria = 'climatización' WHERE categoria = 'climatizacion';
UPDATE articulos SET categoria = 'computación'  WHERE categoria = 'computacion';
UPDATE articulos SET categoria = 'perfumería'   WHERE categoria = 'perfumeria';
UPDATE articulos SET categoria = 'bebé'         WHERE categoria = 'bebe';
UPDATE articulos SET categoria = 'papelería'    WHERE categoria = 'papeleria';
UPDATE articulos SET categoria = 'fútbol'       WHERE categoria = 'futbol';
