<?php
declare(strict_types=1);

/**
 * Repara texto mal codificado en la base.
 *
 * Qué pasó: los volcados venían en UTF-8, pero al importarlos por la
 * consola de Windows el cliente de MySQL los interpretó como CP850 (la
 * página de códigos del símbolo del sistema) y los volvió a guardar
 * como UTF-8. Así, "Crédito" se convirtió en "Cr├®dito":
 *
 *     é  =  C3 A9   (UTF-8 original)
 *     C3 leído como CP850  ->  ├  (U+251C)  ->  E2 94 9C
 *     A9 leído como CP850  ->  ®  (U+00AE)  ->  C2 AE
 *
 * La reparación deshace exactamente ese camino: se vuelve a convertir
 * cada carácter a su byte en CP850 y la secuencia resultante se lee
 * como UTF-8.
 *
 * Solo se tocan las filas cuyo texto contiene caracteres de dibujo de
 * cajas (U+2500–U+257F). Esos nunca aparecen en datos legítimos de un
 * banco, así que sirven de marca inequívoca: sin ellos, no se toca la
 * fila. De lo contrario, una conversión a ciegas estropearía texto que
 * está bien.
 *
 * Uso:
 *   php bin/reparar-codificacion.php            → solo informa
 *   php bin/reparar-codificacion.php --aplicar  → corrige
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Persistencia\BaseDatos;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por línea de comandos.\n");
}

$opciones = getopt('', ['aplicar']);
$aplicar  = isset($opciones['aplicar']);

/** ¿Lleva la marca de la corrupción? */
function estaCorrupto(string $s): bool
{
    // Dibujo de cajas, bloques y medios bloques: el rastro que deja
    // CP850 al reinterpretar los bytes altos de UTF-8.
    return preg_match('/[\x{2500}-\x{257F}\x{2580}-\x{259F}]/u', $s) === 1;
}

/** Deshace la doble conversión. Devuelve null si no se puede. */
function reparar(string $s): ?string
{
    $bytes = @iconv('UTF-8', 'CP850', $s);

    if ($bytes === false || !mb_check_encoding($bytes, 'UTF-8')) {
        return null;
    }

    // Si tras reparar sigue habiendo marcas, la cadena pasó por el
    // proceso más de una vez: se repite hasta que quede limpia.
    if (estaCorrupto($bytes)) {
        $otra = reparar($bytes);
        return $otra ?? $bytes;
    }

    return $bytes;
}

echo PHP_EOL;
echo '  Reparación de codificación' . PHP_EOL;
echo '  ' . str_repeat('=', 68) . PHP_EOL;
echo '  Modo: ' . ($aplicar ? 'APLICAR CAMBIOS' : 'solo informar (usa --aplicar para corregir)')
   . PHP_EOL . PHP_EOL;

$baseDatos = (string) BaseDatos::unValor('SELECT DATABASE()');

// Solo tablas reales: las vistas se recalculan solas.
$tablas = BaseDatos::todas(
    "SELECT TABLE_NAME FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
      ORDER BY TABLE_NAME",
    [$baseDatos]
);

$totalFilas = 0;
$totalCeldas = 0;
$tablasTocadas = 0;

foreach ($tablas as $t) {
    $tabla = (string) $t['TABLE_NAME'];

    $columnas = BaseDatos::todas(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            AND DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext')",
        [$baseDatos, $tabla]
    );

    if ($columnas === []) {
        continue;
    }

    // Se necesita una clave para poder actualizar la fila concreta.
    $clave = BaseDatos::unValor(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_KEY = 'PRI'
          LIMIT 1",
        [$baseDatos, $tabla]
    );

    if ($clave === null) {
        continue;
    }

    $nombres = array_column($columnas, 'COLUMN_NAME');
    $lista   = '`' . implode('`,`', $nombres) . '`';

    $filas = BaseDatos::todas("SELECT `{$clave}`, {$lista} FROM `{$tabla}`");

    $filasTabla  = 0;
    $celdasTabla = 0;
    $ejemplos    = [];

    foreach ($filas as $fila) {
        $cambios = [];

        foreach ($nombres as $col) {
            $valor = $fila[$col] ?? null;

            if (!is_string($valor) || $valor === '' || !estaCorrupto($valor)) {
                continue;
            }

            $limpio = reparar($valor);

            if ($limpio === null || $limpio === $valor) {
                continue;
            }

            $cambios[$col] = $limpio;

            if (count($ejemplos) < 3) {
                $ejemplos[] = "{$col}: {$valor}  ->  {$limpio}";
            }
        }

        if ($cambios === []) {
            continue;
        }

        $filasTabla++;
        $celdasTabla += count($cambios);

        if ($aplicar) {
            $sets = [];
            $args = [];
            foreach ($cambios as $col => $nuevo) {
                $sets[] = "`{$col}` = ?";
                $args[] = $nuevo;
            }
            $args[] = $fila[$clave];

            BaseDatos::ejecutar(
                "UPDATE `{$tabla}` SET " . implode(', ', $sets) . " WHERE `{$clave}` = ?",
                $args
            );
        }
    }

    if ($filasTabla === 0) {
        continue;
    }

    $tablasTocadas++;
    $totalFilas  += $filasTabla;
    $totalCeldas += $celdasTabla;

    printf("  %-26s %3d filas, %3d campos%s" . PHP_EOL,
        $tabla, $filasTabla, $celdasTabla, $aplicar ? '  [corregida]' : '');

    foreach ($ejemplos as $e) {
        echo '      ' . $e . PHP_EOL;
    }
    echo PHP_EOL;
}

echo '  ' . str_repeat('-', 68) . PHP_EOL;

if ($totalFilas === 0) {
    echo '  No se encontró texto mal codificado.' . PHP_EOL . PHP_EOL;
    exit(0);
}

printf("  %d tablas, %d filas, %d campos%s" . PHP_EOL,
    $tablasTocadas, $totalFilas, $totalCeldas,
    $aplicar ? ' corregidos.' : ' por corregir.');

if (!$aplicar) {
    echo PHP_EOL . '  Para aplicarlo:  php bin/reparar-codificacion.php --aplicar' . PHP_EOL;
}

echo PHP_EOL;
