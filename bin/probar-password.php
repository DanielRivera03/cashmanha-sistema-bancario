<?php
declare(strict_types=1);

/**
 * Comprueba que el hashing Argon2id funciona de extremo a extremo.
 * Uso:  php bin/probar-password.php "LaContraseña"
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\ServicioPassword;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por línea de comandos.\n");
}

$password = $argv[1] ?? 'CashMan#2026$Seguro';
$svc      = new ServicioPassword();

echo "\n  Verificación de Argon2id\n";
echo "  " . str_repeat('═', 68) . "\n\n";

$p = $svc->parametros();
echo "  Parámetros: memoria=" . number_format($p['memory_cost'] / 1024, 0) . " MiB"
   . "  time={$p['time_cost']}  threads={$p['threads']}\n\n";

// --- 1. Salt aleatorio por hash -------------------------------------
echo "  [1] Dos hashes de la MISMA contraseña deben ser distintos\n";
$h1 = $svc->hashear($password);
$h2 = $svc->hashear($password);
echo "      hash A: " . substr($h1, 0, 56) . "...\n";
echo "      hash B: " . substr($h2, 0, 56) . "...\n";
echo "      ¿Distintos? " . ($h1 !== $h2 ? 'SÍ — salt aleatorio correcto' : 'NO — FALLO GRAVE') . "\n";
echo "      ¿Ambos verifican? "
   . ($svc->verificar($password, $h1) && $svc->verificar($password, $h2) ? 'SÍ' : 'NO — FALLO') . "\n\n";

// --- 2. La contraseña completa cuenta -------------------------------
echo "  [2] El esquema viejo truncaba a 8 caracteres. Comprobemos que ya no\n";
$larga  = 'ContraseniaMuyLargaDe50Caracteres1234567890ABCDEF!!';
$cortada = substr($larga, 0, 8);
$hLarga = $svc->hashear($larga);
echo "      completa  : {$larga}\n";
echo "      truncada  : {$cortada}\n";
echo "      ¿La truncada abre la cuenta? "
   . ($svc->verificar($cortada, $hLarga) ? 'SÍ — FALLO GRAVE' : 'NO — correcto') . "\n\n";

// --- 3. Comparación con el esquema anterior -------------------------
echo "  [3] Contraste con el esquema DES anterior\n";
$viejoA = @crypt($larga,  sha1($larga));
$viejoB = @crypt($cortada, sha1($cortada));
echo "      DES(completa): {$viejoA}  (" . strlen((string) $viejoA) . " caracteres)\n";
echo "      DES(truncada): {$viejoB}  (" . strlen((string) $viejoB) . " caracteres)\n";
echo "      Argon2id     : " . strlen($hLarga) . " caracteres\n\n";

// --- 4. Hashes reales de la base ------------------------------------
echo "  [4] Verificación contra los usuarios migrados\n";
$usuarios = BaseDatos::todas(
    'SELECT u.usuario, u.password_hash, u.password_algoritmo, r.nombre AS rol
       FROM usuarios u JOIN roles r ON r.id = u.rol_id
      ORDER BY u.rol_id, u.id'
);

$ok = 0;
$distintos = [];
foreach ($usuarios as $u) {
    $valido = $svc->verificar($password, (string) $u['password_hash'], (string) $u['password_algoritmo']);
    $distintos[] = $u['password_hash'];
    if ($valido) {
        $ok++;
    }
    printf("      %-20s %-22s %s\n", $u['usuario'], $u['rol'], $valido ? 'verifica' : 'FALLA');
}

echo "\n      {$ok} de " . count($usuarios) . " verifican con la misma contraseña\n";
echo "      Hashes únicos en base: " . count(array_unique($distintos)) . " de " . count($distintos);
echo (count(array_unique($distintos)) === count($distintos)
     ? "  — ningún hash se repite, como debe ser\n\n"
     : "  — HAY HASHES REPETIDOS, FALLO\n\n");

// --- 5. Contraseña incorrecta ---------------------------------------
echo "  [5] Una contraseña incorrecta debe fallar\n";
$mala = $password . 'x';
$primero = $usuarios[0] ?? null;
if ($primero !== null) {
    $r = $svc->verificar($mala, (string) $primero['password_hash'], (string) $primero['password_algoritmo']);
    echo "      '{$mala}' → " . ($r ? 'ACEPTADA — FALLO GRAVE' : 'rechazada — correcto') . "\n\n";
}

// --- 6. Tiempo -------------------------------------------------------
echo "  [6] Costo por verificación\n";
$ini = microtime(true);
$svc->verificar($password, $h1);
printf("      %.1f ms por intento\n", (microtime(true) - $ini) * 1000);
printf("      Un atacante con 1000 GPUs probaría ~%s claves/segundo\n",
       number_format(1000 / ((microtime(true) - $ini)) ));
echo "      Con DES de 8 caracteres eran miles de millones por segundo.\n\n";

echo "  " . str_repeat('═', 68) . "\n\n";
