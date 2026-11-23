<?php

declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\FondosInsuficientes;
use CashMan\Aplicacion\Servicio\PlazoInvalido;
use CashMan\Aplicacion\Servicio\ServicioPlazoFijo;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Comun\MontoInvalido;
use CashMan\Dominio\Plazo\CalculoPlazo;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\AccesoDenegado;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\Sesion;

final class ControladorPlazoFijo extends ControladorBase
{
    public function pantalla(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        if (!Autorizacion::puede('plazo.abrir_propio')) {
            throw new AccesoDenegado('No tiene permiso para operar depósitos a plazo.');
        }

        $servicio = new ServicioPlazoFijo();

        $this->render('plazo/mis-plazos', [
            'titulo'     => 'Depósitos a plazo · CashMan H.A.',
            'encabezado' => 'Depósitos a plazo fijo',
            'depositos'  => $servicio->deUsuario($usuarioId),
            'cuentas'    => $this->cuentasDe($usuarioId),
            'tarifario'  => CalculoPlazo::tarifario(),
            'retencion'  => [
                'bp'           => CalculoPlazo::retencionBp(),
                'exento_cent'  => CalculoPlazo::exentoHastaCent(),
                'penalidad_bp' => CalculoPlazo::penalidadBp(),
            ],
            'csrf'       => Csrf::token('plazo'),
        ]);
    }

    public function simular(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        try {
            $monto = Monto::deEntradaUsuario($this->post('monto'));
        } catch (MontoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        try {
            $r = (new ServicioPlazoFijo())->simular($monto->centavos, $this->postEntero('dias'));
        } catch (PlazoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        /* No muta nada: solo calcula. Un aviso de «Listo» cada vez que
           alguien mueve el deslizador de plazos sería ruido. */
        Respuesta::exito($r, 'Proyección calculada.', ['silencio' => true]);
    }

    public function constituir(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $origenId = $this->idDesde($this->post('cuenta_origen'), 'cuenta');

        if ($origenId === 0) {
            Respuesta::error('Elija la cuenta de la que sale el dinero.', 422);
            return;
        }

        $abono = $this->idDesde($this->post('cuenta_abono'), 'cuenta');

        try {
            $r = (new ServicioPlazoFijo())->constituir([
                'cuenta_origen_id' => $origenId,
                'cuenta_abono_id'  => $abono !== 0 ? $abono : $origenId,
                'monto'            => $this->post('monto'),
                'dias'             => $this->postEntero('dias'),
                'renovacion'       => (string) $this->post('renovacion', 'no'),
            ]);
        } catch (MontoInvalido | PlazoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        } catch (FondosInsuficientes $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'fondos_insuficientes']);
            return;
        }

        $servicio = new ServicioPlazoFijo();

        $r['depositos'] = $servicio->deUsuario($usuarioId);
        $r['cuentas']   = $this->cuentasDe($usuarioId);

        Respuesta::exito(
            $r,
            'Depósito constituido con folio ' . $r['folio']
            . '. Vence el ' . date('d/m/Y', (int) strtotime((string) $r['fecha_vence'])) . '.'
        );
    }

    /**
     * Cancelación anticipada.
     *
     * Es irreversible y cuesta intereses, así que el servicio exige
     * que el cliente haya visto cuánto pierde: la pantalla se lo
     * enseña antes de pedir confirmación.
     */
    public function cancelar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $folio = (string) ($parametros['folio'] ?? '');

        $d = BaseDatos::unaFila(
            'SELECT usuario_id, estado FROM depositos_plazo WHERE folio = ?',
            [$folio]
        );

        if ($d === null) {
            Respuesta::error('No existe ese depósito.', 404);
            return;
        }

        if ((int) $d['usuario_id'] !== $usuarioId
            && !Autorizacion::puede('plazo.cancelar_terceros')) {
            throw new AccesoDenegado('Ese depósito no es suyo.');
        }

        try {
            $r = (new ServicioPlazoFijo())->liquidar($folio, $usuarioId, true);
        } catch (PlazoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        $servicio = new ServicioPlazoFijo();

        $r['depositos'] = $servicio->deUsuario($usuarioId);
        $r['cuentas']   = $this->cuentasDe($usuarioId);

        Respuesta::exito(
            $r,
            'Depósito cancelado. Se abonaron '
            . Monto::deCentavos((int) $r['total_cent'])->formateado() . ' a su cuenta.'
        );
    }

    // =================================================================

    /** @return array<int,array<string,mixed>> */
    private function cuentasDe(int $usuarioId): array
    {
        /* Las de plazo quedan fuera: no se puede constituir un depósito
           con el dinero de otro depósito sin cancelarlo antes. */
        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, c.saldo_cent, c.saldo_retenido_cent,
                    p.nombre AS producto, p.saldo_minimo_cent
               FROM cuentas c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.estado = "activa"
                AND c.es_tecnica = 0 AND p.tipo <> "plazo"
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
}
