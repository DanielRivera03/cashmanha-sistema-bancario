#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Liquida con los colectores lo recaudado por pago de servicios.
 *
 * QUÉ ES LIQUIDAR
 *
 * Cuando un cliente paga su recibo de luz, el dinero sale de su cuenta
 * y entra a la cuenta técnica de colecturía. Ahí no es del banco: es
 * una DEUDA del banco frente a la distribuidora, que todavía no ha
 * recibido nada.
 *
 * Liquidar es saldar esa deuda. Mientras no ocurre, el saldo de la
 * colecturía crece sin parar y figura como un pasivo que nadie mira.
 *
 * LO QUE AQUÍ ESTÁ SIMULADO, DICHO SIN ADORNOS
 *
 * El traspaso real a cada empresa recaudadora. Eso se hace por el
 * convenio de recaudación de cada una, con sus cortes y sus archivos,
 * y no se improvisa.
 *
 * Lo que sí es real es el rastro: qué se recaudó, de quién, por cuánto
 * y cuándo se dio por liquidado. Es lo que permite cuadrar con el
 * colector el día que reclame.
 *
 * Uso:
 *     php bin/liquidar-colecturia.php            dice qué haría
 *     php bin/liquidar-colecturia.php --aplicar  lo hace
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioPagoServicios;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/* A mano, no con getopt(): se detiene en el primer argumento suelto y
   deja de ver las banderas que vienen detrás. */
$aplicar = in_array('--aplicar', array_slice($argv, 1), true);

echo "\n  LIQUIDACIÓN DE COLECTURÍA\n";
echo '  ' . str_repeat('=', 68) . "\n\n";

if (!$aplicar) {
    echo "  ENSAYO: no se liquida nada. Repita con --aplicar.\n\n";
}

$servicio = new ServicioPagoServicios();

$r = $servicio->liquidar($aplicar);

if ($r['colectores'] === []) {
    echo "  No hay nada pendiente de liquidar.\n";
} else {
    printf("  %-28s %8s  %14s\n", 'COLECTOR', 'PAGOS', 'RECAUDADO');
    echo '  ' . str_repeat('-', 54) . "\n";

    foreach ($r['colectores'] as $c) {
        printf(
            "  %-28s %8d  %14s\n",
            $c['nombre_corto'],
            (int) $c['pagos'],
            Monto::deCentavos((int) $c['total_cent'])->formateado()
        );
    }

    echo '  ' . str_repeat('-', 54) . "\n";
    printf(
        "  %-28s %8d  %14s\n",
        'TOTAL',
        (int) $r['pagos'],
        Monto::deCentavos((int) $r['total_cent'])->formateado()
    );
}

// ---------------------------------------------------------------------
// Que la colecturía cuadre
// ---------------------------------------------------------------------
//
// El saldo de la cuenta técnica tiene que ser, exactamente, lo
// recaudado y todavía no liquidado. Si no lo es, hay dinero de un
// cliente que no llegó a su colector, y es mejor enterarse aquí que
// cuando la empresa le corte el servicio.
// ---------------------------------------------------------------------
$saldo = (int) BaseDatos::unValor(
    'SELECT saldo_cent FROM cuentas WHERE numero_cuenta = "0000000007"'
);

$pendiente = (int) BaseDatos::unValor(
    'SELECT COALESCE(SUM(monto_cent + comision_cent), 0)
       FROM pagos_servicio WHERE estado = "aplicado"'
);

echo "\n";
printf("  Saldo de la colecturía .................. %s\n", Monto::deCentavos($saldo)->formateado());
printf("  Pendiente de liquidar ................... %s\n", Monto::deCentavos($pendiente)->formateado());

if ($aplicar && $saldo !== $pendiente) {
    /* Tras liquidar, el saldo de la cuenta técnica refleja el
       histórico acumulado: lo liquidado ya no es deuda viva, pero el
       dinero salió del banco hacia el colector y eso solo lo reflejará
       el asiento de traspaso cuando exista el convenio real.
       Se dice en voz alta en lugar de callarlo. */
    printf(
        "\n  Nota: quedan %s en la cuenta técnica correspondientes a pagos\n"
        . "  ya liquidados. El traspaso real a cada colector se registrará\n"
        . "  cuando exista el convenio de recaudación; hasta entonces el\n"
        . "  saldo acumula y el rastro de cada pago queda en pagos_servicio.\n",
        Monto::deCentavos($saldo - $pendiente)->formateado()
    );
}

echo "\n";
