<?php
declare(strict_types=1);

/**
 * Restablece la contraseña de todos los usuarios a una sola.
 *
 * Para desarrollo y pruebas. Cada usuario recibe su PROPIO hash
 * Argon2id con salt aleatorio: aunque la contraseña sea la misma,
 * los hashes almacenados son todos distintos.
 *
 * Uso:
 *   php bin/restablecer-claves.php
 *   php bin/restablecer-claves.php --password="OtraClave#2026" --forzar-cambio
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\ServicioPassword;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por línea de comandos.\n");
}

$op       = getopt('', ['password::', 'forzar-cambio']);
$password = $op['password'] ?? 'CashMan#2026$Seguro';
$forzar   = isset($op['forzar-cambio']) ? 1 : 0;

$svc    = new ServicioPassword();
$fallos = $svc->validarPolitica($password);

if ($fallos !== []) {
    echo "\n  La contraseña no cumple la política:\n";
    foreach ($fallos as $f) {
        echo "    - {$f}\n";
    }
    echo "\n";
    exit(1);
}

$usuarios = BaseDatos::todas(
    'SELECT u.id, u.usuario, r.nombre AS rol, u.estado
       FROM usuarios u JOIN roles r ON r.id = u.rol_id
      ORDER BY u.rol_id, u.id'
);

echo "\n  Restableciendo contraseñas con Argon2id\n";
echo '  ' . str_repeat('=', 62) . "\n\n";

$p = $svc->parametros();
echo '  Parámetros: ' . number_format($p['memory_cost'] / 1024, 0) . " MiB, "
   . "time={$p['time_cost']}, threads={$p['threads']}\n";
echo "  Un hash por usuario (tarda ~" . count($usuarios) . " x 230 ms)\n\n";

$hashes = [];

BaseDatos::enTransaccion(function () use ($usuarios, $svc, $password, $forzar, &$hashes): void {
    foreach ($usuarios as $u) {
        $hash = $svc->hashear($password);
        $hashes[] = $hash;

        BaseDatos::ejecutar(
            'UPDATE usuarios
                SET password_hash = ?, password_algoritmo = "argon2id",
                    password_actualizado_en = NOW(), requiere_cambio_password = ?,
                    intentos_fallidos = 0, bloqueado_hasta = NULL
              WHERE id = ?',
            [$hash, $forzar, $u['id']]
        );

        printf("  %-20s %-22s %s\n", $u['usuario'], $u['rol'], $u['estado']);
    }

    // Cerrar todas las sesiones abiertas: si la contraseña cambia,
    // nadie debe seguir dentro con la anterior.
    BaseDatos::ejecutar(
        'UPDATE sesiones SET revocada_en = NOW(), motivo_revocacion = "restablecimiento"
          WHERE revocada_en IS NULL'
    );
});

$unicos = count(array_unique($hashes));

echo "\n  " . str_repeat('-', 62) . "\n";
echo "  Usuarios actualizados : " . count($usuarios) . "\n";
echo "  Hashes distintos      : {$unicos} de " . count($hashes);
echo $unicos === count($hashes) ? "  (correcto: salt aleatorio)\n" : "  (PROBLEMA)\n";
echo "  Cambio obligatorio    : " . ($forzar ? 'sí' : 'no') . "\n";
echo "  " . str_repeat('-', 62) . "\n\n";
echo "  Contraseña para todos:\n\n      {$password}\n\n";
echo '  ' . str_repeat('=', 62) . "\n\n";
