-- ---------------------------------------------------------------------
-- La línea reservada y el escalón que la reserva, de acuerdo.
--
-- La Tarjeta Infinite pedía $10,000 de ingreso mensual y estaba
-- reservada a Patrimonial, a cuyo escalón se entra con $8,000. El
-- resultado: un cliente alcanzaba el escalón, la pantalla le anunciaba
-- «acceso a líneas de tarjeta reservadas», le enseñaba la Infinite con
-- su botón, y al pulsarlo se le decía que le faltaban $2,000.
--
-- Un escalón no puede anunciar una puerta que su propio umbral no
-- alcanza. De las dos formas de arreglarlo —subir el escalón o bajar la
-- línea— se baja la línea: $8,000 mensuales para una línea desde
-- $30,000 son 3.75 veces el ingreso, que está dentro de lo que la
-- propia capacidad de pago admite para un patrimonial (cinco veces).
--
-- La Obsidiana se queda en $25,000 y NO se toca: es por invitación de
-- la institución, lo dice su descripción y lo dice su primera viñeta.
-- Esa sí es una puerta estrecha a propósito, y ahora la pantalla enseña
-- el requisito antes de que nadie pulse nada.
-- ---------------------------------------------------------------------

UPDATE productos
   SET ingreso_minimo_cent = 800000      -- $8,000, el umbral de Patrimonial
 WHERE codigo = 'TAR-INF'
   AND ingreso_minimo_cent <> 800000;
