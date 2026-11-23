<?php

declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\BeneficiarioInvalido;
use CashMan\Aplicacion\Servicio\DesafioInvalido;
use CashMan\Aplicacion\Servicio\FondosInsuficientes;
use CashMan\Aplicacion\Servicio\LimiteExcedidoTransferencia;
use CashMan\Aplicacion\Servicio\ServicioBeneficiarios;
use CashMan\Aplicacion\Servicio\ServicioDesafio;
use CashMan\Aplicacion\Servicio\ServicioInterbancario;
use CashMan\Aplicacion\Servicio\ServicioLimitesCliente;
use CashMan\Aplicacion\Servicio\TransferenciaRechazada;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Comun\MontoInvalido;
use CashMan\Dominio\Interbancario\RedDePagos;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\AccesoDenegado;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\LimitadorTasa;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * La pantalla de enviar dinero a otros bancos.
 *
 * Tres cosas conviven aquí porque son una sola historia para el
 * cliente: a quién le puede mandar (la agenda), cuánto puede mandar
 * (los límites) y el envío en sí.
 */
final class ControladorInterbancario extends ControladorBase
{
    // =================================================================
    // Pantalla
    // =================================================================

    public function formulario(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        if (!Autorizacion::puede('transferencia.ejecutar')) {
            throw new AccesoDenegado('No tiene permiso para transferir.');
        }

        $this->render('interbancario/enviar', [
            'titulo'        => 'Transfer365 · CashMan H.A.',
            'encabezado'    => 'Enviar a otro banco',
            'cuentas'       => $this->cuentasDe($usuarioId),
            'beneficiarios' => $this->agendaParaPantalla($usuarioId),
            'bancos'        => $this->bancos(),
            'limites'       => (new ServicioLimitesCliente())->panorama($usuarioId),
            'ventanas'      => $this->ventanas(),
            'csrf'          => Csrf::token('interbancario'),
        ]);
    }

    public function agenda(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $this->render('interbancario/agenda', [
            'titulo'        => 'Mis destinos · CashMan H.A.',
            'encabezado'    => 'Destinos guardados',
            'beneficiarios' => $this->agendaParaPantalla($usuarioId),
            'bancos'        => $this->bancos(),
            'enfriamiento'  => ServicioBeneficiarios::ENFRIAMIENTO_HORAS,
            'csrf'          => Csrf::token('interbancario'),
        ]);
    }

    public function historial(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $this->render('interbancario/historial', [
            'titulo'          => 'Transferencias a otros bancos · CashMan H.A.',
            'encabezado'      => 'Transferencias a otros bancos',
            'transferencias'  => (new ServicioInterbancario())->historial($usuarioId, 200),
            'csrf'            => Csrf::token('interbancario'),
        ]);
    }

    // =================================================================
    // La agenda
    // =================================================================

    public function guardarBeneficiario(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        try {
            $r = (new ServicioBeneficiarios())->registrar([
                'alias'             => (string) $this->post('alias'),
                'banco_id'          => (int) $this->post('banco_id'),
                'numero_cuenta'     => (string) $this->post('numero_cuenta'),
                'tipo_cuenta'       => (string) $this->post('tipo_cuenta', 'ahorro'),
                'titular_nombre'    => (string) $this->post('titular_nombre'),
                'titular_documento' => (string) $this->post('titular_documento'),
                'celular'           => (string) $this->post('celular'),
                'codigo_otp'        => (string) $this->post('codigo'),
            ]);
        } catch (BeneficiarioInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        } catch (DesafioInvalido $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'otp_invalido']);
            return;
        }

        /* La agenda entera vuelve en la respuesta: la pantalla se
           repinta sin recargar, que es como funciona el resto del
           sistema. */
        $r['agenda'] = $this->agendaParaPantalla($usuarioId);

        Respuesta::exito(
            $r,
            $r['estado'] === 'activo'
                ? 'Destino guardado. Ya puede enviarle dinero.'
                : 'Destino guardado. Podrá usarlo dentro de '
                  . ServicioBeneficiarios::ENFRIAMIENTO_HORAS . ' horas.'
        );
    }

    public function codigoBeneficiario(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        (new LimitadorTasa(5, 300, 300))->exigir('otp-benef:' . $usuarioId);

        $bancoId = (int) $this->post('banco_id');
        $cuenta  = RedDePagos::normalizarCuenta((string) $this->post('numero_cuenta'));
        $celular = RedDePagos::normalizarCelular((string) $this->post('celular'));

        if ($bancoId === 0 || ($cuenta === '' && $celular === '')) {
            Respuesta::error('Complete el banco y la cuenta antes de pedir el código.', 422);
            return;
        }

        $clave = $cuenta !== '' ? $cuenta : 'CEL:' . $celular;

        $r = (new ServicioDesafio())->emitir(
            $usuarioId,
            'beneficiario',
            hash('sha256', "beneficiario|{$bancoId}|{$clave}"),
            'Alta de un destino en su agenda'
        );

        Respuesta::exito($r, $r['mensaje']);
    }

    public function bloquearBeneficiario(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        try {
            (new ServicioBeneficiarios())->bloquear((string) ($parametros['uuid'] ?? ''), $usuarioId);
        } catch (BeneficiarioInvalido $e) {
            Respuesta::error($e->getMessage(), 404);
            return;
        }

        Respuesta::exito(
            ['agenda' => $this->agendaParaPantalla($usuarioId)],
            'Destino bloqueado. Ya no se le puede enviar dinero.'
        );
    }

    public function eliminarBeneficiario(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        try {
            (new ServicioBeneficiarios())->eliminar((string) ($parametros['uuid'] ?? ''), $usuarioId);
        } catch (BeneficiarioInvalido $e) {
            Respuesta::error($e->getMessage(), 404);
            return;
        }

        Respuesta::exito(
            ['agenda' => $this->agendaParaPantalla($usuarioId)],
            'Destino eliminado de su agenda.'
        );
    }

    // =================================================================
    // El envío
    // =================================================================

    public function solicitarCodigo(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        (new LimitadorTasa(5, 300, 300))->exigir('otp-inter:' . $usuarioId);

        $origenId = $this->idDesde($this->post('origen'), 'cuenta');
        $uuid     = (string) $this->post('beneficiario');

        if ($origenId === 0 || $uuid === '') {
            Respuesta::error('Elija la cuenta y el destino antes de pedir el código.', 422);
            return;
        }

        Autorizacion::exigirCuenta($origenId, $usuarioId, 'transferencia.ejecutar_terceros');

        try {
            $monto = Monto::deEntradaUsuario($this->post('monto'));
        } catch (MontoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        try {
            $benef = (new ServicioBeneficiarios())->exigirDe($uuid, $usuarioId);
        } catch (BeneficiarioInvalido $e) {
            Respuesta::error($e->getMessage(), 404);
            return;
        }

        $r = (new ServicioDesafio())->emitir(
            $usuarioId,
            'interbancaria',
            hash('sha256', "interbancaria|{$origenId}|{$uuid}|{$monto->centavos}"),
            'Envío de ' . $monto->formateado() . ' a ' . $benef['alias']
            . ' (' . $benef['banco'] . ')'
        );

        Respuesta::exito($r, $r['mensaje']);
    }

    public function enviar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $origenId = $this->idDesde($this->post('origen'), 'cuenta');

        if ($origenId === 0) {
            Respuesta::error('Cuenta de origen inválida.', 422);
            return;
        }

        try {
            $r = (new ServicioInterbancario())->enviar([
                'cuenta_origen_id'   => $origenId,
                'beneficiario_uuid'  => (string) $this->post('beneficiario'),
                'canal'              => (string) $this->post('canal', 'transfer365'),
                'monto'              => $this->post('monto'),
                'concepto'           => (string) $this->post('concepto', ''),
                'codigo_otp'         => (string) $this->post('codigo'),
                'clave_idempotencia' => $this->claveIdempotencia(),
            ]);
        } catch (MontoInvalido | TransferenciaRechazada | BeneficiarioInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        } catch (FondosInsuficientes $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'fondos_insuficientes']);
            return;
        } catch (LimiteExcedidoTransferencia $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'limite_excedido']);
            return;
        } catch (DesafioInvalido $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'otp_invalido']);
            return;
        }

        /* Lo que la pantalla necesita para repintarse sin recargar. */
        $r['cuentas'] = $this->cuentasDe($usuarioId);
        $r['limites'] = (new ServicioLimitesCliente())->panorama($usuarioId);

        Respuesta::exito($r, match ($r['estado']) {
            'aplicada'             => 'Transferencia aplicada. El dinero ya está en la cuenta destino.',
            'enviada'              => 'Transferencia enviada. Folio ' . ($r['folio'] ?? '') . '.',
            'creada'               => 'Quedó programada: saldrá en la próxima apertura del servicio.',
            'duplicado'            => 'Esta operación ya se había procesado.',
            'pendiente_aprobacion' => 'Por el monto, la operación quedó pendiente de aprobación.',
            default                => 'Operación registrada.',
        });
    }

    // =================================================================
    // Límites
    // =================================================================

    public function limites(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $this->render('interbancario/limites', [
            'titulo'     => 'Mis límites · CashMan H.A.',
            'encabezado' => 'Mis límites por canal',
            'limites'    => (new ServicioLimitesCliente())->panorama($usuarioId),
            'csrf'       => Csrf::token('interbancario'),
        ]);
    }

    public function guardarLimites(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $servicio = new ServicioLimitesCliente();

        $canal = (string) $this->post('canal');

        try {
            $operacion = Monto::deEntradaUsuario($this->post('max_operacion'))->centavos;
            $diario    = Monto::deEntradaUsuario($this->post('max_diario'))->centavos;
        } catch (MontoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        $ops = max(1, $this->postEntero('max_ops', 10));

        /* Subir un límite pide segundo factor; bajarlo, no.
         *
         * No es una asimetría caprichosa: bajarlo solo puede proteger
         * al cliente, mientras que subirlo es exactamente lo primero
         * que haría quien le robó la sesión. */
        if ($servicio->esSubida($usuarioId, $canal, $operacion, $diario, $ops)) {
            try {
                (new ServicioDesafio())->consumir(
                    $usuarioId,
                    'limites',
                    (string) $this->post('codigo'),
                    hash('sha256', "limites|{$canal}|{$operacion}|{$diario}|{$ops}")
                );
            } catch (DesafioInvalido $e) {
                Respuesta::error($e->getMessage(), 422, ['codigo' => 'otp_invalido']);
                return;
            }
        }

        try {
            $r = $servicio->fijar($usuarioId, $canal, $operacion, $diario, $ops, $usuarioId);
        } catch (LimiteExcedidoTransferencia $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        $r['limites'] = $servicio->panorama($usuarioId);

        Respuesta::exito($r, (string) $r['mensaje']);
    }

    public function codigoLimites(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        (new LimitadorTasa(5, 300, 300))->exigir('otp-limites:' . $usuarioId);

        $canal = (string) $this->post('canal');

        try {
            $operacion = Monto::deEntradaUsuario($this->post('max_operacion'))->centavos;
            $diario    = Monto::deEntradaUsuario($this->post('max_diario'))->centavos;
        } catch (MontoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        $ops = max(1, $this->postEntero('max_ops', 10));

        $r = (new ServicioDesafio())->emitir(
            $usuarioId,
            'limites',
            hash('sha256', "limites|{$canal}|{$operacion}|{$diario}|{$ops}"),
            'Subida de su límite de ' . $canal
        );

        Respuesta::exito($r, $r['mensaje']);
    }

    // =================================================================
    // Interno
    // =================================================================

    /**
     * Clave de idempotencia.
     *
     * La envía el navegador, una por intento. Si la red se cae a mitad
     * y el cliente vuelve a pulsar, llega la MISMA clave y el libro
     * mayor devuelve el asiento original en lugar de mandar el dinero
     * dos veces. Sin esto, un botón pulsado dos veces con mala
     * conexión es dinero enviado dos veces.
     */
    private function claveIdempotencia(): string
    {
        $delCliente = $this->post('_idem');

        if ($delCliente !== '' && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $delCliente)) {
            return 'inter:' . Sesion::usuarioId() . ':' . $delCliente;
        }

        return 'inter:' . Sesion::usuarioId() . ':' . bin2hex(random_bytes(16));
    }

    /** @return array<int,array<string,mixed>> */
    private function cuentasDe(int $usuarioId): array
    {
        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, c.saldo_cent, c.moneda, c.saldo_retenido_cent,
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

    /**
     * La agenda lista para pintar: sin ids internos y con el número
     * de cuenta enmascarado.
     *
     * Que el cliente vea el número completo de un destino suyo no
     * aporta nada y sí lo expone: basta con los últimos dígitos para
     * reconocerlo, y es lo que muestran los bancos.
     *
     * @return array<int,array<string,mixed>>
     */
    private function agendaParaPantalla(int $usuarioId): array
    {
        $filas = (new ServicioBeneficiarios())->listar($usuarioId);

        $limpias = [];

        foreach ($filas as $f) {
            $numero = (string) $f['numero_cuenta'];
            $esCel  = str_starts_with($numero, 'CEL:');

            $limpias[] = [
                'uuid'        => $f['uuid'],
                'alias'       => $f['alias'],
                'banco'       => $f['banco'],
                'banco_nombre' => $f['banco_nombre'],
                'es_propio'   => (int) $f['es_propio'] === 1,
                'titular'     => $f['titular_nombre'],
                'tipo_cuenta' => $f['tipo_cuenta'],
                'destino'     => $esCel
                    ? RedDePagos::celularLegible(substr($numero, 4))
                    : '••••' . substr($numero, -4),
                'por_celular' => $esCel,
                'estado'      => $f['estado'],
                'usable'      => (int) $f['usable'] === 1,
                'faltan_min'  => (int) $f['faltan_min'],
                'veces_usado' => (int) $f['veces_usado'],
                'canales'     => array_values(array_filter([
                    (int) $f['transfer365'] === 1 ? 'transfer365' : null,
                    ((int) $f['transfer365_movil'] === 1 && $f['celular'] !== '')
                        ? 'transfer365_movil' : null,
                    (int) $f['uni'] === 1 ? 'uni' : null,
                ])),
            ];
        }

        return $limpias;
    }

    /** @return array<int,array<string,mixed>> */
    private function bancos(): array
    {
        return BaseDatos::todas(
            'SELECT id, nombre, nombre_corto, tipo, transfer365, transfer365_movil, uni, es_propio
               FROM bancos_participantes
              WHERE activo = 1
              ORDER BY es_propio DESC, nombre_corto'
        );
    }

    /** @return array<string,array<string,mixed>> */
    private function ventanas(): array
    {
        $v = [];

        foreach (['transfer365', 'transfer365_movil', 'uni'] as $canal) {
            $abierta = RedDePagos::ventanaAbierta($canal);

            $v[$canal] = [
                'abierta' => $abierta,
                'abre_a'  => $abierta ? null : date('c', RedDePagos::proximaVentana($canal)),
            ];
        }

        return $v;
    }
}
