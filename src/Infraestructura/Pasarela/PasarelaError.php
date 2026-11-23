<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Pasarela;

/**
 * Fallo al hablar con la pasarela de pago.
 *
 * Es una excepción propia y no una genérica para que quien la capture
 * sepa que el problema está fuera: la red, las credenciales o el propio
 * Stripe. Un fallo aquí nunca debe confundirse con un error de los
 * datos del usuario ni con un descuadre contable.
 */
final class PasarelaError extends \RuntimeException
{
}
