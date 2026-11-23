#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mueve las transferencias interbancarias por sus estados.
 *
 * QUÉ HACE, EN ORDEN
 *
 *   1. Madura los enfriamientos de la agenda que ya vencieron, para
 *      que la pantalla diga «listo» cuando de verdad lo está.
 *
 *   2. Despacha las que quedaron esperando a que abriera la ventana
 *      del servicio. El cliente no tiene que volver a entrar: su
 *      transferencia sale sola a la hora que le dijimos.
 *
 *   3. Cierra las que ya viajaron, acusando el recibo de la red.
 *
 * SOBRE EL PASO 3, SIN ADORNOS
 *
 * En un banco conectado de verdad, ese acuse lo manda el sistema de
 * pagos del BCR y entra por un extremo propio. Aquí no hay convenio ni
 * certificados, así que lo da este guion pasado un tiempo prudencial.
 *
 * Eso es una SIMULACIÓN y conviene decirlo en voz alta: el día que
 * exista el convenio, este paso se borra y en su lugar queda la
 * llamada real. Lo que NO es simulación es todo lo demás —el asiento,
 * la cámara, el reverso, la bitácora—, que es real desde el primer día
 * porque es lo único que este banco controla.
 *
 * Dejarlas colgadas en «enviada» para siempre sería peor que simular:
 * el dinero se quedaría en la cámara, que es un pasivo del banco, y el
 * cuadre acusaría un saldo en tránsito que no corresponde a nada.
 *
 * Uso:
 *     php bin/despachar-interbancarias.php           dice qué haría
 *     php bin/despachar-interbancarias.php --aplicar lo hace
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioBeneficiarios;
use CashMan\Aplicacion\Servicio\ServicioInterbancario;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/* `getopt()` se detiene en el primer argumento suelto y deja de ver lo
   que viene detrás: una bandera ignorada en un guion que mueve dinero
   es exactamente el accidente que ya ocurrió una vez aquí. */
$aplicar = in_array('--aplicar', array_slice($argv, 1), true);

/**
 * Cuánto espera el acuse de la red.
 *
 * Transfer365 liquida en segundos. Dos minutos es tiempo de sobra para
 * que una transferencia recién creada se vea «en camino» en pantalla
 * antes de cerrarse, que es lo que el cliente espera ver.
 */
const MINUTOS_PARA_ACUSE = 2;

echo "\n  TRANSFERENCIAS INTERBANCARIAS\n";
echo '  ' . str_repeat('=', 68) . "\n\n";

if (!$aplicar) {
    echo "  ENSAYO: no se cambia nada. Repita con --aplicar.\n\n";
}

// ---------------------------------------------------------------------
// 1. Enfriamientos vencidos
// ---------------------------------------------------------------------
$porMadurar = (int) BaseDatos::unValor(
    'SELECT COUNT(*) FROM beneficiarios
      WHERE estado = "en_enfriamiento" AND enfriamiento_hasta <= NOW()'
);

printf("  Destinos que terminan su espera ......... %d\n", $porMadurar);

if ($aplicar && $porMadurar > 0) {
    (new ServicioBeneficiarios())->madurarEnfriamientos();
}

// ---------------------------------------------------------------------
// 2. Las que esperaban a que abriera el servicio
// ---------------------------------------------------------------------
$servicio = new ServicioInterbancario();

$pendientes = $servicio->pendientesDeVentana();

printf("  Transferencias esperando la apertura .... %d\n", count($pendientes));

foreach ($pendientes as $p) {
    printf(
        "      %s  %-18s %10s  %s\n",
        $p['folio'],
        $p['canal'],
        Monto::deCentavos((int) $p['monto_cent'])->formateado(),
        \CashMan\Dominio\Interbancario\RedDePagos::ventanaAbierta((string) $p['canal'])
            ? 'sale ahora'
            : 'sigue esperando'
    );
}

$salieron = $aplicar ? $servicio->despacharPendientes() : 0;

if ($aplicar) {
    printf("  Salieron ................................ %d\n", $salieron);
}

// ---------------------------------------------------------------------
// 3. El acuse de la red
// ---------------------------------------------------------------------
$porCerrar = BaseDatos::todas(
    'SELECT folio, monto_cent, canal
       FROM transferencias_interbancarias
      WHERE estado = "enviada" AND sentido = "enviada"
        AND enviada_en <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
      ORDER BY enviada_en',
    [MINUTOS_PARA_ACUSE]
);

printf("  En camino, esperando el acuse ........... %d\n", count($porCerrar));

$cerradas = 0;

if ($aplicar) {
    foreach ($porCerrar as $t) {
        try {
            $servicio->confirmar((string) $t['folio']);
            $cerradas++;
        } catch (\RuntimeException $e) {
            printf("      %s: %s\n", $t['folio'], $e->getMessage());
        }
    }

    printf("  Aplicadas ............................... %d\n", $cerradas);
}

// ---------------------------------------------------------------------
// Lo que queda en la cámara
// ---------------------------------------------------------------------
//
// El saldo de la cámara tiene que ser, exactamente, la suma de lo que
// está en viaje. Si no lo es, hay dinero perdido en el tránsito y es
// mejor enterarse aquí que en un cierre.
// ---------------------------------------------------------------------
$camara = (int) BaseDatos::unValor(
    'SELECT saldo_cent FROM cuentas WHERE numero_cuenta = "0000000006"'
);

$enViaje = (int) BaseDatos::unValor(
    'SELECT COALESCE(SUM(CASE WHEN sentido = "enviada" THEN monto_cent
                              ELSE -monto_cent END), 0)
       FROM transferencias_interbancarias
      WHERE estado IN ("enviada", "aplicada")
        AND banco_contraparte_id <> (SELECT id FROM bancos_participantes WHERE es_propio = 1)'
);

echo "\n";
printf("  Saldo de la cámara ...................... %s\n", Monto::deCentavos($camara)->formateado());
printf("  Suma de lo que viajó .................... %s\n", Monto::deCentavos($enViaje)->formateado());

if ($camara !== $enViaje) {
    printf(
        "\n  !! DESCUADRE DE %s en la cámara interbancaria.\n",
        Monto::deCentavos(abs($camara - $enViaje))->formateado()
    );
    echo "     Revise las transferencias devueltas y sus asientos de reverso.\n";
}

echo "\n";
