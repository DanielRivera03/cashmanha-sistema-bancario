<?php

declare(strict_types=1);

namespace CashMan\Infraestructura\Persistencia;

/**
 * Un fallo de la base de datos, contado para quien está en pantalla.
 *
 * POR QUÉ EXISTE
 *
 * `PDOException` extiende `RuntimeException`. Eso parece un detalle y
 * no lo es: los cincuenta y pico de controladores del sistema hacen
 *
 *     catch (\InvalidArgumentException | \RuntimeException $e) {
 *         ... responder($e->getMessage()) ...
 *     }
 *
 * porque esas excepciones llevan mensajes escritos para el cliente
 * («Su tarjeta está congelada», «El cupo no alcanza»). Al heredar de
 * `RuntimeException`, cualquier tropiezo de la base entraba por esa
 * misma puerta y su mensaje salía tal cual a la pantalla:
 *
 *     SQLSTATE[23000]: Integrity constraint violation: 1048
 *     Column 'cargo' cannot be null
 *
 * Eso está mal por dos razones. La primera es que el cliente no puede
 * hacer nada con ese texto: no le dice qué corregir. La segunda es
 * que regala el motor, el nombre de la columna y la forma de la
 * restricción a cualquiera que esté probando qué rompe.
 *
 * Así que `BaseDatos` atrapa los fallos de PDO y lanza ESTO en su
 * lugar: un mensaje que una persona entiende, con el detalle técnico
 * guardado aparte para el registro. Los `catch` de siempre lo siguen
 * capturando —sigue siendo una `RuntimeException`— pero ahora lo que
 * enseñan sirve de algo.
 */
final class FalloDeBaseDatos extends \RuntimeException
{
    public function __construct(
        string $mensaje,
        /** El texto original de PDO. Para el registro, nunca para la pantalla. */
        public readonly string $detalle = '',
        public readonly string $sqlstate = '',
        ?\Throwable $anterior = null
    ) {
        parent::__construct($mensaje, 0, $anterior);
    }

    /**
     * Traduce un fallo de PDO a algo que se pueda leer.
     *
     * Solo se explican los casos en que de verdad se sabe qué pasó y
     * la persona puede hacer algo. Para el resto, un mensaje honesto:
     * no se pudo, inténtelo otra vez, y si sigue igual avise. Inventar
     * una explicación para un error que no se entiende es peor que
     * admitir que no se entiende.
     */
    public static function desde(\PDOException $e): self
    {
        $detalle  = $e->getMessage();
        $sqlstate = (string) ($e->errorInfo[0] ?? $e->getCode());
        $codigo   = (int) ($e->errorInfo[1] ?? 0);

        $mensaje = match (true) {
            // Clave duplicada: el dato ya está, y eso sí se puede decir.
            $codigo === 1062 => 'Ya existe un registro con esos datos. '
                              . 'Revise si la operación ya se hizo antes de repetirla.',

            // Bloqueo o interbloqueo: es temporal y reintentar funciona.
            $codigo === 1205 || $codigo === 1213 =>
                'El sistema está atendiendo otra operación sobre los mismos datos. '
                . 'Espere unos segundos y vuelva a intentarlo.',

            // Referencia que no existe o que no se puede borrar.
            $codigo === 1451 || $codigo === 1452 =>
                'No se pudo completar porque hay información relacionada. '
                . 'Si el problema sigue, avísenos indicando qué estaba haciendo.',

            // Sin conexión: tampoco es culpa de quien está en pantalla.
            str_starts_with($sqlstate, '08') =>
                'No pudimos comunicarnos con la base de datos. '
                . 'Vuelva a intentarlo en un momento.',

            default => 'No se pudo completar la operación. Vuelva a intentarlo; '
                     . 'si el problema sigue, avísenos indicando qué estaba haciendo.',
        };

        return new self($mensaje, $detalle, $sqlstate, $e);
    }
}
