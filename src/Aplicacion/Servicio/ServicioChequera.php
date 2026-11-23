<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Chequeras y cheques.
 *
 * UN CHEQUE NO ES UNA TRANSFERENCIA CON OTRO NOMBRE
 *
 * Es un TÍTULO VALOR: un papel numerado que circula fuera del banco,
 * que se presenta semanas después de firmarse, que puede perderse y
 * que puede aparecer dos veces.
 *
 * De ahí la decisión central: cada cheque existe en la base DESDE QUE
 * SE IMPRIME, en blanco, mucho antes de que nadie lo llene. Un papel
 * que se presenta al cobro y no está en esta tabla no es un cheque del
 * banco: es un papel, y hay que rechazarlo.
 *
 * Y de ahí el UNIQUE de (cuenta, serie, número): es lo único que
 * impide pagar dos veces el mismo papel, que es el fraude clásico con
 * cheques y el que no se nota hasta que el cliente reclama.
 *
 * EL RECHAZO NO ES UN ERROR DEL SISTEMA
 *
 * Es un hecho con consecuencias legales. Por eso el motivo se guarda
 * en una lista cerrada: «sin fondos» y «firma no coincide» no son lo
 * mismo ante un juez, y un campo de texto libre convertiría un acto
 * jurídico en una nota suelta.
 */
final class ServicioChequera
{
    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    private function parametro(string $clave, string $porDefecto = '0'): string
    {
        $v = BaseDatos::unValor(
            'SELECT valor FROM chequera_parametros WHERE clave = ?',
            [$clave]
        );

        return $v !== null ? (string) $v : $porDefecto;
    }

    // =================================================================
    // Solicitar
    // =================================================================

    /**
     * @return array<string,mixed>
     */
    public function solicitar(int $cuentaId, ?int $usuarioId = null): array
    {
        $actorId = Sesion::exigirAutenticado();

        Autorizacion::exigir('chequera.solicitar');

        $cuenta = Autorizacion::exigirCuenta($cuentaId, $actorId, 'chequera.administrar');
        Autorizacion::exigirCuentaOperable($cuenta);

        /* Solo cuentas corrientes. Una cuenta de ahorro no gira
           cheques: no es una restricción del sistema, es lo que es una
           cuenta de ahorro. */
        if (($cuenta['producto_tipo'] ?? '') !== 'corriente') {
            throw new ChequeraInvalida(
                'Solo las cuentas corrientes pueden tener chequera. '
                . 'Ésta es una cuenta de ' . ($cuenta['producto_tipo'] ?? 'otro tipo') . '.'
            );
        }

        /* Una chequera pendiente a la vez. Pedir cinco de golpe deja
           cincuenta cheques en blanco circulando sin control. */
        $pendiente = BaseDatos::unaFila(
            'SELECT folio, estado FROM chequeras
              WHERE cuenta_id = ? AND estado IN ("solicitada", "impresa")
              LIMIT 1',
            [$cuentaId]
        );

        if ($pendiente !== null) {
            throw new ChequeraInvalida(
                'Ya hay una chequera ' . $pendiente['estado'] . ' para esta cuenta ('
                . $pendiente['folio'] . '). Retírela antes de pedir otra.'
            );
        }

        $cantidad = max(10, min(100, (int) $this->parametro('cheques_por_chequera', '25')));
        $comision = (int) $this->parametro('comision_cent', '500');

        /* El número siguiente de ESTA cuenta. Nunca se reutilizan: un
           número repetido haría imposible saber cuál de los dos
           cheques se está pagando. */
        $ultimo = (int) (BaseDatos::unValor(
            'SELECT COALESCE(MAX(hasta_numero), 0) FROM chequeras WHERE cuenta_id = ?',
            [$cuentaId]
        ) ?? 0);

        $desde = $ultimo + 1;
        $hasta = $ultimo + $cantidad;

        $folio = Folio::unico('CHQ', 'chequeras', 'folio', 'ymd', 5);

        $titular = (int) $cuenta['usuario_id'];

        $r = BaseDatos::enTransaccion(function () use (
            $cuentaId, $titular, $folio, $desde, $hasta, $cantidad, $comision, $actorId
        ): array {
            $asientoId = null;

            if ($comision > 0) {
                $general = (int) BaseDatos::unValor(
                    'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
                );

                $bloqueadas = LibroMayor::bloquearCuentas([$cuentaId, $general]);
                $c = $bloqueadas[$cuentaId] ?? null;

                $disponible = (int) $c['saldo_cent']
                            - (int) $c['saldo_retenido_cent']
                            - (int) $c['saldo_minimo_cent'];

                if ($disponible < $comision) {
                    throw new FondosInsuficientes(
                        'No hay fondos para la comisión de '
                        . Monto::deCentavos($comision)->formateado() . '.'
                    );
                }

                $m = Monto::deCentavos($comision);

                $asiento = LibroMayor::registrar(
                    'comision',
                    [
                        ['cuenta_id' => $cuentaId, 'importe' => $m->negado(),
                         'descripcion' => 'Emisión de chequera ' . $folio],
                        ['cuenta_id' => $general, 'importe' => $m,
                         'descripcion' => 'Comisión por chequera ' . $folio],
                    ],
                    $actorId,
                    'chequera-' . $folio,
                    'Comisión por emisión de chequera'
                );

                $asientoId = (int) $asiento['asiento_id'];
            }

            BaseDatos::ejecutar(
                'INSERT INTO chequeras
                   (uuid, folio, cuenta_id, usuario_id, serie, desde_numero,
                    hasta_numero, cantidad, estado, comision_cent, asiento_id)
                 VALUES (?, ?, ?, ?, "A", ?, ?, ?, "solicitada", ?, ?)',
                [
                    LibroMayor::uuid4(), $folio, $cuentaId, $titular,
                    $desde, $hasta, $cantidad, $comision, $asientoId,
                ]
            );

            return ['id' => BaseDatos::ultimoId(), 'folio' => $folio];
        });

        Auditoria::registrar(
            'chequera.solicitada',
            Auditoria::EXITO,
            $actorId,
            'chequera',
            $folio,
            ['cuenta' => $cuenta['numero_cuenta'], 'cheques' => $cantidad]
        );

        $this->notificaciones->crear(
            $titular,
            'chequera.solicitada',
            'Chequera solicitada',
            'Su chequera de ' . $cantidad . ' cheques (números ' . $desde . ' al '
            . $hasta . ') está en proceso. Le avisaremos cuando pueda retirarla.'
            . ($comision > 0
                ? ' Se cobró la comisión de ' . Monto::deCentavos($comision)->formateado() . '.'
                : ''),
            ['folio' => $folio],
            'info'
        );

        return $r + [
            'desde' => $desde,
            'hasta' => $hasta,
            'cantidad' => $cantidad,
            'comision_cent' => $comision,
        ];
    }

    /**
     * Imprime la chequera: crea los cheques EN BLANCO.
     *
     * Es el momento en que los papeles empiezan a existir para el
     * banco. A partir de aquí, un cheque con uno de estos números es
     * reconocible; cualquier otro es un papel.
     */
    public function imprimir(string $folio, ?int $actorId = null): int
    {
        Autorizacion::exigir('chequera.administrar');

        $ch = $this->exigirChequera($folio);

        if ($ch['estado'] !== 'solicitada') {
            throw new ChequeraInvalida('Esa chequera ya está ' . $ch['estado'] . '.');
        }

        $creados = BaseDatos::enTransaccion(static function () use ($ch): int {
            $n = 0;

            for ($i = (int) $ch['desde_numero']; $i <= (int) $ch['hasta_numero']; $i++) {
                BaseDatos::ejecutar(
                    'INSERT INTO cheques (chequera_id, cuenta_id, serie, numero, estado)
                     VALUES (?, ?, ?, ?, "en_blanco")',
                    [(int) $ch['id'], (int) $ch['cuenta_id'], (string) $ch['serie'], $i]
                );

                $n++;
            }

            BaseDatos::ejecutar(
                'UPDATE chequeras SET estado = "impresa", impresa_en = NOW() WHERE id = ?',
                [(int) $ch['id']]
            );

            return $n;
        });

        Auditoria::registrar(
            'chequera.impresa',
            Auditoria::EXITO,
            $actorId,
            'chequera',
            $folio,
            ['cheques' => $creados]
        );

        $this->notificaciones->crear(
            (int) $ch['usuario_id'],
            'chequera.lista',
            'Su chequera está lista',
            'Puede retirarla en cualquier agencia presentando su documento de identidad.',
            ['folio' => $folio],
            'exito'
        );

        return $creados;
    }

    /** Entrega la chequera a quien la retira. */
    public function entregar(string $folio, string $retiraNombre, ?int $actorId = null): void
    {
        Autorizacion::exigir('chequera.administrar');

        $ch = $this->exigirChequera($folio);

        if ($ch['estado'] !== 'impresa') {
            throw new ChequeraInvalida(
                'Solo se entrega una chequera impresa; ésta está ' . $ch['estado'] . '.'
            );
        }

        $retiraNombre = trim($retiraNombre);

        if (mb_strlen($retiraNombre) < 5) {
            throw new ChequeraInvalida(
                'Anote el nombre completo de quien retira: veinticinco cheques en blanco '
                . 'salen del banco y hay que saber en manos de quién.'
            );
        }

        BaseDatos::ejecutar(
            'UPDATE chequeras
                SET estado = "entregada", entregada_en = NOW(),
                    entregada_a = ?, entregada_por = ?
              WHERE id = ?',
            [mb_substr($retiraNombre, 0, 140), $actorId, (int) $ch['id']]
        );

        Auditoria::registrar(
            'chequera.entregada',
            Auditoria::EXITO,
            $actorId,
            'chequera',
            $folio,
            ['retiro' => $retiraNombre]
        );
    }

    // =================================================================
    // El cobro
    // =================================================================

    /**
     * Presenta un cheque al cobro y lo paga, o lo rechaza con su razón.
     *
     * @return array<string,mixed>
     */
    public function presentar(
        int $cuentaId,
        int $numero,
        int $montoCent,
        string $beneficiario,
        string $serie = 'A',
        ?int $actorId = null
    ): array {
        Autorizacion::exigir('cheque.pagar');

        $cheque = BaseDatos::unaFila(
            'SELECT ch.*, c.estado AS cuenta_estado, c.usuario_id
               FROM cheques ch
               JOIN cuentas c ON c.id = ch.cuenta_id
              WHERE ch.cuenta_id = ? AND ch.serie = ? AND ch.numero = ?',
            [$cuentaId, $serie, $numero]
        );

        /* Un papel que no está en la tabla NO es un cheque de este
           banco. No se rechaza: no existe. */
        if ($cheque === null) {
            throw new ChequeraInvalida(
                'No existe el cheque ' . $serie . '-' . $numero . ' para esa cuenta. '
                . 'Verifique el número: si es correcto, el documento no fue emitido '
                . 'por esta institución.'
            );
        }

        $rechazo = $this->razonDeRechazo($cheque, $montoCent);

        return BaseDatos::enTransaccion(function () use (
            $cheque, $montoCent, $beneficiario, $rechazo, $actorId, $serie, $numero
        ): array {
            $chequeId = (int) $cheque['id'];
            $cuentaId = (int) $cheque['cuenta_id'];

            if ($rechazo !== null) {
                $comision = (int) $this->parametro('comision_rechazo_cent', '1000');

                /* UN CHEQUE YA RESUELTO NO SE REESCRIBE.
                 *
                 * Si alguien vuelve a presentar uno que ya se pagó
                 * —el fraude clásico— no se puede machacar su estado:
                 * quedaría como «rechazado» un cheque cuyo dinero SÍ
                 * salió de la cuenta, y el estado de cuenta del cliente
                 * diría una cosa mientras el cheque dice otra.
                 *
                 * El intento se rechaza y queda en la bitácora, que es
                 * donde tiene que quedar. El papel conserva su historia.
                 *
                 * Lo mismo con uno ya rechazado: la razón del PRIMER
                 * rechazo es la que tiene efectos legales, no la del
                 * segundo intento. */
                $yaResuelto = in_array($cheque['estado'], ['pagado', 'rechazado'], true);

                if (!$yaResuelto) {
                    BaseDatos::ejecutar(
                        'UPDATE cheques
                            SET estado = "rechazado", motivo_rechazo = ?, rechazado_en = NOW(),
                                presentado_en = NOW(), presentado_por = ?,
                                beneficiario = ?, monto_cent = ?
                          WHERE id = ?',
                        [
                            $rechazo, mb_substr($beneficiario, 0, 140),
                            mb_substr($beneficiario, 0, 160), $montoCent, $chequeId,
                        ]
                    );
                }

                /* La comisión por rechazo solo se cobra cuando el
                   motivo es del librador. Si el cheque se rechazó
                   porque él mismo pidió no pagarlo, cobrarle sería
                   castigarlo por protegerse. */
                $culpaDelLibrador = in_array(
                    $rechazo,
                    ['sin_fondos', 'fondos_insuficientes'],
                    true
                );

                if ($culpaDelLibrador && $comision > 0) {
                    $this->cobrarComisionRechazo($cuentaId, $comision, $serie, $numero, $actorId);
                }

                Auditoria::registrar(
                    'cheque.rechazado',
                    Auditoria::FALLO,
                    $actorId,
                    'cheque',
                    $serie . '-' . $numero,
                    ['motivo' => $rechazo, 'monto' => Monto::deCentavos($montoCent)->aDecimal()]
                );

                $this->notificaciones->crear(
                    (int) $cheque['usuario_id'],
                    'cheque.rechazado',
                    'Se rechazó un cheque suyo',
                    'El cheque ' . $serie . '-' . $numero . ' por '
                    . Monto::deCentavos($montoCent)->formateado()
                    . ' se presentó al cobro y no se pudo pagar: '
                    . self::explicarRechazo($rechazo)
                    . ($culpaDelLibrador && $comision > 0
                        ? ' Se cobró una comisión de '
                          . Monto::deCentavos($comision)->formateado() . '.'
                        : ''),
                    ['cheque' => $serie . '-' . $numero],
                    'critico'
                );

                return [
                    'estado'  => 'rechazado',
                    'motivo'  => $rechazo,
                    'mensaje' => self::explicarRechazo($rechazo),
                ];
            }

            // --- Se paga -------------------------------------------
            $general = (int) BaseDatos::unValor(
                'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
            );

            LibroMayor::bloquearCuentas([$cuentaId, $general]);

            $m = Monto::deCentavos($montoCent);

            $asiento = LibroMayor::registrar(
                'cheque',
                [
                    ['cuenta_id' => $cuentaId, 'importe' => $m->negado(),
                     'descripcion' => 'Cheque ' . $serie . '-' . $numero . ' a '
                                    . mb_substr($beneficiario, 0, 60)],
                    ['cuenta_id' => $general, 'importe' => $m,
                     'descripcion' => 'Pago de cheque ' . $serie . '-' . $numero],
                ],
                $actorId,
                'cheque-' . $cuentaId . '-' . $serie . '-' . $numero,
                'Pago de cheque ' . $serie . '-' . $numero
            );

            BaseDatos::ejecutar(
                'UPDATE cheques
                    SET estado = "pagado", presentado_en = NOW(), pagado_en = NOW(),
                        presentado_por = ?, beneficiario = ?, monto_cent = ?,
                        asiento_id = ?
                  WHERE id = ?',
                [
                    mb_substr($beneficiario, 0, 140), mb_substr($beneficiario, 0, 160),
                    $montoCent, (int) $asiento['asiento_id'], $chequeId,
                ]
            );

            Auditoria::registrar(
                'cheque.pagado',
                Auditoria::EXITO,
                $actorId,
                'cheque',
                $serie . '-' . $numero,
                ['monto' => $m->aDecimal(), 'beneficiario' => $beneficiario]
            );

            $this->notificaciones->crear(
                (int) $cheque['usuario_id'],
                'cheque.pagado',
                'Se pagó un cheque suyo',
                'El cheque ' . $serie . '-' . $numero . ' por ' . $m->formateado()
                . ' se pagó a ' . $beneficiario . '.',
                ['cheque' => $serie . '-' . $numero],
                'info'
            );

            return [
                'estado'     => 'pagado',
                'monto_cent' => $montoCent,
                'referencia' => $asiento['referencia'],
            ];
        });
    }

    /**
     * Por qué no se puede pagar este cheque, o null si sí se puede.
     *
     * El orden importa: se devuelve la razón MÁS GRAVE, porque es la
     * que tiene consecuencias. Un cheque sobre una cuenta cerrada no
     * es «sin fondos»: es un cheque sobre una cuenta cerrada.
     *
     * @param array<string,mixed> $cheque
     */
    private function razonDeRechazo(array $cheque, int $montoCent): ?string
    {
        if ($cheque['estado'] === 'suspendido') {
            return 'suspendido_por_titular';
        }

        if (in_array($cheque['estado'], ['pagado', 'rechazado'], true)) {
            /* Ya se presentó. Que llegue otra vez es exactamente el
               fraude que el UNIQUE de número viene a impedir. */
            return 'alterado';
        }

        if ($cheque['estado'] === 'anulado') {
            return 'mal_llenado';
        }

        if ($cheque['cuenta_estado'] === 'cerrada') {
            return 'cuenta_cerrada';
        }

        if ($cheque['cuenta_estado'] !== 'activa') {
            return 'cuenta_bloqueada';
        }

        /* Posdatado: no se paga antes de su fecha. */
        if ($cheque['fecha_cobro'] !== null && $cheque['fecha_cobro'] > date('Y-m-d')) {
            return 'posdatado';
        }

        /* Caducado: seis meses desde la emisión, según el Código de
           Comercio. Se cuenta desde la emisión declarada, no desde la
           impresión. */
        $dias = (int) $this->parametro('dias_caducidad', '180');

        if ($cheque['fecha_emision'] !== null
            && strtotime((string) $cheque['fecha_emision'] . ' +' . $dias . ' days') < time()) {
            return 'caducado';
        }

        if ($montoCent <= 0) {
            return 'mal_llenado';
        }

        // --- Los fondos, al final ---------------------------------
        $c = BaseDatos::unaFila(
            'SELECT c.saldo_cent, c.saldo_retenido_cent, p.saldo_minimo_cent
               FROM cuentas c JOIN productos p ON p.id = c.producto_id
              WHERE c.id = ?',
            [(int) $cheque['cuenta_id']]
        );

        $disponible = (int) $c['saldo_cent']
                    - (int) $c['saldo_retenido_cent']
                    - (int) $c['saldo_minimo_cent'];

        if ($disponible <= 0) {
            return 'sin_fondos';
        }

        if ($disponible < $montoCent) {
            return 'fondos_insuficientes';
        }

        return null;
    }

    public static function explicarRechazo(string $motivo): string
    {
        return match ($motivo) {
            'sin_fondos'             => 'la cuenta no tiene fondos.',
            'fondos_insuficientes'   => 'los fondos no alcanzan para el monto del cheque.',
            'firma_no_coincide'      => 'la firma no coincide con la registrada.',
            'cuenta_cerrada'         => 'la cuenta está cerrada.',
            'cuenta_bloqueada'       => 'la cuenta está bloqueada.',
            'suspendido_por_titular' => 'el titular dio orden de no pago.',
            'caducado'               => 'el cheque caducó: pasaron más de seis meses desde su emisión.',
            'posdatado'              => 'el cheque tiene fecha posterior y aún no se puede cobrar.',
            'mal_llenado'            => 'el cheque está mal llenado o anulado.',
            'alterado'               => 'el cheque ya se había presentado al cobro.',
            default                  => 'no se pudo pagar.',
        };
    }

    private function cobrarComisionRechazo(
        int $cuentaId,
        int $comision,
        string $serie,
        int $numero,
        ?int $actorId
    ): void {
        $general = (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
        );

        $bloqueadas = LibroMayor::bloquearCuentas([$cuentaId, $general]);
        $c = $bloqueadas[$cuentaId] ?? null;

        if ($c === null) {
            return;
        }

        /* Si no hay con qué cobrarla, NO se cobra ni se deja a deber:
           la cuenta no puede quedar en negativo, y un cargo que no cabe
           no es un cargo. El cheque ya quedó rechazado, que es lo que
           de verdad importa. */
        $disponible = (int) $c['saldo_cent'] - (int) $c['saldo_retenido_cent'];

        if ($disponible < $comision) {
            return;
        }

        $m = Monto::deCentavos($comision);

        LibroMayor::registrar(
            'comision',
            [
                ['cuenta_id' => $cuentaId, 'importe' => $m->negado(),
                 'descripcion' => 'Comisión por cheque rechazado ' . $serie . '-' . $numero],
                ['cuenta_id' => $general, 'importe' => $m,
                 'descripcion' => 'Comisión por cheque rechazado'],
            ],
            $actorId,
            'cheque-rech-' . $cuentaId . '-' . $serie . '-' . $numero,
            'Comisión por cheque rechazado'
        );
    }

    // =================================================================
    // Orden de no pago
    // =================================================================

    /**
     * El titular pide que NO se pague un cheque suyo.
     *
     * Existe porque los cheques se pierden y se roban. Es gratuito y
     * no pide segundo factor: frenar un pago nunca puede ser más
     * difícil que hacerlo.
     */
    public function suspender(int $cuentaId, int $numero, string $motivo, string $serie = 'A'): void
    {
        $actorId = Sesion::exigirAutenticado();

        Autorizacion::exigir('cheque.suspender');

        $cuenta = Autorizacion::exigirCuenta($cuentaId, $actorId, 'chequera.administrar');

        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 5) {
            throw new ChequeraInvalida('Diga por qué: se perdió, se lo robaron, se equivocó.');
        }

        $cheque = BaseDatos::unaFila(
            'SELECT id, estado FROM cheques WHERE cuenta_id = ? AND serie = ? AND numero = ?',
            [$cuentaId, $serie, $numero]
        );

        if ($cheque === null) {
            throw new ChequeraInvalida('No existe ese cheque en su chequera.');
        }

        if ($cheque['estado'] === 'pagado') {
            throw new ChequeraInvalida(
                'Ese cheque ya se pagó: no se puede suspender lo que ya salió. '
                . 'Si no lo reconoce, repórtelo como operación no autorizada.'
            );
        }

        if ($cheque['estado'] === 'suspendido') {
            return;
        }

        BaseDatos::ejecutar(
            'UPDATE cheques
                SET estado = "suspendido", suspendido_en = NOW(), motivo_suspension = ?
              WHERE id = ?',
            [mb_substr($motivo, 0, 255), (int) $cheque['id']]
        );

        Auditoria::registrar(
            'cheque.suspendido',
            Auditoria::EXITO,
            $actorId,
            'cheque',
            $serie . '-' . $numero,
            ['motivo' => $motivo, 'cuenta' => $cuenta['numero_cuenta']]
        );

        $this->notificaciones->crear(
            (int) $cuenta['usuario_id'],
            'cheque.suspendido',
            'Orden de no pago registrada',
            'El cheque ' . $serie . '-' . $numero . ' no se pagará si alguien lo presenta. '
            . 'Si lo recupera y quiere volver a usarlo, pida que se levante la orden.',
            ['cheque' => $serie . '-' . $numero],
            'exito'
        );
    }

    // =================================================================
    // Consulta
    // =================================================================

    /** @return array<int,array<string,mixed>> */
    public function chequerasDe(int $usuarioId): array
    {
        $filas = BaseDatos::todas(
            'SELECT ch.*, c.numero_cuenta
               FROM chequeras ch
               JOIN cuentas c ON c.id = ch.cuenta_id
              WHERE ch.usuario_id = ?
              ORDER BY ch.solicitada_en DESC',
            [$usuarioId]
        );

        foreach ($filas as &$f) {
            $f['resumen'] = BaseDatos::unaFila(
                'SELECT COUNT(*) AS total,
                        SUM(estado = "en_blanco")  AS en_blanco,
                        SUM(estado = "pagado")     AS pagados,
                        SUM(estado = "rechazado")  AS rechazados,
                        SUM(estado = "suspendido") AS suspendidos
                   FROM cheques WHERE chequera_id = ?',
                [(int) $f['id']]
            );
        }

        return $filas;
    }

    /** @return array<int,array<string,mixed>> */
    public function chequesDe(int $chequeraId): array
    {
        return BaseDatos::todas(
            'SELECT * FROM cheques WHERE chequera_id = ? ORDER BY numero',
            [$chequeraId]
        );
    }

    /** @return array<string,mixed> */
    private function exigirChequera(string $folio): array
    {
        $ch = BaseDatos::unaFila('SELECT * FROM chequeras WHERE folio = ?', [$folio]);

        if ($ch === null) {
            throw new ChequeraInvalida('No existe la chequera ' . $folio . '.');
        }

        return $ch;
    }
}
