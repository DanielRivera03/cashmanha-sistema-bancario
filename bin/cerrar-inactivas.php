#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Barrido de cuentas inactivas.
 *
 * Dos pasadas en una:
 *
 *   1. AVISA a los titulares de las cuentas que llevan el plazo en
 *      cero y sin movimientos. Una sola vez por cuenta.
 *
 *   2. CIERRA las que ya fueron avisadas y dejaron pasar los días de
 *      gracia sin mover nada.
 *
 * El número de las cerradas se retira y no vuelve a asignarse.
 *
 * Pensado para correr una vez al día. Pasarlo dos veces el mismo día
 * no avisa ni cierra dos veces: lo ya avisado lleva su marca y lo ya
 * cerrado deja de aparecer.
 *
 * Uso:
 *     php bin/cerrar-inactivas.php            avisa y cierra
 *     php bin/cerrar-inactivas.php --ensayo   solo dice qué haría
 */

require_once __DIR__ . '/../src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioCierreInactividad;

$ensayo = in_array('--ensayo', $argv, true) || in_array('--dry-run', $argv, true);

$svc = new ServicioCierreInactividad();

echo "\n  Cuentas inactivas" . ($ensayo ? ' (ENSAYO, no aplica nada)' : '') . "\n";
echo '  ' . str_repeat('-', 68) . "\n";

if (!$svc->activo()) {
    echo "\n  El cierre automatico esta apagado (cuenta.cierre_automatico = 0).\n\n";
    exit(0);
}

printf(
    "  Plazo: %d meses en cero y sin movimientos. Aviso: %d dias antes.\n\n",
    $svc->mesesDeInactividad(),
    $svc->diasDeAviso()
);

// --- 1. Las que hay que avisar ---------------------------------------

$porAvisar = $svc->porAvisar();

if ($porAvisar === []) {
    echo "  Ninguna cuenta nueva que avisar.\n";
} else {
    printf("  %-14s %-28s %s\n", 'CUENTA', 'TITULAR', 'SIN MOVIMIENTO DESDE');

    foreach ($porAvisar as $c) {
        printf(
            "  %-14s %-28s %s\n",
            $c['numero_cuenta'],
            mb_substr(trim($c['nombres'] . ' ' . $c['apellidos']), 0, 28),
            substr((string) ($c['ultimo_movimiento'] ?? $c['apertura_en']), 0, 10)
        );

        if (!$ensayo) { $svc->avisar($c); }
    }

    printf(
        "\n  %d cuenta(s) %s.\n",
        count($porAvisar),
        $ensayo ? 'se avisarian' : 'avisadas'
    );
}

// --- 2. Las que ya toca cerrar ---------------------------------------

$porCerrar = $svc->porCerrar();

echo "\n";

if ($porCerrar === []) {
    echo "  Ninguna cuenta que cerrar.\n";
} else {
    printf("  %-14s %-28s %s\n", 'CUENTA', 'TITULAR', 'AVISADA EL');

    $cerradas = 0;

    foreach ($porCerrar as $c) {
        $ok = $ensayo ? true : $svc->cerrar($c);

        printf(
            "  %-14s %-28s %s%s\n",
            $c['numero_cuenta'],
            mb_substr(trim($c['nombres'] . ' ' . $c['apellidos']), 0, 28),
            substr((string) $c['aviso_inactividad_en'], 0, 10),
            $ok ? '' : '   (no se cerro: tenia saldo o cambio de estado)'
        );

        if ($ok) { $cerradas++; }
    }

    printf(
        "\n  %d cuenta(s) %s. Sus numeros quedan retirados.\n",
        $cerradas,
        $ensayo ? 'se cerrarian' : 'cerradas'
    );
}

echo "\n";
