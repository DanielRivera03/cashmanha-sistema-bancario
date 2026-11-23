-- =====================================================================
-- 014 — Tasas acordes al mercado salvadoreño
-- ---------------------------------------------------------------------
-- Las tasas de arranque eran las de una semilla de ejemplo y quedaban
-- por debajo de lo que se cobra de verdad en El Salvador, sobre todo en
-- tarjetas de crédito. El sitio público las publica, así que una tasa
-- irreal no es un detalle de demostración: es información equivocada
-- para quien la lee.
--
-- Referencias consultadas (noviembre de 2026):
--
--  · Banco Central de Reserva — en julio de 2026 los préstamos
--    personales de largo plazo promediaron 12.11 % anual, y los
--    depósitos a 180 días alcanzaron 4.70 %.
--  · Comparativas de mercado — los préstamos personales de banca
--    supervisada se mueven entre el 12 % y el 30 % anual; las tarjetas
--    de crédito, entre el 24 % y el 48 %.
--  · Oferta hipotecaria publicada por la banca local — desde 12.50 %
--    anual a plazos de hasta 240 meses.
--
-- Importante: esto solo afecta a lo que se otorgue de aquí en adelante.
-- Cada crédito ya vigente guarda su propia tasa en `creditos.tasa_bp` y
-- ninguna de estas líneas la toca; cambiar la tasa de un contrato en
-- curso sería alterar lo pactado.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Depósitos
-- ---------------------------------------------------------------------
-- Rendimiento al ahorrador. Subirlo acerca el producto a la referencia
-- del BCR para plazos de 180 días.

UPDATE productos SET tasa_interes_bp = 450 WHERE codigo = 'AHO-PER';
UPDATE productos SET tasa_interes_bp = 375 WHERE codigo = 'AHO-EMP';

-- La cuenta corriente no paga intereses: es transaccional. Se deja en 0.

-- ---------------------------------------------------------------------
-- Créditos
-- ---------------------------------------------------------------------

-- Personal: 22 % anual, dentro de la franja de banca supervisada.
UPDATE productos
   SET tasa_interes_bp = 2200, tasa_min_bp = 1600, tasa_max_bp = 3000
 WHERE codigo = 'CRE-PER';

-- Hipotecario: 12.50 %, que es desde donde arranca la oferta local.
UPDATE productos
   SET tasa_interes_bp = 1250, tasa_min_bp = 900, tasa_max_bp = 1600
 WHERE codigo = 'CRE-HIP';

-- ---------------------------------------------------------------------
-- Tarjetas de crédito
-- ---------------------------------------------------------------------
-- Es donde más se alejaba de la realidad. En El Salvador las tarjetas
-- se mueven entre el 24 % y el 48 % anual; 24 % era el piso del mercado,
-- no una tarifa normal.

UPDATE productos
   SET tasa_interes_bp = 4400, tasa_min_bp = 3600, tasa_max_bp = 5400
 WHERE codigo = 'TAR-CLA';

-- La Oro es la de mejor perfil: paga menos que la Clásica.
UPDATE productos
   SET tasa_interes_bp = 3800, tasa_min_bp = 3000, tasa_max_bp = 4800
 WHERE codigo = 'TAR-ORO';

-- ---------------------------------------------------------------------
-- Comprobación
-- ---------------------------------------------------------------------
-- Ningún producto debe quedar con un rango invertido ni con la tasa
-- fuera de su propio rango: eso haría que el deslizador naciera en una
-- posición que el servidor rechazaría.

UPDATE productos
   SET tasa_min_bp = LEAST(tasa_min_bp, tasa_interes_bp),
       tasa_max_bp = GREATEST(tasa_max_bp, tasa_interes_bp)
 WHERE tasa_interes_bp > 0;
