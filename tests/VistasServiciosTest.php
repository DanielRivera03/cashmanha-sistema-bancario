<?php

declare(strict_types=1);

/**
 * Las pantallas de pago de servicios, renderizadas.
 */

use CashMan\Dominio\Colecturia\Colector;
use CashMan\Http\Enrutador;
use CashMan\Http\Plantilla;

Pruebas::grupo('Servicios · las pantallas se pintan');

$vista = (new Plantilla())->compartir([
    'rutas'    => new Enrutador('/cashmanha-v2'),
    'usuario'  => ['nombres' => 'Prueba', 'apellidos' => 'De Vista', 'usuario' => 'prueba'],
    'avisoUrl' => '',
]);

$pintar = static function (string $nombre, array $datos) use ($vista): array {
    try {
        return ['ok' => true, 'html' => $vista->render($nombre, $datos)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'html' => $e->getMessage() . ' @ '
                 . basename($e->getFile()) . ':' . $e->getLine()];
    }
};

$porCategoria = [];

foreach (Colector::catalogo() as $c) {
    $porCategoria[(string) $c['categoria']][] = $c;
}

Pruebas::cierto(
    isset($porCategoria['energia'], $porCategoria['agua'], $porCategoria['gobierno']),
    'el catálogo cubre energía, agua y gobierno'
);

$cuentas = [[
    'codigo' => 'ABC123', 'numero_cuenta' => '1234567890',
    'producto' => 'Cuenta de Ahorro Personal',
    'saldo_cent' => 150000, 'saldo_retenido_cent' => 0,
    'saldo_minimo_cent' => 2500, 'disponible_cent' => 147500,
]];

$guardado = [[
    'uuid' => 'uuid-1', 'alias' => 'Casa', 'convenio' => 'CAESS',
    'convenio_id' => 1, 'categoria' => 'energia',
    'etiqueta_referencia' => 'NIS', 'permite_parcial' => 1,
    'referencia' => '987654321', 'titular' => 'José Hernández Flores',
    'ultimo_pago_en' => '2026-10-05 10:00:00', 'veces_pagado' => 7,
    'creado_en' => '2026-01-01 10:00:00', 'usuario_id' => 2, 'id' => 1,
]];

$limite = [
    'canal' => 'servicios', 'descripcion' => 'Pago de servicios',
    'max_operacion_cent' => 500000, 'max_diario_cent' => 1500000,
    'max_ops_diarias' => 30, 'propio' => false,
    'techo_operacion_cent' => 500000, 'techo_diario_cent' => 1500000,
    'techo_ops_diarias' => 30, 'usado_hoy_cent' => 4500,
    'ops_hoy' => 1, 'restante_cent' => 1495500,
];

// =====================================================================
$r = $pintar('servicios/pagar', [
    'cuentas'    => $cuentas,
    'categorias' => $porCategoria,
    'guardados'  => $guardado,
    'limite'     => $limite,
    'csrf'       => 'token-de-prueba',
]);

Pruebas::cierto($r['ok'], 'la pantalla de pagar se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));

if ($r['ok']) {
    Pruebas::cierto(
        str_contains($r['html'], 'optgroup label="Energía eléctrica"'),
        'y agrupa los convenios por categoría, no en una lista suelta de veinte'
    );

    Pruebas::cierto(
        str_contains($r['html'], 'readonly'),
        'el monto nace bloqueado: lo pone el colector, no el cliente'
    );

    Pruebas::cierto(
        str_contains($r['html'], 'tokenVigente'),
        'y lee el token vigente, no una copia que se gasta en la primera acción'
    );
}

/* Sin cuentas no se puede pagar nada: hay que decirlo, no mostrar un
   formulario que no lleva a ninguna parte. */
$r = $pintar('servicios/pagar', [
    'cuentas' => [], 'categorias' => $porCategoria, 'guardados' => [],
    'limite' => $limite, 'csrf' => 'x',
]);

Pruebas::cierto($r['ok'], 'se pinta sin cuentas: ' . ($r['ok'] ? 'ok' : $r['html']));

if ($r['ok']) {
    Pruebas::cierto(
        str_contains($r['html'], 'No tiene cuentas activas'),
        'y lo dice en vez de mostrar un formulario inútil'
    );
}

$r = $pintar('servicios/pagar', [
    'cuentas' => $cuentas, 'categorias' => $porCategoria, 'guardados' => [],
    'limite' => [], 'csrf' => 'x',
]);

Pruebas::cierto($r['ok'], 'se pinta sin límite configurado: ' . ($r['ok'] ? 'ok' : $r['html']));

// =====================================================================
$pago = static fn(array $c): array => $c + [
    'id' => 1, 'uuid' => 'u1', 'folio' => 'PSV-261113-AAAAA',
    'usuario_id' => 2, 'cuenta_id' => 1, 'convenio_id' => 1, 'guardado_id' => 1,
    'referencia' => '987654321', 'titular' => 'José Hernández Flores',
    'monto_cent' => 4500, 'deuda_cent' => 4500, 'comision_cent' => 0,
    'periodo' => '10/2026', 'vence_el' => '2026-11-20',
    'estado' => 'liquidado', 'motivo' => '', 'autorizacion' => 'CAE1234567890',
    'asiento_id' => 1, 'asiento_reverso_id' => null,
    'creado_en' => '2026-11-13 10:00:00', 'liquidado_en' => '2026-11-14 07:00:00',
    'convenio' => 'CAESS', 'categoria' => 'energia', 'etiqueta_referencia' => 'NIS',
];

$r = $pintar('servicios/historial', [
    'pagos' => [
        $pago([]),
        /* Un abono parcial: el monto pagado es menor que la deuda. */
        $pago(['folio' => 'PSV-261113-BBBBB', 'monto_cent' => 2000, 'deuda_cent' => 4500]),
        /* Uno con comisión. */
        $pago(['folio' => 'PSV-261113-CCCCC', 'comision_cent' => 50]),
        /* Y uno que la empresa devolvió. */
        $pago(['folio' => 'PSV-261113-DDDDD', 'estado' => 'reversado',
               'motivo' => 'La empresa no reconoce esa referencia.']),
    ],
    'csrf' => 'x',
]);

Pruebas::cierto($r['ok'], 'el historial se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));

if ($r['ok']) {
    Pruebas::cierto(
        str_contains($r['html'], 'abono de $45.00'),
        'y distingue un abono parcial de un pago total'
    );

    Pruebas::cierto(
        str_contains($r['html'], 'comisión'),
        'muestra la comisión cuando la hay'
    );

    Pruebas::cierto(
        str_contains($r['html'], 'CAE1234567890'),
        'y la autorización, que es el comprobante ante la empresa'
    );
}

$r = $pintar('servicios/historial', ['pagos' => [], 'csrf' => 'x']);

Pruebas::cierto($r['ok'], 'el historial vacío se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));
