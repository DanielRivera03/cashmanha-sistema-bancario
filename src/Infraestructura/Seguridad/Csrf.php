<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Seguridad;

/**
 * Protección contra Cross-Site Request Forgery.
 *
 * El sistema anterior tenía CERO tokens CSRF: cualquier sitio web podía
 * hacer que el navegador de un cliente autenticado ejecutara una
 * transferencia con un formulario oculto.
 *
 * Aquí hay tres capas:
 *   1. Token sincronizado por sesión, comparado en tiempo constante.
 *   2. Cookie SameSite=Strict (se configura en Sesion).
 *   3. Verificación de la cabecera Origin / Referer.
 *
 * Una sola capa se puede eludir en algún navegador o configuración;
 * las tres juntas, no.
 */
final class Csrf
{
    private const CLAVE_SESION = '_csrf_tokens';

    /* Cuántos tokens vivos se guardan a la vez.
     *
     * Eran 12, y se quedaba corto de verdad: cada pantalla emite uno o
     * varios, y con media docena de pestañas abiertas —algo normal
     * trabajando— los tokens nuevos iban expulsando a los viejos. El
     * formulario de una pestaña que llevaba un rato quieta dejaba de
     * valer sin que nadie lo tocara, y salía «el token de seguridad
     * venció» en una pantalla recién abierta.
     *
     * Son 64 bytes cada uno: guardar sesenta no cuesta nada, y la purga
     * por antigüedad sigue siendo la que de verdad limpia. */
    private const MAX_TOKENS   = 60;
    private const VIGENCIA_SEG = 7200;

    /**
     * Genera un token.
     *
     * Se emite uno por formulario y se guarda una ventana de los últimos
     * tokens, para que varias pestañas abiertas no se invaliden entre sí.
     */
    public static function token(string $proposito = 'global'): string
    {
        self::purgar();

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::CLAVE_SESION][$token] = [
            'proposito' => $proposito,
            'creado'    => time(),
        ];

        // Conservar solo los más recientes.
        if (count($_SESSION[self::CLAVE_SESION]) > self::MAX_TOKENS) {
            $_SESSION[self::CLAVE_SESION] = array_slice(
                $_SESSION[self::CLAVE_SESION],
                -self::MAX_TOKENS,
                null,
                true
            );
        }

        return $token;
    }

    /** Propósito del último token consumido en esta petición. */
    private static ?string $propositoConsumido = null;

    /**
     * Emite un reemplazo del token recién consumido.
     *
     * Devuelve null si en esta petición no se validó ninguno, de
     * modo que las respuestas que no gastaron token no emitan uno
     * nuevo sin motivo.
     */
    public static function renovar(): ?string
    {
        if (self::$propositoConsumido === null) {
            return null;
        }
        return self::token(self::$propositoConsumido);
    }

    /**
     * Propósito del token que se acaba de reemplazar.
     *
     * El navegador lo necesita para sustituir solo los campos de ese
     * propósito: un token de «tarjeta» no vale para el formulario de
     * «perfil» que haya en la misma pantalla, y escribirlo ahí lo
     * rompía en silencio.
     */
    public static function propositoRenovado(): ?string
    {
        return self::$propositoConsumido;
    }

    /** Valida y consume el token (un solo uso). */
    public static function validar(?string $token, string $proposito = 'global'): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        self::purgar();
        $almacenados = $_SESSION[self::CLAVE_SESION] ?? [];

        foreach ($almacenados as $guardado => $meta) {
            if (!hash_equals((string) $guardado, $token)) {
                continue;
            }
            if (($meta['proposito'] ?? '') !== $proposito) {
                return false;
            }
            // Un solo uso: evita la reutilización del token.
            unset($_SESSION[self::CLAVE_SESION][$guardado]);

            // Se anota el propósito para poder devolver un token
            // nuevo en la respuesta: una pantalla que hace varias
            // peticiones seguidas (activar 2FA, subir documentos)
            // se quedaría sin token válido tras la primera.
            self::$propositoConsumido = $proposito;
            return true;
        }

        return false;
    }

    /**
     * Comprueba que la petición venga del propio sitio.
     *
     * Segunda barrera, independiente del token: aunque un atacante
     * lograra filtrar un token, no puede falsificar el Origin.
     */
    public static function origenValido(string $hostEsperado): bool
    {
        $origen = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ($origen === '') {
            // Sin Origin, caer al Referer.
            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            if ($referer === '') {
                // Ni Origin ni Referer en una petición de escritura: sospechoso.
                return false;
            }
            $origen = $referer;
        }

        $host = parse_url($origen, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        return hash_equals(strtolower($hostEsperado), strtolower($host));
    }

    /** Campo oculto listo para insertar en un formulario. */
    public static function campo(string $proposito = 'global'): string
    {
        $token = self::token($proposito);
        return sprintf(
            '<input type="hidden" name="_csrf" value="%s">',
            htmlspecialchars($token, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        );
    }

    private static function purgar(): void
    {
        if (!isset($_SESSION[self::CLAVE_SESION]) || !is_array($_SESSION[self::CLAVE_SESION])) {
            $_SESSION[self::CLAVE_SESION] = [];
            return;
        }

        $limite = time() - self::VIGENCIA_SEG;
        foreach ($_SESSION[self::CLAVE_SESION] as $token => $meta) {
            if (($meta['creado'] ?? 0) < $limite) {
                unset($_SESSION[self::CLAVE_SESION][$token]);
            }
        }
    }
}
