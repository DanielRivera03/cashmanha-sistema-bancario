<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Notificacion;

use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Config;

/**
 * Envío de correo por SMTP, sin dependencias externas.
 *
 * Habla SMTP directamente sobre sockets: soporta STARTTLS, TLS
 * implícito, autenticación LOGIN y PLAIN. Funciona con Mailtrap,
 * Gmail, Brevo, Papercut o cualquier servidor estándar.
 *
 * Detalles que importan y que suelen hacerse mal:
 *
 *   - Las cabeceras se sanean: un salto de línea en el asunto o en el
 *     destinatario permite inyectar cabeceras y convertir el sistema
 *     en un relé de spam.
 *   - El contenido se codifica en quoted-printable, así que los
 *     acentos no se rompen ni se cortan las líneas largas.
 *   - El servidor nunca ve credenciales en los logs.
 *   - Si el envío falla, NO rompe la operación de negocio: se registra
 *     y se sigue. Un correo caído no debe impedir una transferencia.
 */
final class Correo
{
    private const CRLF = "\r\n";

    private string $host;
    private int    $puerto;
    private string $usuario;
    private string $clave;
    private string $cifrado;
    private string $desde;
    private string $desdeNombre;
    private int    $timeout = 20;

    /** @var resource|null */
    private $conexion = null;

    /** @var array<int,string> */
    private array $traza = [];

    public function __construct()
    {
        $this->host        = Config::obtener('SMTP_HOST', '127.0.0.1') ?? '127.0.0.1';
        $this->puerto      = Config::entero('SMTP_PUERTO', 25);
        $this->usuario     = Config::obtener('SMTP_USUARIO', '') ?? '';
        $this->clave       = Config::obtener('SMTP_CLAVE', '') ?? '';
        $this->cifrado     = strtolower(Config::obtener('SMTP_CIFRADO', '') ?? '');
        $this->desde       = Config::obtener('SMTP_DESDE', 'no-responder@localhost') ?? '';
        $this->desdeNombre = Config::obtener('SMTP_DESDE_NOMBRE', 'CashMan H.A.') ?? '';
    }

    /**
     * Mordaza para el proceso en curso.
     *
     * Las pruebas ejercitan servicios que avisan al cliente: la
     * compensación le manda un correo cada vez que toma saldo, y la
     * batería la corre nueve veces seguidas. Con `correo.copia_oculta`
     * configurada, esos avisos de clientes inventados —«Prueba
     * Compensacion», cuentas con sufijo al azar— aterrizan en una
     * bandeja real, y cada `php bin/probar.php` suelta otra tanda.
     *
     * Apagarlo desde `.env` no sirve aquí: hay que acordarse de
     * encenderlo después, y el día que a alguien se le olvide el
     * sistema deja de avisar a clientes de verdad sin que nada lo
     * cante. Esto es del proceso y muere con él.
     *
     * `bin/probar.php` lo activa antes de la primera prueba.
     */
    private static bool $silenciado = false;

    /** Nada sale de este proceso hasta que termine. */
    public static function silenciar(): void
    {
        self::$silenciado = true;
    }

    public static function silenciado(): bool
    {
        return self::$silenciado;
    }

    public static function habilitado(): bool
    {
        if (self::$silenciado) {
            return false;
        }

        if (!Config::booleano('CORREO_HABILITADO', false)) {
            return false;
        }

        // Un servidor que pide usuario pero no tiene clave configurada
        // va a rechazar cada envío tras negociar TLS: son dos segundos
        // perdidos y una excepción por cada correo. Mejor detectarlo
        // aquí y degradar a "solo registro", que es lo que de verdad
        // está pasando.
        $usuario = Config::obtener('SMTP_USUARIO', '') ?? '';
        $clave   = Config::obtener('SMTP_CLAVE', '') ?? '';

        if ($usuario !== '' && $clave === '') {
            error_log('[Correo] SMTP_USUARIO definido sin SMTP_CLAVE: envío deshabilitado.');
            return false;
        }

        return true;
    }

    /**
     * Envía un correo HTML.
     *
     * Devuelve false en vez de lanzar: el envío de correo nunca debe
     * tumbar la operación que lo originó.
     */
    /**
     * @param list<string> $copias Direcciones en copia visible.
     * @param string $remitente Nombre con el que firma este correo
     *        concreto. Un requerimiento de cobro no debe llegar a
     *        nombre de la institución a secas: el cliente tiene que
     *        saber que viene de cobranza y a quién responderle. La
     *        dirección no cambia —es la única autenticada—, solo el
     *        nombre que se muestra.
     */
    public function enviar(
        string $para,
        string $asunto,
        string $cuerpoHtml,
        string $cuerpoTexto = '',
        string $paraNombre = '',
        array $copias = [],
        string $remitente = '',
        array $adjuntos = []
    ): bool {
        if ($remitente !== '') {
            $this->desdeNombre = $remitente;
        }

        if (!self::habilitado()) {
            error_log("[Correo] Deshabilitado. Destinatario: {$para} — Asunto: {$asunto}");
            return false;
        }

        $para   = $this->sanearDireccion($para);
        $asunto = $this->sanearCabecera($asunto);

        if ($para === '') {
            error_log('[Correo] Dirección de destinatario inválida.');
            return false;
        }

        /* Las copias.
         *
         * Se sanean igual que el destinatario —una dirección con un
         * salto de línea dentro convierte esto en un relé de spam— y se
         * descarta la que coincida con el destinatario: mandarle dos
         * veces el mismo correo a la misma persona queda mal y algunos
         * servidores lo tratan como señal de spam. */
        $copias = array_values(array_unique(array_filter(
            array_map(fn(string $c): string => $this->sanearDireccion($c), $copias),
            fn(string $c): bool => $c !== '' && strcasecmp($c, $para) !== 0
        )));

        /* La copia oculta de la casa.
         *
         * Va en `Bcc` y no en `Cc` a propósito. El cliente que recibe
         * el aviso de su propio movimiento no tiene por qué ver a qué
         * otra dirección le llegó una copia de sus datos, y quien
         * reenvíe ese correo tampoco debe arrastrar esa dirección
         * consigo.
         *
         * En el sobre SMTP sí viaja —abajo lleva su `RCPT TO`—, pero
         * NO se escribe en las cabeceras del mensaje: eso es
         * exactamente lo que distingue una copia oculta de una
         * visible.
         *
         * Se configura con el parámetro `correo.copia_oculta`, que
         * admite varias direcciones separadas por coma. Vacío, no hay
         * copia y esto no hace nada. */
        $ocultas = array_values(array_unique(array_filter(
            array_map(
                fn(string $c): string => $this->sanearDireccion(trim($c)),
                explode(',', (string) Parametros::obtener('correo.copia_oculta', ''))
            ),
            fn(string $c): bool => $c !== ''
                && strcasecmp($c, $para) !== 0
                && !in_array(strtolower($c), array_map('strtolower', $copias), true)
        )));

        if ($cuerpoTexto === '') {
            $cuerpoTexto = $this->htmlATexto($cuerpoHtml);
        }

        try {
            $this->conectar();
            $this->saludar();

            if ($this->cifrado === 'tls') {
                $this->iniciarTls();
                $this->saludar();   // EHLO de nuevo tras STARTTLS
            }

            if ($this->usuario !== '') {
                $this->autenticar();
            }

            $this->comando('MAIL FROM:<' . $this->desde . '>', [250]);
            $this->comando('RCPT TO:<' . $para . '>', [250, 251]);

            /* Cada copia necesita su propio RCPT TO.
             *
             * La cabecera `Cc:` solo sirve para que el destinatario vea
             * a quién más le llegó; quien decide a dónde va el mensaje
             * es el sobre SMTP. Sin este bucle, la copia aparecería
             * escrita en el correo pero no la recibiría nadie. */
            foreach ($copias as $cc) {
                $this->comando('RCPT TO:<' . $cc . '>', [250, 251]);
            }

            /* Las ocultas, solo en el sobre. Si una rebota, que no se
               lleve por delante el correo del cliente: el aviso de su
               transferencia tiene que llegarle aunque la copia de la
               casa falle. */
            foreach ($ocultas as $bcc) {
                try {
                    $this->comando('RCPT TO:<' . $bcc . '>', [250, 251]);
                } catch (\Throwable $e) {
                    error_log('[Correo] copia oculta rechazada (' . $bcc . '): ' . $e->getMessage());
                }
            }

            $this->comando('DATA', [354]);

            $this->escribir($this->construirMensaje(
                $para, $paraNombre, $asunto, $cuerpoHtml, $cuerpoTexto, $copias, $adjuntos
            ));
            $this->comando('.', [250]);
            $this->comando('QUIT', [221]);

            $this->cerrar();
            return true;

        } catch (\Throwable $e) {
            error_log('[Correo] Fallo al enviar a ' . $para . ': ' . $e->getMessage());
            $this->cerrar();
            return false;
        }
    }

    // -----------------------------------------------------------------
    // Protocolo SMTP
    // -----------------------------------------------------------------

    private function conectar(): void
    {
        $destino = $this->cifrado === 'ssl'
            ? 'ssl://' . $this->host . ':' . $this->puerto
            : $this->host . ':' . $this->puerto;

        $contexto = stream_context_create([
            'ssl' => [
                'verify_peer'       => Config::esProduccion(),
                'verify_peer_name'  => Config::esProduccion(),
                'allow_self_signed' => !Config::esProduccion(),
            ],
        ]);

        $conexion = @stream_socket_client(
            $destino,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $contexto
        );

        if ($conexion === false) {
            throw new \RuntimeException("No se pudo conectar a {$this->host}:{$this->puerto} — {$errstr}");
        }

        $this->conexion = $conexion;
        stream_set_timeout($this->conexion, $this->timeout);

        $this->esperar([220]);
    }

    private function saludar(): void
    {
        $nombre = Config::obtener('APP_DOMINIO', 'localhost');
        try {
            $this->comando('EHLO ' . $nombre, [250]);
        } catch (\Throwable) {
            // Servidores antiguos solo entienden HELO.
            $this->comando('HELO ' . $nombre, [250]);
        }
    }

    private function iniciarTls(): void
    {
        $this->comando('STARTTLS', [220]);

        $ok = @stream_socket_enable_crypto(
            $this->conexion,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
            | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
        );

        if ($ok !== true) {
            throw new \RuntimeException('No se pudo establecer TLS con el servidor SMTP.');
        }
    }

    private function autenticar(): void
    {
        try {
            $this->comando('AUTH LOGIN', [334]);
            $this->comando(base64_encode($this->usuario), [334]);
            $this->comando(base64_encode($this->clave), [235]);
        } catch (\Throwable) {
            // Algunos servidores solo aceptan PLAIN.
            $credencial = base64_encode("\0" . $this->usuario . "\0" . $this->clave);
            $this->comando('AUTH PLAIN ' . $credencial, [235]);
        }
    }

    /** @param array<int,int> $esperados */
    private function comando(string $orden, array $esperados): string
    {
        $this->escribir($orden . self::CRLF);
        return $this->esperar($esperados);
    }

    private function escribir(string $datos): void
    {
        if ($this->conexion === null) {
            throw new \RuntimeException('Sin conexión SMTP.');
        }
        if (@fwrite($this->conexion, $datos) === false) {
            throw new \RuntimeException('Error al escribir en el socket SMTP.');
        }
    }

    /** @param array<int,int> $esperados */
    private function esperar(array $esperados): string
    {
        if ($this->conexion === null) {
            throw new \RuntimeException('Sin conexión SMTP.');
        }

        $respuesta = '';
        while (($linea = @fgets($this->conexion, 515)) !== false) {
            $respuesta .= $linea;
            // El último renglón lleva un espacio tras el código: "250 OK"
            if (strlen($linea) < 4 || $linea[3] === ' ') {
                break;
            }
        }

        $this->traza[] = trim($respuesta);
        $codigo = (int) substr(ltrim($respuesta), 0, 3);

        if (!in_array($codigo, $esperados, true)) {
            throw new \RuntimeException(
                'SMTP respondió ' . $codigo . ' (se esperaba '
                . implode('/', $esperados) . '): ' . trim($respuesta)
            );
        }

        return $respuesta;
    }

    private function cerrar(): void
    {
        if ($this->conexion !== null) {
            @fclose($this->conexion);
            $this->conexion = null;
        }
    }

    // -----------------------------------------------------------------
    // Construcción del mensaje
    // -----------------------------------------------------------------

    /**
     * Dominio para el Message-ID.
     *
     * Tiene que ser un dominio real y relacionado con el remitente.
     * Antes salía `@localhost`, que no existe: Gmail se niega a firmar
     * con DKIM un mensaje así y el receptor lo rebota con
     * `550 5.7.30 DKIM authentication didn't pass`. Se toma del propio
     * remitente, que es lo que hace cualquier cliente de correo.
     */
    /**
     * ¿El servidor de salida es el relé de un proveedor?
     *
     * Gmail, Outlook y compañía firman con DKIM lo que sale por su
     * relé, pero solo si el mensaje parece originado allí. Cuando ya
     * trae un `Message-ID` propio, lo tratan como reenvío y lo sueltan
     * sin firma; el buzón de destino lo rechaza por no autenticado.
     *
     * Con un servidor propio pasa lo contrario: si no ponemos nosotros
     * el `Message-ID`, el mensaje sale sin él, lo cual rompe el hilo de
     * conversación en los clientes de correo y puntúa como spam.
     *
     * De ahí esta lista: decide quién lo pone.
     */
    private static function relayDeProveedor(string $host): bool
    {
        $host = strtolower(trim($host));

        foreach ([
            'gmail.com', 'googlemail.com', 'google.com',
            'outlook.com', 'office365.com', 'hotmail.com',
            'sendgrid.net', 'mailgun.org', 'amazonses.com',
            'postmarkapp.com', 'sparkpostmail.com', 'zoho.com',
        ] as $proveedor) {
            if ($host === $proveedor || str_ends_with($host, '.' . $proveedor)) {
                return true;
            }
        }

        return false;
    }

    private function dominioMensaje(): string
    {
        $arroba = strrpos($this->desde, '@');

        if ($arroba !== false) {
            $dominio = substr($this->desde, $arroba + 1);
            if ($dominio !== '' && str_contains($dominio, '.')) {
                return $dominio;
            }
        }

        $config = (string) Config::obtener('APP_DOMINIO', '');
        if ($config !== '' && str_contains($config, '.')) {
            return $config;
        }

        // Último recurso: el servidor por el que se envía.
        return $this->host;
    }
    /** @param list<string> $copias */
    private function construirMensaje(
        string $para,
        string $paraNombre,
        string $asunto,
        string $html,
        string $texto,
        array $copias = [],
        array $adjuntos = []
    ): string {
        $frontera = '=_cm_' . bin2hex(random_bytes(12));

        $de = $this->direccionConNombre($this->desde, $this->desdeNombre);
        $a  = $this->direccionConNombre($para, $paraNombre);

        $cabeceras = [
            'Date: ' . date('r'),
            'From: ' . $de,
            'To: ' . $a,
        ];

        if ($copias !== []) {
            $cabeceras[] = 'Cc: ' . implode(', ', $copias);
        }

        /* Con adjuntos la estructura cambia.
         *
         * Sin ellos basta `multipart/alternative`: dos versiones del
         * mismo contenido y el cliente elige. Con un adjunto hace falta
         * `multipart/mixed` por fuera, que contiene el alternative como
         * primera parte y cada archivo después. Mandar un adjunto
         * dentro de un `alternative` lo convierte en «otra versión del
         * mensaje» y el cliente simplemente no lo enseña. */
        $frontMixta = '=_cmx_' . bin2hex(random_bytes(12));
        $frontRel   = '=_cmr_' . bin2hex(random_bytes(12));
        $hayAdjuntos = $adjuntos !== [];

        /* El logotipo va DENTRO del mensaje, no enlazado.
         *
         * Gmail y Outlook bloquean las imágenes remotas por omisión:
         * enlazarlo dejaría un recuadro vacío donde debería estar la
         * marca en la mayoría de los clientes. Y en una instalación
         * local no hay ninguna URL pública que enlazar. */
        $incrustadas = $this->imagenesIncrustadas($html);

        $cabeceras = [
            ...$cabeceras,
            'Subject: ' . $this->codificarCabecera($asunto),
            'MIME-Version: 1.0',
            $hayAdjuntos
                ? 'Content-Type: multipart/mixed; boundary="' . $frontMixta . '"'
                : ($incrustadas !== []
                    ? 'Content-Type: multipart/related; boundary="' . $frontRel . '"'
                    : 'Content-Type: multipart/alternative; boundary="' . $frontera . '"'),
        ];

        /* El Message-ID lo pone el servidor que relaya, no nosotros.
         *
         * Aquí se generaba uno propio con el dominio del remitente:
         *
         *     Message-ID: <a1b2...@gmail.com>
         *
         * Para el relé de Gmail eso es un mensaje que ya viene
         * identificado como originado en gmail.com, es decir, algo que
         * él no generó y que solo está reenviando. Un mensaje reenviado
         * no lo firma con DKIM, y el Gmail del destinatario lo rechaza:
         *
         *     550 5.7.30 ... DKIM authentication didn't pass
         *
         * Omitiéndolo, el relé pone el suyo y firma el mensaje como
         * propio. Solo se escribe uno cuando el servidor de salida es
         * nuestro y no va a generarlo. */
        if (!self::relayDeProveedor($this->host)) {
            $cabeceras[] = 'Message-ID: <' . bin2hex(random_bytes(16))
                         . '@' . $this->dominioMensaje() . '>';
        }

        // Que una respuesta llegue a una persona y no al vacío.
        $cabeceras[] = 'Reply-To: ' . $de;

        // Las dos versiones del mensaje, que van juntas siempre.
        $alternativo = '--' . $frontera . self::CRLF
            . 'Content-Type: text/plain; charset=UTF-8' . self::CRLF
            . 'Content-Transfer-Encoding: quoted-printable' . self::CRLF . self::CRLF
            . quoted_printable_encode($texto) . self::CRLF . self::CRLF
            . '--' . $frontera . self::CRLF
            . 'Content-Type: text/html; charset=UTF-8' . self::CRLF
            . 'Content-Transfer-Encoding: quoted-printable' . self::CRLF . self::CRLF
            . quoted_printable_encode($html) . self::CRLF . self::CRLF
            . '--' . $frontera . '--' . self::CRLF;

        /* El `related`: el mensaje y las imágenes que lo acompañan.
         *
         * Tiene que ENVOLVER al alternative, no ser su hermano. Si el
         * logo fuera hermano del alternative dentro de un mixed, el
         * cliente lo enseñaría como un archivo adjunto más en lugar de
         * pintarlo dentro del mensaje. */
        $conLogo = static function (string $dentro) use ($incrustadas, $frontera, $frontRel): string {
            if ($incrustadas === []) {
                return $dentro;
            }

            $partes = '--' . $frontRel . self::CRLF
                . 'Content-Type: multipart/alternative; boundary="' . $frontera . '"'
                . self::CRLF . self::CRLF
                . $dentro . self::CRLF;

            foreach ($incrustadas as $img) {
                $partes .= '--' . $frontRel . self::CRLF
                    . 'Content-Type: ' . $img['tipo'] . '; name="' . $img['nombre'] . '"' . self::CRLF
                    . 'Content-Transfer-Encoding: base64' . self::CRLF
                    . 'Content-ID: <' . $img['cid'] . '>' . self::CRLF
                    . 'Content-Disposition: inline; filename="' . $img['nombre'] . '"'
                    . self::CRLF . self::CRLF
                    . chunk_split(base64_encode($img['datos']), 76, self::CRLF);
            }

            return $partes . '--' . $frontRel . '--' . self::CRLF;
        };

        if (!$hayAdjuntos) {
            $cuerpo = $conLogo($alternativo);
        } else {
            $cuerpo = '--' . $frontMixta . self::CRLF
                . ($incrustadas !== []
                    ? 'Content-Type: multipart/related; boundary="' . $frontRel . '"'
                    : 'Content-Type: multipart/alternative; boundary="' . $frontera . '"')
                . self::CRLF . self::CRLF
                . $conLogo($alternativo) . self::CRLF;

            foreach ($adjuntos as $a) {
                $ruta = (string) ($a['ruta'] ?? '');

                if ($ruta === '' || !is_file($ruta) || !is_readable($ruta)) {
                    error_log('[Correo] adjunto inexistente o ilegible: ' . $ruta);
                    continue;
                }

                $datos = @file_get_contents($ruta);

                if ($datos === false) {
                    continue;
                }

                // El nombre con el que se guarda, sin rutas dentro: un
                // «../..» en el nombre de un adjunto es un intento de
                // escribir donde no toca al guardarlo.
                $nombre = basename((string) ($a['nombre'] ?? basename($ruta)));
                $tipo   = (string) ($a['tipo'] ?? 'application/octet-stream');

                /* base64 en líneas de 76 caracteres.
                 *
                 * Sin partirlas, un PDF de 50 KB sería una sola línea de
                 * 68.000 caracteres. El límite de SMTP es 998 por línea
                 * y los servidores estrictos rechazan el mensaje entero.
                 */
                $cuerpo .= '--' . $frontMixta . self::CRLF
                    . 'Content-Type: ' . $this->sanearCabecera($tipo) . '; name="'
                    . $this->sanearCabecera($nombre) . '"' . self::CRLF
                    . 'Content-Transfer-Encoding: base64' . self::CRLF
                    . 'Content-Disposition: attachment; filename="'
                    . $this->sanearCabecera($nombre) . '"' . self::CRLF . self::CRLF
                    . chunk_split(base64_encode($datos), 76, self::CRLF)
                    . self::CRLF;
            }

            $cuerpo .= '--' . $frontMixta . '--' . self::CRLF;
        }

        // Un punto al inicio de línea se duplica: así lo exige SMTP,
        // porque "." solo marca el fin del mensaje.
        $cuerpo = preg_replace('/^\./m', '..', $cuerpo) ?? $cuerpo;

        return implode(self::CRLF, $cabeceras) . self::CRLF . self::CRLF . $cuerpo;
    }

    /**
     * Elimina CR/LF de una cabecera.
     *
     * Sin esto, un asunto con "\r\nBcc: victima@..." convierte el
     * sistema en un relé de spam. Es inyección de cabeceras.
     */
    private function sanearCabecera(string $valor): string
    {
        return trim(str_replace(["\r", "\n", "\0", '%0a', '%0d'], '', $valor));
    }

    private function sanearDireccion(string $correo): string
    {
        $correo = $this->sanearCabecera($correo);
        return filter_var($correo, FILTER_VALIDATE_EMAIL) ? $correo : '';
    }

    private function codificarCabecera(string $valor): string
    {
        $valor = $this->sanearCabecera($valor);

        // Solo codificar si hay caracteres fuera de ASCII.
        if (preg_match('/^[\x20-\x7E]*$/', $valor)) {
            return $valor;
        }

        return $this->partirCabecera($valor);
    }

    /**
     * Codifica en trozos que quepan en una línea de cabecera.
     *
     * Una palabra codificada no puede pasar de 75 caracteres (RFC 2047)
     * y una línea de cabecera no debería pasar de 78. Un asunto largo
     * con acentos producía UNA sola palabra codificada de 200
     * caracteres: formalmente inválida, y motivo suficiente para que un
     * servidor estricto rechace o no firme el mensaje.
     */
    private function partirCabecera(string $valor): string
    {
        $trozos = [];
        $actual = '';

        // Se corta por caracteres completos; partir un UTF-8 por la
        // mitad produce basura al decodificar.
        foreach (preg_split('//u', $valor, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $caracter) {
            // 45 bytes crudos ≈ 60 en base64, con margen para el envoltorio.
            if (strlen($actual . $caracter) > 45) {
                $trozos[] = $actual;
                $actual = '';
            }

            $actual .= $caracter;
        }

        if ($actual !== '') {
            $trozos[] = $actual;
        }

        return implode(
            self::CRLF . ' ',
            array_map(
                static fn(string $t): string => '=?UTF-8?B?' . base64_encode($t) . '?=',
                $trozos
            )
        );
    }

    /**
     * Una dirección con su nombre, bien formada.
     *
     * El nombre va SIEMPRE entre comillas cuando lleva alguno de los
     * caracteres que RFC 5322 llama «specials». «CashMan H.A.» los
     * lleva: los puntos. Sin comillas, la cabecera queda
     *
     *     From: CashMan H.A. <contacto@cashmanha.sv>
     *
     * que es sintácticamente inválida —el punto no puede aparecer suelto
     * en un `display-name`— y hace que el relé de Gmail no reconozca el
     * remitente como propio. Al no reconocerlo no firma el mensaje con
     * DKIM, y el Gmail del destinatario lo rechaza con
     *
     *     550 5.7.30 ... DKIM authentication didn't pass
     *
     * que es exactamente el rebote que estaba llegando.
     */
    private function direccionConNombre(string $correo, string $nombre): string
    {
        $correo = $this->sanearDireccion($correo);
        $nombre = trim($this->sanearCabecera($nombre));

        if ($nombre === '') {
            return $correo;
        }

        // Fuera de ASCII: palabra codificada, que no se entrecomilla.
        if (!preg_match('/^[\x20-\x7E]*$/', $nombre)) {
            return $this->partirCabecera($nombre) . ' <' . $correo . '>';
        }

        // Los `specials` de RFC 5322 obligan a entrecomillar.
        if (preg_match('/[()<>\[\]:;@\\\\,."]/', $nombre)) {
            $nombre = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $nombre) . '"';
        }

        return $nombre . ' <' . $correo . '>';
    }

    private function htmlATexto(string $html): string
    {
        $t = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $t = preg_replace('/<\/(p|div|tr|h[1-6])>/i', "\n", $t) ?? $t;
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        return trim($t);
    }

    /** @return array<int,string> Traza del diálogo SMTP, para diagnóstico. */
    public function traza(): array
    {
        return $this->traza;
    }

    /**
     * Prueba la conexión sin enviar nada.
     *
     * @return array{ok:bool, mensaje:string, traza:array<int,string>}
     */
    public function probarConexion(): array
    {
        try {
            $this->conectar();
            $this->saludar();

            if ($this->cifrado === 'tls') {
                $this->iniciarTls();
                $this->saludar();
            }
            if ($this->usuario !== '') {
                $this->autenticar();
            }

            $this->comando('QUIT', [221]);
            $this->cerrar();

            return ['ok' => true, 'mensaje' => 'Conexión SMTP correcta.', 'traza' => $this->traza];

        } catch (\Throwable $e) {
            $this->cerrar();
            return ['ok' => false, 'mensaje' => $e->getMessage(), 'traza' => $this->traza];
        }
    }
    /**
     * El logotipo que se incrusta, leído una sola vez.
     *
     * Se usa la versión en REVERSO —tinta clara— porque la cabecera del
     * correo va sobre morado oscuro: el logotipo normal es azul marino
     * y ahí no se vería, que es exactamente lo que pasaba en el modo
     * noche de la banca.
     *
     * Si el archivo no está, se devuelve null y el correo sale sin
     * logo: un correo sin imagen se lee perfectamente, uno que no se
     * manda no.
     *
     * @return array{datos:string, tipo:string, nombre:string, cid:string}|null
     */
    /**
     * Las imágenes que el cuerpo referencia, listas para incrustar.
     *
     * Se mira el HTML y se incrusta SOLO lo que aparece: un aviso
     * sencillo no tiene por qué cargar con los cinco iconos, y un
     * `cid:` que se quedó escrito sin su imagen dejaría un recuadro
     * roto en el correo.
     *
     * Los archivos salen de `public/assets/correo/`, que es donde
     * `bin/generar-imagenes-correo.php` los deja ya al tamaño en que se
     * usan. El logotipo original pesa 97 KB y mide 3.500 píxeles de
     * ancho para enseñarse a 34 de alto: mandarlo entero en cada aviso
     * es lo que hace que Gmail acabe recortando el mensaje.
     *
     * @return list<array{datos:string, tipo:string, nombre:string, cid:string}>
     */
    private function imagenesIncrustadas(string $html): array
    {
        static $cache = [];

        // Qué `cid:` aparece en el cuerpo.
        if (preg_match_all('/cid:([a-z0-9\-]+)/i', $html, $m) === 0) {
            return [];
        }

        $base  = dirname(__DIR__, 3) . '/public/assets/correo/';
        $salen = [];

        foreach (array_unique($m[1]) as $cid) {
            if (isset($cache[$cid])) {
                if ($cache[$cid] !== null) {
                    $salen[] = $cache[$cid];
                }

                continue;
            }

            /* El `cid` ES el nombre del archivo, sin extensión. Esa
               correspondencia evita una tabla de equivalencias que
               alguien tendría que mantener al añadir un icono. Se
               limpia igualmente: un `cid` con barras sería una ruta. */
            $limpio = preg_replace('/[^a-z0-9\-]/i', '', $cid) ?? '';
            $ruta   = $base . $limpio . '.png';

            if ($limpio === '' || !is_file($ruta) || !is_readable($ruta)) {
                $cache[$cid] = null;
                error_log('[Correo] imagen incrustada que no existe: ' . $cid);
                continue;
            }

            $datos = @file_get_contents($ruta);

            if ($datos === false || $datos === '') {
                $cache[$cid] = null;
                continue;
            }

            $salen[] = $cache[$cid] = [
                'datos'  => $datos,
                'tipo'   => 'image/png',
                'nombre' => $limpio . '.png',
                'cid'    => $limpio,
            ];
        }

        return $salen;
    }
}
