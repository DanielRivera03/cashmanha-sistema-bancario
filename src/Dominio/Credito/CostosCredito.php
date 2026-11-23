<?php
declare(strict_types=1);

namespace CashMan\Dominio\Credito;

use CashMan\Infraestructura\Parametros;

/**
 * Seguro de deuda y gastos administrativos de un crédito.
 *
 * Son los tramos del sistema anterior, tal como estaban en
 * `vista/js/calculocuotamensualclientes.js` y
 * `vista/js/calculocuotamensualhipotecasclientes.js`: dos cargos fijos
 * que dependen del monto solicitado y que el contrato detalla al
 * cliente. El desembolso no es el monto del crédito, sino el monto menos
 * los gastos administrativos.
 *
 * Estaban en JavaScript, es decir, en el navegador: cualquiera podía
 * abrir la consola y cambiarlos antes de enviar el formulario. Aquí
 * viven en el servidor y el navegador solo los muestra.
 *
 * Todo en centavos enteros, como el resto del sistema.
 */
final class CostosCredito
{
    /**
     * Tramos de crédito personal y de consumo.
     *
     * Cada entrada es [monto_máximo_cent, seguro_cent, gastos_cent]. El
     * monto máximo es exclusivo; la última entrada usa PHP_INT_MAX.
     */
    private const TRAMOS_PERSONAL = [
        [   30000,    0,    0],  // menos de $300
        [   50000,  195,    0],  // $300 – $499.99
        [  100000,  395,  200],  // $500 – $999.99
        [  250000,  695,  600],  // $1,000 – $2,499.99
        [  950000,  795, 1200],  // $2,500 – $9,499.99
        [PHP_INT_MAX, 1395, 1800],  // $9,500 en adelante
    ];

    /**
     * Tramos de crédito hipotecario.
     *
     * Nota sobre una corrección: el original tenía el tramo de
     * $50,000–$75,000 escrito como `MontoCredito > 50000 && MontoCredito
     * <= 50000`, una condición que no puede cumplirse nunca. En la
     * práctica esos montos caían en el tramo siguiente y pagaban
     * $61.50 / $154.99 en lugar de los $41.50 / $114.99 que el propio
     * comentario del código decía que les tocaban. Aquí se aplica el
     * tramo como estaba previsto.
     */
    private const TRAMOS_HIPOTECARIO = [
        [ 3000000,  3550, 11099],  // menos de $30,000: se trata como el primer tramo
        [ 5000000,  3550, 11099],  // $30,000 – $50,000
        [ 7500000,  4150, 11499],  // $50,000.01 – $75,000
        [15000000,  6150, 15499],  // $75,000.01 – $150,000
        [50000000, 14050, 35499],  // $150,000.01 – $500,000
        [PHP_INT_MAX, 54050, 95499],  // más de $500,000
    ];

    /** Proporción máxima del valor que se financia en un hipotecario. */
    public const FINANCIAMIENTO_HIPOTECARIO = 0.90;

    /**
     * Seguro de vida sobre saldo deudor, para un mes.
     *
     * Si el deudor fallece, la aseguradora cancela lo que quede y la
     * deuda no pasa a la familia. Es obligatorio y el contrato obliga a
     * mantener la póliza vigente durante todo el plazo.
     *
     * Se cobra sobre el SALDO, no sobre el monto original: baja conforme
     * se amortiza. Cobrarlo sobre el monto inicial haría pagar en la
     * última cuota lo mismo que en la primera, por un riesgo que ya
     * casi no existe.
     *
     * @param int $saldoCent Saldo al inicio del periodo
     */
    public static function seguroVida(int $saldoCent, bool $tarjeta = false): int
    {
        return self::porMillar(
            $saldoCent,
            $tarjeta ? 'seguro.vida_tarjeta_por_mil_cent' : 'seguro.vida_por_mil_cent',
            $tarjeta ? 120 : 90
        );
    }

    /**
     * Seguro de desempleo, para un mes.
     *
     * Cubre un número de cuotas si el deudor pierde el empleo de forma
     * involuntaria. Misma base que el de vida: el saldo.
     */
    public static function seguroEmpleo(int $saldoCent, bool $tarjeta = false): int
    {
        return self::porMillar(
            $saldoCent,
            $tarjeta ? 'seguro.empleo_tarjeta_por_mil_cent' : 'seguro.empleo_por_mil_cent',
            $tarjeta ? 140 : 110
        );
    }

    /**
     * Prima mensual a partir de una tarifa por cada $1,000 de saldo.
     *
     * Con saldo cero no hay prima: una cuota ya saldada no asegura nada.
     * Con saldo positivo se aplica un mínimo, porque la aseguradora cobra
     * la póliza aunque el saldo sea de diez dólares.
     */
    private static function porMillar(int $saldoCent, string $parametro, int $porDefecto): int
    {
        if ($saldoCent <= 0) {
            return 0;
        }

        $tarifa = Parametros::entero($parametro, $porDefecto);
        if ($tarifa <= 0) {
            return 0;
        }

        // saldo (centavos) / 100000 = millares de dólares.
        $prima = (int) round($saldoCent * $tarifa / 100000);
        $minimo = Parametros::entero('seguro.minimo_cent', 50);

        return max($prima, $minimo);
    }

    /**
     * @return array{seguro_cent:int, gastos_cent:int, desembolso_cent:int,
     *               financiable_cent:int}
     */
    public static function para(string $tipoProducto, int $montoCent, string $codigo = ''): array
    {
        $hipotecario = str_contains(mb_strtoupper($codigo), 'HIP');

        $tramos = $hipotecario ? self::TRAMOS_HIPOTECARIO : self::TRAMOS_PERSONAL;

        $seguro = 0;
        $gastos = 0;

        foreach ($tramos as [$tope, $s, $g]) {
            if ($montoCent < $tope) {
                $seguro = $s;
                $gastos = $g;
                break;
            }
        }

        // En hipotecario no se desembolsa el valor completo: se financia
        // hasta el 90 % y de ahí salen los gastos.
        $financiable = $hipotecario
            ? (int) round($montoCent * self::FINANCIAMIENTO_HIPOTECARIO)
            : $montoCent;

        return [
            'seguro_cent'      => $seguro,
            'gastos_cent'      => $gastos,
            // Nunca negativo: con montos pequeños los gastos podrían
            // superar lo financiable y el original ya lo acotaba a cero.
            'desembolso_cent'  => max(0, $financiable - $gastos),
            'financiable_cent' => $financiable,
        ];
    }
}
