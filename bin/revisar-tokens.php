#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Revisa el token de seguridad en TODAS las acciones del sistema.
 *
 * QUÉ BUSCA, Y POR QUÉ CADA COSA
 *
 *  1. Rutas que escriben SIN la barrera CSRF.
 *     Una ruta POST sin `csrf` acepta peticiones de cualquier sitio
 *     web que el cliente visite mientras tiene la sesión abierta. Es
 *     el agujero que la barrera existe para tapar.
 *
 *  2. Peticiones del navegador que escriben y NO mandan token.
 *     Fallarán siempre, en cuanto su ruta tenga la barrera puesta.
 *
 *  3. Vistas que guardan una COPIA del token en una variable.
 *     Los tokens son de un solo uso: la copia se gasta en la primera
 *     acción y de la segunda en adelante todo falla. Es exactamente
 *     el fallo que se veía por todo el sistema.
 *
 *  4. Guiones que envuelven `fetch` por su cuenta.
 *     Dos envoltorios encadenados leen el cuerpo dos veces y se pisan.
 *     Solo puede haber uno, y es `token.js`.
 *
 * Uso:
 *     php bin/revisar-tokens.php
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

$raiz = dirname(__DIR__);

echo "\n  EL TOKEN DE SEGURIDAD, EN TODO EL SISTEMA\n";
echo '  ' . str_repeat('=', 68) . "\n\n";

$problemas = [];

/** Acumula un hallazgo. */
$anotar = static function (string $grupo, string $detalle) use (&$problemas): void {
    $problemas[$grupo][] = $detalle;
};

// =====================================================================
// 1. Las rutas que escriben
// =====================================================================
$indice = (string) file_get_contents($raiz . '/public/index.php');

preg_match_all(
    '/\$r->(post|put|delete)\(\s*\'([^\']+)\'\s*,\s*\[([^\]]+)\]\s*(?:,\s*\[([^\]]*)\])?/',
    $indice,
    $rutas,
    PREG_SET_ORDER
);

$conCsrf = 0;
$sinCsrf = 0;

/* Rutas que legítimamente no llevan la barrera. Cada excepción se
   justifica aquí, en una línea, o no es una excepción: es un olvido. */
$exentas = [
    '/entrar'       => 'valida su propio token de propósito "login"',
    '/recuperar'    => 'valida su propio token de propósito "recuperar"',
    '/clave'        => 'valida su propio token de propósito "clave"',
    '/restablecer/' => 'valida su propio token de propósito "restablecer"',
    '/api/stripe'   => 'lo llama la pasarela, no el navegador; se verifica por firma',

    /* Las que usan POST pero NO escriben: llevan parámetros, y por eso
       no caben en un GET, pero solo consultan.
       Un atacante puede provocarlas desde otro sitio y no gana nada:
       la política de origen cruzado le impide LEER la respuesta, que
       es lo único que devuelven. */
    '/transferir/validar-cuenta' => 'solo consulta: no escribe nada',
    '/caja/buscar-cuenta'        => 'solo consulta: no escribe nada',
    '/creditos/simular'          => 'solo calcula: no escribe nada',
    '/tarjetas/{codigo}/simular' => 'solo calcula: no escribe nada',

    /* Cerrar sesión. Se deja sin barrera a conciencia: existe también
       como GET —el enlace del menú— así que ponérsela solo al POST no
       protegería de nada, y convertir el enlace en formulario rompería
       la salida en todas las pantallas.
       Lo peor que logra un atacante es cerrarle la sesión a alguien:
       molesta, no revela nada y no destruye nada. */
    '/salir'        => 'cerrar sesión: molestia sin daño, y existe como GET',
];

foreach ($rutas as $r) {
    $metodo = strtoupper($r[1]);
    $ruta   = $r[2];
    $mw     = $r[4] ?? '';

    if (str_contains($mw, "'csrf'")) {
        $conCsrf++;
        continue;
    }

    $sinCsrf++;

    $motivo = null;

    foreach ($exentas as $patron => $razon) {
        if (str_starts_with($ruta, $patron)) {
            $motivo = $razon;
            break;
        }
    }

    if ($motivo !== null) {
        continue;
    }

    $anotar(
        'Rutas que escriben sin la barrera CSRF',
        sprintf('%-6s %s', $metodo, $ruta)
    );
}

printf("  Rutas que escriben, con la barrera ...... %d\n", $conCsrf);
printf("  Rutas que escriben, sin ella ............ %d\n", $sinCsrf);

// =====================================================================
// 2 y 3. Las vistas
// =====================================================================
$vistas = [];

$iterador = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($raiz . '/src/Vistas', FilesystemIterator::SKIP_DOTS)
);

foreach ($iterador as $archivo) {
    if ($archivo->isFile() && $archivo->getExtension() === 'php') {
        $vistas[] = $archivo->getPathname();
    }
}

sort($vistas);

$conPeticion = 0;

foreach ($vistas as $ruta) {
    $s = (string) file_get_contents($ruta);

    $corto = str_replace($raiz . DIRECTORY_SEPARATOR, '', $ruta);
    $corto = str_replace('\\', '/', $corto);

    // --- Copias del token en una variable ---------------------------
    //
    // Se admite la que se llama SEMILLA a propósito: esa está ahí
    // justamente para NO usarse directamente.
    if (preg_match('/var\s+(CSRF|TOKEN|csrf)\s*=\s*<\?=\s*P::js\(\$csrf\)/', $s) === 1
        && !str_contains($s, 'CSRF_SEMILLA')
        && !str_contains($s, 'tokenVigente')
        && !str_contains($s, 'j.csrf')) {
        $anotar(
            'Vistas que guardan una copia del token y no la renuevan',
            $corto
        );
    }

    // --- Peticiones que escriben sin token ---------------------------
    if (preg_match_all('/fetch\s*\(/', $s) === 0) {
        continue;
    }

    $conPeticion++;

    /* Se mira si la vista escribe y si manda token por alguna vía:
       campo `_csrf`, cabecera, o un FormData construido del propio
       formulario (que ya lo lleva dentro). */
    $escribe = str_contains($s, "method: 'POST'")
            || str_contains($s, 'method:"POST"')
            || str_contains($s, "method: 'post'");

    if (!$escribe) {
        continue;
    }

    $mandaToken = str_contains($s, '_csrf')
               || str_contains($s, 'X-CSRF-Token')
               || str_contains($s, 'new FormData(form')
               || str_contains($s, 'CM.csrf');

    if (!$mandaToken) {
        $anotar(
            'Vistas que escriben sin mandar token',
            $corto
        );
    }
}

printf("  Vistas que hacen peticiones ............. %d\n", $conPeticion);

// =====================================================================
// 4. Envoltorios de fetch
// =====================================================================
$js = glob($raiz . '/public/assets/js/*.js') ?: [];

$envoltorios = [];

$conToken = [];

foreach ($js as $ruta) {
    $s = (string) file_get_contents($ruta);

    /* Un envoltorio de verdad REASIGNA fetch. Leerlo no cuenta. */
    if (preg_match('/global\.fetch\s*=\s*function|window\.fetch\s*=\s*function/', $s) !== 1) {
        continue;
    }

    $envoltorios[] = basename($ruta);

    /* Envolver `fetch` varias veces es legítimo —se encadenan, y cada
       uno se ocupa de lo suyo: uno de los avisos, otro del token—.
       Lo que NO puede haber es dos que toquen el token: se pisarían,
       y el orden en que se cargan decidiría cuál gana. */
    if (str_contains($s, 'csrf_vencido') || preg_match('/adoptar\s*\(\s*j\.csrf/', $s) === 1) {
        $conToken[] = basename($ruta);
    }
}

printf("  Guiones que envuelven fetch ............. %d\n", count($envoltorios));
printf("  De ellos, los que tocan el token ........ %d\n", count($conToken));

if (count($conToken) > 1) {
    $anotar(
        'Más de un envoltorio toca el token: se pisan entre sí',
        implode(', ', $conToken)
    );
}

if ($conToken === []) {
    $anotar(
        'Nadie recoge el token nuevo',
        'Cada pantalla fallaría a la segunda acción.'
    );
}

// =====================================================================
// 5. Que el servidor sepa recuperarse
// =====================================================================
if (!str_contains($indice, 'csrf_vencido')) {
    $anotar(
        'El servidor no devuelve un token nuevo al fallar',
        'Sin eso, la pantalla queda muerta y hay que recargarla a mano.'
    );
}

$tokenJs = $raiz . '/public/assets/js/token.js';

if (!is_file($tokenJs)) {
    $anotar('Falta token.js', 'Es la pieza que mantiene el token al día.');
} else {
    $t = (string) file_get_contents($tokenJs);

    if (!str_contains($t, 'csrf_vencido')) {
        $anotar('token.js no reintenta', 'El error llegaría al cliente igualmente.');
    }
}

/* Y que esté cargado en los tres layouts: si falta en uno, ese sitio
   entero se queda sin red. */
foreach (['chrev', 'acceso', 'publico'] as $layout) {
    $l = $raiz . '/src/Vistas/layout/' . $layout . '.php';

    if (!is_file($l)) {
        continue;
    }

    if (!str_contains((string) file_get_contents($l), 'token.js')) {
        $anotar('Layouts sin token.js', $layout . '.php');
    }
}

// =====================================================================
echo "\n";

if ($problemas === []) {
    echo "  Todo en orden: ninguna acción del sistema se queda sin token.\n\n";
    exit(0);
}

echo '  ' . str_repeat('-', 68) . "\n";
printf("  %d grupo(s) con hallazgos:\n\n", count($problemas));

foreach ($problemas as $grupo => $filas) {
    echo "  · {$grupo}\n";

    foreach ($filas as $f) {
        echo '      ' . $f . "\n";
    }

    echo "\n";
}

exit(1);
