<?php

declare(strict_types=1);

/**
 * Clases que se usan sin importar.
 *
 * `new ServicioRiesgo()` dentro de `CashMan\Http\Controlador` sin su
 * `use` no es un error de sintaxis: PHP lo resuelve a
 * `CashMan\Http\Controlador\ServicioRiesgo`, que no existe, y revienta
 * en tiempo de ejecución, solo cuando alguien entra por esa rama. Un
 * `php -l` nunca lo ve. Esta clase de fallo aparece en la pantalla de
 * un cliente, no en una prueba.
 *
 * NO SE CARGA NADA PARA AVERIGUARLO
 * ---------------------------------
 * La primera versión preguntaba con `class_exists()`, que dispara el
 * autocargador: revisar el código lo iba EJECUTANDO, y se cayó sola al
 * tocar un generador de PDF que hereda de una biblioteca que solo se
 * carga a demanda. Una herramienta que revisa no puede tener efectos.
 *
 * Así que primero se lee todo el proyecto y se arma el censo de qué
 * clase vive en qué espacio, leyendo `namespace` y `class`. Después se
 * comprueba contra ese censo. Nada se instancia ni se carga.
 *
 *   php bin/verificar-clases.php
 *
 * Devuelve 1 si encuentra algo, para poder usarlo en un gancho.
 */

$raiz = dirname(__DIR__);

/** Palabras que aparecen donde una clase pero no lo son. */
const NO_SON_CLASES = [
    'self', 'static', 'parent', 'class', 'object', 'array', 'string', 'int',
    'float', 'bool', 'callable', 'iterable', 'void', 'mixed', 'never', 'null',
    'true', 'false', 'fn', 'function', 'list',
];

/** Lo que trae PHP de serie y se usa sin importar nada. */
const DEL_LENGUAJE = [
    'Exception', 'Error', 'Throwable', 'RuntimeException', 'LogicException',
    'InvalidArgumentException', 'TypeError', 'ValueError', 'ArithmeticError',
    'DivisionByZeroError', 'ErrorException', 'OutOfRangeException',
    'OutOfBoundsException', 'LengthException', 'DomainException',
    'RangeException', 'UnexpectedValueException', 'JsonException',
    'DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateInterval',
    'DatePeriod', 'DateTimeInterface',
    'PDO', 'PDOStatement', 'PDOException',
    'ArrayObject', 'ArrayIterator', 'Iterator', 'IteratorAggregate',
    'Traversable', 'Countable', 'JsonSerializable', 'Stringable',
    'Closure', 'Generator', 'WeakMap', 'SplFileInfo', 'SplObjectStorage',
    'SplStack', 'SplQueue', 'SplFixedArray',
    'RecursiveDirectoryIterator', 'RecursiveIteratorIterator',
    'DirectoryIterator', 'FilesystemIterator', 'CallbackFilterIterator',
    'ReflectionClass', 'ReflectionMethod', 'ReflectionProperty',
    'ReflectionFunction', 'ReflectionNamedType',
    'SimpleXMLElement', 'DOMDocument', 'NumberFormatter', 'IntlDateFormatter',
    'ZipArchive', 'CurlHandle', 'Random\\Randomizer',
];

/** @return list<string> Todos los .php bajo una carpeta. */
function archivosPhp(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $salen = [];
    $iter  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    foreach ($iter as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $salen[] = str_replace('\\', '/', $f->getPathname());
        }
    }

    return $salen;
}

/**
 * El código de un archivo, sin comentarios ni cadenas de texto.
 *
 * Se conservan los saltos de línea para no juntar lo que estaba
 * separado y crear parejas de palabras que no existían.
 */
function codigoSolo(string $src): string
{
    $fuera = '';

    foreach (token_get_all($src) as $t) {
        if (!is_array($t)) {
            $fuera .= $t;
            continue;
        }

        $esRuido = in_array($t[0], [
            T_COMMENT, T_DOC_COMMENT,
            T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE,
            T_INLINE_HTML,
        ], true);

        $fuera .= $esRuido
            ? str_repeat("\n", substr_count($t[1], "\n"))
            : $t[1];
    }

    return $fuera;
}
$archivos = array_merge(
    archivosPhp($raiz . '/src'),
    archivosPhp($raiz . '/vendor')
);

/* ---------- 1. El censo: qué clase vive en qué espacio ---------- */

/** @var array<string, list<string>> $censo  nombre corto => espacios completos */
$censo = [];

foreach ($archivos as $ruta) {
    $src = (string) file_get_contents($ruta);

    $espacio = preg_match('/^namespace\s+([^;{]+)[;{]/m', $src, $m) === 1
        ? trim($m[1])
        : '';

    if (preg_match_all(
        '/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m',
        $src,
        $ms
    ) > 0) {
        foreach ($ms[1] as $nombre) {
            $completo = $espacio === '' ? $nombre : $espacio . '\\' . $nombre;
            $censo[$nombre][] = $completo;
        }
    }
}

/* ---------- 2. Comprobar cada archivo del proyecto ---------- */

$fallos = [];
$leidos = 0;

foreach (archivosPhp($raiz . '/src') as $ruta) {
    $src = (string) file_get_contents($ruta);
    $leidos++;

    /* Fuera comentarios y textos ANTES de buscar.
     *
     * La primera versión los leía y denunciaba un `Segmento::` escrito
     * dentro de un comentario que explicaba por qué NO se usaba. Siete
     * de los nueve hallazgos eran eso: ruido que obliga a comprobar a
     * mano lo que la herramienta debía decidir. Una herramienta que
     * acusa en falso deja de leerse, y entonces no sirve para nada.
     *
     * `token_get_all` es el analizador léxico del propio PHP: separa
     * código de comentarios y de cadenas con exactitud, y no ejecuta
     * nada de lo que lee. */
    $src = codigoSolo($src);

    /* Sin espacio de nombres —una vista— todo cae en el global y esta
       comprobación no aplica. */
    if (preg_match('/^namespace\s+([^;{]+)[;{]/m', $src, $m) !== 1) {
        continue;
    }

    $espacio = trim($m[1]);

    // Lo que el archivo importa, por su nombre corto.
    $importado = [];

    if (preg_match_all('/^use\s+(?:function\s+|const\s+)?([^;]+);/m', $src, $ms) > 0) {
        foreach ($ms[1] as $linea) {
            foreach (explode(',', $linea) as $parte) {
                $parte = trim($parte);

                if (preg_match('/\s+as\s+(\w+)$/i', $parte, $alias) === 1) {
                    $importado[$alias[1]] = true;
                    continue;
                }

                $corto = substr(strrchr('\\' . $parte, '\\') ?: '', 1);

                if ($corto !== '') {
                    $importado[$corto] = true;
                }
            }
        }
    }

    /* Los nombres usados como clase. Se ignora todo lo que lleve barra
       delante, porque eso ya dice dónde vive. */
    $usados = [];

    foreach ([
        '/\bnew\s+([A-Z]\w*)\s*\(/',
        '/(?<![\\\\\w$>])([A-Z]\w*)::/',
        '/\bcatch\s*\(\s*\\\\?([A-Z]\w*)[\s|)]/',
        '/\binstanceof\s+([A-Z]\w*)/',
        '/\b(?:extends|implements)\s+([A-Z]\w*)/',
    ] as $patron) {
        if (preg_match_all($patron, $src, $hits) > 0) {
            foreach ($hits[1] as $nombre) {
                $usados[$nombre] = true;
            }
        }
    }

    foreach (array_keys($usados) as $nombre) {
        if (in_array(strtolower($nombre), NO_SON_CLASES, true)) { continue; }
        if (in_array($nombre, DEL_LENGUAJE, true))              { continue; }
        if (isset($importado[$nombre]))                         { continue; }

        // ¿Vive en el mismo espacio de nombres? Entonces no hace falta.
        $aqui = $espacio . '\\' . $nombre;

        if (isset($censo[$nombre]) && in_array($aqui, $censo[$nombre], true)) {
            continue;
        }

        // ¿Es una clase global del proyecto o de una biblioteca?
        if (isset($censo[$nombre]) && in_array($nombre, $censo[$nombre], true)) {
            continue;
        }

        $fallos[] = [
            'archivo' => str_replace(str_replace('\\', '/', $raiz) . '/', '', $ruta),
            'clase'   => $nombre,
            'espacio' => $espacio,
            'donde'   => $censo[$nombre] ?? [],
        ];
    }
}

echo "\n  CLASES USADAS SIN IMPORTAR\n";
echo "  ==================================================================\n\n";

if ($fallos === []) {
    printf("  %d archivos revisados. Todos los nombres resuelven.\n\n", $leidos);
    exit(0);
}

foreach ($fallos as $f) {
    printf("  %s\n", $f['archivo']);
    printf("      usa  %s  sin importarlo\n", $f['clase']);

    if ($f['donde'] !== []) {
        foreach ($f['donde'] as $d) {
            printf("      falta:  use %s;\n", $d);
        }
    } else {
        printf("      no hay ninguna clase con ese nombre en el proyecto.\n");
    }

    echo "\n";
}

printf("  %d archivos revisados, %d problema(s).\n\n", $leidos, count($fallos));
exit(1);
