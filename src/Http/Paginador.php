<?php
declare(strict_types=1);

namespace CashMan\Http;

/**
 * Paginación de listados.
 *
 * Hasta ahora los listados largos se resolvían con un `LIMIT 400` y una
 * nota al pie —"se muestran las primeras 400, acota el rango"—, que
 * traslada al usuario un problema que le toca resolver al sistema: con
 * 290 cuotas en pantalla la página pesa, la tabla no cabe y lo que se
 * busca queda enterrado.
 *
 * Esta clase solo calcula; no consulta ni imprime nada. El controlador
 * le da el total de filas y ella devuelve el `LIMIT`/`OFFSET` que toca y
 * la ventana de números que la vista tiene que dibujar.
 *
 * Dos detalles que evitan páginas en blanco:
 *
 * 1. La página se recorta al rango válido. Si alguien pide la 40 de un
 *    listado que tiene 8 —o filtra y el resultado encoge— se le entrega
 *    la última, no una vacía.
 *
 * 2. El tamaño de página se compara contra una lista cerrada. Viene de
 *    la URL, así que sin esa comprobación bastaría con pedir
 *    `?pp=1000000` para hacer que el servidor intente traer la tabla
 *    entera.
 */
final class Paginador
{
    /** Tamaños que se ofrecen; cualquier otro valor se ignora. */
    public const TAMANOS = [25, 50, 100, 200];

    public const POR_DEFECTO = 25;

    public readonly int $total;
    public readonly int $porPagina;
    public readonly int $paginas;
    public readonly int $pagina;
    public readonly int $desplazamiento;

    public function __construct(int $total, mixed $pagina = 1, mixed $porPagina = self::POR_DEFECTO)
    {
        $this->total = max(0, $total);

        $pp = (int) $porPagina;
        $this->porPagina = in_array($pp, self::TAMANOS, true) ? $pp : self::POR_DEFECTO;

        // Con cero filas sigue habiendo una página: la que muestra el
        // mensaje de "no hay nada", no un hueco.
        $this->paginas = max(1, (int) ceil($this->total / $this->porPagina));

        $p = (int) $pagina;
        $this->pagina = max(1, min($this->paginas, $p < 1 ? 1 : $p));

        $this->desplazamiento = ($this->pagina - 1) * $this->porPagina;
    }

    /** Número de la primera fila de esta página, contando desde 1. */
    public function desde(): int
    {
        return $this->total === 0 ? 0 : $this->desplazamiento + 1;
    }

    /** Número de la última fila de esta página. */
    public function hasta(): int
    {
        return min($this->total, $this->desplazamiento + $this->porPagina);
    }

    public function hayAnterior(): bool
    {
        return $this->pagina > 1;
    }

    public function haySiguiente(): bool
    {
        return $this->pagina < $this->paginas;
    }

    /**
     * Números a dibujar, con huecos marcados como null.
     *
     * Se muestran siempre la primera y la última, más un radio alrededor
     * de la actual. Con 60 páginas eso da "1 … 28 29 [30] 31 32 … 60" en
     * lugar de sesenta botones, que es lo que ocurría al listar sin
     * ventana.
     *
     * @return array<int, int|null>
     */
    public function ventana(int $radio = 2): array
    {
        $numeros = [1, $this->paginas];

        for ($p = $this->pagina - $radio; $p <= $this->pagina + $radio; $p++) {
            if ($p >= 1 && $p <= $this->paginas) {
                $numeros[] = $p;
            }
        }

        $numeros = array_values(array_unique($numeros));
        sort($numeros);

        $salida   = [];
        $anterior = 0;

        foreach ($numeros as $n) {
            // Un solo número de separación no merece puntos suspensivos:
            // ocupa lo mismo y se puede pulsar.
            if ($anterior !== 0 && $n - $anterior > 1) {
                $salida[] = $n - $anterior === 2 ? $anterior + 1 : null;
            }
            $salida[] = $n;
            $anterior = $n;
        }

        return $salida;
    }

    /** ¿Merece la pena dibujar los controles? */
    public function esNecesario(): bool
    {
        return $this->total > self::TAMANOS[0];
    }

    /** @return array<string,mixed> Lo que la vista necesita, ya resuelto. */
    public function aArreglo(): array
    {
        return [
            'pagina'     => $this->pagina,
            'paginas'    => $this->paginas,
            'porPagina'  => $this->porPagina,
            'total'      => $this->total,
            'desde'      => $this->desde(),
            'hasta'      => $this->hasta(),
            'anterior'   => $this->hayAnterior() ? $this->pagina - 1 : null,
            'siguiente'  => $this->haySiguiente() ? $this->pagina + 1 : null,
            'ventana'    => $this->ventana(),
            'necesario'  => $this->esNecesario(),
            'tamanos'    => self::TAMANOS,
        ];
    }
}
