<?php

declare(strict_types=1);

/**
 * Todas las vistas compilan, y sus `if` están cerrados.
 *
 * POR QUÉ EXISTE ESTA PRUEBA
 *
 * Porque rompí el layout del panel con un reemplazo automático: un
 * patrón se comió un `<?php endif; ?>` y dejó un `if` abierto. El
 * archivo seguía pareciendo correcto al leerlo —el bloque que había
 * tocado estaba bien— y el fallo estaba cien líneas más arriba.
 *
 * Una vista que no compila NO es un detalle estético: es una pantalla
 * en blanco. Y si la que no compila es el layout, es el sistema
 * entero en blanco.
 *
 * `php -l` lo detecta, pero solo si alguien se acuerda de pasarlo por
 * el archivo correcto. Esto lo pasa por todos, siempre.
 *
 * LO SEGUNDO QUE COMPRUEBA, Y POR QUÉ
 *
 * El balance de `if`/`endif` por separado. PHP señala el error al
 * FINAL del archivo —«unexpected end of file»— que es justo donde no
 * está el problema. Contarlos aparte dice en qué línea se abrió el que
 * quedó sin cerrar, que es lo que hace falta para arreglarlo.
 */

Pruebas::grupo('Vistas · todas compilan');

$raiz = dirname(__DIR__);

$vistas = [];

$iterador = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $raiz . '/src/Vistas',
        FilesystemIterator::SKIP_DOTS
    )
);

foreach ($iterador as $archivo) {
    if ($archivo->isFile() && $archivo->getExtension() === 'php') {
        $vistas[] = $archivo->getPathname();
    }
}

sort($vistas);

Pruebas::cierto($vistas !== [], 'se encontraron vistas que revisar');

$php = PHP_BINARY;

$rotas       = [];
$desbalance  = [];

foreach ($vistas as $ruta) {
    $corto = str_replace('\\', '/', str_replace($raiz . DIRECTORY_SEPARATOR, '', $ruta));

    // --- 1. ¿Compila? ------------------------------------------------
    $salida = [];
    $codigo = 0;

    exec(
        escapeshellarg($php) . ' -l ' . escapeshellarg($ruta) . ' 2>&1',
        $salida,
        $codigo
    );

    if ($codigo !== 0) {
        $rotas[] = $corto . ': ' . trim(implode(' ', $salida));
        continue;
    }

    // --- 2. ¿Están cerrados todos los `if`? --------------------------
    //
    // Se lleva una pila con la línea donde se abrió cada uno. Al
    // terminar, lo que quede en la pila es lo que nadie cerró, y la
    // línea es la que de verdad hace falta para arreglarlo.
    $pila = [];

    foreach (file($ruta) ?: [] as $i => $linea) {
        if (preg_match('/<\?php\s+if\s*\(/', $linea) === 1) {
            $pila[] = $i + 1;
        }

        if (preg_match('/<\?php\s+endif;/', $linea) === 1) {
            array_pop($pila);
        }
    }

    if ($pila !== []) {
        $desbalance[] = $corto . ': if sin cerrar en la línea ' . implode(', ', $pila);
    }
}

Pruebas::cierto(
    $rotas === [],
    count($vistas) . ' vista(s) compilan'
    . ($rotas === [] ? '' : ' — ROTAS: ' . implode(' | ', $rotas))
);

Pruebas::cierto(
    $desbalance === [],
    'y todos sus «if» están cerrados'
    . ($desbalance === [] ? '' : ' — ' . implode(' | ', $desbalance))
);

/* El layout del panel aparte, y dicho con su nombre: si ése no
   compila, no es una pantalla en blanco, son todas. */
$layout = $raiz . '/src/Vistas/layout/chrev.php';

$salida = [];
$codigo = 0;

exec(escapeshellarg($php) . ' -l ' . escapeshellarg($layout) . ' 2>&1', $salida, $codigo);

Pruebas::cierto(
    $codigo === 0,
    'el layout del panel compila: si no, el sistema entero queda en blanco'
);
