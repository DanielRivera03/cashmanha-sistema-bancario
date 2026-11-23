-- =====================================================================
-- 080. Los códigos de un solo uso del módulo interbancario
-- =====================================================================
--
-- `desafios.proposito` es un ENUM, y lo es a propósito: el propósito
-- ata el código a una operación concreta, de modo que un código pedido
-- para recuperar la contraseña no sirva para transferir dinero.
--
-- Esa misma virtud hace que añadir operaciones nuevas obligue a pasar
-- por aquí. Es el precio correcto: la base rechaza un propósito que
-- nadie definió, en lugar de aceptarlo en silencio y dejar un código
-- viviendo fuera de toda regla.
--
-- Se añaden dos:
--
--   beneficiario   Dar de alta un destino en la agenda. Pide código
--                  porque registrar un destino es exactamente lo que
--                  necesita hacer quien roba una sesión antes de poder
--                  sacar dinero.
--
--   interbancaria  Enviar a otro banco. Pide código por lo evidente.
--
-- `limites` queda para cuando el cliente SUBE un límite suyo: bajarlo
-- solo puede protegerlo, subirlo es lo que haría un atacante.
-- =====================================================================
USE cashmanha_v2;

ALTER TABLE desafios
  MODIFY COLUMN proposito ENUM('transferencia','recuperacion','alta_tarjeta',
                               'cambio_correo','pago_tarjeta',
                               'beneficiario','interbancaria','limites',
                               'celular_t365') NOT NULL;
