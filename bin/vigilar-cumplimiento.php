#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * La vigilancia diaria de lavado de dinero.
 *
 * Revisa las operaciones del día contra los umbrales de la Ley Contra
 * el Lavado de Dinero y de Activos y levanta alertas para el encargado
 * de cumplimiento.
 *
 * NO reporta nada a la UIF. Detecta, deja constancia y arma el
 * expediente. El envío es un acto de una persona, por los canales de
 * la Fiscalía, y un sistema que dijera «reportado» sin haber reportado
 * sería peor que no tener nada: daría por cumplida una obligación que
 * seguiría pendiente.
 *
 * Uso:
 *     php bin/vigilar-cumplimiento.php                 ensayo de hoy
 *     php bin/vigilar-cumplimiento.php --aplicar       levanta las alertas
 *     php bin/vigilar-cumplimiento.php --dia=2026-11-01 --aplicar
 *     php bin/vigilar-cumplimiento.php --desde=2026-10-01 --hasta=2026-11-13 --aplicar
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioCumplimiento;
use CashMan\Dominio\Cumplimiento\UmbralesUIF;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/* A mano, no con getopt(): se detiene en el primer argumento suelto y
   deja de ver las banderas que vienen detrás. */
$opciones = [];

foreach (array_slice($argv, 1) as $a) {
    if (!str_starts_with($a, '--')) {
        continue;
    }

    $par = explode('=', substr($a, 2), 2);
    $opciones[$par[0]] = $par[1] ?? true;
}

$aplicar = isset($opciones['aplicar']);

$desde = (string) ($opciones['desde'] ?? $opciones['dia'] ?? date('Y-m-d'));
$hasta = (string) ($opciones['hasta'] ?? $opciones['dia'] ?? date('Y-m-d'));

foreach ([$desde, $hasta] as $f) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) !== 1) {
        exit("  Fecha no válida: {$f}. Use AAAA-MM-DD.\n");
    }
}

echo "\n  VIGILANCIA DE LAVADO DE DINERO\n";
echo '  ' . str_repeat('=', 68) . "\n\n";

printf("  Periodo revisado ........................ %s a %s\n", $desde, $hasta);
printf("  Umbral en efectivo ...................... \$%s (art. 9 LCLDA)\n",
    number_format(UmbralesUIF::EFECTIVO_CENT / 100, 2));
printf("  Umbral otros medios ..................... \$%s\n",
    number_format(UmbralesUIF::OTROS_MEDIOS_CENT / 100, 2));

if (!$aplicar) {
    echo "\n  ENSAYO: no se levanta ninguna alerta. Repita con --aplicar.\n";
}

echo "\n";

$servicio = new ServicioCumplimiento();

$totales = [];

$dia = new DateTimeImmutable($desde);
$fin = new DateTimeImmutable($hasta);

while ($dia <= $fin) {
    $conteo = $servicio->vigilar($dia->format('Y-m-d'), $aplicar);

    foreach ($conteo as $tipo => $n) {
        $totales[$tipo] = ($totales[$tipo] ?? 0) + $n;
    }

    $dia = $dia->modify('+1 day');
}

$etiquetas = [
    'umbral_efectivo_diario'  => 'Efectivo sobre el umbral en un día',
    'umbral_efectivo_mensual' => 'Efectivo acumulado del mes',
    'umbral_otros_medios'     => 'Otros medios sobre el umbral',
    'fraccionamiento'         => 'Fraccionamiento (operaciones bajo el umbral)',
    'fuera_de_perfil'         => 'Fuera del perfil declarado',
    'pep_movimiento_alto'     => 'Persona expuesta con movimiento alto',
    'cuenta_nueva_activa'     => 'Cuenta nueva con movimiento fuerte',
    'lista_restrictiva'       => 'Coincidencia con lista restrictiva',
];

$suma = 0;

foreach ($etiquetas as $tipo => $texto) {
    $n = $totales[$tipo] ?? 0;
    $suma += $n;

    printf("  %-46s %s\n", $texto, $n === 0 ? '—' : $n);
}

printf("\n  %-46s %d\n", $aplicar ? 'Alertas levantadas' : 'Alertas que se levantarían', $suma);

// ---------------------------------------------------------------------
// Lo que ya estaba abierto, y lo que corre prisa
// ---------------------------------------------------------------------
$p = $servicio->panorama();

echo "\n  SITUACIÓN DEL ÁREA DE CUMPLIMIENTO\n";
echo '  ' . str_repeat('-', 68) . "\n";

printf("  Alertas abiertas ........................ %d (%d de severidad alta)\n",
    $p['abiertas'], $p['altas']);

if ($p['vencidas'] > 0) {
    printf("  !! Con el plazo de análisis VENCIDO ...... %d\n", $p['vencidas']);
    echo "     El artículo 9-A da quince días hábiles, prorrogables una vez.\n";
}

printf("  Reportes en borrador .................... %d\n", $p['borradores']);

if ($p['borradores_vencidos'] > 0) {
    printf("  !! Borradores con el plazo VENCIDO ....... %d\n", $p['borradores_vencidos']);
    echo "     El plazo legal de remisión es de cinco días hábiles.\n";
}

printf("  Reportes enviados ....................... %d\n", $p['enviados']);

echo "\n  LO QUE FALTA PARA PODER VIGILAR BIEN\n";
echo '  ' . str_repeat('-', 68) . "\n";

printf("  Clientes sin perfil transaccional ....... %d\n", $p['sin_perfil']);

if ($p['sin_perfil'] > 0) {
    echo "     Sin perfil declarado no se puede decir que algo es inusual:\n";
    echo "     inusual es lo que se sale de lo esperado, y lo esperado hay\n";
    echo "     que haberlo preguntado.\n";
}

printf("  Con la debida diligencia vencida ........ %d\n", $p['diligencia_vencida']);

$enListas = (int) BaseDatos::unValor('SELECT COUNT(*) FROM listas_restrictivas WHERE vigente = 1');

printf("  Nombres cargados en listas restrictivas . %d\n", $enListas);

if ($enListas === 0) {
    echo "     No hay ninguna lista cargada, así que ese cotejo no filtra nada.\n";
    echo "     Las listas de OFAC y Naciones Unidas se publican en sus sitios\n";
    echo "     oficiales y se cargan en la tabla listas_restrictivas.\n";
}

echo "\n";
