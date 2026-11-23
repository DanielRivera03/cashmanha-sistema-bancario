<?php

declare(strict_types=1);

/**
 * El menú tiene que decir qué pantalla se está viendo.
 *
 * POR QUÉ ESTA PRUEBA EXISTE
 *
 * El menú se marcaba comparando la dirección letra por letra, y cada
 * opción tenía que acordarse de pedir la marca a mano. Con el tiempo se
 * añadieron dieciocho opciones que nunca la pidieron —«Abrir cuenta»,
 * «Roles y permisos», «Redactar», los tres informes, «Accesos al
 * sistema»…— y ninguna se encendía jamás. Nadie lo notó porque no
 * rompe nada: solo deja al usuario sin saber dónde está.
 *
 * Lo que se comprueba:
 *
 *   · que toda dirección que el menú ofrece está en `$rutasMenu`, de
 *     modo que añadir una opción y olvidar la marca rompe la suite;
 *   · que se elige UNA sola opción, la más específica;
 *   · que una pantalla de detalle enciende la opción de su listado;
 *   · que un enlace con filtro gana al mismo enlace sin él;
 *   · que un camino parecido no enciende la opción equivocada;
 *   · que el grupo que contiene la opción encendida se abre.
 */

Pruebas::grupo('Menú · dónde estoy');

$fuente = file_get_contents(CM_VISTAS . '/layout/chrev.php');

if ($fuente === false) {
    Pruebas::cierto(false, 'se puede leer el layout del menú');
    return;
}

// ---------------------------------------------------------------------
// 1. La lista de rutas está completa
// ---------------------------------------------------------------------

/* El bloque del menú, que es donde manda esto. La cabecera y el
   desplegable del perfil tienen sus propios enlaces y no se marcan. */
$ini = strpos($fuente, '<ul class="metismenu" id="menu">');
$fin = strpos($fuente, '</div>', (int) strpos($fuente, '</ul>', (int) $ini));

$menu = substr($fuente, (int) $ini, (int) $fin - (int) $ini);

/* La lista declarada arriba del archivo. */
preg_match('/\$rutasMenu = \[(.*?)\n\];/s', $fuente, $m);
preg_match_all("/'([^']+)'/", $m[1] ?? '', $mm);
$declaradas = $mm[1] ?? [];

Pruebas::cierto(
    count($declaradas) > 50,
    'la lista de rutas del menú está declarada y no está vacía'
);

/* Las que el menú enseña de verdad. */
preg_match_all("/url\('([^']+)'\)(?:\s*\.\s*'(\?[^']*)')?/", $menu, $u);

$ofrecidas = [];

foreach ($u[1] as $i => $ruta) {
    /* `salir` nunca es una pantalla; la tienda abre en otra pestaña. */
    if ($ruta === 'salir' || $ruta === 'tienda') {
        continue;
    }

    $ofrecidas[] = $ruta . ($u[2][$i] ?? '');
}

$ofrecidas = array_values(array_unique($ofrecidas));
$faltan    = array_values(array_diff($ofrecidas, $declaradas));

Pruebas::cierto(
    $faltan === [],
    'toda opción del menú está en $rutasMenu'
    . ($faltan === [] ? '' : ' — faltan: ' . implode(', ', $faltan))
);

$sobran = array_values(array_diff($declaradas, $ofrecidas));

Pruebas::cierto(
    $sobran === [],
    'y no sobra ninguna que el menú ya no ofrezca'
    . ($sobran === [] ? '' : ' — sobran: ' . implode(', ', $sobran))
);

// ---------------------------------------------------------------------
// 2. El motor de elección
// ---------------------------------------------------------------------

/**
 * Lo mismo que hace el layout, para poder probarlo sin pintar nada.
 *
 * @param array<int,string> $rutasMenu
 */
$elegir = static function (string $uri, array $rutasMenu): string {
    $base   = '/cashmanha-v2';
    $actual = rtrim((string) parse_url($uri, PHP_URL_PATH), '/');

    $consulta = [];
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $consulta);

    $mejor = '';
    $peso  = -1;

    foreach ($rutasMenu as $r) {
        [$ruta, $filtro] = array_pad(explode('?', $r, 2), 2, '');
        $url = rtrim($base . '/' . ltrim($ruta, '/'), '/');

        if ($actual !== $url && !str_starts_with($actual, $url . '/')) {
            continue;
        }

        $p = strlen($url) * 2;

        if ($filtro !== '') {
            $exige = [];
            parse_str($filtro, $exige);

            foreach ($exige as $k => $v) {
                if (!isset($consulta[$k]) || (string) $consulta[$k] !== (string) $v) {
                    continue 2;
                }
            }

            $p++;
        }

        if ($p > $peso) {
            $peso  = $p;
            $mejor = $r;
        }
    }

    return $mejor;
};

$casos = [
    // la que falló en pantalla
    ['/cashmanha-v2/programadas',            'programadas',
     'el listado de pagos programados enciende su propia opción'],

    // lo que antes no encendía nada
    ['/cashmanha-v2/creditos/4821',          'creditos',
     'la ficha de un crédito enciende «Mis créditos», no deja el menú a oscuras'],

    ['/cashmanha-v2/cuentas/abrir',          'cuentas/abrir',
     '«Abrir cuenta» se enciende, que nunca lo había hecho'],

    ['/cashmanha-v2/roles',                  'roles',
     'y «Roles y permisos» también'],

    // la más específica gana
    ['/cashmanha-v2/otros-bancos/agenda',    'otros-bancos/agenda',
     'en «Mis destinos» no se enciende además «A otros bancos»'],

    ['/cashmanha-v2/otros-bancos',           'otros-bancos',
     'y en el envío se enciende el envío'],

    // los filtros
    ['/cashmanha-v2/tarjetas?todas=1',       'tarjetas?todas=1',
     '«Todas las tarjetas» gana a «Mis tarjetas» cuando el filtro está puesto'],

    ['/cashmanha-v2/tarjetas',               'tarjetas',
     'y lo pierde cuando no'],

    ['/cashmanha-v2/cobranza?etapa=judicial', 'cobranza?etapa=judicial',
     'la vía judicial se distingue del expediente general'],

    // lo que NO debe encenderse
    ['/cashmanha-v2/no-existe-esto',         '',
     'una dirección que el menú no ofrece no enciende nada'],
];

foreach ($casos as [$uri, $esperado, $dice]) {
    $sale = $elegir($uri, $declaradas);

    Pruebas::cierto(
        $sale === $esperado,
        $dice . ($sale === $esperado ? '' : " — salió «{$sale}»")
    );
}

/* El prefijo tiene que terminar en barra: `/caja` no describe
   `/cajamarca`. Se prueba con rutas inventadas para no depender de que
   el sistema tenga hoy una pantalla así. */
Pruebas::cierto(
    $elegir('/cashmanha-v2/cajamarca', ['caja', 'cajamarca']) === 'cajamarca',
    'un camino que solo se parece no enciende la opción del otro'
);

// ---------------------------------------------------------------------
// 3. Los grupos se abren
// ---------------------------------------------------------------------

/** @param array<int,string> $ramas */
$abre = static function (string $mejor, array $ramas): bool {
    $m = explode('?', $mejor, 2)[0];

    foreach ($ramas as $r) {
        if ($m !== '' && str_starts_with($m . '/', explode('?', $r, 2)[0] . '/')) {
            return true;
        }
    }

    return false;
};

/* Cada grupo del layout, con sus ramas tal como están escritas. */
preg_match_all('/\$abierto\(\[([^\]]+)\]\)/', $menu, $g);

$grupos = [];

foreach ($g[1] as $lista) {
    preg_match_all("/'([^']+)'/", $lista, $r);
    $grupos[] = $r[1];
}

Pruebas::cierto(
    count($grupos) >= 10,
    'el layout declara los grupos del menú'
);

/* La comprobación que de verdad importa: toda opción que se puede
   encender tiene que caer dentro de algún grupo o ser de primer nivel.
   Si no, el usuario ve la opción marcada… dentro de un grupo cerrado. */
$sueltas = [];

/* Las de primer nivel llevan la marca en su propio <li>. */
preg_match_all('/<li class="<\?= \$act\(\'([^\']+)\'\)/', $menu, $pn);
$primerNivel = $pn[1] ?? [];

foreach ($declaradas as $r) {
    if (in_array($r, $primerNivel, true)) {
        continue;
    }

    $dentro = false;

    foreach ($grupos as $ramas) {
        if ($abre($r, $ramas)) {
            $dentro = true;
            break;
        }
    }

    if (!$dentro) {
        $sueltas[] = $r;
    }
}

Pruebas::cierto(
    $sueltas === [],
    'ninguna opción queda marcada dentro de un grupo que no se abre'
    . ($sueltas === [] ? '' : ' — sueltas: ' . implode(', ', $sueltas))
);
