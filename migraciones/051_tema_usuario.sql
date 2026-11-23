-- ---------------------------------------------------------------------
-- El tema que eligió el cliente.
--
-- Guardarlo solo en el navegador significa volver a elegirlo en cada
-- dispositivo, y quien usa el modo oscuro lo usa en todos. Aquí vive
-- su elección; el navegador la cachea para poder aplicarla antes del
-- primer pintado y evitar el destello blanco.
--
-- NULL significa «no ha elegido»: ahí manda el valor por omisión, que
-- para un cliente preferente es el modo noche y para el resto el del
-- sistema operativo.
-- ---------------------------------------------------------------------

ALTER TABLE usuarios
  ADD COLUMN tema VARCHAR(8) NULL DEFAULT NULL
      COMMENT 'dia, noche o auto. NULL = no ha elegido'
      AFTER segmento_desde;
