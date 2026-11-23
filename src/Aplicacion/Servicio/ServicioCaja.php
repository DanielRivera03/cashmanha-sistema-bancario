<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Operaciones de ventanilla: depósitos, retiros y apertura de cuentas.
 *
 * En v1 los depósitos y retiros eran INSERTs en
 * `transaccionescuentasclientes` y un trigger sumaba o restaba el
 * saldo sin validar nada. Un retiro mayor al saldo dejaba la cuenta
 * en negativo sin aviso.
 *
 * Aquí toda operación pasa por el libro mayor, con bloqueo de la
 * cuenta, verificación de fondos y límites por rol.
 */
final class ServicioCaja
{
    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    /**
     * Con qué puede pagar o recibir alguien en una ventanilla.
     *
     * El valor es cómo se dice en el comprobante del cliente, que es
     * donde se lee. «Efectivo» y «cheque» son los dos que de verdad
     * pasan por una caja; los otros dos existen porque una operación
     * puede llegar ya cobrada por otra vía y aun así tener que quedar
     * anotada en el turno del cajero que la atendió.
     */
    public const MEDIOS = [
        'efectivo'      => 'efectivo',
        'cheque'        => 'cheque',
        'transferencia' => 'transferencia',
        'tarjeta'       => 'tarjeta',
    ];

    /**
     * ¿Está atendiendo en una ventanilla con su caja abierta?
     *
     * Es la diferencia entre tener al cliente delante y no tenerlo.
     * Con la caja abierta, la operación queda atada a un turno, a una
     * caja y a un cajero con nombre, y ese rastro se arquea al cerrar.
     */
    private function enVentanilla(int $actorId): bool
    {
        return (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM caja_sesiones WHERE usuario_id = ? AND estado = "abierta"',
            [$actorId]
        ) > 0;
    }

    /** Un medio del catálogo, o efectivo. */
    private function medioValido(mixed $medio): string
    {
        $m = is_string($medio) ? strtolower(trim($medio)) : '';

        return isset(self::MEDIOS[$m]) ? $m : 'efectivo';
    }

    /**
     * @param array{cuenta_id:int, monto:mixed, concepto?:string,
     *              medio?:string, cheque_banco?:string, cheque_numero?:string,
     *              clave_idempotencia:string} $datos
     * @return array<string,mixed>
     */
    public function depositar(array $datos): array
    {
        Autorizacion::exigir('deposito.registrar');
        $actorId = Sesion::exigirAutenticado();

        $medio = $this->medioValido($datos['medio'] ?? 'efectivo');

        /* Un cheque sin banco ni número no se puede reclamar si rebota,
           y rebotan. Se exige al recibirlo, que es el único momento en
           que el cliente lo tiene en la mano. */
        if ($medio === 'cheque') {
            if (trim((string) ($datos['cheque_banco'] ?? '')) === ''
             || trim((string) ($datos['cheque_numero'] ?? '')) === '') {
                throw new \InvalidArgumentException(
                    'De un cheque hay que anotar el banco y el número.'
                );
            }
        }

        $monto    = Monto::deEntradaUsuario($datos['monto']);
        $cuentaId = (int) $datos['cuenta_id'];

        $cuenta = Autorizacion::exigirCuenta($cuentaId, $actorId, 'cuenta.ver_todas');
        Autorizacion::exigirCuentaOperable($cuenta);

        $this->verificarLimite('deposito', $monto);

        $resultado = BaseDatos::enTransaccion(function () use ($cuentaId, $monto, $actorId, $datos, $cuenta) {
            $tecnica = $this->cuentaTecnica();
            LibroMayor::bloquearCuentas([$cuentaId, $tecnica]);

            $concepto = mb_substr(trim((string) ($datos['concepto'] ?? 'Depósito en ventanilla')), 0, 160);

            return LibroMayor::registrar(
                'deposito',
                [
                    ['cuenta_id' => $cuentaId, 'importe' => $monto,           'descripcion' => $concepto],
                    ['cuenta_id' => $tecnica,  'importe' => $monto->negado(), 'descripcion' => 'Contrapartida de depósito'],
                ],
                $actorId,
                (string) $datos['clave_idempotencia'],
                $concepto
            );
        });

        if (($resultado['duplicado'] ?? false) === true) {
            return ['estado' => 'duplicado', 'referencia' => $resultado['referencia'],
                    'mensaje' => 'Este depósito ya había sido registrado.'];
        }

        Auditoria::registrar('deposito.registrado', Auditoria::EXITO, $actorId, 'cuenta',
            (string) $cuentaId, ['monto' => $monto->aDecimal()]);

        /* El efectivo entra a la caja de quien lo recibió.
         *
         * El asiento contable ya cuadraba, pero eso no dice nada del
         * dinero físico: alguien recibió unos billetes y tiene que
         * entregarlos al cerrar su turno. Si el depósito lo hizo el
         * propio cliente desde su casa, no hay turno abierto y no se
         * anota nada: solo se registra el efectivo que de verdad pasó
         * por un cajón. */
        (new ServicioSesionCaja())->anotarSiHayTurno(
            'deposito',
            $monto->centavos,
            'Depósito en ' . self::MEDIOS[$medio] . ' · cuenta ' . $cuenta['numero_cuenta'],
            [
                'cuenta_id'     => $cuentaId,
                'cliente_id'    => (int) $cuenta['usuario_id'],
                'asiento_id'    => $resultado['asiento_id'] ?? null,
                /* Un cheque no es efectivo: entra por la ventanilla y se
                   remesa, pero no deja un billete en la gaveta. Si no
                   se distinguiera, el arqueo le reclamaría al cajero un
                   dinero que nunca tuvo. */
                'medio'         => $medio,
                'cheque_banco'  => $datos['cheque_banco']  ?? null,
                'cheque_numero' => $datos['cheque_numero'] ?? null,
            ]
        );

        $this->notificaciones->crear(
            (int) $cuenta['usuario_id'], 'deposito.recibido', 'Depósito acreditado',
            'Con mucho gusto le confirmamos que se acreditaron ' . $monto->formateado()
            . ' a su cuenta ' . $cuenta['numero_cuenta'] . '. Quedamos a sus órdenes.',
            [
                'referencia' => $resultado['referencia'],
                'monto'      => $monto->formateado(),
                'cuenta'     => $cuenta['numero_cuenta'],
            ],
            'exito'
        );

        $saldoFinal = Monto::deCentavos((int) $cuenta['saldo_cent'] + $monto->centavos);

        /* Compensación: el dinero que acaba de entrar cubre la mora.
         *
         * Va DESPUÉS de confirmar el depósito, no dentro: el depósito
         * es válido por sí mismo y no puede caerse porque falle el
         * cobro que viene detrás. El servicio no lanza; si algo sale
         * mal lo deja anotado y el saldo se queda donde está.
         *
         * Solo toca cuentas donde el cliente firmó la compensación.
         * Sin esa firma no se mueve un centavo. */
        $compensado = (new ServicioCompensacion($this->notificaciones))
            ->trasAbono($cuentaId, 'abono');

        if ((int) $compensado['aplicado_cent'] > 0) {
            $saldoFinal = Monto::deCentavos(
                max(0, $saldoFinal->centavos - (int) $compensado['aplicado_cent'])
            );
        }

        return [
            'estado' => 'procesado', 'referencia' => $resultado['referencia'],
            'monto' => $monto->jsonSerialize(), 'saldo_final' => $saldoFinal->jsonSerialize(),
            'compensacion' => (int) $compensado['aplicado_cent'] > 0 ? $compensado : null,
        ];
    }

    /**
     * @param array{cuenta_id:int, monto:mixed, concepto?:string, codigo_otp?:string, clave_idempotencia:string} $datos
     * @return array<string,mixed>
     */
    public function retirar(array $datos): array
    {
        Autorizacion::exigir('retiro.registrar');
        $actorId = Sesion::exigirAutenticado();

        /* Con qué se le entrega el dinero. Normalmente efectivo; por
           encima de cierto importe una ventanilla entrega cheque de
           caja, que es lo que hace cualquier banco antes que poner
           veinte mil dólares en billetes sobre el mostrador. */
        $medio = $this->medioValido($datos['medio'] ?? 'efectivo');

        $monto    = Monto::deEntradaUsuario($datos['monto']);
        $cuentaId = (int) $datos['cuenta_id'];

        $cuenta = Autorizacion::exigirCuenta($cuentaId, $actorId, 'cuenta.ver_todas');
        Autorizacion::exigirCuentaOperable($cuenta);

        $limite = $this->verificarLimite('retiro', $monto);

        /* El código de verificación, solo fuera de la ventanilla.
         *
         * El código se emitía y se consumía contra el ACTOR, que en
         * ventanilla es el cajero: él lo pedía, le llegaba a él y él lo
         * tecleaba. No probaba nada del cliente —que es de quien hay
         * que asegurarse— y solo añadía dos pasos a una fila.
         *
         * A quien está delante del mostrador se le identifica como se
         * ha hecho siempre: con su documento, en persona, y queda
         * anotado quién lo atendió y en qué caja. Por eso el código se
         * exige cuando NO hay un turno de caja abierto, que es cuando
         * el retiro se está haciendo a distancia y la identidad es
         * justo lo que falta por comprobar. */
        if ((bool) $limite['requiere_otp'] && !$this->enVentanilla($actorId)) {
            (new ServicioDesafio())->consumir(
                $actorId, 'transferencia',
                (string) ($datos['codigo_otp'] ?? ''),
                hash('sha256', "retiro|{$cuentaId}|{$monto->centavos}")
            );
        }

        $resultado = BaseDatos::enTransaccion(function () use ($cuentaId, $monto, $actorId, $datos) {
            $tecnica = $this->cuentaTecnica();
            $bloq    = LibroMayor::bloquearCuentas([$cuentaId, $tecnica]);
            $origen  = $bloq[$cuentaId] ?? null;

            if ($origen === null) {
                throw new \RuntimeException('No se pudo bloquear la cuenta.');
            }

            // Verificación de fondos que v1 no hacía.
            $disponible = Monto::deCentavos(
                (int) $origen['saldo_cent']
                - (int) $origen['saldo_retenido_cent']
                - (int) $origen['saldo_minimo_cent']
            );

            if ($disponible->menorQue($monto)) {
                throw new FondosInsuficientes(
                    'Fondos insuficientes. Disponible: ' . $disponible->formateado()
                    . ' (saldo mínimo: ' . Monto::deCentavos((int) $origen['saldo_minimo_cent'])->formateado() . ').'
                );
            }

            $concepto = mb_substr(trim((string) ($datos['concepto'] ?? 'Retiro en ventanilla')), 0, 160);

            return LibroMayor::registrar(
                'retiro',
                [
                    ['cuenta_id' => $cuentaId, 'importe' => $monto->negado(), 'descripcion' => $concepto],
                    ['cuenta_id' => $tecnica,  'importe' => $monto,           'descripcion' => 'Contrapartida de retiro'],
                ],
                $actorId,
                (string) $datos['clave_idempotencia'],
                $concepto
            );
        });

        if (($resultado['duplicado'] ?? false) === true) {
            return ['estado' => 'duplicado', 'referencia' => $resultado['referencia'],
                    'mensaje' => 'Este retiro ya había sido registrado.'];
        }

        Auditoria::registrar('retiro.registrado', Auditoria::EXITO, $actorId, 'cuenta',
            (string) $cuentaId, ['monto' => $monto->aDecimal()]);

        // El efectivo sale de la caja de quien lo entregó: signo
        // negativo, porque es lo que ya no está en el cajón.
        (new ServicioSesionCaja())->anotarSiHayTurno(
            'retiro',
            -$monto->centavos,
            'Retiro en ' . self::MEDIOS[$medio] . ' · cuenta ' . $cuenta['numero_cuenta'],
            [
                'cuenta_id'     => $cuentaId,
                'cliente_id'    => (int) $cuenta['usuario_id'],
                'asiento_id'    => $resultado['asiento_id'] ?? null,
                // Un cheque de caja no vacía la gaveta: no se descuenta
                // del efectivo que el cajero tiene que entregar.
                'medio'         => $medio,
                'cheque_banco'  => $datos['cheque_banco']  ?? null,
                'cheque_numero' => $datos['cheque_numero'] ?? null,
            ]
        );

        $this->notificaciones->crear(
            (int) $cuenta['usuario_id'], 'retiro.realizado', 'Retiro realizado',
            'Le confirmamos el retiro de ' . $monto->formateado() . ' de su cuenta '
            . $cuenta['numero_cuenta'] . '. Si no reconoce esta operación, por favor '
            . 'comuníquese con nosotros de inmediato.',
            [
                'referencia' => $resultado['referencia'],
                'monto'      => $monto->formateado(),
                'cuenta'     => $cuenta['numero_cuenta'],
            ],
            'alerta'
        );

        $saldoFinal = Monto::deCentavos((int) $cuenta['saldo_cent'] - $monto->centavos);

        return [
            'estado' => 'procesado', 'referencia' => $resultado['referencia'],
            'monto' => $monto->jsonSerialize(), 'saldo_final' => $saldoFinal->jsonSerialize(),
        ];
    }

    /**
     * Abre una cuenta nueva.
     *
     * @return array<string,mixed>
     */
    /**
     * Abre una cuenta nueva y le emite su tarjeta de débito.
     *
     * La tarjeta ya no se pide aparte. Una cuenta de ahorro sin
     * tarjeta obliga al cliente a volver otro día a pedirla, y a
     * nosotros a explicarle por qué no vino con la cuenta: en un banco
     * de verdad salen juntas del mostrador. Lo único que hay que
     * preguntarle es con qué marca la quiere.
     *
     * Una sola por cuenta, que es lo que fija
     * `tarjeta.debito_max_por_cuenta`.
     *
     * @param string $marca Marca de la tarjeta. Vacío para no emitir
     *                      ninguna, que es lo que corresponde a una
     *                      cuenta técnica o a un producto sin plástico.
     */
    public function abrirCuenta(
        int $usuarioId,
        int $productoId,
        mixed $montoInicial = null,
        string $marca = 'visa'
    ): array {
        Autorizacion::exigir('cuenta.abrir');
        $actorId = Sesion::exigirAutenticado();

        $titular = BaseDatos::unaFila(
            'SELECT id, nombres, apellidos FROM usuarios WHERE id = ? AND estado = "activo"',
            [$usuarioId]
        );
        if ($titular === null) {
            throw new \InvalidArgumentException('El cliente no existe o no está activo.');
        }

        $producto = BaseDatos::unaFila(
            'SELECT * FROM productos WHERE id = ? AND activo = 1 AND tipo IN ("ahorro","corriente")',
            [$productoId]
        );
        if ($producto === null) {
            throw new \InvalidArgumentException('Producto no válido para apertura de cuenta.');
        }

        return BaseDatos::enTransaccion(function () use ($usuarioId, $productoId, $montoInicial, $actorId, $producto, $marca) {
            // Número de cuenta único de 9 dígitos, no secuencial.
            do {
                $numero = (string) random_int(100000000, 999999999);

                /* No basta con que no exista hoy: tampoco puede ser uno
                 * de los retirados.
                 *
                 * Un número de cuenta vive fuera del sistema —en
                 * recibos, en ordenes de pago, en la libreta de un
                 * proveedor— y esa vida no termina cuando la cuenta se
                 * cierra. Reasignarlo es lo que hace que un deposito
                 * dirigido a la cuenta vieja de alguien aterrice en la
                 * cuenta nueva de otro. */
                $existe = BaseDatos::unValor(
                    'SELECT 1 FROM cuentas WHERE numero_cuenta = ?
                      UNION ALL
                     SELECT 1 FROM numeros_cuenta_retirados WHERE numero_cuenta = ?
                      LIMIT 1',
                    [$numero, $numero]
                );
            } while ($existe !== null);

            BaseDatos::ejecutar(
                'INSERT INTO cuentas (uuid, numero_cuenta, usuario_id, producto_id, moneda, saldo_cent, estado)
                 VALUES (UUID(), ?,?,?, "USD", 0, "activa")',
                [$numero, $usuarioId, $productoId]
            );
            $cuentaId = BaseDatos::ultimoId();

            $depositoInicial = null;
            if ($montoInicial !== null && $montoInicial !== '') {
                $monto = Monto::deEntradaUsuario($montoInicial);
                $minimo = Monto::deCentavos((int) $producto['saldo_minimo_cent']);

                if ($monto->menorQue($minimo)) {
                    throw new \InvalidArgumentException(
                        'El depósito inicial debe ser al menos ' . $minimo->formateado() . '.'
                    );
                }

                $tecnica = $this->cuentaTecnica();
                LibroMayor::bloquearCuentas([$cuentaId, $tecnica]);

                $asiento = LibroMayor::registrar(
                    'deposito',
                    [
                        ['cuenta_id' => $cuentaId, 'importe' => $monto,           'descripcion' => 'Depósito de apertura'],
                        ['cuenta_id' => $tecnica,  'importe' => $monto->negado(), 'descripcion' => 'Contrapartida de apertura'],
                    ],
                    $actorId,
                    'apertura-cuenta-' . $cuentaId,
                    'Apertura de cuenta ' . $numero
                );
                $depositoInicial = $asiento['referencia'];
            }

            /* Su tarjeta de débito, en el mismo acto.
             *
             * Si falla, la cuenta NO se cae con ella: ya está abierta y
             * el cliente puede usarla en ventanilla. Se deja constancia
             * y se le emite después, que es mucho mejor que deshacer
             * una apertura que ya se le anunció. */
            $tarjeta = null;

            if ($marca !== '') {
                try {
                    $tarjeta = (new EmisorTarjeta())->emitir(
                        $usuarioId,
                        $marca,
                        'debito',
                        Monto::cero(),
                        null,
                        $actorId,
                        ['cuenta_id' => $cuentaId]
                    );
                } catch (\Throwable $e) {
                    error_log('[Caja] cuenta ' . $numero . ' sin tarjeta: ' . $e->getMessage());

                    Auditoria::registrar(
                        'cuenta.tarjeta_pendiente', Auditoria::FALLO, $actorId,
                        'cuenta', (string) $cuentaId,
                        ['numero' => $numero, 'motivo' => $e->getMessage()]
                    );
                }
            }

            Auditoria::registrar('cuenta.abierta', Auditoria::EXITO, $actorId, 'cuenta',
                (string) $cuentaId,
                ['numero' => $numero, 'titular' => $usuarioId,
                 'tarjeta' => $tarjeta['ultimos4'] ?? null]);

            $this->notificaciones->crear(
                $usuarioId, 'cuenta.abierta', 'Cuenta abierta',
                'Su cuenta ' . $numero . ' (' . $producto['nombre'] . ') quedó activa.'
                . ($tarjeta !== null
                    ? ' Le emitimos su tarjeta de débito ••••' . $tarjeta['ultimos4'] . '.'
                    : ''),
                null, 'exito'
            );

            return [
                'cuenta_id' => $cuentaId,
                'numero'    => $numero,
                'producto'  => $producto['nombre'],
                'deposito'  => $depositoInicial,
                'tarjeta'   => $tarjeta === null ? null : [
                    'ultimos4' => $tarjeta['ultimos4'],
                    'marca'    => $marca,
                ],
            ];
        });
    }

    /** @return array<string,mixed> */
    private function verificarLimite(string $tipo, Monto $monto): array
    {
        $limite = BaseDatos::unaFila(
            'SELECT * FROM limites_operacion
              WHERE tipo_operacion = ? AND activo = 1 AND (rol_id = ? OR rol_id IS NULL)
              ORDER BY rol_id IS NULL ASC LIMIT 1',
            [$tipo, Sesion::rolId()]
        );

        // Sin límite configurado, se permite pero se registra: un
        // depósito no puede bloquearse por falta de configuración.
        if ($limite === null) {
            return ['requiere_otp' => 0, 'max_por_operacion_cent' => PHP_INT_MAX];
        }

        if ($monto->centavos > (int) $limite['max_por_operacion_cent']) {
            throw new LimiteExcedidoTransferencia(
                'El monto supera el máximo por operación ('
                . Monto::deCentavos((int) $limite['max_por_operacion_cent'])->formateado() . ').'
            );
        }

        return $limite;
    }

    private function cuentaTecnica(): int
    {
        $id = BaseDatos::unValor('SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1');
        if ($id === null) {
            throw new \RuntimeException('No existe la cuenta técnica de contrapartida.');
        }
        return (int) $id;
    }
}
