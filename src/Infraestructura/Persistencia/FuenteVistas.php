<?php

declare(strict_types=1);

namespace CashMan\Infraestructura\Persistencia;

/**
 * Inlinea las vistas en las consultas, para no depender de `CREATE VIEW`.
 *
 * POR QUÉ
 *
 * Los hostings gratuitos (InfinityFree, byet/ezyro…) niegan el
 * privilegio `CREATE VIEW`, así que la app no puede crear sus vistas
 * allí. En vez de perderlas, aquí cada `v_x` que aparece en una consulta
 * se reemplaza por su propia definición como subconsulta con alias:
 *
 *     FROM v_cartera_morosa          →  FROM (SELECT … ) `v_cartera_morosa`
 *
 * El resultado que devuelve el motor es EXACTAMENTE el mismo que con la
 * vista —es su definición al centavo—, pero la vista ya no necesita
 * existir en la base. Así la app corre igual en cualquier host.
 *
 * FIDELIDAD
 *
 * Las definiciones de `definiciones_vistas.php` se sacan del motor con
 * `SHOW CREATE VIEW` (ver bin/regenerar-vistas.php), de un entorno que
 * sí tiene las vistas. No se transcriben a mano: son la definición real.
 *
 * DOS DETALLES QUE IMPORTAN
 *
 *   · Una vista puede usar otra (v_indicadores usa v_cartera_morosa).
 *     Las definiciones se resuelven entre sí al cargar, de modo que lo
 *     que se inyecta en la consulta ya no contiene ninguna vista.
 *
 *   · `\`v_x\`.\`columna\`` es un CALIFICADOR de columna, no una tabla.
 *     Solo se reemplaza la referencia como tabla (la que NO va seguida
 *     de un punto); el calificador se deja, y resuelve contra el alias
 *     de la subconsulta.
 */
final class FuenteVistas
{
    /** @var array<string,string>|null  nombre => SELECT ya sin otras vistas */
    private static ?array $resueltas = null;

    /**
     * Expande en la consulta las vistas que aparezcan.
     */
    public static function expandir(string $sql): string
    {
        // Atajo: si no hay ninguna «v_», no hay nada que expandir.
        if (strpos($sql, 'v_') === false) {
            return $sql;
        }

        /* NO tocar DDL. El expansor es para consultas que LEEN de las
           vistas. Un `CREATE VIEW v_x AS …` o un `DROP VIEW v_x` llevan
           el nombre de la vista, y reemplazarlo ahí rompería la
           sentencia (y es justo lo que hacen las migraciones). Se quita
           cualquier comentario inicial antes de mirar la palabra clave. */
        $sinComentarios = preg_replace('#^\s*(?:--[^\n]*\n|/\*.*?\*/\s*)*#s', '', $sql) ?? $sql;
        $prefijo = strtoupper(substr(ltrim($sinComentarios), 0, 8));

        foreach (['CREATE', 'DROP', 'ALTER', 'RENAME', 'TRUNCATE'] as $ddl) {
            if (str_starts_with($prefijo, $ddl)) {
                return $sql;
            }
        }

        foreach (self::definiciones() as $nombre => $select) {
            if (strpos($sql, $nombre) !== false) {
                $sql = self::reemplazar($sql, $nombre, $select);
            }
        }

        return $sql;
    }

    /**
     * Las definiciones ya resueltas entre sí (sin vistas anidadas).
     *
     * @return array<string,string>
     */
    private static function definiciones(): array
    {
        if (self::$resueltas !== null) {
            return self::$resueltas;
        }

        /** @var array<string,string> $crudas */
        $crudas = require __DIR__ . '/definiciones_vistas.php';

        $resueltas = [];

        $resolver = static function (string $nombre, array $pila) use (
            &$resolver, &$resueltas, $crudas
        ): string {
            if (isset($resueltas[$nombre])) {
                return $resueltas[$nombre];
            }
            // Guarda contra una referencia circular (no debería haberla).
            if (isset($pila[$nombre])) {
                return $crudas[$nombre] ?? '';
            }
            $pila[$nombre] = true;

            $select = (string) ($crudas[$nombre] ?? '');

            foreach ($crudas as $otra => $_) {
                if ($otra === $nombre) {
                    continue;
                }
                if (strpos($select, $otra) !== false) {
                    $select = self::reemplazar($select, $otra, $resolver($otra, $pila));
                }
            }

            return $resueltas[$nombre] = $select;
        };

        foreach (array_keys($crudas) as $nombre) {
            $resolver($nombre, []);
        }

        return self::$resueltas = $resueltas;
    }

    /**
     * Reemplaza las referencias a $vista COMO TABLA por su definición.
     *
     * El `(?!\s*\.)` deja fuera los calificadores de columna
     * (`\`v_x\`.\`col\``): esos no son una tabla y deben seguir
     * apuntando al alias de la subconsulta.
     */
    private static function reemplazar(string $sql, string $vista, string $select): string
    {
        $v    = preg_quote($vista, '/');
        $repl = '(' . $select . ') `' . $vista . '`';

        // Callback: devuelve el reemplazo literal, sin que preg_replace
        // interprete `$1`/`\1` que pudieran aparecer en el SELECT.
        $dar = static fn(): string => $repl;

        /* 1) Forma con backticks como TABLA: `v_x` que NO va seguida de
              un punto (eso sería el calificador `v_x`.`col`, que no se
              toca para que apunte al alias de la subconsulta). */
        $sql = preg_replace_callback('/`' . $v . '`(?!\s*\.)/', $dar, $sql) ?? $sql;

        /* 2) Forma sin backticks como TABLA (la que escribe la app:
              `FROM v_x`): el nombre suelto, NO pegado a un backtick ni a
              otra palabra —así no entra dentro de `v_x`— y no seguido de
              un punto. */
        $sql = preg_replace_callback(
            '/(?<![`\w])' . $v . '(?![`\w])(?!\s*\.)/',
            $dar,
            $sql
        ) ?? $sql;

        return $sql;
    }
}
