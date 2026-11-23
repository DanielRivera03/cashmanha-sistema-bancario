<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\CarritoTienda;
use CashMan\Aplicacion\Servicio\ServicioPasarela;
use CashMan\Aplicacion\Servicio\RedRechazada;
use CashMan\Aplicacion\Servicio\ServicioTienda;
use CashMan\Http\Enrutador;
use CashMan\Http\Plantilla;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Pasarela\PasarelaError;
use CashMan\Infraestructura\Pasarela\Stripe;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * La tienda, de cara al público.
 *
 * Nació dentro de la banca en línea y ahí no servía de mucho: para
 * comprar había que ser cliente y haber iniciado sesión. Una tienda de
 * comercios afiliados funciona justo al revés —cualquiera entra, ve el
 * catálogo y compra—, y si además resulta ser cliente, mejor: se le
 * reconoce, se le llenan sus datos y puede pagar con la tarjeta que ya
 * tiene en lugar de teclear otra.
 *
 * Nada de lo que hay aquí exige sesión. Lo que sí se exige, en cada
 * operación que escribe, es el token CSRF: que la tienda sea pública no
 * significa que acepte peticiones de cualquier sitio.
 */
final class ControladorTiendaPublica extends ControladorBase
{
    private CarritoTienda $carrito;

    public function __construct(Enrutador $enrutador)
    {
        parent::__construct($enrutador);
        $this->carrito = new CarritoTienda();
    }

    // =================================================================
    // CATÁLOGO
    // =================================================================

    public function catalogo(array $parametros = []): void
    {
        $busca    = $this->get('q');
        $comercio = $this->get('c');
        $orden    = $this->get('orden');

        $donde = ['a.activo = 1', 'c.activo = 1'];
        $args  = [];

        if ($busca !== '') {
            $donde[] = '(a.nombre LIKE ? OR a.descripcion LIKE ? OR a.marca LIKE ?)';
            $like = '%' . $busca . '%';
            array_push($args, $like, $like, $like);
        }

        $comercioId = $comercio !== '' ? Hashid::decodificar($comercio, 'comercio') : null;

        if ($comercioId !== null) {
            $donde[] = 'c.id = ?';
            $args[]  = $comercioId;
        }

        // El orden lo elige quien compra, pero entre opciones cerradas:
        // interpolar un `ORDER BY` que llegue por la URL es abrir la
        // puerta a inyección por el sitio más tonto.
        $porOrden = match ($orden) {
            'barato' => 'a.precio_cent ASC',
            'caro'   => 'a.precio_cent DESC',
            'oferta' => '(a.antes_cent > a.precio_cent) DESC, a.precio_cent ASC',
            'nombre' => 'a.nombre ASC',
            default  => 'c.orden ASC, a.nombre ASC',
        };

        $articulos = BaseDatos::todas(
            'SELECT a.id, a.sku, a.nombre, a.descripcion, a.marca, a.categoria,
                    a.precio_cent, a.antes_cent, a.existencias, a.imagen, a.imagen_alt,
                    a.imagen_credito, a.imagen_origen,
                    c.id AS comercio_id, c.nombre AS comercio, c.iniciales, c.color,
                    c.cuotas_sin_intereses, c.envio_cent
               FROM articulos a JOIN comercios c ON c.id = a.comercio_id
              WHERE ' . implode(' AND ', $donde) . '
              ORDER BY ' . $porOrden . '
              LIMIT 200',
            $args
        );

        foreach ($articulos as &$a) {
            $a['codigo']          = Hashid::codificar((int) $a['id'], 'articulo');
            $a['comercio_codigo'] = Hashid::codificar((int) $a['comercio_id'], 'comercio');
            unset($a['id'], $a['comercio_id']);
        }
        unset($a);

        $this->renderTienda('tienda/publico/catalogo', [
            'titulo'      => 'Tienda · CashMan H.A.',
            'descripcion' => 'Compre en los comercios afiliados a CashMan H.A. y pague '
                           . 'con su tarjeta o con la de cualquier banco.',
            'articulos'   => $articulos,
            'comercios'   => $this->comercios(),
            'busca'       => $busca,
            'comercioSel' => $comercio,
            'orden'       => $orden,
        ]);
    }

    public function articulo(array $parametros = []): void
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'articulo');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Ese artículo no existe.');
        }

        $a = BaseDatos::unaFila(
            'SELECT a.*, c.id AS comercio_id, c.nombre AS comercio, c.rubro,
                    c.iniciales, c.color, c.cuotas_sin_intereses, c.envio_cent,
                    c.descripcion AS comercio_descripcion
               FROM articulos a JOIN comercios c ON c.id = a.comercio_id
              WHERE a.id = ? AND a.activo = 1 AND c.activo = 1',
            [$id]
        );

        if ($a === null) {
            throw new RecursoNoEncontrado('Ese artículo ya no está disponible.');
        }

        // Del mismo comercio, para que quien no encontró lo que buscaba
        // tenga a dónde seguir en lugar de un callejón sin salida.
        $relacionados = BaseDatos::todas(
            'SELECT a.id, a.nombre, a.precio_cent, a.antes_cent, a.imagen, a.imagen_alt,
                    a.imagen_credito,
                    c.nombre AS comercio, c.iniciales, c.color
               FROM articulos a JOIN comercios c ON c.id = a.comercio_id
              WHERE a.comercio_id = ? AND a.id <> ? AND a.activo = 1 AND a.existencias > 0
              ORDER BY RAND() LIMIT 4',
            [(int) $a['comercio_id'], $id]
        );

        foreach ($relacionados as &$r) {
            $r['codigo'] = Hashid::codificar((int) $r['id'], 'articulo');
            unset($r['id']);
        }
        unset($r);

        $a['codigo']          = Hashid::codificar($id, 'articulo');
        $a['comercio_codigo'] = Hashid::codificar((int) $a['comercio_id'], 'comercio');
        unset($a['id'], $a['comercio_id']);

        $this->renderTienda('tienda/publico/articulo', [
            'titulo'       => $a['nombre'] . ' · Tienda CashMan H.A.',
            'descripcion'  => mb_substr((string) ($a['descripcion'] ?? ''), 0, 180),
            'articulo'     => $a,
            'relacionados' => $relacionados,
            'comercios'    => $this->comercios(),
        ]);
    }

    // =================================================================
    // CARRITO
    // =================================================================

    public function verCarrito(array $parametros = []): void
    {
        $detalle = $this->carrito->detalle();

        $this->renderTienda('tienda/publico/carrito', [
            'titulo'    => 'Su carrito · Tienda CashMan H.A.',
            'carrito'   => $detalle,
            'comercios' => $this->comercios(),
            'csrf'      => Csrf::token('tienda_publica'),
        ]);
    }

    public function agregar(array $parametros = []): void
    {
        $id = $this->idDesde($this->post('articulo'), 'articulo');

        if ($id === 0) {
            $this->responderSegunCliente(
                false,
                'No reconocimos ese artículo.',
                $this->enrutador->url('tienda'),
                null,
                [],
                422
            );
            return;
        }

        $cantidad = max(1, $this->postEntero('cantidad', 1));

        $this->carrito->agregar($id, $cantidad);

        $detalle = $this->carrito->detalle();

        $this->responderSegunCliente(
            true,
            'Lo agregamos a su carrito.',
            $this->enrutador->url('tienda/carrito'),
            ['unidades' => $detalle['unidades'], 'total' => $detalle['total_cent']]
        );
    }

    public function actualizarCarrito(array $parametros = []): void
    {
        foreach ((array) ($_POST['cant'] ?? []) as $codigo => $cantidad) {
            $id = Hashid::decodificar((string) $codigo, 'articulo');

            if ($id !== null) {
                $this->carrito->fijar($id, (int) $cantidad);
            }
        }

        // El botón «Quitar» de cada línea llega por aquí mismo.
        //
        // Se atiende DESPUÉS de las cantidades a propósito: si alguien
        // cambió un número y además quitó otra línea, las dos cosas
        // valen. Al revés, el artículo quitado reviviría con la cantidad
        // que traía el formulario.
        $quitar = Hashid::decodificar((string) ($_POST['quitar'] ?? ''), 'articulo');

        if ($quitar !== null) {
            $this->carrito->fijar($quitar, 0);

            $this->responderSegunCliente(
                true,
                'Lo quitamos de su carrito.',
                $this->enrutador->url('tienda/carrito')
            );
            return;
        }

        $this->responderSegunCliente(
            true,
            'Actualizamos su carrito.',
            $this->enrutador->url('tienda/carrito')
        );
    }

    public function vaciar(array $parametros = []): void
    {
        $this->carrito->vaciar();

        $this->responderSegunCliente(
            true,
            'Su carrito quedó vacío.',
            $this->enrutador->url('tienda')
        );
    }

    // =================================================================
    // PAGAR
    // =================================================================

    public function pagar(array $parametros = []): void
    {
        $detalle = $this->carrito->detalle();

        if ($detalle['lineas'] === []) {
            Respuesta::redirigir($this->enrutador->url('tienda'));
            return;
        }

        /* Si hay sesión, los datos se llenan solos.
         *
         * Pedirle el nombre y el correo a alguien que acaba de iniciar
         * sesión es hacerle teclear lo que ya sabemos, y encima da pie a
         * que lo escriba distinto y acabemos con dos registros de la
         * misma persona. */
        $usuarioId = Sesion::usuarioId();
        $cliente   = null;
        $tarjetas  = [];

        if ($usuarioId !== null) {
            $cliente = BaseDatos::unaFila(
                'SELECT u.nombres, u.apellidos, u.correo, u.telefono, u.documento_identidad, u.tipo_documento,
                        d.direccion
                   FROM usuarios u
                   LEFT JOIN detalle_usuarios d ON d.usuario_id = u.id
                  WHERE u.id = ?',
                [$usuarioId]
            );

            $tarjetas = BaseDatos::todas(
                'SELECT id, ultimos4, marca, limite_cent, saldo_usado_cent,
                        sobregiro_permitido, sobregiro_tolerancia_bp
                   FROM tarjetas
                  WHERE usuario_id = ? AND tipo = "credito" AND estado = "activa"
                  ORDER BY id',
                [$usuarioId]
            );

            foreach ($tarjetas as &$t) {
                $t['codigo'] = Hashid::codificar((int) $t['id'], 'tarjeta');
                $t['disponible_cent'] = max(0, (int) $t['limite_cent'] - (int) $t['saldo_usado_cent']);
                $t['tope_cent'] = $t['disponible_cent'] + ((int) $t['sobregiro_permitido'] === 1
                    ? (int) round((int) $t['limite_cent'] * (int) $t['sobregiro_tolerancia_bp'] / 10000)
                    : 0);
                unset($t['id']);
            }
            unset($t);
        }

        $this->renderTienda('tienda/publico/pagar', [
            'titulo'   => 'Finalizar su compra · Tienda CashMan H.A.',
            'carrito'  => $detalle,
            'cliente'  => $cliente,
            'tarjetas' => $tarjetas,
            'pasarela' => Stripe::habilitado(),
            'esPrueba' => Stripe::esPrueba(),
            'publica'  => Stripe::clavePublica(),
            'comercios'=> $this->comercios(),
            // El tope de compras diferidas se dice antes de elegir
            // el plazo, no al rechazar la compra.
            'topePlanes' => \CashMan\Aplicacion\Servicio\ServicioTarjeta::topePlanes(),
            'csrf'     => Csrf::token('tienda_publica'),
        ], false);
    }

    /** Compra pagada con una tarjeta emitida por la institución. */
    public function pagarConTarjeta(array $parametros = []): void
    {
        $destino = $this->enrutador->url('tienda/pagar');

        try {
            $r = (new ServicioTienda())->comprar([
                'tarjeta_id' => $this->idDesde($this->post('tarjeta'), 'tarjeta'),
                'lineas'     => $this->carrito->lineas(),
                'cuotas'     => $this->postEntero('cuotas'),
                'direccion'  => $this->post('direccion'),
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $this->carrito->vaciar();

        $url = $this->enrutador->url('tienda/pedido/' . $r['folio']);

        $this->responderSegunCliente(
            true,
            '¡Gracias por su compra! Su pedido ' . $r['folio'] . ' quedó registrado.',
            $url,
            $r + ['redirigir' => $url]
        );
    }

    /**
     * Compra tecleando el número de la tarjeta, como en cualquier tienda.
     *
     * No pide sesión: lo que autoriza la compra es tener la tarjeta, y
     * eso es lo que demuestran el número, el vencimiento y el CVV. Es
     * también lo que convierte en útiles los números que el banco
     * entrega: hasta ahora una tarjeta de CashManHA solo servía
     * eligiéndola de una lista dentro de la banca de su titular.
     */
    public function pagarTecleando(array $parametros = []): void
    {
        $destino = $this->enrutador->url('tienda/pagar');

        try {
            $r = (new ServicioTienda())->comprarConNumero([
                'numero'    => $this->post('numero'),
                'cvv'       => $this->post('cvv'),
                'mes'       => $this->postEntero('mes'),
                'anio'      => $this->postEntero('anio'),
                'lineas'    => $this->carrito->lineas(),
                'cuotas'    => $this->postEntero('cuotas'),
                'direccion' => $this->post('direccion'),
            ]);
        } catch (RedRechazada $e) {
            /* El código de la red viaja aparte del texto: la pantalla
               decide con él si ofrecer otra tarjeta o solo reintentar,
               sin tener que leer el mensaje. */
            $this->responderSegunCliente(
                false,
                $e->getMessage(),
                $destino,
                null,
                ['codigo' => $e->codigo, 'ultimos4' => $e->ultimos4],
                402
            );
            return;
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $this->carrito->vaciar();

        $url = $this->enrutador->url('tienda/pedido/' . $r['folio']);

        $this->responderSegunCliente(
            true,
            '¡Gracias por su compra! Su pedido ' . $r['folio'] . ' quedó registrado.',
            $url,
            $r + ['redirigir' => $url]
        );
    }

    /**
     * Aparta el pedido y prepara el cobro por la pasarela.
     *
     * APRETAR DOS VECES NO COBRA DOS VECES
     *
     * Esta ruta aparta inventario y abre un intento de cobro. Sin
     * ninguna protección, cada clic en «Finalizar compra» abría un
     * juego de pedidos nuevo y retenía otra vez la mercadería: dos
     * clics nerviosos en una pantalla que tarda, y el catálogo dice
     * que no hay existencias de algo que sí está en bodega, con dos
     * intentos de cobro vivos sobre la misma tarjeta.
     *
     * Así que el intento se recuerda en la sesión junto con una huella
     * del carrito. Si vuelven con el mismo carrito y el intento sigue
     * en pie, se les devuelve EL MISMO, sin apartar nada más. Si el
     * carrito cambió, es otra compra y se empieza de nuevo.
     */
    public function iniciarPasarela(array $parametros = []): void
    {
        $destino = $this->enrutador->url('tienda/pagar');

        /* La huella: qué se lleva y cuánto. Si cambia cualquiera de las
           dos cosas, ya no es el mismo cobro. */
        $lineas = $this->carrito->lineas();

        ksort($lineas);

        $huella = hash('sha256', json_encode($lineas) . '|' . $this->postEntero('cuotas'));

        $enCurso = $_SESSION['tienda_cobro'] ?? null;

        if (is_array($enCurso)
            && ($enCurso['huella'] ?? '') === $huella
            && $this->cobroSigueVivo((int) ($enCurso['pedido_id'] ?? 0))) {

            Respuesta::exito(
                $enCurso['respuesta'] ?? [],
                'Retomamos el cobro que ya había empezado. Complete los datos de su tarjeta.'
            );
            return;
        }

        /* La huella no coincide, o el intento anterior ya no sirve: si
           quedó mercadería apartada de aquél, se devuelve antes de
           apartar otra vez. */
        if (is_array($enCurso) && (int) ($enCurso['pedido_id'] ?? 0) > 0) {
            (new ServicioTienda())->liberar(
                (int) $enCurso['pedido_id'],
                'El comprador cambió su carrito antes de pagar.'
            );

            unset($_SESSION['tienda_cobro']);
        }

        try {
            $pedido = (new ServicioTienda())->apartarParaPasarela([
                'lineas'    => $this->carrito->lineas(),
                'cuotas'    => $this->postEntero('cuotas'),
                'direccion' => $this->post('direccion'),
                'nombre'    => $this->post('nombre'),
                'correo'    => $this->post('correo'),
                'telefono'  => $this->post('telefono'),
                'documento' => $this->post('documento'),
                'tipo_documento' => $this->post('tipo_documento'),
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        try {
            $cobro = (new ServicioPasarela())->iniciar(
                Sesion::usuarioId(),
                'tienda',
                (int) $pedido['total_cent'],
                ['pedido_id' => (int) $pedido['pedido_id']]
            );
        } catch (PasarelaError $e) {
            // El pedido ya apartó existencias: si el cobro ni siquiera se
            // pudo preparar, se devuelven en el acto.
            (new ServicioTienda())->liberar(
                (int) $pedido['pedido_id'],
                'No se pudo preparar el cobro: ' . $e->getMessage()
            );

            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 502);
            return;
        }

        /* Con varias tiendas el cobro es uno y los pedidos son varios.
           Quien compra tiene que salir de aquí con los folios y las
           claves de TODOS: cada tienda despacha por su cuenta y cada
           pedido se consulta con la suya. */
        $varios = (int) ($pedido['comercios'] ?? 1) > 1;

        $extra = [];

        if ($varios) {
            $extra = [
                'pedidos' => array_map(
                    fn(array $p): array => [
                        'folio'    => $p['folio'],
                        'clave'    => $p['clave'],
                        'comercio' => $p['comercio'],
                        'total'    => $p['total']['formateado'],
                        'cuotas'   => $p['cuotas'],
                        'url'      => $this->enrutador->url('tienda/pedido/' . $p['folio']),
                    ],
                    $pedido['pedidos']
                ),
                'comercios' => (int) $pedido['comercios'],
            ];
        }

        $datos = [
            'secreto_cliente'    => $cobro['secreto'],
            'referencia'         => $cobro['referencia'],
            'folio'              => $pedido['folio'],
            'clave'              => $pedido['clave'],
            'importe_formateado' => $pedido['total']['formateado'],
            'seguimiento'        => $this->enrutador->url('tienda/pedido/' . $pedido['folio']),
        ] + $extra;

        /* Queda apuntado para que un segundo clic lo retome en vez de
           apartar inventario otra vez. Se borra al confirmarse el
           cobro, en `confirmarPasarela()`. */
        $_SESSION['tienda_cobro'] = [
            'huella'     => $huella,
            'pedido_id'  => (int) $pedido['pedido_id'],
            'referencia' => $cobro['referencia'],
            'respuesta'  => $datos,
            'creado'     => time(),
        ];

        Respuesta::exito($datos, $varios
            ? 'Cobro preparado por las ' . (int) $pedido['comercios']
              . ' tiendas. Complete los datos de su tarjeta.'
            : 'Cobro preparado. Complete los datos de su tarjeta.');
    }

    /**
     * Cierra el cobro de la tienda.
     *
     * Entre que la pasarela acepta la tarjeta y que el pedido queda
     * contabilizado hace falta que alguien de este lado lo aplique.
     * Eso lo hacía solo el aviso de Stripe, que a una instalación sin
     * dirección pública no llega nunca: el comprador veía «pago
     * aceptado», el pedido se quedaba apartado y el cobro en el aire.
     *
     * No lleva sesión, como el resto de la tienda, y no hace falta:
     * esta ruta no decide nada. Le pregunta a Stripe por ESE intento y
     * solo aplica si la propia pasarela dice que está cobrado. Lo que
     * manda el navegador es, como mucho, cuál mirar.
     */
    public function confirmarPasarela(array $parametros = []): void
    {
        $intento = $this->post('intento');

        if (!preg_match('/^pi_[A-Za-z0-9_]+$/', $intento)) {
            Respuesta::error('Referencia de cobro no válida.', 422);
            return;
        }

        $pago = BaseDatos::unaFila(
            'SELECT id, estado, concepto FROM pagos_pasarela WHERE referencia = ?',
            [$intento]
        );

        // Solo cobros de la tienda: esta ruta es pública, y con ella no
        // se cierra el pago de la cuota ni el saldo de nadie.
        if ($pago === null || (string) $pago['concepto'] !== 'tienda') {
            Respuesta::error('Ese cobro no corresponde a un pedido de la tienda.', 422);
            return;
        }

        if (in_array((string) $pago['estado'], ['cobrado', 'aplicado'], true)) {
            /* Si se vuelve aquí con un cobro ya aplicado —se recargó la
               pantalla, llegó antes el aviso de Stripe—, el carrito
               tiene que quedar vacío igual. */
            $this->carrito->vaciar();
            unset($_SESSION['tienda_cobro']);

            Respuesta::exito(['ya' => true], 'Este pago ya estaba aplicado.');
            return;
        }

        try {
            $estado = (new Stripe())->consultarIntento($intento);
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

        /* Aquí el dinero YA salió de la tarjeta. Si la aplicación
           fallara no se le puede pedir al comprador que reintente
           —pagaría dos veces—: se le confirma lo recibido y el barrido
           de conciliación lo termina. */
        /* El carrito se vacía AQUÍ, no al salir bien de todo.
         *
         * En este punto el dinero ya salió de la tarjeta: lo dijo la
         * propia pasarela dos líneas más arriba. Si el carrito se
         * quedara lleno, el comprador vuelve a la tienda, ve sus
         * artículos ahí y aprieta «Finalizar compra» otra vez. Paga
         * dos veces por lo mismo, y la segunda nadie la pidió.
         *
         * Por eso va antes del `try`: aunque la aplicación interna
         * falle y haya que terminarla con el barrido de conciliación,
         * el cobro está hecho y el carrito ya no es suyo. */
        $this->carrito->vaciar();
        unset($_SESSION['tienda_cobro']);

        try {
            $detalle = (new ServicioPasarela())->aplicarCobroConfirmado($intento);
        } catch (\Throwable $e) {
            error_log('[Tienda] cobro ' . $intento . ' sin aplicar: ' . $e->getMessage());

            Respuesta::exito(
                ['pendiente' => true],
                'Su pago se recibió correctamente. El pedido quedará confirmado en breve.'
            );
            return;
        }

        Respuesta::exito(['detalle' => $detalle], 'Pago aplicado. ' . $detalle);
    }

    /**
     * ¿El cobro que empezamos antes sigue en pie?
     *
     * Sirve mientras sus pedidos sigan apartados: en cuanto se pagan
     * —o se liberan porque la tarjeta no pasó— ese intento ya no vale
     * y hay que empezar de nuevo. Se mira el grupo entero, que es como
     * se cobran las compras de varias tiendas.
     */
    private function cobroSigueVivo(int $pedidoId): bool
    {
        if ($pedidoId <= 0) { return false; }

        $grupo = BaseDatos::unValor(
            'SELECT grupo_pago FROM tienda_pedidos WHERE id = ?',
            [$pedidoId]
        );

        if ($grupo !== null && (string) $grupo !== '') {
            $vivos = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM tienda_pedidos
                  WHERE grupo_pago = ? AND estado = "pago_pendiente"',
                [(string) $grupo]
            );

            return $vivos > 0;
        }

        return (string) BaseDatos::unValor(
            'SELECT estado FROM tienda_pedidos WHERE id = ?',
            [$pedidoId]
        ) === 'pago_pendiente';
    }

    /**
     * La puerta de entrada al seguimiento.
     *
     * Hasta aquí, para ver un pedido había que tener el enlace: el que
     * salió en pantalla al comprar o el del correo. Quien cerró la
     * pestaña y borró el correo se quedaba fuera aunque tuviera su
     * folio y su clave apuntados.
     *
     * Esta pantalla no consulta nada ni dice si un folio existe —eso
     * sería regalarle medio dato a quien prueba folios al azar—: solo
     * recoge folio y clave y lleva a la pantalla del pedido, que es la
     * que comprueba de verdad.
     */
    public function seguimiento(array $parametros = []): void
    {
        $this->renderTienda('tienda/publico/seguimiento', [
            'titulo'    => 'Seguimiento de su pedido · Tienda CashMan H.A.',
            'folio'     => strtoupper(trim($this->get('folio'))),
            'comercios' => $this->comercios(),
            'csrf'      => Csrf::token('tienda_publica'),
        ], false);
    }

    /**
     * Seguimiento de un pedido.
     *
     * Con el folio solo no basta: quien adivinara uno vería el pedido de
     * otra persona, con su nombre y su dirección. Se exige la clave que
     * se entregó al comprar, salvo que el pedido sea de quien está dentro
     * con su sesión.
     */
    public function pedido(array $parametros = []): void
    {
        $folio = trim((string) ($parametros['folio'] ?? ''));

        $p = BaseDatos::unaFila(
            'SELECT p.*, c.nombre AS comercio, c.iniciales, c.color,
                    t.ultimos4, t.marca
               FROM tienda_pedidos p
               JOIN comercios c ON c.id = p.comercio_id
               LEFT JOIN tarjetas t ON t.id = p.tarjeta_id
              WHERE p.folio = ?',
            [$folio]
        );

        if ($p === null) {
            throw new RecursoNoEncontrado('No encontramos ese pedido.');
        }

        $suyo = Sesion::usuarioId() !== null
            && (int) ($p['usuario_id'] ?? 0) === Sesion::usuarioId();

        $clave = $this->get('clave') !== '' ? $this->get('clave') : $this->post('clave');

        $autorizado = $suyo
            || ($p['clave_consulta'] !== null
                && hash_equals((string) $p['clave_consulta'], strtoupper(trim($clave))));

        if (!$autorizado) {
            $this->renderTienda('tienda/publico/pedido_clave', [
                'titulo'    => 'Consultar su pedido · Tienda CashMan H.A.',
                'folio'     => $folio,
                'intento'   => $clave !== '',
                'comercios' => $this->comercios(),
                'csrf'      => Csrf::token('tienda_publica'),
            ], false);
            return;
        }

        $lineas = BaseDatos::todas(
            'SELECT nombre, precio_cent, cantidad FROM tienda_pedido_lineas
              WHERE pedido_id = ? ORDER BY id',
            [(int) $p['id']]
        );

        /* Los otros pedidos del mismo cobro.
         *
         * Un carrito con dos tiendas se cobra una sola vez pero abre un
         * pedido por tienda. Quien llega aquí desde el correo o desde el
         * pago ve uno solo, y el otro —con su propio envío y su propia
         * fecha de entrega— quedaría invisible. Se listan al lado, con
         * su clave: es la única pantalla donde vuelve a aparecer.
         *
         * La clave de los hermanos solo se enseña a quien ya demostró
         * tener la de este pedido, o a su dueño con la sesión abierta.
         * Es la misma puerta, no una más ancha. */
        $hermanos = [];

        if ($p['grupo_pago'] !== null && (string) $p['grupo_pago'] !== '') {
            $hermanos = BaseDatos::todas(
                'SELECT p.folio, p.clave_consulta, p.total_cent, p.estado,
                        c.nombre AS comercio, c.iniciales, c.color
                   FROM tienda_pedidos p
                   JOIN comercios c ON c.id = p.comercio_id
                  WHERE p.grupo_pago = ? AND p.id <> ?
                  ORDER BY p.id',
                [(string) $p['grupo_pago'], (int) $p['id']]
            );
        }

        $this->renderTienda('tienda/publico/pedido', [
            'titulo'    => 'Pedido ' . $p['folio'] . ' · Tienda CashMan H.A.',
            'pedido'    => $p,
            'lineas'    => $lineas,
            'hermanos'  => $hermanos,
            'comercios' => $this->comercios(),
        ], false);
    }

    // -----------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function comercios(): array
    {
        $filas = BaseDatos::todas(
            'SELECT c.id, c.nombre, c.rubro, c.descripcion, c.iniciales, c.color,
                    c.cuotas_sin_intereses, c.envio_cent,
                    (SELECT COUNT(*) FROM articulos a
                      WHERE a.comercio_id = c.id AND a.activo = 1) AS articulos
               FROM comercios c WHERE c.activo = 1 ORDER BY c.orden'
        );

        foreach ($filas as &$c) {
            $c['codigo'] = Hashid::codificar((int) $c['id'], 'comercio');
            unset($c['id']);
        }
        unset($c);

        return $filas;
    }

    /**
     * El resultado de la acción anterior, si lo hubo.
     *
     * Tras cada escritura se redirige con el mensaje en la URL (`ok=` o
     * `e=`). Eso evita que recargar repita la compra, pero el mensaje no
     * se pintaba en ninguna parte: agregar al carrito, quitar un
     * artículo o fallar un pago devolvían la página sin decir nada y
     * había que deducir qué pasó mirando la lista.
     *
     * El texto viene de la URL, así que cualquiera puede poner lo que
     * quiera en ella. Se escapa antes de imprimirlo y se recorta: no es
     * un sitio donde escribir un párrafo.
     */
    protected function avisoDeLaUrl(): string
    {
        $ok    = trim((string) ($_GET['ok'] ?? ''));
        $error = trim((string) ($_GET['e'] ?? ''));

        $texto = $ok !== '' ? $ok : $error;

        if ($texto === '') {
            return '';
        }

        $texto = mb_substr($texto, 0, 180);

        return '<div class="tda-flash ' . ($ok !== '' ? 'tda-flash--ok' : 'tda-flash--mal')
            . '" role="status">' . Plantilla::e($texto) . '</div>';
    }

    /**
     * Dibuja dentro del marco de la tienda.
     *
     * @param array<string,mixed> $datos
     * @param bool $cacheable Falso en lo que lleva token o datos de nadie.
     */
    private function renderTienda(string $vista, array $datos = [], bool $cacheable = true): void
    {
        $datos['seccion'] ??= 'tienda';
        $datos['carritoUnidades'] = $this->carrito->unidades();

        if (!headers_sent()) {
            header($cacheable
                ? 'Cache-Control: public, max-age=300'
                : 'Cache-Control: no-store, no-cache, must-revalidate, private');
        }

        /* El contenido va dentro del ancho del sitio.
         *
         * Las vistas de la tienda volcaban su marcado directamente en
         * `<main>`, y el resto del sitio no: por eso el título «La tienda
         * de CashMan H.A.» quedaba pegado al borde izquierdo de la
         * ventana mientras las demás páginas respetaban su margen. El
         * ancho de lectura es del marco, no de cada vista, así que se
         * aplica aquí una sola vez en lugar de recordarlo en cada
         * plantilla. */
        $datos['contenido'] = '<section class="sitio-seccion tda-pagina">'
            . '<div class="sitio-ancho">'
            . $this->avisoDeLaUrl()
            . $this->vista->render($vista, $datos)
            . '</div></section>';

        Respuesta::html($this->vista->render('layout/publico', $datos));
    }
}
