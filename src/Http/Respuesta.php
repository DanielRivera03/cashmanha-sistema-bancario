<?php
declare(strict_types=1);

namespace CashMan\Http;

/**
 * Construcción de respuestas HTTP.
 */
final class Respuesta
{
    /** @param array<string,mixed>|list<mixed> $datos */
    public static function json(array $datos, int $codigo = 200, array $cabeceras = []): void
    {
        if (!headers_sent()) {
            http_response_code($codigo);
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, private');
            foreach ($cabeceras as $k => $v) {
                header("{$k}: {$v}");
            }
        }

        /* Si esta petición gastó un token CSRF, se devuelve uno nuevo
         * para que la pantalla pueda seguir operando sin recargar.
         *
         * Va con su propósito: el navegador solo debe sustituir los
         * campos de ese mismo propósito. Sin el dato, una respuesta de
         * «tarjeta» pisaba el token de un formulario de «perfil» que
         * estuviera en la misma pantalla, y ese formulario quedaba roto
         * sin que nadie hubiera tocado nada. */
        if (array_is_list($datos) === false) {
            $nuevo = \CashMan\Infraestructura\Seguridad\Csrf::renovar();
            if ($nuevo !== null) {
                $datos['csrf'] = $nuevo;
                $datos['csrf_proposito'] = \CashMan\Infraestructura\Seguridad\Csrf::propositoRenovado();
            }
        }

        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        exit;
    }

    /** @param array<string,mixed> $extra */
    public static function exito(mixed $datos = null, string $mensaje = '', array $extra = []): void
    {
        self::json(['ok' => true, 'mensaje' => $mensaje, 'datos' => $datos] + $extra);
    }

    /** @param array<string,mixed> $extra */
    public static function error(string $mensaje, int $codigo = 400, array $extra = []): void
    {
        self::json(['ok' => false, 'mensaje' => $mensaje] + $extra, $codigo);
    }

    /**
     * Respuesta cacheable con ETag.
     *
     * Es la base del polling eficiente: si el contenido no cambió,
     * se responde 304 sin cuerpo y sin volver a consultar nada.
     *
     * @param array<string,mixed>|list<mixed> $datos
     */
    public static function jsonConEtag(array $datos, int $maxAge = 0): void
    {
        $cuerpo = json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $etag = '"' . substr(hash('xxh128', $cuerpo), 0, 24) . '"';

        $recibido = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';

        if ($recibido !== '' && trim($recibido) === $etag) {
            if (!headers_sent()) {
                http_response_code(304);
                header('ETag: ' . $etag);
                header('Cache-Control: private, max-age=' . $maxAge);
            }
            exit;
        }

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=UTF-8');
            header('ETag: ' . $etag);
            header('Cache-Control: private, max-age=' . $maxAge);
            header('X-Content-Type-Options: nosniff');
        }

        echo $cuerpo;
        exit;
    }

    public static function redirigir(string $ruta, int $codigo = 302): void
    {
        if (!headers_sent()) {
            // Evita inyección de cabeceras por CR/LF.
            $limpia = str_replace(["\r", "\n", "\0"], '', $ruta);
            header('Location: ' . $limpia, true, $codigo);
        }
        exit;
    }

    public static function texto(string $contenido, int $codigo = 200): void
    {
        if (!headers_sent()) {
            http_response_code($codigo);
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo $contenido;
        exit;
    }

    public static function html(string $contenido, int $codigo = 200): void
    {
        if (!headers_sent()) {
            http_response_code($codigo);
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo $contenido;
        exit;
    }

    /** ¿El cliente espera JSON? */
    public static function esperaJson(): bool
    {
        $acepta = $_SERVER['HTTP_ACCEPT'] ?? '';
        $ajax   = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        return $ajax || str_contains($acepta, 'application/json');
    }

    public static function descargar(string $rutaArchivo, string $nombreVisible, string $tipo = 'application/pdf'): void
    {
        self::entregar($rutaArchivo, $nombreVisible, $tipo, 'attachment');
    }

    /**
     * El mismo archivo, pero para verlo en el navegador.
     *
     * Un informe o un comprobante se consultan antes de guardarse: con
     * `attachment` el navegador lo baja a la carpeta de descargas sin
     * enseñarlo, y para mirarlo hay que ir a buscarlo al disco. Abierto
     * en su pestaña se lee en el acto, y quien quiera guardarlo tiene
     * el botón del propio visor.
     */
    public static function ver(string $rutaArchivo, string $nombreVisible, string $tipo = 'application/pdf'): void
    {
        self::entregar($rutaArchivo, $nombreVisible, $tipo, 'inline');
    }

    private static function entregar(
        string $rutaArchivo,
        string $nombreVisible,
        string $tipo,
        string $disposicion
    ): void {
        if (!is_readable($rutaArchivo)) {
            self::error('Archivo no disponible.', 404);
        }

        // Nombre saneado: evita inyección en la cabecera.
        $nombre = preg_replace('/[^A-Za-z0-9._-]/', '_', $nombreVisible) ?? 'documento';

        if (!headers_sent()) {
            header('Content-Type: ' . $tipo);
            header('Content-Disposition: ' . $disposicion . '; filename="' . $nombre . '"');
            header('Content-Length: ' . (string) filesize($rutaArchivo));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, private');

            self::avisarListo();
        }

        readfile($rutaArchivo);
        exit;
    }

    /**
     * Le dice a la pestaña que lo pidió que el documento ya salió.
     *
     * Generar un contrato o un estado de cuenta tarda, y mientras tanto
     * la pantalla no daba ninguna señal: el usuario volvía a pulsar y
     * se generaban dos. Pero la pestaña que abre el documento no puede
     * avisar a la que la abrió —son documentos distintos y el segundo
     * es un PDF, sin JavaScript—, así que el aviso va por donde sí
     * pasa: una galleta que el navegador comparte entre las dos.
     *
     * La pide el navegador con `?listo=<ficha>`; aquí solo se devuelve
     * esa misma ficha, que no identifica nada ni vale para nada más.
     */
    private static function avisarListo(): void
    {
        $ficha = (string) ($_GET['listo'] ?? '');

        if ($ficha === '' || preg_match('/^[A-Za-z0-9]{6,32}$/', $ficha) !== 1) {
            return;
        }

        setcookie('cm_doc_' . $ficha, '1', [
            'expires'  => time() + 120,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => false,   // la lee el JavaScript de la pestaña que esperaba
            'samesite' => 'Lax',
        ]);
    }
}
