<?php
declare(strict_types=1);

namespace CashMan\Dominio\Riesgo;

use CashMan\Dominio\Comun\Monto;

/**
 * Motor de decisión crediticia.
 *
 * Esto es una «tarjeta de puntuación» (scorecard): la misma técnica con
 * la que un banco decide de verdad. Cada factor aporta puntos dentro de
 * un tope conocido, la suma cae en una banda y la banda determina qué
 * se puede ofrecer.
 *
 * SE HIZO ASÍ Y NO CON UN MODELO DE LENGUAJE, a propósito.
 *
 * Una decisión de crédito tiene que cumplir tres cosas que un modelo
 * generativo no cumple:
 *
 *   REPRODUCIBLE  el mismo expediente tiene que dar el mismo resultado
 *     hoy y dentro de un año. Si dos clientes idénticos reciben
 *     respuestas distintas, la institución no puede defender ninguna
 *     de las dos.
 *
 *   EXPLICABLE   al cliente se le debe poder decir exactamente qué pesó
 *     en contra y cuánto. De ahí que cada factor devuelva su código de
 *     razón y sus puntos, y no una frase bonita.
 *
 *   AUDITABLE    el supervisor puede pedir que se reconstruya una
 *     decisión de hace dos años con los datos de entonces. Eso exige
 *     que la regla esté escrita, versionada y fuera del modelo.
 *
 * Lo que sí aporta aquí el aprendizaje automático —y es donde encaja de
 * verdad— es calibrar los pesos y los cortes con el historial real de
 * la cartera. Esos números viven en `PESOS` y `BANDAS` justamente para
 * poder recalibrarlos sin tocar la lógica. Mientras no haya cartera
 * suficiente para calibrar, los valores son los habituales del sector.
 *
 * La versión se guarda con cada decisión: cambiar los pesos no debe
 * poder reescribir el pasado.
 */
final class MotorDecision
{
    /** Se guarda con cada evaluación: una decisión vieja se lee con su regla. */
    public const VERSION = '1.0';

    /** Tope de puntos de cada factor. Suman 1000. */
    private const PESOS = [
        'buro'        => 300,   // Lo que dicen las centrales de riesgo
        'interno'     => 260,   // Cómo ha pagado AQUÍ
        'capacidad'   => 250,   // Si le cabe la cuota en el bolsillo
        'vinculacion' => 110,   // Antigüedad y productos con la casa
        'uso_linea'   => 80,    // Cuánta deuda revolvente arrastra
    ];

    /**
     * Las bandas y lo que cada una permite.
     *
     * `monto_factor` y `plazo_factor` recortan lo solicitado; el recargo
     * de tasa cobra el riesgo adicional en lugar de negarlo, que es lo
     * que permite dar crédito a un perfil regular sin regalarlo.
     */
    private const BANDAS = [
        'A' => ['desde' => 800, 'etiqueta' => 'Excelente',
                'monto_factor' => 1.00, 'plazo_factor' => 1.00, 'recargo_bp' => 0,
                'exige_garantia' => false, 'requiere_comite' => false, 'aprueba' => true],

        'B' => ['desde' => 680, 'etiqueta' => 'Bueno',
                'monto_factor' => 1.00, 'plazo_factor' => 1.00, 'recargo_bp' => 0,
                'exige_garantia' => false, 'requiere_comite' => false, 'aprueba' => true],

        'C' => ['desde' => 560, 'etiqueta' => 'Regular',
                'monto_factor' => 0.70, 'plazo_factor' => 0.75, 'recargo_bp' => 400,
                'exige_garantia' => false, 'requiere_comite' => false, 'aprueba' => true],

        'D' => ['desde' => 440, 'etiqueta' => 'Deficiente',
                'monto_factor' => 0.40, 'plazo_factor' => 0.60, 'recargo_bp' => 900,
                'exige_garantia' => true,  'requiere_comite' => true,  'aprueba' => true],

        'E' => ['desde' => 0,   'etiqueta' => 'Alto riesgo',
                'monto_factor' => 0.00, 'plazo_factor' => 0.00, 'recargo_bp' => 0,
                'exige_garantia' => true,  'requiere_comite' => true,  'aprueba' => false],
    ];

    /**
     * Hechos que cierran la puerta por sí solos.
     *
     * Van aparte de la puntuación porque no se compensan: por mucho
     * puntaje que traiga un cliente, no se le presta mientras tenga una
     * cuota vencida encima. Mezclarlos con los puntos permitiría que un
     * buen buró «comprara» una mora, y eso es exactamente como se
     * construye una cartera mala.
     */
    private const VETOS = [
        'vencidas'  => 'Tiene cuotas vencidas sin regularizar.',
        'cobranza'  => 'Tiene un expediente de cobro abierto.',
        'castigado' => 'Registra un crédito castigado o en cobro judicial.',
        'buro_malo' => 'Las centrales de riesgo reportan mora grave reciente.',
    ];

    /**
     * Evalúa un expediente y devuelve la decisión completa.
     *
     * @param array{
     *   buro_score?:int, buro_categoria?:string, buro_moras_12m?:int,
     *   buro_peor_mora?:int, buro_sin_historial?:bool,
     *   cuotas_pagadas?:int, atraso_maximo_dias?:int, cuotas_vencidas?:int,
     *   creditos_cerrados?:int, tiene_cobranza?:bool, tiene_castigado?:bool,
     *   meses_cliente?:int, productos_activos?:int,
     *   uso_linea_pct?:float,
     *   ingreso?:Monto, compromisos?:Monto, cuota?:Monto
     * } $e
     *
     * @return array{
     *   puntaje:int, banda:string, etiqueta:string, aprueba:bool,
     *   razones:list<array{codigo:string,texto:string,puntos:int,tope:int}>,
     *   vetos:list<string>, condiciones:array<string,mixed>,
     *   narrativa:string, version:string
     * }
     */
    public function evaluar(array $e): array
    {
        $razones = [];

        $razones[] = $this->factorBuro($e);
        $razones[] = $this->factorInterno($e);
        $razones[] = $this->factorCapacidad($e);
        $razones[] = $this->factorVinculacion($e);
        $razones[] = $this->factorUsoLinea($e);

        /* El puntaje se calcula sobre los factores que SÍ se pudieron
         * medir, no sobre los cinco siempre.
         *
         * Si no se ha consultado la central de riesgo —porque el
         * cliente no ha autorizado, porque el servicio no respondió—,
         * contar ese factor como «casi cero» castiga al cliente por una
         * gestión que no depende de él. Un cliente de cuatro años,
         * puntual y sin deuda revolvente acababa en banca «regular» y
         * se le recortaba el monto, por no haberle pedido la firma.
         *
         * Lo correcto es puntuar con lo que se sabe y escalarlo: es lo
         * que en banca se llama tarjeta reducida. El factor ausente no
         * suma ni resta, solo deja de contar. */
        $obtenidos = 0;
        $posibles  = 0;

        foreach ($razones as $r) {
            if (($r['aplica'] ?? true) === false) {
                continue;
            }

            $obtenidos += $r['puntos'];
            $posibles  += $r['tope'];
        }

        $puntaje = $posibles > 0
            ? (int) round(1000 * max(0, $obtenidos) / $posibles)
            : 0;

        $puntaje = max(0, min(1000, $puntaje));

        // --- Los vetos, que no se compensan con puntos ------------------
        $vetos = [];

        if ((int) ($e['cuotas_vencidas'] ?? 0) > 0) {
            $vetos[] = self::VETOS['vencidas'];
        }

        if (!empty($e['tiene_cobranza'])) {
            $vetos[] = self::VETOS['cobranza'];
        }

        if (!empty($e['tiene_castigado'])) {
            $vetos[] = self::VETOS['castigado'];
        }

        // Mora grave en el buró: 90 días o más es incumplimiento, no atraso.
        if ((int) ($e['buro_peor_mora'] ?? 0) >= 90) {
            $vetos[] = self::VETOS['buro_malo'];
        }

        $banda = $vetos === [] ? $this->bandaDe($puntaje) : 'E';
        $def   = self::BANDAS[$banda];

        return [
            'puntaje'     => $puntaje,
            'banda'       => $banda,
            'etiqueta'    => (string) $def['etiqueta'],
            'aprueba'     => $vetos === [] && (bool) $def['aprueba'],
            'razones'     => $razones,
            'vetos'       => $vetos,
            'condiciones' => [
                'monto_factor'    => (float) $def['monto_factor'],
                'plazo_factor'    => (float) $def['plazo_factor'],
                'recargo_bp'      => (int) $def['recargo_bp'],
                'exige_garantia'  => (bool) $def['exige_garantia'],
                'requiere_comite' => (bool) $def['requiere_comite'],
            ],
            'narrativa'   => $this->narrar($puntaje, $banda, $razones, $vetos),
            'version'     => self::VERSION,
        ];
    }

    /**
     * Lo que se le puede ofrecer de verdad, a partir de lo que pidió.
     *
     * Un perfil regular no se rechaza: se le ofrece menos plazo, menos
     * monto y una tasa que cobre el riesgo. Rechazar sin más a quien
     * paga tarde pero paga es dejar negocio sano en la calle.
     *
     * @param array<string,mixed> $decision
     * @return array{monto:Monto, plazo:int, tasa_bp:int, ajustado:bool}
     */
    public function condicionarOferta(
        array $decision,
        Monto $montoPedido,
        int $plazoPedido,
        int $tasaBp
    ): array {
        $c = $decision['condiciones'];

        $monto = Monto::deCentavos(
            (int) floor($montoPedido->centavos * (float) $c['monto_factor'] / 100) * 100
        );

        $plazo = max(3, (int) floor($plazoPedido * (float) $c['plazo_factor']));
        $tasa  = $tasaBp + (int) $c['recargo_bp'];

        return [
            'monto'    => $monto,
            'plazo'    => $plazo,
            'tasa_bp'  => $tasa,
            'ajustado' => $monto->centavos !== $montoPedido->centavos
                       || $plazo !== $plazoPedido
                       || $tasa !== $tasaBp,
        ];
    }

    // =================================================================
    // LOS FACTORES
    // =================================================================

    /**
     * Lo que dicen las centrales de riesgo.
     *
     * Sin historial no es lo mismo que mal historial: a quien nunca ha
     * pedido prestado se le da la mitad del tramo, no cero. Tratar la
     * ausencia de datos como una señal negativa deja fuera a todo el
     * que empieza, que es de donde sale la cartera de mañana.
     *
     * @param array<string,mixed> $e
     * @return array{codigo:string,texto:string,puntos:int,tope:int}
     */
    private function factorBuro(array $e): array
    {
        $tope = self::PESOS['buro'];

        if (!empty($e['buro_sin_historial'])) {
            return ['codigo' => 'BUR-00', 'tope' => $tope,
                    'puntos' => (int) round($tope * 0.50),
                    'texto'  => 'Sin historial en centrales de riesgo. No cuenta en contra, '
                              . 'pero tampoco acredita comportamiento de pago.'];
        }

        $score = (int) ($e['buro_score'] ?? 0);

        if ($score <= 0) {
            /* No se consultó, que no es lo mismo que salir mal.
             *
             * Este factor no cuenta: se puntúa con lo que sí se sabe y
             * se escala. Darle un valor bajo castigaría al cliente por
             * una gestión que no depende de él —la autorización, el
             * servicio del buró—, y dejaba a clientes de años, puntuales
             * y sin deuda, en banda «regular» con el monto recortado. */
            return ['codigo' => 'BUR-99', 'tope' => $tope, 'puntos' => 0,
                    'aplica' => false,
                    'texto'  => 'No hay consulta vigente a la central de riesgo: la '
                              . 'evaluación se hace con la información interna.'];
        }

        // El puntaje del buró va de 300 a 850: se lleva a la escala propia.
        $normal = max(0.0, min(1.0, ($score - 300) / 550));
        $puntos = (int) round($tope * $normal);

        $moras = (int) ($e['buro_moras_12m'] ?? 0);

        // Cada atraso del último año descuenta, con suelo.
        if ($moras > 0) {
            $puntos = max(0, $puntos - $moras * 25);
        }

        return ['codigo' => $moras > 0 ? 'BUR-02' : 'BUR-01', 'tope' => $tope,
                'puntos' => $puntos,
                'texto'  => 'Puntaje externo ' . $score
                          . ' (' . (string) ($e['buro_categoria'] ?? '—') . ')'
                          . ($moras > 0
                              ? ', con ' . $moras . ' atraso(s) en los últimos 12 meses.'
                              : ', sin atrasos en los últimos 12 meses.')];
    }

    /**
     * Cómo ha pagado AQUÍ.
     *
     * Pesa casi tanto como el buró a propósito: lo que un cliente hace
     * con esta institución se observa de primera mano y es más fiable
     * que un informe de terceros con semanas de rezago.
     *
     * @param array<string,mixed> $e
     * @return array{codigo:string,texto:string,puntos:int,tope:int}
     */
    private function factorInterno(array $e): array
    {
        $tope    = self::PESOS['interno'];
        $pagadas = (int) ($e['cuotas_pagadas'] ?? 0);
        $atraso  = (int) ($e['atraso_maximo_dias'] ?? 0);
        $cerrados = (int) ($e['creditos_cerrados'] ?? 0);

        if ($pagadas === 0) {
            return ['codigo' => 'INT-00', 'tope' => $tope,
                    'puntos' => (int) round($tope * 0.45),
                    'texto'  => 'Todavía no ha pagado cuotas con nosotros: no hay '
                              . 'comportamiento interno que evaluar.'];
        }

        /* Volumen de cuotas pagadas, con rendimientos decrecientes: la
           diferencia entre 2 y 12 cuotas dice mucho; entre 60 y 70, casi
           nada. */
        $volumen = min(1.0, log(1 + $pagadas) / log(1 + 36));
        $puntos  = (int) round($tope * 0.65 * $volumen);

        // Puntualidad: es lo que de verdad distingue.
        $puntos += match (true) {
            $atraso === 0 => (int) round($tope * 0.35),
            $atraso <= 5  => (int) round($tope * 0.25),
            $atraso <= 15 => (int) round($tope * 0.12),
            $atraso <= 30 => 0,
            $atraso <= 60 => -(int) round($tope * 0.15),
            default       => -(int) round($tope * 0.30),
        };

        // Un crédito terminado de pagar es la mejor señal que existe.
        $puntos += min(40, $cerrados * 20);

        $puntos = max(0, min($tope, $puntos));

        return ['codigo' => $atraso === 0 ? 'INT-01' : 'INT-02', 'tope' => $tope,
                'puntos' => $puntos,
                'texto'  => $pagadas . ' cuota(s) pagadas con nosotros, atraso máximo de '
                          . $atraso . ' día(s)'
                          . ($cerrados > 0
                              ? ', y ' . $cerrados . ' crédito(s) liquidados por completo.'
                              : '.')];
    }

    /**
     * Si la cuota le cabe en el bolsillo.
     *
     * Se mide contra el ingreso LIBRE —lo que queda después de sus
     * compromisos—, no contra el ingreso bruto. Un salario alto con
     * cinco préstamos encima no tiene capacidad, y medirlo sobre el
     * bruto es como se aprueban los créditos que luego no se cobran.
     *
     * @param array<string,mixed> $e
     * @return array{codigo:string,texto:string,puntos:int,tope:int}
     */
    private function factorCapacidad(array $e): array
    {
        $tope = self::PESOS['capacidad'];

        $ingreso     = $e['ingreso'] ?? null;
        $compromisos = $e['compromisos'] ?? null;
        $cuota       = $e['cuota'] ?? null;

        if (!$ingreso instanceof Monto || !$ingreso->esPositivo()) {
            return ['codigo' => 'CAP-00', 'tope' => $tope, 'puntos' => 0,
                    'texto'  => 'No hay ingreso declarado y verificable: sin ese dato no '
                              . 'se puede acreditar capacidad de pago.'];
        }

        $otros = $compromisos instanceof Monto ? $compromisos->centavos : 0;

        if (!$cuota instanceof Monto || !$cuota->esPositivo()) {
            /* Sin una cuota sobre la mesa se mide lo que YA debe cada
             * mes contra lo que gana.
             *
             * Antes se daba medio tramo fijo, y eso hacía que el mismo
             * cliente saliera en una banda al pedir un crédito y en
             * otra al abrir su expediente, sin que nada hubiera
             * cambiado. La carga que ya arrastra es un dato real y
             * estable: con él la clasificación no baila. */
            $carga = $ingreso->centavos > 0 ? $otros / $ingreso->centavos : 1.0;

            $puntos = match (true) {
                $carga <= 0.20 => $tope,
                $carga <= 0.35 => (int) round($tope * 0.85),
                $carga <= 0.50 => (int) round($tope * 0.60),
                $carga <= 0.65 => (int) round($tope * 0.30),
                default        => 0,
            };

            return ['codigo' => 'CAP-01', 'tope' => $tope, 'puntos' => $puntos,
                    'texto'  => 'Sus compromisos actuales son '
                              . Monto::deCentavos($otros)->formateado() . ' al mes, el '
                              . number_format($carga * 100, 1) . '% de su ingreso declarado de '
                              . $ingreso->formateado() . '.'];
        }

        $libre = max(1, $ingreso->centavos - $otros);

        // Relación cuota / ingreso libre. Por debajo del 30% es holgado;
        // del 30 al 40 es razonable; pasado el 50 ya aprieta.
        $relacion = $cuota->centavos / $libre;

        $puntos = match (true) {
            $relacion <= 0.20 => $tope,
            $relacion <= 0.30 => (int) round($tope * 0.88),
            $relacion <= 0.40 => (int) round($tope * 0.70),
            $relacion <= 0.50 => (int) round($tope * 0.45),
            $relacion <= 0.65 => (int) round($tope * 0.18),
            default           => 0,
        };

        return ['codigo' => $relacion <= 0.40 ? 'CAP-02' : 'CAP-03', 'tope' => $tope,
                'puntos' => $puntos,
                'texto'  => 'La cuota de ' . $cuota->formateado() . ' representa el '
                          . number_format($relacion * 100, 1) . '% de su ingreso disponible ('
                          . Monto::deCentavos($libre)->formateado() . ' al mes, ya '
                          . 'descontados sus compromisos).'];
    }

    /**
     * Antigüedad y productos con la casa.
     *
     * @param array<string,mixed> $e
     * @return array{codigo:string,texto:string,puntos:int,tope:int}
     */
    private function factorVinculacion(array $e): array
    {
        $tope      = self::PESOS['vinculacion'];
        $meses     = (int) ($e['meses_cliente'] ?? 0);
        $productos = (int) ($e['productos_activos'] ?? 0);

        // Hasta tres años de antigüedad suman; más allá no añade.
        $puntos = (int) round($tope * 0.6 * min(1.0, $meses / 36));
        $puntos += (int) round($tope * 0.4 * min(1.0, $productos / 3));

        return ['codigo' => $meses >= 12 ? 'VIN-01' : 'VIN-02', 'tope' => $tope,
                'puntos' => min($tope, $puntos),
                'texto'  => $meses . ' mes(es) como cliente, con ' . $productos
                          . ' producto(s) activo(s).'];
    }

    /**
     * Cuánta deuda revolvente arrastra.
     *
     * Una tarjeta al tope es señal de tensión aunque se pague puntual:
     * significa que el cliente ya está financiando su mes a mes.
     *
     * @param array<string,mixed> $e
     * @return array{codigo:string,texto:string,puntos:int,tope:int}
     */
    private function factorUsoLinea(array $e): array
    {
        $tope = self::PESOS['uso_linea'];
        $uso  = (float) ($e['uso_linea_pct'] ?? 0.0);

        $puntos = match (true) {
            $uso <= 30.0 => $tope,
            $uso <= 50.0 => (int) round($tope * 0.75),
            $uso <= 70.0 => (int) round($tope * 0.45),
            $uso <= 90.0 => (int) round($tope * 0.15),
            default      => 0,
        };

        return ['codigo' => $uso <= 50.0 ? 'USO-01' : 'USO-02', 'tope' => $tope,
                'puntos' => $puntos,
                'texto'  => 'Usa el ' . number_format($uso, 1) . '% de sus líneas de '
                          . 'tarjeta de crédito.'];
    }

    private function bandaDe(int $puntaje): string
    {
        foreach (self::BANDAS as $letra => $def) {
            if ($puntaje >= (int) $def['desde']) {
                return (string) $letra;
            }
        }

        return 'E';
    }

    /**
     * La decisión, contada en palabras.
     *
     * Se compone de los mismos códigos de razón que la produjeron, no
     * de un texto escrito aparte: así no puede decir una cosa mientras
     * el sistema hace otra, que es el riesgo de redactar la explicación
     * por separado.
     *
     * @param list<array{codigo:string,texto:string,puntos:int,tope:int}> $razones
     * @param list<string> $vetos
     */
    private function narrar(int $puntaje, string $banda, array $razones, array $vetos): string
    {
        // Solo pesan los factores que contaron.
        $razones = array_values(array_filter(
            $razones,
            static fn(array $r): bool => ($r['aplica'] ?? true) !== false
        ));

        if ($vetos !== []) {
            /* Los motivos se enlazan con punto y coma, no concatenando
               frases: unidas con espacio quedaba «…sin regularizar.
               tiene un expediente…», con minúscula detrás del punto. */
            $lista = implode('; ', array_map(
                static fn(string $v): string => mb_strtolower(rtrim($v, '.')),
                $vetos
            ));

            /* En impersonal: este mismo texto se lee en el expediente
               del cliente, donde no hay ninguna solicitud de por medio,
               y decir «su solicitud no procede» ahí confunde. */
            return count($vetos) === 1
                ? 'No procede otorgar crédito en este momento porque ' . $lista
                  . '. Regularizada esa situación, el expediente puede volver a evaluarse.'
                : 'No procede otorgar crédito en este momento por ' . count($vetos)
                  . ' motivos: ' . $lista
                  . '. Regularizadas esas situaciones, el expediente puede volver a evaluarse.';
        }

        // Lo que más pesó a favor y lo que más pesó en contra.
        $mejor = null;
        $peor  = null;

        foreach ($razones as $r) {
            $prop = $r['tope'] > 0 ? $r['puntos'] / $r['tope'] : 0.0;

            if ($mejor === null || $prop > $mejor[1]) { $mejor = [$r, $prop]; }
            if ($peor === null  || $prop < $peor[1])  { $peor  = [$r, $prop]; }
        }

        $texto = 'Puntaje ' . $puntaje . ' sobre 1000, banda ' . $banda . ' ('
            . mb_strtolower((string) self::BANDAS[$banda]['etiqueta']) . '). ';

        if ($mejor !== null) {
            $texto .= 'Lo que más pesa a favor: ' . mb_strtolower($mejor[0]['texto']) . ' ';
        }

        if ($peor !== null && $peor[1] < 0.6) {
            $texto .= 'Lo que más limita: ' . mb_strtolower($peor[0]['texto']) . ' ';
        }

        $texto .= match ($banda) {
            'A', 'B' => 'Procede en las condiciones solicitadas.',
            'C'      => 'Procede con condiciones ajustadas: menor monto, menor plazo y una '
                      . 'tasa que recoge el riesgo adicional.',
            'D'      => 'Procede únicamente con garantía y aprobación de comité, por un '
                      . 'monto sensiblemente menor al solicitado.',
            default  => 'No procede otorgar crédito con este perfil.',
        };

        return $texto;
    }
}
