<?php

declare(strict_types=1);

/**
 * Las pantallas del módulo interbancario, renderizadas de verdad.
 *
 * QUÉ COMPRUEBA
 *
 * Que las cuatro vistas se pintan sin reventar, con datos reales y con
 * los casos de borde que de verdad ocurren: un cliente sin destinos,
 * uno con un destino todavía en espera, un historial con una
 * transferencia devuelta.
 *
 * POR QUÉ HACE FALTA
 *
 * Porque `php -l` dice que el archivo compila, y eso no es lo mismo
 * que decir que se pinta. Un `$l['campo']` que no existe, un
 * `number_format()` sobre null o un bucle sobre algo que no es lista
 * pasan la comprobación de sintaxis y revientan en pantalla, delante
 * del cliente, en el único momento que importa.
 *
 * Y una pantalla rota en el camino del dinero no es un detalle
 * estético: es un cliente que no puede mandar su dinero y no sabe por
 * qué.
 */

use CashMan\Aplicacion\Servicio\ServicioLimitesCliente;
use CashMan\Http\Enrutador;
use CashMan\Http\Plantilla;
use CashMan\Infraestructura\Persistencia\BaseDatos;

Pruebas::grupo('Interbancario · las pantallas se pintan');

$rutas = new Enrutador('/cashmanha-v2');

$vista = (new Plantilla())->compartir([
    'rutas'    => $rutas,
    'usuario'  => ['nombres' => 'Prueba', 'apellidos' => 'De Vista', 'usuario' => 'prueba'],
    'avisoUrl' => '',
]);

$bancos = BaseDatos::todas(
    'SELECT id, nombre, nombre_corto, tipo, transfer365, transfer365_movil, uni, es_propio
       FROM bancos_participantes WHERE activo = 1 ORDER BY es_propio DESC, nombre_corto'
);

Pruebas::cierto(
    count($bancos) >= 20,
    'el catálogo trae las instituciones del sistema financiero salvadoreño'
);

Pruebas::cierto(
    count(array_filter($bancos, static fn(array $b): bool => (int) $b['es_propio'] === 1)) === 1,
    'y exactamente una de ellas es este banco'
);

/* Los límites de un cliente cualquiera: sirven para las dos pantallas
   que los muestran. */
$usuarioId = (int) BaseDatos::unValor(
    'SELECT u.id FROM usuarios u
       JOIN cuentas c ON c.usuario_id = u.id AND c.es_tecnica = 0
      WHERE u.estado = "activo" LIMIT 1'
);

$limites = (new ServicioLimitesCliente())->panorama($usuarioId);

Pruebas::cierto(
    count($limites) === count(ServicioLimitesCliente::CANALES),
    'el panorama de límites trae un renglón por canal'
);

$cuentas = [[
    'codigo' => 'ABC123', 'numero_cuenta' => '1234567890',
    'producto' => 'Cuenta de Ahorro Personal', 'moneda' => 'USD',
    'saldo_cent' => 150000, 'saldo_retenido_cent' => 0,
    'saldo_minimo_cent' => 2500, 'disponible_cent' => 147500,
]];

$ventanas = [
    'transfer365'       => ['abierta' => true,  'abre_a' => null],
    'transfer365_movil' => ['abierta' => true,  'abre_a' => null],
    'uni'               => ['abierta' => false, 'abre_a' => date('c', strtotime('+1 day'))],
];

/** Un destino de la agenda, listo para pintar. */
$destino = static function (array $cambios = []): array {
    return $cambios + [
        'uuid'         => 'uuid-de-prueba',
        'alias'        => 'Mamá',
        'banco'        => 'BAC',
        'banco_nombre' => 'Banco de América Central, S.A.',
        'es_propio'    => false,
        'titular'      => 'Ana Lucía Mejía',
        'tipo_cuenta'  => 'ahorro',
        'destino'      => '••••3456',
        'por_celular'  => false,
        'estado'       => 'activo',
        'usable'       => true,
        'faltan_min'   => 0,
        'veces_usado'  => 3,
        'canales'      => ['transfer365', 'uni'],
    ];
};

/** Pinta una vista y devuelve el HTML, o el fallo si reventó. */
$pintar = static function (string $nombre, array $datos) use ($vista): array {
    try {
        return ['ok' => true, 'html' => $vista->render($nombre, $datos)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'html' => $e->getMessage() . ' @ '
                 . basename($e->getFile()) . ':' . $e->getLine()];
    }
};

// =====================================================================
// 1. Enviar, con destinos disponibles
// =====================================================================
$r = $pintar('interbancario/enviar', [
    'cuentas'       => $cuentas,
    'beneficiarios' => [$destino()],
    'bancos'        => $bancos,
    'limites'       => $limites,
    'ventanas'      => $ventanas,
    'csrf'          => 'token-de-prueba',
]);

Pruebas::cierto($r['ok'], 'la pantalla de enviar se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));

if ($r['ok']) {
    Pruebas::cierto(
        str_contains($r['html'], 'Ana Lucía Mejía'),
        'y muestra a quién le está mandando, con nombre y apellido'
    );

    Pruebas::cierto(
        str_contains($r['html'], 'form-inter'),
        'con el formulario de envío'
    );
}

// =====================================================================
// 2. Enviar, sin ningún destino disponible
// =====================================================================
//
// El caso del cliente nuevo. Antes de que existiera esta rama, la
// pantalla habría mostrado un formulario con un desplegable vacío:
// todo en su sitio y nada que hacer.
// =====================================================================
$r = $pintar('interbancario/enviar', [
    'cuentas'       => $cuentas,
    'beneficiarios' => [],
    'bancos'        => $bancos,
    'limites'       => $limites,
    'ventanas'      => $ventanas,
    'csrf'          => 'token-de-prueba',
]);

Pruebas::cierto($r['ok'], 'se pinta sin destinos: ' . ($r['ok'] ? 'ok' : $r['html']));

if ($r['ok']) {
    Pruebas::cierto(
        str_contains($r['html'], 'Ir a mis destinos'),
        'y en vez de un formulario vacío, manda a guardar el primero'
    );
}

// =====================================================================
// 3. Enviar, con un destino todavía en espera
// =====================================================================
$r = $pintar('interbancario/enviar', [
    'cuentas'       => $cuentas,
    'beneficiarios' => [$destino([
        'usable' => false, 'estado' => 'en_enfriamiento', 'faltan_min' => 185,
    ])],
    'bancos'        => $bancos,
    'limites'       => $limites,
    'ventanas'      => $ventanas,
    'csrf'          => 'token-de-prueba',
]);

Pruebas::cierto($r['ok'], 'se pinta con un destino en espera: ' . ($r['ok'] ? 'ok' : $r['html']));

if ($r['ok']) {
    Pruebas::cierto(
        str_contains($r['html'], '3 h 5 min'),
        'y dice cuánto falta exactamente, no un «pronto»'
    );
}

// =====================================================================
// 4. Enviar, con el servicio cerrado
// =====================================================================
$r = $pintar('interbancario/enviar', [
    'cuentas'       => $cuentas,
    'beneficiarios' => [$destino()],
    'bancos'        => $bancos,
    'limites'       => $limites,
    'ventanas'      => [
        'transfer365'       => ['abierta' => false, 'abre_a' => date('c', strtotime('+2 hours'))],
        'transfer365_movil' => ['abierta' => false, 'abre_a' => date('c', strtotime('+2 hours'))],
        'uni'               => ['abierta' => false, 'abre_a' => date('c', strtotime('+1 day'))],
    ],
    'csrf'          => 'token-de-prueba',
]);

Pruebas::cierto($r['ok'], 'se pinta con el servicio cerrado: ' . ($r['ok'] ? 'ok' : $r['html']));

if ($r['ok']) {
    Pruebas::cierto(
        str_contains($r['html'], 'Cerrado hasta las'),
        'y lo dice arriba, antes de que el cliente teclee nada'
    );
}

// =====================================================================
// 5. La agenda
// =====================================================================
$r = $pintar('interbancario/agenda', [
    'beneficiarios' => [$destino(), $destino(['alias' => 'Bloqueado', 'estado' => 'bloqueado', 'usable' => false])],
    'bancos'        => $bancos,
    'enfriamiento'  => 12,
    'csrf'          => 'token-de-prueba',
]);

Pruebas::cierto($r['ok'], 'la agenda se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));

if ($r['ok']) {
    Pruebas::cierto(
        str_contains($r['html'], 'Por qué hay una espera'),
        'y explica por qué hay un enfriamiento, en vez de solo imponerlo'
    );
}

// La agenda vacía, que es como la ve todo cliente nuevo.
$r = $pintar('interbancario/agenda', [
    'beneficiarios' => [],
    'bancos'        => $bancos,
    'enfriamiento'  => 12,
    'csrf'          => 'token-de-prueba',
]);

Pruebas::cierto($r['ok'], 'la agenda vacía se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));

// =====================================================================
// 6. Los límites
// =====================================================================
$r = $pintar('interbancario/limites', [
    'limites' => $limites,
    'csrf'    => 'token-de-prueba',
]);

Pruebas::cierto($r['ok'], 'la pantalla de límites se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));

if ($r['ok']) {
    Pruebas::cierto(
        substr_count($r['html'], 'data-canal=') === count(ServicioLimitesCliente::CANALES),
        'con un bloque por canal, ni uno de más ni de menos'
    );
}

// =====================================================================
// 7. El historial, con los cinco estados
// =====================================================================
$fila = static fn(array $c): array => $c + [
    'folio' => 'T365-261113-AAAAA', 'sentido' => 'enviada', 'canal' => 'transfer365',
    'banco' => 'BAC', 'alias' => 'Mamá', 'titular_contraparte' => 'Ana Lucía Mejía',
    'monto_cent' => 7550, 'estado' => 'aplicada', 'motivo' => '',
    'creada_en' => date('Y-m-d H:i:s'),
];

$r = $pintar('interbancario/historial', [
    'transferencias' => [
        $fila([]),
        $fila(['folio' => 'T365-261113-BBBBB', 'estado' => 'creada']),
        $fila(['folio' => 'T365-261113-CCCCC', 'estado' => 'enviada']),
        $fila(['folio' => 'T365-261113-DDDDD', 'estado' => 'rechazada',
               'motivo' => 'La cuenta destino no existe en esa institución.']),
        $fila(['folio' => 'T365-261113-EEEEE', 'estado' => 'devuelta',
               'motivo' => 'El nombre del titular no coincide.']),
        $fila(['folio' => 'T365-261113-FFFFF', 'sentido' => 'recibida', 'alias' => null]),
    ],
    'csrf' => 'token-de-prueba',
]);

Pruebas::cierto($r['ok'], 'el historial se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));

if ($r['ok']) {
    Pruebas::cierto(
        str_contains($r['html'], 'badge-danger') && str_contains($r['html'], 'badge-success'),
        'y una devuelta no se confunde con una aplicada: llevan color distinto'
    );

    Pruebas::cierto(
        str_contains($r['html'], 'La cuenta destino no existe'),
        'con el motivo del rechazo a la vista, que es lo que el cliente pregunta'
    );

    Pruebas::cierto(
        str_contains($r['html'], 'text-success'),
        'y lo recibido se distingue de lo enviado'
    );
}

$r = $pintar('interbancario/historial', ['transferencias' => [], 'csrf' => 'x']);

Pruebas::cierto($r['ok'], 'el historial vacío se pinta: ' . ($r['ok'] ? 'ok' : $r['html']));
