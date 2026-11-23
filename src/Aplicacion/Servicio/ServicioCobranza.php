<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\Mora;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Cobranza: del recordatorio amable a la demanda.
 *
 * «Cartera morosa» era una lista de nombres y montos. Sirve para saber
 * cuánto se debe y no sirve para cobrarlo: no dice a quién se le llamó,
 * qué contestó, si prometió pagar ni en qué punto va cada caso. Con dos
 * deudores eso se lleva de memoria; con doscientos, no.
 *
 * Aquí cada crédito moroso tiene un expediente con su etapa:
 *
 *   preventiva → administrativa → extrajudicial → judicial → sentencia
 *
 * y de ahí a `recuperado` o `castigado`. La etapa no es decorativa:
 * determina quién lleva el caso —la agencia hasta administrativa,
 * jurídico desde extrajudicial— y qué carta le toca al deudor.
 *
 * Sobre los correos automáticos: son el punto delicado de todo esto.
 * Un sistema que manda cartas de cobro solo puede hacer mucho daño si se
 * equivoca, así que:
 *
 *   · Nunca escribe a quien ya se puso al día. Se recalcula la mora
 *     antes de cada envío, no se confía en la foto del expediente.
 *   · Guarda días de separación entre dos correos al mismo deudor. Sin
 *     eso, un cambio de etapa dispararía dos cartas el mismo día.
 *   · Cada envío queda en la bitácora. Si un cliente dice «a mí nadie
 *     me avisó», la respuesta está escrita con fecha y hora.
 *   · En seco (`$aplicar = false`) enseña qué haría sin mandar nada.
 */
final class ServicioCobranza
{
    /** Orden del proceso. Importa para saber si algo avanza o retrocede. */
    private const ORDEN = [
        'preventiva'     => 1,
        'administrativa' => 2,
        'extrajudicial'  => 3,
        'judicial'       => 4,
        'sentencia'      => 5,
        'recuperado'     => 9,
        'castigado'      => 9,
    ];

    /**
     * Los resultados que admite una gestión, que son los de la columna.
     *
     * Escritos aquí para poder comprobarlos ANTES de intentar la
     * inserción. La diferencia no es de estilo: el enum de MySQL
     * rechaza lo que no reconoce, y como las anotaciones se escriben
     * dentro de la transacción del cobro, un valor equivocado tumbaba
     * la operación entera en vez de la anotación sola.
     */
    private const RESULTADOS = [
        'enviada', 'contactado', 'no_contesta', 'numero_equivocado',
        'promesa_pago', 'negativa', 'pago_recibido', 'sin_resultado',
    ];

    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    /**
     * Abre o actualiza los expedientes de los créditos en mora.
     *
     * Se ejecuta a diario. Es idempotente: vuelve a calcular la mora de
     * cada crédito y actualiza el expediente que ya exista en lugar de
     * crear otro, porque la clave única por crédito lo impide.
     *
     * @return array<string,mixed>
     */
    /**
     * Pone al día el expediente de un crédito concreto.
     *
     * El expediente guardaba las cifras del día en que se abrió y nadie
     * volvía a tocarlas: después de una compensación que cubrió dos
     * cuotas, la cabecera seguía diciendo «mora acumulada $317.47»
     * cuando la viva eran $252,520.43, y el gestor negociaba con un
     * número que no era.
     *
     * Se llama desde donde entra dinero —pago de cuotas, compensación,
     * arreglo— para que el expediente refleje lo que de verdad se debe
     * en ese momento.
     *
     * Si ya no queda nada vencido, el caso se cierra solo: un
     * expediente de cobro abierto sobre un crédito al día hace que
     * alguien llame a reclamarle a quien ya pagó.
     *
     * No lanza nunca: un expediente desactualizado es un problema, pero
     * menor que deshacer el cobro que acaba de entrar.
     *
     * @return array<string,mixed>
     */
    public function refrescarCaso(int $creditoId): array
    {
        try {
            $caso = BaseDatos::unaFila(
                'SELECT id, folio, etapa, cerrado_en FROM cobranza_casos
                  WHERE credito_id = ? ORDER BY id DESC LIMIT 1',
                [$creditoId]
            );

            if ($caso === null) {
                return ['actualizado' => false];
            }

            /* Un caso cerrado no se reabre: si el cliente vuelve a caer
               en mora se abre uno nuevo, porque mezclar dos ciclos de
               cobro en el mismo expediente borra la historia de ambos.
               Pero sí se limpian sus cifras. Quedaban congeladas en el
               día del cierre, de modo que un expediente «recuperado»
               seguía mostrando 52 cuotas vencidas y una deuda viva. */
            if ($caso['cerrado_en'] !== null) {
                $quedaAlgo = (int) BaseDatos::unValor(
                    'SELECT COUNT(*) FROM cuotas
                      WHERE credito_id = ?
                        AND estado NOT IN ("pagada","condonada")
                        AND fecha_vencimiento < CURDATE()',
                    [$creditoId]
                );

                if ($quedaAlgo === 0) {
                    BaseDatos::ejecutar(
                        'UPDATE cobranza_casos
                            SET cuotas_vencidas = 0, deuda_cent = 0,
                                mora_cent = 0, dias_mora = 0
                          WHERE id = ? AND (cuotas_vencidas <> 0
                                         OR deuda_cent <> 0 OR mora_cent <> 0)',
                        [(int) $caso['id']]
                    );
                }

                return ['actualizado' => false, 'cerrado' => true];
            }

            // Lo vencido HOY, con la mora calculada como la calcula el
            // cobro, no la que quedó guardada de otro día.
            $cuotas = BaseDatos::todas(
                'SELECT total_cent, pagado_cent,
                        DATEDIFF(CURDATE(), fecha_vencimiento) AS dias
                   FROM cuotas
                  WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")
                    AND fecha_vencimiento < CURDATE()',
                [$creditoId]
            );

            $deuda = 0;
            $mora  = 0;
            $dias  = 0;

            foreach ($cuotas as $q) {
                $pend = Monto::deCentavos((int) $q['total_cent'] - (int) $q['pagado_cent']);
                $d    = max(0, (int) $q['dias']);

                $deuda += $pend->centavos;
                $mora  += Mora::calcular($pend, $d)->centavos;
                $dias   = max($dias, $d);
            }

            $estadoCredito = (string) BaseDatos::unValor(
                'SELECT estado FROM creditos WHERE id = ?', [$creditoId]
            );

            /* Nada vencido, o crédito cancelado: el caso se cierra.
             *
             * Se distingue el motivo porque no es lo mismo que el
             * cliente se pusiera al día que que liquidara el crédito,
             * y el expediente tiene que poder contarlo después. */
            if ($cuotas === [] || $estadoCredito === 'cancelado') {
                $motivo = $estadoCredito === 'cancelado'
                    ? 'El crédito quedó cancelado por completo.'
                    : 'El crédito se puso al día: no quedan cuotas vencidas.';

                BaseDatos::ejecutar(
                    'UPDATE cobranza_casos
                        SET etapa = "recuperado", cerrado_en = NOW(),
                            cuotas_vencidas = 0, deuda_cent = 0, mora_cent = 0,
                            motivo_cierre = ?
                      WHERE id = ?',
                    [$motivo, (int) $caso['id']]
                );

                /* El resultado es «pago_recibido», no «recuperado».
                 *
                 * «Recuperado» es la ETAPA del expediente; el RESULTADO
                 * de la gestión es que entró el pago, y esa columna es
                 * un enum que no admite otra cosa. Con el valor
                 * equivocado MySQL rechazaba la fila y se llevaba por
                 * delante el cierre entero: el expediente de un cliente
                 * que acababa de pagar se quedaba abierto y en el
                 * registro solo aparecía «no se pudo refrescar el caso».
                 *
                 * Es decir que el cierre automático al pagar no
                 * funcionó nunca, y el síntoma era justo el que no se
                 * puede tener en un banco: clientes al día con una
                 * gestión de cobro viva encima. */
                $this->anotar(
                    (int) $caso['id'], 'nota', 'recuperado',
                    'Expediente cerrado automáticamente',
                    $motivo . ' El sistema lo cerró al aplicarse el pago.',
                    'pago_recibido', true
                );

                return [
                    'actualizado' => true,
                    'cerrado'     => true,
                    'folio'       => $caso['folio'],
                    'motivo'      => $motivo,
                ];
            }

            // Sigue debiendo: se actualizan las cifras y la etapa, que
            // puede haber bajado si pagó las más viejas.
            BaseDatos::ejecutar(
                'UPDATE cobranza_casos
                    SET cuotas_vencidas = ?, deuda_cent = ?, mora_cent = ?,
                        dias_mora = ?, etapa = ?
                  WHERE id = ?',
                [
                    count($cuotas), $deuda, $mora, $dias,
                    $this->etapaPorDias($dias), (int) $caso['id'],
                ]
            );

            return [
                'actualizado' => true,
                'cerrado'     => false,
                'folio'       => $caso['folio'],
                'vencidas'    => count($cuotas),
                'deuda_cent'  => $deuda,
                'mora_cent'   => $mora,
            ];
        } catch (\Throwable $e) {
            error_log('[Cobranza] no se pudo refrescar el caso del credito '
                . $creditoId . ': ' . $e->getMessage());

            return ['actualizado' => false, 'error' => true];
        }
    }

    public function sincronizar(bool $aplicar = false): array
    {
        $morosos = BaseDatos::todas(
            'SELECT c.id AS credito_id, c.usuario_id, c.folio,
                    COUNT(q.id)                                   AS cuotas_vencidas,
                    COALESCE(SUM(q.total_cent - q.pagado_cent), 0) AS deuda_cent,
                    COALESCE(SUM(q.mora_cent), 0)                 AS mora_cent,
                    COALESCE(MAX(DATEDIFF(CURDATE(), q.fecha_vencimiento)), 0) AS dias_mora
               FROM creditos c
               JOIN cuotas   q ON q.credito_id = c.id
              WHERE c.estado = "vigente"
                AND q.estado IN ("pendiente","vencida","parcial")
                AND q.fecha_vencimiento < CURDATE()
              GROUP BY c.id, c.usuario_id, c.folio
             HAVING deuda_cent > 0
              ORDER BY dias_mora DESC'
        );

        $abiertos    = 0;
        $actualizados = 0;
        $escalados   = [];

        foreach ($morosos as $m) {
            $etapa = $this->etapaPorDias((int) $m['dias_mora']);

            $caso = BaseDatos::unaFila(
                'SELECT id, etapa, folio FROM cobranza_casos WHERE credito_id = ?',
                [(int) $m['credito_id']]
            );

            if ($caso === null) {
                $folio = Folio::unico('COB', 'cobranza_casos');

                if ($aplicar) {
                    BaseDatos::ejecutar(
                        'INSERT INTO cobranza_casos
                           (uuid, folio, credito_id, usuario_id, etapa, deuda_cent,
                            mora_cent, dias_mora, cuotas_vencidas)
                         VALUES (UUID(),?,?,?,?,?,?,?,?)',
                        [
                            $folio, (int) $m['credito_id'], (int) $m['usuario_id'], $etapa,
                            (int) $m['deuda_cent'], (int) $m['mora_cent'],
                            (int) $m['dias_mora'], (int) $m['cuotas_vencidas'],
                        ]
                    );

                    $this->anotar(
                        BaseDatos::ultimoId(),
                        'nota',
                        $etapa,
                        'Expediente abierto',
                        'Se abrió el expediente por ' . $m['cuotas_vencidas']
                        . ' cuota(s) vencida(s) y ' . $m['dias_mora'] . ' días de mora.',
                        'sin_resultado',
                        true
                    );
                }

                $abiertos++;
                continue;
            }

            $etapaActual = (string) $caso['etapa'];

            // Un caso cerrado no se reabre solo: si el cliente volvió a
            // caer en mora, eso lo revisa una persona.
            if (in_array($etapaActual, ['recuperado', 'castigado'], true)) {
                continue;
            }

            $sube = (self::ORDEN[$etapa] ?? 0) > (self::ORDEN[$etapaActual] ?? 0);

            if ($aplicar) {
                BaseDatos::ejecutar(
                    'UPDATE cobranza_casos
                        SET deuda_cent = ?, mora_cent = ?, dias_mora = ?,
                            cuotas_vencidas = ?, etapa = IF(?, ?, etapa)
                      WHERE id = ?',
                    [
                        (int) $m['deuda_cent'], (int) $m['mora_cent'],
                        (int) $m['dias_mora'], (int) $m['cuotas_vencidas'],
                        $sube ? 1 : 0, $etapa, (int) $caso['id'],
                    ]
                );

                if ($sube) {
                    $this->anotar(
                        (int) $caso['id'],
                        'nota',
                        $etapa,
                        'El caso pasó a ' . str_replace('_', ' ', $etapa),
                        'Pasó de ' . $etapaActual . ' a ' . $etapa . ' por acumular '
                        . $m['dias_mora'] . ' días de mora.',
                        'sin_resultado',
                        true
                    );

                    $this->avisarAlArea((int) $caso['id'], (string) $caso['folio'], $etapa, $m);
                }
            }

            $actualizados++;

            if ($sube) {
                $escalados[] = [
                    'folio'    => (string) $caso['folio'],
                    'credito'  => (string) $m['folio'],
                    'de'       => $etapaActual,
                    'a'        => $etapa,
                    'dias'     => (int) $m['dias_mora'],
                ];
            }
        }

        // Las promesas vencidas que nadie cumplió.
        $incumplidas = 0;

        if ($aplicar) {
            $incumplidas = BaseDatos::ejecutar(
                'UPDATE cobranza_promesas
                    SET estado = "incumplida"
                  WHERE estado = "vigente" AND fecha_promesa < CURDATE()'
            );
        }

        return [
            'morosos'      => count($morosos),
            'abiertos'     => $abiertos,
            'actualizados' => $actualizados,
            'escalados'    => $escalados,
            'promesas_incumplidas' => $incumplidas,
            'aplicado'     => $aplicar,
        ];
    }

    /**
     * Manda los correos de cobranza que correspondan hoy.
     *
     * @return array<string,mixed>
     */
    public function enviarCorreos(bool $aplicar = false, int $limite = 100): array
    {
        $separacion = max(1, Parametros::entero('cobranza.dias_entre_correos', 7));

        $casos = BaseDatos::todas(
            'SELECT k.id, k.folio, k.etapa, k.dias_mora, k.deuda_cent, k.mora_cent,
                    k.cuotas_vencidas, k.usuario_id,
                    c.folio AS credito_folio,
                    u.correo, u.nombres, u.apellidos,
                    (SELECT MAX(g.creada_en) FROM cobranza_gestiones g
                      WHERE g.caso_id = k.id AND g.tipo = "correo") AS ultimo_correo,
                    (SELECT MIN(q.fecha_vencimiento) FROM cuotas q
                      WHERE q.credito_id = k.credito_id
                        AND q.estado IN ("pendiente","vencida","parcial")
                        AND q.fecha_vencimiento < CURDATE()) AS vencimiento_mas_viejo
               FROM cobranza_casos k
               JOIN creditos c ON c.id = k.credito_id
               JOIN usuarios u ON u.id = k.usuario_id
              WHERE k.etapa NOT IN ("recuperado","castigado")
                AND u.estado = "activo"
                AND u.correo <> ""
              ORDER BY k.dias_mora DESC
              LIMIT ' . max(1, min(500, $limite))
        );

        $enviados = [];
        $saltados = [];

        foreach ($casos as $k) {
            /* La deuda se vuelve a comprobar aquí.
             *
             * El expediente guarda una foto del día que se sincronizó, y
             * entre esa foto y el envío el cliente puede haber pagado.
             * Escribirle a alguien que ya se puso al día para exigirle lo
             * que no debe es el peor error posible de un cobrador. */
            $vigente = BaseDatos::unaFila(
                'SELECT COUNT(*) AS cuotas,
                        COALESCE(SUM(q.total_cent - q.pagado_cent),0) AS deuda,
                        COALESCE(SUM(q.mora_cent),0) AS mora,
                        COALESCE(MAX(DATEDIFF(CURDATE(), q.fecha_vencimiento)),0) AS dias
                   FROM cuotas q
                  WHERE q.credito_id = (SELECT credito_id FROM cobranza_casos WHERE id = ?)
                    AND q.estado IN ("pendiente","vencida","parcial")
                    AND q.fecha_vencimiento < CURDATE()',
                [(int) $k['id']]
            ) ?? [];

            if ((int) ($vigente['deuda'] ?? 0) <= 0) {
                $saltados[] = ['folio' => $k['folio'], 'motivo' => 'ya se puso al día'];
                continue;
            }

            // Separación entre correos.
            if ($k['ultimo_correo'] !== null) {
                $dias = (int) ((new \DateTimeImmutable('today'))
                    ->diff(new \DateTimeImmutable((string) $k['ultimo_correo']))
                    ->format('%a'));

                if ($dias < $separacion) {
                    $saltados[] = [
                        'folio'  => $k['folio'],
                        'motivo' => 'se le escribió hace ' . $dias . ' día(s)',
                    ];
                    continue;
                }
            }

            $plantilla = $this->plantillaPara((string) $k['etapa'], (int) $vigente['dias']);

            if ($plantilla === null) {
                $saltados[] = ['folio' => $k['folio'], 'motivo' => 'sin plantilla para su etapa'];
                continue;
            }

            $datos = [
                'nombre'      => trim($k['nombres'] . ' ' . $k['apellidos']),
                'folio'       => (string) $k['credito_folio'],
                'deuda'       => Monto::deCentavos((int) $vigente['deuda'])->formateado(),
                'mora'        => Monto::deCentavos((int) $vigente['mora'])->formateado(),
                'total'       => Monto::deCentavos(
                    (int) $vigente['deuda'] + (int) $vigente['mora']
                )->formateado(),
                'dias'        => (string) (int) $vigente['dias'],
                'cuotas'      => (string) (int) $vigente['cuotas'],
                'cuota'       => Monto::deCentavos((int) $k['deuda_cent'])->formateado(),
                'vencimiento' => $k['vencimiento_mas_viejo'] !== null
                    ? date('d/m/Y', strtotime((string) $k['vencimiento_mas_viejo']))
                    : '-',
                'referencia'  => (string) ($k['referencia_judicial'] ?? 'en tramite'),
                'juzgado'     => (string) ($k['juzgado'] ?? 'el tribunal competente'),
            ];

            $asunto = $this->rellenar((string) $plantilla['asunto'], $datos);
            $cuerpo = $this->rellenar((string) $plantilla['cuerpo'], $datos);

            if ($aplicar) {
                $html = PlantillaCorreo::base(
                    $asunto,
                    'Estimado(a) ' . trim((string) $k['nombres']),
                    $this->aParrafos($cuerpo),
                    [
                        ['etiqueta' => 'Credito',       'valor' => $datos['folio']],
                        ['etiqueta' => 'Saldo vencido', 'valor' => $datos['deuda']],
                        ['etiqueta' => 'Recargo por mora', 'valor' => $datos['mora']],
                        ['etiqueta' => 'Total a pagar', 'valor' => $datos['total']],
                        ['etiqueta' => 'Dias de atraso', 'valor' => $datos['dias']],
                    ],
                    null,
                    null,
                    null,
                    'Si ya efectuo el pago, le agradecemos hacer caso omiso de este aviso.'
                );

                /* Con copia a la dirección de seguimiento.
                 *
                 * Los correos de cobranza no pasan por
                 * `ServicioNotificacion` —se mandan directos porque
                 * llevan su propia plantilla con el texto legal—, así
                 * que la copia hay que ponerla aquí también. Si no, la
                 * institución vería los avisos del crédito pero no los
                 * requerimientos, que son justo los que importa seguir. */
                (new Correo())->enviar(
                    (string) $k['correo'],
                    $asunto,
                    $html,
                    $cuerpo,
                    $datos['nombre'],
                    $this->copiasDeSeguimiento()
                );

                $this->anotar(
                    (int) $k['id'],
                    'correo',
                    (string) $k['etapa'],
                    $asunto,
                    'Enviado a ' . $k['correo'] . ' con la plantilla «' . $plantilla['clave'] . '».',
                    'enviada',
                    true
                );

                // Y en su portal, para que lo vea al entrar.
                $this->notificaciones->crear(
                    (int) $k['usuario_id'],
                    'cobranza.aviso',
                    $asunto,
                    mb_substr(str_replace("\n", ' ', $cuerpo), 0, 400),
                    ['credito' => $datos['folio'], 'total' => $datos['total']],
                    (string) $plantilla['tono'] === 'juridico' ? 'critico' : 'alerta'
                );
            }

            $enviados[] = [
                'folio'     => (string) $k['folio'],
                'credito'   => $datos['folio'],
                'etapa'     => (string) $k['etapa'],
                'plantilla' => (string) $plantilla['clave'],
                'correo'    => (string) $k['correo'],
                'total'     => $datos['total'],
                'dias'      => (int) $vigente['dias'],
            ];
        }

        return [
            'enviados'  => $enviados,
            'saltados'  => $saltados,
            'aplicado'  => $aplicar,
        ];
    }

    /**
     * Mueve un caso de etapa a mano.
     *
     * @return array<string,mixed>
     */
    public function escalar(int $casoId, string $etapa, string $nota = ''): array
    {
        $actorId = Sesion::exigirAutenticado();

        if (!isset(self::ORDEN[$etapa])) {
            throw new \InvalidArgumentException('Esa etapa no existe.');
        }

        // Las etapas judiciales las lleva jurídico, no la agencia.
        if (in_array($etapa, ['judicial', 'sentencia'], true)) {
            Autorizacion::exigir('cobranza.juridico');
        } else {
            Autorizacion::exigir('cobranza.escalar');
        }

        $k = BaseDatos::unaFila('SELECT * FROM cobranza_casos WHERE id = ?', [$casoId]);

        if ($k === null) {
            throw new \RuntimeException('Expediente no encontrado.');
        }

        if (in_array((string) $k['etapa'], ['recuperado', 'castigado'], true)) {
            throw new \RuntimeException('Este expediente ya está cerrado.');
        }

        BaseDatos::ejecutar(
            'UPDATE cobranza_casos SET etapa = ? WHERE id = ?',
            [$etapa, $casoId]
        );

        $this->anotar(
            $casoId,
            'nota',
            $etapa,
            'Cambio de etapa: ' . $k['etapa'] . ' → ' . $etapa,
            $nota !== '' ? $nota : 'Cambio registrado manualmente.',
            'sin_resultado',
            false
        );

        Auditoria::registrar('cobranza.escalado', Auditoria::EXITO, $actorId,
            'cobranza_caso', (string) $casoId,
            ['folio' => $k['folio'], 'de' => $k['etapa'], 'a' => $etapa]);

        return ['folio' => (string) $k['folio'], 'etapa' => $etapa];
    }

    /**
     * Registra una gestión hecha por una persona.
     *
     * @param array<string,mixed> $datos
     */
    public function registrarGestion(int $casoId, array $datos): array
    {
        Autorizacion::exigir('cobranza.gestionar');
        $actorId = Sesion::exigirAutenticado();

        $k = BaseDatos::unaFila('SELECT id, folio, etapa, usuario_id FROM cobranza_casos WHERE id = ?', [$casoId]);

        if ($k === null) {
            throw new \RuntimeException('Expediente no encontrado.');
        }

        $tipos = ['correo', 'llamada', 'mensaje', 'carta', 'visita', 'requerimiento',
                  'demanda', 'audiencia', 'escrito', 'convenio', 'nota'];

        $tipo = (string) ($datos['tipo'] ?? 'nota');

        if (!in_array($tipo, $tipos, true)) {
            throw new \InvalidArgumentException('Ese tipo de gestión no existe.');
        }

        $resultados = ['enviada', 'contactado', 'no_contesta', 'numero_equivocado',
                       'promesa_pago', 'negativa', 'pago_recibido', 'sin_resultado'];

        $resultado = (string) ($datos['resultado'] ?? 'sin_resultado');

        if (!in_array($resultado, $resultados, true)) {
            $resultado = 'sin_resultado';
        }

        $asunto = trim((string) ($datos['asunto'] ?? ''));

        if ($asunto === '') {
            throw new \InvalidArgumentException('Diga de qué se trata la gestión.');
        }

        $id = $this->anotar(
            $casoId,
            $tipo,
            (string) $k['etapa'],
            $asunto,
            (string) ($datos['detalle'] ?? ''),
            $resultado,
            false
        );

        /* Una promesa de pago se guarda aparte.
         *
         * Es el dato con el que se decide si escalar: alguien que
         * prometió y cumplió merece paciencia; alguien que prometió tres
         * veces y no cumplió ninguna, no. */
        if ($resultado === 'promesa_pago' && !empty($datos['promesa_fecha'])) {
            $monto = Monto::deEntradaUsuario($datos['promesa_monto'] ?? 0);

            BaseDatos::ejecutar(
                'INSERT INTO cobranza_promesas
                   (caso_id, monto_cent, fecha_promesa, observaciones, registrada_por)
                 VALUES (?,?,?,?,?)',
                [
                    $casoId, $monto->centavos,
                    (string) $datos['promesa_fecha'],
                    mb_substr($asunto, 0, 400), $actorId,
                ]
            );
        }

        Auditoria::registrar('cobranza.gestion', Auditoria::EXITO, $actorId,
            'cobranza_caso', (string) $casoId,
            ['folio' => $k['folio'], 'tipo' => $tipo, 'resultado' => $resultado]);

        return ['id' => $id, 'folio' => (string) $k['folio']];
    }

    /** Guarda los datos del proceso judicial. */
    public function datosJudiciales(int $casoId, array $datos): array
    {
        Autorizacion::exigir('cobranza.juridico');
        $actorId = Sesion::exigirAutenticado();

        $k = BaseDatos::unaFila('SELECT id, folio, usuario_id FROM cobranza_casos WHERE id = ?', [$casoId]);

        if ($k === null) {
            throw new \RuntimeException('Expediente no encontrado.');
        }

        $fecha = static function (mixed $v): ?string {
            $v = trim((string) $v);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
        };

        BaseDatos::ejecutar(
            'UPDATE cobranza_casos
                SET juzgado = NULLIF(TRIM(?), ""),
                    referencia_judicial = NULLIF(TRIM(?), ""),
                    abogado = NULLIF(TRIM(?), ""),
                    demanda_en = COALESCE(?, demanda_en),
                    emplazamiento_en = COALESCE(?, emplazamiento_en),
                    sentencia_en = COALESCE(?, sentencia_en),
                    sentencia_favorable = COALESCE(?, sentencia_favorable)
              WHERE id = ?',
            [
                (string) ($datos['juzgado'] ?? ''),
                (string) ($datos['referencia'] ?? ''),
                (string) ($datos['abogado'] ?? ''),
                $fecha($datos['demanda_en'] ?? ''),
                $fecha($datos['emplazamiento_en'] ?? ''),
                $fecha($datos['sentencia_en'] ?? ''),
                isset($datos['sentencia_favorable']) && $datos['sentencia_favorable'] !== ''
                    ? (int) (bool) $datos['sentencia_favorable'] : null,
                $casoId,
            ]
        );

        $this->anotar(
            $casoId,
            'escrito',
            'judicial',
            'Actualización del expediente judicial',
            'Juzgado: ' . ($datos['juzgado'] ?? '-')
            . ' · Referencia: ' . ($datos['referencia'] ?? '-')
            . ' · Abogado: ' . ($datos['abogado'] ?? '-'),
            'sin_resultado',
            false
        );

        Auditoria::registrar('cobranza.judicial', Auditoria::EXITO, $actorId,
            'cobranza_caso', (string) $casoId, ['folio' => $k['folio']]);

        return ['folio' => (string) $k['folio']];
    }

    /** Cierra un caso: recuperado o castigado. */
    public function cerrar(int $casoId, string $como, string $motivo): array
    {
        Autorizacion::exigir('cobranza.cerrar');
        $actorId = Sesion::exigirAutenticado();

        if (!in_array($como, ['recuperado', 'castigado'], true)) {
            throw new \InvalidArgumentException('Un caso se cierra como recuperado o castigado.');
        }

        if (mb_strlen(trim($motivo)) < 10) {
            throw new \InvalidArgumentException(
                'Explique el motivo del cierre: es lo que queda para la auditoría.'
            );
        }

        $k = BaseDatos::unaFila('SELECT * FROM cobranza_casos WHERE id = ?', [$casoId]);

        if ($k === null) {
            throw new \RuntimeException('Expediente no encontrado.');
        }

        // Recuperado significa que de verdad no queda nada pendiente. Se
        // comprueba en lugar de creerle al operador.
        if ($como === 'recuperado') {
            $pendiente = (int) BaseDatos::unValor(
                'SELECT COALESCE(SUM(total_cent - pagado_cent), 0) FROM cuotas
                  WHERE credito_id = ? AND estado IN ("pendiente","vencida","parcial")',
                [(int) $k['credito_id']]
            );

            if ($pendiente > 0) {
                throw new \RuntimeException(
                    'No se puede cerrar como recuperado: todavía quedan '
                    . Monto::deCentavos($pendiente)->formateado() . ' pendientes.'
                );
            }
        }

        BaseDatos::ejecutar(
            'UPDATE cobranza_casos
                SET etapa = ?, cerrado_en = NOW(), cerrado_por = ?, motivo_cierre = ?,
                    recuperado_cent = IF(? = "recuperado", deuda_cent, recuperado_cent)
              WHERE id = ?',
            [$como, $actorId, mb_substr(trim($motivo), 0, 400), $como, $casoId]
        );

        $this->anotar(
            $casoId,
            'nota',
            $como,
            'Expediente cerrado como ' . $como,
            $motivo,
            $como === 'recuperado' ? 'pago_recibido' : 'sin_resultado',
            false
        );

        Auditoria::registrar('cobranza.cerrado',
            $como === 'recuperado' ? Auditoria::EXITO : Auditoria::FALLO,
            $actorId, 'cobranza_caso', (string) $casoId,
            ['folio' => $k['folio'], 'como' => $como]);

        if ($como === 'recuperado') {
            $this->notificaciones->crear(
                (int) $k['usuario_id'],
                'cobranza.recuperado',
                '¡Su crédito quedó al día!',
                'Nos alegra mucho informarle que su crédito quedó al corriente y '
                . 'cerramos el expediente de cobro. Le agradecemos su esfuerzo y '
                . 'quedamos a sus órdenes para lo que necesite.',
                ['expediente' => (string) $k['folio']],
                'exito'
            );
        }

        return ['folio' => (string) $k['folio'], 'etapa' => $como];
    }

    // -----------------------------------------------------------------

    /** Qué etapa corresponde a tantos días de mora. */
    public function etapaPorDias(int $dias): string
    {
        $judicial      = Parametros::entero('cobranza.dias_judicial', 121);
        $extrajudicial = Parametros::entero('cobranza.dias_extrajudicial', 61);

        if ($dias >= $judicial) {
            return 'judicial';
        }

        if ($dias >= $extrajudicial) {
            return 'extrajudicial';
        }

        return $dias >= 1 ? 'administrativa' : 'preventiva';
    }

    /** @return array<string,mixed>|null */
    private function plantillaPara(string $etapa, int $dias): ?array
    {
        return BaseDatos::unaFila(
            'SELECT * FROM cobranza_plantillas
              WHERE activa = 1 AND etapa = ? AND ? BETWEEN dias_desde AND dias_hasta
              ORDER BY dias_desde DESC LIMIT 1',
            [$etapa, $dias]
        ) ?? BaseDatos::unaFila(
            'SELECT * FROM cobranza_plantillas
              WHERE activa = 1 AND etapa = ? ORDER BY orden LIMIT 1',
            [$etapa]
        );
    }

    /** @param array<string,string> $datos */
    private function rellenar(string $texto, array $datos): string
    {
        foreach ($datos as $k => $v) {
            $texto = str_replace('{{' . $k . '}}', $v, $texto);
        }

        return $texto;
    }

    /** Convierte el texto plano de la plantilla en párrafos HTML. */
    private function aParrafos(string $texto): string
    {
        $html = '';

        foreach (preg_split('/\n\s*\n/', $texto) ?: [] as $p) {
            $p = trim($p);

            if ($p === '') {
                continue;
            }

            $html .= '<p>' . nl2br(htmlspecialchars($p, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</p>';
        }

        return $html;
    }

    /**
     * Las direcciones que reciben copia de todo lo de crédito.
     *
     * Misma lista que usa `ServicioNotificacion`: un solo parámetro
     * para que no haya que cambiarla en dos sitios y que se queden
     * desincronizados.
     *
     * @return list<string>
     */
    private function copiasDeSeguimiento(): array
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

    private function anotar(
        int $casoId,
        string $tipo,
        string $etapa,
        string $asunto,
        string $detalle,
        string $resultado,
        bool $automatica
    ): int {
        /* Un resultado que la columna no admite no puede tumbar un
         * cierre de expediente.
         *
         * La columna es un enum: lo que no está en la lista lo rechaza
         * MySQL, y como esto se llama dentro de la transacción del
         * cobro, una palabra mal escrita en una ANOTACIÓN se llevaba
         * por delante el cierre del caso. Pasó: durante meses, cerrar
         * un expediente al pagar falló siempre por un «recuperado»
         * donde iba «pago_recibido».
         *
         * Una nota mal clasificada es un problema menor; un expediente
         * de cobro que no se cierra cuando el cliente pagó es un
         * problema serio. Así que ante la duda se guarda la anotación
         * con un resultado neutro, y el aviso queda en el registro
         * para quien tenga que corregir la llamada. */
        if (!in_array($resultado, self::RESULTADOS, true)) {
            error_log(sprintf(
                '[Cobranza] resultado de gestion desconocido "%s" en el caso %d; '
                . 'se anota como sin_resultado',
                $resultado,
                $casoId
            ));

            $resultado = 'sin_resultado';
        }

        BaseDatos::ejecutar(
            'INSERT INTO cobranza_gestiones
               (caso_id, tipo, automatica, etapa, asunto, detalle, resultado, registrada_por)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $casoId, $tipo, $automatica ? 1 : 0, $etapa,
                mb_substr($asunto, 0, 190), $detalle !== '' ? $detalle : null, $resultado,
                // En la tarea automática no hay sesión: el actor es el
                // sistema, y decir que fue una persona sería mentir.
                $automatica || PHP_SAPI === 'cli' ? null : Sesion::usuarioId(),
            ]
        );

        return BaseDatos::ultimoId();
    }

    /**
     * Avisa al área que recibe el caso cuando cambia de etapa.
     *
     * @param array<string,mixed> $m
     */
    private function avisarAlArea(int $casoId, string $folio, string $etapa, array $m): void
    {
        $permiso = in_array($etapa, ['extrajudicial', 'judicial'], true)
            ? 'cobranza.juridico'
            : 'cobranza.gestionar';

        $destinatarios = BaseDatos::todas(
            'SELECT DISTINCT u.id
               FROM usuarios u
               JOIN roles r        ON r.id = u.rol_id
               JOIN rol_permiso rp ON rp.rol_id = r.id
               JOIN permisos p     ON p.id = rp.permiso_id
              WHERE p.clave = ? AND u.estado = "activo"',
            [$permiso]
        );

        foreach ($destinatarios as $d) {
            $this->notificaciones->crear(
                (int) $d['id'],
                'cobranza.escalado',
                'Expediente ' . $folio . ' pasó a ' . str_replace('_', ' ', $etapa),
                'El crédito ' . $m['folio'] . ' acumula ' . $m['dias_mora']
                . ' días de mora y ' . $m['cuotas_vencidas'] . ' cuota(s) vencida(s), '
                . 'con un saldo de '
                . Monto::deCentavos((int) $m['deuda_cent'])->formateado()
                . '. El expediente queda a cargo de su área.',
                ['expediente' => $folio, 'etapa' => $etapa],
                $etapa === 'judicial' ? 'critico' : 'alerta'
            );
        }
    }
}
