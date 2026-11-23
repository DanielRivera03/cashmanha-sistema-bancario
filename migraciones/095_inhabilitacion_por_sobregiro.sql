-- -----------------------------------------------------------------
-- 095. La inhabilitación de la tarjeta por sobregiro no solventado
-- -----------------------------------------------------------------
--
-- QUÉ RESUELVE
--
-- Una tarjeta cuyo saldo supera la línea aprobada está exigiendo un
-- dinero que el cliente aún no ha puesto. Ese excedente es exigible de
-- inmediato y ya va incluido en el pago mínimo del corte. Si al llegar
-- la fecha límite de pago la tarjeta sigue por encima del límite, deja
-- de admitir nuevos consumos hasta que se solvente: se INHABILITA.
--
-- POR QUÉ UN ESTADO PROPIO, Y NO «bloqueada»
--
-- «bloqueada» es una decisión de una persona —el titular que no
-- encuentra la tarjeta, o el banco— y se levanta a mano. La
-- inhabilitación por sobregiro es automática y se levanta sola en
-- cuanto el saldo vuelve dentro del límite. Mezclar las dos haría que
-- un pago reactivara una tarjeta que alguien bloqueó a propósito, o
-- que la cobranza tuviera que distinguirlas por adivinación. Son cosas
-- distintas, y llevan nombre distinto.
--
-- EL CORTE SIGUE CORRIENDO
--
-- Una tarjeta inhabilitada no está muerta: su saldo sigue devengando
-- interés y necesita su estado de cuenta cada mes. Por eso el corte la
-- sigue tomando (ver ServicioCorteTarjeta::cortar, que ahora incluye
-- 'activa' e 'inhabilitada'). Lo único que no puede es gastar más.
--
-- EL CARGO POR SOBREGIRO, AHORA PROPORCIONAL
--
-- Antes la comisión por sobregiro era fija. Pasa a ser un porcentaje
-- de lo excedido con un piso, como la banca real: rebasar la línea por
-- mil no puede costar lo mismo que rebasarla por uno. El piso sigue
-- siendo `tarjeta.sobregiro_comision_cent`; el porcentaje es nuevo.
-- -----------------------------------------------------------------

-- El nuevo estado. Se añade al final del ENUM para no alterar el
-- orden de los que ya existen.
ALTER TABLE tarjetas
    MODIFY COLUMN estado ENUM(
        'activa',
        'bloqueada',
        'vencida',
        'cancelada',
        'pendiente_activacion',
        'inhabilitada'
    ) NOT NULL DEFAULT 'pendiente_activacion';

-- El porcentaje del cargo por sobregiro, y la aclaración de que el
-- cargo fijo pasa a ser el piso. No se pisa el valor si ya estaba
-- afinado: solo se asegura que exista.
INSERT INTO parametros (clave, valor, descripcion)
VALUES
  ('tarjeta.sobregiro_comision_pct', '500',
   'Comision por sobregiro como porcentaje de lo excedido (puntos base)'),
  ('tarjeta.sobregiro_comision_cent', '500',
   'Piso de la comision por sobregiro (minimo, en centavos)')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- La tarea diaria que la aplica. Corre tras el corte: inhabilita las
-- tarjetas que vencieron su fecha limite de pago aun excedidas, y
-- reactiva las que un pago devolvio a su linea. Idempotente, se puede
-- correr cuantas veces se quiera.
INSERT INTO tareas_sistema (clave, descripcion, cada_minutos, hora_preferida)
SELECT 'inhabilitar-tarjetas-sobregiro',
       'Inhabilita las tarjetas que vencieron su fecha limite de pago aun excedidas del limite, y reactiva las que volvieron dentro de su linea. Impide seguir gastando mientras no se solvente el sobregiro.',
       1440,
       '06:30:00'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM tareas_sistema WHERE clave = 'inhabilitar-tarjetas-sobregiro');
