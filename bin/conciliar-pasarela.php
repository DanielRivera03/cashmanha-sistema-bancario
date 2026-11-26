<?php
declare(strict_types=1);

/**
 * Cierra los cobros que quedaron en el aire.
 *
 * Entre que la pasarela acepta la tarjeta y que este sistema aplica el
 * cobro hay un hueco, y ese hueco se cierra de tres maneras: el aviso
 * de Stripe, la confirmación desde el navegador y este barrido.
 *
 * Las dos primeras fallan solas. El aviso no llega a una instalación
 * sin dirección pública —en local, nunca—, y el navegador se cierra, se
 * queda sin red o el cliente cambia de pestaña a mitad. Entonces el
 * dinero salió de la tarjeta y de este lado no se movió nada.
 *
 * Este es el que no depende de nadie. Se le pregunta a la pasarela por
 * cada cobro en duda y se cierra según lo que ella conteste: aplicado
 * si dice cobrado, fallido si dice rechazado, y en paz si todavía está
 * en curso. Pensado para correr cada pocos minutos.
 *
 *   php bin/conciliar-pasarela.php [--minutos=2] [--limite=200]
 */

require __DIR__ . '/../src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioPasarela;
use CashMan\Aplicacion\Servicio\ServicioTienda;
use CashMan\Infraestructura\Persistencia\BaseDatos;

if (PHP_SAPI !== 'cli' && !defined('CM_WEBCRON')) {
    http_response_code(403);
    exit("Solo por linea de comandos.\n");
}

$opciones = getopt('', ['minutos::', 'limite::', 'pedidos::', 'ayuda']);

if (isset($opciones['ayuda'])) {
    echo "Uso: php bin/conciliar-pasarela.php [--minutos=2] [--limite=200] [--pedidos=60]\n\n"
       . "  --minutos  gracia antes de tocar un cobro sin cerrar\n"
       . "  --limite   cuantos cobros revisar como maximo\n"
       . "  --pedidos  minutos antes de liberar un pedido sin cobro confirmado\n";
    exit(0);
}

$minutos = (int) ($opciones['minutos'] ?? 2);
$limite  = (int) ($opciones['limite'] ?? 200);

$enEspera = (int) BaseDatos::unValor(
    'SELECT COUNT(*) FROM pagos_pasarela WHERE estado IN ("iniciado","procesando")'
);

printf("  Cobros sin cerrar: %d  ·  gracia de %d minuto(s)\n\n", $enEspera, $minutos);

if ($enEspera === 0) {
    echo "  Nada que conciliar.\n";
    exit(0);
}

try {
    $r = (new ServicioPasarela())->reconciliar($minutos, $limite);
} catch (\Throwable $e) {
    fwrite(STDERR, '  No se pudo conciliar: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($r['detalle'] as $d) {
    printf("  %-28s %-20s %s\n",
        $d['referencia'], $d['resultado'], mb_substr($d['nota'], 0, 96));
}

printf("\n  Revisados %d  ·  aplicados %d  ·  fallidos %d  ·  siguen en curso %d\n",
    $r['revisados'], $r['aplicados'], $r['fallidos'], $r['pendientes']);

/* Y los pedidos que se quedaron esperando ese cobro.
 *
 * Van aquí porque es el mismo problema visto desde el otro lado: un
 * cobro que no prosperó deja un pedido apartando existencias que nadie
 * suelta. Corriendo juntos, lo que se cierra arriba se libera abajo en
 * la misma pasada. */
$liberados = (new ServicioTienda())->caducarPendientes(
    (int) ($opciones['pedidos'] ?? 60)
);

if ($liberados !== []) {
    printf("\n  Pedidos liberados por cobro no confirmado: %d\n", count($liberados));

    foreach ($liberados as $folio) {
        printf("    %s\n", $folio);
    }
}

/* El cuadre, siempre. Un barrido que aplica asientos y no comprueba
   que la partida doble siga cuadrando no sirve de nada. */
$descuadre = (int) BaseDatos::unValor('SELECT COUNT(*) FROM v_asientos_descuadrados');

printf("  Asientos descuadrados: %d%s\n", $descuadre,
    $descuadre === 0 ? '' : '   <-- REVISAR');

exit($descuadre === 0 ? 0 : 1);
