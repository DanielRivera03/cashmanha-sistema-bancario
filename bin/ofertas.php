<?php
declare(strict_types=1);

/**
 * Evaluación de la cartera y envío de ofertas preaprobadas.
 *
 * Pensado para correr una vez al día, de madrugada. Mira quién reúne
 * las condiciones —cuotas pagadas, sin atrasos, sin cuotas vencidas y
 * sin gestión de cobro abierta—, calcula lo que le cabe según su
 * capacidad de pago real y le manda la oferta por correo.
 *
 * Caduca de paso las que pasaron de fecha: una oferta vencida que
 * sigue apareciendo vigente es una promesa que se incumple al pulsar.
 *
 *   php bin/ofertas.php              evalúa y envía
 *   php bin/ofertas.php --sin-correo evalúa sin enviar (para revisar)
 */

require __DIR__ . '/../src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioOfertaPreaprobada;
use CashMan\Dominio\Comun\Monto;

$enviar = !in_array('--sin-correo', $argv, true);

$svc = new ServicioOfertaPreaprobada();

$caducadas = $svc->caducarVencidas();

if ($caducadas > 0) {
    printf("  %d oferta(s) caducada(s) por vencimiento.\n", $caducadas);
}

$r = $svc->evaluar(null, $enviar);

printf(
    "  Evaluados %d cliente(s). Ofertas nuevas: %d.%s\n",
    $r['evaluados'],
    $r['ofertas'],
    $enviar ? '' : ' (no se envió correo)'
);

foreach ($r['detalle'] as $o) {
    printf(
        "    %-18s %-8s %12s  %s\n",
        $o['folio'],
        $o['tipo'],
        Monto::deCentavos((int) $o['monto_cent'])->formateado(),
        $o['motivo']
    );
}

exit(0);
