<?php
declare(strict_types=1);

namespace CashMan\Dominio\Credito;

use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Parametros;

/**
 * Recargo por mora.
 *
 * Una sola regla, en un solo sitio. Antes había dos que no coincidían:
 *
 *  · `Amortizacion::calcularMora()` cobraba un 24 % anual proporcional
 *    al saldo vencido, y era la que se aplicaba al recibir un pago.
 *  · El contrato modelo —y el sistema anterior— fijan una penalización
 *    DIARIA de hasta $5.99 por cuota atrasada, sin relación con el
 *    monto.
 *
 * Mandaba el código, pero quien tiene la razón es el contrato: es lo que
 * el cliente firmó. Con dos cálculos distintos, lo que se le cobraba al
 * pagar no coincidía con lo que la cartera decía que debía.
 *
 * La cláusula dice "hasta la cantidad de CINCO PUNTO NOVENTA Y NUEVE
 * DÓLARES" diarios: es un máximo, no una cuota fija, así que el cargo
 * admite tope por cuota.
 *
 * Todos los números salen de `parametros`, de modo que Gerencia los
 * cambia sin tocar código ni volver a desplegar.
 */
final class Mora
{
    /** Cláusula penal del contrato: $5.99 por día. */
    public const CARGO_DIARIO_POR_DEFECTO = 599;

    /**
     * Días de atraso que generan cargo.
     *
     * El contrato concede diez días de gracia ("dentro de los diez días
     * siguientes a la fecha estipulada"), pero el sistema anterior
     * cobraba desde el primer día. El valor por defecto reproduce lo que
     * el sistema hacía; poner 10 aplica el contrato al pie de la letra.
     */
    public static function diasCobrables(int $diasAtraso): int
    {
        if ($diasAtraso <= 0) {
            return 0;
        }

        $gracia = max(0, Parametros::entero('mora.dias_gracia', 0));

        return max(0, $diasAtraso - $gracia);
    }

    /**
     * Recargo acumulado para una cuota.
     *
     * @param Monto $saldoVencido Lo que queda por pagar de esa cuota
     * @param int   $diasAtraso   Días transcurridos desde el vencimiento
     */
    public static function calcular(Monto $saldoVencido, int $diasAtraso): Monto
    {
        if (!$saldoVencido->esPositivo()) {
            return Monto::cero();
        }

        $dias = self::diasCobrables($diasAtraso);
        if ($dias === 0) {
            return Monto::cero();
        }

        $diario = max(0, Parametros::entero('mora.cargo_diario_cent', self::CARGO_DIARIO_POR_DEFECTO));
        if ($diario === 0) {
            return Monto::cero();
        }

        return Monto::deCentavos(self::aplicarTope($dias * $diario, $saldoVencido->centavos));
    }

    /** Cargo de un solo día, para el devengo diario. */
    public static function cargoDeUnDia(): int
    {
        return max(0, Parametros::entero('mora.cargo_diario_cent', self::CARGO_DIARIO_POR_DEFECTO));
    }

    /**
     * Recorta el recargo a los topes vigentes.
     *
     * Dos límites, y se aplica el menor:
     *
     *  1. El tope por cuota que configure la institución, si lo hay.
     *  2. El propio saldo vencido. Que el recargo supere a la deuda que
     *     lo origina es desproporcionado y, con plazos largos de atraso,
     *     es exactamente lo que pasaría: 600 días por $5.99 son $3,594
     *     sobre una cuota que podría ser de $155.
     */
    public static function aplicarTope(int $cargoCent, int $saldoVencidoCent): int
    {
        $tope = max(0, Parametros::entero('mora.tope_por_cuota_cent', 0));

        if ($tope > 0) {
            $cargoCent = min($cargoCent, $tope);
        }

        return max(0, min($cargoCent, $saldoVencidoCent));
    }
}
