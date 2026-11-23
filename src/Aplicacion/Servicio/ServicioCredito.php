<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;


use CashMan\Dominio\Credito\Clausulado;
use CashMan\Dominio\Credito\DestinoDeCredito;
use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\Amortizacion;
use CashMan\Dominio\Credito\CapacidadPago;
use CashMan\Dominio\Credito\CostosCredito;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Ciclo completo del crédito: solicitud, aprobación por etapas,
 * desembolso, pago de cuotas y reestructuración.
 *
 * El sistema anterior repartía esto en casos sueltos del controlador
 * ("primer paso gerencia", "segundo paso presidencia") sin estados
 * explícitos: no se podía saber en qué etapa estaba una solicitud ni
 * quién la había revisado. Aquí cada transición queda registrada con
 * su responsable, y el desembolso pasa por el libro mayor.
 */
final class ServicioCredito
{
    /** Orden de las etapas de revisión. */
    private const FLUJO = [
        'capturada'            => 'revision_atencion',
        'revision_atencion'    => 'revision_gerencia',
        'revision_gerencia'    => 'revision_presidencia',
        'revision_presidencia' => 'aprobada',
    ];

    private const PERMISO_ETAPA = [
        'capturada'            => 'solicitud.revisar_atencion',
        'revision_atencion'    => 'solicitud.revisar_gerencia',
        'revision_gerencia'    => 'solicitud.revisar_presidencia',
        'revision_presidencia' => 'credito.aprobar',
    ];

    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    // =================================================================
    // SOLICITUD
    // =================================================================

    /**
     * @param array{usuario_id?:int, producto_id:int, monto:mixed, plazo:int,
     *              destino?:string, ingreso?:mixed} $datos
     * @return array<string,mixed>
     */
    public function solicitar(array $datos): array
    {
        $actorId = Sesion::exigirAutenticado();

        // Un cliente solicita para sí mismo; el personal puede capturar
        // la solicitud de un cliente en ventanilla.
        $titularId = (int) ($datos['usuario_id'] ?? 0);
        if ($titularId === 0 || $titularId === $actorId) {
            Autorizacion::exigir('credito.solicitar');
            $titularId = $actorId;
        } else {
            Autorizacion::exigir('solicitud.capturar');
        }

        /* Y ahora: ¿puede pedir?
         *
         * Se comprueba sobre el TITULAR, no sobre quien captura:
         * en ventanilla la solicitud la teclea un empleado y el
         * historial que pesa es el del cliente.
         *
         * Va aquí y no en el controlador porque una solicitud
         * puede entrar por la banca en línea, por ventanilla o por
         * una tarea: la comprobación tiene que estar donde pasan
         * las tres, no en una de las tres pantallas. */
        (new ServicioElegibilidad())->exigir($titularId, 'credito');

        $monto = Monto::deEntradaUsuario($datos['monto']);
        $plazo = (int) $datos['plazo'];

        if ($plazo < 3 || $plazo > 360) {
            throw new \InvalidArgumentException('El plazo debe estar entre 3 y 360 meses.');
        }

        $producto = BaseDatos::unaFila(
            'SELECT * FROM productos WHERE id = ? AND activo = 1 AND tipo = "credito"',
            [(int) $datos['producto_id']]
        );
        if ($producto === null) {
            throw new \InvalidArgumentException('Producto crediticio no válido.');
        }

        // La tasa pactada solo se acepta de quien puede concederla, y
        // siempre dentro del rango del producto. Sin este permiso se
        // ignora lo que venga y se usa la tasa publicada.
        $tasaPactada = null;
        if (isset($datos['tasa_bp']) && $datos['tasa_bp'] !== null
            && $datos['tasa_bp'] !== ''
            && Autorizacion::puede('credito.ajustar_tasa')) {
            $tasaPactada = self::tasaValidada($producto, (int) $datos['tasa_bp']);
        }

        // Un cliente con cuotas vencidas no puede pedir más crédito.
        $vencidas = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM cuotas q JOIN creditos c ON c.id = q.credito_id
              WHERE c.usuario_id = ? AND q.estado IN ("vencida","parcial")
                AND q.fecha_vencimiento < CURDATE()',
            [$titularId]
        );
        if ($vencidas > 0) {
            throw new \RuntimeException(
                'No se puede tramitar una solicitud con ' . $vencidas . ' cuota(s) en mora.'
            );
        }

        $pendiente = BaseDatos::unValor(
            'SELECT folio FROM solicitudes_credito
              WHERE usuario_id = ? AND etapa NOT IN ("aprobada","rechazada","desembolsada") LIMIT 1',
            [$titularId]
        );
        if ($pendiente !== null) {
            throw new \RuntimeException('Ya existe una solicitud en trámite (' . $pendiente . ').');
        }

        // Con la tasa pactada, no con la publicada: si se concede una
        // tasa distinta, la cuota que se evalúa tiene que ser la que el
        // cliente va a pagar de verdad.
        $amort   = new Amortizacion($monto, $tasaPactada ?? (int) $producto['tasa_interes_bp'], $plazo);
        $resumen = $amort->resumen();

        // Evaluación de capacidad con el ingreso declarado.
        $ingreso = isset($datos['ingreso']) && $datos['ingreso'] !== ''
            ? Monto::deEntradaUsuario($datos['ingreso'])
            : Monto::deCentavos((int) (BaseDatos::unValor(
                'SELECT ingreso_mensual_cent FROM detalle_usuarios WHERE usuario_id = ?',
                [$titularId]
              ) ?? 0));

        // El mismo criterio que usa el simulador: créditos vigentes más
        // el mínimo de las tarjetas. Tener dos cuentas distintas de lo
        // mismo es cómo se acaba aprobando lo que el simulador negaba.
        $compromisos = $this->compromisosMensuales($titularId);

        $capacidad = $ingreso->esPositivo()
            ? Amortizacion::evaluarCapacidad($ingreso, $compromisos, $resumen['cuota'])
            : ['aprueba' => true, 'relacion' => 0.0, 'motivo' => 'Ingreso no declarado; evaluar manualmente.'];

        /* La decisión de riesgo, ANTES de capturar nada.
         *
         * Un expediente que no procede no debería entrar en el circuito
         * de revisión: hace esperar al cliente días para un «no» que se
         * le puede dar ahora, con sus razones y con lo que tendría que
         * cambiar para que la respuesta fuera otra. */
        $riesgo   = new ServicioRiesgo();
        $decision = $riesgo->evaluar($titularId, $resumen['cuota']);

        if (!$decision['aprueba']) {
            // Queda constancia del rechazo: sin ella no se puede
            // responder a «¿por qué a mí me dijeron que no?».
            $riesgo->registrar($titularId, $decision, 'credito');

            throw new \RuntimeException($decision['narrativa']);
        }

        /* Un perfil regular no se rechaza: se le ofrece menos.
         *
         * Negarle crédito a quien paga tarde pero paga es dejar en la
         * calle negocio sano. Lo que procede es recortar el monto, el
         * plazo y cobrar el riesgo en la tasa, y decírselo con las
         * cifras delante en lugar de aprobarle otra cosa en silencio. */
        /* El descuento del segmento, antes del recargo por riesgo.
         *
         * Es un beneficio de la banca preferente —un punto para el
         * preferente, dos y medio para el patrimonial— y hasta ahora
         * estaba escrito en su pantalla sin aplicarse en ninguna parte.
         * Un beneficio que solo existe en el folleto es publicidad.
         *
         * Va ANTES del recargo a propósito: el riesgo se cobra sobre la
         * tasa que al cliente le corresponde, no sobre la de catálogo. */
        $tasaBase = $tasaPactada ?? (int) $producto['tasa_interes_bp'];

        $descuento = (int) ((new ServicioSegmento())
            ->beneficio($titularId, 'tasa_descuento_bp') ?? 0);

        if ($descuento > 0) {
            $tasaBase = max(0, $tasaBase - $descuento);
        }

        $ajuste = $riesgo->condicionar($decision, $monto, $plazo, $tasaBase);

        /* Sin recargo ni recorte, el descuento se aplica igual: el
           cliente de banda A no puede acabar pagando la tasa de
           catálogo por no haber nada que ajustarle. */
        if (!$ajuste['ajustado'] && $descuento > 0) {
            $tasaPactada = self::tasaValidada($producto, $tasaBase, true);

            $amort   = new Amortizacion($monto, $tasaPactada, $plazo);
            $resumen = $amort->resumen();

            $capacidad = $ingreso->esPositivo()
                ? Amortizacion::evaluarCapacidad($ingreso, $compromisos, $resumen['cuota'])
                : $capacidad;
        }

        if ($ajuste['ajustado']) {
            $monto = $ajuste['monto'];
            $plazo = $ajuste['plazo'];

            // La tasa ajustada solo cuenta si cabe en el rango del
            // producto: un recargo no puede sacar la tasa de lo pactado
            // con la Superintendencia.
            $tasaPactada = self::tasaValidada($producto, $ajuste['tasa_bp'], true);

            // Lo que se evalúa y se firma es la cuota NUEVA.
            $amort   = new Amortizacion($monto, $tasaPactada, $plazo);
            $resumen = $amort->resumen();

            $capacidad = $ingreso->esPositivo()
                ? Amortizacion::evaluarCapacidad($ingreso, $compromisos, $resumen['cuota'])
                : $capacidad;
        }

        $folio = Folio::unico('SOL', 'solicitudes_credito');

        $evaluacionId = $riesgo->registrar($titularId, $decision, 'credito');

        BaseDatos::ejecutar(
            'INSERT INTO solicitudes_credito
               (uuid, folio, usuario_id, producto_id, monto_cent, plazo_meses, tasa_bp,
                destino, ingreso_cent, etapa, observaciones, evaluacion_id)
             VALUES (UUID(),?,?,?,?,?,?,?,?, "capturada", ?,?)',
            [
                $folio, $titularId, (int) $producto['id'], $monto->centavos, $plazo,
                $tasaPactada,
                // El destino, resuelto contra la modalidad del
                // producto: un crédito de vehículo no puede quedar
                // registrado con destino «estudios universitarios».
                mb_substr(DestinoDeCredito::resolver(
                    (string) ($datos['destino'] ?? ''),
                    Clausulado::modalidadDe((string) $producto['codigo'])
                ), 0, 255),
                $ingreso->centavos,
                mb_substr($decision['narrativa'] . ' ' . $capacidad['motivo'], 0, 2000),
                $evaluacionId,
            ]
        );
        $id = BaseDatos::ultimoId();

        /* Los datos del bien que se financia.
         *
         * Se guardan con la solicitud y no con el crédito porque se
         * capturan ANTES de aprobar: qué vehículo se va a comprar es
         * parte de lo que se evalúa, no una consecuencia de haber
         * aprobado. */
        $this->guardarObjeto(
            $id,
            null,
            Clausulado::modalidadDe((string) $producto['codigo']),
            (array) ($datos['objeto'] ?? []),
            (string) ($datos['destino'] ?? '')
        );

        BaseDatos::ejecutar(
            'UPDATE evaluaciones_riesgo SET solicitud_id = ? WHERE id = ?',
            [$id, $evaluacionId]
        );

        if ($ingreso->esPositivo()) {
            BaseDatos::ejecutar(
                'UPDATE detalle_usuarios SET ingreso_mensual_cent = ? WHERE usuario_id = ?',
                [$ingreso->centavos, $titularId]
            );
        }

        Auditoria::registrar('credito.solicitado', Auditoria::EXITO, $actorId,
            'solicitud', (string) $id,
            ['folio' => $folio, 'monto' => $monto->aDecimal(), 'plazo' => $plazo]);

        $this->notificaciones->crear(
            $titularId, 'credito.solicitud', 'Solicitud de crédito recibida',
            'Su solicitud ' . $folio . ' por ' . $monto->formateado() . ' entró en revisión.'
            . ($ajuste['ajustado']
                ? ' Se capturó por ' . $monto->formateado() . ' a ' . $plazo . ' meses, '
                  . 'ajustada a su perfil crediticio.'
                : ''),
            ['folio' => $folio], 'info'
        );

        $this->avisarRevisores('solicitud.revisar_atencion', $folio, $monto);

        return [
            'id'        => $id,
            'folio'     => $folio,
            'monto'     => $monto->jsonSerialize(),
            'cuota'     => $resumen['cuota']->jsonSerialize(),
            'interes'   => $resumen['interes_total']->jsonSerialize(),
            'total'     => $resumen['total_pagar']->jsonSerialize(),
            'plazo'     => $plazo,
            'capacidad' => $capacidad,
            /* La decisión viaja con la respuesta: la pantalla tiene que
               poder decir «se le aprueba menos de lo que pidió, y esta
               es la razón» en el mismo momento. */
            'riesgo'    => [
                'puntaje'   => $decision['puntaje'],
                'banda'     => $decision['banda'],
                'etiqueta'  => $decision['etiqueta'],
                'narrativa' => $decision['narrativa'],
                'ajustado'  => (bool) $ajuste['ajustado'],
                'condiciones' => $decision['condiciones'],
            ],
        ];
    }

    /**
     * Simulador: no crea nada.
     *
     * @param int|null $tasaBp Tasa pactada en puntos base. Si viene, se
     *        comprueba contra el rango del producto —llega del navegador,
     *        y el `min`/`max` del deslizador no obliga a nada—. Si no
     *        viene, se usa la tasa del producto.
     */
    /**
     * Guarda los datos del bien que se financia.
     *
     * Se queda SOLO con los campos de la modalidad: un crédito
     * educativo no tiene número de chasis, y si la pantalla lo mandara
     * —porque el usuario escribió uno antes de cambiar de producto—
     * guardarlo dejaría un dato suelto que luego alguien leería como
     * verdadero.
     *
     * No guarda nada si no llegó ningún dato. Una fila vacía solo
     * sirve para que el contrato crea que tiene información y escriba
     * una cláusula con los dos puntos y nada detrás.
     *
     * @param array<string,mixed> $datos
     */
    private function guardarObjeto(
        ?int $solicitudId,
        ?int $creditoId,
        string $modalidad,
        array $datos,
        string $destino
    ): void {
        $prefijos = [
            'vehiculo' => 'veh_',
            'vivienda' => 'inm_',
            'estudios' => 'edu_',
            'negocio'  => 'neg_',
        ];

        $prefijo = $prefijos[$modalidad] ?? '';

        $limpios = [];

        foreach ($datos as $campo => $valor) {
            $campo = (string) $campo;
            $valor = trim((string) $valor);

            if ($valor === '') { continue; }

            $suyo = $prefijo !== '' && str_starts_with($campo, $prefijo);

            if ($suyo || $campo === 'detalle') {
                $limpios[$campo] = mb_substr($valor, 0, 255);
            }
        }

        if ($limpios === [] && $destino === '') { return; }

        $columnas = array_merge(
            ['solicitud_id', 'credito_id', 'modalidad', 'destino'],
            array_keys($limpios)
        );

        $valores = array_merge(
            [
                $solicitudId,
                $creditoId,
                $modalidad,
                mb_substr(DestinoDeCredito::resolver($destino, $modalidad), 0, 255),
            ],
            array_values($limpios)
        );

        $marcas = implode(',', array_fill(0, count($columnas), '?'));

        BaseDatos::ejecutar(
            'INSERT INTO credito_objeto (' . implode(',', $columnas) . ') '
            . 'VALUES (' . $marcas . ')',
            $valores
        );
    }

    public function simular(
        mixed $monto,
        int $plazo,
        int $productoId,
        ?int $tasaBp = null,
        mixed $ingreso = null,
        ?int $usuarioId = null
    ): array {
        $p = BaseDatos::unaFila(
            'SELECT codigo, nombre, tipo, tasa_interes_bp, tasa_min_bp, tasa_max_bp
               FROM productos WHERE id = ? AND tipo = "credito"',
            [$productoId]
        );
        if ($p === null) {
            throw new \InvalidArgumentException('Producto no válido.');
        }

        $tasa     = self::tasaValidada($p, $tasaBp);
        $montoObj = Monto::deEntradaUsuario($monto);

        $amort   = new Amortizacion($montoObj, $tasa, $plazo);
        $resumen = $amort->resumen();

        // Cargos fijos del contrato: los mismos tramos del sistema
        // anterior, ahora calculados en el servidor.
        $costos = CostosCredito::para(
            (string) $p['tipo'],
            $montoObj->centavos,
            (string) $p['codigo']
        );

        // Seguros obligatorios del primer mes, sobre el saldo completo.
        $seguroVida   = CostosCredito::seguroVida($montoObj->centavos);
        $seguroEmpleo = CostosCredito::seguroEmpleo($montoObj->centavos);
        $cuotaTotal   = $resumen['cuota']->centavos + $seguroVida + $seguroEmpleo;

        /* Capacidad de pago.
           Antes el sistema solo avisaba si la cuota no cabía; quien
           capturaba tenía que ir probando montos hasta acertar. Ahora
           se devuelve directamente el máximo que admite el ingreso, y
           la pantalla puede ofrecerlo. */
        $capacidad = null;

        if ($ingreso !== null && $ingreso !== '') {
            $ingresoObj   = Monto::deEntradaUsuario($ingreso);
            $compromisos  = $usuarioId !== null
                ? $this->compromisosMensuales($usuarioId)
                : Monto::cero();

            $cuotaMax  = CapacidadPago::cuotaMaxima($ingresoObj, $compromisos);
            $montoMax  = CapacidadPago::montoMaximo($ingresoObj, $compromisos, $tasa, $plazo);

            $capacidad = [
                'ingreso'      => ['centavos' => $ingresoObj->centavos,
                                    'formateado' => self::enDolares($ingresoObj->centavos)],
                'compromisos'  => ['centavos' => $compromisos->centavos,
                                    'formateado' => self::enDolares($compromisos->centavos)],
                'cuota_maxima' => ['centavos' => $cuotaMax->centavos,
                                    'formateado' => self::enDolares($cuotaMax->centavos)],
                'monto_maximo' => ['centavos' => $montoMax->centavos,
                                    'formateado' => self::enDolares($montoMax->centavos)],
                'cabe'         => $cuotaTotal <= $cuotaMax->centavos,
                'relacion_pct' => $cuotaMax->esPositivo()
                    ? round($cuotaTotal * 100 / max(1, $ingresoObj->centavos - $compromisos->centavos), 1)
                    : 100.0,
            ];
        }

        return [
            'producto'      => $p['nombre'],
            'tasa_bp'       => $tasa,
            'tasa_min_bp'   => (int) $p['tasa_min_bp'],
            'tasa_max_bp'   => (int) $p['tasa_max_bp'],
            'tasa_base_bp'  => (int) $p['tasa_interes_bp'],
            'ajustable'     => (int) $p['tasa_max_bp'] > (int) $p['tasa_min_bp'],
            // Primas del primer mes: son las más altas del crédito, porque
            // se calculan sobre el saldo completo. Se cotiza esa para no
            // prometer una cuota menor de la que se va a cobrar.
            'seguro_vida'   => ['centavos' => $seguroVida,
                                'formateado' => self::enDolares($seguroVida)],
            'seguro_empleo' => ['centavos' => $seguroEmpleo,
                                'formateado' => self::enDolares($seguroEmpleo)],
            'cuota_total'   => ['centavos' => $cuotaTotal,
                                'formateado' => self::enDolares($cuotaTotal)],
            'seguro'        => ['centavos' => $costos['seguro_cent'],
                                'formateado' => self::enDolares($costos['seguro_cent'])],
            'gastos'        => ['centavos' => $costos['gastos_cent'],
                                'formateado' => self::enDolares($costos['gastos_cent'])],
            'desembolso'    => ['centavos' => $costos['desembolso_cent'],
                                'formateado' => self::enDolares($costos['desembolso_cent'])],
            'capacidad'     => $capacidad,
            'tasa_anual'    => $resumen['tasa_anual'],
            'cuota'         => $resumen['cuota']->jsonSerialize(),
            'interes_total' => $resumen['interes_total']->jsonSerialize(),
            'total_pagar'   => $resumen['total_pagar']->jsonSerialize(),
            'costo_pct'     => $resumen['costo_pct'],
            'plazo'         => $plazo,
            'tabla'         => array_map(static fn(array $f): array => [
                'numero'      => $f['numero'],
                'capital'     => $f['capital']->centavos,
                'interes'     => $f['interes']->centavos,
                'cuota'       => $f['cuota']->centavos,
                'saldo'       => $f['saldo']->centavos,
                'vencimiento' => $f['vencimiento'],
            ], $amort->tabla()),
        ];
    }

    /**
     * Comprueba que la tasa pedida cabe en el rango del producto.
     *
     * Esta es la barrera de verdad. El deslizador de la pantalla lleva
     * `min` y `max`, pero eso es decoración: la petición puede llegar con
     * cualquier número, y sin esto bastaría con enviar `tasa=0` para
     * regalar un crédito sin intereses, o una tasa desorbitada para
     * perjudicar a un cliente.
     *
     * @param array<string,mixed> $producto
     */
    /**
     * Las cuotas se pagan en orden, de la más vieja a la más nueva.
     *
     * No es una comodidad de la pantalla: es cómo se imputa un pago en
     * un crédito. Dejar que alguien pague la cuota 12 con la 3 vencida
     * produce un crédito que, mirando los totales, parece al día y
     * tiene nueve cuotas sin pagar debajo. Y lo peor: el interés y la
     * mora de esas nueve se siguen devengando mientras el cliente cree
     * que va adelantado.
     *
     * La regla vale para TODOS —cliente, cajero y gerencia—: si quien
     * cobra en ventanilla puede saltarse el orden, la cartera acaba
     * igual de rota que si lo hiciera el cliente.
     *
     * Para pagar por adelantado está `pagarVarias()`, que cobra desde
     * la más vieja hacia adelante sin dejar huecos.
     */
    private static function exigirOrden(int $creditoId, int $numero): void
    {
        $anterior = BaseDatos::unaFila(
            'SELECT numero, fecha_vencimiento FROM cuotas
              WHERE credito_id = ? AND numero < ?
                AND estado NOT IN ("pagada","condonada")
              ORDER BY numero LIMIT 1',
            [$creditoId, $numero]
        );

        if ($anterior === null) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Las cuotas se pagan en orden. Antes de la %d queda pendiente la %d, '
            . 'que vence el %s. Si desea adelantar pagos, se cobran desde la más '
            . 'antigua hacia adelante.',
            $numero,
            (int) $anterior['numero'],
            date('d/m/Y', (int) strtotime((string) $anterior['fecha_vencimiento']))
        ));
    }

    /**
     * El grupo empieza en la más antigua pendiente y va seguido.
     *
     * Se comprueba contra la base y no contra lo que llegó: lo que
     * llega es una lista de identificadores que alguien pudo armar a
     * mano, y la verdad de qué cuotas quedan pendientes está aquí.
     *
     * @param list<array<string,mixed>> $filas Ya ordenadas por número.
     */
    private static function exigirGrupoSeguido(int $creditoId, array $filas): void
    {
        $pendientes = BaseDatos::todas(
            'SELECT numero FROM cuotas
              WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")
              ORDER BY numero',
            [$creditoId]
        );

        $esperados = array_slice(
            array_map(static fn(array $p): int => (int) $p['numero'], $pendientes),
            0,
            count($filas)
        );

        $elegidos = array_map(static fn(array $f): int => (int) $f['numero'], $filas);

        if ($elegidos === $esperados) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Las cuotas se pagan en orden y sin saltos. Para pagar %d cuota(s) '
            . 'corresponden la %s. Si quiere adelantar más, agregue las siguientes '
            . 'en orden.',
            count($filas),
            count($esperados) === 1
                ? 'número ' . $esperados[0]
                : 'de la ' . $esperados[0] . ' a la ' . end($esperados)
        ));
    }

    /**
     * @param bool $recortar Si la tasa viene del propio sistema —un
     *        recargo por riesgo, por ejemplo— se ajusta al rango en vez
     *        de reventar: el motor no es un formulario del que haya que
     *        desconfiar, y el tope del producto manda igual. Lo que
     *        llega del navegador sigue rechazándose de plano.
     */
    private static function tasaValidada(array $producto, ?int $tasaBp, bool $recortar = false): int
    {
        $base = (int) $producto['tasa_interes_bp'];

        if ($tasaBp === null) {
            return $base;
        }

        $min = (int) $producto['tasa_min_bp'];
        $max = (int) $producto['tasa_max_bp'];

        // Un producto sin rango configurado no admite ajuste: se queda
        // con la suya en lugar de aceptar lo que llegue.
        if ($max <= $min) {
            return $base;
        }

        if ($recortar) {
            return max($min, min($max, $tasaBp));
        }

        if ($tasaBp < $min || $tasaBp > $max) {
            throw new \InvalidArgumentException(sprintf(
                'La tasa debe estar entre %s %% y %s %% para este producto.',
                number_format($min / 100, 2),
                number_format($max / 100, 2)
            ));
        }

        return $tasaBp;
    }

    /**
     * Lo que el cliente ya paga cada mes.
     *
     * Suma las cuotas de sus créditos vigentes y el pago mínimo de sus
     * tarjetas de crédito. Contar solo los créditos dejaba fuera a quien
     * tiene el límite de la tarjeta consumido, que es un compromiso
     * mensual igual de real.
     */
    private function compromisosMensuales(int $usuarioId): Monto
    {
        $creditos = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(cuota_cent),0) FROM creditos
              WHERE usuario_id = ? AND estado = "vigente"',
            [$usuarioId]
        );

        // Para la tarjeta cuenta el pago mínimo sobre el saldo usado,
        // que es la obligación exigible cada mes.
        $pct = max(1, \CashMan\Infraestructura\Parametros::entero('tarjeta.pago_minimo_pct', 500));

        $saldoTarjetas = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(saldo_usado_cent),0) FROM tarjetas
              WHERE usuario_id = ? AND tipo = "credito" AND estado = "activa"',
            [$usuarioId]
        );

        $minimoTarjetas = (int) round($saldoTarjetas * $pct / 10000);

        return Monto::deCentavos($creditos + $minimoTarjetas);
    }

    /** Formato de dinero para las respuestas JSON del simulador. */
    private static function enDolares(int $centavos): string
    {
        return '$' . number_format($centavos / 100, 2);
    }

    // =================================================================
    // FLUJO DE APROBACIÓN
    // =================================================================

    /** @return array<string,mixed> */
    public function avanzarEtapa(int $solicitudId, string $observacion = ''): array
    {
        $actorId = Sesion::exigirAutenticado();

        return BaseDatos::enTransaccion(function () use ($solicitudId, $observacion, $actorId) {
            $s = BaseDatos::unaFila(
                'SELECT * FROM solicitudes_credito WHERE id = ? FOR UPDATE',
                [$solicitudId]
            );
            if ($s === null) {
                throw new \RuntimeException('Solicitud no encontrada.');
            }

            $etapa = (string) $s['etapa'];
            if (!isset(self::FLUJO[$etapa])) {
                throw new \RuntimeException('La solicitud ya no admite avances (estado: ' . $etapa . ').');
            }

            // Cada etapa exige su propio permiso: nadie salta pasos.
            Autorizacion::exigir(self::PERMISO_ETAPA[$etapa]);

            $siguiente = self::FLUJO[$etapa];
            $columna   = match ($etapa) {
                'capturada'         => 'revisado_atencion_por',
                'revision_atencion' => 'revisado_gerencia_por',
                'revision_gerencia' => 'revisado_presidencia_por',
                default             => null,
            };

            $sql  = 'UPDATE solicitudes_credito SET etapa = ?';
            $args = [$siguiente];

            if ($columna !== null) {
                $sql .= ", {$columna} = ?";
                $args[] = $actorId;
            }
            if ($observacion !== '') {
                $sql .= ', observaciones = CONCAT(COALESCE(observaciones,""), ?)';
                $args[] = "\n[" . date('d/m/Y H:i') . '] ' . mb_substr($observacion, 0, 500);
            }
            $sql .= ' WHERE id = ?';
            $args[] = $solicitudId;

            BaseDatos::ejecutar($sql, $args);

            Auditoria::registrar('solicitud.avanzada', Auditoria::EXITO, $actorId,
                'solicitud', (string) $solicitudId, ['de' => $etapa, 'a' => $siguiente]);

            // Al llegar a "aprobada" se crea el crédito con su tabla.
            $creditoId = null;
            if ($siguiente === 'aprobada') {
                $creditoId = $this->crearCredito($s, $actorId);
                BaseDatos::ejecutar(
                    'UPDATE solicitudes_credito SET credito_id = ? WHERE id = ?',
                    [$creditoId, $solicitudId]
                );
            }

            $this->notificaciones->crear(
                (int) $s['usuario_id'], 'credito.etapa',
                $siguiente === 'aprobada' ? 'Crédito aprobado' : 'Su solicitud avanzó',
                $siguiente === 'aprobada'
                    ? 'Su solicitud ' . $s['folio'] . ' fue aprobada. Pronto se desembolsará.'
                    : 'Su solicitud ' . $s['folio'] . ' pasó a: ' . str_replace('_', ' ', $siguiente),
                ['folio' => $s['folio']],
                $siguiente === 'aprobada' ? 'exito' : 'info'
            );

            if (isset(self::PERMISO_ETAPA[$siguiente])) {
                $this->avisarRevisores(
                    self::PERMISO_ETAPA[$siguiente],
                    (string) $s['folio'],
                    Monto::deCentavos((int) $s['monto_cent'])
                );
            }

            return ['etapa' => $siguiente, 'credito_id' => $creditoId, 'folio' => $s['folio']];
        });
    }

    public function rechazar(int $solicitudId, string $motivo): array
    {
        $actorId = Sesion::exigirAutenticado();

        $s = BaseDatos::unaFila('SELECT * FROM solicitudes_credito WHERE id = ?', [$solicitudId]);
        if ($s === null) {
            throw new \RuntimeException('Solicitud no encontrada.');
        }
        if (in_array($s['etapa'], ['aprobada', 'rechazada', 'desembolsada'], true)) {
            throw new \RuntimeException('La solicitud ya fue resuelta.');
        }

        Autorizacion::exigir(self::PERMISO_ETAPA[$s['etapa']] ?? 'credito.aprobar');

        if (trim($motivo) === '') {
            throw new \InvalidArgumentException('Debe indicar el motivo del rechazo.');
        }

        BaseDatos::ejecutar(
            'UPDATE solicitudes_credito SET etapa = "rechazada", motivo_rechazo = ? WHERE id = ?',
            [mb_substr($motivo, 0, 500), $solicitudId]
        );

        Auditoria::registrar('solicitud.rechazada', Auditoria::EXITO, $actorId,
            'solicitud', (string) $solicitudId, ['motivo' => mb_substr($motivo, 0, 120)]);

        $this->notificaciones->crear(
            (int) $s['usuario_id'], 'credito.rechazo', 'Solicitud no aprobada',
            'Su solicitud ' . $s['folio'] . ' no fue aprobada. Motivo: ' . mb_substr($motivo, 0, 180),
            ['folio' => $s['folio']], 'alerta'
        );

        return ['etapa' => 'rechazada', 'folio' => $s['folio']];
    }

    /**
     * Materializa el credito y su plan de pagos.
     *
     * Publico porque la aceptacion de una oferta preaprobada tiene
     * que crear el credito por este mismo camino. Duplicar el armado
     * de la tabla de amortizacion y de los seguros en otro servicio
     * seria tener dos verdades sobre lo que el cliente debe pagar.
     *
     * @param array<string,mixed> $solicitud
     */
    public function crearCredito(array $solicitud, int $actorId): int
    {
        $producto = BaseDatos::unaFila(
            'SELECT * FROM productos WHERE id = ?', [(int) $solicitud['producto_id']]
        );

        $monto  = Monto::deCentavos((int) $solicitud['monto_cent']);
        $plazo  = (int) $solicitud['plazo_meses'];
        // La tasa del crédito es la que se pactó en la solicitud. Si se
        // tomara la del producto, el cliente acabaría pagando una tasa
        // distinta de la que se le simuló y aprobó.
        $tasaBp = $solicitud['tasa_bp'] !== null
            ? (int) $solicitud['tasa_bp']
            : (int) $producto['tasa_interes_bp'];

        $amort = new Amortizacion($monto, $tasaBp, $plazo);
        $tabla = $amort->tabla();

        $folio = Folio::unico('CR', 'creditos');

        BaseDatos::ejecutar(
            'INSERT INTO creditos
               (uuid, folio, usuario_id, producto_id, solicitud_id, monto_cent,
                saldo_capital_cent, tasa_bp, plazo_meses, cuota_cent, estado,
                destino, aprobado_por, aprobado_en, proxima_cuota)
             VALUES (UUID(),?,?,?,?,?,?,?,?,?, "aprobado", ?,?, NOW(), ?)',
            [
                $folio, (int) $solicitud['usuario_id'], (int) $producto['id'],
                (int) $solicitud['id'], $monto->centavos, $monto->centavos,
                $tasaBp, $plazo, $amort->cuota()->centavos,
                (string) ($solicitud['destino'] ?? ''), $actorId,
                $tabla[0]['vencimiento'],
            ]
        );
        $creditoId = BaseDatos::ultimoId();

        /* El objeto viaja de la solicitud al crédito.
         *
         * El contrato lee del crédito, así que sin este paso el VIN
         * capturado en la solicitud no llegaría nunca al documento
         * que el cliente firma, y la cláusula del vehículo seguiría
         * remitiendo al expediente. */
        BaseDatos::ejecutar(
            'UPDATE credito_objeto SET credito_id = ?, actualizado_en = NOW()
              WHERE solicitud_id = ? AND credito_id IS NULL',
            [$creditoId, (int) $solicitud['id']]
        );

        // Los seguros obligatorios se calculan sobre el saldo con el que
        // ARRANCA cada periodo, que es el riesgo real que cubre la póliza
        // ese mes. Por eso van bajando cuota a cuota en lugar de ser un
        // cargo fijo sobre el monto original.
        $saldoApertura = $monto->centavos;

        foreach ($tabla as $f) {
            $seguroVida   = CostosCredito::seguroVida($saldoApertura);
            $seguroEmpleo = CostosCredito::seguroEmpleo($saldoApertura);

            BaseDatos::ejecutar(
                'INSERT INTO cuotas
                   (credito_id, numero, capital_cent, interes_cent,
                    seguro_vida_cent, seguro_empleo_cent, total_cent,
                    saldo_posterior_cent, fecha_vencimiento)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [
                    $creditoId, $f['numero'],
                    $f['capital']->centavos, $f['interes']->centavos,
                    $seguroVida, $seguroEmpleo,
                    // El total es lo que el cliente paga ese mes: cuota
                    // más las dos primas. Dejarlas fuera del total haría
                    // que la cuota mostrada no coincidiera con el cargo.
                    $f['cuota']->centavos + $seguroVida + $seguroEmpleo,
                    $f['saldo']->centavos,
                    $f['vencimiento'],
                ]
            );

            $saldoApertura = $f['saldo']->centavos;
        }

        Auditoria::registrar('credito.creado', Auditoria::EXITO, $actorId,
            'credito', (string) $creditoId, ['folio' => $folio, 'cuotas' => count($tabla)]);

        return $creditoId;
    }

    // =================================================================
    // DESEMBOLSO
    // =================================================================

    /** @return array<string,mixed> */
    public function desembolsar(int $creditoId, int $cuentaId): array
    {
        Autorizacion::exigir('credito.desembolsar');
        $actorId = Sesion::exigirAutenticado();

        return BaseDatos::enTransaccion(function () use ($creditoId, $cuentaId, $actorId) {
            $c = BaseDatos::unaFila('SELECT * FROM creditos WHERE id = ? FOR UPDATE', [$creditoId]);

            if ($c === null) {
                throw new \RuntimeException('Crédito no encontrado.');
            }
            if ($c['estado'] !== 'aprobado') {
                throw new \RuntimeException('Solo se desembolsan créditos aprobados (estado: ' . $c['estado'] . ').');
            }

            // La cuenta debe ser del titular del crédito.
            $cuenta = BaseDatos::unaFila(
                'SELECT * FROM cuentas WHERE id = ? AND usuario_id = ? AND es_tecnica = 0 AND estado = "activa"',
                [$cuentaId, (int) $c['usuario_id']]
            );
            if ($cuenta === null) {
                throw new \RuntimeException('La cuenta de desembolso debe ser del titular y estar activa.');
            }

            $monto   = Monto::deCentavos((int) $c['monto_cent']);
            $tecnica = (int) BaseDatos::unValor('SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1');

            LibroMayor::bloquearCuentas([$cuentaId, $tecnica]);

            $asiento = LibroMayor::registrar(
                'deposito',
                [
                    ['cuenta_id' => $cuentaId, 'importe' => $monto,
                     'descripcion' => 'Desembolso de crédito ' . $c['folio']],
                    ['cuenta_id' => $tecnica,  'importe' => $monto->negado(),
                     'descripcion' => 'Contrapartida de desembolso'],
                ],
                $actorId,
                'desembolso-credito-' . $creditoId,
                'Desembolso ' . $c['folio']
            );

            BaseDatos::ejecutar(
                'UPDATE creditos SET estado = "vigente", desembolsado_en = NOW(),
                        cuenta_desembolso_id = ? WHERE id = ?',
                [$cuentaId, $creditoId]
            );
            BaseDatos::ejecutar(
                'UPDATE solicitudes_credito SET etapa = "desembolsada" WHERE credito_id = ?',
                [$creditoId]
            );

            Auditoria::registrar('credito.desembolsado', Auditoria::EXITO, $actorId,
                'credito', (string) $creditoId,
                ['monto' => $monto->aDecimal(), 'cuenta' => $cuenta['numero_cuenta']]);

            $this->notificaciones->crear(
                (int) $c['usuario_id'], 'credito.desembolso', 'Crédito desembolsado',
                'Se acreditaron ' . $monto->formateado() . ' en su cuenta ' . $cuenta['numero_cuenta'] . '.',
                ['referencia' => $asiento['referencia']], 'exito'
            );

            return [
                'referencia' => $asiento['referencia'],
                'monto'      => $monto->jsonSerialize(),
                'cuenta'     => $cuenta['numero_cuenta'],
            ];
        });
    }

    // =================================================================
    // PAGO DE CUOTAS
    // =================================================================

    /**
     * Con qué se puede pagar una cuota.
     *
     * `cuenta` es el débito de siempre. Los otros dos son dinero que el
     * cliente trae al mostrador y que no pasa por su cuenta de ahorro.
     */
    public const MEDIOS_PAGO = ['cuenta', 'efectivo', 'cheque'];

    /** Un medio del catálogo, o el débito a cuenta de siempre. */
    private static function medioDePago(mixed $medio): string
    {
        $m = is_string($medio) ? strtolower(trim($medio)) : '';

        return in_array($m, self::MEDIOS_PAGO, true) ? $m : 'cuenta';
    }

    /**
     * La cuenta técnica que hace de contrapartida del efectivo.
     *
     * Se busca por número y no por «la primera técnica que haya»: esa
     * es la general y usarla para las dos patas del asiento dejaría un
     * apunte de una cuenta contra sí misma, que cuadra y no dice nada.
     *
     * Si falta, se cae antes de mover un centavo. Un cobro a medias es
     * peor que un cobro que no se hizo.
     */
    private static function cuentaDeEfectivo(): int
    {
        $numero = \CashMan\Infraestructura\Parametros::obtener(
            'caja.cuenta_efectivo',
            '0000000005'
        );

        $id = (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE numero_cuenta = ? AND es_tecnica = 1',
            [$numero]
        );

        if ($id === 0) {
            throw new \RuntimeException(
                'No está configurada la cuenta técnica del efectivo de ventanilla. '
                . 'Avise a sistemas antes de cobrar en efectivo.'
            );
        }

        return $id;
    }
    /**
     * Cobra una cuota, de una cuenta o en ventanilla.
     *
     * `$pago['medio']` dice de dónde sale el dinero:
     *
     *   · `cuenta`   — se debita la cuenta de ahorro del cliente. Es lo
     *                  único que había, y lo único que tiene sentido
     *                  cuando paga el propio titular desde su banca;
     *   · `efectivo` — el cliente puso los billetes en el mostrador;
     *   · `cheque`   — trajo un cheque, del que se anota banco y número.
     *
     * El apaño que había era cobrar siempre contra la cuenta y poner en
     * la pantalla «con el efectivo ya recibido en ventanilla»: el
     * cajero tomaba el dinero y después debitaba la cuenta, así que al
     * cliente le aparecía en su estado de cuenta un cargo que él no
     * hizo, y el efectivo no quedaba anotado en ningún sitio. Si la
     * cuenta no tenía fondos —que es la razón por la que alguien va a
     * pagar en efectivo— el cobro ni siquiera se podía hacer.
     *
     * @param array{medio?:string, cheque_banco?:string, cheque_numero?:string} $pago
     * @return array<string,mixed>
     */
    public function pagarCuota(
        int $cuotaId,
        int $cuentaId,
        string $claveIdem,
        array $pago = []
    ): array {
        $actorId = Sesion::exigirAutenticado();
        $medio   = self::medioDePago($pago['medio'] ?? 'cuenta');

        if ($medio === 'cheque'
            && (trim((string) ($pago['cheque_banco'] ?? '')) === ''
             || trim((string) ($pago['cheque_numero'] ?? '')) === '')) {
            throw new \InvalidArgumentException(
                'De un cheque hay que anotar el banco y el numero.'
            );
        }

        return BaseDatos::enTransaccion(function () use (
            $cuotaId, $cuentaId, $actorId, $claveIdem, $medio, $pago
        ) {
            $q = BaseDatos::unaFila(
                'SELECT q.*, c.folio, c.usuario_id, c.saldo_capital_cent, c.id AS credito_id
                   FROM cuotas q JOIN creditos c ON c.id = q.credito_id
                  WHERE q.id = ? FOR UPDATE',
                [$cuotaId]
            );

            if ($q === null) {
                throw new \RuntimeException('Cuota no encontrada.');
            }
            if ($q['estado'] === 'pagada') {
                throw new \RuntimeException('Esta cuota ya está pagada.');
            }

            self::exigirOrden((int) $q['credito_id'], (int) $q['numero']);

            // El titular paga la suya; el personal puede pagar en ventanilla.
            if ((int) $q['usuario_id'] !== $actorId) {
                Autorizacion::exigir('deposito.registrar');
            }

            /* En ventanilla el dinero lo trae el cliente, así que no
               hay cuenta que validar ni fondos que comprobar: es
               justamente quien no tiene saldo el que viene a pagar en
               efectivo. Quien cobra sí necesita permiso de caja. */
            $cuenta = null;

            if ($medio === 'cuenta') {
                $cuenta = Autorizacion::exigirCuenta($cuentaId, $actorId, 'cuenta.ver_todas');
                Autorizacion::exigirCuentaOperable($cuenta);
            } else {
                Autorizacion::exigir('deposito.registrar');
            }

            // Mora proporcional al atraso, no una suma fija.
            $dias = max(0, (int) (new \DateTimeImmutable())
                ->diff(new \DateTimeImmutable((string) $q['fecha_vencimiento']))->days
                * (new \DateTimeImmutable((string) $q['fecha_vencimiento']) < new \DateTimeImmutable() ? 1 : 0));

            $pendiente = Monto::deCentavos((int) $q['total_cent'] - (int) $q['pagado_cent']);
            $mora      = Amortizacion::calcularMora($pendiente, $dias);
            $aPagar    = $pendiente->mas($mora);

            $tecnica = (int) BaseDatos::unValor('SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1');

            if ($medio === 'cuenta') {
                LibroMayor::bloquearCuentas([$cuentaId]);
                $saldoActual = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]);
                $minimo      = (int) ($cuenta['saldo_minimo_cent'] ?? 0);

                if ($saldoActual - $minimo < $aPagar->centavos) {
                    throw new FondosInsuficientes(
                        'Fondos insuficientes. Se requieren ' . $aPagar->formateado()
                        . ' y hay ' . Monto::deCentavos(max(0, $saldoActual - $minimo))->formateado() . ' disponibles.'
                    );
                }

                $origenId   = $cuentaId;
                $descOrigen = 'Pago cuota ' . $q['numero'] . ' de ' . $q['folio'];
            } else {
                /* El dinero entró por la ventanilla y no salió de
                   ninguna cuenta: la contrapartida es la cuenta técnica
                   del efectivo, que se vuelve negativa cuando el banco
                   recibe dinero de fuera, igual que en un depósito. */
                $origenId   = self::cuentaDeEfectivo();
                $descOrigen = ucfirst($medio) . ' recibido en ventanilla · cuota '
                            . $q['numero'] . ' de ' . $q['folio'];
            }

            LibroMayor::bloquearCuentas([$origenId, $tecnica]);

            $asiento = LibroMayor::registrar(
                'pago_cuota',
                [
                    ['cuenta_id' => $origenId, 'importe' => $aPagar->negado(),
                     'descripcion' => $descOrigen],
                    ['cuenta_id' => $tecnica,  'importe' => $aPagar,
                     'descripcion' => 'Cobro de cuota ' . $q['folio']],
                ],
                $actorId,
                $claveIdem,
                'Pago de cuota ' . $q['numero']
            );

            if (($asiento['duplicado'] ?? false) === true) {
                return ['estado' => 'duplicado', 'referencia' => $asiento['referencia']];
            }

            /* El asiento queda apuntado en la propia cuota.
               Es lo que permite que el cliente saque el comprobante de
               ESE pago desde su tabla de amortización en vez de tener
               que buscarlo entre los movimientos de la cuenta. */
            BaseDatos::ejecutar(
                'UPDATE cuotas
                    SET pagado_cent = total_cent, mora_cent = ?, estado = "pagada",
                        pagada_en = NOW(), pagada_tarde = ?, dias_mora = ?,
                        asiento_id = ?
                  WHERE id = ?',
                [$mora->centavos, $dias > 0 ? 1 : 0, $dias,
                 (int) ($asiento['asiento_id'] ?? 0) ?: null, $cuotaId]
            );

            BaseDatos::ejecutar(
                'UPDATE creditos c SET
                    saldo_capital_cent = GREATEST(0, saldo_capital_cent - ?),
                    total_pagado_cent  = total_pagado_cent + ?,
                    cuotas_pagadas     = (SELECT COUNT(*) FROM cuotas WHERE credito_id=c.id AND estado="pagada"),
                    cuotas_tardias     = (SELECT COUNT(*) FROM cuotas WHERE credito_id=c.id AND pagada_tarde=1),
                    proxima_cuota      = (SELECT MIN(fecha_vencimiento) FROM cuotas
                                           WHERE credito_id=c.id AND estado NOT IN ("pagada","condonada"))
                  WHERE c.id = ?',
                [(int) $q['capital_cent'], $aPagar->centavos, (int) $q['credito_id']]
            );

            // Última cuota: el crédito se cancela y pasa al histórico.
            $restantes = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM cuotas WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")',
                [(int) $q['credito_id']]
            );

            if ($restantes === 0) {
                BaseDatos::ejecutar(
                    'UPDATE creditos SET estado = "cancelado", saldo_capital_cent = 0 WHERE id = ?',
                    [(int) $q['credito_id']]
                );
                $this->archivarCredito((int) $q['credito_id']);

                $this->notificaciones->crear(
                    (int) $q['usuario_id'], 'credito.cancelado', 'Crédito cancelado',
                    'Felicidades, terminaste de pagar su crédito ' . $q['folio'] . '.',
                    null, 'exito'
                );
            }

            // El expediente de cobro refleja lo que se debe ahora.
            (new ServicioCobranza())->refrescarCaso((int) $q['credito_id']);

            /* El dinero físico entra a la caja de quien lo recibió.
             *
             * Sin esto el cajero se queda con unos billetes que no
             * constan en ninguna parte y que al arquear le aparecen
             * como sobrante. El cheque se anota igual pero no cuenta
             * como efectivo: `ServicioSesionCaja` ya separa las dos
             * cosas. Si no hay turno abierto —el titular pagando desde
             * su casa— no se anota nada. */
            if ($medio !== 'cuenta') {
                (new ServicioSesionCaja())->anotarSiHayTurno(
                    'pago_credito',
                    $aPagar->centavos,
                    ucfirst($medio) . ' por cuota ' . $q['numero'] . ' de ' . $q['folio'],
                    [
                        'cliente_id'    => (int) $q['usuario_id'],
                        'credito_id'    => (int) $q['credito_id'],
                        'asiento_id'    => $asiento['asiento_id'] ?? null,
                        'medio'         => $medio,
                        'cheque_banco'  => $pago['cheque_banco']  ?? null,
                        'cheque_numero' => $pago['cheque_numero'] ?? null,
                    ]
                );
            }

            Auditoria::registrar('cuota.pagada', Auditoria::EXITO, $actorId,
                'cuota', (string) $cuotaId,
                ['credito' => $q['folio'], 'monto' => $aPagar->aDecimal(),
                 'mora' => $mora->aDecimal(), 'medio' => $medio]);

            /* El aviso dice con qué se pagó.
               «Pago registrado» a secas, cuando el cobro fue en
               efectivo en una ventanilla, no le deja al cliente forma
               de reconocer la operación ni de reclamarla. */
            $comoPago = match ($medio) {
                'efectivo' => ' Lo pagó en efectivo en ventanilla.',
                'cheque'   => ' Lo pagó con cheque'
                            . (($pago['cheque_banco'] ?? '') !== ''
                                ? ' de ' . $pago['cheque_banco'] : '')
                            . (($pago['cheque_numero'] ?? '') !== ''
                                ? ' n.º ' . $pago['cheque_numero'] : '')
                            . ' en ventanilla.',
                default    => '',
            };

            $this->notificaciones->crear(
                (int) $q['usuario_id'], 'cuota.pagada', 'Pago registrado',
                'Cuota ' . $q['numero'] . ' de ' . $q['folio'] . ' pagada por ' . $aPagar->formateado()
                . ($mora->esPositivo() ? ' (incluye ' . $mora->formateado() . ' de mora)' : '')
                . $comoPago,
                ['referencia' => $asiento['referencia'], 'asiento_id' => $asiento['asiento_id'] ?? null],
                'exito'
            );

            return [
                'estado'      => 'pagada',
                'referencia'  => $asiento['referencia'],
                'monto'       => $aPagar->jsonSerialize(),
                'mora'        => $mora->jsonSerialize(),
                'dias_mora'   => $dias,
                'medio'       => $medio,
                'restantes'   => $restantes,
                'cancelado'   => $restantes === 0,
            ];
        });
    }

    /**
     * Cobra varias cuotas en una sola operación.
     *
     * Con 53 cuotas vencidas, cobrarlas de una en una son 53 diálogos,
     * 53 asientos y 53 avisos al cliente por lo que él vive como un
     * único pago. Peor: si la cuenta se queda sin fondos en la cuota 30,
     * quedan 29 cobradas y 24 sin cobrar, un estado a medias que nadie
     * pidió.
     *
     * Aquí va todo dentro de una transacción. Se comprueba el saldo
     * contra el total ANTES de tocar nada, de modo que o entran todas o
     * no entra ninguna.
     *
     * @param list<int> $cuotaIds
     * @return array<string,mixed>
     */
    public function pagarVarias(
        array $cuotaIds,
        int $cuentaId,
        string $claveIdem,
        array $pago = []
    ): array {
        $actorId = Sesion::exigirAutenticado();
        $medio   = self::medioDePago($pago['medio'] ?? 'cuenta');

        if ($medio === 'cheque'
            && (trim((string) ($pago['cheque_banco'] ?? '')) === ''
             || trim((string) ($pago['cheque_numero'] ?? '')) === '')) {
            throw new \InvalidArgumentException(
                'De un cheque hay que anotar el banco y el numero.'
            );
        }

        $cuotaIds = array_values(array_unique(array_filter(
            array_map('intval', $cuotaIds),
            static fn(int $id): bool => $id > 0
        )));

        if ($cuotaIds === []) {
            throw new \RuntimeException('No se indicó ninguna cuota.');
        }

        if (count($cuotaIds) > 60) {
            throw new \RuntimeException(
                'Son demasiadas cuotas para un solo cobro. Divídalo en varios.'
            );
        }

        return BaseDatos::enTransaccion(function () use (
            $cuotaIds, $cuentaId, $actorId, $claveIdem, $medio, $pago
        ) {
            $marcas = implode(',', array_fill(0, count($cuotaIds), '?'));

            $filas = BaseDatos::todas(
                'SELECT q.*, c.folio, c.usuario_id, c.id AS credito_id
                   FROM cuotas q JOIN creditos c ON c.id = q.credito_id
                  WHERE q.id IN (' . $marcas . ') AND q.estado NOT IN ("pagada","condonada")
                  ORDER BY q.numero
                  FOR UPDATE',
                $cuotaIds
            );

            if ($filas === []) {
                throw new \RuntimeException('Ninguna de esas cuotas sigue pendiente.');
            }

            // Todas tienen que ser del mismo crédito: mezclar créditos
            // en un asiento haría imposible leer después a qué se
            // aplicó cada centavo.
            $creditos = array_unique(array_map(
                static fn(array $f): int => (int) $f['credito_id'], $filas
            ));

            if (count($creditos) > 1) {
                throw new \RuntimeException(
                    'Las cuotas seleccionadas son de créditos distintos.'
                );
            }

            /* El grupo tiene que arrancar en la más antigua pendiente y
             * no dejar huecos.
             *
             * Adelantar pagos está bien; saltarse cuotas no. Pagar la 3
             * y la 7 dejando la 4, la 5 y la 6 produce un crédito que
             * parece al día por totales y tiene tres cuotas vencidas
             * debajo devengando mora. */
            self::exigirGrupoSeguido((int) $filas[0]['credito_id'], $filas);

            $titular = (int) $filas[0]['usuario_id'];

            if ($titular !== $actorId) {
                Autorizacion::exigir('deposito.registrar');
            }

            // En ventanilla el dinero lo trae el cliente: no hay cuenta
            // que validar. Ver `pagarCuota()`, donde está explicado.
            $cuenta = null;

            if ($medio === 'cuenta') {
                $cuenta = Autorizacion::exigirCuenta($cuentaId, $actorId, 'cuenta.ver_todas');
                Autorizacion::exigirCuentaOperable($cuenta);
            } else {
                Autorizacion::exigir('deposito.registrar');
            }

            $hoy   = new \DateTimeImmutable();
            $total = Monto::cero();
            $moras = [];

            foreach ($filas as $f) {
                $vence = new \DateTimeImmutable((string) $f['fecha_vencimiento']);
                $dias  = $vence < $hoy ? (int) $hoy->diff($vence)->days : 0;

                $pendiente = Monto::deCentavos((int) $f['total_cent'] - (int) $f['pagado_cent']);
                $mora      = Amortizacion::calcularMora($pendiente, $dias);

                $moras[(int) $f['id']] = ['mora' => $mora, 'dias' => $dias];
                $total = $total->mas($pendiente)->mas($mora);
            }

            $tecnica = (int) BaseDatos::unValor('SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1');
            $folio   = (string) $filas[0]['folio'];
            $numeros = implode(', ', array_map(static fn(array $f): string => (string) $f['numero'], $filas));

            if ($medio === 'cuenta') {
                LibroMayor::bloquearCuentas([$cuentaId]);

                $saldo  = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]);
                $minimo = (int) ($cuenta['saldo_minimo_cent'] ?? 0);

                if ($saldo - $minimo < $total->centavos) {
                    throw new FondosInsuficientes(
                        'Fondos insuficientes para las ' . count($filas) . ' cuotas. '
                        . 'Se requieren ' . $total->formateado() . ' y hay '
                        . Monto::deCentavos(max(0, $saldo - $minimo))->formateado() . ' disponibles.'
                    );
                }

                $origenId   = $cuentaId;
                $descOrigen = 'Pago de ' . count($filas) . ' cuota(s) de ' . $folio;
            } else {
                $origenId   = self::cuentaDeEfectivo();
                $descOrigen = ucfirst($medio) . ' recibido en ventanilla · '
                            . count($filas) . ' cuota(s) de ' . $folio;
            }

            LibroMayor::bloquearCuentas([$origenId, $tecnica]);

            $asiento = LibroMayor::registrar(
                'pago_cuota',
                [
                    ['cuenta_id' => $origenId, 'importe' => $total->negado(),
                     'descripcion' => $descOrigen],
                    ['cuenta_id' => $tecnica, 'importe' => $total,
                     'descripcion' => 'Cobro cuotas ' . $numeros . ' de ' . $folio],
                ],
                $actorId,
                $claveIdem,
                'Pago de ' . count($filas) . ' cuotas de ' . $folio
            );

            /* El dinero físico, a la caja de quien lo recibió.
               Una sola anotación por el lote: el cliente entregó un
               fajo, no veinte pagos. */
            if ($medio !== 'cuenta') {
                (new ServicioSesionCaja())->anotarSiHayTurno(
                    'pago_credito',
                    $total->centavos,
                    ucfirst($medio) . ' por ' . count($filas) . ' cuota(s) de ' . $folio,
                    [
                        'cliente_id'    => (int) $filas[0]['usuario_id'],
                        'credito_id'    => (int) $filas[0]['credito_id'],
                        'asiento_id'    => $asiento['asiento_id'] ?? null,
                        'medio'         => $medio,
                        'cheque_banco'  => $pago['cheque_banco']  ?? null,
                        'cheque_numero' => $pago['cheque_numero'] ?? null,
                    ]
                );
            }

            if (($asiento['duplicado'] ?? false) === true) {
                return ['estado' => 'duplicado', 'referencia' => $asiento['referencia']];
            }

            $capital = 0;

            foreach ($filas as $f) {
                $m = $moras[(int) $f['id']];

                /* Todas apuntan al mismo asiento, que es lo correcto:
                   fue un solo pago. El comprobante que saque el cliente
                   desde cualquiera de ellas es el de esa operación, con
                   todas las cuotas que cubrió. */
                BaseDatos::ejecutar(
                    'UPDATE cuotas
                        SET pagado_cent = total_cent, mora_cent = ?, estado = "pagada",
                            pagada_en = NOW(), pagada_tarde = ?, dias_mora = ?,
                            asiento_id = ?
                      WHERE id = ?',
                    [$m['mora']->centavos, $m['dias'] > 0 ? 1 : 0, $m['dias'],
                     (int) ($asiento['asiento_id'] ?? 0) ?: null, (int) $f['id']]
                );

                $capital += (int) $f['capital_cent'];
            }

            $creditoId = (int) $filas[0]['credito_id'];

            BaseDatos::ejecutar(
                'UPDATE creditos c SET
                    saldo_capital_cent = GREATEST(0, saldo_capital_cent - ?),
                    total_pagado_cent  = total_pagado_cent + ?,
                    cuotas_pagadas     = (SELECT COUNT(*) FROM cuotas WHERE credito_id=c.id AND estado="pagada"),
                    cuotas_tardias     = (SELECT COUNT(*) FROM cuotas WHERE credito_id=c.id AND pagada_tarde=1),
                    proxima_cuota      = (SELECT MIN(fecha_vencimiento) FROM cuotas
                                           WHERE credito_id=c.id AND estado NOT IN ("pagada","condonada"))
                  WHERE c.id = ?',
                [$capital, $total->centavos, $creditoId]
            );

            $restantes = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM cuotas WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")',
                [$creditoId]
            );

            if ($restantes === 0) {
                BaseDatos::ejecutar(
                    'UPDATE creditos SET estado = "cancelado", saldo_capital_cent = 0 WHERE id = ?',
                    [$creditoId]
                );
                $this->archivarCredito($creditoId);
            }

            (new ServicioCobranza())->refrescarCaso($creditoId);

            Auditoria::registrar('cuota.pagada_lote', Auditoria::EXITO, $actorId,
                'credito', (string) $creditoId, [
                    'folio'  => $folio,
                    'cuotas' => count($filas),
                    'monto'  => $total->aDecimal(),
                ]);

            // Un solo aviso, no uno por cuota: para el cliente fue un
            // pago, y recibir 53 correos seguidos sería hostil.
            $this->notificaciones->crear(
                $titular,
                'cuota.pagada',
                'Pago registrado',
                'Recibimos ' . $total->formateado() . ' y con ello quedan pagadas '
                . count($filas) . ' cuota(s) de su crédito ' . $folio
                . ' (la ' . $numeros . ').',
                ['referencia' => $asiento['referencia']],
                'exito'
            );

            return [
                'estado'     => 'pagada',
                'referencia' => $asiento['referencia'],
                'cuotas'     => count($filas),
                'monto'      => $total->jsonSerialize(),
                'restantes'  => $restantes,
                'cancelado'  => $restantes === 0,
            ];
        });
    }

    private function archivarCredito(int $creditoId): void
    {
        $c = BaseDatos::unaFila('SELECT * FROM creditos WHERE id = ?', [$creditoId]);
        if ($c === null) {
            return;
        }

        $agg = BaseDatos::unaFila(
            'SELECT COALESCE(SUM(interes_cent),0) interes, COALESCE(SUM(mora_cent),0) mora
               FROM cuotas WHERE credito_id = ?',
            [$creditoId]
        );

        $tardias = (int) $c['cuotas_tardias'];
        $clasif  = match (true) {
            $tardias === 0 => 'A',
            $tardias <= 2  => 'B',
            $tardias <= 5  => 'C',
            $tardias <= 9  => 'D',
            default        => 'E',
        };

        BaseDatos::ejecutar(
            'INSERT INTO historico_creditos
               (credito_id, usuario_id, producto_id, monto_cent, tasa_bp, plazo_meses,
                cuota_cent, total_pagado_cent, total_interes_cent, total_mora_cent,
                cuotas_pagadas, cuotas_tardias, motivo_cierre, clasificacion_final)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?, "cancelado", ?)',
            [
                $creditoId, (int) $c['usuario_id'], (int) $c['producto_id'],
                (int) $c['monto_cent'], (int) $c['tasa_bp'], (int) $c['plazo_meses'],
                (int) $c['cuota_cent'], (int) $c['total_pagado_cent'],
                (int) $agg['interes'], (int) $agg['mora'],
                (int) $c['cuotas_pagadas'], $tardias, $clasif,
            ]
        );
    }

    // =================================================================

    private function avisarRevisores(string $permiso, string $folio, Monto $monto): void
    {
        $revisores = BaseDatos::todas(
            'SELECT DISTINCT u.id FROM usuarios u
               JOIN rol_permiso rp ON rp.rol_id = u.rol_id
               JOIN permisos p ON p.id = rp.permiso_id
              WHERE p.clave = ? AND u.estado = "activo" LIMIT 15',
            [$permiso]
        );

        foreach ($revisores as $r) {
            $this->notificaciones->crear(
                (int) $r['id'], 'solicitud.revision', 'Solicitud por revisar',
                $folio . ' por ' . $monto->formateado() . ' espera tu revisión.',
                ['folio' => $folio], 'info'
            );
        }
    }
}
