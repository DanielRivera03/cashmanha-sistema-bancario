<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * El carrito de la tienda pública.
 *
 * Vive en la sesión y no en la base de datos. En una tienda donde la
 * mayoría de quien entra no tiene cuenta, guardar cada carrito abandonado
 * como filas sería acumular basura que nadie va a mirar: por cada compra
 * terminada habría veinte carritos muertos.
 *
 * Lo único que se guarda es `articulo_id => cantidad`. Ni precios ni
 * nombres: esos se vuelven a leer del catálogo en cada operación, porque
 * si se guardaran en la sesión bastaría con manipularla para comprar una
 * refrigeradora a tres dólares. El carrito dice QUÉ se quiere, nunca
 * cuánto cuesta.
 */
final class CarritoTienda
{
    private const CLAVE = '_tienda_carrito';

    /** @return array<int,int> articulo_id => cantidad */
    public function lineas(): array
    {
        $c = $_SESSION[self::CLAVE] ?? [];

        if (!is_array($c)) {
            return [];
        }

        $limpio = [];

        foreach ($c as $id => $n) {
            $id = (int) $id;
            $n  = (int) $n;

            if ($id > 0 && $n > 0) {
                $limpio[$id] = min($n, $this->tope());
            }
        }

        return $limpio;
    }

    /** Fija la cantidad de un artículo. En cero, lo saca. */
    public function fijar(int $articuloId, int $cantidad): void
    {
        $carrito = $this->lineas();

        if ($cantidad <= 0) {
            unset($carrito[$articuloId]);
        } else {
            $carrito[$articuloId] = min($cantidad, $this->tope());
        }

        $_SESSION[self::CLAVE] = $carrito;
    }

    /** Suma unidades a lo que ya hubiera. */
    public function agregar(int $articuloId, int $cantidad = 1): void
    {
        $carrito = $this->lineas();
        $this->fijar($articuloId, ($carrito[$articuloId] ?? 0) + $cantidad);
    }

    public function vaciar(): void
    {
        unset($_SESSION[self::CLAVE]);
    }

    public function unidades(): int
    {
        return array_sum($this->lineas());
    }

    /**
     * El carrito con sus artículos y totales, leídos del catálogo.
     *
     * Devuelve también los avisos: un artículo que se agotó o que dejó de
     * publicarse mientras el carrito estaba abierto. Es mejor decirlo
     * aquí que al pulsar «pagar».
     *
     * @return array<string,mixed>
     */
    public function detalle(): array
    {
        $lineas = $this->lineas();

        if ($lineas === []) {
            return [
                'lineas' => [], 'grupos' => [], 'subtotal_cent' => 0, 'envio_cent' => 0,
                'total_cent' => 0, 'unidades' => 0, 'comercio' => null,
                'comercios' => 0, 'mezcla' => false,
                'avisos' => [], 'cuotas_disponibles' => 0,
            ];
        }

        $ids   = array_keys($lineas);
        $hueco = implode(',', array_fill(0, count($ids), '?'));

        $filas = BaseDatos::todas(
            "SELECT a.id, a.sku, a.nombre, a.precio_cent, a.existencias, a.activo,
                    a.imagen, a.imagen_alt,
                    c.id AS comercio_id, c.nombre AS comercio, c.iniciales, c.color,
                    c.envio_cent, c.cuotas_sin_intereses, c.activo AS comercio_activo
               FROM articulos a JOIN comercios c ON c.id = a.comercio_id
              WHERE a.id IN ({$hueco})",
            $ids
        );

        $detalle  = [];
        $avisos   = [];
        $subtotal = 0;
        $comercio = null;

        foreach ($filas as $f) {
            $id   = (int) $f['id'];
            $cant = $lineas[$id];

            if ((int) $f['activo'] !== 1 || (int) $f['comercio_activo'] !== 1) {
                $avisos[] = '«' . $f['nombre'] . '» ya no está disponible y lo quitamos del carrito.';
                $this->fijar($id, 0);
                continue;
            }

            if ((int) $f['existencias'] < $cant) {
                if ((int) $f['existencias'] === 0) {
                    $avisos[] = '«' . $f['nombre'] . '» se agotó y lo quitamos del carrito.';
                    $this->fijar($id, 0);
                    continue;
                }

                $avisos[] = 'De «' . $f['nombre'] . '» quedan ' . (int) $f['existencias']
                    . ', así que ajustamos la cantidad.';
                $cant = (int) $f['existencias'];
                $this->fijar($id, $cant);
            }

            $importe = (int) $f['precio_cent'] * $cant;
            $subtotal += $importe;

            if ($comercio === null) {
                $comercio = [
                    'id'        => (int) $f['comercio_id'],
                    'nombre'    => (string) $f['comercio'],
                    'iniciales' => (string) $f['iniciales'],
                    'color'     => (string) $f['color'],
                    'envio_cent'=> (int) $f['envio_cent'],
                    'cuotas'    => (int) $f['cuotas_sin_intereses'],
                ];
            }

            $detalle[] = [
                'id'          => $id,
                'sku'         => (string) $f['sku'],
                'nombre'      => (string) $f['nombre'],
                'precio_cent' => (int) $f['precio_cent'],
                'cantidad'    => $cant,
                'importe_cent'=> $importe,
                'imagen'      => $f['imagen'],
                'imagen_alt'  => $f['imagen_alt'],
                'comercio'        => (string) $f['comercio'],
                'comercio_id'     => (int) $f['comercio_id'],
                // Lo que hace falta para agrupar sin volver a consultar.
                'iniciales'       => (string) $f['iniciales'],
                'color'           => (string) $f['color'],
                'envio_cent'      => (int) $f['envio_cent'],
                'cuotas_comercio' => (int) $f['cuotas_sin_intereses'],
            ];
        }

        /* Varios comercios en un mismo carrito, agrupados.
         *
         * Antes esto se rechazaba: «cada pedido es de una sola tienda,
         * deje los de una y haga la otra compra después». Eso describe
         * cómo estaba hecho por dentro, no lo que necesita quien
         * compra: si en el mismo rato quiere unas sábanas y una
         * licuadora, tiene que pagar dos veces y teclear su tarjeta dos
         * veces.
         *
         * Lo que de verdad impedía mezclarlas eran dos cosas, y las dos
         * se resuelven agrupando en lugar de prohibiendo:
         *
         *   · EL ENVÍO es de cada comercio, así que se cobra uno por
         *     comercio y se dice cuál es cuál. No se reparte ni se
         *     promedia: cada tienda despacha lo suyo.
         *
         *   · LOS MESES SIN INTERESES los pone cada comercio. Si uno
         *     ofrece 12 y otro 18, el carrito ofrece 12: prometer 18
         *     sería prometer por cuenta de quien no lo ofreció.
         *
         * El pedido sigue siendo de un comercio —eso no cambia, porque
         * cada tienda tiene que saber qué despacha—. Lo que cambia es
         * que de un carrito salen varios pedidos a la vez. */
        $grupos = [];

        foreach ($detalle as $d) {
            $c = $d['comercio_id'];

            if (!isset($grupos[$c])) {
                $grupos[$c] = [
                    'comercio_id'   => $c,
                    'nombre'        => $d['comercio'],
                    'iniciales'     => $d['iniciales'],
                    'color'         => $d['color'],
                    'envio_cent'    => $d['envio_cent'],
                    'cuotas'        => $d['cuotas_comercio'],
                    'lineas'        => [],
                    'subtotal_cent' => 0,
                ];
            }

            $grupos[$c]['lineas'][]       = $d;
            $grupos[$c]['subtotal_cent'] += $d['importe_cent'];
        }

        /* Un envío por comercio. */
        $envio = 0;

        foreach ($grupos as $id => $g) {
            $grupos[$id]['total_cent'] = $g['subtotal_cent'] + $g['envio_cent'];
            $envio += $g['envio_cent'];
        }

        $grupos = array_values($grupos);

        /* El plazo que se ofrece es el MAYOR de los del carrito, y cada
         * tienda aplica después el suyo.
         *
         * Con 18 y 15 en la misma compra se ofrecen 18: la tienda que
         * los da los da de verdad, y recortarle a 15 porque la otra no
         * llega es quitarle al cliente algo que sí estaba disponible.
         * La de 15 aplicará 15, y el desglose lo dice antes de pagar.
         *
         * Lo contrario —el menor de todos— es más fácil de explicar en
         * una frase y peor para quien compra: paga menos meses de los
         * que le ofrecían por culpa de un artículo de otra tienda. */
        $cuotas     = 0;
        $cuotasPorTienda = [];

        foreach ($grupos as $g) {
            $cuotasPorTienda[$g['comercio_id']] = $g['cuotas'];
            $cuotas = max($cuotas, $g['cuotas']);
        }

        return [
            'lineas'        => $detalle,
            'grupos'        => $grupos,
            'subtotal_cent' => $subtotal,
            'envio_cent'    => $envio,
            'total_cent'    => $subtotal + $envio,
            'unidades'      => array_sum(array_column($detalle, 'cantidad')),
            // Se conserva para quien solo quiere saber «de qué tienda
            // es»: con un único comercio sigue valiendo lo de siempre.
            'comercio'      => count($grupos) === 1 ? $comercio : null,
            'comercios'     => count($grupos),
            'mezcla'        => count($grupos) > 1,
            'avisos'        => $avisos,
            'cuotas_disponibles' => $cuotas,
            // Para el desglose: qué ofrece cada tienda y si no coinciden.
            'cuotas_por_tienda'  => $cuotasPorTienda,
            'cuotas_dispares'    => count(array_unique($cuotasPorTienda)) > 1,
        ];
    }

    private function tope(): int
    {
        return max(1, Parametros::entero('tienda.max_unidades_articulo', 20));
    }
}
