<?php

declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\FondosInsuficientes;
use CashMan\Aplicacion\Servicio\LimiteExcedidoTransferencia;
use CashMan\Aplicacion\Servicio\ServicioLimitesCliente;
use CashMan\Aplicacion\Servicio\ServicioNoDisponible;
use CashMan\Aplicacion\Servicio\ServicioPagoServicios;
use CashMan\Dominio\Colecturia\Colector;
use CashMan\Dominio\Comun\MontoInvalido;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\AccesoDenegado;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Pagar la luz, el agua, el teléfono, el colegio.
 *
 * El flujo son dos pasos y la pantalla los respeta: primero se
 * consulta qué se debe —y eso no mueve dinero— y después se paga lo
 * que el colector dijo. El cliente nunca teclea el monto.
 */
final class ControladorServicios extends ControladorBase
{
    public function pantalla(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        if (!Autorizacion::puede('transferencia.ejecutar')) {
            throw new AccesoDenegado('No tiene permiso para pagar servicios.');
        }

        $servicio = new ServicioPagoServicios();

        /* El catálogo agrupado por categoría: un desplegable con
           veinte convenios sueltos no se puede recorrer. */
        $porCategoria = [];

        foreach (Colector::catalogo() as $c) {
            $porCategoria[(string) $c['categoria']][] = $c;
        }

        $this->render('servicios/pagar', [
            'titulo'       => 'Pagar servicios · CashMan H.A.',
            'encabezado'   => 'Pagar servicios',
            'cuentas'      => $this->cuentasDe($usuarioId),
            'categorias'   => $porCategoria,
            'guardados'    => $servicio->guardados($usuarioId),
            'limite'       => $this->limiteServicios($usuarioId),
            'csrf'         => Csrf::token('servicios'),
        ]);
    }

    public function historial(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $this->render('servicios/historial', [
            'titulo'     => 'Servicios pagados · CashMan H.A.',
            'encabezado' => 'Servicios pagados',
            'pagos'      => (new ServicioPagoServicios())->historial($usuarioId, 200),
            'csrf'       => Csrf::token('servicios'),
        ]);
    }

    // =================================================================
    // Paso 1
    // =================================================================

    public function consultar(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        try {
            $r = (new ServicioPagoServicios())->consultar(
                $this->postEntero('convenio_id'),
                (string) $this->post('referencia')
            );
        } catch (ServicioNoDisponible $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(
            $r,
            'A nombre de ' . $r['titular'] . '.',
            /* Esta no cambia nada: solo pregunta. El aviso automático
               de `cm.js` avisa de todo lo que muta, y una consulta que
               confirmara «Listo» cada vez que se teclea una referencia
               sería ruido. */
            ['silencio' => true]
        );
    }

    // =================================================================
    // Paso 2
    // =================================================================

    public function pagar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $cuentaId = $this->idDesde($this->post('cuenta'), 'cuenta');

        if ($cuentaId === 0) {
            Respuesta::error('Elija la cuenta de la que va a pagar.', 422);
            return;
        }

        try {
            $r = (new ServicioPagoServicios())->pagar([
                'cuenta_id'          => $cuentaId,
                'convenio_id'        => $this->postEntero('convenio_id'),
                'referencia'         => (string) $this->post('referencia'),
                'monto'              => $this->post('monto'),
                'guardar'            => $this->post('guardar') === '1',
                'alias'              => (string) $this->post('alias'),
                'clave_idempotencia' => $this->claveIdempotencia(),
            ]);
        } catch (MontoInvalido | ServicioNoDisponible $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        } catch (FondosInsuficientes $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'fondos_insuficientes']);
            return;
        } catch (LimiteExcedidoTransferencia $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'limite_excedido']);
            return;
        }

        $servicio = new ServicioPagoServicios();

        $r['cuentas']   = $this->cuentasDe($usuarioId);
        $r['guardados'] = $servicio->guardados($usuarioId);
        $r['limite']    = $this->limiteServicios($usuarioId);

        Respuesta::exito($r, match ($r['estado']) {
            'duplicado' => 'Este pago ya se había procesado.',
            default     => ($r['parcial'] ?? false)
                ? 'Abono aplicado. Autorización ' . ($r['autorizacion'] ?? '') . '.'
                : 'Servicio pagado. Autorización ' . ($r['autorizacion'] ?? '') . '.',
        });
    }

    public function olvidar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        try {
            (new ServicioPagoServicios())->olvidar(
                (string) ($parametros['uuid'] ?? ''),
                $usuarioId
            );
        } catch (ServicioNoDisponible $e) {
            Respuesta::error($e->getMessage(), 404);
            return;
        }

        Respuesta::exito(
            ['guardados' => (new ServicioPagoServicios())->guardados($usuarioId)],
            'Servicio quitado de su lista. Los pagos que ya hizo siguen en su historial.'
        );
    }

    // =================================================================
    // Interno
    // =================================================================

    /**
     * Clave de idempotencia.
     *
     * Si la red se cae a mitad y el cliente vuelve a pulsar, llega la
     * MISMA clave y el libro mayor devuelve el asiento original en
     * lugar de cobrar el recibo dos veces.
     */
    private function claveIdempotencia(): string
    {
        $delCliente = $this->post('_idem');

        if ($delCliente !== '' && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $delCliente)) {
            return 'srv:' . Sesion::usuarioId() . ':' . $delCliente;
        }

        return 'srv:' . Sesion::usuarioId() . ':' . bin2hex(random_bytes(16));
    }

    /** @return array<int,array<string,mixed>> */
    private function cuentasDe(int $usuarioId): array
    {
        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, c.saldo_cent, c.saldo_retenido_cent,
                    p.nombre AS producto, p.saldo_minimo_cent
               FROM cuentas c
               JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.estado = "activa" AND c.es_tecnica = 0
              ORDER BY c.id',
            [$usuarioId]
        );

        foreach ($cuentas as &$c) {
            $c['codigo'] = Hashid::codificar((int) $c['id'], 'cuenta');

            $c['disponible_cent'] = max(
                0,
                (int) $c['saldo_cent'] - (int) $c['saldo_retenido_cent'] - (int) $c['saldo_minimo_cent']
            );

            unset($c['id']);
        }

        return $cuentas;
    }

    /** @return array<string,mixed> */
    private function limiteServicios(int $usuarioId): array
    {
        foreach ((new ServicioLimitesCliente())->panorama($usuarioId) as $l) {
            if ($l['canal'] === 'servicios') {
                return $l;
            }
        }

        return [];
    }
}
