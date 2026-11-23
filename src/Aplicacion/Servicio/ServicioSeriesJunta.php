<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * Las series temporales del tablero de la Junta.
 *
 * Separado de `ServicioIndicadoresJunta` porque responde otra
 * pregunta: aquél dice cómo está el banco HOY, éste dice cómo llegó
 * hasta aquí. Mezclarlos daría una clase que hace dos cosas y se lee
 * mal.
 *
 * EL PERIODO LO ELIGE QUIEN MIRA
 *
 * Día, semana, mes o año. No es un adorno: la misma serie cuenta
 * historias distintas según el grano. Por día se ve el pico de un
 * viernes de pago; por mes, la tendencia. Un consejo necesita las dos
 * cosas en momentos distintos.
 *
 * SOBRE EL SALDO ACUMULADO
 *
 * Las captaciones y la cartera no son sumas del periodo: son un SALDO
 * a una fecha. Para dibujarlas hay que acumular todo lo anterior y no
 * solo lo que pasó en ese tramo. Sumar el movimiento del mes daría
 * una línea que sube y baja alrededor de cero en vez de la curva del
 * saldo, que es lo que se quiere ver.
 */
final class ServicioSeriesJunta
{
    /** Los granos admitidos, con cómo se agrupan y cómo se rotulan. */
    private const PERIODOS = [
        'dia' => [
            'formato'  => '%Y-%m-%d',
            'etiqueta' => 'Día',
            'rotulo'   => '%d/%m',
            'atras'    => '-30 days',
            'paso'     => 'P1D',
        ],
        'semana' => [
            'formato'  => '%x-S%v',
            'etiqueta' => 'Semana',
            'rotulo'   => 'Sem %v',
            'atras'    => '-26 weeks',
            'paso'     => 'P1W',
        ],
        'mes' => [
            'formato'  => '%Y-%m',
            'etiqueta' => 'Mes',
            'rotulo'   => '%m/%Y',
            'atras'    => '-24 months',
            'paso'     => 'P1M',
        ],
        'anio' => [
            'formato'  => '%Y',
            'etiqueta' => 'Año',
            'rotulo'   => '%Y',
            'atras'    => '-10 years',
            'paso'     => 'P1Y',
        ],
    ];

    /** @return array<string,string> Para pintar el selector. */
    public static function periodos(): array
    {
        return array_map(
            static fn(array $p): string => $p['etiqueta'],
            self::PERIODOS
        );
    }

    public static function valido(string $periodo): string
    {
        return isset(self::PERIODOS[$periodo]) ? $periodo : 'mes';
    }

    /**
     * Todo lo que la pantalla de evolución necesita.
     *
     * @return array<string,mixed>
     */
    public function todo(string $periodo): array
    {
        $periodo = self::valido($periodo);

        return [
            'periodo'      => $periodo,
            'etiqueta'     => self::PERIODOS[$periodo]['etiqueta'],
            'captaciones'  => $this->saldoAcumulado($periodo, false),
            'cartera'      => $this->saldoAcumulado($periodo, true),
            'operaciones'  => $this->operaciones($periodo),
            'desembolsos'  => $this->desembolsos($periodo),
            'recuperacion' => $this->recuperacion($periodo),
            'clientes'     => $this->clientesNuevos($periodo),
        ];
    }

    /** Los tramos del eje, de más viejo a más nuevo. */
    private function tramos(string $periodo): array
    {
        $p     = self::PERIODOS[$periodo];
        $desde = (new \DateTimeImmutable())->modify($p['atras']);
        $hoy   = new \DateTimeImmutable();

        /* Se recorta al primer dato real: dibujar dos años de ceros
           porque el banco abrió el mes pasado no informa de nada y
           aplasta la parte que sí tiene historia. */
        $primero = BaseDatos::unValor('SELECT MIN(creado_en) FROM asientos');

        if ($primero !== null) {
            $inicio = new \DateTimeImmutable((string) $primero);

            if ($inicio > $desde) { $desde = $inicio; }
        }

        $cursor = $desde;
        $tramos = [];
        $visto  = [];
        $paso   = new \DateInterval($p['paso']);

        /* Tope de seguridad: con un `paso` mal puesto esto sería un
           bucle infinito, y un bucle infinito en una pantalla de
           consulta tumba el servidor. */
        $tope = 400;

        while ($cursor <= $hoy && $tope-- > 0) {
            $clave = $this->claveDe($cursor, $periodo);

            if (!isset($visto[$clave])) {
                $visto[$clave] = true;

                $tramos[] = [
                    'clave'  => $clave,
                    'rotulo' => $this->rotuloDe($cursor, $periodo),
                    'hasta'  => $this->finDe($cursor, $periodo),
                ];
            }

            $cursor = $cursor->add($paso);
        }

        /* El tramo en curso siempre entra, aunque el paso no caiga
           justo: sin él la última columna sería la de antes de ayer. */
        $clave = $this->claveDe($hoy, $periodo);

        if (!isset($visto[$clave])) {
            $tramos[] = [
                'clave'  => $clave,
                'rotulo' => $this->rotuloDe($hoy, $periodo),
                'hasta'  => $this->finDe($hoy, $periodo),
            ];
        }

        return $tramos;
    }

    private function claveDe(\DateTimeImmutable $f, string $periodo): string
    {
        return match ($periodo) {
            'dia'    => $f->format('Y-m-d'),
            'semana' => $f->format('o-\SW'),
            'anio'   => $f->format('Y'),
            default  => $f->format('Y-m'),
        };
    }

    private function rotuloDe(\DateTimeImmutable $f, string $periodo): string
    {
        return match ($periodo) {
            'dia'    => $f->format('d/m'),
            'semana' => 'Sem ' . $f->format('W'),
            'anio'   => $f->format('Y'),
            default  => $f->format('m/Y'),
        };
    }

    /** El último instante del tramo: hasta ahí se acumula. */
    private function finDe(\DateTimeImmutable $f, string $periodo): string
    {
        return match ($periodo) {
            'dia'    => $f->format('Y-m-d') . ' 23:59:59',
            'semana' => $f->modify('sunday this week')->format('Y-m-d') . ' 23:59:59',
            'anio'   => $f->format('Y') . '-12-31 23:59:59',
            default  => $f->format('Y-m-t') . ' 23:59:59',
        };
    }

    /**
     * El saldo a cierre de cada tramo.
     *
     * Con `$deCartera` da el capital prestado; sin él, el dinero del
     * público en cuentas.
     *
     * @return list<array{rotulo:string, valor:int}>
     */
    private function saldoAcumulado(string $periodo, bool $deCartera): array
    {
        $tramos = $this->tramos($periodo);
        $serie  = [];

        foreach ($tramos as $t) {
            if ($deCartera) {
                /* El saldo de cartera se ANCLA en el dato real de hoy
                 * y se deriva hacia atrás.
                 *
                 * Reconstruirlo sumando desembolsos y restando
                 * amortizaciones parecía lo natural, pero no cuadra con
                 * la cifra de cabecera: hay cuotas pagadas sin
                 * `pagada_en` y créditos vigentes sin `desembolsado_en`
                 * —herencia del sistema anterior—, y cada hueco mueve
                 * el total. La serie terminaba en $2.373.214 mientras
                 * el indicador decía $2.340.436, y dos números para lo
                 * mismo en la misma pantalla es la forma más rápida de
                 * que un consejo deje de fiarse del tablero.
                 *
                 * Así el último punto ES el saldo real por
                 * construcción, y los anteriores se obtienen
                 * deshaciendo lo que pasó después:
                 *
                 *     saldo(t) = saldo_hoy
                 *              + capital amortizado después de t
                 *              − capital prestado después de t
                 *
                 * Las fechas que faltan se resuelven del lado
                 * conservador: una cuota pagada sin fecha se da por
                 * pagada antes —no se suma—, y un crédito sin fecha de
                 * origen se da por existente desde siempre —no se
                 * resta—. Así ningún hueco infla la curva. */
                $hoy = (int) BaseDatos::unValor(
                    'SELECT COALESCE(SUM(saldo_capital_cent), 0) FROM creditos
                      WHERE estado IN ("vigente","desembolsado","mora")'
                );

                $amortizadoDespues = (int) BaseDatos::unValor(
                    'SELECT COALESCE(SUM(q.capital_cent), 0)
                       FROM cuotas q
                       JOIN creditos c ON c.id = q.credito_id
                      WHERE q.estado = "pagada"
                        AND q.pagada_en IS NOT NULL
                        AND q.pagada_en > ?
                        AND c.estado IN ("vigente","desembolsado","mora")',
                    [$t['hasta']]
                );

                $prestadoDespues = (int) BaseDatos::unValor(
                    'SELECT COALESCE(SUM(monto_cent), 0) FROM creditos
                      WHERE estado IN ("vigente","desembolsado","mora")
                        AND COALESCE(desembolsado_en, aprobado_en, creado_en) > ?',
                    [$t['hasta']]
                );

                $valor = max(0, $hoy + $amortizadoDespues - $prestadoDespues);
            } else {
                /* El saldo de las cuentas de clientes es la suma de
                   todo su movimiento hasta la fecha. Las técnicas
                   quedan fuera: son del sistema, no del público. */
                $valor = (int) BaseDatos::unValor(
                    'SELECT COALESCE(SUM(m.importe_cent), 0)
                       FROM movimientos m
                       JOIN cuentas c ON c.id = m.cuenta_id
                      WHERE c.es_tecnica = 0 AND m.creado_en <= ?',
                    [$t['hasta']]
                );

                $valor = max(0, $valor);
            }

            $serie[] = ['rotulo' => $t['rotulo'], 'valor' => $valor];
        }

        return $serie;
    }

    /**
     * Cuántas operaciones hubo en cada tramo.
     *
     * Esto SÍ es del periodo, no acumulado: es actividad, no saldo.
     *
     * @return list<array{rotulo:string, valor:int, monto:int}>
     */
    private function operaciones(string $periodo): array
    {
        $f = self::PERIODOS[$periodo]['formato'];

        $filas = BaseDatos::todas(
            "SELECT DATE_FORMAT(a.creado_en, '{$f}') AS k,
                    COUNT(DISTINCT a.id) AS n,
                    COALESCE(SUM(CASE WHEN m.importe_cent > 0
                                      THEN m.importe_cent ELSE 0 END), 0) AS monto
               FROM asientos a
               JOIN movimientos m ON m.asiento_id = a.id
              GROUP BY k"
        );

        return $this->alinear($periodo, $filas, 'n', 'monto');
    }

    /** @return list<array{rotulo:string, valor:int, monto:int}> */
    private function desembolsos(string $periodo): array
    {
        $f = self::PERIODOS[$periodo]['formato'];

        $filas = BaseDatos::todas(
            "SELECT DATE_FORMAT(desembolsado_en, '{$f}') AS k,
                    COUNT(*) AS n,
                    COALESCE(SUM(monto_cent), 0) AS monto
               FROM creditos
              WHERE desembolsado_en IS NOT NULL
              GROUP BY k"
        );

        return $this->alinear($periodo, $filas, 'n', 'monto');
    }

    /** Lo cobrado de los créditos en cada tramo. */
    private function recuperacion(string $periodo): array
    {
        $f = self::PERIODOS[$periodo]['formato'];

        $filas = BaseDatos::todas(
            "SELECT DATE_FORMAT(q.pagada_en, '{$f}') AS k,
                    COUNT(*) AS n,
                    COALESCE(SUM(q.total_cent), 0) AS monto
               FROM cuotas q
              WHERE q.estado = 'pagada' AND q.pagada_en IS NOT NULL
              GROUP BY k"
        );

        return $this->alinear($periodo, $filas, 'n', 'monto');
    }

    /** Clientes dados de alta en cada tramo. */
    private function clientesNuevos(string $periodo): array
    {
        $f = self::PERIODOS[$periodo]['formato'];

        $filas = BaseDatos::todas(
            "SELECT DATE_FORMAT(u.creado_en, '{$f}') AS k, COUNT(*) AS n, 0 AS monto
               FROM usuarios u JOIN roles r ON r.id = u.rol_id
              WHERE r.clave = 'cliente'
              GROUP BY k"
        );

        return $this->alinear($periodo, $filas, 'n', 'monto');
    }

    /**
     * Pone los datos sobre el eje, rellenando los tramos sin nada.
     *
     * Sin esto, una semana sin operaciones desaparece del gráfico y
     * las dos de al lado quedan pegadas: la línea miente sobre el
     * ritmo. Un cero es un dato.
     *
     * @param list<array<string,mixed>> $filas
     * @return list<array{rotulo:string, valor:int, monto:int}>
     */
    private function alinear(string $periodo, array $filas, string $campo, string $monto): array
    {
        $por = [];

        foreach ($filas as $f) {
            $por[(string) $f['k']] = [
                'valor' => (int) $f[$campo],
                'monto' => (int) $f[$monto],
            ];
        }

        $serie = [];

        foreach ($this->tramos($periodo) as $t) {
            /* La clave que arma MySQL y la que arma PHP tienen que
               coincidir. Para la semana, `%x-S%v` da «2026-S46» y
               `o-\SW` da «2026-S46»; para el resto el formato es el
               mismo texto. */
            $d = $por[$t['clave']] ?? ['valor' => 0, 'monto' => 0];

            $serie[] = [
                'rotulo' => $t['rotulo'],
                'valor'  => $d['valor'],
                'monto'  => $d['monto'],
            ];
        }

        return $serie;
    }
}
