<?php
declare(strict_types=1);

/**
 * Gestión diaria de cobranza.
 *
 * Hace dos cosas, en este orden:
 *
 *   1. SINCRONIZA los expedientes: abre uno por cada crédito que entró
 *      en mora y mueve de etapa los que acumularon días. La etapa decide
 *      quién lleva el caso —la agencia hasta administrativa, jurídico
 *      desde extrajudicial— y qué carta le toca al deudor.
 *
 *   2. ENVÍA los correos que correspondan hoy, según la etapa y los días
 *      de atraso de cada caso.
 *
 * Por qué no se manda todo de golpe: un sistema que escribe cartas de
 * cobro solo puede hacer mucho daño si se equivoca. Antes de cada envío
 * se vuelve a comprobar la deuda contra las cuotas reales, porque entre
 * la sincronización y el correo el cliente puede haber pagado, y no hay
 * peor error de cobranza que exigirle a alguien lo que ya pagó.
 *
 * Uso:
 *   php bin/cobranza.php                      → simula, no escribe nada
 *   php bin/cobranza.php --aplicar            → abre y escala expedientes
 *   php bin/cobranza.php --aplicar --correos  → además manda los correos
 *   php bin/cobranza.php --correos            → simula solo los correos
 *   php bin/cobranza.php --limite=20          → tope de correos por corrida
 *
 * En el Programador de tareas de Windows, una vez al día por la mañana:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\cashmanha-v2\bin\cobranza.php --aplicar --correos
 *
 * Sin `--aplicar` no toca la base de datos ni manda un solo correo: solo
 * enseña qué haría. Conviene mirarlo antes de la primera corrida de
 * verdad, sobre todo por el volumen de correos.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioCobranza;
use CashMan\Infraestructura\Parametros;

$argumentos = $argv ?? [];
$aplicar    = in_array('--aplicar', $argumentos, true);
$conCorreos = in_array('--correos', $argumentos, true);

$limite = 100;

foreach ($argumentos as $a) {
    if (str_starts_with($a, '--limite=')) {
        $limite = max(1, min(500, (int) substr($a, 9)));
    }
}

$linea = str_repeat('-', 68);

echo "\n  COBRANZA — " . date('d/m/Y H:i') . "\n  {$linea}\n";
printf(
    "  Modo: %s%s\n",
    $aplicar ? 'APLICAR (escribe en la base)' : 'SIMULACION (no escribe nada)',
    $conCorreos ? ($aplicar ? ' · MANDA CORREOS' : ' · simula correos') : ''
);

printf(
    "  Etapas: administrativa desde %d dia(s) · extrajudicial desde %d · judicial desde %d\n",
    Parametros::entero('cobranza.dias_administrativa', 1),
    Parametros::entero('cobranza.dias_extrajudicial', 61),
    Parametros::entero('cobranza.dias_judicial', 121)
);

echo "  {$linea}\n\n";

$svc = new ServicioCobranza();

// --- 1. Expedientes --------------------------------------------------
$r = $svc->sincronizar($aplicar);

printf("  Creditos en mora encontrados : %d\n", $r['morosos']);
printf("  Expedientes nuevos           : %d\n", $r['abiertos']);
printf("  Expedientes actualizados     : %d\n", $r['actualizados']);
printf("  Promesas marcadas incumplidas: %d\n", $r['promesas_incumplidas']);

if ($r['escalados'] !== []) {
    echo "\n  Cambios de etapa:\n";

    foreach ($r['escalados'] as $e) {
        printf(
            "    %-16s %-14s %-14s -> %-14s (%d dias)\n",
            $e['folio'],
            $e['credito'],
            $e['de'],
            $e['a'],
            $e['dias']
        );
    }
}

// --- 2. Correos ------------------------------------------------------
if ($conCorreos) {
    echo "\n  {$linea}\n  CORREOS\n  {$linea}\n";

    $c = $svc->enviarCorreos($aplicar, $limite);

    if ($c['enviados'] === []) {
        echo "\n  No corresponde escribirle a nadie hoy.\n";
    } else {
        printf("\n  %s %d correo(s):\n\n", $aplicar ? 'Enviados:' : 'Se enviarian', count($c['enviados']));

        foreach ($c['enviados'] as $e) {
            printf(
                "    %-16s %-14s %-14s %-20s %10s  %4d dias\n",
                $e['folio'],
                $e['credito'],
                $e['etapa'],
                $e['plantilla'],
                $e['total'],
                $e['dias']
            );
            printf("      -> %s\n", $e['correo']);
        }
    }

    if ($c['saltados'] !== []) {
        printf("\n  No se les escribio (%d):\n", count($c['saltados']));

        foreach ($c['saltados'] as $s) {
            printf("    %-16s %s\n", $s['folio'], $s['motivo']);
        }
    }
}

echo "\n  {$linea}\n";

if (!$aplicar) {
    echo "  Nada de esto se guardo. Vuelva a correrlo con --aplicar para que surta efecto.\n";
}

echo "\n";
