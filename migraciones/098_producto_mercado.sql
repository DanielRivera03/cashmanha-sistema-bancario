-- -----------------------------------------------------------------
-- 098. Para quién es cada producto: persona, empresa o ambos
-- -----------------------------------------------------------------
--
-- El sitio mostraba todos los productos en las dos páginas, así que la
-- Tarjeta Empresarial salía en «personas» y las personales en
-- «empresas». No había forma de saber para quién es cada uno. Esta
-- columna lo dice, y el sitio filtra por ella.
--
-- Clasificación de los productos ya registrados, por su código:
--   empresa : AHO-EMP (ahorro empresarial), TAR-EMP (tarjeta
--             empresarial), CRE-PYM (crédito para negocio).
--   ambos   : DPF (plazo fijo) y AHO-DIG (cuenta digital), que sirven
--             igual a una persona o a una empresa.
--   persona : el resto (queda por el DEFAULT).
-- -----------------------------------------------------------------

ALTER TABLE productos
    ADD COLUMN mercado ENUM('persona','empresa','ambos')
        NOT NULL DEFAULT 'persona'
        COMMENT 'Para quién es el producto' AFTER tipo;

UPDATE productos SET mercado = 'empresa'
 WHERE codigo IN ('AHO-EMP', 'TAR-EMP', 'CRE-PYM');

UPDATE productos SET mercado = 'ambos'
 WHERE codigo IN ('DPF', 'AHO-DIG');
