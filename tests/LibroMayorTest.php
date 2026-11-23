<?php

declare(strict_types=1);

use CashMan\Dominio\Comun\AsientoDescuadrado;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/* El libro mayor toca la base de datos, así que no se puede probar en
 * el vacío como los demás. Pero lo que más importa de él son sus
 * GUARDAS —las condiciones que se niega a saltarse— y esas se pueden
 * comprobar sin escribir una sola fila, porque saltan antes de llegar
 * al disco.
 *
 * Todo lo que escribe va dentro de una transacción que se deshace al
 * final. Una prueba que deja rastro en los datos de un banco es una
 * prueba que nadie se atreve a correr. */

Pruebas::grupo('LibroMayor · exige transacción');

Pruebas::lanza(LogicException::class,
    static fn() => LibroMayor::registrar('ajuste', [
        ['cuenta_id' => 1, 'importe' => Monto::deCentavos(100), 'descripcion' => 'a'],
        ['cuenta_id' => 2, 'importe' => Monto::deCentavos(-100), 'descripcion' => 'b'],
    ], null, 'prueba-sin-transaccion'),
    'se niega a registrar fuera de una transacción');

Pruebas::grupo('LibroMayor · partida doble de verdad');

/* `enTransaccion` confirma al terminar bien, así que para no dejar
   rastro habría que lanzar... y entonces la excepción sale fuera y
   tumba el archivo. Aquí no hace falta ninguna de las dos cosas:
   todas estas comprobaciones saltan ANTES de escribir nada, así que
   la transacción se abre, no se toca y se confirma vacía. */
BaseDatos::enTransaccion(static function (): void {
    Pruebas::lanza(InvalidArgumentException::class,
        static fn() => LibroMayor::registrar('ajuste', [
            ['cuenta_id' => 1, 'importe' => Monto::deCentavos(100), 'descripcion' => 'solo uno'],
        ], null, 'prueba-un-solo-movimiento'),
        'rechaza un asiento con un solo movimiento');

    /* El descuadre: un centavo de diferencia y no entra. Es la
       invariante que sostiene todo lo demás del sistema. */
    Pruebas::lanza(AsientoDescuadrado::class,
        static fn() => LibroMayor::registrar('ajuste', [
            ['cuenta_id' => 1, 'importe' => Monto::deCentavos(100), 'descripcion' => 'a'],
            ['cuenta_id' => 2, 'importe' => Monto::deCentavos(-99), 'descripcion' => 'b'],
        ], null, 'prueba-descuadre-de-un-centavo'),
        'rechaza un asiento descuadrado por un centavo');

    Pruebas::lanza(AsientoDescuadrado::class,
        static fn() => LibroMayor::registrar('ajuste', [
            ['cuenta_id' => 1, 'importe' => Monto::deCentavos(100), 'descripcion' => 'a'],
            ['cuenta_id' => 2, 'importe' => Monto::deCentavos(100), 'descripcion' => 'b'],
        ], null, 'prueba-dos-positivos'),
        'rechaza dos movimientos del mismo signo');

    Pruebas::lanza(InvalidArgumentException::class,
        static fn() => LibroMayor::registrar('ajuste', [
            ['cuenta_id' => 1, 'importe' => Monto::cero(), 'descripcion' => 'a'],
            ['cuenta_id' => 2, 'importe' => Monto::cero(), 'descripcion' => 'b'],
        ], null, 'prueba-importes-cero'),
        'rechaza movimientos de importe cero');

    Pruebas::lanza(InvalidArgumentException::class,
        static fn() => LibroMayor::registrar('ajuste', [
            ['cuenta_id' => 1, 'importe' => Monto::deCentavos(100, 'USD'), 'descripcion' => 'a'],
            ['cuenta_id' => 2, 'importe' => Monto::deCentavos(-100, 'EUR'), 'descripcion' => 'b'],
        ], null, 'prueba-dos-monedas'),
        'rechaza mezclar monedas en un mismo asiento');

    // Nada escrito: las guardas saltaron todas antes del disco.
});

Pruebas::grupo('LibroMayor · el saldo que dice la tabla es el que suman sus movimientos');

/* Esto no prueba una regla, prueba los DATOS: que ninguna cuenta real
   tenga un saldo que sus propios movimientos no expliquen. Es la misma
   igualdad que comprueba `bin/verificar-cuadre.php`, aquí para que
   falle también al correr las pruebas. */
$descuadradas = BaseDatos::todas(
    'SELECT c.numero_cuenta, c.saldo_cent,
            COALESCE((SELECT SUM(m.importe_cent) FROM movimientos m
                       WHERE m.cuenta_id = c.id), 0) AS suma
       FROM cuentas c
      WHERE c.estado <> "cerrada"
     HAVING suma <> c.saldo_cent'
);

Pruebas::igual(0, count($descuadradas),
    sprintf('ninguna cuenta descuadrada (hay %d)', count($descuadradas)));

foreach ($descuadradas as $d) {
    printf("          %s: saldo %s, movimientos %s\n",
        $d['numero_cuenta'],
        number_format((int) $d['saldo_cent'] / 100, 2),
        number_format((int) $d['suma'] / 100, 2));
}

Pruebas::grupo('LibroMayor · ningún asiento descuadrado en el libro');

$malos = (int) BaseDatos::unValor('SELECT COUNT(*) FROM v_asientos_descuadrados');
Pruebas::igual(0, $malos, sprintf('la vista de descuadres está vacía (hay %d)', $malos));

Pruebas::grupo('LibroMayor · identificadores únicos');

$uuids = [];
for ($i = 0; $i < 5000; $i++) {
    $uuids[LibroMayor::uuid4()] = true;
}
Pruebas::igual(5000, count($uuids), 'cinco mil uuid sin una sola repetición');
