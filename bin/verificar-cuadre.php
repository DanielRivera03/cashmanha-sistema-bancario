<?php

declare(strict_types=1);

/**
 * ¿Cuadra todo?
 *
 * No el libro mayor —de eso ya se encarga `v_asientos_descuadrados`—
 * sino las demás igualdades de dinero del sistema, que son las que
 * fallan en silencio porque nadie las comprueba:
 *
 *   · una cuota cuyo total no es la suma de sus partes;
 *   · un crédito cuyo saldo no coincide con el capital que le queda
 *     por cobrar;
 *   · una tabla de amortización que no liquida en cero;
 *   · una cuenta cuyo saldo no es el del último movimiento;
 *   · mora calculada con una regla distinta en el mismo crédito.
 *
 * Lo aprendí a base de romperlo: al aplicar el descuento de categoría
 * escribí el total de 34 cuotas sin los seguros y el libro mayor siguió
 * cuadrado todo el tiempo, porque la tabla de amortización es un plan y
 * el interés se reconoce al pagar. Ninguna comprobación de las que
 * había lo habría encontrado.
 *
 *   php bin/verificar-cuadre.php
 *
 * Devuelve 1 si algo no cuadra, para poder usarlo en un gancho.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/** @var list<array{titulo:string, filas:list<string>}> */
$hallazgos = [];

function dinero(int $cent): string
{
    return '$' . number_format($cent / 100, 2);
}

/** Registra lo que no cuadra. */
function fallo(string $titulo, array $filas): void
{
    global $hallazgos;

    if ($filas !== []) {
        $hallazgos[] = ['titulo' => $titulo, 'filas' => $filas];
    }
}

echo "  VERIFICACIÓN DE CUADRE · " . date('d/m/Y H:i') . "\n";
echo "  " . str_repeat('=', 74) . "\n\n";

// =====================================================================
// 1. La cuota, consigo misma
// =====================================================================
//
// `total_cent` es lo que el cliente paga ese mes: capital + interés +
// seguro de vida + seguro de desempleo. Una fila donde no lo sea está
// peleada consigo misma y el cliente paga de menos o de más.

$filas = BaseDatos::todas(
    'SELECT c.folio, q.numero, q.capital_cent, q.interes_cent,
            q.seguro_vida_cent, q.seguro_empleo_cent, q.total_cent
       FROM cuotas q JOIN creditos c ON c.id = q.credito_id
      WHERE q.estado NOT IN ("pagada","condonada")
        AND q.total_cent <> q.capital_cent + q.interes_cent
                          + q.seguro_vida_cent + q.seguro_empleo_cent
      ORDER BY c.folio, q.numero
      LIMIT 20'
);

printf("  1. Cuota = capital + interés + seguros .......... %s\n",
    $filas === [] ? 'cuadra' : count($filas) . ' cuota(s) NO');

fallo('Cuotas cuyo total no es la suma de sus partes', array_map(
    static fn(array $f): string => sprintf(
        '%s cuota %d: total %s pero sus partes suman %s',
        $f['folio'], $f['numero'], dinero((int) $f['total_cent']),
        dinero((int) $f['capital_cent'] + (int) $f['interes_cent']
             + (int) $f['seguro_vida_cent'] + (int) $f['seguro_empleo_cent'])
    ),
    $filas
));

// =====================================================================
// 2. El crédito contra su tabla
// =====================================================================
//
// El capital que queda por cobrar tiene que ser el saldo del crédito.
// Si no, o la tabla miente o el saldo miente, y cualquiera de las dos
// cosas se le cobra a alguien.

/* El capital pendiente de una cuota NO es su capital entero.
 *
 * Una cuota abierta puede tener parte del capital ya cobrada. Pasa en
 * cuanto un abono no alcanza para la cuota completa: el sistema imputa
 * primero los seguros y el interés, y lo que sobra va a capital. La
 * cuota sigue abierta —falta el recargo, o el resto— pero ese capital
 * ya está pagado y el crédito ya lo descontó de su saldo, como debe.
 *
 * Sumando el capital entero de toda cuota no pagada, esta comprobación
 * daba por pendiente un dinero ya cobrado y acusaba de descuadre a
 * siete créditos que estaban perfectos. El falso positivo es peor que
 * no comprobar: un verificador que grita sin motivo deja de mirarse.
 *
 * La fórmula es la MISMA que usa el cobro al imputar
 * (`ServicioCompensacion::capitalCubierto`). Tiene que serlo: si el
 * verificador midiera con otra regla que la que aplica el sistema,
 * estaría comprobando otra cosa.
 */
$filas = BaseDatos::todas(
    'SELECT c.folio, c.saldo_capital_cent,
            COALESCE((
                /* Los CAST no son adorno: `seguro_vida_cent` y
                   `seguro_empleo_cent` son BIGINT UNSIGNED, y restar
                   sin signo revienta en cuanto el resultado es
                   negativo —que es el caso normal, porque casi
                   ninguna cuota tiene abonado más que sus seguros—.
                   Sin esto la consulta no da un número malo: da un
                   error y la comprobación no llega a correr. */
                SELECT SUM(
                    GREATEST(0,
                        CAST(q.capital_cent AS SIGNED) - LEAST(
                            CAST(q.capital_cent AS SIGNED),
                            GREATEST(0,
                                CAST(q.pagado_cent AS SIGNED)
                                - CAST(q.interes_cent AS SIGNED)
                                - CAST(q.seguro_vida_cent AS SIGNED)
                                - CAST(q.seguro_empleo_cent AS SIGNED))
                        )
                    )
                )
                  FROM cuotas q
                 WHERE q.credito_id = c.id
                   AND q.estado NOT IN ("pagada","condonada")
            ), 0) AS capital_pendiente
       FROM creditos c
      WHERE c.estado = "vigente"
     HAVING capital_pendiente <> c.saldo_capital_cent
      ORDER BY c.folio'
);

printf("  2. Capital por cobrar = saldo del crédito ....... %s\n",
    $filas === [] ? 'cuadra' : count($filas) . ' crédito(s) NO');

fallo('Créditos cuyo saldo no coincide con su tabla', array_map(
    static fn(array $f): string => sprintf(
        '%s: la tabla deja %s por cobrar y el crédito dice %s (diferencia %s)',
        $f['folio'],
        dinero((int) $f['capital_pendiente']),
        dinero((int) $f['saldo_capital_cent']),
        dinero((int) $f['capital_pendiente'] - (int) $f['saldo_capital_cent'])
    ),
    $filas
));

// =====================================================================
// 3. La tabla liquida en cero
// =====================================================================

$filas = BaseDatos::todas(
    'SELECT c.folio, q.numero, q.saldo_posterior_cent
       FROM creditos c
       JOIN cuotas q ON q.credito_id = c.id
      WHERE c.estado = "vigente"
        AND q.numero = (SELECT MAX(q2.numero) FROM cuotas q2 WHERE q2.credito_id = c.id)
        AND q.saldo_posterior_cent <> 0
      ORDER BY c.folio'
);

printf("  3. La última cuota deja saldo cero .............. %s\n",
    $filas === [] ? 'cuadra' : count($filas) . ' crédito(s) NO');

fallo('Créditos que no liquidan', array_map(
    static fn(array $f): string => sprintf(
        '%s: tras la última cuota (%d) queda %s',
        $f['folio'], $f['numero'], dinero((int) $f['saldo_posterior_cent'])
    ),
    $filas
));

// =====================================================================
// 4. Nadie pagó más de lo que debía
// =====================================================================

$filas = BaseDatos::todas(
    'SELECT c.folio, q.numero, q.total_cent, q.pagado_cent, q.mora_cent
       FROM cuotas q JOIN creditos c ON c.id = q.credito_id
      WHERE q.pagado_cent > q.total_cent + q.mora_cent
      ORDER BY c.folio, q.numero
      LIMIT 20'
);

printf("  4. Lo abonado no supera lo debido .............. %s\n",
    $filas === [] ? 'cuadra' : count($filas) . ' cuota(s) NO');

fallo('Cuotas con abono mayor que la deuda', array_map(
    static fn(array $f): string => sprintf(
        '%s cuota %d: abonado %s sobre una deuda de %s',
        $f['folio'], $f['numero'], dinero((int) $f['pagado_cent']),
        dinero((int) $f['total_cent'] + (int) $f['mora_cent'])
    ),
    $filas
));

// =====================================================================
// 5. El cargo por día, el mismo en todo el sistema
// =====================================================================
//
// El estándar es $5.99 por cada día de atraso, en todos los productos.
// Aquí se comprueba dividiendo: la mora anotada entre los días de
// atraso tiene que dar el cargo diario.
//
// Se saltan las cuotas TOPADAS —cuando el recargo alcanzó el saldo
// pendiente deja de crecer, y entonces la división da menos sin que
// nada esté mal— y las de cero días.
//
// Lo que esta comprobación NO hace es mirar si dos cuotas del mismo
// crédito tienen importes muy distintos: eso es normal y esperado,
// porque la mora crece con los días. La versión anterior lo marcaba
// como anomalía y era un falso positivo.

$cargo = \CashMan\Dominio\Credito\Mora::cargoDeUnDia();

$malas = [];

foreach (BaseDatos::todas(
    'SELECT c.folio, q.numero, q.mora_cent, q.dias_mora,
            q.total_cent, q.pagado_cent
       FROM cuotas q JOIN creditos c ON c.id = q.credito_id
      WHERE q.mora_cent > 0 AND q.dias_mora > 0'
) as $q) {
    $pendiente = max(0, (int) $q['total_cent'] - (int) $q['pagado_cent']);
    $sinTope   = (int) $q['dias_mora'] * $cargo;

    /* Con el principal ya cubierto, el recargo quedó congelado.
     *
     * El tope de `Mora::calcular` es el saldo vencido: en cuanto un
     * abono cubre capital, interés y seguros, ese saldo es cero y el
     * recargo deja de crecer en el importe que tuviera. Dividirlo
     * entre los días da menos que el cargo diario, y no porque se
     * cobre mal: porque se dejó de cobrar el día en que el cliente
     * pagó el principal.
     *
     * Sin esta salida, toda cuota pagada a medias acababa acusada de
     * cobrar un recargo distinto al del contrato. Ocho de golpe, y
     * las ocho correctas. */
    if ($pendiente <= 0) {
        continue;
    }

    // Topada: el recargo llegó al saldo y dejó de crecer.
    if ($sinTope >= $pendiente && $pendiente > 0) {
        continue;
    }

    if ((int) $q['mora_cent'] !== $sinTope) {
        $malas[] = sprintf(
            '%s cuota %d: %s en %d día(s) = %s por día, y el estándar es %s',
            $q['folio'], (int) $q['numero'], dinero((int) $q['mora_cent']),
            (int) $q['dias_mora'],
            dinero((int) round((int) $q['mora_cent'] / max(1, (int) $q['dias_mora']))),
            dinero($cargo)
        );
    }
}

printf("  5. El cargo por día es el estándar ............. %s\n",
    $malas === [] ? 'cuadra' : count($malas) . ' cuota(s) NO');

fallo('Cuotas con un cargo diario distinto al estándar', array_slice($malas, 0, 20));

// =====================================================================
// 6. El rastro del devengo
// =====================================================================
//
// `mora_devengos` guarda una fila por día cobrado. Es AUDITORÍA, no la
// fuente del importe: el cobro usa `Mora::calcular()` y no lee esta
// tabla. Por eso aquí no se falla, se informa: mientras el devengo
// diario no se haya puesto al día, faltarán filas y no significa que
// nadie esté pagando de más.

$conMora = (int) BaseDatos::unValor(
    'SELECT COUNT(*) FROM cuotas WHERE mora_cent > 0 AND estado NOT IN ("pagada","condonada")'
);

$conRastro = (int) BaseDatos::unValor(
    'SELECT COUNT(DISTINCT cuota_id) FROM mora_devengos'
);

printf("  6. Rastro diario del devengo .................. %d de %d cuota(s) con filas\n",
    $conRastro, $conMora);
// =====================================================================
// 6b. La ficha dice lo que la caja cobra
// =====================================================================
//
// La comprobación que faltaba, y la que destapó el lío: la columna
// `mora_cent` tiene que valer exactamente lo que `Mora::calcular()`
// devuelve, porque esa función es la que usan `ServicioCredito` y
// `ServicioPasarela` al cobrar de verdad.
//
// Mientras no coincidieron, la tabla de amortización enseñaba $8,230.26
// en la columna MORA y $5.99 en el desglose de la misma fila.

$malas = [];

foreach (BaseDatos::todas(
    'SELECT c.folio, q.numero, q.mora_cent, q.total_cent, q.pagado_cent,
            DATEDIFF(CURDATE(), q.fecha_vencimiento) AS dias
       FROM cuotas q JOIN creditos c ON c.id = q.credito_id
      WHERE q.estado NOT IN ("pagada","condonada")'
) as $q) {
    /* Igual que en las comprobaciones 5 y 11: la cuota cuyo principal
       ya está cubierto tiene el recargo congelado y no hay nada que
       recalcular. Lo guardado es lo devengado hasta el día del abono,
       y es exactamente lo que falta por cobrar. */
    if ((int) $q['total_cent'] - (int) $q['pagado_cent'] <= 0) {
        continue;
    }

    $esperada = \CashMan\Dominio\Credito\Mora::calcular(
        \CashMan\Dominio\Comun\Monto::deCentavos(
            max(0, (int) $q['total_cent'] - (int) $q['pagado_cent'])
        ),
        max(0, (int) $q['dias'])
    )->centavos;

    if ($esperada !== (int) $q['mora_cent']) {
        $malas[] = sprintf(
            '%s cuota %d: la ficha dice %s y el cobro aplicaría %s (%d día(s))',
            $q['folio'], (int) $q['numero'], dinero((int) $q['mora_cent']),
            dinero($esperada), (int) $q['dias']
        );
    }
}

printf("  6b. La ficha dice lo que la caja cobra ......... %s\n",
    $malas === [] ? 'cuadra' : count($malas) . ' cuota(s) NO');

fallo('Cuotas donde la mora guardada no es la que se cobra', array_slice($malas, 0, 20));

// =====================================================================
// 7. La cuenta contra su último movimiento
// =====================================================================

$filas = BaseDatos::todas(
    'SELECT c.numero_cuenta, c.saldo_cent,
            (SELECT m.saldo_posterior_cent FROM movimientos m
              WHERE m.cuenta_id = c.id
              ORDER BY m.creado_en DESC, m.id DESC LIMIT 1) AS ultimo
       FROM cuentas c
      WHERE EXISTS (SELECT 1 FROM movimientos m2 WHERE m2.cuenta_id = c.id)
      ORDER BY c.numero_cuenta'
);

$malas = [];

foreach ($filas as $f) {
    if ((int) $f['saldo_cent'] !== (int) $f['ultimo']) {
        $malas[] = sprintf(
            'cuenta %s: saldo %s pero el último movimiento dejó %s',
            $f['numero_cuenta'], dinero((int) $f['saldo_cent']), dinero((int) $f['ultimo'])
        );
    }
}

printf("  7. Saldo de cuenta = último movimiento ......... %s\n",
    $malas === [] ? 'cuadra' : count($malas) . ' cuenta(s) NO');

fallo('Cuentas cuyo saldo no es el de su último movimiento', $malas);

// =====================================================================
// 8. Los movimientos suman el saldo
// =====================================================================

$filas = BaseDatos::todas(
    'SELECT c.numero_cuenta, c.saldo_cent,
            COALESCE((SELECT SUM(m.importe_cent) FROM movimientos m
                       WHERE m.cuenta_id = c.id), 0) AS suma
       FROM cuentas c
      ORDER BY c.numero_cuenta'
);

$malas = [];

foreach ($filas as $f) {
    if ((int) $f['saldo_cent'] !== (int) $f['suma']) {
        $malas[] = sprintf(
            'cuenta %s: saldo %s, sus movimientos suman %s (diferencia %s)',
            $f['numero_cuenta'], dinero((int) $f['saldo_cent']), dinero((int) $f['suma']),
            dinero((int) $f['saldo_cent'] - (int) $f['suma'])
        );
    }
}

printf("  8. Movimientos de la cuenta suman su saldo ..... %s\n",
    $malas === [] ? 'cuadra' : count($malas) . ' cuenta(s) NO');

fallo('Cuentas cuyos movimientos no suman el saldo', $malas);

// =====================================================================
// 9. El libro mayor
// =====================================================================

$desc = (int) BaseDatos::unValor('SELECT COUNT(*) FROM v_asientos_descuadrados');

printf("  9. Asientos de partida doble ................... %s\n",
    $desc === 0 ? 'cuadra' : $desc . ' descuadrado(s)');

if ($desc > 0) {
    fallo('Asientos descuadrados', ['Hay ' . $desc . ' asiento(s) cuya suma no es cero.']);
}

// =====================================================================
// 10. La tarjeta contra sus movimientos
// =====================================================================

$filas = BaseDatos::todas(
    'SELECT t.ultimos4, t.saldo_usado_cent,
            COALESCE((SELECT SUM(CASE WHEN m.tipo = "pago" THEN -m.importe_cent
                                      WHEN m.tipo = "reverso" THEN -m.importe_cent
                                      ELSE m.importe_cent END)
                        FROM tarjeta_movimientos m
                       WHERE m.tarjeta_id = t.id
                         AND m.estado = "liquidada"), 0) AS suma
       FROM tarjetas t
      WHERE t.tipo = "credito" AND t.estado <> "cancelada"
      ORDER BY t.id'
);

$malas = [];

foreach ($filas as $f) {
    if ((int) $f['saldo_usado_cent'] !== (int) $f['suma']) {
        $malas[] = sprintf(
            'tarjeta ····%s: usado %s, sus movimientos suman %s (diferencia %s)',
            $f['ultimos4'], dinero((int) $f['saldo_usado_cent']), dinero((int) $f['suma']),
            dinero((int) $f['saldo_usado_cent'] - (int) $f['suma'])
        );
    }
}

printf("  10. Saldo de tarjeta = sus movimientos ......... %s\n",
    $malas === [] ? 'cuadra' : count($malas) . ' tarjeta(s) NO');

fallo('Tarjetas cuyo saldo no coincide con sus movimientos', $malas);

// =====================================================================
// 11. La mora guardada es la que toca
// =====================================================================
//
// El recargo vive en dos sitios: `cuotas.mora_cent`, que es lo que se
// le cobra al cliente y lo que ve en pantalla, y la regla de
// `Mora::calcular()`, que es lo que el contrato dice que se le debe
// cobrar. Mientras el devengo diario corra, coinciden.
//
// Si divergen, el cliente está pagando algo distinto de lo que firmó
// —de más o de menos— y nadie se entera, porque cada cifra por
// separado se ve perfectamente razonable. Esta comprobación las
// enfrenta, que es la única forma de notarlo.
//
// Una diferencia aquí casi siempre significa una de dos cosas: que
// `bin/devengar-mora.php` lleva días sin correr, o que alguien tocó
// `mora_cent` por fuera de los servicios.

$malas = [];

foreach (BaseDatos::todas(
    'SELECT q.numero, q.total_cent, q.pagado_cent, q.mora_cent,
            c.folio, DATEDIFF(CURDATE(), q.fecha_vencimiento) AS dias
       FROM cuotas q
       JOIN creditos c ON c.id = q.credito_id
      WHERE q.estado NOT IN ("pagada","condonada")
        AND q.fecha_vencimiento < CURDATE()
        AND c.estado IN ("vigente","desembolsado","mora")'
) as $q) {
    $pendiente = (int) $q['total_cent'] - (int) $q['pagado_cent'];

    /* La cuota cuyo principal ya está cubierto queda fuera.
     *
     * Cuando un abono —una compensación, normalmente— alcanza para el
     * capital, el interés y los seguros pero no para el recargo, la
     * cuota sigue abierta debiendo SOLO la mora. Ahí `total - pagado`
     * da cero, y esta comprobación concluía que la mora teórica
     * también debía ser cero y marcaba como descuadrada una cuota
     * perfectamente correcta: la mora guardada es lo devengado y es
     * justo lo que falta por cobrar.
     *
     * Peor todavía, era ruido que tapaba hallazgos de verdad. Un
     * verificador que grita en falso acaba ignorándose, y entonces ya
     * no sirve para lo que se escribió.
     *
     * Sobre estas cuotas la mora tampoco crece: el tope de
     * `Mora::calcular` es el saldo vencido, y el saldo vencido del
     * principal ya es cero. */
    if ($pendiente <= 0) {
        continue;
    }

    $teorica = \CashMan\Dominio\Credito\Mora::calcular(
        \CashMan\Dominio\Comun\Monto::deCentavos($pendiente),
        (int) $q['dias']
    )->centavos;

    if ((int) $q['mora_cent'] !== $teorica) {
        $malas[] = sprintf(
            '%s cuota #%d: guardada %s, deberia ser %s',
            $q['folio'],
            (int) $q['numero'],
            dinero((int) $q['mora_cent']),
            dinero($teorica)
        );
    }
}

printf("  11. Mora guardada = la que manda el contrato .... %s\n",
    $malas === [] ? 'cuadra' : count($malas) . ' cuota(s) NO');

fallo('Cuotas cuya mora no coincide con la regla del contrato', $malas);

// =====================================================================
// 12. El sistema corre solo
// =====================================================================
//
// Las once comprobaciones de arriba miran si las cifras cuadran HOY.
// Ésta mira si van a seguir cuadrando mañana, que es otra cosa.
//
// Casi todas aquellas igualdades dependen de que las tareas periódicas
// corran: el devengo pone la mora al día, el corte cierra las tarjetas,
// el abono paga los intereses. Una noche sin devengar y la ficha del
// cliente dice una cifra mientras la caja le cobra otra. Pasó, y fueron
// treinta y cuatro cuotas.
//
// El sistema late solo —`bin/latido.php`, que arranca desde la primera
// visita— pero un latido caído es invisible hasta que algo se
// descuadra. Esto lo hace visible antes de que eso ocurra.

$latido = dirname(__DIR__) . '/storage/latido/latido.pid';
$malas  = [];

$marca = is_file($latido)
    ? json_decode((string) @file_get_contents($latido), true)
    : null;

$edad = is_array($marca) ? time() - (int) ($marca['visto'] ?? 0) : null;

if ($edad === null) {
    $malas[] = 'No hay latido corriendo: las tareas solo se ejecutarán cuando '
             . 'alguien use el sistema. Arránquelo con «php bin/latido.php».';
} elseif ($edad > 180) {
    $malas[] = sprintf(
        'El latido lleva %d minuto(s) callado; el proceso murió sin limpiar. '
        . 'Arránquelo con «php bin/latido.php».',
        (int) ($edad / 60)
    );
}

/* Y las tareas en sí. Una que lleva más del doble de su periodo sin
   correr está atrasada, late quien late. */
foreach (BaseDatos::todas(
    'SELECT clave, cada_minutos, ultima_corrida,
            TIMESTAMPDIFF(MINUTE, COALESCE(ultima_corrida, "2000-01-01"), NOW()) AS mins
       FROM tareas_sistema
      WHERE activa = 1'
) as $t) {
    $limite = max(30, (int) $t['cada_minutos'] * 2);

    if ((int) $t['mins'] > $limite) {
        $malas[] = sprintf(
            '%s: debía correr cada %d min y lleva %d sin hacerlo (última vez: %s)',
            (string) $t['clave'],
            (int) $t['cada_minutos'],
            (int) $t['mins'],
            (string) ($t['ultima_corrida'] ?? 'nunca')
        );
    }
}

printf("  12. El sistema corre solo ...................... %s\n",
    $malas === [] ? 'cuadra' : count($malas) . ' aviso(s)');

fallo('Tareas que no están corriendo como deben', $malas);

// =====================================================================
// 13. La cámara interbancaria
// =====================================================================
//
// La cámara es la contrapartida de todo lo que sale hacia otro banco y
// de todo lo que entra desde otro banco. Su saldo NO es de nadie de
// adentro: es lo que este banco le debe al sistema de pagos por el
// dinero que está en viaje.
//
// Tiene que valer exactamente la suma de lo enviado menos lo recibido,
// contando solo lo que fue de verdad a otra institución —lo que se
// manda a una cuenta de este mismo banco nunca pasa por aquí— y sin
// contar lo que la red devolvió, cuyo reverso ya vació la cámara.
//
// Si sobra, hay dinero que salió de un cliente y no llegó a ningún
// sitio. Si falta, se acreditó algo que nadie envió. Ninguna de las dos
// cosas produce un error el día que ocurre: producen un descuadre que
// aparece en un cierre, semanas después, cuando ya nadie recuerda qué
// lo causó.
// =====================================================================
$malasCamara = [];

$camaraId = (int) BaseDatos::unValor(
    'SELECT id FROM cuentas WHERE numero_cuenta = "0000000006" AND es_tecnica = 1'
);

if ($camaraId === 0) {
    $malasCamara[] = 'No existe la cuenta técnica de cámara (0000000006). Sin ella, '
                   . 'una transferencia a otro banco no tendría contrapartida.';
} else {
    /* El saldo según el libro mayor, no según la proyección: si los dos
       discrepan, ese es otro problema y lo caza la comprobación 7. */
    $saldoCamara = (int) BaseDatos::unValor(
        'SELECT COALESCE(SUM(importe_cent), 0) FROM movimientos WHERE cuenta_id = ?',
        [$camaraId]
    );

    $enViaje = (int) BaseDatos::unValor(
        'SELECT COALESCE(SUM(CASE WHEN sentido = "enviada" THEN monto_cent
                                  ELSE -monto_cent END), 0)
           FROM transferencias_interbancarias
          WHERE estado IN ("enviada", "aplicada")
            AND banco_contraparte_id <> (
                  SELECT id FROM bancos_participantes WHERE es_propio = 1 LIMIT 1
                )'
    );

    if ($saldoCamara !== $enViaje) {
        $malasCamara[] = sprintf(
            'La cámara tiene %s y lo que viajó suma %s: hay %s sin explicación.',
            Monto::deCentavos($saldoCamara)->formateado(),
            Monto::deCentavos($enViaje)->formateado(),
            Monto::deCentavos(abs($saldoCamara - $enViaje))->formateado()
        );
    }

    /* Una devuelta sin su asiento de reverso es dinero que el cliente no
       recuperó aunque la pantalla le diga que sí. */
    foreach (BaseDatos::todas(
        'SELECT folio, monto_cent, estado
           FROM transferencias_interbancarias
          WHERE estado IN ("rechazada", "devuelta") AND asiento_reverso_id IS NULL'
    ) as $t) {
        $malasCamara[] = sprintf(
            'La transferencia %s figura como %s pero no tiene asiento de reverso: '
            . 'al cliente no se le devolvieron los %s.',
            $t['folio'],
            $t['estado'],
            Monto::deCentavos((int) $t['monto_cent'])->formateado()
        );
    }

    /* Y al revés: una que sigue viva pero ya tiene reverso significa que
       se devolvió el dinero y el estado no se enteró. */
    foreach (BaseDatos::todas(
        'SELECT folio FROM transferencias_interbancarias
          WHERE estado IN ("creada", "enviada", "aplicada")
            AND asiento_reverso_id IS NOT NULL'
    ) as $t) {
        $malasCamara[] = sprintf(
            'La transferencia %s tiene asiento de reverso pero su estado no dice '
            . 'que se devolviera.',
            $t['folio']
        );
    }

    /* Las que llevan demasiado en camino. No es un descuadre todavía,
       pero es dinero del cliente retenido en la cámara. */
    $colgadas = (int) BaseDatos::unValor(
        'SELECT COUNT(*) FROM transferencias_interbancarias
          WHERE estado = "enviada" AND sentido = "enviada"
            AND enviada_en <= DATE_SUB(NOW(), INTERVAL 1 DAY)'
    );

    if ($colgadas > 0) {
        $malasCamara[] = sprintf(
            '%d transferencia(s) llevan más de un día en camino sin acuse de la red. '
            . 'Ese dinero sigue retenido en la cámara.',
            $colgadas
        );
    }
}

printf("  13. La cámara interbancaria ................... %s\n",
    $malasCamara === [] ? 'cuadra' : count($malasCamara) . ' hallazgo(s)');

fallo('La cámara interbancaria no cuadra', $malasCamara);

// =====================================================================

echo "\n";

if ($hallazgos === []) {
    echo "  Todo cuadra.\n";
    exit(0);
}

echo "  " . str_repeat('-', 74) . "\n";
printf("  %d comprobación(es) con hallazgos:\n\n", count($hallazgos));

foreach ($hallazgos as $h) {
    echo "  · " . $h['titulo'] . "\n";

    foreach ($h['filas'] as $f) {
        echo "      " . wordwrap($f, 68, "\n      ") . "\n";
    }

    echo "\n";
}

echo "  Nota: esto SEÑALA, no corrige. Cada hallazgo es dinero de alguien\n";
echo "  y la corrección es una decisión, no un automatismo.\n";

exit(1);
