#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Caduca las solicitudes de constancia que nadie pagó en caja.
 *
 * No mueve dinero: una solicitud sin pagar nunca cobró nada. Lo que
 * hace es cerrarla y avisarle al cliente.
 *
 * POR QUÉ CADUCAN
 *
 * Una constancia de saldo certifica el saldo del DÍA en que se emite.
 * Si una solicitud de hace tres semanas se cobrara hoy, el documento
 * saldría con el saldo de hoy bajo un trámite de hace tres semanas, y
 * el cliente —que la pidió para enseñar lo que tenía entonces— creería
 * que se le certificó otra cosa.
 *
 * Uso:
 *     php bin/vencer-solicitudes-constancia.php            dice cuántas caducarían
 *     php bin/vencer-solicitudes-constancia.php --aplicar  las caduca y avisa
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioSolicitudConstancia;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;

$aplicar = in_array('--aplicar', array_slice($argv, 1), true);

echo "\n  SOLICITUDES DE CONSTANCIA CADUCADAS\n";
echo '  ' . str_repeat('=', 68) . "\n\n";

$porVencer = BaseDatos::todas(
    'SELECT s.folio, s.comision_cent, s.vence_en, s.tipo,
            CONCAT(u.nombres," ",u.apellidos) AS cliente
       FROM constancia_solicitudes s
       JOIN usuarios u ON u.id = s.usuario_id
      WHERE s.estado = "pendiente_pago" AND s.vence_en < NOW()
      ORDER BY s.vence_en'
);

printf("  Solicitudes con el plazo cumplido ....... %d\n\n", count($porVencer));

foreach ($porVencer as $p) {
    printf(
        "      %-22s %-22s %9s  venció %s\n",
        $p['folio'],
        mb_substr((string) $p['cliente'], 0, 22),
        Monto::deCentavos((int) $p['comision_cent'])->formateado(),
        date('d/m/Y H:i', (int) strtotime((string) $p['vence_en']))
    );
}

if ($porVencer !== []) {
    echo "\n";
}

if (!$aplicar) {
    echo "  Nada se tocó. Para caducarlas:\n";
    echo "      php bin/vencer-solicitudes-constancia.php --aplicar\n\n";
    exit(0);
}

$n = (new ServicioSolicitudConstancia())->vencer();

printf("  Caducadas: %d. Al cliente se le avisó y no se le cobró nada.\n\n", $n);
