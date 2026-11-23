<?php

declare(strict_types=1);

/**
 * El abono mensual de intereses a las cuentas de ahorro.
 *
 * Los productos de depósito declaraban su tasa desde el primer día y no
 * había nada que la pagara. Esto es lo que la paga.
 *
 * Va el último día de cada mes. Como todo lo que mueve dinero,
 * **simula por omisión**: sin bandera calcula y enseña, no abona.
 *
 *   php bin/abonar-intereses.php                 simula el mes en curso
 *   php bin/abonar-intereses.php --hasta=2026-10-31   simula ese período
 *   php bin/abonar-intereses.php --abonar        abona de verdad
 *
 * Correrlo dos veces el mismo período no paga dos veces: `cuenta_intereses`
 * tiene índice único por (cuenta, fin de período) y la clave de
 * idempotencia del asiento lleva las mismas dos cosas.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioInteresDeposito;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;

$abonar = in_array('--abonar', $argv, true);

$hasta = date('Y-m-t');   // último día del mes en curso

foreach ($argv as $a) {
    if (str_starts_with($a, '--hasta=')) {
        $hasta = substr($a, 8);
    }
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
    exit("  La fecha debe venir como --hasta=AAAA-MM-DD\n");
}

/* Un período que todavía no ha cerrado se puede SIMULAR —sirve para ver
   por dónde va el mes— pero NO se puede abonar.
 
   Abonarlo pagaría los días que faltan dando por hecho que el saldo no
   se mueve, y el cliente podría cobrar el interés del mes completo y
   retirarlo todo al día siguiente. Me pasó: aboné treinta días de un mes
   que llevaba once, y hubo que reversar cinco asientos. */
if ($abonar && $hasta >= date('Y-m-d')) {
    printf("  El período termina el %s y hoy es %s.\n", $hasta, date('Y-m-d'));
    echo "  No se abona un período que no ha cerrado.\n";
    echo "  Simule sin --abonar, o espere a que termine el mes.\n";

    /* Sale con CERO: negarse a pagar un periodo abierto no es un fallo,
       es la guarda haciendo su trabajo. Devolver error haría que el
       planificador lo contara como avería todos los días del mes menos
       uno, y las averías de verdad se perderían entre el ruido. */
    exit(0);
}
printf("  Intereses de ahorro · período que termina el %s\n", $hasta);
echo $abonar
    ? "  MODO ABONAR: se registran asientos y se abona a los clientes.\n\n"
    : "  Simulación. No se abona nada. (para abonar: --abonar)\n\n";

$svc   = new ServicioInteresDeposito();
$filas = $svc->calcular($hasta);

if ($filas === []) {
    exit("  Ninguna cuenta genera interés en este período.\n"
       . "  (puede ser que ya se abonó, que el saldo promedio no llegue al mínimo,\n"
       . "   o que no haya cuentas de ahorro activas)\n");
}

printf("  %-14s %-26s %-12s %14s %7s %10s %10s\n",
    'cuenta', 'titular', 'segmento', 'saldo prom.', 'tasa', 'interés', 'neto');
echo "  " . str_repeat('-', 100) . "\n";

$bruto = 0;
$ret   = 0;
$neto  = 0;

foreach ($filas as $f) {
    printf("  %-14s %-26s %-12s %14s %6.2f%% %10s %10s%s\n",
        $f['numero_cuenta'],
        mb_substr((string) $f['titular'], 0, 26),
        $f['segmento'],
        Monto::deCentavos((int) $f['saldo_promedio_cent'])->formateado(),
        $f['tasa_aplicada_bp'] / 100,
        Monto::deCentavos((int) $f['interes_bruto_cent'])->formateado(),
        Monto::deCentavos((int) $f['interes_neto_cent'])->formateado(),
        (int) $f['tasa_extra_bp'] > 0
            ? '  (+' . number_format((int) $f['tasa_extra_bp'] / 100, 2) . ' por su categoría)'
            : ''
    );

    $bruto += (int) $f['interes_bruto_cent'];
    $ret   += (int) $f['retencion_cent'];
    $neto  += (int) $f['interes_neto_cent'];
}

printf("\n  %d cuenta(s) · interés bruto %s · retenido %s · abonado %s\n",
    count($filas),
    Monto::deCentavos($bruto)->formateado(),
    Monto::deCentavos($ret)->formateado(),
    Monto::deCentavos($neto)->formateado());

if (!$abonar) {
    exit("\n  No se abonó nada. Para abonar: --abonar\n");
}

$r = $svc->abonar($filas, null);

printf("\n  Abonadas: %d · total %s\n",
    $r['abonadas'], Monto::deCentavos($r['interes_cent'])->formateado());

/* Lo que no puede fallar: que el libro siga cuadrado. Cada abono lleva
   su contrapartida contra la técnica de gasto; si alguno no la llevara,
   aquí saldría. */
$desc = (int) BaseDatos::unValor('SELECT COUNT(*) FROM v_asientos_descuadrados');

printf("  Asientos descuadrados: %d%s\n", $desc, $desc === 0 ? '' : '  *** REVISAR ***');

$tec = BaseDatos::unaFila(
    'SELECT numero_cuenta, saldo_cent FROM cuentas WHERE numero_cuenta = ?',
    [\CashMan\Infraestructura\Parametros::obtener('contabilidad.cuenta_intereses_deposito', '0000000004')]
);

printf("  Cuenta técnica %s: %s de gasto acumulado por intereses\n",
    $tec['numero_cuenta'],
    Monto::deCentavos(abs((int) $tec['saldo_cent']))->formateado());

exit($desc === 0 ? 0 : 1);
