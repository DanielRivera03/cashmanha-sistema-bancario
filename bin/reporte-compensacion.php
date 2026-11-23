#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reporte INTERNO de compensación.
 *
 * No va al cliente —cada cliente ya recibe su aviso y su aviso formal
 * en cada aplicación—, sino a una casilla del banco: cuánto se recuperó
 * por compensación en el periodo, a cuántos clientes, y cuánto fue a
 * crédito y cuánto a tarjeta. Sirve para medir recuperación de cartera.
 *
 * DESTINO
 *
 * El parámetro `reporte.correo_interno`. Si no está puesto, no se manda
 * (un reporte interno sin destinatario es un error de configuración, no
 * algo que adivinar).
 *
 * PERIODO
 *
 * Por defecto los últimos 30 días. `--dias=N` cambia la ventana;
 * `--desde=YYYY-MM-DD --hasta=YYYY-MM-DD` la fija exacta.
 *
 * Uso:
 *     php bin/reporte-compensacion.php              ensayo (enseña el reporte)
 *     php bin/reporte-compensacion.php --si         lo envía
 *     php bin/reporte-compensacion.php --dias=7 --si
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Dominio\Comun\Monto;

$opc = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        $par = explode('=', substr($a, 2), 2);
        $opc[$par[0]] = $par[1] ?? true;
    }
}

$deVerdad = isset($opc['si']) || isset($opc['aplicar']);

$dias  = isset($opc['dias']) ? max(1, (int) $opc['dias']) : 30;
$desde = isset($opc['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $opc['desde'])
    ? (string) $opc['desde'] . ' 00:00:00'
    : date('Y-m-d 00:00:00', strtotime("-{$dias} days"));
$hasta = isset($opc['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $opc['hasta'])
    ? (string) $opc['hasta'] . ' 23:59:59'
    : date('Y-m-d H:i:s');

echo "\n  REPORTE INTERNO DE COMPENSACIÓN\n";
echo '  ' . str_repeat('=', 68) . "\n";
echo "  Periodo: {$desde}  a  {$hasta}\n\n";

if (!Correo::habilitado()) {
    exit("  El correo está deshabilitado (CORREO_HABILITADO en .env).\n\n");
}

$destino = trim((string) Parametros::obtener('reporte.correo_interno', ''));

// --- Los totales del periodo ----------------------------------------
$tot = BaseDatos::unaFila(
    'SELECT COUNT(*) AS aplicaciones,
            COUNT(DISTINCT usuario_id) AS clientes,
            COALESCE(SUM(importe_cent),0) AS total_cent,
            COALESCE(SUM(mora_cent),0)    AS mora_cent,
            COALESCE(SUM(CASE WHEN credito_id IS NOT NULL THEN importe_cent ELSE 0 END),0) AS credito_cent,
            COALESCE(SUM(CASE WHEN tarjeta_id IS NOT NULL THEN importe_cent ELSE 0 END),0) AS tarjeta_cent
       FROM compensaciones
      WHERE creado_en BETWEEN ? AND ?',
    [$desde, $hasta]
);

$top = BaseDatos::todas(
    'SELECT CONCAT(u.nombres," ",u.apellidos) AS nombre,
            SUM(c.importe_cent) AS total_cent, COUNT(*) AS n
       FROM compensaciones c
       JOIN usuarios u ON u.id = c.usuario_id
      WHERE c.creado_en BETWEEN ? AND ?
      GROUP BY c.usuario_id
      ORDER BY total_cent DESC
      LIMIT 10',
    [$desde, $hasta]
);

$d = static fn(int $c): string => Monto::deCentavos($c)->formateado();

printf("  Aplicaciones: %d   Clientes: %d   Total: %s   (mora %s)\n",
    (int) $tot['aplicaciones'], (int) $tot['clientes'],
    $d((int) $tot['total_cent']), $d((int) $tot['mora_cent']));
printf("  A crédito: %s    A tarjeta: %s\n\n",
    $d((int) $tot['credito_cent']), $d((int) $tot['tarjeta_cent']));

if ((int) $tot['aplicaciones'] === 0) {
    echo "  Sin compensaciones en el periodo. No se envía reporte.\n\n";
    exit(0);
}

// --- Armar el correo ------------------------------------------------
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$f = static fn(string $x): string => date('d/m/Y', (int) strtotime($x));

$fila = static fn(string $k, string $v, bool $fuerte = false): string =>
    '<tr><td style="padding:9px 0;border-bottom:1px solid #e3e8ee;color:#667085;font-size:14px">'
    . $k . '</td><td style="padding:9px 0;border-bottom:1px solid #e3e8ee;text-align:right;'
    . 'font-size:14px' . ($fuerte ? ';font-weight:700' : ';font-weight:600') . '">' . $v . '</td></tr>';

$topHtml = '';
foreach ($top as $t) {
    $topHtml .= '<tr><td style="padding:7px 0;border-bottom:1px solid #eef1f5;font-size:13px">'
        . $e((string) $t['nombre']) . '</td>'
        . '<td style="padding:7px 0;border-bottom:1px solid #eef1f5;text-align:right;font-size:13px">'
        . (int) $t['n'] . '</td>'
        . '<td style="padding:7px 0;border-bottom:1px solid #eef1f5;text-align:right;font-size:13px;font-weight:600">'
        . $e($d((int) $t['total_cent'])) . '</td></tr>';
}

$cuerpo =
    '<p>Resumen de lo recuperado por <strong>compensación automática</strong> entre el '
    . $e($f($desde)) . ' y el ' . $e($f($hasta)) . '.</p>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;border-collapse:collapse">'
    . $fila('Aplicaciones', (string) (int) $tot['aplicaciones'])
    . $fila('Clientes alcanzados', (string) (int) $tot['clientes'])
    . $fila('Aplicado a crédito', $e($d((int) $tot['credito_cent'])))
    . $fila('Aplicado a tarjeta', $e($d((int) $tot['tarjeta_cent'])))
    . $fila('Recargo por mora cobrado', $e($d((int) $tot['mora_cent'])))
    . $fila('TOTAL RECUPERADO', $e($d((int) $tot['total_cent'])), true)
    . '</table>'
    . '<p style="margin-top:20px"><strong>Mayores aplicaciones del periodo</strong></p>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:12px 0;border-collapse:collapse">'
    . '<tr style="color:#667085;font-size:11px;text-transform:uppercase;letter-spacing:.04em">'
    . '<td style="padding:6px 0;border-bottom:2px solid #e3e8ee">Cliente</td>'
    . '<td style="padding:6px 0;border-bottom:2px solid #e3e8ee;text-align:right">Veces</td>'
    . '<td style="padding:6px 0;border-bottom:2px solid #e3e8ee;text-align:right">Monto</td></tr>'
    . $topHtml
    . '</table>'
    . '<p style="font-size:12px;color:#98a2b3;margin-top:18px">Reporte interno automático. '
    . 'No se envía a clientes.</p>';

$html = PlantillaCorreo::base(
    'Reporte de compensación',
    'Reporte interno',
    $cuerpo,
    [], null, null, null, '',
    'Resumen interno de la recuperación por compensación del periodo.',
    [], [], 'info'
);

if (!$deVerdad) {
    echo "  Top del periodo:\n";
    foreach ($top as $t) {
        printf("    %-32s %2d x  %s\n", mb_substr((string) $t['nombre'], 0, 32),
            (int) $t['n'], $d((int) $t['total_cent']));
    }
    echo "\n  Destino interno: " . ($destino !== '' ? $destino : '(SIN CONFIGURAR — parámetro reporte.correo_interno)') . "\n";
    echo "  ENSAYO: no se envió. Repita con --si\n\n";
    exit(0);
}

if ($destino === '') {
    exit("  No hay destinatario interno configurado (parámetro reporte.correo_interno). No se envía.\n\n");
}

$ok = (new Correo())->enviar($destino,
    'Reporte interno de compensación', $html,
    'Reporte interno de compensacion del periodo. Total recuperado: '
    . $d((int) $tot['total_cent']) . '.', 'Reporte interno');

Auditoria::registrar('reporte.compensacion', $ok ? Auditoria::EXITO : Auditoria::FALLO, null,
    'sistema', 'reporte', ['destino' => $destino, 'total' => Monto::deCentavos((int) $tot['total_cent'])->aDecimal()]);

echo $ok ? "  Reporte enviado a {$destino}.\n\n" : "  No se pudo enviar el reporte.\n\n";
