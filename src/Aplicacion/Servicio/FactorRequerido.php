<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

/**
 * Esta OPERACIÓN necesita un factor adicional.
 *
 * Es distinta de `RequiereSegundoFactor`, que es la del login: allí el
 * usuario todavía no ha entrado y la sesión no existe.
 *
 * Aquí la sesión está abierta y el cliente ya demostró quién es. Lo
 * que falta es confirmar ESTA operación concreta, que es lo que pide
 * la norma de canales digitales: un factor que dependa de la
 * transacción y no de la sesión.
 *
 * La diferencia importa para la pantalla: ante ésta se pide un código
 * sin sacar al cliente de donde está; ante la otra, se le manda a
 * iniciar sesión.
 */
final class FactorRequerido extends \RuntimeException
{
    public function __construct(
        string $mensaje,
        private string $factor = 'otp',
        private string $operacion = ''
    ) {
        parent::__construct($mensaje);
    }

    /** `otp` para el código al correo, `totp` para la app. */
    public function factor(): string
    {
        return $this->factor;
    }

    public function operacion(): string
    {
        return $this->operacion;
    }
}
