#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Liquida o renueva los depósitos a plazo que vencieron.
 *
 * Un plazo fijo que vence y nadie toca no puede quedarse quieto: el
 * cliente ya cumplió su parte —dejó el dinero el tiempo pactado— y
 * retenérselo un día más sería quedarse con lo que no es nuestro.
 *
 * Según lo que pidió al abrirlo:
 *   · «no»                → se le devuelve todo a su cuenta
 *   · «capital»           → se renueva el capital, los intereses van a su cuenta
 *   · «capital_intereses» → se renueva todo
 *
 * Y avisa a quien le vence pronto. Antes, no después: quien tiene que
 * decidir si renueva necesita saberlo con tiempo.
 *
 * Uso:
 *     php bin/vencer-plazos.php            dice qué haría
 *     php bin/vencer-plazos.php --aplicar  lo hace
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioNotificacion;
use CashMan\Aplicacion\Servicio\ServicioPlazoFijo;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Plazo\CalculoPlazo;
use CashMan\Infraestructura\Persistencia\BaseDatos;

$aplicar = in_array('--aplicar', array_slice($argv, 1), true);

echo "\n  DEPÓSITOS A PLAZO\n";
echo '  ' . str_repeat('=', 74) . "\n\n";

$servicio = new ServicioPlazoFijo();

$vencidos = $servicio->vencidos();

printf("  Vencidos sin liquidar ................... %d\n\n", count($vencidos));

$liquidados = 0;
$renovados  = 0;

foreach ($vencidos as $d) {
    $p = CalculoPlazo::proyeccion(
        (int) $d['monto_cent'],
        (int) $d['tasa_bp'],
        (int) $d['plazo_dias']
    );

    printf(
        "      %s  %12s  venció el %s  →  %s\n",
        $d['folio'],
        Monto::deCentavos((int) $d['monto_cent'])->formateado(),
        $d['fecha_vence'],
        $d['renovacion'] === 'no' ? 'devolver ' . Monto::deCentavos((int) $p['total_cent'])->formateado()
                                  : 'renovar (' . $d['renovacion'] . ')'
    );

    if (!$aplicar) {
        continue;
    }

    try {
        if ($d['renovacion'] === 'no') {
            $servicio->liquidar((string) $d['folio'], null, false);
            $liquidados++;
        } else {
            $servicio->renovar((string) $d['folio'], null);
            $renovados++;
        }
    } catch (\RuntimeException $e) {
        printf("        !! %s\n", $e->getMessage());
    }
}

if ($aplicar) {
    printf("\n  Liquidados .............................. %d\n", $liquidados);
    printf("  Renovados ............................... %d\n", $renovados);
}

// ---------------------------------------------------------------------
// Los que vencen pronto
// ---------------------------------------------------------------------
$pronto = $servicio->porVencer(7);

printf("\n  Vencen en los próximos 7 días ........... %d\n", count($pronto));

if ($aplicar && $pronto !== []) {
    $notif = new ServicioNotificacion();

    foreach ($pronto as $d) {
        /* Una sola vez por depósito: avisar cada día de los siete
           convierte un recordatorio útil en algo que se ignora. */
        $yaAvisado = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM notificaciones
              WHERE usuario_id = ? AND tipo = "plazo.por_vencer"
                AND datos_json LIKE ?',
            [(int) $d['usuario_id'], '%' . $d['folio'] . '%']
        );

        if ($yaAvisado > 0) {
            continue;
        }

        $p = CalculoPlazo::proyeccion(
            (int) $d['monto_cent'],
            (int) $d['tasa_bp'],
            (int) $d['plazo_dias']
        );

        $notif->crear(
            (int) $d['usuario_id'],
            'plazo.por_vencer',
            'Su depósito a plazo vence pronto',
            'El depósito ' . $d['folio'] . ' por '
            . Monto::deCentavos((int) $d['monto_cent'])->formateado()
            . ' vence el ' . date('d/m/Y', (int) strtotime((string) $d['fecha_vence']))
            . ' y recibirá ' . Monto::deCentavos((int) $p['total_cent'])->formateado() . '. '
            . ($d['renovacion'] === 'no'
                ? 'Se abonará a su cuenta.'
                : 'Se renovará automáticamente.'),
            ['folio' => $d['folio']],
            'info'
        );
    }
}

echo "\n";
