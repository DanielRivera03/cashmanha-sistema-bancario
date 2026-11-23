<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Credito\Amortizacion;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Documento\ServicioDocumentos;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Pasarela\PasarelaError;
use CashMan\Infraestructura\Pasarela\Stripe;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\ClienteIp;

/**
 * Cobros con tarjeta externa a través de la pasarela.
 *
 * Permite que un cliente pague su cuota o el saldo de su tarjeta con
 * una tarjeta de otro banco, sin tener que tener fondos aquí.
 *
 * Lo importante de este servicio no es hablar con Stripe —eso lo hace
 * la clase Stripe— sino decidir qué significa contablemente un cobro
 * externo:
 *
 *   El dinero entra del exterior, así que la contrapartida no puede ser
 *   otra cuenta del banco. Se abona a donde corresponda (la cuenta del
 *   cliente, el crédito, la tarjeta) y se carga contra la cuenta
 *   técnica de la pasarela, que queda con saldo negativo hasta que
 *   Stripe liquide de verdad. Ese saldo es exactamente lo que el banco
 *   está esperando cobrar, y por eso se puede conciliar.
 *
 * El asiento se registra cuando llega la confirmación del webhook, no
 * cuando el cliente pulsa pagar: hasta ese momento no hay dinero.
 */
final class ServicioPasarela
{
    /** Cuenta técnica donde se acumula lo cobrado y no liquidado. */
    private const CUENTA_TECNICA = '0000000001';

    private const CONCEPTOS = [
        'cuota_credito' => 'Pago de cuota de crédito',
        'saldo_tarjeta' => 'Pago de saldo de tarjeta',
        'deposito'      => 'Depósito a cuenta',
        'tienda'        => 'Compra en la tienda',
    ];

    /**
     * Prepara un cobro.
     *
     * Devuelve lo que el navegador necesita para mostrar el formulario
     * de tarjeta de Stripe. El importe queda apuntado aquí antes de
     * hablar con la pasarela: si la respuesta se pierde, el intento
     * consta igual y el webhook lo reconoce.
     *
     * @return array{uuid:string, referencia:string, secreto:string, publica:string, importe:int}
     */
    public function iniciar(
        ?int $usuarioId,
        string $concepto,
        int $importeCent,
        array $destino
    ): array {
        if (!Stripe::habilitado()) {
            throw new PasarelaError(
                'La pasarela de pago no está activa. Revise PASARELA y las claves en .env.'
            );
        }

        if (!isset(self::CONCEPTOS[$concepto])) {
            throw new PasarelaError('Concepto de pago no válido.');
        }

        // El importe pasa por Monto para que las mismas reglas del resto
        // del sistema apliquen aquí: nada de negativos ni de ceros.
        $monto = Monto::deCentavos($importeCent);

        if ($importeCent <= 0) {
            throw new PasarelaError('El importe a pagar debe ser mayor que cero.');
        }

        $uuid = $this->uuid();

        // La clave de idempotencia la fija este sistema, no Stripe: si
        // el cliente da doble clic, el segundo intento devuelve el mismo
        // cobro en lugar de crear otro.
        $idem = 'cm-' . $uuid;

        $descripcion = self::CONCEPTOS[$concepto];

        BaseDatos::ejecutar(
            'INSERT INTO pagos_pasarela
               (uuid, usuario_id, concepto, credito_id, cuota_id, tarjeta_id,
                cuenta_destino_id, pedido_id, importe_cent, pasarela, estado, ip)
             VALUES (?,?,?,?,?,?,?,?,?,?,"iniciado",?)',
            [
                $uuid, $usuarioId, $concepto,
                $destino['credito_id'] ?? null,
                $destino['cuota_id'] ?? null,
                $destino['tarjeta_id'] ?? null,
                $destino['cuenta_id'] ?? null,
                $destino['pedido_id'] ?? null,
                $importeCent, 'stripe',
                ClienteIp::binaria(),
            ]
        );
        $pagoId = BaseDatos::ultimoId();

        try {
            $intento = (new Stripe())->crearIntento(
                $importeCent,
                $descripcion . ' · ' . $monto->formateado(),
                $idem,
                ['pago_uuid' => $uuid, 'concepto' => $concepto, 'usuario' => (string) $usuarioId]
            );
        } catch (PasarelaError $e) {
            BaseDatos::ejecutar(
                'UPDATE pagos_pasarela SET estado = "fallido", detalle_error = ? WHERE id = ?',
                [mb_substr($e->getMessage(), 0, 500), $pagoId]
            );

            Auditoria::registrar('pasarela.intento_fallido', Auditoria::FALLO, $usuarioId,
                'pago', (string) $pagoId, ['motivo' => $e->getMessage()]);

            throw $e;
        }

        BaseDatos::ejecutar(
            'UPDATE pagos_pasarela
                SET referencia = ?, secreto_cliente = ?, estado = "procesando"
              WHERE id = ?',
            [$intento['id'], $intento['client_secret'], $pagoId]
        );

        Auditoria::registrar('pasarela.intento_creado', Auditoria::EXITO, $usuarioId,
            'pago', (string) $pagoId,
            ['concepto' => $concepto, 'importe' => $importeCent, 'referencia' => $intento['id']]);

        return [
            'uuid'       => $uuid,
            'referencia' => $intento['id'],
            'secreto'    => $intento['client_secret'],
            'publica'    => Stripe::clavePublica(),
            'importe'    => $importeCent,
        ];
    }

    /**
     * Procesa un evento del webhook.
     *
     * Stripe reenvía hasta recibir un 2xx y puede entregar el mismo
     * evento más de una vez. Por eso lo primero es registrarlo por su
     * identificador: si ya estaba, se responde que sí y no se vuelve a
     * contabilizar.
     *
     * @return string Lo que se hizo, para la respuesta y la bitácora
     */
    public function procesarEvento(array $evento, string $cuerpo): string
    {
        $eventoId = (string) $evento['id'];
        $tipo     = (string) $evento['type'];

        $yaVisto = BaseDatos::unaFila(
            'SELECT id, procesado_en, resultado FROM pasarela_eventos WHERE evento_id = ?',
            [$eventoId]
        );

        if ($yaVisto !== null) {
            return 'Evento ya procesado antes: ' . ($yaVisto['resultado'] ?? 'sin detalle');
        }

        BaseDatos::ejecutar(
            'INSERT INTO pasarela_eventos (evento_id, tipo, cuerpo) VALUES (?,?,?)',
            [$eventoId, $tipo, $cuerpo]
        );
        $registroId = BaseDatos::ultimoId();

        $objeto     = $evento['data']['object'] ?? [];
        $referencia = (string) ($objeto['id'] ?? '');

        $resultado = match ($tipo) {
            'payment_intent.succeeded'       => $this->confirmar($referencia),
            'payment_intent.payment_failed'  => $this->marcarFallido($referencia, $objeto),
            'payment_intent.canceled'        => $this->marcarCancelado($referencia),
            'charge.refunded'                => $this->marcarDevuelto($objeto),
            default                          => 'Evento ignorado: ' . $tipo,
        };

        $pagoId = $referencia !== ''
            ? BaseDatos::unValor('SELECT id FROM pagos_pasarela WHERE referencia = ?', [$referencia])
            : null;

        BaseDatos::ejecutar(
            'UPDATE pasarela_eventos SET procesado_en = NOW(), resultado = ?, pago_id = ? WHERE id = ?',
            [mb_substr($resultado, 0, 200), $pagoId, $registroId]
        );

        return $resultado;
    }

    /**
     * Cobro confirmado: aquí es donde se mueve el dinero.
     */
    private function confirmar(string $referencia): string
    {
        $pago = BaseDatos::unaFila(
            'SELECT * FROM pagos_pasarela WHERE referencia = ?', [$referencia]
        );

        if ($pago === null) {
            // Un cobro que esta base no conoce. Puede ser de otro
            // entorno apuntando al mismo webhook: no se inventa nada.
            Auditoria::registrar('pasarela.cobro_desconocido', Auditoria::FALLO, null,
                'pago', '', ['referencia' => $referencia]);

            return 'Cobro sin intento registrado en este sistema: ' . $referencia;
        }

        /* Los dos estados finales de un cobro que salió bien.
         *
         * El camino general lo deja en «cobrado»; las ramas que
         * extinguen deuda —tarjeta y cuota— en «aplicado», porque ahí
         * no se abona nada a nadie. Este guardia miraba solo el
         * primero, y ahora que hay tres caminos y tres disparadores
         * —el aviso de Stripe, el navegador y el barrido— el mismo
         * cobro podía entrar dos veces y dar por pagadas las cuotas
         * otra vez. */
        if (in_array((string) $pago['estado'], ['cobrado', 'aplicado'], true)) {
            return 'El cobro ya estaba contabilizado.';
        }

        $pagoId   = (int) $pago['id'];
        $usuario  = (int) $pago['usuario_id'];
        $importe  = (int) $pago['importe_cent'];
        $concepto = (string) $pago['concepto'];

        $tecnica = (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE numero_cuenta = ?', [self::CUENTA_TECNICA]
        );

        if ($tecnica === 0) {
            throw new PasarelaError(
                'No existe la cuenta técnica de la pasarela: el cobro no se puede asentar.'
            );
        }

        /* Una compra de la tienda no se abona a ninguna cuenta de cliente.
         *
         * El resto de conceptos acreditan la cuenta del titular, y para
         * una compra eso sería justo al revés de la verdad: el dinero
         * salió de su tarjeta y entró a la institución, que ahora le debe
         * al comercio afiliado. La partida doble va entre dos cuentas
         * técnicas y ninguna cuenta de cliente se toca.
         *
         * Se resuelve antes del camino general porque ese camino busca
         * «la cuenta del usuario», y un invitado no tiene ninguna. */
        if ($concepto === 'tienda') {
            return $this->asentarCompraTienda($pago, $referencia, $tecnica);
        }

        /* Un cobro por enlace tampoco acredita la cuenta del cliente.
         *
         * El dinero sale de su tarjeta y entra a la institución para
         * saldar lo que debe. Abonárselo a su cuenta Y ADEMÁS dar por
         * pagadas las cuotas sería contarlo dos veces: lo que procede
         * es aplicarlo al arreglo o a las cuotas que el enlace cubría. */
        if ($concepto === 'enlace_cobro') {
            return $this->aplicarEnlace($pago, $referencia);
        }

        /* Pagar la tarjeta tampoco acredita la cuenta del cliente.
         *
         * El camino general abona la cuenta de ahorro, que para un
         * deposito esta bien, pero aqui el cliente esta pagando el saldo
         * de su tarjeta: el dinero le aparecia en la cuenta y la deuda
         * seguia intacta. Pagaba, y no pagaba nada. */
        if ($concepto === 'saldo_tarjeta') {
            return $this->aplicarPagoTarjeta($pago, $referencia, $tecnica);
        }

        /* Pagar una cuota tampoco acredita la cuenta del cliente.
         *
         * El cliente eligio «cuota de credito» y paso una tarjeta: el
         * dinero sale de esa tarjeta y entra a la institucion para
         * extinguir la cuota. Por el camino general le aparecia en su
         * cuenta de ahorro y la cuota seguia debiendose, igual que
         * pasaba con el saldo de tarjeta antes de darle su rama. */
        if ($concepto === 'cuota_credito') {
            return $this->aplicarCuotaCredito($pago, $referencia, $tecnica);
        }

        $destino = $this->cuentaDestino($pago);

        if ($destino === 0) {
            BaseDatos::ejecutar(
                'UPDATE pagos_pasarela SET estado = "fallido",
                        detalle_error = "No se pudo determinar la cuenta destino"
                  WHERE id = ?',
                [$pagoId]
            );

            return 'Cobrado en la pasarela pero sin cuenta destino: requiere revisión manual.';
        }

        $asientoId = 0;

        BaseDatos::enTransaccion(function () use (
            $pagoId, $usuario, $importe, $concepto, $tecnica, $destino, $referencia, &$asientoId
        ): void {
            /* La partida doble de un cobro externo:
               - se abona la cuenta destino (entra dinero al cliente)
               - se carga la técnica de la pasarela, que queda en
                 negativo hasta que Stripe liquide.
               La suma da cero, que es lo que el libro mayor exige. */
            $r = LibroMayor::registrar(
                'deposito',
                [
                    // El libro mayor espera `importe` como objeto Monto, no
                    // `importe_cent` como entero. Pasarle el entero reventaba
                    // el webhook con "Undefined array key importe", y Stripe
                    // lo reintentaba durante días sin que el cobro llegara a
                    // contabilizarse jamás.
                    ['cuenta_id' => $destino, 'importe' => Monto::deCentavos($importe),
                     'descripcion' => self::CONCEPTOS[$concepto] . ' (pasarela)'],
                    ['cuenta_id' => $tecnica, 'importe' => Monto::deCentavos(-$importe),
                     'descripcion' => 'Cobro pendiente de liquidación · ' . $referencia],
                ],
                $usuario,
                'pasarela-' . $referencia,
                self::CONCEPTOS[$concepto] . ' con tarjeta externa'
            );

            $asientoId = (int) ($r['asiento_id'] ?? 0);

            BaseDatos::ejecutar(
                'UPDATE pagos_pasarela
                    SET estado = "cobrado", confirmado_en = NOW(), asiento_id = ?
                  WHERE id = ?',
                [$asientoId ?: null, $pagoId]
            );
        });

        Auditoria::registrar('pasarela.cobro_confirmado', Auditoria::EXITO, $usuario,
            'pago', (string) $pagoId,
            ['referencia' => $referencia, 'importe' => $importe, 'asiento' => $asientoId]);

        (new ServicioNotificacion())->crear(
            $usuario,
            'pago.pasarela',
            'Pago recibido',
            'Se acreditó ' . Monto::deCentavos($importe)->formateado()
            . ' por ' . mb_strtolower(self::CONCEPTOS[$concepto]) . '.',
            null,
            'exito'
        );

        return 'Cobro contabilizado, asiento ' . $asientoId;
    }

    /**
     * Aplica el cobro de un enlace de pago.
     *
     * El enlace puede cubrir dos cosas, y cada una se cierra distinto:
     *
     *   UN ARREGLO     lo aplica `ServicioArreglo`, que da por cubiertas
     *                  las cuotas pactadas, registra la condonación en
     *                  su cuenta y —si era finiquito— cancela el
     *                  crédito. Es el camino que estaba roto: el dinero
     *                  entraba y el crédito seguía vigente.
     *
     *   CUOTAS SUELTAS se pagan una por una contra la cuenta técnica de
     *                  la pasarela, que es donde está el dinero.
     *
     * @param array<string,mixed> $pago
     */
    private function aplicarEnlace(array $pago, string $referencia): string
    {
        $enlaceId = (int) ($pago['enlace_id'] ?? 0);

        $enlace = BaseDatos::unaFila(
            'SELECT * FROM enlaces_pago WHERE id = ?', [$enlaceId]
        );

        if ($enlace === null) {
            return 'Cobro de un enlace que ya no existe: ' . $referencia;
        }

        if ($enlace['estado'] === 'pagado') {
            return 'El enlace ya estaba dado por pagado.';
        }

        $servicioEnlace = new ServicioEnlacePago();
        $arregloId      = $enlace['arreglo_id'] !== null ? (int) $enlace['arreglo_id'] : 0;

        // La clave ata la aplicación al cobro: si el webhook y el cierre
        // del navegador llegan los dos, el segundo no vuelve a cobrar.
        $clave = 'enlace:' . $enlaceId . ':' . $referencia;

        $detalle = '';

        if ($arregloId > 0) {
            $r = (new ServicioArreglo())->aplicarPago($arregloId, null, $clave);

            $detalle = 'arreglo ' . ($r['folio'] ?? $arregloId) . ' ' . ($r['estado'] ?? '');

            if (!empty($r['cancelado'])) {
                $detalle .= ', crédito cancelado';
            }
        } else {
            $detalle = $this->pagarCuotasDelEnlace($enlaceId, (int) $pago['credito_id'], $clave);
        }

        $servicioEnlace->marcarPagado($enlaceId, (int) $pago['id']);

        BaseDatos::ejecutar(
            'UPDATE pagos_pasarela
                SET estado = "cobrado", confirmado_en = NOW()
              WHERE id = ?',
            [(int) $pago['id']]
        );

        // El comprobante, que la pantalla le promete al cliente.
        $this->comprobanteDeEnlace($enlace, $pago, $detalle);

        Auditoria::registrar('pasarela.enlace_aplicado', Auditoria::EXITO,
            (int) $pago['usuario_id'], 'enlace_pago', (string) $enlaceId,
            ['referencia' => $referencia, 'detalle' => $detalle]);

        return 'Cobro por enlace aplicado: ' . $detalle;
    }

    /**
     * Paga con el dinero de la pasarela las cuotas vencidas del crédito.
     *
     * Para un enlace sin arreglo detrás. Va de la más antigua a la más
     * nueva, que es el orden en que se calculó el importe.
     */
    private function pagarCuotasDelEnlace(int $enlaceId, int $creditoId, string $clave): string
    {
        $cuotas = BaseDatos::todas(
            'SELECT id FROM cuotas
              WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")
                AND fecha_vencimiento < CURDATE()
              ORDER BY numero',
            [$creditoId]
        );

        if ($cuotas === []) {
            return 'no quedaban cuotas vencidas que aplicar';
        }

        $ids = array_map(static fn(array $c): int => (int) $c['id'], $cuotas);

        $cuentaPasarela = (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE numero_cuenta = ?', [self::CUENTA_TECNICA]
        );

        $r = (new ServicioCredito())->pagarVarias($ids, $cuentaPasarela, $clave);

        return ($r['cuotas'] ?? 0) . ' cuota(s) pagadas'
            . (!empty($r['cancelado']) ? ', crédito cancelado' : '');
    }

    /**
     * El comprobante del pago hecho por enlace.
     *
     * La pantalla le dice al cliente «le enviamos el comprobante a su
     * correo», así que hay que enviarlo: una promesa en pantalla que el
     * sistema no cumple es peor que no haberla hecho, porque el cliente
     * se queda esperando y vuelve a llamar.
     *
     * Va con el detalle dentro del propio correo y no como adjunto: el
     * PDF vive detrás de la banca en línea, y quien paga por un enlace
     * puede no tener sesión ni acordarse de su contraseña. Lo que
     * necesita en ese momento es poder guardar lo que pagó.
     *
     * @param array<string,mixed> $enlace
     * @param array<string,mixed> $pago
     */
    private function comprobanteDeEnlace(array $enlace, array $pago, string $detalle): void
    {
        try {
            $u = BaseDatos::unaFila(
                'SELECT correo, nombres, apellidos FROM usuarios WHERE id = ?',
                [(int) $enlace['usuario_id']]
            );

            if ($u === null || trim((string) $u['correo']) === '') {
                return;
            }

            $nombre  = trim($u['nombres'] . ' ' . $u['apellidos']);
            $importe = Monto::deCentavos((int) $pago['importe_cent'])->formateado();

            $institucion = Parametros::obtener(
                'institucion.razon_social', 'CASHMAN H.A., S.A. DE C.V.'
            );

            $credito = $enlace['credito_id'] !== null
                ? (string) BaseDatos::unValor(
                    'SELECT folio FROM creditos WHERE id = ?', [(int) $enlace['credito_id']]
                )
                : '';

            // Lo que queda debiendo después de este pago.
            $restante = $credito !== ''
                ? (int) BaseDatos::unValor(
                    'SELECT COALESCE(SUM(q.total_cent - q.pagado_cent), 0)
                       FROM cuotas q
                      WHERE q.credito_id = ? AND q.estado NOT IN ("pagada","condonada")
                        AND q.fecha_vencimiento < CURDATE()',
                    [(int) $enlace['credito_id']]
                )
                : 0;

            $cancelado = str_contains($detalle, 'cancelado');

            /* El finiquito, generado y adjunto.
             *
             * Si el pago canceló el crédito, el cliente necesita el
             * documento que acredita que no debe nada, y lo necesita
             * ahora: decirle «se lo haremos llegar» y que tenga que
             * pedirlo después es dejarle sin lo único que le queda por
             * recibir de esta operación.
             *
             * Se genera aquí y viaja adjunto al comprobante. Si fallara,
             * el correo sale igual: el comprobante importa más que el
             * adjunto, y el finiquito se puede emitir después desde la
             * pantalla del crédito. */
            $adjuntos = [];

            if ($cancelado && $enlace['credito_id'] !== null) {
                try {
                    $fin = (new ServicioDocumentos())
                        ->finiquitoCredito((int) $enlace['credito_id']);

                    if (is_file((string) $fin['ruta'])) {
                        $adjuntos[] = [
                            'ruta'   => (string) $fin['ruta'],
                            'nombre' => 'finiquito-' . $credito . '.pdf',
                            'tipo'   => 'application/pdf',
                        ];
                    }
                } catch (\Throwable $ex) {
                    error_log('[Pasarela] finiquito no generado: ' . $ex->getMessage());
                }
            }

            $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $cuerpo =
                '<p>' . $e($institucion) . ' acusa recibo del pago efectuado por usted '
                . 'y le extiende el presente comprobante.</p>'

                . ($cancelado
                    ? '<p>Con este pago <strong>queda cancelado en su totalidad</strong> el '
                      . 'contrato de crédito ' . $e($credito) . '. Esta institución no '
                      . 'conserva reclamo alguno derivado de dicho contrato, y le hará '
                      . 'llegar el <strong>finiquito</strong> correspondiente.</p>'
                    : ($restante > 0
                        ? '<p>Su crédito ' . $e($credito) . ' mantiene un saldo vencido de '
                          . '<strong>' . $e(Monto::deCentavos($restante)->formateado())
                          . '</strong>, que continúa devengando recargo por mora conforme '
                          . 'a la cláusula SEXTA del contrato.</p>'
                        : '<p>Con este pago su crédito ' . $e($credito) . ' queda '
                          . '<strong>al día</strong>. No hay cuotas vencidas pendientes.</p>'))

                . '<p style="font-size:13px;color:#667085">Conserve este comprobante. '
                . 'También puede consultarlo y descargarlo en cualquier momento desde su '
                . 'banca en línea.</p>';

            $html = PlantillaCorreo::base(
                'Comprobante de pago',
                'Señor(a) ' . $nombre,
                $cuerpo,
                [
                    ['etiqueta' => 'Fecha',    'valor' => date('d/m/Y H:i')],
                    ['etiqueta' => 'Concepto', 'valor' => (string) $enlace['concepto']],
                    ['etiqueta' => 'Credito',  'valor' => $credito !== '' ? $credito : '—'],
                    ['etiqueta' => 'Medio de pago', 'valor' => 'Tarjeta (pasarela)'],
                    ['etiqueta' => 'Referencia',    'valor' => (string) $pago['referencia']],
                    ['etiqueta' => 'IMPORTE PAGADO', 'valor' => $importe],
                    ['etiqueta' => 'Aplicado a',     'valor' => $detalle],
                ],
                null,
                null,
                null,
                'Este comprobante acredita el pago recibido. Si detecta cualquier '
                . 'diferencia, comuniquese con nosotros indicando la referencia.'
            );

            (new Correo())->enviar(
                (string) $u['correo'],
                'Comprobante de pago · ' . ($credito !== '' ? $credito : 'CashMan H.A.'),
                $html,
                "COMPROBANTE DE PAGO\n\n"
                . "Senor(a) {$nombre}:\n\n"
                . "{$institucion} acusa recibo del pago efectuado por usted.\n\n"
                . 'Fecha: ' . date('d/m/Y H:i') . "\n"
                . 'Credito: ' . ($credito !== '' ? $credito : '-') . "\n"
                . 'Importe pagado: ' . $importe . "\n"
                . 'Referencia: ' . $pago['referencia'] . "\n"
                . 'Aplicado a: ' . $detalle . "\n\n"
                . ($cancelado
                    ? "Con este pago queda CANCELADO en su totalidad el contrato de "
                      . "credito. Le haremos llegar el finiquito correspondiente.\n"
                    : ($restante > 0
                        ? 'Saldo vencido que queda: '
                          . Monto::deCentavos($restante)->formateado() . "\n"
                        : "Su credito queda al dia.\n"))
                . "\nConserve este comprobante.\n",
                $nombre,
                $this->copiasDeSeguimiento(),
                Parametros::obtener('cobranza.remitente',
                                    'CashMan H.A. · Departamento de Cobranza'),
                $adjuntos
            );
        } catch (\Throwable $ex) {
            // El comprobante no puede tumbar un cobro ya aplicado.
            error_log('[Pasarela] comprobante de enlace fallido: ' . $ex->getMessage());
        }
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

    /**
     * Aplica un cobro ya confirmado por la pasarela.
     *
     * Lo llama la página de pago cuando el navegador ve que el cobro
     * salió bien. Existe porque el webhook no siempre llega —en una
     * instalación local Stripe no alcanza el servidor— y porque, cuando
     * llega, puede tardar minutos.
     *
     * Quien la llama ya verificó contra Stripe que el intento está
     * cobrado; aquí solo se aplica. La clave de idempotencia impide que
     * el webhook lo vuelva a aplicar después.
     */
    /**
     * Quien pidio el barrido, si lo pidio alguien.
     *
     * En linea lo dispara una persona con permiso y queda a su
     * nombre. Por tarea programada no hay nadie detras, y ademas
     * tocar la sesion desde CLI revienta: `ini_set` de sesion
     * despues de haber escrito en pantalla es error fatal.
     */
    private function quienConcilia(): ?int
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }

        try {
            return \CashMan\Infraestructura\Seguridad\Sesion::usuarioId() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function aplicarCobroConfirmado(string $referencia): string
    {
        return $this->confirmar($referencia);
    }

    /**
     * Cierra los cobros que quedaron en el aire.
     *
     * Un cobro queda «procesando» desde que la pasarela acepta la
     * tarjeta hasta que algo de este lado lo aplica. Ese algo era el
     * webhook o el navegador, y los dos fallan: el webhook no llega a
     * una instalación sin dirección pública, y el navegador se cierra,
     * se queda sin red o el cliente cambia de pestaña.
     *
     * Aquí se pregunta a la pasarela, que es la única que sabe la
     * verdad, y se cierra cada uno según lo que conteste. No se aplica
     * nada por lo que diga esta base ni por lo que pidiera un
     * navegador: solo por lo que afirme Stripe del intento concreto.
     *
     * @param int $minutos Gracia antes de tocar un cobro. El camino
     *                     normal tiene unos segundos para cerrarlo
     *                     solo; esto es el barrido de lo que no se
     *                     cerró, no una carrera contra él.
     * @return array{revisados:int, aplicados:int, fallidos:int,
     *               pendientes:int, detalle:list<array<string,string>>}
     */
    public function reconciliar(int $minutos = 2, int $limite = 200): array
    {
        if (!Stripe::habilitado()) {
            throw new PasarelaError('La pasarela no está configurada en este entorno.');
        }

        $pendientes = BaseDatos::todas(
            'SELECT id, referencia, concepto, importe_cent, estado, creado_en
               FROM pagos_pasarela
              WHERE estado IN ("iniciado","procesando")
                AND referencia LIKE "pi_%"
                AND creado_en < DATE_SUB(NOW(), INTERVAL ? MINUTE)
              ORDER BY id ASC
              LIMIT ' . max(1, min(500, $limite)),
            [max(0, $minutos)]
        );

        $r = ['revisados' => 0, 'aplicados' => 0, 'fallidos' => 0,
              'pendientes' => 0, 'detalle' => []];

        $stripe = new Stripe();

        foreach ($pendientes as $p) {
            $referencia = (string) $p['referencia'];
            $r['revisados']++;

            try {
                $intento = $stripe->consultarIntento($referencia);
            } catch (\Throwable $e) {
                /* No se pudo preguntar: se deja como está. Dar por
                   bueno un cobro que no se pudo verificar sería abonar
                   dinero que quizá nadie pagó. */
                $r['pendientes']++;
                $r['detalle'][] = [
                    'referencia' => $referencia,
                    'resultado'  => 'sin verificar',
                    'nota'       => $e->getMessage(),
                ];
                continue;
            }

            $estado = (string) ($intento['status'] ?? '');

            try {
                if ($estado === 'succeeded') {
                    $nota = $this->confirmar($referencia);
                    $r['aplicados']++;
                    $r['detalle'][] = [
                        'referencia' => $referencia,
                        'resultado'  => 'aplicado',
                        'nota'       => $nota,
                    ];
                    continue;
                }

                if (in_array($estado, ['canceled', 'requires_payment_method'], true)) {
                    /* `requires_payment_method` después de un intento
                       es un rechazo: la tarjeta no pasó y Stripe
                       devuelve el intento a pedir otra. */
                    $nota = $estado === 'canceled'
                        ? $this->marcarCancelado($referencia)
                        : $this->marcarFallido($referencia, $intento);

                    $r['fallidos']++;
                    $r['detalle'][] = [
                        'referencia' => $referencia,
                        'resultado'  => $estado === 'canceled' ? 'cancelado' : 'fallido',
                        'nota'       => $nota,
                    ];
                    continue;
                }

                // Sigue en curso de verdad: 3-D Secure a medias, por
                // ejemplo. Se mira en el siguiente barrido.
                $r['pendientes']++;
                $r['detalle'][] = [
                    'referencia' => $referencia,
                    'resultado'  => 'en curso',
                    'nota'       => 'La pasarela lo reporta como ' . $estado . '.',
                ];
            } catch (\Throwable $e) {
                /* El cobro SÍ se hizo y no se pudo aplicar. No se
                   reintenta a ciegas ni se marca fallido —sería negar
                   un cobro real—: queda anotado para que alguien lo
                   mire, y el siguiente barrido vuelve a intentarlo. */
                error_log('[Pasarela] ' . $referencia . ' sin aplicar: ' . $e->getMessage());

                Auditoria::registrar('pasarela.cobro_sin_aplicar', Auditoria::ALERTA,
                    null, 'pago_pasarela', $referencia, ['error' => $e->getMessage()]);

                $r['pendientes']++;
                $r['detalle'][] = [
                    'referencia' => $referencia,
                    'resultado'  => 'cobrado sin aplicar',
                    'nota'       => $e->getMessage(),
                ];
            }
        }

        Auditoria::registrar('pasarela.conciliacion', Auditoria::EXITO,
            $this->quienConcilia(), 'pasarela', '', [
                'revisados'  => $r['revisados'],
                'aplicados'  => $r['aplicados'],
                'fallidos'   => $r['fallidos'],
                'pendientes' => $r['pendientes'],
            ]);

        return $r;
    }

    /**
     * Aplica a las cuotas un pago cobrado con tarjeta.
     *
     * El dinero vino de fuera, así que ninguna cuenta de cliente recibe
     * nada: el asiento va entre la técnica de la pasarela —que queda
     * en negativo hasta que Stripe liquide— y la general.
     *
     * Se empieza por la cuota que el cobro indicaba y se sigue por las
     * demás en orden mientras quede importe. Pagar de más es algo que
     * pasa —el cliente abonó por otra vía entre que se preparó el cobro
     * y se confirmó—, y dejar el sobrante colgando sería peor que
     * aplicarlo a lo siguiente que debe.
     *
     * @param array<string,mixed> $pago
     */
    private function aplicarCuotaCredito(array $pago, string $referencia, int $tecnicaPasarela): string
    {
        $pagoId    = (int) $pago['id'];
        $importe   = (int) $pago['importe_cent'];
        $usuario   = (int) $pago['usuario_id'];
        $creditoId = (int) ($pago['credito_id'] ?? 0);
        $cuotaId   = (int) ($pago['cuota_id'] ?? 0);

        if ($creditoId === 0 && $cuotaId === 0) {
            BaseDatos::ejecutar(
                'UPDATE pagos_pasarela SET estado = "fallido",
                        detalle_error = "El cobro no indica a que credito se aplica"
                  WHERE id = ?',
                [$pagoId]
            );

            return 'Cobrado en la pasarela pero sin crédito destino: requiere revisión manual.';
        }

        if ($creditoId === 0) {
            $creditoId = (int) BaseDatos::unValor(
                'SELECT credito_id FROM cuotas WHERE id = ?', [$cuotaId]
            );
        }

        $general = (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE es_tecnica = 1 AND id <> ? ORDER BY id LIMIT 1',
            [$tecnicaPasarela]
        );

        if ($general === 0) {
            $general = $tecnicaPasarela;
        }

        $detalle   = '';
        $cancelado = false;
        /* El asiento, para que el aviso pueda adjuntar su comprobante.
           Sin esto el correo salía pelado en los cobros más grandes. */
        $asientoId = 0;
        /* Y el folio, para nombrar el crédito en el correo. Dentro de
           la transacción se conoce; fuera, no. */
        $folioCredito = '';

        BaseDatos::enTransaccion(function () use (
            $pagoId, $creditoId, $cuotaId, $importe, $usuario, $referencia,
            $tecnicaPasarela, $general, &$detalle, &$cancelado, &$asientoId,
            &$folioCredito
        ): void {
            $credito = BaseDatos::unaFila(
                'SELECT id, folio, usuario_id FROM creditos WHERE id = ? FOR UPDATE',
                [$creditoId]
            );

            if ($credito === null) {
                throw new \RuntimeException('El crédito del cobro ya no existe.');
            }

            $folioCredito = (string) $credito['folio'];

            /* Primero la cuota que el cobro señalaba, luego el resto en
               orden. `FIELD()` la pone la primera sin repetirla. */
            $cuotas = BaseDatos::todas(
                'SELECT * FROM cuotas
                  WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")
                  ORDER BY FIELD(id, ?) DESC, numero ASC
                  FOR UPDATE',
                [$creditoId, $cuotaId ?: 0]
            );

            if ($cuotas === []) {
                throw new \RuntimeException(
                    'El crédito ' . $credito['folio'] . ' no tiene cuotas pendientes.'
                );
            }

            $queda    = $importe;
            $pagadas  = 0;
            $capital  = 0;
            $moraDia  = 0;
            $cerradas = [];
            $hoy      = new \DateTimeImmutable();

            foreach ($cuotas as $q) {
                if ($queda <= 0) {
                    break;
                }

                $vence = new \DateTimeImmutable((string) $q['fecha_vencimiento']);
                $dias  = $vence < $hoy ? (int) $vence->diff($hoy)->days : 0;

                $pendiente = Monto::deCentavos((int) $q['total_cent'] - (int) $q['pagado_cent']);
                $mora      = Amortizacion::calcularMora($pendiente, $dias);
                $debe      = $pendiente->centavos + $mora->centavos;

                if ($queda < $debe) {
                    /* No alcanza para la cuota entera. Se abona lo que
                       hay y la cuota queda parcial: decirle al cliente
                       que la pagó cuando no la pagó es lo único que no
                       se puede hacer aquí. */
                    BaseDatos::ejecutar(
                        'UPDATE cuotas SET pagado_cent = pagado_cent + ?, estado = "parcial",
                                dias_mora = ?
                          WHERE id = ?',
                        [$queda, $dias, (int) $q['id']]
                    );

                    $moraDia += $dias > 0 ? 1 : 0;
                    $queda    = 0;
                    break;
                }

                /* El asiento todavía no existe —se pone más abajo, con
                   el total—, así que las cuotas se enlazan al final.
                   Aquí solo se apuntan cuáles fueron. */
                $cerradas[] = (int) $q['id'];

                BaseDatos::ejecutar(
                    'UPDATE cuotas
                        SET pagado_cent = total_cent, mora_cent = ?, estado = "pagada",
                            pagada_en = NOW(), pagada_tarde = ?, dias_mora = ?
                      WHERE id = ?',
                    [$mora->centavos, $dias > 0 ? 1 : 0, $dias, (int) $q['id']]
                );

                $queda  -= $debe;
                $capital += (int) $q['capital_cent'];
                $pagadas++;
            }

            BaseDatos::ejecutar(
                'UPDATE creditos c SET
                    saldo_capital_cent = GREATEST(0, saldo_capital_cent - ?),
                    total_pagado_cent  = total_pagado_cent + ?,
                    cuotas_pagadas     = (SELECT COUNT(*) FROM cuotas WHERE credito_id=c.id AND estado="pagada"),
                    cuotas_tardias     = (SELECT COUNT(*) FROM cuotas WHERE credito_id=c.id AND pagada_tarde=1),
                    proxima_cuota      = (SELECT MIN(fecha_vencimiento) FROM cuotas
                                           WHERE credito_id=c.id AND estado NOT IN ("pagada","condonada"))
                  WHERE c.id = ?',
                [$capital, $importe - $queda, $creditoId]
            );

            $restantes = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM cuotas
                  WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")',
                [$creditoId]
            );

            if ($restantes === 0) {
                BaseDatos::ejecutar(
                    'UPDATE creditos SET estado = "cancelado", saldo_capital_cent = 0 WHERE id = ?',
                    [$creditoId]
                );
                $cancelado = true;
            }

            LibroMayor::bloquearCuentas(array_unique([$tecnicaPasarela, $general]));

            $r = LibroMayor::registrar(
                'pago_cuota',
                [
                    ['cuenta_id' => $tecnicaPasarela, 'importe' => Monto::deCentavos(-$importe),
                     'descripcion' => 'Cobro pendiente de liquidación · ' . $referencia],
                    ['cuenta_id' => $general, 'importe' => Monto::deCentavos($importe),
                     'descripcion' => 'Pago de cuotas de ' . $credito['folio']
                                    . ' con tarjeta externa'],
                ],
                $usuario,
                /* Clave propia. La del deposito —«pasarela-ref»— puede
                   existir ya de cuando este mismo cobro se aplico mal,
                   y el libro mayor la daria por repetida: el asiento no
                   se pondria y las cuotas quedarian pagadas sin respaldo. */
                'pasarela-cuota-' . $referencia,
                'Pago de cuota de crédito con tarjeta externa'
            );

            /* Si el libro mayor ya conocía esta clave, el asiento NO se
               puso: alguien aplicó este mismo cobro antes. Las cuotas
               acaban de marcarse pagadas unas líneas arriba, así que
               continuar las dejaría cobradas sin respaldo contable.
               Reventar aquí deshace la transacción entera. */
            if (($r['duplicado'] ?? false) === true) {
                throw new \RuntimeException(
                    'Este cobro ya se había aplicado a ' . $credito['folio'] . '.'
                );
            }

            /* Cada cuota, con su comprobante.
               Sin esto el cliente ve «Pagada» en su tabla de
               amortización y no tiene de dónde sacar la constancia:
               este asiento no toca ninguna cuenta suya —el dinero vino
               de una tarjeta de otro banco—, así que no aparece
               tampoco en sus movimientos. */
            if ($cerradas !== [] && (int) ($r['asiento_id'] ?? 0) > 0) {
                BaseDatos::ejecutar(
                    'UPDATE cuotas SET asiento_id = ? WHERE id IN ('
                    . implode(',', array_fill(0, count($cerradas), '?')) . ')',
                    array_merge([(int) $r['asiento_id']], $cerradas)
                );
            }

            $asientoId = (int) ($r['asiento_id'] ?? 0);

            BaseDatos::ejecutar(
                'UPDATE pagos_pasarela
                    SET estado = "aplicado", confirmado_en = NOW(), asiento_id = ?
                  WHERE id = ?',
                [(int) ($r['asiento_id'] ?? 0) ?: null, $pagoId]
            );

            $detalle = $pagadas > 0
                ? 'Se pagaron ' . $pagadas . ' cuota(s) de ' . $credito['folio']
                  . ' por ' . Monto::deCentavos($importe - $queda)->formateado() . '.'
                : 'Se abonaron ' . Monto::deCentavos($importe)->formateado()
                  . ' a la cuota en curso de ' . $credito['folio']
                  . ', que todavía no queda cubierta.';

            if ($queda > 0) {
                $detalle .= ' Quedaron ' . Monto::deCentavos($queda)->formateado()
                    . ' a favor: ya no había más cuotas que cubrir.';
            }

            if ($cancelado) {
                $detalle .= ' Con esto el crédito queda cancelado.';
            }
        });

        /* El expediente de cobro, fuera de la transacción: consulta
           mucho y no debe alargar el bloqueo de las cuotas. */
        try {
            (new \CashMan\Aplicacion\Servicio\ServicioCobranza())->refrescarCaso($creditoId);
        } catch (\Throwable $e) {
            error_log('[Pasarela] expediente de ' . $creditoId . ': ' . $e->getMessage());
        }

        Auditoria::registrar('pasarela.pago_cuota', Auditoria::EXITO, $usuario,
            'credito', (string) $creditoId,
            ['referencia' => $referencia, 'importe' => $importe, 'cancelado' => $cancelado]);

        (new ServicioNotificacion())->crear(
            $usuario,
            'cuota.pagada',
            $cancelado ? 'Crédito cancelado' : 'Pago aplicado a su crédito',
            $detalle,
            [
                /* El ASIENTO, no solo la referencia de la pasarela: es
                   lo que permite adjuntar el comprobante. Buscar por
                   `referencia` no servía aquí, porque la que llega es
                   la de Stripe y el asiento tiene la suya. */
                'asiento_id' => $asientoId,
                'credito'    => $folioCredito,
                'referencia' => $referencia,
                'importe'    => Monto::deCentavos($importe)->formateado(),
            ],
            'exito'
        );

        return $detalle;
    }

    /**
     * Aplica a la tarjeta un pago cobrado con otra tarjeta.
     *
     * Baja la deuda, deja su movimiento —para que entre en el estado de
     * cuenta y cuente para el mínimo del mes— y asienta entre dos
     * cuentas técnicas: ninguna cuenta de cliente recibe nada, porque
     * el dinero vino de fuera y se fue a extinguir deuda.
     *
     * @param array<string,mixed> $pago
     */
    private function aplicarPagoTarjeta(array $pago, string $referencia, int $tecnicaPasarela): string
    {
        $pagoId    = (int) $pago['id'];
        $importe   = (int) $pago['importe_cent'];
        $tarjetaId = (int) ($pago['tarjeta_id'] ?? 0);
        $usuario   = (int) $pago['usuario_id'];

        if ($tarjetaId === 0) {
            BaseDatos::ejecutar(
                'UPDATE pagos_pasarela SET estado = "fallido",
                        detalle_error = "El cobro no indica a que tarjeta se aplica"
                  WHERE id = ?',
                [$pagoId]
            );

            return 'Cobrado en la pasarela pero sin tarjeta destino: requiere revisión manual.';
        }

        $general = (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE es_tecnica = 1 AND id <> ? ORDER BY id LIMIT 1',
            [$tecnicaPasarela]
        );

        if ($general === 0) {
            $general = $tecnicaPasarela;
        }

        $detalle = '';
        /* El asiento y los últimos cuatro salen de la transacción: el
           aviso los necesita para adjuntar el comprobante y para decir
           de qué tarjeta habla. */
        $asientoId = 0;
        $ultimos4  = '';

        BaseDatos::enTransaccion(function () use (
            $pagoId, $tarjetaId, $importe, $usuario, $referencia,
            $tecnicaPasarela, $general, &$detalle, &$asientoId, &$ultimos4
        ): void {
            $t = BaseDatos::unaFila(
                'SELECT id, ultimos4, saldo_usado_cent FROM tarjetas WHERE id = ? FOR UPDATE',
                [$tarjetaId]
            );

            if ($t === null) {
                throw new \RuntimeException('La tarjeta del cobro ya no existe.');
            }

            $ultimos4 = (string) $t['ultimos4'];

            /* Nunca más de lo que se debe.
             *
             * Si el cliente pagó de más —porque abonó por otra vía
             * entre medias, o porque pulsó pagar dos veces— el
             * excedente no puede dejar la tarjeta en negativo: la base
             * lo prohíbe con `chk_usado_no_negativo`.
             *
             * QUÉ PASABA ANTES, Y POR QUÉ ERA GRAVE
             *
             * Se aplicaba lo que cabía, el sobrante se mencionaba en
             * el texto de un aviso... y se quedaba en el banco. El
             * cobro se hacía por el importe completo, la cuenta
             * general del banco se acreditaba por el importe completo,
             * y al cliente no le llegaba nada de ese excedente. La
             * frase «quedaron a favor» no correspondía a ningún dato:
             * no había tal saldo a favor en ninguna parte.
             *
             * Además el MOVIMIENTO se registraba por el importe
             * completo aunque solo se hubiera aplicado una parte, así
             * que el historial de la tarjeta dejaba de cuadrar con su
             * saldo. Eso es lo que destapó la comprobación 10 del
             * verificador.
             *
             * QUÉ HACE AHORA
             *
             * El movimiento registra lo que DE VERDAD se aplicó, y el
             * excedente se le acredita al cliente en su cuenta. Si no
             * tiene ninguna, el cobro NO se da por aplicado: queda
             * señalado para que una persona decida, que es mejor que
             * quedárselo en silencio. */
            $aplica   = min($importe, (int) $t['saldo_usado_cent']);
            $sobrante = $importe - $aplica;

            $cuentaDelCliente = 0;

            if ($sobrante > 0) {
                $cuentaDelCliente = (int) (BaseDatos::unValor(
                    'SELECT id FROM cuentas
                      WHERE usuario_id = ? AND estado = "activa" AND es_tecnica = 0
                      ORDER BY id LIMIT 1',
                    [$usuario]
                ) ?? 0);

                if ($cuentaDelCliente === 0) {
                    throw new \RuntimeException(
                        'El cobro excede el saldo de la tarjeta ••••' . $t['ultimos4']
                        . ' en ' . Monto::deCentavos($sobrante)->formateado()
                        . ' y el cliente no tiene cuenta donde abonarle la diferencia. '
                        . 'Requiere revisión manual: no se aplicó nada.'
                    );
                }
            }

            if ($aplica > 0) {
                BaseDatos::ejecutar(
                    'UPDATE tarjetas SET saldo_usado_cent = saldo_usado_cent - ? WHERE id = ?',
                    [$aplica, $tarjetaId]
                );
            }

            /* El movimiento: es lo que hace que el pago aparezca en el
               estado de cuenta y cuente para el mínimo del mes.
               Por lo APLICADO, no por lo cobrado: si no, el historial
               de la tarjeta dice una cosa y su saldo otra. */
            if ($aplica > 0) {
                BaseDatos::ejecutar(
                    'INSERT INTO tarjeta_movimientos
                       (tarjeta_id, tipo, importe_cent, comercio, categoria, referencia, estado, diferible)
                     VALUES (?, "pago", ?, "Pago con tarjeta de otro banco", "pago", ?, "liquidada", 0)',
                    [$tarjetaId, $aplica, $referencia]
                );
            }

            $aBloquear = [$tecnicaPasarela, $general];

            if ($cuentaDelCliente > 0) {
                $aBloquear[] = $cuentaDelCliente;
            }

            LibroMayor::bloquearCuentas(array_unique($aBloquear));

            /* Las patas del asiento: sale todo de la pasarela, y se
               reparte entre lo que cubre la deuda —que es del banco— y
               lo que sobra, que es del cliente. */
            $patas = [
                ['cuenta_id' => $tecnicaPasarela, 'importe' => Monto::deCentavos(-$importe),
                 'descripcion' => 'Cobro pendiente de liquidación · ' . $referencia],
            ];

            if ($aplica > 0) {
                $patas[] = ['cuenta_id' => $general, 'importe' => Monto::deCentavos($aplica),
                            'descripcion' => 'Pago de tarjeta ••••' . $t['ultimos4']
                                           . ' con tarjeta externa'];
            }

            if ($sobrante > 0) {
                $patas[] = ['cuenta_id' => $cuentaDelCliente, 'importe' => Monto::deCentavos($sobrante),
                            'descripcion' => 'Excedente del pago de tarjeta ••••'
                                           . $t['ultimos4'] . ' devuelto a su cuenta'];
            }

            $r = LibroMayor::registrar(
                'pago_tarjeta',
                $patas,
                $usuario,
                'pasarela-' . $referencia,
                'Pago de saldo de tarjeta con tarjeta externa'
            );

            // Misma precaución que en la rama de cuotas: sin asiento
            // nuevo, la deuda no puede quedar rebajada.
            if (($r['duplicado'] ?? false) === true) {
                throw new \RuntimeException(
                    'Este cobro ya se había aplicado a la tarjeta ••••' . $t['ultimos4'] . '.'
                );
            }

            $asientoId = (int) ($r['asiento_id'] ?? 0);

            BaseDatos::ejecutar(
                'UPDATE pagos_pasarela
                    SET estado = "aplicado", confirmado_en = NOW(), asiento_id = ?
                  WHERE id = ?',
                [(int) ($r['asiento_id'] ?? 0) ?: null, $pagoId]
            );

            /* Lo que se le dice al cliente tiene que corresponder con
               lo que ocurrió. Decir «quedaron a favor» sin que exista
               ese saldo a favor en ninguna parte era mentirle. */
            $detalle = $aplica > 0
                ? 'Se aplicaron ' . Monto::deCentavos($aplica)->formateado()
                  . ' al saldo de la tarjeta ••••' . $t['ultimos4'] . '.'
                : 'La tarjeta ••••' . $t['ultimos4'] . ' ya no tenía saldo pendiente.';

            if ($sobrante > 0) {
                $detalle .= ' Los ' . Monto::deCentavos($sobrante)->formateado()
                    . ' restantes se abonaron a su cuenta.';
            }
        });

        Auditoria::registrar('pasarela.pago_tarjeta', Auditoria::EXITO, $usuario,
            'tarjeta', (string) $tarjetaId,
            ['referencia' => $referencia, 'importe' => $importe]);

        (new ServicioNotificacion())->crear(
            $usuario,
            'pago.pasarela',
            'Pago aplicado a su tarjeta',
            $detalle,
            [
                // Igual que en el crédito: el asiento es lo que permite
                // adjuntar el comprobante.
                'asiento_id' => $asientoId,
                'tarjeta'    => '···· ' . $ultimos4,
                'referencia' => $referencia,
                'importe'    => Monto::deCentavos($importe)->formateado(),
            ],
            'exito'
        );

        return $detalle;
    }
    /**
     * Asienta una compra de la tienda y confirma su pedido.
     *
     * Dos cuentas técnicas y nada más:
     *
     *   · La de cobros de pasarela se CARGA, porque la procesadora
     *     todavía no ha liquidado y ese dinero está en camino.
     *   · La de comercios por pagar se ABONA, porque eso es lo que la
     *     institución le debe al comercio afiliado.
     *
     * La suma da cero, que es lo que el libro mayor exige, y el saldo de
     * «comercios por pagar» dice en todo momento cuánto se les debe.
     *
     * @param array<string,mixed> $pago
     */
    private function asentarCompraTienda(array $pago, string $referencia, int $tecnica): string
    {
        $pagoId   = (int) $pago['id'];
        $importe  = (int) $pago['importe_cent'];
        $pedidoId = (int) ($pago['pedido_id'] ?? 0);

        $comercios = (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE numero_cuenta = ?',
            [Parametros::obtener('tienda.cuenta_comercios', '0000000002')]
        );

        if ($comercios === 0) {
            BaseDatos::ejecutar(
                'UPDATE pagos_pasarela SET estado = "fallido",
                        detalle_error = "Falta la cuenta tecnica de comercios afiliados"
                  WHERE id = ?',
                [$pagoId]
            );

            return 'Cobrado en la pasarela pero sin cuenta de comercios: requiere revisión manual.';
        }

        $asientoId = 0;

        BaseDatos::enTransaccion(function () use (
            $pagoId, $importe, $tecnica, $comercios, $referencia, &$asientoId
        ): void {
            LibroMayor::bloquearCuentas([$tecnica, $comercios]);

            $r = LibroMayor::registrar(
                'deposito',
                [
                    ['cuenta_id' => $comercios, 'importe' => Monto::deCentavos($importe),
                     'descripcion' => 'Venta de la tienda por liquidar al comercio'],
                    ['cuenta_id' => $tecnica, 'importe' => Monto::deCentavos(-$importe),
                     'descripcion' => 'Cobro pendiente de liquidación · ' . $referencia],
                ],
                null,
                'pasarela-' . $referencia,
                'Compra en la tienda con tarjeta'
            );

            $asientoId = (int) ($r['asiento_id'] ?? 0);

            BaseDatos::ejecutar(
                'UPDATE pagos_pasarela
                    SET estado = "cobrado", confirmado_en = NOW(), asiento_id = ?
                  WHERE id = ?',
                [$asientoId ?: null, $pagoId]
            );
        });

        // El pedido pasa de «apartado» a «pagado» y se avisa a quien
        // compró, tenga cuenta o no.
        if ($pedidoId > 0) {
            (new ServicioTienda())->confirmarPagoPasarela($pedidoId, $pagoId);
        }

        Auditoria::registrar('pasarela.compra_tienda', Auditoria::EXITO,
            $pago['usuario_id'] !== null ? (int) $pago['usuario_id'] : null,
            'pago', (string) $pagoId,
            ['referencia' => $referencia, 'importe' => $importe,
             'pedido' => $pedidoId, 'asiento' => $asientoId]);

        return 'Compra de la tienda contabilizada, asiento ' . $asientoId;
    }

    /** A qué cuenta va el abono según lo que se estuviera pagando. */
    private function cuentaDestino(array $pago): int
    {
        // Pago directo a una cuenta.
        if ($pago['cuenta_destino_id'] !== null) {
            return (int) $pago['cuenta_destino_id'];
        }

        // Cuota de crédito o saldo de tarjeta: entra por la cuenta de
        // ahorro del titular, desde donde el sistema aplica el pago con
        // sus propias reglas. Hacerlo así evita duplicar aquí la lógica
        // de amortización, que ya está probada.
        $cuenta = BaseDatos::unValor(
            'SELECT id FROM cuentas
              WHERE usuario_id = ? AND es_tecnica = 0 AND estado = "activa"
              ORDER BY id LIMIT 1',
            [(int) $pago['usuario_id']]
        );

        return (int) ($cuenta ?? 0);
    }

    private function marcarFallido(string $referencia, array $objeto): string
    {
        /* El motivo, dicho como es.
         *
         * Antes se escribía «sin detalle» siempre que la pasarela no
         * mandaba mensaje de error, y en pantalla el cliente leía
         * «fallido · sin detalle» junto a un cobro que él nunca llegó
         * a intentar. No es lo mismo una tarjeta rechazada que un
         * cobro que se preparó y se abandonó: lo segundo pasa cada vez
         * que alguien cierra la pestaña, y decirle que le rechazaron
         * la tarjeta es alarmarlo por nada. */
        $error = (string) ($objeto['last_payment_error']['message'] ?? '');

        $cobrado = false;

        foreach ((array) ($objeto['charges']['data'] ?? []) as $cargo) {
            if (($cargo['status'] ?? '') === 'succeeded') { $cobrado = true; }
        }

        $abandonado = $error === '' && !$cobrado;

        $motivo = $error !== ''
            ? $error
            : ($abandonado
                ? 'Quedó sin completar: no se llegaron a ingresar los datos de la tarjeta.'
                : 'La pasarela no indicó el motivo.');

        $n = BaseDatos::ejecutar(
            'UPDATE pagos_pasarela SET estado = "fallido", detalle_error = ?
              WHERE referencia = ? AND estado <> "cobrado"',
            [mb_substr($motivo, 0, 500), $referencia]
        );

        if ($n === 0) { return 'Sin intento que marcar como fallido.'; }

        $pago = BaseDatos::unaFila(
            'SELECT id, usuario_id, concepto, pedido_id FROM pagos_pasarela WHERE referencia = ?',
            [$referencia]
        );

        /* Una tarjeta rechazada devuelve los artículos a bodega.
         *
         * El pedido había apartado las existencias para que nadie más
         * comprara la última unidad mientras se cobraba. Si el cobro no
         * prospera y no se devuelven, el catálogo acabaría diciendo que
         * no hay existencias de algo que sí está en bodega, y cada
         * tarjeta rechazada dejaría ese rastro para siempre. */
        if ($pago !== null
            && (string) $pago['concepto'] === 'tienda'
            && (int) ($pago['pedido_id'] ?? 0) > 0) {
            (new ServicioTienda())->liberar(
                (int) $pago['pedido_id'],
                'La tarjeta fue rechazada: ' . mb_substr($motivo, 0, 180)
            );
        }

        if ($pago !== null && $pago['usuario_id'] !== null) {
            Auditoria::registrar('pasarela.cobro_rechazado', Auditoria::FALLO,
                (int) $pago['usuario_id'], 'pago', (string) $pago['id'], ['motivo' => $motivo]);

            /* Un cobro abandonado no merece aviso: el cliente estaba
               delante cuando cerró la pantalla y no se le cobró nada.
               Mandarle una alerta por eso es ruido, y encima lo
               asusta. Se avisa solo cuando hubo rechazo de verdad. */
            if (!$abandonado) {
                (new ServicioNotificacion())->crear(
                    (int) $pago['usuario_id'],
                    'pago.pasarela',
                    'El pago no se completó',
                    'Su tarjeta rechazó el cobro: ' . $motivo
                    . '. Puede intentarlo de nuevo con otra tarjeta; no se le cobró nada.',
                    null,
                    'alerta'
                );
            }
        }

        return 'Cobro rechazado: ' . $motivo;
    }

    private function marcarCancelado(string $referencia): string
    {
        $n = BaseDatos::ejecutar(
            'UPDATE pagos_pasarela SET estado = "cancelado"
              WHERE referencia = ? AND estado NOT IN ("cobrado","devuelto")',
            [$referencia]
        );

        return $n > 0 ? 'Intento cancelado.' : 'Sin intento que cancelar.';
    }

    /**
     * Devolución.
     *
     * No se revierte el asiento automáticamente: sacar dinero de la
     * cuenta de un cliente sin su intervención es una operación que
     * tiene que decidir una persona, sobre todo si entretanto ya lo
     * gastó. Queda marcado y notificado para que lo resuelva el área
     * correspondiente.
     */
    private function marcarDevuelto(array $objeto): string
    {
        $referencia = (string) ($objeto['payment_intent'] ?? '');

        if ($referencia === '') { return 'Devolución sin referencia de cobro.'; }

        $pago = BaseDatos::unaFila(
            'SELECT id, usuario_id, importe_cent FROM pagos_pasarela WHERE referencia = ?',
            [$referencia]
        );

        if ($pago === null) { return 'Devolución de un cobro desconocido.'; }

        BaseDatos::ejecutar(
            'UPDATE pagos_pasarela SET estado = "devuelto" WHERE id = ?', [(int) $pago['id']]
        );

        Auditoria::registrar('pasarela.cobro_devuelto', Auditoria::FALLO,
            (int) $pago['usuario_id'], 'pago', (string) $pago['id'],
            ['referencia' => $referencia, 'importe' => (int) $pago['importe_cent'],
             'nota' => 'El asiento NO se revirtió: requiere decisión manual']);

        return 'Devolución registrada. El asiento contable no se revierte solo: '
             . 'hay que resolverlo a mano.';
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
