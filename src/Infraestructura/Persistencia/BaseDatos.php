<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Persistencia;

use CashMan\Infraestructura\Config;
use PDO;
use PDOException;

/**
 * Conexión única a la base de datos.
 *
 * Reemplaza las 8 conexiones mysqli que el sistema anterior abría
 * en CADA petición. Aquí hay una sola, perezosa y compartida.
 *
 * Reglas:
 *   - EMULATE_PREPARES = false: las sentencias preparadas son REALES,
 *     procesadas por el servidor. Esto es lo que hace imposible la
 *     inyección SQL, no el escapado manual.
 *   - ERRMODE_EXCEPTION: ningún error pasa desapercibido.
 *   - Sin STRINGIFY_FETCHES: los enteros llegan como enteros, lo que
 *     importa cuando se manejan centavos.
 */
final class BaseDatos
{
    private static ?PDO $pdo = null;
    private static int $nivelTransaccion = 0;

    public static function conexion(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host    = Config::obtener('DB_HOST', '127.0.0.1');
        $puerto  = Config::entero('DB_PUERTO', 3306);
        $nombre  = Config::exigir('DB_NOMBRE');
        $charset = Config::obtener('DB_CHARSET', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$puerto};dbname={$nombre};charset={$charset}";

        /* La conexión se intenta varias veces. En Windows, cuando el
           servidor arranca o su cola de conexiones se llena bajo carga
           (p. ej. toda la batería de pruebas de golpe), el intento se
           rechaza con «[2002] el equipo de destino denegó la conexión» y
           al instante siguiente ya no ocurre; en un hosting compartido
           pasa igual con picos momentáneos. Un solo intento convertía ese
           parpadeo en un error duro. Solo se reintentan los fallos
           TRANSITORIOS: una clave mala o una base inexistente no se
           arreglan esperando, así que esos se lanzan de una. */
        $intentos    = 0;
        $maxIntentos = max(1, Config::entero('DB_REINTENTOS_CONEXION', 3));

        while (true) {
            try {
                self::$pdo = new PDO(
                    $dsn,
                    Config::obtener('DB_USUARIO', 'root'),
                    Config::obtener('DB_CLAVE', ''),
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        // Crítico: sentencias preparadas reales, no emuladas.
                        PDO::ATTR_EMULATE_PREPARES   => false,
                        PDO::ATTR_STRINGIFY_FETCHES  => false,
                        PDO::ATTR_PERSISTENT         => false,
                        PDO::ATTR_TIMEOUT            => 5,
                    ]
                );

                // Modo estricto: que la base rechace datos inválidos en lugar
                // de truncarlos en silencio, que es como se corrompen los saldos.
                self::$pdo->exec(
                    "SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'"
                );
                // Sintaxis SQL estándar: MySQL usa 'transaction_isolation' y
                // MariaDB 'tx_isolation', pero ambos aceptan esta forma.
                self::$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
                self::$pdo->exec("SET SESSION time_zone = '" . self::desplazamientoHorario() . "'");

                return self::$pdo;
            } catch (PDOException $e) {
                self::$pdo = null;
                $intentos++;

                if ($intentos < $maxIntentos && self::esFalloTransitorio($e)) {
                    usleep(150000 * $intentos); // 150 ms, 300 ms, …
                    continue;
                }

                // Nunca exponer credenciales ni el DSN al cliente.
                error_log('[BaseDatos] Fallo de conexión: ' . $e->getMessage());
                throw new \RuntimeException('No se pudo conectar con la base de datos.', 0, $e);
            }
        }
    }

    /**
     * ¿El fallo al CONECTAR es un parpadeo del que vale la pena reponerse
     * (servidor arrancando, cola llena, corte momentáneo) y no un
     * problema que esperar no arregla (clave mala, base que no existe)?
     */
    private static function esFalloTransitorio(\PDOException $e): bool
    {
        $codigo = (int) ($e->errorInfo[1] ?? 0);

        // 2002 no conecta · 2003 no alcanza al servidor · 2006/2013 caída.
        if (in_array($codigo, [2002, 2003, 2006, 2013], true)) {
            return true;
        }

        $msg = strtolower($e->getMessage());

        return str_contains($msg, '2002')
            || str_contains($msg, 'refused')
            || str_contains($msg, 'denegó')   // Windows en español
            || str_contains($msg, 'denego')
            || str_contains($msg, 'gone away')
            || str_contains($msg, 'lost connection')
            || str_contains($msg, 'timed out')
            || str_contains($msg, 'try again');
    }

    private static function desplazamientoHorario(): string
    {
        $tz  = new \DateTimeZone(Config::obtener('APP_ZONA_HORARIA', 'UTC'));
        $off = $tz->getOffset(new \DateTimeImmutable('now', $tz));
        $signo = $off < 0 ? '-' : '+';
        $off = abs($off);
        return sprintf('%s%02d:%02d', $signo, intdiv($off, 3600), intdiv($off % 3600, 60));
    }

    /**
     * Ejecuta una consulta.
     *
     * Los fallos de PDO NO salen de aquí tal cual.
     *
     * `PDOException` extiende `RuntimeException`, y los controladores
     * capturan `RuntimeException` para enseñar su mensaje al cliente
     * —esas excepciones llevan textos escritos para él—. El resultado
     * era que un tropiezo de la base aterrizaba en la pantalla del
     * cliente con su forma original:
     *
     *     SQLSTATE[23000]: Integrity constraint violation: 1048
     *     Column 'cargo' cannot be null
     *
     * Con eso nadie puede hacer nada, y de paso regala el motor, el
     * nombre de la columna y la restricción a quien esté probando qué
     * rompe.
     *
     * Así que se cambia por `FalloDeBaseDatos`, que dice lo mismo en
     * castellano y guarda el original en el registro. Sigue siendo una
     * `RuntimeException`, así que los `catch` de siempre funcionan.
     *
     * @param array<string|int,mixed> $parametros
     */
    public static function consultar(string $sql, array $parametros = []): \PDOStatement
    {
        /* Las vistas se inlinean aquí, en el único punto por el que
           pasan todas las consultas: así la app no depende de que la
           base tenga las vistas (los hostings gratis niegan CREATE
           VIEW). El resultado es idéntico; ver FuenteVistas. */
        $sql = FuenteVistas::expandir($sql);

        try {
            $stmt = self::conexion()->prepare($sql);
            $stmt->execute($parametros);
            return $stmt;
        } catch (\PDOException $e) {
            /* La conexión se cayó (el servidor la cerró por inactividad,
               un reinicio, un corte de red): no es un fallo de la
               consulta —con una conexión nueva, la misma consulta corre—.
               Se reintenta UNA vez, y SOLO fuera de transacción: dentro,
               reconectar perdería lo ya hecho y repetir la sentencia sería
               peor que fallar. Sin esto, un «MySQL server has gone away»
               tumbaba el latido y el canal de tiempo real en vez de
               reponerse solo. */
            if (self::$nivelTransaccion === 0 && self::esConexionPerdida($e)) {
                self::$pdo = null; // tirar la conexión muerta; conexion() abre otra

                try {
                    $stmt = self::conexion()->prepare($sql);
                    $stmt->execute($parametros);
                    return $stmt;
                } catch (\PDOException $e2) {
                    $e = $e2; // cayó también al reconectar: se reporta esto
                }
            }

            /* El SQL va al registro, no a la pantalla. Sin él, depurar
               esto es adivinar; con él en pantalla, es un mapa para
               quien quiera buscarle las vueltas al sistema. */
            error_log(
                '[BaseDatos] ' . $e->getMessage()
                . ' | SQL: ' . preg_replace('/\s+/', ' ', trim($sql))
            );

            throw FalloDeBaseDatos::desde($e);
        }
    }

    /**
     * ¿La excepción es porque se perdió la conexión, y no porque la
     * consulta esté mal? Son los casos en que reconectar y reintentar
     * tiene sentido; un error de sintaxis o de restricción, no.
     */
    private static function esConexionPerdida(\PDOException $e): bool
    {
        $codigo = (int) ($e->errorInfo[1] ?? 0);

        // 2006: server has gone away. 2013: lost connection during query.
        if ($codigo === 2006 || $codigo === 2013) {
            return true;
        }

        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'gone away')
            || str_contains($msg, 'lost connection')
            || str_contains($msg, 'broken pipe')
            || str_contains($msg, 'error while sending');
    }

    /**
     * @param array<string|int,mixed> $parametros
     * @return array<string,mixed>|null
     */
    public static function unaFila(string $sql, array $parametros = []): ?array
    {
        $fila = self::consultar($sql, $parametros)->fetch();
        return $fila === false ? null : $fila;
    }

    /**
     * @param array<string|int,mixed> $parametros
     * @return array<int,array<string,mixed>>
     */
    public static function todas(string $sql, array $parametros = []): array
    {
        return self::consultar($sql, $parametros)->fetchAll();
    }

    /** @param array<string|int,mixed> $parametros */
    public static function unValor(string $sql, array $parametros = []): mixed
    {
        $v = self::consultar($sql, $parametros)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<string|int,mixed> $parametros */
    public static function ejecutar(string $sql, array $parametros = []): int
    {
        return self::consultar($sql, $parametros)->rowCount();
    }

    public static function ultimoId(): int
    {
        return (int) self::conexion()->lastInsertId();
    }

    // -----------------------------------------------------------------
    // Transacciones con soporte de anidamiento (SAVEPOINT)
    // -----------------------------------------------------------------

    public static function iniciarTransaccion(): void
    {
        if (self::$nivelTransaccion === 0) {
            self::conexion()->beginTransaction();
        } else {
            self::conexion()->exec('SAVEPOINT nivel_' . self::$nivelTransaccion);
        }
        self::$nivelTransaccion++;
    }

    public static function confirmar(): void
    {
        if (self::$nivelTransaccion === 0) {
            throw new \LogicException('No hay transacción activa que confirmar.');
        }
        self::$nivelTransaccion--;
        if (self::$nivelTransaccion === 0) {
            self::conexion()->commit();
        } else {
            self::conexion()->exec('RELEASE SAVEPOINT nivel_' . self::$nivelTransaccion);
        }
    }

    public static function revertir(): void
    {
        if (self::$nivelTransaccion === 0) {
            return;
        }
        self::$nivelTransaccion--;
        if (self::$nivelTransaccion === 0) {
            if (self::conexion()->inTransaction()) {
                self::conexion()->rollBack();
            }
        } else {
            self::conexion()->exec('ROLLBACK TO SAVEPOINT nivel_' . self::$nivelTransaccion);
        }
    }

    /**
     * Ejecuta una operación dentro de una transacción.
     * Si algo lanza excepción, revierte y propaga. Nada queda a medias.
     *
     * @template T
     * @param callable():T $operacion
     * @return T
     */
    public static function enTransaccion(callable $operacion): mixed
    {
        self::iniciarTransaccion();
        try {
            $resultado = $operacion();
            self::confirmar();
            return $resultado;
        } catch (\Throwable $e) {
            self::revertir();
            throw $e;
        }
    }

    public static function hayTransaccionActiva(): bool
    {
        return self::$nivelTransaccion > 0;
    }
}
