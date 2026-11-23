<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;


use CashMan\Dominio\Credito\Clausulado;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\Mora;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

/**
 * Compensación automática: el dinero que entra cubre la mora.
 *
 * Si un cliente tiene saldo en su cuenta y cuotas vencidas, el dinero
 * parado no le sirve a nadie: a él le sigue corriendo el recargo por
 * mora, y a la institución le crece una cartera vencida que ya podría
 * estar cobrada. El derecho de compensación es justo para esto.
 *
 * Se dispara en dos momentos:
 *
 *   ABONO    en cuanto entra dinero a la cuenta —un depósito, una
 *            transferencia recibida—. Es el caso que más importa: el
 *            cliente deposita para pagar y el sistema lo aplica solo,
 *            sin que tenga que hacer un segundo trámite.
 *
 *   BARRIDO  en la pasada diaria, por si quedó saldo de antes.
 *
 * Tres salvaguardas, porque tomar dinero de la cuenta de alguien sin
 * que lo pida es delicado y conviene que no pueda salir mal:
 *
 *   1. SOLO SI ESTÁ PACTADO. Lo dice `cuentas.compensacion_autorizada`,
 *      y sin ese uno no se toca nada.
 *
 *      Ojo con lo que significa: la columna nace en UNO, no en cero.
 *      Este comentario decía lo contrario y era falso —se compensaba
 *      desde el primer día sin que nadie hubiera firmado nada—, lo
 *      cual estaba mal por partida doble: el contrato tampoco lo
 *      mencionaba. Ahora el contrato de cuenta lo pacta expresamente,
 *      con su porcentaje y su mínimo intocable, y la apertura en línea
 *      escribe el valor a mano en vez de heredarlo de un DEFAULT que
 *      no se ve al leer el código.
 *
 *   2. UN PORCENTAJE, NO TODO. `compensacion_bp` permite acordar que se
 *      tome la mitad de lo que entre, no el total. Dejar a alguien sin
 *      un centavo porque depositó su sueldo es la forma más rápida de
 *      perder al cliente.
 *
 *   3. UN MÍNIMO INTOCABLE. `compensacion_minimo_cent` reserva lo que
 *      no se toca nunca.
 *
 * Y se aplica por antigüedad: primero la cuota más vieja, que es la que
 * acumula más recargo. Pagar primero la reciente dejaría corriendo la
 * que más cuesta.
 */
final class ServicioCompensacion
{
    /** Los que admite `compensaciones.origen`. */
    private const ORIGENES = ['abono', 'barrido', 'manual'];

    private ServicioNotificacion $notificaciones;

    public function __construct(?ServicioNotificacion $notificaciones = null)
    {
        $this->notificaciones = $notificaciones ?? new ServicioNotificacion();
    }

    /**
     * Aplica compensación tras entrar dinero a una cuenta.
     *
     * La llaman el depósito, la transferencia recibida y la nota de
     * abono. Nunca lanza: una compensación que falle no puede tumbar el
     * depósito que la originó, porque el depósito en sí fue correcto.
     *
     * @return array<string,mixed> Lo aplicado, para poder informarlo.
     */
    public function trasAbono(int $cuentaId, string $origen = 'abono'): array
    {
        try {
            return $this->aplicar($cuentaId, $origen);
        } catch (\Throwable $e) {
            error_log('[Compensacion] cuenta ' . $cuentaId . ': ' . $e->getMessage());
            return ['aplicado_cent' => 0, 'cuotas' => 0, 'error' => true];
        }
    }

    /**
     * El trabajo de verdad.
     *
     * @return array<string,mixed>
     */
    public function aplicar(int $cuentaId, string $origen = 'abono'): array
    {
        $vacio = ['aplicado_cent' => 0, 'cuotas' => 0];

        /* El origen es un ENUM en la base, y hasta aquí llegaba sin
         * mirar. Una cadena que no estuviera en la lista no fallaba al
         * entrar: fallaba al final, al insertar la fila de la
         * compensación, con un «Data truncated» que ya venía con el
         * asiento del libro mayor registrado unas líneas antes.
         *
         * Rechazarlo aquí deja el error donde se puede entender y
         * antes de haber movido un solo centavo. */
        if (!in_array($origen, self::ORIGENES, true)) {
            throw new \InvalidArgumentException(
                'Origen de compensacion no valido: "' . $origen . '". '
                . 'Los validos son: ' . implode(', ', self::ORIGENES) . '.'
            );
        }

        if (!Parametros::booleano('compensacion.activa', true)) {
            return $vacio;
        }

        return BaseDatos::enTransaccion(function () use ($cuentaId, $origen, $vacio) {
            $cuenta = BaseDatos::unaFila(
                'SELECT c.id, c.usuario_id, c.numero_cuenta, c.saldo_cent, c.estado,
                        c.compensacion_autorizada, c.compensacion_bp,
                        c.compensacion_minimo_cent,
                        COALESCE(p.saldo_minimo_cent, 0) AS saldo_minimo_cent
                   FROM cuentas c
                   LEFT JOIN productos p ON p.id = c.producto_id
                  WHERE c.id = ? AND c.es_tecnica = 0
                  FOR UPDATE',
                [$cuentaId]
            );

            if ($cuenta === null
                || $cuenta['estado'] !== 'activa'
                || (int) $cuenta['compensacion_autorizada'] !== 1) {
                return $vacio;
            }

            /* Cuánto se puede tomar.
             *
             * Del saldo se descuenta primero lo intocable —el mínimo del
             * producto y el que se haya pactado— y de lo que quede se
             * toma el porcentaje acordado. */
            $intocable = max(
                (int) $cuenta['saldo_minimo_cent'],
                (int) $cuenta['compensacion_minimo_cent']
            );

            $disponible = max(0, (int) $cuenta['saldo_cent'] - $intocable);
            $tomable    = (int) floor($disponible * ((int) $cuenta['compensacion_bp']) / 10000);

            $umbral = Parametros::entero('compensacion.minimo_cent', 100);

            if ($tomable < $umbral) {
                return $vacio;
            }

            // Lo vencido, de lo más viejo a lo más nuevo: es lo que más
            // recargo acumula y lo primero que hay que apagar.
            $cuotas = BaseDatos::todas(
                /* Los componentes de la cuota, no solo su total.
                 *
                 * Hacen falta para imputar un abono parcial en el orden
                 * que manda la cláusula DÉCIMA: mora, seguros, interés
                 * y por último capital. */
                'SELECT q.id, q.numero, q.credito_id, q.total_cent, q.pagado_cent,
                        q.capital_cent, q.interes_cent,
                        q.seguro_vida_cent, q.seguro_empleo_cent,
                        q.fecha_vencimiento,
                        c.folio,
                        DATEDIFF(CURDATE(), q.fecha_vencimiento) AS dias
                   FROM cuotas q
                   JOIN creditos c ON c.id = q.credito_id
                  WHERE c.usuario_id = ?
                    AND q.estado NOT IN ("pagada","condonada")
                    AND q.fecha_vencimiento < CURDATE()
                    -- castigado tambien: castigar es contable, la deuda
                    -- sigue existiendo y la compensacion procede sobre ella.
                    AND c.estado IN ("vigente","desembolsado","castigado")
                  ORDER BY q.fecha_vencimiento
                  FOR UPDATE',
                [(int) $cuenta['usuario_id']]
            );

            /* Las tarjetas vencidas del mismo titular se compensan
               igual que un credito: el excedente sobre el limite y el
               minimo vencido son exigibles. El esquema ya preveia esto
               (compensaciones.tarjeta_id existe desde su creacion). */
            $tarjetas = $this->tarjetasExigibles((int) $cuenta['usuario_id']);

            /* Lo exigible POR PRODUCTO, para repartir EN PROPORCION a lo
               vencido entre TODOS: cada credito y cada tarjeta recibe su
               parte segun cuanto debe. En el credito lo exigible es el
               pendiente mas la mora de cada cuota; en la tarjeta, su
               exigible ya calculado. */
            $exigiblePorCredito = [];
            foreach ($cuotas as $q) {
                $cid  = (int) $q['credito_id'];
                $pend = (int) $q['total_cent'] - (int) $q['pagado_cent'];
                $exigiblePorCredito[$cid] = ($exigiblePorCredito[$cid] ?? 0)
                    + $pend
                    + Mora::calcular(Monto::deCentavos($pend), max(0, (int) $q['dias']))->centavos;
            }

            $exigibleTotal = (int) (array_sum($exigiblePorCredito)
                + array_sum(array_column($tarjetas, 'exigible')));

            if ($exigibleTotal <= 0) {
                return $vacio;
            }

            /* El tope de cada producto: su proporcion del total tomable.
               El redondeo hacia abajo deja algunos centavos sin asignar;
               se entregan al producto que mas debe, para no perderlos. */
            $capPorCredito = [];
            foreach ($exigiblePorCredito as $cid => $ex) {
                $capPorCredito[$cid] = (int) floor($tomable * $ex / $exigibleTotal);
            }
            $capPorTarjeta = [];
            foreach ($tarjetas as $t) {
                $capPorTarjeta[(int) $t['id']] = (int) floor($tomable * (int) $t['exigible'] / $exigibleTotal);
            }

            $sobrante = $tomable - array_sum($capPorCredito) - array_sum($capPorTarjeta);
            if ($sobrante > 0) {
                $maxCred = $exigiblePorCredito !== [] ? max($exigiblePorCredito) : -1;
                $maxTar  = $tarjetas !== [] ? max(array_column($tarjetas, 'exigible')) : -1;

                if ($maxCred >= $maxTar && $exigiblePorCredito !== []) {
                    $cid = (int) array_search($maxCred, $exigiblePorCredito, true);
                    $capPorCredito[$cid] += $sobrante;
                } elseif ($tarjetas !== []) {
                    $cols = array_column($tarjetas, 'exigible');
                    $tid  = (int) $tarjetas[(int) array_search(max($cols), $cols, true)]['id'];
                    $capPorTarjeta[$tid] += $sobrante;
                }
            }

            $tecnica = (int) BaseDatos::unValor(
                'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
            );

            LibroMayor::bloquearCuentas([$cuentaId, $tecnica]);

            /* Cada credito gasta HASTA su tope, cuota mas vieja primero.
               Cuando agota su presupuesto se pasa al siguiente credito,
               en vez de cortar todo el reparto: cada producto tiene su
               parte. */
            $restantePorCredito = $capPorCredito;
            $aplicadas = [];
            $totalMora = 0;

            foreach ($cuotas as $q) {
                $cid  = (int) $q['credito_id'];
                $pres = $restantePorCredito[$cid] ?? 0;

                if ($pres < $umbral) {
                    continue;
                }

                $pendiente = Monto::deCentavos((int) $q['total_cent'] - (int) $q['pagado_cent']);
                $mora      = Mora::calcular($pendiente, max(0, (int) $q['dias']));
                $aPagar    = $pendiente->centavos + $mora->centavos;

                /* Cuotas enteras mientras alcance; la última, parcial.
                 *
                 * Dejar quieto el remanente porque no da para una cuota
                 * completa es peor para el deudor, no mejor: el recargo
                 * por mora se calcula sobre el SALDO PENDIENTE de cada
                 * cuota, así que abonar lo que haya baja el pendiente y
                 * con él lo que se le sigue cobrando cada día.
                 *
                 * EL ABONO PARCIAL VA ÍNTEGRO A LA CUOTA.
                 *
                 * Lo ortodoxo sería imputar primero el recargo. Aquí no,
                 * y por una razón concreta: una cuota con un año de
                 * atraso acumula más mora de la que suele haber en la
                 * cuenta. Con la mora por delante, todo abono se iba
                 * entero en recargo, la cuota quedaba «parcial» con su
                 * `pagado_cent` en cero y el cliente veía desaparecer su
                 * dinero sin que la deuda bajara un centavo. Eso no es
                 * cobrar: es conseguir que el deudor deje de intentarlo.
                 *
                 * Así, cada abono baja el pendiente de la cuota —y con
                 * él el recargo que se devenga cada día, porque se
                 * calcula sobre ese pendiente—. La mora se cobra cuando
                 * el abono alcanza para liquidar la cuota entera, que es
                 * el caso de abajo. Mientras tanto sigue devengándose y
                 * no se pierde. */
                $esParcial = $aPagar > $pres;

                if ($esParcial) {
                    $abono     = $pres;
                    $moraCobra = 0;
                } else {
                    $abono     = $aPagar;
                    $moraCobra = $mora->centavos;
                }

                $aplicadas[] = [
                    'cuota'   => $q,
                    'monto'   => $abono,
                    'mora'    => $moraCobra,
                    'dias'    => max(0, (int) $q['dias']),
                    'parcial' => $esParcial,
                    // Lo que de verdad entra a la cuota, descontado el
                    // recargo, que no es parte de ella.
                    'abono_cuota' => $abono - $moraCobra,
                ];

                $restantePorCredito[$cid] = $pres - $abono;
                $totalMora += $moraCobra;
            }

            /* El reparto a las tarjetas: a cada una su tope, sin pasar
               de lo que debe ni de su saldo. El excedente y el minimo
               vencido ya quedaron en 'exigible'. */
            $tarjetasAplicadas = [];
            foreach ($tarjetas as $t) {
                $cap   = $capPorTarjeta[(int) $t['id']] ?? 0;
                $monto = min($cap, (int) $t['exigible'], (int) $t['saldo_usado_cent']);

                if ($monto < $umbral) {
                    continue;
                }

                $tarjetasAplicadas[] = ['tarjeta' => $t, 'monto' => $monto];
            }

            if ($aplicadas === [] && $tarjetasAplicadas === []) {
                return $vacio;
            }

            $totalCredito = (int) array_sum(array_column($aplicadas, 'monto'));
            $totalTarjeta = (int) array_sum(array_column($tarjetasAplicadas, 'monto'));
            $total        = $totalCredito + $totalTarjeta;

            $saldoAntes = (int) $cuenta['saldo_cent'];

            $asiento = LibroMayor::registrar(
                'compensacion',
                [
                    ['cuenta_id' => $cuentaId,
                     'importe' => Monto::deCentavos($total)->negado(),
                     'descripcion' => 'Compensacion: ' . count($aplicadas) . ' cuota(s) vencida(s)'
                        . ($tarjetasAplicadas !== []
                            ? ' y ' . count($tarjetasAplicadas) . ' tarjeta(s)'
                            : '')],
                    ['cuenta_id' => $tecnica,
                     'importe' => Monto::deCentavos($total),
                     'descripcion' => 'Cobro por compensacion'],
                ],
                null,                      // la origina el sistema, no una persona
                /* La clave de idempotencia, que identifica ESTA
                 * aplicación y no cualquier otra.
                 *
                 * Antes llevaba `random_bytes`, y con eso nunca se
                 * repetía: la comprobación de duplicado del libro mayor
                 * —el `if` de aquí abajo— no se cumplía jamás. Quien
                 * leyera el código creía que había una red de seguridad
                 * contra el doble cobro, y lo que había era código
                 * muerto.
                 *
                 * Ahora se arma con lo que distingue una aplicación de
                 * otra: la cuenta, el SALDO DEL QUE SE PARTE, lo que se
                 * toma y sobre qué cuotas. Dos compensaciones legítimas
                 * del mismo día parten de saldos distintos —la primera
                 * bajó el saldo—, así que no chocan. Un reintento del
                 * mismo evento parte del mismo saldo y sí choca, que es
                 * exactamente lo que se quiere atrapar. */
                'comp:' . $cuentaId
                    . ':' . $saldoAntes
                    . ':' . $total
                    . ':' . implode(',', array_map(
                        static fn(array $a): int => (int) $a['cuota']['id'],
                        $aplicadas
                    ))
                    . ':t' . implode(',', array_map(
                        static fn(array $a): int => (int) $a['tarjeta']['id'],
                        $tarjetasAplicadas
                    )),
                'Compensacion automatica'
            );

            if (($asiento['duplicado'] ?? false) === true) {
                return $vacio;
            }

            $porCredito = [];

            foreach ($aplicadas as $a) {
                $q = $a['cuota'];

                if (!empty($a['parcial'])) {
                    /* Abono parcial.
                     *
                     * La cuota NO queda pagada: sube su `pagado_cent` y
                     * pasa a «parcial». Lo que baja con ella es su saldo
                     * pendiente, y con él el recargo que se le calcula
                     * cada día, porque la mora se topa justamente en ese
                     * pendiente.
                     *
                     * El abono entra ENTERO aquí: en un parcial no se
                     * cobra recargo, precisamente para que el pago se
                     * note en la deuda. `mora_cent` no se toca, porque
                     * no se cobró nada por ese concepto; la mora sigue
                     * devengando sobre un pendiente ya más chico. */
                    /* Y el recargo se vuelve a topar al saldo que queda.
                     *
                     * El contrato pone un techo al recargo: no puede
                     * superar la deuda que lo origina, porque cobrar
                     * trescientos dólares de mora sobre cuatro de saldo
                     * es desproporcionado y así está escrito en
                     * `Mora::aplicarTope`.
                     *
                     * Ese techo se calculaba al devengar y no se volvía
                     * a mirar al cobrar. Entonces un abono parcial
                     * bajaba el saldo vencido y el recargo se quedaba
                     * donde estaba: en una cuota con $4.23 pendientes
                     * la ficha seguía exigiendo $300.32 de mora. El
                     * verificador lo cantó en cuatro cuotas.
                     *
                     * Se recorta aquí, en el mismo UPDATE, para que no
                     * exista ni un instante en que la cuota diga una
                     * cifra que el contrato no permite cobrar. */
                    /* La mora se recalcula sobre el pendiente que queda
                       y se GUARDA, para que la ficha diga exactamente lo
                       que la caja cobraria (es lo que comprueba el
                       cuadre 6b). Antes se topaba con LEAST(mora_vieja,
                       pendiente): si la mora vieja estaba en cero —aun
                       sin devengar— la cuota quedaba diciendo $0 mientras
                       seguia debiendo recargo, y el verificador lo canta. */
                    $pagadoNuevo    = min(
                        (int) $q['total_cent'],
                        (int) $q['pagado_cent'] + (int) $a['abono_cuota']
                    );
                    $pendienteNuevo = (int) $q['total_cent'] - $pagadoNuevo;
                    $moraNueva      = $pendienteNuevo > 0
                        ? Mora::calcular(Monto::deCentavos($pendienteNuevo), (int) $a['dias'])->centavos
                        : 0;

                    BaseDatos::ejecutar(
                        'UPDATE cuotas
                            SET pagado_cent = ?, mora_cent = ?,
                                estado = "parcial", dias_mora = ?
                          WHERE id = ?',
                        [$pagadoNuevo, $moraNueva, $a['dias'], (int) $q['id']]
                    );
                } else {
                    BaseDatos::ejecutar(
                        'UPDATE cuotas
                            SET pagado_cent = total_cent, mora_cent = ?, estado = "pagada",
                                pagada_en = NOW(), pagada_tarde = 1, dias_mora = ?
                          WHERE id = ?',
                        [$a['mora'], $a['dias'], (int) $q['id']]
                    );
                }

                $cid = (int) $q['credito_id'];
                $porCredito[$cid] ??= ['capital' => 0, 'pagado' => 0, 'folio' => $q['folio']];
                $porCredito[$cid]['capital'] += $this->capitalCubierto($q, $a);
                $porCredito[$cid]['pagado']  += $a['monto'];

                BaseDatos::ejecutar(
                    'INSERT INTO compensaciones
                       (usuario_id, cuenta_id, credito_id, cuota_id, importe_cent,
                        mora_cent, saldo_antes_cent, saldo_despues_cent, origen, asiento_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [
                        (int) $cuenta['usuario_id'], $cuentaId, $cid, (int) $q['id'],
                        $a['monto'], $a['mora'], $saldoAntes, $saldoAntes - $total,
                        $origen, (int) ($asiento['id'] ?? 0) ?: null,
                    ]
                );
            }

            foreach ($porCredito as $cid => $datos) {
                BaseDatos::ejecutar(
                    'UPDATE creditos c SET
                        saldo_capital_cent = GREATEST(0, saldo_capital_cent - ?),
                        total_pagado_cent  = total_pagado_cent + ?,
                        cuotas_pagadas     = (SELECT COUNT(*) FROM cuotas
                                               WHERE credito_id=c.id AND estado="pagada"),
                        cuotas_tardias     = (SELECT COUNT(*) FROM cuotas
                                               WHERE credito_id=c.id AND pagada_tarde=1),
                        proxima_cuota      = (SELECT MIN(fecha_vencimiento) FROM cuotas
                                               WHERE credito_id=c.id AND estado NOT IN ("pagada","condonada"))
                      WHERE c.id = ?',
                    [$datos['capital'], $datos['pagado'], $cid]
                );
            }

            /* El expediente de cobro, al dia.
             *
             * Si no se refresca, la cabecera del expediente sigue
             * diciendo lo que se debia antes de este cobro y el
             * gestor negocia con un numero que ya no es. Y si el
             * credito quedo al dia, el caso se cierra solo: un
             * expediente abierto sobre quien ya pago hace que
             * alguien le llame a reclamarle. */
            $cobranza = new ServicioCobranza();

            foreach (array_keys($porCredito) as $cid) {
                $cobranza->refrescarCaso((int) $cid);
            }

            /* Las tarjetas. El asiento de arriba ya movio el dinero de
               la cuenta a la tecnica por el total; aqui se baja el saldo
               usado de cada tarjeta, se deja su movimiento, se reactiva
               si volvio dentro del limite (como un pago) y se registra
               la fila de compensacion con su tarjeta_id. */
            $tarjetasTocadas = [];

            foreach ($tarjetasAplicadas as $ta) {
                $t     = $ta['tarjeta'];
                $tid   = (int) $t['id'];
                $monto = (int) $ta['monto'];

                BaseDatos::ejecutar(
                    'UPDATE tarjetas SET saldo_usado_cent = GREATEST(0, saldo_usado_cent - ?)
                      WHERE id = ?',
                    [$monto, $tid]
                );

                $reactivada = BaseDatos::ejecutar(
                    'UPDATE tarjetas SET estado = "activa"
                      WHERE id = ? AND estado = "inhabilitada"
                        AND saldo_usado_cent <= limite_cent',
                    [$tid]
                );

                /* Si la compensacion la devolvio dentro del limite, se
                   lo confirmamos, igual que cuando paga el cliente o
                   cuando lo hace la tarea diaria: la reactivacion avisa
                   siempre, venga por donde venga. */
                if ($reactivada > 0) {
                    $this->notificaciones->crear(
                        (int) $cuenta['usuario_id'],
                        'tarjeta.reactivada',
                        'Tarjeta reactivada',
                        'Su tarjeta ••••' . $t['ultimos4'] . ' volvió dentro de su límite de '
                        . 'crédito y ya puede usarla de nuevo.',
                        null,
                        'info'
                    );
                }

                BaseDatos::ejecutar(
                    'INSERT INTO tarjeta_movimientos
                       (tarjeta_id, asiento_id, tipo, importe_cent, comercio, referencia, estado)
                     VALUES (?, ?, "pago", ?, ?, ?, "liquidada")',
                    [
                        $tid,
                        (int) ($asiento['id'] ?? 0) ?: null,
                        $monto,
                        'Compensacion de saldo',
                        (string) $asiento['referencia'],
                    ]
                );

                BaseDatos::ejecutar(
                    'INSERT INTO compensaciones
                       (usuario_id, cuenta_id, credito_id, cuota_id, tarjeta_id, importe_cent,
                        mora_cent, saldo_antes_cent, saldo_despues_cent, origen, asiento_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        (int) $cuenta['usuario_id'], $cuentaId, null, null, $tid,
                        $monto, 0, $saldoAntes, $saldoAntes - $total,
                        $origen, (int) ($asiento['id'] ?? 0) ?: null,
                    ]
                );

                $tarjetasTocadas[] = '****' . $t['ultimos4'];
            }

            Auditoria::registrar('compensacion.aplicada', Auditoria::EXITO, null,
                'cuenta', (string) $cuentaId, [
                    'cuotas'   => count($aplicadas),
                    'tarjetas' => count($tarjetasAplicadas),
                    'monto'    => Monto::deCentavos($total)->aDecimal(),
                    'mora'     => Monto::deCentavos($totalMora)->aDecimal(),
                    'origen'   => $origen,
                ]);

            /* Avisar siempre, y explicando.
             *
             * Ver que el saldo bajó sin haber hecho nada asusta, y con
             * razón. El aviso dice cuánto, por qué y a qué cuotas fue,
             * para que el cliente pueda comprobarlo. */
            $folios = implode(', ', array_column($porCredito, 'folio'));

            /* Qué se cubrió, en palabras: cuotas de crédito y/o
               tarjetas, según lo que el reparto haya tocado. */
            $detalle = [];
            if ($aplicadas !== []) {
                $detalle[] = count($aplicadas) . ' cuota(s) vencida(s) de ' . $folios;
            }
            if ($tarjetasAplicadas !== []) {
                $detalle[] = count($tarjetasAplicadas) . ' tarjeta(s) ('
                    . implode(', ', $tarjetasTocadas) . ')';
            }

            $this->notificaciones->crear(
                (int) $cuenta['usuario_id'],
                'compensacion.aplicada',
                'Aplicamos su saldo a sus deudas atrasadas',
                'Tomamos ' . Monto::deCentavos($total)->formateado()
                . ' de su cuenta ' . $cuenta['numero_cuenta'] . ' para cubrir '
                . implode(' y ', $detalle)
                . ($totalMora > 0
                    ? ', incluyendo ' . Monto::deCentavos($totalMora)->formateado()
                      . ' de recargo por mora'
                    : '')
                . '. Lo hacemos conforme a la compensación que usted autorizó, y '
                . 'así deja de acumularle recargo.',
                ['referencia' => $asiento['referencia']],
                'info'
            );

            /* El aviso formal, aparte del del portal.
             *
             * La cláusula DÉCIMA CUARTA obliga a informar de cada
             * aplicación detallando importe, cuotas cubiertas y saldo
             * resultante. Un aviso de dos líneas en el portal no cumple
             * eso, y además no es lo que necesita quien ve que su saldo
             * bajó sin haber hecho nada: necesita poder cuadrarlo.
             *
             * Solo cuando hubo cuotas de crédito: la cláusula es del
             * contrato de crédito. Lo de tarjeta va en su propio estado
             * de cuenta y en el aviso del portal de arriba. */
            if ($aplicadas !== []) {
                $this->avisoFormal(
                    $cuenta, $aplicadas, $totalCredito, $totalMora,
                    $saldoAntes, $saldoAntes - $total,
                    (string) $asiento['referencia'], $porCredito
                );
            }

            return [
                'aplicado_cent' => $total,
                'mora_cent'     => $totalMora,
                'cuotas'        => count($aplicadas),
                'tarjetas'      => count($tarjetasAplicadas),
                'referencia'    => $asiento['referencia'],
                'creditos'      => array_column($porCredito, 'folio'),
                'tarjetas_tocadas' => $tarjetasTocadas,
            ];
        });
    }

    /**
     * Lo exigible de cada tarjeta de crédito del titular.
     *
     * Exigible = excedente sobre el límite + mínimo vencido no pagado,
     * sin pasar del saldo usado. El excedente es dinero por encima de
     * la línea aprobada, exigible de inmediato; el mínimo vencido es la
     * cuota del último corte cuya fecha límite ya pasó, menos lo abonado
     * desde ese corte. Una tarjeta sin excedente ni mínimo vencido no
     * entra: no hay nada exigible que compensar.
     *
     * @return list<array{id:int,ultimos4:string,saldo_usado_cent:int,limite_cent:int,exigible:int}>
     */
    private function tarjetasExigibles(int $usuarioId): array
    {
        $hoy = date('Y-m-d');

        $filas = BaseDatos::todas(
            'SELECT t.id, t.ultimos4, t.saldo_usado_cent, t.limite_cent,
                    k.pago_minimo_cent, k.fecha_limite_pago, k.fecha_corte
               FROM tarjetas t
          LEFT JOIN tarjeta_cortes k ON k.id = (
                       SELECT k2.id FROM tarjeta_cortes k2
                        WHERE k2.tarjeta_id = t.id
                        ORDER BY k2.fecha_corte DESC, k2.id DESC
                        LIMIT 1)
              WHERE t.usuario_id = ?
                AND t.tipo = "credito"
                AND t.estado IN ("activa","inhabilitada")
                AND t.saldo_usado_cent > 0',
            [$usuarioId]
        );

        $salida = [];

        foreach ($filas as $f) {
            $saldo     = (int) $f['saldo_usado_cent'];
            $limite    = (int) $f['limite_cent'];
            $excedente = $limite > 0 ? max(0, $saldo - $limite) : 0;

            $minVencido = 0;

            if ($f['fecha_limite_pago'] !== null
                && (string) $f['fecha_limite_pago'] < $hoy) {
                $pagosPost = (int) BaseDatos::unValor(
                    'SELECT COALESCE(SUM(importe_cent), 0)
                       FROM tarjeta_movimientos
                      WHERE tarjeta_id = ? AND tipo IN ("pago","abono")
                        AND DATE(creado_en) > ?',
                    [(int) $f['id'], (string) $f['fecha_corte']]
                );

                $minVencido = max(0, (int) $f['pago_minimo_cent'] - $pagosPost);
            }

            $exigible = min($saldo, $excedente + $minVencido);

            if ($exigible <= 0) {
                continue;
            }

            $salida[] = [
                'id'               => (int) $f['id'],
                'ultimos4'         => (string) $f['ultimos4'],
                'saldo_usado_cent' => $saldo,
                'limite_cent'      => $limite,
                'exigible'         => $exigible,
            ];
        }

        return $salida;
    }

    /**
     * La pasada diaria sobre toda la cartera.
     *
     * Recoge lo que quedó de días anteriores: saldo que entró cuando no
     * había cuota vencida y ahora sí, o cuentas donde la compensación se
     * autorizó después.
     *
     * @return array<string,mixed>
     */
    public function barrido(): array
    {
        $cuentas = BaseDatos::todas(
            'SELECT DISTINCT c.id
               FROM cuentas c
               JOIN creditos cr ON cr.usuario_id = c.usuario_id
               JOIN cuotas q    ON q.credito_id = cr.id
              WHERE c.es_tecnica = 0
                AND c.estado = "activa"
                AND c.compensacion_autorizada = 1
                AND c.saldo_cent > 0
                AND q.estado NOT IN ("pagada","condonada")
                AND q.fecha_vencimiento < CURDATE()
                AND cr.estado IN ("vigente","desembolsado","castigado")
            UNION
            SELECT DISTINCT c.id
               FROM cuentas  c
               JOIN tarjetas t ON t.usuario_id = c.usuario_id AND t.tipo = "credito"
              WHERE c.es_tecnica = 0
                AND c.estado = "activa"
                AND c.compensacion_autorizada = 1
                AND c.saldo_cent > 0
                AND t.saldo_usado_cent > 0
                AND t.saldo_usado_cent > t.limite_cent'
        );

        $total  = 0;
        $cuotas = 0;
        $tocadas = 0;

        foreach ($cuentas as $c) {
            $r = $this->trasAbono((int) $c['id'], 'barrido');

            if ((int) $r['aplicado_cent'] > 0) {
                $total  += (int) $r['aplicado_cent'];
                $cuotas += (int) $r['cuotas'];
                $tocadas++;
            }
        }

        return [
            'cuentas_revisadas' => count($cuentas),
            'cuentas_aplicadas' => $tocadas,
            'cuotas'            => $cuotas,
            'total_cent'        => $total,
        ];
    }

    /**
     * El desglose cuota por cuota de una aplicación.
     *
     * Es lo que convierte «se aplicaron $74,975.00» en algo que el
     * deudor puede verificar contra su plan de pagos: qué cuota, cuándo
     * vencía, cuánto llevaba de atraso, cuánto se le abonó, cuánto de
     * eso fue recargo y si quedó cubierta o a medias.
     *
     * Va como tabla y no como lista de párrafos porque con treinta y
     * tres cuotas la lista es ilegible, y porque así se puede imprimir
     * y adjuntar al expediente tal cual.
     *
     * @param list<array<string,mixed>> $aplicadas
     */
    private function tablaDeCuotas(array $aplicadas): string
    {
        if ($aplicadas === []) {
            return '';
        }

        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $d = static fn(int $c): string => Monto::deCentavos($c)->formateado();

        $celda = 'padding:7px 9px;border-bottom:1px solid #eceef4;font-size:13px';
        $cab   = 'padding:8px 9px;border-bottom:2px solid #d6dae4;font-size:12px;'
               . 'text-align:left;color:#667085;font-weight:600';

        $filas = '';

        foreach ($aplicadas as $a) {
            $q = $a['cuota'];
            $esParcial = !empty($a['parcial']);

            $filas .= '<tr>'
                . '<td style="' . $celda . ';font-weight:600">'
                . (int) $q['numero'] . '</td>'

                . '<td style="' . $celda . ';color:#667085">'
                . $e(date('d/m/Y', strtotime((string) ($q['fecha_vencimiento'] ?? 'now'))))
                . '</td>'

                . '<td style="' . $celda . ';text-align:right;color:#667085">'
                . number_format((int) $a['dias']) . '</td>'

                . '<td style="' . $celda . ';text-align:right">'
                . $e($d((int) $a['mora'])) . '</td>'

                . '<td style="' . $celda . ';text-align:right;font-weight:600">'
                . $e($d((int) $a['monto'])) . '</td>'

                . '<td style="' . $celda . '">'
                . ($esParcial
                    ? '<span style="color:#b45309">parcial</span>'
                    : '<span style="color:#15603a">cubierta</span>')
                . '</td>'
                . '</tr>';
        }

        return '<p style="margin-bottom:6px"><strong>Detalle de las cuotas.</strong></p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
            . ' style="border-collapse:collapse;margin-bottom:18px">'
            . '<tr>'
            . '<th style="' . $cab . '">Cuota</th>'
            . '<th style="' . $cab . '">Vencia</th>'
            . '<th style="' . $cab . ';text-align:right">Dias</th>'
            . '<th style="' . $cab . ';text-align:right">Recargo</th>'
            . '<th style="' . $cab . ';text-align:right">Aplicado</th>'
            . '<th style="' . $cab . '">Resultado</th>'
            . '</tr>'
            . $filas
            . '</table>';
    }

    /**
     * El mismo desglose, en texto plano.
     *
     * @param list<array<string,mixed>> $aplicadas
     */
    private function listaDeCuotas(array $aplicadas): string
    {
        if ($aplicadas === []) {
            return '';
        }

        $d = static fn(int $c): string => Monto::deCentavos($c)->formateado();

        $t = "DETALLE DE LAS CUOTAS\n"
           . sprintf("  %-7s %-12s %7s %14s %14s  %s\n",
                     'Cuota', 'Vencia', 'Dias', 'Recargo', 'Aplicado', 'Resultado')
           . '  ' . str_repeat('-', 70) . "\n";

        foreach ($aplicadas as $a) {
            $q = $a['cuota'];

            $t .= sprintf("  %-7d %-12s %7s %14s %14s  %s\n",
                (int) $q['numero'],
                date('d/m/Y', strtotime((string) ($q['fecha_vencimiento'] ?? 'now'))),
                number_format((int) $a['dias']),
                $d((int) $a['mora']),
                $d((int) $a['monto']),
                empty($a['parcial']) ? 'cubierta' : 'parcial');
        }

        return $t . "\n";
    }

    /**
     * Cuánto capital cubre este abono.
     *
     * El saldo de capital del crédito solo baja por la parte del abono
     * que llega efectivamente a capital, y a capital se llega el último:
     * la cláusula DÉCIMA manda imputar primero el recargo por mora,
     * después las primas de seguro, luego el interés corriente y solo
     * entonces el capital.
     *
     * Dar por amortizado el capital completo de una cuota a medio pagar
     * sería regalarle al deudor un saldo que no ha pagado, y descuadrar
     * el crédito contra sus propias cuotas.
     *
     * @param array<string,mixed> $q Cuota, como estaba antes del abono
     * @param array<string,mixed> $a Lo aplicado
     */
    private function capitalCubierto(array $q, array $a): int
    {
        if (empty($a['parcial'])) {
            return (int) $q['capital_cent'];
        }

        // Lo que hay que cubrir antes de tocar capital.
        $previo = (int) ($q['seguro_vida_cent'] ?? 0)
                + (int) ($q['seguro_empleo_cent'] ?? 0)
                + (int) ($q['interes_cent'] ?? 0);

        $antes   = (int) $q['pagado_cent'];
        $despues = min((int) $q['total_cent'], $antes + (int) $a['abono_cuota']);

        // Capital acumulado después menos capital acumulado antes.
        return max(0, $despues - $previo) - max(0, $antes - $previo);
    }

    /**
     * El aviso formal de una compensación aplicada.
     *
     * Ver que el saldo bajó sin haber hecho nada asusta, y con razón.
     * Este correo tiene que responder a las tres preguntas que se hace
     * cualquiera en ese momento:
     *
     *   QUÉ se hizo      el importe, las cuotas cubiertas y el saldo
     *                    antes y después, para que pueda cuadrarlo.
     *   POR QUÉ se hizo  la cláusula que lo autoriza, por su número, y
     *                    el hecho que lo motivó: cuotas vencidas.
     *   QUÉ se espera    si queda saldo pendiente, qué pasa ahora; y
     *                    cómo revocar la autorización si no la quiere.
     *
     * Lo último importa tanto como lo primero. Una autorización que no
     * se puede revocar es la que un juez tumba por abusiva, y callar
     * cómo revocarla equivale a no concederla.
     *
     * @param array<string,mixed>       $cuenta
     * @param list<array<string,mixed>> $aplicadas
     * @param array<int,array<string,mixed>> $porCredito
     */
    private function avisoFormal(
        array $cuenta,
        array $aplicadas,
        int $total,
        int $mora,
        int $saldoAntes,
        int $saldoDespues,
        string $referencia,
        array $porCredito
    ): void {
        try {
            $u = BaseDatos::unaFila(
                'SELECT correo, nombres, apellidos FROM usuarios
                  WHERE id = ? AND estado = "activo"',
                [(int) $cuenta['usuario_id']]
            );

            if ($u === null || trim((string) $u['correo']) === '') {
                return;
            }

            $nombre = trim($u['nombres'] . ' ' . $u['apellidos']);
            $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $d = static fn(int $c): string => Monto::deCentavos($c)->formateado();

            $institucion = Parametros::obtener(
                'institucion.razon_social', 'CASHMAN H.A., S.A. DE C.V.'
            );

            $folios = implode(', ', array_column($porCredito, 'folio'));

            // Lo que queda debiendo después de esto.
            $restante = (int) BaseDatos::unValor(
                'SELECT COALESCE(SUM(q.total_cent - q.pagado_cent), 0)
                   FROM cuotas q JOIN creditos c ON c.id = q.credito_id
                  WHERE c.usuario_id = ? AND q.estado NOT IN ("pagada","condonada")
                    AND q.fecha_vencimiento < CURDATE()',
                [(int) $cuenta['usuario_id']]
            );

            $numeros = implode(', ', array_map(
                static fn(array $a): string => (string) $a['cuota']['numero'],
                $aplicadas
            ));

            /* Los numerales de las cláusulas que este aviso cita.
             *
             * Salen de `Clausulado`, con la modalidad del crédito que
             * se está cobrando, porque NO son los mismos en todos los
             * contratos: el de un vehículo lleva una cláusula más —la
             * que identifica el vehículo— y a partir de ahí todas las
             * de abajo corren un número.
             *
             * Escritos a mano, este aviso fundaba la compensación en
             * la «DÉCIMA CUARTA», que en un contrato de vehículo es
             * «Domicilio y jurisdicción». Un cargo fundado en la
             * cláusula equivocada es un cargo que el cliente puede
             * impugnar, y tendría razón. */
            $modalidad = Clausulado::modalidadDe((string) BaseDatos::unValor(
                'SELECT p.codigo FROM cuotas q
                   JOIN creditos c  ON c.id = q.credito_id
                   JOIN productos p ON p.id = c.producto_id
                  WHERE q.id = ? LIMIT 1',
                [(int) ($aplicadas[0]['cuota']['id'] ?? 0)]
            ));

            $clCompensacion      = Clausulado::numeralConTildes('compensacion', $modalidad);
            $clImputacion        = Clausulado::numeralConTildes('imputacion', $modalidad);
            $clMora              = Clausulado::numeralConTildes('mora', $modalidad);
            $clCompensacionLlano = Clausulado::numeral('compensacion', $modalidad);

            $cuerpo =
                '<p>Por este medio, ' . $e($institucion) . ' le informa que, con esta '
                . 'fecha, ha efectuado una <strong>aplicación por compensación</strong> '
                . 'sobre los fondos disponibles en su cuenta <strong>'
                . $e((string) $cuenta['numero_cuenta']) . '</strong>, destinada al pago '
                . 'de cuotas vencidas de su crédito ' . $e($folios) . '.</p>'

                . '<p><strong>Qué se hizo.</strong> Se aplicaron '
                . '<strong>' . $e($d($total)) . '</strong> a la cobertura de '
                . '<strong>' . count($aplicadas) . ' cuota(s)</strong> '
                . '(número ' . $e($numeros) . ')'
                . ($mora > 0
                    ? ', cantidad que comprende ' . $e($d($mora))
                      . ' en concepto de recargo por mora devengado hasta hoy'
                    : '')
                . '. El saldo de su cuenta pasó de ' . $e($d($saldoAntes))
                . ' a ' . $e($d($saldoDespues)) . '.</p>'

                /* El detalle cuota por cuota.
                 *
                 * El literal e) de la cláusula obliga a informar «las
                 * cuotas cubiertas», y eso no se cumple con un número
                 * total: el deudor tiene que poder tomar su plan de
                 * pagos y tachar una por una las que quedaron cubiertas.
                 * Sin ese detalle no puede comprobar nada, y un cargo
                 * que no se puede comprobar es un cargo que se discute. */
                . $this->tablaDeCuotas($aplicadas)

                . '<p><strong>Por qué se hizo.</strong> Conforme a la cláusula '
                . '<strong>' . $clCompensacion . '</strong> del contrato de crédito suscrito por '
                . 'usted, esta institución está facultada para aplicar los fondos '
                . 'disponibles en sus cuentas al pago de cuotas vencidas. La aplicación '
                . 'se practicó porque su crédito registraba cuotas en mora y su cuenta '
                . 'presentaba saldo disponible. Se efectuó comenzando por la cuota más '
                . 'antigua y, agotadas las que el saldo alcanzaba a cubrir íntegramente, '
                . 'el remanente se abonó de forma parcial a la cuota siguiente, imputado '
                . 'en el orden de la cláusula ' . $clImputacion . '. Se respetó en todo caso el '
                . 'remanente mínimo que la propia cláusula reserva.</p>'

                . '<p><strong>Qué se espera.</strong> '
                . ($restante > 0
                    ? 'Su crédito mantiene un saldo vencido de <strong>'
                      . $e($d($restante)) . '</strong>, que continúa devengando recargo '
                      . 'por mora conforme a la cláusula ' . $clMora . '. Le invitamos a ponerse '
                      . 'al día o a comunicarse con nosotros para revisar un plan de '
                      . 'pago; mientras exista saldo vencido y fondos disponibles, la '
                      . 'compensación seguirá aplicándose en los mismos términos.'
                    : 'Con esta aplicación <strong>no queda saldo vencido</strong> en su '
                      . 'crédito. No se requiere ninguna acción de su parte y no se '
                      . 'practicarán nuevas compensaciones mientras se mantenga al día.')
                . '</p>'

                . '<p style="font-size:13px;color:#667085">Si no reconoce esta operación, '
                . 'o si desea <strong>revocar</strong> la autorización de compensación, '
                . 'puede hacerlo mediante comunicación escrita dirigida a esta '
                . 'institución. La revocación no afecta a las aplicaciones ya '
                . 'practicadas ni a la exigibilidad del saldo adeudado.</p>';

            $filas = [
                ['etiqueta' => 'Cuenta afectada',  'valor' => (string) $cuenta['numero_cuenta']],
                ['etiqueta' => 'Credito',          'valor' => $folios],
                ['etiqueta' => 'Cuotas cubiertas', 'valor' => (string) count($aplicadas)],
                ['etiqueta' => 'Recargo por mora comprendido', 'valor' => $d($mora)],
                ['etiqueta' => 'IMPORTE APLICADO', 'valor' => $d($total)],
                ['etiqueta' => 'Saldo antes',      'valor' => $d($saldoAntes)],
                ['etiqueta' => 'Saldo despues',    'valor' => $d($saldoDespues)],
                ['etiqueta' => 'Saldo vencido que queda', 'valor' => $d($restante)],
                ['etiqueta' => 'Referencia contable', 'valor' => $referencia],
            ];

            $html = PlantillaCorreo::base(
                'Aplicacion por compensacion',
                'Señor(a) ' . $nombre,
                $cuerpo,
                $filas,
                null,
                null,
                null,
                'Esta comunicacion se emite en cumplimiento del literal e) de la clausula '
                . 'DECIMA CUARTA del contrato, que obliga a informar de cada aplicacion '
                . 'detallando su importe, las cuotas cubiertas y el saldo resultante. '
                . 'Conservela como comprobante.'
            );

            $texto = "APLICACION POR COMPENSACION\n\n"
                . "Senor(a) {$nombre}:\n\n"
                . "{$institucion} le informa que ha aplicado " . $d($total)
                . " de los fondos disponibles en su cuenta {$cuenta['numero_cuenta']} "
                . "al pago de " . count($aplicadas) . " cuota(s) vencida(s) del credito "
                . "{$folios}.\n\n"
                // El mismo detalle que el HTML: quien lee en texto plano
                // tiene el mismo derecho a comprobar lo que se le cobro.
                . $this->listaDeCuotas($aplicadas)
                . 'Saldo antes: ' . $d($saldoAntes) . "\n"
                . 'Saldo despues: ' . $d($saldoDespues) . "\n"
                . 'Recargo por mora comprendido: ' . $d($mora) . "\n"
                . 'Saldo vencido que queda: ' . $d($restante) . "\n"
                . "Referencia contable: {$referencia}\n\n"
                . "Fundamento: clausula " . $clCompensacionLlano . " del contrato de credito, que "
                . "faculta a esta institucion para aplicar los fondos disponibles al "
                . "pago de cuotas vencidas, comenzando por la mas antigua, cubriendo "
                . "solo cuotas completas y respetando un remanente minimo.\n\n"
                . ($restante > 0
                    ? "Su credito mantiene saldo vencido, que sigue devengando recargo. "
                      . "Comuniquese con nosotros para revisar un plan de pago.\n\n"
                    : "No queda saldo vencido. No se requiere ninguna accion de su parte.\n\n")
                . "Puede revocar la autorizacion de compensacion mediante comunicacion "
                . "escrita. La revocacion no afecta las aplicaciones ya practicadas.\n";

            (new Correo())->enviar(
                (string) $u['correo'],
                'Aplicacion por compensacion · cuenta ' . $cuenta['numero_cuenta'],
                $html,
                $texto,
                $nombre,
                $this->copiasDeSeguimiento(),
                Parametros::obtener('cobranza.remitente',
                                    'CashMan H.A. · Departamento de Cobranza')
            );
        } catch (\Throwable $e) {
            // El aviso no puede tumbar la operación que ya se registró.
            error_log('[Compensacion] aviso formal fallido: ' . $e->getMessage());
        }
    }

    /**
     * Aviso previo de que la compensación entra en vigor.
     *
     * Se manda ANTES de aplicar nada, y eso no es un detalle de cortesía:
     * tomar saldo de la cuenta de alguien el mismo día en que se le
     * comunica la facultad, sin darle margen para ponerse al día o
     * revocarla, es lo que convierte una cláusula legítima en una
     * práctica abusiva.
     *
     * @return array{avisados:int, sin_correo:int}
     */
    public function avisarActivacion(): array
    {
        $clientes = BaseDatos::todas(
            'SELECT DISTINCT u.id, u.correo, u.nombres, u.apellidos,
                    c.numero_cuenta, c.saldo_cent,
                    COALESCE(c.compensacion_minimo_cent, 0) AS colchon
               FROM cuentas  c
               JOIN usuarios u ON u.id = c.usuario_id
              WHERE c.es_tecnica = 0
                AND c.estado = "activa"
                AND c.compensacion_autorizada = 1
                AND u.estado = "activo"'
        );

        $institucion = Parametros::obtener(
            'institucion.razon_social', 'CASHMAN H.A., S.A. DE C.V.'
        );

        $avisados = 0;
        $sinCorreo = 0;

        foreach ($clientes as $c) {
            if (trim((string) $c['correo']) === '') {
                $sinCorreo++;
                continue;
            }

            $nombre = trim($c['nombres'] . ' ' . $c['apellidos']);
            $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Su situación concreta, no un texto genérico.
            $vencido = (int) BaseDatos::unValor(
                'SELECT COALESCE(SUM(q.total_cent - q.pagado_cent), 0)
                   FROM cuotas q JOIN creditos cr ON cr.id = q.credito_id
                  WHERE cr.usuario_id = ? AND q.estado NOT IN ("pagada","condonada")
                    AND q.fecha_vencimiento < CURDATE()',
                [(int) $c['id']]
            );

            /* La cláusula de la compensación, con la modalidad del
               crédito que este cliente tiene vivo. Si tuviera varios
               de modalidades distintas se toma el más reciente: es el
               contrato que acaba de firmar y el que tiene presente. */
            $clCompensacionLlano = Clausulado::numeral(
                'compensacion',
                Clausulado::modalidadDe((string) BaseDatos::unValor(
                    'SELECT p.codigo FROM creditos cr
                       JOIN productos p ON p.id = cr.producto_id
                      WHERE cr.usuario_id = ? AND cr.estado IN ("vigente","desembolsado","castigado")
                      ORDER BY cr.id DESC LIMIT 1',
                    [(int) $c['id']]
                ))
            );

            $cuerpo =
                '<p>Por este medio, ' . $e($institucion) . ' le comunica que, conforme a '
                . 'la cláusula <strong>DÉCIMA CUARTA</strong> del contrato de crédito '
                . 'suscrito por usted, procederá a aplicar la <strong>compensación</strong> '
                . 'allí pactada sobre los fondos disponibles en sus cuentas.</p>'

                . '<p><strong>En qué consiste.</strong> Cuando su crédito registre cuotas '
                . 'vencidas y su cuenta presente saldo disponible, esta institución '
                . 'aplicará dichos fondos al pago de esas cuotas, sin necesidad de '
                . 'requerimiento previo. La aplicación se practicará comenzando por la '
                . 'cuota más antigua y cubriendo únicamente cuotas completas.</p>'

                . '<p><strong>Qué se respeta en todo caso.</strong> No se aplicará sobre '
                . 'cuotas por vencer, sino solo sobre las ya vencidas; no se practicarán '
                . 'aplicaciones parciales que dejen una cuota en mora; y se conservará '
                . 'siempre un remanente mínimo en su cuenta. De cada aplicación se le '
                . 'informará detallando el importe, las cuotas cubiertas y el saldo '
                . 'resultante.</p>'

                . ($vencido > 0
                    ? '<p><strong>Su situación actual.</strong> Su crédito registra un '
                      . 'saldo vencido de <strong>'
                      . $e(Monto::deCentavos($vencido)->formateado()) . '</strong>. '
                      . 'Si desea evitar la aplicación, puede ponerse al día o '
                      . 'comunicarse con nosotros para revisar un plan de pago antes de '
                      . 'que se practique.</p>'
                    : '<p><strong>Su situación actual.</strong> Su crédito se encuentra '
                      . '<strong>al día</strong>, por lo que no procede ninguna '
                      . 'aplicación. Esta comunicación se le dirige únicamente para su '
                      . 'conocimiento.</p>')

                . '<p><strong>Qué se espera de usted.</strong> No se requiere ninguna '
                . 'gestión de su parte. Si prefiere que no se aplique la compensación, '
                . 'puede <strong>revocar la autorización</strong> mediante comunicación '
                . 'escrita dirigida a esta institución; la revocación no afecta a la '
                . 'exigibilidad del saldo adeudado ni a las gestiones de cobro que '
                . 'correspondan.</p>';

            $html = PlantillaCorreo::base(
                'Aviso de compensacion',
                'Señor(a) ' . $nombre,
                $cuerpo,
                [
                    ['etiqueta' => 'Cuenta',  'valor' => (string) $c['numero_cuenta']],
                    ['etiqueta' => 'Saldo actual',
                     'valor' => Monto::deCentavos((int) $c['saldo_cent'])->formateado()],
                    ['etiqueta' => 'Remanente minimo reservado',
                     'valor' => Monto::deCentavos((int) $c['colchon'])->formateado()],
                    ['etiqueta' => 'Saldo vencido de su credito',
                     'valor' => Monto::deCentavos($vencido)->formateado()],
                ],
                null,
                null,
                null,
                'Esta comunicacion se emite con caracter previo, para que usted pueda '
                . 'ponerse al dia o revocar la autorizacion antes de que se practique '
                . 'aplicacion alguna. Conservela para su control.'
            );

            (new Correo())->enviar(
                (string) $c['correo'],
                'Aviso de compensacion · cuenta ' . $c['numero_cuenta'],
                $html,
                "AVISO DE COMPENSACION\n\nSenor(a) {$nombre}:\n\n"
                . "{$institucion} le comunica que, conforme a la clausula {$clCompensacionLlano} "
                . "del contrato de credito, aplicara la compensacion pactada sobre los "
                . "fondos disponibles en su cuenta {$c['numero_cuenta']} cuando su "
                . "credito registre cuotas vencidas.\n\n"
                . "Se aplicara solo sobre cuotas VENCIDAS, empezando por la mas antigua, "
                . "cubriendo unicamente cuotas completas y conservando siempre un "
                . "remanente minimo en su cuenta. De cada aplicacion se le informara.\n\n"
                . 'Saldo vencido actual de su credito: '
                . Monto::deCentavos($vencido)->formateado() . "\n\n"
                . "Si desea evitarlo, puede ponerse al dia o comunicarse con nosotros. "
                . "Tambien puede revocar la autorizacion por escrito.\n",
                $nombre,
                $this->copiasDeSeguimiento(),
                Parametros::obtener('cobranza.remitente',
                                    'CashMan H.A. · Departamento de Cobranza')
            );

            $this->notificaciones->crear(
                (int) $c['id'],
                'compensacion.aviso',
                'Aviso: compensación de saldos',
                'Le informamos que aplicaremos la compensación pactada en la cláusula '
                . 'DÉCIMA CUARTA de su contrato: cuando su crédito registre cuotas '
                . 'vencidas y su cuenta tenga saldo disponible, lo aplicaremos a esas '
                . 'cuotas. Le avisaremos de cada aplicación.',
                ['cuenta' => (string) $c['numero_cuenta']],
                'info'
            );

            $avisados++;
        }

        Auditoria::registrar('compensacion.aviso_previo', Auditoria::EXITO, null,
            'parametro', 'compensacion.activa',
            ['avisados' => $avisados, 'sin_correo' => $sinCorreo]);

        return ['avisados' => $avisados, 'sin_correo' => $sinCorreo];
    }

    /**
     * Las direcciones que reciben copia de todo lo de crédito.
     *
     * @return list<string>
     */
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
     * Lo que se le ha compensado a alguien.
     *
     * @return list<array<string,mixed>>
     */
    public function historial(int $usuarioId, int $limite = 50): array
    {
        return BaseDatos::todas(
            'SELECT co.*, c.numero_cuenta, cr.folio, q.numero AS cuota_numero
               FROM compensaciones co
               JOIN cuentas c    ON c.id = co.cuenta_id
               LEFT JOIN creditos cr ON cr.id = co.credito_id
               LEFT JOIN cuotas q    ON q.id = co.cuota_id
              WHERE co.usuario_id = ?
              ORDER BY co.id DESC
              LIMIT ' . max(1, min(200, $limite)),
            [$usuarioId]
        );
    }
}
