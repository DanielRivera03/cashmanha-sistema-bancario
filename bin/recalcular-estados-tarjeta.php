#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Recalcula el pago mínimo y los meses de los estados de cuenta de
 * tarjeta ya emitidos, sin re-cerrar nada.
 *
 * PARA QUÉ EXISTE
 *
 * Los cortes guardaron en su día un `meses_si_paga_minimo` y un
 * `pago_minimo_cent` con una cuenta vieja: algunos decían que un saldo
 * de miles de dólares se liquidaba «en 1 mes», y el mínimo no incluía
 * el excedente sobre el límite. El PDF mostraba una cifra de meses y el
 * correo otra. Esto reescribe esos dos campos —y SOLO esos— con la
 * misma cuenta que usa hoy la generación del corte
 * (`ServicioCorteTarjeta::proyeccionMinimo`), para que la plataforma,
 * el PDF y el correo digan lo mismo.
 *
 * QUÉ NO TOCA
 *
 * No re-cierra el corte: no recalcula saldos, intereses, seguros ni
 * movimientos. Solo `pago_minimo_cent` y `meses_si_paga_minimo`, que
 * son cifras derivadas de lo ya guardado. El libro mayor y el cuadre no
 * se mueven. Aun así, corra `bin/verificar-cuadre.php` después.
 *
 * NO MANDA CORREO
 *
 * Solo reescribe la base. El reenvío de los documentos corregidos es
 * otro guion aparte (`bin/enviar-estados-tarjeta.php`), que además
 * exige su propia confirmación.
 *
 * Uso:
 *     php bin/recalcular-estados-tarjeta.php          dice qué cambiaría (seco)
 *     php bin/recalcular-estados-tarjeta.php --si      lo escribe
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioCorteTarjeta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

$deVerdad = in_array('--si', array_slice($argv, 1), true);

echo "\n  RECÁLCULO DE ESTADOS DE CUENTA DE TARJETA\n";
echo '  ' . str_repeat('=', 68) . "\n\n";

/* Todo lo que la cuenta necesita y que ya está guardado: el saldo del
   corte, su plazo, y de la tarjeta el límite, la tasa del producto y el
   titular (para el descuento de segmento). */
$filas = BaseDatos::todas(
    'SELECT k.id, k.saldo_final_cent, k.plazo_financiamiento_meses, k.fecha_limite_pago,
            k.pago_minimo_cent AS min_old, k.meses_si_paga_minimo AS meses_old,
            t.limite_cent, t.usuario_id, COALESCE(p.tasa_interes_bp, 0) AS tasa_bp
       FROM tarjeta_cortes k
       JOIN tarjetas t ON t.id = k.tarjeta_id
  LEFT JOIN productos p ON p.id = t.producto_id'
);

$hoy = date('Y-m-d');

$total       = count($filas);
$cambian     = 0;
$conExcedente = 0;
$muestra     = [];

$aEscribir = [];

foreach ($filas as $f) {
    $saldo  = (int) $f['saldo_final_cent'];
    $limite = (int) $f['limite_cent'];

    $tasa = ServicioCorteTarjeta::tasaRevolvente((int) $f['tasa_bp'], (int) $f['usuario_id']);

    $proy = ServicioCorteTarjeta::proyeccionMinimo(
        $saldo,
        $tasa,
        (int) $f['plazo_financiamiento_meses'],
        $limite
    );

    /* El MÍNIMO solo se reescribe en cortes AÚN NO VENCIDOS (el ciclo
       en curso). Subir el mínimo de un corte ya vencido marcaría como
       atraso a quien pagó el mínimo viejo en fecha —y un atraso
       descalifica para extrafinanciamiento—, así que en los vencidos se
       respeta lo que se cobró. Los MESES son solo informativos y se
       corrigen en todos los cortes. */
    $vencido  = $f['fecha_limite_pago'] !== null
             && (string) $f['fecha_limite_pago'] < $hoy;
    $minNew   = $vencido ? (int) $f['min_old'] : (int) $proy['minimo'];
    $mesesNew = (int) $proy['meses'];

    if (!$vencido && $proy['excedente'] > 0) { $conExcedente++; }

    if ($minNew !== (int) $f['min_old'] || $mesesNew !== (int) $f['meses_old']) {
        $cambian++;
        $aEscribir[] = ['id' => (int) $f['id'], 'min' => $minNew, 'meses' => $mesesNew];

        if (count($muestra) < 12) {
            $muestra[] = [
                $f['id'], $saldo, (int) $f['meses_old'], $mesesNew,
                (int) $f['min_old'], $minNew, (int) $proy['excedente'],
            ];
        }
    }
}

$d = static fn(int $c): string => '$' . number_format($c / 100, 2);

printf("  Cortes revisados:          %d\n", $total);
printf("  Con excedente sobre límite: %d\n", $conExcedente);
printf("  Cambian (mínimo o meses):  %d\n\n", $cambian);

if ($muestra !== []) {
    printf("  %-8s %-12s %-9s %-9s %-12s %-12s %-10s\n",
        'corte', 'saldo', 'mesesOld', 'mesesNew', 'minOld', 'minNew', 'excedente');
    foreach ($muestra as $m) {
        printf("  %-8s %-12s %-9s %-9s %-12s %-12s %-10s\n",
            $m[0], $d($m[1]), $m[2], $m[3], $d($m[4]), $d($m[5]), $d($m[6]));
    }
    echo "\n";
}

if ($cambian === 0) {
    echo "  Nada que recalcular: todo ya coincide.\n\n";
    exit(0);
}

if (!$deVerdad) {
    echo "  ENSAYO: no se escribió nada.\n";
    echo "  Para aplicarlo, repita con --si\n\n";
    exit(0);
}

// ---------------------------------------------------------------------
// A escribir (una transacción, un UPDATE por corte que cambia).
// ---------------------------------------------------------------------
BaseDatos::enTransaccion(function () use ($aEscribir) {
    foreach ($aEscribir as $w) {
        BaseDatos::ejecutar(
            'UPDATE tarjeta_cortes
                SET pago_minimo_cent = ?, meses_si_paga_minimo = ?
              WHERE id = ?',
            [$w['min'], $w['meses'], $w['id']]
        );
    }
});

Auditoria::registrar(
    'tarjeta.estados_recalculados',
    Auditoria::EXITO,
    null,
    'tarjeta',
    'todas',
    ['cortes' => $cambian]
);

printf("  %d corte(s) recalculado(s) y guardado(s).\n", $cambian);
echo "  Ahora corra  bin/verificar-cuadre.php  para confirmar que nada se descuadró.\n\n";
