#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ejecuta las órdenes programadas y los débitos automáticos.
 *
 * LO QUE VINO A ARREGLAR
 *
 * `tarjetas.pago_automatico` llevaba tiempo configurado por decenas de
 * clientes y NADIE lo ejecutaba. El sistema les prometía que su
 * tarjeta se pagaba sola y les corría la mora.
 *
 * Este guion es lo que faltaba. Corre dos veces al día: a primera hora
 * para lo del día, y a media tarde para reintentar lo que no pudo. Un
 * reintento al día siguiente llega tarde cuando la fecha límite es hoy.
 *
 * Uso:
 *     php bin/ejecutar-programadas.php              dice qué haría
 *     php bin/ejecutar-programadas.php --aplicar    lo hace
 *     php bin/ejecutar-programadas.php --sincronizar-tarjetas --aplicar
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioProgramadas;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;

$args    = array_slice($argv, 1);
$aplicar = in_array('--aplicar', $args, true);
$sincro  = in_array('--sincronizar-tarjetas', $args, true);

echo "\n  ÓRDENES PROGRAMADAS\n";
echo '  ' . str_repeat('=', 70) . "\n\n";

$servicio = new ServicioProgramadas();

// ---------------------------------------------------------------------
// Las tarjetas domiciliadas que nunca tuvieron orden
// ---------------------------------------------------------------------
$huerfanas = (int) BaseDatos::unValor(
    'SELECT COUNT(*) FROM tarjetas t
      WHERE t.pago_automatico IN ("minimo", "total")
        AND t.pago_automatico_cuenta_id IS NOT NULL
        AND t.estado <> "cancelada"
        AND NOT EXISTS (
            SELECT 1 FROM ordenes_programadas o
             WHERE o.tarjeta_id = t.id AND o.estado IN ("activa", "pausada")
        )'
);

if ($huerfanas > 0) {
    printf("  !! %d tarjeta(s) con pago automático y SIN orden que lo ejecute.\n", $huerfanas);
    echo "     Esos clientes creen que su tarjeta se paga sola y no se paga.\n";
    echo "     Corra con --sincronizar-tarjetas --aplicar para crearles la orden.\n\n";
}

if ($sincro) {
    $r = $servicio->sincronizarDebitoDeTarjetas($aplicar);

    printf(
        "  Tarjetas domiciliadas revisadas ......... %d\n",
        $r['revisadas']
    );
    printf(
        "  Órdenes %s ....................... %d\n",
        $aplicar ? 'creadas ' : 'que se crearían',
        $r['creadas']
    );

    echo "\n";
}

// ---------------------------------------------------------------------
// Lo que toca hoy
// ---------------------------------------------------------------------
$pendientes = BaseDatos::todas(
    'SELECT o.folio, o.alias, o.tipo, o.modo_monto, o.monto_cent, o.proxima_en,
            o.reintentos_hechos, CONCAT(u.nombres, " ", u.apellidos) AS cliente
       FROM ordenes_programadas o
       JOIN usuarios u ON u.id = o.usuario_id
      WHERE o.estado = "activa" AND o.proxima_en <= CURDATE()
      ORDER BY o.proxima_en, o.id'
);

printf("  Órdenes que tocan hoy ................... %d\n\n", count($pendientes));

foreach ($pendientes as $p) {
    printf(
        "      %s  %-10s %-24s %12s  %s%s\n",
        $p['folio'],
        $p['tipo'],
        mb_substr((string) ($p['alias'] !== '' ? $p['alias'] : $p['cliente']), 0, 24),
        $p['modo_monto'] === 'fijo'
            ? Monto::deCentavos((int) $p['monto_cent'])->formateado()
            : '(' . $p['modo_monto'] . ')',
        $p['proxima_en'],
        (int) $p['reintentos_hechos'] > 0
            ? '  reintento ' . $p['reintentos_hechos'] : ''
    );
}

if (!$aplicar) {
    echo "\n  ENSAYO: no se ejecutó ninguna. Repita con --aplicar.\n\n";
    exit(0);
}

$r = $servicio->ejecutarPendientes(true);

echo "\n";
printf("  Intentadas .............................. %d\n", $r['intentadas']);
printf("  Ejecutadas .............................. %d\n", $r['exitosas']);
printf("  Sin fondos (se reintentarán) ............ %d\n", $r['sin_fondos']);
printf("  Con problema ............................ %d\n", $r['fallidas']);

// ---------------------------------------------------------------------
// Lo que lleva fallando
// ---------------------------------------------------------------------
$problematicas = BaseDatos::todas(
    'SELECT folio, alias, fallos_consecutivos
       FROM ordenes_programadas
      WHERE estado = "activa" AND fallos_consecutivos >= 2
      ORDER BY fallos_consecutivos DESC'
);

if ($problematicas !== []) {
    echo "\n  ÓRDENES QUE LLEVAN VARIOS PERIODOS SIN PODER COBRARSE\n";
    echo '  ' . str_repeat('-', 70) . "\n";

    foreach ($problematicas as $p) {
        printf(
            "      %s  %-30s %d periodo(s) seguidos\n",
            $p['folio'],
            mb_substr((string) $p['alias'], 0, 30),
            (int) $p['fallos_consecutivos']
        );
    }

    echo "\n  Conviene llamar a esos clientes: una orden que nunca cobra\n";
    echo "  es una promesa que el sistema no está cumpliendo.\n";
}

echo "\n";
