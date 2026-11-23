-- -----------------------------------------------------------------
-- Registro público, Cuenta Digital y tarjeta de débito automática.
--
-- Tres cosas que van juntas porque describen un mismo camino: alguien
-- que no es cliente entra al sitio, se registra, abre una cuenta y
-- sale con una tarjeta en la mano. Hasta aquí ese camino no existía:
-- al sistema solo se entraba si un empleado te daba de alta.
--
-- 1. LA CUENTA DIGITAL
--
--    Se abre desde la banca en línea, sin ir a una sucursal y sin
--    depósito de apertura. A cambio es más limitada que una cuenta de
--    ahorro corriente: tope de saldo, tope de movimiento diario y sin
--    chequera. Es el producto de entrada, no el de toda la vida.
--
--    Su tarjeta de débito es VIRTUAL: se emite sola al abrir la cuenta
--    y sirve para comprar por internet desde el primer minuto. No hay
--    plástico que imprimir ni que esperar.
--
-- 2. LA TARJETA DE DÉBITO, UNA POR CUENTA
--
--    El parámetro permitía dos. Una cuenta de ahorro con dos tarjetas
--    de débito activas es dos juegos de credenciales sobre el mismo
--    dinero, y al cliente le cuesta explicar cuál usó. Se baja a una.
--
-- 3. EL REGISTRO
--
--    Quien se registra queda como Cliente con el perfil incompleto:
--    puede entrar y abrir su cuenta digital, y para pedir un crédito
--    o una tarjeta de crédito tendrá que completar sus datos. Eso es
--    lo que separa «tener usuario» de «ser sujeto de crédito».
-- -----------------------------------------------------------------

-- ---------- 1. Una sola tarjeta de débito por cuenta ----------
INSERT INTO parametros (clave, valor, descripcion) VALUES
  ('tarjeta.debito_max_por_cuenta', '1',
   'Tarjetas de debito activas que admite una misma cuenta')
ON DUPLICATE KEY UPDATE valor = '1', descripcion = VALUES(descripcion);

-- ---------- 2. El producto ----------
INSERT INTO productos
    (codigo, nombre, tipo, descripcion, tasa_interes_bp, saldo_minimo_cent,
     monto_minimo_cent, membresia_anual_cent, beneficios, nivel,
     marca_emision, activo, publico, creado_en)
VALUES (
    'AHO-DIG',
    'Cuenta Digital',
    'ahorro',
    'Se abre en línea en un par de minutos, sin depósito de apertura y sin ir a una sucursal. Incluye tarjeta de débito virtual desde el primer día. Tiene topes de saldo y de movimiento diario más bajos que una cuenta de ahorro tradicional: cuando se le quede corta, puede cambiarla.',
    100,     -- 1% anual: menos que la de ahorro tradicional
    0,       -- sin saldo mínimo
    0,       -- sin depósito de apertura
    0,       -- sin membresía
    'Sin monto de apertura|Sin saldo mínimo que mantener|Tarjeta de débito virtual inmediata|Compras por internet desde el primer día|Contrato por correo, sin papeles|Se abre y se usa el mismo día',
    1,
    'visa',
    1,
    1,
    NOW()
)
ON DUPLICATE KEY UPDATE
    nombre      = VALUES(nombre),
    descripcion = VALUES(descripcion),
    beneficios  = VALUES(beneficios),
    activo      = 1,
    publico     = 1;

-- ---------- 3. Sus límites ----------
-- Van en parámetros y no en columnas porque son política comercial:
-- el día que el banco los suba, nadie debería tocar código.
INSERT INTO parametros (clave, valor, descripcion) VALUES
  ('cuenta_digital.producto',             'AHO-DIG',
   'Codigo del producto que se abre desde la banca en linea'),
  ('cuenta_digital.saldo_maximo_cent',    '300000',
   'Tope de saldo de una cuenta digital, en centavos'),
  ('cuenta_digital.movimiento_diario_cent', '100000',
   'Tope de lo que puede moverse en un dia desde una cuenta digital'),
  ('cuenta_digital.marca_tarjeta',        'visa',
   'Marca de la tarjeta de debito virtual que se emite al abrirla'),
  ('registro.abierto',                    '1',
   'Si el publico puede registrarse por su cuenta desde el sitio'),
  ('registro.edad_minima',                '18',
   'Anos cumplidos para poder registrarse')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- ---------- 4. El permiso de abrirse una cuenta uno mismo ----------
INSERT INTO permisos (clave, descripcion) VALUES
  ('cuenta.abrir_propia', 'Abrir una cuenta digital a su propio nombre')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  CROSS JOIN permisos p
 WHERE p.clave = 'cuenta.abrir_propia'
   AND r.nombre IN ('Cliente', 'Administrador')
ON DUPLICATE KEY UPDATE rol_id = rol_permiso.rol_id;

-- ---------- 5. De dónde salió cada cuenta ----------
-- Para distinguir después lo que se abrió en ventanilla de lo que se
-- abrió solo el cliente: son dos riesgos distintos y el cumplimiento
-- los mira por separado.
ALTER TABLE cuentas
    ADD COLUMN origen ENUM('ventanilla','en_linea','migracion') NOT NULL DEFAULT 'ventanilla'
    COMMENT 'Por donde se abrio la cuenta'
    AFTER estado;
