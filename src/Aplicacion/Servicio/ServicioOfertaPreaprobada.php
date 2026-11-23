<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\Amortizacion;
use CashMan\Dominio\Credito\CapacidadPago;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;

use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Ofertas preaprobadas para clientes de buena paga.
 *
 * Invierte el orden de siempre. En lugar de que el cliente pida y la
 * institución evalúe, la institución evalúa su propia cartera, decide a
 * quién le ofrece y cuánto, y se lo manda. El cliente solo acepta, y en
 * ese momento se le otorga: sin solicitud, sin revisión y sin esperar.
 *
 * Eso solo se sostiene si la preaprobación es de verdad una decisión
 * tomada, y por eso aquí hay tres reglas que no se negocian:
 *
 *   SE DICE POR QUÉ      Cada oferta guarda los números con los que se
 *                        decidió —cuotas pagadas, atraso máximo,
 *                        antigüedad—. Una preaprobación sin rastro de su
 *                        criterio no se puede auditar ni defender ante
 *                        quien pregunte por qué a este sí y a aquel no.
 *
 *   SE VUELVE A MIRAR    La capacidad se recalcula AL ACEPTAR, no solo
 *                        al ofrecer. Entre una cosa y otra pueden pasar
 *                        semanas, y en ese tiempo el cliente pudo tomar
 *                        otro crédito o llenar su tarjeta. Otorgar sobre
 *                        la foto vieja es prestar lo que ya no aguanta.
 *
 *   NO SE OFRECE DE MÁS  De la capacidad libre se ofrece una parte
 *                        —`oferta.margen_bp`, 80 % por defecto—, no toda.
 *                        Dejar al cliente exactamente en su techo es
 *                        ponerlo a un imprevisto de la mora.
 */
final class ServicioOfertaPreaprobada
{
    public function __construct(
        private readonly ServicioNotificacion $notificaciones = new ServicioNotificacion(),
    ) {
    }

    // =================================================================
    // GENERACIÓN
    // =================================================================

    /**
     * Evalúa la cartera y genera las ofertas que procedan.
     *
     * @param int|null $soloUsuario Para evaluar a un cliente concreto.
     * @return array{evaluados:int, ofertas:int, detalle:list<array<string,mixed>>}
     */
    public function evaluar(?int $soloUsuario = null, bool $enviar = true): array
    {
        $this->caducarVencidas();

        $candidatos = $this->buenaPaga($soloUsuario);
        $hechas     = [];
        $filtrados  = 0;

        $riesgo = new ServicioRiesgo();

        foreach ($candidatos as $c) {
            /* El filtro de buena paga mira el historial de pago; el
             * motor mira además el buró, la capacidad y la carga
             * revolvente que arrastra.
             *
             * Hacen falta los dos. Una oferta preaprobada se otorga sin
             * que nadie la revise, así que no basta con que el cliente
             * pague puntual aquí: si viene de endeudarse fuera,
             * ofrecerle más es justo lo que no hay que hacer. Solo las
             * bandas altas reciben oferta; a las demás se les evalúa
             * cuando ellas pidan, y con sus condiciones. */
            /* Primero lo que la ley hace pesar.
             *
             * Un cliente que estuvo atrasado tiene un plazo durante el
             * cual no opta a productos nuevos, y ese plazo manda sobre
             * cualquier evaluación de riesgo: por bien que puntúe hoy,
             * el dato negativo todavía pesa. Comprobarlo DESPUÉS del
             * motor de riesgo sería gastar la consulta al buró en
             * alguien a quien de todos modos no se le va a ofrecer. */
            $eleg = (new ServicioElegibilidad())->de((int) $c['id']);

            if (!$eleg['puede']) {
                $filtrados++;
                continue;
            }

            $d = $riesgo->evaluar((int) $c['id']);

            if (!$d['aprueba'] || !in_array($d['banda'], ['A', 'B'], true)) {
                $filtrados++;
                continue;
            }

            foreach ($this->ofertasPara($c) as $oferta) {
                $hechas[] = $oferta;

                if ($enviar) {
                    $this->avisarOferta((int) $oferta['id']);
                }
            }
        }

        return [
            'evaluados' => count($candidatos),
            'ofertas'   => count($hechas),
            'filtrados' => $filtrados,
            'detalle'   => $hechas,
        ];
    }

    /**
     * Ofrecer a un cliente concreto, por decisión y con motivo.
     *
     * NO es un atajo para saltarse los criterios: es la vía para los
     * casos que los criterios no contemplan y una persona sí.
     *
     * El caso típico es el cliente que venía mal y acaba de ponerse
     * al día pagando todo lo vencido de una vez. El barrido no lo
     * propone —ve el atraso con que se pagaron esas cuotas, y hace
     * bien— pero es exactamente el cliente con el que un banco quiere
     * volver a hablar.
     *
     * Lo que NO hace esta vía:
     *
     *   · No toca el historial. Las cuotas siguen diciendo cuándo se
     *     pagaron de verdad. Maquillar eso para que el barrido lo
     *     acepte sería falsear el expediente, y el expediente es lo
     *     único que sostiene una decisión de crédito.
     *
     *   · No se salta la capacidad de pago. Eso se comprueba igual, y
     *     otra vez al aceptar. Ofrecer una cuota que no se puede
     *     pagar no es un favor que se le hace a nadie.
     *
     * Queda constancia de quién decidió y por qué, dentro de los
     * criterios de la propia oferta y en la auditoría.
     *
     * @return array{ofertas:int, detalle:list<array<string,mixed>>, motivo:string}
     */
    public function ofrecerPorDecision(int $usuarioId, string $motivo, bool $enviar = true): array
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new \InvalidArgumentException(
                'Una oferta por decisión necesita su motivo: es lo que la separa '
                . 'de un capricho.'
            );
        }

        $this->caducarVencidas();

        $c = BaseDatos::unaFila(
            'SELECT u.id, u.usuario, u.correo,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente,
                    COALESCE(d.ingreso_mensual_cent,0) AS ingreso_cent,
                    (SELECT COUNT(*) FROM cuotas q
                       JOIN creditos cc ON cc.id = q.credito_id
                      WHERE cc.usuario_id = u.id AND q.estado = "pagada") AS cuotas_pagadas,
                    0 AS atraso_max,
                    (SELECT MIN(desembolsado_en) FROM creditos WHERE usuario_id = u.id) AS cliente_desde
               FROM usuarios u
               LEFT JOIN detalle_usuarios d ON d.usuario_id = u.id
              WHERE u.id = ? AND u.estado = "activo"',
            [$usuarioId]
        );

        if ($c === null) {
            throw new \RuntimeException('Ese cliente no existe o no está activo.');
        }

        /* Lo que sí se exige, porque no es historial sino situación de
           hoy: nada vencido y ningún expediente de cobro abierto. Una
           oferta a quien se le está cobrando sería una contradicción
           salida del mismo sistema. */
        $vencidas = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM cuotas v
               JOIN creditos cv ON cv.id = v.credito_id
              WHERE cv.usuario_id = ?
                AND v.estado NOT IN ("pagada","condonada")
                AND v.fecha_vencimiento < CURDATE()',
            [$usuarioId]
        );

        if ($vencidas > 0) {
            throw new \RuntimeException(
                'Tiene ' . $vencidas . ' cuota(s) vencida(s). Primero se pone al día.'
            );
        }

        $casos = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM cobranza_casos WHERE usuario_id = ? AND cerrado_en IS NULL',
            [$usuarioId]
        );

        if ($casos > 0) {
            throw new \RuntimeException(
                'Tiene ' . $casos . ' expediente(s) de cobro abierto(s).'
            );
        }

        /* Si además está inhabilitado por atraso, la oferta por
           decisión SÍ puede otorgarse —es su razón de ser— pero queda
           dicho en los criterios, con la fecha hasta la que pesaba.
           Dentro de dos años, la pregunta «¿por qué se le ofreció a
           éste?» tiene que poder responderse sola. */
        $eleg = (new ServicioElegibilidad())->de($usuarioId);

        if (!$eleg['puede'] && $eleg['hasta'] !== null) {
            $motivo .= ' Se otorga pese a que su historial lo mantenía inhabilitado '
                     . 'hasta el ' . (new \DateTimeImmutable($eleg['hasta']))->format('d/m/Y')
                     . ' (' . $eleg['motivo'] . ').';
        }

        $c['por_decision'] = $motivo;

        $hechas = [];

        foreach ($this->ofertasPara($c) as $oferta) {
            $hechas[] = $oferta;

            if ($enviar) {
                $this->avisarOferta((int) $oferta['id']);
            }
        }

        Auditoria::registrar(
            'oferta.por_decision',
            Auditoria::EXITO,
            $this->actorId(),
            'usuario',
            (string) $c['usuario'],
            ['ofertas' => count($hechas), 'motivo' => $motivo]
        );

        return ['ofertas' => count($hechas), 'detalle' => $hechas, 'motivo' => $motivo];
    }

    /**
     * Quién es «buena paga».
     *
     * No es una etiqueta que alguien ponga a mano: sale del historial.
     * Tres condiciones, todas comprobables:
     *
     *   1. Ha pagado al menos N cuotas. Un cliente con dos cuotas
     *      pagadas no tiene historial, tiene suerte.
     *   2. Ninguna de esas cuotas se pagó con más de X días de atraso.
     *      El promedio engaña: un solo atraso de noventa días dice más
     *      que veinte pagos puntuales.
     *   3. Hoy no debe nada vencido ni tiene expediente de cobro
     *      abierto. Ofrecerle crédito nuevo a quien se está cobrando es
     *      lo que convierte una cartera sana en una incobrable.
     *
     * @return list<array<string,mixed>>
     */
    private function buenaPaga(?int $soloUsuario = null): array
    {
        $minCuotas = max(1, Parametros::entero('oferta.min_cuotas_pagadas', 6));
        $maxMora   = max(0, Parametros::entero('oferta.max_dias_mora', 5));

        $args  = [$maxMora, $minCuotas];
        $donde = '';

        if ($soloUsuario !== null) {
            $donde = ' AND u.id = ?';
            $args[] = $soloUsuario;
        }

        return BaseDatos::todas(
            'SELECT u.id, u.usuario, u.correo,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente,
                    COALESCE(d.ingreso_mensual_cent,0) AS ingreso_cent,
                    COUNT(q.id)       AS cuotas_pagadas,
                    MAX(GREATEST(0, DATEDIFF(q.pagada_en, q.fecha_vencimiento))) AS atraso_max,
                    MIN(c.desembolsado_en) AS cliente_desde
               FROM usuarios u
               JOIN roles r        ON r.id = u.rol_id AND r.nombre = "Cliente"
               LEFT JOIN detalle_usuarios d ON d.usuario_id = u.id
               JOIN creditos c     ON c.usuario_id = u.id
               JOIN cuotas   q     ON q.credito_id = c.id AND q.estado = "pagada"
                                  AND q.pagada_en IS NOT NULL
              WHERE u.estado = "activo"
                AND NOT EXISTS (
                      SELECT 1 FROM cuotas v
                       JOIN creditos cv ON cv.id = v.credito_id
                      WHERE cv.usuario_id = u.id
                        AND v.estado NOT IN ("pagada","condonada")
                        AND v.fecha_vencimiento < CURDATE())
                AND NOT EXISTS (
                      SELECT 1 FROM cobranza_casos k
                       WHERE k.usuario_id = u.id AND k.cerrado_en IS NULL)
              GROUP BY u.id
             HAVING atraso_max <= ? AND cuotas_pagadas >= ?' . $donde . '
              ORDER BY cuotas_pagadas DESC',
            $args
        );
    }

    /**
     * Las ofertas que le caben a un cliente.
     *
     * @param array<string,mixed> $c
     * @return list<array<string,mixed>>
     */
    private function ofertasPara(array $c): array
    {
        $usuarioId = (int) $c['id'];
        $ingreso   = Monto::deCentavos((int) $c['ingreso_cent']);

        // Sin ingreso declarado no hay capacidad que calcular, y una
        // preaprobación «a ojo» es justo lo que no puede existir.
        if (!$ingreso->esPositivo()) {
            return [];
        }

        $maxVigentes = max(1, Parametros::entero('oferta.max_vigentes', 2));
        $vigentes    = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM ofertas_preaprobadas
              WHERE usuario_id = ? AND estado = "vigente"',
            [$usuarioId]
        );

        if ($vigentes >= $maxVigentes) {
            return [];
        }

        /* Un descanso tras aceptar.
           Sin esto, quien acepta un credito recibe otra oferta al dia
           siguiente: su capacidad bajo, pero todavia queda margen y el
           calculo lo vuelve a encontrar. Eso no es ofrecer, es empujar.
           Quien acaba de endeudarse necesita tiempo para demostrar que
           paga lo nuevo antes de que se le ofrezca mas. */
        $descanso = max(0, Parametros::entero('oferta.dias_descanso', 90));

        if ($descanso > 0) {
            $reciente = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM ofertas_preaprobadas
                  WHERE usuario_id = ? AND estado = "aceptada"
                    AND aceptada_en > NOW() - INTERVAL ? DAY',
                [$usuarioId, $descanso]
            );

            if ($reciente > 0) {
                return [];
            }
        }

        $compromisos = $this->compromisos($usuarioId);
        $margen      = max(1, Parametros::entero('oferta.margen_bp', 8000)) / 10000;

        $criterios = [
            'cuotas_pagadas' => (int) $c['cuotas_pagadas'],
            'atraso_maximo_dias' => (int) $c['atraso_max'],
            'cliente_desde'  => (string) $c['cliente_desde'],
            'sin_mora_hoy'   => true,
            'sin_cobranza'   => true,
        ];

        /* Si la oferta la decidio una persona, se dice. Sin esto, una
           oferta otorgada por criterio humano quedaria indistinguible
           de una que salio del barrido, y dentro de seis meses nadie
           podria explicar por que se otorgo. */
        if (trim((string) ($c['por_decision'] ?? '')) !== '') {
            $criterios['por_decision'] = trim((string) $c['por_decision']);
        }

        $hechas = [];

        foreach (['credito', 'tarjeta', 'extrafinanciamiento'] as $tipo) {
            if (count($hechas) + $vigentes >= $maxVigentes) {
                break;
            }

            // No se repite una oferta del mismo tipo que ya está viva.
            $yaTiene = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM ofertas_preaprobadas
                  WHERE usuario_id = ? AND tipo = ? AND estado = "vigente"',
                [$usuarioId, $tipo]
            );

            if ($yaTiene > 0) {
                continue;
            }

            $o = match ($tipo) {
                'credito' => $this->armarCredito(
                    $usuarioId, $ingreso, $compromisos, $margen, $criterios, $c
                ),
                'tarjeta' => $this->armarTarjeta(
                    $usuarioId, $ingreso, $compromisos, $margen, $criterios, $c
                ),
                default   => $this->armarExtra($usuarioId, $criterios),
            };

            if ($o !== null) {
                $hechas[] = $o;
            }
        }

        return $hechas;
    }

    /**
     * @param array<string,mixed> $criterios
     * @param array<string,mixed> $c
     * @return array<string,mixed>|null
     */
    private function armarCredito(
        int $usuarioId,
        Monto $ingreso,
        Monto $compromisos,
        float $margen,
        array $criterios,
        array $c
    ): ?array {
        $producto = BaseDatos::unaFila(
            'SELECT * FROM productos
              WHERE tipo = "credito" AND activo = 1
              ORDER BY tasa_interes_bp ASC LIMIT 1'
        );

        if ($producto === null) {
            return null;
        }

        $plazo = max(3, Parametros::entero('oferta.plazo_credito', 36));

        /* La tasa que le corresponde A ESTE CLIENTE, no la de catálogo.
         *
         * Una oferta es una promesa, y el cliente decide sobre la cifra
         * que ve. Aplicar el descuento solo al firmar le haría decidir
         * sobre una tasa peor que la real.
         *
         * Se recorta al rango del producto: un descuento no puede sacar
         * la tasa por debajo del mínimo pactado con la
         * Superintendencia. */
        $tasa = $this->tasaDelCliente($usuarioId, $producto);

        $maximo = CapacidadPago::montoMaximo($ingreso, $compromisos, $tasa, $plazo);

        // Del techo se ofrece una parte, no todo: dejar al cliente
        // exactamente en su límite es ponerlo a un imprevisto de la mora.
        $ofrecido = Monto::deCentavos((int) floor($maximo->centavos * $margen));

        // A centenas de dólar: una oferta se publica redonda.
        $ofrecido = Monto::deCentavos(intdiv($ofrecido->centavos, 10000) * 10000);

        $minimo = max(10000, Parametros::entero('oferta.credito_minimo_cent', 50000));

        if ($ofrecido->centavos < $minimo) {
            return null;
        }

        $cuota = (new Amortizacion($ofrecido, $tasa, $plazo))->cuota();

        return $this->guardar([
            'usuario_id'  => $usuarioId,
            'tipo'        => 'credito',
            'producto_id' => (int) $producto['id'],
            'monto_cent'  => $ofrecido->centavos,
            'plazo_meses' => $plazo,
            'tasa_bp'     => $tasa,
            'cuota_cent'  => $cuota->centavos,
            'ingreso_cent'     => $ingreso->centavos,
            'compromisos_cent' => $compromisos->centavos,
            'capacidad_cent'   => $maximo->centavos,
            'motivo' => 'Historial de pago: ' . (int) $c['cuotas_pagadas']
                      . ' cuotas pagadas, atraso maximo '
                      . (int) $c['atraso_max'] . ' dia(s).',
            'criterios' => $criterios,
        ]);
    }

    /**
     * @param array<string,mixed> $criterios
     * @param array<string,mixed> $c
     * @return array<string,mixed>|null
     */
    private function armarTarjeta(
        int $usuarioId,
        Monto $ingreso,
        Monto $compromisos,
        float $margen,
        array $criterios,
        array $c
    ): ?array {
        // Quien ya tiene tarjeta de crédito activa no necesita que le
        // ofrezcan otra: lo que necesitaría es más línea, y eso es una
        // ampliación, no una emisión.
        $yaTiene = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM tarjetas
              WHERE usuario_id = ? AND tipo = "credito" AND estado = "activa"',
            [$usuarioId]
        );

        if ($yaTiene > 0) {
            return null;
        }

        $producto = BaseDatos::unaFila(
            'SELECT * FROM productos
              WHERE tipo = "tarjeta" AND activo = 1
              ORDER BY nivel ASC, id ASC LIMIT 1'
        );

        $techo    = CapacidadPago::limiteTarjeta($ingreso, $compromisos);
        $ofrecido = Monto::deCentavos(
            intdiv((int) floor($techo->centavos * $margen), 10000) * 10000
        );

        $minimo = max(10000, Parametros::entero('oferta.tarjeta_minima_cent', 30000));

        if ($ofrecido->centavos < $minimo) {
            return null;
        }

        return $this->guardar([
            'usuario_id'  => $usuarioId,
            'tipo'        => 'tarjeta',
            'producto_id' => $producto !== null ? (int) $producto['id'] : null,
            'monto_cent'  => $ofrecido->centavos,
            'plazo_meses' => 0,
            /* Tambien la de tarjeta: su interes revolvente lleva el
               descuento del segmento en cada corte, asi que anunciar
               la de catalogo seria decirle al cliente una tasa peor
               de la que se le va a cobrar. */
            'tasa_bp'     => $producto !== null
                ? $this->tasaDelCliente($usuarioId, $producto)
                : 0,
            'cuota_cent'  => 0,
            'ingreso_cent'     => $ingreso->centavos,
            'compromisos_cent' => $compromisos->centavos,
            'capacidad_cent'   => $techo->centavos,
            'motivo' => 'Historial de pago: ' . (int) $c['cuotas_pagadas']
                      . ' cuotas pagadas, atraso maximo '
                      . (int) $c['atraso_max'] . ' dia(s).',
            'criterios' => $criterios,
        ]);
    }

    /**
     * Quien genera, cuando hay alguien.
     *
     * La evaluacion corre tambien por lotes, de madrugada y sin
     * nadie delante. `Sesion::usuarioId()` arranca la sesion para
     * responder, y en consola eso revienta el proceso entero por
     * una columna que admite nulo: la oferta la decide la politica
     * de credito, no una persona.
     */
    private function actorId(): ?int
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }

        try {
            return Sesion::usuarioId() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * La oferta de extrafinanciamiento.
     *
     * No sale de la capacidad general como las otras dos: sale del
     * historial de UNA tarjeta concreta —sus cortes, su puntualidad, su
     * uso de la línea—, porque es crédito de esa tarjeta. El servicio
     * que lo evalúa es el mismo que luego lo otorga, así que lo que se
     * ofrece y lo que se entrega no pueden separarse.
     *
     * @param array<string,mixed> $criterios
     * @return array<string,mixed>|null
     */
    private function armarExtra(int $usuarioId, array $criterios): ?array
    {
        $svc = new ServicioExtrafinanciamiento();

        $tarjetas = BaseDatos::todas(
            'SELECT id, ultimos4 FROM tarjetas
              WHERE usuario_id = ? AND tipo = "credito" AND estado = "activa"
              ORDER BY limite_cent DESC',
            [$usuarioId]
        );

        foreach ($tarjetas as $t) {
            $r = $svc->evaluar((int) $t['id']);

            if (empty($r['califica'])) {
                continue;
            }

            // Sin oferta viva sobre la misma tarjeta.
            $ya = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM ofertas_preaprobadas
                  WHERE tarjeta_origen_id = ? AND estado = "vigente"',
                [(int) $t['id']]
            );

            if ($ya > 0) {
                continue;
            }

            return $this->guardar([
                'usuario_id'  => $usuarioId,
                'tipo'        => 'extrafinanciamiento',
                'producto_id' => null,
                'tarjeta_id'  => (int) $t['id'],
                'monto_cent'  => (int) $r['monto_cent'],
                'plazo_meses' => (int) $r['plazo_meses'],
                'tasa_bp'     => (int) $r['tasa_bp'],
                'cuota_cent'  => (int) $r['cuota_cent'],
                'ingreso_cent'     => 0,
                'compromisos_cent' => 0,
                'capacidad_cent'   => (int) $r['capacidad_cent'],
                'motivo'      => (string) $r['motivo'],
                'criterios'   => $criterios + [
                    'tarjeta'        => '****' . $t['ultimos4'],
                    'cortes_tarjeta' => (int) $r['cortes'],
                    'uso_linea_pct'  => round((int) $r['uso_bp'] / 100, 1),
                ],
            ]);
        }

        return null;
    }
    /**
     * @param array<string,mixed> $d
     * @return array<string,mixed>
     */
    private function guardar(array $d): array
    {
        $dias  = max(1, Parametros::entero('oferta.vigencia_dias', 30));
        $folio = Folio::unico('OFE', 'ofertas_preaprobadas');

        BaseDatos::ejecutar(
            'INSERT INTO ofertas_preaprobadas
               (uuid, folio, usuario_id, tipo, producto_id, tarjeta_origen_id,
                monto_cent, plazo_meses,
                tasa_bp, cuota_cent, ingreso_cent, compromisos_cent, capacidad_cent,
                motivo, criterios_json, vence_el, creada_por)
             VALUES (UUID(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,
                     DATE_ADD(CURDATE(), INTERVAL ? DAY), ?)',
            [
                $folio, $d['usuario_id'], $d['tipo'], $d['producto_id'],
                $d['tarjeta_id'] ?? null,
                $d['monto_cent'], $d['plazo_meses'], $d['tasa_bp'], $d['cuota_cent'],
                $d['ingreso_cent'], $d['compromisos_cent'], $d['capacidad_cent'],
                mb_substr((string) $d['motivo'], 0, 255),
                json_encode($d['criterios'], JSON_UNESCAPED_UNICODE),
                $dias, $this->actorId(),
            ]
        );

        $id = BaseDatos::ultimoId();

        Auditoria::registrar('oferta.generada', Auditoria::EXITO,
            $this->actorId(), 'oferta', (string) $id,
            ['folio' => $folio, 'tipo' => $d['tipo'],
             'monto' => Monto::deCentavos((int) $d['monto_cent'])->aDecimal()]);

        return ['id' => $id, 'folio' => $folio] + $d;
    }

    // =================================================================
    // ACEPTACIÓN
    // =================================================================

    /**
     * El cliente acepta: se le otorga en el acto.
     *
     * La preaprobación es la autorización —la institución ya decidió, y
     * dejó por escrito con qué criterios—, así que aquí no se pide otra
     * aprobación. Lo que sí se vuelve a hacer es la cuenta: entre la
     * oferta y la aceptación pueden pasar semanas, y en ese tiempo el
     * cliente pudo tomar otro crédito o llenar su tarjeta.
     *
     * @return array<string,mixed>
     */
    /**
     * Los plazos, con lo que cuesta cada uno, para el correo.
     *
     * No es una lista de números: es la decisión puesta en cifras. El
     * plazo largo baja la cuota y sube el costo, y las dos cosas
     * tienen que verse juntas o la elección no es informada. Con solo
     * la cuota delante, el plazo más largo parece siempre el mejor.
     *
     * Se marca el plazo de referencia —el que se usó para evaluar la
     * capacidad— pero sin presentarlo como el elegido: no lo es.
     *
     * Los que no caben en la capacidad de pago del cliente NO se
     * ofrecen. Enseñar una cuota que se va a rechazar al aceptar es
     * una promesa que el sistema ya sabe que no va a cumplir.
     *
     * @param array<string,mixed> $o
     */
    private function tablaDePlazos(array $o): string
    {
        $monto = Monto::deCentavos((int) $o['monto_cent']);
        $tasa  = (int) $o['tasa_bp'];
        $ref   = (int) $o['plazo_meses'];

        $e      = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $dinero = static fn(int $c): string => Monto::deCentavos($c)->formateado();

        /* Cuánto le cabe al mes, para no ofrecer lo que no puede
           pagar. Si no se puede averiguar, se ofrecen todos: es mejor
           eso que no ofrecer ninguno. */
        $techo = 0;

        try {
            /* `capacidadAlDia` devuelve [cabe, techo, explicacion].
               Leerlo como mapa daba cero siempre, y con cero se
               ofrecian todos los plazos, incluidos los que el
               cliente no puede pagar. */
            [, $techo] = $this->capacidadAlDia($o);
            $techo = (int) $techo;
        } catch (\Throwable) {
            $techo = 0;
        }

        $filas = '';
        $n     = 0;

        foreach (self::plazosPosibles() as $plazo) {
            $cuota = (new Amortizacion($monto, $tasa, $plazo))->cuota()->centavos;
            $total = $cuota * $plazo;
            $costo = max(0, $total - (int) $o['monto_cent']);

            if ($techo > 0 && $cuota > $techo) { continue; }

            $esRef = $plazo === $ref;

            $filas .=
                '<tr style="' . ($esRef ? 'background:#faf5ff' : '') . '">'
                . '<td style="padding:8px 10px;border-bottom:1px solid #eef1f5;font-size:14px">'
                . '<strong>' . $plazo . '</strong> meses'
                . ($esRef ? ' <span style="color:#7b00ff;font-size:12px">(referencia)</span>' : '')
                . '</td>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #eef1f5;font-size:14px;text-align:right">'
                . '<strong>' . $e($dinero($cuota)) . '</strong></td>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #eef1f5;font-size:14px;text-align:right;color:#667085">'
                . $e($dinero($costo)) . '</td>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #eef1f5;font-size:14px;text-align:right;color:#667085">'
                . $e($dinero($total)) . '</td>'
                . '</tr>';

            $n++;
        }

        if ($n === 0) { return ''; }

        return '<p style="margin-top:22px"><strong>Elija el plazo</strong></p>'
             . '<p style="font-size:14px;color:#475467;margin:0 0 10px">'
             . 'La cuota no incluye las primas de seguro. El costo del financiamiento '
             . 'es lo que paga de más por tomar el crédito a ese plazo.</p>'
             . '<table role="presentation" cellpadding="0" cellspacing="0" '
             . 'style="width:100%;border-collapse:collapse;margin:0 0 6px">'
             . '<thead><tr>'
             . '<th style="padding:8px 10px;border-bottom:2px solid #e4e7ec;font-size:12px;'
             . 'text-transform:uppercase;letter-spacing:.04em;color:#667085;text-align:left">Plazo</th>'
             . '<th style="padding:8px 10px;border-bottom:2px solid #e4e7ec;font-size:12px;'
             . 'text-transform:uppercase;letter-spacing:.04em;color:#667085;text-align:right">Cuota</th>'
             . '<th style="padding:8px 10px;border-bottom:2px solid #e4e7ec;font-size:12px;'
             . 'text-transform:uppercase;letter-spacing:.04em;color:#667085;text-align:right">Costo</th>'
             . '<th style="padding:8px 10px;border-bottom:2px solid #e4e7ec;font-size:12px;'
             . 'text-transform:uppercase;letter-spacing:.04em;color:#667085;text-align:right">Total</th>'
             . '</tr></thead><tbody>' . $filas . '</tbody></table>'
             . '<p style="font-size:14px;color:#475467;margin:10px 0 0">'
             . 'Al entrar a su banca en línea podrá ver estas mismas cifras, probar '
             . 'cualquier plazo y aceptar con el que elija. Ninguno está escogido de '
             . 'antemano.</p>';
    }

    /**
     * Los plazos entre los que puede elegir una oferta de crédito.
     *
     * Son los habituales del producto. Se exponen para que la pantalla
     * los ofrezca y para validar lo que llegue: el plazo viaja desde el
     * navegador, y un plazo de 400 meses convertiría una oferta en algo
     * que nadie aprobó.
     *
     * @return list<int>
     */
    public static function plazosPosibles(): array
    {
        // Hasta diez anos. Un plazo largo baja la cuota a costa de
        // mas intereses, y esa es justamente la decision que se le
        // deja al cliente con las cifras de cada opcion delante.
        return [12, 18, 24, 36, 48, 60, 72, 84, 96, 120];
    }

    /**
     * Cómo queda la oferta con otro plazo.
     *
     * El monto y la tasa no se tocan —salieron de su capacidad y de su
     * perfil—; lo que cambia es la cuota y, con ella, los intereses
     * totales. Es lo que el cliente necesita ver para decidir.
     *
     * @return array{plazo:int, cuota_cent:int, total_cent:int, interes_cent:int, cabe:bool}
     */
    public function simularPlazo(int $ofertaId, int $plazo): array
    {
        $o = BaseDatos::unaFila(
            'SELECT * FROM ofertas_preaprobadas WHERE id = ?', [$ofertaId]
        );

        if ($o === null) {
            throw new \RuntimeException('Oferta no encontrada.');
        }

        if (!in_array($plazo, self::plazosPosibles(), true)) {
            throw new \InvalidArgumentException('Ese plazo no está disponible.');
        }

        $monto = Monto::deCentavos((int) $o['monto_cent']);
        $cuota = (new Amortizacion($monto, (int) $o['tasa_bp'], $plazo))->cuota();

        $total = $cuota->centavos * $plazo;

        /* ¿Le cabe esa cuota HOY? Acortar el plazo sube la cuota, y una
           oferta aprobada a 36 meses puede no caber a 12. */
        $ingreso = Monto::deCentavos((int) BaseDatos::unValor(
            'SELECT COALESCE(ingreso_mensual_cent,0) FROM detalle_usuarios WHERE usuario_id = ?',
            [(int) $o['usuario_id']]
        ));

        $cuotaMax = CapacidadPago::cuotaMaxima(
            $ingreso, $this->compromisos((int) $o['usuario_id'])
        );

        return [
            'plazo'        => $plazo,
            'cuota_cent'   => $cuota->centavos,
            'total_cent'   => $total,
            'interes_cent' => max(0, $total - $monto->centavos),
            'cabe'         => $cuotaMax->centavos >= $cuota->centavos,
            'cuota_maxima_cent' => $cuotaMax->centavos,
        ];
    }

    /**
     * @param int|null $plazoElegido El plazo que el cliente prefiere.
     *        Nulo deja el de la oferta. Se valida contra la lista, no
     *        se acepta lo que llegue.
     */
    public function aceptar(int $ofertaId, ?int $plazoElegido = null): array
    {
        $actorId = Sesion::exigirAutenticado();

        $resultado = BaseDatos::enTransaccion(function () use ($ofertaId, $actorId, $plazoElegido) {
            $o = BaseDatos::unaFila(
                'SELECT * FROM ofertas_preaprobadas WHERE id = ? FOR UPDATE',
                [$ofertaId]
            );

            if ($o === null || (int) $o['usuario_id'] !== $actorId) {
                throw new \RuntimeException('Oferta no encontrada.');
            }

            if ($o['estado'] !== 'vigente') {
                throw new \RuntimeException(
                    'Esta oferta ya no está vigente (' . $o['estado'] . ').'
                );
            }

            if (strtotime((string) $o['vence_el']) < strtotime(date('Y-m-d'))) {
                $this->cerrar($ofertaId, 'caducada', 'Venció el ' . $o['vence_el'] . '.');
                throw new \RuntimeException(
                    'Esta oferta venció el '
                    . date('d/m/Y', strtotime((string) $o['vence_el'])) . '.'
                );
            }

            /* El plazo que eligió el cliente.
             *
             * Solo tiene sentido en un crédito: una tarjeta no tiene
             * plazo, y el extrafinanciamiento lo fija el servicio que
             * lo otorga. Se recalcula la cuota con el plazo nuevo ANTES
             * de medir la capacidad, porque acortar el plazo la sube y
             * una oferta que cabía a 36 meses puede no caber a 12. */
            if ($plazoElegido !== null
                && (string) $o['tipo'] === 'credito'
                && $plazoElegido !== (int) $o['plazo_meses']) {

                if (!in_array($plazoElegido, self::plazosPosibles(), true)) {
                    throw new \InvalidArgumentException('Ese plazo no está disponible.');
                }

                $o['plazo_meses'] = $plazoElegido;
                $o['cuota_cent']  = (new Amortizacion(
                    Monto::deCentavos((int) $o['monto_cent']),
                    (int) $o['tasa_bp'],
                    $plazoElegido
                ))->cuota()->centavos;

                // La oferta guarda lo que de verdad se aceptó: si
                // mañana alguien revisa el expediente, tiene que leer
                // el plazo que el cliente eligió, no el del folleto.
                BaseDatos::ejecutar(
                    'UPDATE ofertas_preaprobadas SET plazo_meses = ?, cuota_cent = ?
                      WHERE id = ?',
                    [$plazoElegido, (int) $o['cuota_cent'], $ofertaId]
                );
            }

            /* La capacidad, hoy. Si ya no da, no se otorga de menos por
               nuestra cuenta: se cierra la oferta diciendo por qué y se
               deja constancia de las dos cifras. Rebajar en silencio lo
               que se ofreció por escrito es cambiar el trato. */
            [$cabe, $hoy, $detalle] = $this->capacidadAlDia($o);

            if (!$cabe) {
                $this->cerrar($ofertaId, 'caducada', $detalle);

                throw new \RuntimeException(
                    'Sus compromisos actuales ya no admiten esta oferta. ' . $detalle
                    . ' Podemos revisar un monto menor cuando guste.'
                );
            }

            $r = match ($o['tipo']) {
                'credito' => $this->otorgarCredito($o, $actorId),
                'tarjeta' => $this->otorgarTarjeta($o, $actorId),
                default   => $this->otorgarExtra($o, $actorId),
            };

            BaseDatos::ejecutar(
                'UPDATE ofertas_preaprobadas
                    SET estado = "aceptada", aceptada_en = NOW(),
                        credito_id = ?, tarjeta_id = ?
                  WHERE id = ?',
                [$r['credito_id'] ?? null, $r['tarjeta_id'] ?? null, $ofertaId]
            );

            Auditoria::registrar('oferta.aceptada', Auditoria::EXITO, $actorId,
                'oferta', (string) $ofertaId,
                ['folio' => $o['folio'], 'tipo' => $o['tipo'],
                 'capacidad_al_aceptar' => Monto::deCentavos($hoy)->aDecimal()]);

            return ['folio' => $o['folio'], 'tipo' => $o['tipo']] + $r;
        });

        /* El acuse va DESPUES de confirmar, nunca dentro.
           Enviarlo dentro de la transaccion deja las filas bloqueadas
           mientras se habla con el servidor de correo: si este tarda,
           la peticion se corta y el cliente no sabe si se otorgo o no,
           con el credito a medio camino. Primero se confirma el hecho,
           luego se cuenta. */
        $this->avisarOtorgado($ofertaId, $resultado);

        return $resultado;
    }

    /**
     * ¿Sigue cabiendo hoy lo que se ofreció?
     *
     * @param array<string,mixed> $o
     * @return array{0:bool, 1:int, 2:string}
     */
    private function capacidadAlDia(array $o): array
    {
        $ingreso = Monto::deCentavos((int) BaseDatos::unValor(
            'SELECT COALESCE(ingreso_mensual_cent,0) FROM detalle_usuarios WHERE usuario_id = ?',
            [(int) $o['usuario_id']]
        ));

        $compromisos = $this->compromisos((int) $o['usuario_id']);

        if ($o['tipo'] === 'credito') {
            $cuotaMax = CapacidadPago::cuotaMaxima($ingreso, $compromisos);
            $cabe     = $cuotaMax->centavos >= (int) $o['cuota_cent'];

            return [
                $cabe,
                $cuotaMax->centavos,
                'La cuota de esta oferta es ' . Monto::deCentavos((int) $o['cuota_cent'])->formateado()
                . ' y hoy su capacidad admite hasta ' . $cuotaMax->formateado() . ' al mes.',
            ];
        }

        $techo = CapacidadPago::limiteTarjeta($ingreso, $compromisos);
        $cabe  = $techo->centavos >= (int) $o['monto_cent'];

        return [
            $cabe,
            $techo->centavos,
            'La línea de esta oferta es ' . Monto::deCentavos((int) $o['monto_cent'])->formateado()
            . ' y hoy su capacidad admite hasta ' . $techo->formateado() . '.',
        ];
    }

    /**
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    private function otorgarCredito(array $o, int $actorId): array
    {
        $usuarioId = (int) $o['usuario_id'];

        $cuenta = BaseDatos::unaFila(
            'SELECT id, numero_cuenta FROM cuentas
              WHERE usuario_id = ? AND es_tecnica = 0 AND estado = "activa"
              ORDER BY id LIMIT 1',
            [$usuarioId]
        );

        if ($cuenta === null) {
            throw new \RuntimeException(
                'Para desembolsar necesita una cuenta de ahorro activa. '
                . 'Ábrala y vuelva a aceptar la oferta; sigue vigente.'
            );
        }

        // La solicitud se deja registrada igual: el crédito tiene que
        // poder decir de dónde vino, y «de una oferta preaprobada» es
        // una respuesta, pero sin expediente no hay dónde escribirla.
        $folioSol = Folio::unico('SOL', 'solicitudes_credito');

        BaseDatos::ejecutar(
            'INSERT INTO solicitudes_credito
               (uuid, folio, usuario_id, producto_id, monto_cent, plazo_meses, tasa_bp,
                destino, ingreso_cent, etapa, observaciones)
             VALUES (UUID(),?,?,?,?,?,?,?,?, "aprobada", ?)',
            [
                $folioSol, $usuarioId, (int) $o['producto_id'],
                (int) $o['monto_cent'], (int) $o['plazo_meses'], (int) $o['tasa_bp'],
                'Oferta preaprobada ' . $o['folio'],
                (int) $o['ingreso_cent'],
                'Preaprobado por historial de pago. ' . $o['motivo'],
            ]
        );

        $solicitudId = BaseDatos::ultimoId();

        $creditoId = (new ServicioCredito())->crearCredito(
            [
                'id' => $solicitudId,
                'usuario_id' => $usuarioId,
                'producto_id' => (int) $o['producto_id'],
                'monto_cent' => (int) $o['monto_cent'],
                'plazo_meses' => (int) $o['plazo_meses'],
                'tasa_bp' => (int) $o['tasa_bp'],
                'destino' => 'Oferta preaprobada ' . $o['folio'],
            ],
            $actorId
        );

        BaseDatos::ejecutar(
            'UPDATE solicitudes_credito SET credito_id = ? WHERE id = ?',
            [$creditoId, $solicitudId]
        );

        // --- El desembolso -------------------------------------------
        $monto   = Monto::deCentavos((int) $o['monto_cent']);
        $cuentaId = (int) $cuenta['id'];
        $tecnica = (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
        );

        LibroMayor::bloquearCuentas([$cuentaId, $tecnica]);

        $folioCredito = (string) BaseDatos::unValor(
            'SELECT folio FROM creditos WHERE id = ?', [$creditoId]
        );

        $asiento = LibroMayor::registrar(
            'deposito',
            [
                ['cuenta_id' => $cuentaId, 'importe' => $monto,
                 'descripcion' => 'Desembolso de crédito ' . $folioCredito],
                ['cuenta_id' => $tecnica, 'importe' => $monto->negado(),
                 'descripcion' => 'Contrapartida de desembolso'],
            ],
            $actorId,
            'oferta-' . $o['id'] . '-credito-' . $creditoId,
            'Desembolso ' . $folioCredito . ' (oferta ' . $o['folio'] . ')'
        );

        BaseDatos::ejecutar(
            'UPDATE creditos SET estado = "vigente", desembolsado_en = NOW(),
                    cuenta_desembolso_id = ? WHERE id = ?',
            [$cuentaId, $creditoId]
        );

        BaseDatos::ejecutar(
            'UPDATE solicitudes_credito SET etapa = "desembolsada" WHERE id = ?',
            [$solicitudId]
        );

        Auditoria::registrar('oferta.credito_otorgado', Auditoria::EXITO, $actorId,
            'credito', (string) $creditoId,
            ['oferta' => $o['folio'], 'folio' => $folioCredito,
             'monto' => $monto->aDecimal(), 'cuenta' => $cuenta['numero_cuenta']]);

        $this->notificaciones->crear(
            $usuarioId, 'credito.desembolso', 'Crédito preaprobado otorgado',
            'Su crédito ' . $folioCredito . ' por ' . $monto->formateado()
            . ' quedó desembolsado en la cuenta ' . $cuenta['numero_cuenta'] . '.',
            ['referencia' => $asiento['referencia']], 'exito'
        );

        return [
            'credito_id' => $creditoId,
            'folio_credito' => $folioCredito,
            'cuenta' => (string) $cuenta['numero_cuenta'],
            'referencia' => $asiento['referencia'],
            'monto' => $monto->jsonSerialize(),
        ];
    }

    /**
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    private function otorgarTarjeta(array $o, int $actorId): array
    {
        $r = (new EmisorTarjeta())->emitir(
            (int) $o['usuario_id'],
            'visa',
            'credito',
            Monto::deCentavos((int) $o['monto_cent']),
            $o['producto_id'] !== null ? (int) $o['producto_id'] : null,
            $actorId
        );

        $tarjetaId = (int) ($r['id'] ?? BaseDatos::ultimoId());

        Auditoria::registrar('oferta.tarjeta_otorgada', Auditoria::EXITO, $actorId,
            'tarjeta', (string) $tarjetaId,
            ['oferta' => $o['folio'],
             'limite' => Monto::deCentavos((int) $o['monto_cent'])->aDecimal()]);

        $this->notificaciones->crear(
            (int) $o['usuario_id'], 'tarjeta.emitida', 'Tarjeta preaprobada emitida',
            'Su tarjeta de crédito quedó emitida con una línea de '
            . Monto::deCentavos((int) $o['monto_cent'])->formateado() . '.',
            ['oferta' => (string) $o['folio']], 'exito'
        );

        return ['tarjeta_id' => $tarjetaId] + $r;
    }

    /**
     * Otorga el extrafinanciamiento ofrecido.
     *
     * Se vuelve a evaluar la tarjeta: entre la oferta y la aceptación
     * el cliente pudo gastar su línea, y entregar efectivo que ya no
     * cabe es prestar por encima de lo aprobado.
     *
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    private function otorgarExtra(array $o, int $actorId): array
    {
        $tarjetaId = (int) ($o['tarjeta_origen_id'] ?? 0);

        if ($tarjetaId === 0) {
            throw new \RuntimeException('La oferta no indica sobre qué tarjeta aplica.');
        }

        $svc = new ServicioExtrafinanciamiento();
        $hoy = $svc->evaluar($tarjetaId);

        if (empty($hoy['califica'])) {
            throw new \RuntimeException((string) $hoy['motivo']);
        }

        // Lo ofrecido, salvo que hoy quepa menos: nunca mas de lo que
        // su linea admite en este momento.
        $monto = min((int) $o['monto_cent'], (int) $hoy['monto_cent']);

        if ($monto < (int) $o['monto_cent']) {
            throw new \RuntimeException(
                'Su cupo disponible ya no admite los '
                . Monto::deCentavos((int) $o['monto_cent'])->formateado()
                . ' ofrecidos. Hoy podríamos entregarle hasta '
                . Monto::deCentavos((int) $hoy['monto_cent'])->formateado()
                . '; abone a su tarjeta o pídanos una oferta nueva.'
            );
        }

        $cuenta = BaseDatos::unaFila(
            'SELECT id FROM cuentas
              WHERE usuario_id = ? AND es_tecnica = 0 AND estado = "activa"
              ORDER BY id LIMIT 1',
            [(int) $o['usuario_id']]
        );

        if ($cuenta === null) {
            throw new \RuntimeException(
                'Para recibir el efectivo necesita una cuenta de ahorro activa.'
            );
        }

        $r = $svc->otorgar(
            [
                'tarjeta_id'  => $tarjetaId,
                'usuario_id'  => (int) $o['usuario_id'],
                'monto_cent'  => $monto,
                'plazo_meses' => (int) $o['plazo_meses'],
                'tasa_bp'     => (int) $o['tasa_bp'],
                'cuota_cent'  => (int) $o['cuota_cent'],
            ],
            (int) $cuenta['id'],
            $actorId,
            (int) $o['id']
        );

        $this->notificaciones->crear(
            (int) $o['usuario_id'], 'credito.desembolso',
            'Extrafinanciamiento acreditado',
            'Se acreditaron ' . Monto::deCentavos($monto)->formateado()
            . ' en su cuenta ' . $r['cuenta'] . '. La cuota de '
            . Monto::deCentavos((int) $r['cuota_cent'])->formateado()
            . ' aparecerá en el estado de cuenta de su tarjeta.',
            ['referencia' => (string) $r['referencia']], 'exito'
        );

        /* El folio propio viaja con otro nombre: arriba se compone
           ['folio' => el de la oferta] + esto, y el de la izquierda
           gana. Sin renombrarlo, el EXF se perdia por el camino.
           La tarjeta va para que la oferta aceptada deje dicho
           sobre cual se coloco. */
        $r['folio_extra'] = $r['folio'];
        $r['tarjeta_id']  = $tarjetaId;

        return $r;
    }
    // =================================================================
    // CIERRE
    // =================================================================

    /** El cliente la descarta. */
    public function rechazar(int $ofertaId, string $motivo = ''): array
    {
        $actorId = Sesion::exigirAutenticado();

        $o = BaseDatos::unaFila(
            'SELECT * FROM ofertas_preaprobadas WHERE id = ?', [$ofertaId]
        );

        if ($o === null || (int) $o['usuario_id'] !== $actorId) {
            throw new \RuntimeException('Oferta no encontrada.');
        }

        if ($o['estado'] !== 'vigente') {
            throw new \RuntimeException('Esta oferta ya no está vigente.');
        }

        $this->cerrar($ofertaId, 'rechazada', mb_substr($motivo, 0, 200));

        Auditoria::registrar('oferta.rechazada', Auditoria::EXITO, $actorId,
            'oferta', (string) $ofertaId, ['folio' => $o['folio']]);

        return ['folio' => $o['folio']];
    }

    /** La institución la retira antes de que se acepte. */
    public function anular(int $ofertaId, string $motivo): array
    {
        \CashMan\Infraestructura\Seguridad\Autorizacion::exigir('oferta.anular');
        $actorId = Sesion::exigirAutenticado();

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new \InvalidArgumentException('Diga por qué se anula la oferta.');
        }

        $o = BaseDatos::unaFila(
            'SELECT * FROM ofertas_preaprobadas WHERE id = ?', [$ofertaId]
        );

        if ($o === null) {
            throw new \RuntimeException('Oferta no encontrada.');
        }

        if ($o['estado'] !== 'vigente') {
            throw new \RuntimeException('Solo se anulan ofertas vigentes.');
        }

        $this->cerrar($ofertaId, 'anulada', mb_substr($motivo, 0, 200));

        Auditoria::registrar('oferta.anulada', Auditoria::EXITO, $actorId,
            'oferta', (string) $ofertaId,
            ['folio' => $o['folio'], 'motivo' => mb_substr($motivo, 0, 120)]);

        return ['folio' => $o['folio']];
    }

    /** Las que pasaron de fecha dejan de estar vivas. */
    public function caducarVencidas(): int
    {
        return BaseDatos::ejecutar(
            'UPDATE ofertas_preaprobadas
                SET estado = "caducada",
                    cerrada_motivo = "Vencio el plazo de la oferta."
              WHERE estado = "vigente" AND vence_el < CURDATE()'
        );
    }

    private function cerrar(int $ofertaId, string $estado, string $motivo): void
    {
        BaseDatos::ejecutar(
            'UPDATE ofertas_preaprobadas SET estado = ?, cerrada_motivo = ? WHERE id = ?',
            [$estado, mb_substr($motivo, 0, 255), $ofertaId]
        );
    }

    // =================================================================
    // CONSULTA
    // =================================================================

    /** @return list<array<string,mixed>> */
    public function vigentesDe(int $usuarioId): array
    {
        $this->caducarVencidas();

        return BaseDatos::todas(
            'SELECT o.*, p.nombre AS producto
               FROM ofertas_preaprobadas o
               LEFT JOIN productos p ON p.id = o.producto_id
              WHERE o.usuario_id = ? AND o.estado = "vigente"
              ORDER BY o.id DESC',
            [$usuarioId]
        );
    }

    /** Lo que ya paga cada mes: cuotas vigentes y mínimo de tarjetas. */
    private function compromisos(int $usuarioId): Monto
    {
        $creditos = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(cuota_cent),0) FROM creditos
              WHERE usuario_id = ? AND estado = "vigente"',
            [$usuarioId]
        );

        $pct = max(1, Parametros::entero('tarjeta.pago_minimo_pct', 500));

        $tarjetas = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(saldo_usado_cent),0) FROM tarjetas
              WHERE usuario_id = ? AND tipo = "credito" AND estado = "activa"',
            [$usuarioId]
        );

        return Monto::deCentavos($creditos + (int) round($tarjetas * $pct / 10000));
    }

    // =================================================================
    // CORREOS
    // =================================================================

    /**
     * La oferta, por correo.
     *
     * Tiene que poder leerse como lo que es: una propuesta en firme, con
     * sus números, su fecha de caducidad y la condición que la gobierna
     * —que la capacidad se vuelve a comprobar al aceptar—. Una oferta
     * sin esa letra es una promesa que luego se incumple en pantalla.
     */
    public function avisarOferta(int $ofertaId): bool
    {
        try {
            $o = $this->paraCorreo($ofertaId);

            if ($o === null) {
                return false;
            }

            $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $dinero = static fn(int $c): string => Monto::deCentavos($c)->formateado();

            $esCredito = $o['tipo'] === 'credito';
            $vence     = date('d/m/Y', strtotime((string) $o['vence_el']));
            $criterios = json_decode((string) $o['criterios_json'], true) ?: [];

            $cuerpo =
                '<p>Hemos revisado su historial con nosotros y le confirmamos una '
                . '<strong>oferta preaprobada</strong>. No necesita presentar solicitud '
                . 'ni esperar resolución: la decisión ya está tomada y solo falta que '
                . 'usted la acepte.</p>'

                . ($esCredito
                    ? '<p>Se trata de un <strong>crédito personal de '
                      . $e($dinero((int) $o['monto_cent'])) . '</strong> con una tasa del '
                      . number_format((int) $o['tasa_bp'] / 100, 2) . '% anual. '
                      . '<strong>El plazo lo elige usted</strong> al aceptar: abajo están '
                      . 'las opciones con la cuota y el costo de cada una. A la cuota se '
                      . 'suman las primas de seguro de vida y desempleo que el contrato '
                      . 'exige. Al aceptar, el monto se acredita en su cuenta de ahorro '
                      . 'el mismo momento.</p>'

                      . $this->tablaDePlazos($o)
                    : '<p>Se trata de una <strong>tarjeta de crédito con una línea de '
                      . $e($dinero((int) $o['monto_cent'])) . '</strong>. Al aceptar, la '
                      . 'tarjeta se emite en el acto y queda disponible en su banca en '
                      . 'línea; el plástico se le entrega después por los canales '
                      . 'habituales.</p>')

                . '<p style="margin-top:22px"><strong>Por qué se le ofrece</strong></p>'
                . '<p>Esta preaprobación no es una promoción general: sale de su propio '
                . 'historial de pago. A la fecha de la evaluación registraba '
                . '<strong>' . (int) ($criterios['cuotas_pagadas'] ?? 0) . ' cuotas '
                . 'pagadas</strong>, con un atraso máximo de '
                . '<strong>' . (int) ($criterios['atraso_maximo_dias'] ?? 0) . ' día(s)</strong>, '
                . 'sin cuotas vencidas y sin ninguna gestión de cobro abierta.</p>'

                . '<p style="margin-top:22px"><strong>Condiciones</strong></p>'
                . '<ul style="font-size:14px;color:#475467;padding-left:18px;margin:0">'
                . '<li>La oferta está vigente hasta el <strong>' . $e($vence) . '</strong>. '
                . 'Pasada esa fecha caduca automáticamente.</li>'
                . '<li>El <strong>plazo lo elige usted</strong> al aceptar, entre los de la tabla anterior. El que aparece como referencia es el que se usó para evaluar su capacidad de pago, no una elección hecha por nosotros.</li>'
                . '<li>Al aceptar <strong>se vuelve a verificar su capacidad de pago</strong> '
                . 'con sus compromisos de ese día. Si en el entretanto hubiera tomado otras '
                . 'obligaciones y el monto ya no le correspondiera, se lo diremos con las '
                . 'cifras a la vista en lugar de otorgarle algo distinto de lo ofrecido.</li>'
                . '<li>Aceptar es un acto voluntario y genera una obligación de pago '
                . 'exigible en los términos del contrato correspondiente.</li>'
                . '<li>No aceptar no tiene consecuencia alguna sobre sus productos '
                . 'actuales ni sobre su historial.</li>'
                . '</ul>'

                . '<p style="font-size:13px;color:#667085;margin-top:22px">Puede aceptarla '
                . 'o descartarla desde su banca en línea, en la sección de ofertas. Si no '
                . 'reconoce este mensaje o no solicitó esta evaluación, comuníquese con '
                . 'nosotros indicando el folio.</p>';

            $filas = [
                ['etiqueta' => 'Oferta',   'valor' => (string) $o['folio']],
                ['etiqueta' => 'Producto', 'valor' => $esCredito
                    ? 'Crédito personal preaprobado'
                    : 'Tarjeta de crédito preaprobada'],
            ];

            if ($esCredito) {
                $filas[] = ['etiqueta' => 'MONTO PREAPROBADO', 'valor' => $dinero((int) $o['monto_cent'])];
                $filas[] = ['etiqueta' => 'Plazo', 'valor' => (int) $o['plazo_meses'] . ' meses'];
                $filas[] = ['etiqueta' => 'Tasa anual', 'valor' => number_format((int) $o['tasa_bp'] / 100, 2) . '%'];
                $filas[] = ['etiqueta' => 'Cuota estimada', 'valor' => $dinero((int) $o['cuota_cent']) . ' al mes'];
            } else {
                $filas[] = ['etiqueta' => 'LÍNEA PREAPROBADA', 'valor' => $dinero((int) $o['monto_cent'])];
                $filas[] = ['etiqueta' => 'Tasa anual', 'valor' => number_format((int) $o['tasa_bp'] / 100, 2) . '%'];
            }

            $filas[] = ['etiqueta' => 'Vigente hasta', 'valor' => $vence];
            $filas[] = ['etiqueta' => 'Cuotas pagadas', 'valor' => (string) (int) ($criterios['cuotas_pagadas'] ?? 0)];
            $filas[] = ['etiqueta' => 'Atraso máximo', 'valor' => (int) ($criterios['atraso_maximo_dias'] ?? 0) . ' día(s)'];

            $texto = "OFERTA PREAPROBADA\n\n"
                . 'Senor(a) ' . $o['cliente'] . ":\n\n"
                . "Hemos revisado su historial y le confirmamos una oferta preaprobada.\n"
                . "No necesita presentar solicitud ni esperar resolucion.\n\n"
                . 'Oferta: ' . $o['folio'] . "\n"
                . ($esCredito
                    ? 'Credito personal de ' . $dinero((int) $o['monto_cent']) . "\n"
                      . 'Plazo: ' . (int) $o['plazo_meses'] . " meses\n"
                      . 'Tasa: ' . number_format((int) $o['tasa_bp'] / 100, 2) . "% anual\n"
                      . 'Cuota estimada: ' . $dinero((int) $o['cuota_cent']) . " al mes\n"
                    : 'Tarjeta de credito con linea de ' . $dinero((int) $o['monto_cent']) . "\n"
                      . 'Tasa: ' . number_format((int) $o['tasa_bp'] / 100, 2) . "% anual\n")
                . 'Vigente hasta: ' . $vence . "\n\n"
                . "POR QUE SE LE OFRECE\n"
                . '  Cuotas pagadas: ' . (int) ($criterios['cuotas_pagadas'] ?? 0) . "\n"
                . '  Atraso maximo: ' . (int) ($criterios['atraso_maximo_dias'] ?? 0) . " dia(s)\n"
                . "  Sin cuotas vencidas y sin gestion de cobro abierta.\n\n"
                . "CONDICIONES\n"
                . "  - Caduca el {$vence}.\n"
                . "  - Al aceptar se vuelve a verificar su capacidad de pago.\n"
                . "  - Aceptar genera una obligacion de pago exigible.\n"
                . "  - No aceptar no afecta sus productos ni su historial.\n\n"
                . "Puede aceptarla o descartarla desde su banca en linea.\n";

            $enviado = (new Correo())->enviar(
                (string) $o['correo'],
                ($esCredito ? 'Crédito preaprobado de ' : 'Tarjeta preaprobada con línea de ')
                . $dinero((int) $o['monto_cent']) . ' · ' . $o['folio'],
                PlantillaCorreo::base(
                    $esCredito ? 'Crédito preaprobado' : 'Tarjeta preaprobada',
                    'Señor(a) ' . $o['cliente'],
                    $cuerpo,
                    $filas,
                    null,
                    null,
                    null,
                    'Esta oferta es personal e intransferible y responde a su historial '
                    . 'de pago con esta institucion.'
                ),
                $texto,
                (string) $o['cliente'],
                $this->copias()
            );

            if ($enviado) {
                BaseDatos::ejecutar(
                    'UPDATE ofertas_preaprobadas SET enviada_en = NOW() WHERE id = ?',
                    [$ofertaId]
                );
            }

            $this->notificaciones->crear(
                (int) $o['usuario_id'], 'oferta.preaprobada',
                $esCredito ? 'Crédito preaprobado disponible' : 'Tarjeta preaprobada disponible',
                'Tiene una oferta preaprobada de ' . $dinero((int) $o['monto_cent'])
                . ' vigente hasta el ' . $vence . '. Puede aceptarla desde su banca en línea.',
                ['folio' => (string) $o['folio']], 'exito'
            );

            return $enviado;
        } catch (\Throwable $ex) {
            error_log('[Oferta] aviso no enviado: ' . $ex->getMessage());
            return false;
        }
    }

    /**
     * El acuse de lo otorgado.
     *
     * @param array<string,mixed> $r
     */
    public function avisarOtorgado(int $ofertaId, array $r): bool
    {
        try {
            $o = $this->paraCorreo($ofertaId);

            if ($o === null) {
                return false;
            }

            $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $dinero = static fn(int $c): string => Monto::deCentavos($c)->formateado();
            $esCredito = $o['tipo'] === 'credito';

            $cuerpo =
                '<p>Acusamos recibo de su <strong>aceptación</strong> de la oferta '
                . $e((string) $o['folio']) . ', recibida el ' . date('d/m/Y \a \l\a\s H:i')
                . '. La operación quedó procesada en el acto.</p>'

                . ($esCredito
                    ? '<p>Su crédito <strong>' . $e((string) ($r['folio_credito'] ?? ''))
                      . '</strong> quedó formalizado por '
                      . $e($dinero((int) $o['monto_cent'])) . ' a ' . (int) $o['plazo_meses']
                      . ' meses, y el monto fue <strong>acreditado en su cuenta '
                      . $e((string) ($r['cuenta'] ?? '')) . '</strong> con la referencia '
                      . $e((string) ($r['referencia'] ?? '')) . '.</p>'
                      . '<p>La primera cuota vence según el plan de pagos que ya puede '
                      . 'consultar y descargar desde su banca en línea. Cada cuota incluye '
                      . 'capital, intereses y las primas de seguro de vida y desempleo, '
                      . 'calculadas sobre el saldo con el que arranca cada periodo.</p>'
                    : '<p>Su tarjeta de crédito quedó <strong>emitida</strong> con una línea '
                      . 'de ' . $e($dinero((int) $o['monto_cent'])) . '. Ya aparece en su '
                      . 'banca en línea, donde puede consultar el número, activar los '
                      . 'controles de uso y configurar el pago automático.</p>')

                . '<p style="margin-top:22px"><strong>Qué conviene que sepa</strong></p>'
                . '<ul style="font-size:14px;color:#475467;padding-left:18px;margin:0">'
                . ($esCredito
                    ? '<li>El contrato y la tabla de amortización están disponibles para '
                      . 'descarga en su banca en línea.</li>'
                      . '<li>Puede abonar a capital o cancelar anticipadamente cuando '
                      . 'quiera; el sistema recalcula lo que corresponde.</li>'
                      . '<li>El pago puntual mantiene su historial, que es lo que originó '
                      . 'esta misma oferta.</li>'
                    : '<li>Las compras a cuotas de la tienda se difieren solas, con el '
                      . 'plazo que elija al pagar.</li>'
                      . '<li>Puede mantener hasta ' . ServicioTarjeta::topePlanes()
                      . ' compras diferidas a la vez.</li>'
                      . '<li>El pago puntual mantiene su historial, que es lo que originó '
                      . 'esta misma oferta.</li>')
                . '</ul>';

            $filas = [
                ['etiqueta' => 'Oferta aceptada', 'valor' => (string) $o['folio']],
                ['etiqueta' => 'Fecha', 'valor' => date('d/m/Y H:i')],
            ];

            if ($esCredito) {
                $filas[] = ['etiqueta' => 'Crédito', 'valor' => (string) ($r['folio_credito'] ?? '—')];
                $filas[] = ['etiqueta' => 'MONTO ACREDITADO', 'valor' => $dinero((int) $o['monto_cent'])];
                $filas[] = ['etiqueta' => 'Cuenta', 'valor' => (string) ($r['cuenta'] ?? '—')];
                $filas[] = ['etiqueta' => 'Referencia', 'valor' => (string) ($r['referencia'] ?? '—')];
                $filas[] = ['etiqueta' => 'Plazo', 'valor' => (int) $o['plazo_meses'] . ' meses'];
                $filas[] = ['etiqueta' => 'Cuota', 'valor' => $dinero((int) $o['cuota_cent']) . ' + seguros'];
            } else {
                $filas[] = ['etiqueta' => 'LÍNEA APROBADA', 'valor' => $dinero((int) $o['monto_cent'])];
                $filas[] = ['etiqueta' => 'Tasa anual', 'valor' => number_format((int) $o['tasa_bp'] / 100, 2) . '%'];
            }

            $texto = "ACEPTACION PROCESADA\n\n"
                . 'Senor(a) ' . $o['cliente'] . ":\n\n"
                . 'Acusamos recibo de su aceptacion de la oferta ' . $o['folio'] . ",\n"
                . 'recibida el ' . date('d/m/Y H:i') . ". La operacion quedo procesada.\n\n"
                . ($esCredito
                    ? 'Credito: ' . ($r['folio_credito'] ?? '-') . "\n"
                      . 'Monto acreditado: ' . $dinero((int) $o['monto_cent']) . "\n"
                      . 'Cuenta: ' . ($r['cuenta'] ?? '-') . "\n"
                      . 'Referencia: ' . ($r['referencia'] ?? '-') . "\n"
                      . 'Plazo: ' . (int) $o['plazo_meses'] . " meses\n"
                      . 'Cuota: ' . $dinero((int) $o['cuota_cent']) . " mas seguros\n"
                    : 'Tarjeta de credito emitida con linea de '
                      . $dinero((int) $o['monto_cent']) . "\n")
                . "\nPuede consultar el detalle en su banca en linea.\n";

            return (new Correo())->enviar(
                (string) $o['correo'],
                ($esCredito ? 'Crédito otorgado · ' : 'Tarjeta emitida · ') . $o['folio'],
                PlantillaCorreo::base(
                    $esCredito ? 'Crédito preaprobado otorgado' : 'Tarjeta preaprobada emitida',
                    'Señor(a) ' . $o['cliente'],
                    $cuerpo,
                    $filas,
                    null,
                    null,
                    null,
                    'Conserve este acuse. Si no reconoce esta operacion, comuniquese con '
                    . 'nosotros de inmediato indicando el folio.'
                ),
                $texto,
                (string) $o['cliente'],
                $this->copias()
            );
        } catch (\Throwable $ex) {
            error_log('[Oferta] acuse no enviado: ' . $ex->getMessage());
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    private function paraCorreo(int $ofertaId): ?array
    {
        $o = BaseDatos::unaFila(
            'SELECT o.*, u.correo, CONCAT(u.nombres," ",u.apellidos) AS cliente
               FROM ofertas_preaprobadas o
               JOIN usuarios u ON u.id = o.usuario_id
              WHERE o.id = ?',
            [$ofertaId]
        );

        return ($o !== null && trim((string) $o['correo']) !== '') ? $o : null;
    }

    /** @return list<string> */
    private function copias(): array
    {
        $crudo = trim(Parametros::obtener('correo.copia_creditos', ''));

        if ($crudo === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $crudo)),
            static fn(string $c): bool => $c !== '',
        ));
    }

    /**
     * La tasa que le toca a este cliente para este producto.
     *
     * Catálogo menos el descuento de su segmento, recortada al rango
     * autorizado del producto. Es la misma resta que hace
     * `ServicioCredito::otorgar()`; aquí se adelanta para que la oferta
     * diga la verdad desde que se publica.
     */
    private function tasaDelCliente(int $usuarioId, array $producto): int
    {
        $tasa = (int) $producto['tasa_interes_bp'];

        $descuento = (int) ((new ServicioSegmento())
            ->beneficio($usuarioId, 'tasa_descuento_bp') ?? 0);

        if ($descuento <= 0) {
            return $tasa;
        }

        $tasa -= $descuento;

        /* Nunca por debajo del mínimo del producto. El beneficio es un
           descuento comercial, no una licencia para salirse del rango
           que el producto tiene autorizado. */
        $minimo = (int) ($producto['tasa_min_bp'] ?? 0);

        return $minimo > 0 ? max($minimo, $tasa) : max(0, $tasa);
    }
}
