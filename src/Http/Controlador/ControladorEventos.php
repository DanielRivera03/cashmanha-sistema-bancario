<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioNotificacion;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Canal en vivo: Server-Sent Events + pulso con ETag.
 *
 * SSE mantiene una conexión abierta por la que el servidor empuja
 * eventos al navegador. El usuario ve su saldo cambiar, la
 * transferencia que acaba de recibir o la alerta de cuota vencida
 * sin tocar F5.
 *
 * Dos detalles que hacen que esto funcione bien en Apache/PHP:
 *
 *   1. La sesión se cierra en cuanto se lee (session_write_close).
 *      Si no, el archivo de sesión queda bloqueado durante toda la
 *      conexión SSE y el resto de peticiones del mismo usuario se
 *      quedan esperando: la aplicación entera parece congelada.
 *
 *   2. La conexión se corta sola a los ~45 segundos y el navegador
 *      reconecta. Cada conexión SSE ocupa un worker de Apache, y
 *      mantenerlas abiertas indefinidamente agota el pool.
 */
final class ControladorEventos extends ControladorBase
{
    public function stream(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        /* Cursor desde el que se empuja.
         *
         * Hay que distinguir una RECONEXIÓN de una PRIMERA conexión, y
         * antes no se distinguía: el cliente abre el canal con `desde=0`
         * en cada carga de página, el servidor entendía "mándame todo
         * desde el principio" y reenviaba las notificaciones más
         * antiguas del usuario. Con 104 avisos sin leer, cada vez que
         * abría cualquier pantalla le saltaban veinte globos de pagos
         * hechos hacía meses.
         *
         * El historial sin leer es trabajo de la campanita y de la
         * pantalla de notificaciones. Un globo emergente es para algo
         * que acaba de pasar.
         *
         *   · Con `Last-Event-ID` → es el navegador reanudando tras un
         *     corte: ahí SÍ hay que reenviar lo que se perdió.
         *   · Con `desde` > 0 → el cliente ya sabe por dónde iba.
         *   · Sin nada → primera conexión: la línea base es lo último
         *     que ya existe, y solo se empuja lo que venga después.
         */
        $reconexion = isset($_SERVER['HTTP_LAST_EVENT_ID'])
            && $_SERVER['HTTP_LAST_EVENT_ID'] !== '';

        $pedido = (int) $this->get('desde', '0');

        if ($reconexion) {
            $ultimoId = (int) $_SERVER['HTTP_LAST_EVENT_ID'];
        } elseif ($pedido > 0) {
            $ultimoId = $pedido;
        } else {
            $ultimoId = (int) BaseDatos::unValor(
                'SELECT COALESCE(MAX(id), 0) FROM notificaciones WHERE usuario_id = ?',
                [$usuarioId]
            );
        }

        // CRÍTICO: liberar el bloqueo de sesión antes del bucle.
        session_write_close();

        @set_time_limit(0);
        ignore_user_abort(false);

        // Desactivar cualquier buffer: los eventos deben salir al instante.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        if (!headers_sent()) {
            header('Content-Type: text/event-stream; charset=UTF-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // evita el buffering de nginx
        }

        $notificaciones = new ServicioNotificacion();

        $intervaloMs = Config::entero('SSE_INTERVALO_MS', 2000);
        $timeout     = Config::entero('SSE_TIMEOUT_SEG', 45);
        $inicio      = time();
        $ultimoPulso = '';

        $this->emitir('conectado', ['desde' => $ultimoId, 'ts' => date('c')]);

        while ((time() - $inicio) < $timeout) {
            if (connection_aborted()) {
                break;
            }

            // --- Notificaciones nuevas -------------------------------
            $nuevas = $notificaciones->desde($usuarioId, $ultimoId, 20);

            foreach ($nuevas as $n) {
                $ultimoId = (int) $n['id'];
                $this->emitir('notificacion', [
                    'id'        => $n['id'],
                    'tipo'      => $n['tipo'],
                    'titulo'    => $n['titulo'],
                    'mensaje'   => $n['mensaje'],
                    'severidad' => $n['severidad'],
                    'url'       => $n['url_destino'],
                    'datos'     => $n['datos'],
                    'creada_en' => $n['creada_en'],
                ], (string) $ultimoId);
            }

            // --- Pulso: saldos y contadores --------------------------
            // Solo se emite cuando algo cambió de verdad.
            $pulso = $this->construirPulso($usuarioId);
            $firma = hash('xxh128', json_encode($pulso, JSON_THROW_ON_ERROR));

            if ($firma !== $ultimoPulso) {
                $ultimoPulso = $firma;
                $this->emitir('pulso', $pulso);
            }

            // Comentario SSE: mantiene viva la conexión a través de
            // proxies que cortan por inactividad.
            echo ": keep-alive\n\n";
            @ob_flush();
            @flush();

            usleep($intervaloMs * 1000);
        }

        // Le dice al navegador cuándo reconectar.
        $this->emitir('reconectar', ['en_ms' => 1000]);
        exit;
    }

    /**
     * Polling con ETag, para los clientes que no usen SSE.
     *
     * Si nada cambió devuelve 304 sin cuerpo: cero bytes de red y
     * ninguna consulta extra.
     */
    public function pulso(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        Respuesta::jsonConEtag($this->construirPulso($usuarioId));
    }

    /**
     * Cuánto le queda a la sesión antes de caducar por inactividad.
     *
     * Lleva la cabecera de sondeo a propósito: preguntar cuánto queda
     * no puede, por sí mismo, reiniciar la cuenta. Si lo hiciera, el
     * propio aviso mantendría la sesión viva para siempre.
     */
    public function cuentaAtras(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        $c = Sesion::cuentaAtras();

        Respuesta::json([
            'ok'      => true,
            'restan'  => $c['restan'] ?? 0,
            'aviso'   => $c['aviso'] ?? 60,
            'total'   => $c['inactividad'] ?? 600,
            // No se avisa de esto por el canal de notificaciones.
            'silencio' => true,
        ]);
    }
    public function notificaciones(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $servicio  = new ServicioNotificacion();

        Respuesta::exito([
            'notificaciones' => $servicio->recientes($usuarioId, 20),
            'sin_leer'       => $servicio->sinLeer($usuarioId),
        ]);
    }

    public function marcarLeidas(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $servicio  = new ServicioNotificacion();

        $id = $this->postEntero('id', 0);

        if ($id > 0) {
            // El servicio filtra por usuario: nadie marca las de otro.
            $servicio->marcarLeida($usuarioId, $id);
        } else {
            $servicio->marcarTodasLeidas($usuarioId);
        }

        Respuesta::exito(['sin_leer' => $servicio->sinLeer($usuarioId)]);
    }

    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function construirPulso(int $usuarioId): array
    {
        // Se traen también lo retenido y el saldo mínimo del producto:
        // sin eso, el panel en vivo puede mostrar el saldo pero no lo
        // que de verdad se puede usar, que es el dato que importa antes
        // de transferir.
        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, c.saldo_cent, c.saldo_retenido_cent,
                    c.moneda, c.estado, p.nombre AS producto, p.saldo_minimo_cent
               FROM cuentas c
               JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.es_tecnica = 0
              ORDER BY c.id',
            [$usuarioId]
        );

        $total = 0;
        $lista = [];
        foreach ($cuentas as $c) {
            $total += (int) $c['saldo_cent'];
            $retenido = (int) $c['saldo_retenido_cent'];
            $minimo   = (int) $c['saldo_minimo_cent'];

            $lista[] = [
                'codigo'     => Hashid::codificar((int) $c['id'], 'cuenta'),
                'numero'     => $c['numero_cuenta'],
                'producto'   => $c['producto'],
                'moneda'     => $c['moneda'],
                'saldo'      => (int) $c['saldo_cent'],
                'retenido'   => $retenido,
                'minimo'     => $minimo,
                // Lo que de verdad se puede mover ahora mismo.
                'disponible' => max(0, (int) $c['saldo_cent'] - $retenido - $minimo),
                'estado'     => $c['estado'],
            ];
        }

        $sinLeer = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND leida_en IS NULL',
            [$usuarioId]
        );

        $ultimoMov = BaseDatos::unaFila(
            'SELECT m.id, m.importe_cent, m.descripcion, m.creado_en, a.referencia, a.tipo
               FROM movimientos m
               JOIN asientos a   ON a.id = m.asiento_id
               JOIN cuentas  c   ON c.id = m.cuenta_id
              WHERE c.usuario_id = ?
              ORDER BY m.id DESC LIMIT 1',
            [$usuarioId]
        );

        return [
            'cuentas'        => $lista,
            'saldo_total'    => $total,
            'sin_leer'       => $sinLeer,
            'ultimo_mov'     => $ultimoMov === null ? null : [
                'importe'    => (int) $ultimoMov['importe_cent'],
                'concepto'   => $ultimoMov['descripcion'],
                'referencia' => $ultimoMov['referencia'],
                'tipo'       => $ultimoMov['tipo'],
                'fecha'      => $ultimoMov['creado_en'],
            ],
            'servidor_ts'    => date('c'),
        ];
    }

    /** @param array<string,mixed> $datos */
    private function emitir(string $evento, array $datos, ?string $id = null): void
    {
        if ($id !== null) {
            echo "id: {$id}\n";
        }
        echo "event: {$evento}\n";
        echo 'data: ' . json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n\n";

        @ob_flush();
        @flush();
    }
}
