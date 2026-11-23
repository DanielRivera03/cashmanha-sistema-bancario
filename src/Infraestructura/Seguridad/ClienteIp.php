<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Seguridad;

/**
 * Obtención fiable de la IP del cliente.
 *
 * Importa que sea fiable: esta IP alimenta el límite de intentos y la
 * bitácora de auditoría. Si se confía ciegamente en X-Forwarded-For,
 * cualquiera la falsifica y esquiva el bloqueo por fuerza bruta.
 *
 * Por eso las cabeceras de proxy SOLO se respetan cuando la conexión
 * viene de un proxy declarado explícitamente como de confianza.
 */
final class ClienteIp
{
    /** @var array<int,string> Proxies de confianza (CIDR o IP exacta) */
    private static array $proxiesConfiables = ['127.0.0.1', '::1'];

    /** @param array<int,string> $proxies */
    public static function configurarProxies(array $proxies): void
    {
        self::$proxiesConfiables = $proxies;
    }

    public static function obtener(): string
    {
        $remota = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Solo mirar cabeceras de proxy si quien conecta ES un proxy confiable.
        if (self::esProxyConfiable($remota)) {
            foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'] as $cab) {
                if (empty($_SERVER[$cab])) {
                    continue;
                }
                // X-Forwarded-For puede traer una cadena: el cliente es el primero.
                $partes = explode(',', (string) $_SERVER[$cab]);
                $ip     = trim($partes[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return filter_var($remota, FILTER_VALIDATE_IP) ? $remota : '0.0.0.0';
    }

    /** Forma binaria compacta, para la columna VARBINARY(16). */
    public static function binaria(): string
    {
        $empaquetada = @inet_pton(self::obtener());
        return $empaquetada === false ? str_repeat("\x00", 4) : $empaquetada;
    }

    public static function desdeBinaria(string $binaria): string
    {
        $texto = @inet_ntop($binaria);
        return $texto === false ? 'desconocida' : $texto;
    }

    private static function esProxyConfiable(string $ip): bool
    {
        foreach (self::$proxiesConfiables as $confiable) {
            if ($ip === $confiable) {
                return true;
            }
            if (str_contains($confiable, '/') && self::enRangoCidr($ip, $confiable)) {
                return true;
            }
        }
        return false;
    }

    private static function enRangoCidr(string $ip, string $cidr): bool
    {
        [$subred, $bits] = explode('/', $cidr, 2);

        $ipBin  = @inet_pton($ip);
        $subBin = @inet_pton($subred);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
            return false;
        }

        $bits      = (int) $bits;
        $bytes     = intdiv($bits, 8);
        $bitsResto = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subBin, $bytes) !== 0) {
            return false;
        }
        if ($bitsResto === 0) {
            return true;
        }

        $mascara = ~((1 << (8 - $bitsResto)) - 1) & 0xFF;
        return (ord($ipBin[$bytes]) & $mascara) === (ord($subBin[$bytes]) & $mascara);
    }
}
