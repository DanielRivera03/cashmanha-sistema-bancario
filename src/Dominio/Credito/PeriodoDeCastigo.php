<?php

declare(strict_types=1);

namespace CashMan\Dominio\Credito;

/**
 * Cuánto tiempo pesa un atraso antes de poder pedir algo nuevo.
 *
 * DE DÓNDE SALEN LOS PLAZOS
 *
 * De la Ley de Regulación de los Servicios de Información sobre el
 * Historial de Crédito de las Personas (Decreto 695, 2011) y de la
 * NCB-022, que es la norma con la que se clasifican los deudores:
 *
 *   · Un dato negativo no puede permanecer en el historial más de
 *     TRES AÑOS desde que se incorporó. Ése es el techo: ningún
 *     castigo de esta casa puede durar más que lo que la ley permite
 *     que el dato exista.
 *
 *   · Si el cliente CANCELA TOTALMENTE la deuda, el dato negativo no
 *     permanece más de UN AÑO. Pagar entero acorta el castigo, y tiene
 *     todo el sentido: es la mejor prueba de que el problema terminó.
 *
 *   · Y si esa deuda cancelada no pasaba de medio salario mínimo del
 *     sector comercio y servicios, no permanece más de SEIS MESES. La
 *     ley no quiere que una deuda pequeña arrastre a alguien años.
 *
 *   · Los tramos de gravedad siguen a la NCB-022, que cuenta la mora
 *     en días calendario y trata distinto hasta 30 días, de 31 a 90,
 *     de 91 a 180, y de 181 en adelante.
 *
 * EL CASTIGO SE CUENTA DESDE QUE SE PUSO AL DÍA, NO DESDE EL ATRASO
 *
 * Contarlo desde que empezó el atraso premiaría a quien tarda más en
 * pagar: cuanto más se demore, más «castigo» habría corrido ya. Se
 * cuenta desde que quedó al corriente, que es el día en que el
 * problema se resolvió de verdad.
 *
 * QUÉ NO ES ESTO
 *
 * No es una lista negra ni un juicio sobre nadie. Es el tiempo que el
 * banco se da para volver a prestarle a quien tuvo un problema, y
 * tiene fecha de caducidad escrita: pasada esa fecha, el cliente opta
 * a todo como cualquier otro, sin que nadie tenga que levantarle nada
 * a mano.
 */
final class PeriodoDeCastigo
{
    /** Lo que la ley permite que un dato negativo permanezca. */
    public const TECHO_LEGAL_MESES = 36;

    /** Techo cuando la deuda quedó cancelada por completo. */
    public const TECHO_CANCELADA_MESES = 12;

    /** Y cuando además era pequeña. */
    public const TECHO_CANCELADA_MENOR_MESES = 6;

    /**
     * Medio salario mínimo del sector comercio y servicios, en
     * centavos. El salario de referencia se guarda en parámetros
     * porque cambia por decreto, no por código.
     */
    public const SALARIO_MINIMO_COMERCIO_CENT = 36500;

    /**
     * Los tramos, por el peor atraso alcanzado.
     *
     * El primero no castiga: hasta 30 días la propia norma de
     * clasificación considera al deudor en la mejor categoría, y
     * cerrarle la puerta por una cuota pagada con una semana de
     * retraso sería desproporcionado y espantaría a buenos clientes.
     *
     * @var list<array{dias:int, meses:int, motivo:string}>
     */
    private const TRAMOS = [
        ['dias' => 30,  'meses' => 0,
         'motivo' => 'Atraso leve, sin consecuencia sobre la elegibilidad.'],

        ['dias' => 90,  'meses' => 6,
         'motivo' => 'Atraso de más de un mes y hasta tres.'],

        ['dias' => 180, 'meses' => 12,
         'motivo' => 'Atraso de más de tres meses y hasta seis.'],

        ['dias' => PHP_INT_MAX, 'meses' => 24,
         'motivo' => 'Atraso de más de seis meses.'],
    ];

    /** Lo que suma haber llegado a cada etapa de cobranza. */
    private const POR_COBRANZA = [
        'preventiva'     => 0,
        'administrativa' => 0,
        'extrajudicial'  => 6,
        'judicial'       => 12,
        'sentencia'      => 12,
        'castigado'      => 24,
        'recuperado'     => 0,
    ];

    /**
     * Cuántos meses de castigo corresponden.
     *
     * @param int         $peorAtrasoDias Días del peor atraso alcanzado
     * @param string|null $etapaCobranza  La etapa más grave a la que llegó
     * @param bool        $creditoCastigado Si llegó a castigarse contablemente
     * @param bool        $deudaCancelada Si la deuda quedó liquidada por completo
     * @param int         $deudaCancelCent Cuánto se canceló, para el tramo de deuda menor
     */
    public static function meses(
        int $peorAtrasoDias,
        ?string $etapaCobranza = null,
        bool $creditoCastigado = false,
        bool $deudaCancelada = false,
        int $deudaCancelCent = 0
    ): int {
        if ($peorAtrasoDias <= 0 && $etapaCobranza === null && !$creditoCastigado) {
            return 0;
        }

        $meses = 0;

        foreach (self::TRAMOS as $t) {
            if ($peorAtrasoDias <= $t['dias']) {
                $meses = $t['meses'];
                break;
            }
        }

        /* Haber llegado al juzgado pesa por sí mismo, más allá de los
           días: son dos cosas distintas y las dos informan. */
        if ($etapaCobranza !== null) {
            $meses += self::POR_COBRANZA[$etapaCobranza] ?? 0;
        }

        if ($creditoCastigado) {
            $meses = max($meses, self::TECHO_LEGAL_MESES);
        }

        /* Los techos de la ley. Son techos, no sumandos: por encima de
           ellos el dato negativo ya no puede ni existir, así que el
           banco tampoco puede seguir teniéndolo en cuenta. */
        if ($deudaCancelada) {
            $limite = $deudaCancelCent > 0
                   && $deudaCancelCent <= (int) (self::SALARIO_MINIMO_COMERCIO_CENT / 2)
                ? self::TECHO_CANCELADA_MENOR_MESES
                : self::TECHO_CANCELADA_MESES;

            $meses = min($meses, $limite);
        }

        return min($meses, self::TECHO_LEGAL_MESES);
    }

    /**
     * Desde cuándo vuelve a poder optar a productos nuevos.
     *
     * @param \DateTimeImmutable $alDiaDesde Cuándo quedó al corriente
     */
    public static function hasta(
        \DateTimeImmutable $alDiaDesde,
        int $peorAtrasoDias,
        ?string $etapaCobranza = null,
        bool $creditoCastigado = false,
        bool $deudaCancelada = false,
        int $deudaCancelCent = 0
    ): ?\DateTimeImmutable {
        $meses = self::meses(
            $peorAtrasoDias, $etapaCobranza, $creditoCastigado,
            $deudaCancelada, $deudaCancelCent
        );

        if ($meses === 0) { return null; }

        return $alDiaDesde->modify('+' . $meses . ' months');
    }

    /**
     * Dicho con palabras, para el cliente y para quien atiende.
     *
     * Un «no» sin explicación en un banco es lo que convierte una
     * decisión defendible en un agravio. Esto es lo que se le enseña
     * al cliente, y es verdad comprobable: los días son los suyos y la
     * fecha está calculada, no inventada.
     */
    public static function explicacion(
        int $peorAtrasoDias,
        ?string $etapaCobranza = null,
        bool $creditoCastigado = false
    ): string {
        if ($creditoCastigado) {
            return 'Tuvo un crédito castigado contablemente. La ley permite que ese '
                 . 'dato pese hasta tres años, y ése es el plazo que se aplica.';
        }

        $base = '';

        foreach (self::TRAMOS as $t) {
            if ($peorAtrasoDias <= $t['dias']) { $base = $t['motivo']; break; }
        }

        $extra = match ($etapaCobranza) {
            'extrajudicial' => ' Además hubo requerimiento extrajudicial de pago.',
            'judicial'      => ' Además el expediente llegó a sede judicial.',
            'sentencia'     => ' Además hubo sentencia en su contra.',
            'castigado'     => ' Además el crédito llegó a castigarse.',
            default         => '',
        };

        return $base . $extra;
    }
}
