<?php
declare(strict_types=1);

/**
 * Calibra los parámetros de Argon2id para ESTE hardware.
 *
 * Los parámetros no se copian de un tutorial: se miden. Demasiado
 * bajos y el atacante prueba millones de contraseñas por segundo;
 * demasiado altos y el propio login se convierte en un vector de
 * denegación de servicio.
 *
 * Objetivo: 250-400 ms por hash.
 *
 * Uso:  php bin/calibrar-argon2.php
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por línea de comandos.\n");
}

echo "\n";
echo "  Calibración de Argon2id\n";
echo "  " . str_repeat('─', 62) . "\n";
echo "  PHP " . PHP_VERSION . "  |  " . php_uname('m') . "\n";
echo "  Objetivo: 250-400 ms por hash\n";
echo "  " . str_repeat('─', 62) . "\n\n";

if (!defined('PASSWORD_ARGON2ID')) {
    exit("  ERROR: este PHP no soporta Argon2id.\n\n");
}

$combinaciones = [
    ['m' => 19456,  't' => 2, 'p' => 1],  // mínimo OWASP
    ['m' => 32768,  't' => 2, 'p' => 1],
    ['m' => 32768,  't' => 3, 'p' => 2],
    ['m' => 65536,  't' => 2, 'p' => 2],
    ['m' => 65536,  't' => 3, 'p' => 2],  // por defecto propuesto
    ['m' => 65536,  't' => 4, 'p' => 2],
    ['m' => 131072, 't' => 3, 'p' => 2],
];

printf("  %-10s %-6s %-5s %12s %s\n", 'memoria', 'time', 'thr', 'tiempo', 'veredicto');
echo "  " . str_repeat('─', 62) . "\n";

$recomendado = null;

foreach ($combinaciones as $c) {
    $opciones = [
        'memory_cost' => $c['m'],
        'time_cost'   => $c['t'],
        'threads'     => $c['p'],
    ];

    // Tres mediciones, se toma la mediana: amortigua el ruido del sistema.
    $tiempos = [];
    for ($i = 0; $i < 3; $i++) {
        $ini = microtime(true);
        password_hash('referencia-de-calibracion-' . $i, PASSWORD_ARGON2ID, $opciones);
        $tiempos[] = (microtime(true) - $ini) * 1000;
    }
    sort($tiempos);
    $ms = $tiempos[1];

    if ($ms < 100) {
        $veredicto = 'demasiado rápido';
    } elseif ($ms <= 250) {
        $veredicto = 'aceptable';
    } elseif ($ms <= 400) {
        $veredicto = '◄ RECOMENDADO';
        $recomendado ??= $c;
    } elseif ($ms <= 700) {
        $veredicto = 'lento pero viable';
    } else {
        $veredicto = 'riesgo de DoS';
    }

    printf(
        "  %-10s %-6d %-5d %9.1f ms  %s\n",
        number_format($c['m'] / 1024, 0) . ' MiB',
        $c['t'],
        $c['p'],
        $ms,
        $veredicto
    );
}

echo "  " . str_repeat('─', 62) . "\n\n";

if ($recomendado !== null) {
    echo "  Valores sugeridos para .env:\n\n";
    echo "    ARGON_MEMORY_COST={$recomendado['m']}\n";
    echo "    ARGON_TIME_COST={$recomendado['t']}\n";
    echo "    ARGON_THREADS={$recomendado['p']}\n\n";
} else {
    echo "  Ninguna combinación cayó en la franja ideal.\n";
    echo "  Si todas van rápido, sube memory_cost. Si todas van lentas,\n";
    echo "  bájalo: más vale 200 ms que un login que se cae bajo carga.\n\n";
}
