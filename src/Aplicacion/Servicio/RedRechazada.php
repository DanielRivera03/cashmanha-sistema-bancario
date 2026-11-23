<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

/**
 * Una operación que la red no autoriza.
 *
 * Lleva el código ISO 8583 además del mensaje porque quien la recibe
 * necesita las dos cosas por separado: el código para decidir qué hacer
 * —reintentar, pedir otra tarjeta, parar— y el mensaje para enseñárselo
 * a alguien. Leer la decisión del texto de un error es lo que acaba
 * rompiéndose cuando alguien corrige una tilde.
 */
final class RedRechazada extends \RuntimeException
{
    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        /* De qué tarjeta se trataba, cuando se llegó a saber. Sirve
           para que el comercio pueda decir «su tarjeta ····1234 fue
           rechazada» y el cliente sepa cuál de las suyas probar. */
        public readonly ?string $ultimos4 = null,
        public readonly ?string $marca = null
    ) {
        parent::__construct($mensaje);
    }
}
