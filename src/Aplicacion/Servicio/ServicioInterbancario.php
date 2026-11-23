<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Interbancario\RedDePagos;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\AccesoDenegado;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Transferencias a otros bancos: Transfer365, Transfer365 Móvil y UNI.
 *
 * LA DIFERENCIA CON UNA TRANSFERENCIA INTERNA
 *
 * Una transferencia interna ocurre o no ocurre, y se acabó: las dos
 * patas del asiento están en casa.
 *
 * Ésta no. La contrapartida es otra institución, y el dinero pasa por
 * un estado intermedio real: salió de la cuenta del cliente pero aún
 * no se sabe si el banco destino lo aceptó. Ese estado intermedio se
 * contabiliza en una CUENTA TÉCNICA DE CÁMARA, que es lo que este
 * banco le debe al sistema de pagos mientras la operación viaja.
 *
 * Si el otro banco rechaza, el dinero vuelve mediante un asiento de
 * REVERSO que apunta al original. El original no se toca jamás: lo
 * que ocurrió, ocurrió, y el cliente tiene derecho a ver las dos
 * líneas en su estado de cuenta —la salida y la devolución— porque es
 * lo que de verdad pasó con su dinero.
 *
 * EL CASO DEL MISMO BANCO
 *
 * Transfer365 también sirve para mandarle a alguien de este mismo
 * banco. Ahí no hay cámara que valga: el dinero no sale a ninguna
 * parte, se acredita en el acto y la operación nace ya aplicada. Es lo
 * que pasa de verdad, y fingir un viaje que no existe sería meter un
 * saldo en tránsito que nadie debe.
 */
final class ServicioInterbancario
{
    /** El número de la cuenta técnica de cámara, creada en la 079. */
    private const CUENTA_CAMARA = '0000000006';

    public function __construct(
        private ServicioDesafio $desafios = new ServicioDesafio(),
        private ServicioNotificacion $notificaciones = new ServicioNotificacion(),
        private ServicioBeneficiarios $beneficiarios = new ServicioBeneficiarios(),
        private ServicioLimitesCliente $limites = new ServicioLimitesCliente()
    ) {
    }

    // =================================================================
    // Envío
    // =================================================================

    /**
     * @param array{
     *   cuenta_origen_id:int, beneficiario_uuid:string, canal:string,
     *   monto:mixed, concepto?:string, codigo_otp?:string,
     *   clave_idempotencia:string
     * } $datos
     * @return array<string,mixed>
     */
    public function enviar(array $datos): array
    {
        $actorId = Sesion::exigirAutenticado();

        Autorizacion::exigir('transferencia.ejecutar');

        $canal = (string) ($datos['canal'] ?? 'transfer365');

        if (!in_array($canal, ['transfer365', 'transfer365_movil', 'uni'], true)) {
            throw new TransferenciaRechazada('Canal de envío no reconocido.');
        }

        $monto = Monto::deEntradaUsuario($datos['monto']);

        // --- El destino tiene que estar en la agenda ------------------
        // No se puede enviar a un destino escrito al vuelo: ésa es la
        // protección entera de la agenda. Quien robe una sesión tiene
        // que registrar primero, avisar al dueño y esperar.
        $benef = $this->beneficiarios->exigirDe(
            (string) ($datos['beneficiario_uuid'] ?? ''),
            $actorId
        );

        if ((int) $benef['usable'] !== 1) {
            if ($benef['estado'] === 'bloqueado') {
                throw new TransferenciaRechazada('Ese destino está bloqueado.');
            }

            throw new TransferenciaRechazada(
                'Ese destino todavía está en período de espera. '
                . 'Podrá usarlo a partir de las '
                . date('H:i \d\e\l d/m', (int) strtotime((string) $benef['enfriamiento_hasta'])) . '.'
            );
        }

        $this->exigirCanalDelBanco($benef, $canal);

        // --- La cuenta de origen, comprobando propiedad ---------------
        $origenId = (int) $datos['cuenta_origen_id'];

        $cuentaOrigen = Autorizacion::exigirCuenta(
            $origenId,
            $actorId,
            'transferencia.ejecutar_terceros'
        );
        Autorizacion::exigirCuentaOperable($cuentaOrigen);

        if ($cuentaOrigen['moneda'] !== 'USD') {
            throw new TransferenciaRechazada(
                'El sistema de pagos del BCR solo opera en dólares.'
            );
        }

        // --- Los dos techos: el del banco y el del cliente ------------
        $limiteBanco = $this->limiteInstitucional($canal);
        $this->verificarLimiteInstitucional($origenId, $monto, $limiteBanco);
        $this->limites->verificar($actorId, $canal, $monto);

        // --- Segundo factor, atado a ESTA operación -------------------
        if ((bool) $limiteBanco['requiere_otp']) {
            $this->desafios->consumir(
                $actorId,
                'interbancaria',
                (string) ($datos['codigo_otp'] ?? ''),
                $this->contexto($origenId, (string) $benef['uuid'], $monto)
            );
        }

        // --- Doble control por monto ----------------------------------
        $umbral = $limiteBanco['umbral_doble_control_cent'] ?? null;

        if ($umbral !== null && $monto->centavos >= (int) $umbral) {
            return $this->solicitarAprobacion($actorId, $datos, $monto, $origenId, $benef);
        }

        // --- ¿Está abierta la ventana del canal? ----------------------
        $abierta = RedDePagos::ventanaAbierta($canal);

        $mismoBanco = (int) $benef['es_propio'] === 1;

        return $this->ejecutar(
            $actorId, $cuentaOrigen, $benef, $canal, $monto,
            (string) ($datos['concepto'] ?? ''),
            (string) $datos['clave_idempotencia'],
            $abierta || $mismoBanco,
            $mismoBanco
        );
    }

    /**
     * El movimiento de dinero, ya con todo verificado.
     *
     * @param array<string,mixed> $cuentaOrigen
     * @param array<string,mixed> $benef
     * @return array<string,mixed>
     */
    private function ejecutar(
        int $actorId,
        array $cuentaOrigen,
        array $benef,
        string $canal,
        Monto $monto,
        string $concepto,
        string $claveIdempotencia,
        bool $ventanaAbierta,
        bool $mismoBanco
    ): array {
        $origenId = (int) $cuentaOrigen['id'];
        $concepto = mb_substr(trim($concepto !== '' ? $concepto : 'Transferencia'), 0, 160);

        /* El destino dentro de casa, si lo hay: una cuenta de este
           banco a la que el dinero puede entrar directamente. */
        $destinoInterno = $mismoBanco
            ? $this->cuentaInternaDe($benef)
            : null;

        $camara = $this->cuentaCamara();

        $contrapartidaId = $destinoInterno !== null
            ? (int) $destinoInterno['id']
            : (int) $camara['id'];

        $resultado = BaseDatos::enTransaccion(function () use (
            $actorId, $origenId, $contrapartidaId, $monto, $concepto, $claveIdempotencia,
            $benef, $canal, $destinoInterno, $ventanaAbierta, $mismoBanco
        ): array {
            $bloqueadas = LibroMayor::bloquearCuentas([$origenId, $contrapartidaId]);

            $origen = $bloqueadas[$origenId] ?? null;
            $contra = $bloqueadas[$contrapartidaId] ?? null;

            if ($origen === null || $contra === null) {
                throw new TransferenciaRechazada('No se pudo bloquear alguna de las cuentas.');
            }

            // --- Fondos, con el saldo ya bloqueado --------------------
            $disponible = Monto::deCentavos((int) $origen['saldo_cent'])
                ->menos(Monto::deCentavos((int) $origen['saldo_retenido_cent']))
                ->menos(Monto::deCentavos((int) $origen['saldo_minimo_cent']));

            if ($disponible->menorQue($monto)) {
                Auditoria::registrar(
                    'interbancaria.fondos_insuficientes',
                    Auditoria::FALLO,
                    $actorId,
                    'cuenta',
                    (string) $origenId,
                    ['solicitado' => $monto->aDecimal(), 'disponible' => $disponible->aDecimal()]
                );

                throw new FondosInsuficientes(
                    'Fondos insuficientes. Disponible: ' . $disponible->formateado() . '.'
                );
            }

            $folio = Folio::unico('T365', 'transferencias_interbancarias', 'folio', 'ymd', 5);

            $destinoTexto = $this->destinoLegible($benef);

            // --- El asiento ------------------------------------------
            $asiento = LibroMayor::registrar(
                'interbancaria',
                [
                    ['cuenta_id' => $origenId, 'importe' => $monto->negado(),
                     'descripcion' => $this->etiquetaCanal($canal) . ' enviada a '
                                      . $benef['banco'] . ' ' . $destinoTexto],
                    ['cuenta_id' => $contrapartidaId, 'importe' => $monto,
                     'descripcion' => $destinoInterno !== null
                        ? $this->etiquetaCanal($canal) . ' recibida de ' . $origen['numero_cuenta']
                        : 'Cámara interbancaria · envío ' . $folio],
                ],
                $actorId,
                $claveIdempotencia,
                $concepto
            );

            if (($asiento['duplicado'] ?? false) === true) {
                return [
                    'estado'     => 'duplicado',
                    'referencia' => $asiento['referencia'],
                    'mensaje'    => 'Esta transferencia ya había sido procesada.',
                ];
            }

            /* Si va al mismo banco, llegó: no hay viaje. Si sale fuera y
               la ventana está abierta, queda enviada a la espera de la
               respuesta de la red. Si está cerrada, queda creada y sale
               en la próxima apertura. */
            $estado = $mismoBanco
                ? 'aplicada'
                : ($ventanaAbierta ? 'enviada' : 'creada');

            $uuid = LibroMayor::uuid4();

            BaseDatos::ejecutar(
                'INSERT INTO transferencias_interbancarias
                   (uuid, folio, sentido, canal, usuario_id, cuenta_id, beneficiario_id,
                    banco_contraparte_id, cuenta_contraparte, titular_contraparte,
                    celular_contraparte, monto_cent, comision_cent, concepto, estado,
                    referencia_externa, asiento_envio_id, enviada_en, resuelta_en)
                 VALUES (?, ?, "enviada", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $uuid,
                    $folio,
                    $canal,
                    $actorId,
                    $origenId,
                    (int) $benef['id'],
                    (int) $benef['banco_id'],
                    str_starts_with((string) $benef['numero_cuenta'], 'CEL:')
                        ? '' : (string) $benef['numero_cuenta'],
                    (string) $benef['titular_nombre'],
                    (string) $benef['celular'],
                    $monto->centavos,
                    RedDePagos::COMISION_CENT,
                    $concepto,
                    $estado,
                    $estado === 'creada' ? '' : $this->referenciaRed($folio),
                    (int) $asiento['asiento_id'],
                    $estado === 'creada' ? null : date('Y-m-d H:i:s'),
                    $estado === 'aplicada' ? date('Y-m-d H:i:s') : null,
                ]
            );

            BaseDatos::ejecutar(
                'UPDATE beneficiarios
                    SET veces_usado = veces_usado + 1, ultima_uso_en = NOW()
                  WHERE id = ?',
                [(int) $benef['id']]
            );

            Auditoria::registrar(
                'interbancaria.enviada',
                Auditoria::EXITO,
                $actorId,
                'transferencia_interbancaria',
                $folio,
                [
                    'canal'   => $canal,
                    'banco'   => $benef['banco'],
                    'destino' => $destinoTexto,
                    'monto'   => $monto->aDecimal(),
                    'estado'  => $estado,
                ]
            );

            // --- Avisos ----------------------------------------------
            $this->notificaciones->crear(
                $actorId,
                'interbancaria.enviada',
                $estado === 'creada' ? 'Transferencia programada' : 'Transferencia enviada',
                'Envió ' . $monto->formateado() . ' a ' . $benef['alias']
                . ' (' . $benef['banco'] . ')'
                . ($estado === 'creada'
                    ? '. Saldrá a las ' . date('H:i \d\e\l d/m', RedDePagos::proximaVentana($canal)) . '.'
                    : '. Folio ' . $folio . '.'),
                ['folio' => $folio, 'uuid' => $uuid],
                $estado === 'creada' ? 'info' : 'exito'
            );

            /* Si el destino es de este banco, el que recibe también se
               entera, y se entera ahora. */
            if ($destinoInterno !== null) {
                $this->notificaciones->crear(
                    (int) $destinoInterno['usuario_id'],
                    'interbancaria.recibida',
                    'Dinero recibido',
                    'Recibió ' . $monto->formateado() . ' por '
                    . $this->etiquetaCanal($canal) . '. Folio ' . $folio . '.',
                    ['folio' => $folio],
                    'exito'
                );
            }

            return [
                'estado'           => $estado,
                'folio'            => $folio,
                'uuid'             => $uuid,
                'referencia'       => $asiento['referencia'],
                'monto'            => $monto->jsonSerialize(),
                'banco'            => $benef['banco'],
                'alias'            => $benef['alias'],
                'titular'          => $benef['titular_nombre'],
                'canal'            => $canal,
                'cuenta_destino_interna' => $destinoInterno !== null ? (int) $destinoInterno['id'] : null,
                'sale_a'           => $estado === 'creada'
                    ? date('c', RedDePagos::proximaVentana($canal)) : null,
                'saldo_final'      => Monto::deCentavos(
                    (int) $origen['saldo_cent'] - $monto->centavos
                )->jsonSerialize(),
                'fecha'            => date('c'),
            ];
        });

        /* La compensación de mora sobre quien recibió, si recibió aquí
           dentro. Fuera de la transacción a propósito: abre la suya, y
           anidarlas haría que un fallo cobrando deshiciera un envío que
           ya era válido. */
        if (($resultado['cuenta_destino_interna'] ?? null) !== null) {
            $compensado = (new ServicioCompensacion($this->notificaciones))
                ->trasAbono((int) $resultado['cuenta_destino_interna'], 'abono');

            if ((int) $compensado['aplicado_cent'] > 0) {
                $resultado['compensacion'] = $compensado;
            }
        }

        unset($resultado['cuenta_destino_interna']);

        return $resultado;
    }

    // =================================================================
    // Respuesta de la red
    // =================================================================

    /**
     * La red confirmó que el banco destino aceptó el dinero.
     *
     * No mueve nada: el dinero ya salió al enviarse. Solo cierra el
     * estado, que es lo que estaba pendiente.
     */
    public function confirmar(string $folio, string $referenciaExterna = ''): void
    {
        $t = $this->exigirTransferencia($folio);

        if ($t['estado'] !== 'enviada') {
            throw new TransferenciaRechazada(
                'Solo se puede confirmar una transferencia enviada; ésta está ' . $t['estado'] . '.'
            );
        }

        BaseDatos::ejecutar(
            'UPDATE transferencias_interbancarias
                SET estado = "aplicada", resuelta_en = NOW(),
                    referencia_externa = IF(? = "", referencia_externa, ?)
              WHERE id = ?',
            [$referenciaExterna, $referenciaExterna, (int) $t['id']]
        );

        Auditoria::registrar(
            'interbancaria.aplicada',
            Auditoria::EXITO,
            null,
            'transferencia_interbancaria',
            $folio,
            ['monto' => Monto::deCentavos((int) $t['monto_cent'])->aDecimal()]
        );

        $this->notificaciones->crear(
            (int) $t['usuario_id'],
            'interbancaria.aplicada',
            'Transferencia aplicada',
            'Su envío de ' . Monto::deCentavos((int) $t['monto_cent'])->formateado()
            . ' (folio ' . $folio . ') fue acreditado en el banco destino.',
            ['folio' => $folio],
            'exito'
        );
    }

    /**
     * El banco destino rechazó, o devolvió más tarde. El dinero vuelve.
     *
     * Vuelve con un asiento de REVERSO que apunta al original. Nunca se
     * borra ni se edita el asiento de salida: el cliente tiene derecho
     * a ver en su estado de cuenta que el dinero salió y volvió, porque
     * es lo que ocurrió de verdad.
     */
    public function devolver(string $folio, string $motivo, bool $tardia = false): void
    {
        $t = $this->exigirTransferencia($folio);

        if (!in_array($t['estado'], ['enviada', 'aplicada'], true)) {
            throw new TransferenciaRechazada(
                'No se puede devolver una transferencia en estado ' . $t['estado'] . '.'
            );
        }

        if ($t['asiento_reverso_id'] !== null) {
            return; // Ya se devolvió: no se devuelve dos veces.
        }

        $monto  = Monto::deCentavos((int) $t['monto_cent']);
        $camara = $this->cuentaCamara();

        BaseDatos::enTransaccion(function () use ($t, $monto, $camara, $motivo, $folio): void {
            $cuentaId = (int) $t['cuenta_id'];
            $camaraId = (int) $camara['id'];

            LibroMayor::bloquearCuentas([$cuentaId, $camaraId]);

            $asiento = LibroMayor::registrar(
                'interbancaria',
                [
                    ['cuenta_id' => $camaraId, 'importe' => $monto->negado(),
                     'descripcion' => 'Cámara interbancaria · devolución ' . $folio],
                    ['cuenta_id' => $cuentaId, 'importe' => $monto,
                     'descripcion' => 'Devolución de transferencia ' . $folio
                                      . ' · ' . RedDePagos::explicar($motivo)],
                ],
                null,
                'devolucion-' . $folio,
                'Devolución de ' . $folio,
                (int) $t['asiento_envio_id']
            );

            BaseDatos::ejecutar(
                'UPDATE transferencias_interbancarias
                    SET estado = ?, motivo = ?, resuelta_en = NOW(), asiento_reverso_id = ?
                  WHERE id = ?',
                [
                    $t['estado'] === 'aplicada' ? 'devuelta' : 'rechazada',
                    mb_substr(RedDePagos::explicar($motivo), 0, 160),
                    (int) $asiento['asiento_id'],
                    (int) $t['id'],
                ]
            );
        });

        Auditoria::registrar(
            'interbancaria.devuelta',
            Auditoria::EXITO,
            null,
            'transferencia_interbancaria',
            $folio,
            ['motivo' => $motivo, 'monto' => $monto->aDecimal(), 'tardia' => $tardia]
        );

        $this->notificaciones->crear(
            (int) $t['usuario_id'],
            'interbancaria.devuelta',
            $tardia ? 'Le devolvieron una transferencia' : 'Transferencia rechazada',
            'El envío de ' . $monto->formateado() . ' (folio ' . $folio . ') no se pudo aplicar: '
            . RedDePagos::explicar($motivo) . ' El dinero ya está de vuelta en su cuenta.',
            ['folio' => $folio, 'motivo' => $motivo],
            'alerta'
        );
    }

    /**
     * Dinero que entra desde otro banco.
     *
     * La cámara se debita y el cliente se acredita. Es el reflejo
     * exacto del envío, y es por donde llega un sueldo o una
     * transferencia de un familiar desde otra institución.
     *
     * @return array<string,mixed>
     */
    public function recibir(
        int $cuentaDestinoId,
        int $bancoOrigenId,
        Monto $monto,
        string $titularOrigen,
        string $cuentaOrigen = '',
        string $concepto = '',
        string $canal = 'transfer365'
    ): array {
        $camara = $this->cuentaCamara();

        return BaseDatos::enTransaccion(function () use (
            $cuentaDestinoId, $bancoOrigenId, $monto, $titularOrigen,
            $cuentaOrigen, $concepto, $canal, $camara
        ): array {
            $camaraId = (int) $camara['id'];

            $bloqueadas = LibroMayor::bloquearCuentas([$cuentaDestinoId, $camaraId]);
            $destino    = $bloqueadas[$cuentaDestinoId] ?? null;

            if ($destino === null) {
                throw new TransferenciaRechazada('La cuenta destino no existe.');
            }

            if ($destino['estado'] !== 'activa' || (int) $destino['es_tecnica'] === 1) {
                throw new TransferenciaRechazada('La cuenta destino no puede recibir fondos.');
            }

            $folio = Folio::unico('T365', 'transferencias_interbancarias', 'folio', 'ymd', 5);

            $banco = BaseDatos::unaFila(
                'SELECT * FROM bancos_participantes WHERE id = ?',
                [$bancoOrigenId]
            );

            $asiento = LibroMayor::registrar(
                'interbancaria',
                [
                    ['cuenta_id' => $camaraId, 'importe' => $monto->negado(),
                     'descripcion' => 'Cámara interbancaria · recepción ' . $folio],
                    ['cuenta_id' => $cuentaDestinoId, 'importe' => $monto,
                     'descripcion' => $this->etiquetaCanal($canal) . ' recibida de '
                                      . ($banco['nombre_corto'] ?? 'otro banco')
                                      . ' · ' . $titularOrigen],
                ],
                null,
                'recepcion-' . $folio,
                $concepto !== '' ? mb_substr($concepto, 0, 160) : 'Transferencia recibida'
            );

            $uuid = LibroMayor::uuid4();

            BaseDatos::ejecutar(
                'INSERT INTO transferencias_interbancarias
                   (uuid, folio, sentido, canal, usuario_id, cuenta_id,
                    banco_contraparte_id, cuenta_contraparte, titular_contraparte,
                    monto_cent, concepto, estado, referencia_externa,
                    asiento_envio_id, enviada_en, resuelta_en)
                 VALUES (?, ?, "recibida", ?, ?, ?, ?, ?, ?, ?, ?, "aplicada", ?, ?, NOW(), NOW())',
                [
                    $uuid, $folio, $canal,
                    (int) $destino['usuario_id'], $cuentaDestinoId, $bancoOrigenId,
                    $cuentaOrigen, $titularOrigen, $monto->centavos,
                    mb_substr($concepto, 0, 160),
                    $this->referenciaRed($folio),
                    (int) $asiento['asiento_id'],
                ]
            );

            $this->notificaciones->crear(
                (int) $destino['usuario_id'],
                'interbancaria.recibida',
                'Dinero recibido de otro banco',
                'Recibió ' . $monto->formateado() . ' de ' . $titularOrigen
                . ' (' . ($banco['nombre_corto'] ?? 'otro banco') . '). Folio ' . $folio . '.',
                ['folio' => $folio],
                'exito'
            );

            return ['folio' => $folio, 'uuid' => $uuid, 'estado' => 'aplicada'];
        });
    }

    // =================================================================
    // Consulta
    // =================================================================

    /** @return array<int,array<string,mixed>> */
    public function historial(int $usuarioId, int $limite = 50): array
    {
        return BaseDatos::todas(
            'SELECT t.*, bp.nombre_corto AS banco, b.alias
               FROM transferencias_interbancarias t
               JOIN bancos_participantes bp ON bp.id = t.banco_contraparte_id
               LEFT JOIN beneficiarios b ON b.id = t.beneficiario_id
              WHERE t.usuario_id = ?
              ORDER BY t.creada_en DESC
              LIMIT ' . max(1, min(500, $limite)),
            [$usuarioId]
        );
    }

    /**
     * Las que esperan a que abra la ventana del canal.
     *
     * Las despacha el latido del sistema: el cliente no tiene que
     * volver a entrar para que su transferencia salga.
     *
     * @return array<int,array<string,mixed>>
     */
    public function pendientesDeVentana(): array
    {
        return BaseDatos::todas(
            'SELECT * FROM transferencias_interbancarias
              WHERE estado = "creada" AND sentido = "enviada"
              ORDER BY creada_en'
        );
    }

    /** Pone en camino las que estaban esperando la apertura. */
    public function despacharPendientes(): int
    {
        $salieron = 0;

        foreach ($this->pendientesDeVentana() as $t) {
            if (!RedDePagos::ventanaAbierta((string) $t['canal'])) {
                continue;
            }

            BaseDatos::ejecutar(
                'UPDATE transferencias_interbancarias
                    SET estado = "enviada", enviada_en = NOW(), referencia_externa = ?
                  WHERE id = ? AND estado = "creada"',
                [$this->referenciaRed((string) $t['folio']), (int) $t['id']]
            );

            $this->notificaciones->crear(
                (int) $t['usuario_id'],
                'interbancaria.enviada',
                'Su transferencia salió',
                'El envío de ' . Monto::deCentavos((int) $t['monto_cent'])->formateado()
                . ' (folio ' . $t['folio'] . ') ya salió hacia el banco destino.',
                ['folio' => $t['folio']],
                'info'
            );

            $salieron++;
        }

        return $salieron;
    }

    // =================================================================
    // Interno
    // =================================================================

    /** @return array<string,mixed> */
    private function exigirTransferencia(string $folio): array
    {
        $t = BaseDatos::unaFila(
            'SELECT * FROM transferencias_interbancarias WHERE folio = ?',
            [$folio]
        );

        if ($t === null) {
            throw new TransferenciaRechazada('No existe una transferencia con el folio ' . $folio . '.');
        }

        return $t;
    }

    /** @return array<string,mixed> */
    private function cuentaCamara(): array
    {
        $c = BaseDatos::unaFila(
            'SELECT * FROM cuentas WHERE numero_cuenta = ? AND es_tecnica = 1',
            [self::CUENTA_CAMARA]
        );

        if ($c === null) {
            /* Sin cámara no hay contrapartida y el asiento no cuadraría.
               Fallar aquí, ruidosamente, antes de tocar dinero. */
            throw new \RuntimeException(
                'Falta la cuenta técnica de cámara interbancaria (' . self::CUENTA_CAMARA . ').'
            );
        }

        return $c;
    }

    /**
     * La cuenta de este banco a la que apunta un beneficiario propio.
     *
     * @param array<string,mixed> $benef
     * @return array<string,mixed>|null
     */
    private function cuentaInternaDe(array $benef): ?array
    {
        $numero = (string) $benef['numero_cuenta'];

        /* Un destino de Transfer365 Móvil se resuelve por el celular,
           que es justamente la gracia del servicio. */
        if (str_starts_with($numero, 'CEL:')) {
            return BaseDatos::unaFila(
                'SELECT c.* FROM celulares_transfer365 ct
                   JOIN cuentas c ON c.id = ct.cuenta_id
                  WHERE ct.celular = ? AND ct.activo = 1 AND c.estado = "activa"',
                [substr($numero, 4)]
            );
        }

        return BaseDatos::unaFila(
            'SELECT * FROM cuentas
              WHERE numero_cuenta = ? AND es_tecnica = 0 AND estado = "activa"',
            [$numero]
        );
    }

    /** @param array<string,mixed> $benef */
    private function exigirCanalDelBanco(array $benef, string $canal): void
    {
        $columna = $canal === 'uni' ? 'uni' : $canal;

        if ((int) ($benef[$columna] ?? 0) !== 1) {
            throw new TransferenciaRechazada(
                $benef['banco'] . ' no participa en ' . $this->etiquetaCanal($canal) . '.'
            );
        }

        if ($canal === 'transfer365_movil' && (string) $benef['celular'] === '') {
            throw new TransferenciaRechazada(
                'Ese destino no tiene celular registrado: use Transfer365 con el número de cuenta.'
            );
        }
    }

    private function etiquetaCanal(string $canal): string
    {
        return match ($canal) {
            'transfer365_movil' => 'Transfer365 Móvil',
            'uni'               => 'UNI',
            default             => 'Transfer365',
        };
    }

    /** @param array<string,mixed> $benef */
    private function destinoLegible(array $benef): string
    {
        $n = (string) $benef['numero_cuenta'];

        if (str_starts_with($n, 'CEL:')) {
            return RedDePagos::celularLegible(substr($n, 4));
        }

        return '****' . substr($n, -4);
    }

    /**
     * La referencia que devolvería el sistema de pagos.
     *
     * Se deriva del folio para que sea estable y reproducible: dos
     * llamadas sobre la misma transferencia dan la misma referencia, y
     * nunca choca con la de otra.
     */
    private function referenciaRed(string $folio): string
    {
        return 'BCR' . strtoupper(substr(hash('sha256', $folio), 0, 12));
    }

    /** @return array<string,mixed> */
    private function limiteInstitucional(string $canal): array
    {
        $limite = BaseDatos::unaFila(
            'SELECT * FROM limites_operacion
              WHERE tipo_operacion = ? AND activo = 1 AND (rol_id = ? OR rol_id IS NULL)
              ORDER BY rol_id IS NULL ASC
              LIMIT 1',
            [$canal === 'uni' ? 'transfer365' : $canal, Sesion::rolId()]
        );

        if ($limite === null) {
            // Sin límite configurado NO se opera. Fallar cerrado.
            throw new AccesoDenegado(
                'No hay límites configurados para ' . $this->etiquetaCanal($canal) . '.'
            );
        }

        return $limite;
    }

    /** @param array<string,mixed> $limite */
    private function verificarLimiteInstitucional(int $cuentaId, Monto $monto, array $limite): void
    {
        if ($monto->centavos > (int) $limite['max_por_operacion_cent']) {
            throw new LimiteExcedidoTransferencia(
                'El monto supera el máximo por operación ('
                . Monto::deCentavos((int) $limite['max_por_operacion_cent'])->formateado() . ').'
            );
        }

        $hoy = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(monto_cent), 0)
               FROM transferencias_interbancarias
              WHERE cuenta_id = ? AND sentido = "enviada"
                AND estado <> "rechazada"
                AND DATE(creada_en) = CURDATE()',
            [$cuentaId]
        );

        if ($hoy + $monto->centavos > (int) $limite['max_diario_cent']) {
            $restante = Monto::deCentavos(max(0, (int) $limite['max_diario_cent'] - $hoy));

            throw new LimiteExcedidoTransferencia(
                'Supera el límite diario del banco. Disponible hoy: ' . $restante->formateado() . '.'
            );
        }

        $ops = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM transferencias_interbancarias
              WHERE cuenta_id = ? AND sentido = "enviada"
                AND estado <> "rechazada" AND DATE(creada_en) = CURDATE()',
            [$cuentaId]
        );

        if ($ops >= (int) $limite['max_ops_diarias']) {
            throw new LimiteExcedidoTransferencia(
                'Alcanzó el máximo de envíos por día (' . $limite['max_ops_diarias'] . ').'
            );
        }
    }

    /**
     * @param array<string,mixed> $benef
     * @return array<string,mixed>
     */
    private function solicitarAprobacion(
        int $actorId,
        array $datos,
        Monto $monto,
        int $origenId,
        array $benef
    ): array {
        $uuid = LibroMayor::uuid4();

        BaseDatos::ejecutar(
            'INSERT INTO aprobaciones
               (uuid, tipo_operacion, payload_json, importe_cent, solicitada_por, expira_en)
             VALUES (?, "interbancaria", ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))',
            [
                $uuid,
                json_encode([
                    'cuenta_origen_id'  => $origenId,
                    'beneficiario_uuid' => (string) $benef['uuid'],
                    'canal'             => (string) ($datos['canal'] ?? 'transfer365'),
                    'monto_cent'        => $monto->centavos,
                    'concepto'          => (string) ($datos['concepto'] ?? ''),
                ], JSON_UNESCAPED_UNICODE),
                $monto->centavos,
                $actorId,
            ]
        );

        Auditoria::registrar(
            'interbancaria.requiere_aprobacion',
            Auditoria::EXITO,
            $actorId,
            'aprobacion',
            $uuid,
            ['monto' => $monto->aDecimal(), 'banco' => $benef['banco']]
        );

        return [
            'estado'  => 'pendiente_aprobacion',
            'uuid'    => $uuid,
            'mensaje' => 'Por el monto, la operación requiere aprobación de un segundo usuario.',
        ];
    }

    private function contexto(int $origenId, string $benefUuid, Monto $monto): string
    {
        return hash('sha256', "interbancaria|{$origenId}|{$benefUuid}|{$monto->centavos}");
    }
}
