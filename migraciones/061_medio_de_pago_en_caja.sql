-- -----------------------------------------------------------------
-- Con qué pagó el cliente en la ventanilla.
--
-- Hasta aquí un movimiento de caja solo tenía importe, y el cierre
-- sumaba TODOS los movimientos para decir cuánto efectivo debía haber
-- en la gaveta. Eso solo es cierto si todo se paga en efectivo.
--
-- Un cheque no es efectivo. Entra por la ventanilla, se abona a la
-- cuenta y se remesa al banco, pero no hay un solo billete más en la
-- gaveta. Contándolo como efectivo, el arqueo le reclamaba al cajero
-- un dinero que nunca tuvo: deposita $500 en cheque y al cerrar le
-- faltan $500.
--
-- Del cheque se guarda banco y número porque es lo que se necesita
-- para reclamarlo si rebota, y es lo que el cliente quiere ver en su
-- comprobante.
-- -----------------------------------------------------------------

ALTER TABLE caja_movimientos
    ADD COLUMN medio ENUM('efectivo','cheque','transferencia','tarjeta')
        NOT NULL DEFAULT 'efectivo'
        COMMENT 'Con qué se pagó o se entregó. Solo el efectivo cuenta en el arqueo'
        AFTER tipo,
    ADD COLUMN cheque_banco VARCHAR(80) NULL AFTER medio,
    ADD COLUMN cheque_numero VARCHAR(30) NULL AFTER cheque_banco;

-- Para que el cierre separe efectivo de cheques sin recorrer la tabla.
ALTER TABLE caja_movimientos
    ADD INDEX idx_caja_mov_sesion_medio (sesion_id, medio);
