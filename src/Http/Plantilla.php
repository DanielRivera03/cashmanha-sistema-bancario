<?php
declare(strict_types=1);

namespace CashMan\Http;

use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;

/**
 * Motor de plantillas mínimo con escape obligatorio.
 *
 * El sistema anterior tenía CERO llamadas a htmlspecialchars en 251
 * archivos PHP: cualquier dato de usuario que se reimprimiera era un
 * XSS almacenado.
 *
 * Aquí la función de escape se llama `e()` y es tan corta que no hay
 * excusa para no usarla. Para los casos en que de verdad hace falta
 * HTML sin escapar existe `crudo()`, que obliga a escribir la
 * intención de forma explícita y visible en la revisión de código.
 */
final class Plantilla
{
    /** @var array<string,mixed> */
    private array $datos = [];
    private string $directorioVistas;

    public function __construct(?string $directorioVistas = null)
    {
        $this->directorioVistas = $directorioVistas
            ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Vistas';
    }

    /** @param array<string,mixed> $datos */
    public function compartir(array $datos): self
    {
        $this->datos = array_merge($this->datos, $datos);
        return $this;
    }

    /** @param array<string,mixed> $datos */
    public function render(string $vista, array $datos = []): string
    {
        $archivo = $this->rutaVista($vista);

        if (!is_file($archivo)) {
            throw new \RuntimeException("Vista no encontrada: {$vista}");
        }

        // extract() con EXTR_SKIP: una variable de la vista nunca
        // sobrescribe $this, $archivo ni $datos.
        $todos = array_merge($this->datos, $datos);

        ob_start();
        (function () use ($archivo, $todos): void {
            extract($todos, EXTR_SKIP);
            require $archivo;
        })();

        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $datos */
    public function mostrar(string $vista, array $datos = [], int $codigo = 200): void
    {
        Respuesta::html($this->render($vista, $datos), $codigo);
    }

    /**
     * Render con layout.
     *
     * @param array<string,mixed> $datos
     */
    public function conLayout(string $vista, string $layout = 'layout/principal', array $datos = []): string
    {
        $contenido = $this->render($vista, $datos);
        return $this->render($layout, array_merge($datos, ['contenido' => $contenido]));
    }

    private function rutaVista(string $vista): string
    {
        // Solo caracteres seguros: corta cualquier intento de
        // atravesar directorios con ../
        $limpia = preg_replace('#[^a-zA-Z0-9_/\-]#', '', $vista) ?? '';
        $limpia = str_replace('..', '', $limpia);

        return $this->directorioVistas . DIRECTORY_SEPARATOR
             . str_replace('/', DIRECTORY_SEPARATOR, $limpia) . '.php';
    }

    // =================================================================
    // Ayudantes disponibles dentro de las vistas
    // =================================================================

    /** Escape para contenido HTML. Es el que se usa el 95% de las veces. */
    public static function e(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Escape para dentro de un atributo. */
    public static function attr(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Escape para incrustar datos en JavaScript. */
    /**
     * Nonce de la politica de seguridad, listo para un <script>.
     *
     * Sin este atributo el navegador NO ejecuta el bloque: la politica
     * solo admite scripts firmados. Todo <script> en linea de una vista
     * tiene que llevarlo.
     */
    public static function nonce(): string
    {
        return \CashMan\Infraestructura\Seguridad\Csp::nonce();
    }

    public static function js(mixed $valor): string
    {
        return json_encode(
            $valor,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * HTML sin escapar.
     *
     * Solo para contenido que el propio sistema generó. Nunca, bajo
     * ninguna circunstancia, con datos que vengan del usuario.
     */
    public static function crudo(string $html): string
    {
        return $html;
    }

    public static function csrf(string $proposito = 'global'): string
    {
        return Csrf::campo($proposito);
    }

    public static function tokenCsrf(string $proposito = 'global'): string
    {
        return Csrf::token($proposito);
    }

    /** Codifica un id para la URL. */
    public static function hid(int $id, string $espacio = 'general'): string
    {
        return Hashid::codificar($id, $espacio);
    }

    /** Formatea centavos como moneda. */
    public static function money(int $centavos, string $simbolo = '$'): string
    {
        $signo = $centavos < 0 ? '-' : '';
        $abs   = abs($centavos);
        return $signo . $simbolo . number_format(intdiv($abs, 100), 0, '.', ',')
             . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function fecha(?string $iso, string $formato = 'd/m/Y H:i'): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }
        try {
            return (new \DateTimeImmutable($iso))->format($formato);
        } catch (\Throwable) {
            return '—';
        }
    }

    /** "hace 5 minutos" */
    public static function hace(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }
        try {
            $d = (new \DateTimeImmutable($iso))->getTimestamp();
        } catch (\Throwable) {
            return '—';
        }

        $seg = time() - $d;

        return match (true) {
            $seg < 10      => 'ahora mismo',
            $seg < 60      => "hace {$seg} s",
            $seg < 3600    => 'hace ' . intdiv($seg, 60) . ' min',
            $seg < 86400   => 'hace ' . intdiv($seg, 3600) . ' h',
            $seg < 604800  => 'hace ' . intdiv($seg, 86400) . ' d',
            default        => self::fecha($iso, 'd/m/Y'),
        };
    }
}
