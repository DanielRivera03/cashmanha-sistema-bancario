<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\CapacidadPago;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

/**
 * Extrafinanciamiento: efectivo contra la línea de la tarjeta.
 *
 * El cliente recibe el dinero en su cuenta de ahorro y lo devuelve en
 * cuotas fijas que aparecen en el estado de cuenta de la tarjeta, mes a
 * mes, hasta liquidarlo. Mientras tanto ocupa su línea: es crédito de
 * esa tarjeta, no una línea aparte.
 *
 * Tres cosas que lo gobiernan, y por qué:
 *
 *   NO ES PARA TODOS   Se preaprueba con el historial de la propia
 *                      tarjeta: cortes emitidos, sin mora, y uso de la
 *                      línea por debajo del tope. A quien ya tiene la
 *                      línea casi consumida, prestarle efectivo encima
 *                      es empujarlo contra su propio techo.
 *
 *   OCUPA LA LÍNEA     El monto entregado entra en `saldo_usado_cent`.
 *                      Si no lo hiciera, el cliente tendría el efectivo
 *                      Y el cupo entero disponible: la exposición real
 *                      pasaría del límite aprobado sin que nada lo
 *                      impidiera.
 *
 *   SE COBRA AL CORTE  La cuota se carga como un movimiento más de la
 *                      tarjeta en cada corte, con su capital y su
 *                      interés separados. Así el estado de cuenta puede
 *                      desglosarlo y el cliente sigue la cuenta.
 */
final class ServicioExtrafinanciamiento
{
    // =================================================================
    // PREAPROBACIÓN
    // =================================================================

    /**
     * ¿Califica esta tarjeta, y por cuánto?
     *
     * Devuelve null cuando no califica, con el motivo dentro del
     * resultado para poder explicarlo en pantalla en lugar de dejar un
     * silencio.
     *
     * @return array<string,mixed>
     */
    public function evaluar(int $tarjetaId): array
    {
        $t = BaseDatos::unaFila(
            'SELECT t.*, u.id AS uid,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente
               FROM tarjetas t JOIN usuarios u ON u.id = t.usuario_id
              WHERE t.id = ?',
            [$tarjetaId]
        );

        $no = static fn(string $motivo): array => ['califica' => false, 'motivo' => $motivo];

        if ($t === null || $t['tipo'] !== 'credito' || $t['estado'] !== 'activa') {
            return $no('Solo aplica a tarjetas de crédito activas.');
        }

        if ($t['congelada_hasta'] !== null
            && strtotime((string) $t['congelada_hasta']) > time()) {
            return $no('La tarjeta está congelada.');
        }

        // --- Historial propio de la tarjeta -----------------------
        $minCortes = max(1, Parametros::entero('extra.min_cortes', 3));

        $cortes = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM tarjeta_cortes WHERE tarjeta_id = ?', [$tarjetaId]
        );

        if ($cortes < $minCortes) {
            return $no(
                'Necesita ' . $minCortes . ' estados de cuenta emitidos y lleva '
                . $cortes . '. Es el historial con el que se evalúa.'
            );
        }

        /* Sin mora. Se mira corte por corte si el minimo se cubrio
           antes de la fecha limite: el saldo de hoy no dice si pago
           tarde, y pagar tarde es justo lo que descalifica. */
        $atrasos = (int) BaseDatos::unValor(
            'SELECT COUNT(*)
               FROM tarjeta_cortes k
              WHERE k.tarjeta_id = ?
                AND k.pago_minimo_cent > 0
                AND k.fecha_limite_pago < CURDATE()
                AND COALESCE((
                      SELECT SUM(m.importe_cent) FROM tarjeta_movimientos m
                       WHERE m.tarjeta_id = k.tarjeta_id
                         AND m.tipo IN ("pago","abono")
                         AND DATE(m.creado_en) > k.fecha_corte
                         AND DATE(m.creado_en) <= k.fecha_limite_pago
                    ), 0) < k.pago_minimo_cent',
            [$tarjetaId]
        );

        if ($atrasos > 0) {
            return $no(
                'Registra ' . $atrasos . ' corte(s) con el pago mínimo fuera de fecha.'
            );
        }

        // --- Margen en la línea -----------------------------------
        $limite = (int) $t['limite_cent'];
        $usado  = (int) $t['saldo_usado_cent'];
        $maxUso = max(1, Parametros::entero('extra.max_uso_bp', 7000));

        if ($limite <= 0) {
            return $no('La tarjeta no tiene línea asignada.');
        }

        $usoBp = (int) round($usado * 10000 / $limite);

        if ($usoBp > $maxUso) {
            return $no(
                'Su línea está al ' . round($usoBp / 100) . '% de uso y el máximo para '
                . 'optar es ' . round($maxUso / 100) . '%. Abonando a su saldo vuelve a '
                . 'calificar.'
            );
        }

        // --- Uno a la vez ------------------------------------------
        $vivos = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM tarjeta_extrafinanciamientos
              WHERE tarjeta_id = ? AND estado = "vigente"',
            [$tarjetaId]
        );

        if ($vivos >= max(1, Parametros::entero('extra.max_vigentes', 1))) {
            return $no('Ya tiene un extrafinanciamiento vigente en esta tarjeta.');
        }

        // --- Cuánto cabe -------------------------------------------
        $plazo = max(3, Parametros::entero('extra.plazo_meses', 12));
        $tasa  = max(0, Parametros::entero('extra.tasa_bp', 2400));
        $pct   = max(1, Parametros::entero('extra.pct_linea_bp', 5000));

        // Por la linea: una porcion de lo aprobado, y nunca mas de lo
        // que queda libre hoy.
        $porLinea = min(
            (int) floor($limite * $pct / 10000),
            max(0, $limite - $usado)
        );

        // Por la capacidad: la cuota nueva tiene que caber en lo que de
        // verdad le sobra cada mes.
        $ingreso = Monto::deCentavos((int) BaseDatos::unValor(
            'SELECT COALESCE(ingreso_mensual_cent,0) FROM detalle_usuarios WHERE usuario_id = ?',
            [(int) $t['uid']]
        ));

        if (!$ingreso->esPositivo()) {
            return $no('No tenemos su ingreso declarado para evaluar la capacidad de pago.');
        }

        $cuotaMax = CapacidadPago::cuotaMaxima($ingreso, $this->compromisos((int) $t['uid']));

        if (!$cuotaMax->esPositivo()) {
            return $no('Sus compromisos actuales no dejan margen para una cuota más.');
        }

        $porCapacidad = $this->montoDesdeCuota($cuotaMax->centavos, $tasa, $plazo);

        // A centenas de dolar: una oferta se publica redonda.
        $ofrecido = intdiv(min($porLinea, $porCapacidad), 10000) * 10000;
        $minimo   = max(1000, Parametros::entero('extra.monto_minimo_cent', 20000));

        if ($ofrecido < $minimo) {
            return $no(
                'Lo que su línea y su capacidad admiten hoy queda por debajo del mínimo '
                . 'que ofrecemos (' . Monto::deCentavos($minimo)->formateado() . ').'
            );
        }

        $cuota = $this->cuotaDe($ofrecido, $tasa, $plazo);

        return [
            'califica'      => true,
            'tarjeta_id'    => $tarjetaId,
            'usuario_id'    => (int) $t['uid'],
            'monto_cent'    => $ofrecido,
            'plazo_meses'   => $plazo,
            'tasa_bp'       => $tasa,
            'cuota_cent'    => $cuota,
            'total_cent'    => $cuota * $plazo,
            'interes_cent'  => $cuota * $plazo - $ofrecido,
            'cortes'        => $cortes,
            'uso_bp'        => $usoBp,
            'capacidad_cent'=> $cuotaMax->centavos,
            'motivo'        => 'Historial de la tarjeta: ' . $cortes . ' cortes al día, '
                             . 'uso de la línea ' . round($usoBp / 100) . '%.',
        ];
    }

    // =================================================================
    // OTORGAMIENTO
    // =================================================================

    /**
     * Otorga el extrafinanciamiento y lo desembolsa en la cuenta.
     *
     * @param array<string,mixed> $condiciones Lo devuelto por evaluar()
     * @return array<string,mixed>
     */
    public function otorgar(array $condiciones, int $cuentaId, int $actorId, ?int $ofertaId = null): array
    {
        $tarjetaId = (int) $condiciones['tarjeta_id'];
        $usuarioId = (int) $condiciones['usuario_id'];
        $monto     = Monto::deCentavos((int) $condiciones['monto_cent']);
        $plazo     = (int) $condiciones['plazo_meses'];
        $tasa      = (int) $condiciones['tasa_bp'];
        $cuota     = (int) $condiciones['cuota_cent'];

        return BaseDatos::enTransaccion(function () use (
            $tarjetaId, $usuarioId, $monto, $plazo, $tasa, $cuota, $cuentaId, $actorId, $ofertaId
        ) {
            $t = BaseDatos::unaFila(
                'SELECT * FROM tarjetas WHERE id = ? FOR UPDATE', [$tarjetaId]
            );

            if ($t === null || $t['estado'] !== 'activa') {
                throw new \RuntimeException('La tarjeta no está activa.');
            }

            // El cupo, otra vez y ahora: entre la oferta y la aceptacion
            // el cliente pudo gastar.
            $libre = max(0, (int) $t['limite_cent'] - (int) $t['saldo_usado_cent']);

            if ($monto->centavos > $libre) {
                throw new \RuntimeException(
                    'Su cupo disponible es de ' . Monto::deCentavos($libre)->formateado()
                    . ' y el extrafinanciamiento es de ' . $monto->formateado()
                    . '. Abone a su tarjeta y vuelva a intentarlo.'
                );
            }

            $cuenta = BaseDatos::unaFila(
                'SELECT id, numero_cuenta FROM cuentas
                  WHERE id = ? AND usuario_id = ? AND es_tecnica = 0 AND estado = "activa"',
                [$cuentaId, $usuarioId]
            );

            if ($cuenta === null) {
                throw new \RuntimeException(
                    'La cuenta de desembolso debe ser suya y estar activa.'
                );
            }

            $folio = Folio::unico('EXF', 'tarjeta_extrafinanciamientos');
            $total = $cuota * $plazo;

            BaseDatos::ejecutar(
                'INSERT INTO tarjeta_extrafinanciamientos
                   (uuid, folio, tarjeta_id, usuario_id, cuenta_id, monto_cent,
                    plazo_meses, tasa_bp, cuota_cent, interes_total_cent, total_cent,
                    oferta_id)
                 VALUES (UUID(),?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $folio, $tarjetaId, $usuarioId, $cuentaId, $monto->centavos,
                    $plazo, $tasa, $cuota, $total - $monto->centavos, $total, $ofertaId,
                ]
            );

            $extraId = BaseDatos::ultimoId();

            // --- El calendario -----------------------------------
            $this->generarCuotas($extraId, $monto->centavos, $tasa, $plazo, $cuota,
                (int) $t['dia_corte']);

            // --- El dinero, a la cuenta --------------------------
            $tecnica = (int) BaseDatos::unValor(
                'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
            );

            LibroMayor::bloquearCuentas([$cuentaId, $tecnica]);

            $asiento = LibroMayor::registrar(
                'deposito',
                [
                    ['cuenta_id' => $cuentaId, 'importe' => $monto,
                     'descripcion' => 'Extrafinanciamiento ' . $folio
                                    . ' tarjeta ••••' . $t['ultimos4']],
                    ['cuenta_id' => $tecnica, 'importe' => $monto->negado(),
                     'descripcion' => 'Contrapartida de extrafinanciamiento'],
                ],
                $actorId,
                'extra-' . $extraId,
                'Extrafinanciamiento ' . $folio
            );

            BaseDatos::ejecutar(
                'UPDATE tarjeta_extrafinanciamientos SET asiento_id = ? WHERE id = ?',
                [(int) ($asiento['id'] ?? 0) ?: null, $extraId]
            );

            /* Ocupa la linea. Sin esto el cliente tendria el efectivo Y
               el cupo entero disponible, y la exposicion real pasaria
               del limite aprobado sin que nada lo impidiera. */
            BaseDatos::ejecutar(
                'UPDATE tarjetas SET saldo_usado_cent = saldo_usado_cent + ? WHERE id = ?',
                [$monto->centavos, $tarjetaId]
            );

            BaseDatos::ejecutar(
                'INSERT INTO tarjeta_movimientos
                   (tarjeta_id, tipo, importe_cent, comercio, categoria, referencia, estado, diferible)
                 VALUES (?, "consumo", ?, ?, "extrafinanciamiento", ?, "liquidada", 0)',
                [
                    $tarjetaId, $monto->centavos,
                    'Extrafinanciamiento ' . $folio,
                    $asiento['referencia'],
                ]
            );

            Auditoria::registrar('extra.otorgado', Auditoria::EXITO, $actorId,
                'extrafinanciamiento', (string) $extraId,
                ['folio' => $folio, 'monto' => $monto->aDecimal(),
                 'cuenta' => $cuenta['numero_cuenta'], 'plazo' => $plazo]);

            return [
                'extra_id'   => $extraId,
                'folio'      => $folio,
                'monto'      => $monto->jsonSerialize(),
                'cuota_cent' => $cuota,
                'plazo'      => $plazo,
                'cuenta'     => (string) $cuenta['numero_cuenta'],
                'referencia' => $asiento['referencia'],
            ];
        });
    }

    // =================================================================
    // COBRO EN EL CORTE
    // =================================================================

    /**
     * Carga a la tarjeta las cuotas vencidas hasta la fecha del corte.
     *
     * Se llama desde el corte. Devuelve lo cargado y el saldo que queda
     * pendiente, que es lo que el estado de cuenta desglosa.
     *
     * @return array{cuota_cent:int, saldo_cent:int}
     */
    public function cobrarEnCorte(int $tarjetaId, string $corte): array
    {
        $cargado = 0;

        $pendientes = BaseDatos::todas(
            'SELECT q.id, q.extra_id, q.numero, q.total_cent, q.capital_cent, q.interes_cent,
                    e.folio
               FROM tarjeta_extra_cuotas q
               JOIN tarjeta_extrafinanciamientos e ON e.id = q.extra_id
              WHERE e.tarjeta_id = ? AND e.estado = "vigente"
                AND q.cobrada_en IS NULL AND q.fecha_corte <= ?
              ORDER BY q.extra_id, q.numero',
            [$tarjetaId, $corte]
        );

        foreach ($pendientes as $q) {
            /* La cuota es un movimiento mas de la tarjeta: asi aparece
               en el detalle del estado de cuenta con su nombre, y no
               como un ajuste que nadie sabe de donde sale. */
            BaseDatos::ejecutar(
                'INSERT INTO tarjeta_movimientos
                   (tarjeta_id, tipo, importe_cent, comercio, categoria, referencia, estado, diferible)
                 VALUES (?, "consumo", ?, ?, "extrafinanciamiento", ?, "liquidada", 0)',
                [
                    $tarjetaId, (int) $q['total_cent'],
                    'Cuota ' . (int) $q['numero'] . ' extrafinanciamiento ' . $q['folio'],
                    $q['folio'] . '-' . str_pad((string) (int) $q['numero'], 2, '0', STR_PAD_LEFT),
                ]
            );

            BaseDatos::ejecutar(
                'UPDATE tarjeta_extra_cuotas SET cobrada_en = NOW() WHERE id = ?',
                [(int) $q['id']]
            );

            BaseDatos::ejecutar(
                'UPDATE tarjeta_extrafinanciamientos
                    SET pagado_cent = pagado_cent + ?, cuotas_pagadas = cuotas_pagadas + 1
                  WHERE id = ?',
                [(int) $q['total_cent'], (int) $q['extra_id']]
            );

            $cargado += (int) $q['total_cent'];

            // Si ya no quedan cuotas, el extrafinanciamiento esta
            // liquidado: dejarlo vigente lo haria seguir ocupando sitio
            // y bloqueando uno nuevo.
            $faltan = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM tarjeta_extra_cuotas
                  WHERE extra_id = ? AND cobrada_en IS NULL',
                [(int) $q['extra_id']]
            );

            if ($faltan === 0) {
                BaseDatos::ejecutar(
                    'UPDATE tarjeta_extrafinanciamientos
                        SET estado = "liquidado", liquidado_en = NOW()
                      WHERE id = ?',
                    [(int) $q['extra_id']]
                );
            }
        }

        return [
            'cuota_cent' => $cargado,
            'saldo_cent' => $this->saldoPendiente($tarjetaId),
        ];
    }

    /** Lo que queda por pagar de los extrafinanciamientos vigentes. */
    public function saldoPendiente(int $tarjetaId): int
    {
        return (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(q.total_cent),0)
               FROM tarjeta_extra_cuotas q
               JOIN tarjeta_extrafinanciamientos e ON e.id = q.extra_id
              WHERE e.tarjeta_id = ? AND e.estado = "vigente" AND q.cobrada_en IS NULL',
            [$tarjetaId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function vigentesDe(int $tarjetaId): array
    {
        return BaseDatos::todas(
            'SELECT e.*,
                    (SELECT COALESCE(SUM(q.total_cent),0) FROM tarjeta_extra_cuotas q
                      WHERE q.extra_id = e.id AND q.cobrada_en IS NULL) AS pendiente_cent,
                    (SELECT MIN(q.fecha_corte) FROM tarjeta_extra_cuotas q
                      WHERE q.extra_id = e.id AND q.cobrada_en IS NULL) AS proxima_cuota
               FROM tarjeta_extrafinanciamientos e
              WHERE e.tarjeta_id = ? AND e.estado = "vigente"
              ORDER BY e.id DESC',
            [$tarjetaId]
        );
    }

    // =================================================================
    // CÁLCULO
    // =================================================================

    /** Cuota francesa: capital e intereses iguales todos los meses. */
    private function cuotaDe(int $monto, int $tasaBp, int $plazo): int
    {
        if ($plazo < 1) {
            return $monto;
        }

        $i = $tasaBp / 10000 / 12;

        if ($i <= 0) {
            return (int) ceil($monto / $plazo);
        }

        $factor = (1 - (1 + $i) ** (-$plazo)) / $i;

        return (int) ceil($monto / $factor);
    }

    /** El monto que deja una cuota dada: la fórmula al revés. */
    private function montoDesdeCuota(int $cuota, int $tasaBp, int $plazo): int
    {
        if ($plazo < 1 || $cuota <= 0) {
            return 0;
        }

        $i = $tasaBp / 10000 / 12;

        if ($i <= 0) {
            return $cuota * $plazo;
        }

        return (int) floor($cuota * (1 - (1 + $i) ** (-$plazo)) / $i);
    }

    /**
     * El calendario, cuota por cuota.
     *
     * La última ajusta el redondeo: con cuotas redondeadas hacia arriba
     * la suma se pasa unos centavos, y cobrar de más —aunque sean tres
     * centavos— es cobrar de más.
     */
    private function generarCuotas(
        int $extraId,
        int $monto,
        int $tasaBp,
        int $plazo,
        int $cuota,
        int $diaCorte
    ): void {
        $i = $tasaBp / 10000 / 12;
        $saldo = $monto;
        $diaCorte = min(28, max(1, $diaCorte));

        for ($n = 1; $n <= $plazo; $n++) {
            $interes = (int) round($saldo * $i);
            $capital = $cuota - $interes;

            if ($n === $plazo || $capital > $saldo) {
                // La ultima salda lo que quede, ni un centavo mas.
                $capital = $saldo;
                $cuotaN  = $capital + $interes;
            } else {
                $cuotaN = $cuota;
            }

            $saldo -= $capital;

            $base = (new \DateTimeImmutable('first day of this month'))
                ->modify('+' . $n . ' months');

            $fecha = $base->setDate(
                (int) $base->format('Y'),
                (int) $base->format('n'),
                min($diaCorte, (int) $base->format('t'))
            );

            BaseDatos::ejecutar(
                'INSERT INTO tarjeta_extra_cuotas
                   (extra_id, numero, capital_cent, interes_cent, total_cent,
                    saldo_posterior_cent, fecha_corte)
                 VALUES (?,?,?,?,?,?,?)',
                [$extraId, $n, $capital, $interes, $cuotaN, max(0, $saldo),
                 $fecha->format('Y-m-d')]
            );
        }
    }

    /** Lo que ya paga cada mes: cuotas de crédito y mínimo de tarjetas. */
    private function compromisos(int $usuarioId): Monto
    {
        $creditos = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(cuota_cent),0) FROM creditos
              WHERE usuario_id = ? AND estado = "vigente"',
            [$usuarioId]
        );

        $pct = max(1, Parametros::entero('tarjeta.pago_minimo_pct', 500));

        $tarjetas = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(saldo_usado_cent),0) FROM tarjetas
              WHERE usuario_id = ? AND tipo = "credito" AND estado = "activa"',
            [$usuarioId]
        );

        $extras = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(cuota_cent),0) FROM tarjeta_extrafinanciamientos
              WHERE usuario_id = ? AND estado = "vigente"',
            [$usuarioId]
        );

        return Monto::deCentavos(
            $creditos + $extras + (int) round($tarjetas * $pct / 10000)
        );
    }
}
