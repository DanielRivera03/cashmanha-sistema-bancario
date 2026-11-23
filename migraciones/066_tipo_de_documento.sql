-- -----------------------------------------------------------------
-- Qué documento es, no solo qué número.
--
-- El sistema guardaba «el documento» y ya: una cadena donde cabía un
-- DUI con guion, el mismo DUI sin guion, un NIT y lo que a cada quien
-- le pareciera. Eso tiene dos consecuencias feas:
--
--   1. Buscar por documento falla según cómo lo haya tecleado quien
--      registró al cliente. `ServicioAtencionVentanilla` ya tenía que
--      buscar además con los guiones quitados para que la ventanilla
--      encontrara a la gente.
--
--   2. El mismo cliente puede quedar dos veces, con su DUI escrito de
--      dos maneras, y nada lo señala.
--
-- Con el tipo declarado, el número se guarda siempre igual y se puede
-- pedir con la máscara que le toca.
--
-- EL NIT Y EL DUI
--
-- Desde 2022 el NIT de la persona natural está homologado con su DUI:
-- el número de DUI hace de NIT. Por eso el relleno de abajo manda los
-- de nueve dígitos a `dui` aunque alguien los hubiera registrado como
-- NIT. El tipo `nit` queda para las personas jurídicas y para quien
-- todavía arrastra el suyo de antes, que son catorce dígitos.
--
-- Queda nulo donde no se puede saber: un documento que no tiene ni
-- nueve ni catorce dígitos no es ninguno de los dos, y marcarlo a ojo
-- sería inventarse un dato del expediente de un cliente.
-- -----------------------------------------------------------------

ALTER TABLE usuarios
    ADD COLUMN tipo_documento ENUM('dui','nit','residente') NULL DEFAULT NULL
        COMMENT 'Qué documento es el de documento_identidad'
        AFTER documento_identidad;

ALTER TABLE tienda_pedidos
    ADD COLUMN invitado_tipo_documento ENUM('dui','nit','residente') NULL DEFAULT NULL
        AFTER invitado_documento;

-- Nueve dígitos: DUI, o un NIT homologado, que es lo mismo.
UPDATE usuarios
   SET tipo_documento = 'dui'
 WHERE tipo_documento IS NULL
   AND documento_identidad IS NOT NULL
   AND CHAR_LENGTH(REGEXP_REPLACE(documento_identidad, '[^0-9]', '')) = 9;

-- Catorce: NIT de los de antes, o de persona jurídica.
UPDATE usuarios
   SET tipo_documento = 'nit'
 WHERE tipo_documento IS NULL
   AND documento_identidad IS NOT NULL
   AND CHAR_LENGTH(REGEXP_REPLACE(documento_identidad, '[^0-9]', '')) = 14;

-- Con letras de por medio solo puede ser un carné de residente.
UPDATE usuarios
   SET tipo_documento = 'residente'
 WHERE tipo_documento IS NULL
   AND documento_identidad IS NOT NULL
   AND TRIM(documento_identidad) <> ''
   AND documento_identidad REGEXP '[A-Za-z]';

UPDATE tienda_pedidos
   SET invitado_tipo_documento = 'dui'
 WHERE invitado_tipo_documento IS NULL
   AND invitado_documento IS NOT NULL
   AND CHAR_LENGTH(REGEXP_REPLACE(invitado_documento, '[^0-9]', '')) = 9;

UPDATE tienda_pedidos
   SET invitado_tipo_documento = 'nit'
 WHERE invitado_tipo_documento IS NULL
   AND invitado_documento IS NOT NULL
   AND CHAR_LENGTH(REGEXP_REPLACE(invitado_documento, '[^0-9]', '')) = 14;
