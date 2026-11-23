<?php

declare(strict_types=1);

/**
 * Vuelve a generar los PDF que salieron con la cabecera vieja.
 *
 * El hilo morado de separación estaba clavado a una altura fija, y
 * cuando la cabecera llevaba folio Y subtítulo el último renglón caía
 * justo donde pasaba la línea: «Crédito Personal» salía atravesado.
 *
 * El generador ya está corregido —pregunta dónde terminó de escribir
 * en vez de suponerlo—, pero eso solo arregla los documentos NUEVOS.
 * Los que ya estaban guardados conservan el defecto, y son los que el
 * cliente abre: un contrato no se regenera al mirarlo, se sirve el
 * archivo que se firmó.
 *
 * Esto los detecta midiendo el PDF —dónde cae el hilo y dónde el texto
 * de la cabecera— y regenera solo los que lo tienen mal.
 *
 *   php bin/regenerar-pdfs-viejos.php             solo mide y dice cuáles
 *   php bin/regenerar-pdfs-viejos.php --rehacer   los vuelve a generar
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Documento\ServicioDocumentos;
use CashMan\Infraestructura\Persistencia\BaseDatos;

$rehacer = in_array('--rehacer', $argv, true);

const DIR = __DIR__ . '/../storage/pdf/';

/**
 * ¿A qué altura cae el hilo de separación de la cabecera?
 *
 * Es la medida que distingue las dos versiones: la vieja lo ponía en
 * ~41.6 mm pasara lo que pasara, la nueva lo baja hasta donde haga
 * falta (46.6 mm cuando hay folio y subtítulo).
 */
function alturaDelHilo(string $archivo): ?float
{
    $raw = (string) @file_get_contents($archivo);

    if ($raw === '') { return null; }

    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $m);

    $t = '';

    foreach ($m[1] as $s) {
        $d = @gzuncompress($s);
        if ($d !== false && str_contains($d, 'BT')) { $t = $d; break; }
    }

    if ($t === '') { return null; }

    // FPDF escribe en puntos desde abajo; la página A4 mide 841.89 pt.
    preg_match_all('/([\d.]+)\s+([\d.]+)\s+m\s+([\d.]+)\s+([\d.]+)\s+l\s+S/', $t, $l, PREG_SET_ORDER);

    foreach ($l as $e) {
        $y = (841.89 - (float) $e[2]) / 2.8346;

        // El primero que cae en la franja de la cabecera.
        if ($y > 15 && $y < 55) { return round($y, 1); }
    }

    return null;
}

/* Por debajo de esta altura, el hilo viene de la cabecera vieja. La
   nueva nunca sube de 46 mm cuando hay subtítulo, y cuando no lo hay
   se queda en el mínimo de 24 + 6: el rango intermedio es el del
   defecto. */
const SOSPECHOSO_DESDE = 38.0;
const SOSPECHOSO_HASTA = 45.0;

printf("  Cabecera de los PDF guardados · %s\n\n", $rehacer ? 'REHACIENDO' : 'solo midiendo');

$malos = [];

foreach (glob(DIR . '*.pdf') ?: [] as $f) {
    $y = alturaDelHilo($f);

    if ($y === null) { continue; }

    $mal = $y >= SOSPECHOSO_DESDE && $y <= SOSPECHOSO_HASTA;

    if ($mal) {
        $malos[] = $f;
        printf("  %-48s hilo en %4.1f mm  *** cabecera vieja\n", basename($f), $y);
    }
}

if ($malos === []) {
    exit("\n  Todos los PDF guardados tienen la cabecera corregida.\n");
}

printf("\n  %d archivo(s) con la cabecera vieja.\n", count($malos));

if (!$rehacer) {
    exit("\n  Para volver a generarlos: --rehacer\n");
}

echo "\n";

$svc = new ServicioDocumentos();
$hechos   = 0;
$sellados = 0;

foreach ($malos as $f) {
    $nombre = basename($f, '.pdf');

    try {
        /* Se deduce qué documento era por su nombre. Regenerarlo por el
           mismo camino que lo creó es lo único que garantiza que salga
           con el mismo contenido y la cabecera nueva. */
        if (preg_match('/^contrato-(.+?)(?:-\d{14})?$/', $nombre, $m)) {
            $id = (int) BaseDatos::unValor(
                'SELECT id FROM creditos WHERE folio = ?', [$m[1]]
            );

            if ($id === 0) { throw new \RuntimeException('no encuentro el crédito ' . $m[1]); }

            $svc->contratoCredito($id);
        } elseif (preg_match('/^amortizacion-(.+)$/', $nombre, $m)) {
            $id = (int) BaseDatos::unValor(
                'SELECT id FROM creditos WHERE folio = ?', [$m[1]]
            );

            if ($id === 0) { throw new \RuntimeException('no encuentro el crédito ' . $m[1]); }

            $svc->tablaAmortizacion($id);
        } elseif (preg_match('/^estado-/', $nombre)) {
            /* Los estados de cuenta NO se regeneran.
             *
             * `estados_cuenta.hash_documento` guarda la huella del PDF
             * que se le entregó al cliente, y existe para poder
             * demostrar que no se ha tocado desde que se emitió.
             * Regenerarlo cambiaría esa huella, y el motivo sería que
             * una línea de la cabecera queda tres milímetros más
             * arriba: un defecto de maquetación no justifica romper el
             * sello de un documento archivado.
             *
             * Reemitir uno es una decisión con su propio procedimiento,
             * no el efecto secundario de arreglar un hilo. */
            printf("  %-48s sellado, se respeta (ver nota)\n", basename($f));
            $sellados++;
            continue;
        } else {
            printf("  %-48s no sé regenerarlo, se deja\n", basename($f));
            continue;
        }

        /* El viejo se borra SOLO si la regeneración salió bien. Un
           contrato guarda con marca de tiempo, así que el nuevo es otro
           archivo y el viejo seguiría sirviéndose. */
        $ahora = alturaDelHilo($f);

        if ($ahora !== null && $ahora >= SOSPECHOSO_DESDE && $ahora <= SOSPECHOSO_HASTA) {
            @unlink($f);
            printf("  %-48s regenerado; el viejo retirado\n", basename($f));
        } else {
            printf("  %-48s regenerado en su sitio\n", basename($f));
        }

        $hechos++;
    } catch (\Throwable $e) {
        printf("  %-48s FALLO: %s\n", basename($f), mb_substr($e->getMessage(), 0, 40));
    }
}

printf("\n  %d de %d regenerado(s).\n", $hechos, count($malos));

/* Y se vuelve a medir: lo que importa no es que el script corriera,
   sino que ya no quede ninguno con la cabecera vieja. */
$quedan = 0;

foreach (glob(DIR . '*.pdf') ?: [] as $f) {
    $y = alturaDelHilo($f);

    if ($y !== null && $y >= SOSPECHOSO_DESDE && $y <= SOSPECHOSO_HASTA) {
        $quedan++;
        printf("  todavía mal: %s (%.1f mm)\n", basename($f), $y);
    }
}

printf("  Quedan con la cabecera vieja: %d", $quedan);

if ($sellados > 0) {
    printf(" (%d son estados de cuenta sellados)", $sellados);
}

echo "\n";

if ($sellados > 0) {
    echo "\n  Nota sobre los sellados: un estado de cuenta archivado lleva la huella\n";
    echo "  del PDF que se le entregó al cliente. Regenerarlo la cambiaría, y el\n";
    echo "  motivo sería que una línea queda tres milímetros más arriba. El defecto\n";
    echo "  se queda en los ya emitidos; los que se emitan de ahora en adelante\n";
    echo "  salen con la cabecera corregida.\n";
}

exit($quedan - $sellados <= 0 ? 0 : 1);
