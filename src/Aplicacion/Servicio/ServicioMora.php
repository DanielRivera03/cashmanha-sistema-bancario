<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Credito\Mora;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Devengo del recargo por mora.
 *
 * Hasta ahora la mora solo se calculaba en el instante de cobrar una
 * cuota atrasada. Eso dejaba la cartera mintiendo: 105 cuotas vencidas,
 * algunas con más de mil quinientos días de atraso, y `mora_cent` en
 * cero en todas. Quien miraba el listado veía una cartera sin recargos
 * que al cobrar aparecían de la nada.
 *
 * Este proceso recorre las cuotas vencidas y anota el cargo de cada día
 * en `mora_devengos`, que es lo que permite explicar después por qué una
 * cuota tiene el recargo que tiene.
 *
 * Tres decisiones que conviene dejar dichas:
 *
 * 1. Se devenga por día, no de golpe. Si el proceso lleva semanas sin
 *    correr, rellena los días que faltan uno a uno. La clave única
 *    (cuota, fecha) hace que volver a ejecutarlo no duplique nada, así
 *    que se puede lanzar dos veces el mismo día sin miedo.
 *
 * 2. No genera asientos contables. Un recargo devengado y no cobrado no
 *    es ingreso todavía; se reconoce cuando entra el dinero, que es lo
 *    que ya hace el cobro de la cuota. Anotarlo como ingreso aquí
 *    inflaría los resultados con plata que nadie ha pagado.
 *
 * 3. El recargo se topa al saldo de la propia cuota. Sin tope, 600 días
 *    de atraso sobre una cuota de $155 darían $3,594 de recargo.
 */
final class ServicioMora
{
    /**
     * Devenga la mora pendiente hasta la fecha indicada.
     *
     * @param string|null $hasta  Fecha límite (Y-m-d). Por defecto, hoy.
     * @param bool $simular       Calcula y reporta sin escribir nada.
     *
     * @return array{cuotas:int, dias:int, cargo_cent:int, detalle:array<int,array<string,mixed>>}
     */
    public function devengar(?string $hasta = null, bool $simular = false): array
    {
        $hasta = $hasta !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) === 1
            ? $hasta
            : date('Y-m-d');

        $cargoDiario = Mora::cargoDeUnDia();
        $gracia      = max(0, Parametros::entero('mora.dias_gracia', 0));

        if ($cargoDiario === 0) {
            return ['cuotas' => 0, 'dias' => 0, 'cargo_cent' => 0, 'detalle' => []];
        }

        // Cuotas no pagadas cuyo vencimiento ya pasó el periodo de
        // gracia. `pagado_cent` entra en la cuenta porque una cuota
        // parcialmente abonada sigue generando recargo sobre lo que
        // falta, no sobre el total.
        $cuotas = BaseDatos::todas(
            "SELECT q.id, q.credito_id, q.numero, q.fecha_vencimiento,
                    q.total_cent, q.pagado_cent, q.mora_cent, q.estado,
                    cr.folio, cr.usuario_id,
                    DATEDIFF(?, q.fecha_vencimiento) AS dias_atraso
               FROM cuotas   q
               JOIN creditos cr ON cr.id = q.credito_id
              WHERE q.estado NOT IN ('pagada','condonada')
                AND q.fecha_vencimiento < DATE_SUB(?, INTERVAL ? DAY)
              ORDER BY q.fecha_vencimiento, q.id",
            [$hasta, $hasta, $gracia]
        );

        $resumen = ['cuotas' => 0, 'dias' => 0, 'cargo_cent' => 0, 'detalle' => []];

        foreach ($cuotas as $q) {
            $pendiente = max(0, (int) $q['total_cent'] - (int) $q['pagado_cent']);
            if ($pendiente <= 0) {
                continue;
            }

            $corte = new \DateTimeImmutable($hasta);

            // Primer día que genera cargo: el siguiente al vencimiento,
            // más los días de gracia.
            $primerDia = (new \DateTimeImmutable((string) $q['fecha_vencimiento']))
                ->add(new \DateInterval('P' . ($gracia + 1) . 'D'));

            // Freno contra el cobro retroactivo.
            //
            // Sin esto, la primera ejecución sobre datos migrados cobraba
            // de una sentada todo el atraso histórico: en esta base eran
            // $260,129.50 repartidos en 105 cuotas, por años en los que el
            // sistema ni siquiera calculaba mora. Más que duplicaba la
            // deuda de varios clientes de un día para otro.
            //
            // El devengo arranca en la fecha que fije la institución.
            // Quien de verdad quiera cobrar lo anterior, mueve el
            // parámetro a una fecha antigua, pero que sea una decisión
            // tomada y no un descuido.
            $desde = Parametros::obtener('mora.devengar_desde', '');

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) === 1) {
                $arranque = new \DateTimeImmutable($desde);
                if ($arranque > $primerDia) {
                    $primerDia = $arranque;
                }
            }

            if ($primerDia > $corte) {
                continue;
            }

            // Días con cargo, contados por calendario y ambos extremos
            // incluidos. Contarlos sobre el atraso total y no sobre las
            // fechas reales fue el error de la primera versión: anotaba
            // los días de 2026 con fecha de 2022.
            $diasCobrables = (int) $primerDia->diff($corte)->days + 1;

            // Días ya anotados: lo que falta es la diferencia.
            $yaDevengados = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM mora_devengos WHERE cuota_id = ? AND fecha <= ?',
                [(int) $q['id'], $hasta]
            );

            $porDevengar = $diasCobrables - $yaDevengados;
            if ($porDevengar <= 0) {
                continue;
            }

            $acumulado = (int) $q['mora_cent'];
            $topeTotal = Mora::aplicarTope($diasCobrables * $cargoDiario, $pendiente);

            // Si el tope ya se alcanzó, no hay nada que añadir aunque
            // sigan pasando los días.
            if ($acumulado >= $topeTotal) {
                continue;
            }

            $nuevoAcumulado = $topeTotal;
            $incremento     = $nuevoAcumulado - $acumulado;

            $resumen['cuotas']++;
            $resumen['dias']       += $porDevengar;
            $resumen['cargo_cent'] += $incremento;
            $resumen['detalle'][]   = [
                'folio'      => $q['folio'],
                'cuota'      => (int) $q['numero'],
                'vencio'     => $q['fecha_vencimiento'],
                'dias'       => $diasCobrables,
                'nuevos'     => $porDevengar,
                'incremento' => $incremento,
                'acumulado'  => $nuevoAcumulado,
            ];

            if ($simular) {
                continue;
            }

            $this->anotar(
                (int) $q['id'],
                (int) $q['credito_id'],
                $primerDia->format('Y-m-d'),
                $hasta,
                $cargoDiario,
                $nuevoAcumulado,
                $diasCobrables,
                (string) $q['estado']
            );
        }

        if (!$simular && $resumen['cuotas'] > 0) {
            Auditoria::registrar(
                'mora.devengada',
                Auditoria::EXITO,
                // En consola no hay sesión que consultar: pedirla hace que
                // PHP intente abrir una y falle. El proceso programado no
                // tiene autor, y eso es justo lo que debe quedar anotado.
                PHP_SAPI === 'cli' ? null : Sesion::usuarioId(),
                'mora',
                $hasta,
                [
                    'cuotas'     => $resumen['cuotas'],
                    'dias'       => $resumen['dias'],
                    'cargo'      => number_format($resumen['cargo_cent'] / 100, 2),
                    'cargo_diario' => number_format($cargoDiario / 100, 2),
                ]
            );
        }

        return $resumen;
    }

    /**
     * Escriba el devengo de una cuota.
     *
     * Va en transacción porque son dos escrituras que tienen que ir
     * juntas: el rastro día a día y el acumulado de la cuota. Si se
     * quedara a medias, el listado y el detalle dirían cosas distintas.
     */
    private function anotar(
        int $cuotaId,
        int $creditoId,
        string $primerDia,
        string $hasta,
        int $cargoDiario,
        int $nuevoAcumulado,
        int $diasCobrables,
        string $estadoActual
    ): void {
        BaseDatos::enTransaccion(function () use (
            $cuotaId, $creditoId, $primerDia, $hasta,
            $cargoDiario, $nuevoAcumulado, $diasCobrables, $estadoActual
        ) {
            $fecha = new \DateTimeImmutable($primerDia);
            $corte = new \DateTimeImmutable($hasta);
            $dia   = 0;

            // Un renglón por cada día del calendario, con su fecha real.
            // Calcular estas fechas a partir del atraso total —y no del
            // primer día que de verdad genera cargo— fue el error de la
            // primera versión: anotaba los días de 2026 con fecha de 2022.
            while ($fecha <= $corte) {
                $dia++;

                // INSERT IGNORE y no un INSERT a secas: si dos ejecuciones
                // se cruzan, la clave única (cuota, fecha) rechaza la
                // repetida en lugar de reventar el proceso entero.
                BaseDatos::ejecutar(
                    'INSERT IGNORE INTO mora_devengos
                       (cuota_id, credito_id, fecha, dias_atraso, cargo_cent, acumulado_cent)
                     VALUES (?,?,?,?,?,?)',
                    [
                        $cuotaId, $creditoId, $fecha->format('Y-m-d'),
                        $dia, $cargoDiario,
                        min($dia * $cargoDiario, $nuevoAcumulado),
                    ]
                );

                $fecha = $fecha->add(new \DateInterval('P1D'));
            }

            // El acumulado de la cuota y su estado. Solo se marca como
            // "vencida" si no estaba ya en "parcial": un abono parcial es
            // información que no conviene perder.
            $estado = $estadoActual === 'parcial' ? 'parcial' : 'vencida';

            BaseDatos::ejecutar(
                'UPDATE cuotas
                    SET mora_cent = ?, dias_mora = ?, estado = ?
                  WHERE id = ?',
                [$nuevoAcumulado, $diasCobrables, $estado, $cuotaId]
            );
        });
    }

    /**
     * Condona total o parcialmente el recargo de una cuota.
     *
     * Hace falta porque la mora se devenga sola y a veces el atraso no
     * es culpa del cliente: un pago que se traspapeló, un error de la
     * institución. Sin esto, la única salida sería editar la tabla a
     * mano, que es justo lo que no debe pasar con dinero.
     *
     * No borra los devengos: queda el rastro de lo que se generó y de
     * quién decidió perdonarlo.
     */
    public function condonar(int $cuotaId, int $dejarEnCent, string $motivo): array
    {
        $actorId = Sesion::exigirAutenticado();

        if (trim($motivo) === '') {
            throw new \InvalidArgumentException('Indique el motivo de la condonación.');
        }

        return BaseDatos::enTransaccion(function () use ($cuotaId, $dejarEnCent, $motivo, $actorId) {
            $q = BaseDatos::unaFila(
                'SELECT q.id, q.numero, q.mora_cent, cr.folio, cr.usuario_id
                   FROM cuotas q JOIN creditos cr ON cr.id = q.credito_id
                  WHERE q.id = ? FOR UPDATE',
                [$cuotaId]
            );

            if ($q === null) {
                throw new \RuntimeException('Cuota no encontrada.');
            }

            $antes  = (int) $q['mora_cent'];
            $dejar  = max(0, min($dejarEnCent, $antes));
            $perdon = $antes - $dejar;

            if ($perdon <= 0) {
                throw new \RuntimeException('No hay recargo que condonar en esa cuota.');
            }

            BaseDatos::ejecutar('UPDATE cuotas SET mora_cent = ? WHERE id = ?', [$dejar, $cuotaId]);

            Auditoria::registrar('mora.condonada', Auditoria::EXITO, $actorId,
                'cuota', (string) $cuotaId, [
                    'folio'     => $q['folio'],
                    'cuota'     => (int) $q['numero'],
                    'antes'     => number_format($antes / 100, 2),
                    'queda_en'  => number_format($dejar / 100, 2),
                    'condonado' => number_format($perdon / 100, 2),
                    'motivo'    => mb_substr($motivo, 0, 300),
                ]);

            // Al cliente se le avisa: es una decisión a su favor y tiene
            // que poder verla sin preguntar.
            (new ServicioNotificacion())->crear(
                (int) $q['usuario_id'],
                'mora.condonada',
                'Le condonamos un recargo por mora',
                'Hemos condonado $' . number_format($perdon / 100, 2) . ' de recargo en la cuota '
                . (int) $q['numero'] . ' de su crédito ' . $q['folio'] . '. Quedamos a sus órdenes.',
                ['folio' => $q['folio'], 'cuota' => (int) $q['numero']],
                'exito'
            );

            return ['antes' => $antes, 'queda_en' => $dejar, 'condonado' => $perdon];
        });
    }
}
