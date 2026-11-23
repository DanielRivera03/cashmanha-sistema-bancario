<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Seguridad;

use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * Bitácora de auditoría append-only, encadenada por hash.
 *
 * Cada registro incluye el hash del anterior. Alterar o borrar una fila
 * rompe la cadena de todas las posteriores, y verificar() lo detecta.
 * Es la diferencia entre un log (que un atacante con acceso edita) y
 * una bitácora de auditoría (donde la manipulación deja rastro).
 *
 * Reemplaza al registro anterior, que guardaba php_uname('n') —el nombre
 * del SERVIDOR, idéntico en todas las filas— creyendo que era el
 * dispositivo del cliente.
 */
final class Auditoria
{
    public const EXITO    = 'exito';
    public const FALLO    = 'fallo';
    public const DENEGADO = 'denegado';

    /**
     * @param array<string,mixed>|null $detalle
     */
    public static function registrar(
        string $accion,
        string $resultado = self::EXITO,
        ?int $usuarioId = null,
        string $entidad = '',
        string $entidadId = '',
        ?array $detalle = null
    ): void {
        try {
            $hashAnterior = self::ultimoHash();

            $ip        = ClienteIp::binaria();
            $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $creadoEn  = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');

            // Nunca registrar datos sensibles en claro.
            $detalleJson = $detalle === null
                ? null
                : json_encode(self::depurarSensibles($detalle), JSON_UNESCAPED_UNICODE);

            $contenido = implode('|', [
                $hashAnterior,
                (string) ($usuarioId ?? 0),
                $accion,
                $entidad,
                $entidadId,
                $resultado,
                $detalleJson ?? '',
                bin2hex($ip),
                $userAgent,
                $creadoEn,
            ]);
            $hashActual = hash('sha256', $contenido);

            BaseDatos::ejecutar(
                'INSERT INTO auditoria
                   (usuario_id, accion, entidad, entidad_id, resultado, detalle_json,
                    ip, user_agent, creado_en, hash_anterior, hash_actual)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $usuarioId, $accion, $entidad, $entidadId, $resultado, $detalleJson,
                    $ip, $userAgent, $creadoEn, $hashAnterior, $hashActual,
                ]
            );
        } catch (\Throwable $e) {
            // La auditoría NUNCA debe tumbar la operación de negocio.
            // Pero sí tiene que dejar rastro de su propio fallo.
            error_log('[Auditoria] No se pudo registrar "' . $accion . '": ' . $e->getMessage());
        }
    }

    private static function ultimoHash(): string
    {
        $v = BaseDatos::unValor('SELECT hash_actual FROM auditoria ORDER BY id DESC LIMIT 1');
        return is_string($v) && $v !== ''
            ? $v
            : Config::obtener('AUDITORIA_SEMILLA', str_repeat('0', 64));
    }

    /**
     * Verifica la integridad de la cadena.
     *
     * @return array{valida:bool, revisados:int, primera_rota:int|null}
     */
    public static function verificarCadena(int $desdeId = 0, int $limite = 10000): array
    {
        $filas = BaseDatos::todas(
            'SELECT id, usuario_id, accion, entidad, entidad_id, resultado, detalle_json,
                    ip, user_agent, creado_en, hash_anterior, hash_actual
             FROM auditoria
             WHERE id > ?
             ORDER BY id ASC
             LIMIT ' . (int) $limite,
            [$desdeId]
        );

        $esperado = $desdeId === 0
            ? Config::obtener('AUDITORIA_SEMILLA', str_repeat('0', 64))
            : (string) BaseDatos::unValor('SELECT hash_actual FROM auditoria WHERE id = ?', [$desdeId]);

        $revisados = 0;

        foreach ($filas as $f) {
            if (!hash_equals($esperado, (string) $f['hash_anterior'])) {
                return ['valida' => false, 'revisados' => $revisados, 'primera_rota' => (int) $f['id']];
            }

            $contenido = implode('|', [
                (string) $f['hash_anterior'],
                (string) ((int) ($f['usuario_id'] ?? 0)),
                (string) $f['accion'],
                (string) $f['entidad'],
                (string) $f['entidad_id'],
                (string) $f['resultado'],
                (string) ($f['detalle_json'] ?? ''),
                bin2hex((string) $f['ip']),
                (string) $f['user_agent'],
                (string) $f['creado_en'],
            ]);

            if (!hash_equals(hash('sha256', $contenido), (string) $f['hash_actual'])) {
                return ['valida' => false, 'revisados' => $revisados, 'primera_rota' => (int) $f['id']];
            }

            $esperado = (string) $f['hash_actual'];
            $revisados++;
        }

        return ['valida' => true, 'revisados' => $revisados, 'primera_rota' => null];
    }

    /**
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    private static function depurarSensibles(array $datos): array
    {
        $prohibidas = ['password', 'contrasena', 'contraseña', 'clave', 'pan', 'cvv',
                       'cvc', 'pin', 'token', 'secreto', 'secret'];

        foreach ($datos as $clave => $valor) {
            $cl = mb_strtolower((string) $clave);
            foreach ($prohibidas as $p) {
                if (str_contains($cl, $p)) {
                    $datos[$clave] = '[OCULTO]';
                    continue 2;
                }
            }
            if (is_array($valor)) {
                $datos[$clave] = self::depurarSensibles($valor);
            }
        }

        return $datos;
    }
}
