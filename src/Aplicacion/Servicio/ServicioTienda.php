<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Cliente\Documento;
use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * La tienda: comprar con una tarjeta emitida aquí.
 *
 * Sin esto, las tarjetas se emitían y no se podían usar. La única forma
 * de que una tarjeta tuviera saldo era «simular consumo» desde la
 * pantalla de administración, que sirve para probar y no se parece en
 * nada a comprar. Y sin compras de verdad no hay estado de cuenta que
 * revisar, ni compras a cuotas que diferir, ni corte que cuadrar.
 *
 * Una compra hace exactamente lo que haría en la calle:
 *
 *   1. Comprueba el cupo, con el sobregiro que el titular haya
 *      autorizado y su comisión si lo usa.
 *   2. Descuenta las existencias del comercio.
 *   3. Anota el consumo en la tarjeta con el nombre del comercio y su
 *      categoría, de modo que aparezca en el estado de cuenta como
 *      cualquier otro.
 *   4. Si se compró a cuotas, arma el plan diferido del comercio.
 *   5. Avisa al cliente en el portal y por correo.
 *
 * Todo dentro de una transacción: si falla el descuento de existencias,
 * no queda un consumo cobrado por algo que no se entregó.
 */
final class ServicioTienda
{
    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    /**
     * Compra: cobra a la tarjeta y deja el pedido.
     *
     * @param array{comercio_id:int, tarjeta_id:int, lineas:array<int,int>, cuotas?:int, direccion?:string} $datos
     * @return array<string,mixed>
     */
    public function comprar(array $datos): array
    {
        Autorizacion::exigir('tienda.comprar');
        $usuarioId = Sesion::exigirAutenticado();

        $tarjetaId = (int) ($datos['tarjeta_id'] ?? 0);

        if ($tarjetaId === 0) {
            throw new \InvalidArgumentException('Elija con qué tarjeta desea pagar.');
        }

        // La tarjeta tiene que ser suya: pagar con la de otro es lo
        // primero que intentaría alguien curioso.
        $tarjeta = Autorizacion::exigirTarjeta($tarjetaId, $usuarioId);

        if ((int) $tarjeta['usuario_id'] !== $usuarioId) {
            throw new \RuntimeException('Esa tarjeta no es suya.');
        }

        return $this->realizarCompra($tarjetaId, $usuarioId, $datos);
    }

    /**
     * Compra tecleando el número de la tarjeta, como en cualquier tienda.
     *
     * Este es el camino de quien no elige la tarjeta de una lista sino
     * que la saca de la cartera y escribe lo que hay impreso: número,
     * vencimiento y CVV. No hace falta tener la sesión abierta —en una
     * tienda tampoco se la piden a nadie—, porque lo que autoriza la
     * compra es tener la tarjeta, que es justo lo que esos tres datos
     * demuestran.
     *
     * El pedido queda a nombre del TITULAR de la tarjeta, no de quien
     * esté conectado: el cargo es suyo, el estado de cuenta es suyo y
     * el aviso le llega a él.
     *
     * @param array{numero:string, cvv:string, mes:int, anio:int,
     *              lineas:array<int,int>, cuotas?:int, direccion?:string} $datos
     * @return array<string,mixed>
     */
    public function comprarConNumero(array $datos): array
    {
        $lineas = $this->normalizarLineas($datos['lineas'] ?? []);

        if ($lineas === []) {
            throw new \InvalidArgumentException('Su carrito está vacío.');
        }

        /* Cuánto se va a cobrar, solo para dejarlo anotado.
         *
         * No se usa `articulosBloqueados()`: su `FOR UPDATE` fuera de
         * una transacción no bloquea nada y daría una sensación falsa
         * de seguridad. El importe de verdad lo calcula
         * `realizarCompra()` dentro de la transacción, con los
         * artículos bloqueados; este de aquí solo sirve para que un
         * rechazo quede auditado con una cifra y no con un cero. */
        $total = $this->totalAproximado($lineas);

        $tarjeta = (new ServicioRed())->identificar(
            (string) ($datos['numero'] ?? ''),
            (string) ($datos['cvv'] ?? ''),
            (int) ($datos['mes'] ?? 0),
            (int) ($datos['anio'] ?? 0),
            Monto::deCentavos($total),
            'Tienda CashMan H.A.'
        );

        return $this->realizarCompra(
            (int) $tarjeta['id'],
            (int) $tarjeta['usuario_id'],
            ['lineas' => $lineas] + $datos
        );
    }

    /**
     * El cuerpo de la compra, una vez que se sabe con qué tarjeta se paga.
     *
     * Se separó de `comprar()` cuando la tienda admitió números
     * tecleados: los dos caminos difieren solo en cómo se identifica la
     * tarjeta, y todo lo demás —existencias, pedido, consumo, plan de
     * cuotas, asiento de débito, avisos— tiene que ser lo mismo o los
     * pedidos acabarían contándose distinto según por dónde entraron.
     *
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    /**
     * Compra un carrito que puede tener artículos de varias tiendas.
     *
     * De un carrito salen VARIOS pedidos: uno por comercio. No es un
     * capricho de implementación, es lo que pasa de verdad —cada tienda
     * despacha lo suyo, cobra su propio envío y pone sus propios meses
     * sin intereses—, y es lo que permite al cliente llevarse unas
     * sábanas y una licuadora pagando una sola vez.
     *
     * Antes esto se rechazaba con un «cada pedido es de una sola
     * tienda, haga la otra compra después», que le trasladaba al
     * cliente un límite que era nuestro.
     *
     * TODOS O NINGUNO
     * ---------------
     * Los pedidos van dentro de una misma transacción. Si al tercer
     * comercio se agota un artículo o la tarjeta se queda sin cupo, se
     * deshacen los tres: nadie se queda con dos pedidos cobrados y uno
     * a medias, que es un estado que después hay que arreglar a mano.
     *
     * Las transacciones de este sistema anidan con puntos de guardado,
     * así que cada compra individual conserva su propia atomicidad
     * dentro de la grande.
     *
     * @return array<string,mixed> Con un solo comercio devuelve lo de
     *         siempre, para no romper a quien ya lo usaba. Con varios,
     *         añade `pedidos` con el detalle de cada uno.
     */
    private function realizarCompra(int $tarjetaId, int $usuarioId, array $datos): array
    {
        $lineas = $this->normalizarLineas($datos['lineas'] ?? []);

        if ($lineas === []) {
            throw new \InvalidArgumentException('Su carrito está vacío.');
        }

        $porComercio = $this->agruparPorComercio($lineas);

        if (count($porComercio) === 1) {
            return $this->comprarDeUnComercio($tarjetaId, $usuarioId, $datos);
        }

        return BaseDatos::enTransaccion(function () use ($porComercio, $tarjetaId, $usuarioId, $datos) {
            $pedidos = [];
            $total   = 0;
            $folios  = [];

            foreach ($porComercio as $lineasDelComercio) {
                $r = $this->comprarDeUnComercio(
                    $tarjetaId,
                    $usuarioId,
                    [
                        'lineas' => $lineasDelComercio,
                        // Varias tiendas: cada una aplica su plazo en
                        // vez de rechazar la compra entera.
                        'ajustar_cuotas' => true,
                    ] + $datos
                );

                $pedidos[] = $r;
                $folios[]  = $r['folio'];
                $total    += (int) ($r['total']['centavos'] ?? 0);
            }

            /* El primero hace de cabecera para quien solo mira el
               resultado por encima; el detalle completo va en
               `pedidos`. */
            return $pedidos[0] + [
                'pedidos'       => $pedidos,
                'folios'        => $folios,
                'comercios'     => count($pedidos),
                'total_general' => Monto::deCentavos($total)->jsonSerialize(),
            ];
        });
    }

    /**
     * Reparte las líneas del carrito por comercio, conservando el orden.
     *
     * @param  array<int,int> $lineas
     * @return list<array<int,int>>
     */
    private function agruparPorComercio(array $lineas): array
    {
        $ids   = array_keys($lineas);
        $hueco = implode(',', array_fill(0, count($ids), '?'));

        $filas = BaseDatos::todas(
            "SELECT id, comercio_id FROM articulos WHERE id IN ({$hueco})",
            $ids
        );

        $grupos = [];

        foreach ($filas as $a) {
            $grupos[(int) $a['comercio_id']][(int) $a['id']] = $lineas[(int) $a['id']];
        }

        return array_values($grupos);
    }

    /** El cuerpo de siempre: una compra, de un solo comercio. */
    private function comprarDeUnComercio(int $tarjetaId, int $usuarioId, array $datos): array
    {
        $lineas = $this->normalizarLineas($datos['lineas'] ?? []);

        if ($lineas === []) {
            throw new \InvalidArgumentException('Su carrito está vacío.');
        }

        return BaseDatos::enTransaccion(function () use ($usuarioId, $tarjetaId, $lineas, $datos) {
            $t = BaseDatos::unaFila('SELECT * FROM tarjetas WHERE id = ? FOR UPDATE', [$tarjetaId]);

            if ((string) $t['estado'] !== 'activa') {
                throw new \RuntimeException(
                    'Su tarjeta está ' . $t['estado'] . ', así que no puede usarse para comprar.'
                );
            }

            if ($t['congelada_hasta'] !== null
                && new \DateTimeImmutable((string) $t['congelada_hasta']) > new \DateTimeImmutable()) {
                throw new \RuntimeException(
                    'Su tarjeta está congelada. Descongélela desde «Mis tarjetas» y vuelva a intentarlo.'
                );
            }

            if (!in_array((string) $t['tipo'], ['credito', 'debito'], true)) {
                throw new \RuntimeException(
                    'Con esta tarjeta no se puede comprar en la tienda.'
                );
            }

            $esDebito = (string) $t['tipo'] === 'debito';

            // --- Los artículos, bloqueados para no vender de más ------
            $articulos = $this->articulosBloqueados($lineas);
            $this->verificarUnSoloComercio($articulos);

            [$subtotal, $detalle] = $this->totalizar($articulos, $lineas);

            $primero = $articulos[0];
            $envio   = $subtotal > 0 ? (int) $primero['envio_cent'] : 0;
            $total   = $subtotal + $envio;

            /* Con qué se paga.
               En crédito, la línea. En débito, el saldo disponible de la
               cuenta: ni lo retenido ni el mínimo son suyos para
               gastarlos hoy. */
            $cuentaDeb = null;

            if ($esDebito) {
                if ((int) $t['cuenta_id'] <= 0) {
                    throw new \RuntimeException(
                        'Esta tarjeta de débito no tiene una cuenta ligada.'
                    );
                }

                $bloq = LibroMayor::bloquearCuentas([(int) $t['cuenta_id']]);
                $cuentaDeb = $bloq[(int) $t['cuenta_id']] ?? null;

                if ($cuentaDeb === null || $cuentaDeb['estado'] !== 'activa') {
                    throw new \RuntimeException(
                        'La cuenta ligada a su tarjeta de débito no está activa.'
                    );
                }

                $disponible = max(0,
                    (int) $cuentaDeb['saldo_cent']
                    - (int) $cuentaDeb['saldo_retenido_cent']
                    - (int) ($cuentaDeb['saldo_minimo_cent'] ?? 0)
                );
            } else {
                $disponible = ServicioTarjeta::cupoDisponible(
                    (int) $t['limite_cent'],
                    (int) $t['saldo_usado_cent']
                );
            }

            $tolerancia = (int) $t['sobregiro_permitido'] === 1
                ? (int) round((int) $t['limite_cent'] * (int) $t['sobregiro_tolerancia_bp'] / 10000)
                : 0;

            if ($total > $disponible + $tolerancia) {
                throw new \RuntimeException(
                    'El cupo de su tarjeta no alcanza para esta compra. Disponible: '
                    . Monto::deCentavos(max(0, $disponible))->formateado()
                    . ($tolerancia > 0
                        ? ' más ' . Monto::deCentavos($tolerancia)->formateado()
                          . ' de sobregiro autorizado.'
                        : '.')
                    . ' Puede abonar a su tarjeta y volver a intentarlo.'
                );
            }

            $comision = 0;

            if ($esDebito) {
                /* Sin sobregiro. El dinero es del cliente: dejarle gastar
                   de más seria prestarle sin que lo haya pedido, y encima
                   cobrarle comision por ello. */
                if ($total > $disponible) {
                    throw new \RuntimeException(
                        'Fondos insuficientes en la cuenta ligada a su tarjeta. '
                        . 'Disponible: ' . Monto::deCentavos($disponible)->formateado() . '.'
                    );
                }
            } elseif ($total > $disponible) {
                /* Misma regla que en el consumo de tarjeta: la comisión
                   la decide el segmento del titular. Tener dos criterios
                   para lo mismo es como acaba cobrándose por una puerta
                   lo que se perdona por la otra. */
                $decisionSob = (new ServicioSobregiro())->comision($usuarioId);
                $comision    = $decisionSob['comision_cent'];
            }

            /* --- Las cuotas sin intereses del comercio ---------------
             *
             * Cada tienda pone su propio plazo: una da 18 meses y la de
             * al lado 15. Con las dos en el mismo carrito, el cliente
             * pide UN plazo y cada comercio aplica el suyo si no llega
             * hasta el pedido.
             *
             * Por qué no el menor de todos, que sería más simple: el
             * comercio que ofrece 18 los ofrece de verdad, y recortarle
             * a 15 porque otro no da más es quitarle al cliente algo
             * que sí estaba disponible. Y por qué no el mayor para
             * todos: sería prometer 18 por cuenta de quien solo ofrece
             * 15, y el que acaba financiando la diferencia es el banco.
             *
             * Se ajusta en silencio solo cuando la compra viene de un
             * carrito de varias tiendas (`ajustar_cuotas`). Comprando en
             * una sola, pedir más de lo que ofrece sigue siendo un
             * error que hay que decir: ahí no hay nada que conciliar. */
            $pedidas = max(0, (int) ($datos['cuotas'] ?? 0));
            $tope    = (int) $primero['cuotas_sin_intereses'];
            $ajustar = (bool) ($datos['ajustar_cuotas'] ?? false);

            if ($ajustar) {
                $cuotas = min($pedidas, $tope);
            } else {
                $cuotas = $pedidas;

                if ($cuotas > 0 && ($tope === 0 || $cuotas > $tope)) {
                    throw new \InvalidArgumentException(
                        $tope === 0
                            ? 'Este comercio no ofrece compras a cuotas.'
                            : 'Este comercio ofrece hasta ' . $tope . ' cuotas sin intereses.'
                    );
                }
            }

            // No se difiere lo que ya se pagó con el propio dinero.
            if ($esDebito && $cuotas > 0) {
                throw new \InvalidArgumentException(
                    'Las compras a cuotas son para tarjeta de crédito. Con débito el '
                    . 'importe se descuenta de su cuenta en el momento.'
                );
            }

            /* El maximo de compras diferidas vale por los dos caminos.
               Si solo se comprobara al diferir a mano, comprar a cuotas
               en la tienda seria la forma de saltarselo. */
            if ($cuotas > 0 && ServicioTarjeta::huecosDePlan($tarjetaId) < 1) {
                throw new \InvalidArgumentException(
                    'Ya tiene ' . ServicioTarjeta::topePlanes() . ' compras diferidas '
                    . 'vigentes en esta tarjeta, que es el maximo. Puede completar la '
                    . 'compra en un solo pago, o diferirla cuando termine de pagar alguno '
                    . 'de los planes en curso.'
                );
            }

            // --- Se registra ------------------------------------------
            $folio = Folio::unico('PED', 'tienda_pedidos', 'folio', 'ymd');
            $ref   = Folio::unico('CMP', 'tienda_pedidos', 'referencia', 'ymd');

            foreach ($detalle as $d) {
                BaseDatos::ejecutar(
                    'UPDATE articulos SET existencias = existencias - ? WHERE id = ?',
                    [$d['cantidad'], $d['id']]
                );
            }

            BaseDatos::ejecutar(
                /* El estado, explícito.
                 *
                 * Aquí la tarjeta se carga unas líneas más abajo, dentro
                 * de esta misma transacción: cuando el pedido existe,
                 * ya está pagado. Al no nombrar la columna caía en el
                 * valor por omisión de la tabla —«pago_pendiente»—, y
                 * el cliente veía «esperando el pago» para siempre en
                 * una compra que se le había cobrado, con su consumo en
                 * el estado de cuenta y todo. */
                'INSERT INTO tienda_pedidos
                   (uuid, folio, usuario_id, comercio_id, tarjeta_id, medio_pago,
                    subtotal_cent, envio_cent, total_cent, cuotas, referencia,
                    estado, direccion)
                 VALUES (UUID(),?,?,?,?,"tarjeta_emitida",?,?,?,?,?,"pagado",?)',
                [
                    $folio, $usuarioId, (int) $primero['c_id'], $tarjetaId,
                    $subtotal, $envio, $total, $cuotas, $ref,
                    mb_substr(trim((string) ($datos['direccion'] ?? '')), 0, 400) ?: null,
                ]
            );

            $pedidoId = BaseDatos::ultimoId();

            foreach ($detalle as $d) {
                BaseDatos::ejecutar(
                    'INSERT INTO tienda_pedido_lineas
                       (pedido_id, articulo_id, nombre, precio_cent, cantidad)
                     VALUES (?,?,?,?,?)',
                    [$pedidoId, $d['id'], $d['nombre'], $d['precio'], $d['cantidad']]
                );
            }

            /* El consumo en la tarjeta.
             *
             * Va con el nombre del comercio y su categoría, igual que
             * uno hecho con el datáfono: así el cliente reconoce el
             * cargo en su estado de cuenta, que es la única forma de que
             * pueda detectar uno que no hizo. */
            BaseDatos::ejecutar(
                'INSERT INTO tarjeta_movimientos
                   (tarjeta_id, tipo, importe_cent, comercio, categoria, referencia,
                    estado, diferible)
                 VALUES (?, "consumo", ?, ?, ?, ?, "liquidada", ?)',
                [
                    $tarjetaId, $total,
                    mb_substr((string) $primero['comercio'], 0, 120),
                    (string) $primero['categoria'],
                    $ref,
                    // Si ya se compró a cuotas, no se puede volver a diferir.
                    $cuotas > 0 ? 0 : 1,
                ]
            );

            $movId = BaseDatos::ultimoId();

            BaseDatos::ejecutar(
                'UPDATE tienda_pedidos SET movimiento_id = ? WHERE id = ?',
                [$movId, $pedidoId]
            );

            /* La compra a cuotas crea su plan de tasa cero.
             *
             * Antes el pedido decía «12 cuotas sin intereses» y no se
             * creaba ningún plan: el saldo se quedaba en la tarjeta como
             * un consumo corriente, y al llegar el corte devengaba
             * interés revolvente y primas de seguro como cualquier otro.
             * Es decir, la frase era falsa y el cliente acababa pagando
             * bastante más que el precio de etiqueta.
             *
             * Con el plan registrado como `tasa_cero`, el corte lo aparta
             * de la base de intereses y de la de seguros, que es lo que
             * «sin intereses» tiene que significar. */
            $planId = 0;

            if ($cuotas > 0) {
                $planId = $this->planTasaCero(
                    $tarjetaId,
                    $movId,
                    $total,
                    $cuotas,
                    (string) $primero['comercio'],
                    (int) $t['dia_corte']
                );
            }

            /* Igual que en el consumo de tarjeta: el sobregiro se
               anota aunque la comisión sea cero. Si solo se anotara
               al cobrar, la cortesía por categoría no se consumiría
               nunca y el beneficio sería ilimitado. */
            if (isset($decisionSob)) {
                (new ServicioSobregiro())->registrar($tarjetaId, $decisionSob, $ref . '-SOB');
            }

            if ($esDebito) {
                /* El dinero sale de la cuenta, con su asiento.
                   Sin esto el pedido quedaria pagado y el saldo del
                   cliente intacto: una compra que nadie pago. */
                $tecnica = (int) BaseDatos::unValor(
                    'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
                );

                $montoVo = Monto::deCentavos($total);

                $asiento = LibroMayor::registrar(
                    'consumo_tarjeta',
                    [
                        ['cuenta_id' => (int) $t['cuenta_id'],
                         'importe' => $montoVo->negado(),
                         'descripcion' => 'Compra en ' . mb_substr((string) $primero['comercio'], 0, 60)
                                        . ' con tarjeta ••••' . $t['ultimos4']],
                        ['cuenta_id' => $tecnica, 'importe' => $montoVo,
                         'descripcion' => 'Contrapartida de compra con débito'],
                    ],
                    $usuarioId,
                    'tienda-debito-' . $pedidoId,
                    'Compra ' . $folio
                );

                // Un consumo de débito se liquida en el acto.
                BaseDatos::ejecutar(
                    'UPDATE tarjeta_movimientos
                        SET estado = "liquidada", diferible = 0 WHERE id = ?',
                    [$movId]
                );
            } else {
                BaseDatos::ejecutar(
                    'UPDATE tarjetas SET saldo_usado_cent = saldo_usado_cent + ? WHERE id = ?',
                    [$total + $comision, $tarjetaId]
                );
            }

            Auditoria::registrar('tienda.compra', Auditoria::EXITO, $usuarioId,
                'tienda_pedido', (string) $pedidoId,
                ['folio' => $folio, 'comercio' => $primero['comercio'],
                 'total' => Monto::deCentavos($total)->aDecimal(), 'cuotas' => $cuotas]);

            $this->notificaciones->crear(
                $usuarioId,
                /* `tienda.compra` y no `tarjeta.consumo`: este ultimo
                   dispara el correo generico del servicio de avisos, y la
                   compra manda el suyo con el detalle entero. Dos correos
                   de lo mismo, uno peor que el otro, no ayudan a nadie. */
                'tienda.compra',
                'Compra realizada en ' . $primero['comercio'],
                'Le confirmamos su compra por ' . Monto::deCentavos($total)->formateado()
                . ' en ' . $primero['comercio'] . ', cargada a su tarjeta ••••'
                . $t['ultimos4'] . '.'
                . ($cuotas > 0 ? ' Queda a ' . $cuotas . ' cuotas sin intereses.' : '')
                . ($comision > 0
                    ? ' Esta compra usó el sobregiro que usted autorizó, con una comisión de '
                      . Monto::deCentavos($comision)->formateado() . '.'
                    : '')
                . ' Gracias por su preferencia.',
                [
                    'pedido'     => $folio,
                    'comercio'   => (string) $primero['comercio'],
                    'total'      => Monto::deCentavos($total)->formateado(),
                    'referencia' => $ref,
                ],
                'exito'
            );

            /* El detalle, por correo.
               La notificacion del portal cabe en una linea y decia solo
               «queda a N cuotas sin intereses»: con eso el cliente no
               puede comprobar un cargo ni reclamar nada. El aviso del
               plan lleva folio, calendario, importes y cuantas compras
               mas puede diferir. */
            if ($planId > 0) {
                ServicioTarjeta::avisarPlan(
                    $planId,
                    'La compra se difirio automaticamente al plazo que usted eligio en '
                    . 'la tienda, sin que tenga que hacer ningun tramite adicional.'
                );
            }

            $this->avisarCompra($pedidoId, $planId);

            return [
                'pedido_id'  => $pedidoId,
                'plan_id'    => $planId,
                'folio'      => $folio,
                'referencia' => $ref,
                'comercio'   => (string) $primero['comercio'],
                'subtotal'   => Monto::deCentavos($subtotal)->jsonSerialize(),
                'envio'      => Monto::deCentavos($envio)->jsonSerialize(),
                'total'      => Monto::deCentavos($total)->jsonSerialize(),
                'comision'   => Monto::deCentavos($comision)->jsonSerialize(),
                'cuotas'     => $cuotas,
                /* Lo que el cliente pidió y lo que esta tienda pudo
                   dar. Si no coinciden hay que decírselo, no dejar que
                   lo descubra en su estado de cuenta. */
                'cuotas_pedidas' => $pedidas,
                'cuotas_tope'    => $tope,
                'cuotas_ajustadas' => $pedidas > 0 && $cuotas < $pedidas,
                'movimiento_id' => $movId,
            ];
        });
    }

    /**
     * Deja un pedido apartado, a la espera del cobro por la pasarela.
     *
     * Es el camino de quien compra sin tener cuenta, y también el de un
     * cliente que prefiere pagar con la tarjeta de otro banco.
     *
     * El pedido nace en `pago_pendiente` y las existencias SÍ se
     * descuentan: si no se apartaran, dos personas podrían pagar a la vez
     * el último televisor y una de las dos se quedaría con un cobro hecho
     * y sin producto. Si el cobro no llega, `liberar()` las devuelve.
     *
     * @param array{comercio_id?:int, lineas:array<int,int>, cuotas?:int,
     *              direccion?:string, nombre:string, correo:string,
     *              telefono?:string, documento?:string} $datos
     * @return array<string,mixed>
     */
    public function apartarParaPasarela(array $datos): array
    {
        if (!Parametros::booleano('tienda.compra_invitados', true)
            && Sesion::usuarioId() === null) {
            throw new \RuntimeException(
                'En este momento la compra requiere iniciar sesión. '
                . 'Con gusto le ayudamos desde cualquiera de nuestras agencias.'
            );
        }

        $nombre = trim((string) ($datos['nombre'] ?? ''));
        $correo = trim((string) ($datos['correo'] ?? ''));

        if (mb_strlen($nombre) < 5) {
            throw new \InvalidArgumentException('Indíquenos su nombre completo, por favor.');
        }

        if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException(
                'El correo no parece válido. Lo necesitamos para enviarle el comprobante '
                . 'y avisarle del envío.'
            );
        }

        $direccion = trim((string) ($datos['direccion'] ?? ''));

        if (mb_strlen($direccion) < 10) {
            throw new \InvalidArgumentException(
                'Necesitamos una dirección de entrega con algo de detalle.'
            );
        }

        $lineas = $this->normalizarLineas($datos['lineas'] ?? []);

        if ($lineas === []) {
            throw new \InvalidArgumentException('Su carrito está vacío.');
        }

        return BaseDatos::enTransaccion(function () use ($lineas, $datos, $nombre, $correo, $direccion) {
            $porComercio = $this->agruparPorComercio($lineas);

            /* Varias tiendas en el carrito: un pedido por cada una,
             * pero UN SOLO cobro a la tarjeta.
             *
             * Antes este camino —el de quien paga con la tarjeta de
             * otro banco— rechazaba el carrito mezclado, aunque la
             * compra con tarjeta del banco ya lo permitía. Quien
             * llegaba hasta la pantalla de pago con dos tiendas se
             * encontraba con que tenía que vaciar el carrito y volver a
             * empezar.
             *
             * Cada tienda despacha lo suyo y cobra su propio envío, así
             * que son pedidos distintos con folios distintos. Lo que no
             * tiene sentido es pasar la tarjeta dos veces: `grupo_pago`
             * los mantiene unidos para que un solo cobro los confirme a
             * todos, y un solo rechazo los libere a todos. */
            $grupo = count($porComercio) > 1
                ? strtoupper(bin2hex(random_bytes(8)))
                : null;

            $pedidos  = [];
            $subtotal = 0;
            $envio    = 0;
            $total    = 0;

            foreach ($porComercio as $lineasDelComercio) {
                $r = $this->apartarDeUnComercio(
                    $lineasDelComercio,
                    $datos,
                    $nombre,
                    $correo,
                    $direccion,
                    $grupo,
                    /* Con varias tiendas, cada una aplica el plazo que
                       ofrece en vez de tumbar la compra entera porque
                       una no llega a los meses que se pidieron. */
                    $grupo !== null
                );

                $pedidos[] = $r;
                $subtotal += (int) $r['subtotal']['centavos'];
                $envio    += (int) $r['envio']['centavos'];
                $total    += (int) $r['total_cent'];
            }

            if (count($pedidos) === 1) {
                return $pedidos[0];
            }

            /* La cabecera es la del primer pedido, con los totales del
             * grupo encima: a la tarjeta se le cobra la suma, no el
             * primero. El detalle de cada tienda va en `pedidos`.
             *
             * `array_merge` y no `+`: con `+` ganan las claves de la
             * izquierda y el cobro saldría por el total de un solo
             * pedido. */
            return array_merge($pedidos[0], [
                'grupo'      => $grupo,
                'pedidos'    => $pedidos,
                'folios'     => array_column($pedidos, 'folio'),
                'claves'     => array_column($pedidos, 'clave'),
                'comercios'  => count($pedidos),
                'subtotal'   => Monto::deCentavos($subtotal)->jsonSerialize(),
                'envio'      => Monto::deCentavos($envio)->jsonSerialize(),
                'total'      => Monto::deCentavos($total)->jsonSerialize(),
                'total_cent' => $total,
            ]);
        });
    }

    /**
     * Aparta el pedido de UNA tienda, a la espera del cobro.
     *
     * @param array<int,int>      $lineas
     * @param array<string,mixed> $datos
     * @param string|null         $grupo Pedidos que se cobran juntos.
     * @param bool                $ajustarCuotas Recorta el plazo al que
     *        ofrece esta tienda, en vez de rechazar la compra.
     * @return array<string,mixed>
     */
    private function apartarDeUnComercio(
        array $lineas,
        array $datos,
        string $nombre,
        string $correo,
        string $direccion,
        ?string $grupo,
        bool $ajustarCuotas
    ): array {
        return BaseDatos::enTransaccion(function () use (
            $lineas, $datos, $nombre, $correo, $direccion, $grupo, $ajustarCuotas
        ) {
            $articulos = $this->articulosBloqueados($lineas);
            $primero   = $articulos[0];

            /* Red de seguridad: si el reparto por comercio fallara, es
               mejor no cobrar que cobrar un pedido mezclado. */
            $this->verificarUnSoloComercio($articulos);

            [$subtotal, $detalle] = $this->totalizar($articulos, $lineas);

            $envio = (int) $primero['envio_cent'];
            $total = $subtotal + $envio;

            $pedidas = max(0, (int) ($datos['cuotas'] ?? 0));
            $tope    = (int) $primero['cuotas_sin_intereses'];

            $cuotas = $ajustarCuotas
                ? min($pedidas, $tope)
                : $this->validarCuotas($pedidas, $tope);

            $folio = Folio::unico('PED', 'tienda_pedidos', 'folio', 'ymd');
            $ref   = Folio::unico('CMP', 'tienda_pedidos', 'referencia', 'ymd');

            // Clave de seguimiento: con el folio solo, cualquiera que
            // adivinara uno podría ver el pedido de otra persona.
            $clave = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));

            /* El documento del invitado es opcional, así que un número
               mal escrito no tumba la compra: se guarda normalizado si
               se entiende, y si no, no se guarda. Lo que no se hace es
               guardar una cadena cualquiera que luego no case con la
               del expediente del mismo cliente. */
            $doc = Documento::oNada(
                (string) ($datos['tipo_documento'] ?? 'dui'),
                (string) ($datos['documento'] ?? '')
            );

            $this->descontarExistencias($detalle);

            BaseDatos::ejecutar(
                'INSERT INTO tienda_pedidos
                   (uuid, folio, usuario_id, comercio_id, tarjeta_id, medio_pago,
                    subtotal_cent, envio_cent, total_cent, cuotas, referencia,
                    grupo_pago, estado, direccion, invitado_nombre, invitado_correo,
                    invitado_telefono, invitado_documento, invitado_tipo_documento,
                    clave_consulta)
                 VALUES (UUID(),?,?,?,NULL,"pasarela",?,?,?,?,?,?,"pago_pendiente",?,?,?,?,?,?,?)',
                [
                    $folio,
                    Sesion::usuarioId(),
                    (int) $primero['c_id'],
                    $subtotal, $envio, $total, $cuotas, $ref,
                    $grupo,
                    mb_substr($direccion, 0, 400),
                    mb_substr($nombre, 0, 140),
                    mb_substr($correo, 0, 190),
                    mb_substr(trim((string) ($datos['telefono'] ?? '')), 0, 30) ?: null,
                    $doc?->crudo(),
                    $doc?->tipo(),
                    $clave,
                ]
            );

            $pedidoId = BaseDatos::ultimoId();

            $this->guardarLineas($pedidoId, $detalle);

            Auditoria::registrar('tienda.pedido_apartado', Auditoria::EXITO,
                Sesion::usuarioId(), 'tienda_pedido', (string) $pedidoId,
                ['folio' => $folio, 'comercio' => $primero['comercio'],
                 'total' => Monto::deCentavos($total)->aDecimal(), 'correo' => $correo,
                 'grupo' => $grupo]);

            return [
                'pedido_id' => $pedidoId,
                'folio'     => $folio,
                'clave'     => $clave,
                'referencia'=> $ref,
                'grupo'     => $grupo,
                'comercio'  => (string) $primero['comercio'],
                'subtotal'  => Monto::deCentavos($subtotal)->jsonSerialize(),
                'envio'     => Monto::deCentavos($envio)->jsonSerialize(),
                'total'     => Monto::deCentavos($total)->jsonSerialize(),
                'total_cent'=> $total,
                'cuotas'    => $cuotas,
                /* Lo que se pidió y lo que esta tienda pudo dar. Si no
                   coinciden hay que decirlo, no dejar que se descubra
                   en el estado de cuenta. */
                'cuotas_pedidas'   => $pedidas,
                'cuotas_tope'      => $tope,
                'cuotas_ajustadas' => $pedidas > 0 && $cuotas < $pedidas,
            ];
        });
    }

    /**
     * Confirma el cobro que entró por la pasarela.
     *
     * Lo llama el webhook, no el navegador: hasta que la procesadora lo
     * confirma no hay dinero, y un «gracias por su compra» dibujado en
     * pantalla no es una confirmación de nada.
     *
     * Si el carrito tenía varias tiendas, el cobro fue uno solo pero los
     * pedidos son varios. `pagos_pasarela` guarda un único `pedido_id`,
     * así que desde ese se alcanza al resto por su grupo: de otro modo
     * la segunda tienda se quedaría en «pago pendiente» para siempre,
     * con el dinero ya cobrado y sus existencias apartadas.
     */
    public function confirmarPagoPasarela(int $pedidoId, int $pagoPasarelaId): void
    {
        foreach ($this->pedidosDelGrupo($pedidoId) as $id) {
            $this->confirmarUnPedidoPasarela($id, $pagoPasarelaId);
        }
    }

    /**
     * Los pedidos que se cobraron en un mismo pago.
     *
     * Devuelve siempre el que se pasó; con grupo, también a sus
     * hermanos, en el orden en que se crearon.
     *
     * @return list<int>
     */
    private function pedidosDelGrupo(int $pedidoId): array
    {
        $grupo = BaseDatos::unValor(
            'SELECT grupo_pago FROM tienda_pedidos WHERE id = ?',
            [$pedidoId]
        );

        if ($grupo === null || (string) $grupo === '') {
            return [$pedidoId];
        }

        $ids = array_map(
            static fn(array $r): int => (int) $r['id'],
            BaseDatos::todas(
                'SELECT id FROM tienda_pedidos WHERE grupo_pago = ? ORDER BY id',
                [(string) $grupo]
            )
        );

        return $ids !== [] ? $ids : [$pedidoId];
    }

    /** Un solo pedido del cobro. */
    private function confirmarUnPedidoPasarela(int $pedidoId, int $pagoPasarelaId): void
    {
        $p = BaseDatos::unaFila(
            'SELECT p.*, c.nombre AS comercio FROM tienda_pedidos p
               JOIN comercios c ON c.id = p.comercio_id
              WHERE p.id = ?',
            [$pedidoId]
        );

        if ($p === null || (string) $p['estado'] !== 'pago_pendiente') {
            return;   // ya estaba confirmado, o no existe: nada que hacer
        }

        BaseDatos::ejecutar(
            'UPDATE tienda_pedidos
                SET estado = "pagado", pago_pasarela_id = ?
              WHERE id = ? AND estado = "pago_pendiente"',
            [$pagoPasarelaId, $pedidoId]
        );

        $total = Monto::deCentavos((int) $p['total_cent']);

        $mensaje = '¡Gracias por su compra! Recibimos su pago de ' . $total->formateado()
            . ' en ' . $p['comercio'] . '. Su pedido ' . $p['folio']
            . ' ya está en preparación y le avisaremos cuando salga.';

        if ($p['usuario_id'] !== null) {
            $this->notificaciones->crear(
                (int) $p['usuario_id'],
                'tienda.pedido',
                'Compra confirmada en ' . $p['comercio'],
                $mensaje,
                ['pedido' => (string) $p['folio'], 'total' => $total->formateado()],
                'exito'
            );
        } elseif ((string) $p['invitado_correo'] !== '') {
            // El invitado no tiene portal donde recibir avisos: su único
            // canal es el correo, así que se le manda directo.
            $this->correoInvitado(
                (string) $p['invitado_correo'],
                (string) $p['invitado_nombre'],
                'Compra confirmada · pedido ' . $p['folio'],
                $mensaje,
                [
                    ['etiqueta' => 'Pedido',     'valor' => (string) $p['folio']],
                    ['etiqueta' => 'Clave de seguimiento', 'valor' => (string) $p['clave_consulta']],
                    ['etiqueta' => 'Comercio',   'valor' => (string) $p['comercio']],
                    ['etiqueta' => 'Total',      'valor' => $total->formateado()],
                ],
                (string) $p['folio'],
                (string) $p['clave_consulta']
            );
        }

        Auditoria::registrar('tienda.pedido_pagado', Auditoria::EXITO,
            $p['usuario_id'] !== null ? (int) $p['usuario_id'] : null,
            'tienda_pedido', (string) $pedidoId,
            ['folio' => $p['folio'], 'pasarela' => $pagoPasarelaId]);
    }

    /**
     * Devuelve las existencias de un cobro que no prosperó.
     *
     * Sin esto, cada tarjeta rechazada dejaría artículos apartados para
     * siempre y el catálogo acabaría diciendo que no hay existencias de
     * algo que sí está en bodega.
     *
     * Alcanza a todos los pedidos del grupo: una tarjeta rechazada no
     * paga ninguna de las dos tiendas, así que ninguna de las dos debe
     * quedarse con la mercadería apartada.
     */
    public function liberar(int $pedidoId, string $motivo = ''): void
    {
        foreach ($this->pedidosDelGrupo($pedidoId) as $id) {
            $this->liberarUno($id, $motivo);
        }
    }

    /** Un solo pedido del grupo. */
    private function liberarUno(int $pedidoId, string $motivo = ''): void
    {
        BaseDatos::enTransaccion(function () use ($pedidoId, $motivo): void {
            $p = BaseDatos::unaFila(
                'SELECT * FROM tienda_pedidos WHERE id = ? FOR UPDATE',
                [$pedidoId]
            );

            if ($p === null || (string) $p['estado'] !== 'pago_pendiente') {
                return;
            }

            $lineas = BaseDatos::todas(
                'SELECT articulo_id, cantidad FROM tienda_pedido_lineas WHERE pedido_id = ?',
                [$pedidoId]
            );

            foreach ($lineas as $l) {
                BaseDatos::ejecutar(
                    'UPDATE articulos SET existencias = existencias + ? WHERE id = ?',
                    [(int) $l['cantidad'], (int) $l['articulo_id']]
                );
            }

            /* El motivo se guarda, no solo se audita.
               «Anulado» a secas obliga al cliente a llamar para saber
               si le rechazaron la tarjeta o si se le pasó el tiempo. */
            BaseDatos::ejecutar(
                'UPDATE tienda_pedidos
                    SET estado = "anulado", cerrado_motivo = ?, cerrado_en = NOW()
                  WHERE id = ?',
                [mb_substr($motivo !== '' ? $motivo : 'El cobro no se confirmó.', 0, 240), $pedidoId]
            );

            Auditoria::registrar('tienda.pedido_liberado', Auditoria::FALLO, null,
                'tienda_pedido', (string) $pedidoId,
                ['folio' => $p['folio'], 'motivo' => $motivo !== '' ? $motivo : 'cobro no confirmado']);
        });
    }

    /**
     * Suelta los pedidos que esperan un cobro que no va a llegar.
     *
     * Un pedido aparta existencias en el momento de crearse, para que
     * nadie más compre la última unidad mientras se cobra. Si el cobro
     * no prospera —el comprador cerró la pestaña, la tarjeta no pasó,
     * se quedó sin red— ese apartado no lo suelta nadie: el pedido se
     * queda en «pago pendiente» para siempre y el catálogo dice que no
     * hay existencias de algo que sí está en bodega.
     *
     * El margen es generoso a propósito: quien está tecleando los datos
     * de su tarjeta no debe perder el pedido por tardar.
     *
     * @return list<string> Los folios liberados.
     */
    public function caducarPendientes(int $minutos = 60): array
    {
        $pendientes = BaseDatos::todas(
            'SELECT p.id, p.folio, g.estado AS cobro, g.detalle_error
               FROM tienda_pedidos p
          LEFT JOIN pagos_pasarela g ON g.id = p.pago_pasarela_id
              WHERE p.estado = "pago_pendiente"
                AND p.creado_en < DATE_SUB(NOW(), INTERVAL ? MINUTE)
                /* Nunca uno que ya tiene su consumo en una tarjeta de
                   la casa: eso está COBRADO, por mucho que su estado
                   diga otra cosa. Liberarlo devuelve la mercancía y
                   deja al cliente con el cargo y sin pedido. */
                AND p.movimiento_id IS NULL
                /* Y si el cobro de la pasarela sigue vivo tampoco: puede
                   estar a mitad de una verificación con el banco. */
                AND (g.id IS NULL OR g.estado IN ("fallido", "cancelado", "devuelto"))
              ORDER BY p.id',
            [max(1, $minutos)]
        );

        $folios = [];

        foreach ($pendientes as $p) {
            $motivo = ((string) ($p['detalle_error'] ?? '')) !== ''
                ? (string) $p['detalle_error']
                : 'El pago no se completó dentro del tiempo previsto y el pedido se liberó. '
                  . 'Los artículos volvieron a estar disponibles.';

            $this->liberar((int) $p['id'], $motivo);
            $folios[] = (string) $p['folio'];
        }

        return $folios;
    }

    // =================================================================
    // Piezas que comparten los dos caminos de compra
    // =================================================================

    /**
     * Deja el carrito en `articulo_id => cantidad`, con sus topes.
     *
     * @param  mixed $entrada
     * @return array<int,int>
     */
    private function normalizarLineas(mixed $entrada): array
    {
        $tope = max(1, Parametros::entero('tienda.max_unidades_articulo', 20));
        $lineas = [];

        foreach ((array) $entrada as $articuloId => $cantidad) {
            $articuloId = (int) $articuloId;
            $cantidad   = max(0, min($tope, (int) $cantidad));

            if ($articuloId > 0 && $cantidad > 0) {
                $lineas[$articuloId] = $cantidad;
            }
        }

        return $lineas;
    }

    /**
     * Los artículos del carrito, bloqueados hasta el fin de la
     * transacción.
     *
     * El bloqueo es lo que impide vender dos veces la última unidad: sin
     * él, dos compras simultáneas leerían «queda 1» las dos y las dos
     * pasarían.
     *
     * @param  array<int,int> $lineas
     * @return list<array<string,mixed>>
     */
    /**
     * Lo que costaría el carrito, sin bloquear nada.
     *
     * Para enseñar una cifra o dejarla en una anotación. Lo que se
     * cobra sale siempre de `totalizar()` con los artículos bloqueados,
     * porque entre esta consulta y el cobro alguien puede cambiar un
     * precio o agotar una existencia.
     *
     * @param array<int,int> $lineas
     */
    private function totalAproximado(array $lineas): int
    {
        if ($lineas === []) {
            return 0;
        }

        $ids   = array_keys($lineas);
        $hueco = implode(',', array_fill(0, count($ids), '?'));

        $filas = BaseDatos::todas(
            "SELECT a.id, a.precio_cent, c.envio_cent
               FROM articulos a JOIN comercios c ON c.id = a.comercio_id
              WHERE a.id IN ({$hueco}) AND a.activo = 1 AND c.activo = 1",
            $ids
        );

        $total = 0;
        $envio = 0;

        foreach ($filas as $a) {
            $total += (int) $a['precio_cent'] * (int) ($lineas[(int) $a['id']] ?? 0);
            $envio  = (int) $a['envio_cent'];
        }

        return $total + ($total > 0 ? $envio : 0);
    }
    private function articulosBloqueados(array $lineas): array
    {
        $ids   = array_keys($lineas);
        $hueco = implode(',', array_fill(0, count($ids), '?'));

        $articulos = BaseDatos::todas(
            "SELECT a.*, c.id AS c_id, c.nombre AS comercio, c.categoria,
                    c.envio_cent, c.cuotas_sin_intereses
               FROM articulos a JOIN comercios c ON c.id = a.comercio_id
              WHERE a.id IN ({$hueco}) AND a.activo = 1 AND c.activo = 1
              FOR UPDATE",
            $ids
        );

        if (count($articulos) !== count($lineas)) {
            throw new \RuntimeException(
                'Alguno de los artículos ya no está disponible. Revise su carrito, por favor.'
            );
        }

        return $articulos;
    }

    /**
     * Un pedido, un comercio.
     *
     * Cada comercio tiene su propio envío y sus propios meses sin
     * intereses; mezclarlos obligaría a repartir el envío entre dos
     * tiendas y a decidir cuál de los dos planes de cuotas manda. Se
     * compra por tienda, como en la calle.
     *
     * @param list<array<string,mixed>> $articulos
     */
    private function verificarUnSoloComercio(array $articulos): void
    {
        $comercios = array_unique(array_map(
            static fn(array $a): int => (int) $a['c_id'],
            $articulos
        ));

        if (count($comercios) > 1) {
            throw new \RuntimeException(
                'Cada pedido es de un solo comercio. Deje en el carrito los artículos '
                . 'de una tienda y haga la otra compra después.'
            );
        }
    }

    /**
     * Suma el carrito y arma el detalle de las líneas.
     *
     * @param  list<array<string,mixed>> $articulos
     * @param  array<int,int>            $lineas
     * @return array{0:int, 1:list<array<string,mixed>>}
     */
    private function totalizar(array $articulos, array $lineas): array
    {
        $subtotal = 0;
        $detalle  = [];

        foreach ($articulos as $a) {
            $cant = $lineas[(int) $a['id']];

            if ((int) $a['existencias'] < $cant) {
                throw new \RuntimeException(
                    'De «' . $a['nombre'] . '» '
                    . ((int) $a['existencias'] === 0
                        ? 'ya no quedan existencias.'
                        : 'quedan ' . (int) $a['existencias'] . ' unidad(es). '
                          . 'Ajuste la cantidad, por favor.')
                );
            }

            $subtotal += (int) $a['precio_cent'] * $cant;

            $detalle[] = [
                'id'       => (int) $a['id'],
                'nombre'   => (string) $a['nombre'],
                'precio'   => (int) $a['precio_cent'],
                'cantidad' => $cant,
            ];
        }

        return [$subtotal, $detalle];
    }

    /** Las cuotas que de verdad ofrece el comercio. */
    /**
     * El plazo pedido, comprobado contra lo que ofrece el comercio.
     *
     * `$tarjetaId` es opcional porque por la pasarela puede pagarse con
     * una tarjeta de otro banco: ahí no hay plan que crear ni tope que
     * aplicar. Cuando sí es tarjeta de la casa, el máximo de compras
     * diferidas se comprueba igual que al diferir a mano.
     */
    private function validarCuotas(int $pedidas, int $tope, int $tarjetaId = 0): int
    {
        $pedidas = max(0, $pedidas);

        if ($pedidas === 0) {
            return 0;
        }

        if ($tarjetaId > 0 && ServicioTarjeta::huecosDePlan($tarjetaId) < 1) {
            throw new \InvalidArgumentException(
                'Ya tiene ' . ServicioTarjeta::topePlanes() . ' compras diferidas vigentes '
                . 'en esta tarjeta, que es el maximo. Puede completar la compra en un solo '
                . 'pago, o diferirla cuando termine de pagar alguno de los planes en curso.'
            );
        }

        if ($tope === 0) {
            throw new \InvalidArgumentException('Este comercio no ofrece compras a cuotas.');
        }

        if ($pedidas > $tope) {
            throw new \InvalidArgumentException(
                'Este comercio ofrece hasta ' . $tope . ' cuotas sin intereses.'
            );
        }

        return $pedidas;
    }

    /**
     * Arma el plan de cuotas sin intereses de una compra.
     *
     * Tasa cero significa exactamente eso: el total se reparte entre las
     * cuotas y no se añade ni un centavo. Nada de intereses y nada de
     * seguros —el corte aparta el saldo de los planes `tasa_cero` de las
     * dos bases de cálculo—, porque un «sin intereses» que acaba cobrando
     * primas cada mes no es sin intereses.
     *
     * El reparto usa `repartir()` para que la suma de las cuotas sea
     * EXACTAMENTE el total: dividir y redondear deja centavos sueltos, y
     * en doce cuotas eso es un descuadre que alguien acaba reclamando.
     */
    /** @return int El id del plan creado. */
    private function planTasaCero(
        int $tarjetaId,
        int $movimientoId,
        int $totalCent,
        int $cuotas,
        string $comercio,
        int $diaCorte
    ): int {
        $folio = Folio::unico('PL', 'tarjeta_planes');

        BaseDatos::ejecutar(
            'INSERT INTO tarjeta_planes
               (uuid, folio, tarjeta_id, movimiento_id, tipo, monto_cent,
                interes_total_cent, total_cent, cuotas, cuota_cent, tasa_bp, comercio)
             VALUES (UUID(),?,?,?,"tasa_cero",?,0,?,?,?,0,?)',
            [
                $folio, $tarjetaId, $movimientoId,
                $totalCent, $totalCent, $cuotas,
                intdiv($totalCent, $cuotas),
                mb_substr($comercio, 0, 120),
            ]
        );

        $planId = BaseDatos::ultimoId();

        $partes  = Monto::deCentavos($totalCent)->repartir($cuotas);
        $diaCorte = min(28, max(1, $diaCorte));

        for ($i = 0; $i < $cuotas; $i++) {
            $base   = (new \DateTimeImmutable('first day of this month'))
                        ->modify('+' . ($i + 1) . ' months');
            $ultimo = (int) $base->format('t');

            $corte = $base->setDate(
                (int) $base->format('Y'),
                (int) $base->format('n'),
                min($diaCorte, $ultimo)
            );

            BaseDatos::ejecutar(
                'INSERT INTO tarjeta_plan_cuotas
                   (plan_id, numero, capital_cent, interes_cent, total_cent, fecha_corte)
                 VALUES (?,?,?,0,?,?)',
                [
                    $planId, $i + 1,
                    $partes[$i]->centavos,
                    $partes[$i]->centavos,
                    $corte->format('Y-m-d'),
                ]
            );
        }

        /* El consumo queda marcado como diferido.
           Faltaba, asi que el movimiento se veia «al contado» aunque
           tuviera su plan: la ficha no lo mostraba diferido y cualquier
           recuento por `plan_id` lo dejaba fuera. */
        BaseDatos::ejecutar(
            'UPDATE tarjeta_movimientos SET plan_id = ? WHERE id = ?',
            [$planId, $movimientoId]
        );

        return $planId;
    }

    /**
     * El detalle de la compra, por correo.
     *
     * Una compra con tarjeta mueve dinero y compromete cupo: el aviso
     * tiene que permitir comprobarla. Lleva qué se compró y a qué
     * precio, el total con su desglose, la tarjeta y cómo le quedó el
     * cupo, y —si se difirió— el plan que se creó solo.
     *
     * Y si NO se difirió pudiendo hacerlo, también lo dice. Callarlo es
     * lo que hace que alguien descubra días después que su compra «a 12
     * cuotas» entró al contado.
     *
     * Nunca lanza: la compra ya está hecha y cobrada.
     */
    private function avisarCompra(int $pedidoId, int $planId = 0): bool
    {
        try {
            $p = BaseDatos::unaFila(
                'SELECT d.*, u.correo, CONCAT(u.nombres," ",u.apellidos) AS cliente,
                        t.ultimos4, t.marca, t.limite_cent, t.saldo_usado_cent,
                        c.nombre AS comercio, c.cuotas_sin_intereses
                   FROM tienda_pedidos d
                   JOIN usuarios  u ON u.id = d.usuario_id
                   LEFT JOIN tarjetas  t ON t.id = d.tarjeta_id
                   LEFT JOIN comercios c ON c.id = d.comercio_id
                  WHERE d.id = ?',
                [$pedidoId]
            );

            if ($p === null || trim((string) $p['correo']) === '') {
                return false;
            }

            $lineas = BaseDatos::todas(
                'SELECT nombre, precio_cent, cantidad FROM tienda_pedido_lineas
                  WHERE pedido_id = ? ORDER BY id',
                [$pedidoId]
            );

            $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $dinero = static fn(int $c): string => Monto::deCentavos($c)->formateado();

            // --- Lo que se compró ---------------------------------
            $tabla = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
                . ' style="margin:18px 0;border-collapse:collapse;font-size:13px">'
                . '<tr>'
                . '<th align="left" style="padding:7px 0;border-bottom:2px solid #e4e7ec;color:#667085">Artículo</th>'
                . '<th align="center" style="padding:7px 0;border-bottom:2px solid #e4e7ec;color:#667085">Cant.</th>'
                . '<th align="right" style="padding:7px 0;border-bottom:2px solid #e4e7ec;color:#667085">Importe</th>'
                . '</tr>';

            foreach ($lineas as $l) {
                $tabla .= '<tr>'
                    . '<td style="padding:7px 0;border-bottom:1px solid #f2f4f7">'
                    . $e((string) $l['nombre']) . '</td>'
                    . '<td align="center" style="padding:7px 0;border-bottom:1px solid #f2f4f7">'
                    . (int) $l['cantidad'] . '</td>'
                    . '<td align="right" style="padding:7px 0;border-bottom:1px solid #f2f4f7;font-weight:600">'
                    . $e($dinero((int) $l['precio_cent'] * (int) $l['cantidad'])) . '</td>'
                    . '</tr>';
            }

            $tabla .= '</table>';

            // --- El plan, si lo hubo -------------------------------
            $bloquePlan = '';

            if ($planId > 0) {
                $pl = BaseDatos::unaFila(
                    'SELECT folio, cuotas, cuota_cent, total_cent FROM tarjeta_planes WHERE id = ?',
                    [$planId]
                );

                $fechas = BaseDatos::todas(
                    'SELECT fecha_corte FROM tarjeta_plan_cuotas
                      WHERE plan_id = ? ORDER BY numero', [$planId]
                );

                if ($pl !== null) {
                    $bloquePlan =
                        '<p style="margin-top:22px"><strong>Su compra quedó diferida</strong></p>'
                        . '<p>Se creó el plan <strong>' . $e((string) $pl['folio']) . '</strong> a '
                        . '<strong>' . (int) $pl['cuotas'] . ' cuotas de '
                        . $e($dinero((int) $pl['cuota_cent'])) . '</strong>, en modalidad '
                        . '<strong>tasa cero</strong>. El plan se creó solo con el plazo que '
                        . 'usted eligió al pagar: no hay ningún trámite pendiente de su parte.</p>'
                        . '<p>Pagará exactamente ' . $e($dinero((int) $pl['total_cent']))
                        . ', que es el importe de la compra. Este saldo <strong>no devenga '
                        . 'intereses corrientes ni primas de seguro</strong>: queda apartado de '
                        . 'esas dos bases en cada corte.'
                        . ($fechas !== []
                            ? ' La primera cuota se carga el <strong>'
                              . $e(date('d/m/Y', strtotime((string) $fechas[0]['fecha_corte'])))
                              . '</strong> y la última el <strong>'
                              . $e(date('d/m/Y', strtotime((string) end($fechas)['fecha_corte'])))
                              . '</strong>.'
                            : '')
                        . '</p>'
                        . '<p style="font-size:13px;color:#667085">Le enviamos aparte el '
                        . 'calendario completo, cuota por cuota, en el aviso del plan '
                        . $e((string) $pl['folio']) . '.</p>';
                }
            } elseif ((int) ($p['cuotas_sin_intereses'] ?? 0) > 0) {
                /* Pudiendo diferirse, no se difirió. Decirlo evita que el
                   cliente lo descubra dias despues al ver el cargo entero. */
                $bloquePlan =
                    '<p style="margin-top:22px"><strong>Esta compra se cargó de contado</strong></p>'
                    . '<p>Este comercio ofrece hasta ' . (int) $p['cuotas_sin_intereses']
                    . ' cuotas sin intereses y en esta compra no se eligió plazo, así que el '
                    . 'importe se cargó completo a su tarjeta. Si prefiere diferirla, puede '
                    . 'hacerlo desde su banca en línea, en la pestaña «Compras a cuotas» de '
                    . 'la tarjeta, mientras el consumo siga sin facturar.</p>';
            }

            $disponible = $p['tarjeta_id'] !== null
                ? ServicioTarjeta::cupoDisponible(
                    (int) $p['limite_cent'], (int) $p['saldo_usado_cent']
                  )
                : 0;

            $cuerpo =
                '<p>Le confirmamos su compra en <strong>' . $e((string) $p['comercio'])
                . '</strong> por <strong>' . $e($dinero((int) $p['total_cent']))
                . '</strong>, cargada a su tarjeta ' . $e((string) $p['marca']) . ' ••••'
                . $e((string) $p['ultimos4']) . '.</p>'

                . '<p style="margin-top:22px"><strong>Qué compró</strong></p>'
                . $tabla
                . $bloquePlan

                . '<p style="font-size:13px;color:#667085;margin-top:22px">Si no reconoce '
                . 'esta compra, comuníquese con nosotros de inmediato indicando la '
                . 'referencia. Puede congelar la tarjeta usted mismo desde su banca en '
                . 'línea mientras lo revisamos.</p>';

            $filas = [
                ['etiqueta' => 'Pedido',     'valor' => (string) $p['folio']],
                ['etiqueta' => 'Comercio',   'valor' => (string) $p['comercio']],
                ['etiqueta' => 'Fecha',      'valor' => date('d/m/Y H:i', strtotime((string) $p['creado_en']))],
                ['etiqueta' => 'Tarjeta',    'valor' => strtoupper((string) $p['marca']) . ' ****' . $p['ultimos4']],
                ['etiqueta' => 'Subtotal',   'valor' => $dinero((int) $p['subtotal_cent'])],
                ['etiqueta' => 'Envío',      'valor' => $dinero((int) $p['envio_cent'])],
                ['etiqueta' => 'TOTAL',      'valor' => $dinero((int) $p['total_cent'])],
                ['etiqueta' => 'Forma de pago', 'valor' => (int) $p['cuotas'] > 0
                    ? (int) $p['cuotas'] . ' cuotas sin intereses'
                    : 'De contado'],
                ['etiqueta' => 'Cupo disponible', 'valor' => $dinero($disponible)],
                ['etiqueta' => 'Referencia', 'valor' => (string) $p['referencia']],
            ];

            $texto = "COMPRA REALIZADA\n\n"
                . 'Senor(a) ' . $p['cliente'] . ":\n\n"
                . 'Le confirmamos su compra en ' . $p['comercio'] . ' por '
                . $dinero((int) $p['total_cent']) . ",\n"
                . 'cargada a su tarjeta ' . strtoupper((string) $p['marca'])
                . ' ****' . $p['ultimos4'] . ".\n\n"
                . "QUE COMPRO\n";

            foreach ($lineas as $l) {
                $texto .= '  ' . (int) $l['cantidad'] . ' x ' . $l['nombre'] . '  '
                    . $dinero((int) $l['precio_cent'] * (int) $l['cantidad']) . "\n";
            }

            $texto .= "\n"
                . 'Pedido: ' . $p['folio'] . "\n"
                . 'Subtotal: ' . $dinero((int) $p['subtotal_cent']) . "\n"
                . 'Envio: ' . $dinero((int) $p['envio_cent']) . "\n"
                . 'Total: ' . $dinero((int) $p['total_cent']) . "\n"
                . 'Forma de pago: ' . ((int) $p['cuotas'] > 0
                    ? (int) $p['cuotas'] . ' cuotas sin intereses'
                    : 'de contado') . "\n"
                . 'Cupo disponible: ' . $dinero($disponible) . "\n"
                . 'Referencia: ' . $p['referencia'] . "\n";

            return (new Correo())->enviar(
                (string) $p['correo'],
                'Compra en ' . $p['comercio'] . ' · ' . $dinero((int) $p['total_cent'])
                . ((int) $p['cuotas'] > 0 ? ' a ' . (int) $p['cuotas'] . ' cuotas' : ''),
                PlantillaCorreo::base(
                    'Compra realizada',
                    'Señor(a) ' . $p['cliente'],
                    $cuerpo,
                    $filas,
                    null,
                    null,
                    null,
                    'Conserve este comprobante. El cupo disponible que se indica es el '
                    . 'que quedo tras aplicar esta compra.'
                ),
                $texto,
                (string) $p['cliente']
            );
        } catch (\Throwable $ex) {
            error_log('[Tienda] aviso de compra no enviado: ' . $ex->getMessage());
            return false;
        }
    }
    /** @param list<array<string,mixed>> $detalle */
    private function descontarExistencias(array $detalle): void
    {
        foreach ($detalle as $d) {
            BaseDatos::ejecutar(
                'UPDATE articulos SET existencias = existencias - ? WHERE id = ?',
                [$d['cantidad'], $d['id']]
            );
        }
    }

    /** @param list<array<string,mixed>> $detalle */
    private function guardarLineas(int $pedidoId, array $detalle): void
    {
        foreach ($detalle as $d) {
            BaseDatos::ejecutar(
                'INSERT INTO tienda_pedido_lineas
                   (pedido_id, articulo_id, nombre, precio_cent, cantidad)
                 VALUES (?,?,?,?,?)',
                [$pedidoId, $d['id'], $d['nombre'], $d['precio'], $d['cantidad']]
            );
        }
    }

    /**
     * El correo de quien compró sin tener cuenta.
     *
     * Un invitado no tiene portal donde recibir avisos: el correo es su
     * único canal, así que se le manda directo en lugar de pasar por el
     * servicio de notificaciones, que escribe en una tabla indexada por
     * usuario.
     *
     * @param list<array{etiqueta:string, valor:string}> $filas
     */
    /**
     * El correo de quien compró sin cuenta.
     *
     * Lleva un BOTÓN que abre el pedido directo, además del folio y la
     * clave escritos. Sin el botón, el correo decía «guarde su clave»
     * y no decía dónde usarla: quien no supiera que existe
     * `/tienda/seguimiento` se quedaba con un folio y una clave sin
     * puerta donde meterlos.
     *
     * El enlace lleva la clave dentro. Es un enlace de capacidad, como
     * el de recuperar contraseña: quien lo tenga ve ese pedido, y por
     * eso el correo también deja folio y clave por separado, para
     * quien prefiera escribirlos a mano en la pantalla de consulta.
     *
     * @param list<array{etiqueta:string,valor:string}> $filas
     */
    private function correoInvitado(
        string $para,
        string $nombre,
        string $asunto,
        string $mensaje,
        array $filas,
        string $folio = '',
        string $clave = ''
    ): void {
        try {
            $base = rtrim((string) Config::obtener("APP_URL", "http://localhost:90/cashmanha-v2"), "/");

            $enlace = $folio !== ''
                ? (
                    $base . '/tienda/pedido/' . rawurlencode($folio)
                    . ($clave !== '' ? '?clave=' . rawurlencode($clave) : '')
                  )
                : null;

            $consulta = $base . '/tienda/seguimiento';

            /* El párrafo que explica qué hacer con todo esto. Va en el
               cuerpo y no en el pie, porque el pie no lo lee nadie. */
            $cuerpo = '<p>' . htmlspecialchars($mensaje, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';

            if ($folio !== '' && $clave !== '') {
                $cuerpo .= '<p style="margin:16px 0 0">'
                    . 'Puede seguir su pedido cuando quiera, sin iniciar sesión: entre a '
                    . '<a href="' . htmlspecialchars($consulta, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    . '" style="color:#5b21b6;font-weight:600">Seguimiento de pedidos</a> '
                    . 'y escriba su folio <strong>'
                    . htmlspecialchars($folio, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    . '</strong> con la clave <strong>'
                    . htmlspecialchars($clave, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    . '</strong>. O use el botón de aquí abajo, que lo abre directo.'
                    . '</p>';
            }

            $html = PlantillaCorreo::base(
                $asunto,
                'Estimado(a) ' . ($nombre !== '' ? $nombre : 'cliente'),
                $cuerpo,
                $filas,
                null,
                $enlace !== null ? 'Ver mi pedido' : null,
                $enlace,
                'Guarde este correo: el folio y la clave son lo único que necesita '
                . 'para consultar su pedido. No los comparta con nadie más.'
            );

            /* El texto plano lleva lo mismo: hay quien lee el correo en
               un cliente que no pinta HTML, y ahí el botón no existe. */
            $texto = $mensaje;

            if ($folio !== '' && $clave !== '') {
                $texto .= "\n\nSeguimiento: " . $consulta
                        . "\nFolio: " . $folio
                        . "\nClave: " . $clave
                        . ($enlace !== null ? "\nEnlace directo: " . $enlace : '');
            }

            (new Correo())->enviar($para, $asunto, $html, $texto, $nombre);
        } catch (\Throwable $e) {
            // El pedido ya está registrado; que falle el correo no puede
            // deshacer una compra pagada.
            error_log('[Tienda] No se pudo avisar al invitado: ' . $e->getMessage());
        }
    }

    /**
     * Mueve el estado de un pedido (preparando, enviado, entregado).
     *
     * @return array<string,mixed>
     */
    /**
     * Mueve un pedido por el camino del despacho.
     *
     * @param array{transportista?:string, guia?:string,
     *              entrega_estimada?:string, nota?:string} $envio
     *        Lo que la tienda informa al despachar. Solo cuenta al
     *        pasar a «enviado»; en los demás pasos se ignora.
     * @return array<string,mixed>
     */
    public function cambiarEstado(int $pedidoId, string $estado, array $envio = []): array
    {
        Autorizacion::exigir('tienda.gestionar');
        $actorId = Sesion::exigirAutenticado();

        $validos = ['pagado', 'preparando', 'enviado', 'entregado', 'anulado'];

        if (!in_array($estado, $validos, true)) {
            throw new \InvalidArgumentException('Ese estado no existe.');
        }

        $p = BaseDatos::unaFila(
            'SELECT p.*, c.nombre AS comercio FROM tienda_pedidos p
               JOIN comercios c ON c.id = p.comercio_id
              WHERE p.id = ?',
            [$pedidoId]
        );

        if ($p === null) {
            throw new \RuntimeException('Pedido no encontrado.');
        }

        if ((string) $p['estado'] === 'anulado') {
            throw new \RuntimeException('Este pedido ya está anulado.');
        }

        $desde = (string) $p['estado'];

        /* El camino tiene un orden.
         *
         * Antes se podía saltar de «pagado» a «entregado» de un clic.
         * Eso deja un pedido que nunca estuvo en camino, al cliente sin
         * el aviso de que salió, y a la tienda sin fecha de despacho
         * cuando toca responder por qué no llegó. Se permite avanzar y
         * se permite anular; retroceder, no.
         *
         * Un pedido en «pago_pendiente» no se despacha: todavía no hay
         * dinero y la mercadería está apartada, no vendida. */
        $orden = ['pagado' => 1, 'preparando' => 2, 'enviado' => 3, 'entregado' => 4];

        if ($desde === 'pago_pendiente' && $estado !== 'anulado') {
            throw new \RuntimeException(
                'Este pedido todavía no tiene el pago confirmado. '
                . 'No se puede despachar hasta que la procesadora lo cobre.'
            );
        }

        if ($estado !== 'anulado' && isset($orden[$desde], $orden[$estado])) {
            if ($orden[$estado] <= $orden[$desde]) {
                throw new \RuntimeException(
                    'El pedido ya está en «' . $desde . '». Un pedido no vuelve atrás; '
                    . 'si hubo un error, anúlelo y deje constancia del motivo.'
                );
            }

            /* Ni a saltos.
             *
             * De «pagado» a «entregado» de un clic deja un pedido que
             * nunca estuvo en camino: sin fecha de despacho, sin
             * transportista y sin el aviso de que salió. El día que el
             * cliente reclame que no le llegó, no hay con qué
             * responderle. */
            if ($orden[$estado] > $orden[$desde] + 1) {
                $nombres = array_flip($orden);

                throw new \RuntimeException(
                    'Falta el paso anterior: de «' . $desde . '» se pasa a «'
                    . $nombres[$orden[$desde] + 1] . '», no directamente a «' . $estado . '».'
                );
            }
        }

        /* Los datos del envío. Solo al despachar: ponerlos antes sería
           prometer una guía que todavía no existe. */
        $transportista = mb_substr(trim((string) ($envio['transportista'] ?? '')), 0, 120);
        $guia          = mb_substr(trim((string) ($envio['guia'] ?? '')), 0, 60);
        $nota          = mb_substr(trim((string) ($envio['nota'] ?? '')), 0, 300);
        $estimada      = trim((string) ($envio['entrega_estimada'] ?? ''));

        if ($estimada !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $estimada) !== 1) {
            throw new \InvalidArgumentException('La fecha de entrega no tiene el formato correcto.');
        }

        if ($estado === 'enviado' && $transportista === '') {
            throw new \InvalidArgumentException(
                'Diga quién lleva el pedido. Si lo entrega su propio personal, '
                . 'escríbalo así: para quien espera en su casa, «va en camino» '
                . 'sin saber con quién no es una respuesta.'
            );
        }

        /* La marca de tiempo del paso al que se entra. Sin esto, el
           estado se sobrescribía y no quedaba cuándo pasó cada cosa:
           la primera pregunta de todo reclamo es «¿cuánto tardaron?». */
        $sello = [
            'preparando' => 'preparando_en',
            'enviado'    => 'enviado_en',
            'entregado'  => 'entregado_en',
        ][$estado] ?? null;

        BaseDatos::ejecutar(
            'UPDATE tienda_pedidos
                SET estado = ?,
                    transportista = COALESCE(NULLIF(?, ""), transportista),
                    guia          = COALESCE(NULLIF(?, ""), guia),
                    entrega_estimada = COALESCE(NULLIF(?, ""), entrega_estimada),
                    nota_despacho = COALESCE(NULLIF(?, ""), nota_despacho)'
                . ($sello !== null ? ", {$sello} = COALESCE({$sello}, NOW())" : '')
                . ' WHERE id = ?',
            [$estado, $transportista, $guia, $estimada, $nota, $pedidoId]
        );

        Auditoria::registrar('tienda.estado', Auditoria::EXITO, $actorId,
            'tienda_pedido', (string) $pedidoId,
            ['folio' => $p['folio'], 'de' => $desde, 'a' => $estado,
             'transportista' => $transportista ?: null, 'guia' => $guia ?: null]);

        $this->avisarDelDespacho($pedidoId, $estado, $transportista, $guia, $estimada, $nota);

        return [
            'folio'         => (string) $p['folio'],
            'estado'        => $estado,
            'transportista' => $transportista ?: ($p['transportista'] ?? null),
            'guia'          => $guia ?: ($p['guia'] ?? null),
        ];
    }

    /**
     * Le cuenta al comprador en qué va su pedido.
     *
     * AL INVITADO TAMBIÉN.
     *
     * Antes esto solo creaba una notificación en el portal, con
     * `(int) $p['usuario_id']`. En una compra de invitado ese campo es
     * nulo, así que el aviso se creaba para el usuario 0 —que no
     * existe— y a quien compró no le llegaba absolutamente nada: ni
     * que se estaba preparando, ni que iba en camino, ni que lo habían
     * entregado. Su única pista era entrar al seguimiento a adivinar.
     *
     * Quien no tiene portal tiene correo, y es por donde se le habla.
     */
    private function avisarDelDespacho(
        int $pedidoId,
        string $estado,
        string $transportista,
        string $guia,
        string $estimada,
        string $nota
    ): void {
        $p = BaseDatos::unaFila(
            'SELECT p.*, c.nombre AS comercio FROM tienda_pedidos p
               JOIN comercios c ON c.id = p.comercio_id
              WHERE p.id = ?',
            [$pedidoId]
        );

        if ($p === null) { return; }

        $titulos = [
            'preparando' => 'Su pedido se está preparando',
            'enviado'    => 'Su pedido va en camino',
            'entregado'  => 'Su pedido fue entregado',
            'anulado'    => 'Su pedido fue anulado',
        ];

        $cuerpos = [
            'preparando' => 'La tienda ya está alistando su pedido. Le avisamos en cuanto salga.',
            'enviado'    => 'Su pedido salió de la tienda y va en camino a la dirección '
                          . 'que nos dio.',
            'entregado'  => 'Su pedido fue entregado. ¡Que lo disfrute!',
            'anulado'    => 'Su pedido fue anulado y el cargo será revertido.',
        ];

        if (!isset($titulos[$estado])) { return; }

        $titulo  = $titulos[$estado] . ' · ' . $p['comercio'];
        $mensaje = $cuerpos[$estado];

        /* Lo concreto del envío, que es lo que de verdad se quiere
           saber. Se arma una sola vez y sirve para los dos canales. */
        $filas = [
            ['etiqueta' => 'Pedido',   'valor' => (string) $p['folio']],
            ['etiqueta' => 'Comercio', 'valor' => (string) $p['comercio']],
        ];

        $suelto = [];

        if (($p['transportista'] ?? '') !== '') {
            $filas[]   = ['etiqueta' => 'Lo lleva', 'valor' => (string) $p['transportista']];
            $suelto[]  = 'Lo lleva ' . $p['transportista'] . '.';
        }

        if (($p['guia'] ?? '') !== '') {
            $filas[]  = ['etiqueta' => 'Número de guía', 'valor' => (string) $p['guia']];
            $suelto[] = 'Guía ' . $p['guia'] . '.';
        }

        if (($p['entrega_estimada'] ?? '') !== '') {
            $fecha = date('d/m/Y', strtotime((string) $p['entrega_estimada']));
            $filas[]  = ['etiqueta' => 'Entrega estimada', 'valor' => $fecha];
            $suelto[] = 'Entrega estimada para el ' . $fecha . '.';
        }

        if (($p['nota_despacho'] ?? '') !== '') {
            $filas[]  = ['etiqueta' => 'De la tienda', 'valor' => (string) $p['nota_despacho']];
            $suelto[] = (string) $p['nota_despacho'];
        }

        $completo = $mensaje . ($suelto !== [] ? ' ' . implode(' ', $suelto) : '');

        // --- Quien tiene portal, por el portal --------------------------
        if ($p['usuario_id'] !== null) {
            $this->notificaciones->crear(
                (int) $p['usuario_id'],
                'tienda.pedido',
                $titulo,
                $completo,
                ['pedido' => (string) $p['folio'],
                 'guia'   => (string) ($p['guia'] ?? '')],
                $estado === 'anulado' ? 'alerta' : 'info'
            );

            return;
        }

        // --- Quien no, por correo ---------------------------------------
        if ((string) ($p['invitado_correo'] ?? '') === '') { return; }

        $filas[] = ['etiqueta' => 'Clave de seguimiento',
                    'valor'    => (string) $p['clave_consulta']];

        $this->correoInvitado(
            (string) $p['invitado_correo'],
            (string) $p['invitado_nombre'],
            $titulo . ' · pedido ' . $p['folio'],
            $completo,
            $filas,
            (string) $p['folio'],
            (string) $p['clave_consulta']
        );
    }
}
