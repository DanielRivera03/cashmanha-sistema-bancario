<?php

declare(strict_types=1);

/**
 * Apaga y enciende el envío de correo, para poder probar sin molestar.
 *
 * POR QUÉ EXISTE ESTO
 *
 * Probar el despacho de un pedido, una transferencia o una apertura
 * de cuenta dispara avisos: es lo que esas operaciones hacen, y está
 * bien que lo hagan. Lo que no está bien es que una prueba le llegue
 * a una persona de verdad.
 *
 * Y hay dos trampas que no se ven:
 *
 *   1. El interruptor del correo es `CORREO_HABILITADO` en `.env`.
 *      NO es una fila de la tabla `parametros`. Escribir ahí un
 *      `correo.habilitado = 0` no apaga nada, porque nadie lo lee.
 *
 *   2. Con `correo.copia_oculta` configurada, TODO correo lleva copia
 *      a esa dirección. Da igual que el destinatario de la prueba sea
 *      `@example.test`: la copia sí llega a una bandeja real. Usar un
 *      destinatario falso NO basta para no molestar a nadie.
 *
 * Las dos juntas hacen que una prueba «inofensiva» acabe en el correo
 * de alguien. Por eso esto apaga el envío de verdad, en el único
 * lugar donde se puede apagar.
 *
 *   php bin/correo-silencio.php apagar     antes de probar
 *   php bin/correo-silencio.php encender   al terminar
 *   php bin/correo-silencio.php estado     para ver cómo está
 *
 * Deja respaldo del `.env` antes de tocarlo. Si algo sale mal, el
 * archivo anterior queda en `.env.antes-de-silenciar`.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Notificacion\Correo;

$env = dirname(__DIR__) . '/.env';
$que = strtolower(trim($argv[1] ?? 'estado'));

if (!is_readable($env) || !is_writable($env)) {
    exit("  No se puede leer o escribir {$env}\n");
}

$texto = (string) file_get_contents($env);

/** Lee el valor actual sin depender de la caché de `Config`. */
$valorActual = static function (string $t): ?string {
    if (preg_match('/^\s*CORREO_HABILITADO\s*=\s*(.*)$/m', $t, $m) === 1) {
        return trim($m[1], " \t\"'");
    }
    return null;
};

$actual = $valorActual($texto);

if ($que === 'estado') {
    printf("  CORREO_HABILITADO = %s\n", $actual ?? '(no está en .env)');
    printf("  Correo::habilitado() = %s\n", Correo::habilitado() ? 'SÍ ENVÍA' : 'solo registra');

    $copia = \CashMan\Infraestructura\Parametros::obtener('correo.copia_oculta', '');

    printf(
        "  copia oculta = %s\n",
        $copia !== '' ? $copia . '  ← esta SÍ recibe, aunque el destinatario sea falso' : '(ninguna)'
    );

    exit(0);
}

if (!in_array($que, ['apagar', 'encender'], true)) {
    exit("  Uso: php bin/correo-silencio.php [apagar|encender|estado]\n");
}

$nuevo = $que === 'apagar' ? 'false' : 'true';

copy($env, $env . '.antes-de-silenciar');

if ($actual === null) {
    $texto = rtrim($texto) . "\n\nCORREO_HABILITADO={$nuevo}\n";
} else {
    $texto = (string) preg_replace(
        '/^\s*CORREO_HABILITADO\s*=.*$/m',
        "CORREO_HABILITADO={$nuevo}",
        $texto,
        1
    );
}

file_put_contents($env, $texto);

/* Se comprueba que quedó escrito y recién entonces se borra el
   respaldo. Un `.env` es un archivo de credenciales: dejar copias
   sueltas por ahí es multiplicar los sitios de donde se pueden
   filtrar. El respaldo existe para el momento en que la escritura
   falla, no para siempre. */
$quedo = $valorActual((string) file_get_contents($env));

if ($quedo === $nuevo) {
    @unlink($env . '.antes-de-silenciar');
} else {
    echo "  !! No quedó escrito. El archivo anterior está en .env.antes-de-silenciar\n";
    exit(1);
}

printf(
    "  CORREO_HABILITADO: %s -> %s\n",
    $actual ?? '(ausente)',
    $nuevo
);

if ($que === 'apagar') {
    echo "  Los correos quedan solo en el registro. ACUÉRDESE de encenderlo al terminar.\n";
} else {
    echo "  El correo vuelve a salir.\n";
}
