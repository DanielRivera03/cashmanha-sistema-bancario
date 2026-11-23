<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Seguridad;

/**
 * Política de seguridad de contenido.
 *
 * El `.htaccess` declaraba `script-src 'self'` sin `'unsafe-inline'`, lo
 * cual es lo correcto para un sistema que mueve dinero: así un texto
 * inyectado en cualquier campo no puede ejecutarse como código.
 *
 * El problema es que las vistas de este sistema llevan su JavaScript en
 * bloques `<script>` dentro del propio HTML, y esa política los bloquea
 * TODOS. En el navegador no se ve ningún error en la página: los
 * formularios simplemente dejan de responder. Eso era lo que pasaba con
 * el medidor de contraseñas, con el botón del código de verificación y
 * con la transferencia entera.
 *
 * La salida no es rebajar la política a `'unsafe-inline'` —eso devuelve
 * el agujero que la política existe para cerrar— sino firmar los
 * scripts propios: cada petición genera un número de un solo uso, la
 * cabecera lo declara y cada `<script>` del sistema lo lleva. El
 * navegador ejecuta los que llevan el número y rechaza cualquier otro,
 * que es justo lo que se quería.
 *
 * El nonce tiene que ser imprevisible y distinto en cada respuesta: uno
 * fijo o adivinable equivale a no tener política.
 */
final class Csp
{
    private static ?string $nonce = null;

    /** Nonce de esta petición. Se genera una sola vez. */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = base64_encode(random_bytes(18));
        }

        return self::$nonce;
    }

    /**
     * La política, ya con el nonce dentro.
     *
     * `connect-src` incluye a la pasarela porque el formulario de tarjeta
     * de Stripe habla con sus servidores; sin eso el cobro no se puede
     * confirmar. `frame-src` por el iframe en el que Stripe monta el
     * campo de la tarjeta, que es justo lo que evita que el número pase
     * por este servidor.
     */
    public static function politica(): string
    {
        $nonce = self::nonce();

        return implode('; ', [
            "default-src 'self'",

            /* Sin `strict-dynamic`, y la razón importa.

               Con esa palabra, los navegadores modernos IGNORAN la lista
               de orígenes —incluido `'self'`— y solo ejecutan lo que
               lleve nonce o lo que cargue un script ya autorizado. Eso
               dejaba fuera a los `<script src>` del sistema, que no lo
               llevan: `base.js` no llegaba a ejecutarse y el resto de la
               página se caía con él.

               Así, `'self'` autoriza los archivos propios y el nonce
               autoriza los bloques en línea. Lo que sigue sin poder
               ejecutarse es un script inyectado por un tercero, que es de
               lo que se trata. */
            "script-src 'self' 'nonce-{$nonce}' https://js.stripe.com",

            // Los estilos en línea se conservan: las vistas llevan
            // `style=` en muchos sitios y, a diferencia de un script, un
            // estilo inyectado no ejecuta código.
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",

            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: blob:",
            "connect-src 'self' https://api.stripe.com",
            "frame-src https://js.stripe.com https://hooks.stripe.com",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }

    /** Envía la cabecera. Debe llamarse antes de cualquier salida. */
    public static function enviar(): void
    {
        if (headers_sent()) {
            return;
        }

        header('Content-Security-Policy: ' . self::politica());
    }

    /** Atributo listo para pegar en un `<script>`. */
    public static function atributo(): string
    {
        return 'nonce="' . htmlspecialchars(self::nonce(), ENT_QUOTES, 'UTF-8') . '"';
    }
}
