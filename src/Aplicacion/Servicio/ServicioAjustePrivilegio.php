<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\Amortizacion;
use CashMan\Dominio\Credito\CostosCredito;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

/**
 * Aplica el descuento de tasa a los créditos que YA están vivos.
 *
 * El descuento por categoría se aplicaba solo a lo que el cliente
 * contratara después de ascender. Sus créditos vigentes seguían con la
 * tasa con la que nacieron, mientras su pantalla le prometía «se
 * descuentan de la tasa de cualquier crédito que se le otorgue».
 *
 * Reglas de este ajuste, y el porqué de cada una:
 *
 *  · **Solo baja, nunca sube.** Si el cliente desciende de categoría,
 *    el crédito se queda con la tasa que se firmó. Subírsela sería
 *    cambiarle el contrato en su contra por algo que él no pidió.
 *
 *  · **El plazo no se toca.** Esto no es una reestructuración: no hay
 *    dificultad de pago que resolver. Baja la tasa, baja la cuota, y
 *    el crédito termina el mismo mes que iba a terminar.
 *
 *  · **Solo se rehacen las cuotas NO pagadas.** Lo ya pagado es
 *    historia y tiene sus asientos; recalcularlo sería reescribir el
 *    pasado contable.
 *
 *  · **La cuota lleva seguros.** `cuotas.total_cent` es la cuota de
 *    amortización MÁS el seguro de vida y el de desempleo, y las
 *    primas se recalculan sobre el saldo de cada periodo. Escribir
 *    ahí solo la cuota deja la fila peleada consigo misma.
 *
 *  · **No mueve el libro mayor.** La tabla de amortización es un plan,
 *    no un asiento: el interés se reconoce cuando la cuota se paga. Por
 *    eso cambiar cuotas futuras no descuadra nada.
 *
 *  · **Es idempotente.** Un crédito que ya está en su tasa —o por
 *    debajo— no se toca. Se puede correr todos los días.
 */
final class ServicioAjustePrivilegio
{
    public function __construct(private ?ServicioSegmento $segmento = null)
    {
        $this->segmento ??= new ServicioSegmento();
    }

    /**
     * Qué créditos vigentes tienen una tasa peor que la que les toca.
     *
     * No cambia nada. Es lo que se mira antes de aplicar, porque esto
     * le toca la cuota a gente real.
     *
     * @return list<array<string,mixed>>
     */
    public function pendientes(): array
    {
        $creditos = BaseDatos::todas(
            'SELECT c.id, c.folio, c.tasa_bp, c.cuota_cent, c.saldo_capital_cent,
                    c.usuario_id, u.usuario, u.segmento,
                    p.nombre AS producto, p.tasa_min_bp,
                    (SELECT COUNT(*) FROM cuotas q
                      WHERE q.credito_id = c.id
                        AND q.estado NOT IN ("pagada","condonada")) AS por_pagar
               FROM creditos c
               JOIN usuarios  u ON u.id = c.usuario_id
               JOIN productos p ON p.id = c.producto_id
              WHERE c.estado = "vigente"
              ORDER BY c.id'
        );

        $out = [];

        foreach ($creditos as $c) {
            $plan = $this->planear($c);

            if ($plan !== null) {
                $out[] = $plan;
            }
        }

        return $out;
    }

    /**
     * Aplica el ajuste a un crédito.
     *
     * @param  int      $creditoId
     * @param  int|null $actorId   Quién lo corrió; NULL si fue el proceso.
     * @return array<string,mixed>|null  Lo aplicado, o null si no procedía.
     */
    public function aplicar(int $creditoId, ?int $actorId = null): ?array
    {
        return BaseDatos::enTransaccion(function () use ($creditoId, $actorId): ?array {
            $c = BaseDatos::unaFila(
                'SELECT c.*, u.usuario, u.segmento, p.nombre AS producto, p.tasa_min_bp
                   FROM creditos c
                   JOIN usuarios  u ON u.id = c.usuario_id
                   JOIN productos p ON p.id = c.producto_id
                  WHERE c.id = ? AND c.estado = "vigente"
                  FOR UPDATE',
                [$creditoId]
            );

            if ($c === null) {
                return null;
            }

            $plan = $this->planear($c);

            if ($plan === null) {
                return null;
            }

            /* Las cuotas que faltan, con su primer vencimiento. Se
               conserva el calendario: el cliente no tiene por qué
               cambiar de día de pago porque le bajaran la tasa. */
            $pendientes = BaseDatos::todas(
                'SELECT id, numero, fecha_vencimiento FROM cuotas
                  WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")
                  ORDER BY numero ASC',
                [$creditoId]
            );

            if ($pendientes === []) {
                return null;
            }

            $saldo = Monto::deCentavos((int) $c['saldo_capital_cent']);
            $tabla = (new Amortizacion($saldo, $plan['tasa_nueva_bp'], count($pendientes)))
                ->tabla(new \DateTimeImmutable((string) $pendientes[0]['fecha_vencimiento']));

            /* Se ACTUALIZA cada cuota pendiente en lugar de borrarlas y
               volver a insertarlas. Sus identificadores pueden estar
               referenciados —un recibo, un plan de cobro, un enlace que
               el cliente guardó— y renumerarlo todo rompería eso para
               ahorrarse una línea. */
            $rehechas = 0;

            /* Las primas se calculan sobre el saldo con el que ARRANCA
               cada periodo —es el riesgo que cubre la póliza ese mes, y
               por eso van bajando cuota a cuota—, así que al cambiar la
               tabla cambian ellas también. Mismo cálculo que al
               originar el crédito; si se hiciera distinto aquí, la
               misma cuota valdría una cosa recién otorgada y otra
               después de un ajuste. */
            $saldoApertura = $saldo->centavos;

            foreach ($pendientes as $i => $q) {
                $f = $tabla[$i] ?? null;

                if ($f === null) {
                    break;
                }

                $seguroVida   = CostosCredito::seguroVida($saldoApertura);
                $seguroEmpleo = CostosCredito::seguroEmpleo($saldoApertura);

                BaseDatos::ejecutar(
                    'UPDATE cuotas
                        SET capital_cent = ?, interes_cent = ?,
                            seguro_vida_cent = ?, seguro_empleo_cent = ?,
                            total_cent = ?, saldo_posterior_cent = ?
                      WHERE id = ?',
                    [
                        $f['capital']->centavos,
                        $f['interes']->centavos,
                        $seguroVida,
                        $seguroEmpleo,
                        /* El total es lo que paga ese mes: cuota más las
                           dos primas. Dejarlas fuera haría que el importe
                           mostrado no coincidiera con el cargo, que es
                           exactamente lo que pasó la primera vez. */
                        $f['cuota']->centavos + $seguroVida + $seguroEmpleo,
                        $f['saldo']->centavos,
                        (int) $q['id'],
                    ]
                );

                $saldoApertura = $f['saldo']->centavos;
                $rehechas++;
            }

            BaseDatos::ejecutar(
                'UPDATE creditos SET tasa_bp = ?, cuota_cent = ? WHERE id = ?',
                [$plan['tasa_nueva_bp'], $plan['cuota_nueva_cent'], $creditoId]
            );

            BaseDatos::ejecutar(
                'INSERT INTO credito_ajustes_tasa
                   (credito_id, tasa_anterior_bp, tasa_nueva_bp,
                    cuota_anterior_cent, cuota_nueva_cent, ahorro_cent,
                    segmento, regla_version, descuento_bp, actor_id, cuotas_rehechas)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $creditoId,
                    $plan['tasa_anterior_bp'],
                    $plan['tasa_nueva_bp'],
                    $plan['cuota_anterior_cent'],
                    $plan['cuota_nueva_cent'],
                    $plan['ahorro_cent'],
                    $plan['segmento'],
                    $plan['regla_version'],
                    $plan['descuento_bp'],
                    $actorId,
                    $rehechas,
                ]
            );

            Auditoria::registrar(
                'credito.tasa_ajustada_por_segmento',
                Auditoria::EXITO,
                $actorId ?? 0,
                'credito',
                (string) $creditoId,
                [
                    'folio'    => (string) $c['folio'],
                    'de'       => $plan['tasa_anterior_bp'],
                    'a'        => $plan['tasa_nueva_bp'],
                    'segmento' => $plan['segmento'],
                ]
            );

            (new ServicioNotificacion())->crear(
                (int) $c['usuario_id'],
                'credito.tasa',
                'Le bajamos la tasa de su ' . $c['producto'],
                /* Se dice primero lo que baja CADA MES, que es lo que el
                   cliente va a ver en su cuenta, y el total después como
                   acompañamiento.

                   Antes decía «deja de pagar $582.42 en lo que le
                   resta», y eso se lee como si la deuda se hubiera
                   reducido. No se redujo: el capital es exactamente el
                   mismo y lo que baja es el interés. Una cifra que el
                   cliente puede entender al revés es peor que no darla. */
                sprintf(
                    'Por su categoría %s le aplicamos un descuento de %s puntos en su '
                    . 'crédito %s. La tasa baja de %s%% a %s%%, así que su cuota mensual '
                    . 'pasa de %s a %s: paga %s menos cada mes. El plazo no cambia, le '
                    . 'siguen quedando %d cuotas, y el saldo que debe es el mismo; lo que '
                    . 'se reduce son los intereses, unos %s menos en total hasta terminarlo.',
                    $plan['segmento'],
                    number_format($plan['descuento_bp'] / 100, 2),
                    $c['folio'],
                    number_format($plan['tasa_anterior_bp'] / 100, 2),
                    number_format($plan['tasa_nueva_bp'] / 100, 2),
                    Monto::deCentavos($plan['cuota_anterior_cent'])->formateado(),
                    Monto::deCentavos($plan['cuota_nueva_cent'])->formateado(),
                    Monto::deCentavos(
                        $plan['cuota_anterior_cent'] - $plan['cuota_nueva_cent']
                    )->formateado(),
                    $plan['por_pagar'],
                    Monto::deCentavos($plan['ahorro_cent'])->formateado()
                )
            );

            $plan['cuotas_rehechas'] = $rehechas;

            return $plan;
        });
    }

    /**
     * Qué pasaría con este crédito, sin tocarlo.
     *
     * Devuelve null cuando no procede: sin descuento, ya ajustado, sin
     * cuotas por pagar o sin saldo.
     *
     * @param  array<string,mixed> $c Fila del crédito con su usuario y producto.
     * @return array<string,mixed>|null
     */
    private function planear(array $c): ?array
    {
        $usuarioId = (int) $c['usuario_id'];
        $descuento = (int) ($this->segmento->beneficio($usuarioId, 'tasa_descuento_bp') ?? 0);

        if ($descuento <= 0) {
            return null;
        }

        $actual = (int) $c['tasa_bp'];
        $minimo = (int) ($c['tasa_min_bp'] ?? 0);

        // Nunca por debajo del mínimo autorizado del producto: el
        // beneficio es comercial, no una licencia para salirse del rango.
        $nueva = max($minimo, $actual - $descuento);

        if ($nueva >= $actual) {
            return null;   // ya está en su tasa, o mejor
        }

        $porPagar = (int) ($c['por_pagar'] ?? 0);

        if ($porPagar === 0) {
            $porPagar = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM cuotas
                  WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")',
                [(int) $c['id']]
            );
        }

        $saldo = (int) $c['saldo_capital_cent'];

        if ($porPagar === 0 || $saldo <= 0) {
            return null;
        }

        $cuotaAnterior = (int) $c['cuota_cent'];

        $cuotaNueva = (new Amortizacion(Monto::deCentavos($saldo), $nueva, $porPagar))
            ->cuota()->centavos;

        /* El ahorro en INTERESES sobre lo que le queda. El capital no
           cambia —debe lo mismo—, así que toda la diferencia entre la
           cuota vieja y la nueva es interés que ya no se cobra.

           Se mide sobre las cuotas pendientes: incluir lo ya pagado
           daría una cifra que el cliente no va a ver nunca. */
        $ahorro = max(0, ($cuotaAnterior - $cuotaNueva) * $porPagar);

        return [
            'credito_id'          => (int) $c['id'],
            'folio'               => (string) $c['folio'],
            'usuario'             => (string) $c['usuario'],
            'producto'            => (string) $c['producto'],
            'segmento'            => (string) $c['segmento'],
            'regla_version'       => \CashMan\Dominio\Cliente\Segmento::VERSION,
            'descuento_bp'        => $descuento,
            'tasa_anterior_bp'    => $actual,
            'tasa_nueva_bp'       => $nueva,
            'cuota_anterior_cent' => $cuotaAnterior,
            'cuota_nueva_cent'    => $cuotaNueva,
            'ahorro_cent'         => $ahorro,
            'por_pagar'           => $porPagar,
            'topado'              => $nueva === $minimo && ($actual - $descuento) < $minimo,
        ];
    }
}
