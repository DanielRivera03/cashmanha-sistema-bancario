<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Seguridad;

use CashMan\Infraestructura\Config;

/**
 * Hashing de contraseñas con Argon2id.
 *
 * Sustituye al esquema anterior:
 *     crypt($password, sha1($password))
 *
 * ...que era catastrófico por tres razones acumuladas:
 *
 *   1. El salt (un SHA1 en hexadecimal) no lleva prefijo reconocido,
 *      así que PHP caía al DES tradicional. El hash resultante tiene
 *      13 caracteres exactos — la firma que se veía en la base.
 *   2. DES descarta todo el password más allá del carácter 8.
 *      "MiClaveSuperSegura2024!" y "MiClaveS" daban el mismo hash.
 *   3. El salt se derivaba del propio password, así que dos usuarios
 *      con la misma clave producían hashes idénticos. Sin salt real,
 *      una sola tabla arcoíris revienta toda la base de usuarios.
 *
 * Qué hace esta clase:
 *   - Argon2id, ganador del Password Hashing Competition, resistente
 *     tanto a GPU como a ataques de canal lateral.
 *   - Salt aleatorio de 16 bytes por hash, generado por PHP.
 *   - Pepper vía HMAC-SHA256 previo: una clave que vive en .env, fuera
 *     de la base. Si roban un dump, los hashes no son atacables sin ella.
 *     El HMAC además normaliza la entrada a 44 bytes base64, lo que
 *     evita el límite de 72 bytes y los problemas con bytes nulos.
 *   - Verificación en tiempo constante incluso para usuarios inexistentes.
 */
final class ServicioPassword
{
    private const ALGORITMO = PASSWORD_ARGON2ID;

    /** @var array{memory_cost:int,time_cost:int,threads:int} */
    private array $opciones;

    private string $pepper;
    private ?string $hashSenuelo = null;

    public function __construct(?string $pepper = null)
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            throw new \RuntimeException(
                'Este PHP no soporta Argon2id. Hay que recompilarlo con --with-password-argon2 '
                . 'o habilitar ext-sodium.'
            );
        }

        $this->pepper = $pepper ?? Config::claveBinaria('PASSWORD_PEPPER');

        if (strlen($this->pepper) < 32) {
            throw new \RuntimeException('PASSWORD_PEPPER debe tener al menos 32 bytes.');
        }

        $this->opciones = [
            'memory_cost' => Config::entero('ARGON_MEMORY_COST', 65536),
            'time_cost'   => Config::entero('ARGON_TIME_COST', 3),
            'threads'     => Config::entero('ARGON_THREADS', 2),
        ];

        // OWASP: mínimo 19 MiB de memoria para Argon2id.
        if ($this->opciones['memory_cost'] < 19456) {
            throw new \RuntimeException(
                'ARGON_MEMORY_COST por debajo del mínimo recomendado (19456 KiB).'
            );
        }
    }

    /**
     * HMAC previo con el pepper.
     *
     * El pepper no se guarda junto al hash: vive en .env. Un atacante
     * con el dump de la base pero sin el archivo de configuración no
     * puede montar un ataque de diccionario offline.
     */
    private function aplicarPepper(string $password): string
    {
        return base64_encode(hash_hmac('sha256', $password, $this->pepper, true));
    }

    public function hashear(string $password): string
    {
        if ($password === '') {
            throw new \InvalidArgumentException('La contraseña no puede estar vacía.');
        }

        $hash = password_hash($this->aplicarPepper($password), self::ALGORITMO, $this->opciones);

        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Falló la generación del hash Argon2id.');
        }
        return $hash;
    }

    /**
     * Verifica una contraseña.
     *
     * @param string $algoritmo 'argon2id' o 'legacy' (esquema DES anterior)
     */
    public function verificar(string $password, string $hashAlmacenado, string $algoritmo = 'argon2id'): bool
    {
        if ($password === '' || $hashAlmacenado === '') {
            return false;
        }

        if ($algoritmo === 'legacy') {
            return $this->verificarLegacy($password, $hashAlmacenado);
        }

        return password_verify($this->aplicarPepper($password), $hashAlmacenado);
    }

    /**
     * Consume el mismo tiempo que una verificación real.
     *
     * Sin esto, un atacante mide la diferencia entre "usuario no existe"
     * (respuesta inmediata) y "contraseña incorrecta" (300 ms de Argon2)
     * y enumera qué cuentas existen.
     */
    public function verificarSenuelo(string $password): void
    {
        if ($this->hashSenuelo === null) {
            $this->hashSenuelo = password_hash(
                $this->aplicarPepper(bin2hex(random_bytes(16))),
                self::ALGORITMO,
                $this->opciones
            );
        }
        password_verify($this->aplicarPepper($password), $this->hashSenuelo);
    }

    /** ¿Hay que regenerar el hash con parámetros actuales? */
    public function necesitaRehash(string $hashAlmacenado, string $algoritmo = 'argon2id'): bool
    {
        if ($algoritmo !== 'argon2id') {
            return true;
        }
        return password_needs_rehash($hashAlmacenado, self::ALGORITMO, $this->opciones);
    }

    /**
     * Reproduce el esquema anterior SOLO para migrar usuarios existentes.
     *
     * Permite que alguien con la contraseña vieja entre una última vez,
     * momento en el que su hash se regenera con Argon2id de forma
     * transparente. Eliminar este método cuando no queden filas 'legacy'.
     */
    private function verificarLegacy(string $password, string $hashAlmacenado): bool
    {
        // El esquema viejo truncaba a 8 caracteres; crypt() lo replica.
        $calculado = @crypt($password, sha1($password));
        return is_string($calculado) && hash_equals($hashAlmacenado, $calculado);
    }

    /**
     * Política mínima de contraseñas.
     *
     * @return array<int,string> Lista de incumplimientos; vacía si es válida.
     */
    public function validarPolitica(string $password): array
    {
        $errores = [];

        if (mb_strlen($password) < 12) {
            $errores[] = 'Debe tener al menos 12 caracteres.';
        }
        if (mb_strlen($password) > 256) {
            $errores[] = 'No puede superar los 256 caracteres.';
        }
        if (!preg_match('/[a-záéíóúñ]/u', $password)) {
            $errores[] = 'Debe incluir al menos una letra minúscula.';
        }
        if (!preg_match('/[A-ZÁÉÍÓÚÑ]/u', $password)) {
            $errores[] = 'Debe incluir al menos una letra mayúscula.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errores[] = 'Debe incluir al menos un número.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errores[] = 'Debe incluir al menos un símbolo.';
        }

        // Rechazo de las contraseñas más explotadas en ataques reales.
        $prohibidas = [
            'password', 'contrasena', 'contraseña', '123456789', 'qwertyuiop',
            'administrador', 'cashmanha', 'bienvenido', 'iloveyou', 'password1',
        ];
        $normalizada = mb_strtolower($password);
        foreach ($prohibidas as $mala) {
            if (str_contains($normalizada, $mala)) {
                $errores[] = 'Contiene una secuencia demasiado común y predecible.';
                break;
            }
        }

        return $errores;
    }

    /** @return array{memory_cost:int,time_cost:int,threads:int} */
    public function parametros(): array
    {
        return $this->opciones;
    }

    /** Mide cuánto tarda un hash en este hardware, para calibrar. */
    public function medirCosto(): float
    {
        $inicio = microtime(true);
        $this->hashear('medicion-de-referencia-' . bin2hex(random_bytes(4)));
        return (microtime(true) - $inicio) * 1000;
    }
}
