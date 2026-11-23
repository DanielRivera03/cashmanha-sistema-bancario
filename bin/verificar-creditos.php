<?php
declare(strict_types=1);

/**
 * Comprueba la coherencia de los créditos migrados.
 *
 * Los créditos que vienen del sistema anterior pueden traer dos
 * problemas que el motor de amortización de este sistema no comete,
 * pero que arrastran los datos importados:
 *
 *   1. El plazo guardado en AÑOS cuando la columna es `plazo_meses`.
 *      Se detecta porque el número de cuotas generadas es doce veces
 *      el plazo declarado. Deja la barra de avance mintiendo: "3 de 15
 *      cuotas" cuando en realidad son 180.
 *
 *   2. La suma de los capitales de las cuotas no coincide con el monto
 *      otorgado. Pasa al repartir una cantidad que no divide exacta y
 *      redondear cada cuota por separado: los centavos sobrantes no se
 *      absorben en la última. En un crédito hipotecario esa diferencia
 *      significa que el cliente nunca termina de pagar, o que paga de
 *      más.
 *
 * Uso:
 *   php bin/verificar-creditos.php              → solo informa
 *   php bin/verificar-creditos.php --aplicar    → corrige
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por línea de comandos.\n");
}

$aplicar = isset(getopt('', ['aplicar'])['aplicar']);

echo PHP_EOL . '  Verificación de créditos' . PHP_EOL;
echo '  ' . str_repeat('=', 72) . PHP_EOL;
echo '  Modo: ' . ($aplicar ? 'APLICAR CORRECCIONES' : 'solo informar (--aplicar para corregir)')
   . PHP_EOL . PHP_EOL;

$creditos = BaseDatos::todas(
    'SELECT c.id, c.folio, c.monto_cent, c.plazo_meses, c.estado,
            CONCAT(u.nombres," ",u.apellidos) AS cliente
       FROM creditos c
       JOIN usuarios u ON u.id = c.usuario_id
      ORDER BY c.id'
);

$problemas = 0;
$corregidos = 0;

foreach ($creditos as $c) {
    $id    = (int) $c['id'];
    $plazo = (int) $c['plazo_meses'];
    $monto = (int) $c['monto_cent'];

    $cuotas = (int) BaseDatos::unValor(
        'SELECT COUNT(*) FROM cuotas WHERE credito_id = ?', [$id]
    );

    if ($cuotas === 0) {
        printf("  %-16s sin cuotas generadas%s" . PHP_EOL, $c['folio'],
            $c['estado'] === 'vigente' ? '  <<< un crédito vigente debería tenerlas' : '');
        continue;
    }

    $capital = (int) BaseDatos::unValor(
        'SELECT COALESCE(SUM(capital_cent),0) FROM cuotas WHERE credito_id = ?', [$id]
    );

    $fallos = [];

    // --- 1. Plazo en años ---
    if ($cuotas !== $plazo) {
        if ($cuotas === $plazo * 12) {
            $fallos[] = [
                'tipo'  => 'plazo_en_anios',
                'texto' => "plazo {$plazo} (años) frente a {$cuotas} cuotas",
                'arreglo' => "plazo_meses: {$plazo} -> {$cuotas}",
                'accion' => static function () use ($id, $cuotas): void {
                    BaseDatos::ejecutar(
                        'UPDATE creditos SET plazo_meses = ? WHERE id = ?', [$cuotas, $id]
                    );
                },
            ];
        } else {
            $fallos[] = [
                'tipo'  => 'plazo_descuadrado',
                'texto' => "plazo {$plazo} frente a {$cuotas} cuotas (no es múltiplo de 12)",
                'arreglo' => null,
                'accion' => null,
            ];
        }
    }

    // --- 2. Capital que no cuadra ---
    $dif = $capital - $monto;

    if ($dif !== 0) {
        $ultima = BaseDatos::unaFila(
            'SELECT id, numero, capital_cent, estado FROM cuotas
              WHERE credito_id = ? ORDER BY numero DESC LIMIT 1',
            [$id]
        );

        $puedeArreglar = $ultima !== null
            && $ultima['estado'] !== 'pagada'
            && ((int) $ultima['capital_cent'] - $dif) > 0;

        $fallos[] = [
            'tipo'  => 'capital_descuadrado',
            'texto' => sprintf('capital suma %s, otorgado %s (diferencia %s)',
                number_format($capital / 100, 2),
                number_format($monto / 100, 2),
                number_format($dif / 100, 2)),
            'arreglo' => $puedeArreglar
                ? sprintf('absorber %s en la cuota %d', number_format(-$dif / 100, 2), (int) $ultima['numero'])
                : 'la última cuota ya está pagada: hay que revisarlo a mano',
            'accion' => $puedeArreglar
                ? static function () use ($ultima, $dif): void {
                    // El sobrante se absorbe en la última cuota, que es
                    // donde corresponde: así el capital suma exacto sin
                    // tocar las cuotas que el cliente ya conoce.
                    BaseDatos::ejecutar(
                        'UPDATE cuotas SET capital_cent = capital_cent - ?,
                                           total_cent  = total_cent  - ?
                          WHERE id = ?',
                        [$dif, $dif, (int) $ultima['id']]
                    );
                }
                : null,
        ];
    }

    if ($fallos === []) {
        printf("  %-16s correcto (%d cuotas, capital cuadra)" . PHP_EOL, $c['folio'], $cuotas);
        continue;
    }

    $problemas++;
    printf(PHP_EOL . "  %-16s %s" . PHP_EOL, $c['folio'], $c['cliente']);

    foreach ($fallos as $f) {
        echo '      · ' . $f['texto'] . PHP_EOL;

        if ($f['arreglo'] !== null) {
            echo '        -> ' . $f['arreglo'] . PHP_EOL;
        }

        if ($aplicar && $f['accion'] !== null) {
            ($f['accion'])();
            $corregidos++;
            echo '        [corregido]' . PHP_EOL;

            Auditoria::registrar(
                'credito.coherencia_corregida', Auditoria::EXITO, null,
                'credito', (string) $id, ['tipo' => $f['tipo'], 'arreglo' => $f['arreglo']]
            );
        }
    }
}

echo PHP_EOL . '  ' . str_repeat('-', 72) . PHP_EOL;

if ($problemas === 0) {
    echo '  Todos los créditos son coherentes.' . PHP_EOL . PHP_EOL;
    exit(0);
}

printf("  %d crédito(s) con problemas%s" . PHP_EOL,
    $problemas, $aplicar ? ", {$corregidos} arreglo(s) aplicado(s)." : '.');

if (!$aplicar) {
    echo PHP_EOL . '  Para aplicarlo:  php bin/verificar-creditos.php --aplicar' . PHP_EOL;
}

echo PHP_EOL;
