<?php

declare(strict_types=1);

/**
 * ¿Promete cada escalón algo que su propio umbral alcanza?
 *
 * Un escalón de banca preferencial reserva líneas de tarjeta. Si la
 * línea reservada pide más ingreso del que hace falta para entrar al
 * escalón, el cliente llega, ve el anuncio «acceso a líneas de tarjeta
 * reservadas», ve la tarjeta con su botón, y al pulsarlo se entera de
 * que no le alcanza. Eso fue exactamente lo que pasó con la Infinite:
 * pedía $10,000 y a Patrimonial se entraba con $8,000.
 *
 * Esta clase de incoherencia no la detecta ninguna prueba de las
 * normales, porque cada pieza por separado es correcta: el producto
 * tiene su requisito, el escalón tiene su umbral, y los dos están bien
 * escritos. Lo que está mal es la relación entre ambos, y para verla
 * hay que mirarlos juntos.
 *
 * Se deja como comprobación para correr tras tocar umbrales o
 * requisitos:
 *
 *   php bin/revisar-coherencia-segmentos.php
 *
 * Devuelve 1 si hay algo incoherente, para que sirva en un gancho.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Dominio\Cliente\Segmento;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/** Los umbrales no son públicos; se leen por reflexión, solo aquí. */
$ref = new ReflectionClass(Segmento::class);
$reglas = $ref->getConstant('REGLAS');

$escalones = [Segmento::PREFERENTE, Segmento::PATRIMONIAL];

$problemas = [];

printf("  Coherencia de los escalones · regla %s\n", $ref->getConstant('VERSION'));
echo "  " . str_repeat('-', 78) . "\n\n";

foreach ($escalones as $seg) {
    $umbral = (int) ($reglas[$seg]['ingreso'] ?? 0);
    $lineas = (array) (Segmento::beneficios($seg)['lineas_exclusivas'] ?? []);

    printf("  %-14s entra con $%s de ingreso mensual\n",
        Segmento::etiqueta($seg), number_format($umbral / 100, 2));

    if ($lineas === []) {
        echo "                 no reserva ninguna línea\n\n";
        continue;
    }

    $marcas = implode(',', array_fill(0, count($lineas), '?'));

    $productos = BaseDatos::todas(
        'SELECT codigo, nombre, ingreso_minimo_cent, descripcion, activo
           FROM productos WHERE codigo IN (' . $marcas . ')',
        $lineas
    );

    // Una línea anunciada que no existe es peor que una inalcanzable.
    $encontrados = array_column($productos, 'codigo');

    foreach (array_diff($lineas, $encontrados) as $falta) {
        $problemas[] = sprintf('%s reserva «%s», que no existe en el catálogo.',
            Segmento::etiqueta($seg), $falta);
    }

    foreach ($productos as $p) {
        $pide = (int) $p['ingreso_minimo_cent'];

        /* Una línea «por invitación» puede pedir más: no se concede por
           cumplir un número, se concede porque la institución la
           ofrece, y su descripción se lo dice al cliente. */
        $porInvitacion = stripos((string) $p['descripcion'], 'invitaci') !== false;

        $estado = match (true) {
            (int) $p['activo'] !== 1 => 'INACTIVA en el catálogo',
            $pide <= $umbral         => 'alcanzable',
            $porInvitacion           => 'por invitación (exenta)',
            default                  => 'INALCANZABLE',
        };

        printf("                 %-9s %-20s pide $%-12s %s\n",
            $p['codigo'], $p['nombre'], number_format($pide / 100, 2), $estado);

        if ($estado === 'INALCANZABLE') {
            $problemas[] = sprintf(
                '%s reserva la %s, que pide $%s, pero al escalón se entra con $%s. '
                . 'Suba el umbral del escalón o baje el requisito de la línea.',
                Segmento::etiqueta($seg), $p['nombre'],
                number_format($pide / 100, 2), number_format($umbral / 100, 2)
            );
        }

        if ($estado === 'INACTIVA en el catálogo') {
            $problemas[] = sprintf('%s reserva la %s, que está inactiva.',
                Segmento::etiqueta($seg), $p['nombre']);
        }
    }

    echo "\n";
}

/* Y que los escalones vayan de menos a más: un umbral de Patrimonial
   por debajo del de Preferente haría que nadie llegara nunca al de
   arriba, o que llegara saltándose el de abajo. */
$umbralPref = (int) ($reglas[Segmento::PREFERENTE]['ingreso'] ?? 0);
$umbralPatr = (int) ($reglas[Segmento::PATRIMONIAL]['ingreso'] ?? 0);

if ($umbralPatr <= $umbralPref) {
    $problemas[] = sprintf(
        'El umbral de Patrimonial ($%s) no es mayor que el de Preferente ($%s).',
        number_format($umbralPatr / 100, 2), number_format($umbralPref / 100, 2)
    );
}

if ($problemas === []) {
    echo "  Todo coherente: ningún escalón anuncia una línea que no alcance.\n";
    exit(0);
}

printf("  %d incoherencia(s):\n\n", count($problemas));

foreach ($problemas as $p) {
    echo "    · " . $p . "\n";
}

exit(1);
