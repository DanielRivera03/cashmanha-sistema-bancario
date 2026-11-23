<?php
declare(strict_types=1);

namespace CashMan\Dominio\Comun;

use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\ClienteIp;

/**
 * Libro mayor de partida doble.
 *
 * Todo movimiento de dinero del sistema pasa por aquí. No hay otra
 * forma de alterar un saldo.
 *
 * El sistema anterior sumaba y restaba `montocuenta` desde triggers de
 * MySQL, sin validar fondos y sin dejar rastro de quién lo hizo. Un
 * monto negativo invertía la operación y extraía dinero de la cuenta
 * destino.
 *
 * Aquí:
 *   - Cada asiento se compone de movimientos que SUMAN CERO.
 *   - Nada se actualiza ni se borra: una corrección es un asiento nuevo.
 *   - Los asientos se encadenan por hash: alterar uno rompe la cadena.
 *   - El saldo de la cuenta es una proyección, verificable contra la
 *     suma de sus movimientos en cualquier momento.
 */
final class LibroMayor
{
    /**
     * Registra un asiento con sus movimientos.
     *
     * DEBE llamarse dentro de una transacción con las cuentas ya
     * bloqueadas (SELECT ... FOR UPDATE). Si no, dos operaciones
     * simultáneas sobre la misma cuenta pueden dejar el saldo mal.
     *
     * @param array<int,array{cuenta_id:int, importe:Monto, descripcion?:string}> $movimientos
     * @return array{asiento_id:int, referencia:string, uuid:string}
     */
    public static function registrar(
        string $tipo,
        array $movimientos,
        // Nulo cuando el asiento lo origina el sistema y no una
        // persona: una compra de invitado, el devengo de mora de
        // madrugada, un reverso automatico. Poner ahi el id de un
        // administrador diria que esa persona lo registro.
        ?int $creadoPor,
        string $claveIdempotencia,
        string $descripcion = '',
        ?int $reversaA = null
    ): array {
        if (!BaseDatos::hayTransaccionActiva()) {
            throw new \LogicException(
                'LibroMayor::registrar debe ejecutarse dentro de una transacción.'
            );
        }

        if (count($movimientos) < 2) {
            throw new \InvalidArgumentException(
                'Un asiento necesita al menos dos movimientos (partida doble).'
            );
        }

        // --- Invariante: la suma debe ser exactamente cero ------------
        $suma   = 0;
        $moneda = null;
        foreach ($movimientos as $m) {
            $suma += $m['importe']->centavos;
            $moneda ??= $m['importe']->moneda;
            if ($m['importe']->moneda !== $moneda) {
                throw new \InvalidArgumentException('Un asiento no puede mezclar monedas.');
            }
            if ($m['importe']->esCero()) {
                throw new \InvalidArgumentException('Un movimiento no puede ser de importe cero.');
            }
        }

        if ($suma !== 0) {
            throw new AsientoDescuadrado(
                "El asiento no cuadra: la suma de los movimientos es {$suma} centavos, debe ser 0."
            );
        }

        // --- Idempotencia --------------------------------------------
        // Si esta clave ya se procesó, se devuelve el asiento original
        // en lugar de duplicar la operación. Resuelve el doble clic,
        // el reintento por timeout y el F5 sobre un POST.
        $previo = BaseDatos::unaFila(
            'SELECT id, referencia, uuid FROM asientos WHERE clave_idempotencia = ?',
            [$claveIdempotencia]
        );
        if ($previo !== null) {
            return [
                'asiento_id' => (int) $previo['id'],
                'referencia' => (string) $previo['referencia'],
                'uuid'       => (string) $previo['uuid'],
                'duplicado'  => true,
            ];
        }

        // --- Cadena de integridad ------------------------------------
        $hashAnterior = (string) (BaseDatos::unValor(
            'SELECT hash_actual FROM asientos ORDER BY id DESC LIMIT 1'
        ) ?: Config::obtener('AUDITORIA_SEMILLA', str_repeat('0', 64)));

        $referencia = self::generarReferencia($tipo);
        $uuid       = self::uuid4();
        $ip         = ClienteIp::binaria();

        $contenido = implode('|', [
            $hashAnterior,
            $uuid,
            $tipo,
            $referencia,
            $claveIdempotencia,
            (string) $creadoPor,
            self::huellaMovimientos($movimientos),
        ]);
        $hashActual = hash('sha256', $contenido);

        BaseDatos::ejecutar(
            'INSERT INTO asientos
               (uuid, clave_idempotencia, tipo, referencia, descripcion,
                creado_por, ip_origen, reversa_a, hash_anterior, hash_actual)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$uuid, $claveIdempotencia, $tipo, $referencia, $descripcion,
             $creadoPor, $ip, $reversaA, $hashAnterior, $hashActual]
        );

        $asientoId = BaseDatos::ultimoId();

        // --- Aplicar movimientos y proyectar saldos -------------------
        foreach ($movimientos as $m) {
            $cuentaId = $m['cuenta_id'];
            $importe  = $m['importe'];

            // La cuenta ya debe estar bloqueada por quien llama.
            $saldoActual = (int) BaseDatos::unValor(
                'SELECT saldo_cent FROM cuentas WHERE id = ?',
                [$cuentaId]
            );
            $saldoNuevo = $saldoActual + $importe->centavos;

            BaseDatos::ejecutar(
                'INSERT INTO movimientos
                   (asiento_id, cuenta_id, importe_cent, moneda, saldo_posterior_cent, descripcion)
                 VALUES (?,?,?,?,?,?)',
                [$asientoId, $cuentaId, $importe->centavos, $importe->moneda,
                 $saldoNuevo, $m['descripcion'] ?? $descripcion]
            );

            // Proyección del saldo. El CHECK de la tabla rechaza el
            // negativo en cuentas de cliente: última red de seguridad.
            BaseDatos::ejecutar(
                'UPDATE cuentas SET saldo_cent = ? WHERE id = ?',
                [$saldoNuevo, $cuentaId]
            );
        }

        return [
            'asiento_id' => $asientoId,
            'referencia' => $referencia,
            'uuid'       => $uuid,
            'duplicado'  => false,
        ];
    }

    /**
     * Bloquea cuentas para actualización, SIEMPRE en orden de id.
     *
     * El orden determinista es lo que evita el interbloqueo: si una
     * transferencia bloquea A→B y otra simultánea bloquea B→A, ambas
     * se quedan esperando a la otra para siempre. Ordenando por id,
     * todas las transacciones piden los bloqueos en la misma secuencia.
     *
     * @param array<int,int> $cuentaIds
     * @return array<int,array<string,mixed>> Indexado por id de cuenta
     */
    public static function bloquearCuentas(array $cuentaIds): array
    {
        if (!BaseDatos::hayTransaccionActiva()) {
            throw new \LogicException('bloquearCuentas requiere una transacción activa.');
        }

        $ids = array_values(array_unique(array_map('intval', $cuentaIds)));
        sort($ids, SORT_NUMERIC);

        if ($ids === []) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($ids), '?'));

        $filas = BaseDatos::todas(
            "SELECT c.*, p.saldo_minimo_cent, p.nombre AS producto_nombre, p.tipo AS producto_tipo
               FROM cuentas c
               JOIN productos p ON p.id = c.producto_id
              WHERE c.id IN ({$marcadores})
              ORDER BY c.id
              FOR UPDATE",
            $ids
        );

        $mapa = [];
        foreach ($filas as $f) {
            $mapa[(int) $f['id']] = $f;
        }

        return $mapa;
    }

    /** Saldo real según el libro mayor, no la proyección. */
    public static function saldoReal(int $cuentaId): Monto
    {
        $cent = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(importe_cent), 0) FROM movimientos WHERE cuenta_id = ?',
            [$cuentaId]
        );
        return Monto::deCentavos($cent);
    }

    /**
     * Verifica que la proyección coincida con el libro.
     *
     * @return array{conciliado:bool, proyectado:int, libro:int, diferencia:int}
     */
    public static function conciliar(int $cuentaId): array
    {
        $fila = BaseDatos::unaFila(
            'SELECT saldo_proyectado_cent, saldo_libro_cent, diferencia_cent
               FROM v_conciliacion_saldos WHERE cuenta_id = ?',
            [$cuentaId]
        );

        if ($fila === null) {
            return ['conciliado' => false, 'proyectado' => 0, 'libro' => 0, 'diferencia' => 0];
        }

        return [
            'conciliado' => ((int) $fila['diferencia_cent']) === 0,
            'proyectado' => (int) $fila['saldo_proyectado_cent'],
            'libro'      => (int) $fila['saldo_libro_cent'],
            'diferencia' => (int) $fila['diferencia_cent'],
        ];
    }

    /** Totales de cargos y abonos de una cuenta en un periodo. */
    public static function totalesPeriodo(int $cuentaId, string $desde, string $hasta): array
    {
        $f = BaseDatos::unaFila(
            'SELECT
               COALESCE(SUM(CASE WHEN importe_cent < 0 THEN -importe_cent ELSE 0 END), 0) AS cargos,
               COALESCE(SUM(CASE WHEN importe_cent > 0 THEN  importe_cent ELSE 0 END), 0) AS abonos,
               COUNT(*) AS cantidad
             FROM movimientos
            WHERE cuenta_id = ? AND creado_en >= ? AND creado_en < DATE_ADD(?, INTERVAL 1 DAY)',
            [$cuentaId, $desde, $hasta]
        );

        return [
            'cargos'   => Monto::deCentavos((int) ($f['cargos'] ?? 0)),
            'abonos'   => Monto::deCentavos((int) ($f['abonos'] ?? 0)),
            'cantidad' => (int) ($f['cantidad'] ?? 0),
        ];
    }

    private static function generarReferencia(string $tipo): string
    {
        $prefijo = match ($tipo) {
            'transferencia'   => 'TRF',
            'deposito'        => 'DEP',
            'retiro'          => 'RET',
            'pago_cuota'      => 'PGC',
            'consumo_tarjeta' => 'CST',
            'pago_tarjeta'    => 'PGT',
            'interes'         => 'INT',
            'mora'            => 'MOR',
            'comision'        => 'COM',
            'reverso'         => 'REV',
            default           => 'AJU',
        };

        return Folio::unico($prefijo, 'asientos', 'referencia', 'ymd', 5);
    }

    /** @param array<int,array{cuenta_id:int, importe:Monto}> $movimientos */
    private static function huellaMovimientos(array $movimientos): string
    {
        $partes = [];
        foreach ($movimientos as $m) {
            $partes[] = $m['cuenta_id'] . ':' . $m['importe']->centavos;
        }
        sort($partes);
        return implode(',', $partes);
    }

    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
