<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Plazo\CalculoPlazo;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Depósitos a plazo fijo.
 *
 * CADA DEPÓSITO ES UNA CUENTA
 *
 * No una tabla con un saldo propio: una fila en `cuentas`, con un
 * producto de tipo `plazo`. Así el capital queda vigilado por la misma
 * maquinaria de cuadre que todo lo demás —los asientos, la
 * conciliación, las comprobaciones del verificador— en lugar de vivir
 * en un rincón que nadie comprueba.
 *
 * Y constituir un plazo fijo es, contablemente, una transferencia:
 * sale de la cuenta del cliente y entra a la del depósito.
 *
 * LO QUE EL CLIENTE VE ANTES DE DECIDIR
 *
 * No una tasa: lo que va a recibir. Cuánto en intereses, cuánto se
 * retiene de renta y cuánto le queda. Una tasa anual en puntos base no
 * le dice nada a nadie sobre si le conviene.
 */
final class ServicioPlazoFijo
{
    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    // =================================================================
    // Simulación
    // =================================================================

    /**
     * Lo que daría un depósito, sin constituir nada.
     *
     * @return array<string,mixed>
     */
    public function simular(int $montoCent, int $dias): array
    {
        $tasa = CalculoPlazo::tasaPara($dias, $montoCent);

        if ($tasa === null) {
            $minimo = (int) (BaseDatos::unValor(
                'SELECT MIN(monto_minimo_cent) FROM plazo_tasas WHERE activa = 1'
            ) ?? 0);

            throw new PlazoInvalido(sprintf(
                'No hay tasa para %d día(s) con %s. Los plazos van de %d a %d días, '
                . 'desde %s.',
                $dias,
                Monto::deCentavos($montoCent)->formateado(),
                (int) (BaseDatos::unValor('SELECT MIN(dias_min) FROM plazo_tasas WHERE activa = 1') ?? 0),
                (int) (BaseDatos::unValor('SELECT MAX(dias_max) FROM plazo_tasas WHERE activa = 1') ?? 0),
                Monto::deCentavos($minimo)->formateado()
            ));
        }

        $p = CalculoPlazo::proyeccion($montoCent, $tasa['tasa_bp'], $dias);

        return $p + [
            'fecha_vence' => date('Y-m-d', strtotime('+' . $dias . ' days')),
            'monto_minimo_cent' => $tasa['monto_minimo_cent'],
        ];
    }

    // =================================================================
    // Constitución
    // =================================================================

    /**
     * @param array{
     *   cuenta_origen_id:int, monto:mixed, dias:int,
     *   renovacion?:string, cuenta_abono_id?:int, usuario_id?:int
     * } $datos
     * @return array<string,mixed>
     */
    public function constituir(array $datos): array
    {
        $actorId = Sesion::exigirAutenticado();

        $paraId = (int) ($datos['usuario_id'] ?? $actorId);

        if ($paraId !== $actorId) {
            Autorizacion::exigir('plazo.cancelar_terceros');
        } else {
            Autorizacion::exigir('plazo.abrir_propio');
        }

        $monto = Monto::deEntradaUsuario($datos['monto']);
        $dias  = (int) $datos['dias'];

        $tasa = CalculoPlazo::tasaPara($dias, $monto->centavos);

        if ($tasa === null) {
            throw new PlazoInvalido(
                'No hay tasa disponible para ese plazo y ese monto.'
            );
        }

        /* La lectura va ANTES de comprobarla: si no, el caso en que la
           clave no viene lee una clave que no existe. */
        $renovacion = (string) ($datos['renovacion'] ?? 'no');

        if (!in_array($renovacion, ['no', 'capital', 'capital_intereses'], true)) {
            $renovacion = 'no';
        }

        // --- La cuenta de donde sale -------------------------------
        $origenId = (int) $datos['cuenta_origen_id'];

        $origen = Autorizacion::exigirCuenta($origenId, $actorId, 'plazo.cancelar_terceros');
        Autorizacion::exigirCuentaOperable($origen);

        $abonoId = (int) ($datos['cuenta_abono_id'] ?? 0) ?: $origenId;

        if ($abonoId !== $origenId) {
            $abono = Autorizacion::exigirCuenta($abonoId, $actorId, 'plazo.cancelar_terceros');
            Autorizacion::exigirCuentaOperable($abono);
        }

        $proyeccion = CalculoPlazo::proyeccion($monto->centavos, $tasa['tasa_bp'], $dias);

        $folio = Folio::unico('DPF', 'depositos_plazo', 'folio', 'ymd', 5);

        $resultado = BaseDatos::enTransaccion(function () use (
            $paraId, $origenId, $abonoId, $monto, $dias, $tasa, $renovacion,
            $folio, $actorId
        ): array {
            /* La cuenta del depósito. Se crea aquí dentro: si algo
               falla después, no queda una cuenta huérfana. */
            $productoId = (int) BaseDatos::unValor(
                'SELECT id FROM productos WHERE codigo = "DPF" AND activo = 1'
            );

            if ($productoId === 0) {
                throw new \RuntimeException('Falta el producto de depósito a plazo.');
            }

            $numero = Folio::unico('', 'cuentas', 'numero_cuenta', 'ymd', 5);
            $numero = '9' . substr(preg_replace('/\D/', '', $numero) ?: '', 0, 9);

            /* Que el número no choque con ninguno existente. */
            while ((int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM cuentas WHERE numero_cuenta = ?', [$numero]
            ) > 0) {
                $numero = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
            }

            BaseDatos::ejecutar(
                'INSERT INTO cuentas (uuid, numero_cuenta, usuario_id, producto_id,
                                      moneda, saldo_cent, estado, es_tecnica)
                 VALUES (?, ?, ?, ?, "USD", 0, "activa", 0)',
                [LibroMayor::uuid4(), $numero, $paraId, $productoId]
            );

            $cuentaPlazoId = BaseDatos::ultimoId();

            // --- El dinero: del cliente al depósito -----------------
            $bloqueadas = LibroMayor::bloquearCuentas([$origenId, $cuentaPlazoId]);
            $o = $bloqueadas[$origenId] ?? null;

            if ($o === null) {
                throw new PlazoInvalido('No se pudo bloquear la cuenta de origen.');
            }

            $disponible = (int) $o['saldo_cent']
                        - (int) $o['saldo_retenido_cent']
                        - (int) $o['saldo_minimo_cent'];

            if ($disponible < $monto->centavos) {
                throw new FondosInsuficientes(
                    'Fondos insuficientes. Disponible: '
                    . Monto::deCentavos(max(0, $disponible))->formateado() . '.'
                );
            }

            $asiento = LibroMayor::registrar(
                'plazo_fijo',
                [
                    ['cuenta_id' => $origenId, 'importe' => $monto->negado(),
                     'descripcion' => 'Constitución de depósito a plazo ' . $folio],
                    ['cuenta_id' => $cuentaPlazoId, 'importe' => $monto,
                     'descripcion' => 'Depósito a plazo ' . $folio . ' · '
                                    . number_format($tasa['tasa_bp'] / 100, 2) . '% a '
                                    . $dias . ' días'],
                ],
                $actorId,
                'dpf-' . $folio,
                'Depósito a plazo fijo ' . $folio
            );

            $inicio = date('Y-m-d');
            $vence  = date('Y-m-d', strtotime('+' . $dias . ' days'));

            BaseDatos::ejecutar(
                'INSERT INTO depositos_plazo
                   (uuid, folio, usuario_id, cuenta_id, cuenta_origen_id, cuenta_abono_id,
                    monto_cent, tasa_bp, plazo_dias, fecha_inicio, fecha_vence,
                    renovacion, estado, asiento_apertura_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "vigente", ?)',
                [
                    LibroMayor::uuid4(), $folio, $paraId, $cuentaPlazoId,
                    $origenId, $abonoId, $monto->centavos, $tasa['tasa_bp'], $dias,
                    $inicio, $vence, $renovacion, (int) $asiento['asiento_id'],
                ]
            );

            return [
                'folio'        => $folio,
                'cuenta_id'    => $cuentaPlazoId,
                'numero'       => $numero,
                'fecha_inicio' => $inicio,
                'fecha_vence'  => $vence,
            ];
        });

        Auditoria::registrar(
            'plazo.constituido',
            Auditoria::EXITO,
            $actorId,
            'deposito_plazo',
            $folio,
            [
                'monto' => $monto->aDecimal(),
                'dias'  => $dias,
                'tasa'  => $tasa['tasa_bp'],
                'para'  => $paraId,
            ]
        );

        $this->notificaciones->crear(
            $paraId,
            'plazo.constituido',
            'Depósito a plazo constituido',
            'Constituyó ' . $monto->formateado() . ' a ' . $dias . ' días al '
            . number_format($tasa['tasa_bp'] / 100, 2) . '% anual. '
            . 'Vence el ' . date('d/m/Y', (int) strtotime((string) $resultado['fecha_vence']))
            . ' y recibirá ' . Monto::deCentavos($proyeccion['total_cent'])->formateado() . '.',
            ['folio' => $folio],
            'exito'
        );

        return $resultado + ['proyeccion' => $proyeccion, 'tasa_bp' => $tasa['tasa_bp']];
    }

    // =================================================================
    // Liquidación
    // =================================================================

    /**
     * Paga el depósito: capital más intereses, menos retención.
     *
     * @return array<string,mixed>
     */
    public function liquidar(string $folio, ?int $actorId = null, bool $anticipada = false): array
    {
        $d = $this->exigirDeposito($folio);

        if (!in_array($d['estado'], ['vigente', 'vencido'], true)) {
            throw new PlazoInvalido('Ese depósito ya está ' . $d['estado'] . '.');
        }

        $capital = (int) $d['monto_cent'];
        $tasa    = (int) $d['tasa_bp'];

        if ($anticipada) {
            $c = CalculoPlazo::cancelacionAnticipada(
                $capital, $tasa, (string) $d['fecha_inicio']
            );

            $interesBruto = (int) $c['interes_pagado_cent'];
            $penalidad    = (int) $c['penalidad_cent'];
            $retencion    = (int) $c['retencion_cent'];
        } else {
            /* Al vencimiento se pagan los días PACTADOS, no los
               transcurridos. Si el barrido corre con un día de retraso,
               el cliente no gana ni pierde por eso. */
            $p = CalculoPlazo::proyeccion($capital, $tasa, (int) $d['plazo_dias']);

            $interesBruto = (int) $p['interes_cent'];
            $penalidad    = 0;
            $retencion    = (int) $p['retencion_cent'];
        }

        $neto  = $interesBruto - $retencion;
        $total = $capital + $neto;

        return BaseDatos::enTransaccion(function () use (
            $d, $capital, $interesBruto, $penalidad, $retencion, $neto, $total,
            $folio, $actorId, $anticipada
        ): array {
            $cuentaPlazo = (int) $d['cuenta_id'];
            $cuentaAbono = (int) $d['cuenta_abono_id'];

            $general = (int) BaseDatos::unValor(
                'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
            );

            LibroMayor::bloquearCuentas([$cuentaPlazo, $cuentaAbono, $general]);

            /* Las patas:
             *   · sale el capital del depósito
             *   · salen los intereses netos del banco
             *   · entra todo a la cuenta del cliente
             *   · la retención se queda en el banco (se le debe a
             *     Hacienda, y el banco la entera)
             *
             * La penalidad no es una pata: son intereses que
             * simplemente no se devengan. No se le quita nada al
             * cliente, se le paga menos de lo que habría ganado. */
            $patas = [
                ['cuenta_id' => $cuentaPlazo, 'importe' => Monto::deCentavos(-$capital),
                 'descripcion' => 'Liquidación del depósito a plazo ' . $folio],
                ['cuenta_id' => $cuentaAbono, 'importe' => Monto::deCentavos($total),
                 'descripcion' => 'Depósito a plazo ' . $folio . ' liquidado'
                                . ($anticipada ? ' (cancelación anticipada)' : '')],
            ];

            if ($neto > 0) {
                $patas[] = ['cuenta_id' => $general, 'importe' => Monto::deCentavos(-$neto),
                            'descripcion' => 'Intereses del depósito ' . $folio];
            }

            $asiento = LibroMayor::registrar(
                'plazo_fijo',
                $patas,
                $actorId,
                'dpf-liq-' . $folio,
                'Liquidación de depósito a plazo ' . $folio
            );

            BaseDatos::ejecutar(
                'UPDATE depositos_plazo
                    SET estado = ?, intereses_cent = ?, retencion_cent = ?,
                        penalidad_cent = ?, liquidado_en = NOW(),
                        asiento_liquidacion_id = ?
                  WHERE id = ?',
                [
                    $anticipada ? 'cancelado' : 'liquidado',
                    $interesBruto, $retencion, $penalidad,
                    (int) $asiento['asiento_id'], (int) $d['id'],
                ]
            );

            /* La cuenta del depósito se cierra: ya no tiene razón de
               existir y dejarla activa en cero la deja saliendo en los
               desplegables del cliente para siempre. */
            BaseDatos::ejecutar(
                'UPDATE cuentas
                    SET estado = "cerrada", cerrada_en = NOW(), cierre_motivo = ?
                  WHERE id = ?',
                [
                    $anticipada
                        ? 'Depósito a plazo ' . $folio . ' cancelado anticipadamente'
                        : 'Depósito a plazo ' . $folio . ' liquidado al vencimiento',
                    $cuentaPlazo,
                ]
            );

            Auditoria::registrar(
                $anticipada ? 'plazo.cancelado' : 'plazo.liquidado',
                Auditoria::EXITO,
                $actorId,
                'deposito_plazo',
                $folio,
                [
                    'capital'   => Monto::deCentavos($capital)->aDecimal(),
                    'intereses' => Monto::deCentavos($interesBruto)->aDecimal(),
                    'retencion' => Monto::deCentavos($retencion)->aDecimal(),
                    'penalidad' => Monto::deCentavos($penalidad)->aDecimal(),
                ]
            );

            $this->notificaciones->crear(
                (int) $d['usuario_id'],
                'plazo.liquidado',
                $anticipada ? 'Depósito cancelado anticipadamente' : 'Su depósito a plazo venció',
                'Se abonaron ' . Monto::deCentavos($total)->formateado() . ' a su cuenta: '
                . Monto::deCentavos($capital)->formateado() . ' de capital y '
                . Monto::deCentavos($neto)->formateado() . ' de intereses'
                . ($retencion > 0
                    ? ', ya descontada la retención de renta de '
                      . Monto::deCentavos($retencion)->formateado()
                    : ' (exentos de retención)')
                . ($penalidad > 0
                    ? '. Por cancelar antes del plazo se dejaron de devengar '
                      . Monto::deCentavos($penalidad)->formateado() . '.'
                    : '.'),
                ['folio' => $folio],
                $anticipada ? 'alerta' : 'exito'
            );

            return [
                'folio'          => $folio,
                'estado'         => $anticipada ? 'cancelado' : 'liquidado',
                'capital_cent'   => $capital,
                'interes_cent'   => $interesBruto,
                'retencion_cent' => $retencion,
                'penalidad_cent' => $penalidad,
                'total_cent'     => $total,
                'referencia'     => $asiento['referencia'],
            ];
        });
    }

    /**
     * Renueva un depósito vencido, según lo que pidió el cliente.
     *
     * @return array<string,mixed>
     */
    public function renovar(string $folio, ?int $actorId = null): array
    {
        $d = $this->exigirDeposito($folio);

        if ($d['renovacion'] === 'no') {
            throw new PlazoInvalido('Ese depósito no tiene renovación automática.');
        }

        /* Primero se liquida —el cliente recibe su dinero y su
           retención queda enterada— y después se constituye uno nuevo.
           Encadenarlo sin pasar por la cuenta escondería los intereses
           y la retención, que es justo lo que Hacienda quiere ver. */
        $liq = $this->liquidar($folio, $actorId, false);

        $capital = (int) $d['monto_cent'];

        $nuevoCapital = $d['renovacion'] === 'capital_intereses'
            ? (int) $liq['total_cent']
            : $capital;

        $_SESSION['usuario_id'] ??= (int) $d['usuario_id'];

        $nuevo = $this->constituir([
            'usuario_id'       => (int) $d['usuario_id'],
            'cuenta_origen_id' => (int) $d['cuenta_abono_id'],
            'cuenta_abono_id'  => (int) $d['cuenta_abono_id'],
            'monto'            => number_format($nuevoCapital / 100, 2, '.', ''),
            'dias'             => (int) $d['plazo_dias'],
            'renovacion'       => (string) $d['renovacion'],
        ]);

        BaseDatos::ejecutar(
            'UPDATE depositos_plazo SET estado = "renovado" WHERE id = ?',
            [(int) $d['id']]
        );

        BaseDatos::ejecutar(
            'UPDATE depositos_plazo
                SET renovado_de = ?, veces_renovado = ?
              WHERE folio = ?',
            [(int) $d['id'], (int) $d['veces_renovado'] + 1, $nuevo['folio']]
        );

        return ['anterior' => $folio, 'nuevo' => $nuevo['folio'], 'liquidacion' => $liq];
    }

    // =================================================================
    // Vencimientos
    // =================================================================

    /**
     * Los que vencen hoy o antes y siguen vigentes.
     *
     * @return array<int,array<string,mixed>>
     */
    public function vencidos(): array
    {
        return BaseDatos::todas(
            'SELECT * FROM depositos_plazo
              WHERE estado = "vigente" AND fecha_vence <= CURDATE()
              ORDER BY fecha_vence, id'
        );
    }

    /**
     * Avisa a quien le vence pronto.
     *
     * Antes, no después: quien tiene que decidir si renueva necesita
     * saberlo con tiempo. Avisarle el día que venció es informarle de
     * algo que ya no puede cambiar.
     */
    public function porVencer(int $dias = 7): array
    {
        return BaseDatos::todas(
            'SELECT d.*, CONCAT(u.nombres, " ", u.apellidos) AS cliente
               FROM depositos_plazo d
               JOIN usuarios u ON u.id = d.usuario_id
              WHERE d.estado = "vigente"
                AND d.fecha_vence BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
              ORDER BY d.fecha_vence',
            [$dias]
        );
    }

    // =================================================================
    // Consulta
    // =================================================================

    /** @return array<int,array<string,mixed>> */
    public function deUsuario(int $usuarioId): array
    {
        $filas = BaseDatos::todas(
            'SELECT d.*, c.numero_cuenta, ca.numero_cuenta AS cuenta_abono
               FROM depositos_plazo d
               JOIN cuentas c  ON c.id  = d.cuenta_id
               JOIN cuentas ca ON ca.id = d.cuenta_abono_id
              WHERE d.usuario_id = ?
              ORDER BY FIELD(d.estado, "vigente", "vencido", "liquidado", "cancelado", "renovado"),
                       d.fecha_vence DESC',
            [$usuarioId]
        );

        foreach ($filas as &$f) {
            if ($f['estado'] === 'vigente') {
                $f['proyeccion'] = CalculoPlazo::proyeccion(
                    (int) $f['monto_cent'],
                    (int) $f['tasa_bp'],
                    (int) $f['plazo_dias']
                );

                $f['dias_restantes'] = max(0, CalculoPlazo::dias(
                    date('Y-m-d'),
                    (string) $f['fecha_vence']
                ));

                $f['cancelacion'] = CalculoPlazo::cancelacionAnticipada(
                    (int) $f['monto_cent'],
                    (int) $f['tasa_bp'],
                    (string) $f['fecha_inicio']
                );
            }
        }

        return $filas;
    }

    /** @return array<string,mixed> */
    private function exigirDeposito(string $folio): array
    {
        $d = BaseDatos::unaFila('SELECT * FROM depositos_plazo WHERE folio = ?', [$folio]);

        if ($d === null) {
            throw new PlazoInvalido('No existe el depósito ' . $folio . '.');
        }

        return $d;
    }
}
