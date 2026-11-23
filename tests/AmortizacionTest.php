<?php

declare(strict_types=1);

use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\Amortizacion;

Pruebas::grupo('Amortización · rechaza lo imposible');

Pruebas::lanza(InvalidArgumentException::class,
    static fn() => new Amortizacion(Monto::deCentavos(100000), 1800, 0),
    'rechaza plazo de cero cuotas');

Pruebas::lanza(InvalidArgumentException::class,
    static fn() => new Amortizacion(Monto::deCentavos(100000), 1800, 481),
    'rechaza plazo de más de 40 años');

Pruebas::lanza(InvalidArgumentException::class,
    static fn() => new Amortizacion(Monto::deCentavos(100000), -1, 12),
    'rechaza tasa negativa');

Pruebas::lanza(InvalidArgumentException::class,
    static fn() => new Amortizacion(Monto::cero(), 1800, 12),
    'rechaza monto cero');

Pruebas::grupo('Amortización · tasa cero es un reparto simple');

$sinInteres = new Amortizacion(Monto::deCentavos(120000), 0, 12);

Pruebas::igual(10000, $sinInteres->cuota()->centavos,
    '$1,200.00 a 12 meses sin interés son $100.00 al mes');

Pruebas::grupo('Amortización · sistema francés');

/* Caso de libro, comprobable a mano: $10,000.00 al 12% anual a 12
   meses da una cuota de $888.49. Si alguien toca la fórmula, esta
   cifra se mueve y la prueba lo dice. */
$credito = new Amortizacion(Monto::deCentavos(1000000), 1200, 12);

Pruebas::igual(88849, $credito->cuota()->centavos,
    '$10,000 al 12% a 12 meses: cuota de $888.49');

Pruebas::grupo('Amortización · la tabla liquida en cero');

/* La propiedad que de verdad importa. Da igual la fórmula: si al final
   de la tabla queda saldo, el crédito nunca se cancela; y si queda
   negativo, el banco cobró de más. Debe terminar exactamente en cero. */
foreach ([
    [1000000, 1200, 12],
    [250000,  1800, 24],
    [5000000, 2400, 60],
    [100000,  0,    6],
    [333333,  1550, 37],
] as [$capital, $tasa, $plazo]) {
    $plan  = new Amortizacion(Monto::deCentavos($capital), $tasa, $plazo);
    $tabla = $plan->tabla();

    Pruebas::igual($plazo, count($tabla),
        sprintf('%d cuotas para un plazo de %d', $plazo, $plazo));

    /* La tabla devuelve objetos `Monto`, no enteros: el dominio no
       deja que el dinero ande suelto como número. */
    $ultima = $tabla[count($tabla) - 1];
    $saldoFinal = $ultima['saldo']->centavos;

    Pruebas::igual(0, $saldoFinal,
        sprintf('$%s al %s%% a %d meses deja saldo cero',
            number_format($capital / 100, 2), $tasa / 100, $plazo));

    /* Y la suma de los capitales tiene que ser el préstamo entero: ni
       un centavo de más ni de menos. */
    $sumaCapital = 0;
    foreach ($tabla as $c) {
        $sumaCapital += $c['capital']->centavos;
    }

    Pruebas::igual($capital, $sumaCapital,
        sprintf('los capitales de las %d cuotas suman el préstamo', $plazo));
}

Pruebas::grupo('Amortización · capacidad de pago');

$sobrado = Amortizacion::evaluarCapacidad(
    Monto::deCentavos(200000),
    Monto::deCentavos(20000),
    Monto::deCentavos(30000)
);
Pruebas::cierto($sobrado['aprueba'], 'cuota holgada frente al ingreso: aprueba');

$ahogado = Amortizacion::evaluarCapacidad(
    Monto::deCentavos(100000),
    Monto::deCentavos(20000),
    Monto::deCentavos(70000)
);
Pruebas::falso($ahogado['aprueba'], 'cuota que se come el ingreso: no aprueba');

/* El caso que de verdad hay que blindar: compromisos por encima del
   ingreso. Sin la guarda, la relación se divide entre un número
   negativo y sale aprobado alguien que ya no puede pagar lo que tiene. */
$imposible = Amortizacion::evaluarCapacidad(
    Monto::deCentavos(50000),
    Monto::deCentavos(80000),
    Monto::deCentavos(10000)
);
Pruebas::falso($imposible['aprueba'], 'con más compromisos que ingreso: no aprueba');
Pruebas::cierto($imposible['maximo']->esCero(), 'y su máximo prestable es cero');

/* Y el borde exacto: compromisos iguales al ingreso, disponible cero.
   Dividir entre cero es lo que rompe esta clase de cálculos. */
$justo = Amortizacion::evaluarCapacidad(
    Monto::deCentavos(50000),
    Monto::deCentavos(50000),
    Monto::deCentavos(1)
);
Pruebas::falso($justo['aprueba'], 'con disponible cero no aprueba ni un centavo');
