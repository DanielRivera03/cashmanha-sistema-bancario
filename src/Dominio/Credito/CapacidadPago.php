<?php
declare(strict_types=1);

namespace CashMan\Dominio\Credito;

use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Parametros;

/**
 * Capacidad de pago: cuánto puede pagar de verdad un cliente.
 *
 * `Amortizacion::evaluarCapacidad()` ya decía si una cuota cabía en el
 * ingreso, pero solo eso: sí o no. Quien capturaba una solicitud tenía
 * que ir probando montos a mano hasta dar con uno que pasara.
 *
 * Esto resuelve la pregunta al revés, que es la que de verdad importa:
 * dado lo que esta persona gana y lo que ya debe, ¿cuánto es lo máximo
 * que se le puede prestar sin comprometerla? Y para una tarjeta, ¿qué
 * límite aguanta?
 *
 * Dos cuidados en el cálculo:
 *
 *  · Los seguros obligatorios se cobran sobre el saldo, así que forman
 *    parte de la cuota y hay que descontarlos del margen disponible.
 *    Pero dependen del monto, y el monto depende de ellos: es circular.
 *    Se resuelve iterando unas pocas veces, que converge rápido.
 *
 *  · El resultado se redondea hacia ABAJO. Prestar un dólar de más del
 *    que alguien puede pagar es el inicio de una mora.
 */
final class CapacidadPago
{
    /** Proporción máxima del ingreso disponible que puede ir a la cuota. */
    public const RELACION_POR_DEFECTO = 0.40;

    /**
     * Cuota máxima que soporta el cliente.
     *
     * Ingreso menos lo que ya debe cada mes, por la proporción máxima.
     */
    public static function cuotaMaxima(
        Monto $ingresoMensual,
        Monto $compromisos,
        ?float $maxRelacion = null
    ): Monto {
        $relacion = $maxRelacion ?? self::relacionConfigurada();
        $disponible = $ingresoMensual->menos($compromisos);

        if (!$disponible->esPositivo()) {
            return Monto::cero();
        }

        return $disponible->por($relacion);
    }

    /**
     * Monto máximo financiable a un plazo y una tasa dados.
     *
     * Invierte la fórmula de amortización francesa:
     *
     *     P = cuota × (1 − (1+i)^−n) / i
     *
     * y después descuenta los seguros, que también salen de la misma
     * cuota. Con tasa cero la fórmula degenera y se usa cuota × n.
     *
     * @param int $tasaAnualBp Tasa anual en puntos base
     * @param int $plazoMeses  Número de cuotas
     */
    public static function montoMaximo(
        Monto $ingresoMensual,
        Monto $compromisos,
        int $tasaAnualBp,
        int $plazoMeses,
        ?float $maxRelacion = null
    ): Monto {
        $cuotaMax = self::cuotaMaxima($ingresoMensual, $compromisos, $maxRelacion);

        if (!$cuotaMax->esPositivo() || $plazoMeses < 1) {
            return Monto::cero();
        }

        $i = $tasaAnualBp / 10000 / 12;

        // Primera aproximación: toda la cuota va a capital e intereses.
        $capital = self::capitalDesdeCuota($cuotaMax->centavos, $i, $plazoMeses);

        /* Ahora el ajuste por los seguros. Su prima depende del saldo, y
           el saldo es justo lo que se está calculando, así que se itera:
           con el capital estimado se calcula la prima del primer mes, se
           le resta a la cuota disponible y se vuelve a calcular. Tres
           vueltas bastan para que deje de moverse en centavos. */
        for ($vuelta = 0; $vuelta < 3; $vuelta++) {
            $prima = CostosCredito::seguroVida($capital)
                   + CostosCredito::seguroEmpleo($capital);

            $paraCuota = $cuotaMax->centavos - $prima;

            if ($paraCuota <= 0) {
                return Monto::cero();
            }

            $capital = self::capitalDesdeCuota($paraCuota, $i, $plazoMeses);
        }

        // Hacia abajo, a dólares enteros: una oferta de $4,987.43 no la
        // escribe nadie, y redondear hacia arriba sería prestar de más.
        return Monto::deCentavos(intdiv(max(0, $capital), 100) * 100);
    }

    /**
     * Límite de tarjeta que aguanta el cliente.
     *
     * El criterio no es la cuota sino el pago mínimo: una tarjeta no
     * tiene plazo, y lo que el cliente debe poder cubrir cada mes es su
     * pago mínimo más los seguros. De ahí sale el límite.
     *
     * Se acota además a un múltiplo del ingreso disponible, porque un
     * límite muy alto frente al sueldo es una invitación a sobregirarse
     * aunque el mínimo salga pagable.
     */
    /**
     * @param int|null $vecesIngreso Múltiplo del ingreso que admite ESTE
     *        cliente. Lo pone su segmento —un preferente llega a cuatro
     *        veces y un patrimonial a cinco— y por eso entra desde
     *        fuera: el dominio no debe saber de segmentos, pero sí
     *        tiene que poder aplicar lo que valen.
     */
    public static function limiteTarjeta(
        Monto $ingresoMensual,
        Monto $compromisos,
        ?float $maxRelacion = null,
        ?int $vecesIngreso = null
    ): Monto {
        $cuotaMax = self::cuotaMaxima($ingresoMensual, $compromisos, $maxRelacion);

        if (!$cuotaMax->esPositivo()) {
            return Monto::cero();
        }

        $pctMinimo = max(1, Parametros::entero('tarjeta.pago_minimo_pct', 500));

        // Si el mínimo es el 5 % del saldo, el saldo que deja un mínimo
        // igual a la cuota máxima es cuota / 5 %.
        $porMinimo = (int) floor($cuotaMax->centavos * 10000 / $pctMinimo);

        // Tope por múltiplo del ingreso disponible.
        $veces = max(1, $vecesIngreso
            ?? Parametros::entero('tarjeta.limite_veces_ingreso', 3));
        $disponible = $ingresoMensual->menos($compromisos);
        $porIngreso = $disponible->centavos * $veces;

        $limite = min($porMinimo, $porIngreso);

        // A centenas de dólar: los límites se publican redondos.
        return Monto::deCentavos(intdiv(max(0, $limite), 10000) * 10000);
    }

    /**
     * Compromisos mensuales ya adquiridos.
     *
     * Lo que el cliente ya paga cada mes: cuotas de crédito vigentes más
     * el pago mínimo de sus tarjetas. Sin esto, alguien con tres créditos
     * abiertos parecería tener el mismo margen que quien no debe nada.
     */
    public static function relacionConfigurada(): float
    {
        $bp = Parametros::entero('credito.relacion_cuota_ingreso_bp', 4000);

        // Entre el 10 % y el 60 %: fuera de ahí, el parámetro está mal
        // puesto y es preferible la regla estándar que un disparate.
        if ($bp < 1000 || $bp > 6000) {
            return self::RELACION_POR_DEFECTO;
        }

        return $bp / 10000;
    }

    /**
     * Despeja el capital de la fórmula de cuota.
     *
     * @param int   $cuotaCent Cuota disponible para capital e intereses
     * @param float $i         Tasa mensual en tanto por uno
     */
    private static function capitalDesdeCuota(int $cuotaCent, float $i, int $plazoMeses): int
    {
        if ($i <= 0.0) {
            return $cuotaCent * $plazoMeses;
        }

        $factor = (1 - (1 + $i) ** (-$plazoMeses)) / $i;

        return (int) floor($cuotaCent * $factor);
    }
}
