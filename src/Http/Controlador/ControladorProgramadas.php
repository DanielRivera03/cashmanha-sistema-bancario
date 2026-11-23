<?php

declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ProgramadaInvalida;
use CashMan\Aplicacion\Servicio\ServicioProgramadas;
use CashMan\Dominio\Comun\MontoInvalido;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\AccesoDenegado;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\Sesion;

final class ControladorProgramadas extends ControladorBase
{
    public function pantalla(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        if (!Autorizacion::puede('programada.administrar')) {
            throw new AccesoDenegado('No tiene permiso para administrar órdenes programadas.');
        }

        $this->render('programadas/mis-ordenes', [
            'titulo'     => 'Órdenes programadas · CashMan H.A.',
            'encabezado' => 'Transferencias programadas y débito automático',
            'ordenes'    => (new ServicioProgramadas())->deUsuario($usuarioId),
            'cuentas'    => $this->cuentasDe($usuarioId),
            'tarjetas'   => $this->tarjetasDe($usuarioId),
            'csrf'       => Csrf::token('programadas'),
        ]);
    }

    public function crear(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $tipo = (string) $this->post('tipo', 'transferencia');

        $datos = [
            'tipo'             => $tipo,
            'alias'            => (string) $this->post('alias'),
            'cuenta_origen_id' => $this->idDesde($this->post('cuenta_origen'), 'cuenta'),
            'modo_monto'       => (string) $this->post('modo_monto', 'fijo'),
            'monto'            => $this->post('monto', '0'),
            'tope'             => $this->post('tope', '0'),
            'concepto'         => (string) $this->post('concepto'),
            'frecuencia'       => (string) $this->post('frecuencia', 'mensual'),
            'fecha_inicio'     => (string) $this->post('fecha_inicio', date('Y-m-d')),
        ];

        if ($this->post('dia_mes') !== '') {
            $datos['dia_mes'] = $this->postEntero('dia_mes');
        }

        if ($this->post('fecha_fin') !== '') {
            $datos['fecha_fin'] = (string) $this->post('fecha_fin');
        }

        /* El destino, según el tipo. Cada uno llega con su propio
           campo porque cada uno es una cosa distinta. */
        if ($tipo === 'transferencia') {
            $datos['cuenta_destino_id'] = $this->idDesde($this->post('cuenta_destino'), 'cuenta');
        } elseif ($tipo === 'tarjeta') {
            $datos['tarjeta_id'] = $this->idDesde($this->post('tarjeta'), 'tarjeta');
        }

        try {
            $r = (new ServicioProgramadas())->crear($datos);
        } catch (MontoInvalido | ProgramadaInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        $r['ordenes'] = (new ServicioProgramadas())->deUsuario($usuarioId);

        Respuesta::exito(
            $r,
            'Orden creada. La primera ejecución será el '
            . date('d/m/Y', (int) strtotime((string) $r['proxima_en'])) . '.'
        );
    }

    public function pausar(array $parametros = []): void
    {
        $this->accion($parametros, 'pausar', 'Orden pausada. No se cobrará hasta reanudarla.');
    }

    public function reanudar(array $parametros = []): void
    {
        $this->accion(
            $parametros,
            'reanudar',
            'Orden reanudada. No se cobran los periodos que estuvo pausada.'
        );
    }

    public function cancelar(array $parametros = []): void
    {
        $this->accion($parametros, 'cancelar', 'Orden cancelada.');
    }

    /**
     * @param array<string,mixed> $parametros
     */
    private function accion(array $parametros, string $que, string $mensaje): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $servicio = new ServicioProgramadas();
        $folio    = (string) ($parametros['folio'] ?? '');

        try {
            match ($que) {
                'pausar'   => $servicio->pausar($folio, $usuarioId),
                'reanudar' => $servicio->reanudar($folio, $usuarioId),
                'cancelar' => $servicio->cancelar($folio, $usuarioId, (string) $this->post('motivo')),
                default    => null,
            };
        } catch (ProgramadaInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(['ordenes' => $servicio->deUsuario($usuarioId)], $mensaje);
    }

    // =================================================================

    /** @return array<int,array<string,mixed>> */
    private function cuentasDe(int $usuarioId): array
    {
        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, c.saldo_cent, p.nombre AS producto
               FROM cuentas c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.estado = "activa"
                AND c.es_tecnica = 0 AND p.tipo <> "plazo"
              ORDER BY c.id',
            [$usuarioId]
        );

        foreach ($cuentas as &$c) {
            $c['codigo'] = Hashid::codificar((int) $c['id'], 'cuenta');
            unset($c['id']);
        }

        return $cuentas;
    }

    /** @return array<int,array<string,mixed>> */
    private function tarjetasDe(int $usuarioId): array
    {
        $tarjetas = BaseDatos::todas(
            'SELECT t.id, t.ultimos4, t.saldo_usado_cent, p.nombre AS producto
               FROM tarjetas t JOIN productos p ON p.id = t.producto_id
              WHERE t.usuario_id = ? AND t.estado <> "cancelada" AND t.tipo = "credito"
              ORDER BY t.id',
            [$usuarioId]
        );

        foreach ($tarjetas as &$t) {
            $t['codigo'] = Hashid::codificar((int) $t['id'], 'tarjeta');
            unset($t['id']);
        }

        return $tarjetas;
    }
}
