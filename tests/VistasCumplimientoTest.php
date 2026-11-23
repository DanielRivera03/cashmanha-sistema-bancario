<?php

declare(strict_types=1);

/**
 * Las pantallas de cumplimiento, renderizadas.
 *
 * Importan más que la mayoría: es donde una persona decide si una
 * operación se reporta a la Fiscalía. Una pantalla que no se pinta ahí
 * no es una molestia, es una obligación legal que nadie puede atender.
 */

use CashMan\Dominio\Cumplimiento\UmbralesUIF;
use CashMan\Http\Enrutador;
use CashMan\Http\Plantilla;

Pruebas::grupo('Cumplimiento · las pantallas se pintan');

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

$umbrales = [
    'efectivo'     => UmbralesUIF::EFECTIVO_CENT,
    'otros_medios' => UmbralesUIF::OTROS_MEDIOS_CENT,
    'analisis'     => UmbralesUIF::DIAS_HABILES_ANALISIS,
    'reporte'      => UmbralesUIF::DIAS_HABILES_REPORTE,
];

$panorama = [
    'abiertas' => 4, 'altas' => 2, 'vencidas' => 1, 'descartadas' => 10,
    'escaladas' => 3, 'borradores' => 2, 'borradores_vencidos' => 1,
    'enviados' => 5, 'sin_perfil' => 39, 'diligencia_vencida' => 2,
];

$alerta = static fn(array $c): array => $c + [
    'id' => 1, 'folio' => 'ALC-261113-AAAAA', 'usuario_id' => 2,
    'tipo' => 'umbral_efectivo_diario', 'severidad' => 'alta',
    'periodo_desde' => '2026-11-12', 'periodo_hasta' => '2026-11-12',
    'monto_cent' => 1150000, 'operaciones' => 2,
    'detalle' => 'Movió $11,500.00 en efectivo en 2 operaciones durante el día.',
    'datos_json' => '[]', 'estado' => 'nueva', 'analisis_vence' => '2026-12-04',
    'prorrogada' => 0, 'analista_id' => null, 'resolucion' => null,
    'resuelta_en' => null, 'creada_en' => '2026-11-13 05:30:00',
    'cliente' => 'Ana Lucía Mejía', 'documento_identidad' => '01234567-8',
    'nivel_riesgo' => 'bajo', 'pep' => 0, 'dias_restantes' => 15,
];

// =====================================================================
// El panel
// =====================================================================
$r = $pintar('cumplimiento/panel', [
    'panorama' => $panorama,
    'alertas'  => [
        $alerta([]),
        /* Una con el plazo vencido: es el caso que el panel tiene que
           gritar, no susurrar. */
        $alerta(['folio' => 'ALC-261101-BBBBB', 'dias_restantes' => -3,
                 'tipo' => 'fraccionamiento', 'severidad' => 'alta']),
        /* Una de una persona expuesta políticamente. */
        $alerta(['folio' => 'ALC-261113-CCCCC', 'tipo' => 'pep_movimiento_alto',
                 'severidad' => 'media', 'pep' => 1]),
        /* Y una ya prorrogada, que no debe ofrecer prorrogar otra vez. */
        $alerta(['folio' => 'ALC-261113-DDDDD', 'tipo' => 'lista_restrictiva',
                 'prorrogada' => 1, 'monto_cent' => 0, 'operaciones' => 0]),
    ],
    'umbrales'      => $umbrales,
    'puedeAnalizar' => true,
    'puedeReportar' => true,
    'csrf'          => 'token-de-prueba',
]);

Pruebas::cierto($r['ok'], 'el panel se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));

if ($r['ok']) {
    Pruebas::cierto(
        str_contains($r['html'], 'no es un reporte'),
        'y deja claro que una alerta no es un reporte'
    );

    Pruebas::cierto(
        str_contains($r['html'], 'confidencial por ley'),
        'y que nada de eso se le comunica al cliente'
    );

    /* La frase va partida en dos líneas en el marcado, así que se
       busca una que sí quepa entera: la que explica POR QUÉ importa,
       que es la que de verdad tiene que estar. */
    Pruebas::cierto(
        str_contains($r['html'], 'Sin perfil no se puede decir que algo es inusual'),
        'y señala lo que impide vigilar bien, en vez de callarlo'
    );

    Pruebas::cierto(
        str_contains($r['html'], 'debida diligencia vencida'),
        'incluyendo los expedientes que nadie ha revisado a tiempo'
    );
}

/* El panel de quien solo mira: la junta directiva. No debe ofrecerle
   botones que no puede usar. */
$r = $pintar('cumplimiento/panel', [
    'panorama' => $panorama, 'alertas' => [$alerta([])], 'umbrales' => $umbrales,
    'puedeAnalizar' => false, 'puedeReportar' => false, 'csrf' => 'x',
]);

Pruebas::cierto($r['ok'], 'el panel de solo lectura se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));

if ($r['ok']) {
    Pruebas::cierto(
        str_contains($r['html'], 'var ANALIZA = false'),
        'y no le ofrece acciones a quien solo puede mirar'
    );
}

// El panel limpio, que es como debería estar casi siempre.
$r = $pintar('cumplimiento/panel', [
    'panorama' => ['abiertas' => 0, 'altas' => 0, 'vencidas' => 0, 'descartadas' => 0,
                   'escaladas' => 0, 'borradores' => 0, 'borradores_vencidos' => 0,
                   'enviados' => 0, 'sin_perfil' => 0, 'diligencia_vencida' => 0],
    'alertas' => [], 'umbrales' => $umbrales,
    'puedeAnalizar' => true, 'puedeReportar' => true, 'csrf' => 'x',
]);

Pruebas::cierto($r['ok'], 'el panel sin alertas se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));

// =====================================================================
// Los reportes
// =====================================================================
$reporte = static fn(array $c): array => $c + [
    'id' => 1, 'folio' => 'ROS-261113-AAAAA', 'tipo' => 'ros', 'usuario_id' => 2,
    'alerta_id' => 1, 'periodo_desde' => '2026-11-12', 'periodo_hasta' => '2026-11-12',
    'monto_cent' => 1150000, 'operaciones' => 2,
    'relato' => 'El cliente depositó once mil quinientos dólares en efectivo.',
    'datos_json' => '[]', 'vence_en' => '2026-11-20', 'estado' => 'borrador',
    'enviado_en' => null, 'enviado_por' => null, 'acuse' => '', 'confidencial' => 1,
    'creado_en' => '2026-11-13 09:00:00', 'creado_por' => 1,
    'cliente' => 'Ana Lucía Mejía', 'alerta_folio' => 'ALC-261113-AAAAA',
    'dias_restantes' => 5,
];

$r = $pintar('cumplimiento/reportes', [
    'reportes' => [
        $reporte([]),
        $reporte(['folio' => 'RTE-261113-BBBBB', 'tipo' => 'rte', 'confidencial' => 0]),
        $reporte(['folio' => 'ROS-261101-CCCCC', 'dias_restantes' => -2]),
        $reporte(['folio' => 'ROS-261001-DDDDD', 'estado' => 'enviado',
                  'acuse' => 'UIF-2026-00123', 'enviado_en' => '2026-10-05 11:00:00',
                  'dias_restantes' => null]),
    ],
    'puedeReportar' => true,
    'csrf'          => 'token-de-prueba',
]);

Pruebas::cierto($r['ok'], 'la pantalla de reportes se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));

if ($r['ok']) {
    Pruebas::cierto(
        str_contains($r['html'], 'no envía nada a la UIF'),
        'y dice con todas las letras que el sistema no envía nada'
    );

    Pruebas::cierto(
        substr_count($r['html'], 'badge-dark') === 3,
        'marca como confidenciales los tres ROS, y no el RTE'
    );

    Pruebas::cierto(
        str_contains($r['html'], 'vencido hace 2 d'),
        'y canta el plazo vencido'
    );

    Pruebas::cierto(
        str_contains($r['html'], 'UIF-2026-00123'),
        'con el acuse del que ya se remitió'
    );
}

$r = $pintar('cumplimiento/reportes', [
    'reportes' => [], 'puedeReportar' => false, 'csrf' => 'x',
]);

Pruebas::cierto($r['ok'], 'la pantalla de reportes vacía se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));
