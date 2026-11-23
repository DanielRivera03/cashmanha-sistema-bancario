<?php
declare(strict_types=1);

namespace CashMan\Dominio\Cliente;

use CashMan\Dominio\Comun\Monto;

/**
 * El segmento de un cliente, y lo que ese segmento le da.
 *
 * NO ES UNA ETIQUETA QUE ALGUIEN PONE A MANO. Sale de hechos
 * comprobables —lo que tiene depositado, la línea que maneja, su
 * ingreso, su antigüedad y su banda de riesgo—, exactamente igual que
 * la clasificación de crédito. Si lo pusiera una persona, dos clientes
 * idénticos acabarían en segmentos distintos según quién los atendió,
 * y el cliente que se queda fuera tiene razón al preguntar por qué.
 *
 * Los beneficios tampoco son adorno: cada uno está enganchado a algo
 * que el sistema hace de verdad —el múltiplo de línea que se aprueba,
 * cuántas compras puede diferir, qué descuento de tasa se le aplica—.
 * Un beneficio que solo existe en un folleto es publicidad, no un
 * producto.
 *
 * El riesgo manda sobre el patrimonio: por mucho saldo que tenga, un
 * cliente con una cuota vencida no sube de segmento. Premiar el saldo
 * ignorando el comportamiento es cómo se construye una cartera que se
 * ve bien hasta que deja de verse.
 */
final class Segmento
{
    public const ESTANDAR    = 'estandar';
    public const PREFERENTE  = 'preferente';
    public const PATRIMONIAL = 'patrimonial';

    /** Se guarda con cada clasificación: la regla de hoy no reescribe la de ayer. */
    public const VERSION = 'v2.0';

    /**
     * Lo que hace falta para entrar en cada segmento.
     *
     * Las condiciones de patrimonio van en «o»: a un cliente le basta
     * con una. Un profesional con ingreso alto y poco depositado y un
     * jubilado con mucho depositado y poco ingreso merecen el mismo
     * trato, y exigir las dos cosas a la vez dejaría fuera a los dos.
     *
     * La banda de riesgo y la antigüedad van en «y»: esas no se
     * negocian.
     */
    /**
     * Los umbrales de cada escalón.
     *
     * `ingreso` es una CONDICIÓN NECESARIA: sin alcanzarlo no se entra
     * al escalón, por mucho saldo que haya. `depositos` y `linea` ya no
     * son alternativas al ingreso; se siguen midiendo porque forman
     * parte de la relación y el cliente quiere verlos en el motivo,
     * pero no abren la puerta solos.
     *
     * Hasta la v1.0 bastaba con cumplir uno de los tres, y eso hacía
     * preferente a quien tuviera $11,501 parados en una cuenta y
     * $1,500 de ingreso. Un saldo alto puede ser una herencia o dinero
     * de paso; no dice que el cliente pueda sostener las condiciones
     * que el escalón le concede.
     *
     * REGLA DE COHERENCIA: el `ingreso` de un escalón no puede ser
     * menor que el ingreso que pide la línea de tarjeta más exigente
     * que ese escalón reserva. Si lo fuera, el escalón anunciaría una
     * puerta que su propio umbral no alcanza, que es justo lo que
     * pasaba con la Infinite en Preferente. Lo comprueba
     * `bin/revisar-coherencia-segmentos.php`.
     */
    private const REGLAS = [
        self::PATRIMONIAL => [
            'etiqueta'      => 'Patrimonial',
            'depositos'     => 5000000,   // $50,000
            'linea'         => 3000000,   // $30,000
            'ingreso'       => 800000,    // $8,000 al mes
            'bandas'        => ['A', 'B'],
            'meses_minimos' => 12,
        ],
        self::PREFERENTE => [
            'etiqueta'      => 'Preferente',
            'depositos'     => 1000000,   // $10,000
            'linea'         => 500000,    // $5,000
            'ingreso'       => 250000,    // $2,500 al mes
            'bandas'        => ['A', 'B', 'C'],
            'meses_minimos' => 6,
        ],
    ];

    /**
     * Lo que cada segmento concede, en números que el sistema usa.
     *
     * `tasa_descuento_bp` se resta de la tasa del crédito; los
     * múltiplos y topes sustituyen a los parámetros generales. Son los
     * mismos valores que leen el motor de riesgo, la emisión de
     * tarjetas y el diferido: cambiarlos aquí los cambia de verdad.
     */
    private const BENEFICIOS = [
        self::ESTANDAR => [
            'tasa_descuento_bp'     => 0,
            'tasa_deposito_extra_bp' => 0,
            'linea_veces_ingreso'   => 3,
            'planes_maximos'        => 5,
            'membresia_descuento'   => 0.0,
            'sobregiros_sin_cargo'  => 0,
            'atencion_prioritaria'  => false,
            'banquero_asignado'     => false,
            'lineas_exclusivas'     => [],
        ],
        self::PREFERENTE => [
            'tasa_descuento_bp'     => 100,   // un punto menos
            'tasa_deposito_extra_bp' => 50,    // medio punto mas en su ahorro
            'linea_veces_ingreso'   => 4,
            'planes_maximos'        => 8,
            'membresia_descuento'   => 0.50,
            'sobregiros_sin_cargo'  => 1,
            'atencion_prioritaria'  => true,
            'banquero_asignado'     => false,
            /* Vacío a propósito. Tenía la Infinite, que pide $8,000 de
               ingreso mensual mientras que a este escalón se entra con
               $2,500: el escalón anunciaba «acceso a líneas reservadas»
               y enseñaba una tarjeta que su propio umbral no alcanza.
               Un beneficio que no se puede usar no es un beneficio. */
            'lineas_exclusivas'     => [],
        ],
        self::PATRIMONIAL => [
            'tasa_descuento_bp'     => 250,   // dos puntos y medio
            'tasa_deposito_extra_bp' => 100,   // un punto mas en su ahorro
            'linea_veces_ingreso'   => 5,
            'planes_maximos'        => 12,
            'membresia_descuento'   => 1.0,
            'sobregiros_sin_cargo'  => 3,
            'atencion_prioritaria'  => true,
            'banquero_asignado'     => true,
            'lineas_exclusivas'     => ['TAR-INF', 'TAR-OBS'],
        ],
    ];

    /**
     * Lo que se le dice al cliente que tiene.
     *
     * Redactado desde lo que de verdad cambia, no desde lo que suena
     * bien: cada línea de aquí se corresponde con un número de
     * `BENEFICIOS`, y si ese número cambia, la frase cambia con él.
     *
     * @return list<array{titulo:string, detalle:string}>
     */
    public static function beneficiosVisibles(string $segmento): array
    {
        $b = self::BENEFICIOS[$segmento] ?? self::BENEFICIOS[self::ESTANDAR];

        $lista = [];

        if (($b['tasa_deposito_extra_bp'] ?? 0) > 0) {
            $lista[] = [
                'titulo'  => number_format($b['tasa_deposito_extra_bp'] / 100, 2)
                           . ' puntos mas en su ahorro',
                'detalle' => 'Se suman a la tasa de sus cuentas de ahorro. El interes '
                           . 'se abona cada mes sobre su saldo diario promedio, y lo '
                           . 'vera en su estado de cuenta como un abono mas.',
            ];
        }

        if ($b['tasa_descuento_bp'] > 0) {
            $lista[] = [
                'titulo'  => number_format($b['tasa_descuento_bp'] / 100, 2)
                           . ' puntos menos de tasa',
                'detalle' => 'Se descuentan de la tasa de cualquier crédito que se le '
                           . 'otorgue, sobre la que ya le corresponda por su perfil, y '
                           . 'del interés de sus tarjetas en cada corte, también en las '
                           . 'que ya tiene.',
            ];
        }

        $lista[] = [
            'titulo'  => 'Línea de hasta ' . $b['linea_veces_ingreso'] . ' veces su ingreso',
            'detalle' => 'El techo de su línea de tarjeta se calcula con ese múltiplo en '
                       . 'lugar del general, siempre dentro de su capacidad de pago.',
        ];

        $lista[] = [
            'titulo'  => 'Hasta ' . $b['planes_maximos'] . ' compras a cuotas a la vez',
            'detalle' => 'Puede mantener ese número de compras diferidas abiertas en cada '
                       . 'tarjeta, en lugar del tope general.',
        ];

        if ($b['sobregiros_sin_cargo'] > 0) {
            $lista[] = [
                'titulo'  => $b['sobregiros_sin_cargo'] . ' sobregiro(s) al año sin comisión',
                'detalle' => 'Si una compra rebasa su línea, no se le cobra la comisión por '
                           . 'sobregiro las primeras veces del año.',
            ];
        }

        if ($b['membresia_descuento'] >= 1.0) {
            $lista[] = [
                'titulo'  => 'Sin membresía anual',
                'detalle' => 'No se le cobra la membresía de sus tarjetas de crédito, '
                           . 'cualquiera que sea la línea.',
            ];
        } elseif ($b['membresia_descuento'] > 0) {
            $lista[] = [
                'titulo'  => number_format($b['membresia_descuento'] * 100, 0)
                           . '% de descuento en la membresía',
                'detalle' => 'Se aplica sobre la membresía anual de sus tarjetas de crédito.',
            ];
        }

        if ($b['atencion_prioritaria']) {
            $lista[] = [
                'titulo'  => 'Atención prioritaria',
                'detalle' => 'Sus consultas y gestiones se atienden antes en la cola de '
                           . 'soporte, y en agencia se le atiende sin hacer fila.',
            ];
        }

        if ($b['banquero_asignado']) {
            $lista[] = [
                'titulo'  => 'Banquero asignado',
                'detalle' => 'Una persona concreta lleva su relación con la institución, '
                           . 'con línea directa y sin pasar por el conmutador.',
            ];
        }

        if ($b['lineas_exclusivas'] !== []) {
            $lista[] = [
                'titulo'  => 'Acceso a líneas de tarjeta reservadas',
                'detalle' => 'Puede solicitar líneas que no aparecen en el catálogo '
                           . 'general, con los beneficios de viaje y seguro que llevan.',
            ];
        }

        return $lista;
    }

    /** @return array<string,mixed> */
    public static function beneficios(string $segmento): array
    {
        return self::BENEFICIOS[$segmento] ?? self::BENEFICIOS[self::ESTANDAR];
    }

    /**
     * El escalón, como número.
     *
     * Hace falta para saber si un cambio es ascenso o descenso, que es
     * lo que decide el tono de lo que se le dice al cliente: «nos
     * complace informarle» y «le informamos» no son intercambiables.
     *
     * Comparar las cadenas no sirve —«patrimonial» va antes que
     * «preferente» por orden alfabético— y repartir el orden por el
     * código acabaría con tres sitios decidiendo cuál es más alto.
     */
    public static function rango(string $segmento): int
    {
        return match ($segmento) {
            self::PATRIMONIAL => 3,
            self::PREFERENTE  => 2,
            default           => 1,
        };
    }
    public static function etiqueta(string $segmento): string
    {
        return (string) (self::REGLAS[$segmento]['etiqueta'] ?? 'Estándar');
    }

    /**
     * En qué segmento cae este cliente, y por qué.
     *
     * @param array{depositos?:Monto, linea?:Monto, ingreso?:Monto,
     *              banda?:string, meses_cliente?:int} $h
     *
     * @return array{segmento:string, etiqueta:string, version:string,
     *               motivo:string, cumple:list<string>, falta:list<string>}
     */
    public static function clasificar(array $h): array
    {
        $depositos = ($h['depositos'] ?? null) instanceof Monto ? $h['depositos']->centavos : 0;
        $linea     = ($h['linea'] ?? null) instanceof Monto ? $h['linea']->centavos : 0;
        $ingreso   = ($h['ingreso'] ?? null) instanceof Monto ? $h['ingreso']->centavos : 0;
        $banda     = (string) ($h['banda'] ?? 'E');
        $meses     = (int) ($h['meses_cliente'] ?? 0);

        foreach (self::REGLAS as $clave => $r) {
            /* El ingreso es la condición, no una de tres opciones.
               Sin él no se entra, haya el saldo que haya. */
            $cumpleIngreso = $ingreso >= $r['ingreso'];
            $cumpleRiesgo  = in_array($banda, $r['bandas'], true);
            $cumpleEdad    = $meses >= $r['meses_minimos'];

            if (!$cumpleIngreso || !$cumpleRiesgo || !$cumpleEdad) {
                continue;
            }

            /* Lo que sostiene la clasificación, en el orden en que se
               le cuenta al cliente: primero lo que le dio entrada y
               después lo que la acompaña. */
            $cumple = [
                'declara ' . Monto::deCentavos($ingreso)->formateado()
                    . ' de ingreso mensual',
            ];

            if ($depositos >= $r['depositos']) {
                $cumple[] = 'mantiene ' . Monto::deCentavos($depositos)->formateado()
                    . ' en sus cuentas';
            }

            if ($linea >= $r['linea']) {
                $cumple[] = 'maneja ' . Monto::deCentavos($linea)->formateado()
                    . ' de línea de tarjeta';
            }

            return [
                'segmento' => (string) $clave,
                'etiqueta' => (string) $r['etiqueta'],
                'version'  => self::VERSION,
                'motivo'   => 'Califica como ' . mb_strtolower((string) $r['etiqueta'])
                            . ' porque ' . implode(', ', $cumple)
                            . ', su clasificación de riesgo es banda ' . $banda
                            . ' y lleva ' . $meses . ' mes(es) con la institución.',
                'cumple'   => $cumple,
                'falta'    => [],
            ];
        }

        // Qué le falta para el escalón siguiente: es la pregunta que
        // hace todo el que se queda fuera.
        $r = self::REGLAS[self::PREFERENTE];
        $falta = [];

        /* El ingreso primero y solo: decirle a alguien que puede entrar
           «con saldo o con línea o con ingreso» era lo que hacía creer
           que el saldo bastaba. Ahora se nombra lo único que de verdad
           abre la puerta, y con cuánto le falta. */
        if ($ingreso < $r['ingreso']) {
            $falta[] = $ingreso > 0
                ? 'acreditar ' . Monto::deCentavos($r['ingreso'])->formateado()
                  . ' de ingreso mensual (hoy constan '
                  . Monto::deCentavos($ingreso)->formateado() . ', faltan '
                  . Monto::deCentavos($r['ingreso'] - $ingreso)->formateado() . ')'
                : 'registrar su ingreso mensual, que debe ser de al menos '
                  . Monto::deCentavos($r['ingreso'])->formateado();
        }

        if (!in_array($banda, $r['bandas'], true)) {
            $falta[] = 'mejorar su clasificación de riesgo hasta la banda C o superior'
                . ' (hoy está en la ' . $banda . ')';
        }

        if ($meses < $r['meses_minimos']) {
            $falta[] = 'cumplir ' . $r['meses_minimos'] . ' meses con la institución'
                . ' (lleva ' . $meses . ')';
        }

        return [
            'segmento' => self::ESTANDAR,
            'etiqueta' => 'Estándar',
            'version'  => self::VERSION,
            'motivo'   => $falta === []
                ? 'Cliente estándar.'
                : 'Para pasar a Preferente le falta ' . implode('; ', $falta) . '.',
            'cumple'   => [],
            'falta'    => $falta,
        ];
    }
}
