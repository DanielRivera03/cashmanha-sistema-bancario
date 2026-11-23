<?php

declare(strict_types=1);

namespace CashMan\Dominio\Plazo;

use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * El cálculo de un depósito a plazo fijo.
 *
 * TODO EN CENTAVOS ENTEROS
 *
 * No hay un solo `float` en el camino del dinero. Un interés calculado
 * con coma flotante se desvía un centavo cada tanto, y en un producto
 * que se renueva ocho veces esa desviación se acumula y aparece en un
 * cuadre meses después.
 *
 * LA BASE DE 360 DÍAS
 *
 * La banca calcula intereses sobre un año de 360 días, no 365. No es
 * una aproximación: es la convención del oficio, y usar 365 daría un
 * interés distinto del que dice el contrato.
 *
 * LA RETENCIÓN
 *
 * El artículo 159 del Código Tributario obliga a retener el 10% de los
 * intereses pagados por depósitos a personas domiciliadas en El
 * Salvador, con exención cuando el saldo no supera los US$25,000.
 *
 * Se aplica sobre los INTERESES, nunca sobre el capital. Retener sobre
 * el capital sería quedarse con dinero del cliente.
 */
final class CalculoPlazo
{
    /** @var array<string,string>|null */
    private static ?array $parametros = null;

    public static function parametro(string $clave, string $porDefecto = '0'): string
    {
        if (self::$parametros === null) {
            self::$parametros = [];

            foreach (BaseDatos::todas('SELECT clave, valor FROM plazo_parametros') as $p) {
                self::$parametros[(string) $p['clave']] = (string) $p['valor'];
            }
        }

        return self::$parametros[$clave] ?? $porDefecto;
    }

    /** Para que las pruebas puedan releer tras cambiar un parámetro. */
    public static function olvidarParametros(): void
    {
        self::$parametros = null;
    }

    public static function diasBase(): int
    {
        return max(1, (int) self::parametro('dias_base', '360'));
    }

    public static function retencionBp(): int
    {
        return (int) self::parametro('retencion_bp', '1000');
    }

    public static function exentoHastaCent(): int
    {
        return (int) self::parametro('retencion_exento_cent', '2500000');
    }

    public static function penalidadBp(): int
    {
        return (int) self::parametro('penalidad_bp', '5000');
    }

    // =================================================================
    // Tasas
    // =================================================================

    /**
     * La tasa que corresponde a un plazo y un monto.
     *
     * @return array{tasa_bp:int, dias_min:int, dias_max:int, monto_minimo_cent:int}|null
     */
    public static function tasaPara(int $dias, int $montoCent): ?array
    {
        $t = BaseDatos::unaFila(
            'SELECT tasa_bp, dias_min, dias_max, monto_minimo_cent
               FROM plazo_tasas
              WHERE activa = 1 AND ? BETWEEN dias_min AND dias_max
                AND monto_minimo_cent <= ?
              ORDER BY tasa_bp DESC
              LIMIT 1',
            [$dias, $montoCent]
        );

        if ($t === null) {
            return null;
        }

        return [
            'tasa_bp'           => (int) $t['tasa_bp'],
            'dias_min'          => (int) $t['dias_min'],
            'dias_max'          => (int) $t['dias_max'],
            'monto_minimo_cent' => (int) $t['monto_minimo_cent'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function tarifario(): array
    {
        return BaseDatos::todas(
            'SELECT * FROM plazo_tasas WHERE activa = 1 ORDER BY dias_min'
        );
    }

    // =================================================================
    // Intereses
    // =================================================================

    /**
     * El interés simple de un capital por unos días.
     *
     *     interés = capital × tasa × días / (10000 × base)
     *
     * Todo en enteros, y la división al final: dividir antes perdería
     * precisión en cada paso.
     *
     * Se trunca, no se redondea al alza. Cobrarle al banco un centavo
     * de más por depósito, multiplicado por miles de depósitos, es
     * dinero de verdad; y la convención bancaria es truncar.
     */
    public static function interes(int $capitalCent, int $tasaBp, int $dias): int
    {
        if ($capitalCent <= 0 || $tasaBp <= 0 || $dias <= 0) {
            return 0;
        }

        return intdiv($capitalCent * $tasaBp * $dias, 10000 * self::diasBase());
    }

    /**
     * La retención de renta sobre unos intereses.
     *
     * Exenta si el capital no supera el umbral. La ley habla de saldo
     * promedio; en un plazo fijo el saldo no se mueve, así que el
     * capital ES el promedio.
     */
    public static function retencion(int $interesCent, int $capitalCent): int
    {
        if ($interesCent <= 0) {
            return 0;
        }

        if ($capitalCent <= self::exentoHastaCent()) {
            return 0;
        }

        return intdiv($interesCent * self::retencionBp(), 10000);
    }

    /**
     * Lo que proyecta un depósito, dicho entero.
     *
     * Es lo que se le enseña al cliente ANTES de constituirlo: lo que
     * va a recibir, no una tasa que tenga que interpretar.
     *
     * @return array{
     *   capital_cent:int, tasa_bp:int, dias:int, interes_cent:int,
     *   retencion_cent:int, interes_neto_cent:int, total_cent:int,
     *   exento:bool, tasa_efectiva_bp:int
     * }
     */
    public static function proyeccion(int $capitalCent, int $tasaBp, int $dias): array
    {
        $interes   = self::interes($capitalCent, $tasaBp, $dias);
        $retencion = self::retencion($interes, $capitalCent);
        $neto      = $interes - $retencion;

        /* La tasa efectiva: lo que de verdad gana el cliente después de
           la retención, anualizada. Es el número que le sirve para
           comparar, y el que ningún banco enseña. */
        $efectiva = ($capitalCent > 0 && $dias > 0)
            ? intdiv($neto * 10000 * self::diasBase(), $capitalCent * $dias)
            : 0;

        return [
            'capital_cent'      => $capitalCent,
            'tasa_bp'           => $tasaBp,
            'dias'              => $dias,
            'interes_cent'      => $interes,
            'retencion_cent'    => $retencion,
            'interes_neto_cent' => $neto,
            'total_cent'        => $capitalCent + $neto,
            'exento'            => $retencion === 0 && $interes > 0,
            'tasa_efectiva_bp'  => $efectiva,
        ];
    }

    /**
     * Lo que se llevaría quien cancela antes de tiempo.
     *
     * Se le pagan los intereses DEVENGADOS hasta hoy, menos una
     * penalidad sobre ellos. El capital nunca se toca: penalizar el
     * capital sería quedarse con dinero del cliente, y eso no es una
     * penalidad, es una multa que ningún contrato de depósito permite.
     *
     * @return array{
     *   dias_transcurridos:int, interes_devengado_cent:int,
     *   penalidad_cent:int, interes_pagado_cent:int,
     *   retencion_cent:int, total_cent:int
     * }
     */
    public static function cancelacionAnticipada(
        int $capitalCent,
        int $tasaBp,
        string $fechaInicio,
        ?string $hoy = null
    ): array {
        $inicio = new \DateTimeImmutable($fechaInicio);
        $fin    = new \DateTimeImmutable($hoy ?? date('Y-m-d'));

        $dias = max(0, (int) $inicio->diff($fin)->days);

        $devengado = self::interes($capitalCent, $tasaBp, $dias);
        $penalidad = intdiv($devengado * self::penalidadBp(), 10000);
        $pagado    = $devengado - $penalidad;

        $retencion = self::retencion($pagado, $capitalCent);

        return [
            'dias_transcurridos'     => $dias,
            'interes_devengado_cent' => $devengado,
            'penalidad_cent'         => $penalidad,
            'interes_pagado_cent'    => $pagado,
            'retencion_cent'         => $retencion,
            'total_cent'             => $capitalCent + $pagado - $retencion,
        ];
    }

    /** Los días entre dos fechas, como los cuenta el contrato. */
    public static function dias(string $desde, string $hasta): int
    {
        $a = new \DateTimeImmutable($desde);
        $b = new \DateTimeImmutable($hasta);

        return max(0, (int) $a->diff($b)->days);
    }
}
