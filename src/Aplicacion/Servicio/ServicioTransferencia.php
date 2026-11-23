<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\AccesoDenegado;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Ejecución de transferencias.
 *
 * Cierra de una vez los cuatro agujeros del sistema anterior:
 *
 *   C-3  La cuenta de origen venía de un campo oculto del formulario
 *        y nadie verificaba la propiedad  →  Autorizacion::exigirCuenta()
 *   C-4  El OTP se validaba solo para dibujar el formulario; el
 *        endpoint que movía el dinero no lo miraba  →  se consume aquí
 *   C-5  El tope de $2,000 vivía en un addEventListener de JavaScript
 *        →  tabla limites_operacion, verificada en el servidor
 *   C-6  El trigger restaba a ciegas, sin comprobar fondos
 *        →  verificación explícita + CHECK en la base
 *
 * Y añade lo que no existía: idempotencia, bloqueo pesimista,
 * atomicidad real y doble control para montos altos.
 */
final class ServicioTransferencia
{
    public function __construct(
        private ServicioDesafio $desafios = new ServicioDesafio(),
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    /**
     * @param array{
     *   cuenta_origen_id:int, cuenta_destino_id:int, monto:mixed,
     *   concepto?:string, codigo_otp?:string, clave_idempotencia:string
     * } $datos
     * @return array<string,mixed>
     */
    public function ejecutar(array $datos): array
    {
        $actorId = Sesion::exigirAutenticado();

        // --- NIVEL 1: ¿el rol puede transferir? -----------------------
        $esPropia = true;
        if (!Autorizacion::puede('transferencia.ejecutar')) {
            Autorizacion::exigir('transferencia.ejecutar_terceros');
            $esPropia = false;
        }

        // --- El monto se valida en el constructor del value object ----
        // Rechaza negativos, ceros, texto y notación científica antes
        // de que lleguen a ninguna parte.
        $monto = Monto::deEntradaUsuario($datos['monto']);

        $origenId  = (int) $datos['cuenta_origen_id'];
        $destinoId = (int) $datos['cuenta_destino_id'];

        if ($origenId === $destinoId) {
            throw new TransferenciaRechazada('La cuenta de origen y destino no pueden ser la misma.');
        }

        // --- NIVEL 2: propiedad del objeto ----------------------------
        // Sin esto, cambiar el id en el formulario vacía la cuenta ajena.
        $cuentaOrigen = Autorizacion::exigirCuenta(
            $origenId,
            $actorId,
            'transferencia.ejecutar_terceros'
        );
        Autorizacion::exigirCuentaOperable($cuentaOrigen);

        $cuentaDestino = BaseDatos::unaFila(
            'SELECT c.*, u.nombres, u.apellidos
               FROM cuentas c JOIN usuarios u ON u.id = c.usuario_id
              WHERE c.id = ? AND c.es_tecnica = 0',
            [$destinoId]
        );
        if ($cuentaDestino === null) {
            throw new TransferenciaRechazada('La cuenta destino no existe.');
        }
        if ($cuentaDestino['estado'] !== 'activa') {
            throw new TransferenciaRechazada('La cuenta destino no puede recibir fondos.');
        }
        if ($cuentaDestino['moneda'] !== $cuentaOrigen['moneda']) {
            throw new TransferenciaRechazada('No se permiten transferencias entre monedas distintas.');
        }

        // --- Límites del servidor -------------------------------------
        $limite = $this->limiteAplicable('transferencia');
        $this->verificarLimites($actorId, $origenId, $monto, $limite);

        // --- Segundo factor, consumido AQUÍ ---------------------------
        // El contexto ata el código a ESTA operación: un OTP emitido
        // para otra transferencia no sirve.
        if ((bool) $limite['requiere_otp']) {
            $contexto = $this->contextoOperacion($origenId, $destinoId, $monto);
            $this->desafios->consumir(
                $actorId,
                'transferencia',
                (string) ($datos['codigo_otp'] ?? ''),
                $contexto
            );
        }

        // --- Doble control (maker-checker) -----------------------------
        $umbral = $limite['umbral_doble_control_cent'] ?? null;
        if ($umbral !== null && $monto->centavos >= (int) $umbral) {
            return $this->solicitarAprobacion($actorId, $datos, $monto, $origenId, $destinoId);
        }

        // --- Ejecución atómica -----------------------------------------
        $resultado = BaseDatos::enTransaccion(function () use (
            $actorId, $origenId, $destinoId, $monto, $datos, $cuentaDestino, $esPropia
        ): array {
            // Bloqueo pesimista en orden de id: sin deadlocks.
            $bloqueadas = LibroMayor::bloquearCuentas([$origenId, $destinoId]);
            $origen     = $bloqueadas[$origenId]  ?? null;
            $destino    = $bloqueadas[$destinoId] ?? null;

            if ($origen === null || $destino === null) {
                throw new TransferenciaRechazada('No se pudo bloquear alguna de las cuentas.');
            }

            // --- Fondos suficientes, ya con el saldo bloqueado --------
            $saldo      = Monto::deCentavos((int) $origen['saldo_cent']);
            $retenido   = Monto::deCentavos((int) $origen['saldo_retenido_cent']);
            $minimo     = Monto::deCentavos((int) $origen['saldo_minimo_cent']);
            $disponible = $saldo->menos($retenido)->menos($minimo);

            if ($disponible->menorQue($monto)) {
                Auditoria::registrar(
                    'transferencia.fondos_insuficientes',
                    Auditoria::FALLO,
                    $actorId,
                    'cuenta',
                    (string) $origenId,
                    ['solicitado' => $monto->aDecimal(), 'disponible' => $disponible->aDecimal()]
                );
                throw new FondosInsuficientes(
                    'Fondos insuficientes. Disponible: ' . $disponible->formateado()
                    . ' (saldo mínimo requerido: ' . $minimo->formateado() . ').'
                );
            }

            $concepto = mb_substr(trim((string) ($datos['concepto'] ?? 'Transferencia')), 0, 160);

            // --- Asiento de partida doble -----------------------------
            $asiento = LibroMayor::registrar(
                'transferencia',
                [
                    ['cuenta_id' => $origenId,  'importe' => $monto->negado(),
                     'descripcion' => 'Transferencia enviada a ' . $destino['numero_cuenta']],
                    ['cuenta_id' => $destinoId, 'importe' => $monto,
                     'descripcion' => 'Transferencia recibida de ' . $origen['numero_cuenta']],
                ],
                $actorId,
                (string) $datos['clave_idempotencia'],
                $concepto
            );

            if (($asiento['duplicado'] ?? false) === true) {
                // Reintento de una operación ya procesada: se devuelve
                // la original sin volver a mover dinero.
                return [
                    'estado'     => 'duplicado',
                    'referencia' => $asiento['referencia'],
                    'mensaje'    => 'Esta transferencia ya había sido procesada.',
                ];
            }

            Auditoria::registrar(
                'transferencia.ejecutada',
                Auditoria::EXITO,
                $actorId,
                'asiento',
                (string) $asiento['asiento_id'],
                [
                    'origen'     => $origen['numero_cuenta'],
                    'destino'    => $destino['numero_cuenta'],
                    'monto'      => $monto->aDecimal(),
                    'propia'     => $esPropia,
                    'referencia' => $asiento['referencia'],
                ]
            );

            // --- Notificaciones en vivo (alimentan el canal SSE) ------
            $this->notificaciones->crear(
                (int) $origen['usuario_id'],
                'transferencia.enviada',
                'Transferencia enviada',
                'Envió ' . $monto->formateado() . ' a la cuenta ' . $destino['numero_cuenta'] . '.',
                ['referencia' => $asiento['referencia'], 'monto' => $monto->jsonSerialize()],
                'info'
            );

            $this->notificaciones->crear(
                (int) $destino['usuario_id'],
                'transferencia.recibida',
                'Dinero recibido',
                'Recibiste ' . $monto->formateado() . ' de la cuenta ' . $origen['numero_cuenta'],
                ['referencia' => $asiento['referencia'], 'monto' => $monto->jsonSerialize()],
                'exito'
            );

            $saldoFinal = Monto::deCentavos((int) $origen['saldo_cent'] - $monto->centavos);

            return [
                'estado'       => 'procesada',
                'referencia'   => $asiento['referencia'],
                'uuid'         => $asiento['uuid'],
                'monto'        => $monto->jsonSerialize(),
                'saldo_final'  => $saldoFinal->jsonSerialize(),
                'destinatario' => trim(($cuentaDestino['nombres'] ?? '') . ' ' . ($cuentaDestino['apellidos'] ?? '')),
                'fecha'        => date('c'),
            ];
        });

        /* Compensación sobre la cuenta que recibió el dinero.
         *
         * Fuera de la transacción a propósito: el servicio abre la suya
         * y anidarlas haría que un fallo en el cobro deshiciera una
         * transferencia que ya era válida. Y sobre el DESTINO, no sobre
         * el origen: el que acaba de recibir es quien tiene saldo nuevo
         * con el que cubrir su mora. */
        $compensado = (new ServicioCompensacion($this->notificaciones))
            ->trasAbono($destinoId, 'abono');

        if ((int) $compensado['aplicado_cent'] > 0) {
            $resultado['compensacion'] = $compensado;
        }

        return $resultado;
    }

    /**
     * Límites acumulados: por operación, diario, mensual y por cantidad.
     *
     * @param array<string,mixed> $limite
     */
    private function verificarLimites(int $actorId, int $cuentaId, Monto $monto, array $limite): void
    {
        if ($monto->centavos > (int) $limite['max_por_operacion_cent']) {
            throw new LimiteExcedidoTransferencia(
                'El monto supera el máximo por operación ('
                . Monto::deCentavos((int) $limite['max_por_operacion_cent'])->formateado() . ').'
            );
        }

        // Acumulado del día.
        $hoy = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(-m.importe_cent), 0)
               FROM movimientos m
               JOIN asientos a ON a.id = m.asiento_id
              WHERE m.cuenta_id = ? AND m.importe_cent < 0
                AND a.tipo = "transferencia"
                AND DATE(a.creado_en) = CURDATE()',
            [$cuentaId]
        );

        if ($hoy + $monto->centavos > (int) $limite['max_diario_cent']) {
            $restante = Monto::deCentavos(max(0, (int) $limite['max_diario_cent'] - $hoy));
            throw new LimiteExcedidoTransferencia(
                'Superas el límite diario. Disponible hoy: ' . $restante->formateado() . '.'
            );
        }

        // Acumulado del mes.
        $mes = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(-m.importe_cent), 0)
               FROM movimientos m
               JOIN asientos a ON a.id = m.asiento_id
              WHERE m.cuenta_id = ? AND m.importe_cent < 0
                AND a.tipo = "transferencia"
                AND YEAR(a.creado_en) = YEAR(CURDATE())
                AND MONTH(a.creado_en) = MONTH(CURDATE())',
            [$cuentaId]
        );

        if ($mes + $monto->centavos > (int) $limite['max_mensual_cent']) {
            throw new LimiteExcedidoTransferencia('Superas el límite mensual de transferencias.');
        }

        // Cantidad de operaciones del día: frena el vaciado por goteo.
        $ops = (int) BaseDatos::unValor(
            'SELECT COUNT(*)
               FROM movimientos m
               JOIN asientos a ON a.id = m.asiento_id
              WHERE m.cuenta_id = ? AND m.importe_cent < 0
                AND a.tipo = "transferencia"
                AND DATE(a.creado_en) = CURDATE()',
            [$cuentaId]
        );

        if ($ops >= (int) $limite['max_ops_diarias']) {
            throw new LimiteExcedidoTransferencia(
                'Alcanzaste el máximo de transferencias por día (' . $limite['max_ops_diarias'] . ').'
            );
        }
    }

    /** @return array<string,mixed> */
    private function limiteAplicable(string $tipoOperacion): array
    {
        $rolId = Sesion::rolId();

        $limite = BaseDatos::unaFila(
            'SELECT * FROM limites_operacion
              WHERE tipo_operacion = ? AND activo = 1 AND (rol_id = ? OR rol_id IS NULL)
              ORDER BY rol_id IS NULL ASC
              LIMIT 1',
            [$tipoOperacion, $rolId]
        );

        if ($limite === null) {
            // Sin límite configurado NO se permite la operación.
            // Fallar cerrado, nunca abierto.
            throw new AccesoDenegado(
                'No hay límites configurados para esta operación. Contacta al administrador.'
            );
        }

        return $limite;
    }

    /** @return array<string,mixed> */
    private function solicitarAprobacion(
        int $actorId,
        array $datos,
        Monto $monto,
        int $origenId,
        int $destinoId
    ): array {
        $uuid = LibroMayor::uuid4();

        BaseDatos::ejecutar(
            'INSERT INTO aprobaciones
               (uuid, tipo_operacion, payload_json, importe_cent, solicitada_por, expira_en)
             VALUES (?, "transferencia", ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))',
            [
                $uuid,
                json_encode([
                    'cuenta_origen_id'  => $origenId,
                    'cuenta_destino_id' => $destinoId,
                    'monto_cent'        => $monto->centavos,
                    'concepto'          => (string) ($datos['concepto'] ?? ''),
                ], JSON_UNESCAPED_UNICODE),
                $monto->centavos,
                $actorId,
            ]
        );

        Auditoria::registrar(
            'transferencia.requiere_aprobacion',
            Auditoria::EXITO,
            $actorId,
            'aprobacion',
            $uuid,
            ['monto' => $monto->aDecimal()]
        );

        return [
            'estado'  => 'pendiente_aprobacion',
            'uuid'    => $uuid,
            'mensaje' => 'Por el monto, la operación requiere aprobación de un segundo usuario.',
        ];
    }

    private function contextoOperacion(int $origenId, int $destinoId, Monto $monto): string
    {
        return hash('sha256', "transferencia|{$origenId}|{$destinoId}|{$monto->centavos}");
    }
}
