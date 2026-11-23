<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioAtencionVentanilla;

use CashMan\Aplicacion\Servicio\FondosInsuficientes;
use CashMan\Aplicacion\Servicio\DesafioInvalido;
use CashMan\Aplicacion\Servicio\LimiteExcedidoTransferencia;
use CashMan\Aplicacion\Servicio\ServicioCaja;
use CashMan\Aplicacion\Servicio\ServicioDesafio;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Comun\MontoInvalido;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\LimitadorTasa;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Ventanilla: depósitos, retiros y apertura de cuentas.
 */
final class ControladorCaja extends ControladorBase
{
    public function formularioDeposito(array $parametros = []): void
    {
        Autorizacion::exigir('deposito.registrar');

        $this->render('caja/deposito', [
            'titulo'     => 'Registrar depósito · CashMan H.A.',
            'encabezado' => 'Registrar depósito',
            'limite'     => $this->limite('deposito'),
            'csrf'       => Csrf::token('caja'),
            'ultimos'    => $this->ultimasOperaciones('deposito'),
            /* La cuenta con la que viene el cajero desde la ficha del
               cliente. Llegar aquí y tener que volver a teclear el
               número que acaba de pulsar era un paso de más, y el sitio
               donde se equivoca uno de dígito. */
            'cuenta'     => $this->cuentaPrecargada(),
            // En ventanilla el cliente está delante: ver `enVentanilla()`.
            'ventanilla' => $this->hayTurnoAbierto(),
        ]);
    }

    public function depositar(array $parametros = []): void
    {
        $cuentaId = $this->idDesde($this->post('cuenta'), 'cuenta');
        if ($cuentaId === 0) {
            Respuesta::error('Seleccione una cuenta válida.', 422);
        }

        try {
            $r = (new ServicioCaja())->depositar([
                'cuenta_id'          => $cuentaId,
                'monto'              => $this->post('monto'),
                'concepto'           => $this->post('concepto', 'Depósito en ventanilla'),
                // Con qué paga: solo el efectivo cuenta en el arqueo.
                'medio'              => $this->post('medio', 'efectivo'),
                'cheque_banco'       => $this->post('cheque_banco'),
                'cheque_numero'      => $this->post('cheque_numero'),
                'clave_idempotencia' => $this->idem('dep'),
            ]);
        } catch (MontoInvalido | LimiteExcedidoTransferencia | \InvalidArgumentException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito($r, $r['estado'] === 'duplicado'
            ? $r['mensaje']
            : 'Depósito acreditado. Referencia: ' . $r['referencia']);
    }

    public function formularioRetiro(array $parametros = []): void
    {
        Autorizacion::exigir('retiro.registrar');

        $this->render('caja/retiro', [
            'titulo'     => 'Registrar retiro · CashMan H.A.',
            'encabezado' => 'Registrar retiro',
            'limite'     => $this->limite('retiro'),
            'csrf'       => Csrf::token('caja'),
            'ultimos'    => $this->ultimasOperaciones('retiro'),
            /* La cuenta con la que viene el cajero desde la ficha del
               cliente. Llegar aquí y tener que volver a teclear el
               número que acaba de pulsar era un paso de más, y el sitio
               donde se equivoca uno de dígito. */
            'cuenta'     => $this->cuentaPrecargada(),
            // En ventanilla el cliente está delante: ver `enVentanilla()`.
            'ventanilla' => $this->hayTurnoAbierto(),
        ]);
    }

    public function retirar(array $parametros = []): void
    {
        $cuentaId = $this->idDesde($this->post('cuenta'), 'cuenta');
        if ($cuentaId === 0) {
            Respuesta::error('Seleccione una cuenta válida.', 422);
        }

        try {
            $r = (new ServicioCaja())->retirar([
                'cuenta_id'          => $cuentaId,
                'monto'              => $this->post('monto'),
                'concepto'           => $this->post('concepto', 'Retiro en ventanilla'),
                'codigo_otp'         => $this->post('codigo'),
                'medio'              => $this->post('medio', 'efectivo'),
                'cheque_banco'       => $this->post('cheque_banco'),
                'cheque_numero'      => $this->post('cheque_numero'),
                'clave_idempotencia' => $this->idem('ret'),
            ]);
        } catch (MontoInvalido | LimiteExcedidoTransferencia | \InvalidArgumentException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        } catch (FondosInsuficientes $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'fondos_insuficientes']);
            return;
        } catch (DesafioInvalido $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'otp_invalido']);
            return;
        }

        Respuesta::exito($r, $r['estado'] === 'duplicado'
            ? $r['mensaje']
            : 'Retiro procesado. Referencia: ' . $r['referencia']);
    }

    /** Código OTP para retiros. */
    public function codigoRetiro(array $parametros = []): void
    {
        Autorizacion::exigir('retiro.registrar');
        $actorId = Sesion::exigirAutenticado();

        (new LimitadorTasa(5, 300, 300))->exigir('otp-retiro:' . $actorId);

        $cuentaId = $this->idDesde($this->post('cuenta'), 'cuenta');
        if ($cuentaId === 0) {
            Respuesta::error('Seleccione la cuenta.', 422);
        }

        Autorizacion::exigirCuenta($cuentaId, $actorId, 'cuenta.ver_todas');

        try {
            $monto = Monto::deEntradaUsuario($this->post('monto'));
        } catch (MontoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        $r = (new ServicioDesafio())->emitir(
            $actorId, 'transferencia',
            hash('sha256', "retiro|{$cuentaId}|{$monto->centavos}"),
            'Retiro de ' . $monto->formateado()
        );

        Respuesta::exito($r, 'Código de verificación enviado.');
    }

    /** Busca una cuenta por número, para la ventanilla. */
    public function buscarCuenta(array $parametros = []): void
    {
        Autorizacion::exigir('cuenta.ver_todas');
        Sesion::exigirAutenticado();

        (new LimitadorTasa(40, 60, 120))->exigir('busca-cuenta:' . Sesion::usuarioId());

        $numero = preg_replace('/\D/', '', $this->post('numero'));
        if ($numero === '' || strlen($numero) < 6) {
            Respuesta::error('Número de cuenta inválido.', 422);
        }

        $c = BaseDatos::unaFila(
            'SELECT c.id, c.numero_cuenta, c.saldo_cent, c.saldo_retenido_cent, c.estado, c.moneda,
                    p.nombre AS producto, p.saldo_minimo_cent,
                    CONCAT(u.nombres," ",u.apellidos) AS titular, u.estado AS estado_titular
               FROM cuentas c
               JOIN productos p ON p.id = c.producto_id
               JOIN usuarios  u ON u.id = c.usuario_id
              WHERE c.numero_cuenta = ? AND c.es_tecnica = 0',
            [$numero]
        );

        if ($c === null) {
            Respuesta::error('La cuenta no existe.', 404);
        }

        $disponible = max(
            0,
            (int) $c['saldo_cent'] - (int) $c['saldo_retenido_cent'] - (int) $c['saldo_minimo_cent']
        );

        Respuesta::exito([
            'codigo'      => Hashid::codificar((int) $c['id'], 'cuenta'),
            'numero'      => $c['numero_cuenta'],
            'titular'     => $c['titular'],
            'producto'    => $c['producto'],
            'estado'      => $c['estado'],
            'operable'    => $c['estado'] === 'activa' && $c['estado_titular'] === 'activo',
            'saldo'       => Monto::deCentavos((int) $c['saldo_cent'])->jsonSerialize(),
            'disponible'  => Monto::deCentavos($disponible)->jsonSerialize(),
        ]);
    }

    /**
     * Atención en ventanilla.
     *
     * El módulo estaba organizado por OPERACIÓN —una pantalla para
     * depositar, otra para retirar, otra para abrir cuenta— y cada una
     * volvía a pedir el número de cuenta. Eso describe lo que hace el
     * sistema, no lo que pasa en una ventanilla.
     *
     * Lo que pasa es que llega una persona, trae lo que trae, y el
     * cajero necesita identificarla y verla entera antes de actuar.
     */
    public function atencion(array $parametros = []): void
    {
        Autorizacion::exigir('cuenta.ver_todas');
        $cajeroId = Sesion::exigirAutenticado();

        $svc = new ServicioAtencionVentanilla();

        $buscado = trim((string) $this->get('q'));
        $cliente = trim((string) $this->get('cliente'));

        $ficha         = null;
        $coincidencias = [];

        if ($cliente !== '') {
            $id = $this->idDesde($cliente, 'usuario');

            if ($id > 0) {
                $ficha = $svc->ficha($id);
            }
        } elseif ($buscado !== '') {
            (new LimitadorTasa(60, 60, 180))->exigir('ventanilla:' . $cajeroId);

            $coincidencias = $svc->buscar($buscado);

            /* Una sola coincidencia se abre sola. Obligar a pulsar
               sobre el único resultado es una pulsación que no decide
               nada, y en ventanilla las pulsaciones se notan. */
            if (count($coincidencias) === 1) {
                $id = $this->idDesde((string) $coincidencias[0]['codigo'], 'usuario');

                if ($id > 0) {
                    $ficha = $svc->ficha($id);
                }
            }
        }

        $this->render('caja/atencion', [
            'titulo'        => 'Atención en ventanilla · CashMan H.A.',
            'encabezado'    => 'Atención en ventanilla',
            'ficha'         => $ficha,
            'coincidencias' => $coincidencias,
            'buscado'       => $buscado,
            'turno'         => $this->turnoAbierto($cajeroId),
        ]);
    }

    /**
     * El turno abierto del cajero, si lo tiene.
     *
     * Va en la pantalla porque un cajero que cobra con la caja cerrada
     * tiene un problema que solo descubre al cuadrar.
     *
     * @return array<string,mixed>|null
     */
    /**
     * La cuenta que el cajero traía de la ficha del cliente.
     *
     * Se devuelve con su titular y su saldo ya resueltos, para que el
     * formulario se abra con todo puesto: el cajero acaba de pulsar
     * «Depositar» sobre una cuenta concreta y volver a pedirle el
     * número es hacerle teclear lo que ya dijo.
     *
     * Se busca por número de cuenta, que es lo que viaja en la URL, y
     * pasa por el mismo permiso que cualquier otra consulta: traer el
     * número en la barra de direcciones no da acceso a nada.
     *
     * @return array<string,mixed>|null
     */
    private function cuentaPrecargada(): ?array
    {
        $numero = preg_replace('/\D+/', '', (string) ($_GET['cuenta'] ?? '')) ?? '';

        if ($numero === '') {
            return null;
        }

        /* El saldo mínimo es del PRODUCTO, no de la cuenta: es una
           condición del tipo de cuenta y vale igual para todas las que
           se abran con él. Pedírselo a `cuentas` fue un error mío y
           reventaba las dos pantallas al entrar. */
        $c = BaseDatos::unaFila(
            'SELECT c.id, c.numero_cuenta, c.saldo_cent, c.saldo_retenido_cent,
                    c.estado, c.usuario_id,
                    COALESCE(p.saldo_minimo_cent, 0) AS saldo_minimo_cent,
                    CONCAT(u.nombres, " ", u.apellidos) AS titular,
                    p.nombre AS producto
               FROM cuentas c
               JOIN usuarios u ON u.id = c.usuario_id
          LEFT JOIN productos p ON p.id = c.producto_id
              WHERE c.numero_cuenta = ? AND c.es_tecnica = 0',
            [$numero]
        );

        if ($c === null) {
            return null;
        }

        $c['codigo'] = Hashid::codificar((int) $c['id'], 'cuenta');
        $c['disponible_cent'] = max(0,
            (int) $c['saldo_cent']
            - (int) $c['saldo_retenido_cent']
            - (int) ($c['saldo_minimo_cent'] ?? 0)
        );
        unset($c['id']);

        return $c;
    }

    /** ¿Tiene este cajero su caja abierta ahora mismo? */
    private function hayTurnoAbierto(): bool
    {
        $u = Sesion::usuarioId();

        if ($u === null) {
            return false;
        }

        return (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM caja_sesiones WHERE usuario_id = ? AND estado = "abierta"',
            [$u]
        ) > 0;
    }

    private function turnoAbierto(int $cajeroId): ?array
    {
        $t = BaseDatos::unaFila(
            'SELECT s.id, s.folio, s.abierta_en, s.fondo_inicial_cent,
                    k.nombre AS caja, su.nombre AS sucursal,
                    (SELECT COALESCE(SUM(m.importe_cent),0) FROM caja_movimientos m
                      WHERE m.sesion_id = s.id) AS movido_cent,
                    (SELECT COUNT(*) FROM caja_movimientos m
                      WHERE m.sesion_id = s.id AND m.tipo <> "fondo_inicial") AS movimientos
               FROM caja_sesiones s
               JOIN cajas k ON k.id = s.caja_id
          LEFT JOIN sucursales su ON su.id = k.sucursal_id
              WHERE s.usuario_id = ? AND s.estado = "abierta"
              ORDER BY s.id DESC LIMIT 1',
            [$cajeroId]
        );

        if ($t === null) {
            return null;
        }

        $t['codigo'] = Hashid::codificar((int) $t['id'], 'caja_sesion');

        // Lo que debería haber en la gaveta ahora mismo.
        $t['efectivo_cent'] = (int) $t['movido_cent'];

        unset($t['id']);

        return $t;
    }
    public function formularioApertura(array $parametros = []): void
    {
        Autorizacion::exigir('cuenta.abrir');

        $clientes = BaseDatos::todas(
            'SELECT u.id, u.usuario, u.nombres, u.apellidos, u.correo
               FROM usuarios u
              WHERE u.rol_id = 5 AND u.estado = "activo"
              ORDER BY u.nombres, u.apellidos'
        );
        foreach ($clientes as &$c) {
            $c['codigo'] = Hashid::codificar((int) $c['id'], 'usuario');
            unset($c['id']);
        }

        $productos = BaseDatos::todas(
            'SELECT id, codigo, nombre, tipo, saldo_minimo_cent, tasa_interes_bp
               FROM productos WHERE activo = 1 AND tipo IN ("ahorro","corriente") ORDER BY nombre'
        );
        foreach ($productos as &$p) {
            $p['codigo_h'] = Hashid::codificar((int) $p['id'], 'producto');
            unset($p['id']);
        }

        $this->render('caja/abrir-cuenta', [
            'titulo'     => 'Abrir cuenta · CashMan H.A.',
            'encabezado' => 'Abrir cuenta nueva',
            'clientes'   => $clientes,
            'productos'  => $productos,
            'csrf'       => Csrf::token('caja'),
        ]);
    }

    public function abrirCuenta(array $parametros = []): void
    {
        $clienteId  = $this->idDesde($this->post('cliente'), 'usuario');
        $productoId = $this->idDesde($this->post('producto'), 'producto');

        if ($clienteId === 0 || $productoId === 0) {
            Respuesta::error('Seleccione el cliente y el producto.', 422);
        }

        try {
            $r = (new ServicioCaja())->abrirCuenta(
                $clienteId,
                $productoId,
                $this->post('monto_inicial') !== '' ? $this->post('monto_inicial') : null,
                // Con qué marca quiere su tarjeta de débito, que sale
                // con la cuenta y no en otra visita.
                $this->post('marca', 'visa')
            );
        } catch (\InvalidArgumentException | MontoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(
            $r,
            'Cuenta ' . $r['numero'] . ' abierta correctamente.'
            . ($r['tarjeta'] !== null
                ? ' Tarjeta de débito ••••' . $r['tarjeta']['ultimos4'] . ' emitida.'
                : ' La tarjeta de débito quedó pendiente: emítala desde la ficha del cliente.')
        );
    }

    // -----------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function limite(string $tipo): ?array
    {
        return BaseDatos::unaFila(
            'SELECT * FROM limites_operacion
              WHERE tipo_operacion = ? AND activo = 1 AND (rol_id = ? OR rol_id IS NULL)
              ORDER BY rol_id IS NULL ASC LIMIT 1',
            [$tipo, Sesion::rolId()]
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function ultimasOperaciones(string $tipo, int $limite = 15): array
    {
        return BaseDatos::todas(
            "SELECT a.referencia, a.descripcion, a.creado_en,
                    m.importe_cent, c.numero_cuenta,
                    CONCAT(u.nombres,' ',u.apellidos) AS titular
               FROM asientos a
               JOIN movimientos m ON m.asiento_id = a.id AND m.cuenta_id <> (
                     SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1)
               JOIN cuentas c  ON c.id = m.cuenta_id
               JOIN usuarios u ON u.id = c.usuario_id
              WHERE a.tipo = ? AND a.creado_por = ?
              ORDER BY a.id DESC LIMIT {$limite}",
            [$tipo, Sesion::usuarioId()]
        );
    }

    private function idem(string $prefijo): string
    {
        $cliente = $this->post('_idem');
        if ($cliente !== '' && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $cliente)) {
            return $prefijo . ':' . Sesion::usuarioId() . ':' . $cliente;
        }
        return $prefijo . ':' . Sesion::usuarioId() . ':' . bin2hex(random_bytes(16));
    }
}
