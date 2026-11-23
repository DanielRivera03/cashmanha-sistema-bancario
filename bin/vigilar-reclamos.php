#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Vigila los plazos legales de los reclamos.
 *
 * Un reclamo que vence sin que nadie lo note no es un retraso interno:
 * es un incumplimiento, y el cliente puede llevarlo a la Defensoría
 * del Consumidor con esa fecha en la mano.
 *
 * Por eso avisa ANTES, no después.
 *
 * Uso:
 *     php bin/vigilar-reclamos.php            dice qué hay
 *     php bin/vigilar-reclamos.php --aplicar  avisa al área
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioNotificacion;
use CashMan\Aplicacion\Servicio\ServicioReclamos;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;

$aplicar = in_array('--aplicar', array_slice($argv, 1), true);

echo "\n  PLAZOS DE RECLAMOS\n";
echo '  ' . str_repeat('=', 74) . "\n\n";

$servicio = new ServicioReclamos();

$p = $servicio->panorama();

printf("  Vencidos (fuera del plazo legal) ........ %d\n", count($p['vencidos']));
printf("  Por vencer en los próximos días ......... %d\n", count($p['por_vencer']));
printf("  Esperando al cliente demasiado .......... %d\n\n", count($p['esperando']));

foreach ($p['vencidos'] as $r) {
    printf(
        "  !! %s  %-26s vencido hace %d día(s)  %s\n",
        $r['folio'],
        mb_substr((string) $r['cliente'], 0, 26),
        (int) $r['dias'],
        (int) $r['monto_cent'] > 0
            ? Monto::deCentavos((int) $r['monto_cent'])->formateado() : ''
    );
}

foreach ($p['por_vencer'] as $r) {
    printf(
        "     %s  %-26s vence en %d día(s)\n",
        $r['folio'],
        mb_substr((string) $r['cliente'], 0, 26),
        (int) $r['dias']
    );
}

if (!$aplicar) {
    echo "\n  ENSAYO: no se avisó a nadie. Repita con --aplicar.\n\n";
    exit(count($p['vencidos']) > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------
// Avisar a quien tiene que actuar
// ---------------------------------------------------------------------
//
// A los asignados, y si no hay asignado, a quienes pueden atender. Un
// aviso que no llega a nadie concreto no lo atiende nadie.
// ---------------------------------------------------------------------
$notif = new ServicioNotificacion();

$avisados = 0;

foreach (array_merge($p['vencidos'], $p['por_vencer']) as $r) {
    $destinos = [];

    if ($r['asignado_a'] !== null) {
        $destinos[] = (int) $r['asignado_a'];
    } else {
        foreach (BaseDatos::todas(
            'SELECT DISTINCT u.id FROM usuarios u
               JOIN rol_permiso rp ON rp.rol_id = u.rol_id
               JOIN permisos p ON p.id = rp.permiso_id
              WHERE p.clave = "reclamo.atender" AND u.estado = "activo"'
        ) as $u) {
            $destinos[] = (int) $u['id'];
        }
    }

    $vencido = (int) $r['dias'] > 0 && $r['vence_en'] < date('Y-m-d');

    foreach ($destinos as $d) {
        $notif->crear(
            $d,
            'reclamo.plazo',
            $vencido ? 'Reclamo FUERA de plazo' : 'Reclamo por vencer',
            'El reclamo ' . $r['folio'] . ' de ' . $r['cliente']
            . ($vencido
                ? ' venció hace ' . (int) $r['dias'] . ' día(s).'
                : ' vence en ' . (int) $r['dias'] . ' día(s).')
            . ' Asunto: ' . $r['asunto'],
            ['folio' => $r['folio']],
            $vencido ? 'critico' : 'alerta'
        );

        $avisados++;
    }
}

printf("  Avisos enviados ......................... %d\n", $avisados);

if ($p['esperando'] !== []) {
    echo "\n  RECLAMOS QUE LLEVAN DEMASIADO ESPERANDO AL CLIENTE\n";
    echo '  ' . str_repeat('-', 74) . "\n";

    foreach ($p['esperando'] as $r) {
        printf(
            "     %s  %-26s %d día(s) sin contestar\n",
            $r['folio'],
            mb_substr((string) $r['cliente'], 0, 26),
            (int) $r['dias']
        );
    }

    echo "\n  Conviene resolverlos con lo que haya: no se puede tener un\n";
    echo "  reclamo abierto indefinidamente esperando una respuesta.\n";
}

echo "\n";
