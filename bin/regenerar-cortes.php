#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Borra los estados de cuenta de tarjeta y los vuelve a emitir.
 *
 * PARA QUÉ
 *
 * Los cortes que había se emitieron con datos y con reglas que luego
 * cambiaron: periodos que no respetaban el día de corte de la tarjeta
 * —uno decía «del 1 al 10 de octubre» en un documento mensual—, saldos
 * que contaban dos veces el saldo anterior, y ninguno decía el plazo
 * de financiamiento que la Ley del Sistema de Tarjetas de Crédito
 * manda declarar.
 *
 * Corregir eso fila a fila habría dejado documentos parcheados. Es más
 * limpio tirarlos y volver a emitirlos: los movimientos —cada consumo,
 * cada pago, con su fecha y su comercio— no se tocan, y son la única
 * fuente de verdad. Un estado de cuenta es una lectura de ellos, y una
 * lectura siempre se puede repetir.
 *
 * QUÉ SE BORRA Y QUÉ NO
 *
 *   SE BORRA   `tarjeta_cortes`, y la marca de corte de los
 *              movimientos, que es lo que les permite volver a
 *              facturarse.
 *
 *   NO SE TOCA ni un movimiento, ni un saldo de tarjeta, ni un asiento
 *              contable. Esto no mueve dinero: reconstruye documentos.
 *
 * CÓMO SE REGENERA
 *
 * Mes a mes y tarjeta por tarjeta, en el día de corte de cada una,
 * desde su primer movimiento hasta hoy. Se usa el servicio de corte de
 * siempre —no una copia—, así que lo que salga de aquí es exactamente
 * lo que saldrá el mes que viene cuando corte solo.
 *
 * Uso:
 *     php bin/regenerar-cortes.php --ensayo     dice qué haría
 *     php bin/regenerar-cortes.php --si         lo hace
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioCorteTarjeta;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

/* Ni un correo. Esto reemite decenas de estados de cuenta, y cada
   emisión avisa al cliente: sin esto, treinta personas recibirían
   treinta avisos de documentos que ya habían visto. */
Correo::silenciar();

$opciones = [];

foreach (array_slice($argv, 1) as $a) {
    if (!str_starts_with($a, '--')) { continue; }

    $par = explode('=', substr($a, 2), 2);
    $opciones[$par[0]] = $par[1] ?? true;
}

$ensayo = !isset($opciones['si']);

echo "\n  REGENERAR ESTADOS DE CUENTA DE TARJETA\n";
echo '  ' . str_repeat('=', 68) . "\n\n";

// =====================================================================
// Lo que hay ahora
// =====================================================================

$cortes = (int) BaseDatos::unValor('SELECT COUNT(*) FROM tarjeta_cortes');
$marcados = (int) BaseDatos::unValor(
    'SELECT COUNT(*) FROM tarjeta_movimientos WHERE corte_id IS NOT NULL'
);

printf("  Hay %d corte(s) emitidos y %d movimiento(s) facturados.\n\n", $cortes, $marcados);

if ($ensayo) {
    echo "  ENSAYO: no se borró ni se emitió nada.\n";
    echo "  Para hacerlo de verdad: php bin/regenerar-cortes.php --si\n\n";
    exit(0);
}

// =====================================================================
// Borrar
// =====================================================================

echo "  Borrando los estados de cuenta anteriores…\n";

BaseDatos::enTransaccion(static function (): void {
    /* Los cargos QUE CREÓ EL PROPIO CORTE se van con él.
     *
     * Al cortar, el servicio inserta los intereses y los seguros del
     * periodo como movimientos, para que el saldo tenga una línea que
     * lo explique. Si se regenera sin borrarlos, el corte nuevo los
     * calcula otra vez y los inserta de nuevo: la segunda pasada dejó
     * el total facturado en $703,764 cuando la primera decía
     * $275,546. Intereses sobre intereses, y encima en la deuda de
     * gente de verdad.
     *
     * Se reconocen por su referencia, que el corte escribe como
     * «CORTE-<id>-<concepto>». Los consumos, los pagos y los cargos
     * que no nacieron de un corte no llevan esa marca y no se tocan:
     * ésos son los hechos, y los hechos no se regeneran. */
    $borrados = BaseDatos::ejecutar(
        'DELETE FROM tarjeta_movimientos WHERE referencia LIKE "CORTE-%"'
    );

    printf("  %d cargo(s) de corte anteriores retirados.\n", (int) $borrados);

    /* Luego se suelta la marca de los que quedan: con la clave foránea
       puesta, borrar los cortes antes fallaría, y soltarla después
       dejaría movimientos apuntando a un corte que ya no existe. */
    BaseDatos::ejecutar(
        'UPDATE tarjeta_movimientos SET corte_id = NULL, estado_cuenta_id = NULL
          WHERE corte_id IS NOT NULL OR estado_cuenta_id IS NOT NULL'
    );

    BaseDatos::ejecutar('DELETE FROM tarjeta_cortes');

    /* Y las tarjetas vuelven a no tener corte: si conservaran la fecha
       del último, el primer corte nuevo arrancaría a mitad de camino y
       el periodo saldría corto otra vez. */
    BaseDatos::ejecutar(
        'UPDATE tarjetas SET ultimo_corte = NULL, proximo_corte = NULL
          WHERE tipo = "credito"'
    );

    /* El saldo vuelve a ser lo que suman los movimientos que QUEDAN.
     *
     * Sin esto la regeneración no converge: el primer corte de cada
     * tarjeta deduce su punto de partida del saldo actual, y ese saldo
     * todavía incluía los intereses de la corrida anterior, que
     * acababan de borrarse. Cada pasada arrancaba un poco más arriba y
     * la deuda subía sola —mil ochocientos dólares por vuelta, sobre
     * gente de verdad—.
     *
     * Ahora el punto de partida son los hechos: los consumos y los
     * pagos que nadie regeneró. Repetir la regeneración da el mismo
     * resultado, que es lo mínimo que se le puede pedir. */
    BaseDatos::ejecutar(
        'UPDATE tarjetas t
            SET t.saldo_usado_cent = GREATEST(0, COALESCE((
                    SELECT SUM(CASE WHEN m.tipo IN ("pago","reverso")
                                    THEN -m.importe_cent ELSE m.importe_cent END)
                      FROM tarjeta_movimientos m
                     WHERE m.tarjeta_id = t.id AND m.estado = "liquidada"
                ), 0))
          WHERE t.tipo = "credito"'
    );
});

echo "  Hecho.\n\n";

/* El rango se calcula AHORA, con los movimientos ya limpios.
 *
 * Calcularlo antes de borrar tomaba como primer movimiento de la
 * tarjeta un cargo que habia creado el corte de la pasada anterior.
 * Como ese cargo lleva la fecha del corte, cada regeneracion
 * retrocedia el punto de partida y emitia mas cortes que la
 * anterior: 314, luego 338, luego 404. Una regeneracion que no da
 * dos veces el mismo resultado no sirve para regenerar nada. */
/* De cuándo a cuándo hay que emitir, por tarjeta. */
$tarjetas = BaseDatos::todas(
    'SELECT t.id, t.ultimos4, t.dia_corte,
            MIN(DATE(m.creado_en)) AS desde
       FROM tarjetas t
       JOIN tarjeta_movimientos m ON m.tarjeta_id = t.id
                                AND m.referencia NOT LIKE "CORTE-%"
      WHERE t.tipo = "credito" AND t.estado = "activa"
      GROUP BY t.id
      ORDER BY t.id'
);

if ($tarjetas === []) { exit("  No hay tarjetas con movimientos.\n\n"); }

/* Las fechas en que hay que cortar: la unión de los días de corte de
   todas las tarjetas, desde el primer movimiento de cada una. El
   servicio corta solo las que les toca ese día, así que basta con
   pasarle cada fecha una vez. */
$hoy    = new DateTimeImmutable('today');
$fechas = [];

foreach ($tarjetas as $t) {
    $desde = new DateTimeImmutable((string) $t['desde']);
    $dia   = max(1, (int) $t['dia_corte']);

    $cursor = $desde->setDate(
        (int) $desde->format('Y'),
        (int) $desde->format('n'),
        min($dia, (int) $desde->format('t'))
    );

    /* Si el día de corte de ese mes ya pasó cuando llegó el primer
       movimiento, el primer corte es el del mes siguiente. */
    if ($cursor < $desde) { $cursor = $cursor->modify('+1 month'); }

    while ($cursor <= $hoy) {
        $fechas[$cursor->format('Y-m-d')] = true;

        $siguiente = $cursor->modify('first day of +1 month');

        $cursor = $siguiente->setDate(
            (int) $siguiente->format('Y'),
            (int) $siguiente->format('n'),
            min($dia, (int) $siguiente->format('t'))
        );
    }
}

ksort($fechas);

printf("  %d tarjeta(s), %d fecha(s) de corte a emitir, de %s a %s.\n\n",
    count($tarjetas),
    count($fechas),
    array_key_first($fechas) ?: '-',
    array_key_last($fechas) ?: '-'
);


// =====================================================================
// Emitir
// =====================================================================

echo "  Emitiendo de nuevo, mes a mes…\n\n";

$servicio = new ServicioCorteTarjeta();
$emitidos = 0;
$saldo    = 0;

foreach (array_keys($fechas) as $fecha) {
    $r = $servicio->cortar((string) $fecha);

    $n = (int) ($r['cortes'] ?? 0);

    if ($n > 0) {
        $emitidos += $n;
        $saldo    += (int) ($r['saldo_cent'] ?? 0);

        printf("    %s  %2d corte(s)\n", $fecha, $n);
    }
}

echo "\n";

Auditoria::registrar(
    'tarjeta.cortes_regenerados',
    Auditoria::EXITO,
    null,
    'tarjeta',
    'todas',
    ['borrados' => $cortes, 'emitidos' => $emitidos]
);

printf("  %d corte(s) emitidos (antes había %d).\n", $emitidos, $cortes);
printf("  Saldo total facturado en el último ciclo: %s\n\n",
    Monto::deCentavos($saldo)->formateado());

// =====================================================================
// Y que lo emitido se sostenga
// =====================================================================

echo "  Comprobando que los periodos sean mensuales…\n";

$cortos = BaseDatos::todas(
    'SELECT c.id, t.ultimos4, c.periodo_inicio, c.periodo_fin,
            DATEDIFF(c.periodo_fin, c.periodo_inicio) + 1 AS dias
       FROM tarjeta_cortes c
       JOIN tarjetas t ON t.id = c.tarjeta_id
     HAVING dias < 25 OR dias > 35
      ORDER BY dias
      LIMIT 10'
);

if ($cortos === []) {
    echo "  Todos los periodos están entre 25 y 35 días.\n\n";
} else {
    printf("  %d periodo(s) fuera de un mes:\n", count($cortos));

    foreach ($cortos as $c) {
        printf("    ····%s  %s al %s (%d días)\n",
            $c['ultimos4'], $c['periodo_inicio'], $c['periodo_fin'], (int) $c['dias']);
    }

    echo "\n  El primero de cada tarjeta puede ser corto: arranca en su\n";
    echo "  primer movimiento, no en un ciclo completo. Los demás, no.\n\n";
}
