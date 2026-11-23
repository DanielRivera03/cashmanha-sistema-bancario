<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Seguridad\Sesion;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Documento\ServicioDocumentos;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * Notificaciones del sistema.
 *
 * Es la fuente de datos del canal SSE: cuando ocurre algo relevante
 * (dinero recibido, cuota por vencer, tarjeta bloqueada), se crea una
 * notificación y el navegador del usuario la recibe sin refrescar.
 *
 * El cursor es el id autoincremental, no la fecha: dos notificaciones
 * creadas en el mismo microsegundo tendrían la misma marca de tiempo
 * y una de las dos se perdería al paginar.
 *
 * Desde aquí sale también el correo. Antes el aviso solo vivía en el
 * portal: quien no entrara ese día no se enteraba de que le habían
 * movido dinero, y había que acordarse de mandar el correo a mano en
 * cada sitio que notificara —así que unos lo mandaban y otros no—. Un
 * movimiento de dinero se avisa por los dos canales o no se avisa.
 */
final class ServicioNotificacion
{
    /** @param array<string,mixed>|null $datos */
    public function crear(
        int $usuarioId,
        string $tipo,
        string $titulo,
        string $mensaje,
        ?array $datos = null,
        string $severidad = 'info',
        ?string $urlDestino = null
    ): int {
        BaseDatos::ejecutar(
            'INSERT INTO notificaciones
               (usuario_id, tipo, titulo, mensaje, datos_json, severidad, url_destino)
             VALUES (?,?,?,?,?,?,?)',
            [
                $usuarioId,
                mb_substr($tipo, 0, 50),
                mb_substr($titulo, 0, 160),
                mb_substr($mensaje, 0, 500),
                $datos === null ? null : json_encode($datos, JSON_UNESCAPED_UNICODE),
                $severidad,
                $urlDestino,
            ]
        );

        $id = BaseDatos::ultimoId();

        $this->porCorreoSiCorresponde($usuarioId, $tipo, $titulo, $mensaje, $datos, $severidad);

        return $id;
    }

    /**
     * Tipos de aviso que además salen por correo.
     *
     * No todo merece un correo. Que a alguien le cambie el disponible
     * por un centavo de interés no justifica entrar en su bandeja de
     * entrada; que le hayan movido dinero, sí.
     *
     * El criterio es el del cliente, no el del sistema: si al verlo
     * pensaría «esto lo tengo que saber aunque hoy no abra la
     * aplicación», va por correo. El resto vive en la campanita.
     *
     * Se compara por prefijo: `caja.` cubre todo lo que haga un cajero
     * con el dinero de alguien sin tener que enumerarlo uno por uno.
     */
    private const AVISA_POR_CORREO = [
        'deposito.',
        'retiro.',
        'transferencia.',
        'pago.',
        'cuota.',
        'credito.',
        'tarjeta.solicitud',
        'tarjeta.bloqueo',
        'tarjeta.consumo',
        'tarjeta.corte',
        'caja.',
        'nota.',
        'cobranza.',
        'seguridad.',
        'arreglo.',
    ];

    /**
     * Lo que va con copia a la dirección de seguimiento de créditos.
     *
     * Todo el ciclo: el crédito, sus cuotas, los pagos que las cubren,
     * los arreglos que se pactan y la cobranza completa hasta la vía
     * judicial. La dirección se configura en `correo.copia_creditos`.
     */
    private const COPIA_SEGUIMIENTO_CREDITO = [
        'credito.',
        'cuota.',
        'cobranza.',
        'arreglo.',
        'mora.',
        'reestructuracion.',
        'contrato.',
        'pago.cuota',
        'compensacion.',
    ];

    /**
     * Manda el aviso por correo, si el tipo lo amerita.
     *
     * Falla en silencio a propósito: el correo es un extra sobre el
     * aviso del portal, que ya quedó guardado. Si el servidor de correo
     * está caído, lo último que debe pasar es que se caiga con él la
     * operación que lo originó —un depósito, por ejemplo—. Queda
     * anotado en el registro de errores para poder revisarlo.
     *
     * @param array<string,mixed>|null $datos
     */
    private function porCorreoSiCorresponde(
        int $usuarioId,
        string $tipo,
        string $titulo,
        string $mensaje,
        ?array $datos,
        // Para elegir el icono del correo. Ya venia decidida en
        // `crear()`; aqui solo hacia falta dejarla llegar.
        string $severidad = 'info'
    ): void {
        $procede = false;

        foreach (self::AVISA_POR_CORREO as $prefijo) {
            if (str_starts_with($tipo, $prefijo)) {
                $procede = true;
                break;
            }
        }

        if (!$procede) {
            return;
        }

        try {
            $u = BaseDatos::unaFila(
                'SELECT correo, nombres, apellidos FROM usuarios
                  WHERE id = ? AND estado = "activo"',
                [$usuarioId]
            );

            if ($u === null || (string) $u['correo'] === '') {
                return;
            }

            // Los datos del aviso se enseñan como tabla de detalle: es
            // lo que convierte «recibimos su pago» en un comprobante.
            $filas = [];

            foreach (($datos ?? []) as $k => $v) {
                if (is_scalar($v) && (string) $v !== '') {
                    $filas[] = [
                        'etiqueta' => ucfirst(str_replace('_', ' ', (string) $k)),
                        'valor'    => (string) $v,
                    ];
                }
            }

            /* La fecha, siempre. Un acuse sin fecha no sirve para
               reclamar: el cliente no puede decir cuándo pasó. */
            $filas[] = ['etiqueta' => 'Fecha y hora', 'valor' => date('d/m/Y H:i:s')];

            /* Y quién lo hizo.
             *
             * Un cargo que el cliente no recuerda es justo el caso en
             * que importa saber si lo hizo él desde su banca, un cajero
             * en ventanilla o un proceso automático. Sin esto, la única
             * forma de averiguarlo es llamar y que alguien mire la
             * bitácora. */
            $filas[] = [
                'etiqueta' => 'Realizado por',
                'valor'    => (string) ($datos['actor'] ?? self::quienLoHizo($usuarioId)),
            ];

            /* El comprobante en PDF, cuando el aviso corresponde a un
               movimiento del libro mayor. Es el mismo documento que se
               descargaría desde la banca en línea; adjuntarlo ahorra el
               viaje de ida y vuelta al sistema para guardar constancia. */
            $adjuntos = self::comprobanteDe($datos, $usuarioId);

            $html = PlantillaCorreo::comprobante(
                trim((string) $u['nombres']),
                $titulo,
                $mensaje,
                $filas,
                null,
                self::importeDe($mensaje, $datos),
                array_map(static fn(array $a): string => (string) $a['nombre'], $adjuntos),
                'Si no reconoce esta operación, comuníquese con nosotros de inmediato '
                . 'indicando la referencia que aparece arriba.',
                /* El icono sale de la severidad que el aviso ya traía.
                   Dice de qué va el correo antes de leerlo: una paloma
                   verde y un aspa roja no se confunden, y quien recibe
                   veinte avisos al mes agradece no tener que abrirlos
                   todos para saber cuál le importa. */
                match ($severidad) {
                    'exito' => 'exito',
                    'error' => 'error',
                    'alerta', 'aviso' => 'aviso',
                    'info'  => 'info',
                    default => 'movimiento',
                }
            );

            self::encolar(
                (string) $u['correo'],
                $titulo,
                $html,
                $mensaje . "\n\n" . date('d/m/Y H:i'),
                trim($u['nombres'] . ' ' . $u['apellidos']),
                self::copiasDe($tipo),
                $adjuntos
            );
        } catch (\Throwable $e) {
            error_log('[Notificacion] No se pudo preparar el correo: ' . $e->getMessage());
        }
    }

    /**
     * El importe que encabeza el correo.
     *
     * Quien abre el aviso de un cargo quiere saber cuánto, y lo quiere
     * antes de leer la frase entera. Se saca de los datos del aviso si
     * vienen con monto, y si no del propio mensaje, que es donde
     * siempre acaba estando.
     *
     * @param array<string,mixed>|null $datos
     * @return array{importe?:string,concepto?:string,signo?:string}
     */
    private static function importeDe(string $mensaje, ?array $datos): array
    {
        foreach (['monto', 'importe', 'total'] as $clave) {
            $v = $datos[$clave] ?? null;

            if (is_scalar($v) && (string) $v !== '') {
                $texto = (string) $v;

                return ['importe' => str_starts_with($texto, '$') ? $texto : '$' . $texto];
            }
        }

        // Del mensaje: el primer importe con formato de dinero.
        if (preg_match('/\$\s?[\d,]+\.\d{2}/', $mensaje, $m) === 1) {
            return ['importe' => str_replace(' ', '', $m[0])];
        }

        return [];
    }

    /**
     * El comprobante del movimiento, si el aviso corresponde a uno.
     *
     * Los avisos de dinero traen la referencia del asiento, y de esa
     * referencia sale el mismo PDF que el cliente descargaría desde su
     * banca. Se comprueba antes que el asiento sea suyo: un adjunto con
     * el nombre y los saldos de otra persona es una fuga de datos, no
     * una comodidad.
     *
     * Falla en silencio: el aviso vale sin adjunto, y no generarlo
     * nunca debe impedir que el correo salga.
     *
     * @param array<string,mixed>|null $datos
     * @return list<array{ruta:string,nombre:string,tipo:string}>
     */
    /**
     * Quién originó la operación, dicho para el cliente.
     *
     * Se saca de la sesión, que es donde está el hecho: nadie tiene que
     * acordarse de pasarlo en cada aviso. Tres casos, y los tres
     * importan de forma distinta cuando el cliente reclama:
     *
     *   · él mismo, desde su banca;
     *   · una persona de la institución, con su nombre y su puesto;
     *   · un proceso automático, que no tiene a quién preguntarle.
     *
     * Quien quiera decir otra cosa pasa `actor` en los datos del aviso.
     */
    private static function quienLoHizo(int $destinatarioId): string
    {
        /* En consola no hay sesión: lo corrió un proceso. Arrancar la
           sesión aquí para averiguarlo reventaría los guiones de
           madrugada por una columna que admite nulo. */
        if (PHP_SAPI === 'cli') {
            return 'Proceso automático del sistema';
        }

        try {
            $actorId = Sesion::usuarioId();
        } catch (\Throwable $e) {
            $actorId = 0;
        }

        if ($actorId === 0) {
            return 'Proceso automático del sistema';
        }

        if ($actorId === $destinatarioId) {
            return 'Usted, desde su banca en línea';
        }

        $a = BaseDatos::unaFila(
            'SELECT CONCAT(u.nombres," ",u.apellidos) AS nombre, r.nombre AS rol
               FROM usuarios u JOIN roles r ON r.id = u.rol_id
              WHERE u.id = ?',
            [$actorId]
        );

        if ($a === null) {
            return 'Personal de la institución';
        }

        return $a['nombre'] . ' · ' . $a['rol'];
    }
    private static function comprobanteDe(?array $datos, int $usuarioId): array
    {
        /* El id del asiento, cuando quien avisa lo sabe.
         *
         * Es el camino bueno. Buscar por `referencia` solo funciona si
         * esa referencia es la DEL ASIENTO, y los cobros por pasarela
         * mandaban la de Stripe (`pi_3Uah…`): no encontraba nada y el
         * correo salía sin comprobante justo en las operaciones más
         * grandes. */
        $id = (int) ($datos['asiento_id'] ?? 0);

        if ($id === 0) {
            $referencia = (string) ($datos['referencia'] ?? '');

            if ($referencia === '') {
                return [];
            }

            try {
                $asiento = BaseDatos::unaFila(
                    'SELECT id FROM asientos WHERE referencia = ? ORDER BY id DESC LIMIT 1',
                    [$referencia]
                );
            } catch (\Throwable $e) {
                return [];
            }

            if ($asiento === null) {
                return [];
            }

            $id = (int) $asiento['id'];
        }

        try {

            /* ¿Es suyo? Por sus cuentas, o por el cobro de pasarela que
               lo originó —ahí el asiento va entre cuentas técnicas y no
               toca ninguna cuenta del cliente. */
            $suyo = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM movimientos m
                   JOIN cuentas c ON c.id = m.cuenta_id
                  WHERE m.asiento_id = ? AND c.usuario_id = ?',
                [$id, $usuarioId]
            );

            if ($suyo === 0) {
                $suyo = (int) BaseDatos::unValor(
                    'SELECT COUNT(*) FROM pagos_pasarela
                      WHERE asiento_id = ? AND usuario_id = ?',
                    [$id, $usuarioId]
                );
            }

            if ($suyo === 0) {
                return [];
            }

            $doc = (new ServicioDocumentos())->comprobante($id);

            /* El nombre lleva la referencia DEL ASIENTO, no la que venga
               en los datos: cuando el aviso llega por pasarela esa es la
               de Stripe, y el cliente acabaría con un archivo llamado
               «comprobante-pi_3Uah….pdf», que no le dice nada ni le
               sirve para buscarlo después. */
            $nombre = (string) (BaseDatos::unValor(
                'SELECT referencia FROM asientos WHERE id = ?',
                [$id]
            ) ?? '');

            if ($nombre === '') {
                $nombre = 'asiento-' . $id;
            }

            return [[
                'ruta'   => (string) $doc['ruta'],
                'nombre' => 'comprobante-' . $nombre . '.pdf',
                'tipo'   => 'application/pdf',
            ]];
        } catch (\Throwable $e) {
            error_log('[Notificacion] Sin comprobante del asiento ' . $id . ': '
                . $e->getMessage());

            return [];
        }
    }

    /**
     * A quién más le llega este aviso.
     *
     * Todo lo que tenga que ver con crédito —el crédito en sí, sus
     * cuotas, los pagos, los arreglos y la cobranza entera— va con copia
     * a una dirección de seguimiento. La institución quiere ver ese
     * flujo completo sin tener que entrar al sistema a buscarlo.
     *
     * La dirección vive en `parametros` y no en el código: cambiarla no
     * debería requerir tocar un archivo ni reiniciar nada. Admite varias
     * separadas por coma.
     *
     * @return list<string>
     */
    private static function copiasDe(string $tipo): array
    {
        $esDeCredito = false;

        foreach (self::COPIA_SEGUIMIENTO_CREDITO as $prefijo) {
            if (str_starts_with($tipo, $prefijo)) {
                $esDeCredito = true;
                break;
            }
        }

        if (!$esDeCredito) {
            return [];
        }

        $crudo = trim(Parametros::obtener('correo.copia_creditos', ''));

        if ($crudo === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $crudo)),
            static fn(string $c): bool => $c !== '',
        ));
    }

    /** Correos pendientes de esta petición. */
    private static array $buzon = [];
    private static bool $buzonRegistrado = false;

    /**
     * Guarda el correo para mandarlo al terminar la petición.
     *
     * Enviarlo en el momento sería un error serio, y no por lentitud:
     * `crear()` se llama muchas veces DENTRO de una transacción —al
     * aprobar una tarjeta, al pagar una cuota, al cerrar una caja—, y
     * una conexión SMTP puede tardar segundos. Durante todo ese rato la
     * transacción seguiría abierta reteniendo bloqueos sobre cuentas y
     * asientos, y el resto de operaciones esperando. Un servidor de
     * correo lento llegaría a parecer una base de datos colgada.
     *
     * Aplazarlo al cierre tiene además una ventaja: para entonces la
     * transacción ya hizo COMMIT, así que nunca se avisa por correo de
     * algo que acabó deshaciéndose.
     */
    /** @param list<string> $copias */
    /** @param list<array{ruta:string,nombre:string,tipo:string}> $adjuntos */
    private static function encolar(
        string $para,
        string $asunto,
        string $html,
        string $texto,
        string $nombre,
        array $copias = [],
        array $adjuntos = []
    ): void {
        self::$buzon[] = [$para, $asunto, $html, $texto, $nombre, $copias, $adjuntos];

        if (self::$buzonRegistrado) {
            return;
        }

        self::$buzonRegistrado = true;

        register_shutdown_function(static function (): void {
            // Si el entorno lo permite, el cliente recibe su respuesta
            // antes de que empiece el envío.
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }

            $pendientes = self::$buzon;
            self::$buzon = [];

            if ($pendientes === []) {
                return;
            }

            try {
                $correo = new Correo();

                foreach ($pendientes as [$para, $asunto, $html, $texto, $nombre, $copias, $adjuntos]) {
                    $correo->enviar($para, $asunto, $html, $texto, $nombre, $copias, '', $adjuntos);
                }
            } catch (\Throwable $e) {
                error_log('[Notificacion] Envio diferido fallido: ' . $e->getMessage());
            }
        });
    }

    /**
     * Notificaciones posteriores a un cursor. Es lo que consulta el SSE.
     *
     * @return array<int,array<string,mixed>>
     */
    public function desde(int $usuarioId, int $ultimoId = 0, int $limite = 30): array
    {
        $limite = max(1, min(100, $limite));

        $filas = BaseDatos::todas(
            "SELECT id, tipo, titulo, mensaje, datos_json, severidad, url_destino,
                    leida_en, creada_en
               FROM notificaciones
              WHERE usuario_id = ? AND id > ?
              ORDER BY id ASC
              LIMIT {$limite}",
            [$usuarioId, $ultimoId]
        );

        foreach ($filas as &$f) {
            $f['datos'] = $f['datos_json'] !== null
                ? json_decode((string) $f['datos_json'], true)
                : null;
            unset($f['datos_json']);
            $f['id']    = (int) $f['id'];
            $f['leida'] = $f['leida_en'] !== null;
            // Tiempo relativo para el panel de la campana, donde no
            // cabe la fecha completa.
            $f['hace']  = \CashMan\Http\Plantilla::hace((string) $f['creada_en']);
        }

        return $filas;
    }

    /** @return array<int,array<string,mixed>> */
    public function recientes(int $usuarioId, int $limite = 20): array
    {
        $limite = max(1, min(100, $limite));

        $filas = BaseDatos::todas(
            "SELECT id, tipo, titulo, mensaje, datos_json, severidad, url_destino,
                    leida_en, creada_en
               FROM notificaciones
              WHERE usuario_id = ?
              ORDER BY id DESC
              LIMIT {$limite}",
            [$usuarioId]
        );

        foreach ($filas as &$f) {
            $f['datos'] = $f['datos_json'] !== null
                ? json_decode((string) $f['datos_json'], true)
                : null;
            unset($f['datos_json']);
            $f['id']    = (int) $f['id'];
            $f['leida'] = $f['leida_en'] !== null;
            // Tiempo relativo para el panel de la campana, donde no
            // cabe la fecha completa.
            $f['hace']  = \CashMan\Http\Plantilla::hace((string) $f['creada_en']);
        }

        return $filas;
    }

    public function sinLeer(int $usuarioId): int
    {
        return (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND leida_en IS NULL',
            [$usuarioId]
        );
    }

    /** Marca como leída. El WHERE por usuario impide marcar las de otro. */
    public function marcarLeida(int $usuarioId, int $notificacionId): bool
    {
        return BaseDatos::ejecutar(
            'UPDATE notificaciones SET leida_en = NOW()
              WHERE id = ? AND usuario_id = ? AND leida_en IS NULL',
            [$notificacionId, $usuarioId]
        ) > 0;
    }

    public function marcarTodasLeidas(int $usuarioId): int
    {
        return BaseDatos::ejecutar(
            'UPDATE notificaciones SET leida_en = NOW()
              WHERE usuario_id = ? AND leida_en IS NULL',
            [$usuarioId]
        );
    }

    /** Último id del usuario: cursor inicial del SSE. */
    public function ultimoId(int $usuarioId): int
    {
        return (int) BaseDatos::unValor(
            'SELECT COALESCE(MAX(id), 0) FROM notificaciones WHERE usuario_id = ?',
            [$usuarioId]
        );
    }

    public static function purgar(int $diasRetencion = 90): int
    {
        return BaseDatos::ejecutar(
            'DELETE FROM notificaciones
              WHERE leida_en IS NOT NULL
                AND creada_en < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$diasRetencion]
        );
    }
}
