<?php

declare(strict_types=1);

/**
 * Las tres pantallas de constancias, renderizadas de verdad.
 *
 * Compilar no basta: una vista compila perfectamente y revienta al
 * pintarse porque le falta una variable, o enseña la opción de pagar
 * con tarjeta a quien no tiene ninguna.
 *
 * Lo que se vigila aquí:
 *
 *   · que las tres se pintan sin reventar, también en los casos vacíos;
 *   · que la regla de las tarjetas —solo CashMan H.A.— se dice en la
 *     pantalla, donde el cliente elige, y no después de un rechazo;
 *   · que al cliente sin tarjetas no se le ofrece la opción;
 *   · que el cajero ve a quién le está cobrando antes de cobrar;
 *   · que ninguna lee un token copiado al cargar la página.
 */

use CashMan\Http\Enrutador;
use CashMan\Http\Plantilla;

Pruebas::grupo('Constancias · las pantallas se pintan');

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

$tipos = [
    ['tipo' => 'saldo', 'nombre' => 'Constancia de saldo',
     'descripcion' => 'Certifica el saldo de una cuenta.',
     'comision_cent' => 500, 'dias_vigencia' => 30, 'autoservicio' => 1, 'activa' => 1],
    ['tipo' => 'no_adeudo', 'nombre' => 'Constancia de no adeudo',
     'descripcion' => 'Certifica que no debe nada.',
     'comision_cent' => 0, 'dias_vigencia' => 30, 'autoservicio' => 1, 'activa' => 1],
];

$cuentas = [[
    'codigo' => 'ABC123', 'numero_cuenta' => '1234567890',
    'producto' => 'Cuenta de Ahorro Personal',
    'saldo_cent' => 150000, 'saldo_retenido_cent' => 0,
    'saldo_minimo_cent' => 2500, 'disponible_cent' => 147500,
]];

$creditos = [[
    'codigo' => 'KRD987', 'folio' => 'CRD-2601-0001',
    'saldo_capital_cent' => 250000, 'estado' => 'vigente',
    'producto' => 'Crédito personal',
]];

$tarjetas = [[
    'codigo' => 'TJT555', 'ultimos4' => '4321', 'marca' => 'visa',
    'tipo' => 'credito', 'alias' => 'La de siempre',
    'producto' => 'Clásica CashMan',
]];

// =====================================================================
// 1. La del cliente, con tarjetas
// =====================================================================
$r = $pintar('constancias/mias', [
    'tipos'       => $tipos,
    'constancias' => [],
    'cuentas'     => $cuentas,
    'creditos'    => $creditos,
    'tarjetas'    => $tarjetas,
    'solicitudes' => [],
    'csrf'        => 'tok',
]);

Pruebas::cierto($r['ok'], 'la pantalla del cliente se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));

Pruebas::cierto(
    str_contains($r['html'], 'Con mi tarjeta'),
    'y ofrece pagar con tarjeta a quien tiene una'
);

Pruebas::cierto(
    str_contains($r['html'], 'Solo tarjetas CashMan H.A.')
    && str_contains($r['html'], 'otros bancos'),
    'la regla se dice DONDE se elige, no después de que el cobro se caiga'
);

Pruebas::cierto(
    str_contains($r['html'], 'pídala en cualquier agencia')
    || str_contains($r['html'], 'Pídala en cualquier agencia'),
    'y a quien quiera pagar en efectivo se le dice adónde ir'
);

Pruebas::cierto(
    str_contains($r['html'], 'CMToken'),
    'lee el token vigente, no una copia que se gasta en la primera acción'
);

// =====================================================================
// 2. El cliente sin tarjetas
// =====================================================================
$r = $pintar('constancias/mias', [
    'tipos'       => $tipos,
    'constancias' => [],
    'cuentas'     => $cuentas,
    'creditos'    => [],
    'tarjetas'    => [],
    'solicitudes' => [],
    'csrf'        => 'tok',
]);

Pruebas::cierto($r['ok'], 'se pinta sin tarjetas: ' . ($r['ok'] ? 'ok' : $r['html']));

Pruebas::cierto(
    str_contains($r['html'], 'No tiene ninguna tarjeta CashMan H.A. activa'),
    'y lo dice, en vez de ofrecer una opción que va a fallar'
);

Pruebas::cierto(
    str_contains($r['html'], 'disabled'),
    'con el botón de la tarjeta apagado'
);

// =====================================================================
// 3. Lo que le espera en caja
// =====================================================================
$r = $pintar('constancias/mias', [
    'tipos'       => $tipos,
    'constancias' => [],
    'cuentas'     => $cuentas,
    'creditos'    => [],
    'tarjetas'    => [],
    'solicitudes' => [[
        'folio' => 'SCT-261113-AABBCCDDEE', 'tipo' => 'saldo',
        'tipo_nombre' => 'Constancia de saldo', 'estado' => 'pendiente_pago',
        'comision_cent' => 500, 'generada_en' => '2026-11-13 09:00:00',
        'vence_en' => '2026-11-14 09:00:00', 'minutos_restantes' => 310,
    ]],
    'csrf'        => 'tok',
]);

Pruebas::cierto($r['ok'], 'se pinta con una solicitud pendiente: ' . ($r['ok'] ? 'ok' : $r['html']));

Pruebas::cierto(
    str_contains($r['html'], 'Le esperan en caja')
    && str_contains($r['html'], 'SCT-261113-AABBCCDDEE'),
    'el folio que tiene que llevar a la ventanilla se ve, y arriba'
);

Pruebas::cierto(
    str_contains($r['html'], 'quedan 5 horas'),
    'con el plazo que le queda, que es lo que decide si va hoy o mañana'
);

/* Una ya cobrada no tiene por qué seguir gritando desde arriba. */
$r = $pintar('constancias/mias', [
    'tipos'       => $tipos,
    'constancias' => [],
    'cuentas'     => $cuentas,
    'creditos'    => [],
    'tarjetas'    => [],
    'solicitudes' => [[
        'folio' => 'SCT-261113-AABBCCDDEE', 'tipo' => 'saldo',
        'tipo_nombre' => 'Constancia de saldo', 'estado' => 'emitida',
        'comision_cent' => 500, 'generada_en' => '2026-11-13 09:00:00',
        'vence_en' => '2026-11-14 09:00:00', 'minutos_restantes' => -40,
    ]],
    'csrf'        => 'tok',
]);

Pruebas::falso(
    str_contains($r['html'], 'Le esperan en caja'),
    'y la que ya se cobró deja de aparecer como pendiente'
);

// =====================================================================
// 4. Atención al cliente
// =====================================================================
$r = $pintar('constancias/agencia', [
    'tipos' => $tipos,
    'csrf'  => 'tok',
]);

Pruebas::cierto($r['ok'], 'la pantalla de agencia se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));

Pruebas::cierto(
    str_contains($r['html'], 'Documento o número de cuenta'),
    'empieza por identificar a quien está en el mostrador'
);

Pruebas::cierto(
    str_contains($r['html'], 'pasa a caja y paga'),
    'y explica el circuito: aquí se prepara, en caja se paga'
);

// =====================================================================
// 5. La caja
// =====================================================================
$r = $pintar('constancias/caja', [
    'pendientes' => [[
        'folio' => 'SCT-261113-AABBCCDDEE', 'tipo' => 'saldo',
        'tipo_nombre' => 'Constancia de saldo', 'estado' => 'pendiente_pago',
        'comision_cent' => 500, 'cliente' => 'José Hernández Flores',
        'documento_identidad' => '04212345-6',
        'generada_por_nombre' => 'Ana López',
        'generada_en' => '2026-11-13 09:00:00',
        'vence_en' => '2026-11-14 09:00:00', 'minutos_restantes' => 310,
    ]],
    'csrf' => 'tok',
]);

Pruebas::cierto($r['ok'], 'la pantalla de caja se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));

Pruebas::cierto(
    str_contains($r['html'], 'quien paga es quien pidió la constancia'),
    'y le recuerda al cajero que compruebe el documento antes de entregar saldos ajenos'
);

Pruebas::cierto(
    str_contains($r['html'], 'Efectivo') && str_contains($r['html'], 'Cheque'),
    'con los medios de pago que la ventanilla acepta'
);

$r = $pintar('constancias/caja', ['pendientes' => [], 'csrf' => 'tok']);

Pruebas::cierto($r['ok'], 'la caja vacía se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));
