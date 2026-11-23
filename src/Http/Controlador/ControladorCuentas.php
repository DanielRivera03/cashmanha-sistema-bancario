<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Parametros;
use CashMan\Aplicacion\Servicio\EmisorTarjeta;
use CashMan\Http\Paginador;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

final class ControladorCuentas extends ControladorBase
{
    /**
     * Listado de cuentas.
     *
     * Quien tiene `cuenta.ver_todas` —administración, presidencia,
     * gerencia y atención a clientes— ve la cartera de captaciones de
     * la institución, con sus totales y sus filtros. Un cliente ve las
     * suyas. Antes todos veían solo las propias, y como el personal no
     * tiene cuentas personales, la pantalla les salía vacía.
     */
    public function listar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $todas     = Autorizacion::puede('cuenta.ver_todas');

        if (!$todas) {
            Autorizacion::exigir('cuenta.ver_propia');
        }

        $condiciones = ['c.es_tecnica = 0'];
        $args        = [];

        if (!$todas) {
            $condiciones[] = 'c.usuario_id = ?';
            $args[]        = $usuarioId;
        }

        // Filtros: solo tienen sentido en la vista institucional.
        $buscar  = $todas ? $this->get('q') : '';
        $estado  = $todas ? $this->get('estado') : '';
        $tipo    = $todas ? $this->get('tipo') : '';

        if ($buscar !== '') {
            $condiciones[] = '(c.numero_cuenta LIKE ? OR u.usuario LIKE ?
                               OR CONCAT(u.nombres," ",u.apellidos) LIKE ?)';
            $like   = '%' . $buscar . '%';
            $args[] = $like; $args[] = $like; $args[] = $like;
        }

        if (in_array($estado, ['activa', 'inactiva', 'bloqueada', 'cerrada'], true)) {
            $condiciones[] = 'c.estado = ?';
            $args[]        = $estado;
        }

        if (in_array($tipo, ['ahorro', 'corriente'], true)) {
            $condiciones[] = 'p.tipo = ?';
            $args[]        = $tipo;
        }

        $donde = implode(' AND ', $condiciones);

        $cuentas = BaseDatos::todas(
            "SELECT c.id, c.numero_cuenta, c.saldo_cent, c.saldo_retenido_cent, c.moneda,
                    c.estado, c.apertura_en, c.usuario_id,
                    p.nombre AS producto, p.tipo, p.saldo_minimo_cent,
                    CONCAT(u.nombres,' ',u.apellidos) AS titular, u.usuario AS titular_usuario
               FROM cuentas c
               JOIN productos p ON p.id = c.producto_id
               JOIN usuarios  u ON u.id = c.usuario_id
              WHERE {$donde}
              ORDER BY c.estado = 'activa' DESC, c.saldo_cent DESC
              LIMIT 300",
            $args
        );

        $totales = [
            'cuentas'     => count($cuentas),
            'activas'     => 0,
            'saldo'       => 0,
            'retenido'    => 0,
            'disponible'  => 0,
        ];

        foreach ($cuentas as &$c) {
            $disponible = max(
                0,
                (int) $c['saldo_cent'] - (int) $c['saldo_retenido_cent'] - (int) $c['saldo_minimo_cent']
            );

            $c['codigo']          = Hashid::codificar((int) $c['id'], 'cuenta');
            $c['disponible_cent'] = $disponible;
            $c['es_mia']          = (int) $c['usuario_id'] === $usuarioId;

            $totales['saldo']      += (int) $c['saldo_cent'];
            $totales['retenido']   += (int) $c['saldo_retenido_cent'];
            $totales['disponible'] += $disponible;
            if ($c['estado'] === 'activa') { $totales['activas']++; }

            unset($c['id'], $c['usuario_id']);
        }
        unset($c);

        $this->render('cuentas/listar', [
            'titulo'     => $todas ? 'Cuentas · CashMan H.A.' : 'Mis cuentas · CashMan H.A.',
            'encabezado' => $todas ? 'Cuentas de la institución' : 'Mis cuentas',
            'cuentas'    => $cuentas,
            'totales'    => $totales,
            'vistaTodas' => $todas,
            'filtros'    => ['q' => $buscar, 'estado' => $estado, 'tipo' => $tipo],
            'puedeAbrir' => Autorizacion::puede('cuenta.abrir'),
        ]);
    }

    public function detalle(array $parametros = []): void
    {
        $cuentaId = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'cuenta');
        if ($cuentaId === 0) {
            throw new RecursoNoEncontrado('Cuenta no encontrada.');
        }

        // Verificación de propiedad: sin esto volvería el IDOR.
        $cuenta = Autorizacion::exigirCuenta($cuentaId);

        // `a.id` hace falta para enlazar el comprobante de cada
        // movimiento. Se entrega codificado: el identificador real del
        // asiento no sale nunca a la vista.
        $movimientos = BaseDatos::todas(
            'SELECT m.importe_cent, m.descripcion, m.saldo_posterior_cent, m.creado_en,
                    a.id AS asiento_id, a.referencia, a.tipo
               FROM movimientos m
               JOIN asientos a ON a.id = m.asiento_id
              WHERE m.cuenta_id = ?
              ORDER BY m.id DESC
              LIMIT 60',
            [$cuentaId]
        );

        foreach ($movimientos as &$m) {
            $m['comprobante'] = Hashid::codificar((int) $m['asiento_id'], 'asiento');
            unset($m['asiento_id']);
        }
        unset($m);

        $cuenta['codigo'] = Hashid::codificar($cuentaId, 'cuenta');

        // Lo que de verdad se puede usar, y el último movimiento: los dos
        // datos que el panel en vivo refresca solo.
        $disponible = max(0,
            (int) $cuenta['saldo_cent']
            - (int) $cuenta['saldo_retenido_cent']
            - (int) ($cuenta['saldo_minimo_cent'] ?? 0)
        );

        $ultimoMov = $movimientos[0] ?? null;

        /* Las tarjetas de débito de esta cuenta.
           Se enseñan aquí porque es donde se piden: ver el plástico
           junto al saldo del que tira es lo que hace evidente qué es
           una tarjeta de débito. */
        $debitos = BaseDatos::todas(
            'SELECT * FROM v_tarjetas_detalle
              WHERE cuenta_id = ? AND tipo = "debito" AND estado <> "cancelada"
              ORDER BY id DESC',
            [$cuentaId]
        );

        foreach ($debitos as &$d) {
            $d['codigo'] = Hashid::codificar((int) $d['id'], 'tarjeta');
            unset($d['id']);
        }
        unset($d);

        $this->render('cuentas/detalle', [
            'titulo'      => 'Cuenta ' . $cuenta['numero_cuenta'] . ' · CashMan H.A.',
            'encabezado'  => 'Detalle de cuenta',
            'cuenta'      => $cuenta,
            'movimientos' => $movimientos,
            'disponible'  => $disponible,
            'ultimoMov'   => $ultimoMov,
            'debitos'     => $debitos,
            'topeDebito'  => Parametros::entero('tarjeta.debito_max_por_cuenta', 2),
            'marcas'      => array_values(array_filter(array_map('trim', explode(',',
                Parametros::obtener('tarjeta.marcas_disponibles', 'visa,mastercard,amex'))))),
            'puedeDebito' => (int) $cuenta['usuario_id'] === Sesion::usuarioId()
                ? Autorizacion::puede('tarjeta.debito_propia')
                : Autorizacion::puede('tarjeta.emitir'),
            'csrf'        => Csrf::token('cuenta'),
        ]);
    }

    /**
     * Movimientos.
     *
     * Con `cuenta.ver_todas` se ve el movimiento de toda la institución
     * —que es lo que necesita quien concilia o atiende ventanilla— con
     * el titular de cada cuenta y filtros por tipo y fecha. El cliente
     * ve el de sus propias cuentas.
     */
    /**
     * Emite una tarjeta de débito para esta cuenta.
     *
     * El titular puede pedirla para la suya; el personal, para la de un
     * cliente en ventanilla. En los dos casos el emisor comprueba que
     * la cuenta sea de quien se dice y esté activa, y cuántas lleva ya.
     */
    public function tarjetaDebito(array $parametros = []): void
    {
        $actorId  = Sesion::exigirAutenticado();
        $cuentaId = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'cuenta');

        if ($cuentaId === 0) {
            $this->responderSegunCliente(
                false, 'Cuenta no encontrada.', $this->url('cuentas'), null, [], 404
            );
            return;
        }

        $cuenta = Autorizacion::exigirCuenta($cuentaId);
        $destino = $this->url('cuentas/' . Hashid::codificar($cuentaId, 'cuenta'));

        /* Quién puede pedirla: el titular para su cuenta, o quien tenga
           el permiso de emitir para la de otro. Sin esta distinción,
           cualquiera con acceso a la pantalla emitiría plásticos sobre
           cuentas ajenas. */
        $esSuya = (int) $cuenta['usuario_id'] === $actorId;

        if ($esSuya) {
            Autorizacion::exigir('tarjeta.debito_propia');
        } else {
            Autorizacion::exigir('tarjeta.emitir');
        }

        $marca = $this->post('marca', 'visa');

        try {
            $r = (new EmisorTarjeta())->emitir(
                (int) $cuenta['usuario_id'],
                $marca,
                'debito',
                \CashMan\Dominio\Comun\Monto::cero(),
                (int) BaseDatos::unValor(
                    'SELECT id FROM productos WHERE codigo = "TAR-DEB" LIMIT 1'
                ) ?: null,
                $actorId,
                ['cuenta_id' => $cuentaId, 'origen' => 'cuenta']
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $this->responderSegunCliente(
            true,
            'Tarjeta de débito ••••' . ($r['ultimos4'] ?? '')
            . ' emitida y ligada a la cuenta ' . $cuenta['numero_cuenta']
            . '. Ya puede usarla; el saldo sale de esta cuenta.',
            $destino,
            $r
        );
    }
    public function movimientos(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $todas     = Autorizacion::puede('cuenta.ver_todas');

        $condiciones = ['c.es_tecnica = 0'];
        $args        = [];

        if (!$todas) {
            $condiciones[] = 'c.usuario_id = ?';
            $args[]        = $usuarioId;
        }

        $tipo  = $this->get('tipo');
        $desde = $this->get('desde');
        $hasta = $this->get('hasta');
        $q     = $todas ? $this->get('q') : '';

        if ($tipo !== '' && preg_match('/^[a-z_]{3,30}$/', $tipo) === 1) {
            $condiciones[] = 'a.tipo = ?';
            $args[]        = $tipo;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) === 1) {
            $condiciones[] = 'm.creado_en >= ?';
            $args[]        = $desde . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) === 1) {
            $condiciones[] = 'm.creado_en <= ?';
            $args[]        = $hasta . ' 23:59:59';
        }
        if ($q !== '') {
            $condiciones[] = '(c.numero_cuenta LIKE ? OR CONCAT(u.nombres," ",u.apellidos) LIKE ?
                               OR a.referencia LIKE ?)';
            $like = '%' . $q . '%';
            $args[] = $like; $args[] = $like; $args[] = $like;
        }

        $donde = implode(' AND ', $condiciones);

        $total = (int) BaseDatos::unValor(
            "SELECT COUNT(*)
               FROM movimientos m
               JOIN asientos a ON a.id = m.asiento_id
               JOIN cuentas  c ON c.id = m.cuenta_id
               JOIN usuarios u ON u.id = c.usuario_id
              WHERE {$donde}",
            $args
        );

        // Recorta la página al rango existente: un `?p=999` devolvía una
        // tabla vacía en lugar de la última página.
        $pag    = new Paginador($total, $this->get('p', '1'), $this->get('pp', '50'));
        $porPag = $pag->porPagina;
        $offset = $pag->desplazamiento;

        $filas = BaseDatos::todas(
            "SELECT m.importe_cent, m.descripcion, m.saldo_posterior_cent, m.creado_en,
                    a.referencia, a.tipo, a.id AS asiento_id, c.numero_cuenta,
                    CONCAT(u.nombres,' ',u.apellidos) AS titular
               FROM movimientos m
               JOIN asientos a ON a.id = m.asiento_id
               JOIN cuentas  c ON c.id = m.cuenta_id
               JOIN usuarios u ON u.id = c.usuario_id
              WHERE {$donde}
              ORDER BY m.id DESC
              LIMIT {$porPag} OFFSET {$offset}",
            $args
        );

        // Totales del periodo filtrado: entradas, salidas y neto.
        $resumen = BaseDatos::unaFila(
            "SELECT
                COALESCE(SUM(CASE WHEN m.importe_cent > 0 THEN m.importe_cent END), 0) AS entradas,
                COALESCE(SUM(CASE WHEN m.importe_cent < 0 THEN -m.importe_cent END), 0) AS salidas
               FROM movimientos m
               JOIN asientos a ON a.id = m.asiento_id
               JOIN cuentas  c ON c.id = m.cuenta_id
               JOIN usuarios u ON u.id = c.usuario_id
              WHERE {$donde}",
            $args
        ) ?? ['entradas' => 0, 'salidas' => 0];

        foreach ($filas as &$fm) {
            $fm['comprobante'] = Hashid::codificar((int) $fm['asiento_id'], 'asiento');
            unset($fm['asiento_id']);
        }
        unset($fm);

        if ($this->esperaJson()) {
            Respuesta::jsonConEtag(['movimientos' => $filas, 'total' => $total, 'pagina' => $pag->pagina]);
        }

        $tipos = BaseDatos::todas(
            'SELECT DISTINCT tipo FROM asientos ORDER BY tipo'
        );

        $this->render('cuentas/movimientos', [
            'titulo'      => 'Movimientos · CashMan H.A.',
            'encabezado'  => $todas ? 'Movimientos de la institución' : 'Mis movimientos',
            'movimientos' => $filas,
            'total'       => $total,
            'pagina'      => $pag->pagina,
            'paginas'     => $pag->paginas,
            'paginacion'  => $pag->aArreglo(),
            'vistaTodas'  => $todas,
            'resumen'     => [
                'entradas' => (int) $resumen['entradas'],
                'salidas'  => (int) $resumen['salidas'],
            ],
            'tipos'       => array_column($tipos, 'tipo'),
            'filtros'     => ['tipo' => $tipo, 'desde' => $desde, 'hasta' => $hasta, 'q' => $q],
        ]);
    }
    /**
     * Comprobante de una operación.
     *
     * Es el `modelo-facturacion-transacciones-cuentas-clientes` del
     * sistema anterior. El generador ya existía en ServicioDocumentos
     * desde el principio, pero no había ninguna ruta que lo llamara:
     * era código escrito y muerto.
     *
     * El asiento se identifica por su referencia, que es lo que el
     * cliente ve en su listado de movimientos, y se comprueba que
     * alguna de las cuentas implicadas sea suya antes de emitirlo.
     */
    public function comprobante(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'asiento');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Operación no encontrada.');
        }

        // Propiedad verificada ANTES de generar nada: el comprobante
        // lleva el nombre del titular y los saldos, así que no puede
        // emitirse para una operación ajena.
        /* `es_tecnica = 0` no es un detalle: es lo que impide que esto
         * se convierta en una llave maestra.
         *
         * Las cuentas técnicas están a nombre de un usuario real —el
         * primero que se creó— porque la tabla exige titular. Sin esta
         * condición, ese usuario salía como dueño de TODA operación que
         * tocara una técnica, que son el depósito, el retiro, el cobro
         * por pasarela, el pago de cuota y el adelanto de cualquier
         * cliente del banco: podía descargarse el comprobante de todos,
         * con su nombre y sus saldos dentro. */
        $propia = (int) BaseDatos::unValor(
            'SELECT COUNT(*)
               FROM movimientos m
               JOIN cuentas c ON c.id = m.cuenta_id
              WHERE m.asiento_id = ? AND c.usuario_id = ? AND c.es_tecnica = 0',
            [$id, $usuarioId]
        );

        /* También es suya la operación que pagó con tarjeta de otro
         * banco.
         *
         * Ese asiento va entre dos cuentas técnicas —el dinero vino de
         * fuera y se fue a extinguir deuda—, así que ninguna cuenta del
         * cliente aparece en él y la comprobación de arriba lo daba por
         * ajeno: el cliente pagaba su cuota y después no podía sacar el
         * comprobante de su propio pago. */
        if ($propia === 0) {
            $propia = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM pagos_pasarela
                  WHERE asiento_id = ? AND usuario_id = ?',
                [$id, $usuarioId]
            );
        }

        /* Y la cuota que pagó en la ventanilla.
         *
         * Mismo caso que el de arriba y por la misma razón: cuando el
         * cliente paga en efectivo o con cheque, el dinero no sale de
         * ninguna cuenta suya, así que el asiento va entre dos cuentas
         * técnicas y la comprobación de propiedad no encuentra nada que
         * sea de él. Resultado: pagaba su cuota en la caja, el cajero
         * le decía que ahí tenía su comprobante, y al pulsarlo le salía
         * «Operación no encontrada».
         *
         * La cuota sí sabe de quién es. Se pregunta por ahí. */
        if ($propia === 0) {
            $propia = (int) BaseDatos::unValor(
                'SELECT COUNT(*)
                   FROM cuotas q JOIN creditos c ON c.id = q.credito_id
                  WHERE q.asiento_id = ? AND c.usuario_id = ?',
                [$id, $usuarioId]
            );
        }

        /* Lo mismo con un movimiento de tarjeta: el consumo de una
           tarjeta de crédito tampoco toca la cuenta de ahorro. */
        if ($propia === 0) {
            $propia = (int) BaseDatos::unValor(
                'SELECT COUNT(*)
                   FROM tarjeta_movimientos tm JOIN tarjetas t ON t.id = tm.tarjeta_id
                  WHERE tm.asiento_id = ? AND t.usuario_id = ?',
                [$id, $usuarioId]
            );
        }

        /* Dos llaves distintas abren esto, y a propósito.
         *
         * `cuenta.ver_todas` es la de siempre: quien administra las
         * cuentas de la institución puede emitir cualquier
         * comprobante.
         *
         * `comprobante.ver_todos` es más estrecha y existe para la
         * Junta Directiva: deja abrir EL COMPROBANTE de una operación
         * sin abrir el resto del expediente del cliente. Un director
         * que ve un movimiento raro en el reporte tiene que poder
         * verificarlo —eso es su trabajo— sin que para ello haya que
         * darle acceso a todas las cuentas del banco.
         *
         * El 404 en vez de un 403 también es deliberado: un «no tiene
         * permiso» confirmaría que esa operación existe, y con eso se
         * pueden sondear referencias. */
        if ($propia === 0
            && !Autorizacion::puedeAlguno(['cuenta.ver_todas', 'comprobante.ver_todos'])) {
            Auditoria::registrar('comprobante.acceso_denegado', Auditoria::DENEGADO,
                $usuarioId, 'asiento', (string) $id);
            throw new RecursoNoEncontrado('Operación no encontrada.');
        }

        try {
            $doc = (new \CashMan\Infraestructura\Documento\ServicioDocumentos())
                ->comprobante($id);
        } catch (\RuntimeException $e) {
            throw new RecursoNoEncontrado($e->getMessage());
        }

        Auditoria::registrar('comprobante.emitido', Auditoria::EXITO,
            $usuarioId, 'asiento', (string) $id);

        Respuesta::ver($doc['ruta'], $doc['nombre']);
    }
}
