<?php

declare(strict_types=1);

use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Comun\MontoInvalido;

Pruebas::grupo('Monto · el dinero no se guarda en float');

/* Lo primero que hay que demostrar: por qué el dinero son enteros.
   0.1 + 0.2 !== 0.3 en coma flotante, y ese centavo perdido es el que
   descuadra un libro al cabo de mil operaciones. */
Pruebas::falso(0.1 + 0.2 === 0.3, 'en coma flotante 0.1 + 0.2 no es 0.3');

Pruebas::igual(
    Monto::deCentavos(30),
    Monto::deCentavos(10)->mas(Monto::deCentavos(20)),
    'en centavos, 0.10 + 0.20 sí son 0.30'
);

Pruebas::grupo('Monto · entrada del usuario');

foreach ([
    ['1234.56', 123456, 'decimal con punto'],
    ['1,234.56', 123456, 'con separador de miles'],
    ['$1,234.56', 123456, 'con símbolo de moneda'],
    ['  10  ', 1000, 'con espacios alrededor'],
    ['0.01', 1, 'un centavo'],
] as [$entrada, $esperado, $que]) {
    Pruebas::igual($esperado, Monto::deEntradaUsuario($entrada)->centavos, $que);
}

/* Lo que tiene que RECHAZAR. Un importe que se cuela mal convertido es
   un cobro equivocado que nadie detecta hasta el estado de cuenta.
   Entre ellos el cero, que a primera vista parece válido y no lo es:
   nadie deposita, retira ni transfiere cero, y dejarlo pasar llena el
   libro de asientos vacíos que alguien tiene que explicar después. */
foreach ([
    ['0', 'un importe de cero'],
    ['abc', 'texto que no es un número'],
    ['', 'cadena vacía'],
    ['1.234', 'tres decimales'],
    ['--5', 'doble signo'],
] as [$entrada, $que]) {
    Pruebas::lanza(MontoInvalido::class,
        static fn() => Monto::deEntradaUsuario($entrada),
        'rechaza ' . $que);
}

Pruebas::grupo('Monto · repartir sin perder centavos');

/* El reparto es donde se pierde dinero sin que nadie mire: 100 entre 3
   da 33.33 tres veces, que son 99.99. El centavo que falta tiene que
   ir a alguna parte, y la suma de las partes tiene que ser el todo. */
foreach ([
    [10000, 3],
    [10000, 7],
    [155, 12],
    [1, 5],
    [999999, 13],
] as [$centavos, $partes]) {
    $trozos = Monto::deCentavos($centavos)->repartir($partes);

    $suma = 0;
    foreach ($trozos as $t) { $suma += $t->centavos; }

    Pruebas::igual($partes, count($trozos),
        sprintf('%d entre %d da %d partes', $centavos, $partes, $partes));
    Pruebas::igual($centavos, $suma,
        sprintf('%d entre %d suma exactamente %d', $centavos, $partes, $centavos));
}

$trozos = Monto::deCentavos(10000)->repartir(3);
Pruebas::igual(3334, $trozos[0]->centavos, 'el centavo sobrante va a la primera parte');
Pruebas::igual(3333, $trozos[2]->centavos, 'y las demás quedan parejas');

Pruebas::grupo('Monto · puntos base');

Pruebas::igual(500, Monto::deCentavos(10000)->porPuntosBase(500)->centavos,
    '5% de $100.00 son $5.00');
Pruebas::igual(0, Monto::deCentavos(10000)->porPuntosBase(0)->centavos,
    '0 puntos base no cobra nada');
Pruebas::igual(10000, Monto::deCentavos(10000)->porPuntosBase(10000)->centavos,
    '10.000 puntos base es el 100%');

Pruebas::grupo('Monto · comparación y signo');

Pruebas::cierto(Monto::deCentavos(100)->mayorQue(Monto::deCentavos(99)), 'mayor que');
Pruebas::falso(Monto::deCentavos(100)->mayorQue(Monto::deCentavos(100)), 'no es mayor que sí mismo');
Pruebas::cierto(Monto::deCentavos(100)->mayorOIgualQue(Monto::deCentavos(100)), 'mayor o igual');
Pruebas::cierto(Monto::cero()->esCero(), 'cero es cero');
Pruebas::falso(Monto::cero()->esPositivo(), 'cero no es positivo');
Pruebas::cierto(Monto::deCentavos(-1)->esNegativo(), 'negativo es negativo');
Pruebas::igual(100, Monto::deCentavos(-100)->absoluto()->centavos, 'valor absoluto');
Pruebas::igual(-100, Monto::deCentavos(100)->negado()->centavos, 'negado');

Pruebas::grupo('Monto · la resta puede ser negativa');

/* Un saldo que se pasa de cero tiene que PODER ser negativo: una
   cuenta técnica de contrapartida vive en números rojos por diseño.
   Si la resta se recortara a cero, el libro dejaría de cuadrar. */
Pruebas::igual(-5000,
    Monto::deCentavos(1000)->menos(Monto::deCentavos(6000))->centavos,
    'restar de más da negativo, no cero');
