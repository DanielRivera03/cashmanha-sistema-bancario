<?php

declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ReclamoInvalido;
use CashMan\Aplicacion\Servicio\ServicioReclamos;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Comun\MontoInvalido;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\AccesoDenegado;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Reclamos formales.
 *
 * Dos pantallas porque son dos oficios: el cliente presenta y sigue
 * los suyos; el área de atención los analiza y los resuelve contra un
 * plazo legal.
 */
final class ControladorReclamos extends ControladorBase
{
    public function mios(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        Autorizacion::exigir('reclamo.presentar');

        $servicio = new ServicioReclamos();

        $this->render('reclamos/mis-reclamos', [
            'titulo'     => 'Reclamos · CashMan H.A.',
            'encabezado' => 'Mis reclamos',
            'reclamos'   => $servicio->deUsuario($usuarioId),
            'cuentas'    => $this->productosDe($usuarioId),
            'dias'       => (int) $servicio->parametro('dias_habiles_respuesta', '30'),
            'csrf'       => Csrf::token('reclamos'),
        ]);
    }

    public function presentar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $objeto = (string) $this->post('objeto');

        try {
            $r = (new ServicioReclamos())->presentar([
                'asunto'      => (string) $this->post('asunto'),
                'relato'      => (string) $this->post('relato'),
                'categoria'   => (string) $this->post('categoria', 'otro'),
                'monto'       => $this->post('monto', ''),
                'referencia'  => (string) $this->post('referencia'),
                'objeto_tipo' => $objeto !== '' ? 'cuenta' : 'ninguno',
                'objeto_id'   => $objeto !== '' ? $this->idDesde($objeto, 'cuenta') : null,
            ]);
        } catch (MontoInvalido | ReclamoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        $r['reclamos'] = (new ServicioReclamos())->deUsuario($usuarioId);

        Respuesta::exito(
            $r,
            'Recibimos su reclamo con folio ' . $r['folio']
            . '. Le responderemos a más tardar el '
            . date('d/m/Y', (int) strtotime((string) $r['vence_en'])) . '.'
        );
    }

    public function aportar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        try {
            (new ServicioReclamos())->aportar(
                (string) ($parametros['folio'] ?? ''),
                (string) $this->post('texto'),
                $usuarioId
            );
        } catch (ReclamoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(
            ['reclamos' => (new ServicioReclamos())->deUsuario($usuarioId)],
            'Gracias. Seguimos con su reclamo.'
        );
    }

    // =================================================================
    // El área de atención
    // =================================================================

    public function bandeja(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        Autorizacion::exigir('reclamo.atender');

        $servicio = new ServicioReclamos();

        $this->render('reclamos/bandeja', [
            'titulo'     => 'Atención de reclamos · CashMan H.A.',
            'encabezado' => 'Reclamos',
            'reclamos'   => $servicio->abiertos(200),
            'panorama'   => $servicio->panorama(),
            'puedeResolver' => Autorizacion::puede('reclamo.resolver'),
            'csrf'       => Csrf::token('reclamos'),
        ]);
    }

    public function detalle(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        Autorizacion::exigir('reclamo.atender');

        try {
            $r = (new ServicioReclamos())->porFolio((string) ($parametros['folio'] ?? ''));
        } catch (ReclamoInvalido $e) {
            Respuesta::error($e->getMessage(), 404);
            return;
        }

        /* Para el área SÍ se incluyen las notas internas: son suyas. */
        $r['mensajes'] = (new ServicioReclamos())->mensajes((int) $r['id'], true);

        Respuesta::exito($r, '', ['silencio' => true]);
    }

    public function responder(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        try {
            (new ServicioReclamos())->responder(
                (string) ($parametros['folio'] ?? ''),
                (string) $this->post('texto'),
                $this->post('interno') === '1'
            );
        } catch (ReclamoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito([], $this->post('interno') === '1'
            ? 'Nota interna guardada. El cliente no la ve.'
            : 'Respuesta enviada al cliente.');
    }

    public function pedirInformacion(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        try {
            (new ServicioReclamos())->pedirInformacion(
                (string) ($parametros['folio'] ?? ''),
                (string) $this->post('texto')
            );
        } catch (ReclamoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(
            [],
            'Se le pidió la información y el plazo quedó en pausa hasta que conteste.'
        );
    }

    public function prorrogar(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        try {
            $hasta = (new ServicioReclamos())->prorrogar(
                (string) ($parametros['folio'] ?? ''),
                (string) $this->post('motivo')
            );
        } catch (ReclamoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(
            ['vence_en' => $hasta],
            'Plazo prorrogado hasta el ' . date('d/m/Y', (int) strtotime($hasta))
            . '. Es la única prórroga posible.'
        );
    }

    public function resolver(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        if (!Autorizacion::puede('reclamo.resolver')) {
            throw new AccesoDenegado('No tiene permiso para resolver reclamos.');
        }

        $compensacion = 0;

        if ($this->post('compensacion') !== '' && (float) $this->post('compensacion') > 0) {
            try {
                $compensacion = Monto::deEntradaUsuario($this->post('compensacion'))->centavos;
            } catch (MontoInvalido $e) {
                Respuesta::error($e->getMessage(), 422);
                return;
            }
        }

        try {
            $r = (new ServicioReclamos())->resolver(
                (string) ($parametros['folio'] ?? ''),
                $this->post('a_favor') === '1',
                (string) $this->post('resolucion'),
                $compensacion
            );
        } catch (ReclamoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(
            $r,
            'Reclamo resuelto'
            . ($compensacion > 0
                ? ' y se le abonaron ' . Monto::deCentavos($compensacion)->formateado() . '.'
                : '.')
        );
    }

    // =================================================================

    /** @return array<int,array<string,mixed>> */
    private function productosDe(int $usuarioId): array
    {
        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, p.nombre AS producto
               FROM cuentas c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.es_tecnica = 0
              ORDER BY c.id',
            [$usuarioId]
        );

        foreach ($cuentas as &$c) {
            $c['codigo'] = Hashid::codificar((int) $c['id'], 'cuenta');
            unset($c['id']);
        }

        return $cuentas;
    }
}
