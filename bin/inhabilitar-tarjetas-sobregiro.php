#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Inhabilita las tarjetas que vencieron su fecha límite de pago
 * todavía por encima del límite, y reactiva las que ya volvieron a su
 * línea.
 *
 * LA REGLA
 *
 * El excedente sobre la línea aprobada es exigible de inmediato y va
 * incluido en el pago mínimo del corte. Se da plazo hasta la fecha
 * límite de pago de ese corte. Si esa fecha pasa y la tarjeta sigue
 * por encima del límite, deja de admitir consumos: se INHABILITA. En
 * cuanto un pago la devuelve dentro de la línea, se reactiva sola.
 *
 * POR QUÉ UN ESTADO PROPIO
 *
 * 'inhabilitada' no es 'bloqueada'. La segunda la decide una persona y
 * se levanta a mano; esta es automática y se levanta sola. Mezclarlas
 * haría que un pago reactivara una tarjeta que alguien bloqueó a
 * propósito. El corte sigue tomando las inhabilitadas: su saldo
 * devenga y necesita estado de cuenta; lo único que no pueden es gastar.
 *
 * SE DICE QUÉ CAMBIARÍA ANTES DE CAMBIAR NADA
 *
 * Primero cuenta y enseña, y solo con `--si` escribe. No manda correo:
 * las notificaciones in-app quedan tras el mismo interruptor global que
 * el resto (CORREO_HABILITADO en .env).
 *
 * Uso:
 *     php bin/inhabilitar-tarjetas-sobregiro.php          dice qué haría
 *     php bin/inhabilitar-tarjetas-sobregiro.php --si      lo aplica
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioNotificacion;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Dominio\Comun\Monto;

// `--si` a mano, `--aplicar` desde el despachador de tareas: las dos
// aplican. Sin ninguna, es ensayo.
$deVerdad = array_intersect(['--si', '--aplicar'], array_slice($argv, 1)) !== [];
$hoy      = date('Y-m-d');

echo "\n  INHABILITACIÓN DE TARJETAS POR SOBREGIRO\n";
echo '  ' . str_repeat('=', 68) . "\n\n";

/* Cada tarjeta de crédito con su estado, su cupo, y la fecha límite de
   pago de su último corte (de la que cuelga el plazo para solventar). */
$filas = BaseDatos::todas(
    'SELECT t.id, t.ultimos4, t.usuario_id, t.estado,
            t.saldo_usado_cent, t.limite_cent,
            (SELECT k.fecha_limite_pago
               FROM tarjeta_cortes k
              WHERE k.tarjeta_id = t.id
              ORDER BY k.fecha_corte DESC, k.id DESC
              LIMIT 1) AS fecha_limite
       FROM tarjetas t
      WHERE t.tipo = "credito"'
);

$aInhabilitar = [];
$aReactivar   = [];

foreach ($filas as $f) {
    $saldo     = (int) $f['saldo_usado_cent'];
    $limite    = (int) $f['limite_cent'];
    $excedida  = $limite > 0 && $saldo > $limite;
    $fecha     = $f['fecha_limite'] !== null ? (string) $f['fecha_limite'] : null;

    if ($f['estado'] === 'activa'
        && $excedida
        && $fecha !== null
        && $hoy > $fecha) {
        $aInhabilitar[] = $f + ['excedente' => $saldo - $limite];
        continue;
    }

    if ($f['estado'] === 'inhabilitada' && !$excedida) {
        $aReactivar[] = $f;
    }
}

$d = static fn(int $c): string => Monto::deCentavos($c)->formateado();

printf("  Tarjetas de crédito: %d\n", count($filas));
printf("  A inhabilitar (vencidas y aún excedidas): %d\n", count($aInhabilitar));
printf("  A reactivar (ya dentro del límite):       %d\n\n", count($aReactivar));

foreach (array_slice($aInhabilitar, 0, 10) as $f) {
    printf("    inhabilitar ····%s  excedente %s  (venció %s)\n",
        $f['ultimos4'], $d((int) $f['excedente']), (string) $f['fecha_limite']);
}
foreach (array_slice($aReactivar, 0, 10) as $f) {
    printf("    reactivar   ····%s\n", $f['ultimos4']);
}

echo "\n";

if ($aInhabilitar === [] && $aReactivar === []) {
    echo "  Nada que hacer.\n\n";
    exit(0);
}

if (!$deVerdad) {
    echo "  ENSAYO: no se cambió nada.\n";
    echo "  Para aplicarlo, repita con --si\n\n";
    exit(0);
}

// ---------------------------------------------------------------------
// A aplicar.
// ---------------------------------------------------------------------
$notif        = new ServicioNotificacion();
$inhabilitadas = 0;
$reactivadas   = 0;

BaseDatos::enTransaccion(function () use (
    $aInhabilitar, $aReactivar, $notif, $d, &$inhabilitadas, &$reactivadas
) {
    foreach ($aInhabilitar as $f) {
        /* Condición repetida en el UPDATE: entre el cálculo y aquí
           pudo entrar un pago. Solo se inhabilita la que SIGUE activa y
           excedida en este instante. */
        $n = BaseDatos::ejecutar(
            'UPDATE tarjetas
                SET estado = "inhabilitada"
              WHERE id = ? AND estado = "activa"
                AND limite_cent > 0 AND saldo_usado_cent > limite_cent',
            [(int) $f['id']]
        );

        if ($n > 0) {
            $inhabilitadas++;
            $notif->crear(
                (int) $f['usuario_id'],
                'tarjeta.inhabilitada',
                'Tarjeta inhabilitada por sobregiro',
                'Su tarjeta ••••' . $f['ultimos4'] . ' superó el límite de crédito en '
                . $d((int) $f['excedente']) . ' y venció la fecha de pago sin cubrirlo, '
                . 'por lo que queda inhabilitada para compras y adelantos. Para '
                . 'reactivarla, abone al menos ' . $d((int) $f['excedente']) . ' (el '
                . 'excedente) a esta tarjeta desde su cuenta o en caja: en cuanto el '
                . 'saldo vuelva dentro de su límite, se reactiva automáticamente.',
                null,
                'alerta'
            );
        }
    }

    foreach ($aReactivar as $f) {
        $n = BaseDatos::ejecutar(
            'UPDATE tarjetas
                SET estado = "activa"
              WHERE id = ? AND estado = "inhabilitada"
                AND saldo_usado_cent <= limite_cent',
            [(int) $f['id']]
        );

        if ($n > 0) {
            $reactivadas++;
            $notif->crear(
                (int) $f['usuario_id'],
                'tarjeta.reactivada',
                'Tarjeta reactivada',
                'Su tarjeta ••••' . $f['ultimos4'] . ' volvió dentro de su límite de '
                . 'crédito y ya puede usarla de nuevo.',
                null,
                'info'
            );
        }
    }
});

Auditoria::registrar(
    'tarjeta.inhabilitacion_sobregiro',
    Auditoria::EXITO,
    null,
    'tarjeta',
    'todas',
    ['inhabilitadas' => $inhabilitadas, 'reactivadas' => $reactivadas]
);

printf("  %d inhabilitada(s), %d reactivada(s).\n\n", $inhabilitadas, $reactivadas);
