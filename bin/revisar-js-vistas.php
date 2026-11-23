<?php
declare(strict_types=1);

/**
 * Valida el JavaScript incrustado en las vistas.
 *
 * Un error de sintaxis en un `<script>` de una vista no rompe nada que
 * se vea: la página carga, se pinta entera y parece correcta. Lo que
 * muere es el script completo, así que ningún botón de esa pantalla
 * responde y nadie sabe por qué.
 *
 * Pasó: una coma de menos en las opciones de Stripe dejó sin efecto el
 * script de tres pantallas —el pago de tarjeta, el enlace de pago y el
 * checkout de la tienda—. En el checkout el síntoma fue otro: el plazo
 * elegido no llegaba al servidor y las compras entraban al contado.
 *
 * Esto extrae cada bloque, le quita el PHP incrustado —sustituyendo
 * cada `<?= ... ?>` por un literal del mismo tipo— y lo pasa por el
 * analizador de Node. No comprueba qué hace el script; comprueba que
 * se pueda ejecutar, que es justo lo que no se veía.
 *
 *   php bin/revisar-js-vistas.php
 */

$raiz = dirname(__DIR__) . '/src/Vistas';

$node = trim((string) shell_exec('where node 2>nul'));

if ($node === '') {
    fwrite(STDERR, "  Node no esta disponible: no se puede analizar el JavaScript.\n");
    exit(2);
}

$node = strtok($node, "\r\n");

$archivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz));
$revisados = 0;
$bloques   = 0;
$malos     = [];

foreach ($archivos as $archivo) {
    if (!$archivo->isFile() || $archivo->getExtension() !== 'php') {
        continue;
    }

    $ruta = str_replace('\\', '/', $archivo->getPathname());
    $html = (string) file_get_contents($ruta);

    /* El PHP se quita ANTES de buscar los <script>.
       La etiqueta se abre asi: `<script <?= P::crudo('nonce="…"') ?>>`,
       y buscando `[^>]*>` el patron se corta en el `>` que hay DENTRO
       del PHP: lo que quedaba por analizar empezaba por `>` y los 39
       bloques daban error sin tener ninguno. */
    $plano = preg_replace('#<\?php.*?\?>#s', '', $html);
    $plano = preg_replace('#<\?=\s*P::crudo\(.*?\)\s*\?>#s', '', (string) $plano);
    $plano = preg_replace('#<\?=.*?\?>#s', '0', (string) $plano);

    if (!preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#is', (string) $plano, $m)) {
        continue;
    }

    $revisados++;

    foreach ($m[1] as $i => $js) {
        if (trim($js) === '') {
            continue;
        }

        $bloques++;

        /* El PHP incrustado se sustituye por un literal.
           `<?= P::js($x) ?>` suele ir donde cabe una cadena o un
           numero, asi que se pone `0` entre comillas solo cuando el
           hueco esta dentro de comillas: asi el analizador ve codigo
           valido y no se queja de lo que no es suyo. */
        $limpio = $js;   // el PHP ya se quito arriba

        $tmp = sys_get_temp_dir() . '/cm-js-' . bin2hex(random_bytes(5)) . '.js';
        file_put_contents($tmp, (string) $limpio);

        $salida = [];
        $codigo = 0;
        exec(escapeshellarg($node) . ' --check ' . escapeshellarg($tmp) . ' 2>&1', $salida, $codigo);

        @unlink($tmp);

        if ($codigo !== 0) {
            $malos[] = [
                'archivo' => str_replace($raiz . '/', '', $ruta),
                'bloque'  => $i + 1,
                'error'   => trim(implode("\n", array_slice($salida, 0, 4))),
            ];
        }
    }
}

printf("  %d vista(s) con script, %d bloque(s) revisado(s)\n\n", $revisados, $bloques);

if ($malos === []) {
    echo "  Todos los bloques se analizan sin error.\n\n";
    exit(0);
}

foreach ($malos as $x) {
    printf("  --- %s (bloque %d) ---\n", $x['archivo'], $x['bloque']);

    foreach (explode("\n", $x['error']) as $linea) {
        echo '      ', $linea, "\n";
    }

    echo "\n";
}

printf("  %d bloque(s) NO se pueden ejecutar.\n\n", count($malos));

exit(1);
