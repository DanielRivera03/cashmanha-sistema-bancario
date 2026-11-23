<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Documento;

use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\Clausulado;
use CashMan\Dominio\Credito\DestinoDeCredito;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * Construcción de los documentos PDF del sistema.
 */
final class ServicioDocumentos
{
    public function __construct()
    {
        // FPDF no usa namespaces ni autoload PSR-4.
        if (!class_exists('FPDF', false)) {
            require_once CM_RAIZ . '/lib/FPDF/fpdf.php';
        }
    }

    /**
     * Estado de cuenta.
     *
     * @return array{ruta:string, hash:string, nombre:string}
     */
    public function estadoCuenta(int $estadoId): array
    {
        $e = BaseDatos::unaFila('SELECT * FROM estados_cuenta WHERE id = ?', [$estadoId]);
        if ($e === null) {
            throw new \RuntimeException('Estado de cuenta no encontrado.');
        }

        $cuenta = BaseDatos::unaFila(
            'SELECT c.numero_cuenta, c.moneda, p.nombre AS producto,
                    u.nombres, u.apellidos, u.correo, u.documento_identidad,
                    d.dui, d.direccion, d.telefono
               FROM cuentas c
               JOIN productos p ON p.id = c.producto_id
               JOIN usuarios  u ON u.id = c.usuario_id
          LEFT JOIN detalle_usuarios d ON d.usuario_id = u.id
              WHERE c.id = ?',
            [(int) $e['cuenta_id']]
        );

        $movs = BaseDatos::todas(
            'SELECT m.importe_cent, m.descripcion, m.saldo_posterior_cent, m.creado_en,
                    a.referencia, a.tipo
               FROM movimientos m
               JOIN asientos a ON a.id = m.asiento_id
              WHERE m.cuenta_id = ?
                AND DATE(m.creado_en) BETWEEN ? AND ?
              ORDER BY m.id ASC',
            [(int) $e['cuenta_id'], $e['periodo_inicio'], $e['periodo_fin']]
        );

        $pdf = new GeneradorPdf();
        $pdf->configurar(
            'Estado de cuenta',
            '',
            'EC-' . str_pad((string) $estadoId, 6, '0', STR_PAD_LEFT)
        );
        $pdf->AddPage();

        // --- Datos del titular ---------------------------------------
        $pdf->tituloSeccion('Datos del titular');
        $pdf->bloqueDatos([
            'Titular'   => trim(($cuenta['nombres'] ?? '') . ' ' . ($cuenta['apellidos'] ?? '')),
            'Cuenta'    => (string) ($cuenta['numero_cuenta'] ?? ''),
            'Documento' => (string) ($cuenta['dui'] ?? $cuenta['documento_identidad'] ?? '-'),
            'Producto'  => (string) ($cuenta['producto'] ?? ''),
            'Correo'    => (string) ($cuenta['correo'] ?? ''),
            'Moneda'    => (string) ($cuenta['moneda'] ?? 'USD'),
        ]);

        // --- Periodo --------------------------------------------------
        $pdf->tituloSeccion('Periodo del estado');
        $pdf->bloqueDatos([
            'Desde'         => $this->fecha((string) $e['periodo_inicio']),
            'Hasta'         => $this->fecha((string) $e['periodo_fin']),
            'Fecha de corte'=> $this->fecha((string) $e['fecha_corte']),
            'Movimientos'   => (string) (int) $e['cantidad_movimientos'],
        ]);

        // --- Resumen --------------------------------------------------
        $pdf->tituloSeccion('Resumen del periodo');
        $pdf->bloqueDatos([
            'Saldo inicial' => $this->dinero((int) $e['saldo_inicial_cent']),
            'Total cargos'  => $this->dinero((int) $e['total_cargos_cent']),
            'Total abonos'  => $this->dinero((int) $e['total_abonos_cent']),
            'Saldo final'   => $this->dinero((int) $e['saldo_final_cent']),
        ]);

        $pdf->totalDestacado('SALDO AL CORTE', $this->dinero((int) $e['saldo_final_cent']));

        // --- Movimientos ----------------------------------------------
        $pdf->tituloSeccion('Detalle de movimientos');

        if ($movs === []) {
            $pdf->aviso('No se registraron movimientos durante este periodo.');
        } else {
            $filas = [];
            foreach ($movs as $m) {
                $i = (int) $m['importe_cent'];
                $filas[] = [
                    $this->fecha((string) $m['creado_en'], 'd/m/Y'),
                    (string) $m['referencia'],
                    mb_substr((string) ($m['descripcion'] ?: ucfirst((string) $m['tipo'])), 0, 42),
                    $i < 0 ? $this->dinero(-$i) : '',
                    $i > 0 ? $this->dinero($i) : '',
                    $this->dinero((int) $m['saldo_posterior_cent']),
                ];
            }

            $pdf->tabla(
                ['Fecha', 'Referencia', 'Concepto', 'Cargo', 'Abono', 'Saldo'],
                $filas,
                [20, 36, 60, 24, 24, 26],
                ['L', 'L', 'L', 'R', 'R', 'R']
            );
        }

        $pdf->aviso(
            'Este estado de cuenta refleja los movimientos registrados en el periodo indicado. '
            . 'Si identifica alguna operacion que no reconoce, comuniquese con atencion al cliente '
            . 'dentro de los 30 dias siguientes a la fecha de corte.'
        );

        $hash = (string) ($e['hash_documento'] ?? '');
        if ($hash !== '') {
            $pdf->sello($hash);
        }

        $nombre = 'estado-' . ($cuenta['numero_cuenta'] ?? 'cuenta')
                . '-' . substr((string) $e['periodo_fin'], 0, 7);
        $ruta   = $pdf->guardar($nombre);

        // Hash del ARCHIVO generado: prueba de que no se alteró después.
        $hashArchivo = hash_file('sha256', $ruta);

        BaseDatos::ejecutar(
            'UPDATE estados_cuenta SET ruta_pdf = ? WHERE id = ?',
            [basename($ruta), $estadoId]
        );

        return ['ruta' => $ruta, 'hash' => $hashArchivo, 'nombre' => basename($ruta)];
    }

    /**
     * Comprobante de una operación del libro mayor.
     *
     * @return array{ruta:string, hash:string, nombre:string}
     */
    /**
     * Estado de cuenta de una tarjeta de crédito.
     *
     * Lleva el detalle del periodo —cada consumo, cada pago, cada
     * cargo— y el cuadre completo: saldo anterior, lo del mes, lo que
     * se cobró por intereses y seguros, y el saldo que queda. Con eso
     * se puede comprobar un cargo; con un total suelto, no.
     *
     * @return array{ruta:string, hash:string, nombre:string}
     */
    public function estadoTarjeta(int $corteId): array
    {
        $c = BaseDatos::unaFila(
            'SELECT k.*, t.id AS tarjeta_id, p.tasa_interes_bp,
                    t.ultimos4, t.bin6, t.marca, t.limite_cent, t.titular,
                    t.tasa_diferido_bp, t.expira_mes, t.expira_anio,
                    p.nombre AS producto,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente,
                    u.documento_identidad, u.correo
               FROM tarjeta_cortes k
               JOIN tarjetas t ON t.id = k.tarjeta_id
               JOIN usuarios u ON u.id = t.usuario_id
          LEFT JOIN productos p ON p.id = t.producto_id
              WHERE k.id = ?',
            [$corteId]
        );

        if ($c === null) {
            throw new \RuntimeException('Estado de cuenta no encontrado.');
        }

        $movs = BaseDatos::todas(
            'SELECT tipo, importe_cent, comercio, categoria, referencia, creado_en
               FROM tarjeta_movimientos
              WHERE corte_id = ?
              ORDER BY creado_en ASC, id ASC',
            [$corteId]
        );

        /* Lo que se paga a plazos: planes diferidos y efectivo tomado
           contra la línea. Van juntos porque para el cliente son lo
           mismo —una cuota fija que le cae cada mes— aunque por dentro
           sean dos cosas distintas. */
        $plazos = $this->aPlazosDelCorte((int) $c['tarjeta_id'], (string) $c['fecha_corte']);

        $limite = (int) $c['limite_cent'];
        $saldo  = (int) $c['saldo_final_cent'];
        $uso    = $limite > 0 ? min(100.0, ($saldo / $limite) * 100) : 0.0;
        $tasaBp = (int) Parametros::entero('tarjeta.tasa_revolvente_bp', 4400);

        $pdf = new GeneradorPdf();
        $pdf->configurar(
            'Estado de cuenta',
            mb_strtoupper((string) $c['marca']) . ' ' . $this->panEnmascarado($c)
                . ' - ' . (string) ($c['producto'] ?? 'Tarjeta'),
            /* La referencia con la que el cliente reclama. La cabecera
               antepone «Folio», así que aquí va un folio y no la frase
               «Corte 09/11/2026», que se leía «Folio Corte 09/11/2026». */
            'EC-' . $c['ultimos4'] . '-' . date('Ym', (int) strtotime((string) $c['fecha_corte']))
        );
        $pdf->AddPage();

        // --- Quién y qué tarjeta ------------------------------------
        $pdf->bloqueDatos([
            'Cliente'   => (string) $c['cliente'],
            'Tarjeta'   => $this->panEnmascarado($c),
            'Documento' => (string) ($c['documento_identidad'] ?: 's/n'),
            'Periodo'   => $this->fecha((string) $c['periodo_inicio'])
                         . ' al ' . $this->fecha((string) $c['periodo_fin']),
        ]);

        // --- Los tres resúmenes, en fila ----------------------------
        $pdf->panelesResumen([
            [
                'titulo' => 'Resumen de cuenta',
                'filas'  => [
                    ['Limite de credito',  $this->dinero($limite)],
                    ['Saldo al corte',     $this->dinero($saldo), true],
                    ['Disponible',         $this->dinero(max(0, $limite - $saldo))],
                    ['A plazos pendiente', $this->dinero((int) $c['extra_saldo_cent'])],
                    ['Fecha de corte',     $this->fecha((string) $c['fecha_corte'])],
                    ['Limite de pago',     $this->fecha((string) $c['fecha_limite_pago'])],
                ],
            ],
            [
                'titulo' => 'Resumen de movimientos',
                'filas'  => [
                    ['Saldo anterior',   $this->dinero((int) $c['saldo_anterior_cent'])],
                    ['(+) Consumos',     $this->dinero((int) $c['consumos_cent'])],
                    ['(-) Pagos',        $this->dinero((int) $c['pagos_cent'])],
                    ['(+) Intereses',    $this->dinero((int) $c['intereses_cent'])],
                    ['(+) Seguros',      $this->dinero((int) $c['seguro_vida_cent']
                                                      + (int) $c['seguro_empleo_cent'])],
                    ['(+) Comisiones y mora', $this->dinero((int) $c['comisiones_cent']
                                                      + (int) $c['mora_cent'])],
                    ['(=) Saldo al corte', $this->dinero($saldo), true],
                ],
            ],
            [
                'titulo' => 'Detalle del pago del mes',
                'filas'  => [
                    ['Pago de contado', $this->dinero((int) $c['pago_contado_cent']), true],
                    ['Pago minimo',     $this->dinero((int) $c['pago_minimo_cent']), true],
                    ['Cuota de planes', $this->dinero((int) $c['extra_cuota_cent'])],
                    ['Intereses del mes', $this->dinero((int) $c['intereses_cent'])],
                    ['Seguros del mes',   $this->dinero((int) $c['seguro_vida_cent']
                                                       + (int) $c['seguro_empleo_cent'])],
                    ['Movimientos',     (string) (int) $c['movimientos']],
                    ['Paguese a mas tardar', $this->fecha((string) $c['fecha_limite_pago'])],
                ],
            ],
        ]);

        // --- Tasas, plazo y composición de la deuda -----------------
        //
        // Las tres cifras que la normativa manda declarar junto al pago
        // mínimo, y el desglose que enseña a dónde va cada dólar que se
        // abona. Sin este desglose, el cliente que paga el mínimo cada
        // mes no entiende por qué su saldo no baja: la respuesta es que
        // casi todo se va en intereses y cargos.
        $tasaNominal = (int) ($c['tasa_interes_bp'] ?? 0);

        if ($tasaNominal <= 0) {
            $tasaNominal = Parametros::entero('tarjeta.tasa_revolvente_bp', 4400);
        }

        /* La efectiva, que es la que se paga de verdad al capitalizar
           cada mes. Siempre es mayor que la nominal, y decir solo la
           nominal deja al cliente con una cifra que no cuadra con lo
           que ve cobrado. */
        $tasaEfectiva = (pow(1 + ($tasaNominal / 10000) / 12, 12) - 1) * 100;

        $diasPeriodo = max(1, (int) ((strtotime((string) $c['periodo_fin'])
                                    - strtotime((string) $c['periodo_inicio'])) / 86400) + 1);

        $compInteres  = (int) $c['intereses_cent'];
        $compComision = (int) $c['comisiones_cent']
                      + (int) $c['seguro_vida_cent'] + (int) $c['seguro_empleo_cent'];
        $compRecargo  = (int) $c['mora_cent'];
        $compCapital  = max(0, $saldo - $compInteres - $compComision - $compRecargo);

        $minimo = (int) $c['pago_minimo_cent'];

        /* En el mínimo se cubren primero los cargos; lo que sobra es lo
           único que baja la deuda. */
        $minInteres  = min($minimo, $compInteres);
        $minComision = min(max(0, $minimo - $minInteres), $compComision);
        $minRecargo  = min(max(0, $minimo - $minInteres - $minComision), $compRecargo);
        $minCapital  = max(0, $minimo - $minInteres - $minComision - $minRecargo);

        $pdf->tituloSeccion('Tasas y plazo de financiamiento');

        $pdf->panelesResumen([
            [
                'titulo' => 'Tasas aplicadas',
                'filas'  => [
                    ['Tasa nominal anual',  number_format($tasaNominal / 100, 2) . ' %'],
                    ['Tasa efectiva anual', number_format($tasaEfectiva, 2) . ' %', true],
                    ['Recargo por mora',    $this->dinero(
                        \CashMan\Dominio\Credito\Mora::cargoDeUnDia()) . ' por dia'],
                    ['Dias del periodo',    (string) $diasPeriodo],
                    ['Plazo de financiamiento',
                     (int) ($c['plazo_financiamiento_meses'] ?: 0) . ' meses'],
                    /* El mismo dato que el correo: pagando solo el
                       minimo, en cuantos meses queda liquidado. Antes
                       solo estaba en el correo y el PDF decia otra cosa. */
                    ['Al minimo se liquida en',
                     (int) ($c['meses_si_paga_minimo'] ?? 0) > 0
                        ? (int) $c['meses_si_paga_minimo'] . ' meses'
                        : 'no se liquida al minimo', true],
                ],
            ],
            [
                'titulo' => 'Si paga el minimo',
                'filas'  => [
                    ['Intereses',  $this->dinero($minInteres)],
                    ['Comisiones', $this->dinero($minComision)],
                    ['Recargos',   $this->dinero($minRecargo)],
                    ['A capital',  $this->dinero($minCapital), true],
                    ['TOTAL',      $this->dinero($minimo), true],
                ],
            ],
            [
                'titulo' => 'Si paga de contado',
                'filas'  => [
                    ['Intereses',  $this->dinero($compInteres)],
                    ['Comisiones', $this->dinero($compComision)],
                    ['Recargos',   $this->dinero($compRecargo)],
                    ['A capital',  $this->dinero($compCapital), true],
                    ['TOTAL',      $this->dinero($saldo), true],
                ],
            ],
        ]);

        if ($minCapital <= 0 && $saldo > 0) {
            $pdf->aviso(
                'Pagando el minimo de este corte no abona nada a capital: alcanza solo '
                . 'para los intereses y los cargos del mes, y su deuda no baja.'
            );
        }

        /* El excedente sobre el limite: exigible de inmediato y ya
           sumado al pago minimo de este corte. El mismo aviso que el
           correo, para que ambos documentos digan lo mismo. */
        $excedenteLimite = $limite > 0 ? max(0, $saldo - $limite) : 0;

        if ($excedenteLimite > 0) {
            $pdf->aviso(
                'Su saldo supera el limite aprobado en ' . $this->dinero($excedenteLimite)
                . '. Ese excedente es exigible de inmediato y ya esta incluido en el '
                . 'pago minimo de este corte. Si no se cubre a la fecha limite de pago, '
                . 'la tarjeta se inhabilita para nuevos consumos hasta solventarlo.'
            );
        }

        // --- Las compras a cuotas, con su avance --------------------
        $planes = BaseDatos::todas(
            'SELECT folio, plazo_meses, cuota_cent, monto_cent, cuotas_pagadas,
                    GREATEST(0, total_cent - pagado_cent) AS resta_cent
               FROM tarjeta_extrafinanciamientos
              WHERE tarjeta_id = ? AND estado = "vigente"
              ORDER BY id',
            [(int) $c['tarjeta_id']]
        );

        if ($planes !== []) {
            $pdf->tituloSeccion('Sus compras a cuotas');

            $pdf->tablaDetalle(
                ['Plan', 'Monto', 'Plazo', 'Cuota', 'Avance', 'Le resta'],
                array_map(
                    fn(array $p): array => [
                        (string) $p['folio'],
                        $this->dinero((int) $p['monto_cent']),
                        (int) $p['plazo_meses'] . ' meses',
                        $this->dinero((int) $p['cuota_cent']),
                        (int) $p['cuotas_pagadas'] . ' de ' . (int) $p['plazo_meses'],
                        $this->dinero((int) $p['resta_cent']),
                    ],
                    $planes
                ),
                [34, 26, 22, 26, 24, 26]
            );

            $pdf->aviso(
                'La cuota de estos planes SE SUMA al pago minimo de la tarjeta. Pagar solo '
                . 'el minimo sin la cuota del plan deja el plan en mora.'
            );
        }

        // --- El termómetro ------------------------------------------
        $pdf->tituloSeccion('Termometro de uso de su linea');
        $pdf->termometro(
            $uso,
            'Esta usando el ' . number_format($uso, 1) . '% de su linea de '
            . $this->dinero($limite) . '. '
            . ($uso <= 60
                ? 'Es un uso comodo: le queda margen para un imprevisto.'
                : ($uso <= 85
                    ? 'Conviene bajarlo. Por encima del 60% el margen para un imprevisto '
                      . 'se estrecha y el saldo empieza a costar mas en intereses.'
                    : 'Uso alto. Con la linea casi agotada, un cargo inesperado puede '
                      . 'rebasarla y generar comision por sobregiro.'))
        );

        // --- El detalle ---------------------------------------------
        $pdf->tituloSeccion('Detalle de movimientos del periodo');

        if ($movs === []) {
            $pdf->aviso('Sin movimientos en este periodo.');
        } else {
            $filas = [];

            foreach ($movs as $m) {
                $abono = in_array((string) $m['tipo'], ['pago', 'abono', 'reverso'], true);
                $fecha = (string) $m['creado_en'];

                /* Fecha de transacción y fecha de proceso.
                   En una tarjeta de verdad no coinciden: el comercio
                   captura un día y la red liquida otro. Aquí la de
                   proceso es la de liquidación del movimiento. */
                $filas[] = [
                    $this->fecha($fecha),
                    $this->fecha($fecha),
                    mb_substr((string) ($m['referencia'] ?? ''), 0, 20),
                    (string) ($m['comercio'] ?: ucfirst((string) $m['tipo'])),
                    $abono ? '' : $this->dinero((int) $m['importe_cent']),
                    $abono ? $this->dinero((int) $m['importe_cent']) : '',
                ];
            }

            $pdf->tablaDetalle(
                ["Fecha de\ntransaccion", "Fecha de\nproceso", "Numero de\nautorizacion",
                 'Concepto', 'Cargos', 'Abonos'],
                $filas,
                [22, 22, 30, 60, 27, 27],
                ['C', 'C', 'L', 'L', 'R', 'R']
            );
        }

        // --- Lo que se paga a plazos --------------------------------
        $pdf->tituloSeccion('Resumen de extrafinanciamiento y compras a plazos');

        if ($plazos === []) {
            $pdf->aviso('No tiene compras a plazos ni extrafinanciamiento vigente.');
        } else {
            $filas = [];

            foreach ($plazos as $p) {
                $filas[] = [
                    $p['folio'],
                    $p['descripcion'],
                    $this->fecha($p['fecha']),
                    $this->dinero($p['monto_cent']),
                    $p['pagadas'] . '/' . $p['plazo'],
                    $this->dinero($p['cuota_cent']),
                    $this->dinero($p['pendiente_cent']),
                ];
            }

            $pdf->tablaDetalle(
                ['Folio', 'Concepto', 'Otorgado', "Monto\noriginal", 'Cuotas',
                 "Cuota del\nmes", 'Pendiente'],
                $filas,
                [26, 50, 20, 24, 16, 24, 28],
                ['L', 'L', 'C', 'R', 'C', 'R', 'R']
            );
        }

        // --- Qué hay dentro de la cuota del mes ---------------------
        $pdf->tituloSeccion('Detalle de la cuota mensual');
        $pdf->tabla(
            ['Concepto', 'Importe'],
            [
                ['Consumos del periodo',                $this->dinero((int) $c['consumos_cent'])],
                ['Cuota de planes y extrafinanciamiento', $this->dinero((int) $c['extra_cuota_cent'])],
                ['Intereses del periodo',               $this->dinero((int) $c['intereses_cent'])],
                ['Seguro de vida',                      $this->dinero((int) $c['seguro_vida_cent'])],
                ['Seguro de desempleo',                 $this->dinero((int) $c['seguro_empleo_cent'])],
                ['Comisiones',                          $this->dinero((int) $c['comisiones_cent'])],
                ['Mora',                                $this->dinero((int) $c['mora_cent'])],
            ],
            [120, 68],
            ['L', 'R']
        );

        $pdf->totalDestacado('PAGO DE CONTADO', $this->dinero((int) $c['pago_contado_cent']));

        // --- Lo que hay que decirle ---------------------------------
        $pdf->tituloSeccion('Informacion adicional');

        $dias = (int) (new \DateTimeImmutable((string) $c['periodo_inicio']))
            ->diff(new \DateTimeImmutable((string) $c['periodo_fin']))->days + 1;

        $efectiva = (((1 + ($tasaBp / 10000) / 12) ** 12) - 1) * 100;

        $pdf->bloqueDatos([
            'Tasa nominal anual'   => number_format($tasaBp / 100, 2) . '%',
            'Tasa efectiva anual'  => number_format($efectiva, 2) . '%',
            'Tasa de compras a plazos' => number_format((int) $c['tasa_diferido_bp'] / 100, 2) . '%',
            'Dias del periodo'     => (string) $dias,
            'Pago minimo'          => number_format(
                Parametros::entero('tarjeta.pago_minimo_pct', 500) / 100, 2
            ) . '% del saldo',
            'Vencimiento del plastico' => str_pad((string) (int) $c['expira_mes'], 2, '0', STR_PAD_LEFT)
                . '/' . (string) (int) $c['expira_anio'],
        ]);

        $pdf->cajaLegal('Informacion relevante', $this->avisosDelCorte($c, $tasaBp));

        $ruta = $pdf->guardar(
            'estado-tarjeta-' . $c['ultimos4'] . '-' . $c['fecha_corte']
            . '-' . strtoupper(bin2hex(random_bytes(3)))
        );

        return [
            'ruta'   => $ruta,
            'hash'   => hash_file('sha256', $ruta),
            'nombre' => basename($ruta),
        ];
    }

    /**
     * El número de la tarjeta como se puede imprimir.
     *
     * Primeros seis y últimos cuatro: es lo que permite la norma y lo
     * que basta para que el cliente reconozca cuál de sus tarjetas
     * tiene delante. El resto nunca sale de la base cifrado.
     *
     * @param array<string,mixed> $c
     */
    private function panEnmascarado(array $c): string
    {
        $bin   = (string) ($c['bin6'] ?? '');
        $final = (string) ($c['ultimos4'] ?? '');
        $largo = (string) ($c['marca'] ?? '') === 'amex' ? 15 : 16;

        if ($bin === '') {
            return str_repeat('*', $largo - 4) . $final;
        }

        return substr($bin, 0, 4) . str_repeat('*', max(1, $largo - 8)) . $final;
    }

    /**
     * Planes y extrafinanciamiento vivos en la fecha del corte.
     *
     * Los dos se cobran igual —una cuota fija dentro del estado de
     * cuenta— y el cliente no distingue entre ellos, así que en el
     * documento van en la misma tabla.
     *
     * @return list<array<string,mixed>>
     */
    private function aPlazosDelCorte(int $tarjetaId, string $corte): array
    {
        $filas = [];

        $planes = BaseDatos::todas(
            'SELECT p.folio, p.comercio, p.tipo, p.monto_cent, p.cuotas, p.cuotas_pagadas,
                    p.cuota_cent, p.creado_en,
                    (SELECT COALESCE(SUM(q.total_cent),0) FROM tarjeta_plan_cuotas q
                      WHERE q.plan_id = p.id AND q.estado <> "pagada") AS pendiente_cent
               FROM tarjeta_planes p
              WHERE p.tarjeta_id = ? AND p.estado = "vigente"
              ORDER BY p.id',
            [$tarjetaId]
        );

        foreach ($planes as $p) {
            $filas[] = [
                'folio'       => (string) $p['folio'],
                'descripcion' => ((string) $p['comercio'] ?: 'Compra a plazos')
                               . ((string) $p['tipo'] === 'tasa_cero' ? ' (sin intereses)' : ''),
                'fecha'       => (string) $p['creado_en'],
                'monto_cent'  => (int) $p['monto_cent'],
                'plazo'       => (int) $p['cuotas'],
                'pagadas'     => (int) $p['cuotas_pagadas'],
                'cuota_cent'  => (int) $p['cuota_cent'],
                'pendiente_cent' => (int) $p['pendiente_cent'],
            ];
        }

        $extras = BaseDatos::todas(
            'SELECT e.folio, e.monto_cent, e.plazo_meses, e.cuotas_pagadas, e.cuota_cent,
                    e.otorgado_en,
                    (SELECT COALESCE(SUM(q.total_cent),0) FROM tarjeta_extra_cuotas q
                      WHERE q.extra_id = e.id AND q.cobrada_en IS NULL) AS pendiente_cent
               FROM tarjeta_extrafinanciamientos e
              WHERE e.tarjeta_id = ? AND e.estado = "vigente"
              ORDER BY e.id',
            [$tarjetaId]
        );

        foreach ($extras as $e) {
            $filas[] = [
                'folio'       => (string) $e['folio'],
                'descripcion' => 'Extrafinanciamiento en efectivo',
                'fecha'       => (string) $e['otorgado_en'],
                'monto_cent'  => (int) $e['monto_cent'],
                'plazo'       => (int) $e['plazo_meses'],
                'pagadas'     => (int) $e['cuotas_pagadas'],
                'cuota_cent'  => (int) $e['cuota_cent'],
                'pendiente_cent' => (int) $e['pendiente_cent'],
            ];
        }

        return $filas;
    }

    /**
     * La letra pequeña, con las cifras de ESTE corte dentro.
     *
     * Un aviso genérico sobre el pago mínimo no mueve a nadie. Dicho
     * con el saldo real —«pagando solo el mínimo tardaría N meses y
     * pagaría X de intereses»— es la advertencia que de verdad cambia
     * la decisión, y es la que varias normativas ya exigen imprimir.
     *
     * @param array<string,mixed> $c
     * @return list<string>
     */
    private function avisosDelCorte(array $c, int $tasaBp): array
    {
        $saldo  = (int) $c['saldo_final_cent'];
        $limite = $this->fecha((string) $c['fecha_limite_pago']);

        $avisos = [
            'Pagando el total de contado (' . $this->dinero((int) $c['pago_contado_cent'])
            . ') a mas tardar el ' . $limite . ', los consumos de este periodo no generan '
            . 'intereses. Es la unica forma de usar la tarjeta sin costo financiero.',

            'Pagando solo el minimo, el saldo que quede devenga intereses desde el proximo '
            . 'corte a la tasa vigente, y esos intereses se suman al saldo del mes siguiente.',
        ];

        /* Los meses son los MISMOS que el resto del documento y el
           correo: el valor guardado del corte, no un recalculo propio.
           Antes aqui se simulaba con otro metodo (porcentaje del saldo
           decreciente) y el PDF decia «155 meses» donde el panel y el
           correo decian «36». El interes se deriva del total pagado por
           ese plazo con capital fijo, igual que el correo. */
        $meses = (int) ($c['meses_si_paga_minimo'] ?? 0);

        if ($saldo > 0 && $meses <= 0) {
            $avisos[] = 'Con el saldo actual, el pago minimo no alcanza a cubrir los '
                . 'intereses del mes: pagandolo puntualmente su deuda NO bajaria, subiria. '
                . 'Abone por encima del minimo o acerquese a negociar un plan.';
        } elseif ($meses > 0) {
            $tasaNominal = (int) ($c['tasa_interes_bp'] ?? 0);
            if ($tasaNominal <= 0) {
                $tasaNominal = Parametros::entero('tarjeta.tasa_revolvente_bp', 4400);
            }
            $intereses = max(0, $this->totalPagandoMinimo($saldo, $tasaNominal, $meses) - $saldo);

            $avisos[] = 'Si solo cancela el pago minimo y no vuelve a usar la tarjeta, '
                . 'tardaria aproximadamente ' . $meses . ' meses en liquidar los '
                . $this->dinero($saldo) . ' de este corte, y habria pagado '
                . $this->dinero($intereses) . ' en intereses.';
        }

        $avisos[] = 'Un pago menor al minimo no evita la mora: la cuota se considera '
            . 'incumplida, se cobran intereses moratorios y el incumplimiento se informa '
            . 'a las centrales de riesgo.';

        $avisos[] = 'Dispone de 30 dias desde la fecha de este corte para reclamar '
            . 'cualquier movimiento que no reconozca. Pasado ese plazo se tiene por '
            . 'aceptado el estado de cuenta.';

        $avisos[] = 'Puede diferir a cuotas hasta 5 compras de este estado de cuenta. '
            . 'Las compras en linea en la tienda CashMan con plazo sin intereses se '
            . 'difieren solas, con el plazo que eligio al pagar.';

        $avisos[] = 'CashMan H.A. El Salvador, S.A. de C.V. esta sujeta a la Ley de Bancos '
            . 'y a la supervision de la Superintendencia del Sistema Financiero.';

        return $avisos;
    }

    /**
     * Cuánto tardaría en salir pagando solo el mínimo.
     *
     * Se simula mes a mes porque no hay fórmula cerrada: el mínimo es
     * un porcentaje del saldo con un piso en efectivo, así que la cuota
     * cambia cada mes hasta que el piso la fija.
     *
     * Devuelve [null, 0] cuando el mínimo no cubre ni los intereses: ahí
     * la deuda crece en lugar de bajar, y decirle al cliente «tardaria
     * N meses» seria mentirle.
     *
     * @return array{0:int|null, 1:int}
     */
    /**
     * Cuánto acaba pagando quien paga solo el mínimo, con la MISMA
     * cuenta que el corte y el correo: capital en partes iguales más el
     * interés del mes. Antes aquí había otra simulación (porcentaje del
     * saldo decreciente) que estiraba el plazo —el PDF decía «155
     * meses» mientras el panel y el correo decían «36»—. Ahora el plazo
     * sale del valor guardado y esto solo suma lo pagado en él.
     */
    private function totalPagandoMinimo(int $saldo, int $tasaBp, int $meses): int
    {
        if ($saldo <= 0 || $meses <= 0) {
            return 0;
        }

        $pct  = max(0, Parametros::entero('tarjeta.pago_minimo_pct', 500));
        $piso = max(0, Parametros::entero('tarjeta.pago_minimo_piso_cent', 1000));
        $i    = $tasaBp / 10000 / 12;

        $total       = 0;
        $s           = $saldo;
        $capitalFijo = (int) ceil($saldo / $meses);

        for ($m = 0; $m < $meses && $s > 0; $m++) {
            $interes = (int) round($s * $i);
            $cuota   = max($piso, (int) round($s * $pct / 10000), $capitalFijo + $interes);
            $cuota   = min($cuota, $s + $interes);

            if ($cuota <= $interes) {
                return 0;
            }

            $total += $cuota;
            $s      = $s + $interes - $cuota;
        }

        return $total;
    }
    public function comprobante(int $asientoId): array
    {
        $a = BaseDatos::unaFila(
            // «Sistema» cuando el asiento no lo originó una persona:
            // una compra de invitado, el devengo de mora nocturno, un
            // reverso automático. Dejar el nombre vacío en el
            // comprobante solo haría que el cliente se pregunte quién.
            'SELECT a.*,
                    COALESCE(CONCAT(u.nombres," ",u.apellidos), "Sistema") AS operador
               FROM asientos a LEFT JOIN usuarios u ON u.id = a.creado_por
              WHERE a.id = ?',
            [$asientoId]
        );
        if ($a === null) {
            throw new \RuntimeException('Operación no encontrada.');
        }

        /* Las cuentas técnicas no tienen titular, así que el JOIN tiene
           que ser externo: con el interno sus movimientos desaparecían
           de la consulta y un asiento que solo las toca se quedaba sin
           ninguna fila que leer. */
        $movs = BaseDatos::todas(
            'SELECT m.importe_cent, m.descripcion, m.saldo_posterior_cent,
                    c.numero_cuenta, c.es_tecnica,
                    COALESCE(CONCAT(u.nombres," ",u.apellidos), "") AS titular
               FROM movimientos m
               JOIN cuentas  c ON c.id = m.cuenta_id
          LEFT JOIN usuarios u ON u.id = c.usuario_id
              WHERE m.asiento_id = ?
              ORDER BY m.importe_cent',
            [$asientoId]
        );

        $pdf = new GeneradorPdf('P', 'mm', [140, 200]);  // formato tipo voucher
        $pdf->configurar('Comprobante de operacion', '', (string) $a['referencia']);
        $pdf->AddPage();

        $pdf->tituloSeccion('Datos de la operacion');
        $pdf->bloqueDatos([
            'Referencia' => (string) $a['referencia'],
            'Tipo'       => ucfirst(str_replace('_', ' ', (string) $a['tipo'])),
            'Fecha'      => $this->fecha((string) $a['creado_en'], 'd/m/Y H:i:s'),
            'Operador'   => (string) $a['operador'],
        ], 1);

        $pdf->tituloSeccion('Movimientos');

        $filas  = [];
        $monto  = 0;

        /* El importe sale de CUALQUIER partida, no solo de las de
           cliente.
         *
         * Un cobro con tarjeta de otro banco se asienta entre dos
         * cuentas técnicas —el dinero viene de fuera y se va a
         * extinguir deuda—, así que al mirar solo las de cliente el
         * comprobante salía con la tabla vacía y «MONTO DE LA
         * OPERACION $0.00» en una operación de cuatrocientos dólares. */
        foreach ($movs as $m) {
            $monto = max($monto, abs((int) $m['importe_cent']));
        }

        foreach ($movs as $m) {
            if ((int) $m['es_tecnica'] === 1) {
                continue;   // la contrapartida técnica no interesa al cliente
            }
            $i = (int) $m['importe_cent'];
            $filas[] = [
                (string) $m['numero_cuenta'],
                // El ajuste al ancho real lo hace la tabla: recortar
                // aqui a 24 caracteres le quitaba la ultima letra a
                // cualquier nombre de 25, que cabia de sobra.
                (string) $m['titular'],
                ($i >= 0 ? '+' : '-') . $this->dinero(abs($i)),
            ];
        }

        if ($filas === []) {
            /* Ninguna cuenta del cliente se movió, y eso es correcto.
               Pasa cuando el dinero entra de fuera —una tarjeta de otro
               banco— y se va directo a extinguir deuda. Una tabla vacía
               no lo explica; esto sí. */
            $pdf->aviso(
                'Esta operacion no abono ni cargo ninguna de sus cuentas: el importe '
                . 'entro desde un medio de pago externo y se aplico directamente a lo '
                . 'que se estaba pagando. El efecto se ve en el producto al que '
                . 'corresponde —su credito, su tarjeta o su pedido—, no en su cuenta '
                . 'de ahorro.'
            );
        } else {
            $pdf->tabla(
                ['Cuenta', 'Titular', 'Importe'],
                $filas,
                [34, 48, 30],
                ['L', 'L', 'R']
            );
        }

        $pdf->totalDestacado('MONTO DE LA OPERACION', $this->dinero($monto));

        if (!empty($a['descripcion'])) {
            $pdf->aviso('Concepto: ' . $a['descripcion']);
        }

        $pdf->sello((string) $a['hash_actual']);

        $ruta = $pdf->guardar('comprobante-' . $a['referencia']);

        return [
            'ruta'   => $ruta,
            'hash'   => hash_file('sha256', $ruta),
            'nombre' => basename($ruta),
        ];
    }

    /**
     * Tabla de amortización de un crédito.
     *
     * @return array{ruta:string, hash:string, nombre:string}
     */
    public function tablaAmortizacion(int $creditoId): array
    {
        $c = BaseDatos::unaFila(
            'SELECT c.*, p.nombre AS producto,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente, u.correo,
                    d.dui
               FROM creditos c
               JOIN productos p ON p.id = c.producto_id
               JOIN usuarios  u ON u.id = c.usuario_id
          LEFT JOIN detalle_usuarios d ON d.usuario_id = u.id
              WHERE c.id = ?',
            [$creditoId]
        );
        if ($c === null) {
            throw new \RuntimeException('Crédito no encontrado.');
        }

        /* Lo que se financio, con sus datos.
         *
         * Puede no estar: los creditos anteriores a que se
         * capturara esto no lo tienen, y el contrato se emite
         * igual, sin la clausula del bien, en vez de reventar. */
        $c['objeto'] = BaseDatos::unaFila(
            'SELECT * FROM credito_objeto WHERE credito_id = ?',
            [$creditoId]
        ) ?? [];

        $cuotas = BaseDatos::todas(
            'SELECT * FROM cuotas WHERE credito_id = ? ORDER BY numero',
            [$creditoId]
        );

        $pdf = new GeneradorPdf();
        $pdf->configurar('Tabla de amortizacion', '', (string) ($c['folio'] ?? ''));
        $pdf->AddPage();

        $pdf->tituloSeccion('Datos del credito');
        $pdf->bloqueDatos([
            'Cliente'   => (string) $c['cliente'],
            'Documento' => (string) ($c['dui'] ?? '-'),
            'Producto'  => (string) $c['producto'],
            'Folio'     => (string) ($c['folio'] ?? '-'),
            'Monto'     => $this->dinero((int) $c['monto_cent']),
            'Plazo'     => (int) $c['plazo_meses'] . ' meses',
            'Tasa anual'=> number_format((int) $c['tasa_bp'] / 100, 2) . ' %',
            'Cuota'     => $this->dinero((int) $c['cuota_cent']),
        ]);

        $pdf->tituloSeccion('Plan de pagos');

        $filas = [];
        $totalInteres = 0;
        $totalCuota   = 0;

        foreach ($cuotas as $q) {
            $totalInteres += (int) $q['interes_cent'];
            $totalCuota   += (int) $q['total_cent'];

            $filas[] = [
                (string) (int) $q['numero'],
                $this->fecha((string) $q['fecha_vencimiento'], 'd/m/Y'),
                $this->dinero((int) $q['capital_cent']),
                $this->dinero((int) $q['interes_cent']),
                $this->dinero((int) $q['total_cent']),
                ucfirst((string) $q['estado']),
            ];
        }

        $pdf->tabla(
            ['#', 'Vencimiento', 'Capital', 'Interes', 'Cuota', 'Estado'],
            $filas,
            [12, 30, 32, 32, 34, 28],
            ['C', 'L', 'R', 'R', 'R', 'L']
        );

        $pdf->Ln(2);
        $pdf->bloqueDatos([
            'Total capital'  => $this->dinero((int) $c['monto_cent']),
            'Total interes'  => $this->dinero($totalInteres),
        ]);
        $pdf->totalDestacado('TOTAL A PAGAR', $this->dinero($totalCuota));

        $pdf->aviso(
            'El pago puntual de las cuotas mejora su historial crediticio. '
            . 'Las cuotas vencidas generan intereses moratorios calculados por dia '
            . 'sobre el saldo pendiente.'
        );

        $ruta = $pdf->guardar('amortizacion-' . ($c['folio'] ?? $creditoId));

        return [
            'ruta'   => $ruta,
            'hash'   => hash_file('sha256', $ruta),
            'nombre' => basename($ruta),
        ];
    }

    // -----------------------------------------------------------------

    /**
     * Contrato de crédito.
     *
     * Reproduce el `contrato-modelo-clientes-solicitudes-nuevas-crediticias`
     * del sistema anterior, que era una plantilla con los datos del
     * crédito y un espacio para firmar. Aquí, además, el documento se
     * sella con su SHA-256: el mismo contrato vuelve a generar el mismo
     * hash, así que cualquier alteración posterior se detecta
     * comparándolo con el que quedó guardado al firmarse.
     *
     * @return array{ruta:string, hash:string, nombre:string}
     */
    /**
     * El contrato de una cuenta de depósito.
     *
     * No existía: las cuentas se abrían y el cliente se quedaba sin
     * nada escrito que dijera qué había contratado, qué le cuesta, qué
     * topes tiene y cómo se cierra. En una cuenta abierta por internet
     * eso es más grave todavía, porque no hubo nadie delante que se lo
     * explicara.
     *
     * @return array{ruta:string, hash:string, nombre:string}
     */
    public function contratoCuenta(int $cuentaId): array
    {
        $c = BaseDatos::unaFila(
            'SELECT cu.*, p.nombre AS producto, p.codigo AS producto_codigo,
                    p.tasa_interes_bp, p.saldo_minimo_cent, p.beneficios,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente,
                    u.usuario, u.correo, u.documento_identidad, u.telefono,
                    d.direccion
               FROM cuentas cu
               JOIN productos p ON p.id = cu.producto_id
               JOIN usuarios  u ON u.id = cu.usuario_id
          LEFT JOIN detalle_usuarios d ON d.usuario_id = u.id
              WHERE cu.id = ?',
            [$cuentaId]
        );

        if ($c === null) {
            throw new \RuntimeException('Cuenta no encontrada.');
        }

        $esDigital = (string) $c['producto_codigo'] === Parametros::obtener('cuenta_digital.producto', 'AHO-DIG');

        $pdf = new GeneradorPdf();
        $pdf->configurar(
            'Contrato de cuenta de deposito',
            (string) $c['producto'],
            (string) $c['numero_cuenta']
        );
        $pdf->AddPage();

        $pdf->tituloSeccion('El titular');
        $pdf->bloqueDatos([
            'Nombre'     => (string) $c['cliente'],
            'Documento'  => (string) ($c['documento_identidad'] ?? '—'),
            'Usuario'    => (string) $c['usuario'],
            'Correo'     => (string) $c['correo'],
            'Telefono'   => (string) ($c['telefono'] ?? '—'),
            'Direccion'  => (string) ($c['direccion'] ?? 'No declarada'),
        ]);

        $pdf->tituloSeccion('La cuenta');

        $tasa = (int) $c['tasa_interes_bp'];

        $datos = [
            'Numero de cuenta' => (string) $c['numero_cuenta'],
            'Producto'         => (string) $c['producto'],
            'Moneda'           => (string) $c['moneda'],
            'Tasa de interes'  => $tasa > 0 ? number_format($tasa / 100, 2) . '% anual' : 'Sin interes',
            'Saldo minimo'     => Monto::deCentavos((int) $c['saldo_minimo_cent'])->formateado(),
            'Apertura'         => date('d/m/Y', strtotime((string) $c['apertura_en'])),
        ];

        if ($esDigital) {
            $datos['Tope de saldo'] = Monto::deCentavos(
                Parametros::entero('cuenta_digital.saldo_maximo_cent', 300000)
            )->formateado();

            $datos['Tope diario'] = Monto::deCentavos(
                Parametros::entero('cuenta_digital.movimiento_diario_cent', 100000)
            )->formateado();

            $datos['Como se abrio'] = 'En linea, sin presencia fisica';
        }

        $pdf->bloqueDatos($datos);

        /* Lo que el producto promete, tal como se anuncia.
           Si la pantalla dice una cosa y el contrato otra, vale el
           contrato, así que tienen que decir lo mismo y salir del
           mismo sitio. */
        $beneficios = array_values(array_filter(
            array_map('trim', explode('|', (string) ($c['beneficios'] ?? '')))
        ));

        if ($beneficios !== []) {
            $pdf->tituloSeccion('Lo que incluye');

            foreach ($beneficios as $b) {
                $pdf->parrafo('- ' . ($b));
            }
        }

        $this->clausuladoCuenta($pdf, $c, $esDigital);
        $this->espacioFirma($pdf, (string) $c['cliente'], (string) ($c['documento_identidad'] ?? ''));

        $ruta = $pdf->guardar('contrato-cuenta-' . $c['numero_cuenta'] . '-' . date('YmdHis'));

        return [
            'ruta'   => $ruta,
            'hash'   => hash_file('sha256', $ruta),
            'nombre' => basename($ruta),
        ];
    }

    /**
     * Las condiciones de una cuenta de depósito.
     *
     * @param array<string,mixed> $c
     */
    private function clausuladoCuenta(GeneradorPdf $pdf, array $c, bool $esDigital): void
    {
        $pdf->tituloSeccion('Condiciones');

        /* Los ordinales se cuentan solos.
         *
         * Estaban escritos a mano —'TERCERA:', 'CUARTA:'…— y eso hacía
         * que meter una cláusula en medio obligara a renumerar todas
         * las de abajo. Es exactamente donde se cuelan los errores que
         * nadie ve hasta que un cliente señala que su contrato tiene
         * dos cláusulas sextas, o que salta de la octava a la décima.
         *
         * Ahora se pide el título y el ordinal lo pone el contador. */
        $n = 0;

        $ordinales = [
            'PRIMERA', 'SEGUNDA', 'TERCERA', 'CUARTA', 'QUINTA', 'SEXTA',
            'SEPTIMA', 'OCTAVA', 'NOVENA', 'DECIMA', 'UNDECIMA', 'DUODECIMA',
            'DECIMOTERCERA', 'DECIMOCUARTA', 'DECIMOQUINTA',
        ];

        $clausula = static function (string $titulo, string $texto) use ($pdf, &$n, $ordinales): void {
            $pdf->clausula(
                ($ordinales[$n] ?? ('CLAUSULA ' . ($n + 1))) . ': ' . $titulo,
                $texto
            );

            $n++;
        };

        $clausula(
            'objeto',
            (
                'CashMan H.A., S.A. de C.V. abre al titular la cuenta de deposito '
                . $c['numero_cuenta'] . ', de la modalidad ' . $c['producto'] . ', en '
                . $c['moneda'] . '. Los fondos depositados son propiedad del titular y '
                . 'estan disponibles para el en los terminos de este contrato.'
            )
        );

        $clausula(
            'disponibilidad',
            (
                'El titular puede disponer de su saldo por los canales que la institucion '
                . 'habilite: ventanilla, banca en linea, tarjeta de debito y transferencias. '
                . 'La institucion no retendra fondos salvo orden de autoridad competente o '
                . 'sospecha fundada de fraude, y en tal caso lo comunicara al titular.'
            )
        );

        if ($esDigital) {
            $clausula(
                'limites de la Cuenta Digital',
                (
                    'Por abrirse sin presencia fisica, esta cuenta tiene un tope de saldo de '
                    . Monto::deCentavos(Parametros::entero('cuenta_digital.saldo_maximo_cent', 300000))->formateado()
                    . ' y un tope de movimiento diario de '
                    . Monto::deCentavos(Parametros::entero('cuenta_digital.movimiento_diario_cent', 100000))->formateado()
                    . '. Si el titular necesita mas, puede completar su expediente en '
                    . 'cualquier sucursal y convertirla en una cuenta de ahorro sin esos '
                    . 'topes, conservando el mismo numero de cuenta.'
                )
            );

            $clausula(
                'tarjeta de debito virtual',
                (
                    'Esta cuenta incluye una tarjeta de debito virtual, emitida al momento '
                    . 'de la apertura y sin costo. Sirve para compras por internet desde el '
                    . 'primer dia. No lleva plastico: su numero, vencimiento y codigo de '
                    . 'seguridad se consultan en la banca en linea. El titular puede '
                    . 'congelarla o cancelarla desde ahi en cualquier momento.'
                )
            );
        } else {
            $clausula(
                'saldo minimo',
                (
                    'El titular mantendra un saldo minimo de '
                    . Monto::deCentavos((int) $c['saldo_minimo_cent'])->formateado()
                    . '. Ese monto no se considera disponible para retiro.'
                )
            );

            $clausula(
                'tarjeta de debito',
                (
                    'La cuenta admite una tarjeta de debito activa. Es de uso personal e '
                    . 'intransferible y el titular responde de las operaciones hechas con '
                    . 'ella mientras no haya reportado su perdida, robo o uso indebido.'
                )
            );
        }

        $clausula(
            'intereses',
            (
                (int) $c['tasa_interes_bp'] > 0
                    ? 'La cuenta devenga una tasa de ' . number_format((int) $c['tasa_interes_bp'] / 100, 2)
                      . '% anual sobre el saldo promedio diario, abonada mensualmente. De los '
                      . 'intereses se retiene lo que manda la ley cuando corresponde, y la '
                      . 'retencion aparece detallada en el estado de cuenta.'
                    : 'Esta cuenta no devenga intereses.'
            )
        );

        $clausula(
            'comisiones',
            (
                'Las comisiones vigentes estan publicadas en el tarifario de la institucion '
                . 'y en la banca en linea. Cualquier cambio se comunica al titular con al '
                . 'menos treinta dias de anticipacion, y durante ese plazo puede cerrar la '
                . 'cuenta sin cargo si no esta de acuerdo.'
            )
        );

        /* La compensación, dicha en el contrato y no solo en el código.
         *
         * El sistema ya compensaba —la columna `compensacion_autorizada`
         * nace en 1— pero el contrato no lo mencionaba en ninguna
         * parte. Tomar dinero de la cuenta de alguien para pagar su
         * propia mora es legítimo y le conviene a él, porque el recargo
         * deja de correr; lo que no es legítimo es hacerlo sin que esté
         * escrito en lo que firmó.
         *
         * Se dicen los límites con números, no en abstracto: qué parte
         * de lo que entra se puede tomar, qué cantidad no se toca nunca
         * y en qué orden se imputa lo que se abona. */
        $clausula(
            'compensacion con cuotas vencidas',
            (
                'El titular autoriza a la institucion a aplicar el saldo de esta cuenta '
                . 'al pago de las cuotas vencidas de sus creditos. Esta autorizacion '
                . 'alcanza a todas las modalidades de cuenta, incluida la Cuenta Digital. '
                . 'La compensacion se aplica sobre hasta el '
                . number_format(((int) ($c['compensacion_bp'] ?? 10000)) / 100, 2)
                . '% de lo que exceda del saldo intocable, que es de '
                . Monto::deCentavos(max(
                    (int) ($c['saldo_minimo_cent'] ?? 0),
                    (int) ($c['compensacion_minimo_cent'] ?? 0)
                ))->formateado()
                . ' y nunca se toma. Se aplica primero a la cuota mas antigua, que es la '
                . 'que mas recargo acumula.'
            )
        );

        $clausula(
            'pagos parciales de cuotas vencidas',
            (
                'Cuando lo disponible no alcance para cubrir una cuota completa, se abona '
                . 'igualmente de forma PARCIAL y no se deja el dinero quieto. En ese caso '
                . 'el abono se aplica INTEGRAMENTE a la cuota y no se cobra recargo por '
                . 'mora: el pago baja la deuda desde el primer centavo. Esto favorece al '
                . 'titular por partida doble, porque el recargo se calcula sobre el saldo '
                . 'pendiente de cada cuota, de modo que al bajar ese pendiente baja tambien '
                . 'lo que se devenga cada dia. El recargo acumulado no se condona: se cobra '
                . 'cuando el abono alcance para liquidar la cuota completa. La cuota queda '
                . 'registrada como parcialmente pagada, con su saldo a la vista en la banca '
                . 'en linea, y de cada aplicacion se avisa al titular.'
            )
        );

        $clausula(
            'estados de cuenta',
            (
                'La institucion pone a disposicion del titular un estado de cuenta mensual '
                . 'en la banca en linea. El titular dispone de treinta dias desde su emision '
                . 'para objetar cualquier movimiento; pasado ese plazo se tienen por '
                . 'aceptados, salvo error evidente.'
            )
        );

        /* El cierre por inactividad, dicho antes de que ocurra.
         *
         * Cerrar la cuenta de alguien sin que esté pactado es lo que
         * convierte una limpieza de cartera en un reclamo. Y lo del
         * número que no vuelve conviene decirlo con todas las letras:
         * el titular necesita saber que si entrega ese número a su
         * patrono o a un proveedor y deja morir la cuenta, ese número
         * no va a volver a ser suyo nunca. */
        $clausula(
            'cierre por inactividad',
            (
                'Si la cuenta permanece en cero y sin movimientos durante '
                . Parametros::entero('cuenta.inactividad_meses', 6)
                . ' meses, la institucion puede cerrarla. Antes de hacerlo avisa al '
                . 'titular por correo electronico y por la banca en linea, y le deja '
                . Parametros::entero('cuenta.inactividad_aviso_dias', 30)
                . ' dias para conservarla: basta un movimiento, aunque sea pequeno. '
                . 'Una cuenta con saldo no se cierra por esta causa, ni tampoco la de '
                . 'un titular con creditos vigentes. Cerrada la cuenta, su numero se '
                . 'retira y NO vuelve a asignarse a ninguna otra, para que los '
                . 'depositos dirigidos a ese numero no lleguen nunca a un tercero.'
            )
        );

        $clausula(
            'cierre',
            (
                'Cualquiera de las partes puede dar por terminado este contrato. El titular, '
                . 'en cualquier momento y sin expresar causa, retirando su saldo. La '
                . 'institucion, avisando con treinta dias de anticipacion, salvo que medie '
                . 'uso fraudulento o incumplimiento, en cuyo caso puede cerrarla de inmediato '
                . 'poniendo el saldo a disposicion del titular.'
            )
        );

        $clausula(
            'proteccion de datos',
            (
                'Los datos del titular se tratan para administrar esta cuenta y cumplir las '
                . 'obligaciones legales de la institucion. No se ceden a terceros con fines '
                . 'comerciales. El titular puede consultarlos, corregirlos y pedir su '
                . 'supresion cuando la ley lo permita.'
            )
        );

        $clausula(
            'aceptacion',
            (
                $esDigital
                    ? 'Al abrir esta cuenta desde la banca en linea el titular declara que '
                      . 'leyo y acepta estas condiciones. Este documento se le remite por '
                      . 'correo electronico y queda disponible en su seccion de documentos. '
                      . 'La aceptacion electronica tiene el mismo valor que la firma autografa.'
                    : 'El titular declara que leyo y acepta estas condiciones, y recibe copia '
                      . 'de este documento.'
            )
        );
    }

    public function contratoCredito(int $creditoId): array
    {
        $c = BaseDatos::unaFila(
            'SELECT c.*, p.nombre AS producto, p.tipo AS producto_tipo,
                    p.codigo AS producto_codigo,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente,
                    u.usuario, u.correo, u.documento_identidad, u.telefono,
                    d.direccion, d.cargo, d.empresa, d.ingreso_mensual_cent
               FROM creditos c
               JOIN productos p ON p.id = c.producto_id
               JOIN usuarios  u ON u.id = c.usuario_id
               LEFT JOIN detalle_usuarios d ON d.usuario_id = u.id
              WHERE c.id = ?',
            [$creditoId]
        );

        if ($c === null) {
            throw new \RuntimeException('Crédito no encontrado.');
        }

        /* Lo que se financio, con sus datos.
         *
         * Puede no estar: los creditos anteriores a que se
         * capturara esto no lo tienen, y el contrato se emite
         * igual, sin la clausula del bien, en vez de reventar. */
        $c['objeto'] = BaseDatos::unaFila(
            'SELECT * FROM credito_objeto WHERE credito_id = ?',
            [$creditoId]
        ) ?? [];

        /* El plan de pagos va COMPLETO.
         *
         * Aquí había un `LIMIT 12` y el contrato salía con las primeras
         * doce cuotas y una nota que remitía a «la tabla de amortización
         * que acompaña a este contrato». Esa tabla no acompañaba nada:
         * el cliente firmaba un plan de 24 cuotas viendo doce. Un
         * contrato tiene que contener lo que se firma, y la tabla ya
         * salta de página sola repitiendo la cabecera. */
        $cuotas = BaseDatos::todas(
            'SELECT numero, fecha_vencimiento, capital_cent, interes_cent, total_cent,
                    saldo_posterior_cent, seguro_vida_cent, seguro_empleo_cent
               FROM cuotas WHERE credito_id = ? ORDER BY numero',
            [$creditoId]
        );

        $garantias = BaseDatos::todas(
            'SELECT tipo, descripcion, valor_cent FROM garantias WHERE credito_id = ?',
            [$creditoId]
        );

        $pdf = new GeneradorPdf();
        $pdf->configurar(
            'Contrato de crédito',
            (string) $c['producto'],
            (string) $c['folio']
        );
        $pdf->AddPage();

        $pdf->tituloSeccion('Partes del contrato');
        $pdf->bloqueDatos([
            'Acreedor'  => 'CashMan H.A. S.A. de C.V.',
            'Deudor'    => (string) $c['cliente'],
            'Documento' => (string) ($c['documento_identidad'] ?? '-'),
            'Usuario'   => (string) $c['usuario'],
            'Telefono'  => (string) ($c['telefono'] ?? '-'),
            'Correo'    => (string) $c['correo'],
            'Cargo'     => (string) ($c['cargo'] ?? '-'),
            'Empresa'   => (string) ($c['empresa'] ?? '-'),
            'Ingreso mensual' => $this->dinero((int) ($c['ingreso_mensual_cent'] ?? 0)),
        ]);

        // La direccion va a lo ancho: es el dato mas largo de la ficha y
        // en media columna no cabe. Ademas es el lugar donde se notifica
        // al deudor segun la clausula DECIMA TERCERA, asi que tiene que
        // constar completa.
        $pdf->datoAncho('Direccion', (string) ($c['direccion'] ?? '-'));

        $pdf->tituloSeccion('Condiciones del credito');
        $pdf->bloqueDatos([
            'Folio'            => (string) $c['folio'],
            'Producto'         => (string) $c['producto'],
            'Monto otorgado'   => $this->dinero((int) $c['monto_cent']),
            'Tasa anual'       => number_format((int) $c['tasa_bp'] / 100, 2) . ' %',
            'Plazo'            => (int) $c['plazo_meses'] . ' meses',
            'Cuota mensual'    => $this->dinero((int) $c['cuota_cent']),
            'Destino'          => trim((string) ($c['destino'] ?? '')) !== ''
                                  ? (string) $c['destino'] : 'No declarado',
            'Aprobado el'      => $this->fecha((string) ($c['aprobado_en'] ?? '')),
        ]);

        // El total a pagar es lo que el deudor necesita ver antes de
        // firmar: la cuota sola no dice cuánto cuesta el crédito.
        $totalPagar = (int) $c['cuota_cent'] * (int) $c['plazo_meses'];

        $pdf->totalDestacado(
            'Total a pagar en ' . (int) $c['plazo_meses'] . ' cuotas',
            $this->dinero($totalPagar)
        );
        $pdf->totalDestacado(
            'Costo del financiamiento',
            $this->dinero($totalPagar - (int) $c['monto_cent'])
        );

        if ($garantias !== []) {
            $pdf->tituloSeccion('Garantias');

            $filasGarantia = [];
            foreach ($garantias as $g) {
                $filasGarantia[] = [
                    (string) $g['tipo'],
                    (string) $g['descripcion'],
                    $this->dinero((int) $g['valor_cent']),
                ];
            }

            $pdf->tabla(
                ['Tipo', 'Descripcion', 'Valor'],
                $filasGarantia,
                [35, 110, 35],
                ['L', 'L', 'R']
            );
        }

        if ($cuotas !== []) {
            $pdf->tituloSeccion('Plan de pagos (' . count($cuotas) . ' cuotas)');

            /* Los seguros obligatorios se enseñan en su propia columna.
             *
             * Van dentro de la cuota, y antes no aparecían por ninguna
             * parte: capital más interes no cuadraba con la cuota y el
             * cliente no sabía de dónde salía la diferencia. Lo que se
             * cobra se dice. */
            $conSeguro = false;
            foreach ($cuotas as $q) {
                if ((int) $q['seguro_vida_cent'] > 0 || (int) $q['seguro_empleo_cent'] > 0) {
                    $conSeguro = true;
                    break;
                }
            }

            $filas = [];
            foreach ($cuotas as $q) {
                $fila = [
                    (string) (int) $q['numero'],
                    $this->fecha((string) $q['fecha_vencimiento']),
                    $this->dinero((int) $q['capital_cent']),
                    $this->dinero((int) $q['interes_cent']),
                ];

                if ($conSeguro) {
                    $fila[] = $this->dinero(
                        (int) $q['seguro_vida_cent'] + (int) $q['seguro_empleo_cent']
                    );
                }

                $fila[] = $this->dinero((int) $q['total_cent']);
                $fila[] = $this->dinero((int) $q['saldo_posterior_cent']);

                $filas[] = $fila;
            }

            if ($conSeguro) {
                $pdf->tabla(
                    ['No.', 'Vencimiento', 'Capital', 'Interes', 'Seguros', 'Cuota', 'Saldo'],
                    $filas,
                    [13, 30, 27, 25, 25, 28, 34],
                    ['C', 'L', 'R', 'R', 'R', 'R', 'R']
                );
            } else {
                $pdf->tabla(
                    ['No.', 'Vencimiento', 'Capital', 'Interes', 'Cuota', 'Saldo'],
                    $filas,
                    [15, 37, 32, 30, 33, 35],
                    ['C', 'L', 'R', 'R', 'R', 'R']
                );
            }
        }

        /* El clausulado: lo que de verdad se firma.
         *
         * Antes el contrato terminaba con un párrafo de cuatro líneas
         * bajo el título «Aceptación». Eso no es un contrato: es un
         * resumen. Un mutuo necesita decir por escrito qué pasa si se
         * paga tarde, qué faculta al acreedor a exigir todo el saldo de
         * golpe, qué cubren los seguros que se le descuentan al deudor y
         * ante quién se litiga. El sistema anterior sí traía ese texto
         * extenso, y es el que el cliente está acostumbrado a firmar. */
        $this->clausuladoCredito($pdf, $c, $cuotas, $totalPagar, $garantias);

        $this->firmasDelContrato(
            $pdf,
            (string) $c['cliente'],
            (string) ($c['documento_identidad'] ?? '')
        );

        $ruta = $pdf->guardar('contrato-' . $c['folio'] . '-' . date('YmdHis'));

        return [
            'ruta'   => $ruta,
            'hash'   => hash_file('sha256', $ruta),
            'nombre' => basename($ruta),
        ];
    }

    /**
     * Finiquito de cancelación.
     *
     * Es el `finiquito-cancelacion-creditos-clientes` del sistema
     * anterior: el documento que acredita que un crédito quedó saldado.
     * Solo tiene sentido si de verdad no queda nada pendiente, así que
     * eso se comprueba antes de emitirlo en lugar de confiar en el
     * estado de la fila.
     *
     * @return array{ruta:string, hash:string, nombre:string}
     */
    public function finiquitoCredito(int $creditoId): array
    {
        $c = BaseDatos::unaFila(
            'SELECT c.*, p.nombre AS producto,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente,
                    u.documento_identidad
               FROM creditos c
               JOIN productos p ON p.id = c.producto_id
               JOIN usuarios  u ON u.id = c.usuario_id
              WHERE c.id = ?',
            [$creditoId]
        );

        if ($c === null) {
            throw new \RuntimeException('Crédito no encontrado.');
        }

        /* Lo que se financio, con sus datos.
         *
         * Puede no estar: los creditos anteriores a que se
         * capturara esto no lo tienen, y el contrato se emite
         * igual, sin la clausula del bien, en vez de reventar. */
        $c['objeto'] = BaseDatos::unaFila(
            'SELECT * FROM credito_objeto WHERE credito_id = ?',
            [$creditoId]
        ) ?? [];

        /* Lo que impide un finiquito es saldo vivo, no el nombre del
           estado. Una cuota condonada esta extinguida: el arreglo de
           pago la liquido y el cliente ya no la debe. Contarla como
           pendiente negaba el finiquito justo en el caso en que mas se
           necesita —credito cancelado por arreglo— y lo negaba diciendo
           que quedaban 55 cuotas por $0.00, que es la prueba misma de
           que no quedaba nada. */
        $pendiente = BaseDatos::unaFila(
            'SELECT COUNT(*) AS cuotas,
                    COALESCE(SUM(total_cent - pagado_cent),0) AS importe
               FROM cuotas
              WHERE credito_id = ?
                AND estado NOT IN ("pagada","condonada")
                AND (total_cent - pagado_cent) > 0',
            [$creditoId]
        ) ?? ['cuotas' => 0, 'importe' => 0];

        if ((int) $pendiente['cuotas'] > 0 || (int) $c['saldo_capital_cent'] > 0) {
            throw new \RuntimeException(
                'El credito todavia tiene ' . (int) $pendiente['cuotas']
                . ' cuota(s) pendiente(s) por ' . $this->dinero((int) $pendiente['importe'])
                . ' y un saldo de capital de ' . $this->dinero((int) $c['saldo_capital_cent'])
                . '. No se puede emitir el finiquito.'
            );
        }

        $pagos = BaseDatos::unaFila(
            'SELECT COUNT(*) AS cuotas,
                    COALESCE(SUM(capital_cent),0) AS capital,
                    COALESCE(SUM(interes_cent),0) AS interes,
                    COALESCE(SUM(mora_cent),0)    AS mora,
                    MIN(pagada_en) AS primer_pago,
                    MAX(pagada_en) AS ultimo_pago
               FROM cuotas WHERE credito_id = ? AND estado = "pagada"',
            [$creditoId]
        ) ?? [];

        /* Lo condonado no lo pago el cliente. Sumarlo a «total pagado»
           seria afirmar, en un documento que la empresa firma, que
           entrego un dinero que nunca entrego: va aparte y con su
           nombre. Y el finiquito posterior a un arreglo tiene que decir
           que fue un arreglo lo que extinguio la deuda, no el pago de
           las noventa cuotas. */
        $condonado = BaseDatos::unaFila(
            'SELECT COUNT(*) AS cuotas, COALESCE(SUM(total_cent),0) AS importe,
                    MIN(numero) AS desde, MAX(numero) AS hasta
               FROM cuotas WHERE credito_id = ? AND estado = "condonada"',
            [$creditoId]
        ) ?? ['cuotas' => 0, 'importe' => 0, 'desde' => 0, 'hasta' => 0];

        $arreglo = BaseDatos::unaFila(
            'SELECT folio, tipo, monto_requerido_cent, condonado_cent,
                    cuotas_cubiertas, pagado_en
               FROM arreglos_pago
              WHERE credito_id = ? AND estado = "pagado"
              ORDER BY pagado_en DESC LIMIT 1',
            [$creditoId]
        );

        $pdf = new GeneradorPdf();
        $pdf->configurar('Finiquito de cancelacion', (string) $c['producto'], (string) $c['folio']);
        $pdf->AddPage();

        $cuotasCondonadas = (int) ($condonado['cuotas'] ?? 0);

        /* Como se extinguio la deuda. Un finiquito que dice «pago todas
           las cuotas» cuando medio un arreglo con condonacion es un
           documento falso, y es precisamente el documento con el que el
           cliente va a defender que no debe nada. */
        $comoTermino = 'ha cancelado integramente el credito identificado'
            . ' con el folio ' . $c['folio'];

        if ($arreglo !== null) {
            $comoTermino = 'ha extinguido integramente el credito identificado'
                . ' con el folio ' . $c['folio']
                . ', mediante el arreglo de pago ' . $arreglo['folio']
                . ' de fecha ' . $this->fecha((string) $arreglo['pagado_en'])
                . ', en virtud del cual entero la suma de '
                . $this->dinero((int) $arreglo['monto_requerido_cent'])
                . ' y esta institucion condono la suma de '
                . $this->dinero((int) $arreglo['condonado_cent']);
        }

        $pdf->tituloSeccion('Constancia');
        $pdf->aviso(
            'CashMan H.A. S.A. de C.V. hace constar que ' . $c['cliente']
            . ', portador del documento ' . ($c['documento_identidad'] ?? 's/n')
            . ', ' . $comoTermino . ', quedando libre de toda obligacion'
            . ' derivada del mismo. En consecuencia esta institucion no'
            . ' conserva accion alguna de cobro por este credito, ni por'
            . ' capital, intereses, intereses moratorios, seguros ni gastos,'
            . ' y da por concluida toda gestion de cobranza asociada al mismo.'
        );

        $resumen = [
            'Folio'          => (string) $c['folio'],
            'Producto'       => (string) $c['producto'],
            'Monto otorgado' => $this->dinero((int) $c['monto_cent']),
            'Cuotas pagadas' => (int) ($pagos['cuotas'] ?? 0)
                                . ' de ' . (int) $c['plazo_meses'],
        ];

        if ($cuotasCondonadas > 0) {
            $resumen['Cuotas extinguidas'] = $cuotasCondonadas
                . ' (de la ' . (int) $condonado['desde']
                . ' a la ' . (int) $condonado['hasta'] . ')';
        }

        $resumen['Primer pago']      = $this->fecha((string) ($pagos['primer_pago'] ?? ''));
        $resumen['Ultimo pago']      = $this->fecha((string) ($pagos['ultimo_pago'] ?? ''));
        $resumen['Saldo de capital'] = $this->dinero((int) $c['saldo_capital_cent']);
        $resumen['Clasificacion']    = (string) ($c['clasificacion'] ?? '-');

        $pdf->tituloSeccion('Resumen del credito');
        $pdf->bloqueDatos($resumen);

        $recibido = (int) ($pagos['capital'] ?? 0)
            + (int) ($pagos['interes'] ?? 0)
            + (int) ($pagos['mora'] ?? 0);

        $pdf->tituloSeccion('Totales recibidos del cliente');
        $pdf->tabla(
            ['Concepto', 'Importe'],
            [
                ['Capital',              $this->dinero((int) ($pagos['capital'] ?? 0))],
                ['Intereses',            $this->dinero((int) ($pagos['interes'] ?? 0))],
                ['Intereses moratorios', $this->dinero((int) ($pagos['mora'] ?? 0))],
            ],
            [120, 60],
            ['L', 'R']
        );
        $pdf->totalDestacado('Total recibido', $this->dinero($recibido));

        if ($cuotasCondonadas > 0) {
            $tramo = 'Cuotas ' . (int) $condonado['desde']
                   . ' a ' . (int) $condonado['hasta'];

            /* Un solo importe condonado, el del arreglo.
               La constancia decia «$5,043.75» y esta tabla «$8,528.95»:
               dos cifras para lo mismo en un documento que libera de una
               deuda. La buena es la del arreglo —lo que el acreedor
               renuncio sobre el saldo exigible el dia que se firmo—. El
               nominal de las cuotas es mayor porque arrastra intereses
               que, al liquidarse el credito, nunca se devengaron: no se
               puede condonar lo que nadie llego a deber. Se dice, pero
               como lo que es, no como importe condonado. */
            $condonadoReal = $arreglo !== null
                ? (int) $arreglo['condonado_cent']
                : (int) $condonado['importe'];

            $pdf->tituloSeccion('Importe condonado');
            $pdf->tabla(
                ['Concepto', 'Importe'],
                [[
                    'Saldo condonado' . ($arreglo !== null
                        ? ' conforme al arreglo ' . $arreglo['folio'] : ''),
                    $this->dinero($condonadoReal),
                ]],
                [120, 60],
                ['L', 'R']
            );

            $nominal = (int) $condonado['importe'];

            $pdf->aviso(
                'Quedan extinguidas las ' . $cuotasCondonadas . ' cuotas '
                . strtolower($tramo) . ' del plan de pagos, que no son'
                . ' exigibles al deudor por ningun concepto.'
                . ($arreglo !== null && $nominal > $condonadoReal
                    ? ' Su valor nominal en el plan ascendia a '
                      . $this->dinero($nominal) . ', importe que comprende'
                      . ' intereses pendientes de devengar; al liquidarse'
                      . ' anticipadamente el credito esos intereses no se'
                      . ' generaron, por lo que la condonacion efectiva'
                      . ' sobre el saldo exigible a la fecha del arreglo'
                      . ' es la consignada arriba.'
                    : '')
                . ' La condonacion es expresa, definitiva e irrevocable, y'
                . ' no constituye pago del cliente sino renuncia del'
                . ' acreedor: se consigna por separado para que este'
                . ' documento no afirme un pago que no existio.'
            );
        }

        $this->espacioFirma($pdf, 'CashMan H.A. S.A. de C.V.', 'Representante autorizado');

        /* Cada emision, su propio archivo. El nombre llevaba solo
           el folio, asi que la siguiente emision pisaba la anterior
           y las filas ya guardadas pasaban a «no coincide»: la
           integridad compara el SHA-256 archivado contra el fichero
           de hoy. Con un contrato firmado seria peor todavia, porque
           la firma quedaria sosteniendo un documento distinto. */
        $ruta = $pdf->guardar('finiquito-' . $c['folio'] . '-' . date('YmdHis'));

        return [
            'ruta'   => $ruta,
            'hash'   => hash_file('sha256', $ruta),
            'nombre' => basename($ruta),
        ];
    }

    // -----------------------------------------------------------------

    /**
     * El clausulado del contrato de crédito.
     *
     * Es el texto extenso que el cliente firma. Está redactado sobre el
     * contrato de mutuo que se usa en El Salvador y recoge las reglas
     * que el sistema aplica de verdad: el recargo por mora sale del
     * parámetro `mora.cargo_diario_cent`, los seguros de las cuotas que
     * se acaban de imprimir, y la caducidad del plazo de la cantidad de
     * cuotas que el propio sistema tolera antes de pasar a cobro.
     *
     * Dicho de otro modo: no es texto de relleno. Si una cláusula dice
     * $5.99 por día es porque eso es lo que cobra `Mora`, y si mañana
     * cambia el parámetro, cambia el contrato que se emita.
     *
     * @param array<string,mixed>              $c
     * @param list<array<string,mixed>>        $cuotas
     * @param list<array<string,mixed>>        $garantias
     */
    private function clausuladoCredito(
        GeneradorPdf $pdf,
        array $c,
        array $cuotas,
        int $totalPagar,
        array $garantias
    ): void {
        /* La modalidad manda: el orden de las cláusulas, el destino
           que se puede declarar y qué hay que describir del bien. */
        $modalidad = Clausulado::modalidadDe((string) ($c['producto_codigo'] ?? ''));

        /* El destino, resuelto contra la modalidad.
         *
         * Aquí se cortó de raíz el contrato que decía «modalidad de
         * crédito de vehículo» en la PRIMERA y «estudios
         * universitarios» en la SEGUNDA. Entre dos cláusulas que se
         * contradicen manda el producto que el cliente contrató. */
        $destino = DestinoDeCredito::resolver(
            (string) ($c['destino'] ?? ''),
            $modalidad
        );

        /* Y los datos del bien, si se capturaron. */
        $objeto = $c['objeto'] ?? [];

        /** El numeral de una cláusula de ESTE contrato. */
        $num = static fn(string $clave): string => Clausulado::numeral($clave, $modalidad);

        /** Y su encabezado completo. */
        $tit = static fn(string $clave): string => Clausulado::titulo($clave, $modalidad);

        $moraDia   = Parametros::entero('mora.cargo_diario_cent', 599);
        $gracia    = Parametros::entero('mora.dias_gracia', 0);
        $vidaMil   = Parametros::entero('seguro.vida_por_mil_cent', 90);
        $empleoMil = Parametros::entero('seguro.empleo_por_mil_cent', 110);

        $monto  = (int) $c['monto_cent'];
        $tasa   = number_format((int) $c['tasa_bp'] / 100, 2);
        $plazo  = (int) $c['plazo_meses'];
        $cuota  = (int) $c['cuota_cent'];

        $numeroCuotas = count($cuotas);

        /* Las fechas concretas del plan.
         *
         * Un contrato que dice «pagadera en las fechas que constan en el
         * plan» obliga a quien lo lee a ir a buscarlas. Decir cuando
         * vence la primera, cuando la ultima y que dia de cada mes toca
         * es lo que convierte la clausula en algo que se puede cumplir
         * sin consultar otra hoja. */
        $primerVencimiento = (string) ($cuotas[0]['fecha_vencimiento'] ?? '');
        $ultimoVencimiento = (string) ($cuotas[$numeroCuotas - 1]['fecha_vencimiento'] ?? '');

        $diaPago = $primerVencimiento !== ''
            ? (int) (new \DateTimeImmutable($primerVencimiento))->format('j')
            : 1;

        $pdf->AddPage();
        $pdf->tituloSeccion('Clausulas del contrato');

        $pdf->parrafo(
            'En la ciudad de San Salvador, departamento de San Salvador, a los '
            . $this->enLetraFecha((string) ($c['aprobado_en'] ?? date('Y-m-d')))
            . ', comparecen: por una parte CASHMAN H.A., S.A. DE C.V., sociedad '
            . 'anonima de capital variable, del domicilio de San Salvador, que en '
            . 'adelante se denominara "EL ACREEDOR"; y por otra parte '
            . mb_strtoupper((string) $c['cliente'])
            . ', mayor de edad, portador de su documento de identidad numero '
            . ((string) ($c['documento_identidad'] ?? '') !== ''
                ? (string) $c['documento_identidad'] : 'que consta en el expediente')
            . ', con domicilio en ' . ((string) ($c['direccion'] ?? '') !== ''
                ? (string) $c['direccion'] : 'el que consta en el expediente')
            . ', que en adelante se denominara "EL DEUDOR"; y en el caracter en que '
            . 'comparecen MANIFIESTAN que han convenido en celebrar el presente '
            . 'contrato de credito, que se regira por las clausulas siguientes:'
        );

        $pdf->clausula(
            $tit('objeto'),
            'EL ACREEDOR otorga a EL DEUDOR un credito por la cantidad de '
            . $this->dinero($monto) . ' (' . $this->enLetraMonto($monto) . '), '
            . 'bajo la modalidad de ' . mb_strtolower((string) $c['producto']) . ', '
            . 'cantidad que EL DEUDOR declara haber recibido a su entera satisfaccion '
            . 'mediante abono a su cuenta en esta institucion, sirviendo el presente '
            . 'documento como el mas eficaz recibo y finiquito de dicha entrega.'
        );

        $pdf->clausula(
            $tit('destino'),
            'EL DEUDOR se obliga a invertir el credito recibido unica y exclusivamente '
            // El destino ya viene resuelto contra la modalidad: no
            // puede contradecir a la clausula PRIMERA, que es lo que
            // hacia antes de pasar por `DestinoDeCredito`.
            . 'en ' . mb_strtolower($destino)
            . '. EL ACREEDOR queda facultado para verificar en cualquier momento el '
            . 'destino de los fondos; el empleo del credito en un fin distinto al '
            . 'pactado faculta a EL ACREEDOR para dar por caducado el plazo en los '
            . 'terminos de la clausula ' . $num('caducidad') . '.'
        );

        /* Lo que se financia, con nombre y apellido.
         *
         * Un contrato de vehículo que no dice qué vehículo no sirve
         * para ejecutar la garantía: «un automóvil usado» no se puede
         * embargar, un VIN sí. Lo mismo con la matrícula del inmueble,
         * que es lo que lo identifica ante el Registro, y con la
         * institución y la carrera, que es lo que el banco puede
         * verificar cuando la cláusula del destino le faculta a
         * hacerlo. */
        $detalleBien = $this->detalleDelObjeto($modalidad, $objeto);

        if ($detalleBien !== '') {
            $pdf->clausula($tit('bien'), $detalleBien);
        }

        $pdf->clausula(
            $tit('plazo'),
            'El plazo del presente credito es de ' . $plazo . ' ('
            . $this->enLetraEntero($plazo) . ') meses, contados a partir de la fecha '
            . 'de desembolso. EL DEUDOR pagara el credito mediante '
            . $numeroCuotas . ' (' . $this->enLetraEntero($numeroCuotas) . ') cuotas '
            . 'mensuales, iguales y sucesivas de ' . $this->dinero($cuota) . ' ('
            . $this->enLetraMonto($cuota) . ') cada una, que comprenden capital, '
            . 'intereses y los seguros a que se refiere la clausula ' . $num('seguros') . ', pagaderas '
            . 'el dia ' . $this->enLetraEntero($diaPago) . ' de cada mes en las fechas '
            . 'que constan en el plan de pagos incorporado a este contrato, el cual '
            . 'forma parte integrante del mismo. La primera cuota vencera el '
            . $this->fecha($primerVencimiento) . ' y la ultima el '
            . $this->fecha($ultimoVencimiento) . '. El monto total a pagar asciende a '
            . $this->dinero($totalPagar) . ' (' . $this->enLetraMonto($totalPagar)
            . '), de los cuales ' . $this->dinero($monto) . ' corresponden a capital y '
            . $this->dinero(max(0, $totalPagar - $monto)) . ' al costo del '
            . 'financiamiento. El pago se hara en las oficinas de EL ACREEDOR, por los '
            . 'medios electronicos que este habilite o mediante debito automatico a la '
            . 'cuenta que EL DEUDOR senale para tal efecto.'
        );

        $pdf->clausula(
            $tit('intereses'),
            'El credito devengara un interes nominal del ' . $tasa . '% ('
            . $this->enLetraPorcentaje((float) $tasa) . ') anual sobre '
            . 'saldos, calculado sobre el saldo de capital adeudado y liquidado con '
            . 'cada cuota conforme al sistema de amortizacion frances, tal como se '
            . 'detalla en el plan de pagos. La tasa pactada se mantendra fija durante '
            . 'todo el plazo del credito; cualquier modificacion requerira el acuerdo '
            . 'escrito de ambas partes y la emision de un nuevo plan de pagos.'
        );

        $pdf->clausula(
            $tit('seguros'),
            'Las cuotas incluyen la prima del seguro de vida deudores, a razon de '
            . $this->dinero($vidaMil) . ' por cada mil dolares de saldo insoluto, y la '
            . 'del seguro de desempleo involuntario, a razon de '
            . $this->dinero($empleoMil) . ' por cada mil dolares de saldo insoluto. '
            . 'Ambas primas se calculan sobre el saldo pendiente, por lo que '
            . 'disminuyen conforme avanza la amortizacion. El seguro de vida cubre la '
            . 'cancelacion del saldo insoluto en caso de fallecimiento de EL DEUDOR, y '
            . 'el de desempleo cubre las cuotas que se pacten en la poliza respectiva '
            . 'en caso de perdida involuntaria del empleo. EL DEUDOR declara conocer y '
            . 'aceptar las condiciones de ambas coberturas.'
        );

        $pdf->clausula(
            $tit('mora'),
            'La falta de pago de cualquier cuota en la fecha pactada constituira a EL '
            . 'DEUDOR en mora de pleno derecho, sin necesidad de requerimiento alguno. '
            . ($gracia > 0
                ? 'Se conceden ' . $gracia . ' dia(s) de gracia a partir del '
                  . 'vencimiento, transcurridos los cuales '
                : 'A partir del dia siguiente al vencimiento ')
            . 'EL DEUDOR pagara, en concepto de clausula penal, un recargo de '
            . $this->dinero($moraDia) . ' por cada dia de atraso y por cada cuota en '
            . 'mora, recargo que se acumula dia a dia hasta la fecha del pago efectivo '
            . 'y que en ningun caso excedera el saldo de la propia cuota. Dicho '
            . 'recargo es independiente de los intereses corrientes, que se siguen '
            . 'devengando sobre el saldo de capital.'
        );

        $pdf->clausula(
            $tit('caducidad'),
            'EL ACREEDOR podra dar por caducado el plazo y exigir el pago inmediato '
            . 'del saldo total adeudado, con sus intereses y recargos, sin necesidad '
            . 'de requerimiento ni de accion judicial previa, en cualquiera de los '
            . 'casos siguientes: a) si EL DEUDOR dejare de pagar tres cuotas '
            . 'consecutivas; b) si destinare el credito a un fin distinto del '
            . 'pactado; c) si resultare falsa cualquiera de las declaraciones o '
            . 'documentos presentados para la obtencion del credito; d) si EL DEUDOR '
            . 'fuere declarado en estado de insolvencia o quiebra; e) si se '
            . 'deterioraren o desaparecieren las garantias constituidas y EL DEUDOR no '
            . 'las sustituyere dentro de los treinta dias siguientes al requerimiento. '
            . 'En tales casos la obligacion se tendra por de plazo vencido y sera '
            . 'exigible en su totalidad.'
        );

        $pdf->clausula(
            $num('garantias') . ($garantias !== [] ? '. GARANTIAS.' : '. GARANTIA PERSONAL.'),
            $garantias !== []
                ? 'En garantia del cumplimiento de todas y cada una de las '
                  . 'obligaciones derivadas de este contrato, EL DEUDOR constituye a '
                  . 'favor de EL ACREEDOR las garantias que se detallan en el cuadro '
                  . 'de garantias de este documento. EL DEUDOR se obliga a conservar '
                  . 'los bienes dados en garantia en buen estado, a mantenerlos '
                  . 'asegurados cuando asi corresponda y a no enajenarlos ni gravarlos '
                  . 'sin autorizacion escrita de EL ACREEDOR.'
                : 'EL DEUDOR responde del cumplimiento de las obligaciones de este '
                  . 'contrato con todos sus bienes presentes y futuros, sin perjuicio '
                  . 'de las garantias adicionales que se constituyan en documento '
                  . 'separado.'
        );

        $pdf->clausula(
            $tit('anticipados'),
            'EL DEUDOR podra realizar abonos extraordinarios a capital o cancelar '
            . 'anticipadamente el credito en cualquier momento, sin cargo ni penalidad '
            . 'alguna. Los abonos extraordinarios se aplicaran en primer lugar a '
            . 'recargos por mora, luego a intereses devengados y el remanente a '
            . 'capital, reduciendo el saldo insoluto y, en consecuencia, los intereses '
            . 'y primas de seguro de las cuotas siguientes. A solicitud de EL DEUDOR, '
            . 'EL ACREEDOR emitira el plan de pagos actualizado.'
        );

        $pdf->clausula(
            $tit('imputacion'),
            'Todo pago que alcance para cubrir una cuota completa se imputara en el '
            . 'orden siguiente: primero a recargos por mora, despues a primas de '
            . 'seguro, luego a intereses corrientes y por ultimo a capital. '
            . 'Si el pago NO alcanza para una cuota completa, se aplicara integramente '
            . 'a la cuota, sin cobro de recargo, de modo que todo abono reduzca la deuda '
            . 'desde el primer centavo; el recargo acumulado no se condona y se cobrara '
            . 'cuando se liquide la cuota. EL ACREEDOR entregara a EL DEUDOR el '
            . 'comprobante de cada pago, el cual podra consultar y descargar en todo '
            . 'momento desde la banca en linea.'
        );

        $pdf->clausula(
            $tit('cobro'),
            'En caso de mora, EL DEUDOR autoriza a EL ACREEDOR a realizar gestiones de '
            . 'cobro administrativo por los medios de contacto que constan en su '
            . 'expediente, incluidos correo electronico, llamada telefonica y mensaje '
            . 'escrito. Agotada la gestion administrativa, EL ACREEDOR podra remitir el '
            . 'caso a cobro extrajudicial y, de persistir el incumplimiento, a la via '
            . 'judicial, quedando a cargo de EL DEUDOR las costas procesales y los '
            . 'honorarios que legalmente correspondan.'
        );

        $pdf->clausula(
            $tit('datos'),
            'EL DEUDOR autoriza expresamente a EL ACREEDOR para consultar, '
            . 'proporcionar y actualizar su informacion crediticia ante las agencias de '
            . 'informacion de datos legalmente autorizadas, durante la vigencia de este '
            . 'contrato y mientras subsistan obligaciones pendientes.'
        );

        $pdf->clausula(
            $tit('jurisdiccion'),
            'Para los efectos legales de este contrato, las partes senalan como '
            . 'domicilio especial el de la ciudad de San Salvador, a cuyos tribunales '
            . 'se someten expresamente. EL DEUDOR senala como lugar para recibir '
            . 'notificaciones la direccion y el correo electronico que constan en el '
            . 'encabezado de este documento, obligandose a informar por escrito '
            . 'cualquier cambio; mientras no lo haga, se tendran por validas las '
            . 'notificaciones hechas a los datos aqui consignados.'
        );

        /* La compensación tiene que estar en el contrato.
         *
         * El sistema toma saldo de la cuenta del deudor para cubrir sus
         * cuotas vencidas. Hacer eso sin una cláusula que lo autorice no
         * es compensación: es disponer del dinero de alguien sin título.
         *
         * Por eso la cláusula dice también sus límites —solo cuotas
         * vencidas, solo enteras, con un remanente intocable y con aviso
         * posterior—: una autorización sin límites es la que un juez
         * tumba por abusiva. */
        $pdf->clausula(
            $tit('compensacion'),
            'EL DEUDOR autoriza expresa e irrevocablemente a EL ACREEDOR para aplicar, '
            . 'sin necesidad de requerimiento ni autorizacion adicional, los fondos '
            . 'disponibles en cualquiera de las cuentas que mantenga en esta institucion '
            . 'al pago de las cuotas vencidas y no satisfechas del presente credito, '
            . 'junto con sus recargos por mora. Dicha aplicacion se sujetara a las '
            . 'reglas siguientes: a) solo procedera sobre cuotas efectivamente VENCIDAS, '
            . 'nunca sobre cuotas por vencer; b) se aplicara comenzando por la cuota mas '
            . 'antigua y continuando en orden de antiguedad; c) cubiertas las cuotas que '
            . 'el saldo disponible alcance a satisfacer integramente, el remanente se '
            . 'abonara de forma PARCIAL a la cuota siguiente, imputandose en el orden '
            . 'establecido en la clausula ' . $num('imputacion') . ', con lo cual se reduce el saldo '
            . 'pendiente de esa cuota y, en consecuencia, el recargo por mora que sobre '
            . 'ella se devenga; d) se respetara en todo caso un remanente minimo en la '
            . 'cuenta, que no sera inferior al saldo minimo del producto; y e) EL '
            . 'ACREEDOR informara a EL DEUDOR de cada aplicacion efectuada, detallando '
            . 'el importe, las cuotas cubiertas total o parcialmente y el saldo '
            . 'resultante. EL DEUDOR podra revocar esta '
            . 'autorizacion mediante comunicacion escrita, sin que ello afecte las '
            . 'aplicaciones ya realizadas ni la exigibilidad del saldo adeudado.'
        );

        $pdf->clausula(
            $tit('aceptacion'),
            'EL DEUDOR declara que ha leido integramente este contrato y el plan de '
            . 'pagos que forma parte del mismo, que se le han explicado de forma clara '
            . 'el monto total a pagar, la tasa de interes, el recargo por mora y las '
            . 'primas de seguro incluidas en la cuota, y que acepta todas y cada una de '
            . 'las clausulas anteriores. En fe de lo cual firmamos el presente '
            . 'documento en la ciudad y fecha indicadas.'
        );
    }

    /**
     * Un monto en letras, como lo pide un contrato.
     *
     * No es un adorno: en un documento que se firma, la cantidad en
     * letras es la que prevalece si el numero resulta alterado.
     */
    private function enLetraMonto(int $centavos): string
    {
        $entero = intdiv($centavos, 100);
        $cent   = $centavos % 100;

        $letras = mb_strtoupper($this->enLetras($entero));

        return $letras . ' DOLARES'
            . ($cent > 0 ? ' CON ' . str_pad((string) $cent, 2, '0', STR_PAD_LEFT) . '/100' : ' EXACTOS')
            . ' DE LOS ESTADOS UNIDOS DE AMERICA';
    }

    /**
     * Un numero entero en letras, para plazos y cantidades de cuotas.
     *
     * Misma razon que el monto: en un contrato, «180 (ciento ochenta)
     * meses» no deja lugar a que un digito cambiado pase inadvertido.
     */
    private function enLetraEntero(int $n): string
    {
        return mb_strtoupper($this->enLetras($n));
    }

    /**
     * Un porcentaje en letras, con sus decimales.
     *
     * La tasa es el dato que mas se discute despues del monto, asi que
     * va escrito igual que el monto: «6.90% (SEIS PUNTO NOVENTA POR
     * CIENTO)». Los decimales se leen cifra por cifra, como se hace en
     * los contratos, y no como un numero aparte.
     */
    private function enLetraPorcentaje(float $porcentaje): string
    {
        $entero   = (int) floor($porcentaje);
        $decimal  = (int) round(($porcentaje - $entero) * 100);

        $letras = $this->enLetras($entero);

        if ($decimal > 0) {
            $letras .= ' punto ' . $this->enLetras($decimal);
        }

        return mb_strtoupper($letras) . ' POR CIENTO';
    }

    /** Una fecha escrita como la escribe un notario. */
    private function enLetraFecha(string $iso): string
    {
        try {
            $d = new \DateTimeImmutable($iso !== '' ? $iso : 'today');
        } catch (\Throwable) {
            $d = new \DateTimeImmutable('today');
        }

        $meses = [
            1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
        ];

        return $this->enLetras((int) $d->format('j')) . ' dias del mes de '
            . $meses[(int) $d->format('n')] . ' de ' . $this->enLetras((int) $d->format('Y'));
    }

    /** Numeros a palabras, en castellano. */
    private function enLetras(int $n): string
    {
        if ($n === 0) { return 'cero'; }
        if ($n < 0)   { return 'menos ' . $this->enLetras(-$n); }

        $unidades = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete',
                     'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce',
                     'quince', 'dieciseis', 'diecisiete', 'dieciocho', 'diecinueve'];
        $decenas  = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta',
                     'sesenta', 'setenta', 'ochenta', 'noventa'];
        $centenas = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos',
                     'quinientos', 'seiscientos', 'setecientos', 'ochocientos',
                     'novecientos'];

        if ($n < 20) { return $unidades[$n]; }

        if ($n < 100) {
            $d = intdiv($n, 10);
            $u = $n % 10;

            if ($d === 2 && $u > 0) {
                return 'veinti' . $unidades[$u];
            }

            return $decenas[$d] . ($u > 0 ? ' y ' . $unidades[$u] : '');
        }

        if ($n === 100) { return 'cien'; }

        if ($n < 1000) {
            $c = intdiv($n, 100);
            $r = $n % 100;
            return $centenas[$c] . ($r > 0 ? ' ' . $this->enLetras($r) : '');
        }

        if ($n < 1000000) {
            $m = intdiv($n, 1000);
            $r = $n % 1000;
            $pre = $m === 1 ? 'mil' : $this->enLetras($m) . ' mil';
            return $pre . ($r > 0 ? ' ' . $this->enLetras($r) : '');
        }

        $mill = intdiv($n, 1000000);
        $r    = $n % 1000000;
        $pre  = $mill === 1 ? 'un millon' : $this->enLetras($mill) . ' millones';

        return $pre . ($r > 0 ? ' ' . $this->enLetras($r) : '');
    }

    /**
     * Qué se está financiando, dicho con el detalle que pide la ley y
     * el que hace falta para poder ejecutar.
     *
     * Un contrato de vehículo que dice «un automóvil» no sirve para
     * inscribir una prenda ni para embargar nada: hay que poder
     * señalar ESE vehículo y no otro, y eso lo hacen el chasis y el
     * VIN. En el inmueble lo hace la matrícula del Centro Nacional de
     * Registros, sin la cual la hipoteca no se inscribe. En los
     * estudios, la institución y la carrera son lo que el banco puede
     * ir a verificar, que es justo la facultad que le da la cláusula
     * del destino.
     *
     * Lo que no se capturó no se rellena con nada: se remite al
     * expediente. Un contrato que afirma un número de chasis que nadie
     * anotó es peor que uno que reconoce que está en el expediente.
     *
     * @param array<string,mixed> $o
     */
    private function detalleDelObjeto(string $modalidad, array $o): string
    {
        /** Un dato, o la remisión al expediente. */
        $d = static function (string $clave) use ($o): string {
            $v = trim((string) ($o[$clave] ?? ''));

            return $v !== '' ? $v : '';
        };

        /** Arma «etiqueta: valor» solo con lo que de verdad hay. */
        $lista = static function (array $pares): string {
            $partes = [];

            foreach ($pares as $etiqueta => $valor) {
                $valor = trim((string) $valor);

                if ($valor !== '') { $partes[] = $etiqueta . ': ' . $valor; }
            }

            return implode('; ', $partes);
        };

        $cola = ' Los datos no consignados constan en el expediente de credito y en la '
              . 'documentacion de respaldo que forma parte integrante de este contrato.';

        if ($modalidad === 'vehiculo') {
            $datos = $lista([
                'marca'        => $d('veh_marca'),
                'modelo'       => $d('veh_modelo'),
                'ano'          => $d('veh_anio'),
                'color'        => $d('veh_color'),
                'clase'        => $d('veh_clase'),
                'combustible'  => $d('veh_combustible'),
                'numero de motor'  => $d('veh_motor'),
                'numero de chasis' => $d('veh_chasis'),
                'VIN'          => $d('veh_vin'),
                'placa'        => $d('veh_placa'),
                'condicion'    => $d('veh_condicion'),
                'vendedor'     => $d('veh_vendedor'),
            ]);

            if ($datos === '') { return ''; }

            return 'El credito se destina a la adquisicion del vehiculo automotor que se '
                 . 'identifica asi: ' . $datos . '. EL DEUDOR se obliga a mantener el '
                 . 'vehiculo en buen estado de conservacion y funcionamiento, a no '
                 . 'enajenarlo, gravarlo, arrendarlo ni cederlo a ningun titulo sin '
                 . 'autorizacion escrita de EL ACREEDOR, a no modificar ni sustituir su '
                 . 'motor, carroceria o numeros de identificacion, a no sacarlo del '
                 . 'territorio nacional sin dicha autorizacion, y a mantenerlo asegurado '
                 . 'contra dano y robo durante toda la vigencia del credito, con poliza '
                 . 'endosada a favor de EL ACREEDOR por el saldo insoluto. EL DEUDOR '
                 . 'autoriza a EL ACREEDOR a inspeccionar el vehiculo en cualquier '
                 . 'momento previa coordinacion.' . $cola;
        }

        if ($modalidad === 'vivienda') {
            $datos = $lista([
                'tipo de inmueble'   => $d('inm_tipo'),
                'direccion'          => $d('inm_direccion'),
                'municipio'          => $d('inm_municipio'),
                'departamento'       => $d('inm_departamento'),
                'matricula del Centro Nacional de Registros' => $d('inm_matricula'),
                'area del terreno (m2)'    => $d('inm_area_m2'),
                'area de construccion (m2)' => $d('inm_construccion_m2'),
                'vendedor'           => $d('inm_vendedor'),
            ]);

            if ($datos === '') { return ''; }

            $tasa = trim((string) ($o['inm_tasacion_en'] ?? ''));

            return 'El credito se destina al inmueble que se identifica asi: ' . $datos
                 . '.' . ($tasa !== ''
                     ? ' El inmueble fue valuado mediante informe de tasacion de fecha '
                       . $this->fecha($tasa) . '.'
                     : '')
                 . ' EL DEUDOR se obliga a mantener el inmueble libre de gravamenes '
                 . 'distintos de los constituidos a favor de EL ACREEDOR, al dia en el '
                 . 'pago de impuestos y servicios, asegurado contra incendio y riesgos '
                 . 'afines con poliza endosada a favor de EL ACREEDOR, y a no enajenarlo '
                 . 'ni gravarlo sin autorizacion escrita de este mientras subsista la '
                 . 'deuda.' . $cola;
        }

        if ($modalidad === 'estudios') {
            $datos = $lista([
                'institucion educativa' => $d('edu_institucion'),
                'carrera o programa'    => $d('edu_carrera'),
                'nivel'                 => $d('edu_nivel'),
                'duracion'              => $d('edu_duracion'),
                'modalidad'             => $d('edu_modalidad'),
            ]);

            if ($datos === '') { return ''; }

            return 'El credito se destina a financiar los estudios siguientes: ' . $datos
                 . '. EL DEUDOR se obliga a presentar a EL ACREEDOR, cuando este lo '
                 . 'requiera, la constancia de matricula o de inscripcion del ciclo '
                 . 'correspondiente, y a informar por escrito dentro de los treinta dias '
                 . 'siguientes si abandona, suspende o cambia de carrera o de institucion. '
                 . 'El cambio de programa no altera por si solo las condiciones pactadas, '
                 . 'siempre que el credito siga destinandose a fines educativos.' . $cola;
        }

        if ($modalidad === 'negocio') {
            $datos = $lista([
                'actividad economica' => $d('neg_actividad'),
                'numero de registro de contribuyente (NRC)' => $d('neg_nrc'),
                'direccion del negocio' => $d('neg_direccion'),
                'aplicacion de los fondos' => $d('neg_aplicacion'),
            ]);

            if ($datos === '') { return ''; }

            return 'El credito se destina a la actividad economica siguiente: ' . $datos
                 . '. EL DEUDOR se obliga a destinar los fondos a la aplicacion '
                 . 'declarada, a conservar los comprobantes de las inversiones realizadas '
                 . 'y a permitir a EL ACREEDOR verificarlas en el domicilio del negocio '
                 . 'previa coordinacion.' . $cola;
        }

        $libre = trim((string) ($o['detalle'] ?? ''));

        return $libre !== ''
            ? 'El credito se destina a lo siguiente: ' . $libre . '.' . $cola
            : '';
    }

    /**
     * El pie de firmas de un contrato.
     *
     * Dos espacios, uno al lado del otro, cada uno con su rotulo: un
     * contrato lo firman dos, y el que lo lee tiene que ver de un
     * vistazo quien firma donde.
     *
     * Y, sobre todo, CABE. La version anterior dibujaba la raya en la
     * posicion en que estuviera y seguia: en un contrato de cinco
     * paginas la raya acababa cruzando el pie de pagina y el nombre
     * del cliente colgando solo en una pagina nueva. Aqui se mide
     * antes: si no quedan seis centimetros, se pasa de pagina a
     * proposito, que es distinto de desbordarse.
     */
    private function firmasDelContrato(GeneradorPdf $pdf, string $cliente, string $documento): void
    {
        $alto = 46;

        if ($pdf->GetY() + $alto > $pdf->GetPageHeight() - 25) {
            $pdf->AddPage();
        } else {
            $pdf->Ln(16);
        }

        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(70, 70, 70);
        $pdf->MultiCell(0, 5, $this->texto(
            'En fe de lo anterior, las partes firman el presente contrato en dos '
            . 'ejemplares de un mismo tenor, en la ciudad y fecha senaladas al inicio.'
        ), 0, 'J');

        $pdf->Ln(14);

        $y     = $pdf->GetY();
        $ancho = ($pdf->GetPageWidth() - 28) / 2;

        /* Las dos rayas, a la misma altura: desalineadas darian la
           impresion de que una de las dos firmas vale menos. */
        $pdf->Line(20, $y, 20 + $ancho - 14, $y);
        $pdf->Line(14 + $ancho + 6, $y, $pdf->GetPageWidth() - 20, $y);

        $pdf->SetY($y + 2);

        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(40, 40, 40);
        $pdf->SetX(14);
        $pdf->Cell($ancho, 5, $this->texto('CASHMAN H.A., S.A. DE C.V.'), 0, 0, 'C');
        $pdf->Cell($ancho, 5, $this->texto(mb_strtoupper($cliente)), 0, 1, 'C');

        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(110, 110, 110);
        $pdf->SetX(14);
        $pdf->Cell($ancho, 4, $this->texto('EL ACREEDOR'), 0, 0, 'C');
        $pdf->Cell($ancho, 4, $this->texto('EL DEUDOR'), 0, 1, 'C');

        $pdf->SetX(14);
        $pdf->Cell($ancho, 4, $this->texto('Representante autorizado'), 0, 0, 'C');
        $pdf->Cell($ancho, 4, $this->texto(
            $documento !== '' ? 'Documento de identidad ' . $documento : ''
        ), 0, 1, 'C');

        $pdf->SetTextColor(0, 0, 0);
    }

    /** Zona de firma al pie del documento. */
    private function espacioFirma(GeneradorPdf $pdf, string $nombre, string $pie): void
    {
        $pdf->Ln(14);

        $x = $pdf->GetX();
        $y = $pdf->GetY();

        $pdf->Line($x + 20, $y, $x + 90, $y);

        $pdf->SetY($y + 2);
        $pdf->SetX($x + 20);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(70, 5, $this->texto($nombre), 0, 1, 'C');

        if ($pie !== '') {
            $pdf->SetX($x + 20);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->Cell(70, 4, $this->texto($pie), 0, 1, 'C');
        }
    }

    /** FPDF no habla UTF-8: hay que pasarle Latin-1. */
    private function texto(string $s): string
    {
        return (string) mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    }

    // -----------------------------------------------------------------

    private function dinero(int $centavos): string

    {
        return Monto::deCentavos($centavos)->formateado();
    }

    private function fecha(string $iso, string $formato = 'd/m/Y'): string
    {
        if ($iso === '') {
            return '-';
        }
        try {
            return (new \DateTimeImmutable($iso))->format($formato);
        } catch (\Throwable) {
            return '-';
        }
    }
}
