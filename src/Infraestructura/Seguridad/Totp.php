<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Seguridad;

/**
 * Códigos de un solo uso basados en tiempo (TOTP, RFC 6238).
 *
 * Compatible con Google Authenticator, Microsoft Authenticator, Authy
 * y 1Password. Implementado desde la especificación, sin dependencias.
 *
 * Por qué TOTP y no el código por correo:
 *   - El código no viaja por ningún canal: se calcula en el teléfono.
 *     Interceptar el correo deja de servir.
 *   - Funciona sin conexión y sin depender de que el SMTP esté vivo.
 *   - El secreto nunca se vuelve a transmitir después del alta.
 *
 * Decisiones de implementación que importan:
 *   - Ventana de ±1 periodo (90 s en total) para tolerar el desfase de
 *     reloj del teléfono, sin abrir una ventana grande al atacante.
 *   - Comparación en tiempo constante con hash_equals.
 *   - Control de reutilización: un código usado no vale otra vez,
 *     aunque siga siendo válido por tiempo.
 */
final class Totp
{
    private const PERIODO   = 30;   // segundos por código
    private const DIGITOS   = 6;
    private const ALGORITMO = 'sha1';   // el que implementan las apps
    private const VENTANA   = 1;    // periodos antes y después

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secreto nuevo de 160 bits, el tamaño que recomienda la RFC. */
    public static function generarSecreto(): string
    {
        return self::base32Codificar(random_bytes(20));
    }

    /**
     * Código válido para un instante dado.
     *
     * @param int|null $instante Marca de tiempo; null = ahora
     */
    public static function codigo(string $secreto, ?int $instante = null): string
    {
        $contador = intdiv($instante ?? time(), self::PERIODO);
        $clave    = self::base32Decodificar($secreto);

        if ($clave === '') {
            throw new \InvalidArgumentException('Secreto TOTP inválido.');
        }

        // Contador como entero de 64 bits big-endian.
        $binario = pack('J', $contador);
        $hash    = hash_hmac(self::ALGORITMO, $binario, $clave, true);

        // Truncamiento dinámico (RFC 4226 §5.4).
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $valor  = ((ord($hash[$offset])     & 0x7F) << 24)
                | ((ord($hash[$offset + 1]) & 0xFF) << 16)
                | ((ord($hash[$offset + 2]) & 0xFF) << 8)
                |  (ord($hash[$offset + 3]) & 0xFF);

        $codigo = $valor % (10 ** self::DIGITOS);

        return str_pad((string) $codigo, self::DIGITOS, '0', STR_PAD_LEFT);
    }

    /**
     * Verifica un código.
     *
     * Devuelve el contador del periodo que coincidió, o null si no
     * coincide ninguno. El contador sirve para registrar qué periodo
     * se consumió y rechazar su reutilización.
     */
    public static function verificar(
        string $secreto,
        string $codigo,
        ?int $ultimoUsado = null,
        int $desfaseSeg = 0
    ): ?int {
        $codigo = preg_replace('/\D/', '', $codigo) ?? '';

        if (strlen($codigo) !== self::DIGITOS) {
            return null;
        }

        /* La hora DEL TELEFONO, no la del servidor.
         *
         * El desfase se midio una vez, al dar de alta, con el cliente
         * ya autenticado. A partir de ahi se compara contra el reloj
         * que el cliente usa de verdad, con la misma ventana estrecha
         * de siempre: un codigo robado sigue valiendo noventa segundos
         * y ni uno mas. Lo unico que cambia es desde donde se cuentan.
         *
         * Sin esto, un telefono tres minutos atrasado no podia entrar
         * jamas, aunque su aplicacion mostrara el codigo correcto. */
        $ahora = intdiv(time() + $desfaseSeg, self::PERIODO);

        for ($d = -self::VENTANA; $d <= self::VENTANA; $d++) {
            $contador = $ahora + $d;

            // Un código ya consumido no vale, aunque siga en ventana.
            if ($ultimoUsado !== null && $contador <= $ultimoUsado) {
                continue;
            }

            $esperado = self::codigo($secreto, $contador * self::PERIODO);

            // Tiempo constante: evita deducir dígitos midiendo el tiempo.
            if (hash_equals($esperado, $codigo)) {
                return $contador;
            }
        }

        return null;
    }

    /**
     * URI otpauth:// para el código QR.
     *
     * El emisor y la cuenta se codifican: un nombre con espacios o
     * acentos rompería la URI y la app no podría leerla.
     */
    public static function uri(string $secreto, string $cuenta, string $emisor = 'CashMan H.A.'): string
    {
        return 'otpauth://totp/'
             . rawurlencode($emisor) . ':' . rawurlencode($cuenta)
             . '?secret=' . $secreto
             . '&issuer=' . rawurlencode($emisor)
             . '&algorithm=' . strtoupper(self::ALGORITMO)
             . '&digits=' . self::DIGITOS
             . '&period=' . self::PERIODO;
    }

    /** El secreto en grupos de 4, para teclearlo a mano si no hay cámara. */
    public static function secretoLegible(string $secreto): string
    {
        return trim(chunk_split($secreto, 4, ' '));
    }

    /** Segundos que faltan para que cambie el código actual. */
    public static function segundosRestantes(): int
    {
        return self::PERIODO - (time() % self::PERIODO);
    }

    /**
     * Códigos de respaldo de un solo uso.
     *
     * Son la salida cuando se pierde el teléfono. Se muestran una vez
     * y en la base solo se guarda su hash.
     *
     * @return array<int,string>
     */
    public static function generarRespaldo(int $cantidad = 8): array
    {
        $codigos = [];

        for ($i = 0; $i < $cantidad; $i++) {
            // 10 caracteres hex en dos grupos: legible al dictarlo.
            $c = strtoupper(bin2hex(random_bytes(5)));
            $codigos[] = substr($c, 0, 5) . '-' . substr($c, 5, 5);
        }

        return $codigos;
    }

    // -----------------------------------------------------------------
    // Base32 (RFC 4648) — el formato que usan las apps autenticadoras
    // -----------------------------------------------------------------

    public static function base32Codificar(string $datos): string
    {
        if ($datos === '') {
            return '';
        }

        $bits   = '';
        $salida = '';

        foreach (str_split($datos) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        foreach (str_split($bits, 5) as $grupo) {
            $grupo   = str_pad($grupo, 5, '0', STR_PAD_RIGHT);
            $salida .= self::BASE32[bindec($grupo)];
        }

        return $salida;
    }

    public static function base32Decodificar(string $base32): string
    {
        $base32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $base32) ?? '');

        if ($base32 === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($base32) as $caracter) {
            $pos = strpos(self::BASE32, $caracter);
            if ($pos === false) {
                return '';
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $salida = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $salida .= chr(bindec($byte));
            }
        }

        return $salida;
    }
}
