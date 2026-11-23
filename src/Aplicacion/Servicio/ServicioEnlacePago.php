<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\Mora;
use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Enlaces de pago para cobranza.
 *
 * El personal de cobranza no puede debitar la cuenta de un cliente para
 * cubrir su mora, y tampoco debería pedirle el número de tarjeta por
 * teléfono y teclearlo él: eso convierte a cada gestor en un punto por
 * donde se filtran tarjetas.
 *
 * Lo que sí puede hacer es mandarle un enlace con la cantidad ya
 * calculada. El cliente lo abre, paga con su tarjeta en la pasarela y
 * nadie de la institución llega a ver el número.
 *
 * El enlace es una credencial, y se trata como tal:
 *
 *   - El token va en la URL pero en la base solo se guarda su hash. Un
 *     volcado de la base no sirve para abrir los enlaces vivos.
 *   - Caduca. Una semana por omisión.
 *   - Es de un solo uso: una vez pagado deja de abrir.
 *   - El importe se fija al crearlo y no se recalcula al abrirlo. Si el
 *     cliente tarda tres días, paga lo que se le dijo, no lo que diga la
 *     mora ese día.
 */
final class ServicioEnlacePago
{
    // =================================================================
    // CREAR
    // =================================================================

    /**
     * Enlace para cobrar cuotas concretas.
     *
     * @param list<int> $cuotaIds
     * @return array<string,mixed>
     */
    public function paraCuotas(int $creditoId, array $cuotaIds): array
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('cobro.enlace');

        $cuotaIds = array_values(array_filter(array_map('intval', $cuotaIds)));

        if ($cuotaIds === []) {
            // Sin selección explícita se cobra todo lo vencido, que es
            // lo que cobranza quiere el 90% de las veces.
            $cuotaIds = array_map(
                static fn(array $f): int => (int) $f['id'],
                BaseDatos::todas(
                    'SELECT id FROM cuotas
                      WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")
                        AND fecha_vencimiento < CURDATE()
                      ORDER BY numero',
                    [$creditoId]
                )
            );
        }

        if ($cuotaIds === []) {
            throw new \RuntimeException('Este crédito no tiene cuotas pendientes de cobro.');
        }

        $marcas = implode(',', array_fill(0, count($cuotaIds), '?'));

        $filas = BaseDatos::todas(
            'SELECT q.id, q.numero, q.total_cent, q.pagado_cent, q.fecha_vencimiento,
                    DATEDIFF(CURDATE(), q.fecha_vencimiento) AS dias
               FROM cuotas q
              WHERE q.id IN (' . $marcas . ') AND q.credito_id = ? AND q.estado NOT IN ("pagada","condonada")',
            [...$cuotaIds, $creditoId]
        );

        if ($filas === []) {
            throw new \RuntimeException('Ninguna de esas cuotas sigue pendiente.');
        }

        $cubre = $this->sumar($filas);

        /* La mora COMPLETA del crédito, no solo lo que se cobra aquí.
         *
         * El requerimiento decía «su crédito registra 5 cuota(s) en
         * mora» cuando registraba 53: contaba las que se habían marcado
         * para cobrar, no las que el crédito debe. En una comunicación
         * que obliga a la institución eso es una declaración falsa, y
         * de las que se vuelven en contra: el deudor puede sostener que
         * se le requirió por cinco y que el resto no se le reclamó.
         *
         * Se calcula aparte para poder decir las dos cosas: cuánto debe
         * el crédito y cuánto comprende este requerimiento. */
        $todas = BaseDatos::todas(
            'SELECT q.id, q.numero, q.total_cent, q.pagado_cent, q.fecha_vencimiento,
                    DATEDIFF(CURDATE(), q.fecha_vencimiento) AS dias
               FROM cuotas q
              WHERE q.credito_id = ? AND q.estado NOT IN ("pagada","condonada")
                AND q.fecha_vencimiento < CURDATE()
              ORDER BY q.numero',
            [$creditoId]
        );

        $total = $this->sumar($todas);

        $credito = $this->credito($creditoId);

        return $this->crear(
            (int) $credito['usuario_id'],
            $creditoId,
            null,
            /* El concepto dice cuántas cuotas y de qué crédito.
             *
             * Es lo que el deudor lee como resumen de lo que se le
             * cobra, así que tiene que coincidir con el cuerpo de la
             * carta y con la cantidad requerida. */
            $cubre['cuotas'] === 1
                ? 'Cuota ' . $filas[0]['numero'] . ' del crédito ' . $credito['folio']
                : $cubre['cuotas'] . ' cuotas vencidas del crédito ' . $credito['folio'],
            $cubre['capital'] + $cubre['mora'],
            $credito,
            $actorId,
            /* El desglose que exige un requerimiento.
             *
             * No basta con mandar un total: el deudor tiene derecho a
             * saber cuánto es saldo, cuánto es recargo, desde cuándo se
             * le reclama y cuántas cuotas comprende. Un cobro que no se
             * puede verificar no se puede discutir, y eso lo vuelve
             * impugnable. */
            [
                'clase'   => 'cuotas',
                'cuotas'  => $cubre['cuotas'],
                'capital' => $cubre['capital'],
                'mora'    => $cubre['mora'],
                'dias'    => $total['dias'],
                'desde'   => $total['desde'],

                // La posición completa del crédito.
                'mora_total_cuotas' => $total['cuotas'],
                'mora_total_cent'   => $total['capital'] + $total['mora'],
                'parcial'           => $cubre['cuotas'] < $total['cuotas'],
            ]
        ) + ['cuotas' => $cubre['cuotas']];
    }

    /**
     * Suma un conjunto de cuotas vencidas con su recargo.
     *
     * La mora se calcula con la misma clase que usa el cobro, de modo
     * que lo que se le reclama al deudor es exactamente lo que el
     * sistema le va a cobrar.
     *
     * @param list<array<string,mixed>> $filas
     * @return array{cuotas:int, capital:int, mora:int, dias:int, desde:string}
     */
    private function sumar(array $filas): array
    {
        $capital = 0;
        $mora    = 0;
        $dias    = 0;
        $desde   = null;

        foreach ($filas as $f) {
            $pendiente = Monto::deCentavos((int) $f['total_cent'] - (int) $f['pagado_cent']);
            $d = max(0, (int) $f['dias']);

            $capital += $pendiente->centavos;
            $mora    += Mora::calcular($pendiente, $d)->centavos;
            $dias     = max($dias, $d);

            $venc = (string) $f['fecha_vencimiento'];

            if ($desde === null || $venc < $desde) {
                $desde = $venc;
            }
        }

        return [
            'cuotas'  => count($filas),
            'capital' => $capital,
            'mora'    => $mora,
            'dias'    => $dias,
            'desde'   => (string) $desde,
        ];
    }

    /**
     * Enlace para cobrar un arreglo ya autorizado.
     *
     * @return array<string,mixed>
     */
    public function paraArreglo(int $arregloId): array
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('cobro.enlace');

        $a = BaseDatos::unaFila(
            'SELECT * FROM arreglos_pago WHERE id = ?',
            [$arregloId]
        );

        if ($a === null) {
            throw new \RuntimeException('Arreglo no encontrado.');
        }

        if ($a['estado'] !== 'autorizado') {
            throw new \RuntimeException(
                'El arreglo tiene que estar autorizado antes de mandar el enlace.'
            );
        }

        $credito = $this->credito((int) $a['credito_id']);

        return $this->crear(
            (int) $a['usuario_id'],
            (int) $a['credito_id'],
            $arregloId,
            $a['tipo'] === 'finiquito'
                ? 'Finiquito del crédito ' . $credito['folio']
                : 'Arreglo de pago ' . $a['folio'] . ' · crédito ' . $credito['folio'],
            (int) $a['monto_requerido_cent'],
            $credito,
            $actorId,
            [
                'clase'     => 'arreglo',
                'tipo'      => (string) $a['tipo'],
                'folio'     => (string) $a['folio'],
                'exigible'  => (int) $a['exigible_cent'],
                'condonado' => (int) $a['condonado_cent'],
                'cuotas'    => (int) $a['cuotas_cubiertas'],
            ]
        );
    }

    // =================================================================
    // ABRIR Y PAGAR
    // =================================================================

    /**
     * Resuelve un token a su enlace, si sigue sirviendo.
     *
     * Devuelve null en vez de lanzar: desde fuera, un enlace caducado,
     * uno ya pagado y uno inventado tienen que responder igual. Decir
     * «este existe pero ya se pagó» confirma que el token era bueno.
     *
     * @return array<string,mixed>|null
     */
    public function abrir(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{32,64}$/', $token)) {
            return null;
        }

        $e = BaseDatos::unaFila(
            'SELECT e.*, c.folio AS credito_folio,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente
               FROM enlaces_pago e
               LEFT JOIN creditos c ON c.id = e.credito_id
               JOIN usuarios u ON u.id = e.usuario_id
              WHERE e.token_hash = ?',
            [$this->hash($token)]
        );

        if ($e === null || $e['estado'] !== 'vigente') {
            return null;
        }

        if (strtotime((string) $e['vence_el']) < time()) {
            BaseDatos::ejecutar(
                'UPDATE enlaces_pago SET estado = "caducado" WHERE id = ?',
                [(int) $e['id']]
            );
            return null;
        }

        if ($e['abierto_en'] === null) {
            BaseDatos::ejecutar(
                'UPDATE enlaces_pago SET abierto_en = NOW() WHERE id = ?',
                [(int) $e['id']]
            );
        }

        return $e;
    }

    /**
     * Da por pagado un enlace tras confirmarlo la pasarela.
     *
     * @return array<string,mixed>
     */
    public function marcarPagado(int $enlaceId, ?int $pagoPasarelaId = null): array
    {
        return BaseDatos::enTransaccion(function () use ($enlaceId, $pagoPasarelaId) {
            $e = BaseDatos::unaFila(
                'SELECT * FROM enlaces_pago WHERE id = ? FOR UPDATE',
                [$enlaceId]
            );

            if ($e === null) {
                throw new \RuntimeException('Enlace no encontrado.');
            }

            if ($e['estado'] === 'pagado') {
                return ['estado' => 'duplicado'];
            }

            BaseDatos::ejecutar(
                'UPDATE enlaces_pago
                    SET estado = "pagado", pagado_en = NOW(), pago_pasarela_id = ?
                  WHERE id = ?',
                [$pagoPasarelaId, $enlaceId]
            );

            Auditoria::registrar('cobro.enlace_pagado', Auditoria::EXITO, null,
                'enlace_pago', (string) $enlaceId,
                ['importe' => Monto::deCentavos((int) $e['importe_cent'])->aDecimal()]);

            return ['estado' => 'pagado', 'enlace' => $e];
        });
    }

    // =================================================================
    // INTERNO
    // =================================================================

    /**
     * @param array<string,mixed> $credito
     * @return array<string,mixed>
     */
    private function crear(
        int $usuarioId,
        int $creditoId,
        ?int $arregloId,
        string $concepto,
        int $importe,
        array $credito,
        int $actorId,
        array $detalle = []
    ): array {
        if ($importe <= 0) {
            throw new \RuntimeException('No hay nada que cobrar.');
        }

        $correo = trim((string) ($credito['correo'] ?? ''));

        if ($correo === '') {
            throw new \RuntimeException(
                'El cliente no tiene correo registrado. Actualice su expediente '
                . 'antes de mandarle un enlace.'
            );
        }

        /* La vigencia del enlace se mide en horas, no en días.
         *
         * Un enlace de pago es una credencial: cuanto menos viva,
         * menos tiempo hay para que acabe reenviado, en una bandeja
         * compartida o en un historial ajeno. Dos horas basta de sobra
         * para que alguien que acaba de recibir el correo entre y
         * pague.
         *
         * No confundir con el PLAZO LEGAL del requerimiento, que es de
         * días: el plazo para pagar no se acorta porque caduque un
         * enlace. Si caduca, se manda otro. */
        $horas = max(1, Parametros::entero('cobro.enlace_horas', 2));
        $plazoLegal = max(1, Parametros::entero('cobro.plazo_dias', 7));

        /* Cada correo renueva.
         *
         * Los enlaces anteriores del mismo cobro se dan por sustituidos
         * en el momento de mandar uno nuevo. Dejar varios vivos a la
         * vez sobre la misma deuda es pedir un cobro doble: el cliente
         * abre el correo viejo, paga, abre el nuevo y paga otra vez. */
        $sustituidos = BaseDatos::ejecutar(
            'UPDATE enlaces_pago
                SET estado = "caducado"
              WHERE estado = "vigente"
                AND usuario_id = ?
                AND ' . ($arregloId !== null ? 'arreglo_id = ?' : 'credito_id = ? AND arreglo_id IS NULL'),
            [$usuarioId, $arregloId ?? $creditoId]
        );

        $token = $this->nuevoToken();

        BaseDatos::ejecutar(
            'INSERT INTO enlaces_pago
               (uuid, token_hash, usuario_id, credito_id, arreglo_id,
                concepto, importe_cent, estado, vence_el, creado_por, enviado_a, enviado_en)
             VALUES (UUID(),?,?,?,?,?,?, "vigente",
                     DATE_ADD(NOW(), INTERVAL ? HOUR), ?, ?, NOW())',
            [
                $this->hash($token), $usuarioId, $creditoId, $arregloId,
                mb_substr($concepto, 0, 160), $importe, $horas, $actorId, $correo,
            ]
        );

        $enlaceId = BaseDatos::ultimoId();
        $url      = $this->urlDe($token);

        // El plazo legal, que es lo que se le concede para pagar.
        $vence = date('d/m/Y', strtotime('+' . $plazoLegal . ' days'));

        // La vigencia técnica del enlace, que es otra cosa.
        $venceEnlace = date('d/m/Y H:i', strtotime('+' . $horas . ' hours'));

        $this->enviar(
            $credito, $concepto, $importe, $url, $vence, $plazoLegal,
            $detalle + ['enlace_horas' => $horas, 'enlace_vence' => $venceEnlace]
        );

        Auditoria::registrar('cobro.enlace_creado', Auditoria::EXITO, $actorId,
            'enlace_pago', (string) $enlaceId, [
                'credito'     => $credito['folio'],
                'importe'     => Monto::deCentavos($importe)->aDecimal(),
                'enviado_a'   => $correo,
                'vigencia_h'  => $horas,
                'sustituidos' => $sustituidos,
            ]);

        return [
            'id'      => $enlaceId,
            'importe' => $importe,
            'correo'  => $correo,
            'vence'   => $venceEnlace,
            'horas'   => $horas,
            'sustituidos' => $sustituidos,
            // La URL se devuelve para poder dictarla por teléfono si el
            // correo no llega; no se guarda en claro en ninguna parte.
            'url'     => $url,
        ];
    }

    /**
     * El requerimiento de pago.
     *
     * Esto no es un recordatorio amable: es una comunicación del
     * Departamento de Cobranza sobre una deuda vencida, y como tal
     * tiene que sostenerse si el cliente la discute o si el expediente
     * acaba en un juzgado. De ahí lo que lleva:
     *
     *   - QUIÉN requiere y a quién, con el contrato identificado.
     *   - EL DESGLOSE: saldo, recargo y total. Un cobro que no se puede
     *     verificar no se puede discutir, y eso lo vuelve impugnable.
     *   - EL FUNDAMENTO: las cláusulas del contrato que él firmó, por su
     *     número, no «conforme a lo pactado».
     *   - EL PLAZO y lo que pasa al vencer.
     *   - LA SALIDA: el enlace para pagar y la puerta abierta a negociar.
     *
     * El tono es firme y correcto, no amenazante. Un requerimiento que
     * insulta o presiona indebidamente es una práctica abusiva de
     * cobranza, y además no cobra: lo que cobra es que la persona
     * entienda cuánto debe y vea fácil pagarlo.
     *
     * @param array<string,mixed> $credito
     * @param array<string,mixed> $detalle
     */
    private function enviar(
        array $credito,
        string $concepto,
        int $importe,
        string $url,
        string $vence,
        int $diasVigencia,
        array $detalle = []
    ): void {
        $m = $this->componer($credito, $concepto, $importe, $url, $vence, $diasVigencia, $detalle);

        (new Correo())->enviar(
            (string) $credito['correo'],
            $m['asunto'],
            $m['html'],
            $m['texto'],
            $m['nombre'],
            $this->copiasDeSeguimiento(),
            // Firma el área que requiere, no la institución a secas: el
            // cliente tiene que saber con quién está tratando.
            Parametros::obtener('cobranza.remitente', 'CashMan H.A. · Departamento de Cobranza')
        );
    }

    /**
     * Redacta el requerimiento, sin mandarlo.
     *
     * Separado del envío a propósito: un texto que obliga legalmente a
     * la institución tiene que poder leerse y revisarse sin que eso
     * suponga mandarle una comunicación formal de cobro a una persona
     * de verdad.
     *
     * @param array<string,mixed> $credito
     * @param array<string,mixed> $detalle
     * @return array{asunto:string, html:string, texto:string, nombre:string}
     */
    public function componer(
        array $credito,
        string $concepto,
        int $importe,
        string $url,
        string $vence,
        int $diasVigencia,
        array $detalle = []
    ): array {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $folio   = (string) $credito['folio'];
        $nombre  = trim($credito['nombres'] . ' ' . $credito['apellidos']);
        $esArreglo = ($detalle['clase'] ?? '') === 'arreglo';

        $institucion = Parametros::obtener('institucion.razon_social', 'CASHMAN H.A., S.A. DE C.V.');

        // --- Cuerpo ------------------------------------------------------
        if ($esArreglo) {
            $cuerpo =
                '<p>Por este medio, el <strong>Departamento de Cobranza</strong> de '
                . $e($institucion) . ' hace de su conocimiento que ha sido '
                . '<strong>aprobada la propuesta de pago</strong> referida al contrato de '
                . 'crédito <strong>' . $e($folio) . '</strong> suscrito por usted.</p>'

                . '<p>De conformidad con lo convenido, el pago de la cantidad que se detalla '
                . 'adelante '
                . (($detalle['tipo'] ?? '') === 'finiquito'
                    ? 'extinguirá la totalidad de las obligaciones derivadas de dicho '
                      . 'contrato, procediéndose a su cancelación y a la emisión del '
                      . 'finiquito correspondiente.'
                    : 'cubrirá las ' . (int) ($detalle['cuotas'] ?? 0) . ' cuota(s) en mora '
                      . 'y restablecerá la vigencia regular del plan de pagos pactado.')
                . '</p>'

                . '<p><strong>Esta propuesta tiene vigencia hasta el ' . $e($vence) . '.</strong> '
                . 'Transcurrida dicha fecha sin haberse recibido el pago, quedará sin efecto '
                . 'y la obligación se exigirá por su monto íntegro, con los recargos que a '
                . 'esa fecha se hubieren devengado.</p>'

                . $this->notaDelEnlace($detalle, $e);

            $aviso =
                'La condonacion aqui concedida es un acto de mera liberalidad de la '
                . 'institucion, aplicable unicamente si el pago se efectua dentro del plazo '
                . 'indicado, y no constituye renuncia ni novacion de los derechos derivados '
                . 'del contrato. Si ya efectuo el pago, haga caso omiso de esta comunicacion.';

            $asunto = 'Propuesta de pago aprobada · crédito ' . $folio;
        } else {
            $cuotas = (int) ($detalle['cuotas'] ?? 0);
            $dias   = (int) ($detalle['dias'] ?? 0);

            // La posición completa del crédito, que es lo que se declara.
            $moraTotalCuotas = (int) ($detalle['mora_total_cuotas'] ?? $cuotas);
            $moraTotalCent   = (int) ($detalle['mora_total_cent']
                                      ?? ((int) ($detalle['capital'] ?? 0)
                                          + (int) ($detalle['mora'] ?? 0)));

            $cuerpo =
                '<p>Por este medio, el <strong>Departamento de Cobranza</strong> de '
                . $e($institucion) . ' le <strong>REQUIERE FORMALMENTE</strong> el pago de '
                . 'las obligaciones vencidas y no satisfechas derivadas del contrato de '
                . 'crédito <strong>' . $e($folio) . '</strong>, suscrito por usted en calidad '
                . 'de deudor.</p>'

                /* Lo que el crédito DEBE, no lo que este cobro abarca.
                 *
                 * Antes se contaban aquí las cuotas marcadas para
                 * cobrar, de modo que un requerimiento sobre cinco
                 * afirmaba que el crédito registraba cinco en mora
                 * cuando registraba cincuenta y tres. En una
                 * comunicación que obliga a la institución eso es una
                 * declaración falsa, y de las que se vuelven en contra:
                 * el deudor puede sostener que solo se le reclamó por
                 * cinco. */
                . '<p>A la fecha de esta comunicación su crédito registra <strong>'
                . $moraTotalCuotas . ' cuota(s) en mora</strong>, que suman '
                . '<strong>' . $e(Monto::deCentavos($moraTotalCent)->formateado())
                . '</strong> incluyendo recargos'
                . ($dias > 0
                    ? ', con un atraso de <strong>' . number_format($dias)
                      . ' día(s)</strong> contados desde el vencimiento de la más antigua'
                    : '')
                . (!empty($detalle['desde'])
                    ? ', correspondiente al ' . $e(date('d/m/Y', strtotime((string) $detalle['desde'])))
                    : '')
                . '.</p>'

                /* Si el cobro no abarca todo, decirlo.
                 *
                 * Un requerimiento parcial es legítimo, pero callar que
                 * es parcial no: hay que dejar constancia de que el
                 * resto sigue exigible, o se entiende renunciado. */
                . (!empty($detalle['parcial'])
                    ? '<p>El presente requerimiento comprende <strong>' . $cuotas
                      . ' de dichas cuotas</strong>, por la cantidad que se detalla adelante, '
                      . '<strong>sin perjuicio</strong> del cobro del resto del saldo vencido, '
                      . 'que permanece exigible en su totalidad.</p>'
                    : '<p>El presente requerimiento comprende <strong>la totalidad</strong> de '
                      . 'dichas cuotas.</p>')

                . '<p>El recargo que se detalla se devenga en concepto de <strong>cláusula '
                . 'penal</strong>, conforme a la cláusula <strong>SEXTA</strong> del contrato, '
                . 'y se acumula día a día hasta la fecha del pago efectivo. En consecuencia, '
                . 'la cantidad requerida se incrementa mientras persista el incumplimiento.</p>'

                . '<p>Se le concede un plazo de <strong>' . $diasVigencia . ' día(s) '
                . 'calendario</strong>, que vence el <strong>' . $e($vence) . '</strong>, '
                . 'para efectuar el pago o comparecer a suscribir un convenio.</p>'

                . '<p>Le prevenimos que, de persistir el incumplimiento, '
                . $e($institucion) . ' queda facultada conforme a la cláusula '
                . '<strong>SÉPTIMA</strong> del contrato para <strong>dar por caducado el '
                . 'plazo</strong> y exigir el pago inmediato de la totalidad del saldo '
                . 'adeudado, y conforme a la cláusula <strong>DÉCIMA PRIMERA</strong> para '
                . 'remitir el expediente a cobro extrajudicial y, en su caso, a la vía '
                . 'judicial, quedando a su cargo las costas procesales y los honorarios de '
                . 'ley.</p>'

                . '<p>Le reiteramos que <strong>nuestra disposición a llegar a un arreglo se '
                . 'mantiene</strong> mientras el asunto no se judicialice. Si en este momento '
                . 'no le es posible cubrir el total, comuníquese con nosotros: podemos '
                . 'revisar juntos un plan que se ajuste a su situación.</p>'

                . $this->notaDelEnlace($detalle, $e);

            $aviso =
                'La recepcion de un pago parcial no implica renuncia al cobro del saldo '
                . 'insoluto ni a los derechos que el contrato confiere a la institucion. '
                . 'Conforme a la clausula DECIMA, todo pago se imputa primero a recargos '
                . 'por mora, despues a primas de seguro, luego a intereses corrientes y por '
                . 'ultimo a capital. Si ya efectuo el pago, le rogamos hacer caso omiso de '
                . 'esta comunicacion y nos disculpamos por el cruce.';

            $asunto = 'Requerimiento de pago · crédito ' . $folio;
        }

        // --- Desglose ----------------------------------------------------
        $filas = [
            ['etiqueta' => 'Contrato de credito', 'valor' => $folio],
            ['etiqueta' => 'Deudor',              'valor' => $nombre],
            ['etiqueta' => 'Concepto',            'valor' => $concepto],
        ];

        if ($esArreglo) {
            $filas[] = ['etiqueta' => 'Adeudo total',
                        'valor' => Monto::deCentavos((int) ($detalle['exigible'] ?? 0))->formateado()];
            $filas[] = ['etiqueta' => 'Condonacion concedida',
                        'valor' => Monto::deCentavos((int) ($detalle['condonado'] ?? 0))->formateado()];
        } else {
            $filas[] = ['etiqueta' => 'Saldo vencido',
                        'valor' => Monto::deCentavos((int) ($detalle['capital'] ?? 0))->formateado()];
            $filas[] = ['etiqueta' => 'Recargo por mora (clausula penal)',
                        'valor' => Monto::deCentavos((int) ($detalle['mora'] ?? 0))->formateado()];
        }

        $filas[] = ['etiqueta' => 'CANTIDAD REQUERIDA',
                    'valor' => Monto::deCentavos($importe)->formateado()];
        $filas[] = ['etiqueta' => 'Plazo para pagar', 'valor' => 'hasta el ' . $vence];

        $html = PlantillaCorreo::base(
            $esArreglo ? 'Propuesta de pago aprobada' : 'Requerimiento de pago',
            'Señor(a) ' . $nombre,
            $cuerpo,
            $filas,
            /* Los argumentos, en su sitio.
             *
             * La firma es (…, $datos, $destacado, $botonTexto, $botonUrl,
             * $aviso). Antes se pasaba «Pagar ahora» en la posición de
             * `$destacado` y la URL en la de `$botonTexto`, con lo cual
             * el botón nunca llegaba a tener `href`: el correo mostraba
             * un recuadro con el texto «Pagar ahora» y no se podía
             * pulsar, que es justo lo que el cliente necesitaba hacer. */
            null,
            'Pagar ' . Monto::deCentavos($importe)->formateado(),
            $url,
            $aviso
        );

        return [
            'asunto' => $asunto,
            'html'   => $html,
            'texto'  => $this->versionTexto($institucion, $folio, $nombre, $concepto,
                                            $importe, $url, $vence, $esArreglo),
            'nombre' => $nombre,
        ];
    }

    /**
     * La nota sobre el enlace, separada del plazo legal.
     *
     * Son dos relojes distintos y mezclarlos sería un problema: el
     * plazo para pagar se cuenta en días y lo concede el requerimiento;
     * el enlace vive dos horas porque es una credencial y cuanto menos
     * viva, menos tiempo hay para que acabe reenviado o en una bandeja
     * compartida.
     *
     * Si no se dijera, el cliente que entra al día siguiente se
     * encuentra un enlace muerto y concluye que ya no puede pagar, que
     * es exactamente lo contrario de lo que se busca. Por eso se dice
     * cuánto dura y qué hacer cuando caduque.
     *
     * @param array<string,mixed> $detalle
     */
    private function notaDelEnlace(array $detalle, callable $e): string
    {
        $horas = (int) ($detalle['enlace_horas'] ?? 0);

        if ($horas <= 0) {
            return '';
        }

        return '<p style="font-size:13px;color:#667085">'
            . 'Por seguridad, el botón de pago de este correo queda habilitado durante '
            . '<strong>' . $horas . ' hora(s)</strong>'
            . (!empty($detalle['enlace_vence'])
                ? ', hasta las ' . $e((string) $detalle['enlace_vence'])
                : '')
            . '. Si al entrar le indica que ya no está disponible, solicítenos otro y se '
            . 'lo enviamos en el momento: <strong>esto no acorta el plazo que se le concede '
            . 'para pagar</strong>, que es el indicado arriba.</p>';
    }

    /**
     * La versión en texto plano.
     *
     * No es un descarte: hay clientes que leen el correo en un terminal
     * o con el HTML desactivado, y un requerimiento que les llega vacío
     * no les fue notificado. Lleva lo mismo que el HTML, sin adornos.
     */
    private function versionTexto(
        string $institucion,
        string $folio,
        string $nombre,
        string $concepto,
        int $importe,
        string $url,
        string $vence,
        bool $esArreglo
    ): string {
        $m = Monto::deCentavos($importe)->formateado();

        if ($esArreglo) {
            return "PROPUESTA DE PAGO APROBADA\n\n"
                . "Senor(a) {$nombre}:\n\n"
                . "El Departamento de Cobranza de {$institucion} le comunica que ha sido "
                . "aprobada la propuesta de pago referida al contrato de credito {$folio}.\n\n"
                . "Concepto: {$concepto}\n"
                . "Cantidad a pagar: {$m}\n"
                . "Vigencia de la propuesta: hasta el {$vence}\n\n"
                . "Puede pagar en linea desde:\n{$url}\n\n"
                . "Transcurrida la fecha indicada sin recibirse el pago, la propuesta "
                . "quedara sin efecto y la obligacion se exigira por su monto integro.\n\n"
                . "Si ya efectuo el pago, haga caso omiso de esta comunicacion.\n";
        }

        return "REQUERIMIENTO DE PAGO\n\n"
            . "Senor(a) {$nombre}:\n\n"
            . "El Departamento de Cobranza de {$institucion} le requiere formalmente el "
            . "pago de las obligaciones vencidas del contrato de credito {$folio}.\n\n"
            . "Concepto: {$concepto}\n"
            . "Cantidad requerida: {$m}\n"
            . "Plazo para pagar: hasta el {$vence}\n\n"
            . "Puede pagar en linea desde:\n{$url}\n\n"
            . "El recargo por mora se devenga en concepto de clausula penal conforme a la "
            . "clausula SEXTA del contrato y se acumula dia a dia hasta el pago efectivo.\n\n"
            . "De persistir el incumplimiento, la institucion queda facultada conforme a la "
            . "clausula SEPTIMA para dar por caducado el plazo y exigir la totalidad del "
            . "saldo, y conforme a la clausula DECIMA PRIMERA para remitir el expediente a "
            . "cobro extrajudicial y, en su caso, a la via judicial, quedando a su cargo las "
            . "costas y honorarios de ley.\n\n"
            . "Nuestra disposicion a llegar a un arreglo se mantiene. Comuniquese con "
            . "nosotros si necesita revisar un plan de pago.\n\n"
            . "Si ya efectuo el pago, le rogamos hacer caso omiso de esta comunicacion.\n";
    }

    /** @return array<string,mixed> */
    private function credito(int $creditoId): array
    {
        $c = BaseDatos::unaFila(
            'SELECT c.id, c.folio, c.usuario_id,
                    u.correo, u.nombres, u.apellidos
               FROM creditos c JOIN usuarios u ON u.id = c.usuario_id
              WHERE c.id = ?',
            [$creditoId]
        );

        if ($c === null) {
            throw new \RuntimeException('Crédito no encontrado.');
        }

        return $c;
    }

    /**
     * Un token con entropía de sobra y seguro en una URL.
     *
     * 32 bytes en base64url: ni se adivina ni se rompe al copiarlo de un
     * correo, que es lo que pasa con los caracteres `+` y `/`.
     */
    private function nuevoToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function urlDe(string $token): string
    {
        $base = rtrim(Config::obtener('APP_URL', 'http://localhost:90/cashmanha-v2'), '/');

        return $base . '/pagar-enlace/' . $token;
    }

    /** @return list<string> */
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
}
