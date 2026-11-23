<?php

declare(strict_types=1);

/**
 * La mora, con UNA sola cifra en todo el sistema.
 *
 * Había dos, y no coincidían:
 *
 *   · La columna MORA de la tabla de amortización y la de «cuotas por
 *     cobrar» se calculaban AL VUELO con `Mora::calcular()`: días de
 *     atraso desde el vencimiento por el cargo diario. Cuota 10 con
 *     1.374 días → $8,230.26.
 *
 *   · El desglose de la fila leía `cuotas.mora_cent`, que el devengo
 *     diario alimenta y que respeta `mora.devengar_desde`. La misma
 *     cuota 10 → $5.99.
 *
 * Dos números para el mismo recargo, en la misma pantalla.
 *
 * Y lo decisivo: `Mora::calcular()` NO es solo para enseñar. Es lo que
 * usan `ServicioCredito` y `ServicioPasarela` cuando el cliente paga de
 * verdad. O sea que el freno `mora.devengar_desde` frenaba el script de
 * devengo pero no frenaba el COBRO: al cliente se le cobraba la mora
 * completa desde el vencimiento mientras su ficha decía otra cosa.
 *
 * La cifra buena es la que se cobra. Esto alinea la columna guardada
 * con ella: $5.99 por cada día de atraso, el mismo estándar para todos
 * los productos, con el tope del saldo pendiente de la cuota.
 *
 *   php bin/normalizar-mora.php            simula
 *   php bin/normalizar-mora.php --aplicar  corrige
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\Mora;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

$aplicar = in_array('--aplicar', $argv, true);

$cargo = Mora::cargoDeUnDia();

printf("  Normalización de mora · %s por día de atraso, todos los productos\n",
    '$' . number_format($cargo / 100, 2));

$freno = Parametros::obtener('mora.devengar_desde', '');

if ($freno !== '') {
    printf("\n  OJO: `mora.devengar_desde` = %s\n", $freno);
    echo "  Ese parámetro frena el SCRIPT de devengo, pero no frena el cobro:\n";
    echo "  `ServicioCredito` y `ServicioPasarela` cobran con `Mora::calcular()`,\n";
    echo "  que cuenta desde el vencimiento. Por eso las dos cifras no coincidían.\n";
}

echo $aplicar
    ? "\n  MODO APLICAR: se reescribe mora_cent y dias_mora.\n\n"
    : "\n  Simulación. No se toca nada. (para corregir: --aplicar)\n\n";

/* Toda cuota no saldada, con sus días reales de atraso. Las pagadas no
   se tocan: su recargo, si lo hubo, ya se liquidó con la cuota. */
$filas = BaseDatos::todas(
    'SELECT q.id, q.numero, q.mora_cent, q.dias_mora, q.estado, q.asiento_id,
            q.total_cent, q.pagado_cent, q.fecha_vencimiento,
            c.folio,
            DATEDIFF(CURDATE(), q.fecha_vencimiento) AS dias
       FROM cuotas q JOIN creditos c ON c.id = q.credito_id
      WHERE q.estado NOT IN ("pagada","condonada")
      ORDER BY c.folio, q.numero'
);

$cambios  = [];
$sinTocar = 0;

foreach ($filas as $f) {
    $dias = max(0, (int) $f['dias']);

    $pendiente = Monto::deCentavos(
        max(0, (int) $f['total_cent'] - (int) $f['pagado_cent'])
    );

    // La MISMA función que usa el cobro. Si esto se calculara aquí a
    // mano volveríamos a tener dos reglas, que es el problema que se
    // está arreglando.
    $correcta = Mora::calcular($pendiente, $dias)->centavos;

    if ($correcta === (int) $f['mora_cent'] && $dias === (int) $f['dias_mora']) {
        $sinTocar++;
        continue;
    }

    $cambios[] = [
        'id'      => (int) $f['id'],
        'folio'   => (string) $f['folio'],
        'numero'  => (int) $f['numero'],
        'estado'  => (string) $f['estado'],
        'dias'    => $dias,
        'antes'   => (int) $f['mora_cent'],
        'despues' => $correcta,
    ];
}

printf("  %d cuota(s) ya coinciden con lo que se cobra.\n", $sinTocar);

if ($cambios === []) {
    exit("\n  Nada que normalizar.\n");
}

printf("\n  %d cuota(s) a corregir:\n\n", count($cambios));
printf("  %-16s %-6s %-9s %7s %14s %14s\n",
    'crédito', 'cuota', 'estado', 'días', 'guardada', 'la que se cobra');
echo "  " . str_repeat('-', 72) . "\n";

$antes = 0;
$desp  = 0;

foreach (array_slice($cambios, 0, 25) as $c) {
    printf("  %-16s %-6d %-9s %7d %14s %14s\n",
        $c['folio'], $c['numero'], $c['estado'], $c['dias'],
        '$' . number_format($c['antes'] / 100, 2),
        '$' . number_format($c['despues'] / 100, 2));
}

if (count($cambios) > 25) {
    printf("  ... y %d más\n", count($cambios) - 25);
}

foreach ($cambios as $c) {
    $antes += $c['antes'];
    $desp  += $c['despues'];
}

printf("\n  Mora guardada hoy:        %s\n", '$' . number_format($antes / 100, 2));
printf("  Mora que se cobra:        %s\n", '$' . number_format($desp / 100, 2));
printf("  Diferencia que se corrige: %s\n", '$' . number_format(($desp - $antes) / 100, 2));

echo "\n  No se cobra ni se condona nada aquí: la cifra que se cobra ya era\n";
echo "  esta. Lo que cambia es que la ficha deje de decir otra.\n";

if (!$aplicar) {
    exit("\n  No se cambió nada. Para corregir: --aplicar\n");
}

BaseDatos::enTransaccion(function () use ($cambios): void {
    foreach ($cambios as $c) {
        BaseDatos::ejecutar(
            'UPDATE cuotas SET mora_cent = ?, dias_mora = ? WHERE id = ?',
            [$c['despues'], $c['dias'], $c['id']]
        );
    }
});

Auditoria::registrar(
    'mora.normalizada',
    Auditoria::EXITO,
    0,
    'sistema',
    date('Y-m-d'),
    [
        'cuotas'       => count($cambios),
        'antes_cent'   => $antes,
        'despues_cent' => $desp,
        'motivo' => 'La columna guardada no coincidia con Mora::calcular(), '
                  . 'que es la que usan el cobro y las vistas',
    ]
);

printf("\n  %d cuota(s) corregidas.\n", count($cambios));

/* La comprobación: ninguna cuota pendiente puede diferir de lo que se
   le va a cobrar. */
$malas = 0;

foreach (BaseDatos::todas(
    'SELECT q.mora_cent, q.total_cent, q.pagado_cent,
            DATEDIFF(CURDATE(), q.fecha_vencimiento) AS dias
       FROM cuotas q WHERE q.estado NOT IN ("pagada","condonada")'
) as $q) {
    $esperada = Mora::calcular(
        Monto::deCentavos(max(0, (int) $q['total_cent'] - (int) $q['pagado_cent'])),
        max(0, (int) $q['dias'])
    )->centavos;

    if ($esperada !== (int) $q['mora_cent']) {
        $malas++;
    }
}

printf("  Cuotas donde la ficha no coincide con el cobro: %d%s\n",
    $malas, $malas === 0 ? '' : '  *** REVISAR ***');

exit($malas === 0 ? 0 : 1);
