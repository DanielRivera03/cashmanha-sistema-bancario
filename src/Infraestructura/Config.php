<?php
declare(strict_types=1);

namespace CashMan\Infraestructura;

/**
 * Lector de configuración desde .env.
 *
 * No usa getenv() ni $_ENV para evitar que las claves aparezcan en
 * phpinfo(), var_dump($_ENV) o en el entorno de procesos hijos.
 */
final class Config
{
    /** @var array<string,string> */
    private static array $valores = [];
    private static bool $cargado = false;

    public static function cargar(string $rutaEnv): void
    {
        if (self::$cargado) {
            return;
        }
        if (!is_readable($rutaEnv)) {
            throw new \RuntimeException("No se puede leer el archivo de configuración: {$rutaEnv}");
        }

        $lineas = file($rutaEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if ($linea === '' || str_starts_with($linea, '#')) {
                continue;
            }
            $pos = strpos($linea, '=');
            if ($pos === false) {
                continue;
            }
            $clave  = trim(substr($linea, 0, $pos));
            $valor  = trim(substr($linea, $pos + 1));

            // Quitar comillas envolventes si las hay
            if (strlen($valor) >= 2) {
                $ini = $valor[0];
                $fin = $valor[strlen($valor) - 1];
                if (($ini === '"' && $fin === '"') || ($ini === "'" && $fin === "'")) {
                    $valor = substr($valor, 1, -1);
                }
            }
            self::$valores[$clave] = $valor;
        }
        self::$cargado = true;
    }

    public static function obtener(string $clave, ?string $porDefecto = null): ?string
    {
        return self::$valores[$clave] ?? $porDefecto;
    }

    /** Lanza excepción si falta: para claves sin las cuales el sistema no debe arrancar. */
    public static function exigir(string $clave): string
    {
        $v = self::$valores[$clave] ?? '';
        if ($v === '') {
            throw new \RuntimeException("Falta la clave de configuración obligatoria: {$clave}");
        }
        return $v;
    }

    public static function entero(string $clave, int $porDefecto = 0): int
    {
        $v = self::$valores[$clave] ?? null;
        return $v === null || $v === '' ? $porDefecto : (int) $v;
    }

    public static function booleano(string $clave, bool $porDefecto = false): bool
    {
        $v = self::$valores[$clave] ?? null;
        if ($v === null || $v === '') {
            return $porDefecto;
        }
        return in_array(strtolower($v), ['1', 'true', 'si', 'sí', 'yes', 'on'], true);
    }

    /** Decodifica una clave almacenada en base64. */
    public static function claveBinaria(string $clave): string
    {
        $bruto = self::exigir($clave);
        $bin   = base64_decode($bruto, true);
        if ($bin === false || strlen($bin) < 32) {
            throw new \RuntimeException("La clave {$clave} debe ser base64 de al menos 32 bytes.");
        }
        return $bin;
    }

    public static function esProduccion(): bool
    {
        return self::obtener('APP_ENTORNO', 'produccion') === 'produccion';
    }

    public static function depuracion(): bool
    {
        return self::booleano('APP_DEPURACION', false) && !self::esProduccion();
    }
}
