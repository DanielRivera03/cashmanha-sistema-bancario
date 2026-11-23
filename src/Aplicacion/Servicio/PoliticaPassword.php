<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

/**
 * La contraseña propuesta no cumple la política.
 *
 * Lleva la lista de incumplimientos para poder mostrárselos todos al
 * usuario de una vez, en lugar de hacerle descubrirlos uno por uno.
 */
final class PoliticaPassword extends \RuntimeException
{
    /** @param array<int,string> $fallos */
    public function __construct(private array $fallos)
    {
        parent::__construct(implode(' ', $fallos));
    }

    /** @return array<int,string> */
    public function fallos(): array
    {
        return $this->fallos;
    }
}
