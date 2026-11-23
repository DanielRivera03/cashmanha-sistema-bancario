<?php

declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ChequeraInvalida;
use CashMan\Aplicacion\Servicio\FondosInsuficientes;
use CashMan\Aplicacion\Servicio\ServicioChequera;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Comun\MontoInvalido;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\AccesoDenegado;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\Sesion;

final class ControladorChequeras extends ControladorBase
{
    public function pantalla(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        if (!Autorizacion::puede('chequera.solicitar')) {
            throw new AccesoDenegado('No tiene permiso para operar chequeras.');
        }

        $servicio = new ServicioChequera();

        $this->render('chequeras/mis-chequeras', [
            'titulo'     => 'Chequeras · CashMan H.A.',
            'encabezado' => 'Chequeras y cheques',
            'chequeras'  => $servicio->chequerasDe($usuarioId),
            'corrientes' => $this->cuentasCorrientes($usuarioId),
            'puedeAdmin' => Autorizacion::puede('chequera.administrar'),
            'puedePagar' => Autorizacion::puede('cheque.pagar'),
            'csrf'       => Csrf::token('chequeras'),
        ]);
    }

    public function cheques(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $folio = (string) ($parametros['folio'] ?? '');

        $ch = BaseDatos::unaFila(
            'SELECT id, usuario_id FROM chequeras WHERE folio = ?',
            [$folio]
        );

        if ($ch === null) {
            Respuesta::error('No existe esa chequera.', 404);
            return;
        }

        if ((int) $ch['usuario_id'] !== $usuarioId
            && !Autorizacion::puede('chequera.administrar')) {
            throw new AccesoDenegado('Esa chequera no es suya.');
        }

        Respuesta::exito(
            ['cheques' => (new ServicioChequera())->chequesDe((int) $ch['id'])],
            '',
            ['silencio' => true]
        );
    }

    public function solicitar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $cuentaId = $this->idDesde($this->post('cuenta'), 'cuenta');

        if ($cuentaId === 0) {
            Respuesta::error('Elija la cuenta corriente.', 422);
            return;
        }

        try {
            $r = (new ServicioChequera())->solicitar($cuentaId);
        } catch (ChequeraInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        } catch (FondosInsuficientes $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'fondos_insuficientes']);
            return;
        }

        $r['chequeras'] = (new ServicioChequera())->chequerasDe($usuarioId);

        Respuesta::exito(
            $r,
            'Chequera solicitada: cheques ' . $r['desde'] . ' al ' . $r['hasta'] . '.'
        );
    }

    public function suspender(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $cuentaId = $this->idDesde($this->post('cuenta'), 'cuenta');

        try {
            (new ServicioChequera())->suspender(
                $cuentaId,
                $this->postEntero('numero'),
                (string) $this->post('motivo'),
                (string) $this->post('serie', 'A')
            );
        } catch (ChequeraInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(
            ['chequeras' => (new ServicioChequera())->chequerasDe($usuarioId)],
            'Orden de no pago registrada. Ese cheque ya no se pagará.'
        );
    }

    // =================================================================
    // Caja
    // =================================================================

    public function imprimir(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();

        try {
            $n = (new ServicioChequera())->imprimir(
                (string) ($parametros['folio'] ?? ''),
                $actorId
            );
        } catch (ChequeraInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(['cheques' => $n], $n . ' cheques impresos y listos para entregar.');
    }

    public function entregar(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();

        try {
            (new ServicioChequera())->entregar(
                (string) ($parametros['folio'] ?? ''),
                (string) $this->post('retira'),
                $actorId
            );
        } catch (ChequeraInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito([], 'Chequera entregada, con constancia de quién la retiró.');
    }

    /**
     * Presentar un cheque al cobro.
     *
     * Aquí NO se decide si se paga: eso lo decide el servicio mirando
     * fondos, estado y fechas. Esta pantalla solo transmite lo que el
     * cajero tiene en la mano.
     */
    public function presentar(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();

        $cuentaId = $this->idDesde($this->post('cuenta'), 'cuenta');

        if ($cuentaId === 0) {
            /* También se acepta el número de cuenta tecleado, que es
               como llega un cheque de verdad: el cajero lee el papel,
               no tiene un identificador interno. */
            $cuentaId = (int) (BaseDatos::unValor(
                'SELECT id FROM cuentas WHERE numero_cuenta = ? AND es_tecnica = 0',
                [trim((string) $this->post('numero_cuenta'))]
            ) ?? 0);
        }

        if ($cuentaId === 0) {
            Respuesta::error('No se encontró esa cuenta.', 422);
            return;
        }

        try {
            $monto = Monto::deEntradaUsuario($this->post('monto'));
        } catch (MontoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        try {
            $r = (new ServicioChequera())->presentar(
                $cuentaId,
                $this->postEntero('numero'),
                $monto->centavos,
                (string) $this->post('beneficiario'),
                (string) $this->post('serie', 'A'),
                $actorId
            );
        } catch (ChequeraInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        /* Un rechazo NO es un error de la petición: la operación se
           hizo y su resultado es «no se paga». Devolverlo como error
           haría que la pantalla lo tratara como un fallo técnico. */
        Respuesta::exito(
            $r,
            $r['estado'] === 'pagado'
                ? 'Cheque pagado por ' . $monto->formateado() . '.'
                : 'Cheque RECHAZADO: ' . ($r['mensaje'] ?? '')
        );
    }

    // =================================================================

    /** @return array<int,array<string,mixed>> */
    private function cuentasCorrientes(int $usuarioId): array
    {
        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, c.saldo_cent, p.nombre AS producto
               FROM cuentas c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.estado = "activa"
                AND c.es_tecnica = 0 AND p.tipo = "corriente"
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
