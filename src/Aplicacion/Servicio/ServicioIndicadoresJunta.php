<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * Los números del banco para la junta directiva.
 *
 * QUÉ ES Y QUÉ NO ES
 *
 * Es una lectura. No escribe nada, no mueve nada y no admite
 * parámetros que el usuario controle: todas las consultas son fijas.
 * Un directivo entra, mira y se va.
 *
 * Y es AGREGADA a propósito. La junta decide sobre el banco, no sobre
 * clientes concretos: para gobernar no hace falta —ni conviene— ver
 * el saldo de doña Rosa. Lo único que baja al detalle son los
 * mayores deudores, porque una concentración de riesgo en pocas manos
 * es justo el tipo de cosa que un consejo tiene que saber, y aun ahí
 * se enseña el nombre y el monto, no el expediente.
 *
 * DE DÓNDE SALEN LOS NÚMEROS
 *
 * De las mismas tablas que usa la operación, no de un resumen que
 * alguien actualiza aparte. Un tablero que se alimenta de su propia
 * copia de los datos acaba contando una historia distinta de la que
 * cuenta el sistema, y cuando no coinciden nadie sabe cuál creer.
 *
 * La contrapartida es que cada carga cuesta unas cuantas consultas de
 * agregación. Para una pantalla que se mira unas veces al día es un
 * precio que vale la pena pagar a cambio de que los números sean los
 * de verdad.
 */
final class ServicioIndicadoresJunta
{
    /**
     * Todo lo que la pantalla necesita, en una llamada.
     *
     * @return array<string,mixed>
     */
    public function todo(): array
    {
        return [
            'captaciones' => $this->captaciones(),
            'cartera'     => $this->cartera(),
            'morosidad'   => $this->morosidad(),
            'tarjetas'    => $this->tarjetas(),
            'clientes'    => $this->clientes(),
            'mes'         => $this->delMes(),
            'mayores'     => $this->mayoresDeudores(),
            'libro'       => $this->libroMayor(),
            'generado_en' => date('d/m/Y H:i'),
        ];
    }

    /**
     * El dinero del público que el banco custodia.
     *
     * Se excluyen las cuentas técnicas: son del propio sistema —la de
     * efectivo, la de comercios por pagar— y contarlas como depósitos
     * de clientes infla la cifra con dinero que no es de nadie de
     * fuera.
     *
     * @return array<string,mixed>
     */
    public function captaciones(): array
    {
        $f = BaseDatos::unaFila(
            'SELECT COUNT(*) AS cuentas,
                    COALESCE(SUM(saldo_cent), 0) AS saldo,
                    COALESCE(SUM(saldo_retenido_cent), 0) AS retenido,
                    SUM(estado = "activa") AS activas
               FROM cuentas
              WHERE es_tecnica = 0 AND estado <> "cerrada"'
        );

        $porProducto = BaseDatos::todas(
            'SELECT p.nombre, COUNT(*) AS cuentas, COALESCE(SUM(c.saldo_cent), 0) AS saldo
               FROM cuentas c
               JOIN productos p ON p.id = c.producto_id
              WHERE c.es_tecnica = 0 AND c.estado <> "cerrada"
              GROUP BY p.id
              ORDER BY saldo DESC'
        );

        return [
            'saldo'       => Monto::deCentavos((int) $f['saldo']),
            'retenido'    => Monto::deCentavos((int) $f['retenido']),
            'cuentas'     => (int) $f['cuentas'],
            'activas'     => (int) $f['activas'],
            'por_producto' => array_map(
                static fn(array $p): array => [
                    'nombre'  => (string) $p['nombre'],
                    'cuentas' => (int) $p['cuentas'],
                    'saldo'   => Monto::deCentavos((int) $p['saldo']),
                ],
                $porProducto
            ),
        ];
    }

    /**
     * Lo que el banco tiene prestado.
     *
     * `saldo_capital_cent` es capital, sin intereses futuros: es lo
     * que de verdad está afuera. Sumar la deuda total con sus
     * intereses daría una cartera mayor de la que existe hoy.
     *
     * @return array<string,mixed>
     */
    public function cartera(): array
    {
        $f = BaseDatos::unaFila(
            'SELECT COUNT(*) AS creditos,
                    COALESCE(SUM(saldo_capital_cent), 0) AS capital,
                    COALESCE(SUM(monto_cent), 0) AS otorgado,
                    COALESCE(SUM(total_pagado_cent), 0) AS pagado
               FROM creditos
              WHERE estado IN ("vigente","desembolsado","mora")'
        );

        $porProducto = BaseDatos::todas(
            'SELECT p.nombre, COUNT(*) AS creditos,
                    COALESCE(SUM(c.saldo_capital_cent), 0) AS capital
               FROM creditos c
               JOIN productos p ON p.id = c.producto_id
              WHERE c.estado IN ("vigente","desembolsado","mora")
              GROUP BY p.id
              ORDER BY capital DESC'
        );

        return [
            'capital'  => Monto::deCentavos((int) $f['capital']),
            'otorgado' => Monto::deCentavos((int) $f['otorgado']),
            'pagado'   => Monto::deCentavos((int) $f['pagado']),
            'creditos' => (int) $f['creditos'],
            'por_producto' => array_map(
                static fn(array $p): array => [
                    'nombre'   => (string) $p['nombre'],
                    'creditos' => (int) $p['creditos'],
                    'capital'  => Monto::deCentavos((int) $p['capital']),
                ],
                $porProducto
            ),
        ];
    }

    /**
     * La parte de la cartera que no está pagando.
     *
     * El índice se calcula sobre el CAPITAL de los créditos con alguna
     * cuota vencida, no sobre el importe de las cuotas atrasadas. Es
     * la forma estándar y la prudente: un crédito que dejó de pagar
     * está en riesgo entero, no solo por la cuota que falló.
     *
     * @return array<string,mixed>
     */
    public function morosidad(): array
    {
        $f = BaseDatos::unaFila(
            'SELECT COUNT(DISTINCT c.id) AS creditos,
                    COALESCE(SUM(DISTINCT_CAPITAL.capital), 0) AS capital
               FROM creditos c
               JOIN (
                     SELECT cr.id, cr.saldo_capital_cent AS capital
                       FROM creditos cr
                      WHERE cr.estado IN ("vigente","desembolsado","mora")
                        AND EXISTS (
                              SELECT 1 FROM cuotas q
                               WHERE q.credito_id = cr.id
                                 AND q.estado NOT IN ("pagada","condonada")
                                 AND q.fecha_vencimiento < CURDATE()
                            )
                    ) AS DISTINCT_CAPITAL ON DISTINCT_CAPITAL.id = c.id'
        );

        $cuotas = BaseDatos::unaFila(
            'SELECT COUNT(*) AS cuotas,
                    COALESCE(SUM(q.total_cent - q.pagado_cent), 0) AS pendiente,
                    COALESCE(SUM(q.mora_cent), 0) AS recargo,
                    COALESCE(MAX(DATEDIFF(CURDATE(), q.fecha_vencimiento)), 0) AS peor
               FROM cuotas q
               JOIN creditos c ON c.id = q.credito_id
              WHERE q.estado NOT IN ("pagada","condonada")
                AND q.fecha_vencimiento < CURDATE()
                AND c.estado IN ("vigente","desembolsado","mora")'
        );

        $total = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(saldo_capital_cent), 0) FROM creditos
              WHERE estado IN ("vigente","desembolsado","mora")'
        );

        $enMora = (int) $f['capital'];

        /* Por tramos: no es lo mismo treinta días que trescientos. Son
           los cortes que usa la clasificación de cartera y los que
           anticipan si la mora es un tropiezo o una pérdida. */
        $tramos = BaseDatos::todas(
            'SELECT CASE
                      WHEN DATEDIFF(CURDATE(), q.fecha_vencimiento) <= 30  THEN "1 a 30 dias"
                      WHEN DATEDIFF(CURDATE(), q.fecha_vencimiento) <= 90  THEN "31 a 90 dias"
                      WHEN DATEDIFF(CURDATE(), q.fecha_vencimiento) <= 180 THEN "91 a 180 dias"
                      ELSE "mas de 180 dias"
                    END AS tramo,
                    COUNT(*) AS cuotas,
                    COALESCE(SUM(q.total_cent - q.pagado_cent), 0) AS pendiente
               FROM cuotas q
               JOIN creditos c ON c.id = q.credito_id
              WHERE q.estado NOT IN ("pagada","condonada")
                AND q.fecha_vencimiento < CURDATE()
                AND c.estado IN ("vigente","desembolsado","mora")
              GROUP BY tramo
              ORDER BY MIN(DATEDIFF(CURDATE(), q.fecha_vencimiento))'
        );

        return [
            'capital_en_mora' => Monto::deCentavos($enMora),
            'creditos'        => (int) $f['creditos'],
            'cuotas'          => (int) $cuotas['cuotas'],
            'pendiente'       => Monto::deCentavos((int) $cuotas['pendiente']),
            'recargo'         => Monto::deCentavos((int) $cuotas['recargo']),
            'peor_atraso'     => (int) $cuotas['peor'],
            /* El índice, en porcentaje con dos decimales. Si no hay
               cartera no es cero: es que no hay nada que medir, y
               enseñar «0,00 %» ahí haría pensar que todo va bien. */
            'indice'          => $total > 0 ? round($enMora * 100 / $total, 2) : null,
            'tramos'          => array_map(
                static fn(array $t): array => [
                    'tramo'     => (string) $t['tramo'],
                    'cuotas'    => (int) $t['cuotas'],
                    'pendiente' => Monto::deCentavos((int) $t['pendiente']),
                ],
                $tramos
            ),
        ];
    }

    /** @return array<string,mixed> */
    public function tarjetas(): array
    {
        $f = BaseDatos::unaFila(
            'SELECT COUNT(*) AS emitidas,
                    SUM(estado = "activa") AS activas,
                    COALESCE(SUM(CASE WHEN tipo = "credito" THEN limite_cent ELSE 0 END), 0) AS linea,
                    COALESCE(SUM(CASE WHEN tipo = "credito" THEN saldo_usado_cent ELSE 0 END), 0) AS usado
               FROM tarjetas
              WHERE estado <> "cancelada"'
        );

        $linea = (int) $f['linea'];
        $usado = (int) $f['usado'];

        return [
            'emitidas' => (int) $f['emitidas'],
            'activas'  => (int) $f['activas'],
            'linea'    => Monto::deCentavos($linea),
            'usado'    => Monto::deCentavos($usado),
            /* Cuánto de la línea otorgada está realmente consumido. Es
               el número que dice si el riesgo está tomado o solo
               ofrecido. */
            'uso'      => $linea > 0 ? round($usado * 100 / $linea, 2) : null,
        ];
    }

    /** @return array<string,mixed> */
    public function clientes(): array
    {
        $f = BaseDatos::unaFila(
            'SELECT COUNT(*) AS total,
                    SUM(u.estado = "activo") AS activos,
                    SUM(u.creado_en >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS nuevos,
                    SUM(d.perfil_declarado_en IS NOT NULL) AS declarados
               FROM usuarios u
               JOIN roles r ON r.id = u.rol_id
          LEFT JOIN detalle_usuarios d ON d.usuario_id = u.id
              WHERE r.clave = "cliente"'
        );

        return [
            'total'      => (int) $f['total'],
            'activos'    => (int) $f['activos'],
            'nuevos'     => (int) $f['nuevos'],
            'declarados' => (int) $f['declarados'],
        ];
    }

    /**
     * El movimiento del mes en curso.
     *
     * Los importes se toman del libro mayor, que es la única fuente
     * que no se puede tocar sin dejar rastro.
     *
     * @return array<string,mixed>
     */
    public function delMes(): array
    {
        $filas = BaseDatos::todas(
            'SELECT a.tipo, COUNT(*) AS operaciones,
                    COALESCE(SUM(CASE WHEN m.importe_cent > 0 THEN m.importe_cent ELSE 0 END), 0) AS monto
               FROM asientos a
               JOIN movimientos m ON m.asiento_id = a.id
              WHERE a.creado_en >= DATE_FORMAT(CURDATE(), "%Y-%m-01")
              GROUP BY a.tipo
              ORDER BY monto DESC'
        );

        return array_map(
            static fn(array $f): array => [
                'tipo'         => (string) $f['tipo'],
                'operaciones'  => (int) $f['operaciones'],
                'monto'        => Monto::deCentavos((int) $f['monto']),
            ],
            $filas
        );
    }

    /**
     * Los mayores deudores.
     *
     * Es el único punto donde esta pantalla baja al cliente concreto,
     * y por una razón: la concentración de riesgo en pocas manos es lo
     * que hunde a una institución, y eso no se ve en un total. Se
     * enseña nombre y monto, no el expediente.
     *
     * @return list<array<string,mixed>>
     */
    public function mayoresDeudores(int $cuantos = 8): array
    {
        $cuantos = max(1, min(25, $cuantos));

        $filas = BaseDatos::todas(
            'SELECT CONCAT(u.nombres, " ", u.apellidos) AS cliente,
                    COUNT(c.id) AS creditos,
                    COALESCE(SUM(c.saldo_capital_cent), 0) AS capital,
                    MAX(CASE WHEN EXISTS (
                          SELECT 1 FROM cuotas q
                           WHERE q.credito_id = c.id
                             AND q.estado NOT IN ("pagada","condonada")
                             AND q.fecha_vencimiento < CURDATE()
                        ) THEN 1 ELSE 0 END) AS en_mora
               FROM creditos c
               JOIN usuarios u ON u.id = c.usuario_id
              WHERE c.estado IN ("vigente","desembolsado","mora")
              GROUP BY u.id
              ORDER BY capital DESC
              LIMIT ' . $cuantos
        );

        $total = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(saldo_capital_cent), 0) FROM creditos
              WHERE estado IN ("vigente","desembolsado","mora")'
        );

        return array_map(
            static fn(array $f): array => [
                'cliente'  => (string) $f['cliente'],
                'creditos' => (int) $f['creditos'],
                'capital'  => Monto::deCentavos((int) $f['capital']),
                'en_mora'  => (int) $f['en_mora'] === 1,
                'peso'     => $total > 0 ? round((int) $f['capital'] * 100 / $total, 2) : 0.0,
            ],
            $filas
        );
    }

    /**
     * El libro mayor, en una línea.
     *
     * Es la comprobación que importa: en partida doble, la suma de
     * TODOS los movimientos tiene que dar exactamente cero. Si no da,
     * hay un asiento descuadrado y los demás números de esta pantalla
     * no se pueden creer del todo.
     *
     * Se enseña aquí, y no escondido en un verificador de consola,
     * porque es lo que le permite a un directivo saber si lo que está
     * leyendo se sostiene.
     *
     * @return array<string,mixed>
     */
    public function libroMayor(): array
    {
        $suma = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(importe_cent), 0) FROM movimientos'
        );

        $f = BaseDatos::unaFila(
            'SELECT COUNT(*) AS asientos,
                    MIN(creado_en) AS desde,
                    MAX(creado_en) AS hasta
               FROM asientos'
        );

        $descuadrados = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM (
                 SELECT a.id
                   FROM asientos a
                   JOIN movimientos m ON m.asiento_id = a.id
                  GROUP BY a.id
                 HAVING SUM(m.importe_cent) <> 0
             ) AS x'
        );

        $tecnicas = BaseDatos::todas(
            'SELECT numero_cuenta, saldo_cent
               FROM cuentas WHERE es_tecnica = 1 ORDER BY numero_cuenta'
        );

        return [
            'cuadra'       => $suma === 0 && $descuadrados === 0,
            'descuadre'    => Monto::deCentavos($suma),
            'asientos'     => (int) $f['asientos'],
            'descuadrados' => $descuadrados,
            'desde'        => $f['desde'],
            'hasta'        => $f['hasta'],
            'tecnicas'     => array_map(
                static fn(array $t): array => [
                    'numero' => (string) $t['numero_cuenta'],
                    'saldo'  => Monto::deCentavos((int) $t['saldo_cent']),
                ],
                $tecnicas
            ),
        ];
    }
}
