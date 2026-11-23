<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Seguridad;

/**
 * Se superó un límite de tasa.
 *
 * Lleva los segundos que faltan para poder reintentar, de modo que el
 * controlador pueda devolver una cabecera Retry-After correcta.
 */
final class LimiteExcedido extends \RuntimeException
{
    public function __construct(string $mensaje, private int $reintentarEn = 0)
    {
        parent::__construct($mensaje);
    }

    public function reintentarEn(): int
    {
        return $this->reintentarEn;
    }
}
