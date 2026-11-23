<?php

declare(strict_types=1);

/**
 * El descuento de categoría, aplicado a los créditos ya vivos.
 *
 * El beneficio alcanzaba solo a lo que el cliente contratara DESPUÉS de
 * ascender: sus créditos vigentes seguían con la tasa con la que
 * nacieron, mientras su pantalla le prometía el descuento.
 *
 * Esto le toca la cuota a gente real, así que **simula por omisión**.
 * Sin bandera no cambia nada: enseña qué créditos están por encima de
 * la tasa que les toca, cuánto bajaría cada cuota y cuánto deja de
 * pagar el cliente. Para aplicarlo de verdad hay que pedirlo.
 *
 *   php bin/aplicar-privilegios-credito.php            simula
 *   php bin/aplicar-privilegios-credito.php --aplicar  lo aplica
 *
 * Es idempotente: correrlo dos veces no baja la tasa dos veces, porque
 * un crédito que ya está en la suya no entra en la lista.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioAjustePrivilegio;
use CashMan\Dominio\Comun\Monto;

$aplicar = in_array('--aplicar', $argv, true);

$svc = new ServicioAjustePrivilegio();

printf("  Descuento de categoría sobre créditos vigentes · %s\n", date('d/m/Y H:i'));
echo $aplicar
    ? "  MODO APLICAR: se cambian tasas y cuotas de verdad.\n\n"
    : "  Simulación. No se toca nada. (para aplicarlo: --aplicar)\n\n";

$pendientes = $svc->pendientes();

if ($pendientes === []) {
    exit("  Ningún crédito vigente está por encima de la tasa que le toca.\n");
}

printf("  %d crédito(s) por ajustar:\n\n", count($pendientes));

$ahorroTotal = 0;

foreach ($pendientes as $p) {
    printf("    %-20s %-20s %s (%s)\n",
        $p['folio'], $p['producto'], $p['usuario'], $p['segmento']);

    printf("        tasa  %6.2f%% -> %6.2f%%   (descuento de %.2f pts%s)\n",
        $p['tasa_anterior_bp'] / 100,
        $p['tasa_nueva_bp'] / 100,
        $p['descuento_bp'] / 100,
        $p['topado'] ? ', topado por el mínimo del producto' : '');

    printf("        cuota %10s -> %-10s   %d cuota(s) por pagar\n",
        Monto::deCentavos($p['cuota_anterior_cent'])->formateado(),
        Monto::deCentavos($p['cuota_nueva_cent'])->formateado(),
        $p['por_pagar']);

    printf("        deja de pagar %s en lo que le resta\n",
        Monto::deCentavos($p['ahorro_cent'])->formateado());

    $ahorroTotal += (int) $p['ahorro_cent'];

    if ($aplicar) {
        $r = $svc->aplicar((int) $p['credito_id'], null);

        printf("        -> %s\n",
            $r === null
                ? 'no procedía al momento de aplicarlo'
                : 'aplicado, ' . $r['cuotas_rehechas'] . ' cuota(s) recalculadas');
    }

    echo "\n";
}

printf("  Ahorro total para los clientes: %s\n",
    Monto::deCentavos($ahorroTotal)->formateado());

if (!$aplicar) {
    echo "\n  No se cambió nada. Para aplicarlo: --aplicar\n";
    exit(0);
}

/* Después de tocar cuotas, que el libro siga cuadrado.
 *
 * La tabla de amortización es un plan y no un asiento —el interés se
 * reconoce al pagar la cuota—, así que esto NO debería mover nada. Se
 * comprueba igual: la vez que no se compruebe será la que falle. */
$descuadres = (int) \CashMan\Infraestructura\Persistencia\BaseDatos::unValor(
    'SELECT COUNT(*) FROM v_asientos_descuadrados'
);

printf("\n  Asientos descuadrados tras el ajuste: %d%s\n",
    $descuadres,
    $descuadres === 0 ? ' (correcto: esto no toca el libro mayor)' : '  *** REVISAR ***');

exit($descuadres === 0 ? 0 : 1);
