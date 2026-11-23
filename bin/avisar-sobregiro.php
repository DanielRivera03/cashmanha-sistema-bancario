#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Aviso de sobregiro, automático.
 *
 * A quien tiene una tarjeta que ya pasó del límite aprobado pero
 * todavía NO se inhabilita, un correo para que cubra el excedente antes
 * de la fecha de pago y no se le bloquee la tarjeta.
 *
 * UNA VEZ POR CORTE
 *
 * La tabla `avisos_enviados` evita repetirlo cada día: el aviso sale
 * una vez por corte de la tarjeta. Si en el siguiente corte sigue
 * sobregirada, hay una referencia nueva y vuelve a avisar.
 *
 * SOLO TARJETAS
 *
 * El sobregiro es de la tarjeta: un crédito no pasa de su límite. El
 * aviso equivalente del crédito es el recordatorio de pago y, si ya se
 * atrasó, el aviso de atraso.
 *
 * Uso:
 *     php bin/avisar-sobregiro.php              dice qué haría (ensayo)
 *     php bin/avisar-sobregiro.php --si         lo envía
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Dominio\Comun\Monto;

$deVerdad = array_intersect(['--si', '--aplicar'], array_slice($argv, 1)) !== [];

echo "\n  AVISO DE SOBREGIRO (tarjeta sobre el límite, aún activa)\n";
echo '  ' . str_repeat('=', 68) . "\n\n";

if (!Correo::habilitado()) {
    exit("  El correo está deshabilitado (CORREO_HABILITADO en .env).\n\n");
}

$filas = BaseDatos::todas(
    'SELECT u.id AS uid, CONCAT(u.nombres," ",u.apellidos) AS nombre, u.correo,
            t.id AS tid, t.ultimos4,
            (t.saldo_usado_cent - t.limite_cent) AS excedente_cent,
            t.limite_cent, t.saldo_usado_cent,
            k.id AS corte_id, k.fecha_limite_pago,
            CONCAT("sobregiro:", COALESCE(k.id, 0), ":", t.id) AS ref
       FROM tarjetas t
       JOIN usuarios u ON u.id = t.usuario_id
  LEFT JOIN tarjeta_cortes k ON k.id = (
               SELECT k2.id FROM tarjeta_cortes k2
                WHERE k2.tarjeta_id = t.id
                ORDER BY k2.fecha_corte DESC, k2.id DESC LIMIT 1)
      WHERE u.estado = "activo" AND u.correo <> ""
        AND t.tipo = "credito" AND t.estado = "activa"
        AND t.limite_cent > 0 AND t.saldo_usado_cent > t.limite_cent
        AND NOT EXISTS (SELECT 1 FROM avisos_enviados a
                         WHERE a.clave = "sobregiro"
                           AND a.referencia = CONCAT("sobregiro:", COALESCE(k.id, 0), ":", t.id))
      ORDER BY uid'
);

if ($filas === []) {
    exit("  No hay tarjetas sobre el límite por avisar (o ya se avisó).\n\n");
}

$porCliente = [];
foreach ($filas as $r) {
    $porCliente[(int) $r['uid']]['cliente'] ??= ['nombre' => $r['nombre'], 'correo' => $r['correo']];
    $porCliente[(int) $r['uid']]['tarjetas'][] = $r;
}

printf("  %d cliente(s), %d tarjeta(s) sobre el límite.\n\n", count($porCliente), count($filas));

$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$d = static fn(int $c): string => Monto::deCentavos($c)->formateado();
$f = static fn(?string $x): string => $x !== null ? date('d/m/Y', (int) strtotime($x)) : 'su próxima fecha de pago';

$armar = static function (string $nombre, array $tarjetas) use ($e, $d, $f): array {
    $filas = '';
    foreach ($tarjetas as $t) {
        $filas .= '<li style="margin-bottom:8px">Tarjeta <strong>••••' . $e((string) $t['ultimos4'])
            . '</strong>: superó su límite en <strong>' . $e($d((int) $t['excedente_cent']))
            . '</strong>. Cubra al menos ese excedente antes del <strong>'
            . $e($f($t['fecha_limite_pago'] !== null ? (string) $t['fecha_limite_pago'] : null))
            . '</strong>.</li>';
    }

    $cuerpo =
        '<p>Le avisamos que una o más de sus tarjetas superaron el límite de crédito '
        . 'aprobado. El excedente es exigible de inmediato.</p>'
        . '<ul style="font-size:14px;color:#475467;padding-left:18px;margin:14px 0;line-height:1.6">'
        . $filas . '</ul>'
        . '<p>Si no cubre el excedente a la fecha de pago, la tarjeta se <strong>inhabilita</strong> '
        . 'para nuevos consumos hasta solventarlo; se reactiva sola en cuanto el saldo vuelve dentro '
        . 'de su límite. Puede pagar desde su banca en línea o en caja.</p>';

    $html = PlantillaCorreo::base(
        'Su tarjeta superó el límite',
        'Estimado(a) ' . $nombre . ':',
        $cuerpo,
        [], null, null, null, '',
        'Su tarjeta superó el límite aprobado; cubra el excedente antes de la fecha de pago.',
        [], [], 'aviso'
    );

    return [$html, 'Su tarjeta supero el limite aprobado. El excedente es exigible; cubralo antes '
        . 'de la fecha de pago o la tarjeta se inhabilita. Pague desde su banca en linea.'];
};

if (!$deVerdad) {
    foreach ($porCliente as $uid => $g) {
        printf("    %-30s %d tarjeta(s)  %s\n",
            mb_substr((string) $g['cliente']['nombre'], 0, 30),
            count($g['tarjetas']), $g['cliente']['correo']);
    }
    echo "\n  ENSAYO: no se envió nada. Repita con --si\n\n";
    exit(0);
}

$correo   = new Correo();
$enviados = 0;
$fallidos = 0;

foreach ($porCliente as $uid => $g) {
    [$html, $texto] = $armar((string) $g['cliente']['nombre'], $g['tarjetas']);

    try {
        $ok = $correo->enviar((string) $g['cliente']['correo'],
            'Su tarjeta superó el límite', $html, $texto, (string) $g['cliente']['nombre']);

        if ($ok) {
            foreach ($g['tarjetas'] as $t) {
                BaseDatos::ejecutar(
                    'INSERT IGNORE INTO avisos_enviados (clave, referencia, usuario_id, enviado_en)
                     VALUES ("sobregiro", ?, ?, NOW())',
                    [(string) $t['ref'], (int) $uid]
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

Auditoria::registrar('cliente.aviso_sobregiro', Auditoria::EXITO, null, 'usuario', 'varios',
    ['enviados' => $enviados, 'fallidos' => $fallidos]);

printf("\n  %d enviado(s), %d sin salir.\n\n", $enviados, $fallidos);
