<?php
declare(strict_types=1);

/**
 * Migra los datos de `cashmanha` (v1) a `cashmanha_v2`.
 *
 * Qué hace con las contraseñas:
 *   Los hashes de v1 son DES truncado a 8 caracteres con salt derivado
 *   del propio password. Son irrecuperables en el sentido útil y
 *   triviales de romper. No tiene sentido migrarlos.
 *
 *   Por eso a TODOS los usuarios se les asigna una contraseña nueva,
 *   hasheada con Argon2id, y se marca requiere_cambio_password = 1
 *   para que la cambien al entrar.
 *
 * Qué hace con los saldos:
 *   No se copian como número suelto. Por cada cuenta con saldo se
 *   genera un asiento de apertura en el libro mayor, de modo que
 *   desde el primer día el saldo sea una proyección verificable y no
 *   un dato huérfano.
 *
 * Uso:  php bin/migrar-datos.php [--password=LaClaveQueQuieras]
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\ServicioPassword;
use CashMan\Infraestructura\Seguridad\Hashid;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por línea de comandos.\n");
}

// ---------------------------------------------------------------------
$opciones = getopt('', ['password::', 'solo-usuarios']);
$passwordComun = $opciones['password'] ?? 'CashMan#2026$Seguro';
$soloUsuarios  = isset($opciones['solo-usuarios']);

$svcPassword = new ServicioPassword();

// Validar la contraseña contra la propia política del sistema.
$fallos = $svcPassword->validarPolitica($passwordComun);
if ($fallos !== []) {
    echo "\n  La contraseña indicada no cumple la política:\n";
    foreach ($fallos as $f) {
        echo "    - {$f}\n";
    }
    echo "\n";
    exit(1);
}

echo "\n";
echo "  Migración CashMan H.A.  v1 → v2\n";
echo "  " . str_repeat('═', 64) . "\n\n";

$pdo = BaseDatos::conexion();

// ---------------------------------------------------------------------
// 1. USUARIOS
// ---------------------------------------------------------------------
echo "  [1/5] Usuarios\n";

$usuariosV1 = BaseDatos::todas(
    'SELECT u.idusuarios, u.nombres, u.apellidos, u.codigousuario, u.correo,
            u.idrol, u.estado_usuario, u.fotoperfil
       FROM cashmanha.usuarios u
      ORDER BY u.idusuarios'
);

echo "        Encontrados en v1: " . count($usuariosV1) . "\n";
echo "        Generando hashes Argon2id (esto tarda, es lo esperado)...\n";

// Un hash por usuario: cada uno con su propio salt aleatorio.
// Dos usuarios con la misma contraseña tendrán hashes DISTINTOS.
$mapaUsuarios = [];
$insertados   = 0;

BaseDatos::enTransaccion(function () use (
    $usuariosV1, $svcPassword, $passwordComun, &$mapaUsuarios, &$insertados
): void {
    foreach ($usuariosV1 as $u) {
        $estado = match (strtolower((string) $u['estado_usuario'])) {
            'activo'    => 'activo',
            'inactivo'  => 'inactivo',
            'bloqueado' => 'bloqueado',
            default     => 'pendiente',
        };

        $rolId = (int) $u['idrol'];
        if ($rolId < 1 || $rolId > 5) {
            $rolId = 5;
        }

        $hash = $svcPassword->hashear($passwordComun);

        BaseDatos::ejecutar(
            'INSERT INTO usuarios
               (uuid, usuario, correo, nombres, apellidos, password_hash,
                password_algoritmo, password_actualizado_en, requiere_cambio_password,
                rol_id, estado, foto_perfil, creado_en)
             VALUES (?,?,?,?,?,?, "argon2id", NOW(), 1, ?,?,?, NOW())',
            [
                sprintf(
                    '%s-%s-4%s-%s%s-%s',
                    bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),
                    substr(bin2hex(random_bytes(2)), 1),
                    dechex(random_int(8, 11)), substr(bin2hex(random_bytes(2)), 1),
                    bin2hex(random_bytes(6))
                ),
                (string) $u['codigousuario'],
                (string) $u['correo'],
                (string) $u['nombres'],
                (string) $u['apellidos'],
                $hash,
                $rolId,
                $estado,
                $u['fotoperfil'] ?: null,
            ]
        );

        $nuevoId = BaseDatos::ultimoId();
        $mapaUsuarios[(int) $u['idusuarios']] = $nuevoId;
        $insertados++;

        echo "          " . str_pad((string) $u['codigousuario'], 22)
           . " rol={$rolId}  " . str_pad($estado, 10)
           . " → id {$nuevoId}\n";
    }
});

echo "        Migrados: {$insertados}\n\n";

if ($soloUsuarios) {
    echo "  Modo --solo-usuarios: se omite el resto.\n\n";
    exit(0);
}

// ---------------------------------------------------------------------
// 2. CUENTAS
// ---------------------------------------------------------------------
echo "  [2/5] Cuentas de ahorro\n";

$cuentasV1 = BaseDatos::todas(
    'SELECT idcuentas, numerocuenta, montocuenta, idusuarios, estadocuenta, fechaapertura
       FROM cashmanha.cuentas ORDER BY idcuentas'
);

$mapaCuentas = [];
$productoAhorro = (int) BaseDatos::unValor(
    "SELECT id FROM productos WHERE codigo = 'AHO-PER' LIMIT 1"
);

BaseDatos::enTransaccion(function () use ($cuentasV1, $mapaUsuarios, $productoAhorro, &$mapaCuentas): void {
    foreach ($cuentasV1 as $c) {
        $usuarioV2 = $mapaUsuarios[(int) $c['idusuarios']] ?? null;
        if ($usuarioV2 === null) {
            echo "          AVISO: cuenta {$c['numerocuenta']} sin usuario correspondiente. Omitida.\n";
            continue;
        }

        // DECIMAL(9,2) → centavos enteros. Sin pérdida.
        $centavos = (int) round(((float) $c['montocuenta']) * 100);

        $estado = match (strtolower((string) $c['estadocuenta'])) {
            'activa'    => 'activa',
            'bloqueada' => 'bloqueada',
            'cerrada'   => 'cerrada',
            default     => 'activa',
        };

        BaseDatos::ejecutar(
            'INSERT INTO cuentas
               (uuid, numero_cuenta, usuario_id, producto_id, moneda,
                saldo_cent, estado, apertura_en)
             VALUES (UUID(), ?,?,?, "USD", 0, ?, ?)',
            [
                (string) $c['numerocuenta'],
                $usuarioV2,
                $productoAhorro,
                $estado,
                $c['fechaapertura'] ?: date('Y-m-d H:i:s'),
            ]
        );

        $nuevoId = BaseDatos::ultimoId();
        $mapaCuentas[(int) $c['idcuentas']] = ['id' => $nuevoId, 'centavos' => $centavos, 'usuario' => $usuarioV2];

        echo "          cuenta " . str_pad((string) $c['numerocuenta'], 14)
           . str_pad('$' . number_format($centavos / 100, 2), 14)
           . " → " . Hashid::codificar($nuevoId, 'cuenta') . "\n";
    }
});

echo "        Migradas: " . count($mapaCuentas) . "\n\n";

// ---------------------------------------------------------------------
// 3. ASIENTOS DE APERTURA
// ---------------------------------------------------------------------
echo "  [3/5] Asientos de apertura en el libro mayor\n";

// Cuenta técnica de contrapartida: toda apertura necesita su doble.
// Sin ella la partida doble no cuadra.
$usuarioSistema = (int) BaseDatos::unValor(
    'SELECT id FROM usuarios WHERE rol_id = 1 ORDER BY id LIMIT 1'
);

BaseDatos::ejecutar(
    'INSERT INTO cuentas (uuid, numero_cuenta, usuario_id, producto_id, moneda, saldo_cent, estado)
     VALUES (UUID(), "0000000000", ?, ?, "USD", 0, "activa")
     ON DUPLICATE KEY UPDATE id = id',
    [$usuarioSistema, $productoAhorro]
);

$cuentaTecnica = (int) BaseDatos::unValor(
    'SELECT id FROM cuentas WHERE numero_cuenta = "0000000000" LIMIT 1'
);

$asientos = 0;

BaseDatos::enTransaccion(function () use ($mapaCuentas, $cuentaTecnica, $usuarioSistema, &$asientos): void {
    $semilla = hash('sha256', 'cashmanha-v2-genesis');

    foreach ($mapaCuentas as $info) {
        if ($info['centavos'] <= 0) {
            continue;
        }

        $hashAnterior = (string) (BaseDatos::unValor(
            'SELECT hash_actual FROM asientos ORDER BY id DESC LIMIT 1'
        ) ?: $semilla);

        $referencia = 'APERTURA-' . strtoupper(bin2hex(random_bytes(6)));
        $clave      = 'migracion-apertura-cuenta-' . $info['id'];
        $contenido  = $hashAnterior . '|apertura|' . $referencia . '|' . $info['centavos'];
        $hashActual = hash('sha256', $contenido);

        BaseDatos::ejecutar(
            'INSERT INTO asientos
               (uuid, clave_idempotencia, tipo, referencia, descripcion,
                creado_por, ip_origen, hash_anterior, hash_actual)
             VALUES (UUID(), ?, "ajuste", ?, "Saldo de apertura migrado desde v1",
                     ?, ?, ?, ?)',
            [$clave, $referencia, $usuarioSistema, inet_pton('127.0.0.1'), $hashAnterior, $hashActual]
        );
        $asientoId = BaseDatos::ultimoId();

        // Partida doble: la cuenta del cliente recibe, la técnica entrega.
        BaseDatos::ejecutar(
            'INSERT INTO movimientos (asiento_id, cuenta_id, importe_cent, saldo_posterior_cent, descripcion)
             VALUES (?,?,?,?, "Saldo de apertura")',
            [$asientoId, $info['id'], $info['centavos'], $info['centavos']]
        );
        BaseDatos::ejecutar(
            'INSERT INTO movimientos (asiento_id, cuenta_id, importe_cent, saldo_posterior_cent, descripcion)
             VALUES (?,?,?,?, "Contrapartida de apertura")',
            [$asientoId, $cuentaTecnica, -$info['centavos'], 0]
        );

        // Ahora sí se proyecta el saldo.
        BaseDatos::ejecutar(
            'UPDATE cuentas SET saldo_cent = ? WHERE id = ?',
            [$info['centavos'], $info['id']]
        );

        $asientos++;
    }
});

echo "        Asientos creados: {$asientos}\n\n";

// ---------------------------------------------------------------------
// 4. VERIFICACIÓN CONTABLE
// ---------------------------------------------------------------------
echo "  [4/5] Verificación de la invariante contable\n";

$descuadrados = BaseDatos::todas('SELECT * FROM v_asientos_descuadrados');
$desconciliados = BaseDatos::todas(
    'SELECT * FROM v_conciliacion_saldos WHERE diferencia_cent <> 0'
);

if ($descuadrados === [] && $desconciliados === []) {
    echo "        Todos los asientos suman cero.\n";
    echo "        Todos los saldos coinciden con el libro mayor.\n\n";
} else {
    echo "        PROBLEMA: " . count($descuadrados) . " asientos descuadrados, "
       . count($desconciliados) . " saldos sin conciliar.\n\n";
}

// ---------------------------------------------------------------------
// 5. RESUMEN
// ---------------------------------------------------------------------
echo "  [5/5] Credenciales de acceso\n";
echo "  " . str_repeat('═', 64) . "\n\n";

$usuarios = BaseDatos::todas(
    'SELECT u.id, u.usuario, u.correo, r.nombre AS rol, u.estado
       FROM usuarios u JOIN roles r ON r.id = u.rol_id
      WHERE u.usuario <> ""
      ORDER BY u.rol_id, u.id'
);

printf("  %-22s %-22s %-12s\n", 'USUARIO', 'ROL', 'ESTADO');
echo "  " . str_repeat('─', 64) . "\n";
foreach ($usuarios as $u) {
    printf("  %-22s %-22s %-12s\n", $u['usuario'], $u['rol'], $u['estado']);
}

echo "\n  " . str_repeat('─', 64) . "\n";
echo "  Contraseña para TODOS los usuarios:\n\n";
echo "      {$passwordComun}\n\n";
echo "  Cada uno tiene su propio hash Argon2id con salt distinto.\n";
echo "  Al entrar por primera vez se pedirá cambiarla.\n";
echo "  " . str_repeat('═', 64) . "\n\n";
