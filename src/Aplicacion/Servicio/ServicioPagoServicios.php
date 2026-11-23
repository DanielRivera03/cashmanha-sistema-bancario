<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Colecturia\Colector;
use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\AccesoDenegado;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Pagar la luz, el agua, el teléfono, el colegio.
 *
 * EL FLUJO SON DOS PASOS, Y TIENE QUE SERLO
 *
 *   1. CONSULTAR: se le pregunta al colector qué se debe. No mueve
 *      dinero y se puede repetir sin consecuencias.
 *
 *   2. PAGAR: se paga lo que el colector dijo.
 *
 * Juntarlos en uno obligaría al cliente a teclear el monto, y el monto
 * no lo pone el cliente ni el banco: lo pone la empresa que cobra.
 *
 * POR QUÉ NO PIDE SEGUNDO FACTOR
 *
 * Porque el dinero va a una empresa con convenio y queda aplicado a un
 * servicio concreto, no a una cuenta que alguien pueda vaciar. Quien
 * robara una sesión y pagara la luz de la víctima no le está robando:
 * le está pagando el recibo.
 *
 * Es también lo que hacen los bancos del país: nadie pide un token
 * para pagar el agua. El segundo factor se guarda para donde el dinero
 * puede acabar en manos de cualquiera, y gastarlo aquí solo lograría
 * que el cliente se acostumbre a teclear códigos sin leer para qué.
 */
final class ServicioPagoServicios
{
    private const CUENTA_COLECTURIA = '0000000007';

    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion(),
        private ServicioLimitesCliente $limites = new ServicioLimitesCliente()
    ) {
    }

    // =================================================================
    // Paso 1: consultar
    // =================================================================

    /**
     * Qué se debe por esa referencia.
     *
     * @return array<string,mixed>
     */
    public function consultar(int $convenioId, string $referencia): array
    {
        Sesion::exigirAutenticado();

        $convenio = Colector::convenio($convenioId);

        if ($convenio === null) {
            throw new ServicioNoDisponible('Ese convenio no está disponible.');
        }

        $ref = Colector::normalizarReferencia($referencia);

        if (!Colector::referenciaBienFormada($convenio, $ref)) {
            throw new ServicioNoDisponible(sprintf(
                'El %s debe tener entre %d y %d %s.',
                $convenio['etiqueta_referencia'],
                (int) $convenio['ref_largo_min'],
                (int) $convenio['ref_largo_max'],
                (int) $convenio['ref_solo_digitos'] === 1 ? 'dígitos' : 'caracteres'
            ));
        }

        $r = Colector::consultar($convenio, $ref);

        if (($r['ok'] ?? false) !== true) {
            throw new ServicioNoDisponible(Colector::explicar((string) ($r['motivo'] ?? '')));
        }

        return [
            'convenio_id'     => (int) $convenio['id'],
            'convenio'        => $convenio['nombre_corto'],
            'referencia'      => $ref,
            'etiqueta'        => $convenio['etiqueta_referencia'],
            'titular'         => $r['titular'],
            'deuda_cent'      => (int) $r['deuda_cent'],
            'periodo'         => $r['periodo'],
            'vence_el'        => $r['vence_el'],
            'permite_parcial' => (int) $convenio['permite_parcial'] === 1,
            'comision_cent'   => (int) $convenio['comision_cent'],
        ];
    }

    // =================================================================
    // Paso 2: pagar
    // =================================================================

    /**
     * @param array{
     *   cuenta_id:int, convenio_id:int, referencia:string, monto:mixed,
     *   guardar?:bool, alias?:string, clave_idempotencia:string
     * } $datos
     * @return array<string,mixed>
     */
    public function pagar(array $datos): array
    {
        $actorId = Sesion::exigirAutenticado();

        Autorizacion::exigir('transferencia.ejecutar');

        $convenio = Colector::convenio((int) $datos['convenio_id']);

        if ($convenio === null) {
            throw new ServicioNoDisponible('Ese convenio no está disponible.');
        }

        $ref = Colector::normalizarReferencia((string) $datos['referencia']);

        /* Se vuelve a consultar ANTES de cobrar. No es redundante: entre
           que el cliente vio el monto y pulsó pagar pudo haber pagado en
           otro lado, o el colector pudo recalcular. Cobrar contra un
           monto que el navegador trae de hace diez minutos es cobrar
           contra algo que ya no existe. */
        $estado = Colector::consultar($convenio, $ref);

        if (($estado['ok'] ?? false) !== true) {
            throw new ServicioNoDisponible(Colector::explicar((string) ($estado['motivo'] ?? '')));
        }

        $deuda = (int) $estado['deuda_cent'];
        $monto = Monto::deEntradaUsuario($datos['monto']);

        /* Pagar de más no se permite: el colector lo devolvería o lo
           dejaría como saldo a favor, y en ninguno de los dos casos el
           cliente se entera por aquí. */
        if ($monto->centavos > $deuda) {
            throw new ServicioNoDisponible(
                'No puede pagar más de lo que se debe ('
                . Monto::deCentavos($deuda)->formateado() . ').'
            );
        }

        if ($monto->centavos < $deuda && (int) $convenio['permite_parcial'] !== 1) {
            throw new ServicioNoDisponible(
                $convenio['nombre_corto'] . ' no acepta abonos parciales: '
                . 'hay que pagar los ' . Monto::deCentavos($deuda)->formateado() . '.'
            );
        }

        // --- La cuenta de origen, comprobando propiedad --------------
        $cuentaId = (int) $datos['cuenta_id'];

        $cuenta = Autorizacion::exigirCuenta(
            $cuentaId,
            $actorId,
            'transferencia.ejecutar_terceros'
        );
        Autorizacion::exigirCuentaOperable($cuenta);

        // --- Los dos techos ------------------------------------------
        $this->verificarLimiteInstitucional($cuentaId, $monto);
        $this->limites->verificar($actorId, 'servicios', $monto);

        $comision = (int) $convenio['comision_cent'];

        return $this->ejecutar(
            $actorId, $cuenta, $convenio, $ref, $monto, $deuda, $comision, $estado, $datos
        );
    }

    /**
     * @param array<string,mixed> $cuenta
     * @param array<string,mixed> $convenio
     * @param array<string,mixed> $estado
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    private function ejecutar(
        int $actorId,
        array $cuenta,
        array $convenio,
        string $ref,
        Monto $monto,
        int $deuda,
        int $comision,
        array $estado,
        array $datos
    ): array {
        $cuentaId    = (int) $cuenta['id'];
        $colecturia  = $this->cuentaColecturia();
        $colecturiaId = (int) $colecturia['id'];

        $resultado = BaseDatos::enTransaccion(function () use (
            $actorId, $cuentaId, $colecturiaId, $convenio, $ref, $monto,
            $deuda, $comision, $estado, $datos
        ): array {
            $bloqueadas = LibroMayor::bloquearCuentas([$cuentaId, $colecturiaId]);

            $origen = $bloqueadas[$cuentaId] ?? null;

            if ($origen === null) {
                throw new ServicioNoDisponible('No se pudo bloquear la cuenta.');
            }

            $total = $monto->centavos + $comision;

            $disponible = Monto::deCentavos((int) $origen['saldo_cent'])
                ->menos(Monto::deCentavos((int) $origen['saldo_retenido_cent']))
                ->menos(Monto::deCentavos((int) $origen['saldo_minimo_cent']));

            if ($disponible->centavos < $total) {
                Auditoria::registrar(
                    'servicio.fondos_insuficientes',
                    Auditoria::FALLO,
                    $actorId,
                    'cuenta',
                    (string) $cuentaId,
                    ['solicitado' => Monto::deCentavos($total)->aDecimal()]
                );

                throw new FondosInsuficientes(
                    'Fondos insuficientes. Disponible: ' . $disponible->formateado() . '.'
                );
            }

            // --- El colector aplica ----------------------------------
            // Antes de tocar el libro mayor: si la empresa no lo acepta,
            // no hay nada que deshacer.
            $aplicado = Colector::aplicar($convenio, $ref, $monto->centavos);

            if (($aplicado['ok'] ?? false) !== true) {
                throw new ServicioNoDisponible(
                    Colector::explicar((string) ($aplicado['motivo'] ?? ''))
                );
            }

            $folio = Folio::unico('PSV', 'pagos_servicio', 'folio', 'ymd', 5);

            // --- El asiento -------------------------------------------
            $movimientos = [
                ['cuenta_id' => $cuentaId, 'importe' => $monto->negado(),
                 'descripcion' => $convenio['nombre_corto'] . ' · '
                                  . $convenio['etiqueta_referencia'] . ' ' . $ref],
                ['cuenta_id' => $colecturiaId, 'importe' => $monto,
                 'descripcion' => 'Colecturía ' . $convenio['codigo'] . ' · ' . $folio],
            ];

            /* La comisión, si la hay, va en el MISMO asiento: es parte
               de la misma operación y separarla permitiría que una
               quedara sin la otra. */
            if ($comision > 0) {
                $c = Monto::deCentavos($comision);

                $movimientos[] = ['cuenta_id' => $cuentaId, 'importe' => $c->negado(),
                                  'descripcion' => 'Comisión por recaudación'];
                $movimientos[] = ['cuenta_id' => $colecturiaId, 'importe' => $c,
                                  'descripcion' => 'Comisión de colecturía · ' . $folio];
            }

            $asiento = LibroMayor::registrar(
                'pago_servicio',
                $movimientos,
                $actorId,
                (string) $datos['clave_idempotencia'],
                'Pago de ' . $convenio['nombre_corto']
            );

            if (($asiento['duplicado'] ?? false) === true) {
                return [
                    'estado'     => 'duplicado',
                    'referencia' => $asiento['referencia'],
                    'mensaje'    => 'Este pago ya se había procesado.',
                ];
            }

            // --- Guardar el servicio, si lo pidió ---------------------
            $guardadoId = $this->guardarSiProcede($actorId, $convenio, $ref, $estado, $datos);

            $uuid = LibroMayor::uuid4();

            BaseDatos::ejecutar(
                'INSERT INTO pagos_servicio
                   (uuid, folio, usuario_id, cuenta_id, convenio_id, guardado_id,
                    referencia, titular, monto_cent, deuda_cent, comision_cent,
                    periodo, vence_el, estado, autorizacion, asiento_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "aplicado", ?, ?)',
                [
                    $uuid, $folio, $actorId, $cuentaId, (int) $convenio['id'], $guardadoId,
                    $ref, (string) $estado['titular'], $monto->centavos, $deuda, $comision,
                    (string) $estado['periodo'], (string) $estado['vence_el'],
                    (string) $aplicado['autorizacion'], (int) $asiento['asiento_id'],
                ]
            );

            Auditoria::registrar(
                'servicio.pagado',
                Auditoria::EXITO,
                $actorId,
                'pago_servicio',
                $folio,
                [
                    'convenio'   => $convenio['codigo'],
                    'referencia' => $ref,
                    'monto'      => $monto->aDecimal(),
                    'parcial'    => $monto->centavos < $deuda,
                ]
            );

            $parcial = $monto->centavos < $deuda;

            $this->notificaciones->crear(
                $actorId,
                'servicio.pagado',
                'Servicio pagado',
                'Pagó ' . $monto->formateado() . ' a ' . $convenio['nombre_corto']
                . ' (' . $convenio['etiqueta_referencia'] . ' ' . $ref . ').'
                . ($parcial
                    ? ' Queda pendiente ' . Monto::deCentavos($deuda - $monto->centavos)->formateado() . '.'
                    : '')
                . ' Autorización ' . $aplicado['autorizacion'] . '.',
                ['folio' => $folio],
                'exito'
            );

            return [
                'estado'        => 'aplicado',
                'folio'         => $folio,
                'uuid'          => $uuid,
                'autorizacion'  => $aplicado['autorizacion'],
                'convenio'      => $convenio['nombre_corto'],
                'referencia'    => $ref,
                'titular'       => $estado['titular'],
                'monto'         => $monto->jsonSerialize(),
                'comision_cent' => $comision,
                'deuda_cent'    => $deuda,
                'pendiente_cent' => max(0, $deuda - $monto->centavos),
                'parcial'       => $parcial,
                'periodo'       => $estado['periodo'],
                'saldo_final'   => Monto::deCentavos(
                    (int) $origen['saldo_cent'] - $monto->centavos - $comision
                )->jsonSerialize(),
                'fecha'         => date('c'),
            ];
        });

        return $resultado;
    }

    /**
     * Guarda el servicio en los del cliente, si lo pidió.
     *
     * @param array<string,mixed> $convenio
     * @param array<string,mixed> $estado
     * @param array<string,mixed> $datos
     */
    private function guardarSiProcede(
        int $actorId,
        array $convenio,
        string $ref,
        array $estado,
        array $datos
    ): ?int {
        $ya = BaseDatos::unaFila(
            'SELECT id FROM servicios_guardados
              WHERE usuario_id = ? AND convenio_id = ? AND referencia = ?',
            [$actorId, (int) $convenio['id'], $ref]
        );

        if ($ya !== null) {
            BaseDatos::ejecutar(
                'UPDATE servicios_guardados
                    SET veces_pagado = veces_pagado + 1, ultimo_pago_en = NOW(), titular = ?
                  WHERE id = ?',
                [(string) $estado['titular'], (int) $ya['id']]
            );

            return (int) $ya['id'];
        }

        if (($datos['guardar'] ?? false) !== true) {
            return null;
        }

        $alias = trim((string) ($datos['alias'] ?? ''));

        if ($alias === '') {
            $alias = (string) $convenio['nombre_corto'];
        }

        BaseDatos::ejecutar(
            'INSERT INTO servicios_guardados
               (uuid, usuario_id, convenio_id, alias, referencia, titular,
                ultimo_pago_en, veces_pagado)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), 1)',
            [
                LibroMayor::uuid4(), $actorId, (int) $convenio['id'],
                mb_substr($alias, 0, 60), $ref, (string) $estado['titular'],
            ]
        );

        return BaseDatos::ultimoId();
    }

    // =================================================================
    // Los servicios guardados
    // =================================================================

    /** @return array<int,array<string,mixed>> */
    public function guardados(int $usuarioId): array
    {
        return BaseDatos::todas(
            'SELECT g.*, c.nombre_corto AS convenio, c.categoria,
                    c.etiqueta_referencia, c.permite_parcial, c.id AS convenio_id
               FROM servicios_guardados g
               JOIN convenios_servicio c ON c.id = g.convenio_id
              WHERE g.usuario_id = ? AND c.activo = 1
              ORDER BY g.ultimo_pago_en IS NULL, g.ultimo_pago_en DESC, g.alias',
            [$usuarioId]
        );
    }

    public function olvidar(string $uuid, int $usuarioId): void
    {
        $g = BaseDatos::unaFila(
            'SELECT id, alias FROM servicios_guardados WHERE uuid = ? AND usuario_id = ?',
            [$uuid, $usuarioId]
        );

        if ($g === null) {
            throw new ServicioNoDisponible('Ese servicio no está en su lista.');
        }

        /* Los pagos NO se borran: son hechos ocurridos y el historial
           del cliente tiene que seguir cuadrando. */
        BaseDatos::enTransaccion(static function () use ($g): void {
            BaseDatos::ejecutar(
                'UPDATE pagos_servicio SET guardado_id = NULL WHERE guardado_id = ?',
                [(int) $g['id']]
            );

            BaseDatos::ejecutar('DELETE FROM servicios_guardados WHERE id = ?', [(int) $g['id']]);
        });

        Auditoria::registrar(
            'servicio.olvidado',
            Auditoria::EXITO,
            $usuarioId,
            'servicio_guardado',
            $uuid,
            ['alias' => $g['alias']]
        );
    }

    // =================================================================
    // Historial y liquidación
    // =================================================================

    /** @return array<int,array<string,mixed>> */
    public function historial(int $usuarioId, int $limite = 100): array
    {
        return BaseDatos::todas(
            'SELECT p.*, c.nombre_corto AS convenio, c.categoria, c.etiqueta_referencia
               FROM pagos_servicio p
               JOIN convenios_servicio c ON c.id = p.convenio_id
              WHERE p.usuario_id = ?
              ORDER BY p.creado_en DESC
              LIMIT ' . max(1, min(500, $limite)),
            [$usuarioId]
        );
    }

    /**
     * Liquida con los colectores lo recaudado.
     *
     * Vacía la cuenta técnica de colecturía: ese dinero es una deuda
     * del banco frente a cada empresa, y mientras no se liquide sigue
     * figurando como tal.
     *
     * @return array<string,mixed>
     */
    public function liquidar(bool $aplicar = true): array
    {
        $porLiquidar = BaseDatos::todas(
            'SELECT c.codigo, c.nombre_corto,
                    COUNT(*) AS pagos,
                    SUM(p.monto_cent + p.comision_cent) AS total_cent
               FROM pagos_servicio p
               JOIN convenios_servicio c ON c.id = p.convenio_id
              WHERE p.estado = "aplicado"
              GROUP BY c.codigo, c.nombre_corto
              ORDER BY c.nombre_corto'
        );

        if ($aplicar && $porLiquidar !== []) {
            BaseDatos::ejecutar(
                'UPDATE pagos_servicio
                    SET estado = "liquidado", liquidado_en = NOW()
                  WHERE estado = "aplicado"'
            );
        }

        return [
            'colectores' => $porLiquidar,
            'total_cent' => array_sum(array_map(
                static fn(array $f): int => (int) $f['total_cent'],
                $porLiquidar
            )),
            'pagos'      => array_sum(array_map(
                static fn(array $f): int => (int) $f['pagos'],
                $porLiquidar
            )),
        ];
    }

    // =================================================================
    // Interno
    // =================================================================

    /** @return array<string,mixed> */
    private function cuentaColecturia(): array
    {
        $c = BaseDatos::unaFila(
            'SELECT * FROM cuentas WHERE numero_cuenta = ? AND es_tecnica = 1',
            [self::CUENTA_COLECTURIA]
        );

        if ($c === null) {
            throw new \RuntimeException(
                'Falta la cuenta técnica de colecturía (' . self::CUENTA_COLECTURIA . ').'
            );
        }

        return $c;
    }

    private function verificarLimiteInstitucional(int $cuentaId, Monto $monto): void
    {
        $limite = BaseDatos::unaFila(
            'SELECT * FROM limites_operacion
              WHERE tipo_operacion = "servicios" AND activo = 1
                AND (rol_id = ? OR rol_id IS NULL)
              ORDER BY rol_id IS NULL ASC LIMIT 1',
            [Sesion::rolId()]
        );

        if ($limite === null) {
            throw new AccesoDenegado('No hay límites configurados para el pago de servicios.');
        }

        if ($monto->centavos > (int) $limite['max_por_operacion_cent']) {
            throw new LimiteExcedidoTransferencia(
                'El monto supera el máximo por operación ('
                . Monto::deCentavos((int) $limite['max_por_operacion_cent'])->formateado() . ').'
            );
        }

        $hoy = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(monto_cent + comision_cent), 0)
               FROM pagos_servicio
              WHERE cuenta_id = ? AND estado <> "reversado" AND DATE(creado_en) = CURDATE()',
            [$cuentaId]
        );

        if ($hoy + $monto->centavos > (int) $limite['max_diario_cent']) {
            throw new LimiteExcedidoTransferencia(
                'Supera el límite diario del banco para pago de servicios. Disponible hoy: '
                . Monto::deCentavos(max(0, (int) $limite['max_diario_cent'] - $hoy))->formateado() . '.'
            );
        }

        $ops = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM pagos_servicio
              WHERE cuenta_id = ? AND estado <> "reversado" AND DATE(creado_en) = CURDATE()',
            [$cuentaId]
        );

        if ($ops >= (int) $limite['max_ops_diarias']) {
            throw new LimiteExcedidoTransferencia(
                'Alcanzó el máximo de pagos de servicios por día (' . $limite['max_ops_diarias'] . ').'
            );
        }
    }
}
