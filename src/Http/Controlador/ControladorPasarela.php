<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioPasarela;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\Mora;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Pasarela\PasarelaError;
use CashMan\Infraestructura\Pasarela\Stripe;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Pagos con tarjeta externa.
 *
 * El cliente paga su cuota o el saldo de su tarjeta con una tarjeta de
 * otro banco, sin necesidad de tener fondos aquí.
 */
final class ControladorPasarela extends ControladorBase
{
    /**
     * Cuántas cuotas se pueden adelantar de una sola vez.
     *
     * Vive aquí y no en la vista porque es el servidor quien lo aplica.
     * La pantalla lee este mismo número para armar su desplegable: si
     * estuviera escrito en los dos sitios, acabarían discrepando y el
     * cliente vería ofrecido algo que luego se le recorta.
     */
    public const MAXIMO_ADELANTO = 15;

    public function formulario(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        Autorizacion::exigir('pago.pasarela');

        /* La siguiente cuota de cada crédito, no todas.
         *
         * Un crédito se paga de la más antigua hacia adelante, y el
         * servicio lo exige. Ofrecer aquí las veinticuatro pendientes
         * invita a elegir la número 12 para recibir un «las cuotas se
         * pagan en orden»: la pantalla tiene que ofrecer lo que de
         * verdad se puede pagar.
         *
         * La subconsulta devuelve, por crédito, la de número más bajo
         * entre las pendientes: esa es la que toca. */
        $cuotas = BaseDatos::todas(
            'SELECT q.id, q.numero, q.fecha_vencimiento, q.total_cent, q.pagado_cent,
                    q.capital_cent, q.interes_cent, q.seguro_vida_cent, q.seguro_empleo_cent,
                    q.mora_cent, q.estado,
                    cr.id AS credito_id, cr.folio, cr.plazo_meses,
                    p.nombre AS producto,
                    DATEDIFF(CURDATE(), q.fecha_vencimiento) AS dias_vencida
               FROM cuotas   q
               JOIN creditos cr ON cr.id = q.credito_id
               JOIN productos p ON p.id = cr.producto_id
              WHERE cr.usuario_id = ?
                AND q.estado NOT IN ("pagada","condonada")
                AND q.numero = (
                      SELECT MIN(s.numero) FROM cuotas s
                       WHERE s.credito_id = q.credito_id
                         AND s.estado NOT IN ("pagada","condonada")
                    )
              ORDER BY q.fecha_vencimiento
              LIMIT 24',
            [$usuarioId]
        );

        foreach ($cuotas as &$q) {
            $q['pendiente_cent'] = max(0, (int) $q['total_cent'] - (int) $q['pagado_cent']);

            /* La mora VIVA, la misma que calcula el cobro: sobre lo que
               queda pendiente y los dias de atraso de HOY. `cuotas.mora_cent`
               no sirve aqui —es el recargo ya cobrado, cero mientras la
               cuota sigue impaga—, asi que lo exigible se calcula, no se
               lee. Es lo que de verdad se cobrara (ver ::iniciar), para que
               la pantalla no prometa una cantidad y el cargo sea otra. */
            $q['mora_viva_cent'] = (int) $q['dias_vencida'] > 0
                ? Mora::calcular(Monto::deCentavos((int) $q['pendiente_cent']), (int) $q['dias_vencida'])->centavos
                : 0;
            $q['exigible_cent'] = (int) $q['pendiente_cent'] + (int) $q['mora_viva_cent'];
            $q['seguros_cent']  = (int) $q['seguro_vida_cent'] + (int) $q['seguro_empleo_cent'];

            $q['codigo']  = Hashid::codificar((int) $q['id'], 'cuota');
            $q['vencida'] = (int) $q['dias_vencida'] > 0;
            unset($q['id'], $q['credito_id']);
        }
        unset($q);

        /* El adelanto: qué se eligió exactamente.
         *
         * Llega el crédito y CUÁNTAS cuotas, no la lista. Aquí se
         * resuelve contra lo que de verdad está pendiente hoy, en
         * orden, y se arma el desglose que el cliente tiene que ver
         * antes de poner su tarjeta: antes la pantalla enseñaba una
         * sola cuota aunque hubiera seleccionado doce. */
        $adelanto = $this->adelantoElegido($usuarioId);

        $tarjetas = BaseDatos::todas(
            'SELECT id, ultimos4, marca, tipo, saldo_usado_cent, limite_cent
               FROM tarjetas
              WHERE usuario_id = ? AND tipo = "credito" AND estado = "activa"
                AND saldo_usado_cent > 0',
            [$usuarioId]
        );

        foreach ($tarjetas as &$t) {
            $t['codigo'] = Hashid::codificar((int) $t['id'], 'tarjeta');
            unset($t['id']);
        }
        unset($t);

        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, p.nombre AS producto
               FROM cuentas c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.es_tecnica = 0 AND c.estado = "activa"',
            [$usuarioId]
        );

        foreach ($cuentas as &$c) {
            $c['codigo'] = Hashid::codificar((int) $c['id'], 'cuenta');
            unset($c['id']);
        }
        unset($c);

        /* Todo su historial, no los últimos quince.
         *
         * La pantalla filtra por mes y año y pagina de diez en diez,
         * y para eso necesita tener delante lo que va a filtrar: con
         * quince filas, elegir «marzo» enseñaba una lista vacía aunque
         * marzo tuviera pagos. Son unas pocas decenas de filas por
         * cliente, así que viajan todas y el filtrado es instantáneo;
         * el tope está por si alguna vez deja de ser verdad. */
        $historial = BaseDatos::todas(
            'SELECT uuid, concepto, importe_cent, estado, referencia,
                    creado_en, confirmado_en, detalle_error
               FROM pagos_pasarela
              WHERE usuario_id = ?
              ORDER BY id DESC LIMIT 400',
            [$usuarioId]
        );

        $this->render('pagos/pasarela', [
            'titulo'      => 'Pagar con tarjeta · CashMan H.A.',
            'encabezado'  => 'Pagar con tarjeta',
            'activa'      => Stripe::habilitado(),
            'esPrueba'    => Stripe::esPrueba(),
            'publica'     => Stripe::clavePublica(),
            'cuotas'      => $cuotas,
            'tarjetas'    => $tarjetas,
            'cuentas'     => $cuentas,
            'historial'   => $historial,
            /* Lo que viene preseleccionado desde otra pantalla.
             *
             * Si el cliente llegó aquí pulsando «Pagar con tarjeta» en
             * una cuota concreta, esa cuota tiene que venir ya elegida:
             * obligarle a buscarla otra vez en un desplegable es
             * pedirle que repita lo que acaba de decir. */
            'adelanto'    => $adelanto,
            'preConcepto' => in_array($this->get('concepto'), ['cuota_credito', 'saldo_tarjeta'], true)
                ? $this->get('concepto') : '',
            'preCuota'    => $this->get('cuota'),
            'preTarjeta'  => $this->get('tarjeta'),
            'csrf'        => Csrf::token('pasarela'),
        ]);
    }

    /** Prepara el cobro y devuelve lo que el navegador necesita. */
    /**
     * Las cuotas que el cliente eligió adelantar.
     *
     * Llega el crédito y cuántas, no la lista de identificadores. Se
     * resuelven aquí contra lo que está pendiente HOY, por número
     * ascendente: es el mismo orden en que se van a cobrar, así que lo
     * que se enseña es exactamente lo que va a pasar.
     *
     * Devuelve null cuando no hay adelanto —el caso normal, una sola
     * cuota— para que la pantalla siga como estaba.
     *
     * @return array<string,mixed>|null
     */
    private function adelantoElegido(int $usuarioId): ?array
    {
        /* De los dos sitios: la pantalla llega por GET y el cobro se
           envía por POST. Leer solo uno dejaba el adelanto invisible
           justo al confirmarlo, que es cuando importa. */
        $cuantas = (int) ($_POST['cuantas'] ?? $_GET['cuantas'] ?? 0);
        $codigo  = trim((string) ($_POST['credito'] ?? $_GET['credito'] ?? ''));

        if ($cuantas < 2 || $codigo === '') {
            return null;
        }

        $creditoId = $this->idDesde($codigo, 'credito');

        if ($creditoId === 0) {
            return null;
        }

        $cr = BaseDatos::unaFila(
            'SELECT cr.id, cr.folio, p.nombre AS producto
               FROM creditos cr JOIN productos p ON p.id = cr.producto_id
              WHERE cr.id = ? AND cr.usuario_id = ? AND cr.estado = "vigente"',
            [$creditoId, $usuarioId]
        );

        if ($cr === null) {
            return null;
        }

        /* El tope de verdad.
         *
         * Adelantar es adelantar, no refinanciar el crédito entero por
         * la pasarela. Y este es el límite que MANDA: el desplegable de
         * la pantalla es una comodidad, y bastaba con escribir
         * `cuantas=24` en la URL para saltárselo. Un tope que solo vive
         * en el formulario no es un tope. */
        $cuantas = min($cuantas, self::MAXIMO_ADELANTO);

        $cuotas = BaseDatos::todas(
            'SELECT id, numero, fecha_vencimiento, total_cent, pagado_cent, mora_cent, estado,
                    DATEDIFF(CURDATE(), fecha_vencimiento) AS dias_vencida
               FROM cuotas
              WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")
              ORDER BY numero ASC
              LIMIT ' . $cuantas,
            [$creditoId]
        );

        if ($cuotas === []) {
            return null;
        }

        $total = 0;
        $lista = [];

        foreach ($cuotas as $q) {
            $pendiente = max(0, (int) $q['total_cent'] - (int) $q['pagado_cent']);

            /* Mora viva, no la almacenada: `cuotas.mora_cent` es el recargo
               YA cobrado y vale cero mientras la cuota sigue impaga. Se
               calcula con la misma clase que aplica el cobro para que el
               importe preparado case exactamente con lo que se cobrara. */
            $mora = (int) $q['dias_vencida'] > 0
                ? Mora::calcular(Monto::deCentavos($pendiente), (int) $q['dias_vencida'])->centavos
                : 0;

            $total += $pendiente + $mora;

            $lista[] = [
                'numero'         => (int) $q['numero'],
                'vence'          => (string) $q['fecha_vencimiento'],
                'pendiente_cent' => $pendiente,
                'mora_cent'      => $mora,
                'total_cent'     => $pendiente + $mora,
                'vencida'        => (int) $q['dias_vencida'] > 0,
            ];
        }

        return [
            'credito_codigo' => Hashid::codificar($creditoId, 'credito'),
            'folio'          => (string) $cr['folio'],
            'producto'       => (string) $cr['producto'],
            'cuantas'        => count($lista),
            'desde'          => $lista[0]['numero'],
            'hasta'          => $lista[count($lista) - 1]['numero'],
            'total_cent'     => $total,
            'cuotas'         => $lista,
        ];
    }
    public function iniciar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        Autorizacion::exigir('pago.pasarela');

        $concepto = $this->post('concepto');
        $destino  = [];

        $importe = 0;

        // El importe NO lo decide el navegador: se calcula aquí a partir
        // de lo que de verdad se debe. Si viniera del formulario,
        // bastaría con editarlo para pagar un dólar por una cuota de mil.
        switch ($concepto) {
            case 'cuota_credito':
                /* Un adelanto de varias: el importe es la suma de las
                   que se eligieron, y se señala la MÁS ANTIGUA. El
                   servicio cobra desde ahí hacia adelante hasta donde
                   alcance, cerrando las que pueda y dejando parcial la
                   última. No se manda la lista: entre que el cliente
                   elige y la pasarela confirma pueden pasar minutos, y
                   una lista de identificadores no sobrevive a que entre
                   un abono en medio. El importe y el orden, sí. */
                $adelanto = $this->adelantoElegido($usuarioId);

                if ($adelanto !== null) {
                    $primera = BaseDatos::unaFila(
                        'SELECT q.id, q.credito_id
                           FROM cuotas q JOIN creditos cr ON cr.id = q.credito_id
                          WHERE cr.usuario_id = ?
                            AND cr.folio = ?
                            AND q.estado NOT IN ("pagada","condonada")
                          ORDER BY q.numero ASC LIMIT 1',
                        [$usuarioId, $adelanto['folio']]
                    );

                    if ($primera === null) {
                        Respuesta::error('Ese crédito ya no tiene cuotas pendientes.', 404);
                    }

                    $importe = (int) $adelanto['total_cent'];
                    $destino = [
                        'credito_id' => (int) $primera['credito_id'],
                        'cuota_id'   => (int) $primera['id'],
                    ];
                    break;
                }

                $cuotaId = $this->idDesde($this->post('cuota'), 'cuota');

                $q = BaseDatos::unaFila(
                    'SELECT q.id, q.total_cent, q.pagado_cent, q.credito_id, cr.usuario_id,
                            CASE WHEN q.estado NOT IN ("pagada","condonada")
                                      AND q.fecha_vencimiento < CURDATE()
                                 THEN DATEDIFF(CURDATE(), q.fecha_vencimiento) ELSE 0 END AS dias_vencida
                       FROM cuotas q JOIN creditos cr ON cr.id = q.credito_id
                      WHERE q.id = ? AND q.estado NOT IN ("pagada","condonada")',
                    [$cuotaId]
                );

                if ($q === null || (int) $q['usuario_id'] !== $usuarioId) {
                    Respuesta::error('Cuota no encontrada entre sus cuotas pendientes.', 404);
                }

                $pendiente = max(0, (int) $q['total_cent'] - (int) $q['pagado_cent']);

                /* La mora VIVA entra en el cobro. Antes se cobraba solo
                   `total - pagado` (el capital), y una cuota vencida pagada
                   por tarjeta quedaba 'parcial' con el recargo sin saldar:
                   el cliente creia haberla pagado y seguia debiendo la mora.
                   Se calcula igual que la aplicacion del cobro (Mora::calcular
                   sobre lo pendiente y los dias de atraso). */
                $mora = (int) $q['dias_vencida'] > 0
                    ? Mora::calcular(Monto::deCentavos($pendiente), (int) $q['dias_vencida'])->centavos
                    : 0;

                $importe = $pendiente + $mora;
                $destino = ['credito_id' => (int) $q['credito_id'], 'cuota_id' => (int) $q['id']];
                break;

            case 'saldo_tarjeta':
                $tarjetaId = $this->idDesde($this->post('tarjeta'), 'tarjeta');

                $t = BaseDatos::unaFila(
                    'SELECT id, usuario_id, saldo_usado_cent FROM tarjetas
                      WHERE id = ? AND tipo = "credito"',
                    [$tarjetaId]
                );

                if ($t === null || (int) $t['usuario_id'] !== $usuarioId) {
                    Respuesta::error('Tarjeta no encontrada entre las suyas.', 404);
                }

                $deuda = (int) $t['saldo_usado_cent'];

                /* Pago parcial.
                   Antes se cobraba siempre el saldo completo, y eso dejaba
                   fuera el caso más común: pagar el mínimo. Ahora se admite
                   un monto, pero acotado por arriba a la deuda real — que
                   es el dato que manda y sale de la base, no del
                   formulario. Si no viniera monto, se cobra todo. */
                $pedido = trim((string) $this->post('importe'));

                if ($pedido !== '') {
                    $monto = (float) str_replace([',', '$', ' '], '', $pedido);

                    if ($monto <= 0) {
                        Respuesta::error('El monto a pagar debe ser mayor que cero.', 422);
                    }

                    $importe = min((int) round($monto * 100), $deuda);
                } else {
                    $importe = $deuda;
                }

                $destino = ['tarjeta_id' => (int) $t['id']];
                break;

            case 'deposito':
                $cuentaId = $this->idDesde($this->post('cuenta'), 'cuenta');

                $c = BaseDatos::unaFila(
                    'SELECT id, usuario_id FROM cuentas
                      WHERE id = ? AND es_tecnica = 0 AND estado = "activa"',
                    [$cuentaId]
                );

                if ($c === null || (int) $c['usuario_id'] !== $usuarioId) {
                    Respuesta::error('Cuenta no encontrada entre las suyas.', 404);
                }

                // Este es el único caso donde el importe lo pone el
                // usuario, porque es él quien decide cuánto deposita.
                $monto = (float) str_replace([',', '$', ' '], '', $this->post('importe', '0'));

                if ($monto < 1 || $monto > 10000) {
                    Respuesta::error('El depósito debe estar entre $1.00 y $10,000.00.', 422);
                }

                $importe = (int) round($monto * 100);
                $destino = ['cuenta_id' => (int) $c['id']];
                break;

            default:
                Respuesta::error('Concepto de pago no válido.', 422);
        }

        if ($importe <= 0) {
            Respuesta::error('No hay nada pendiente que pagar en ese concepto.', 422);
        }

        try {
            $r = (new ServicioPasarela())->iniciar($usuarioId, $concepto, $importe, $destino);
        } catch (PasarelaError $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        // Lo que el navegador necesita, con el nombre que usa la vista.
        // La clave secreta de la cuenta no sale de aquí: `client_secret`
        // solo sirve para confirmar este cobro concreto.
        Respuesta::exito([
            'secreto_cliente'    => $r['secreto'],
            'referencia'         => $r['referencia'],
            'importe_cent'       => $r['importe'],
            'importe_formateado' => '$' . number_format($r['importe'] / 100, 2),
        ], 'Cobro preparado. Complete los datos de su tarjeta.');
    }

    /**
     * Webhook de Stripe.
     *
     * Esta ruta la llama Stripe, no el navegador, así que no lleva
     * sesión ni token CSRF: su autenticación es la firma del propio
     * aviso, que se comprueba antes de mirar su contenido.
     *
     * Responde 200 en cuanto el evento queda registrado. Si fallara con
     * un 5xx, Stripe lo reintentaría durante días.
     */
    /**
     * Aplica un cobro que la pasarela ya confirmó.
     *
     * El webhook es la vía normal, pero llega cuando llega —y en una
     * instalación sin acceso desde fuera, nunca—. Sin este paso el
     * dinero salía de la tarjeta del cliente y el saldo no se movía.
     *
     * No se cree lo que diga el navegador: se le pregunta a la pasarela
     * si ese intento está cobrado. El navegador solo dice CUÁL mirar.
     */
    public function confirmar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $intentoId = $this->post('intento');

        if (!preg_match('/^pi_[A-Za-z0-9_]+$/', $intentoId)) {
            Respuesta::error('Referencia de cobro no válida.', 422);
            return;
        }

        // El cobro tiene que ser de quien lo reclama.
        $pago = BaseDatos::unaFila(
            'SELECT id, estado, usuario_id FROM pagos_pasarela WHERE referencia = ?',
            [$intentoId]
        );

        if ($pago === null || (int) $pago['usuario_id'] !== $usuarioId) {
            Respuesta::error('Ese cobro no le corresponde.', 422);
            return;
        }

        if ($pago['estado'] === 'aplicado') {
            Respuesta::exito(['ya' => true], 'Este pago ya estaba aplicado.');
            return;
        }

        try {
            $estado = (new Stripe())->consultarIntento($intentoId);
        } catch (\Throwable $e) {
            Respuesta::error('No se pudo verificar el cobro con la pasarela.', 502);
            return;
        }

        if (($estado['status'] ?? '') !== 'succeeded') {
            Respuesta::error(
                'La pasarela todavía no confirma el cobro. Le avisaremos en cuanto lo haga.',
                409
            );
            return;
        }

        /* Aquí el cobro YA se hizo: el dinero entró en la pasarela.
           Si la aplicación fallara, no se le puede pedir al cliente que
           reintente —pagaría dos veces—: se le confirma lo recibido, se
           deja constancia y la conciliación lo termina. */
        try {
            /* Devuelve el detalle como TEXTO, no como arreglo. Leerlo
               con `$r['detalle']` dejaba el aviso en «Pago aplicado. »,
               con el punto y el hueco donde iba lo que de verdad pasó. */
            $detalle = (new ServicioPasarela())->aplicarCobroConfirmado($intentoId);
        } catch (\Throwable $e) {
            error_log('[Pasarela] cobro ' . $intentoId . ' sin aplicar: ' . $e->getMessage());

            Auditoria::registrar('pasarela.cobro_sin_aplicar', Auditoria::ALERTA,
                $usuarioId, 'pago_pasarela', $intentoId, ['error' => $e->getMessage()]);

            Respuesta::exito(
                ['pendiente' => true],
                'Su pago se recibió correctamente. La aplicación quedó pendiente de '
                . 'revisión interna y se reflejará en breve.'
            );
            return;
        }

        Respuesta::exito(
            ['referencia' => $intentoId, 'detalle' => $detalle],
            trim($detalle) !== '' ? $detalle : 'Su pago quedó aplicado.'
        );
    }
    public function webhook(array $parametros = []): void
    {
        $cuerpo = (string) file_get_contents('php://input');
        $firma  = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

        if ($cuerpo === '' || $firma === '') {
            Auditoria::registrar('pasarela.webhook_invalido', Auditoria::DENEGADO, null,
                'pasarela', '', ['motivo' => 'sin cuerpo o sin firma', 'ip' => ClienteIp::obtener()]);

            Respuesta::json(['ok' => false, 'mensaje' => 'Petición no válida.'], 400);
        }

        try {
            $evento = (new Stripe())->verificarWebhook($cuerpo, $firma);
        } catch (PasarelaError $e) {
            // Una firma que no cuadra es un intento de inyectar un cobro
            // falso o un secreto mal configurado. En ambos casos se
            // rechaza y queda constancia.
            Auditoria::registrar('pasarela.firma_rechazada', Auditoria::DENEGADO, null,
                'pasarela', '', ['motivo' => $e->getMessage(), 'ip' => ClienteIp::obtener()]);

            Respuesta::json(['ok' => false, 'mensaje' => 'Firma no válida.'], 400);
        }

        try {
            $resultado = (new ServicioPasarela())->procesarEvento($evento, $cuerpo);
        } catch (\Throwable $e) {
            // Se devuelve 500 a propósito: así Stripe reintenta en vez
            // de dar el evento por entregado y perderlo.
            Auditoria::registrar('pasarela.webhook_error', Auditoria::FALLO, null,
                'pasarela', (string) $evento['id'], ['error' => $e->getMessage()]);

            Respuesta::json(['ok' => false, 'mensaje' => 'Error al procesar.'], 500);
            return;
        }

        Respuesta::json(['ok' => true, 'resultado' => $resultado, 'silencio' => true]);
    }

    /** Conciliación de la cuenta técnica. */
    public function conciliacion(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('pago.conciliar');

        $resumen = BaseDatos::unaFila('SELECT * FROM v_pasarela_conciliacion') ?? [];

        $pagos = BaseDatos::todas(
            'SELECT p.*, CONCAT(u.nombres," ",u.apellidos) AS cliente
               FROM pagos_pasarela p
               JOIN usuarios u ON u.id = p.usuario_id
              ORDER BY p.id DESC LIMIT 200'
        );

        $eventos = BaseDatos::todas(
            'SELECT evento_id, tipo, resultado, recibido_en, procesado_en
               FROM pasarela_eventos ORDER BY id DESC LIMIT 50'
        );

        $this->render('pagos/conciliacion', [
            'titulo'     => 'Conciliación de pasarela · CashMan H.A.',
            'encabezado' => 'Conciliación de la pasarela',
            'resumen'    => $resumen,
            'pagos'      => $pagos,
            'eventos'    => $eventos,
            'activa'     => Stripe::habilitado(),
            'esPrueba'   => Stripe::esPrueba(),
            'puedeConciliar' => Autorizacion::puede('pago.conciliar'),
            'csrf'       => Csrf::token('pasarela'),
        ]);
    }

    /**
     * Cierra contra la pasarela los cobros que quedaron en el aire.
     *
     * Es la red de seguridad de los otros dos caminos. El aviso de
     * Stripe no llega a una instalación sin dirección pública, y el
     * navegador del cliente se cierra a mitad: lo que queda entonces es
     * un cobro hecho y una deuda intacta. Aquí se le pregunta a la
     * pasarela por cada uno y se cierra según lo que ella conteste.
     */
    public function sincronizar(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('pago.conciliar');

        try {
            $r = (new ServicioPasarela())->reconciliar(0);
        } catch (\Throwable $e) {
            $this->responderSegunCliente(
                false, $e->getMessage(), $this->url('pagos/conciliacion'), null, [], 502
            );
            return;
        }

        $mensaje = $r['revisados'] === 0
            ? 'No había ningún cobro pendiente de cerrar.'
            : 'Se consultaron ' . $r['revisados'] . ' cobro(s): '
              . $r['aplicados'] . ' aplicado(s), '
              . $r['fallidos'] . ' rechazado(s) por la pasarela y '
              . $r['pendientes'] . ' todavía en curso.';

        $this->responderSegunCliente(
            true, $mensaje, $this->url('pagos/conciliacion'), $r
        );
    }
}
