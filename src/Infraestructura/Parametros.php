<?php
declare(strict_types=1);

namespace CashMan\Infraestructura;

use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * Parámetros de operación guardados en la base.
 *
 * Son los números que la institución cambia sin que nadie toque código:
 * el recargo diario por mora, las tarifas de los seguros, los topes. La
 * diferencia con `Config` es de dónde vienen y quién los cambia: `Config`
 * lee el `.env` —entorno, claves, credenciales— y esto lee una tabla que
 * Gerencia puede editar.
 *
 * Se leen todos de una vez y se guardan en memoria durante la petición.
 * Sin eso, calcular el seguro de 180 cuotas haría 360 consultas para
 * devolver siempre el mismo par de números.
 */
final class Parametros
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function obtener(string $clave, string $porDefecto = ''): string
    {
        self::cargar();

        return self::$cache[$clave] ?? $porDefecto;
    }

    public static function entero(string $clave, int $porDefecto = 0): int
    {
        self::cargar();

        $v = self::$cache[$clave] ?? null;

        return $v === null || !is_numeric($v) ? $porDefecto : (int) $v;
    }

    /**
     * Un interruptor guardado como parámetro.
     *
     * Acepta las formas en que la gente escribe «sí» en una tabla de
     * configuración: 1, true, si, sí. Cualquier otra cosa es «no», y lo
     * mismo vale para un parámetro que no exista, de modo que una
     * función nueva no se activa sola por olvidar insertarlo.
     */
    public static function booleano(string $clave, bool $porDefecto = false): bool
    {
        self::cargar();

        $v = self::$cache[$clave] ?? null;

        if ($v === null || trim((string) $v) === '') {
            return $porDefecto;
        }

        return in_array(
            mb_strtolower(trim((string) $v)),
            ['1', 'true', 'si', 'sí', 'on'],
            true
        );
    }

    /**
     * Guarda un parámetro.
     *
     * No valida el contenido a propósito: cada módulo sabe qué rango
     * tiene sentido para el suyo y lo comprueba antes de llamar aquí.
     */
    public static function guardar(string $clave, string $valor): void
    {
        BaseDatos::ejecutar(
            'INSERT INTO parametros (clave, valor) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
            [$clave, $valor]
        );

        // La caché de esta petición deja de ser válida.
        self::$cache = null;
    }

    /** Vacía la caché. Para las pruebas, que cambian parámetros en marcha. */
    public static function olvidar(): void
    {
        self::$cache = null;
    }

    private static function cargar(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];

        // Si la tabla todavía no existe —instalación a medio migrar— se
        // sigue con los valores por defecto en vez de tumbar la página.
        try {
            foreach (BaseDatos::todas('SELECT clave, valor FROM parametros') as $f) {
                self::$cache[(string) $f['clave']] = (string) $f['valor'];
            }
        } catch (\Throwable) {
            self::$cache = [];
        }
    }
}
