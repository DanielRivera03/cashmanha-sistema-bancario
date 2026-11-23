<?php
declare(strict_types=1);

/**
 * Arranque de la aplicación.
 *
 * Define rutas base, registra el autoloader PSR-4, carga la
 * configuración y fija el manejo de errores.
 */

define('CM_RAIZ',       dirname(__DIR__));
define('CM_SRC',        CM_RAIZ . DIRECTORY_SEPARATOR . 'src');
define('CM_STORAGE',    CM_RAIZ . DIRECTORY_SEPARATOR . 'storage');
define('CM_VISTAS',     CM_SRC  . DIRECTORY_SEPARATOR . 'Vistas');
define('CM_INICIO_MS',  microtime(true));

// ---------------------------------------------------------------------
// Autoloader PSR-4: CashMan\  →  src/
// ---------------------------------------------------------------------
spl_autoload_register(static function (string $clase): void {
    /* FPDF no usa namespaces ni PSR-4, así que el autoload PSR-4 no lo
     * encuentra nunca.
     *
     * Hasta ahora cada sitio que generaba un PDF tenía que acordarse de
     * requerirlo a mano, y `ServicioDocumentos` lo hacía en su
     * constructor. Eso funcionaba mientras TODOS los PDF salieran de
     * ahí; al generar uno desde otro sitio, la clase `GeneradorPdf`
     * —que extiende FPDF— no se podía ni cargar, y la pantalla
     * respondía un 500 sin pista de por qué.
     *
     * Registrarlo aquí lo cierra para siempre: quien use `GeneradorPdf`
     * ya no tiene que saber de esto. */
    if ($clase === 'FPDF') {
        $fpdf = CM_RAIZ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR
              . 'FPDF' . DIRECTORY_SEPARATOR . 'fpdf.php';

        if (is_file($fpdf)) {
            require_once $fpdf;
        }

        return;
    }

    $prefijo = 'CashMan\\';
    if (!str_starts_with($clase, $prefijo)) {
        return;
    }

    $relativa = substr($clase, strlen($prefijo));
    $archivo  = CM_SRC . DIRECTORY_SEPARATOR
              . str_replace('\\', DIRECTORY_SEPARATOR, $relativa) . '.php';

    if (is_file($archivo)) {
        require_once $archivo;
    }
});

use CashMan\Infraestructura\Config;

// ---------------------------------------------------------------------
// Ayudantes globales de las vistas
//
// cm_icono() se usa en el layout y en varias vistas. Cargarlo aquí
// evita que cada archivo tenga que acordarse de incluirlo (y que una
// vista falle con "undefined function" si alguien lo olvida).
// ---------------------------------------------------------------------
require_once CM_SRC . DIRECTORY_SEPARATOR . 'Vistas'
           . DIRECTORY_SEPARATOR . 'layout'
           . DIRECTORY_SEPARATOR . 'iconos.php';

// cm_acciones() dibuja la columna de acciones de las tablas y decide,
// permiso por permiso, que ve cada rol. Va aqui por lo mismo: lo usan
// casi todos los listados y ninguno deberia tener que acordarse.
require_once CM_SRC . DIRECTORY_SEPARATOR . 'Vistas'
           . DIRECTORY_SEPARATOR . 'layout'
           . DIRECTORY_SEPARATOR . 'acciones.php';

// cm_plastico() dibuja una tarjeta. Esta aqui para que el panel, el
// listado, la ficha y el catalogo pinten la MISMA tarjeta: cuando cada
// pantalla la dibujaba por su cuenta, la misma tarjeta se veia distinta
// en cada una.
require_once CM_SRC . DIRECTORY_SEPARATOR . 'Vistas'
           . DIRECTORY_SEPARATOR . 'layout'
           . DIRECTORY_SEPARATOR . 'plastico.php';

// ---------------------------------------------------------------------
// Configuración
// ---------------------------------------------------------------------
Config::cargar(CM_RAIZ . DIRECTORY_SEPARATOR . '.env');

date_default_timezone_set(Config::obtener('APP_ZONA_HORARIA', 'UTC'));
mb_internal_encoding('UTF-8');
setlocale(LC_NUMERIC, 'C'); // evita que una locale europea use coma decimal

// ---------------------------------------------------------------------
// Errores
//
// En producción NUNCA se muestran: una traza revela rutas, nombres de
// tabla y a veces credenciales. Se registran en archivo y al usuario
// se le da un mensaje genérico.
// ---------------------------------------------------------------------
$depurar = Config::depuracion();

error_reporting(E_ALL);
ini_set('display_errors', $depurar ? '1' : '0');
ini_set('display_startup_errors', $depurar ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', CM_STORAGE . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'php.log');

// No anunciar la versión de PHP.
header_remove('X-Powered-By');

/**
 * Convierte avisos de PHP en excepciones: ningún fallo pasa inadvertido.
 *
 * Excepción a la regla: los avisos de obsolescencia. Una librería de
 * terceros que use una función marcada como deprecated no debe tumbar
 * una operación bancaria — se registra para corregirlo y se continúa.
 */
set_error_handler(static function (int $nivel, string $mensaje, string $archivo = '', int $linea = 0): bool {
    if (!(error_reporting() & $nivel)) {
        return false;
    }

    if ($nivel === E_DEPRECATED || $nivel === E_USER_DEPRECATED) {
        error_log("[deprecated] {$mensaje} en {$archivo}:{$linea}");
        return true;
    }

    throw new ErrorException($mensaje, 0, $nivel, $archivo, $linea);
});

set_exception_handler(static function (\Throwable $e) use ($depurar): void {
    error_log(sprintf(
        "[%s] %s en %s:%d\n%s",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    if ($depurar) {
        echo '<pre style="background:#1e1e2e;color:#f38ba8;padding:1.5rem;'
           . 'font:13px ui-monospace,monospace;overflow:auto;border-radius:8px;margin:1rem">';
        echo htmlspecialchars(
            get_class($e) . ': ' . $e->getMessage() . "\n\n"
            . $e->getFile() . ':' . $e->getLine() . "\n\n"
            . $e->getTraceAsString(),
            ENT_QUOTES,
            'UTF-8'
        );
        echo '</pre>';
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
           . '<div style="font:16px system-ui;padding:3rem;text-align:center">'
           . '<h1>Algo salió mal</h1>'
           . '<p>El error quedó registrado. Intenta de nuevo en unos momentos.</p></div>';
    }
    exit(1);
});

// ---------------------------------------------------------------------
// Directorios de trabajo
// ---------------------------------------------------------------------
foreach (['logs', 'cache', 'uploads', 'pdf'] as $dir) {
    $ruta = CM_STORAGE . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($ruta)) {
        @mkdir($ruta, 0770, true);
    }
}

// ---------------------------------------------------------------------
// Las tareas periódicas, dentro del propio sistema
// ---------------------------------------------------------------------
//
// Antes dependían del Programador de tareas de Windows. Eso ata el
// sistema a una máquina y a que alguien se acuerde de registrarlas: al
// moverlo a otro servidor o restaurar una copia, las tareas se quedan
// atrás sin que nada avise. Y lo que se queda atrás no es menor —sin
// `revisar-segmentos` los clientes dejan de recibir los beneficios que
// su pantalla les promete.
//
// Esto solo REGISTRA el enganche; la consulta se hace al cerrar la
// petición, cuando el usuario ya tiene su respuesta.
//
// EN CONSOLA TAMBIÉN, Y ES LO QUE CIERRA EL ÚLTIMO AGUJERO
//
// Antes esto se saltaba en consola, con el argumento de que los
// guiones de `bin/` no deben lanzarse unos a otros. El argumento vale
// para las TAREAS —y por eso en consola no se corre ninguna— pero no
// valía para el latido, y ahí quedaba el hueco:
//
// Si el latido moría —al reiniciar la máquina, al cerrar XAMPP, por
// un fallo— solo volvía con una VISITA WEB. En una instalación sin
// tráfico podía pasar días muerto, y mientras tanto la mora no se
// devengaba, los plazos no vencían y las remesas no caducaban. Todo
// en silencio, que es lo peor que puede pasar.
//
// Ahora CUALQUIER contacto con el sistema lo revive: una visita, un
// `php bin/loquesea.php`, una prueba. El propio método se encarga de
// no correr tareas en consola y de no arrancar un segundo latido
// cuando el guion que corre es el latido mismo.
\CashMan\Aplicacion\Servicio\ServicioTareasSistema::engancharAlCierre();
