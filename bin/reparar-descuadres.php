<?php

declare(strict_types=1);

/**
 * Repara los tres descuadres que encontró `bin/verificar-cuadre.php`.
 *
 * Los tres venían de antes y cada uno tiene su causa distinta. En los
 * tres, el criterio es el mismo: **se repara el dato que está mal, no
 * se ajusta el que está bien para que cuadre**. Cuadrar moviendo la
 * cifra correcta es tapar el problema con el problema.
 *
 *   1. CR-000011 · $39,321.37
 *      La tabla de amortización es coherente: lo pagado más lo
 *      pendiente suma exactamente el monto desembolsado. El que está
 *      fuera es `saldo_capital_cent`, un valor sembrado que nunca
 *      correspondió a la tabla. Y no hay UN SOLO asiento contable
 *      asociado al crédito, así que corregirlo no mueve dinero.
 *      → se corrige el saldo del crédito.
 *
 *   2. Cuenta 001141910574 · $250.00
 *      El primer movimiento de la cuenta ya partía de un saldo de $250
 *      que ningún movimiento explica: se sembró el saldo sin sembrar
 *      su origen. Bajar el saldo sería quitarle $250 a la clienta por
 *      un defecto de los datos de prueba.
 *      → se anota el depósito de apertura que falta, con su asiento.
 *
 *   3. Tarjeta ····5350 · $2.27
 *      El corte del 09/11 cobró $2.27 de seguros, los sumó al saldo y
 *      no creó el movimiento. Eso ya está arreglado en
 *      `ServicioCorteTarjeta` para los cortes futuros; aquí se anota
 *      el que falta.
 *      → se anota el cargo del corte que falta.
 *
 *   php bin/reparar-descuadres.php            simula
 *   php bin/reparar-descuadres.php --aplicar  repara
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

$aplicar = in_array('--aplicar', $argv, true);

printf("  Reparación de descuadres · %s\n\n", $aplicar ? 'APLICANDO' : 'simulación');

$hechos = [];

// =====================================================================
// 1. El saldo de capital de CR-000011
// =====================================================================

$c = BaseDatos::unaFila(
    'SELECT id, folio, monto_cent, saldo_capital_cent FROM creditos WHERE folio = ?',
    ['CR-000011']
);

if ($c !== null) {
    $pendiente = (int) BaseDatos::unValor(
        'SELECT COALESCE(SUM(capital_cent),0) FROM cuotas
          WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")',
        [(int) $c['id']]
    );

    $pagado = (int) BaseDatos::unValor(
        'SELECT COALESCE(SUM(capital_cent),0) FROM cuotas
          WHERE credito_id = ? AND estado = "pagada"',
        [(int) $c['id']]
    );

    $asientos = (int) BaseDatos::unValor(
        'SELECT COUNT(*) FROM cuotas WHERE credito_id = ? AND asiento_id IS NOT NULL',
        [(int) $c['id']]
    );

    echo "  1. Saldo de capital de " . $c['folio'] . "\n";
    printf("     monto desembolsado      %16s\n", '$' . number_format((int) $c['monto_cent'] / 100, 2));
    printf("     capital ya pagado       %16s\n", '$' . number_format($pagado / 100, 2));
    printf("     capital por cobrar      %16s  <- la tabla\n", '$' . number_format($pendiente / 100, 2));
    printf("     pagado + pendiente      %16s  %s\n",
        '$' . number_format(($pagado + $pendiente) / 100, 2),
        $pagado + $pendiente === (int) $c['monto_cent'] ? '= el monto, la tabla cuadra' : '*** la tabla TAMPOCO cuadra');
    printf("     saldo del crédito       %16s  <- el que está mal\n", '$' . number_format((int) $c['saldo_capital_cent'] / 100, 2));
    printf("     cuotas con asiento      %16d  %s\n", $asientos,
        $asientos === 0 ? '(ninguna: corregirlo no mueve dinero)' : '*** OJO, hay contabilidad detrás');

    if ($pagado + $pendiente !== (int) $c['monto_cent']) {
        echo "     NO SE TOCA: si la tabla no cuadra con el monto, el que está mal\n";
        echo "     podría ser cualquiera de los dos y hay que mirarlo a mano.\n\n";
    } elseif ($asientos > 0) {
        echo "     NO SE TOCA: hay asientos detrás; un importe asentado en el libro\n";
        echo "     no se cambia desde fuera del libro.\n\n";
    } elseif ((int) $c['saldo_capital_cent'] === $pendiente) {
        echo "     Ya está correcto.\n\n";
    } else {
        $hechos[] = [
            'que'  => 'saldo de ' . $c['folio'],
            'de'   => (int) $c['saldo_capital_cent'],
            'a'    => $pendiente,
            'hacer' => function () use ($c, $pendiente): void {
                BaseDatos::ejecutar(
                    'UPDATE creditos SET saldo_capital_cent = ? WHERE id = ?',
                    [$pendiente, (int) $c['id']]
                );

                Auditoria::registrar(
                    'credito.saldo_corregido', Auditoria::EXITO, 0,
                    'credito', (string) $c['id'],
                    [
                        'folio' => $c['folio'],
                        'de'    => (int) $c['saldo_capital_cent'],
                        'a'     => $pendiente,
                        'motivo' => 'saldo sembrado que no correspondia a la tabla de '
                                  . 'amortizacion; sin asientos contables asociados',
                    ]
                );
            },
        ];

        printf("     → se corrige a %s\n\n", '$' . number_format($pendiente / 100, 2));
    }
}

// =====================================================================
// 2. El saldo de apertura sin movimiento
// =====================================================================

echo "  2. Cuentas cuyo saldo no lo explican sus movimientos\n";

$cuentas = BaseDatos::todas(
    'SELECT c.id, c.numero_cuenta, c.saldo_cent, c.apertura_en, c.usuario_id,
            COALESCE((SELECT SUM(m.importe_cent) FROM movimientos m
                       WHERE m.cuenta_id = c.id), 0) AS suma
       FROM cuentas c
      WHERE c.es_tecnica = 0
     HAVING suma <> c.saldo_cent'
);

if ($cuentas === []) {
    echo "     Ninguna.\n\n";
}

$tecnica = (int) BaseDatos::unValor(
    'SELECT id FROM cuentas WHERE numero_cuenta = ? AND es_tecnica = 1',
    [Parametros::obtener('contabilidad.cuenta_apertura', '0000000000')]
);

foreach ($cuentas as $cu) {
    $falta = (int) $cu['saldo_cent'] - (int) $cu['suma'];

    printf("     cuenta %-16s saldo %14s · movimientos %14s · falta %s\n",
        $cu['numero_cuenta'],
        '$' . number_format((int) $cu['saldo_cent'] / 100, 2),
        '$' . number_format((int) $cu['suma'] / 100, 2),
        '$' . number_format($falta / 100, 2));

    if ($falta <= 0) {
        echo "        NO SE TOCA: sobran movimientos en vez de faltar. Anotar uno\n";
        echo "        negativo sería inventar un retiro que nadie hizo.\n";
        continue;
    }

    if ($tecnica === 0) {
        echo "        NO SE TOCA: no encuentro la cuenta técnica de contrapartida.\n";
        continue;
    }

    $hechos[] = [
        'que' => 'apertura de ' . $cu['numero_cuenta'],
        'de'  => 0,
        'a'   => $falta,
        'hacer' => function () use ($cu, $falta, $tecnica): void {
            BaseDatos::enTransaccion(function () use ($cu, $falta, $tecnica): void {
                LibroMayor::bloquearCuentas([(int) $cu['id'], $tecnica]);

                /* El saldo de la cuenta YA incluye estos $250: lo que
                   falta es el movimiento que los explique, no el
                   dinero. Por eso el apunte del cliente va en cero
                   neto... y no puede: `LibroMayor` rechaza importes
                   cero, y con razón.

                   Se hace al revés: se deja el saldo como está y se
                   anota el depósito de apertura REBAJANDO antes el
                   saldo, de modo que el asiento lo vuelva a subir. El
                   resultado es el mismo saldo, ahora explicado. */
                BaseDatos::ejecutar(
                    'UPDATE cuentas SET saldo_cent = saldo_cent - ? WHERE id = ?',
                    [$falta, (int) $cu['id']]
                );

                LibroMayor::registrar(
                    'deposito',
                    [
                        [
                            'cuenta_id'   => (int) $cu['id'],
                            'importe'     => Monto::deCentavos($falta),
                            'descripcion' => 'Depósito de apertura de cuenta',
                        ],
                        [
                            'cuenta_id'   => $tecnica,
                            'importe'     => Monto::deCentavos(-$falta),
                            'descripcion' => 'Contrapartida de apertura',
                        ],
                    ],
                    null,
                    'apertura-' . $cu['numero_cuenta'],
                    'Depósito de apertura que faltaba por anotar'
                );
            });

            Auditoria::registrar(
                'cuenta.apertura_anotada', Auditoria::EXITO, 0,
                'cuenta', (string) $cu['id'],
                [
                    'numero' => $cu['numero_cuenta'],
                    'importe' => $falta,
                    'motivo' => 'saldo sembrado sin movimiento que lo respaldara',
                ]
            );
        },
    ];

    echo "        → se anota el depósito de apertura, con su asiento\n";
}

echo "\n";

// =====================================================================
// 3. Cargos de corte sin movimiento
// =====================================================================

echo "  3. Tarjetas cuyo saldo no lo explican sus movimientos\n";

$tarjetas = BaseDatos::todas(
    'SELECT t.id, t.ultimos4, t.saldo_usado_cent,
            COALESCE((SELECT SUM(CASE WHEN m.tipo IN ("pago","reverso") THEN -m.importe_cent
                                      ELSE m.importe_cent END)
                        FROM tarjeta_movimientos m
                       WHERE m.tarjeta_id = t.id AND m.estado = "liquidada"), 0) AS suma
       FROM tarjetas t
      WHERE t.tipo = "credito" AND t.estado <> "cancelada"
     HAVING suma <> t.saldo_usado_cent'
);

if ($tarjetas === []) {
    echo "     Ninguna.\n\n";
}

foreach ($tarjetas as $t) {
    $falta = (int) $t['saldo_usado_cent'] - (int) $t['suma'];

    printf("     tarjeta ····%s  usado %12s · movimientos %12s · falta %s\n",
        $t['ultimos4'],
        '$' . number_format((int) $t['saldo_usado_cent'] / 100, 2),
        '$' . number_format((int) $t['suma'] / 100, 2),
        '$' . number_format($falta / 100, 2));

    /* ¿Lo explica un corte? Si el corte cobró intereses o seguros y no
       quedó el movimiento, la diferencia es exactamente eso. */
    $corte = BaseDatos::unaFila(
        'SELECT id, fecha_corte, intereses_cent, seguro_vida_cent, seguro_empleo_cent
           FROM tarjeta_cortes
          WHERE tarjeta_id = ?
          ORDER BY fecha_corte DESC LIMIT 1',
        [(int) $t['id']]
    );

    $delCorte = $corte === null ? 0
        : (int) $corte['intereses_cent']
        + (int) $corte['seguro_vida_cent']
        + (int) $corte['seguro_empleo_cent'];

    if ($corte === null || $delCorte !== $falta) {
        /* El otro caso conocido: el saldo es MENOR que sus movimientos.
         *
         * Pasa con compras de la tienda que se quedaron en «pago
         * pendiente» por un fallo ya corregido: el consumo quedó
         * anotado con su referencia y su comercio, pero la rama que
         * suma al saldo de la tarjeta no llegó a correr, así que el
         * banco tiene el movimiento y no tiene la deuda.
         *
         * Aquí los movimientos son la evidencia de lo que pasó y el
         * saldo es lo que está mal, así que se recalcula desde ellos.
         * Es además lo que el corte espera: resta de la base de
         * intereses el saldo de los planes a tasa cero, lo que solo
         * tiene sentido si ese saldo está dentro. */
        if ($falta < 0) {
            printf("        el saldo no recoge %s de consumos reales\n",
                '$' . number_format(abs($falta) / 100, 2));

            $hechos[] = [
                'que' => 'saldo de ····' . $t['ultimos4'],
                'de'  => (int) $t['saldo_usado_cent'],
                'a'   => (int) $t['suma'],
                'hacer' => function () use ($t): void {
                    BaseDatos::ejecutar(
                        'UPDATE tarjetas SET saldo_usado_cent = ? WHERE id = ?',
                        [(int) $t['suma'], (int) $t['id']]
                    );

                    Auditoria::registrar(
                        'tarjeta.saldo_recalculado', Auditoria::EXITO, 0,
                        'tarjeta', (string) $t['id'],
                        [
                            'de' => (int) $t['saldo_usado_cent'],
                            'a'  => (int) $t['suma'],
                            'motivo' => 'consumos de la tienda anotados sin subir el saldo; '
                                      . 'se recalcula desde los movimientos, que son la '
                                      . 'evidencia de lo que paso',
                        ]
                    );
                },
            ];

            printf("        → se recalcula el saldo a %s\n",
                '$' . number_format((int) $t['suma'] / 100, 2));
            continue;
        }

        printf("        NO SE TOCA: el último corte explica %s, no %s. Hay otra causa.\n",
            '$' . number_format($delCorte / 100, 2),
            '$' . number_format($falta / 100, 2));
        continue;
    }

    $hechos[] = [
        'que' => 'cargos del corte de ····' . $t['ultimos4'],
        'de'  => 0,
        'a'   => $falta,
        'hacer' => function () use ($t, $corte): void {
            $lineas = [
                ['interes',  (int) $corte['intereses_cent'],     'Intereses del periodo', 'interes', 'INT'],
                ['comision', (int) $corte['seguro_vida_cent'],   'Seguro de vida sobre el saldo', 'seguro', 'SVID'],
                ['comision', (int) $corte['seguro_empleo_cent'], 'Seguro de desempleo sobre el saldo', 'seguro', 'SDES'],
            ];

            foreach ($lineas as [$tipo, $importe, $concepto, $cat, $sufijo]) {
                if ($importe <= 0) { continue; }

                // Ya anotada en un intento anterior: no se duplica.
                $ya = (int) BaseDatos::unValor(
                    'SELECT COUNT(*) FROM tarjeta_movimientos WHERE referencia = ?',
                    ['CORTE-' . (int) $corte['id'] . '-' . $sufijo]
                );

                if ($ya > 0) { continue; }

                BaseDatos::ejecutar(
                    'INSERT INTO tarjeta_movimientos
                       (tarjeta_id, tipo, importe_cent, comercio, categoria,
                        referencia, estado, corte_id, creado_en)
                     VALUES (?,?,?,?,?,?,"liquidada",?,?)',
                    [
                        (int) $t['id'], $tipo, $importe, $concepto, $cat,
                        'CORTE-' . (int) $corte['id'] . '-' . $sufijo, (int) $corte['id'],
                        $corte['fecha_corte'] . ' 23:59:59',
                    ]
                );
            }

            Auditoria::registrar(
                'tarjeta.cargos_corte_anotados', Auditoria::EXITO, 0,
                'tarjeta', (string) $t['id'],
                [
                    'corte' => (int) $corte['id'],
                    'motivo' => 'el corte sumo intereses y seguros al saldo sin crear '
                              . 'los movimientos; ya corregido en ServicioCorteTarjeta',
                ]
            );
        },
    ];

    echo "        → se anotan los cargos del corte que faltaban\n";
}

echo "\n";

// =====================================================================

if ($hechos === []) {
    exit("  Nada que reparar.\n");
}

printf("  %d reparación(es) preparadas.\n", count($hechos));

if (!$aplicar) {
    exit("\n  No se cambió nada. Para repararlo: --aplicar\n");
}

foreach ($hechos as $h) {
    ($h['hacer'])();
    printf("  hecho: %s\n", $h['que']);
}

echo "\n  Comprobación final:\n\n";

passthru('"' . PHP_BINARY . '" ' . escapeshellarg(__DIR__ . '/verificar-cuadre.php'));
