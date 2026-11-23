#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Repara los pagos de tarjeta que se cobraron de más.
 *
 * EL FALLO QUE REPARA
 *
 * `ServicioPasarela::aplicarPagoTarjeta()` aplicaba a la tarjeta solo
 * lo que cabía —porque la base no admite saldo negativo— pero
 * registraba el MOVIMIENTO por el importe completo y acreditaba a la
 * cuenta general del banco el importe completo.
 *
 * Resultado, en dos partes y las dos malas:
 *
 *   1. El historial de la tarjeta dejaba de cuadrar con su saldo. Es
 *      lo que destapa la comprobación 10 del verificador.
 *
 *   2. El excedente se lo quedaba el banco. Al cliente se le decía
 *      «quedaron a favor» en un aviso, pero ese saldo a favor no
 *      existía en ninguna parte: ni en la tarjeta, ni en su cuenta.
 *
 * El código ya está corregido: desde ahora el movimiento registra lo
 * aplicado y el excedente se abona a la cuenta del cliente. Esto es
 * para lo que quedó mal ANTES de la corrección.
 *
 * LO QUE HACE
 *
 *   · Reversa el movimiento de tarjeta sobrante, con un asiento que
 *     apunta al original. No borra nada: lo que ocurrió, ocurrió.
 *   · Abona al cliente el excedente en su cuenta.
 *
 * LO QUE NO HACE
 *
 * Nada, si no se le pasa `--aplicar`. Esto es dinero de alguien y la
 * corrección es una decisión, no un automatismo.
 *
 * Uso:
 *     php bin/reparar-pago-excedente.php             dice qué haría
 *     php bin/reparar-pago-excedente.php --aplicar   lo hace
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

$aplicar = in_array('--aplicar', array_slice($argv, 1), true);

echo "\n  PAGOS DE TARJETA COBRADOS DE MÁS\n";
echo '  ' . str_repeat('=', 74) . "\n\n";

/* Las tarjetas cuyo historial no cuadra con su saldo. Es la misma
   consulta de la comprobación 10 del verificador: si ahí sale, aquí
   tiene que salir. */
$malas = BaseDatos::todas(
    'SELECT t.id, t.ultimos4, t.usuario_id, t.saldo_usado_cent,
            CONCAT(u.nombres, " ", u.apellidos) AS cliente,
            COALESCE((SELECT SUM(CASE WHEN m.tipo IN ("pago","reverso") THEN -m.importe_cent
                                      ELSE m.importe_cent END)
                        FROM tarjeta_movimientos m
                       WHERE m.tarjeta_id = t.id AND m.estado = "liquidada"), 0) AS suma
       FROM tarjetas t
       JOIN usuarios u ON u.id = t.usuario_id
      WHERE t.tipo = "credito" AND t.estado <> "cancelada"
      HAVING suma <> t.saldo_usado_cent
      ORDER BY t.id'
);

if ($malas === []) {
    echo "  Ninguna tarjeta tiene el historial descuadrado. Nada que reparar.\n\n";
    exit(0);
}

printf("  %d tarjeta(s) con el historial descuadrado:\n\n", count($malas));

$total = 0;

foreach ($malas as $m) {
    $exceso = (int) $m['saldo_usado_cent'] - (int) $m['suma'];
    $total += $exceso;

    printf(
        "  ····%s  %-28s saldo %10s   historial %10s   exceso %10s\n",
        $m['ultimos4'],
        substr((string) $m['cliente'], 0, 28),
        Monto::deCentavos((int) $m['saldo_usado_cent'])->formateado(),
        Monto::deCentavos((int) $m['suma'])->formateado(),
        Monto::deCentavos($exceso)->formateado()
    );

    /* El movimiento que sobra: el último pago que no pudo aplicarse
       porque la tarjeta ya estaba en cero. */
    $sobrante = BaseDatos::unaFila(
        'SELECT id, importe_cent, referencia, creado_en
           FROM tarjeta_movimientos
          WHERE tarjeta_id = ? AND tipo = "pago" AND estado = "liquidada"
            AND importe_cent = ?
          ORDER BY id DESC LIMIT 1',
        [(int) $m['id'], abs($exceso)]
    );

    if ($sobrante === null) {
        echo "        !! no se identificó un pago que explique el exceso: revisar a mano.\n";
        continue;
    }

    printf(
        "        pago sobrante: mov %s de %s, del %s (ref %s)\n",
        $sobrante['id'],
        Monto::deCentavos((int) $sobrante['importe_cent'])->formateado(),
        substr((string) $sobrante['creado_en'], 0, 10),
        $sobrante['referencia']
    );

    $cuenta = BaseDatos::unaFila(
        'SELECT id, numero_cuenta FROM cuentas
          WHERE usuario_id = ? AND estado = "activa" AND es_tecnica = 0
          ORDER BY id LIMIT 1',
        [(int) $m['usuario_id']]
    );

    if ($cuenta === null) {
        echo "        !! el cliente no tiene cuenta activa: hay que devolvérselo por otra vía.\n";
        continue;
    }

    printf("        se le abonaría a la cuenta %s\n", $cuenta['numero_cuenta']);

    if (!$aplicar) {
        continue;
    }

    // -----------------------------------------------------------------
    // La reparación
    // -----------------------------------------------------------------
    BaseDatos::enTransaccion(static function () use ($m, $sobrante, $cuenta): void {
        $monto = Monto::deCentavos((int) $sobrante['importe_cent']);

        $general = (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
        );

        LibroMayor::bloquearCuentas([(int) $cuenta['id'], $general]);

        /* El dinero sale de donde se quedó —la cuenta general del
           banco— y entra a la del cliente, que es de quien era. */
        $asiento = LibroMayor::registrar(
            'ajuste',
            [
                ['cuenta_id' => $general, 'importe' => $monto->negado(),
                 'descripcion' => 'Devolución de excedente de pago · tarjeta ····'
                                . $m['ultimos4']],
                ['cuenta_id' => (int) $cuenta['id'], 'importe' => $monto,
                 'descripcion' => 'Excedente del pago de su tarjeta ····'
                                . $m['ultimos4'] . ', devuelto'],
            ],
            null,
            'repara-excedente-' . $sobrante['id'],
            'Devolución de excedente de pago de tarjeta'
        );

        /* El movimiento que sobraba se marca como reversado. NO se
           borra: lo que ocurrió, ocurrió, y el cliente tiene derecho a
           ver las dos líneas. */
        BaseDatos::ejecutar(
            'UPDATE tarjeta_movimientos SET estado = "reversada" WHERE id = ?',
            [(int) $sobrante['id']]
        );

        Auditoria::registrar(
            'tarjeta.excedente_devuelto',
            Auditoria::EXITO,
            null,
            'tarjeta',
            (string) $m['ultimos4'],
            [
                'monto'      => $monto->aDecimal(),
                'movimiento' => $sobrante['id'],
                'asiento'    => $asiento['asiento_id'],
                'cuenta'     => $cuenta['numero_cuenta'],
            ]
        );
    });

    (new \CashMan\Aplicacion\Servicio\ServicioNotificacion())->crear(
        (int) $m['usuario_id'],
        'tarjeta.excedente',
        'Le devolvimos un excedente',
        'Un pago a su tarjeta ····' . $m['ultimos4'] . ' superaba el saldo que debía. '
        . 'La diferencia de ' . Monto::deCentavos(abs($exceso))->formateado()
        . ' se abonó a su cuenta.',
        ['tarjeta' => $m['ultimos4']],
        'exito'
    );

    echo "        reparado.\n";
}

printf("\n  Exceso total: %s\n", Monto::deCentavos($total)->formateado());

if (!$aplicar) {
    echo "\n  ENSAYO: no se tocó nada.\n";
    echo "  Para repararlo de verdad, repita con --aplicar\n\n";
    exit(0);
}

echo "\n  Reparación terminada. Vuelva a correr bin/verificar-cuadre.php\n\n";
