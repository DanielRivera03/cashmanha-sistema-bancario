<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

/**
 * El interés de las cuentas de ahorro.
 *
 * Los productos de depósito declaraban su tasa desde el primer día
 * —4.50% la personal, 3.75% la empresarial—, la pantalla de caja la
 * enseñaba al abrir una cuenta y el sitio público la anunciaba. No
 * había nada que la pagara. La tasa era un rótulo.
 *
 * **Sobre qué saldo.** Sobre el SALDO DIARIO PROMEDIO del período. Las
 * otras dos formas de hacerlo están mal por motivos opuestos: sobre el
 * saldo final se le paga lo mismo a quien tuvo el dinero todo el mes
 * que a quien lo depositó el día 30, y sobre el saldo mínimo se castiga
 * a quien usa su cuenta. El promedio diario es lo que de verdad estuvo
 * depositado.
 *
 * **De dónde sale el saldo de cada día.** De `movimientos`, que guarda
 * `saldo_posterior_cent` en cada apunte. Reconstruir el saldo sumando
 * importes hacia atrás daría el mismo número mientras nada falle, pero
 * el saldo que quedó escrito es el que el cliente vio en su estado de
 * cuenta, y es ese el que hay que remunerar.
 *
 * **Partida doble.** El abono al cliente se carga contra la cuenta
 * técnica de gasto por intereses. Sin contrapartida el asiento no
 * cuadra y `LibroMayor` lo rechaza, que es justamente lo que debe
 * hacer.
 *
 * **Una vez por período.** `cuenta_intereses` tiene índice único por
 * (cuenta, fin de período): correr el proceso dos veces el mismo mes no
 * paga dos veces.
 */
final class ServicioInteresDeposito
{
    public function __construct(private ?ServicioSegmento $segmento = null)
    {
        $this->segmento ??= new ServicioSegmento();
    }

    /**
     * Calcula lo que corresponde a cada cuenta en el período, sin abonar.
     *
     * @param  string $hasta Último día del período (inclusive), 'Y-m-d'.
     * @return list<array<string,mixed>>
     */
    public function calcular(string $hasta): array
    {
        $fin    = new \DateTimeImmutable($hasta);
        $inicio = $fin->modify('first day of this month');

        $dias = (int) $inicio->diff($fin)->days + 1;

        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, c.usuario_id, c.saldo_cent, c.apertura_en,
                    p.nombre AS producto, p.tasa_interes_bp,
                    CONCAT(u.nombres," ",u.apellidos) AS titular,
                    u.segmento
               FROM cuentas c
               JOIN productos p ON p.id = c.producto_id
               JOIN usuarios  u ON u.id = c.usuario_id
              WHERE c.es_tecnica = 0
                AND c.estado = "activa"
                AND p.tipo = "ahorro"
                AND p.tasa_interes_bp > 0
              ORDER BY c.id'
        );

        $minimo    = max(0, Parametros::entero('deposito.saldo_minimo_para_interes_cent', 10000));
        $base      = max(1, Parametros::entero('deposito.base_dias_anio', 360));
        $retBp     = max(0, Parametros::entero('deposito.retencion_bp', 1000));
        $exentoCent = max(0, Parametros::entero('deposito.retencion_exenta_cent', 5000));

        $out = [];

        foreach ($cuentas as $c) {
            $cuentaId = (int) $c['id'];

            // Ya devengado este período: no se vuelve a calcular.
            $ya = BaseDatos::unaFila(
                'SELECT id FROM cuenta_intereses WHERE cuenta_id = ? AND periodo_hasta = ?',
                [$cuentaId, $fin->format('Y-m-d')]
            );

            if ($ya !== null) {
                continue;
            }

            /* Una cuenta abierta a mitad de mes devenga por los días que
               estuvo abierta, no por el mes entero. */
            $apertura = new \DateTimeImmutable((string) $c['apertura_en']);
            $desde    = $apertura > $inicio ? $apertura->setTime(0, 0) : $inicio;

            if ($desde > $fin) {
                continue;   // se abrió después del período
            }

            $diasCuenta = (int) $desde->diff($fin)->days + 1;

            $promedio = $this->saldoPromedio($cuentaId, $desde, $fin, (int) $c['saldo_cent']);

            if ($promedio < $minimo) {
                continue;
            }

            $extra = (int) ($this->segmento
                ->beneficio((int) $c['usuario_id'], 'tasa_deposito_extra_bp') ?? 0);

            $tasaProducto = (int) $c['tasa_interes_bp'];
            $tasaAplicada = $tasaProducto + $extra;

            /* Interés simple del período: saldo × tasa × días / base.
               Se trunca hacia abajo: con centavos, redondear al alza en
               cada cuenta y cada mes es dinero que la institución regala
               sin haberlo decidido. */
            $bruto = (int) floor($promedio * $tasaAplicada / 10000 * $diasCuenta / $base);

            if ($bruto <= 0) {
                continue;
            }

            /* Retención sobre intereses. Por debajo del mínimo exento no
               se retiene: así lo hace la ley y así lo espera el cliente
               que gana dos dólares al mes. */
            $retencion = $bruto > $exentoCent
                ? (int) floor($bruto * $retBp / 10000)
                : 0;

            $neto = $bruto - $retencion;

            if ($neto <= 0) {
                continue;
            }

            $out[] = [
                'cuenta_id'      => $cuentaId,
                'numero_cuenta'  => (string) $c['numero_cuenta'],
                'titular'        => (string) $c['titular'],
                'usuario_id'     => (int) $c['usuario_id'],
                'producto'       => (string) $c['producto'],
                'segmento'       => (string) $c['segmento'],
                'periodo_desde'  => $desde->format('Y-m-d'),
                'periodo_hasta'  => $fin->format('Y-m-d'),
                'dias'           => $diasCuenta,
                'saldo_promedio_cent' => $promedio,
                'tasa_producto_bp'    => $tasaProducto,
                'tasa_extra_bp'       => $extra,
                'tasa_aplicada_bp'    => $tasaAplicada,
                'interes_bruto_cent'  => $bruto,
                'retencion_cent'      => $retencion,
                'interes_neto_cent'   => $neto,
            ];
        }

        return $out;
    }

    /**
     * Abona lo calculado.
     *
     * @param  list<array<string,mixed>> $filas Lo que devolvió `calcular()`.
     * @return array{abonadas:int, interes_cent:int, retenido_cent:int}
     */
    public function abonar(array $filas, ?int $actorId = null): array
    {
        $r = ['abonadas' => 0, 'interes_cent' => 0, 'retenido_cent' => 0];

        $tecnica = $this->cuentaTecnica();

        foreach ($filas as $f) {
            $hecho = BaseDatos::enTransaccion(
                function () use ($f, $tecnica, $actorId): bool {
                    $neto = (int) $f['interes_neto_cent'];

                    /* Las dos cuentas, bloqueadas antes de tocarlas.
                       `LibroMayor::registrar` lo exige de quien llama:
                       proyecta el saldo leyendolo y volviendolo a
                       escribir, y sin bloqueo dos procesos a la vez se
                       pisan el saldo de la cuenta tecnica, que es la
                       que toca el lote entero. */
                    LibroMayor::bloquearCuentas([(int) $f['cuenta_id'], $tecnica]);

                    /* El asiento: se abona al cliente y se carga a la
                       técnica de gasto. La suma es cero o `LibroMayor`
                       lo rechaza, que es lo que debe hacer. */
                    $asiento = LibroMayor::registrar(
                        'interes',
                        [
                            [
                                'cuenta_id' => (int) $f['cuenta_id'],
                                'importe'   => Monto::deCentavos($neto),
                                'descripcion' => 'Interés de ahorro ' . $f['periodo_hasta'],
                            ],
                            [
                                'cuenta_id' => $tecnica,
                                'importe'   => Monto::deCentavos(-$neto),
                                'descripcion' => 'Gasto por intereses de depósito',
                            ],
                        ],
                        $actorId,
                        // La clave lleva cuenta y período: reintentar no
                        // abona dos veces aunque falle a mitad del lote.
                        'interes-deposito-' . $f['cuenta_id'] . '-' . $f['periodo_hasta'],
                        'Interés de ahorro del período que termina el ' . $f['periodo_hasta']
                    );

                    BaseDatos::ejecutar(
                        'INSERT INTO cuenta_intereses
                           (cuenta_id, periodo_desde, periodo_hasta, dias,
                            saldo_promedio_cent, tasa_producto_bp, tasa_extra_bp,
                            tasa_aplicada_bp, segmento, interes_bruto_cent,
                            retencion_cent, interes_neto_cent, asiento_id)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [
                            (int) $f['cuenta_id'],
                            $f['periodo_desde'],
                            $f['periodo_hasta'],
                            (int) $f['dias'],
                            (int) $f['saldo_promedio_cent'],
                            (int) $f['tasa_producto_bp'],
                            (int) $f['tasa_extra_bp'],
                            (int) $f['tasa_aplicada_bp'],
                            (string) $f['segmento'],
                            (int) $f['interes_bruto_cent'],
                            (int) $f['retencion_cent'],
                            $neto,
                            (int) $asiento['asiento_id'],
                        ]
                    );

                    return true;
                }
            );

            if (!$hecho) {
                continue;
            }

            $r['abonadas']++;
            $r['interes_cent']  += (int) $f['interes_neto_cent'];
            $r['retenido_cent'] += (int) $f['retencion_cent'];

            $this->avisar($f);
        }

        if ($r['abonadas'] > 0) {
            Auditoria::registrar(
                'deposito.intereses_abonados',
                Auditoria::EXITO,
                $actorId ?? 0,
                'periodo',
                (string) ($filas[0]['periodo_hasta'] ?? ''),
                $r
            );
        }

        return $r;
    }

    /**
     * El saldo diario promedio del período.
     *
     * Se parte del saldo con el que ARRANCA el período —el último
     * `saldo_posterior_cent` anterior a su primer día— y se avanza día a
     * día aplicando lo que haya pasado. Los días sin movimiento
     * conservan el saldo del día anterior, que es justamente lo que un
     * promedio de saldo final no sabría.
     */
    private function saldoPromedio(
        int $cuentaId,
        \DateTimeImmutable $desde,
        \DateTimeImmutable $hasta,
        int $saldoActual
    ): int {
        $saldoInicial = BaseDatos::unValor(
            'SELECT saldo_posterior_cent FROM movimientos
              WHERE cuenta_id = ? AND creado_en < ?
              ORDER BY creado_en DESC, id DESC LIMIT 1',
            [$cuentaId, $desde->format('Y-m-d 00:00:00')]
        );

        /* Sin movimientos anteriores: la cuenta nació dentro del período
           y arranca en cero. Usar el saldo de hoy le pagaría al cliente
           todo el mes por dinero que depositó ayer. */
        $saldo = $saldoInicial === null ? 0 : (int) $saldoInicial;

        // El último saldo de cada día con movimiento.
        $porDia = [];

        foreach (BaseDatos::todas(
            'SELECT DATE(creado_en) AS dia, saldo_posterior_cent
               FROM movimientos
              WHERE cuenta_id = ?
                AND creado_en >= ? AND creado_en < ?
              ORDER BY creado_en ASC, id ASC',
            [
                $cuentaId,
                $desde->format('Y-m-d 00:00:00'),
                $hasta->modify('+1 day')->format('Y-m-d 00:00:00'),
            ]
        ) as $m) {
            $porDia[(string) $m['dia']] = (int) $m['saldo_posterior_cent'];
        }

        $suma = 0;
        $dias = 0;
        $cur  = $desde;

        while ($cur <= $hasta) {
            $clave = $cur->format('Y-m-d');

            if (isset($porDia[$clave])) {
                $saldo = $porDia[$clave];
            }

            // Un saldo negativo no devenga: no se le paga interés a un
            // sobregiro.
            $suma += max(0, $saldo);
            $dias++;

            $cur = $cur->modify('+1 day');
        }

        return $dias > 0 ? intdiv($suma, $dias) : 0;
    }

    /** La cuenta técnica contra la que se carga el gasto. */
    private function cuentaTecnica(): int
    {
        $numero = Parametros::obtener('contabilidad.cuenta_intereses_deposito', '0000000004');

        $id = (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE numero_cuenta = ? AND es_tecnica = 1',
            [$numero]
        );

        if ($id === 0) {
            throw new \RuntimeException(
                'No existe la cuenta técnica de intereses de depósito (' . $numero . '). '
                . 'Aplique la migración 059.'
            );
        }

        return $id;
    }

    /** @param array<string,mixed> $f */
    private function avisar(array $f): void
    {
        $extra = (int) $f['tasa_extra_bp'] > 0
            ? sprintf(
                ' Incluye %s puntos adicionales por su categoría %s.',
                number_format((int) $f['tasa_extra_bp'] / 100, 2),
                (string) $f['segmento']
            )
            : '';

        $retenido = (int) $f['retencion_cent'] > 0
            ? sprintf(
                ' Se retuvieron %s de impuesto sobre el interés.',
                Monto::deCentavos((int) $f['retencion_cent'])->formateado()
            )
            : '';

        (new ServicioNotificacion())->crear(
            (int) $f['usuario_id'],
            'cuenta.interes',
            'Intereses abonados a su ' . $f['producto'],
            sprintf(
                'Abonamos %s a su cuenta %s por los intereses de %d día(s). '
                . 'Se calcularon sobre un saldo diario promedio de %s a una tasa '
                . 'anual del %s%%.%s%s',
                Monto::deCentavos((int) $f['interes_neto_cent'])->formateado(),
                (string) $f['numero_cuenta'],
                (int) $f['dias'],
                Monto::deCentavos((int) $f['saldo_promedio_cent'])->formateado(),
                number_format((int) $f['tasa_aplicada_bp'] / 100, 2),
                $extra,
                $retenido
            )
        );
    }
}
