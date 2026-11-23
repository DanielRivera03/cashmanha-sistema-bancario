-- =====================================================================
-- 038 · El finiquito, registrado como finiquito
-- =====================================================================
--
-- `contratos.tipo` solo contemplaba credito, cuenta, tarjeta y
-- reestructuracion, asi que el finiquito se archivaba como "credito".
-- En la pantalla de Contratos aparecia rotulado «Contrato de credito»
-- y con el boton «Firmar» al lado: un finiquito no lo firma el cliente,
-- lo extiende la institucion para acreditar que la deuda se extinguio.
-- Pedirle la firma invierte quien declara que a quien.
--
-- Con el tipo propio, el documento se nombra por lo que es y la
-- pantalla deja de ofrecer una firma que no corresponde.
-- =====================================================================

ALTER TABLE contratos
  MODIFY COLUMN tipo
    ENUM('credito','cuenta','tarjeta','reestructuracion','finiquito')
    NOT NULL DEFAULT 'credito';

-- Los finiquitos ya emitidos quedaron con el tipo equivocado. Se
-- reconocen por el nombre del archivo, que es como se guardaron.
UPDATE contratos
   SET tipo = 'finiquito'
 WHERE tipo = 'credito'
   AND ruta_pdf LIKE '%finiquito-%';
