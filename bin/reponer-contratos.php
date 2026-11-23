<?php

declare(strict_types=1);

/**
 * Contratos cuyo PDF ya no está en el disco.
 *
 * La tabla `contratos` guarda la RUTA del archivo y su SHA-256, pero no
 * el archivo. Si el PDF desaparece —se limpió `storage/pdf`, se movió
 * el proyecto, se restauró una copia de la base sin los ficheros— la
 * fila sigue ahí y la pantalla enseña «archivo ausente». El cliente ve
 * que tiene un contrato y no puede abrirlo.
 *
 * Hay dos casos y NO se tratan igual:
 *
 *   · SIN FIRMAR — el hash sella un archivo que nadie ha aceptado
 *     todavía. Se vuelve a generar y se sella de nuevo. No se pierde
 *     nada porque no había nada que conservar.
 *
 *   · FIRMADO — el cliente aceptó UNOS bytes concretos y de eso da fe
 *     el hash. Volver a generarlo produce otros bytes: aunque el texto
 *     diga lo mismo, la fecha de emisión y hasta el orden interno del
 *     PDF cambian, así que el hash no coincidiría. Reescribirlo y
 *     volver a sellarlo sería fabricar un documento firmado, que es
 *     justo lo que una firma existe para impedir.
 *
 *     Para esos no se toca nada sin decirlo. Con `--reponer-firmados`
 *     se emite una COPIA de lectura, el hash original se conserva
 *     intacto, y queda anotado en la auditoría que el documento que se
 *     puede abrir no es el que se firmó.
 *
 *   php bin/reponer-contratos.php                      (solo mira)
 *   php bin/reponer-contratos.php --aplicar            (repone los sin firmar)
 *   php bin/reponer-contratos.php --aplicar --reponer-firmados
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Documento\ServicioDocumentos;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

$aplicar   = in_array('--aplicar', $argv, true);
$conFirma  = in_array('--reponer-firmados', $argv, true);

function dinero(int $c): string { return '$' . number_format($c / 100, 2); }

echo "\n  CONTRATOS SIN SU ARCHIVO\n";
echo "  ==================================================================\n\n";

$filas = BaseDatos::todas(
    'SELECT k.id, k.tipo, k.ruta_pdf, k.hash_documento, k.firmado_en,
            k.credito_id, k.usuario_id,
            cr.folio, cr.estado AS credito_estado,
            CONCAT(u.nombres, " ", u.apellidos) AS cliente
       FROM contratos k
       JOIN usuarios u ON u.id = k.usuario_id
  LEFT JOIN creditos cr ON cr.id = k.credito_id
      ORDER BY k.id'
);

$faltan = array_values(array_filter(
    $filas,
    static fn(array $k): bool => !is_readable((string) $k['ruta_pdf'])
));

if ($faltan === []) {
    exit("  Todos los contratos tienen su archivo. Nada que hacer.\n\n");
}

$repuestos = 0;
$omitidos  = 0;

foreach ($faltan as $k) {
    $firmado = $k['firmado_en'] !== null;

    printf("  #%-3d %-12s %-16s %s\n", $k['id'], $k['tipo'],
        (string) $k['folio'], (string) $k['cliente']);
    printf("       falta: %s\n", basename((string) $k['ruta_pdf']));
    printf("       firmado: %s\n", $firmado ? (string) $k['firmado_en'] : 'no');

    /* Solo se sabe rehacer el contrato de crédito. Un finiquito o un
       contrato de cuenta se generan por otro camino y rehacerlos a
       ciegas desde aquí produciría un documento que dice otra cosa. */
    if ((string) $k['tipo'] !== 'credito' || $k['credito_id'] === null) {
        printf("       NO SE TOCA: este tipo no se rehace desde aquí.\n\n");
        $omitidos++;
        continue;
    }

    if ($firmado && !$conFirma) {
        printf("       NO SE TOCA: lleva firma. El hash da fe de unos bytes\n");
        printf("                   que ya no existen y rehacerlos daría otros.\n");
        printf("                   Para emitir una copia de lectura: --reponer-firmados\n\n");
        $omitidos++;
        continue;
    }

    if (!$aplicar) {
        printf("       se repondría%s\n\n", $firmado ? ' COMO COPIA DE LECTURA' : '');
        $repuestos++;
        continue;
    }

    try {
        $doc = (new ServicioDocumentos())->contratoCredito((int) $k['credito_id']);
    } catch (\Throwable $e) {
        printf("       FALLÓ: %s\n\n", $e->getMessage());
        $omitidos++;
        continue;
    }

    if ($firmado) {
        /* El hash original NO se toca: es la única constancia de qué
           aceptó el cliente. Solo se apunta dónde está la copia. */
        BaseDatos::ejecutar(
            'UPDATE contratos SET ruta_pdf = ? WHERE id = ?',
            [$doc['ruta'], (int) $k['id']]
        );

        Auditoria::registrar(
            'contrato.copia_de_lectura', Auditoria::EXITO, 0,
            'contrato', (string) $k['id'],
            [
                'folio'        => $k['folio'],
                'hash_firmado' => $k['hash_documento'],
                'hash_copia'   => $doc['hash'],
                'motivo'       => 'el PDF firmado no estaba en el disco. Se emite una copia '
                                . 'legible; el hash del documento firmado se conserva y NO '
                                . 'coincide con el de esta copia.',
            ]
        );

        printf("       repuesto como COPIA. hash firmado %s… se conserva.\n",
            substr((string) $k['hash_documento'], 0, 12));
        printf("                          hash de la copia %s…\n\n",
            substr((string) $doc['hash'], 0, 12));
    } else {
        BaseDatos::ejecutar(
            'UPDATE contratos SET ruta_pdf = ?, hash_documento = ? WHERE id = ?',
            [$doc['ruta'], $doc['hash'], (int) $k['id']]
        );

        Auditoria::registrar(
            'contrato.repuesto', Auditoria::EXITO, 0,
            'contrato', (string) $k['id'],
            [
                'folio'      => $k['folio'],
                'hash_antes' => $k['hash_documento'],
                'hash_ahora' => $doc['hash'],
                'motivo'     => 'el PDF no estaba en el disco y el contrato no tenía firma',
            ]
        );

        printf("       repuesto y sellado de nuevo: %s…\n\n", substr((string) $doc['hash'], 0, 12));
    }

    $repuestos++;
}

printf("  %d por reponer, %d sin tocar.\n", $repuestos, $omitidos);

if (!$aplicar && $repuestos > 0) {
    echo "\n  No se cambió nada. Para reponerlos: --aplicar\n";
}

echo "\n";
