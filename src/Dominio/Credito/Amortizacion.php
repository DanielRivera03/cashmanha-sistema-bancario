<?php
declare(strict_types=1);

namespace CashMan\Dominio\Credito;

use CashMan\Dominio\Comun\Monto;

/**
 * Tabla de amortización — sistema francés (cuota constante).
 *
 * El sistema anterior calculaba el capital con una división simple
 * (`montocredito / plazocredito`) dentro de un trigger de MySQL, lo
 * que daba dos problemas: la cuota no incluía intereses de forma
 * coherente, y el redondeo acumulaba un desfase que terminó obligando
 * al parche de DECIMAL(9,2) a DECIMAL(15,6) documentado en el propio
 * repositorio.
 *
 * Aquí todo se calcula en centavos enteros y la última cuota absorbe
 * el residuo, de modo que la suma de las cuotas es EXACTAMENTE el
 * total a pagar. Nunca falta ni sobra un centavo.
 */
final class Amortizacion
{
    /**
     * @param Monto $monto   Capital solicitado
     * @param int   $tasaBp  Tasa nominal ANUAL en puntos base (1800 = 18%)
     * @param int   $plazo   Número de cuotas mensuales
     */
    public function __construct(
        private Monto $monto,
        private int $tasaBp,
        private int $plazo
    ) {
        if ($plazo < 1 || $plazo > 480) {
            throw new \InvalidArgumentException('El plazo debe estar entre 1 y 480 meses.');
        }
        if ($tasaBp < 0 || $tasaBp > 20000) {
            throw new \InvalidArgumentException('Tasa fuera de rango.');
        }
        if (!$monto->esPositivo()) {
            throw new \InvalidArgumentException('El monto debe ser positivo.');
        }
    }

    /**
     * Cuota fija del sistema francés:
     *
     *     C = P · i / (1 − (1+i)^−n)
     *
     * Con tasa cero degenera en P/n, que es el reparto simple.
     */
    public function cuota(): Monto
    {
        if ($this->tasaBp === 0) {
            return Monto::deCentavos((int) ceil($this->monto->centavos / $this->plazo));
        }

        $i = $this->tasaBp / 10000 / 12;
        $f = $this->monto->centavos * $i / (1 - (1 + $i) ** -$this->plazo);

        // Se redondea hacia arriba: así la última cuota ajusta hacia
        // abajo y el cliente nunca paga de más por el redondeo.
        return Monto::deCentavos((int) ceil($f));
    }

    /**
     * Genera la tabla completa.
     *
     * @return array<int,array{numero:int,capital:Monto,interes:Monto,cuota:Monto,saldo:Monto,vencimiento:string}>
     */
    public function tabla(?\DateTimeImmutable $primerVencimiento = null): array
    {
        $cuota  = $this->cuota();
        $i      = $this->tasaBp / 10000 / 12;
        $saldo  = $this->monto->centavos;
        $inicio = $primerVencimiento ?? (new \DateTimeImmutable('first day of next month'));
        $diaPref = (int) $inicio->format('j');

        $filas = [];

        for ($n = 1; $n <= $this->plazo; $n++) {
            $interes = $i > 0 ? (int) round($saldo * $i, 0, PHP_ROUND_HALF_EVEN) : 0;
            $capital = $cuota->centavos - $interes;

            // Última cuota: absorbe el residuo para que el saldo cierre
            // exactamente en cero.
            if ($n === $this->plazo || $capital >= $saldo) {
                $capital    = $saldo;
                $cuotaFinal = $capital + $interes;
            } else {
                $cuotaFinal = $cuota->centavos;
            }

            $saldo -= $capital;

            // Vencimiento mensual respetando el día, sin desbordar en
            // los meses cortos (el 31 pasa al 28/29/30 según el mes).
            $base  = $inicio->modify('first day of +' . ($n - 1) . ' months');
            $venc  = $base->setDate(
                (int) $base->format('Y'),
                (int) $base->format('n'),
                min($diaPref, (int) $base->format('t'))
            );

            $filas[] = [
                'numero'      => $n,
                'capital'     => Monto::deCentavos($capital),
                'interes'     => Monto::deCentavos($interes),
                'cuota'       => Monto::deCentavos($cuotaFinal),
                'saldo'       => Monto::deCentavos(max(0, $saldo)),
                'vencimiento' => $venc->format('Y-m-d'),
            ];

            if ($saldo <= 0) {
                break;
            }
        }

        return $filas;
    }

    /** @return array<string,Monto|int|float> */
    public function resumen(): array
    {
        $tabla    = $this->tabla();
        $interes  = 0;
        $total    = 0;

        foreach ($tabla as $f) {
            $interes += $f['interes']->centavos;
            $total   += $f['cuota']->centavos;
        }

        return [
            'capital'        => $this->monto,
            'interes_total'  => Monto::deCentavos($interes),
            'total_pagar'    => Monto::deCentavos($total),
            'cuota'          => $this->cuota(),
            'cuotas'         => count($tabla),
            'tasa_anual'     => $this->tasaBp / 100,
            // Costo efectivo: cuánto se paga de más sobre el capital.
            'costo_pct'      => $this->monto->centavos > 0
                ? round(($interes / $this->monto->centavos) * 100, 2) : 0.0,
        ];
    }

    /**
     * Capacidad de pago.
     *
     * Regla estándar: la cuota no debe superar el 40% del ingreso
     * disponible (ingreso menos compromisos ya existentes).
     *
     * @return array{aprueba:bool, relacion:float, maximo:Monto, motivo:string}
     */
    public static function evaluarCapacidad(
        Monto $ingresoMensual,
        Monto $compromisos,
        Monto $cuotaPropuesta,
        float $maxRelacion = 0.40
    ): array {
        $disponible = $ingresoMensual->menos($compromisos);

        if (!$disponible->esPositivo()) {
            return [
                'aprueba'  => false,
                'relacion' => 1.0,
                'maximo'   => Monto::cero(),
                'motivo'   => 'Los compromisos actuales superan el ingreso declarado.',
            ];
        }

        $maximo   = $disponible->por($maxRelacion);
        $relacion = $cuotaPropuesta->centavos / $disponible->centavos;

        return [
            'aprueba'  => $cuotaPropuesta->menorQue($maximo) || $cuotaPropuesta->igualA($maximo),
            'relacion' => round($relacion, 4),
            'maximo'   => $maximo,
            'motivo'   => $cuotaPropuesta->mayorQue($maximo)
                ? 'La cuota representa el ' . round($relacion * 100, 1)
                  . '% del ingreso disponible (máximo ' . round($maxRelacion * 100) . '%).'
                : 'Capacidad de pago suficiente.',
        ];
    }

    /**
     * Mora por días de atraso.
     *
     * Delega en `Mora`, que es donde vive la regla única.
     *
     * Aquí se calculaba un 24 % anual proporcional al saldo, y eso no
     * era lo pactado: el contrato fija una penalización diaria de hasta
     * $5.99 por cuota atrasada. Mientras convivieron las dos fórmulas,
     * lo que se cobraba al recibir un pago no coincidía con lo que la
     * cartera reportaba como adeudado.
     *
     * Se conserva el método —y el tercer parámetro, ya sin efecto— para
     * no romper a quien lo llame; la cuenta la hace `Mora`.
     *
     * @deprecated Use Mora::calcular()
     */
    public static function calcularMora(Monto $saldoVencido, int $diasAtraso, int $tasaMoraBp = 2400): Monto
    {
        return Mora::calcular($saldoVencido, $diasAtraso);
    }
}
