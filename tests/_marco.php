<?php

declare(strict_types=1);

/**
 * Un marco de pruebas de cien líneas.
 *
 * No hay `composer.json` en este proyecto y meter PHPUnit significaría
 * meter Composer, un `vendor/` de miles de archivos y una versión de
 * PHP que mantener al día. Para comprobar aritmética de dinero hace
 * falta mucho menos: una función que compare y un contador.
 *
 * Si algún día el proyecto adopta Composer, estas pruebas se traducen
 * a PHPUnit casi línea por línea, porque no usan nada que no sea PHP.
 */

final class Pruebas
{
    private static int $pasadas = 0;
    private static int $falladas = 0;
    private static string $grupo = '';

    /** @var list<string> */
    private static array $fallos = [];

    public static function grupo(string $nombre): void
    {
        self::$grupo = $nombre;
        printf("\n  %s\n", $nombre);
    }

    /** Una comprobación. `$esperado` y `$real` se comparan por valor. */
    public static function igual(mixed $esperado, mixed $real, string $que): void
    {
        $ok = $esperado === $real;

        /* Los objetos de valor se comparan por su contenido: dos
           `Monto` de 500 centavos son el mismo dinero aunque sean dos
           instancias distintas, y es el dinero lo que se prueba. */
        if (!$ok && is_object($esperado) && is_object($real)) {
            $ok = $esperado == $real;
        }

        self::anotar($ok, $que, self::comoTexto($esperado), self::comoTexto($real));
    }

    public static function cierto(bool $valor, string $que): void
    {
        self::anotar($valor, $que, 'true', $valor ? 'true' : 'false');
    }

    public static function falso(bool $valor, string $que): void
    {
        self::anotar(!$valor, $que, 'false', $valor ? 'true' : 'false');
    }

    /**
     * Comprueba que algo lance la excepción que debe.
     *
     * Vale tanto o más que comprobar el camino bueno: casi todos los
     * agujeros de dinero aparecen cuando una entrada inválida NO se
     * rechaza y sigue adelante como si nada.
     */
    public static function lanza(string $clase, callable $que, string $texto): void
    {
        try {
            $que();
        } catch (\Throwable $e) {
            self::anotar(
                $e instanceof $clase,
                $texto,
                $clase,
                $e::class . ': ' . $e->getMessage()
            );
            return;
        }

        self::anotar(false, $texto, $clase, 'no lanzó nada');
    }

    private static function anotar(bool $ok, string $que, string $esperado, string $real): void
    {
        if ($ok) {
            self::$pasadas++;
            printf("    ok    %s\n", $que);
            return;
        }

        self::$falladas++;
        self::$fallos[] = sprintf("%s · %s\n          esperaba: %s\n          obtuvo:   %s",
            self::$grupo, $que, $esperado, $real);
        printf("    FALLA %s\n", $que);
    }

    private static function comoTexto(mixed $v): string
    {
        return match (true) {
            is_object($v) && method_exists($v, '__toString') => (string) $v,
            is_object($v)   => $v::class . ' ' . json_encode(get_object_vars($v)),
            is_array($v)    => json_encode($v),
            is_bool($v)     => $v ? 'true' : 'false',
            $v === null     => 'null',
            default         => (string) $v,
        };
    }

    /** @return int El código de salida: 0 si todo pasó. */
    public static function resumen(): int
    {
        printf("\n  ------------------------------------------------------------\n");
        printf("  %d comprobaciones: %d bien, %d mal\n",
            self::$pasadas + self::$falladas, self::$pasadas, self::$falladas);

        if (self::$fallos !== []) {
            echo "\n  LO QUE FALLÓ\n";
            foreach (self::$fallos as $f) {
                printf("    · %s\n", $f);
            }
            echo "\n";
            return 1;
        }

        echo "\n";
        return 0;
    }
}
