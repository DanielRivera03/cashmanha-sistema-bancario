-- -----------------------------------------------------------------
-- Cierre automático de cuentas inactivas, y el número que no vuelve.
--
-- Una cuenta de ahorro en cero y sin movimiento durante meses no le
-- sirve a nadie: al cliente no le da nada y a la institución le cuesta
-- mantenerla, auditarla y reportarla. La práctica bancaria es cerrarla,
-- y lo correcto es avisarlo antes y tenerlo pactado en el contrato.
--
-- LO QUE SE AGREGA
--
--   cerrada_en        cuándo se cerró. `apertura_en` ya existía; esto
--                     es su pareja, y sin ella no se puede responder
--                     «¿desde cuándo está cerrada?».
--
--   cierre_motivo     por qué. Un estado «cerrada» sin motivo obliga a
--                     ir a buscar a la auditoría para saber si la
--                     cerró el cliente, la institución o el barrido.
--
--   aviso_inactividad_en   cuándo se le avisó al titular de que iba a
--                     cerrarse. Esto NO es decoración: sin la marca no
--                     se puede demostrar que se avisó, y no se puede
--                     evitar avisar dos veces.
--
-- EL NÚMERO QUE NO VUELVE
--
-- `numeros_cuenta_retirados` guarda los números de las cuentas
-- cerradas para que el generador no los vuelva a asignar. Reutilizar
-- un número es lo que hace que un depósito dirigido a la cuenta vieja
-- de alguien aterrice en la cuenta nueva de otro, y que un estado de
-- cuenta de hace tres años parezca el de un cliente que no es. El
-- número de cuenta es una referencia que vive fuera del sistema —en
-- recibos, en órdenes de pago, en la libreta de un proveedor— y esa
-- vida no termina cuando la cuenta se cierra.
-- -----------------------------------------------------------------

ALTER TABLE cuentas
    ADD COLUMN cerrada_en DATETIME NULL DEFAULT NULL
        COMMENT 'Cuando se cerro la cuenta' AFTER apertura_en,
    ADD COLUMN cierre_motivo VARCHAR(200) NULL DEFAULT NULL
        COMMENT 'Por que se cerro' AFTER cerrada_en,
    ADD COLUMN aviso_inactividad_en DATETIME NULL DEFAULT NULL
        COMMENT 'Cuando se le avisa al titular del cierre proximo' AFTER cierre_motivo,
    ADD INDEX idx_cuentas_inactivas (estado, saldo_cent);

CREATE TABLE IF NOT EXISTS numeros_cuenta_retirados (
    numero_cuenta VARCHAR(20)  NOT NULL,
    cuenta_id     BIGINT UNSIGNED NULL,
    motivo        VARCHAR(200) NULL,
    retirado_en   DATETIME     NOT NULL,
    PRIMARY KEY (numero_cuenta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Numeros de cuenta que no se vuelven a asignar nunca';

-- Lo que ya estuviera cerrado, retirado desde ahora.
INSERT IGNORE INTO numeros_cuenta_retirados (numero_cuenta, cuenta_id, motivo, retirado_en)
SELECT numero_cuenta, id, 'Cuenta ya cerrada al implantar el retiro de numeros', NOW()
  FROM cuentas
 WHERE estado = 'cerrada';

INSERT INTO parametros (clave, valor, descripcion, actualizado_en) VALUES
  ('cuenta.inactividad_meses', '6',
   'Meses en cero y sin movimiento antes de cerrar una cuenta de ahorro.', NOW()),
  ('cuenta.inactividad_aviso_dias', '30',
   'Dias de aviso al titular antes del cierre por inactividad.', NOW()),
  ('cuenta.cierre_automatico', '1',
   'Activa el cierre automatico por inactividad. Cero lo apaga.', NOW())
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
