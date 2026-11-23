<?php
declare(strict_types=1);

/**
 * Diagnostica y prueba el envío de correo.
 *
 * Uso:
 *   php bin/probar-correo.php                      → solo conexión
 *   php bin/probar-correo.php --a=correo@ejemplo.com → envía de prueba
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por línea de comandos.\n");
}

$op      = getopt('', ['a::']);
$destino = $op['a'] ?? null;

echo PHP_EOL . '  Diagnóstico de correo SMTP' . PHP_EOL;
echo '  ' . str_repeat('=', 62) . PHP_EOL . PHP_EOL;

printf("  %-22s %s\n", 'Habilitado',  Config::booleano('CORREO_HABILITADO') ? 'sí' : 'NO');
printf("  %-22s %s\n", 'Servidor',    Config::obtener('SMTP_HOST', '(sin definir)'));
printf("  %-22s %s\n", 'Puerto',      Config::obtener('SMTP_PUERTO', '(sin definir)'));
printf("  %-22s %s\n", 'Cifrado',     Config::obtener('SMTP_CIFRADO', '(ninguno)') ?: '(ninguno)');
printf("  %-22s %s\n", 'Usuario',     Config::obtener('SMTP_USUARIO', '') ?: '(sin autenticación)');
printf("  %-22s %s\n", 'Clave',       Config::obtener('SMTP_CLAVE', '') !== '' ? '(definida)' : '(vacía)');
printf("  %-22s %s\n", 'Remitente',   Config::obtener('SMTP_DESDE', ''));
echo PHP_EOL;

if (!Config::booleano('CORREO_HABILITADO')) {
    echo '  El correo está DESHABILITADO en .env (CORREO_HABILITADO=false).' . PHP_EOL;
    echo '  Mientras esté así, los códigos OTP solo se muestran en pantalla.' . PHP_EOL . PHP_EOL;
    echo '  Para activarlo, elige un proveedor y ajusta .env:' . PHP_EOL . PHP_EOL;
    echo '    Mailtrap (pruebas, gratis — recomendado para desarrollo)' . PHP_EOL;
    echo '      CORREO_HABILITADO=true' . PHP_EOL;
    echo '      SMTP_HOST=sandbox.smtp.mailtrap.io' . PHP_EOL;
    echo '      SMTP_PUERTO=2525' . PHP_EOL;
    echo '      SMTP_CIFRADO=tls' . PHP_EOL;
    echo '      SMTP_USUARIO=<tu usuario de Mailtrap>' . PHP_EOL;
    echo '      SMTP_CLAVE=<tu clave de Mailtrap>' . PHP_EOL . PHP_EOL;
    echo '    Gmail (requiere "contraseña de aplicación", no la del correo)' . PHP_EOL;
    echo '      SMTP_HOST=smtp.gmail.com' . PHP_EOL;
    echo '      SMTP_PUERTO=587' . PHP_EOL;
    echo '      SMTP_CIFRADO=tls' . PHP_EOL;
    echo '      SMTP_USUARIO=tucorreo@gmail.com' . PHP_EOL;
    echo '      SMTP_CLAVE=<contraseña de aplicación de 16 caracteres>' . PHP_EOL . PHP_EOL;
    echo '    Papercut / MailHog (local, sin cuenta)' . PHP_EOL;
    echo '      SMTP_HOST=127.0.0.1' . PHP_EOL;
    echo '      SMTP_PUERTO=25' . PHP_EOL;
    echo '      SMTP_CIFRADO=' . PHP_EOL . PHP_EOL;
    exit(0);
}

echo '  Probando conexión...' . PHP_EOL . PHP_EOL;

$correo = new Correo();
$r      = $correo->probarConexion();

foreach ($r['traza'] as $l) {
    echo '    < ' . mb_substr(str_replace(["\r", "\n"], ' ', $l), 0, 90) . PHP_EOL;
}
echo PHP_EOL;

if (!$r['ok']) {
    echo '  FALLÓ: ' . $r['mensaje'] . PHP_EOL . PHP_EOL;
    exit(1);
}

echo '  ' . $r['mensaje'] . PHP_EOL . PHP_EOL;

if ($destino === null) {
    echo '  Para enviar un correo real:' . PHP_EOL;
    echo '    php bin/probar-correo.php --a=tucorreo@ejemplo.com' . PHP_EOL . PHP_EOL;
    exit(0);
}

echo '  Enviando mensaje de prueba a ' . $destino . ' ...' . PHP_EOL;

$ok = (new Correo())->enviar(
    $destino,
    'Prueba de configuración — CashMan H.A.',
    PlantillaCorreo::base(
        'Configuración correcta',
        'Hola,',
        '<p>Si estás leyendo esto, el envío de correo de CashMan H.A. '
        . 'quedó funcionando correctamente.</p>'
        . '<p>A partir de ahora los códigos de verificación, comprobantes '
        . 'de transacción y estados de cuenta se entregan por este medio.</p>',
        [
            ['etiqueta' => 'Servidor',  'valor' => Config::obtener('SMTP_HOST', '')],
            ['etiqueta' => 'Puerto',    'valor' => Config::obtener('SMTP_PUERTO', '')],
            ['etiqueta' => 'Cifrado',   'valor' => Config::obtener('SMTP_CIFRADO', '') ?: 'ninguno'],
            ['etiqueta' => 'Enviado',   'valor' => date('d/m/Y H:i:s')],
        ],
        null,
        null,
        null,
        'Este es un mensaje de prueba generado desde la línea de comandos.'
    ),
    '',
    'Equipo CashMan'
);

echo PHP_EOL . ($ok
    ? '  Enviado. Revisa la bandeja de ' . $destino . PHP_EOL
    : '  No se pudo enviar. Revisa storage/logs/php.log' . PHP_EOL);
echo PHP_EOL;
