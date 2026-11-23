-- =====================================================================
-- CashMan H.A. v2 — Datos semilla
-- Roles, permisos, productos y límites operativos.
-- =====================================================================
USE cashmanha_v2;

-- ---------------------------------------------------------------------
-- ROLES
-- ---------------------------------------------------------------------
INSERT INTO roles (id, clave, nombre, descripcion, nivel) VALUES
  (1, 'administrador',    'Administrador',       'Control total del sistema',                100),
  (2, 'presidencia',      'Presidencia',         'Aprobación de operaciones de alto valor',   90),
  (3, 'gerencia',         'Gerencia',            'Gestión de cartera y aprobación crediticia',80),
  (4, 'atencionclientes', 'Atención a Clientes', 'Operaciones de ventanilla y soporte',       50),
  (5, 'cliente',          'Cliente',             'Portal de banca en línea',                  10);

-- ---------------------------------------------------------------------
-- PERMISOS
-- ---------------------------------------------------------------------
INSERT INTO permisos (clave, descripcion) VALUES
  ('cuenta.ver_propia',        'Ver sus propias cuentas'),
  ('cuenta.ver_todas',         'Ver cuentas de cualquier cliente'),
  ('cuenta.abrir',             'Abrir cuentas nuevas'),
  ('cuenta.bloquear',          'Bloquear o congelar cuentas'),
  ('transferencia.ejecutar',   'Ejecutar transferencias propias'),
  ('transferencia.ejecutar_terceros', 'Transferir desde cuentas de terceros'),
  ('transferencia.aprobar',    'Aprobar transferencias de alto valor'),
  ('deposito.registrar',       'Registrar depósitos'),
  ('retiro.registrar',         'Registrar retiros'),
  ('tarjeta.ver_propia',       'Ver sus propias tarjetas'),
  ('tarjeta.ver_todas',        'Ver tarjetas de cualquier cliente'),
  ('tarjeta.emitir',           'Emitir tarjetas nuevas'),
  ('tarjeta.bloquear',         'Bloquear tarjetas'),
  ('tarjeta.pagar',            'Pagar saldo de tarjeta'),
  ('credito.solicitar',        'Solicitar un crédito'),
  ('credito.ver_todos',        'Ver todos los créditos'),
  ('credito.aprobar',          'Aprobar solicitudes de crédito'),
  ('credito.desembolsar',      'Desembolsar créditos aprobados'),
  ('estado_cuenta.ver_propio', 'Ver sus estados de cuenta'),
  ('estado_cuenta.ver_todos',  'Ver estados de cuenta de terceros'),
  ('estado_cuenta.generar',    'Generar y enviar estados de cuenta'),
  ('cartera.ver_morosos',      'Consultar la cartera morosa'),
  ('cartera.gestionar',        'Gestionar cobranza'),
  ('usuario.ver_todos',        'Ver todos los usuarios'),
  ('usuario.crear',            'Crear usuarios'),
  ('usuario.modificar',        'Modificar usuarios'),
  ('usuario.bloquear',         'Bloquear usuarios'),
  ('auditoria.ver',            'Consultar la bitácora de auditoría'),
  ('reporte.ver',              'Ver reportes gerenciales'),
  ('sistema.configurar',       'Configurar parámetros del sistema');

-- ---------------------------------------------------------------------
-- ASIGNACIÓN ROL → PERMISO
-- ---------------------------------------------------------------------

-- Administrador: todo.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 1, id FROM permisos;

-- Presidencia: todo menos configuración del sistema y alta de usuarios.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 2, id FROM permisos
WHERE clave NOT IN ('sistema.configurar','usuario.crear','usuario.bloquear');

-- Gerencia: cartera, crédito y consulta.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 3, id FROM permisos WHERE clave IN (
  'cuenta.ver_todas','cuenta.abrir','transferencia.aprobar',
  'tarjeta.ver_todas','tarjeta.emitir','tarjeta.bloquear',
  'credito.ver_todos','credito.aprobar','credito.desembolsar',
  'estado_cuenta.ver_todos','estado_cuenta.generar',
  'cartera.ver_morosos','cartera.gestionar',
  'usuario.ver_todos','reporte.ver','auditoria.ver'
);

-- Atención a clientes: ventanilla, sin aprobar ni desembolsar.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 4, id FROM permisos WHERE clave IN (
  'cuenta.ver_todas','cuenta.abrir',
  'transferencia.ejecutar_terceros',
  'deposito.registrar','retiro.registrar',
  'tarjeta.ver_todas','tarjeta.bloquear',
  'credito.ver_todos',
  'estado_cuenta.ver_todos','estado_cuenta.generar',
  'cartera.ver_morosos',
  'usuario.ver_todos'
);

-- Cliente: solo lo suyo.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 5, id FROM permisos WHERE clave IN (
  'cuenta.ver_propia',
  'transferencia.ejecutar',
  'tarjeta.ver_propia','tarjeta.pagar',
  'credito.solicitar',
  'estado_cuenta.ver_propio'
);

-- ---------------------------------------------------------------------
-- PRODUCTOS
-- ---------------------------------------------------------------------
INSERT INTO productos (codigo, nombre, tipo, descripcion, tasa_interes_bp, saldo_minimo_cent, activo) VALUES
  ('AHO-PER', 'Cuenta de Ahorro Personal',  'ahorro',  'Cuenta de ahorro para personas naturales',       350,  100, 1),
  ('AHO-EMP', 'Cuenta de Ahorro Empresarial','ahorro', 'Cuenta de ahorro para personas jurídicas',       275, 5000, 1),
  ('CTE-PER', 'Cuenta Corriente',           'corriente','Cuenta corriente con chequera',                   0, 10000, 1),
  ('CRE-PER', 'Crédito Personal',           'credito', 'Crédito de libre disponibilidad',               1800,    0, 1),
  ('CRE-HIP', 'Crédito Hipotecario',        'credito', 'Financiamiento de vivienda a largo plazo',       900,    0, 1),
  ('TAR-CLA', 'Tarjeta Clásica',            'tarjeta', 'Tarjeta de crédito clásica',                    2400,    0, 1),
  ('TAR-ORO', 'Tarjeta Oro',                'tarjeta', 'Tarjeta de crédito oro con beneficios',         2100,    0, 1);

-- ---------------------------------------------------------------------
-- LÍMITES OPERATIVOS
-- Lo que antes vivía en JavaScript ahora es una regla del servidor.
-- Montos en centavos: 200000 = $2,000.00
-- ---------------------------------------------------------------------
INSERT INTO limites_operacion
  (rol_id, tipo_operacion, max_por_operacion_cent, max_diario_cent, max_mensual_cent,
   max_ops_diarias, requiere_otp, umbral_doble_control_cent) VALUES
  -- Clientes: el tope de $2,000 por operación, ahora inviolable.
  (5, 'transferencia',   200000,   500000,  3000000, 10, 1,      NULL),
  (5, 'pago_tarjeta',    500000,  1000000,  5000000, 10, 1,      NULL),
  -- Atención a clientes: ventanilla, con doble control desde $5,000.
  (4, 'transferencia',  1000000,  5000000, 20000000, 50, 1,   500000),
  (4, 'deposito',       2000000, 10000000, 50000000, 80, 0,  1000000),
  (4, 'retiro',          500000,  2000000, 10000000, 50, 1,   300000),
  -- Gerencia
  (3, 'transferencia',  5000000, 20000000,100000000, 50, 1,  2000000),
  -- Presidencia
  (2, 'transferencia', 20000000,100000000,500000000, 50, 1, 10000000),
  -- Administrador: límite alto pero NO infinito, y siempre con OTP.
  (1, 'transferencia', 50000000,200000000,999999999, 50, 1, 20000000);
