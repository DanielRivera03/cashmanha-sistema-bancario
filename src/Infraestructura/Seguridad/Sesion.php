<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Seguridad;

use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * Gestión de sesión endurecida.
 *
 * Corrige del sistema anterior:
 *   - Sin session_regenerate_id() → fijación de sesión. El id previo al
 *     login seguía siendo válido después de autenticarse.
 *   - Cookies sin HttpOnly, Secure ni SameSite.
 *   - Sin caducidad por inactividad ni absoluta.
 *   - Contraseña en claro dentro de $_SESSION.
 *
 * Además registra cada sesión en base de datos, lo que permite
 * revocarlas de verdad (cerrar sesión en todos los dispositivos) y
 * mostrarle al usuario dónde tiene la cuenta abierta.
 */
final class Sesion
{
    private static bool $iniciada = false;

    public static function iniciar(): void
    {
        if (self::$iniciada || session_status() === PHP_SESSION_ACTIVE) {
            self::$iniciada = true;
            return;
        }

        $segura = Config::booleano('SESION_SEGURA', false);
        $nombre = Config::obtener('SESION_NOMBRE', 'cmsid');

        // El prefijo __Host- exige Secure + path=/ + sin Domain.
        // En HTTP local el navegador descartaría la cookie, así que
        // solo se usa cuando realmente hay TLS.
        if (!$segura && str_starts_with($nombre, '__Host-')) {
            $nombre = 'cmsid';
        }

        // Endurecer antes de arrancar la sesión.
        ini_set('session.use_strict_mode', '1');   // rechaza ids no generados aquí
        ini_set('session.use_only_cookies', '1');  // nunca el id en la URL
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');   // invisible a JavaScript
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', (string) Config::entero('SESION_ABSOLUTA_SEG', 28800));

        session_set_cookie_params([
            'lifetime' => 0,          // cookie de sesión: muere al cerrar el navegador
            'path'     => '/',
            'domain'   => '',
            'secure'   => $segura,
            'httponly' => true,
            'samesite' => 'Strict',   // primera barrera contra CSRF
        ]);

        session_name($nombre);
        session_start();
        self::$iniciada = true;

        self::aplicarCaducidad();
    }

    /**
     * Fija la sesión tras un login correcto.
     *
     * session_regenerate_id(true) es lo que mata la fijación de sesión:
     * el id que el atacante pudo haber plantado deja de ser válido.
     */
    public static function autenticar(int $usuarioId, int $rolId, string $usuario): void
    {
        self::iniciar();

        // Conservar el token CSRF para no romper formularios abiertos.
        $csrf = $_SESSION['_csrf_tokens'] ?? [];

        session_regenerate_id(true);
        $_SESSION = [];

        $ahora = time();
        $_SESSION['usuario_id']       = $usuarioId;
        $_SESSION['rol_id']           = $rolId;
        $_SESSION['usuario']          = $usuario;
        $_SESSION['huella']           = self::huellaCliente();
        $_SESSION['creada_en']        = $ahora;
        $_SESSION['ultima_actividad'] = $ahora;
        $_SESSION['_csrf_tokens']     = $csrf;

        self::registrarEnBd($usuarioId);
    }

    public static function autenticado(): bool
    {
        self::iniciar();

        if (empty($_SESSION['usuario_id'])) {
            return false;
        }

        // Si la huella del cliente cambió, la cookie pudo ser robada.
        if (!hash_equals((string) ($_SESSION['huella'] ?? ''), self::huellaCliente())) {
            self::cerrar('huella_invalida');
            return false;
        }

        return true;
    }

    public static function usuarioId(): ?int
    {
        return self::autenticado() ? (int) $_SESSION['usuario_id'] : null;
    }

    public static function rolId(): ?int
    {
        return self::autenticado() ? (int) $_SESSION['rol_id'] : null;
    }

    public static function exigirAutenticado(): int
    {
        $id = self::usuarioId();
        if ($id === null) {
            throw new NoAutenticado('Se requiere iniciar sesión.');
        }
        return $id;
    }

    public static function cerrar(string $motivo = 'cierre_voluntario'): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $sid = session_id();
        if (is_string($sid) && $sid !== '') {
            try {
                BaseDatos::ejecutar(
                    'UPDATE sesiones SET revocada_en = NOW(), motivo_revocacion = ?
                      WHERE id = ? AND revocada_en IS NULL',
                    [$motivo, hash('sha256', $sid)]
                );
            } catch (\Throwable $e) {
                error_log('[Sesion] No se pudo marcar la revocación: ' . $e->getMessage());
            }
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Strict',
            ]);
        }

        session_destroy();
        self::$iniciada = false;
    }

    /** Cierra todas las sesiones del usuario en todos sus dispositivos. */
    public static function revocarTodas(int $usuarioId, string $motivo = 'revocacion_masiva'): int
    {
        return BaseDatos::ejecutar(
            'UPDATE sesiones SET revocada_en = NOW(), motivo_revocacion = ?
              WHERE usuario_id = ? AND revocada_en IS NULL',
            [$motivo, $usuarioId]
        );
    }

    // -----------------------------------------------------------------

    /**
     * Límites de caducidad según el rol del usuario.
     *
     * Un cliente y un cajero no necesitan lo mismo: el primero entra
     * desde cualquier equipo, hace una operación y se va; el segundo
     * pasa la jornada dentro del sistema, en una máquina de la
     * institución. Expulsar al cajero cada diez minutos no añade
     * seguridad —consigue que deje la sesión abierta a propósito— y
     * dejar la del cliente abierta una hora sí abre una ventana real.
     *
     * Se consultan una vez y quedan en la sesión: son datos de
     * configuración que no cambian de una petición a otra, y esto corre
     * en absolutamente todas.
     *
     * @return array{inactividad:int, maxima:int, aviso:int}
     */
    /**
     * ¿La petición viene del sondeo automático de la interfaz?
     *
     * Se reconoce por una cabecera que solo pone el motor de tiempo
     * real, y de forma redundante por el tipo de petición: un
     * EventSource se identifica por su Accept, y así queda cubierto
     * aunque alguien olvide la cabecera al añadir un canal nuevo.
     */
    private static function esSondeoAutomatico(): bool
    {
        if (($_SERVER['HTTP_X_CM_SONDEO'] ?? '') === '1') {
            return true;
        }

        // Un EventSource siempre pide text/event-stream.
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/event-stream')) {
            return true;
        }

        return false;
    }
    private static function limitesDelRol(int $usuarioId): array
    {
        if (isset($_SESSION['_limites']) && is_array($_SESSION['_limites'])) {
            return $_SESSION['_limites'];
        }

        $r = null;

        try {
            $r = BaseDatos::unaFila(
                'SELECT r.inactividad_seg, r.sesion_maxima_seg, r.aviso_previo_seg
                   FROM usuarios u
                   JOIN roles r ON r.id = u.rol_id
                  WHERE u.id = ?',
                [$usuarioId]
            );
        } catch (\Throwable) {
            // Si la base no responde, se cae a los valores de .env en
            // lugar de dejar la sesión sin caducidad ninguna.
            $r = null;
        }

        $limites = [
            'inactividad' => $r !== null
                ? (int) $r['inactividad_seg']
                : Config::entero('SESION_INACTIVIDAD_SEG', 600),
            'maxima'      => $r !== null
                ? (int) $r['sesion_maxima_seg']
                : Config::entero('SESION_ABSOLUTA_SEG', 28800),
            'aviso'       => $r !== null ? (int) $r['aviso_previo_seg'] : 60,
        ];

        $_SESSION['_limites'] = $limites;

        return $limites;
    }

    /**
     * Cuánto le queda a la sesión antes de cerrarse por inactividad.
     *
     * La interfaz lo usa para avisar antes de cortar, como hace
     * cualquier banca en línea, en vez de tirar al usuario sin más en
     * mitad de lo que estuviera haciendo.
     *
     * @return array{restan:int, aviso:int, inactividad:int}|null
     */
    public static function cuentaAtras(): ?array
    {
        if (empty($_SESSION['usuario_id'])) {
            return null;
        }

        $limite = self::limitesDelRol((int) $_SESSION['usuario_id']);
        $ultima = (int) ($_SESSION['ultima_actividad'] ?? time());

        return [
            'restan'      => max(0, $limite['inactividad'] - (time() - $ultima)),
            'aviso'       => $limite['aviso'],
            'inactividad' => $limite['inactividad'],
        ];
    }
    private static function aplicarCaducidad(): void
    {
        if (empty($_SESSION['usuario_id'])) {
            return;
        }

        $ahora  = time();
        $limite = self::limitesDelRol((int) $_SESSION['usuario_id']);

        $inactividad = $limite['inactividad'];
        $absoluta    = $limite['maxima'];

        if (isset($_SESSION['ultima_actividad'])
            && ($ahora - (int) $_SESSION['ultima_actividad']) > $inactividad) {
            self::cerrar('inactividad');
            return;
        }

        if (isset($_SESSION['creada_en'])
            && ($ahora - (int) $_SESSION['creada_en']) > $absoluta) {
            self::cerrar('caducidad_absoluta');
            return;
        }

        // El sondeo del canal en tiempo real NO cuenta como actividad.
        //
        // Esto era un agujero: la interfaz consulta /api/pulso cada
        // cinco segundos y mantiene abierta una conexión SSE. Como
        // ambas pasan por aquí, refrescaban `ultima_actividad` sin
        // parar, de modo que la sesión no caducaba por inactividad
        // mientras la pestaña siguiera abierta. Una sesión
        // desatendida en un equipo compartido quedaba viva
        // indefinidamente, que es justo lo que el temporizador
        // existe para impedir.
        //
        // Solo la navegación y las acciones del usuario cuentan.
        if (!self::esSondeoAutomatico()) {
            $_SESSION['ultima_actividad'] = $ahora;
        }

        // Rotar el id periódicamente acorta la ventana de utilidad de
        // una cookie robada.
        if (!isset($_SESSION['_rotada_en'])) {
            $_SESSION['_rotada_en'] = $ahora;
        } elseif (($ahora - (int) $_SESSION['_rotada_en']) > 900) {
            session_regenerate_id(true);
            $_SESSION['_rotada_en'] = $ahora;
            self::registrarEnBd((int) $_SESSION['usuario_id']);
        }
    }

    /**
     * Huella del cliente: user-agent + sal del servidor.
     *
     * No se incluye la IP: en móviles cambia al saltar de wifi a datos
     * y expulsaría al usuario constantemente.
     */
    private static function huellaCliente(): string
    {
        return hash(
            'sha256',
            ($_SERVER['HTTP_USER_AGENT'] ?? '')
            . '|' . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')
            . '|' . Config::obtener('SESION_SAL', '')
        );
    }

    private static function registrarEnBd(int $usuarioId): void
    {
        $sid = session_id();
        if (!is_string($sid) || $sid === '') {
            return;
        }

        try {
            $expira = (new \DateTimeImmutable())
                ->modify('+' . Config::entero('SESION_ABSOLUTA_SEG', 28800) . ' seconds');

            // Se guarda el HASH del id, nunca el id: quien lea la tabla
            // no puede secuestrar sesiones con lo que ve.
            BaseDatos::ejecutar(
                'INSERT INTO sesiones (id, usuario_id, huella_cliente, ip, user_agent, expira_en)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE ultima_actividad = NOW()',
                [
                    hash('sha256', $sid),
                    $usuarioId,
                    self::huellaCliente(),
                    ClienteIp::binaria(),
                    substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    $expira->format('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $e) {
            error_log('[Sesion] No se pudo registrar la sesión: ' . $e->getMessage());
        }
    }
}
