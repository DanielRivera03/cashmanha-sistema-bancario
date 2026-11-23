#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Recordatorio de pago, automático.
 *
 * A cada cliente con una cuota de crédito o un corte de tarjeta que
 * vence en los próximos días (parámetro `aviso.dias_previos_pago`), un
 * correo que se lo recuerda ANTES de vencer, para que no caiga en mora.
 *
 * UNA VEZ POR CUOTA O CORTE
 *
 * La tabla `avisos_enviados` recuerda qué ya salió. Corra esto cada día
 * sin miedo: un cliente recibe el recordatorio de una cuota una sola
 * vez, el día que entra en la ventana. Cuando nace una cuota o un corte
 * nuevo, hay una referencia nueva y vuelve a avisar.
 *
 * UN SOLO CORREO POR CLIENTE
 *
 * Si tiene varias cosas por vencer, van juntas en un mensaje, no una
 * ráfaga.
 *
 * Uso:
 *     php bin/recordar-pagos.php              dice qué haría (ensayo)
 *     php bin/recordar-pagos.php --si         lo envía
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Dominio\Comun\Monto;

$deVerdad = array_intersect(['--si', '--aplicar'], array_slice($argv, 1)) !== [];
$dias     = max(1, Parametros::entero('aviso.dias_previos_pago', 7));

echo "\n  RECORDATORIO DE PAGO (vence en <= {$dias} dia(s))\n";
echo '  ' . str_repeat('=', 68) . "\n\n";

if (!Correo::habilitado()) {
    exit("  El correo está deshabilitado (CORREO_HABILITADO en .env).\n\n");
}

/* Lo que vence pronto y aún no se ha recordado: cuotas de crédito y
   cortes de tarjeta, en una sola lista por cliente. */
$items = BaseDatos::todas(
    'SELECT u.id AS uid, CONCAT(u.nombres," ",u.apellidos) AS nombre, u.correo,
            "cuota" AS tipo, CONCAT("cuota:", q.id) AS ref,
            cr.folio AS detalle, (q.total_cent - q.pagado_cent) AS monto_cent,
            q.fecha_vencimiento AS fecha
       FROM cuotas q
       JOIN creditos cr ON cr.id = q.credito_id
       JOIN usuarios u  ON u.id = cr.usuario_id
      WHERE u.estado = "activo" AND u.correo <> ""
        AND q.estado NOT IN ("pagada","condonada")
        AND q.fecha_vencimiento BETWEEN CURDATE() AND CURDATE() + INTERVAL ' . (int) $dias . ' DAY
        AND NOT EXISTS (SELECT 1 FROM avisos_enviados a
                         WHERE a.clave = "recordatorio-pago"
                           AND a.referencia = CONCAT("cuota:", q.id))
      UNION ALL
     SELECT u.id, CONCAT(u.nombres," ",u.apellidos), u.correo,
            "tarjeta", CONCAT("corte:", k.id),
            CONCAT("Tarjeta ****", t.ultimos4), k.pago_minimo_cent,
            k.fecha_limite_pago
       FROM tarjeta_cortes k
       JOIN tarjetas t ON t.id = k.tarjeta_id
       JOIN usuarios u ON u.id = t.usuario_id
      WHERE u.estado = "activo" AND u.correo <> ""
        AND t.saldo_usado_cent > 0
        AND k.fecha_limite_pago BETWEEN CURDATE() AND CURDATE() + INTERVAL ' . (int) $dias . ' DAY
        AND NOT EXISTS (SELECT 1 FROM avisos_enviados a
                         WHERE a.clave = "recordatorio-pago"
                           AND a.referencia = CONCAT("corte:", k.id))
      ORDER BY uid, fecha'
);

if ($items === []) {
    exit("  Nada por vencer en la ventana (o ya se recordó todo).\n\n");
}

// Agrupar por cliente.
$porCliente = [];
foreach ($items as $it) {
    $porCliente[(int) $it['uid']]['cliente'] ??= [
        'nombre' => $it['nombre'], 'correo' => $it['correo'],
    ];
    $porCliente[(int) $it['uid']]['items'][] = $it;
}

printf("  %d cliente(s) con algo por vencer (%d aviso(s) nuevos).\n\n",
    count($porCliente), count($items));

$e  = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$d  = static fn(int $c): string => Monto::deCentavos($c)->formateado();
$f  = static fn(string $x): string => date('d/m/Y', (int) strtotime($x));

$armar = static function (string $nombre, array $items) use ($e, $d, $f): array {
    $filas = '';
    foreach ($items as $it) {
        $filas .= '<tr>'
            . '<td style="padding:8px 0;border-bottom:1px solid #e3e8ee;font-size:14px">'
            . ($it['tipo'] === 'cuota' ? 'Cuota de crédito ' : '') . $e((string) $it['detalle']) . '</td>'
            . '<td style="padding:8px 0;border-bottom:1px solid #e3e8ee;font-size:14px;text-align:right">'
            . 'vence ' . $e($f((string) $it['fecha'])) . '</td>'
            . '<td style="padding:8px 0;border-bottom:1px solid #e3e8ee;font-size:14px;text-align:right;font-weight:600">'
            . $e($d((int) $it['monto_cent'])) . '</td></tr>';
    }

    $cuerpo =
        '<p>Le recordamos que tiene pagos próximos a vencer. Pagar antes de la fecha '
        . 'evita recargos por mora y protege su historial crediticio.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
        . 'style="margin:16px 0;border-collapse:collapse">'
        . '<tr style="color:#667085;font-size:11px;text-transform:uppercase;letter-spacing:.04em">'
        . '<td style="padding:6px 0;border-bottom:2px solid #e3e8ee">Concepto</td>'
        . '<td style="padding:6px 0;border-bottom:2px solid #e3e8ee;text-align:right">Fecha</td>'
        . '<td style="padding:6px 0;border-bottom:2px solid #e3e8ee;text-align:right">Monto</td></tr>'
        . $filas
        . '</table>'
        . '<p>Puede pagar desde su banca en línea. Si ya realizó el pago, haga caso omiso '
        . 'de este mensaje.</p>';

    $html = PlantillaCorreo::base(
        'Tiene pagos próximos a vencer',
        'Estimado(a) ' . $nombre . ':',
        $cuerpo,
        [], null, null, null, '',
        'Recordatorio de pagos próximos a vencer en su cuenta.',
        [], [], 'info'
    );

    return [$html, 'Tiene pagos proximos a vencer. Pague antes de la fecha para evitar mora. '
        . 'Revise su banca en linea.'];
};

if (!$deVerdad) {
    foreach ($porCliente as $uid => $g) {
        printf("    %-30s %d item(s)  %s\n",
            mb_substr((string) $g['cliente']['nombre'], 0, 30),
            count($g['items']), $g['cliente']['correo']);
    }
    echo "\n  ENSAYO: no se envió nada. Repita con --si\n\n";
    exit(0);
}

$correo   = new Correo();
$enviados = 0;
$fallidos = 0;

foreach ($porCliente as $uid => $g) {
    [$html, $texto] = $armar((string) $g['cliente']['nombre'], $g['items']);

    try {
        $ok = $correo->enviar((string) $g['cliente']['correo'],
            'Tiene pagos próximos a vencer', $html, $texto,
            (string) $g['cliente']['nombre']);

        if ($ok) {
            // Marcar cada referencia como ya recordada.
            foreach ($g['items'] as $it) {
                BaseDatos::ejecutar(
                    'INSERT IGNORE INTO avisos_enviados (clave, referencia, usuario_id, enviado_en)
                     VALUES ("recordatorio-pago", ?, ?, NOW())',
                    [(string) $it['ref'], (int) $uid]
                );
            }
            $enviados++;
        } else {
            $fallidos++;
        }

        printf("  %-30s %s\n", mb_substr((string) $g['cliente']['nombre'], 0, 30),
            $ok ? 'enviado' : 'no salió');
    } catch (\Throwable $ex) {
        $fallidos++;
        printf("  %-30s falló: %s\n", mb_substr((string) $g['cliente']['nombre'], 0, 30), $ex->getMessage());
    }
}

Auditoria::registrar('cliente.recordatorio_pago', Auditoria::EXITO, null, 'usuario', 'varios',
    ['enviados' => $enviados, 'fallidos' => $fallidos]);

printf("\n  %d enviado(s), %d sin salir.\n\n", $enviados, $fallidos);
