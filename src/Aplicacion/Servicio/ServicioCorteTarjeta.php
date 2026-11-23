<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\CostosCredito;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Corte mensual de tarjetas de crédito.
 *
 * La tabla `tarjeta_cortes` llevaba desde el principio en el esquema y
 * vacía: nunca hubo quien la llenara. Sin corte, una tarjeta de crédito
 * no es más que un saldo quieto — sin estado de cuenta, sin pago mínimo,
 * sin intereses y sin los seguros obligatorios.
 *
 * Qué hace cada mes, por tarjeta:
 *
 *   1. Delimita el periodo: desde el día siguiente al corte anterior
 *      hasta la fecha de corte de este mes.
 *   2. Suma consumos y pagos liquidados dentro del periodo.
 *   3. Calcula intereses sobre el saldo que se queda financiado.
 *   4. Aplica el seguro de vida y el de desempleo sobre ese saldo.
 *   5. Fija el pago mínimo y la fecha límite.
 *   6. Marca los movimientos como pertenecientes a ese corte.
 *
 * Dos cosas que NO hace, a propósito:
 *
 *   · No cobra intereses a quien paga de contado. Si el saldo anterior
 *     quedó en cero, el periodo se financia gratis: es la promesa básica
 *     de una tarjeta de crédito y romperla sería cobrar de más.
 *
 *   · No genera asientos contables. Los intereses y comisiones se
 *     reconocen cuando el cliente paga, igual que la mora de los
 *     créditos. Aquí solo se liquida el periodo y se deja dicho cuánto
 *     debe.
 *
 *   · Pero SÍ anota sus cargos como movimientos de tarjeta. No es
 *     contabilidad: es que el saldo tiene que poder explicarse con
 *     las líneas que lo componen. Antes los intereses y los seguros
 *     se sumaban al saldo y no aparecían en ninguna parte.
 */
final class ServicioCorteTarjeta
{
    /**
     * Ejecuta el corte de las tarjetas que lo tengan vencido.
     *
     * @param string|null $hasta   Fecha de proceso (Y-m-d). Por defecto hoy.
     * @param bool        $simular Calcula sin escribir.
     *
     * @return array{cortes:int, saldo_cent:int, intereses_cent:int,
     *               seguros_cent:int, detalle:array<int,array<string,mixed>>}
     */
    public function cortar(?string $hasta = null, bool $simular = false): array
    {
        $hoy = $hasta !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) === 1
            ? $hasta
            : date('Y-m-d');

        $tarjetas = BaseDatos::todas(
            // LEFT JOIN y no JOIN: una tarjeta de crédito no cuelga de
            // una cuenta de depósito, y las emitidas antes de la
            // migración 017 pueden no tener producto. Con un JOIN
            // interno no salía ninguna y el corte no hacía nada.
            "SELECT t.id, t.ultimos4, t.marca, t.saldo_usado_cent, t.limite_cent,
                    t.dia_corte, t.dias_para_pago, t.ultimo_corte, t.usuario_id,
                    p.tasa_interes_bp, p.plazo_financiamiento_meses
               FROM tarjetas t
          LEFT JOIN productos p ON p.id = t.producto_id
              -- 'inhabilitada' tambien: una tarjeta bloqueada por
              -- sobregiro sigue devengando interes y necesita su estado
              -- de cuenta cada mes; lo unico que no puede es gastar mas.
              WHERE t.tipo = 'credito' AND t.estado IN ('activa', 'inhabilitada')
              ORDER BY t.id"
        );

        $r = ['cortes' => 0, 'saldo_cent' => 0, 'intereses_cent' => 0,
              'seguros_cent' => 0, 'detalle' => []];

        foreach ($tarjetas as $t) {
            $corte = $this->fechaDeCorte((int) $t['dia_corte'], $hoy, $t['ultimo_corte']);

            if ($corte === null) {
                continue;
            }

            $calculo = $this->calcular($t, $corte);

            $r['cortes']++;
            $r['saldo_cent']     += $calculo['saldo_final_cent'];
            $r['intereses_cent'] += $calculo['intereses_cent'];
            $r['seguros_cent']   += $calculo['seguro_vida_cent'] + $calculo['seguro_empleo_cent'];
            $r['detalle'][]       = [
                    'tarjeta' => mb_strtoupper((string) $t['marca']) . ' ····' . $t['ultimos4'],
                ] + $calculo;

            if (!$simular) {
                $this->guardar((int) $t['id'], $calculo);
            }
        }

        if (!$simular && $r['cortes'] > 0) {
            Auditoria::registrar('tarjeta.corte_mensual', Auditoria::EXITO,
                PHP_SAPI === 'cli' ? null : Sesion::usuarioId(),
                'tarjeta', $hoy,
                [
                    'cortes'    => $r['cortes'],
                    'saldo'     => number_format($r['saldo_cent'] / 100, 2),
                    'intereses' => number_format($r['intereses_cent'] / 100, 2),
                    'seguros'   => number_format($r['seguros_cent'] / 100, 2),
                ]);
        }

        return $r;
    }

    /**
     * Fecha de corte que toca, o null si todavía no.
     *
     * Devuelve el corte más reciente ya cumplido y que aún no se haya
     * procesado. Si una tarjeta lleva meses sin cortarse, esto cierra el
     * mes más antiguo pendiente: así los periodos salen consecutivos en
     * vez de quedar un hueco sin estado de cuenta.
     */
    /**
     * El estado de cuenta, por correo y con el PDF adjunto.
     *
     * Un estado de cuenta es el documento con el que se comprueba un
     * cargo y se reclama. Mandar solo el total deja al cliente con la
     * versión del banco y nada con qué contrastarla, así que va el
     * cuadre entero en el cuerpo y el detalle completo en el adjunto.
     *
     * Nunca lanza: el corte ya está hecho y guardado.
     */
    /**
     * Una fila de la tabla de composición.
     *
     * Aparte para no repetir seis veces el mismo `<td>` con su estilo
     * dentro de una concatenación que ya es larga de leer.
     */
    private function filaComp(
        string $etiqueta,
        int $interes,
        int $comision,
        int $recargo,
        int $capital,
        int $total,
        callable $d
    ): string {
        $celda = static fn(int $v): string =>
            '<td style="padding:7px 8px;border-bottom:1px solid #eef1f5;text-align:right">'
            . htmlspecialchars($d($v), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</td>';

        return '<tr><td style="padding:7px 8px;border-bottom:1px solid #eef1f5">'
            . htmlspecialchars($etiqueta, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</td>'
            . $celda($interes) . $celda($comision) . $celda($recargo) . $celda($capital)
            . '<td style="padding:7px 8px;border-bottom:1px solid #eef1f5;text-align:right">'
            . '<strong>' . htmlspecialchars($d($total), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</strong></td></tr>';
    }

    /**
     * Cuánto acaba pagando quien paga solo el mínimo.
     *
     * Se simula mes a mes con la misma regla del pago mínimo, igual
     * que el cálculo del plazo. La cifra importa: un saldo de mil
     * dólares pagado al mínimo puede terminar costando casi tres mil,
     * y eso no se ve en ninguna otra línea del documento.
     */
    private function totalPagandoMinimo(int $saldo, int $tasaBp, int $meses): int
    {
        if ($saldo <= 0 || $meses <= 0) { return 0; }

        $pct  = max(0, Parametros::entero('tarjeta.pago_minimo_pct', 500));
        $piso = max(0, Parametros::entero('tarjeta.pago_minimo_piso_cent', 1000));
        $i    = $tasaBp / 10000 / 12;

        $total = 0;
        $s     = $saldo;

        /* La misma regla que calcula el plazo: capital en partes
           iguales más el interés del mes. Si aquí se usara otra, el
           documento diría que tarda N meses y que paga una cantidad
           que no corresponde a esos N meses. */
        $capitalFijo = (int) ceil($saldo / $meses);

        for ($m = 0; $m < $meses && $s > 0; $m++) {
            $interes = (int) round($s * $i);

            $cuota = max($piso, (int) round($s * $pct / 10000), $capitalFijo + $interes);
            $cuota = min($cuota, $s + $interes);

            if ($cuota <= $interes) { return 0; }

            $total += $cuota;
            $s      = $s + $interes - $cuota;
        }

        return $total;
    }

    /**
     * La tasa revolvente que de verdad se aplica a una tarjeta.
     *
     * Es la del producto (o el parámetro de respaldo si no tiene),
     * menos el descuento del segmento del titular. Vive aquí, en un
     * solo sitio, porque el corte que GENERA y el recálculo que
     * REEMITE tienen que usar exactamente la misma: si fueran dos
     * copias, el día que una cambie el documento diría una tasa y la
     * base otra.
     */
    public static function tasaRevolvente(int $productoTasaBp, int $usuarioId): int
    {
        $tasa = $productoTasaBp > 0
            ? $productoTasaBp
            : Parametros::entero('tarjeta.tasa_revolvente_bp', 4400);

        $descuento = (int) ((new ServicioSegmento())
            ->beneficio($usuarioId, 'tasa_descuento_bp') ?? 0);

        return $descuento > 0 ? max(0, $tasa - $descuento) : $tasa;
    }

    /**
     * El pago mínimo del corte y en cuántos meses se liquida pagándolo.
     *
     * Una sola cuenta, compartida por la generación del corte y por el
     * recálculo que reemite los estados de cuenta, para que el PDF, el
     * correo y la plataforma digan los mismos números. Antes el plazo
     * salía de un sitio y los meses de otro, y el documento se
     * contradecía en la misma página.
     *
     *   - El MÍNIMO recurrente es el mayor de: el porcentaje de ley, el
     *     piso, y la cuota que amortiza el capital dentro del plazo
     *     (capital/plazo + interés del mes). Nunca más que el saldo.
     *   - El EXCEDENTE sobre el límite es exigible de inmediato y se
     *     suma al mínimo de este corte UNA sola vez: la tarjeta no
     *     puede quedar por encima de la línea aprobada.
     *   - Los MESES se simulan con el mínimo recurrente —sin el
     *     excedente, que es un alcance de una vez—: es el plazo real
     *     pagando el mínimo normal, y por diseño cae dentro del plazo
     *     declarado.
     *
     * @return array{minimo:int, meses:int, excedente:int, plazo:int, recurrente:int}
     */
    public static function proyeccionMinimo(
        int $saldoFinal,
        int $tasaAnualBp,
        int $plazoMeses,
        int $limiteCent
    ): array {
        $pct   = max(0, Parametros::entero('tarjeta.pago_minimo_pct', 500));
        $piso  = max(0, Parametros::entero('tarjeta.pago_minimo_piso_cent', 1000));
        $plazo = $plazoMeses > 0
            ? $plazoMeses
            : max(1, Parametros::entero('tarjeta.plazo_financiamiento_meses', 36));

        if ($saldoFinal <= 0) {
            return ['minimo' => 0, 'meses' => 0, 'excedente' => 0, 'plazo' => $plazo, 'recurrente' => 0];
        }

        $porPlazo = (int) ceil($saldoFinal / $plazo)
                  + (int) round($saldoFinal * ($tasaAnualBp / 10000) / 12);

        $recurrente = min(
            $saldoFinal,
            max((int) round($saldoFinal * $pct / 10000), $piso, $porPlazo)
        );

        /* El excedente sobre el límite, exigible de inmediato. Si la
           tarjeta no tiene límite registrado no se infiere ninguno. */
        $excedente = $limiteCent > 0 ? max(0, $saldoFinal - $limiteCent) : 0;
        $minimo    = min($saldoFinal, $recurrente + $excedente);

        /* Los meses, pagando el mínimo recurrente. El capital se
           amortiza en partes iguales (porción fija), así queda
           liquidado dentro del plazo en vez de estirarse al bajar el
           saldo. El excedente no entra: es un alcance de una vez. */
        $capitalFijo = (int) ceil($saldoFinal / $plazo);
        $meses       = 0;
        $saldoSim    = $saldoFinal;
        $iMes        = $tasaAnualBp / 10000 / 12;

        while ($saldoSim > 0 && $meses < 600) {
            $interesSim = (int) round($saldoSim * $iMes);

            $cuotaSim = max($piso, (int) round($saldoSim * $pct / 10000), $capitalFijo + $interesSim);
            $cuotaSim = min($cuotaSim, $saldoSim + $interesSim);

            if ($cuotaSim <= $interesSim) { $meses = 0; break; }

            $saldoSim = $saldoSim + $interesSim - $cuotaSim;
            $meses++;
        }

        return [
            'minimo'     => $minimo,
            'meses'      => $meses,
            'excedente'  => $excedente,
            'plazo'      => $plazo,
            'recurrente' => $recurrente,
        ];
    }

    private function avisarCorte(int $corteId): bool
    {
        try {
            $c = BaseDatos::unaFila(
                'SELECT k.*, t.ultimos4, t.marca, t.limite_cent, t.usuario_id,
                        p.nombre AS producto, u.correo,
                        CONCAT(u.nombres," ",u.apellidos) AS cliente
                   FROM tarjeta_cortes k
                   JOIN tarjetas t ON t.id = k.tarjeta_id
                   JOIN usuarios u ON u.id = t.usuario_id
              LEFT JOIN productos p ON p.id = t.producto_id
                  WHERE k.id = ?',
                [$corteId]
            );

            if ($c === null || trim((string) $c['correo']) === '') {
                return false;
            }

            $e = static fn(string $x): string => htmlspecialchars($x, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $d = static fn(int $x): string => Monto::deCentavos($x)->formateado();
            $f = static fn(string $x): string => date('d/m/Y', strtotime($x));

            $tarjeta = mb_strtoupper((string) $c['marca']) . ' ••••' . $c['ultimos4'];
            $dias = max(0, (int) ceil(
                (strtotime((string) $c['fecha_limite_pago']) - time()) / 86400
            ));

            // --- El PDF, adjunto -----------------------------------
            $adjuntos = [];

            try {
                $doc = (new \CashMan\Infraestructura\Documento\ServicioDocumentos())
                    ->estadoTarjeta($corteId);

                if (is_file((string) $doc['ruta'])) {
                    $adjuntos[] = [
                        'ruta'   => (string) $doc['ruta'],
                        'nombre' => 'estado-de-cuenta-' . $c['ultimos4']
                                  . '-' . $c['fecha_corte'] . '.pdf',
                        'tipo'   => 'application/pdf',
                    ];
                }
            } catch (\Throwable $ex) {
                // El aviso vale aunque el adjunto falle: el cliente
                // puede bajarlo de su banca en linea.
                error_log('[Corte] PDF no adjuntado: ' . $ex->getMessage());
            }

            // ---------- Lo que el documento necesita y no venía ----------

            $saldo      = (int) $c['saldo_final_cent'];
            $limite     = (int) $c['limite_cent'];
            $disponible = $limite - $saldo;

            /* Los días del periodo: es la base sobre la que se calculó
               el interés, y la normativa pide decirla. */
            $diasPeriodo = max(1,
                (int) ((strtotime((string) $c['periodo_fin'])
                      - strtotime((string) $c['periodo_inicio'])) / 86400) + 1
            );

            /* Las tres tasas.
             *
             * La NOMINAL es la del contrato. La EFECTIVA es la que se
             * paga de verdad cuando el interés se capitaliza cada mes,
             * y siempre es mayor: decir solo la nominal deja al cliente
             * con una cifra que no coincide con lo que ve pagar. La
             * MORATORIA es el recargo del contrato. */
            $tasaNominal = (int) BaseDatos::unValor(
                'SELECT COALESCE(p.tasa_interes_bp, 0) FROM tarjetas t
              LEFT JOIN productos p ON p.id = t.producto_id
                  WHERE t.id = ?',
                [(int) $c['tarjeta_id']]
            );

            if ($tasaNominal <= 0) {
                $tasaNominal = Parametros::entero('tarjeta.tasa_revolvente_bp', 4400);
            }

            $tasaEfectiva = (int) round(
                ((pow(1 + ($tasaNominal / 10000) / 12, 12) - 1) * 10000)
            );

            $tasaMora = Parametros::entero('tarjeta.tasa_mora_bp', 0);

            /* El extrafinanciamiento, con su avance. */
            $planes = BaseDatos::todas(
                'SELECT folio, plazo_meses, cuota_cent, monto_cent, cuotas_pagadas,
                        GREATEST(0, total_cent - pagado_cent) AS saldo_cent
                   FROM tarjeta_extrafinanciamientos
                  WHERE tarjeta_id = ? AND estado = "vigente"',
                [(int) $c['tarjeta_id']]
            );

            $cuotaExtra = 0;

            foreach ($planes as $p) { $cuotaExtra += (int) $p['cuota_cent']; }

            /* La composición de la deuda: de lo que va a pagar, cuánto
               es interés, cuánto comisión, cuánto recargo y cuánto
               capital de verdad. */
            $compInteres  = (int) $c['intereses_cent'];
            $compComision = (int) $c['comisiones_cent']
                          + (int) $c['seguro_vida_cent'] + (int) $c['seguro_empleo_cent'];
            $compRecargo  = (int) $c['mora_cent'];
            $compCapital  = max(0, $saldo - $compInteres - $compComision - $compRecargo);

            $minimo = (int) $c['pago_minimo_cent'];

            /* En el pago mínimo, lo primero que se cubre son los
               cargos; lo que sobra es lo único que baja la deuda. Es
               la cuenta que explica por qué pagando el mínimo el saldo
               no se mueve. */
            $minInteres  = min($minimo, $compInteres);
            $minComision = min(max(0, $minimo - $minInteres), $compComision);
            $minRecargo  = min(max(0, $minimo - $minInteres - $minComision), $compRecargo);
            $minCapital  = max(0, $minimo - $minInteres - $minComision - $minRecargo);

            $totalSiMinimo = (int) $c['meses_si_paga_minimo'] > 0
                ? $this->totalPagandoMinimo($saldo, $tasaNominal, (int) $c['meses_si_paga_minimo'])
                : 0;

            $cuerpo =
                '<p>Su estado de cuenta de la tarjeta <strong>' . $e($tarjeta)
                . '</strong> correspondiente al periodo del '
                . $e($f((string) $c['periodo_inicio'])) . ' al '
                . $e($f((string) $c['periodo_fin'])) . ' ya está disponible.</p>'

                . '<p style="margin-top:22px"><strong>Cómo se llegó a este saldo</strong></p>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
                . ' style="margin:14px 0;border-collapse:collapse;font-size:14px">'
                . $this->fila('Saldo anterior', $d((int) $c['saldo_anterior_cent']))
                . $this->fila('(+) Consumos del periodo', $d((int) $c['consumos_cent']))
                . $this->fila('(−) Pagos recibidos', $d((int) $c['pagos_cent']))
                . $this->fila('(+) Intereses', $d((int) $c['intereses_cent']))
                . $this->fila('(+) Seguro de vida', $d((int) $c['seguro_vida_cent']))
                . $this->fila('(+) Seguro de desempleo', $d((int) $c['seguro_empleo_cent']))
                . $this->fila('(+) Comisiones', $d((int) $c['comisiones_cent']))
                . $this->fila('(+) Mora', $d((int) $c['mora_cent']))
                . $this->fila('SALDO AL CORTE', $d((int) $c['saldo_final_cent']), true)
                . '</table>'

                /* RESUMEN DE CUENTA: lo primero que mira cualquiera. */
                . '<p style="margin-top:22px"><strong>Resumen de cuenta</strong></p>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
                . ' style="margin:14px 0;border-collapse:collapse;font-size:14px">'
                . $this->fila('Límite de crédito', $d($limite))
                . $this->fila('Saldo al corte', $d($saldo))
                . $this->fila(
                    $disponible >= 0 ? 'Crédito disponible' : 'Excedido sobre el límite',
                    $d(abs($disponible))
                  )
                . '</table>'
                . ($disponible < 0
                    ? '<p style="font-size:13px;color:#b42318;margin:0">Su saldo supera la '
                      . 'línea aprobada en <strong>' . $e($d(abs($disponible))) . '</strong>. '
                      . 'Ese excedente es exigible de inmediato y ya está incluido en el pago '
                      . 'mínimo de este corte. Si no se cubre a la fecha límite de pago, la '
                      . 'tarjeta se inhabilita para nuevos consumos hasta solventarlo.</p>'
                    : '')

                /* PAGO MÍNIMO, DESGLOSADO. Un cliente con un plan de
                   cuotas que paga «el mínimo» sin la cuota del plan
                   queda en mora sin enterarse. */
                . '<p style="margin-top:22px"><strong>Detalle del pago mínimo</strong></p>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
                . ' style="margin:14px 0;border-collapse:collapse;font-size:14px">'
                . $this->fila('Pago mínimo de la tarjeta', $d($minimo))
                . $this->fila('Cuotas de extrafinanciamiento', $d($cuotaExtra))
                . $this->fila('PAGO MÍNIMO TOTAL', $d($minimo + $cuotaExtra), true)
                . '</table>'

                /* COMPOSICIÓN: a dónde va cada dólar que paga. */
                . '<p style="margin-top:22px"><strong>En qué se va lo que paga</strong></p>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
                . ' style="margin:14px 0;border-collapse:collapse;font-size:13px">'
                . '<tr style="color:#667085;text-transform:uppercase;letter-spacing:.04em;font-size:11px">'
                . '<td style="padding:6px 8px;border-bottom:2px solid #e4e7ec"></td>'
                . '<td style="padding:6px 8px;border-bottom:2px solid #e4e7ec;text-align:right">Intereses</td>'
                . '<td style="padding:6px 8px;border-bottom:2px solid #e4e7ec;text-align:right">Comisiones</td>'
                . '<td style="padding:6px 8px;border-bottom:2px solid #e4e7ec;text-align:right">Recargos</td>'
                . '<td style="padding:6px 8px;border-bottom:2px solid #e4e7ec;text-align:right">Capital</td>'
                . '<td style="padding:6px 8px;border-bottom:2px solid #e4e7ec;text-align:right">Total</td></tr>'
                . $this->filaComp('Pago mínimo', $minInteres, $minComision, $minRecargo, $minCapital, $minimo, $d)
                . $this->filaComp('Pago de contado', $compInteres, $compComision, $compRecargo, $compCapital, $saldo, $d)
                . '</table>'
                . ($minCapital <= 0 && $saldo > 0
                    ? '<p style="font-size:13px;color:#b54708;margin:0">Pagando el mínimo de '
                      . 'este corte <strong>no abona nada a capital</strong>: alcanza solo '
                      . 'para los intereses y cargos, y su deuda no baja.</p>'
                    : '')

                /* LAS TASAS Y EL PLAZO, que es lo que la ley manda. */
                . '<p style="margin-top:22px"><strong>Tasas y plazo</strong></p>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
                . ' style="margin:14px 0;border-collapse:collapse;font-size:14px">'
                . $this->fila('Tasa nominal anual', number_format($tasaNominal / 100, 2) . ' %')
                . $this->fila('Tasa efectiva anual', number_format($tasaEfectiva / 100, 2) . ' %')
                . $this->fila('Tasa moratoria anual',
                    $tasaMora > 0 ? number_format($tasaMora / 100, 2) . ' %' : 'No aplica')
                . $this->fila('Días del periodo de cálculo', (string) $diasPeriodo)
                . $this->fila('Plazo de financiamiento',
                    (int) ($c['plazo_financiamiento_meses'] ?? 0) . ' meses')
                . '</table>'

                /* EL EXTRAFINANCIAMIENTO, con su avance. */
                . ($planes !== []
                    ? '<p style="margin-top:22px"><strong>Sus compras a cuotas</strong></p>'
                      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
                      . ' style="margin:14px 0;border-collapse:collapse;font-size:13px">'
                      . '<tr style="color:#667085;text-transform:uppercase;letter-spacing:.04em;font-size:11px">'
                      . '<td style="padding:6px 8px;border-bottom:2px solid #e4e7ec">Plan</td>'
                      . '<td style="padding:6px 8px;border-bottom:2px solid #e4e7ec;text-align:right">Cuota</td>'
                      . '<td style="padding:6px 8px;border-bottom:2px solid #e4e7ec;text-align:right">Avance</td>'
                      . '<td style="padding:6px 8px;border-bottom:2px solid #e4e7ec;text-align:right">Le resta</td></tr>'
                      . implode('', array_map(
                          function (array $p) use ($d, $e): string {
                              $pagadas = (int) $p['cuotas_pagadas'];
                              $plazo   = max(1, (int) $p['plazo_meses']);

                              return '<tr>'
                                  . '<td style="padding:7px 8px;border-bottom:1px solid #eef1f5">'
                                  . $e((string) $p['folio']) . '</td>'
                                  . '<td style="padding:7px 8px;border-bottom:1px solid #eef1f5;text-align:right">'
                                  . $e($d((int) $p['cuota_cent'])) . '</td>'
                                  . '<td style="padding:7px 8px;border-bottom:1px solid #eef1f5;text-align:right">'
                                  . $pagadas . ' de ' . $plazo . '</td>'
                                  . '<td style="padding:7px 8px;border-bottom:1px solid #eef1f5;text-align:right">'
                                  . $e($d((int) $p['saldo_cent'])) . '</td></tr>';
                          },
                          $planes
                      ))
                      . '</table>'
                      . '<p style="font-size:13px;color:#475467;margin:0">La cuota de estos '
                      . 'planes <strong>se suma al pago mínimo</strong>. Pagar solo el mínimo '
                      . 'de la tarjeta sin la cuota del plan deja el plan en mora.</p>'
                    : '')

                . '<p style="margin-top:22px"><strong>Sus dos opciones</strong></p>'
                . '<ul style="font-size:14px;color:#475467;padding-left:18px;margin:0">'
                . '<li><strong>Pago de contado: ' . $e($d((int) $c['pago_contado_cent']))
                . '.</strong> Pagando el total antes de la fecha límite, los consumos de '
                . 'este periodo <strong>no generan intereses</strong>.</li>'
                . '<li><strong>Pago mínimo: ' . $e($d((int) $c['pago_minimo_cent']))
                . '.</strong> Es lo menos que debe abonar para no caer en mora. El saldo '
                . 'que quede sin pagar devenga intereses desde el próximo corte.</li>'
                . '</ul>'

                /* El plazo, que la ley manda decir y que es lo que
                   convierte el pago mínimo en una cifra con sentido:
                   sin él, nadie sabe si pagando el mínimo termina en
                   tres años o en once. */
                . '<p style="margin-top:22px"><strong>En cuánto tiempo se termina</strong></p>'
                . '<ul style="font-size:14px;color:#475467;padding-left:18px;margin:0">'
                . '<li>El plazo de financiamiento de esta tarjeta es de <strong>'
                . (int) ($c['plazo_financiamiento_meses'] ?? 0) . ' meses</strong>. '
                . 'El pago mínimo está calculado para que el saldo quede liquidado '
                . 'dentro de ese plazo.</li>'
                . ((int) ($c['meses_si_paga_minimo'] ?? 0) > 0
                    ? '<li>Pagando <strong>solo el mínimo</strong> cada mes y sin hacer '
                      . 'consumos nuevos, este saldo quedaría liquidado en '
                      . '<strong>' . (int) $c['meses_si_paga_minimo'] . ' mes(es)</strong>. '
                      . 'Abonar por encima del mínimo acorta ese plazo y reduce lo que '
                      . 'paga de intereses.'
                      . ($totalSiMinimo > 0
                          ? ' Al cabo de ese plazo habría pagado <strong>'
                            . $e($d($totalSiMinimo)) . '</strong> por un saldo de '
                            . $e($d($saldo)) . '.'
                          : '')
                      . '</li>'
                    : '')
                . '</ul>'

                . '<p style="margin-top:18px">Tiene hasta el <strong>'
                . $e($f((string) $c['fecha_limite_pago'])) . '</strong>'
                . ($dias > 0 ? ' — faltan ' . $dias . ' día(s)' : '') . '.</p>'

                . ($adjuntos !== []
                    ? '<p style="font-size:13px;color:#667085;margin-top:22px">Se adjunta el '
                      . 'estado de cuenta completo en PDF, con el detalle de cada movimiento '
                      . 'del periodo. Consérvelo: es el documento con el que puede comprobar '
                      . 'un cargo o reclamarlo.</p>'
                    : '<p style="font-size:13px;color:#667085;margin-top:22px">Puede descargar '
                      . 'el estado de cuenta completo desde su banca en línea, en la ficha de '
                      . 'la tarjeta.</p>')

                . '<p style="font-size:13px;color:#667085">Si no reconoce algún movimiento, '
                . 'comuníquese con nosotros antes de la fecha límite indicando la referencia '
                . 'que aparece en el detalle.</p>';

            $filas = [
                ['etiqueta' => 'Tarjeta',        'valor' => $tarjeta],
                ['etiqueta' => 'Producto',       'valor' => (string) ($c['producto'] ?? 'Tarjeta de crédito')],
                ['etiqueta' => 'Periodo',        'valor' => $f((string) $c['periodo_inicio'])
                                                          . ' al ' . $f((string) $c['periodo_fin'])],
                ['etiqueta' => 'Fecha de corte', 'valor' => $f((string) $c['fecha_corte'])],
                ['etiqueta' => 'Movimientos',    'valor' => (string) (int) $c['movimientos']],
                ['etiqueta' => 'SALDO AL CORTE', 'valor' => $d((int) $c['saldo_final_cent'])],
                ['etiqueta' => 'Pago de contado','valor' => $d((int) $c['pago_contado_cent'])],
                ['etiqueta' => 'Pago mínimo',    'valor' => $d((int) $c['pago_minimo_cent'])],
                ['etiqueta' => 'Fecha límite',   'valor' => $f((string) $c['fecha_limite_pago'])],
                ['etiqueta' => 'Línea aprobada', 'valor' => $d((int) $c['limite_cent'])],
            ];

            $texto = "ESTADO DE CUENTA\n\n"
                . 'Senor(a) ' . $c['cliente'] . ":\n\n"
                . 'Tarjeta: ' . mb_strtoupper((string) $c['marca']) . ' ****' . $c['ultimos4'] . "\n"
                . 'Periodo: ' . $f((string) $c['periodo_inicio'])
                . ' al ' . $f((string) $c['periodo_fin']) . "\n"
                . 'Corte: ' . $f((string) $c['fecha_corte']) . "\n\n"
                . "COMO SE LLEGO A ESTE SALDO\n"
                . '  Saldo anterior ......... ' . $d((int) $c['saldo_anterior_cent']) . "\n"
                . '  (+) Consumos ........... ' . $d((int) $c['consumos_cent']) . "\n"
                . '  (-) Pagos .............. ' . $d((int) $c['pagos_cent']) . "\n"
                . '  (+) Intereses .......... ' . $d((int) $c['intereses_cent']) . "\n"
                . '  (+) Seguro de vida ..... ' . $d((int) $c['seguro_vida_cent']) . "\n"
                . '  (+) Seguro de desempleo  ' . $d((int) $c['seguro_empleo_cent']) . "\n"
                . '  (+) Comisiones ......... ' . $d((int) $c['comisiones_cent']) . "\n"
                . '  (+) Mora ............... ' . $d((int) $c['mora_cent']) . "\n"
                . '  SALDO AL CORTE ......... ' . $d((int) $c['saldo_final_cent']) . "\n\n"
                . "SUS DOS OPCIONES\n"
                . '  Pago de contado: ' . $d((int) $c['pago_contado_cent'])
                . " (sin intereses sobre lo de este periodo)\n"
                . '  Pago minimo: ' . $d((int) $c['pago_minimo_cent'])
                . " (el resto devenga intereses)\n\n"
                . 'Fecha limite: ' . $f((string) $c['fecha_limite_pago']) . "\n\n"
                . ($adjuntos !== []
                    ? "Se adjunta el estado de cuenta completo en PDF.\n"
                    : "Puede descargarlo desde su banca en linea.\n");

            return (new Correo())->enviar(
                (string) $c['correo'],
                'Estado de cuenta ' . $tarjeta . ' · saldo '
                . $d((int) $c['saldo_final_cent']),
                PlantillaCorreo::base(
                    'Estado de cuenta',
                    'Señor(a) ' . $c['cliente'],
                    $cuerpo,
                    $filas,
                    null,
                    null,
                    null,
                    'Este estado de cuenta refleja los movimientos del periodo indicado. '
                    . 'Los posteriores a la fecha de corte entran en el siguiente.'
                ),
                $texto,
                (string) $c['cliente'],
                [],
                '',
                $adjuntos
            );
        } catch (\Throwable $ex) {
            error_log('[Corte] aviso no enviado: ' . $ex->getMessage());
            return false;
        }
    }

    /** Una fila del cuadre, para el cuerpo del correo. */
    private function fila(string $etiqueta, string $valor, bool $total = false): string
    {
        $e = static fn(string $x): string => htmlspecialchars($x, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $borde = $total ? '2px solid #101828' : '1px solid #e4e7ec';
        $peso  = $total ? '700' : '400';

        return '<tr>'
            . '<td style="padding:8px 0;border-bottom:' . $borde . ';color:#475467;'
            . 'font-weight:' . $peso . '">' . $e($etiqueta) . '</td>'
            . '<td align="right" style="padding:8px 0;border-bottom:' . $borde . ';'
            . 'font-weight:' . ($total ? '700' : '600') . ';color:#101828">'
            . $e($valor) . '</td></tr>';
    }
    private function fechaDeCorte(int $diaCorte, string $hoy, ?string $ultimoCorte): ?string
    {
        $diaCorte = max(1, min(28, $diaCorte)); // 28 evita el lío de febrero
        $hoyDt    = new \DateTimeImmutable($hoy);

        // Candidato de este mes.
        $candidato = $hoyDt->setDate((int) $hoyDt->format('Y'), (int) $hoyDt->format('n'), $diaCorte);

        // Si aún no llega, el último corte cumplido fue el del mes pasado.
        if ($candidato > $hoyDt) {
            $candidato = $candidato->modify('-1 month');
        }

        if ($ultimoCorte !== null && $ultimoCorte !== '') {
            $anterior = new \DateTimeImmutable($ultimoCorte);

            if ($anterior >= $candidato) {
                return null; // ya está al día
            }

            // Se cierra el siguiente mes pendiente, no el más reciente.
            $siguiente = $anterior->modify('+1 month')
                ->setDate((int) $anterior->modify('+1 month')->format('Y'),
                          (int) $anterior->modify('+1 month')->format('n'), $diaCorte);

            return $siguiente <= $hoyDt ? $siguiente->format('Y-m-d') : null;
        }

        return $candidato->format('Y-m-d');
    }

    /**
     * Calcula el corte de una tarjeta.
     *
     * @param array<string,mixed> $t
     * @return array<string,mixed>
     */
    private function calcular(array $t, string $corte): array
    {
        $corteDt = new \DateTimeImmutable($corte);

        $inicio = $t['ultimo_corte'] !== null && $t['ultimo_corte'] !== ''
            ? (new \DateTimeImmutable((string) $t['ultimo_corte']))->add(new \DateInterval('P1D'))
            : $corteDt->modify('-1 month')->add(new \DateInterval('P1D'));

        $periodoInicio = $inicio->format('Y-m-d');

        // Saldo con el que venía el periodo.
        $cortePrevio = BaseDatos::unaFila(
            'SELECT saldo_final_cent FROM tarjeta_cortes
              WHERE tarjeta_id = ? ORDER BY fecha_corte DESC LIMIT 1',
            [(int) $t['id']]
        );

        if ($cortePrevio !== null) {
            $saldoAnterior = (int) $cortePrevio['saldo_final_cent'];
        } else {
            // Primer corte de la tarjeta: no hay cifra anterior de donde
            // partir, pero el saldo usado sí existe. Se deduce restándole
            // los movimientos que todavía no se han cortado, porque esos
            // entran en este o en un periodo posterior y contarlos dos
            // veces inflaría la deuda.
            $sinCortar = (int) BaseDatos::unValor(
                "SELECT COALESCE(SUM(CASE WHEN tipo = 'pago' THEN -importe_cent
                                          ELSE importe_cent END), 0)
                   FROM tarjeta_movimientos
                  WHERE tarjeta_id = ? AND corte_id IS NULL",
                [(int) $t['id']]
            );

            $saldoAnterior = max(0, (int) $t['saldo_usado_cent'] - $sinCortar);
        }

        /* La cuota del extrafinanciamiento, antes de sumar el periodo.
           Se carga aqui y no despues porque el movimiento tiene que
           entrar en ESTE corte: cargarlo luego lo dejaria para el mes
           siguiente y el cliente veria un estado de cuenta sin la cuota
           que si le van a cobrar. */
        $extra = (new ServicioExtrafinanciamiento())
            ->cobrarEnCorte((int) $t['id'], $corte);

        $mov = BaseDatos::unaFila(
            "SELECT
               COALESCE(SUM(CASE WHEN tipo = 'consumo' THEN importe_cent ELSE 0 END), 0) AS consumos,
               COALESCE(SUM(CASE WHEN tipo = 'pago'    THEN importe_cent ELSE 0 END), 0) AS pagos,
               COALESCE(SUM(CASE WHEN tipo IN ('comision','mora') THEN importe_cent ELSE 0 END), 0) AS cargos,
               COUNT(*) AS n
             FROM tarjeta_movimientos
            WHERE tarjeta_id = ?
              AND estado = 'liquidada'
              AND corte_id IS NULL
              /* Sin limite inferior a proposito.
                 Con `BETWEEN inicio AND corte`, un movimiento anterior
                 al inicio del periodo y aun sin facturar se quedaba
                 fuera de este corte Y de todos los siguientes, porque el
                 inicio del proximo es todavia mas tarde: se perdia para
                 siempre. Lo facturable es lo que no tiene corte y esta
                 fechado hasta el dia del corte; el `corte_id` es lo que
                 impide contarlo dos veces. */
              AND DATE(creado_en) <= ?",
            [(int) $t['id'], $corte]
        ) ?? [];

        $consumos = (int) ($mov['consumos'] ?? 0);
        $pagos    = (int) ($mov['pagos'] ?? 0);
        $cargos   = (int) ($mov['cargos'] ?? 0);

        /* Lo que está en planes de tasa cero no genera nada.
         *
         * «Sin intereses» tiene que significar sin intereses, y eso
         * incluye los seguros: si una compra a doce cuotas sin intereses
         * acaba pagando prima de vida y de desempleo cada mes sobre su
         * saldo, el cliente termina pagando más que el precio de
         * etiqueta y la promesa era falsa.
         *
         * Antes el corte tomaba el saldo entero como base de las dos
         * cosas, así que un televisor comprado a tasa cero devengaba
         * interés y seguros como cualquier otro consumo. Aquí se aparta
         * ese saldo de las dos bases de cálculo. */
        $saldoTasaCero = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(total_cent - pagado_cent), 0)
               FROM tarjeta_planes
              WHERE tarjeta_id = ? AND estado = "vigente" AND tipo = "tasa_cero"',
            [(int) $t['id']]
        );

        // Interés solo sobre lo que quedó financiado del periodo
        // anterior. Quien pagó todo no paga intereses: es la promesa de
        // una tarjeta y cobrárselos sería un cargo indebido.
        $financiado = max(0, $saldoAnterior - $pagos - $saldoTasaCero);

        /* La tasa revolvente real: la del producto menos el descuento
         * del segmento del titular. Se lee en cada corte y no se
         * congela al emitir —si el cliente sube o baja de segmento, el
         * siguiente corte ya sale con su tasa—, y vive en un método
         * compartido para que el recálculo que reemite use la misma. */
        $tasaAnualBp = self::tasaRevolvente((int) ($t['tasa_interes_bp'] ?? 0), (int) $t['usuario_id']);

        $intereses   = $financiado > 0 && $tasaAnualBp > 0
            ? (int) round($financiado * ($tasaAnualBp / 10000) / 12)
            : 0;

        $saldoFinal = max(0, $saldoAnterior + $consumos + $cargos + $intereses - $pagos);

        /* Seguros obligatorios sobre el saldo del corte, con las tarifas
         * de tarjeta (algo más altas que las de un crédito), y SIN la
         * parte que está en tasa cero. */
        $baseSeguros = max(0, $saldoFinal - $saldoTasaCero);

        $seguroVida   = CostosCredito::seguroVida($baseSeguros, true);
        $seguroEmpleo = CostosCredito::seguroEmpleo($baseSeguros, true);

        $saldoFinal += $seguroVida + $seguroEmpleo;

        /* El pago mínimo y en cuántos meses se liquida, en una sola
           cuenta compartida con el recálculo que reemite los estados
           de cuenta (ver proyeccionMinimo). El EXCEDENTE sobre el
           límite ya va incluido en el mínimo: es exigible de inmediato. */
        $proyeccion     = self::proyeccionMinimo(
            $saldoFinal,
            $tasaAnualBp,
            (int) ($t['plazo_financiamiento_meses'] ?? 0),
            (int) ($t['limite_cent'] ?? 0)
        );
        $plazoDeclarado = $proyeccion['plazo'];
        $minimo         = $proyeccion['minimo'];
        $mesesMinimo    = $proyeccion['meses'];

        $diasPago = (int) ($t['dias_para_pago'] ?: Parametros::entero('tarjeta.dias_para_pago', 20));

        return [
            'periodo_inicio'     => $periodoInicio,
            'periodo_fin'        => $corte,
            'fecha_corte'        => $corte,
            'fecha_limite_pago'  => $corteDt->add(new \DateInterval('P' . $diasPago . 'D'))->format('Y-m-d'),
            'plazo_financiamiento_meses' => $plazoDeclarado,
            'meses_si_paga_minimo'       => $mesesMinimo,
            'saldo_anterior_cent' => $saldoAnterior,
            'consumos_cent'      => $consumos,
            'pagos_cent'         => $pagos,
            'intereses_cent'     => $intereses,
            'comisiones_cent'    => $cargos,
            'seguro_vida_cent'   => $seguroVida,
            'seguro_empleo_cent' => $seguroEmpleo,
            // Lo del extrafinanciamiento va aparte del saldo: el
            // estado de cuenta lo desglosa como linea propia, igual
            // que hace la banca.
            'extra_saldo_cent'   => $extra['saldo_cent'],
            'extra_cuota_cent'   => $extra['cuota_cent'],
            'saldo_final_cent'   => $saldoFinal,
            'pago_minimo_cent'   => $minimo,
            'pago_contado_cent'  => $saldoFinal,
            'movimientos'        => (int) ($mov['n'] ?? 0),
        ];
    }

    /**
     * Guarda el corte y amarra sus movimientos.
     *
     * @param array<string,mixed> $c
     */
    private function guardar(int $tarjetaId, array $c): void
    {
        BaseDatos::enTransaccion(function () use ($tarjetaId, $c) {
            BaseDatos::ejecutar(
                'INSERT INTO tarjeta_cortes
                   (uuid, tarjeta_id, periodo_inicio, periodo_fin, fecha_corte,
                    fecha_limite_pago, plazo_financiamiento_meses, meses_si_paga_minimo,
                    saldo_anterior_cent, consumos_cent, pagos_cent,
                    intereses_cent, seguro_vida_cent, seguro_empleo_cent,
                    comisiones_cent, mora_cent, extra_saldo_cent, extra_cuota_cent,
                    saldo_final_cent, pago_minimo_cent,
                    pago_contado_cent, movimientos, estado, generado_en)
                 VALUES (UUID(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?,"cerrado",NOW())',
                [
                    $tarjetaId, $c['periodo_inicio'], $c['periodo_fin'], $c['fecha_corte'],
                    $c['fecha_limite_pago'],
                    $c['plazo_financiamiento_meses'], $c['meses_si_paga_minimo'],
                    $c['saldo_anterior_cent'], $c['consumos_cent'],
                    $c['pagos_cent'], $c['intereses_cent'], $c['seguro_vida_cent'],
                    $c['seguro_empleo_cent'], $c['comisiones_cent'],
                    // Lo del extrafinanciamiento, aparte: el estado de
                    // cuenta lo desglosa como linea propia.
                    $c['extra_saldo_cent'] ?? 0, $c['extra_cuota_cent'] ?? 0,
                    $c['saldo_final_cent'],
                    $c['pago_minimo_cent'], $c['pago_contado_cent'], $c['movimientos'],
                ]
            );

            $corteId = BaseDatos::ultimoId();

            // Los movimientos del periodo quedan amarrados a este corte:
            // sin esto, el mes siguiente volvería a sumarlos.
            BaseDatos::ejecutar(
                /* El mismo criterio que el calculo, palabra por palabra.
                   Si aqui se marcaran menos movimientos de los que se
                   sumaron, los no marcados volverian a entrar en el
                   corte siguiente y el cliente pagaria dos veces lo
                   mismo; si se marcaran mas, desaparecerian sin que
                   nadie los hubiera facturado. */
                "UPDATE tarjeta_movimientos
                    SET corte_id = ?
                  WHERE tarjeta_id = ? AND corte_id IS NULL
                    AND estado = 'liquidada'
                    AND DATE(creado_en) <= ?",
                [$corteId, $tarjetaId, $c['periodo_fin']]
            );

            /* Los cargos que genera el propio corte, como movimientos.
             *
             * Antes se sumaban al saldo y ahí se quedaban: el saldo
             * subía y ninguna línea lo explicaba. Para el cliente es un
             * importe aparecido; para la institución, un cargo sin
             * respaldo que hay que reconstruir a mano si lo reclaman.
             *
             * Van YA MARCADOS con este `corte_id`. Esa marca —y no la
             * fecha— es lo que impide que el corte siguiente los vuelva
             * a sumar, porque el cálculo filtra por `corte_id IS NULL`.
             * Sin marcarlos, el mes que viene se cobrarían otra vez. */
            /* Cada línea con SU referencia. `referencia` es única en la
               tabla, y poner la misma en las tres hacía que la primera
               entrara y la segunda reventara el corte a mitad, con el
               saldo ya actualizado y una sola línea de las tres. */
            $cargosDelCorte = [
                ['interes',  (int) ($c['intereses_cent'] ?? 0),
                 'Intereses del periodo', 'interes', 'INT'],
                ['comision', (int) ($c['seguro_vida_cent'] ?? 0),
                 'Seguro de vida sobre el saldo', 'seguro', 'SVID'],
                ['comision', (int) ($c['seguro_empleo_cent'] ?? 0),
                 'Seguro de desempleo sobre el saldo', 'seguro', 'SDES'],
            ];

            foreach ($cargosDelCorte as [$tipo, $importe, $concepto, $categoria, $sufijo]) {
                if ($importe <= 0) {
                    continue;
                }

                BaseDatos::ejecutar(
                    'INSERT INTO tarjeta_movimientos
                       (tarjeta_id, tipo, importe_cent, comercio, categoria,
                        referencia, estado, corte_id, creado_en)
                     VALUES (?,?,?,?,?,?,"liquidada",?,?)',
                    [
                        $tarjetaId,
                        $tipo,
                        $importe,
                        $concepto,
                        $categoria,
                        // La referencia lleva el corte: así se puede ir
                        // del movimiento al estado de cuenta que lo generó.
                        'CORTE-' . $corteId . '-' . $sufijo,
                        $corteId,
                        $c['fecha_corte'] . ' 23:59:59',
                    ]
                );
            }

            /* El saldo sale de los MOVIMIENTOS, no del arrastre.
             *
             * Hay dos maneras de saber cuánto debe una tarjeta: sumar
             * sus movimientos, o arrastrar el saldo anterior y sumarle
             * el periodo. Deberían dar lo mismo, y mientras lo den da
             * igual cuál se use.
             *
             * No lo daban. El saldo arrastrado parte de una deducción
             * hecha en el primer corte, y esa deducción carga con
             * cualquier error anterior para siempre: una tarjeta quedó
             * debiendo $23,541 cuando sus movimientos sumaban $11,801.
             *
             * Entre las dos manda la suma de los movimientos. Cada uno
             * existe, tiene fecha y comercio, y el cliente puede
             * señalarlos uno a uno; el saldo arrastrado es una cuenta
             * que nadie puede comprobar. Es además lo que verifica la
             * comprobación 10 del cuadre, así que atarse a ella impide
             * que el sistema se separe de su propia contabilidad.
             *
             * El corte ya insertó sus intereses y sus seguros como
             * movimientos, de modo que la suma los incluye. */
            $saldoReal = max(0, (int) BaseDatos::unValor(
                'SELECT COALESCE(SUM(CASE WHEN tipo IN ("pago","reverso")
                                          THEN -importe_cent ELSE importe_cent END), 0)
                   FROM tarjeta_movimientos
                  WHERE tarjeta_id = ? AND estado = "liquidada"',
                [$tarjetaId]
            ));

            BaseDatos::ejecutar(
                'UPDATE tarjetas
                    SET saldo_usado_cent = ?, ultimo_corte = ?,
                        proximo_corte = DATE_ADD(?, INTERVAL 1 MONTH)
                  WHERE id = ?',
                [$saldoReal, $c['fecha_corte'], $c['fecha_corte'], $tarjetaId]
            );

            /* Y el documento cierra en esa misma cifra. Un estado de
               cuenta que termina en un saldo distinto del que el
               cliente ve en su banca es una discusión asegurada, y la
               gana él. */
            if ($saldoReal !== (int) $c['saldo_final_cent']) {
                $pct  = max(0, Parametros::entero('tarjeta.pago_minimo_pct', 500));
                $piso = max(0, Parametros::entero('tarjeta.pago_minimo_piso_cent', 1000));

                $minimo = $saldoReal > 0
                    ? min($saldoReal, max((int) round($saldoReal * $pct / 10000), $piso))
                    : 0;

                BaseDatos::ejecutar(
                    'UPDATE tarjeta_cortes
                        SET saldo_final_cent = ?, pago_contado_cent = ?, pago_minimo_cent = ?
                      WHERE id = ?',
                    [$saldoReal, $saldoReal, $minimo, $corteId]
                );
            }

            // Y se le avisa al cliente, que es el punto de todo esto.
            $u = (int) BaseDatos::unValor('SELECT usuario_id FROM tarjetas WHERE id = ?', [$tarjetaId]);

            if ($u > 0) {
                /* `estado.tarjeta` y no `tarjeta.corte`.
                   El segundo dispara el correo generico del servicio de
                   avisos, que compone el mensaje con los datos sueltos
                   tal cual y le mandaba al cliente «Corte: 4 · Tarjeta:
                   1»: identificadores internos que no le dicen nada. El
                   corte manda el suyo, con el cuadre y el PDF. */
                (new ServicioNotificacion())->crear(
                    $u,
                    'estado.tarjeta',
                    'Su estado de cuenta está listo',
                    'El saldo al corte es de $' . number_format($c['saldo_final_cent'] / 100, 2)
                    . '. Su pago mínimo es de $' . number_format($c['pago_minimo_cent'] / 100, 2)
                    . ' y puede realizarlo hasta el '
                    . date('d/m/Y', strtotime((string) $c['fecha_limite_pago'])) . '.',
                    ['referencia' => 'Corte del '
                        . date('d/m/Y', strtotime((string) $c['fecha_corte']))],
                    'info'
                );

                $this->avisarCorte($corteId);
            }
        });
    }
}
