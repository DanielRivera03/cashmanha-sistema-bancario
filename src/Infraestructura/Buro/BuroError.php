<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Buro;

/**
 * Falla de la consulta al buró.
 *
 * Se distingue del resto de errores porque no debe tumbar una solicitud:
 * que el buró no conteste es un problema del buró, no del cliente. Quien
 * la captura decide si espera o si evalúa sin ese dato, y queda anotado.
 */
final class BuroError extends \RuntimeException
{
}
