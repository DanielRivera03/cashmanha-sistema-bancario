#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Borra las altas de segundo factor que quedaron a medias.
 *
 * QUÉ ES UN ALTA A MEDIAS
 *
 * Alguien pulsó «Activar», el sistema generó un secreto y lo guardó, y
 * ahí se quedó: nunca se confirmó con un código. La cuenta sigue sin
 * segundo factor, pero arrastra un secreto que no sirve para nada.
 *
 * POR QUÉ MOLESTA
 *
 * Porque mientras existía el fallo del código QR, cada intento dejaba
 * un secreto nuevo y la aplicación del teléfono acumulaba una entrada
 * por intento: cinco «CashMan H.A.» idénticas a la vista y sin forma
 * de saber cuál era la buena. Limpiando aquí, el siguiente intento
 * empieza de cero con un secreto único.
 *
 * LO QUE NO TOCA, NUNCA
 *
 * Las cuentas con el segundo factor ACTIVO. Borrarles el secreto las
 * dejaría sin poder entrar —el sistema les pediría un código que ya
 * nadie podría generar— y recuperarlas exigiría intervención manual.
 * Para desactivarlo está la pantalla de seguridad, con su código.
 *
 * Uso:
 *     php bin/limpiar-2fa-pendiente.php              dice qué haría
 *     php bin/limpiar-2fa-pendiente.php --si         lo hace
 *     php bin/limpiar-2fa-pendiente.php usuario --si  solo a ése
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

$opciones = [];
$soloUno  = '';

foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        $par = explode('=', substr($a, 2), 2);
        $opciones[$par[0]] = $par[1] ?? true;
        continue;
    }

    if ($soloUno === '') { $soloUno = $a; }
}

$deVerdad = isset($opciones['si']);

echo "\n  ALTAS DE SEGUNDO FACTOR SIN TERMINAR\n";
echo '  ' . str_repeat('=', 68) . "\n\n";

$donde = 'u.totp_activo = 0 AND u.totp_secreto_cifrado IS NOT NULL';
$args  = [];

if ($soloUno !== '') {
    $donde .= ' AND u.usuario = ?';
    $args[] = $soloUno;
}

$filas = BaseDatos::todas(
    "SELECT u.id, u.usuario, CONCAT(u.nombres,' ',u.apellidos) AS nombre
       FROM usuarios u
      WHERE {$donde}
      ORDER BY u.usuario",
    $args
);

if ($filas === []) {
    echo "  No hay ninguna. Nada que limpiar.\n\n";
    exit(0);
}

printf("  %d cuenta(s) con un secreto a medio dar de alta:\n\n", count($filas));

foreach ($filas as $f) {
    printf("    %-22s %s\n", $f['usuario'], $f['nombre']);
}

/* Y lo que NO se va a tocar, dicho en voz alta. */
$activas = (int) BaseDatos::unValor(
    'SELECT COUNT(*) FROM usuarios WHERE totp_activo = 1'
);

if ($activas > 0) {
    printf("\n  (%d cuenta(s) tienen el segundo factor ACTIVO y no se tocan.)\n", $activas);
}

if (!$deVerdad) {
    echo "\n  ENSAYO: no se borró nada.\n";
    echo "  Para hacerlo de verdad, repita con --si\n\n";
    exit(0);
}

$ids = implode(',', array_map(static fn(array $f): int => (int) $f['id'], $filas));

BaseDatos::enTransaccion(static function () use ($ids): void {
    /* El secreto y, por si acaso, cualquier código de respaldo que
       hubiera quedado suelto de un intento anterior. */
    BaseDatos::ejecutar(
        "UPDATE usuarios
            SET totp_secreto_cifrado = NULL, totp_confirmado_en = NULL,
                totp_ultimo_contador = NULL
          WHERE id IN ({$ids}) AND totp_activo = 0"
    );

    BaseDatos::ejecutar("DELETE FROM totp_respaldo WHERE usuario_id IN ({$ids})");
});

Auditoria::registrar(
    'totp.pendientes_limpiados',
    Auditoria::EXITO,
    null,
    'usuario',
    $soloUno !== '' ? $soloUno : 'varios',
    ['cuentas' => count($filas)]
);

printf("\n  %d alta(s) a medias borradas.\n", count($filas));
echo "  En su teléfono, borre a mano las entradas sobrantes de la\n";
echo "  aplicación autenticadora: esas viven ahí y el sistema no las ve.\n\n";
