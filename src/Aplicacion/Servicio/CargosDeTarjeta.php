<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Parametros;

/**
 * Lo que cuesta sacar dinero de una tarjeta de crédito.
 *
 * Un adelanto de efectivo y un traslado de saldo no son compras: el
 * banco entrega dinero y cobra por hacerlo. Son dos cargos distintos y
 * conviene no mezclarlos, porque en el estado de cuenta aparecen por
 * separado y el cliente pregunta por cada uno:
 *
 *   · la COMISIÓN, un porcentaje de lo dispuesto, con un mínimo. El
 *     mínimo es lo que evita que adelantar cinco dólares salga por
 *     veinticinco centavos;
 *   · el GASTO ADMINISTRATIVO, una cantidad fija por operación, que no
 *     depende de cuánto se saque.
 *
 * Los importes salen de parámetros y no de números escritos aquí: una
 * comisión es una decisión del negocio y cambia sin que nadie toque el
 * código. Los valores por omisión son los de una tarjeta corriente de
 * la plaza —5 % con mínimo de $5.00, y $3.00 de gestión—, para que el
 * sistema haga algo razonable antes de que nadie los configure.
 *
 * El descuento por segmento se aplica igual que en el resto: quien es
 * preferente o patrimonial lo tiene prometido en su pantalla de
 * beneficios y tiene que notarlo también aquí.
 */
final class CargosDeTarjeta
{
    private function __construct(
        public readonly Monto $comision,
        public readonly Monto $gasto,
        public readonly int $comisionBp,
        public readonly int $descuentoBp
    ) {
    }

    /** Lo que suman los dos cargos. */
    public function total(): Monto
    {
        return Monto::deCentavos($this->comision->centavos + $this->gasto->centavos);
    }

    public function hayAlguno(): bool
    {
        return $this->total()->centavos > 0;
    }

    /**
     * Los cargos de un adelanto de efectivo.
     *
     * @param int $titularId Para el descuento que le corresponda por su segmento.
     */
    public static function porAdelanto(Monto $dispuesto, int $titularId): self
    {
        return self::calcular(
            $dispuesto,
            $titularId,
            Parametros::entero('tarjeta.adelanto_comision_bp', 500),
            Parametros::entero('tarjeta.adelanto_comision_minima_cent', 500),
            Parametros::entero('tarjeta.adelanto_gasto_admin_cent', 300)
        );
    }

    /**
     * Los cargos de un traslado de saldo.
     *
     * Son más bajos que los del adelanto a propósito: un traslado trae
     * deuda de otra tarjeta y al banco le interesa, así que se cobra la
     * gestión pero no se castiga la operación.
     */
    public static function porTraslado(Monto $trasladado, int $titularId): self
    {
        return self::calcular(
            $trasladado,
            $titularId,
            Parametros::entero('tarjeta.traslado_comision_bp', 300),
            Parametros::entero('tarjeta.traslado_comision_minima_cent', 300),
            Parametros::entero('tarjeta.traslado_gasto_admin_cent', 200)
        );
    }

    private static function calcular(
        Monto $base,
        /* Se conserva aunque hoy no cambie nada: es el dato que
           haría falta el día que el banco decida que el segmento
           también toca la comisión, y evita cambiar las llamadas. */
        int $titularId,
        int $comisionBp,
        int $minimoCent,
        int $gastoCent
    ): self {
        /* Sin descuento por segmento, y es a propósito.
         *
         * `Segmento::BENEFICIOS` promete al preferente y al patrimonial
         * un punto menos de TASA, medio punto más en su ahorro, más
         * planes y sobregiros sin cargo. De la comisión de un adelanto
         * no dice nada, ni la pantalla de beneficios la menciona.
         *
         * Descontarla aquí sería lo contrario de lo que hay que hacer:
         * el sistema tiene que cumplir lo que promete, no regalar por
         * su cuenta cosas que nunca prometió —y que luego nadie sabría
         * explicar de dónde salen—. Si algún día el banco decide
         * incluirla, se añade al cuadro de beneficios primero y de ahí
         * se lee, como se hizo con la tasa. */
        $descuentoBp = 0;
        $bpFinal     = max(0, $comisionBp);

        $comision = (int) round($base->centavos * $bpFinal / 10000);

        /* El mínimo solo se aplica si de verdad se cobra comisión.
           A quien el segmento le dejó la comisión en cero no se le
           cobra el mínimo por la puerta de atrás. */
        if ($bpFinal > 0 && $comision < $minimoCent) {
            $comision = $minimoCent;
        }

        return new self(
            Monto::deCentavos(max(0, $comision)),
            Monto::deCentavos(max(0, $gastoCent)),
            $bpFinal,
            $descuentoBp
        );
    }
}
