<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\CapacidadPago;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Solicitudes de tarjeta de crédito.
 *
 * Una tarjeta de crédito es una línea de crédito, así que se tramita con
 * el mismo cuidado que un préstamo: pasa por atención al cliente y por
 * gerencia, y cada paso queda firmado. Antes la emitía directamente
 * quien tuviera el permiso, sin solicitud ni rastro de por qué se le dio
 * ese límite a esa persona.
 *
 * El límite que se aprueba nunca es el que se pidió a secas: es el menor
 * entre lo pedido y lo que aguanta la capacidad de pago del cliente.
 */
final class ServicioSolicitudTarjeta
{
    /**
     * De qué etapa se pasa a cuál.
     *
     * «aprobada → emitida» está aquí por las solicitudes que se
     * aprobaron antes de que la emisión fuera automática: quedaron
     * firmadas y sin plástico, y hay que poder cerrarlas sin volver a
     * capturarlas a mano. En el camino normal esa etapa no se ve, porque
     * aprobar ya emite.
     */
    private const FLUJO = [
        'capturada'         => 'revision_atencion',
        'revision_atencion' => 'revision_gerencia',
        'revision_gerencia' => 'aprobada',
        'aprobada'          => 'emitida',
    ];

    private const PERMISO_ETAPA = [
        'capturada'         => 'tarjeta.revisar_atencion',
        'revision_atencion' => 'tarjeta.revisar_atencion',
        'revision_gerencia' => 'tarjeta.revisar_gerencia',
        'aprobada'          => 'tarjeta.emitir',
    ];

    /**
     * Corrige los datos de una solicitud en trámite.
     *
     * Hace falta porque las solicitudes se capturan con prisa y en
     * ventanilla: el cliente dice «la Oro» y pensaba en la Clásica, o
     * declara un ingreso que luego el expediente desmiente. Sin esto,
     * la única salida era rechazarla y volver a capturarla, y entonces
     * el cliente recibía un «no aprobamos su solicitud» por un error de
     * digitación nuestro.
     *
     * Qué se puede tocar depende de en qué etapa está y de quién lo
     * pide, y hay dos cosas que no se tocan nunca:
     *
     *   · Una solicitud ya resuelta —aprobada, emitida o rechazada— no
     *     se edita. Si una tarjeta ya existe, cambiarle el producto a su
     *     solicitud dejaría el expediente diciendo una cosa y el
     *     plástico otra.
     *   · El límite aprobado no se escribe a mano. Sale siempre del
     *     menor entre lo pedido y la capacidad de pago; si se pudiera
     *     teclear, toda la comprobación de capacidad sobraría.
     *
     * El ingreso solo lo corrige gerencia, porque es el dato del que
     * cuelga la capacidad: subirlo es subir el límite que se puede
     * aprobar, y eso no puede quedar en manos de quien captura.
     *
     * @param  array<string,mixed> $datos
     * @return array<string,mixed>
     */
    public function editar(int $solicitudId, array $datos): array
    {
        $actorId = Sesion::exigirAutenticado();

        return BaseDatos::enTransaccion(function () use ($solicitudId, $datos, $actorId) {
            $s = BaseDatos::unaFila(
                'SELECT * FROM solicitudes_tarjeta WHERE id = ? FOR UPDATE',
                [$solicitudId]
            );

            if ($s === null) {
                throw new \RuntimeException('Solicitud no encontrada.');
            }

            $etapa = (string) $s['etapa'];

            if (!isset(self::FLUJO[$etapa]) || $etapa === 'aprobada') {
                throw new \RuntimeException(
                    'Esta solicitud ya está resuelta: no admite cambios. '
                    . 'Si hay un error, lo correcto es dejarlo dicho en las observaciones.'
                );
            }

            // Para tocarla hay que poder moverla.
            Autorizacion::exigir(self::PERMISO_ETAPA[$etapa]);

            $puedeIngreso = Autorizacion::puede('tarjeta.revisar_gerencia');

            $campos = [];
            $args   = [];
            $cambios = [];

            // --- Producto ---------------------------------------------
            if (isset($datos['producto_id']) && (int) $datos['producto_id'] > 0
                && (int) $datos['producto_id'] !== (int) $s['producto_id']) {

                $p = BaseDatos::unaFila(
                    'SELECT id, nombre, ingreso_minimo_cent, linea_desde_cent
                       FROM productos
                      WHERE id = ? AND tipo = "tarjeta" AND activo = 1 AND publico = 1',
                    [(int) $datos['producto_id']]
                );

                if ($p === null) {
                    throw new \InvalidArgumentException('Esa tarjeta no está disponible.');
                }

                /* El requisito de ingreso se vuelve a comprobar.
                 *
                 * Cambiar de la Clásica a la Signature sin mirar el
                 * ingreso dejaría al cliente pidiendo un producto para
                 * el que no califica, y el rechazo llegaría al final del
                 * trámite en vez de ahora. */
                $minimo = (int) $p['ingreso_minimo_cent'];

                if ($minimo > 0 && (int) $s['ingreso_cent'] < $minimo) {
                    throw new \RuntimeException(sprintf(
                        'La %s pide un ingreso desde $%s y en la solicitud consta $%s. '
                        . 'Corrija primero el ingreso si el expediente lo respalda.',
                        $p['nombre'],
                        number_format($minimo / 100, 2),
                        number_format((int) $s['ingreso_cent'] / 100, 2)
                    ));
                }

                $campos[] = 'producto_id = ?';
                $args[]   = (int) $p['id'];
                $cambios['producto'] = (string) $p['nombre'];
            }

            /* --- Marca ------------------------------------------------
               Solo se cambia si la línea NO la tiene fijada. Con una
               línea contratada en una red concreta, dejar que alguien
               la mueva a otra emite un plástico que esa red no
               reconoce. */
            if (isset($datos['marca'])
                && in_array($datos['marca'], ['visa', 'mastercard', 'amex'], true)
                && $datos['marca'] !== $s['marca']) {
                $producto = BaseDatos::unaFila(
                    'SELECT marca_emision FROM productos WHERE id = ?',
                    [(int) ($p['id'] ?? $s['producto_id'])]
                );

                if (($producto['marca_emision'] ?? null) === null) {
                    $campos[] = 'marca = ?';
                    $args[]   = (string) $datos['marca'];
                    $cambios['marca'] = (string) $datos['marca'];
                }
            }

            // --- Límite solicitado ------------------------------------
            if (isset($datos['limite']) && (string) $datos['limite'] !== '') {
                $pedido = Monto::deEntradaUsuario($datos['limite']);

                if (!$pedido->esPositivo()) {
                    throw new \InvalidArgumentException('El límite pedido debe ser mayor que cero.');
                }

                if ($pedido->centavos !== (int) $s['limite_solicitado_cent']) {
                    $campos[] = 'limite_solicitado_cent = ?';
                    $args[]   = $pedido->centavos;
                    $cambios['pedido'] = $pedido->aDecimal();
                }
            }

            // --- Ingreso: solo gerencia -------------------------------
            if (isset($datos['ingreso']) && (string) $datos['ingreso'] !== '') {
                if (!$puedeIngreso) {
                    throw new \RuntimeException(
                        'El ingreso declarado solo lo puede corregir gerencia: de ese dato '
                        . 'depende el límite que se puede aprobar.'
                    );
                }

                $ingreso = Monto::deEntradaUsuario($datos['ingreso']);

                if (!$ingreso->esPositivo()) {
                    throw new \InvalidArgumentException('El ingreso debe ser mayor que cero.');
                }

                if ($ingreso->centavos !== (int) $s['ingreso_cent']) {
                    // Si cambia el ingreso, la capacidad se recalcula: lo
                    // contrario dejaría un techo que ya no corresponde.
                    $capacidad = CapacidadPago::limiteTarjeta(
                        $ingreso,
                        $this->compromisos((int) $s['usuario_id'])
                    );

                    if (!$capacidad->esPositivo()) {
                        throw new \RuntimeException(
                            'Con ese ingreso y sus compromisos actuales no queda margen '
                            . 'para una tarjeta de crédito.'
                        );
                    }

                    $campos[] = 'ingreso_cent = ?';
                    $args[]   = $ingreso->centavos;
                    $campos[] = 'capacidad_maxima_cent = ?';
                    $args[]   = $capacidad->centavos;

                    $cambios['ingreso']   = $ingreso->aDecimal();
                    $cambios['capacidad'] = $capacidad->aDecimal();
                }
            }

            $observacion = trim((string) ($datos['observacion'] ?? ''));

            if ($campos === [] && $observacion === '') {
                throw new \InvalidArgumentException('No hay cambios que guardar.');
            }

            // Todo cambio deja constancia en el propio expediente, no
            // solo en la bitácora de auditoría: quien aprueba después
            // tiene que ver que esto se tocó y por qué.
            $nota = "\n[" . date('d/m/Y H:i') . '] Corrección: '
                . ($cambios === []
                    ? 'sin cambios de datos'
                    : implode(', ', array_map(
                        static fn(string $k, string $v): string => $k . ' → ' . $v,
                        array_keys($cambios),
                        array_values($cambios)
                    )))
                . ($observacion !== '' ? '. ' . $observacion : '');

            $campos[] = 'observaciones = TRIM(CONCAT(COALESCE(observaciones,""), ?))';
            $args[]   = $nota;

            $args[] = $solicitudId;

            BaseDatos::ejecutar(
                'UPDATE solicitudes_tarjeta SET ' . implode(', ', $campos) . ' WHERE id = ?',
                $args
            );

            Auditoria::registrar('tarjeta.solicitud_editada', Auditoria::EXITO, $actorId,
                'solicitud_tarjeta', (string) $solicitudId,
                ['folio' => $s['folio'], 'cambios' => $cambios]);

            return [
                'folio'   => (string) $s['folio'],
                'cambios' => $cambios,
            ];
        });
    }

    /**
     * Registra una solicitud.
     *
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    public function solicitar(array $datos): array
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('tarjeta.solicitar');

        // El titular: el cliente pide para sí; el personal puede capturar.
        $titularId = (int) ($datos['usuario_id'] ?? 0);

        if ($titularId === 0 || $titularId === $actorId) {
            $titularId = $actorId;
        } elseif (!Autorizacion::puede('tarjeta.ver_solicitudes')) {
            throw new \RuntimeException('No puede solicitar una tarjeta para otra persona.');
        }

        /* La tercera puerta.
         *
         * Un cliente inhabilitado por atraso tampoco entra por aquí.
         * La regla se comprueba en las TRES puertas —oferta, crédito y
         * tarjeta— porque una regla que solo se aplica en dos deja la
         * tercera abierta, y entonces no es una regla: es un adorno.
         *
         * Se mira el historial del TITULAR, no el de quien captura la
         * solicitud en ventanilla. */
        (new ServicioElegibilidad())->exigir($titularId, 'tarjeta');

        $producto = BaseDatos::unaFila(
            'SELECT * FROM productos
              WHERE id = ? AND tipo = "tarjeta" AND activo = 1',
            [(int) ($datos['producto_id'] ?? 0)]
        );

        if ($producto === null) {
            throw new \InvalidArgumentException('Esa tarjeta no está disponible.');
        }

        /* Las líneas que no están en el catálogo general.
         *
         * `publico = 0` significa «no se ofrece a cualquiera», no «no
         * existe»: son las reservadas a un segmento. El filtro las
         * rechazaba siempre, así que la banca preferente le enseñaba al
         * cliente una tarjeta suya y al pulsar le decía que no estaba
         * disponible. Quien puede pedirla es quien la tiene concedida. */
        if ((int) $producto['publico'] !== 1) {
            $suyas = (array) ((new ServicioSegmento())
                ->beneficio($titularId, 'lineas_exclusivas') ?? []);

            if (!in_array((string) $producto['codigo'], $suyas, true)) {
                throw new \InvalidArgumentException(
                    'La ' . $producto['nombre'] . ' se emite por invitación de la '
                    . 'institución. Con gusto revisamos su caso en agencia.'
                );
            }
        }

        // Una solicitud en trámite por vez: dos a la vez acabarían con el
        // cliente teniendo dos líneas de crédito que nadie sumó.
        $pendiente = BaseDatos::unValor(
            "SELECT folio FROM solicitudes_tarjeta
              WHERE usuario_id = ? AND etapa NOT IN ('aprobada','rechazada','emitida')
              LIMIT 1",
            [$titularId]
        );

        if ($pendiente !== null) {
            throw new \RuntimeException(
                'Ya hay una solicitud de tarjeta en trámite (' . $pendiente . '). '
                . 'Le avisaremos en cuanto se resuelva.'
            );
        }

        // Cuotas vencidas: igual que en los créditos, no se abre una
        // línea nueva a quien va atrasado en la que ya tiene.
        $vencidas = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM cuotas q JOIN creditos c ON c.id = q.credito_id
              WHERE c.usuario_id = ? AND q.estado IN ("vencida","parcial")
                AND q.fecha_vencimiento < CURDATE()',
            [$titularId]
        );

        if ($vencidas > 0) {
            throw new \RuntimeException(
                'No podemos tramitar la solicitud mientras existan ' . $vencidas
                . ' cuota(s) en mora. Póngase al día y con gusto la retomamos.'
            );
        }

        $ingreso = $this->ingresoDeclarado($titularId, $datos['ingreso'] ?? null);

        // Requisito de ingreso del producto.
        $minimo = (int) $producto['ingreso_minimo_cent'];

        if ($minimo > 0 && $ingreso->centavos < $minimo) {
            throw new \RuntimeException(sprintf(
                'La %s requiere un ingreso mensual desde $%s. Con gusto le mostramos '
                . 'las opciones que sí se ajustan a su situación.',
                $producto['nombre'],
                number_format($minimo / 100, 2)
            ));
        }

        // Capacidad de pago: el techo real del límite.
        $compromisos = $this->compromisos($titularId);
        /* El múltiplo lo pone el SEGMENTO, no el parámetro general.
           Sin esto, el patrimonial —a quien se le anuncia una línea de
           hasta cinco veces su ingreso— recibía el techo de tres que
           se aplica a todo el mundo, y la solicitud se le recortaba
           por un límite que su categoría no tiene. */
        $capacidad   = CapacidadPago::limiteTarjeta(
            $ingreso,
            $compromisos,
            null,
            (new ServicioSegmento())->vecesIngreso($titularId)
        );

        if (!$capacidad->esPositivo()) {
            throw new \RuntimeException(
                'Sus compromisos actuales no dejan margen para una tarjeta de crédito. '
                . 'Con gusto revisamos su caso en agencia.'
            );
        }

        $pedido = isset($datos['limite'])
            ? Monto::deEntradaUsuario($datos['limite'])
            : Monto::deCentavos((int) $producto['linea_desde_cent']);

        if (!$pedido->esPositivo()) {
            $pedido = Monto::deCentavos((int) $producto['linea_desde_cent']);
        }

        /* La decisión de riesgo, con el mismo motor que los créditos.
         *
         * Una línea de tarjeta es crédito. Tener dos criterios para
         * decidirlo es como se acaba abriendo por una puerta lo que se
         * niega por la otra. La cuota que se evalúa es el pago mínimo
         * de la línea pedida: ese es el compromiso mensual real que la
         * tarjeta le añade al cliente. */
        $riesgo = new ServicioRiesgo();

        $decision = $riesgo->evaluar(
            $titularId,
            Monto::deCentavos((int) round(
                $pedido->centavos * Parametros::entero('tarjeta.pago_minimo_pct', 500) / 10000
            ))
        );

        if (!$decision['aprueba']) {
            // Queda constancia del rechazo, como en los créditos.
            $riesgo->registrar($titularId, $decision, 'tarjeta');

            throw new \RuntimeException($decision['narrativa']);
        }

        /* Una banda regular no se rechaza: se le abre una línea menor.
           El mismo factor que recorta el monto de un crédito recorta
           aquí la línea. */
        $ajuste = $riesgo->condicionar($decision, $pedido, 12, 0);

        if ($ajuste['monto']->esPositivo()
            && $ajuste['monto']->centavos < $pedido->centavos) {
            $pedido = $ajuste['monto'];
        }

        $riesgo->registrar($titularId, $decision, 'tarjeta');

        $folio = Folio::unico('STC', 'solicitudes_tarjeta');

        BaseDatos::ejecutar(
            'INSERT INTO solicitudes_tarjeta
               (uuid, folio, usuario_id, producto_id, marca, limite_solicitado_cent,
                ingreso_cent, capacidad_maxima_cent, etapa)
             VALUES (UUID(),?,?,?,?,?,?,?, "capturada")',
            [
                $folio, $titularId, (int) $producto['id'],
                /* La marca la pone el producto, no quien solicita.
                 *
                 * Una línea se emite en la red con la que está
                 * contratada: la Signature sale en American Express y
                 * la Platinum en Mastercard porque así se pactó con
                 * cada red, no porque el cliente lo prefiera. Antes se
                 * aceptaba lo que llegara del formulario y una
                 * Signature podía acabar emitida en Visa. */
                self::marcaDe($producto, $datos['marca'] ?? null),
                $pedido->centavos,
                $ingreso->centavos,
                $capacidad->centavos,
            ]
        );

        $id = BaseDatos::ultimoId();

        // El primer paso del recorrido, que es el que el cliente ve
        // en cuanto termina de pedirla.
        $this->anotarPaso($id, null, 'capturada', $actorId,
            self::notaDeEtapa('capturada'));

        Auditoria::registrar('tarjeta.solicitada', Auditoria::EXITO, $actorId,
            'solicitud_tarjeta', (string) $id,
            ['folio' => $folio, 'producto' => $producto['codigo'],
             'pedido' => $pedido->aDecimal(), 'capacidad' => $capacidad->aDecimal()]);

        (new ServicioNotificacion())->crear(
            $titularId,
            'tarjeta.solicitud',
            'Recibimos su solicitud de tarjeta',
            'Su solicitud ' . $folio . ' para la ' . $producto['nombre']
            . ' quedó registrada. Le avisaremos en cada paso de la revisión.',
            ['folio' => $folio],
            'info'
        );

        return [
            'id'        => $id,
            'folio'     => $folio,
            'producto'  => $producto['nombre'],
            'pedido'    => $pedido->jsonSerialize(),
            'capacidad' => $capacidad->jsonSerialize(),
            // Se dice desde el principio si el límite tendrá que bajarse:
            // es mejor saberlo ahora que al recibir la tarjeta.
            'se_ajustara' => $pedido->mayorQue($capacidad),
        ];
    }

    /**
     * Deja escrito un paso del recorrido.
     *
     * La solicitud guardaba solo su etapa actual. Con eso se puede
     * decir «está en revisión», pero no cuándo entró ni cuánto lleva
     * ahí, y para el cliente eso es lo que más importa de un trámite:
     * no tanto en qué paso va como que SE ESTÁ MOVIENDO. Una solicitud
     * parada cuatro días y una que avanzó ayer se veían igual.
     *
     * La `nota` es lo que lee el TITULAR. No es la observación interna
     * —esa vive en `solicitudes_tarjeta.observaciones`— porque no todo
     * lo que el personal anota en un expediente de crédito está escrito
     * para que lo lea el cliente.
     */
    private function anotarPaso(
        int $solicitudId,
        ?string $etapaAnterior,
        string $etapaNueva,
        ?int $actorId = null,
        string $nota = ''
    ): void {
        BaseDatos::ejecutar(
            'INSERT INTO solicitud_tarjeta_historial
               (solicitud_id, etapa_anterior, etapa_nueva, actor_id, nota)
             VALUES (?,?,?,?,?)',
            [
                $solicitudId,
                $etapaAnterior,
                $etapaNueva,
                $actorId > 0 ? $actorId : null,
                $nota !== '' ? mb_substr($nota, 0, 300) : null,
            ]
        );
    }

    /**
     * Qué se le dice al cliente en cada etapa.
     *
     * En segunda persona y sin jerga: «revisión de gerencia» es el
     * nombre interno del paso, no lo que el titular necesita leer.
     */
    public static function notaDeEtapa(string $etapa): string
    {
        return match ($etapa) {
            'capturada'         => 'Recibimos su solicitud y entró en cola de revisión.',
            'revision_atencion' => 'Un ejecutivo está revisando su expediente y sus datos.',
            'revision_gerencia' => 'Su expediente pasó a gerencia para la aprobación del límite.',
            'aprobada'          => 'Su solicitud fue aprobada.',
            'emitida'           => 'Su tarjeta quedó emitida y ya aparece en «Mis tarjetas».',
            'rechazada'         => 'Su solicitud no procedió en esta ocasión.',
            default             => '',
        };
    }
    /**
     * Avanza una solicitud a la siguiente etapa.
     *
     * @return array<string,mixed>
     */
    public function avanzar(int $solicitudId, string $observacion = ''): array
    {
        $actorId = Sesion::exigirAutenticado();

        return BaseDatos::enTransaccion(function () use ($solicitudId, $observacion, $actorId) {
            $s = BaseDatos::unaFila(
                'SELECT * FROM solicitudes_tarjeta WHERE id = ? FOR UPDATE',
                [$solicitudId]
            );

            if ($s === null) {
                throw new \RuntimeException('Solicitud no encontrada.');
            }

            $etapa = (string) $s['etapa'];

            if (!isset(self::FLUJO[$etapa])) {
                throw new \RuntimeException('Esta solicitud ya no admite avances.');
            }

            Autorizacion::exigir(self::PERMISO_ETAPA[$etapa]);

            $siguiente = self::FLUJO[$etapa];

            $columna = match ($etapa) {
                'capturada', 'revision_atencion' => 'revisado_atencion_por',
                'revision_gerencia'              => 'revisado_gerencia_por',
                // Emitir no es revisar: no reescribe quién revisó.
                'aprobada'                       => 'revisado_gerencia_por',
            };

            // Al aprobar se fija el límite: el menor entre lo pedido y lo
            // que admitía la capacidad al capturar.
            $limiteAprobado = null;
            $tarjeta        = null;

            if ($siguiente === 'aprobada' || $siguiente === 'emitida') {
                // Si ya venía aprobada, manda el límite que se firmó
                // entonces: recalcularlo hoy daría otra cifra, porque los
                // compromisos del cliente cambian de un día para otro.
                $limiteAprobado = $s['limite_aprobado_cent'] !== null
                    ? (int) $s['limite_aprobado_cent']
                    : min(
                        (int) $s['limite_solicitado_cent'],
                        (int) ($s['capacidad_maxima_cent'] ?: $s['limite_solicitado_cent'])
                    );

                if ((int) ($s['tarjeta_id'] ?? 0) > 0) {
                    throw new \RuntimeException(
                        'Esta solicitud ya tiene su tarjeta emitida.'
                    );
                }

                /* Aprobar es emitir.
                 *
                 * Antes la solicitud se quedaba en «aprobada con $4,000»
                 * y ahí moría: en «Tarjetas emitidas» no aparecía nada y
                 * alguien tenía que volver a capturar a mano unos datos
                 * que ya estaban firmados. El cliente recibía el aviso
                 * de aprobación y se quedaba sin tarjeta, y de paso se
                 * abría la puerta a que el límite emitido no fuera el
                 * límite aprobado.
                 *
                 * Va dentro de la misma transacción a propósito: o
                 * quedan la aprobación y la tarjeta, o no queda ninguna
                 * de las dos. Una aprobación sin plástico se reintenta
                 * sin daño; un plástico sin aprobación es una línea de
                 * crédito que nadie autorizó. */
                $tarjeta = (new EmisorTarjeta())->emitir(
                    (int) $s['usuario_id'],
                    (string) $s['marca'],
                    'credito',
                    Monto::deCentavos($limiteAprobado),
                    (int) $s['producto_id'],
                    $actorId,
                    ['origen' => 'solicitud', 'solicitud' => $s['folio']]
                );

                // La etapa real ya no es «aprobada»: la tarjeta existe.
                $siguiente = 'emitida';
            }

            BaseDatos::ejecutar(
                'UPDATE solicitudes_tarjeta
                    SET etapa = ?, ' . $columna . ' = ?,
                        limite_aprobado_cent = COALESCE(?, limite_aprobado_cent),
                        tarjeta_id = COALESCE(?, tarjeta_id),
                        observaciones = TRIM(CONCAT(COALESCE(observaciones,""), ?, "\n"))
                  WHERE id = ?',
                [
                    $siguiente, $actorId, $limiteAprobado,
                    $tarjeta === null ? null : (int) $tarjeta['id'],
                    $observacion !== '' ? "\n[" . date('d/m/Y H:i') . '] ' . $observacion : '',
                    $solicitudId,
                ]
            );

            $this->anotarPaso($solicitudId, $etapa, $siguiente, $actorId,
                self::notaDeEtapa($siguiente));

            Auditoria::registrar('tarjeta.solicitud_avanzada', Auditoria::EXITO, $actorId,
                'solicitud_tarjeta', (string) $solicitudId,
                ['folio' => $s['folio'], 'de' => $etapa, 'a' => $siguiente,
                 'tarjeta_id' => $tarjeta['id'] ?? null]);

            /* El aviso que recibe el cliente.
             *
             * Se distingue la aprobación de la emisión a secas: a quien
             * ya le habíamos dicho «aprobada» no se le vuelve a dar la
             * misma noticia, se le dice que su tarjeta ya está lista. */
            $yaEstabaAprobada = $etapa === 'aprobada';

            if ($tarjeta !== null) {
                $titulo = $yaEstabaAprobada
                    ? '¡Su tarjeta ya está lista!'
                    : '¡Su tarjeta fue aprobada y ya está emitida!';

                $mensaje = ($yaEstabaAprobada
                        ? 'Su tarjeta de la solicitud ' . $s['folio'] . ' ya quedó emitida'
                        : 'Con mucho gusto le contamos que aprobamos su solicitud '
                          . $s['folio'] . ' con un límite de $'
                          . number_format(($limiteAprobado ?? 0) / 100, 2))
                    . '. La tarjeta ' . strtoupper((string) $tarjeta['marca'])
                    . ' terminada en ' . $tarjeta['ultimos4']
                    . ' ya aparece en «Mis tarjetas», lista para usarse. '
                    . 'Quedamos atentos a lo que necesite.';
            } else {
                $titulo  = 'Su solicitud avanzó';
                $mensaje = 'Su solicitud ' . $s['folio'] . ' pasó a '
                    . str_replace('_', ' ', $siguiente)
                    . '. Le avisaremos en cuanto haya novedades; gracias por su confianza.';
            }

            (new ServicioNotificacion())->crear(
                (int) $s['usuario_id'],
                'tarjeta.solicitud',
                $titulo,
                $mensaje,
                ['folio' => $s['folio'], 'tarjeta' => $tarjeta['codigo'] ?? null],
                $tarjeta !== null ? 'exito' : 'info'
            );

            return [
                'etapa'                => $siguiente,
                'limite_aprobado_cent' => $limiteAprobado,
                'tarjeta'              => $tarjeta,
            ];
        });
    }

    /** Rechaza una solicitud. */
    public function rechazar(int $solicitudId, string $motivo): array
    {
        $actorId = Sesion::exigirAutenticado();

        if (trim($motivo) === '') {
            throw new \InvalidArgumentException(
                'Indique el motivo: el cliente tiene derecho a saber por qué.'
            );
        }

        return BaseDatos::enTransaccion(function () use ($solicitudId, $motivo, $actorId) {
            $s = BaseDatos::unaFila(
                'SELECT * FROM solicitudes_tarjeta WHERE id = ? FOR UPDATE',
                [$solicitudId]
            );

            if ($s === null) {
                throw new \RuntimeException('Solicitud no encontrada.');
            }

            if (!isset(self::FLUJO[(string) $s['etapa']])) {
                throw new \RuntimeException('Esta solicitud ya está resuelta.');
            }

            Autorizacion::exigir(self::PERMISO_ETAPA[(string) $s['etapa']]);

            BaseDatos::ejecutar(
                'UPDATE solicitudes_tarjeta
                    SET etapa = "rechazada", motivo_rechazo = ?
                  WHERE id = ?',
                [mb_substr($motivo, 0, 500), $solicitudId]
            );

            /* El motivo SÍ va en la nota del rechazo: a quien se le
               niega una línea hay que decirle por qué, y el motivo ya
               se escribió pensando en que lo lea él. */
            $this->anotarPaso($solicitudId, (string) $s['etapa'], 'rechazada', $actorId,
                $motivo !== '' ? $motivo : self::notaDeEtapa('rechazada'));

            Auditoria::registrar('tarjeta.solicitud_rechazada', Auditoria::EXITO, $actorId,
                'solicitud_tarjeta', (string) $solicitudId,
                ['folio' => $s['folio'], 'motivo' => mb_substr($motivo, 0, 200)]);

            (new ServicioNotificacion())->crear(
                (int) $s['usuario_id'],
                'tarjeta.solicitud',
                'Sobre su solicitud de tarjeta',
                'Lamentamos informarle que no pudimos aprobar la solicitud '
                . $s['folio'] . '. Motivo: ' . mb_substr($motivo, 0, 200)
                . ' Quedamos a sus órdenes para revisar otras opciones.',
                ['folio' => $s['folio']],
                'alerta'
            );

            return ['etapa' => 'rechazada'];
        });
    }

    // -----------------------------------------------------------------

    /** Ingreso declarado: el que venga en la solicitud o el del expediente. */
    private function ingresoDeclarado(int $usuarioId, mixed $declarado): Monto
    {
        if ($declarado !== null && $declarado !== '') {
            $m = Monto::deEntradaUsuario($declarado);
            if ($m->esPositivo()) {
                return $m;
            }
        }

        return Monto::deCentavos((int) BaseDatos::unValor(
            'SELECT COALESCE(ingreso_mensual_cent, 0) FROM detalle_usuarios WHERE usuario_id = ?',
            [$usuarioId]
        ));
    }

    /**
     * En qué red se emite esta línea.
     *
     * La decide el producto, no quien solicita: cada línea está
     * contratada con una red concreta —la Signature sale en American
     * Express y la Platinum en Mastercard— y eso es una condición del
     * producto, igual que su tasa o su membresía. Solo donde el
     * producto no la fija se admite la preferencia del cliente.
     *
     * @param array<string,mixed> $producto
     */
    private static function marcaDe(array $producto, mixed $preferida): string
    {
        $delProducto = (string) ($producto['marca_emision'] ?? '');

        if (in_array($delProducto, ['visa', 'mastercard', 'amex'], true)) {
            return $delProducto;
        }

        return in_array($preferida, ['visa', 'mastercard', 'amex'], true)
            ? (string) $preferida
            : 'visa';
    }

    /** Compromisos mensuales: cuotas de crédito más mínimos de tarjeta. */
    private function compromisos(int $usuarioId): Monto
    {
        $creditos = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(cuota_cent),0) FROM creditos
              WHERE usuario_id = ? AND estado = "vigente"',
            [$usuarioId]
        );

        $pct = max(1, Parametros::entero('tarjeta.pago_minimo_pct', 500));

        $saldoTarjetas = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(saldo_usado_cent),0) FROM tarjetas
              WHERE usuario_id = ? AND tipo = "credito" AND estado = "activa"',
            [$usuarioId]
        );

        return Monto::deCentavos($creditos + (int) round($saldoTarjetas * $pct / 10000));
    }
}
