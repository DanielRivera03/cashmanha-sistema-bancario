<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Transferencias programadas y débito automático.
 *
 * LO QUE VINO A ARREGLAR
 *
 * `tarjetas.pago_automatico` existía, la pantalla lo ofrecía, treinta
 * y cinco clientes lo tenían puesto, y NADIE lo ejecutaba. El sistema
 * les prometía que su tarjeta se pagaba sola y les corría la mora.
 *
 * Una promesa en pantalla que nada cumple es peor que no ofrecer nada:
 * quien no ve la opción busca cómo pagar; quien la ve, no.
 *
 * LAS DOS COSAS QUE NO PUEDEN FALLAR
 *
 *   1. NO COBRAR DOS VECES. Lo garantiza el UNIQUE de (orden, fecha
 *      prevista, resultado): una ejecución exitosa por fecha, aunque
 *      el barrido corra dos veces o alguien lo lance a mano.
 *
 *   2. NO RENDIRSE AL PRIMER INTENTO. Que no haya fondos el día 5 no
 *      significa que no los haya el 6. Se reintenta unos días; así un
 *      descuido de un día no se convierte en una mora de un mes.
 *
 * POR QUÉ CADA INTENTO DEJA FILA
 *
 * Porque lo que el cliente pregunta no es «¿está activa mi orden?»,
 * es «¿por qué no se pagó el mes pasado?». Sin el registro de cada
 * intento —el que salió y el que no, y por qué— eso no tiene respuesta.
 */
final class ServicioProgramadas
{
    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion(),
        private ServicioAbonoTarjeta $abonos = new ServicioAbonoTarjeta()
    ) {
    }

    // =================================================================
    // Alta
    // =================================================================

    /**
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    public function crear(array $datos): array
    {
        $actorId = Sesion::exigirAutenticado();

        Autorizacion::exigir('programada.administrar');

        $tipo = (string) ($datos['tipo'] ?? '');

        if (!in_array($tipo, ['transferencia', 'interbancaria', 'servicio', 'tarjeta', 'credito'], true)) {
            throw new ProgramadaInvalida('Tipo de orden no reconocido.');
        }

        $origenId = (int) ($datos['cuenta_origen_id'] ?? 0);

        $origen = Autorizacion::exigirCuenta($origenId, $actorId, 'programada.ver_todas');
        Autorizacion::exigirCuentaOperable($origen);

        $modo = (string) ($datos['modo_monto'] ?? 'fijo');

        if (!in_array($modo, ['fijo', 'total', 'minimo'], true)) {
            $modo = 'fijo';
        }

        /* Los modos variables solo tienen sentido sobre algo que tenga
           un saldo que consultar. Una transferencia «por el total» de
           qué: no hay total que valga. */
        if ($modo !== 'fijo' && !in_array($tipo, ['tarjeta', 'credito'], true)) {
            throw new ProgramadaInvalida(
                'El monto variable solo sirve para tarjetas y créditos, que son los '
                . 'que tienen un saldo que consultar. Indique un monto fijo.'
            );
        }

        $montoCent = 0;

        if ($modo === 'fijo') {
            $montoCent = Monto::deEntradaUsuario($datos['monto'] ?? '0')->centavos;

            if ($montoCent <= 0) {
                throw new ProgramadaInvalida('Indique cuánto se va a transferir.');
            }
        }

        $frecuencia = (string) ($datos['frecuencia'] ?? 'mensual');

        $validas = ['unica', 'semanal', 'quincenal', 'mensual',
                    'bimestral', 'trimestral', 'semestral', 'anual'];

        if (!in_array($frecuencia, $validas, true)) {
            throw new ProgramadaInvalida('Frecuencia no reconocida.');
        }

        $inicio = (string) ($datos['fecha_inicio'] ?? date('Y-m-d'));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio) !== 1) {
            throw new ProgramadaInvalida('Fecha de inicio no válida.');
        }

        /* Una orden no puede empezar ayer: ejecutaría de inmediato algo
           que el cliente programó para el futuro. */
        if ($inicio < date('Y-m-d')) {
            $inicio = date('Y-m-d');
        }

        $destino = $this->validarDestino($tipo, $datos, $actorId);

        $folio = Folio::unico('ORD', 'ordenes_programadas', 'folio', 'ymd', 5);

        BaseDatos::ejecutar(
            'INSERT INTO ordenes_programadas
               (uuid, folio, usuario_id, alias, tipo, cuenta_origen_id,
                cuenta_destino_id, beneficiario_id, convenio_id, referencia,
                tarjeta_id, credito_id, modo_monto, monto_cent, tope_cent,
                concepto, frecuencia, dia_mes, dia_semana,
                fecha_inicio, fecha_fin, proxima_en, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "activa")',
            [
                LibroMayor::uuid4(), $folio, $actorId,
                mb_substr(trim((string) ($datos['alias'] ?? '')), 0, 60),
                $tipo, $origenId,
                $destino['cuenta_destino_id'], $destino['beneficiario_id'],
                $destino['convenio_id'], $destino['referencia'],
                $destino['tarjeta_id'], $destino['credito_id'],
                $modo, $montoCent,
                /* El tope es opcional y «sin tope» es cero, que
                   `Monto` rechaza por diseño —un monto de cero no es
                   un monto—. Por eso se mira antes de convertirlo. */
                $this->topeEnCentavos($datos['tope'] ?? null),
                mb_substr(trim((string) ($datos['concepto'] ?? '')), 0, 160),
                $frecuencia,
                isset($datos['dia_mes']) ? max(1, min(31, (int) $datos['dia_mes'])) : null,
                isset($datos['dia_semana']) ? max(1, min(7, (int) $datos['dia_semana'])) : null,
                $inicio,
                $datos['fecha_fin'] ?? null,
                $inicio,
            ]
        );

        Auditoria::registrar(
            'programada.creada',
            Auditoria::EXITO,
            $actorId,
            'orden_programada',
            $folio,
            ['tipo' => $tipo, 'frecuencia' => $frecuencia, 'modo' => $modo]
        );

        return ['folio' => $folio, 'proxima_en' => $inicio];
    }

    /**
     * El tope, que es opcional.
     *
     * Cero significa «sin techo», y `Monto` rechaza el cero a
     * propósito: un monto de cero no es un monto. Así que se mira
     * antes de convertirlo, en lugar de atrapar la excepción.
     */
    private function topeEnCentavos(mixed $valor): int
    {
        if ($valor === null || $valor === '' || $valor === '0') {
            return 0;
        }

        $limpio = trim((string) $valor);

        if ($limpio === '' || (float) str_replace(',', '', $limpio) <= 0) {
            return 0;
        }

        return Monto::deEntradaUsuario($limpio)->centavos;
    }

    /**
     * Comprueba el destino y devuelve las columnas que toca llenar.
     *
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    private function validarDestino(string $tipo, array $datos, int $actorId): array
    {
        $v = [
            'cuenta_destino_id' => null, 'beneficiario_id' => null,
            'convenio_id' => null, 'referencia' => '',
            'tarjeta_id' => null, 'credito_id' => null,
        ];

        switch ($tipo) {
            case 'transferencia':
                $id = (int) ($datos['cuenta_destino_id'] ?? 0);

                $existe = BaseDatos::unaFila(
                    'SELECT id FROM cuentas WHERE id = ? AND es_tecnica = 0 AND estado = "activa"',
                    [$id]
                );

                if ($existe === null) {
                    throw new ProgramadaInvalida('La cuenta destino no existe o no está activa.');
                }

                $v['cuenta_destino_id'] = $id;
                break;

            case 'interbancaria':
                /* Tiene que estar en la agenda Y haber pasado su
                   enfriamiento: una orden programada no puede saltarse
                   el control que protege las transferencias de una. */
                $b = BaseDatos::unaFila(
                    'SELECT id, estado, enfriamiento_hasta FROM beneficiarios
                      WHERE id = ? AND usuario_id = ?',
                    [(int) ($datos['beneficiario_id'] ?? 0), $actorId]
                );

                if ($b === null) {
                    throw new ProgramadaInvalida('Ese destino no está en su agenda.');
                }

                $v['beneficiario_id'] = (int) $b['id'];
                break;

            case 'servicio':
                $c = BaseDatos::unaFila(
                    'SELECT id FROM convenios_servicio WHERE id = ? AND activo = 1',
                    [(int) ($datos['convenio_id'] ?? 0)]
                );

                if ($c === null) {
                    throw new ProgramadaInvalida('Ese convenio no está disponible.');
                }

                $ref = \CashMan\Dominio\Colecturia\Colector::normalizarReferencia(
                    (string) ($datos['referencia'] ?? '')
                );

                if ($ref === '') {
                    throw new ProgramadaInvalida('Indique la referencia del servicio.');
                }

                $v['convenio_id'] = (int) $c['id'];
                $v['referencia']  = $ref;
                break;

            case 'tarjeta':
                $t = BaseDatos::unaFila(
                    'SELECT id FROM tarjetas WHERE id = ? AND usuario_id = ? AND estado <> "cancelada"',
                    [(int) ($datos['tarjeta_id'] ?? 0), $actorId]
                );

                if ($t === null) {
                    throw new ProgramadaInvalida('Esa tarjeta no es suya o está cancelada.');
                }

                $v['tarjeta_id'] = (int) $t['id'];
                break;

            case 'credito':
                $c = BaseDatos::unaFila(
                    'SELECT id FROM creditos WHERE id = ? AND usuario_id = ?',
                    [(int) ($datos['credito_id'] ?? 0), $actorId]
                );

                if ($c === null) {
                    throw new ProgramadaInvalida('Ese crédito no es suyo.');
                }

                $v['credito_id'] = (int) $c['id'];
                break;
        }

        return $v;
    }

    // =================================================================
    // Ejecución
    // =================================================================

    /**
     * Corre todo lo que toca hoy.
     *
     * @return array{intentadas:int, exitosas:int, sin_fondos:int, fallidas:int}
     */
    public function ejecutarPendientes(bool $aplicar = true): array
    {
        $r = ['intentadas' => 0, 'exitosas' => 0, 'sin_fondos' => 0, 'fallidas' => 0];

        $pendientes = BaseDatos::todas(
            'SELECT * FROM ordenes_programadas
              WHERE estado = "activa" AND proxima_en <= CURDATE()
              ORDER BY proxima_en, id'
        );

        foreach ($pendientes as $o) {
            $r['intentadas']++;

            if (!$aplicar) {
                continue;
            }

            $res = $this->ejecutarUna($o);

            if ($res === 'exitosa')        { $r['exitosas']++; }
            elseif ($res === 'sin_fondos') { $r['sin_fondos']++; }
            else                           { $r['fallidas']++; }
        }

        return $r;
    }

    /**
     * Una orden concreta.
     *
     * @param array<string,mixed> $o
     */
    public function ejecutarUna(array $o): string
    {
        $ordenId  = (int) $o['id'];
        $prevista = (string) $o['proxima_en'];

        /* ¿Ya se hizo? El UNIQUE lo impediría, pero preguntarlo antes
           evita gastar un intento y ensuciar la bitácora. */
        $yaHecha = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM ordenes_ejecuciones
              WHERE orden_id = ? AND prevista_para = ? AND resultado = "exitosa"',
            [$ordenId, $prevista]
        );

        if ($yaHecha > 0) {
            $this->avanzar($o);
            return 'omitida';
        }

        // --- Cuánto -------------------------------------------------
        try {
            $montoCent = $this->montoDe($o);
        } catch (\Throwable $e) {
            $this->anotar($ordenId, $prevista, 'error', 0, $e->getMessage());
            return 'error';
        }

        if ($montoCent <= 0) {
            /* No hay nada que pagar este periodo —la tarjeta quedó en
               cero, el crédito no tiene cuota— y eso no es un fallo.
               Se anota y se pasa al siguiente. */
            $this->anotar($ordenId, $prevista, 'omitida', 0, 'No había nada que pagar este periodo.');
            $this->avanzar($o);
            return 'omitida';
        }

        $tope = (int) $o['tope_cent'];

        if ($tope > 0 && $montoCent > $tope) {
            /* El techo protege de un cargo inesperado. No se recorta el
               pago: se avisa y se deja que el cliente decida, porque
               pagar la mitad de una tarjeta igual deja mora. */
            $this->anotar(
                $ordenId, $prevista, 'omitida', $montoCent,
                'El monto supera el tope que usted puso ('
                . Monto::deCentavos($tope)->formateado() . ').'
            );

            $this->notificaciones->crear(
                (int) $o['usuario_id'],
                'programada.sobre_tope',
                'Una orden programada no se ejecutó',
                'La orden ' . $o['folio'] . ' pedía '
                . Monto::deCentavos($montoCent)->formateado()
                . ', por encima del tope de ' . Monto::deCentavos($tope)->formateado()
                . ' que usted fijó. No se cobró nada: revísela.',
                ['folio' => $o['folio']],
                'alerta'
            );

            $this->avanzar($o);
            return 'omitida';
        }

        // --- Hacerlo -------------------------------------------------
        try {
            $hecho = $this->despachar($o, $montoCent);
        } catch (FondosInsuficientes $e) {
            return $this->tratarSinFondos($o, $montoCent, $e->getMessage());
        } catch (\Throwable $e) {
            $this->anotar($ordenId, $prevista, 'rechazada', $montoCent, $e->getMessage());

            $this->notificaciones->crear(
                (int) $o['usuario_id'],
                'programada.fallo',
                'Una orden programada falló',
                'La orden ' . $o['folio'] . ' no se pudo ejecutar: ' . $e->getMessage(),
                ['folio' => $o['folio']],
                'alerta'
            );

            $this->avanzar($o);
            return 'error';
        }

        $this->anotar(
            $ordenId, $prevista, 'exitosa', $montoCent,
            (string) ($hecho['detalle'] ?? ''),
            $hecho['asiento_id'] ?? null,
            (string) ($hecho['referencia'] ?? '')
        );

        BaseDatos::ejecutar(
            'UPDATE ordenes_programadas
                SET ejecuciones = ejecuciones + 1, ultima_en = NOW(),
                    reintentos_hechos = 0, fallos_consecutivos = 0
              WHERE id = ?',
            [$ordenId]
        );

        $this->notificaciones->crear(
            (int) $o['usuario_id'],
            'programada.ejecutada',
            'Se ejecutó una orden programada',
            ($o['alias'] !== '' ? $o['alias'] : 'La orden ' . $o['folio'])
            . ': se cargaron ' . Monto::deCentavos($montoCent)->formateado()
            . ' a su cuenta. ' . ($hecho['detalle'] ?? ''),
            ['folio' => $o['folio']],
            'exito'
        );

        $this->avanzar($o);

        return 'exitosa';
    }

    /**
     * Qué hacer cuando no alcanzó el dinero.
     *
     * Reintentar, no rendirse: que no haya fondos el día 5 no
     * significa que no los haya el 6, y rendirse al primer intento
     * convierte un descuido de un día en una mora de un mes.
     *
     * @param array<string,mixed> $o
     */
    private function tratarSinFondos(array $o, int $montoCent, string $detalle): string
    {
        $ordenId  = (int) $o['id'];
        $prevista = (string) $o['proxima_en'];

        $hechos = (int) $o['reintentos_hechos'] + 1;
        $max    = (int) $o['reintentos_max'];

        $this->anotar($ordenId, $prevista, 'sin_fondos', $montoCent, $detalle);

        if ($hechos < $max) {
            /* Mañana otra vez. La fecha prevista NO cambia: lo que se
               reintenta es el cargo de ESE día, y por eso no cuenta
               como una ejecución nueva. */
            BaseDatos::ejecutar(
                'UPDATE ordenes_programadas
                    SET reintentos_hechos = ?, proxima_en = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                  WHERE id = ?',
                [$hechos, $ordenId]
            );

            $this->notificaciones->crear(
                (int) $o['usuario_id'],
                'programada.sin_fondos',
                'No se pudo cobrar una orden programada',
                ($o['alias'] !== '' ? $o['alias'] : 'La orden ' . $o['folio'])
                . ' necesitaba ' . Monto::deCentavos($montoCent)->formateado()
                . ' y no había saldo. Lo intentaremos mañana otra vez ('
                . ($max - $hechos) . ' intento(s) más).',
                ['folio' => $o['folio']],
                'alerta'
            );

            return 'sin_fondos';
        }

        /* Se agotaron los intentos: se pasa al periodo siguiente y se
           dice con claridad qué hay que hacer. Dejar la orden
           reintentando para siempre la convertiría en un cargo
           sorpresa semanas después. */
        BaseDatos::ejecutar(
            'UPDATE ordenes_programadas
                SET reintentos_hechos = 0, fallos_consecutivos = fallos_consecutivos + 1
              WHERE id = ?',
            [$ordenId]
        );

        $this->notificaciones->crear(
            (int) $o['usuario_id'],
            'programada.agotada',
            'Una orden programada no se pudo cobrar',
            ($o['alias'] !== '' ? $o['alias'] : 'La orden ' . $o['folio'])
            . ' no se pudo cobrar en ' . $max . ' intentos por falta de saldo. '
            . 'Este periodo queda sin pagar: hágalo a mano si hace falta. '
            . 'La orden sigue activa para el periodo siguiente.',
            ['folio' => $o['folio']],
            'critico'
        );

        $this->avanzar($o);

        return 'sin_fondos';
    }

    /**
     * Cuánto cobrar en esta ejecución.
     *
     * @param array<string,mixed> $o
     */
    private function montoDe(array $o): int
    {
        if ($o['modo_monto'] === 'fijo') {
            return (int) $o['monto_cent'];
        }

        if ($o['tipo'] === 'tarjeta') {
            return $this->abonos->montoSegunModo(
                (int) $o['tarjeta_id'],
                (string) $o['modo_monto']
            );
        }

        if ($o['tipo'] === 'credito') {
            /* La cuota vencida más próxima sin pagar. «Total» en un
               crédito es la cuota del periodo, no el saldo entero:
               pagar el saldo entero de un crédito es una cancelación
               anticipada, que es otra operación con otras reglas. */
            return (int) (BaseDatos::unValor(
                'SELECT (total_cent - pagado_cent) FROM cuotas
                  WHERE credito_id = ? AND pagada_en IS NULL
                  ORDER BY fecha_vencimiento LIMIT 1',
                [(int) $o['credito_id']]
            ) ?? 0);
        }

        return 0;
    }

    /**
     * Hace la operación que corresponde al tipo.
     *
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    private function despachar(array $o, int $montoCent): array
    {
        $monto    = Monto::deCentavos($montoCent);
        $origenId = (int) $o['cuenta_origen_id'];
        $usuario  = (int) $o['usuario_id'];

        /* La clave de idempotencia ata el cargo a la orden Y a la
           fecha prevista: un reintento del mismo día no puede cobrar
           dos veces aunque algo falle entre medias. */
        $clave = 'ord:' . $o['folio'] . ':' . $o['proxima_en'];

        switch ($o['tipo']) {
            case 'tarjeta':
                $a = $this->abonos->abonar(
                    (int) $o['tarjeta_id'],
                    $origenId,
                    $monto,
                    null,
                    'Débito automático · ' . $o['folio'],
                    $clave
                );

                return [
                    'asiento_id' => $a['asiento_id'] ?? null,
                    'referencia' => (string) ($a['referencia'] ?? ''),
                    'detalle'    => 'Se abonaron a su tarjeta.',
                ];

            case 'transferencia':
                return $this->transferirInterno(
                    $origenId,
                    (int) $o['cuenta_destino_id'],
                    $monto,
                    (string) $o['concepto'],
                    $clave,
                    $usuario
                );

            default:
                /* Los demás tipos —interbancaria, servicio, crédito—
                 * necesitan la sesión del cliente para pasar por sus
                 * propios controles (agenda, límites, OTP), y un
                 * barrido no tiene sesión.
                 *
                 * Se dice así de claro en lugar de inventar un atajo
                 * que se saltara esos controles: el día que haga falta,
                 * lo correcto es que esos servicios acepten actuar en
                 * nombre del sistema, no que éste los rodee. */
                throw new \RuntimeException(
                    'El tipo «' . $o['tipo'] . '» todavía no se ejecuta automáticamente.'
                );
        }
    }

    /**
     * Transferencia entre cuentas del banco, hecha por el sistema.
     *
     * @return array<string,mixed>
     */
    private function transferirInterno(
        int $origenId,
        int $destinoId,
        Monto $monto,
        string $concepto,
        string $clave,
        int $usuario
    ): array {
        return BaseDatos::enTransaccion(static function () use (
            $origenId, $destinoId, $monto, $concepto, $clave
        ): array {
            $bloq = LibroMayor::bloquearCuentas([$origenId, $destinoId]);

            $o = $bloq[$origenId] ?? null;
            $d = $bloq[$destinoId] ?? null;

            if ($o === null || $d === null) {
                throw new \RuntimeException('No se pudo bloquear alguna de las cuentas.');
            }

            if ($d['estado'] !== 'activa') {
                throw new \RuntimeException('La cuenta destino ya no puede recibir fondos.');
            }

            $disponible = (int) $o['saldo_cent']
                        - (int) $o['saldo_retenido_cent']
                        - (int) $o['saldo_minimo_cent'];

            if ($disponible < $monto->centavos) {
                throw new FondosInsuficientes(
                    'Disponible: ' . Monto::deCentavos(max(0, $disponible))->formateado()
                );
            }

            $asiento = LibroMayor::registrar(
                'programada',
                [
                    ['cuenta_id' => $origenId, 'importe' => $monto->negado(),
                     'descripcion' => 'Transferencia programada a ' . $d['numero_cuenta']],
                    ['cuenta_id' => $destinoId, 'importe' => $monto,
                     'descripcion' => 'Transferencia programada de ' . $o['numero_cuenta']],
                ],
                null,
                $clave,
                $concepto !== '' ? $concepto : 'Transferencia programada'
            );

            return [
                'asiento_id' => $asiento['asiento_id'],
                'referencia' => $asiento['referencia'],
                'detalle'    => 'Se transfirieron a la cuenta ' . $d['numero_cuenta'] . '.',
            ];
        });
    }

    // =================================================================
    // Calendario
    // =================================================================

    /**
     * Mueve la orden a su siguiente fecha.
     *
     * @param array<string,mixed> $o
     */
    private function avanzar(array $o): void
    {
        if ($o['frecuencia'] === 'unica') {
            BaseDatos::ejecutar(
                'UPDATE ordenes_programadas
                    SET estado = "terminada", motivo_fin = "Orden de una sola vez, ya ejecutada."
                  WHERE id = ?',
                [(int) $o['id']]
            );

            return;
        }

        $proxima = self::siguienteFecha(
            (string) $o['proxima_en'],
            (string) $o['frecuencia'],
            $o['dia_mes'] !== null ? (int) $o['dia_mes'] : null
        );

        /* ¿Se pasó de la fecha de fin? Entonces ya terminó. */
        if ($o['fecha_fin'] !== null && $proxima > (string) $o['fecha_fin']) {
            BaseDatos::ejecutar(
                'UPDATE ordenes_programadas
                    SET estado = "terminada", motivo_fin = "Llegó a su fecha de fin."
                  WHERE id = ?',
                [(int) $o['id']]
            );

            return;
        }

        BaseDatos::ejecutar(
            'UPDATE ordenes_programadas SET proxima_en = ?, reintentos_hechos = 0 WHERE id = ?',
            [$proxima, (int) $o['id']]
        );
    }

    /**
     * La siguiente fecha según la frecuencia.
     *
     * EL DÍA 31 EN FEBRERO
     *
     * Quien programa un cargo para el 31 quiere decir «el último día
     * del mes». PHP, sumando un mes al 31 de enero, da el 3 de marzo:
     * se salta febrero entero. Por eso los meses se calculan sobre el
     * día 1 y el día se fija después, recortado al último del mes.
     */
    public static function siguienteFecha(string $desde, string $frecuencia, ?int $diaMes): string
    {
        $f = new \DateTimeImmutable($desde);

        $meses = match ($frecuencia) {
            'mensual'    => 1,
            'bimestral'  => 2,
            'trimestral' => 3,
            'semestral'  => 6,
            'anual'      => 12,
            default      => 0,
        };

        if ($meses === 0) {
            return match ($frecuencia) {
                'semanal'   => $f->modify('+7 days')->format('Y-m-d'),
                'quincenal' => $f->modify('+15 days')->format('Y-m-d'),
                default     => $f->modify('+1 month')->format('Y-m-d'),
            };
        }

        $dia = $diaMes ?? (int) $f->format('j');

        /* Sobre el día 1, para que sumar meses no se salte ninguno. */
        $base = $f->modify('first day of this month')->modify('+' . $meses . ' months');

        $ultimo = (int) $base->format('t');

        return $base->setDate(
            (int) $base->format('Y'),
            (int) $base->format('n'),
            min($dia, $ultimo)
        )->format('Y-m-d');
    }

    // =================================================================
    // Administración
    // =================================================================

    public function pausar(string $folio, int $usuarioId): void
    {
        $o = $this->exigirMia($folio, $usuarioId);

        BaseDatos::ejecutar(
            'UPDATE ordenes_programadas SET estado = "pausada" WHERE id = ?',
            [(int) $o['id']]
        );

        Auditoria::registrar('programada.pausada', Auditoria::EXITO, $usuarioId,
            'orden_programada', $folio);
    }

    public function reanudar(string $folio, int $usuarioId): void
    {
        $o = $this->exigirMia($folio, $usuarioId);

        if ($o['estado'] !== 'pausada') {
            throw new ProgramadaInvalida('Esa orden no está pausada.');
        }

        /* Al reanudar, la próxima fecha se recalcula desde HOY: si
           estuvo pausada tres meses, no se cobran los tres de golpe. */
        $proxima = max(date('Y-m-d'), (string) $o['proxima_en']);

        BaseDatos::ejecutar(
            'UPDATE ordenes_programadas
                SET estado = "activa", proxima_en = ?, reintentos_hechos = 0
              WHERE id = ?',
            [$proxima, (int) $o['id']]
        );

        Auditoria::registrar('programada.reanudada', Auditoria::EXITO, $usuarioId,
            'orden_programada', $folio);
    }

    public function cancelar(string $folio, int $usuarioId, string $motivo = ''): void
    {
        $o = $this->exigirMia($folio, $usuarioId);

        BaseDatos::ejecutar(
            'UPDATE ordenes_programadas
                SET estado = "cancelada", cancelada_en = NOW(), motivo_fin = ?
              WHERE id = ?',
            [mb_substr($motivo !== '' ? $motivo : 'Cancelada por el cliente.', 0, 255),
             (int) $o['id']]
        );

        Auditoria::registrar('programada.cancelada', Auditoria::EXITO, $usuarioId,
            'orden_programada', $folio, ['motivo' => $motivo]);
    }

    // =================================================================
    // Consulta
    // =================================================================

    /** @return array<int,array<string,mixed>> */
    public function deUsuario(int $usuarioId): array
    {
        $filas = BaseDatos::todas(
            'SELECT o.*, c.numero_cuenta AS origen_cuenta,
                    cd.numero_cuenta AS destino_cuenta,
                    cv.nombre_corto AS convenio,
                    b.alias AS beneficiario,
                    t.ultimos4 AS tarjeta
               FROM ordenes_programadas o
               JOIN cuentas c ON c.id = o.cuenta_origen_id
               LEFT JOIN cuentas cd ON cd.id = o.cuenta_destino_id
               LEFT JOIN convenios_servicio cv ON cv.id = o.convenio_id
               LEFT JOIN beneficiarios b ON b.id = o.beneficiario_id
               LEFT JOIN tarjetas t ON t.id = o.tarjeta_id
              WHERE o.usuario_id = ?
              ORDER BY FIELD(o.estado, "activa", "pausada", "terminada", "cancelada"),
                       o.proxima_en',
            [$usuarioId]
        );

        foreach ($filas as &$f) {
            $f['ultimas'] = BaseDatos::todas(
                'SELECT prevista_para, resultado, monto_cent, detalle, intentada_en
                   FROM ordenes_ejecuciones
                  WHERE orden_id = ?
                  ORDER BY intentada_en DESC LIMIT 5',
                [(int) $f['id']]
            );
        }

        return $filas;
    }

    /** @return array<string,mixed> */
    private function exigirMia(string $folio, int $usuarioId): array
    {
        $o = BaseDatos::unaFila(
            'SELECT * FROM ordenes_programadas WHERE folio = ?',
            [$folio]
        );

        if ($o === null) {
            throw new ProgramadaInvalida('No existe esa orden.');
        }

        if ((int) $o['usuario_id'] !== $usuarioId
            && !Autorizacion::puede('programada.ver_todas')) {
            throw new \CashMan\Infraestructura\Seguridad\AccesoDenegado('Esa orden no es suya.');
        }

        return $o;
    }

    private function anotar(
        int $ordenId,
        string $prevista,
        string $resultado,
        int $montoCent,
        string $detalle,
        ?int $asientoId = null,
        string $referencia = ''
    ): void {
        try {
            BaseDatos::ejecutar(
                'INSERT INTO ordenes_ejecuciones
                   (orden_id, prevista_para, resultado, monto_cent, detalle,
                    asiento_id, referencia)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $ordenId, $prevista, $resultado, $montoCent,
                    mb_substr($detalle, 0, 255), $asientoId, $referencia,
                ]
            );
        } catch (\Throwable $e) {
            /* El UNIQUE puede rechazar un duplicado —dos barridos a la
               vez— y eso es exactamente lo que tiene que hacer. No se
               convierte en un error de la ejecución. */
            error_log('[Programadas] ' . $e->getMessage());
        }
    }

    // =================================================================
    // El débito automático de tarjetas que nadie ejecutaba
    // =================================================================

    /**
     * Crea las órdenes que faltaban para las tarjetas domiciliadas.
     *
     * `tarjetas.pago_automatico` llevaba tiempo configurado por 35
     * clientes sin que nada lo ejecutara. Esto convierte esa
     * configuración en órdenes de verdad, sin que el cliente tenga que
     * volver a pedirlo: ya lo pidió.
     *
     * Es idempotente: una tarjeta que ya tiene su orden no genera otra.
     *
     * @return array{creadas:int, revisadas:int}
     */
    public function sincronizarDebitoDeTarjetas(bool $aplicar = true): array
    {
        $tarjetas = BaseDatos::todas(
            'SELECT t.id, t.usuario_id, t.ultimos4, t.pago_automatico,
                    t.pago_automatico_cuenta_id, t.dia_corte, t.dias_para_pago
               FROM tarjetas t
              WHERE t.pago_automatico IN ("minimo", "total")
                AND t.pago_automatico_cuenta_id IS NOT NULL
                AND t.estado <> "cancelada"'
        );

        $creadas = 0;

        foreach ($tarjetas as $t) {
            $ya = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM ordenes_programadas
                  WHERE tarjeta_id = ? AND estado IN ("activa", "pausada")',
                [(int) $t['id']]
            );

            if ($ya > 0) {
                continue;
            }

            if (!$aplicar) {
                $creadas++;
                continue;
            }

            /* El día del cargo: la fecha límite de pago, que es el día
               de corte más los días de gracia. Cobrar el día del corte
               sería cobrar antes de que el cliente sepa cuánto debe. */
            $dia = ((int) $t['dia_corte'] + (int) $t['dias_para_pago']) % 28;
            $dia = $dia === 0 ? 28 : $dia;

            $folio = Folio::unico('ORD', 'ordenes_programadas', 'folio', 'ymd', 5);

            $proxima = self::siguienteFecha(date('Y-m-d'), 'mensual', $dia);

            BaseDatos::ejecutar(
                'INSERT INTO ordenes_programadas
                   (uuid, folio, usuario_id, alias, tipo, cuenta_origen_id,
                    tarjeta_id, modo_monto, monto_cent, concepto,
                    frecuencia, dia_mes, fecha_inicio, proxima_en, estado)
                 VALUES (?, ?, ?, ?, "tarjeta", ?, ?, ?, 0, ?, "mensual", ?, CURDATE(), ?, "activa")',
                [
                    LibroMayor::uuid4(), $folio, (int) $t['usuario_id'],
                    'Pago automático tarjeta ••••' . $t['ultimos4'],
                    (int) $t['pago_automatico_cuenta_id'], (int) $t['id'],
                    (string) $t['pago_automatico'],
                    'Débito automático de tarjeta',
                    $dia, $proxima,
                ]
            );

            $creadas++;
        }

        return ['creadas' => $creadas, 'revisadas' => count($tarjetas)];
    }
}
