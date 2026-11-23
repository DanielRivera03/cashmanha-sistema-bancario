-- ---------------------------------------------------------------------
-- 029 · Crédito de las fotos del catálogo
--
-- Las fotos de los artículos vienen de Wikimedia Commons, con licencias
-- libres (dominio público, CC0, CC BY, CC BY-SA). Casi todas esas
-- licencias piden atribuir al autor, y cumplirlo no es un detalle
-- estético: es la condición bajo la que se puede usar la imagen.
--
-- Se guarda junto a la foto —autor, licencia y enlace a la página de
-- origen— para poder mostrarlo donde corresponde y para saber, meses
-- después, de dónde salió cada imagen.
--
-- Las fotos que suba el personal por el panel no llevan crédito: son
-- suyas, y el campo queda nulo.
-- ---------------------------------------------------------------------

ALTER TABLE articulos
  ADD COLUMN imagen_credito VARCHAR(300) NULL
      COMMENT 'Autor y licencia de la foto, cuando proviene de un banco libre'
      AFTER imagen_alt,
  ADD COLUMN imagen_origen VARCHAR(500) NULL
      COMMENT 'Pagina de origen de la foto, para poder verificarla'
      AFTER imagen_credito;
