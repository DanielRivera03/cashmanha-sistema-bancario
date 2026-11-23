#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Vence las remesas que nadie cobró dentro de su plazo.
 *
 * No mueve dinero: una remesa no cobrada nunca salió de la remesadora.
 * Lo que hace es dejar de decir que está disponible, y avisarle al
 * beneficiario para que llame a quien se la envió.
 *
 * Uso:
 *     php bin/vencer-remesas.php            dice cuántas vencerían
 *     php bin/vencer-remesas.php --aplicar  las vence y avisa
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioRemesas;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;

$aplicar = in_array('--aplicar', array_slice($argv, 1), true);

echo "\n  REMESAS VENCIDAS\n";
echo '  ' . str_repeat('=', 68) . "\n\n";

$porVencer = BaseDatos::todas(
    'SELECT r.folio, r.monto_cent, r.beneficiario_nombre, r.expira_en,
            rm.nombre_corto AS remesadora
       FROM remesas r
       JOIN remesadoras rm ON rm.id = r.remesadora_id
      WHERE r.estado = "disponible" AND r.expira_en IS NOT NULL AND r.expira_en < CURDATE()
      ORDER BY r.expira_en'
);

printf("  Remesas con el plazo cumplido ........... %d\n\n", count($porVencer));

foreach ($porVencer as $p) {
    printf(
        "      %s  %-12s %10s  %s\n",
        $p['folio'],
        $p['remesadora'],
        Monto::deCentavos((int) $p['monto_cent'])->formateado(),
        $p['beneficiario_nombre']
    );
}

if (!$aplicar) {
    echo "\n  ENSAYO: no se venció ninguna. Repita con --aplicar.\n\n";
    exit(0);
}

$n = (new ServicioRemesas())->vencerCaducadas();

printf("\n  %d vencida(s), con aviso al beneficiario.\n", $n);

/* Y las que están a punto: avisar ANTES sirve de algo; avisar después
   solo informa de una pérdida. */
$porAvisar = (int) BaseDatos::unValor(
    'SELECT COUNT(*) FROM remesas
      WHERE estado = "disponible" AND expira_en IS NOT NULL
        AND expira_en BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 5 DAY)'
);

if ($porAvisar > 0) {
    printf("  %d remesa(s) vencen en los próximos cinco días.\n", $porAvisar);
}

echo "\n";
