<?php

declare(strict_types=1);

use CashMan\Dominio\Tarjeta\Pan;

Pruebas::grupo('Pan · algoritmo de Luhn');

/* Los números de prueba que publican las marcas. Si Luhn se rompiera,
   estos dejarían de validar y ninguna tarjeta emitida pasaría por un
   datáfono. */
foreach ([
    ['4242424242424242', 'Visa de prueba'],
    ['5555555555554444', 'Mastercard de prueba'],
    ['378282246310005',  'American Express de prueba'],
    ['4000056655665556', 'Visa débito de prueba'],
] as [$numero, $que]) {
    Pruebas::cierto(Pan::luhnValido($numero), $que . ' valida');
}

/* Y lo que NO debe pasar: un dígito cambiado rompe la suma. Es la
   razón de ser de Luhn —detectar el error de tecleo— y es lo que
   evita una consulta a la base por cada número mal escrito. */
foreach ([
    ['4242424242424243', 'último dígito cambiado'],
    ['4242424242424252', 'un dígito del medio cambiado'],
    ['4241424242424242', 'otro dígito del medio cambiado'],
    ['1234567890123456', 'número inventado'],
] as [$numero, $que]) {
    Pruebas::falso(Pan::luhnValido($numero), $que . ' no valida');
}

Pruebas::grupo('Pan · generación');

foreach (['visa' => 16, 'mastercard' => 16, 'amex' => 15] as $marca => $largo) {
    $pan = Pan::generar($marca);

    Pruebas::igual($largo, strlen($pan->numero),
        sprintf('%s tiene %d dígitos', $marca, $largo));

    Pruebas::cierto(Pan::luhnValido($pan->numero),
        sprintf('el %s generado pasa Luhn', $marca));

    Pruebas::igual(substr($pan->numero, -4), $pan->ultimos4,
        sprintf('los últimos 4 del %s coinciden con el número', $marca));

    Pruebas::igual(substr($pan->numero, 0, 6), $pan->bin6,
        sprintf('el BIN del %s son sus 6 primeros dígitos', $marca));
}

/* Mil números seguidos, todos válidos: si el dígito verificador se
   calculara mal en algún resto concreto, con uno solo no se vería. */
$malos = 0;
for ($i = 0; $i < 1000; $i++) {
    if (!Pan::luhnValido(Pan::generar('visa')->numero)) { $malos++; }
}
Pruebas::igual(0, $malos, 'mil números generados y los mil pasan Luhn');

Pruebas::grupo('Pan · el PAN no se escapa por accidente');

$pan = Pan::generar('visa');
$volcado = print_r($pan, true);

Pruebas::falso(str_contains($volcado, $pan->numero),
    'un print_r no enseña el número completo');

Pruebas::cierto(str_contains((string) $pan, $pan->ultimos4),
    'al convertirlo a texto solo salen los últimos 4');

Pruebas::grupo('Pan · el CVV es fijo y único por tarjeta');

$clave = 'clave-de-prueba-para-el-emisor';

$a = Pan::cvvDe('token-uno', 11, 2030, $clave, 'visa');
$b = Pan::cvvDe('token-uno', 11, 2030, $clave, 'visa');

Pruebas::igual($a, $b, 'la misma tarjeta da siempre el mismo CVV');
Pruebas::igual(3, strlen($a), 'son tres dígitos');
Pruebas::cierto(ctype_digit($a), 'y todos son dígitos');

Pruebas::igual(4, strlen(Pan::cvvDe('token-uno', 11, 2030, $clave, 'amex')),
    'American Express lleva cuatro');

/* Lo que lo hace servible: cambia con la tarjeta y con su vencimiento,
   porque si no dos plásticos del mismo banco compartirían CVV. */
Pruebas::falso(
    Pan::cvvDe('token-uno', 11, 2030, $clave) === Pan::cvvDe('token-dos', 11, 2030, $clave),
    'dos tarjetas distintas dan CVV distinto'
);

Pruebas::falso(
    Pan::cvvDe('token-uno', 11, 2030, $clave) === Pan::cvvDe('token-uno', 12, 2030, $clave),
    'cambiar el mes de vencimiento cambia el CVV'
);

Pruebas::falso(
    Pan::cvvDe('token-uno', 11, 2030, $clave) === Pan::cvvDe('token-uno', 11, 2030, 'otra-clave'),
    'con otra clave del emisor sale otro CVV'
);

/* Reparto razonable: mil tokens distintos no pueden caer en veinte
   valores. Si la derivación tuviera un sesgo, se vería aquí. */
$vistos = [];
for ($i = 0; $i < 1000; $i++) {
    $vistos[Pan::cvvDe('token-' . $i, 11, 2030, $clave)] = true;
}
Pruebas::cierto(count($vistos) > 600,
    sprintf('mil tarjetas dan %d CVV distintos de 1000 posibles', count($vistos)));
