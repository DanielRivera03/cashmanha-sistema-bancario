<?php

declare(strict_types=1);

use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\Mora;

/* La mora depende de parámetros que viven en la base de datos, así que
   estas pruebas leen los que haya configurados en vez de dar por hecho
   unos valores. Lo que se comprueba es la REGLA, no la tarifa: que el
   cargo sea proporcional a los días, que respete la gracia y que nunca
   supere la deuda que lo origina. Si mañana el banco cambia $5.99 por
   otra cifra, estas pruebas siguen valiendo. */

$diario = Mora::cargoDeUnDia();
$gracia = max(0, Mora::diasCobrables(1000) === 1000 ? 0 : 1000 - Mora::diasCobrables(1000));

Pruebas::grupo(sprintf('Mora · cargo diario vigente: %s, gracia: %d día(s)',
    Monto::deCentavos($diario)->formateado(), $gracia));

Pruebas::grupo('Mora · sin atraso no hay recargo');

Pruebas::igual(0, Mora::calcular(Monto::deCentavos(15000), 0)->centavos, 'cero días no cobra');
Pruebas::igual(0, Mora::calcular(Monto::deCentavos(15000), -5)->centavos, 'días negativos no cobran');
Pruebas::igual(0, Mora::diasCobrables(0), 'cero días cobrables');
Pruebas::igual(0, Mora::diasCobrables(-10), 'atraso negativo no es cobrable');

Pruebas::grupo('Mora · nada que deber, nada que cobrar');

Pruebas::igual(0, Mora::calcular(Monto::cero(), 90)->centavos,
    'una cuota saldada no devenga mora aunque esté vencida');
Pruebas::igual(0, Mora::calcular(Monto::deCentavos(-100), 90)->centavos,
    'un saldo negativo tampoco');

Pruebas::grupo('Mora · es proporcional a los días');

/* Un saldo grande, para que el tope por deuda no se active y se pueda
   ver la proporcionalidad limpia. */
$saldoAmplio = Monto::deCentavos(100000000);

$unDia   = Mora::calcular($saldoAmplio, 1 + $gracia)->centavos;
$diezDias = Mora::calcular($saldoAmplio, 10 + $gracia)->centavos;

Pruebas::igual($diario, $unDia, 'un día cobrable cuesta un cargo diario');
Pruebas::igual($diario * 10, $diezDias, 'diez días cuestan diez veces');
Pruebas::igual($unDia * 10, $diezDias, 'y diez veces uno es lo mismo que diez');

Pruebas::grupo('Mora · nunca supera la deuda que la origina');

/* Esto es lo que impide el abuso: 600 días por el cargo diario serían
   miles de dólares sobre una cuota de ciento cincuenta. La regla dice
   que el recargo se recorta al saldo vencido. */
$cuota = Monto::deCentavos(15564);

foreach ([200, 600, 2000, 10000] as $dias) {
    $mora = Mora::calcular($cuota, $dias);

    Pruebas::cierto($mora->centavos <= $cuota->centavos,
        sprintf('con %d días de atraso la mora no pasa de %s (dio %s)',
            $dias, $cuota->formateado(), $mora->formateado()));
}

Pruebas::grupo('Mora · el tope se aplica justo donde toca');

/* En el límite exacto: tantos días como para igualar la cuota. */
if ($diario > 0) {
    $diasJustos = intdiv($cuota->centavos, $diario);

    $antes = Mora::calcular($cuota, $diasJustos + $gracia)->centavos;
    $pasado = Mora::calcular($cuota, $diasJustos + $gracia + 50)->centavos;

    Pruebas::cierto($antes <= $cuota->centavos, 'en el límite no se pasa');
    Pruebas::igual($cuota->centavos, $pasado, 'pasado el límite se queda en la deuda');
}

Pruebas::grupo('Mora · el mismo cálculo dos veces da lo mismo');

/* Suena obvio y no lo es: si la mora dependiera de la hora, del azar o
   de una lectura de la base, la ficha del cliente y la caja mostrarían
   cifras distintas para la misma cuota. Ya pasó una vez. */
$a = Mora::calcular($cuota, 45)->centavos;
$b = Mora::calcular($cuota, 45)->centavos;

Pruebas::igual($a, $b, 'es una función pura de saldo y días');
