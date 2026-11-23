<?php
declare(strict_types=1);

namespace CashMan\Dominio\Comun;

use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * Folios y referencias que no se repiten.
 *
 * Todos los folios del sistema se armaban igual: un prefijo, la fecha y
 * unos bytes al azar. Y todas las columnas que los guardan llevan índice
 * único, así que un choque no es una curiosidad estadística: es una
 * operación que aborta con «Duplicate entry» a la cara del cliente, con
 * el crédito a medio otorgar o la compra a medio cobrar.
 *
 * Pasó de verdad: un crédito nuevo sacó el folio de uno que ya existía.
 *
 * Y la probabilidad no era la que parecía. Con cuatro caracteres
 * hexadecimales hay 65.536 combinaciones, pero lo que importa no es
 * acertar un folio concreto sino que dos cualesquiera coincidan: con
 * trescientos pedidos en un mismo día la probabilidad de choque pasa del
 * 50 %. A eso se le llama la paradoja del cumpleaños, y es la razón de
 * que esto fallara tan pronto.
 *
 * Aquí se hacen dos cosas. Se sube la entropía —ocho caracteres, cuatro
 * mil millones de combinaciones— y, sobre todo, se PREGUNTA a la tabla
 * antes de devolver el folio. Lo segundo es lo que de verdad lo
 * garantiza: la entropía solo hace que casi nunca haga falta repetir.
 *
 * Queda una ventana entre comprobar e insertar en la que otra petición
 * podría tomar el mismo folio. Es estrechísima y el índice único la
 * cierra: si ocurriera, la inserción falla y el reintento resuelve. Lo
 * que no se puede es NO tener el índice y confiar en la suerte.
 */
final class Folio
{
    /** Suficientes para que agotarlos signifique que algo va mal de verdad. */
    private const INTENTOS = 12;

    /**
     * Un folio libre para esa tabla y esa columna.
     *
     * @param string $prefijo Las siglas del documento: CR, SOL, PED…
     * @param string $tabla   Dónde se va a guardar.
     * @param string $columna La columna con el índice único.
     * @param string $fecha   Formato de la parte de fecha; '' la omite.
     * @param int    $bytes   Bytes de azar; cada uno son dos caracteres.
     */
    public static function unico(
        string $prefijo,
        string $tabla,
        string $columna = 'folio',
        string $fecha = 'ym',
        int $bytes = 4
    ): string {
        /* Los nombres vienen de nuestro propio código, nunca de fuera,
           pero se comprueban igual: una consulta armada por concatenación
           es exactamente donde no conviene suponer. */
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $tabla)
            || !preg_match('/^[a-z_][a-z0-9_]*$/', $columna)) {
            throw new \InvalidArgumentException('Tabla o columna no válidas.');
        }

        $bytes = max(2, min(16, $bytes));

        for ($i = 0; $i < self::INTENTOS; $i++) {
            $candidato = self::componer($prefijo, $fecha, $bytes);

            $existe = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM `' . $tabla . '` WHERE `' . $columna . '` = ?',
                [$candidato]
            );

            if ($existe === 0) {
                return $candidato;
            }
        }

        /* Agotar doce intentos contra cuatro mil millones de
           combinaciones no es mala suerte: es que la tabla está rota, la
           consulta no filtra o alguien llenó la columna. Mejor parar y
           decirlo que entregar un folio que va a fallar al insertar. */
        throw new \RuntimeException(
            'No se pudo asignar un ' . $prefijo . ' libre en ' . $tabla . '.'
        );
    }

    /**
     * Un identificador al azar, sin comprobar contra ninguna tabla.
     *
     * Para claves de idempotencia y referencias internas que no llevan
     * índice único. Lleva más entropía porque aquí no hay red debajo.
     */
    public static function aleatorio(string $prefijo, int $bytes = 8): string
    {
        return self::componer($prefijo, '', $bytes);
    }

    private static function componer(string $prefijo, string $fecha, int $bytes): string
    {
        $partes = [$prefijo];

        if ($fecha !== '') {
            $partes[] = date($fecha);
        }

        $partes[] = strtoupper(bin2hex(random_bytes($bytes)));

        return implode('-', array_filter($partes, static fn(string $p): bool => $p !== ''));
    }
}
