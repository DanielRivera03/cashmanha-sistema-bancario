<?php
declare(strict_types=1);

/**
 * Devenga el recargo por mora de las cuotas vencidas.
 *
 * Está pensado para correr una vez al día. El recargo se anota día a
 * día en `mora_devengos`, de modo que:
 *
 *   · Volver a ejecutarlo el mismo día no duplica nada. La clave única
 *     (cuota, fecha) lo impide.
 *   · Si lleva días sin correr, rellena los que faltan uno por uno en
 *     lugar de sumarlos de golpe, y queda constancia de cada uno.
 *
 * Por qué no un EVENT de MySQL: el planificador de eventos está apagado
 * a propósito en esta instalación, y un cargo de dinero que se aplica
 * solo, sin bitácora ni forma de simularlo antes, es difícil de auditar
 * cuando un cliente reclama. Esto deja rastro y se puede ensayar.
 *
 * Uso:
 *   php bin/devengar-mora.php                    → simula, no escribe
 *   php bin/devengar-mora.php --aplicar          → escribe
 *   php bin/devengar-mora.php --hasta=2026-11-30 → corta en esa fecha
 *
 * Para automatizarlo en Windows (Programador de tareas):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\cashmanha-v2\bin\devengar-mora.php --aplicar
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioMora;
use CashMan\Dominio\Credito\Mora;
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
echo "  DEVENGO DE MORA" . ($aplicar ? '' : '  (simulación)') . "\n";
echo "  " . str_repeat('=', 62) . "\n\n";

printf("  Fecha de corte      %s\n", $hasta);
printf("  Cargo diario        %s por cuota\n", $dinero(Mora::cargoDeUnDia()));
printf("  Días de gracia      %d\n", Parametros::entero('mora.dias_gracia', 0));

$tope = Parametros::entero('mora.tope_por_cuota_cent', 0);
printf("  Tope por cuota      %s\n\n", $tope > 0 ? $dinero($tope) : 'sin tope (solo el saldo de la cuota)');

$r = (new ServicioMora())->devengar($hasta, !$aplicar);

if ($r['cuotas'] === 0) {
    echo "  No hay nada que devengar.\n\n";
    exit(0);
}

printf("  %-14s %-6s %-12s %6s %7s %11s %11s\n",
    'CRÉDITO', 'CUOTA', 'VENCIÓ', 'DÍAS', 'NUEVOS', 'SE AÑADE', 'ACUMULADO');
echo "  " . str_repeat('-', 76) . "\n";

foreach (array_slice($r['detalle'], 0, 40) as $d) {
    printf("  %-14s %-6d %-12s %6d %7d %11s %11s\n",
        $d['folio'], $d['cuota'], $d['vencio'], $d['dias'], $d['nuevos'],
        $dinero($d['incremento']), $dinero($d['acumulado']));
}

if (count($r['detalle']) > 40) {
    printf("  ... y %d cuota(s) más\n", count($r['detalle']) - 40);
}

echo "\n  " . str_repeat('=', 62) . "\n";
printf("  Cuotas afectadas    %d\n", $r['cuotas']);
printf("  Días devengados     %d\n", $r['dias']);
printf("  Recargo añadido     %s\n\n", $dinero($r['cargo_cent']));

if (!$aplicar) {
    echo "  No se escribió nada. Añada --aplicar para registrarlo.\n\n";
}

exit(0);
