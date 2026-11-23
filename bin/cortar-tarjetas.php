<?php
declare(strict_types=1);

/**
 * Corte mensual de tarjetas de crédito.
 *
 * Cierra el periodo de cada tarjeta cuyo día de corte ya pasó: liquida
 * consumos y pagos, calcula intereses sobre lo financiado, aplica los
 * seguros obligatorios y fija el pago mínimo con su fecha límite.
 *
 * Es seguro ejecutarlo a diario: solo actúa sobre las tarjetas que
 * tengan un corte cumplido y sin procesar. Si una lleva meses sin
 * cortarse, cierra el mes pendiente más antiguo, de modo que los
 * periodos queden consecutivos; ejecútelo varias veces para ponerla al
 * día mes por mes.
 *
 * Uso:
 *   php bin/cortar-tarjetas.php                    → simula
 *   php bin/cortar-tarjetas.php --aplicar          → escribe
 *   php bin/cortar-tarjetas.php --hasta=2026-11-30 → procesa a esa fecha
 *
 * Para automatizarlo en Windows (Programador de tareas):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\cashmanha-v2\bin\cortar-tarjetas.php --aplicar
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioCorteTarjeta;
use CashMan\Infraestructura\Parametros;

$aplicar = in_array('--aplicar', $argv, true);
$hasta   = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--hasta=')) {
        $hasta = substr($arg, 8);
    }
}

$hasta ??= date('Y-m-d');

$dinero = static fn(int $c): string => '$' . number_format($c / 100, 2);

echo "\n";
echo "  CORTE DE TARJETAS DE CRÉDITO" . ($aplicar ? '' : '  (simulación)') . "\n";
echo "  " . str_repeat('=', 62) . "\n\n";

printf("  Fecha de proceso    %s\n", $hasta);
printf("  Pago mínimo         %s %% del saldo (piso %s)\n",
    number_format(Parametros::entero('tarjeta.pago_minimo_pct', 500) / 100, 2),
    $dinero(Parametros::entero('tarjeta.pago_minimo_piso_cent', 1000)));
printf("  Plazo para pagar    %d días desde el corte\n\n",
    Parametros::entero('tarjeta.dias_para_pago', 20));

$r = (new ServicioCorteTarjeta())->cortar($hasta, !$aplicar);

if ($r['cortes'] === 0) {
    echo "  Ninguna tarjeta tiene corte pendiente.\n\n";
    exit(0);
}

foreach ($r['detalle'] as $d) {
    echo "  " . str_repeat('-', 62) . "\n";
    printf("  %s   corte %s\n\n", $d['tarjeta'], $d['fecha_corte']);
    printf("    Periodo              %s a %s\n", $d['periodo_inicio'], $d['periodo_fin']);
    printf("    Saldo anterior       %12s\n", $dinero($d['saldo_anterior_cent']));
    printf("    Consumos (%d mov.)    %12s\n", $d['movimientos'], $dinero($d['consumos_cent']));
    printf("    Pagos                %12s\n", $dinero($d['pagos_cent']));
    printf("    Intereses            %12s\n", $dinero($d['intereses_cent']));
    printf("    Seguro de vida       %12s\n", $dinero($d['seguro_vida_cent']));
    printf("    Seguro de desempleo  %12s\n", $dinero($d['seguro_empleo_cent']));
    printf("    SALDO AL CORTE       %12s\n", $dinero($d['saldo_final_cent']));
    printf("    Pago mínimo          %12s   hasta el %s\n\n",
        $dinero($d['pago_minimo_cent']), $d['fecha_limite_pago']);
}

echo "  " . str_repeat('=', 62) . "\n";
printf("  Cortes generados    %d\n", $r['cortes']);
printf("  Saldo total         %s\n", $dinero($r['saldo_cent']));
printf("  Intereses           %s\n", $dinero($r['intereses_cent']));
printf("  Seguros             %s\n\n", $dinero($r['seguros_cent']));

if (!$aplicar) {
    echo "  No se escribió nada. Añada --aplicar para registrarlo.\n\n";
}

exit(0);
