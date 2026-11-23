<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioTienda;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * La tienda de comercios afiliados.
 *
 * Es lo que le faltaba a las tarjetas para servir de algo: un sitio
 * donde usarlas. El consumo entra en la tarjeta igual que uno hecho con
 * el datáfono —con su comercio, su categoría y su referencia—, así que
 * desde aquí se puede recorrer el ciclo completo: comprar, verlo en el
 * estado de cuenta, diferirlo a cuotas y pagarlo.
 */
final class ControladorTienda extends ControladorBase
{
    public function catalogo(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        $busca    = $this->get('q');
        $comercio = $this->get('c');

        $donde = ['a.activo = 1', 'c.activo = 1'];
        $args  = [];

        if ($busca !== '') {
            $donde[] = '(a.nombre LIKE ? OR a.descripcion LIKE ? OR a.marca LIKE ?)';
            $like = '%' . $busca . '%';
            array_push($args, $like, $like, $like);
        }

        if ($comercio !== '') {
            $cid = Hashid::decodificar($comercio, 'comercio');

            if ($cid !== null) {
                $donde[] = 'c.id = ?';
                $args[]  = $cid;
            }
        }

        $articulos = BaseDatos::todas(
            'SELECT a.id, a.sku, a.nombre, a.descripcion, a.marca, a.categoria,
                    a.precio_cent, a.antes_cent, a.existencias,
                    c.id AS comercio_id, c.nombre AS comercio, c.iniciales, c.color,
                    c.cuotas_sin_intereses, c.envio_cent
               FROM articulos a JOIN comercios c ON c.id = a.comercio_id
              WHERE ' . implode(' AND ', $donde) . '
              ORDER BY c.orden, a.nombre
              LIMIT 300',
            $args
        );

        foreach ($articulos as &$a) {
            $a['codigo']          = Hashid::codificar((int) $a['id'], 'articulo');
            $a['comercio_codigo'] = Hashid::codificar((int) $a['comercio_id'], 'comercio');
            unset($a['id'], $a['comercio_id']);
        }
        unset($a);

        $comercios = BaseDatos::todas(
            'SELECT c.id, c.nombre, c.rubro, c.descripcion, c.iniciales, c.color,
                    c.cuotas_sin_intereses, c.envio_cent,
                    (SELECT COUNT(*) FROM articulos a
                      WHERE a.comercio_id = c.id AND a.activo = 1) AS articulos
               FROM comercios c WHERE c.activo = 1 ORDER BY c.orden'
        );

        foreach ($comercios as &$c) {
            $c['codigo'] = Hashid::codificar((int) $c['id'], 'comercio');
            unset($c['id']);
        }
        unset($c);

        // Las tarjetas con las que puede pagar, con su cupo disponible.
        $tarjetas = $this->tarjetasDelCliente();

        $this->render('tienda/catalogo', [
            'titulo'      => 'Tienda · CashMan H.A.',
            'encabezado'  => 'Tienda de comercios afiliados',
            'articulos'   => $articulos,
            'comercios'   => $comercios,
            'tarjetas'    => $tarjetas,
            'busca'       => $busca,
            'comercioSel' => $comercio,
            'puedeComprar'=> Autorizacion::puede('tienda.comprar'),
            'csrf'        => Csrf::token('tienda'),
        ]);
    }

    public function comprar(array $parametros = []): void
    {
        $destino = $this->enrutador->url('tienda');

        /* El carrito llega como `cant[<codigo>] = n`.
         *
         * Los códigos se decodifican aquí y no en el servicio: el
         * servicio trabaja con identificadores, y la ofuscación es un
         * asunto del transporte, no del dominio. */
        $lineas = [];

        foreach ((array) ($_POST['cant'] ?? []) as $codigo => $cantidad) {
            $id = Hashid::decodificar((string) $codigo, 'articulo');

            if ($id !== null && (int) $cantidad > 0) {
                $lineas[$id] = (int) $cantidad;
            }
        }

        try {
            $r = (new ServicioTienda())->comprar([
                'tarjeta_id' => $this->idDesde($this->post('tarjeta'), 'tarjeta'),
                'lineas'     => $lineas,
                'cuotas'     => $this->postEntero('cuotas'),
                'direccion'  => $this->post('direccion'),
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $this->responderSegunCliente(
            true,
            '¡Listo! Su compra en ' . $r['comercio'] . ' por '
            . ($r['total']['formateado'] ?? '') . ' quedó registrada con el folio '
            . $r['folio'] . '.'
            . ($r['cuotas'] > 0 ? ' Queda a ' . $r['cuotas'] . ' cuotas sin intereses.' : ''),
            $this->enrutador->url('tienda/pedidos'),
            $r + ['redirigir' => $this->enrutador->url('tienda/pedidos')]
        );
    }

    public function pedidos(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $todos = $this->get('todos') === '1'
            && Autorizacion::puede('tienda.ver_pedidos');

        /* Se traen todos —con un tope alto— porque la pantalla filtra
           por periodo y estado y pagina de diez en diez: con cien filas
           cortadas, elegir «marzo» enseñaba una lista vacía aunque
           marzo tuviera pedidos. */
        $pedidos = BaseDatos::todas(
            'SELECT p.id, p.folio, p.total_cent, p.subtotal_cent, p.envio_cent,
                    p.cuotas, p.estado, p.cerrado_motivo, p.cerrado_en,
                    p.referencia, p.creado_en, p.direccion,
                    p.transportista, p.guia, p.entrega_estimada, p.nota_despacho,
                    p.preparando_en, p.enviado_en, p.entregado_en,
                    p.pago_pasarela_id, p.movimiento_id,
                    c.nombre AS comercio, c.iniciales, c.color,
                    t.ultimos4, t.marca,
                    /* Quién compró. Una compra de invitado no tiene
                       usuario, y con el JOIN interno desaparecía de
                       «Todos los pedidos»: quien atiende no veía las
                       compras del público, que son precisamente las
                       que más preguntas generan. */
                    COALESCE(CONCAT(u.nombres," ",u.apellidos),
                             p.invitado_nombre, "Invitado") AS cliente,
                    g.estado AS cobro_estado, g.detalle_error AS cobro_error
               FROM tienda_pedidos p
               JOIN comercios c ON c.id = p.comercio_id
          LEFT JOIN usuarios  u ON u.id = p.usuario_id
          LEFT JOIN tarjetas t ON t.id = p.tarjeta_id
          LEFT JOIN pagos_pasarela g ON g.id = p.pago_pasarela_id
              WHERE ' . ($todos ? '1=1' : 'p.usuario_id = ?') . '
              ORDER BY p.id DESC LIMIT 400',
            $todos ? [] : [$usuarioId]
        );

        foreach ($pedidos as &$p) {
            $p['codigo'] = Hashid::codificar((int) $p['id'], 'pedido');

            $p['lineas'] = BaseDatos::todas(
                'SELECT nombre, precio_cent, cantidad FROM tienda_pedido_lineas
                  WHERE pedido_id = ? ORDER BY id',
                [(int) $p['id']]
            );

            /* El comprobante de la compra, según cómo se pagó.
             *
             * PAGADA CON TARJETA DE FUERA  el cobro de la pasarela dejó
             *   su asiento en el libro mayor, y de ahí sale el mismo PDF
             *   que se descarga desde la banca.
             *
             * PAGADA CON TARJETA DE LA CASA  no hay asiento: un consumo
             *   de tarjeta no mueve el libro mayor hasta el corte. La
             *   constancia de esa compra es el estado de cuenta de la
             *   tarjeta, y eso es lo que se le dice en lugar de ofrecer
             *   un botón que no llevaría a ninguna parte.
             *
             * El enlace NO se busca por la referencia del pedido: esa es
             * la del cargo en la tarjeta («CMP-…»), no la del asiento, y
             * buscar por ella no encontraba nunca nada. */
            $p['comprobante'] = null;
            $p['enEstadoDeCuenta'] = false;

            $vivo = in_array((string) $p['estado'],
                ['pagado', 'preparando', 'enviado', 'entregado'], true);

            if ($vivo && (int) ($p['pago_pasarela_id'] ?? 0) > 0) {
                $asiento = BaseDatos::unValor(
                    'SELECT asiento_id FROM pagos_pasarela WHERE id = ?',
                    [(int) $p['pago_pasarela_id']]
                );

                if ($asiento !== null && (int) $asiento > 0) {
                    $p['comprobante'] = Hashid::codificar((int) $asiento, 'asiento');
                }
            } elseif ($vivo && (int) ($p['movimiento_id'] ?? 0) > 0) {
                $p['enEstadoDeCuenta'] = true;
            }

            unset($p['id'], $p['pago_pasarela_id'], $p['movimiento_id']);
        }
        unset($p);

        $this->render('tienda/pedidos', [
            'titulo'      => 'Mis pedidos · CashMan H.A.',
            'encabezado'  => $todos ? 'Pedidos de la tienda' : 'Mis pedidos',
            'pedidos'     => $pedidos,
            'todos'       => $todos,
            'puedeVerTodos'  => Autorizacion::puede('tienda.ver_pedidos'),
            'puedeGestionar' => Autorizacion::puede('tienda.gestionar'),
            'csrf'        => Csrf::token('tienda'),
        ]);
    }

    public function estado(array $parametros = []): void
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'pedido');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Pedido no encontrado.');
        }

        $destino = $this->enrutador->url('tienda/pedidos?todos=1');

        try {
            /* Los datos del envío viajan con el cambio de estado, no
               aparte: despachar y decir quién lo lleva son el mismo
               acto, y separarlos deja pedidos «enviados» sin nadie que
               los lleve mientras alguien se acuerda de completarlos. */
            $r = (new ServicioTienda())->cambiarEstado($id, $this->post('estado'), [
                'transportista'    => $this->post('transportista'),
                'guia'             => $this->post('guia'),
                'entrega_estimada' => $this->post('entrega_estimada'),
                'nota'             => $this->post('nota_despacho'),
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $comoSeLeDijo = $r['estado'] === 'enviado' && ($r['guia'] ?? '') !== ''
            ? ' Se le mandó la guía ' . $r['guia'] . '.'
            : ' Se avisó al cliente.';

        $this->responderSegunCliente(
            true,
            'Pedido ' . $r['folio'] . ' marcado como ' . $r['estado'] . '.' . $comoSeLeDijo,
            $destino,
            $r
        );
    }

    // -----------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function tarjetasDelCliente(): array
    {
        $usuarioId = Sesion::usuarioId();

        if ($usuarioId === null) {
            return [];
        }

        $filas = BaseDatos::todas(
            'SELECT id, ultimos4, marca, limite_cent, saldo_usado_cent,
                    sobregiro_permitido, sobregiro_tolerancia_bp
               FROM tarjetas
              WHERE usuario_id = ? AND tipo = "credito" AND estado = "activa"
              ORDER BY id',
            [$usuarioId]
        );

        foreach ($filas as &$t) {
            $t['codigo'] = Hashid::codificar((int) $t['id'], 'tarjeta');

            $t['disponible_cent'] = max(
                0,
                (int) $t['limite_cent'] - (int) $t['saldo_usado_cent']
            );

            // Lo que de verdad se puede gastar: el cupo más el sobregiro
            // que el titular haya autorizado.
            $t['tope_cent'] = $t['disponible_cent'] + ((int) $t['sobregiro_permitido'] === 1
                ? (int) round((int) $t['limite_cent'] * (int) $t['sobregiro_tolerancia_bp'] / 10000)
                : 0);

            unset($t['id']);
        }
        unset($t);

        return $filas;
    }
}
