<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Aplicacion\Servicio\DesafioInvalido;
use CashMan\Aplicacion\Servicio\ServicioDesafio;
use CashMan\Aplicacion\Servicio\ServicioAdelantoEfectivo;
use CashMan\Aplicacion\Servicio\ServicioTarjeta;
use CashMan\Dominio\Comun\MontoInvalido;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\LimitadorTasa;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Acciones avanzadas de tarjeta: revelar datos, diferir compras,
 * trasladar saldo, configurar controles y congelar.
 */
final class ControladorTarjetaAcciones extends ControladorBase
{
    // -----------------------------------------------------------------
    // REVELAR DATOS
    // -----------------------------------------------------------------

    /** Paso 1: pedir el código de verificación. */
    public function pedirCodigoRevelacion(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $id = $this->tarjetaDesde($parametros);

        (new LimitadorTasa(5, 600, 600))->exigir('revelar:' . $usuarioId);

        $t = Autorizacion::exigirTarjeta($id, $usuarioId);
        if ((int) $t['usuario_id'] !== $usuarioId) {
            Respuesta::error('Solo el titular puede ver los datos de la tarjeta.', 403);
        }

        $r = (new ServicioDesafio())->emitir(
            $usuarioId, 'alta_tarjeta',
            hash('sha256', "revelar|{$id}"),
            'Ver datos de la tarjeta ••••' . $t['ultimos4']
        );

        Respuesta::exito($r, $r['mensaje']);
    }

    /** Paso 2: canjear el código por un token de un solo uso. */
    public function solicitarRevelacion(array $parametros = []): void
    {
        $id = $this->tarjetaDesde($parametros);

        try {
            $r = (new ServicioTarjeta())->solicitarRevelacion($id, $this->post('codigo'));
        } catch (DesafioInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 403);
            return;
        }

        Respuesta::exito($r, 'Token generado.');
    }

    /** Paso 3: canjear el token por los datos. Un solo uso. */
    public function revelar(array $parametros = []): void
    {
        $id = $this->tarjetaDesde($parametros);

        try {
            $r = (new ServicioTarjeta())->revelar($id, $this->post('token'));
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 403);
            return;
        }

        // Nunca cachear una respuesta con el PAN.
        Respuesta::json(['ok' => true, 'datos' => $r], 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma'        => 'no-cache',
        ]);
    }

    // -----------------------------------------------------------------
    // COMPRAS A CUOTAS
    // -----------------------------------------------------------------

    /** Simulador: compara plazos y modalidades sin comprometerse. */
    public function simular(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $id = $this->tarjetaDesde($parametros);

        $t = Autorizacion::exigirTarjeta($id, $usuarioId);

        $movId = (int) $this->post('movimiento', '0');
        $monto = 0;

        if ($movId > 0) {
            $m = BaseDatos::unaFila(
                'SELECT importe_cent FROM tarjeta_movimientos
                  WHERE id = ? AND tarjeta_id = ? AND tipo = "consumo" AND plan_id IS NULL',
                [$movId, $id]
            );
            if ($m === null) {
                Respuesta::error('Movimiento no diferible.', 404);
            }
            $monto = (int) $m['importe_cent'];
        } else {
            try {
                $monto = \CashMan\Dominio\Comun\Monto::deEntradaUsuario($this->post('monto'))->centavos;
            } catch (MontoInvalido $e) {
                Respuesta::error($e->getMessage(), 422);
                return;
            }
        }

        Respuesta::exito([
            'monto_cent' => $monto,
            'tasa_bp'    => (int) $t['tasa_diferido_bp'],
            'opciones'   => (new ServicioTarjeta())->simularDiferido($monto, (int) $t['tasa_diferido_bp']),
        ]);
    }

    /**
     * Entrega el estado de cuenta de un corte, en PDF.
     *
     * El corte tiene que ser de ESTA tarjeta: sin esa comprobación,
     * cambiar un número en la dirección enseñaría el estado de cuenta
     * de otra persona.
     */
    public function estadoCuenta(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $id = $this->tarjetaDesde($parametros);

        Autorizacion::exigirTarjeta($id, $usuarioId);

        $corteId = (int) BaseDatos::unValor(
            'SELECT id FROM tarjeta_cortes WHERE id = ? AND tarjeta_id = ?',
            [(int) ($parametros['corte'] ?? 0), $id]
        );

        if ($corteId === 0) {
            throw new \CashMan\Infraestructura\Seguridad\RecursoNoEncontrado(
                'Estado de cuenta no encontrado.'
            );
        }

        try {
            $doc = (new \CashMan\Infraestructura\Documento\ServicioDocumentos())
                ->estadoTarjeta($corteId);
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Auditoria::registrar('tarjeta.estado_descargado', Auditoria::EXITO, $usuarioId,
            'tarjeta_corte', (string) $corteId, ['hash' => $doc['hash']]);

        /* Por el camino común. Así el documento sale en su pestaña Y la
           pantalla que lo pidió se entera de que ya salió, que es lo
           que apaga el indicador de espera. */
        Respuesta::ver((string) $doc['ruta'], basename((string) $doc['ruta']));
    }

    public function diferir(array $parametros = []): void
    {
        $this->tarjetaDesde($parametros);   // valida la tarjeta de la URL

        $movId  = (int) $this->post('movimiento', '0');
        $cuotas = (int) $this->post('cuotas', '0');
        $cero   = $this->post('modalidad', 'tasa_cero') === 'tasa_cero';

        if ($movId <= 0 || $cuotas <= 0) {
            Respuesta::error('Seleccione el consumo y el plazo.', 422);
        }

        try {
            $r = (new ServicioTarjeta())->diferirCompra($movId, $cuotas, $cero);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito($r, 'Compra diferida a ' . $r['cuotas'] . ' cuotas. Folio ' . $r['folio']);
    }

    // -----------------------------------------------------------------
    // TRASLADO DE SALDO
    // -----------------------------------------------------------------

    public function pedirCodigoTraslado(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        Autorizacion::exigir('tarjeta.trasladar');

        (new LimitadorTasa(5, 300, 300))->exigir('traslado:' . $usuarioId);

        $origenId  = $this->idDesde($this->post('origen'), 'tarjeta');
        $destinoId = $this->idDesde($this->post('destino'), 'tarjeta');

        if ($origenId === 0 || $destinoId === 0) {
            Respuesta::error('Seleccione las dos tarjetas.', 422);
        }

        Autorizacion::exigirTarjeta($origenId, $usuarioId);
        Autorizacion::exigirTarjeta($destinoId, $usuarioId);

        try {
            $monto = \CashMan\Dominio\Comun\Monto::deEntradaUsuario($this->post('monto'));
        } catch (MontoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        $r = (new ServicioDesafio())->emitir(
            $usuarioId, 'transferencia',
            hash('sha256', "traslado|{$origenId}|{$destinoId}|{$monto->centavos}"),
            'Traslado de saldo por ' . $monto->formateado()
        );

        Respuesta::exito($r, $r['mensaje']);
    }

    public function trasladar(array $parametros = []): void
    {
        $origenId  = $this->idDesde($this->post('origen'), 'tarjeta');
        $destinoId = $this->idDesde($this->post('destino'), 'tarjeta');

        if ($origenId === 0 || $destinoId === 0) {
            Respuesta::error('Seleccione las dos tarjetas.', 422);
        }

        try {
            $r = (new ServicioTarjeta())->trasladarSaldo(
                $origenId, $destinoId,
                $this->post('monto'),
                (int) $this->post('cuotas', '12'),
                $this->post('codigo')
            );
        } catch (DesafioInvalido $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'otp_invalido']);
            return;
        } catch (\InvalidArgumentException | \RuntimeException | MontoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito($r, 'Saldo trasladado. Folio ' . $r['folio']);
    }

    /**
     * Lo que costaría un adelanto, para enseñarlo antes de confirmar.
     *
     * La pantalla lo pide mientras el cliente teclea. Un adelanto de
     * $500 deja debiendo $528, y enterarse de eso en el estado de
     * cuenta es una reclamación en la ventanilla.
     */
    public function simularAdelanto(array $parametros = []): void
    {
        $tarjetaId = $this->idDesde($this->post('tarjeta'), 'tarjeta');

        if ($tarjetaId === 0) {
            Respuesta::error('Seleccione la tarjeta.', 422);
            return;
        }

        try {
            $servicio = new ServicioAdelantoEfectivo();

            $r = $servicio->simular($tarjetaId, $this->post('monto'));
            $r['maximo'] = $servicio->maximoDisponible($tarjetaId)->jsonSerialize();
        } catch (\InvalidArgumentException | \RuntimeException | MontoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito($r);
    }

    /** Adelanto de efectivo: de la línea de la tarjeta a una cuenta. */
    public function adelantar(array $parametros = []): void
    {
        $tarjetaId = $this->idDesde($this->post('tarjeta'), 'tarjeta');
        $cuentaId  = $this->idDesde($this->post('cuenta'), 'cuenta');

        if ($tarjetaId === 0 || $cuentaId === 0) {
            Respuesta::error('Indique la tarjeta y la cuenta donde recibir el dinero.', 422);
            return;
        }

        try {
            $r = (new ServicioAdelantoEfectivo())->adelantar(
                $tarjetaId,
                $cuentaId,
                $this->post('monto')
            );
        } catch (\InvalidArgumentException | \RuntimeException | MontoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(
            $r,
            'Se acreditaron ' . $r['recibe']['formateado'] . ' a su cuenta '
            . $r['cuenta'] . '. A su tarjeta se le cargaron '
            . $r['se_le_debe']['formateado'] . ' con la comisión y el gasto.'
        );
    }

    // -----------------------------------------------------------------
    // CONTROLES
    // -----------------------------------------------------------------

    public function configurar(array $parametros = []): void
    {
        $id = $this->tarjetaDesde($parametros);

        $ajustes = [];

        // El alias puede quedar vacío a propósito: es quitarlo.
        if (isset($_POST['alias'])) {
            $ajustes['alias'] = $this->post('alias');
        }

        /* El monto de aviso sí se lee aunque venga en cero: cero
         * significa «no me avise», que es una decisión, no un campo sin
         * llenar. Por eso no se filtra por cadena vacía como el resto. */
        if (isset($_POST['alerta_consumo']) && $this->post('alerta_consumo') !== '') {
            $ajustes['alerta_consumo'] = $this->post('alerta_consumo');
        }

        foreach (['compras_internet', 'compras_exterior', 'contactless', 'sobregiro_permitido'] as $c) {
            if (isset($_POST[$c])) {
                $ajustes[$c] = $_POST[$c] === '1';
            }
        }

        if (isset($_POST['sobregiro_tolerancia_bp'])) {
            $ajustes['sobregiro_tolerancia_bp'] = (int) $_POST['sobregiro_tolerancia_bp'];
        }

        if (isset($_POST['pago_automatico'])) {
            $ajustes['pago_automatico'] = $this->post('pago_automatico');
            $ajustes['pago_automatico_cuenta_id'] = $this->postEntero('pago_automatico_cuenta_id');
        }

        try {
            (new ServicioTarjeta())->configurar($id, $ajustes);
        } catch (\InvalidArgumentException | MontoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(null, 'Configuración actualizada.');
    }

    public function congelar(array $parametros = []): void
    {
        $id = $this->tarjetaDesde($parametros);

        try {
            $r = $this->post('accion') === 'descongelar'
                ? (new ServicioTarjeta())->descongelar($id)
                : (new ServicioTarjeta())->congelar($id, (int) $this->post('horas', '24'));
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito($r, $this->post('accion') === 'descongelar'
            ? 'Tarjeta reactivada.'
            : 'Tarjeta congelada temporalmente.');
    }

    /** Simula un consumo, para poder probar el ciclo completo. */
    public function simularConsumo(array $parametros = []): void
    {
        $id = $this->tarjetaDesde($parametros);

        try {
            $r = (new ServicioTarjeta())->simularConsumo(
                $id,
                $this->post('monto'),
                $this->post('comercio', 'Comercio de prueba'),
                $this->post('categoria', 'varios')
            );
        } catch (\RuntimeException | MontoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito($r, 'Consumo registrado: ' . $r['referencia']);
    }

    // -----------------------------------------------------------------

    private function tarjetaDesde(array $parametros): int
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'tarjeta');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Tarjeta no encontrada.');
        }
        return $id;
    }
}
