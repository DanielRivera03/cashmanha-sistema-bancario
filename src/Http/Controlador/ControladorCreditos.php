<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\FondosInsuficientes;
use CashMan\Aplicacion\Servicio\ServicioArreglo;
use CashMan\Aplicacion\Servicio\ServicioCredito;
use CashMan\Aplicacion\Servicio\ServicioEnlacePago;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Comun\MontoInvalido;
use CashMan\Dominio\Credito\Mora;
use CashMan\Infraestructura\Parametros;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

final class ControladorCreditos extends ControladorBase
{
    public function listar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $todos     = Autorizacion::puede('credito.ver_todos');

        $filtro = $this->get('e');
        $where  = $todos ? '1=1' : 'c.usuario_id = ?';
        $args   = $todos ? [] : [$usuarioId];

        $estadosValidos = ['solicitado','en_revision','aprobado','desembolsado',
                           'vigente','cancelado','rechazado','castigado'];

        if ($filtro !== '' && in_array($filtro, $estadosValidos, true)) {
            $where .= ' AND c.estado = ?';
            $args[] = $filtro;
        }

        $creditos = BaseDatos::todas(
            "SELECT c.*, p.nombre AS producto,
                    CONCAT(u.nombres,' ',u.apellidos) AS cliente,
                    (SELECT COUNT(*) FROM cuotas q WHERE q.credito_id=c.id
                      AND q.estado NOT IN ('pagada','condonada') AND q.fecha_vencimiento < CURDATE()) AS vencidas
               FROM creditos c
               JOIN productos p ON p.id = c.producto_id
               JOIN usuarios  u ON u.id = c.usuario_id
              WHERE {$where}
              ORDER BY FIELD(c.estado,'vigente','aprobado','cancelado','rechazado'), c.id DESC
              LIMIT 200",
            $args
        );

        foreach ($creditos as &$c) {
            $c['codigo'] = Hashid::codificar((int) $c['id'], 'credito');
            $c['avance'] = (int) $c['plazo_meses'] > 0
                ? (int) round(((int) $c['cuotas_pagadas'] / (int) $c['plazo_meses']) * 100) : 0;
            unset($c['id']);
        }

        $resumen = BaseDatos::unaFila(
            $todos
                ? 'SELECT COUNT(*) total,
                          SUM(estado="vigente") vigentes,
                          COALESCE(SUM(CASE WHEN estado="vigente" THEN saldo_capital_cent ELSE 0 END),0) cartera,
                          SUM(estado="aprobado") por_desembolsar
                     FROM creditos'
                : 'SELECT COUNT(*) total,
                          SUM(estado="vigente") vigentes,
                          COALESCE(SUM(CASE WHEN estado="vigente" THEN saldo_capital_cent ELSE 0 END),0) cartera,
                          SUM(estado="aprobado") por_desembolsar
                     FROM creditos WHERE usuario_id = ?',
            $todos ? [] : [$usuarioId]
        );

        // Conteo por estado: el sistema anterior dedicaba una pantalla
        // a cada uno (activos, cancelados, denegados, históricos). Aquí
        // son pestañas sobre la misma tabla, con su número a la vista.
        $porEstado = [];
        $filas = BaseDatos::todas(
            $todos
                ? 'SELECT estado, COUNT(*) n FROM creditos GROUP BY estado'
                : 'SELECT estado, COUNT(*) n FROM creditos WHERE usuario_id = ? GROUP BY estado',
            $todos ? [] : [$usuarioId]
        );
        foreach ($filas as $fl) { $porEstado[(string) $fl['estado']] = (int) $fl['n']; }

        $this->render('creditos/listar', [
            'titulo'     => 'Créditos · CashMan H.A.',
            'encabezado' => $todos ? 'Cartera de créditos' : 'Mis créditos',
            'creditos'   => $creditos,
            'resumen'    => $resumen ?? [],
            'todos'      => $todos,
            'filtro'     => $filtro,
            'porEstado'  => $porEstado,
            'estados'    => $estadosValidos,
        ]);
    }

    public function detalle(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'credito');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Crédito no encontrado.');
        }

        $c = BaseDatos::unaFila(
            'SELECT c.*, p.nombre AS producto, p.tipo AS producto_tipo,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente, u.correo AS cliente_correo
               FROM creditos c
               JOIN productos p ON p.id = c.producto_id
               JOIN usuarios  u ON u.id = c.usuario_id
              WHERE c.id = ?',
            [$id]
        );

        // Verificación de propiedad: el crédito ajeno no se ve sin permiso.
        if ($c === null
            || ((int) $c['usuario_id'] !== $usuarioId && !Autorizacion::puede('credito.ver_todos'))) {
            throw new RecursoNoEncontrado('Crédito no encontrado.');
        }

        /* La tabla completa, con todo lo que compone cada cuota.
         *
         * `v_amortizacion` no trae los seguros ni el saldo posterior,
         * así que la pantalla enseñaba capital, interés y cuota y el
         * cliente no podía cuadrar por qué la cuota valía lo que valía.
         * Se lee de `cuotas`, que sí los tiene. */
        $cuotas = BaseDatos::todas(
            'SELECT q.*,
                    GREATEST(0, q.total_cent - q.pagado_cent) AS pendiente_cent,
                    CASE WHEN q.estado NOT IN ("pagada","condonada") AND q.fecha_vencimiento < CURDATE()
                         THEN DATEDIFF(CURDATE(), q.fecha_vencimiento) ELSE 0 END AS dias_vencida
               FROM cuotas q
              WHERE q.credito_id = ?
              ORDER BY q.numero',
            [$id]
        );

        /* El recargo que lleva acumulado cada cuota vencida.
         *
         * `cuotas.mora_cent` solo se escribe al pagar; mientras la cuota
         * sigue impaga la columna vale cero y la pantalla mostraba cero
         * mora en una cuota con cuatro años de atraso. Se calcula con la
         * misma clase que usa el cobro, de modo que lo que se enseña es
         * exactamente lo que se cobrará. */
        foreach ($cuotas as &$q) {
            $q['codigo'] = Hashid::codificar((int) $q['id'], 'cuota');

            $pendiente = Monto::deCentavos((int) $q['pendiente_cent']);
            $dias      = (int) $q['dias_vencida'];

            /* Dos moras distintas, y no se pueden mezclar.
             *
             * `cuotas.mora_cent` es el recargo que YA SE COBRÓ sobre esa
             * cuota. La mora viva es la que se debe HOY sobre lo que
             * queda pendiente.
             *
             * Aquí se devolvía `mora_cent` como si fuera mora viva y se
             * sumaba a «por pagar», con lo cual una cuota pagada por
             * compensación aparecía debiendo $9,512.12 —justo el
             * recargo que acababa de cobrársele—. El cliente veía que
             * le quitaron el dinero y que seguía debiendo lo mismo. */
            $pagada = in_array($q['estado'], ['pagada', 'condonada'], true);

            $q['mora_cobrada_cent'] = (int) $q['mora_cent'];

            $q['mora_viva_cent'] = $pagada
                ? 0
                : Mora::calcular($pendiente, $dias)->centavos;

            $q['a_pagar_cent'] = $pagada
                ? 0
                : (int) $q['pendiente_cent'] + (int) $q['mora_viva_cent'];

            // Todo lo que de verdad se recibió contra esta cuota: lo que
            // entró a la cuota más el recargo que se cobró con ella.
            $q['recibido_cent'] = (int) $q['pagado_cent'] + (int) $q['mora_cent'];

            /* La constancia de ESTE pago.
             *
             * «Pagada» en la tabla no le sirve a nadie para demostrar
             * nada. El asiento con el que se pagó queda apuntado en la
             * propia cuota, y de ahí sale el comprobante. Las cuotas
             * liquidadas por vías que no dejaron asiento no ofrecen
             * enlace: mejor sin botón que con uno que lleva a un
             * documento que no es el suyo. */
            $q['comprobante'] = ($q['asiento_id'] ?? null) !== null
                ? Hashid::codificar((int) $q['asiento_id'], 'asiento')
                : null;
        }
        unset($q);

        $referencias = BaseDatos::todas('SELECT * FROM referencias WHERE credito_id = ?', [$id]);
        $garantias   = BaseDatos::todas('SELECT * FROM garantias WHERE credito_id = ?', [$id]);

        // Cuentas del titular, para pagar cuotas.
        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, c.saldo_cent, p.nombre AS producto, p.saldo_minimo_cent
               FROM cuentas c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.estado = "activa" AND c.es_tecnica = 0',
            [(int) $c['usuario_id']]
        );
        foreach ($cuentas as &$cu) {
            $cu['codigo'] = Hashid::codificar((int) $cu['id'], 'cuenta');
            $cu['disponible_cent'] = max(0, (int) $cu['saldo_cent'] - (int) $cu['saldo_minimo_cent']);
            unset($cu['id']);
        }

        $c['codigo'] = Hashid::codificar($id, 'credito');
        $c['avance'] = (int) $c['plazo_meses'] > 0
            ? (int) round(((int) $c['cuotas_pagadas'] / (int) $c['plazo_meses']) * 100) : 0;
        /* Igual que en la tarjeta: ser el deudor y poder operar
           sobre el crédito son dos cosas, y el administrador
           puede lo segundo sin ser lo primero. */
        $c['esMio']  = (int) $c['usuario_id'] === $usuarioId
                    || Autorizacion::mandoTotal();

        /* El atraso real, calculado ahora.
         *
         * El panel mostraba «Cuotas tardías: 0» y «Clasificación: A» en
         * un crédito con 53 cuotas vencidas y 1,588 días de mora. Las
         * dos cifras eran ciertas según su definición y engañosas según
         * cualquier lectura razonable:
         *
         *   `cuotas_tardias` cuenta las que se PAGARON tarde. Las que
         *   no se han pagado nunca no entran, de modo que el peor
         *   deudor posible —el que no ha pagado nada— marca cero.
         *
         *   `clasificacion` es una columna guardada que solo se
         *   recalcula al archivar el crédito. Hasta entonces conserva
         *   la 'A' con la que nació.
         *
         * Aquí se calcula lo que de verdad importa: cuántas están
         * vencidas hoy y qué categoría le corresponde por días de
         * atraso, con el mismo criterio de `v_cartera_morosa`, para que
         * las dos pantallas no se contradigan. */
        $atraso = BaseDatos::unaFila(
            'SELECT COUNT(*)                                   AS vencidas,
                    COALESCE(SUM(total_cent - pagado_cent), 0) AS deuda_cent,
                    COALESCE(SUM(mora_cent), 0)                AS mora_cent,
                    COALESCE(DATEDIFF(CURDATE(), MIN(fecha_vencimiento)), 0) AS dias
               FROM cuotas
              WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")
                AND fecha_vencimiento < CURDATE()',
            [$id]
        ) ?? [];

        $dias = (int) ($atraso['dias'] ?? 0);

        $c['vencidas']   = (int) ($atraso['vencidas'] ?? 0);
        $c['deuda_cent'] = (int) ($atraso['deuda_cent'] ?? 0);
        $c['mora_cent']  = (int) ($atraso['mora_cent'] ?? 0);
        $c['dias_mora']  = $dias;

        $c['clasificacion_hoy'] = match (true) {
            $c['vencidas'] === 0 => 'A',
            $dias <= 30          => 'A',
            $dias <= 60          => 'B',
            $dias <= 90          => 'C',
            $dias <= 180         => 'D',
            default              => 'E',
        };

        unset($c['id']);

        /* Quién puede cobrar esto, y cómo.
         *
         * El botón decía «Debitar de mi cuenta» en el crédito de
         * cualquiera. No era del todo cierto —la cuenta que cargaba es
         * la del titular, no la del empleado— pero sí era un botón que
         * el personal de cobranza no puede usar: el servicio exige
         * `deposito.registrar` para cobrar lo ajeno, de modo que al
         * pulsarlo se obtenía un 403 sin explicación.
         *
         * Peor que el 403 es lo que insinúa: que alguien de jurídico
         * puede vaciar la cuenta de ahorro de un cliente para cubrir su
         * cuota sin que el cliente intervenga. Eso no es cobranza, y no
         * debe ofrecerse desde aquí.
         *
         * Al titular se le ofrece su cuenta. A ventanilla, cargar la
         * cuenta del cliente que tiene delante. A cobranza, las dos vías
         * en que el cliente consiente: su tarjeta, o un enlace de pago
         * que se le manda y abre él. */
        $puedeDebitar  = $c['esMio'] || Autorizacion::puede('deposito.registrar');
        $puedeGestionar = Autorizacion::puedeAlguno(['cobranza.gestionar', 'cobranza.juridico']);

        $arreglos = new ServicioArreglo();

        $this->render('creditos/detalle', [
            'titulo'      => 'Crédito ' . $c['folio'] . ' · CashMan H.A.',
            'encabezado'  => 'Crédito ' . $c['folio'],
            'credito'     => $c,
            'cuotas'      => $cuotas,
            'referencias' => $referencias,
            'garantias'   => $garantias,
            'cuentas'     => $cuentas,
            'csrf'        => Csrf::token('credito'),
            'puedeDesembolsar' => Autorizacion::puede('credito.desembolsar') && $c['estado'] === 'aprobado',
            /* ¿Está cobrando desde una ventanilla con su caja abierta?
               Solo entonces tiene sentido ofrecerle efectivo y cheque:
               es cuando el cliente está delante y puede entregarlos, y
               es lo que hace que el dinero entre a un turno que alguien
               arquea al cerrar. */
            'enVentanilla'     => (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM caja_sesiones
                  WHERE usuario_id = ? AND estado = "abierta"',
                [Sesion::usuarioId() ?? 0]
            ) > 0,
            'puedeDebitar'     => $puedeDebitar,
            'puedeCobrar'      => $puedeGestionar,
            'puedeArreglo'     => Autorizacion::puede('arreglo.proponer')
                                  && $c['vencidas'] > 0
                                  && in_array($c['estado'], ['vigente', 'desembolsado'], true),
            'arreglos'         => $arreglos->deCredito($id),
            /* La deuda que ve el simulador de arreglos.
             *
             * Sale del mismo servicio que la calcula al proponer. Antes
             * se armaba aquí con `cuotas.mora_cent`, que solo se escribe
             * al PAGAR una cuota: en un crédito impago vale casi cero.
             * El panel decía «exigible $1,081,944» mientras el cobro de
             * las mismas cuotas pedía $1,334,147, y las dos cifras salían
             * de la misma pantalla. */
            'deuda'            => $arreglos->deudaDe($id),
            'quitaMaximaBp'    => Parametros::entero('arreglo.quita_maxima_bp', 3000),
        ]);
    }

    /**
     * Propone un arreglo sobre las cuotas vencidas.
     *
     * El porcentaje llega del formulario, pero no decide nada por sí
     * solo: el servicio recalcula la deuda, aplica los topes y, si la
     * condonación resultante pasa del margen que cobranza concede sola,
     * lo deja esperando a que otra persona lo autorice.
     */
    public function proponerArreglo(array $parametros = []): void
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'credito');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Crédito no encontrado.');
        }

        $tipo = $this->post('tipo', 'al_dia');
        $pct  = max(10, min(100, (int) $this->post('porcentaje', '70')));

        // El expediente de cobranza abierto, si lo hay: así el arreglo
        // queda enganchado al caso y al cobrarse lo cierra.
        $casoId = BaseDatos::unValor(
            'SELECT id FROM cobranza_casos
              WHERE credito_id = ? AND cerrado_en IS NULL
              ORDER BY id DESC LIMIT 1',
            [$id]
        );

        try {
            $r = (new ServicioArreglo())->proponer(
                $id,
                $tipo,
                $pct * 100,                 // el servicio trabaja en puntos básicos
                $this->post('nota'),
                $casoId !== null ? (int) $casoId : null
            );
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        $this->responderSegunCliente(true, $r['requiere_autorizacion']
            ? 'Arreglo ' . $r['folio'] . ' propuesto. Queda a la espera de '
              . 'autorización porque la condonación pasa del margen permitido.'
            : 'Arreglo ' . $r['folio'] . ' listo para cobrar por '
              . Monto::deCentavos((int) $r['requerido'])->formateado() . '.',
            $this->volverAlCredito($parametros));
    }

    /**
     * A dónde se vuelve tras una acción, si no fue por AJAX.
     *
     * A la pantalla del crédito, que es de donde se salió. Mandar a una
     * lista o al inicio obliga a buscar otra vez el expediente en el que
     * se estaba trabajando.
     *
     * @param array<string,mixed> $parametros
     */
    private function volverAlCredito(array $parametros): string
    {
        $codigo = (string) ($parametros['codigo'] ?? '');

        return $codigo !== ''
            ? $this->enrutador->url('creditos/' . $codigo)
            : $this->enrutador->url('creditos');
    }
    public function autorizarArreglo(array $parametros = []): void
    {
        try {
            $r = (new ServicioArreglo())->autorizar(
                (int) ($parametros['arreglo'] ?? 0)
            );
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        $this->responderSegunCliente(
            true,
            'Arreglo ' . $r['folio'] . ' autorizado.',
            $this->volverAlCredito($parametros)
        );
    }

    public function anularArreglo(array $parametros = []): void
    {
        try {
            $r = (new ServicioArreglo())->anular(
                (int) ($parametros['arreglo'] ?? 0),
                $this->post('motivo')
            );
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        $this->responderSegunCliente(
            true,
            'Arreglo ' . $r['folio'] . ' anulado.',
            $this->volverAlCredito($parametros)
        );
    }

    /**
     * Le manda al cliente un enlace para que pague él.
     *
     * Es la alternativa honesta a que cobranza meta la mano en la cuenta
     * del cliente: se calcula aquí lo que debe, se le manda un enlace de
     * un solo uso con esa cantidad y lo paga él con su tarjeta. Nadie de
     * la institución llega a ver el número.
     */
    public function enlacePago(array $parametros = []): void
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'credito');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Crédito no encontrado.');
        }

        /* Siempre la totalidad de lo vencido.
         *
         * No se admite aquí una selección de cuotas, y es deliberado.
         * Un requerimiento parcial obliga a declarar que se cobra una
         * parte y a reservar expresamente el resto; basta con que esa
         * selección llegue mal —una cuota de un clic anterior, un lote
         * a medio marcar— para mandarle al deudor un documento formal
         * que afirma «comprende 5 cuotas» cuando el crédito debe 53.
         *
         * Lo que se cobra lo decide el servidor leyendo la cartera, no
         * lo que venga en el formulario. */
        try {
            $r = (new ServicioEnlacePago())->paraCuotas($id, []);
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        $this->responderSegunCliente(
            true,
            'Requerimiento enviado a ' . $r['correo'] . ' por '
            . Monto::deCentavos((int) $r['importe'])->formateado()
            . ' (' . (int) $r['cuotas'] . ' cuota(s) vencidas). '
            . 'El enlace caduca el ' . $r['vence'] . '.',
            $this->volverAlCredito($parametros)
        );
    }

    public function enlaceArreglo(array $parametros = []): void
    {
        try {
            $r = (new ServicioEnlacePago())->paraArreglo(
                (int) ($parametros['arreglo'] ?? 0)
            );
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        $this->responderSegunCliente(
            true,
            'Enlace enviado a ' . $r['correo'] . ' por '
            . Monto::deCentavos((int) $r['importe'])->formateado() . '.',
            $this->volverAlCredito($parametros)
        );
    }

    /**
     * Las cuotas que vienen marcadas en el formulario.
     *
     * @return list<int>
     */
    private function cuotasDelPost(): array
    {
        $crudo = $this->post('cuotas');

        if ($crudo === '') {
            return [];
        }

        $ids = [];

        foreach (explode(',', $crudo) as $codigo) {
            $id = $this->idDesde(trim($codigo), 'cuota');

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function desembolsar(array $parametros = []): void
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'credito');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Crédito no encontrado.');
        }

        $cuentaId = $this->idDesde($this->post('cuenta'), 'cuenta');
        if ($cuentaId === 0) {
            Respuesta::error('Seleccione la cuenta de desembolso.', 422);
        }

        try {
            $r = (new ServicioCredito())->desembolsar($id, $cuentaId);
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito($r, 'Crédito desembolsado. Referencia ' . $r['referencia']);
    }

    public function pagarCuota(array $parametros = []): void
    {
        /* Con qué paga.
         *
         * `cuenta` es el débito de siempre. `efectivo` y `cheque` son
         * dinero que el cliente trae al mostrador y que no pasa por su
         * cuenta de ahorro: ahí no hay cuenta que pedir, y exigirla era
         * lo que obligaba al cajero a debitar una cuenta ajena al pago
         * que acababa de recibir en billetes. */
        $pago = [
            'medio'         => $this->post('medio', 'cuenta'),
            'cheque_banco'  => $this->post('cheque_banco'),
            'cheque_numero' => $this->post('cheque_numero'),
        ];

        $cuentaId = $this->idDesde($this->post('cuenta'), 'cuenta');

        if ($pago['medio'] === 'cuenta' && $cuentaId === 0) {
            Respuesta::error('Seleccione la cuenta de cargo.', 422);
            return;
        }

        /* Una cuota o muchas.
         *
         * El formulario manda `cuota` cuando se pulsó el botón de una
         * fila y `cuotas` cuando se marcaron varias. Se atiende el
         * segundo si viene, porque es el caso en que la persona eligió
         * explícitamente un conjunto. */
        $varias  = $this->cuotasDelPost();
        $cuotaId = $this->idDesde($this->post('cuota'), 'cuota');

        if ($varias === [] && $cuotaId === 0) {
            Respuesta::error('Seleccione al menos una cuota.', 422);
            return;
        }

        $idem  = $this->post('_idem');
        $sello = preg_match('/^[A-Za-z0-9_-]{8,64}$/', $idem)
            ? $idem
            : bin2hex(random_bytes(12));

        $servicio = new ServicioCredito();

        try {
            if ($varias !== []) {
                // La clave de idempotencia incluye las cuotas: si se
                // reenvía el mismo formulario, el asiento no se repite;
                // si se eligen otras cuotas, es otra operación.
                sort($varias);

                $clave = 'cuotas:' . md5(implode(',', $varias)) . ':'
                       . $pago['medio'] . ':' . $sello;
                $r = $servicio->pagarVarias($varias, $cuentaId, $clave, $pago);
            } else {
                $clave = 'cuota:' . $cuotaId . ':' . $pago['medio'] . ':' . $sello;
                $r = $servicio->pagarCuota($cuotaId, $cuentaId, $clave, $pago);
            }
        } catch (FondosInsuficientes $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'fondos_insuficientes']);
            return;
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        if ($r['estado'] === 'duplicado') {
            Respuesta::exito($r, 'Este pago ya se había registrado.');
            return;
        }

        $cuantas = (int) ($r['cuotas'] ?? 1);

        Respuesta::exito($r, ($r['cancelado'] ?? false)
            ? '¡Crédito cancelado por completo!'
            : ($cuantas > 1
                ? $cuantas . ' cuotas pagadas correctamente.'
                : 'Cuota pagada correctamente.'));
    }

    /** Tabla de amortización en PDF. */
    public function amortizacionPdf(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'credito');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Crédito no encontrado.');
        }

        // Propiedad verificada ANTES de generar el archivo.
        $c = BaseDatos::unaFila('SELECT usuario_id, folio FROM creditos WHERE id = ?', [$id]);
        if ($c === null
            || ((int) $c['usuario_id'] !== $usuarioId && !Autorizacion::puede('credito.ver_todos'))) {
            throw new RecursoNoEncontrado('Crédito no encontrado.');
        }

        try {
            $doc = (new \CashMan\Infraestructura\Documento\ServicioDocumentos())
                ->tablaAmortizacion($id);
        } catch (\Throwable $e) {
            error_log('[PDF] ' . $e->getMessage());
            Respuesta::error('No se pudo generar el documento.', 500);
            return;
        }

        \CashMan\Infraestructura\Seguridad\Auditoria::registrar(
            'credito.amortizacion_descargada',
            \CashMan\Infraestructura\Seguridad\Auditoria::EXITO,
            $usuarioId, 'credito', (string) $id
        );

        Respuesta::ver($doc['ruta'], 'amortizacion-' . ($c['folio'] ?: $id) . '.pdf');
    }

    /** Simulador de crédito, abierto a quien pueda solicitar. */
    public function simular(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        try {
            // La tasa solo se toma en cuenta si quien pide tiene permiso
            // para moverla; el servicio la valida contra el rango del
            // producto antes de usarla.
            $tasa = null;
            if (Autorizacion::puede('credito.ajustar_tasa')) {
                $enviada = $this->post('tasa_bp');
                $tasa = $enviada === '' ? null : (int) $enviada;
            }

            // El titular: si se captura para un cliente, la capacidad se
            // mide con los compromisos de ÉL, no con los de quien captura.
            $titular = Sesion::usuarioId();
            $deCliente = $this->idDesde($this->post('cliente'), 'usuario');

            if ($deCliente > 0 && Autorizacion::puede('solicitud.capturar')) {
                $titular = $deCliente;
            }

            $r = (new ServicioCredito())->simular(
                $this->post('monto'),
                (int) $this->post('plazo', '12'),
                $this->idDesde($this->post('producto'), 'producto'),
                $tasa,
                $this->post('ingreso'),
                $titular
            );
        } catch (\InvalidArgumentException | MontoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito($r);
    }
}
