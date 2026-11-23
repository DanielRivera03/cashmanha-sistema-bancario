<?php

declare(strict_types=1);

namespace CashMan\Dominio\Cumplimiento;

/**
 * Los umbrales y plazos de la Ley Contra el Lavado de Dinero.
 *
 * POR QUÉ ESTÁN AQUÍ Y NO ESPARCIDOS
 *
 * Son números de la ley, no del banco. Si alguna vez cambian —y las
 * leyes cambian— tienen que cambiar en UN sitio, y ese sitio tiene que
 * decir de dónde salió cada uno. Un `10000` suelto dentro de una
 * consulta no se puede auditar ni defender: nadie recuerda, dos años
 * después, si era el umbral legal o algo que alguien puso a ojo.
 *
 * DE DÓNDE SALE CADA NÚMERO
 *
 * Artículo 9 de la LCLDA, con su reforma: las operaciones EN EFECTIVO,
 * individuales o múltiples, de un mismo cliente, que superen los
 * US$10,000.00 en un día o en un mes calendario, se informan a la
 * Unidad de Investigación Financiera. Por cualquier otro medio, el
 * umbral es US$25,000.00.
 *
 * El plazo de reporte es de cinco días hábiles contados a partir del
 * día siguiente de realizada la operación. Cuando lo que se reporta es
 * la acumulación de un mes, los cinco días corren desde el día
 * siguiente al cierre de ese mes calendario.
 *
 * Artículo 9-A: el reporte de operación sospechosa NO tiene umbral de
 * monto. Lo dispara la sospecha. El encargado de cumplimiento tiene
 * quince días hábiles para analizarla, prorrogables una sola vez por
 * otros quince previa solicitud a la UIF.
 *
 * ADVERTENCIA HONESTA
 *
 * Estos valores se tomaron de la ley y de las guías publicadas por la
 * UIF de la Fiscalía General de la República. Antes de operar de
 * verdad hay que cotejarlos contra el texto vigente: las leyes se
 * reforman y un umbral desactualizado produce exactamente el
 * incumplimiento que esta clase intenta evitar.
 */
final class UmbralesUIF
{
    /**
     * Efectivo: US$10,000.00, en un día o en un mes calendario.
     *
     * «Supere» es la palabra de la ley, así que el umbral se cruza
     * cuando el monto es MAYOR, no igual. Exactamente diez mil no es
     * reportable, y esa diferencia de un centavo es justamente la que
     * busca quien estructura sus operaciones.
     */
    public const EFECTIVO_CENT = 1000000;

    /** Por cualquier otro medio: US$25,000.00. */
    public const OTROS_MEDIOS_CENT = 2500000;

    /** Cinco días hábiles para remitir el reporte. */
    public const DIAS_HABILES_REPORTE = 5;

    /** Quince días hábiles para analizar una alerta. */
    public const DIAS_HABILES_ANALISIS = 15;

    /**
     * El umbral de fraccionamiento.
     *
     * Quien quiere evitar el reporte no deposita $12,000: deposita
     * $9,500 hoy y $9,500 mañana. Por eso se vigila la franja justo por
     * debajo del umbral legal, que por sí sola no reporta nada pero,
     * repetida, es la señal más clara que existe.
     *
     * El 80% no sale de la ley —la ley no define fraccionamiento con un
     * número— sino de la práctica de monitoreo: es la franja donde las
     * operaciones dejan de parecer casualidad.
     */
    public const FRACCIONAMIENTO_DESDE_CENT = 800000;

    /** Cuántas operaciones en esa franja levantan la señal. */
    public const FRACCIONAMIENTO_OPS = 2;

    /** En cuántos días se cuentan. */
    public const FRACCIONAMIENTO_DIAS = 7;

    /**
     * Cuánto puede exceder el movimiento real al perfil declarado.
     *
     * Nadie mueve exactamente lo que declaró, y alertar por un 10% de
     * diferencia sería producir ruido. Tres veces lo declarado ya no es
     * variación: es otra cosa.
     */
    public const PERFIL_FACTOR = 3;

    /** Y por debajo de esto no se alerta aunque el factor se cumpla. */
    public const PERFIL_PISO_CENT = 100000;

    /**
     * Una cuenta recién abierta que mueve mucho.
     *
     * Es un patrón clásico: se abre, se usa fuerte unos días y se
     * abandona. El banco todavía no conoce al cliente, así que la misma
     * cantidad pesa más que en alguien de diez años.
     */
    public const CUENTA_NUEVA_DIAS = 60;
    public const CUENTA_NUEVA_CENT = 500000;

    /** Lo que se considera movimiento alto en una persona expuesta. */
    public const PEP_MOVIMIENTO_CENT = 300000;

    // =================================================================
    // Días hábiles
    // =================================================================

    /**
     * Suma días hábiles a una fecha.
     *
     * Hábil es de lunes a viernes. Los asuetos de ley quedan fuera a
     * propósito: el calendario de asuetos cambia cada año y uno
     * incompleto daría una fecha límite EQUIVOCADA y más tardía que la
     * real, que en un plazo legal es el peor error posible.
     *
     * Contar de menos nunca perjudica: adelanta la fecha límite. Si se
     * carga el calendario de asuetos, este método es el único sitio que
     * hay que tocar.
     */
    public static function sumarHabiles(\DateTimeImmutable $desde, int $dias): \DateTimeImmutable
    {
        $fecha = $desde;
        $restan = max(0, $dias);

        while ($restan > 0) {
            $fecha = $fecha->modify('+1 day');

            if ((int) $fecha->format('N') <= 5) {
                $restan--;
            }
        }

        return $fecha;
    }

    /**
     * La fecha límite para remitir un reporte de una operación.
     *
     * Cinco días hábiles «a partir del día siguiente» de la operación:
     * el conteo arranca el día después, no el mismo.
     */
    public static function venceReporte(\DateTimeImmutable $operacion): \DateTimeImmutable
    {
        return self::sumarHabiles($operacion, self::DIAS_HABILES_REPORTE);
    }

    /**
     * La fecha límite del reporte de una acumulación mensual.
     *
     * Los cinco días corren desde el día siguiente al cierre del mes,
     * no desde la operación que cruzó el umbral.
     */
    public static function venceReporteMensual(int $anio, int $mes): \DateTimeImmutable
    {
        $cierre = (new \DateTimeImmutable())
            ->setDate($anio, $mes, 1)
            ->setTime(0, 0)
            ->modify('last day of this month');

        return self::sumarHabiles($cierre, self::DIAS_HABILES_REPORTE);
    }

    /** La fecha límite para analizar una alerta. */
    public static function venceAnalisis(?\DateTimeImmutable $desde = null): \DateTimeImmutable
    {
        return self::sumarHabiles(
            $desde ?? new \DateTimeImmutable(),
            self::DIAS_HABILES_ANALISIS
        );
    }

    // =================================================================
    // Nombres
    // =================================================================

    /**
     * Normaliza un nombre para cotejarlo contra las listas.
     *
     * Sin tildes, sin mayúsculas, sin dobles espacios y sin signos. Un
     * filtro que no encuentre «PEÑA» al buscar «Peña» no filtra nada, y
     * las listas internacionales llegan transliteradas de mil maneras.
     */
    public static function normalizarNombre(string $nombre): string
    {
        $n = mb_strtolower(trim($nombre), 'UTF-8');

        $n = strtr($n, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);

        $n = preg_replace('/[^a-z0-9\s]/', ' ', $n) ?? '';
        $n = preg_replace('/\s+/', ' ', $n) ?? '';

        return trim($n);
    }

    /**
     * ¿Dos nombres son la misma persona, a efectos de cotejo?
     *
     * Se exige que TODAS las partes significativas del nombre de la
     * lista aparezcan en el del cliente. Es deliberadamente estrecho:
     * una coincidencia de más obliga a un analista a descartar a un
     * inocente, y una lista que marca a media cartera se deja de mirar
     * en una semana.
     *
     * Lo que sí es amplio es el orden: «Ramírez López, José» y «José
     * Ramírez López» son la misma persona y ninguna lista se pone de
     * acuerdo en cómo escribirlo.
     */
    public static function nombresCoinciden(string $cliente, string $enLista): bool
    {
        $a = array_filter(
            explode(' ', self::normalizarNombre($cliente)),
            static fn(string $p): bool => strlen($p) > 2
        );

        $b = array_filter(
            explode(' ', self::normalizarNombre($enLista)),
            static fn(string $p): bool => strlen($p) > 2
        );

        /* Un nombre de una sola parte significativa no se coteja: daría
           cientos de coincidencias y ninguna útil. */
        if (count($b) < 2 || $a === []) {
            return false;
        }

        foreach ($b as $parte) {
            if (!in_array($parte, $a, true)) {
                return false;
            }
        }

        return true;
    }
}
