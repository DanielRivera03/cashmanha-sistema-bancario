<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

/**
 * La contraseña era correcta, pero falta el código del segundo factor.
 *
 * Es distinta de CredencialesInvalidas a propósito: aquí el usuario ya
 * demostró saber su contraseña, así que la interfaz puede pedirle el
 * código sin volver a pedir todo. Lanzarla NO abre sesión.
 */
final class RequiereSegundoFactor extends \RuntimeException
{
    public function __construct(string $mensaje, private int $usuarioId)
    {
        parent::__construct($mensaje);
    }

    public function usuarioId(): int
    {
        return $this->usuarioId;
    }
}
