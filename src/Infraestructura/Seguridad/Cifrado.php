<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Seguridad;

/**
 * Cifrado simétrico autenticado con AES-256-GCM.
 *
 * GCM proporciona confidencialidad E integridad: si alguien altera un
 * byte del texto cifrado en la base de datos, el descifrado falla en
 * lugar de devolver basura silenciosamente.
 *
 * Se usa para el PAN de las tarjetas y el secreto TOTP. Nunca para
 * contraseñas: esas se hashean con Argon2id y jamás se descifran.
 *
 * Formato del paquete: version(1) || iv(12) || tag(16) || cifrado(n)
 */
final class Cifrado
{
    private const METODO       = 'aes-256-gcm';
    private const VERSION      = "\x01";
    private const LONG_IV      = 12;   // 96 bits, el tamaño óptimo para GCM
    private const LONG_TAG     = 16;   // 128 bits

    public function __construct(private string $clave)
    {
        if (strlen($this->clave) !== 32) {
            throw new \InvalidArgumentException('La clave de cifrado debe ser de 32 bytes (AES-256).');
        }
    }

    /**
     * @param string $datosAsociados Datos autenticados pero no cifrados.
     *                               Útil para atar el cifrado a un contexto
     *                               concreto (p. ej. el id de la tarjeta),
     *                               de modo que no se pueda reubicar.
     */
    public function cifrar(string $textoPlano, string $datosAsociados = ''): string
    {
        $iv  = random_bytes(self::LONG_IV);
        $tag = '';

        $cifrado = openssl_encrypt(
            $textoPlano,
            self::METODO,
            $this->clave,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $datosAsociados,
            self::LONG_TAG
        );

        if ($cifrado === false) {
            throw new \RuntimeException('Falló el cifrado AES-256-GCM.');
        }

        return self::VERSION . $iv . $tag . $cifrado;
    }

    public function descifrar(string $paquete, string $datosAsociados = ''): string
    {
        $minimo = 1 + self::LONG_IV + self::LONG_TAG;
        if (strlen($paquete) <= $minimo) {
            throw new \RuntimeException('Paquete cifrado con formato inválido.');
        }

        $version = $paquete[0];
        if ($version !== self::VERSION) {
            throw new \RuntimeException('Versión de cifrado no reconocida.');
        }

        $iv      = substr($paquete, 1, self::LONG_IV);
        $tag     = substr($paquete, 1 + self::LONG_IV, self::LONG_TAG);
        $cifrado = substr($paquete, $minimo);

        $plano = openssl_decrypt(
            $cifrado,
            self::METODO,
            $this->clave,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $datosAsociados
        );

        // false aquí significa que el tag no validó: los datos fueron
        // alterados, o la clave es incorrecta. Nunca devolver nada.
        if ($plano === false) {
            throw new \RuntimeException('Falló el descifrado: datos alterados o clave incorrecta.');
        }

        return $plano;
    }

    /**
     * Hash con pepper para búsquedas ciegas.
     *
     * Permite comprobar "¿ya existe esta tarjeta?" sin almacenar ni
     * poder reconstruir el PAN.
     */
    public static function hashBusqueda(string $valor, string $pepper): string
    {
        return hash_hmac('sha256', $valor, $pepper);
    }
}
