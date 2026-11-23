<?php

declare(strict_types=1);

/**
 * Corre las pruebas del dominio.
 *
 *   php bin/probar.php              todas
 *   php bin/probar.php Monto Mora   solo esas
 *
 * Devuelve 1 si algo falla, para poder usarlo en un gancho de git.
 *
 * Qué se prueba y qué no
 * ----------------------
 * El dominio: `Monto`, `Mora`, `Amortizacion`, `Pan`, `LibroMayor`.
 * Son 2.200 líneas que no dependen de nada y donde un error cuesta
 * dinero de verdad —una cuota mal calculada, una mora desproporcionada,
 * un reparto que pierde centavos—.
 *
 * No se prueban los controladores ni las vistas. Eso pide un navegador
 * de mentira y una base de datos de usar y tirar, y es un proyecto en
 * sí mismo. Mientras tanto, `verificar-cuadre.php` comprueba las
 * igualdades sobre los datos reales y `verificar-clases.php` encuentra
 * los nombres que no resuelven.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/../tests/_marco.php';

/* Las pruebas no mandan correo. Nunca.
 *
 * Varias ejercitan servicios que avisan al cliente —la compensación
 * manda uno cada vez que toma saldo— y la batería corre esos casos
 * nueve veces seguidas. Con `correo.copia_oculta` configurada, los
 * avisos de clientes inventados aterrizaban en una bandeja real, y
 * cada ejecución soltaba otra tanda.
 *
 * Se silencia aquí y no en cada prueba a propósito: así también
 * quedan cubiertas las que se escriban mañana, sin que su autor tenga
 * que acordarse de nada. */
/* Y la sesion se abre antes de imprimir una sola linea.
 *
 * `Sesion::iniciar()` ajusta opciones con `ini_set`, y eso solo se
 * puede hacer mientras no se haya enviado nada. Con un `echo` por
 * delante ya no arranca, y cualquier prueba que necesite un actor
 * —las del segundo factor, por ejemplo— revienta en vez de correr.
 *
 * Abrirla aqui no autentica a nadie: solo deja el mecanismo listo
 * para la prueba que lo pida. */
if (PHP_SAPI === 'cli' && session_status() === PHP_SESSION_NONE) {
    @ini_set('session.use_cookies', '0');
    @session_start();
}

\CashMan\Infraestructura\Notificacion\Correo::silenciar();

$soloEstas = array_slice($argv, 1);

$archivos = glob(dirname(__DIR__) . '/tests/*Test.php') ?: [];
sort($archivos);

echo "\n  PRUEBAS DEL DOMINIO\n";
echo "  ==================================================================\n";

$corridas = 0;

foreach ($archivos as $archivo) {
    $nombre = basename($archivo, 'Test.php');

    if ($soloEstas !== [] && !in_array($nombre, $soloEstas, true)) {
        continue;
    }

    $corridas++;

    /* Cada archivo corre aislado de los demás en cuanto a errores: si
       uno revienta a medias, los otros siguen. Enterarse de un fallo no
       debería impedir enterarse de los otros cuatro. */
    try {
        require $archivo;
    } catch (\Throwable $e) {
        printf("\n    FALLA %s reventó: %s\n", $nombre, $e->getMessage());
        printf("          %s:%d\n", $e->getFile(), $e->getLine());
        Pruebas::cierto(false, $nombre . ' terminó sin reventar');
    }
}

if ($corridas === 0) {
    printf("\n  No se encontró ninguna prueba con ese nombre.\n");
    printf("  Hay: %s\n\n", implode(', ', array_map(
        static fn(string $a): string => basename($a, 'Test.php'),
        $archivos
    )));
    exit(1);
}

exit(Pruebas::resumen());
