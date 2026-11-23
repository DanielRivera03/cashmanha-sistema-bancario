<?php

declare(strict_types=1);

/**
 * Aplica las migraciones que falten.
 *
 * Hasta aquí las migraciones se corrían a mano, archivo por archivo, y
 * nadie llevaba la cuenta de cuáles habían pasado: en una máquina
 * nueva eso son 64 ficheros en orden y rezar.
 *
 * Esto lleva el registro en `migraciones_aplicadas` y es idempotente:
 * correrlo dos veces no hace nada la segunda.
 *
 *   php bin/migrar.php            aplica lo que falte
 *   php bin/migrar.php --listar   solo dice qué falta
 *   php bin/migrar.php 064        una concreta, aunque ya esté aplicada
 *
 * El corte por `;` respeta las comillas: una descripción con punto y
 * coma dentro partía la sentencia por la mitad y dejaba la migración a
 * medio aplicar, que es peor que no aplicarla.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Persistencia\BaseDatos;

BaseDatos::ejecutar(
    'CREATE TABLE IF NOT EXISTS migraciones_aplicadas (
        archivo   VARCHAR(190) NOT NULL PRIMARY KEY,
        aplicada_en DATETIME NOT NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

/**
 * Parte un guion SQL en sentencias respetando comillas y comentarios.
 *
 * @return list<string>
 */
function sentencias(string $sql): array
{
    $fuera    = [];
    $actual   = '';
    $comilla  = '';
    $largo    = strlen($sql);

    for ($i = 0; $i < $largo; $i++) {
        $c = $sql[$i];

        // Comentario de línea, fuera de comillas.
        if ($comilla === '' && $c === '-' && ($sql[$i + 1] ?? '') === '-') {
            while ($i < $largo && $sql[$i] !== "\n") { $i++; }
            $actual .= "\n";
            continue;
        }

        if ($comilla !== '') {
            $actual .= $c;

            if ($c === '\\') {                       // escape dentro de la cadena
                $actual .= $sql[++$i] ?? '';
                continue;
            }

            if ($c === $comilla) { $comilla = ''; }
            continue;
        }

        if ($c === "'" || $c === '"' || $c === '`') {
            $comilla = $c;
            $actual .= $c;
            continue;
        }

        if ($c === ';') {
            $t = trim($actual);
            if ($t !== '') { $fuera[] = $t; }
            $actual = '';
            continue;
        }

        $actual .= $c;
    }

    $t = trim($actual);
    if ($t !== '') { $fuera[] = $t; }

    return $fuera;
}

$soloListar = in_array('--listar', $argv, true);
$pedida     = null;

foreach (array_slice($argv, 1) as $a) {
    if ($a[0] !== '-') { $pedida = $a; }
}

$archivos = glob(dirname(__DIR__) . '/migraciones/*.sql') ?: [];
sort($archivos);

$yaEstan = [];
foreach (BaseDatos::todas('SELECT archivo FROM migraciones_aplicadas') as $r) {
    $yaEstan[(string) $r['archivo']] = true;
}

echo "\n  MIGRACIONES\n";
echo "  ==================================================================\n\n";

$aplicadas = 0;
$saltadas  = 0;

foreach ($archivos as $ruta) {
    $nombre = basename($ruta);

    if ($pedida !== null && !str_starts_with($nombre, $pedida)) {
        continue;
    }

    if ($pedida === null && isset($yaEstan[$nombre])) {
        $saltadas++;
        continue;
    }

    if ($soloListar) {
        printf("  falta  %s\n", $nombre);
        $aplicadas++;
        continue;
    }

    printf("  %s\n", $nombre);

    $partes = sentencias((string) file_get_contents($ruta));
    $hechas = 0;

    foreach ($partes as $s) {
        try {
            BaseDatos::ejecutar($s);
            $hechas++;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            /* Lo ya hecho no es un fallo. Una columna que existe o una
               clave duplicada significa que esa parte ya se aplicó a
               mano, que es justo como se venía trabajando. */
            $yaHecho = str_contains($msg, 'Duplicate column')
                || str_contains($msg, 'Duplicate key')
                || str_contains($msg, 'Duplicate entry')
                || str_contains($msg, 'already exists');

            if ($yaHecho) {
                $hechas++;
                continue;
            }

            /* Hostings gratis (InfinityFree, byet/ezyro…) niegan
               CREATE VIEW. No es un fallo que deba frenar la migración:
               la app inlinea las vistas (ver FuenteVistas), así que sin
               ellas corre igual. Se avisa y se sigue. */
            $esVista  = stripos($s, 'CREATE') !== false && stripos($s, 'VIEW') !== false;
            $denegado = str_contains($msg, 'command denied')
                || str_contains($msg, 'denegado')
                || str_contains($msg, 'denied')
                || str_contains($msg, '1142');

            if ($esVista && $denegado) {
                printf("      vista omitida (el host no permite CREATE VIEW; la app la inlinea)\n");
                continue;
            }

            printf("      FALLÓ: %s\n", mb_substr($msg, 0, 140));
            printf("      en: %s\n\n", mb_substr(preg_replace('/\s+/', ' ', $s) ?? '', 0, 120));
            exit(1);
        }
    }

    BaseDatos::ejecutar(
        'INSERT INTO migraciones_aplicadas (archivo, aplicada_en) VALUES (?, NOW())
         ON DUPLICATE KEY UPDATE aplicada_en = NOW()',
        [$nombre]
    );

    printf("      %d sentencia(s)\n", $hechas);
    $aplicadas++;
}

if ($soloListar) {
    printf("\n  %d por aplicar, %d ya aplicadas.\n\n", $aplicadas, $saltadas);
    exit(0);
}

printf("\n  %d aplicada(s), %d ya estaban.\n\n", $aplicadas, $saltadas);
