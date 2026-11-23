<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\Mora;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Arreglos de pago sobre cartera vencida.
 *
 * Un expediente con 53 cuotas vencidas no se cobra cuota por cuota. Lo
 * que se hace de verdad es sentarse a negociar, y lo que se negocia son
 * dos cosas:
 *
 *   AL DÍA     el cliente paga un porcentaje de lo vencido y las cuotas
 *              atrasadas se dan por cubiertas. El crédito sigue vivo con
 *              su plan original y él vuelve a estar al corriente.
 *
 *   FINIQUITO  el cliente paga una cantidad única y se cancela todo:
 *              lo vencido, la mora y el capital que quedaba. El crédito
 *              se cierra.
 *
 * Las dos implican perdonar parte de la deuda, y ahí está lo delicado.
 * Perdonar dinero es la facultad más peligrosa que puede tener un
 * sistema de cobranza: quien la tiene puede regalar la cartera. Por eso
 * aquí hay tres frenos:
 *
 *   1. Un tope de condonación que cobranza puede conceder sola
 *      (`arreglo.quita_maxima_bp`, 30% de salida).
 *   2. Por encima de ese tope hace falta que OTRA persona lo autorice,
 *      con permiso `arreglo.autorizar`. Quien propone no autoriza,
 *      aunque tenga los dos permisos.
 *   3. Un techo absoluto (`arreglo.quita_tope_bp`) que ni con
 *      autorización se pasa.
 *
 * Y todo queda escrito: quién propuso, quién autorizó, cuánto se
 * perdonó y contra qué asiento. La condonación va a una cuenta técnica
 * propia para que la pérdida se vea en el libro mayor en lugar de
 * esfumarse.
 */
final class ServicioArreglo
{
    private ServicioNotificacion $notificaciones;

    public function __construct(?ServicioNotificacion $notificaciones = null)
    {
        $this->notificaciones = $notificaciones ?? new ServicioNotificacion();
    }

    // =================================================================
    // CALCULAR
    // =================================================================

    /**
     * Qué se le puede ofrecer a este crédito, con las cifras de hoy.
     *
     * Se usa para pintar el formulario antes de proponer nada: enseña
     * la deuda real, lo que costaría cada modalidad y cuánto se estaría
     * perdonando. Nada de esto toca la base.
     *
     * @return array<string,mixed>
     */
    public function simular(int $creditoId, string $tipo, int $porcentajeBp): array
    {
        $deuda = $this->deudaDe($creditoId);

        $exigible = $tipo === 'finiquito'
            ? $deuda['vencido_cent'] + $deuda['mora_cent'] + $deuda['capital_futuro_cent']
            : $deuda['vencido_cent'] + $deuda['mora_cent'];

        $porcentajeBp = $this->acotar($porcentajeBp);

        $requerido = (int) round($exigible * $porcentajeBp / 10000);
        $condonado = max(0, $exigible - $requerido);

        $quitaBp = $exigible > 0
            ? (int) round($condonado / $exigible * 10000)
            : 0;

        $maxima = Parametros::entero('arreglo.quita_maxima_bp', 3000);
        $tope   = Parametros::entero('arreglo.quita_tope_bp', 7000);

        return [
            'tipo'             => $tipo,
            'vencido_cent'     => $deuda['vencido_cent'],
            'mora_cent'        => $deuda['mora_cent'],
            'capital_cent'     => $deuda['capital_futuro_cent'],
            'exigible_cent'    => $exigible,
            'porcentaje_bp'    => $porcentajeBp,
            'requerido_cent'   => $requerido,
            'condonado_cent'   => $condonado,
            'quita_bp'         => $quitaBp,
            'cuotas'           => $deuda['cuotas'],
            'cuotas_vencidas'  => count($deuda['cuotas']),
            'dias_mora'        => $deuda['dias_mora'],
            // Lo que de verdad decide si esto se puede firmar hoy o
            // tiene que subir a gerencia.
            'requiere_autorizacion' => $quitaBp > $maxima,
            'quita_maxima_bp'  => $maxima,
            'quita_tope_bp'    => $tope,
            'excede_tope'      => $quitaBp > $tope,
        ];
    }

    /**
     * La deuda de un crédito, desglosada.
     *
     * La mora se calcula con la misma clase que usa el cobro. Si aquí
     * se usara otra fórmula, el arreglo ofrecería una cifra y el cobro
     * exigiría otra.
     *
     * @return array<string,mixed>
     */
    public function deudaDe(int $creditoId): array
    {
        $cuotas = BaseDatos::todas(
            'SELECT id, numero, total_cent, pagado_cent, fecha_vencimiento,
                    GREATEST(0, total_cent - pagado_cent) AS pendiente_cent,
                    DATEDIFF(CURDATE(), fecha_vencimiento) AS dias
               FROM cuotas
              WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")
                AND fecha_vencimiento < CURDATE()
              ORDER BY numero',
            [$creditoId]
        );

        $vencido = 0;
        $mora    = 0;
        $dias    = 0;

        foreach ($cuotas as &$q) {
            $pendiente = (int) $q['pendiente_cent'];
            $d         = max(0, (int) $q['dias']);

            $q['mora_cent'] = Mora::calcular(Monto::deCentavos($pendiente), $d)->centavos;

            $vencido += $pendiente;
            $mora    += (int) $q['mora_cent'];
            $dias     = max($dias, $d);
        }
        unset($q);

        // El capital que aún no ha vencido. En un finiquito también se
        // paga: se está cerrando el crédito entero, no poniéndolo al
        // día.
        $futuro = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(GREATEST(0, total_cent - pagado_cent)), 0)
               FROM cuotas
              WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")
                AND fecha_vencimiento >= CURDATE()',
            [$creditoId]
        );

        return [
            'vencido_cent'        => $vencido,
            'mora_cent'           => $mora,
            'capital_futuro_cent' => $futuro,
            'total_cent'          => $vencido + $mora + $futuro,
            'dias_mora'           => $dias,
            'cuotas'              => $cuotas,
        ];
    }

    // =================================================================
    // PROPONER
    // =================================================================

    /**
     * Deja por escrito una oferta concreta, con fecha de caducidad.
     *
     * La oferta congela las cifras del día en que se hace. Si el cliente
     * tarda cinco días en pagar, la mora habrá seguido subiendo, pero lo
     * que se le prometió es esto: cobrarle más de lo pactado porque el
     * reloj siguió corriendo sería faltar a la palabra dada.
     *
     * @return array<string,mixed>
     */
    public function proponer(
        int $creditoId,
        string $tipo,
        int $porcentajeBp,
        string $nota = '',
        ?int $casoId = null
    ): array {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('arreglo.proponer');

        if (!in_array($tipo, ['al_dia', 'finiquito'], true)) {
            throw new \RuntimeException('Modalidad de arreglo no reconocida.');
        }

        return BaseDatos::enTransaccion(function () use (
            $creditoId, $tipo, $porcentajeBp, $nota, $casoId, $actorId
        ) {
            $credito = BaseDatos::unaFila(
                'SELECT c.id, c.folio, c.usuario_id, c.estado, c.saldo_capital_cent,
                        CONCAT(u.nombres, " ", u.apellidos) AS cliente
                   FROM creditos c JOIN usuarios u ON u.id = c.usuario_id
                  WHERE c.id = ? FOR UPDATE',
                [$creditoId]
            );

            if ($credito === null) {
                throw new \RuntimeException('Crédito no encontrado.');
            }

            if (!in_array($credito['estado'], ['vigente', 'desembolsado'], true)) {
                throw new \RuntimeException(
                    'Solo se pueden pactar arreglos sobre un crédito vigente.'
                );
            }

            // Un crédito con dos arreglos vivos a la vez es una puerta
            // abierta a cobrar dos veces lo mismo, o a que el cliente
            // elija el que más le convenga de los dos.
            $abierto = BaseDatos::unValor(
                'SELECT folio FROM arreglos_pago
                  WHERE credito_id = ? AND estado IN ("propuesto","autorizado")
                  LIMIT 1',
                [$creditoId]
            );

            if ($abierto !== null) {
                throw new \RuntimeException(
                    'Este crédito ya tiene el arreglo ' . $abierto . ' en pie. '
                    . 'Anúlelo antes de proponer otro.'
                );
            }

            $sim = $this->simular($creditoId, $tipo, $porcentajeBp);

            if ($sim['cuotas_vencidas'] === 0) {
                throw new \RuntimeException('Este crédito no tiene cuotas vencidas.');
            }

            if ($sim['excede_tope']) {
                throw new \RuntimeException(
                    'La condonación resultante ('
                    . number_format($sim['quita_bp'] / 100, 2)
                    . '%) supera el tope institucional de '
                    . number_format($sim['quita_tope_bp'] / 100, 2) . '%.'
                );
            }

            $dias  = max(1, Parametros::entero('arreglo.dias_vigencia', 8));
            $folio = $this->siguienteFolio();

            BaseDatos::ejecutar(
                'INSERT INTO arreglos_pago
                   (uuid, folio, credito_id, caso_id, usuario_id, tipo,
                    deuda_vencida_cent, mora_cent, capital_cent, exigible_cent,
                    porcentaje_bp, monto_requerido_cent, condonado_cent,
                    cuotas_cubiertas, estado, vence_el, propuesto_por, nota)
                 VALUES (UUID(),?,?,?,?,?,?,?,?,?,?,?,?,?, "propuesto",
                         DATE_ADD(CURDATE(), INTERVAL ? DAY), ?, ?)',
                [
                    $folio, $creditoId, $casoId, (int) $credito['usuario_id'], $tipo,
                    $sim['vencido_cent'], $sim['mora_cent'], $sim['capital_cent'],
                    $sim['exigible_cent'], $sim['porcentaje_bp'],
                    $sim['requerido_cent'], $sim['condonado_cent'],
                    $sim['cuotas_vencidas'], $dias, $actorId,
                    mb_substr($nota, 0, 500) ?: null,
                ]
            );

            $arregloId = BaseDatos::ultimoId();

            /* Qué cuotas entran en el trato.
             *
             * Se fija ahora, no al pagar. Si entre la propuesta y el
             * pago vence otra cuota, esa no estaba en lo que se ofreció
             * y no puede darse por cubierta. */
            $cuotasCubiertas = $sim['cuotas'];

            if ($tipo === 'finiquito') {
                // En un finiquito entra todo, también lo no vencido.
                $cuotasCubiertas = BaseDatos::todas(
                    'SELECT id FROM cuotas WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")',
                    [$creditoId]
                );
            }

            foreach ($cuotasCubiertas as $q) {
                BaseDatos::ejecutar(
                    'INSERT IGNORE INTO arreglo_cuotas (arreglo_id, cuota_id) VALUES (?,?)',
                    [$arregloId, (int) $q['id']]
                );
            }

            // Si no necesita visto bueno, queda listo para cobrar.
            if (!$sim['requiere_autorizacion']) {
                BaseDatos::ejecutar(
                    'UPDATE arreglos_pago
                        SET estado = "autorizado", autorizado_por = ?, autorizado_en = NOW()
                      WHERE id = ?',
                    [$actorId, $arregloId]
                );
            }

            Auditoria::registrar(
                'arreglo.propuesto',
                Auditoria::EXITO,
                $actorId,
                'arreglo',
                (string) $arregloId,
                [
                    'folio'     => $folio,
                    'credito'   => $credito['folio'],
                    'tipo'      => $tipo,
                    'exigible'  => Monto::deCentavos($sim['exigible_cent'])->aDecimal(),
                    'requerido' => Monto::deCentavos($sim['requerido_cent'])->aDecimal(),
                    'condonado' => Monto::deCentavos($sim['condonado_cent'])->aDecimal(),
                    'quita_bp'  => $sim['quita_bp'],
                ]
            );

            if ($sim['requiere_autorizacion']) {
                $this->avisarAutorizadores($folio, $credito, $sim);
            } else {
                $this->avisarCliente($arregloId, $credito, $sim, $folio, $dias);
            }

            return [
                'id'        => $arregloId,
                'folio'     => $folio,
                'estado'    => $sim['requiere_autorizacion'] ? 'propuesto' : 'autorizado',
                'requerido' => $sim['requerido_cent'],
                'condonado' => $sim['condonado_cent'],
                'quita_bp'  => $sim['quita_bp'],
                'requiere_autorizacion' => $sim['requiere_autorizacion'],
            ];
        });
    }

    // =================================================================
    // AUTORIZAR
    // =================================================================

    /**
     * El segundo par de ojos.
     *
     * Quien propuso no puede autorizar, aunque tenga el permiso. Sin esa
     * regla, los cuatro ojos son dos: bastaría con tener los dos
     * permisos para perdonar lo que se quisiera sin que nadie más lo
     * mirara.
     *
     * @return array<string,mixed>
     */
    public function autorizar(int $arregloId): array
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('arreglo.autorizar');

        return BaseDatos::enTransaccion(function () use ($arregloId, $actorId) {
            $a = $this->cargar($arregloId, true);

            if ($a['estado'] !== 'propuesto') {
                throw new \RuntimeException(
                    'Este arreglo ya está ' . $a['estado'] . '.'
                );
            }

            if ((int) $a['propuesto_por'] === $actorId) {
                throw new \RuntimeException(
                    'No puede autorizar un arreglo que usted mismo propuso. '
                    . 'Tiene que revisarlo otra persona.'
                );
            }

            if ($this->caducado($a)) {
                throw new \RuntimeException('La oferta ya venció. Hay que proponerla de nuevo.');
            }

            BaseDatos::ejecutar(
                'UPDATE arreglos_pago
                    SET estado = "autorizado", autorizado_por = ?, autorizado_en = NOW()
                  WHERE id = ?',
                [$actorId, $arregloId]
            );

            Auditoria::registrar('arreglo.autorizado', Auditoria::EXITO, $actorId,
                'arreglo', (string) $arregloId,
                ['folio' => $a['folio'], 'condonado' => (int) $a['condonado_cent']]);

            $this->notificaciones->crear(
                (int) $a['usuario_id'],
                'cobranza.arreglo',
                'Tenemos una propuesta para ponerse al día',
                $this->textoOferta($a),
                ['arreglo' => $a['folio']],
                'info'
            );

            return ['estado' => 'autorizado', 'folio' => $a['folio']];
        });
    }

    public function anular(int $arregloId, string $motivo = ''): array
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('arreglo.anular');

        return BaseDatos::enTransaccion(function () use ($arregloId, $actorId, $motivo) {
            $a = $this->cargar($arregloId, true);

            if (!in_array($a['estado'], ['propuesto', 'autorizado'], true)) {
                throw new \RuntimeException('Solo se anula un arreglo que siga en pie.');
            }

            BaseDatos::ejecutar(
                'UPDATE arreglos_pago
                    SET estado = "anulado", anulado_por = ?,
                        nota = TRIM(CONCAT(COALESCE(nota, ""), " · Anulado: ", ?))
                  WHERE id = ?',
                [$actorId, mb_substr($motivo, 0, 200) ?: 'sin motivo', $arregloId]
            );

            // Los enlaces de pago que colgaban de él dejan de servir en
            // el mismo momento: si no, el cliente podría pagar un
            // arreglo que ya no existe.
            BaseDatos::ejecutar(
                'UPDATE enlaces_pago SET estado = "anulado"
                  WHERE arreglo_id = ? AND estado = "vigente"',
                [$arregloId]
            );

            Auditoria::registrar('arreglo.anulado', Auditoria::EXITO, $actorId,
                'arreglo', (string) $arregloId, ['folio' => $a['folio'], 'motivo' => $motivo]);

            return ['estado' => 'anulado', 'folio' => $a['folio']];
        });
    }

    // =================================================================
    // COBRAR
    // =================================================================

    /**
     * Aplica el arreglo una vez entra el dinero.
     *
     * Lo llaman tanto la ventanilla (efectivo recibido) como la pasarela
     * (el cliente pagó por el enlace). Lo que hace es lo mismo en los dos
     * casos: dar por cubiertas las cuotas pactadas, registrar el asiento
     * con la pérdida separada y, si era finiquito, cerrar el crédito.
     *
     * @param int|null $cuentaCargoId Cuenta a debitar, o null si el
     *                                dinero entró por la pasarela y ya
     *                                está en la cuenta técnica.
     * @return array<string,mixed>
     */
    public function aplicarPago(int $arregloId, ?int $cuentaCargoId, string $claveIdem): array
    {
        $actorId = Sesion::usuarioId();

        return BaseDatos::enTransaccion(function () use (
            $arregloId, $cuentaCargoId, $claveIdem, $actorId
        ) {
            $a = $this->cargar($arregloId, true);

            if ($a['estado'] === 'pagado') {
                return ['estado' => 'duplicado', 'folio' => $a['folio']];
            }

            if ($a['estado'] !== 'autorizado') {
                throw new \RuntimeException(
                    'El arreglo tiene que estar autorizado antes de cobrarlo.'
                );
            }

            if ($this->caducado($a)) {
                throw new \RuntimeException('La oferta venció el ' . $a['vence_el'] . '.');
            }

            $requerido = (int) $a['monto_requerido_cent'];
            $condonado = (int) $a['condonado_cent'];
            $exigible  = (int) $a['exigible_cent'];

            $tecnica = $this->cuentaTecnica();
            $perdida = $this->cuentaCondonaciones();

            $movimientos = [];

            if ($cuentaCargoId !== null) {
                // Cobro en ventanilla: sale de la cuenta del cliente.
                LibroMayor::bloquearCuentas([$cuentaCargoId, $tecnica, $perdida]);

                $saldo = (int) BaseDatos::unValor(
                    'SELECT saldo_cent FROM cuentas WHERE id = ?',
                    [$cuentaCargoId]
                );

                if ($saldo < $requerido) {
                    throw new FondosInsuficientes(
                        'Fondos insuficientes. El arreglo requiere '
                        . Monto::deCentavos($requerido)->formateado()
                        . ' y la cuenta tiene ' . Monto::deCentavos(max(0, $saldo))->formateado() . '.'
                    );
                }

                $movimientos[] = [
                    'cuenta_id'   => $cuentaCargoId,
                    'importe'     => Monto::deCentavos($requerido)->negado(),
                    'descripcion' => 'Arreglo de pago ' . $a['folio'],
                ];
            } else {
                // Pagó por la pasarela: el dinero ya entró ahí.
                LibroMayor::bloquearCuentas([$tecnica, $perdida]);

                $movimientos[] = [
                    'cuenta_id'   => $this->cuentaPasarela(),
                    'importe'     => Monto::deCentavos($requerido)->negado(),
                    'descripcion' => 'Arreglo de pago ' . $a['folio'] . ' (pasarela)',
                ];
            }

            /* La pérdida, en su propia cuenta.
             *
             * Sin esta pata, cobrar $300 de una deuda de $1,000
             * cuadraría como si la deuda hubiera sido de $300 y los
             * $700 perdonados no aparecerían en ninguna parte. */
            if ($condonado > 0) {
                $movimientos[] = [
                    'cuenta_id'   => $perdida,
                    'importe'     => Monto::deCentavos($condonado)->negado(),
                    'descripcion' => 'Condonación ' . $a['folio'] . ' ('
                                     . number_format($condonado / max(1, $exigible) * 100, 2) . '%)',
                ];
            }

            $movimientos[] = [
                'cuenta_id'   => $tecnica,
                'importe'     => Monto::deCentavos($requerido + $condonado),
                'descripcion' => 'Liquidación por arreglo ' . $a['folio'],
            ];

            $asiento = LibroMayor::registrar(
                'arreglo_pago',
                $movimientos,
                $actorId,
                $claveIdem,
                'Arreglo de pago ' . $a['folio']
            );

            if (($asiento['duplicado'] ?? false) === true) {
                return ['estado' => 'duplicado', 'referencia' => $asiento['referencia']];
            }

            // Las cuotas pactadas quedan saldadas. Se marcan como
            // `condonada` cuando se perdonó algo, para que el histórico
            // no diga que el cliente las pagó íntegras.
            $estadoCuota = $condonado > 0 ? 'condonada' : 'pagada';

            BaseDatos::ejecutar(
                'UPDATE cuotas q
                   JOIN arreglo_cuotas ac ON ac.cuota_id = q.id
                    SET q.pagado_cent = q.total_cent,
                        q.estado      = ?,
                        q.pagada_en   = NOW(),
                        q.mora_cent   = 0
                  WHERE ac.arreglo_id = ?',
                [$estadoCuota, $arregloId]
            );

            $capitalCubierto = (int) BaseDatos::unValor(
                'SELECT COALESCE(SUM(q.capital_cent), 0)
                   FROM cuotas q JOIN arreglo_cuotas ac ON ac.cuota_id = q.id
                  WHERE ac.arreglo_id = ?',
                [$arregloId]
            );

            BaseDatos::ejecutar(
                'UPDATE creditos c SET
                    saldo_capital_cent = GREATEST(0, saldo_capital_cent - ?),
                    total_pagado_cent  = total_pagado_cent + ?,
                    cuotas_pagadas     = (SELECT COUNT(*) FROM cuotas
                                           WHERE credito_id = c.id AND estado IN ("pagada","condonada")),
                    proxima_cuota      = (SELECT MIN(fecha_vencimiento) FROM cuotas
                                           WHERE credito_id = c.id
                                             AND estado NOT IN ("pagada","condonada"))
                  WHERE c.id = ?',
                [$capitalCubierto, $requerido, (int) $a['credito_id']]
            );

            // Un finiquito cierra el crédito. Uno de ponerse al día no:
            // el cliente sigue debiendo lo que aún no vence.
            $restantes = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM cuotas
                  WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")',
                [(int) $a['credito_id']]
            );

            if ($a['tipo'] === 'finiquito' || $restantes === 0) {
                BaseDatos::ejecutar(
                    'UPDATE creditos SET estado = "cancelado", saldo_capital_cent = 0
                      WHERE id = ?',
                    [(int) $a['credito_id']]
                );
            }

            BaseDatos::ejecutar(
                'UPDATE arreglos_pago
                    SET estado = "pagado", pagado_en = NOW(), asiento_id = ?
                  WHERE id = ?',
                [(int) ($asiento['id'] ?? 0) ?: null, $arregloId]
            );

            // El expediente de cobranza, si lo hay, se da por recuperado.
            if ($a['caso_id'] !== null) {
                BaseDatos::ejecutar(
                    'UPDATE cobranza_casos
                        SET etapa = "recuperado", cerrado_en = NOW(),
                            recuperado_cent = recuperado_cent + ?,
                            motivo_cierre = ?
                      WHERE id = ? AND cerrado_en IS NULL',
                    [
                        $requerido,
                        'Arreglo de pago ' . $a['folio'] . ' cobrado',
                        (int) $a['caso_id'],
                    ]
                );
            }

            /* El expediente se cierra o se actualiza segun quede.
             * El cierre por caso ya estaba mas arriba cuando el
             * arreglo traia expediente enganchado; esto cubre
             * tambien los que no lo traian. */
            (new ServicioCobranza())->refrescarCaso((int) $a['credito_id']);

            Auditoria::registrar('arreglo.pagado', Auditoria::EXITO, $actorId,
                'arreglo', (string) $arregloId, [
                    'folio'      => $a['folio'],
                    'cobrado'    => Monto::deCentavos($requerido)->aDecimal(),
                    'condonado'  => Monto::deCentavos($condonado)->aDecimal(),
                    'referencia' => $asiento['referencia'],
                ]);

            $this->notificaciones->crear(
                (int) $a['usuario_id'],
                'cobranza.arreglo_pagado',
                $a['tipo'] === 'finiquito' ? 'Su deuda quedó saldada' : 'Su crédito quedó al día',
                $a['tipo'] === 'finiquito'
                    ? 'Recibimos ' . Monto::deCentavos($requerido)->formateado()
                      . ' y con ello su crédito queda cancelado por completo. '
                      . 'Le haremos llegar el finiquito.'
                    : 'Recibimos ' . Monto::deCentavos($requerido)->formateado()
                      . ' y con ello sus ' . (int) $a['cuotas_cubiertas']
                      . ' cuota(s) atrasada(s) quedan cubiertas. Su crédito vuelve a estar al día.',
                ['referencia' => $asiento['referencia']],
                'exito'
            );

            return [
                'estado'     => 'pagado',
                'folio'      => $a['folio'],
                'referencia' => $asiento['referencia'],
                'cobrado'    => $requerido,
                'condonado'  => $condonado,
                'cancelado'  => $a['tipo'] === 'finiquito' || $restantes === 0,
            ];
        });
    }

    // =================================================================
    // CONSULTA
    // =================================================================

    /** @return list<array<string,mixed>> */
    public function deCredito(int $creditoId): array
    {
        return BaseDatos::todas(
            'SELECT a.*,
                    CONCAT(p.nombres," ",p.apellidos) AS propuso,
                    CONCAT(z.nombres," ",z.apellidos) AS autorizo
               FROM arreglos_pago a
               LEFT JOIN usuarios p ON p.id = a.propuesto_por
               LEFT JOIN usuarios z ON z.id = a.autorizado_por
              WHERE a.credito_id = ?
              ORDER BY a.id DESC',
            [$creditoId]
        );
    }

    /**
     * Caduca las ofertas que nadie recogió.
     *
     * La ejecuta la pasada diaria. Una oferta vencida que siga marcada
     * como vigente es una oferta que alguien podría cobrar meses
     * después con la mora de entonces ya olvidada.
     */
    public function caducarVencidos(): int
    {
        $n = BaseDatos::ejecutar(
            'UPDATE arreglos_pago SET estado = "vencido"
              WHERE estado IN ("propuesto","autorizado") AND vence_el < CURDATE()'
        );

        BaseDatos::ejecutar(
            'UPDATE enlaces_pago e
               JOIN arreglos_pago a ON a.id = e.arreglo_id
                SET e.estado = "caducado"
              WHERE e.estado = "vigente" AND a.estado = "vencido"'
        );

        return $n;
    }

    // =================================================================
    // INTERNO
    // =================================================================

    /** @return array<string,mixed> */
    private function cargar(int $arregloId, bool $bloquear = false): array
    {
        $a = BaseDatos::unaFila(
            'SELECT * FROM arreglos_pago WHERE id = ?' . ($bloquear ? ' FOR UPDATE' : ''),
            [$arregloId]
        );

        if ($a === null) {
            throw new \RuntimeException('Arreglo no encontrado.');
        }

        return $a;
    }

    /** @param array<string,mixed> $a */
    private function caducado(array $a): bool
    {
        return (string) $a['vence_el'] < date('Y-m-d');
    }

    private function acotar(int $porcentajeBp): int
    {
        // Menos del 10% no es un arreglo, es regalar la deuda; más del
        // 100% sería cobrar de más.
        return max(1000, min(10000, $porcentajeBp));
    }

    private function siguienteFolio(): string
    {
        return Folio::unico('ARR', 'arreglos_pago', 'folio', 'ymd');
    }

    private function cuentaTecnica(): int
    {
        return (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
        );
    }

    private function cuentaPasarela(): int
    {
        $numero = Parametros::obtener('pasarela.cuenta_tecnica', '0000000001');

        $id = BaseDatos::unValor('SELECT id FROM cuentas WHERE numero_cuenta = ?', [$numero]);

        return $id !== null ? (int) $id : $this->cuentaTecnica();
    }

    private function cuentaCondonaciones(): int
    {
        $numero = Parametros::obtener('contabilidad.cuenta_condonaciones', '0000000003');

        $id = BaseDatos::unValor('SELECT id FROM cuentas WHERE numero_cuenta = ?', [$numero]);

        if ($id === null) {
            throw new \RuntimeException(
                'No existe la cuenta técnica de condonaciones (' . $numero . ').'
            );
        }

        return (int) $id;
    }

    /** @param array<string,mixed> $a */
    private function textoOferta(array $a): string
    {
        $req = Monto::deCentavos((int) $a['monto_requerido_cent'])->formateado();

        return $a['tipo'] === 'finiquito'
            ? 'Con un pago único de ' . $req . ' damos por cancelado su crédito '
              . 'por completo. La oferta está en pie hasta el '
              . date('d/m/Y', strtotime((string) $a['vence_el'])) . '.'
            : 'Con un pago de ' . $req . ' cubrimos sus '
              . (int) $a['cuotas_cubiertas'] . ' cuota(s) atrasada(s) y su crédito '
              . 'vuelve a estar al día. La oferta está en pie hasta el '
              . date('d/m/Y', strtotime((string) $a['vence_el'])) . '.';
    }

    /**
     * @param array<string,mixed> $credito
     * @param array<string,mixed> $sim
     */
    private function avisarAutorizadores(string $folio, array $credito, array $sim): void
    {
        $revisores = BaseDatos::todas(
            'SELECT DISTINCT u.id FROM usuarios u
               JOIN rol_permiso rp ON rp.rol_id = u.rol_id
               JOIN permisos p ON p.id = rp.permiso_id
              WHERE p.clave = "arreglo.autorizar" AND u.estado = "activo" LIMIT 15'
        );

        foreach ($revisores as $r) {
            $this->notificaciones->crear(
                (int) $r['id'],
                'arreglo.por_autorizar',
                'Arreglo ' . $folio . ' pendiente de autorizar',
                'Se propone condonar '
                . Monto::deCentavos($sim['condonado_cent'])->formateado()
                . ' (' . number_format($sim['quita_bp'] / 100, 2) . '%) del crédito '
                . $credito['folio'] . ' de ' . $credito['cliente'] . '. '
                . 'Supera el ' . number_format($sim['quita_maxima_bp'] / 100, 2)
                . '% que cobranza puede conceder sola.',
                ['arreglo' => $folio],
                // 'alerta', no 'advertencia': la columna es un ENUM con
                // cuatro valores y MySQL trunca en silencio lo que no
                // esté en la lista.
                'alerta'
            );
        }
    }

    /**
     * @param array<string,mixed> $credito
     * @param array<string,mixed> $sim
     */
    private function avisarCliente(
        int $arregloId,
        array $credito,
        array $sim,
        string $folio,
        int $dias
    ): void {
        $this->notificaciones->crear(
            (int) $credito['usuario_id'],
            'cobranza.arreglo',
            'Tenemos una propuesta para ponerse al día',
            ($sim['tipo'] === 'finiquito'
                ? 'Con un pago único de '
                  . Monto::deCentavos($sim['requerido_cent'])->formateado()
                  . ' damos por cancelado su crédito ' . $credito['folio'] . '.'
                : 'Con un pago de '
                  . Monto::deCentavos($sim['requerido_cent'])->formateado()
                  . ' cubrimos sus ' . $sim['cuotas_vencidas']
                  . ' cuota(s) atrasada(s) del crédito ' . $credito['folio']
                  . ' y vuelve a estar al día.')
            . ' La oferta está en pie ' . $dias . ' día(s).',
            ['arreglo' => $folio],
            'info'
        );
    }
}
