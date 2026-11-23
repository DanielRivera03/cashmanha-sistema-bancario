#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Aviso de atraso y sus consecuencias, a los clientes que registran
 * pagos vencidos.
 *
 * A QUIÉN
 *
 * Clientes activos con correo que tienen (o tuvieron) un atraso: una
 * cuota de crédito vencida o pagada tarde, o una tarjeta inhabilitada
 * por sobregiro. A cada uno le llega SU aviso y nada más; la copia de
 * monitoreo va oculta (parámetro `correo.copia_oculta`).
 *
 * EN SECUENCIA, HAROLD PRIMERO
 *
 * Se envía uno por uno, y el primero es Harold Osmany García, como se
 * pidió. El resto, por orden de registro.
 *
 * NO MANDA NADA SIN QUE SE LE DIGA
 *
 * Por defecto solo dice a quién le escribiría y enseña el correo. Con
 * `--si` lo envía de verdad.
 *
 * Uso:
 *     php bin/avisar-atrasos.php              dice qué haría (ensayo)
 *     php bin/avisar-atrasos.php --si         lo envía, en secuencia
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

$deVerdad = in_array('--si', array_slice($argv, 1), true);

echo "\n  AVISO DE ATRASO Y SUS CONSECUENCIAS\n";
echo '  ' . str_repeat('=', 68) . "\n\n";

if (!Correo::habilitado()) {
    echo "  El correo está deshabilitado (CORREO_HABILITADO en .env).\n";
    echo "  Nada que enviar hasta que se encienda.\n\n";
    exit(1);
}

/* Harold primero (u.id = 10), luego el resto por registro. El atraso
   se detecta por cuota de crédito vencida o pagada tarde, o tarjeta
   inhabilitada por sobregiro. */
$clientes = BaseDatos::todas(
    'SELECT DISTINCT u.id, CONCAT(u.nombres," ",u.apellidos) AS nombre, u.correo
       FROM usuarios u
      WHERE u.estado = "activo" AND u.correo <> ""
        AND (
          EXISTS (SELECT 1 FROM creditos c JOIN cuotas q ON q.credito_id = c.id
                   WHERE c.usuario_id = u.id
                     AND (q.pagada_tarde = 1
                          OR (q.estado NOT IN ("pagada","condonada")
                              AND q.fecha_vencimiento < CURDATE())))
          OR EXISTS (SELECT 1 FROM tarjetas t
                      WHERE t.usuario_id = u.id AND t.estado = "inhabilitada")
        )
      ORDER BY (u.id = 10) DESC, u.id'
);

if ($clientes === []) {
    exit("  No hay clientes con atraso a quienes avisar.\n\n");
}

printf("  %d cliente(s) con atraso. El primero es %s.\n",
    count($clientes), $clientes[0]['nombre']);
echo "  (copia oculta de monitoreo: "
    . (trim((string) \CashMan\Infraestructura\Parametros::obtener('correo.copia_oculta', '')) ?: 'ninguna')
    . ")\n\n";

$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

/** Arma el correo de un cliente. */
$armar = static function (string $nombre) use ($e): array {
    $cuerpo =
        '<p>Nuestros registros muestran que usted tiene uno o más pagos vencidos en '
        . 'sus productos con CashMan H.A. Le escribimos para explicarle, con claridad, '
        . 'qué implica un atraso y cómo evitar que le afecte más.</p>'

        . '<p style="margin-top:20px"><strong>Consecuencias de un pago atrasado</strong></p>'
        . '<ul style="font-size:14px;color:#475467;padding-left:18px;margin:0;line-height:1.7">'
        . '<li><strong>Recargos por mora:</strong> cada día de atraso genera intereses '
        . 'moratorios sobre el saldo vencido, que se suman a lo que ya debe.</li>'
        . '<li><strong>Historial crediticio:</strong> el atraso se informa a las centrales '
        . 'de riesgo y queda registrado en su historial.</li>'
        . '<li><strong>Inhabilitación para nuevos productos:</strong> un atraso lo deja '
        . 'inhabilitado para optar a nuevos créditos, tarjetas y ofertas durante un tiempo '
        . 'que fija la ley, incluso después de ponerse al día.</li>'
        . '<li><strong>Compensación de su saldo:</strong> conforme a la autorización que '
        . 'usted firmó, el saldo disponible en su cuenta puede aplicarse automáticamente a '
        . 'sus cuotas vencidas.</li>'
        . '<li><strong>Tarjetas:</strong> si una tarjeta supera su límite y no se cubre a la '
        . 'fecha de pago, se inhabilita para nuevos consumos hasta solventarla.</li>'
        . '<li><strong>Cobro y castigo:</strong> de persistir el atraso, el caso pasa a '
        . 'cobranza y puede llegar a cobro judicial y al castigo de la deuda.</li>'
        . '</ul>'

        . '<p style="margin-top:20px"><strong>Qué puede hacer</strong></p>'
        . '<p>Poniéndose al día cuanto antes detiene los recargos y protege su historial. '
        . 'Desde su banca en línea puede ver sus cuotas vencidas y pagarlas, o solicitar un '
        . 'arreglo de pago. Si ya realizó el pago, por favor haga caso omiso de este mensaje.</p>';

    $html = PlantillaCorreo::base(
        'Su cuenta registra pagos atrasados',
        'Estimado(a) ' . $nombre . ':',
        $cuerpo,
        [],
        null,
        null,
        null,
        '',
        'Información importante sobre sus pagos atrasados y las consecuencias de no regularizarlos.',
        [],
        [],
        'aviso'
    );

    $texto = 'Su cuenta registra pagos atrasados. Un atraso genera recargos por mora, '
        . 'se informa a las centrales de riesgo, lo inhabilita para nuevos productos por un '
        . 'tiempo, permite la compensación de su saldo, puede inhabilitar sus tarjetas y, de '
        . 'persistir, lleva a cobro judicial y castigo de la deuda. Póngase al día desde su '
        . 'banca en línea cuanto antes.';

    return [$html, $texto];
};

// --- Ensayo: enseñar el primero y la lista, sin enviar -------------
if (!$deVerdad) {
    foreach ($clientes as $i => $c) {
        printf("    %2d. %-32s %s\n", $i + 1, mb_substr((string) $c['nombre'], 0, 32), $c['correo']);
    }
    echo "\n  ENSAYO: no se envió nada. Repita con --si para enviar en secuencia.\n\n";
    exit(0);
}

// --- Envío en secuencia --------------------------------------------
$correo   = new Correo();
$enviados = 0;
$fallidos = 0;

foreach ($clientes as $i => $c) {
    [$html, $texto] = $armar((string) $c['nombre']);

    try {
        $ok = $correo->enviar(
            (string) $c['correo'],
            'Su cuenta registra pagos atrasados',
            $html,
            $texto,
            (string) $c['nombre']
        );
        $ok ? $enviados++ : $fallidos++;
        printf("  [%2d/%2d] %-30s %s\n", $i + 1, count($clientes),
            mb_substr((string) $c['nombre'], 0, 30), $ok ? 'enviado' : 'no salió');
    } catch (\Throwable $ex) {
        $fallidos++;
        printf("  [%2d/%2d] %-30s falló: %s\n", $i + 1, count($clientes),
            mb_substr((string) $c['nombre'], 0, 30), $ex->getMessage());
    }
}

Auditoria::registrar('cliente.aviso_atrasos', Auditoria::EXITO, null, 'usuario', 'varios',
    ['enviados' => $enviados, 'fallidos' => $fallidos]);

printf("\n  %d enviado(s), %d sin salir.\n\n", $enviados, $fallidos);
