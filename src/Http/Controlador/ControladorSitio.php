<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\LimitadorTasa;

/**
 * Sitio público.
 *
 * La portada del sistema era la pantalla de acceso: quien llegaba sin
 * cuenta se encontraba un formulario de usuario y contraseña y nada que
 * explicara qué es la institución ni qué ofrece. Esto es lo que ve
 * cualquiera antes de entrar —productos, tasas, quiénes somos— con el
 * acceso a la banca en línea como una acción más, no como la única.
 *
 * Tres reglas que se respetan en todo el módulo:
 *
 * 1. Ninguna página exige estar autenticado. La sesión existe de todos
 *    modos —el controlador frontal la arranca en cada petición—, pero
 *    aquí no se mira quién es nadie.
 *
 * 2. Del catálogo solo salen los campos publicables de los productos
 *    marcados como públicos. Las cuentas técnicas —la de la pasarela—
 *    son contabilidad interna y no se anuncian.
 *
 * 3. El formulario de contacto es el único punto de escritura y lleva
 *    tres frenos: token CSRF, límite por IP y un campo señuelo. Es un
 *    formulario abierto a internet.
 */
final class ControladorSitio extends ControladorBase
{
    /** Buzón al que llegan los mensajes del formulario de contacto. */
    private const BUZON = 'contacto@cashmanha.sv';

    public function inicio(array $parametros = []): void
    {
        $this->renderSitio('sitio/inicio', [
            'titulo'      => 'CashMan H.A. | Banca para toda la vida',
            'descripcion' => 'Cuentas de ahorro, cuenta corriente, créditos personales '
                           . 'e hipotecarios y tarjetas de crédito en El Salvador.',
            'seccion'     => 'inicio',
            'productos'   => $this->catalogo(),
            'cifras'      => $this->cifras(),
        ]);
    }

    public function personas(array $parametros = []): void
    {
        $this->renderSitio('sitio/personas', [
            'titulo'      => 'Banca personal | CashMan H.A.',
            'descripcion' => 'Ahorro, cuenta corriente, créditos y tarjetas para personas.',
            'seccion'     => 'personas',
            'productos'   => $this->catalogo('persona'),
        ]);
    }

    public function empresas(array $parametros = []): void
    {
        $this->renderSitio('sitio/empresas', [
            'titulo'      => 'Banca de empresas | CashMan H.A.',
            'descripcion' => 'Cuentas empresariales, financiamiento y recaudación para su negocio.',
            'seccion'     => 'empresas',
            'productos'   => $this->catalogo('empresa'),
        ]);
    }

    public function nosotros(array $parametros = []): void
    {
        $this->renderSitio('sitio/nosotros', [
            'titulo'      => 'Quiénes somos | CashMan H.A.',
            'descripcion' => 'CashMan H.A. El Salvador, S.A. de C.V. — institución bancaria '
                           . 'domiciliada en San Salvador.',
            'seccion'     => 'nosotros',
        ]);
    }

    public function sucursales(array $parametros = []): void
    {
        /* Las agencias salían de un arreglo escrito dentro de la
           vista, porque no había tabla de sucursales. Ahora la hay, y
           con ella el número de ventanillas de cada una: así la página
           pública dice lo mismo que el sistema sabe, y una agencia
           nueva aparece sola. */
        $agencias = BaseDatos::todas(
            'SELECT s.nombre, s.direccion, s.departamento, s.horario,
                    s.telefono, s.tiene_atm, s.es_central,
                    (SELECT COUNT(*) FROM cajas c
                      WHERE c.sucursal_id = s.id AND c.activa = 1) AS ventanillas
               FROM sucursales s
              WHERE s.activa = 1
              ORDER BY s.es_central DESC, s.departamento, s.nombre'
        );

        $this->renderSitio('sitio/sucursales', [
            'titulo'      => 'Sucursales y cajeros | CashMan H.A.',
            'descripcion' => 'Ubicaciones, horarios y canales de atención.',
            'seccion'     => 'sucursales',
            'agencias'    => $agencias,
        ]);
    }

    public function preguntas(array $parametros = []): void
    {
        $this->renderSitio('sitio/preguntas', [
            'titulo'      => 'Preguntas frecuentes | CashMan H.A.',
            'descripcion' => 'Respuestas sobre cuentas, créditos, tarjetas y banca en línea.',
            'seccion'     => 'preguntas',
        ]);
    }

    public function contacto(array $parametros = []): void
    {
        // Esta página lleva un token CSRF dentro, así que no se guarda en
        // caché: una copia servida a otro visitante traería un token que
        // no es suyo y el envío fallaría.
        $this->renderSitio('sitio/contacto', [
            'titulo'      => 'Contacto | CashMan H.A.',
            'descripcion' => 'Escríbanos o visite una de nuestras agencias.',
            'seccion'     => 'contacto',
            'csrf'        => Csrf::token('contacto'),
            'correoVivo'  => Correo::habilitado(),
        ], 200, false);
    }

    /**
     * Recibe el formulario de contacto.
     *
     * El token CSRF y el origen los verifica el middleware de la ruta.
     * Aquí quedan el señuelo, el límite por IP y la validación.
     */
    public function enviarContacto(array $parametros = []): void
    {
        $destino = $this->enrutador->url('contacto');
        $ip      = ClienteIp::obtener();

        // Campo señuelo: está fuera de la vista, de modo que quien lo
        // rellena es un robot. Se responde que todo fue bien para no
        // enseñarle cómo esquivar el filtro.
        if (trim((string) $this->post('sitio_web')) !== '') {
            Auditoria::registrar('sitio.contacto_senuelo', Auditoria::DENEGADO, null,
                'sitio', '', ['ip' => $ip]);

            $this->responderSegunCliente(true,
                'Gracias por escribirnos. Le responderemos a la brevedad.', $destino);
            return;
        }

        // Cinco mensajes por hora desde la misma dirección. Suficiente
        // para quien se equivoca y escribe de nuevo, poco para inundar
        // el buzón.
        $limite = (new LimitadorTasa(5, 3600, 3600))->intentar('sitio:contacto:' . $ip);

        if (!$limite['permitido']) {
            $this->responderSegunCliente(false,
                'Ha enviado varios mensajes seguidos. Espere un momento o llámenos al 2222-0000.',
                $destino, null, [], 429);
            return;
        }

        $nombre  = trim((string) $this->post('nombre'));
        $correo  = trim((string) $this->post('correo'));
        $asunto  = trim((string) $this->post('asunto'));
        $mensaje = trim((string) $this->post('mensaje'));

        $faltan = [];
        if (mb_strlen($nombre) < 3)                               { $faltan[] = 'su nombre'; }
        if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) { $faltan[] = 'un correo válido'; }
        if (mb_strlen($mensaje) < 15)                             { $faltan[] = 'un mensaje de al menos 15 caracteres'; }

        if ($faltan !== []) {
            $this->responderSegunCliente(false,
                'Falta ' . implode(', ', $faltan) . '.', $destino, null, [], 422);
            return;
        }

        if (!Correo::habilitado()) {
            $this->responderSegunCliente(false,
                'En este momento no podemos enviar su mensaje. Escríbanos a '
                . self::BUZON . ' o llame al 2222-0000.',
                $destino, null, [], 503);
            return;
        }

        $texto = "Mensaje recibido desde el sitio público.\n\n"
               . "Nombre:  {$nombre}\n"
               . "Correo:  {$correo}\n"
               . "Asunto:  " . ($asunto !== '' ? $asunto : '(sin asunto)') . "\n"
               . "IP:      {$ip}\n"
               . "Fecha:   " . date('d/m/Y H:i') . "\n\n"
               . str_repeat('-', 50) . "\n"
               . $mensaje . "\n";

        $e    = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = '<p><b>Mensaje desde el sitio público</b></p>'
              . '<p><b>Nombre:</b> ' . $e($nombre) . '<br>'
              . '<b>Correo:</b> ' . $e($correo) . '<br>'
              . '<b>Asunto:</b> ' . $e($asunto !== '' ? $asunto : '(sin asunto)') . '<br>'
              . '<b>IP:</b> ' . $e($ip) . '</p><hr>'
              . '<p>' . nl2br($e($mensaje)) . '</p>';

        // El asunto lo compone el sistema, nunca el visitante tal cual:
        // un salto de línea dentro de su texto permitiría inyectar
        // cabeceras en el mensaje.
        $enviado = (new Correo())->enviar(
            self::BUZON,
            'Contacto del sitio — ' . mb_substr($nombre, 0, 60),
            $html,
            $texto
        );

        if (!$enviado) {
            $this->responderSegunCliente(false,
                'No pudimos enviar su mensaje. Escríbanos a ' . self::BUZON
                . ' o llame al 2222-0000.',
                $destino, null, [], 503);
            return;
        }

        Auditoria::registrar('sitio.contacto_enviado', Auditoria::EXITO, null,
            'sitio', '', ['correo' => $correo, 'ip' => $ip]);

        $this->responderSegunCliente(true,
            'Gracias por escribirnos, ' . $nombre . '. Le responderemos a la brevedad.',
            $destino);
    }

    // -----------------------------------------------------------------

    /**
     * Catálogo publicable, agrupado por tipo.
     *
     * Solo se seleccionan los campos que se publican. Da igual lo que
     * añada el futuro a la tabla: no llega al sitio sin tocar esto.
     *
     * @return array<string, array<int, array<string,mixed>>>
     */
    private function catalogo(?string $mercado = null): array
    {
        /* Filtro por mercado: la pagina de personas no muestra la
           tarjeta empresarial, ni la de empresas las personales. Lo
           marcado 'ambos' sale en las dos. Sin filtro —la portada— salen
           todos. */
        $where  = 'activo = 1 AND publico = 1';
        $params = [];

        if (in_array($mercado, ['persona', 'empresa'], true)) {
            $where   .= ' AND mercado IN (?, "ambos")';
            $params[] = $mercado;
        }

        /* Todos los campos PUBLICABLES del producto, para que el sitio
           pueda distinguir una tarjeta de otra (su linea, su ingreso
           minimo, su membresia, sus beneficios, su marca) y un credito
           de otro (su rango de monto y de plazo). Nada se inventa en la
           vista: todo sale de aqui, de lo que esta registrado. */
        $filas = BaseDatos::todas(
            'SELECT codigo, nombre, tipo, descripcion,
                    tasa_interes_bp, tasa_min_bp, tasa_max_bp, saldo_minimo_cent,
                    ingreso_minimo_cent, linea_desde_cent, membresia_anual_cent,
                    beneficios, nivel, marca_emision,
                    monto_minimo_cent, monto_maximo_cent, plazo_minimo, plazo_maximo
               FROM productos
              WHERE ' . $where . '
              ORDER BY FIELD(tipo, "ahorro", "corriente", "credito", "tarjeta"),
                       nivel, tasa_interes_bp',
            $params
        );

        $porTipo = ['ahorro' => [], 'corriente' => [], 'credito' => [], 'tarjeta' => []];

        foreach ($filas as $f) {
            $tipo = (string) $f['tipo'];
            if (!isset($porTipo[$tipo])) { continue; }

            // Los puntos base se guardan enteros (350 = 3.50 %). Se
            // formatean aquí y no en cada vista para que las páginas que
            // lo muestran digan lo mismo.
            $f['tasa'] = number_format((int) $f['tasa_interes_bp'] / 100, 2) . ' %';

            /* El rango de tasa, cuando el producto lo tiene. Solo se
               arma si hay un rango de verdad. */
            $min = (int) ($f['tasa_min_bp'] ?? 0);
            $max = (int) ($f['tasa_max_bp'] ?? 0);
            $f['tasa_rango'] = ($min > 0 && $max > 0 && $max > $min)
                ? number_format($min / 100, 2) . ' % – ' . number_format($max / 100, 2) . ' %'
                : '';

            /* Los beneficios vienen uno por linea; se parten aqui para
               que la vista solo los recorra. */
            $f['beneficios_lista'] = array_values(array_filter(array_map(
                'trim',
                preg_split('/\r\n|\r|\n/', (string) ($f['beneficios'] ?? '')) ?: []
            )));

            $porTipo[$tipo][] = $f;
        }

        return $porTipo;
    }

    /**
     * Cifras de la institución para la portada.
     *
     * Son agregados: cuántos clientes, cuántas cuentas. No identifican a
     * nadie ni permiten deducir un saldo individual.
     *
     * @return array<string,int>
     */
    private function cifras(): array
    {
        $f = BaseDatos::unaFila(
            'SELECT
               (SELECT COUNT(*) FROM usuarios u
                  JOIN roles r ON r.id = u.rol_id
                 WHERE r.clave = "cliente")                          AS clientes,
               (SELECT COUNT(*) FROM productos
                 WHERE activo = 1 AND publico = 1)                   AS productos,
               (SELECT COUNT(*) FROM creditos WHERE estado = "vigente") AS creditos,
               (SELECT COUNT(*) FROM cuentas WHERE es_tecnica = 0)   AS cuentas,
               (SELECT COUNT(*) FROM sucursales WHERE activa = 1)    AS sucursales'
        ) ?? [];

        /* Las agencias salen de la tabla y no de un número escrito en
           la plantilla: la portada traía un «6» a mano, que es justo lo
           que esta página dice no hacer con las tasas. El día que abra
           la séptima, el sitio seguiría diciendo seis. */
        return [
            'clientes'   => (int) ($f['clientes']   ?? 0),
            'productos'  => (int) ($f['productos']  ?? 0),
            'creditos'   => (int) ($f['creditos']   ?? 0),
            'cuentas'    => (int) ($f['cuentas']    ?? 0),
            'sucursales' => (int) ($f['sucursales'] ?? 0),
        ];
    }

    /**
     * Renderiza dentro del marco público.
     *
     * @param array<string,mixed> $datos
     * @param bool $cacheable Falso en las páginas que llevan token.
     */
    private function renderSitio(
        string $vista,
        array $datos = [],
        int $codigo = 200,
        bool $cacheable = true
    ): void {
        if (!headers_sent()) {
            header($cacheable
                ? 'Cache-Control: public, max-age=600'
                : 'Cache-Control: no-store, no-cache, must-revalidate, private');
        }

        Respuesta::html($this->vista->conLayout($vista, 'layout/publico', $datos), $codigo);
    }
}
