<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioNotificacion;
use CashMan\Http\Enrutador;
use CashMan\Http\Plantilla;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\Sesion;

abstract class ControladorBase
{
    protected Plantilla $vista;

    public function __construct(protected Enrutador $enrutador)
    {
        $this->vista = new Plantilla();
        $this->vista->compartir([
            'rutas'   => $this->enrutador,
            'usuario' => $this->usuarioActual(),
            // El clima viene de caché (15 min): no añade latencia a
            // cada carga, y si la API falla simplemente llega null.
            'clima'   => (new \CashMan\Infraestructura\Notificacion\ServicioClima())->obtener(),
        ]);
    }

    /**
     * Corta el paso a quien todavía no ha dicho de qué vive.
     *
     * Un banco no da productos sin saber el ingreso del cliente: la
     * cuota se compara contra ese ingreso para saber si va a poder
     * pagarla, y prestarle a alguien una cuota que no puede cubrir no
     * es hacerle un favor.
     *
     * SOLO SE LE EXIGE AL CLIENTE QUE PIDE PARA SÍ. Cuando quien está
     * en pantalla es un empleado capturando la solicitud de otro, el
     * perfil que importa es el del cliente —y ése lo revisa el
     * servicio con el expediente delante—, no el del cajero.
     *
     * El guardián de `index.php` ya trae a declarar a todo cliente
     * nuevo antes de dejarlo entrar a ningún lado. Esto es la segunda
     * línea: la que sigue valiendo si alguien apaga el parámetro, o
     * si se llega por una ruta directa.
     */
    protected function exigirPerfilParaProductos(): void
    {
        $u = $this->usuarioActual();

        if ($u === null || (string) ($u['rol_clave'] ?? '') !== 'cliente') {
            return;
        }

        if (\CashMan\Aplicacion\Servicio\ServicioPerfilDeclarado::puedePedirProductos((int) $u['id'])) {
            return;
        }

        $mensaje = \CashMan\Aplicacion\Servicio\ServicioPerfilDeclarado::porQueNoPuede();
        $destino = $this->enrutador->url('mi-perfil/declarar');

        if (\CashMan\Http\Respuesta::esperaJson()) {
            \CashMan\Http\Respuesta::error($mensaje, 422);
        }

        \CashMan\Http\Respuesta::redirigir($destino);
    }

    /** @return array<string,mixed>|null */
    protected function usuarioActual(): ?array
    {
        $id = Sesion::usuarioId();
        if ($id === null) {
            return null;
        }

        static $cache = null;
        if ($cache !== null && (int) $cache['id'] === $id) {
            return $cache;
        }

        $u = BaseDatos::unaFila(
            'SELECT u.id, u.uuid, u.usuario, u.correo, u.nombres, u.apellidos,
                    u.foto_perfil, u.rol_id, u.estado, u.requiere_cambio_password,
                    u.tema, COALESCE(u.segmento, "estandar") AS segmento,
                    r.clave AS rol_clave, r.nombre AS rol_nombre, r.nivel AS rol_nivel
               FROM usuarios u JOIN roles r ON r.id = u.rol_id
              WHERE u.id = ?',
            [$id]
        );

        if ($u !== null) {
            $u['nombre_completo'] = trim($u['nombres'] . ' ' . $u['apellidos']);
            $u['iniciales'] = mb_strtoupper(
                mb_substr((string) $u['nombres'], 0, 1) . mb_substr((string) $u['apellidos'], 0, 1)
            );
            $u['sin_leer'] = (new ServicioNotificacion())->sinLeer($id);
            $u['permisos'] = Autorizacion::permisosActuales();

            /* Si lleva cartera. No basta el permiso para decidirlo:
               `cliente.ver_expediente` lo tienen tambien gerencia y
               atencion, y a ellos la pantalla de cartera les saldria
               vacia. Lo que cuenta es tener clientes asignados. */
            $u['lleva_cartera'] = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM usuarios WHERE banquero_id = ?',
                [$id]
            ) > 0;
            $u['mensajes_sin_leer'] = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM mensajes
                  WHERE destinatario_id = ? AND leido_en IS NULL AND archivado_dest = 0',
                [$id]
            );
            $cache = $u;
        }

        return $u;
    }

    /**
     * Renderiza una vista dentro del layout Chrev (el del sistema base).
     *
     * Estas páginas NO se guardan en caché, y no es un detalle de
     * rendimiento sino tres problemas a la vez:
     *
     *  1. Llevan datos de una persona concreta: saldos, movimientos,
     *     nombres. En una computadora compartida, pulsar «atrás» después
     *     de cerrar sesión mostraba la página del usuario anterior.
     *
     *  2. Llevan un token CSRF de un solo uso. Una copia en caché trae
     *     un token ya gastado y la siguiente acción falla con «token
     *     inválido» sin motivo aparente.
     *
     *  3. Al desplegar un cambio, el navegador podría seguir sirviendo
     *     la versión vieja del HTML.
     *
     * El `.htaccess` ya pone esta misma cabecera para todo `.php`; esto
     * la repite desde PHP a propósito, para que el sistema siga
     * protegido si se despliega sin `mod_headers` o sin ese archivo.
     *
     * Las hojas de estilo y los scripts sí se cachean: van con `?v=` y
     * cambian de dirección cuando cambian de contenido.
     */
    protected function render(string $vista, array $datos = [], int $codigo = 200): void
    {
        $datos['titulo'] ??= 'CashMan H.A.';

        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, private');
            header('Pragma: no-cache');
        }

        /* El resultado de la acción anterior.
         *
         * Tras una escritura sin JavaScript se redirige con el mensaje
         * en la URL (`?ok=` o `?e=`). Nadie lo pintaba, así que la
         * operación salía bien y la pantalla volvía en silencio.
         *
         * El texto viene de la URL, de modo que cualquiera puede poner
         * ahí lo que quiera: se escapa antes de imprimirlo y se recorta. */
        $datos['avisoUrl'] ??= $this->avisoDeLaUrl();

        Respuesta::html($this->vista->conLayout($vista, 'layout/chrev', $datos), $codigo);
    }

    /** El aviso que viaja en la URL tras un redirect, ya escapado. */
    protected function avisoDeLaUrl(): string
    {
        $ok    = trim((string) ($_GET['ok'] ?? ''));
        $error = trim((string) ($_GET['e'] ?? ''));

        $texto = $ok !== '' ? $ok : $error;

        if ($texto === '') {
            return '';
        }

        return '<div class="alert alert-' . ($ok !== '' ? 'success' : 'danger')
            . '" role="status">'
            . htmlspecialchars(mb_substr($texto, 0, 220), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</div>';
    }

    /** Renderiza sin layout (errores). */
    protected function renderPlano(string $vista, array $datos = [], int $codigo = 200): void
    {
        Respuesta::html($this->vista->render($vista, $datos), $codigo);
    }

    /**
     * Renderiza una pantalla pública (ingresar, recuperar, restablecer)
     * dentro del marco de acceso, que usa el mismo tema que el resto
     * del sistema.
     */
    protected function renderAcceso(string $vista, array $datos = [], int $codigo = 200): void
    {
        $datos['titulo'] ??= 'CashMan H.A.';

        // Estas páginas llevan el token CSRF dentro y cambian de
        // estado: una copia guardada en caché deja al usuario con un
        // token ya gastado o con una versión antigua del formulario.
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, private');
            header('Pragma: no-cache');
        }

        Respuesta::html($this->vista->conLayout($vista, 'layout/acceso', $datos), $codigo);
    }

    /**
     * Responde JSON a las peticiones del navegador por fetch, y una
     * redirección normal a las que llegan por envío de formulario.
     *
     * Sin esto, enviar el formulario sin JavaScript —o con el
     * JavaScript aún sin cargar— deja al usuario mirando el JSON en
     * crudo. El formulario tiene que seguir funcionando solo con HTML:
     * es la puerta de entrada al sistema.
     *
     * @param array<string,mixed> $extra
     */
    protected function responderSegunCliente(
        bool $ok,
        string $mensaje,
        string $destino,
        mixed $datos = null,
        array $extra = [],
        int $codigoError = 400
    ): void {
        if ($this->esperaJson()) {
            if ($ok) {
                Respuesta::exito($datos, $mensaje, $extra);
            }
            Respuesta::error($mensaje, $codigoError, $extra);
            return;
        }

        $separador = str_contains($destino, '?') ? '&' : '?';

        Respuesta::redirigir(
            $destino . $separador
            . ($ok ? 'ok=' : 'e=') . rawurlencode($mensaje)
        );
    }
    protected function url(string $ruta = '/'): string
    {
        return $this->enrutador->url($ruta);
    }

    /** Lee un campo POST saneado. */
    protected function post(string $clave, string $porDefecto = ''): string
    {
        $v = $_POST[$clave] ?? $porDefecto;
        return is_string($v) ? trim($v) : $porDefecto;
    }

    protected function get(string $clave, string $porDefecto = ''): string
    {
        $v = $_GET[$clave] ?? $porDefecto;
        return is_string($v) ? trim($v) : $porDefecto;
    }

    protected function postEntero(string $clave, int $porDefecto = 0): int
    {
        return (int) ($_POST[$clave] ?? $porDefecto);
    }

    /** Decodifica un identificador de URL. Devuelve 0 si es inválido. */
    protected function idDesde(string $codigo, string $espacio): int
    {
        return Hashid::decodificar($codigo, $espacio) ?? 0;
    }

    protected function esperaJson(): bool
    {
        return Respuesta::esperaJson();
    }
}
