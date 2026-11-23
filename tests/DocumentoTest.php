<?php

declare(strict_types=1);

use CashMan\Dominio\Cliente\Documento;

/**
 * El documento de identidad: tipo, formato y normalización.
 *
 * Lo que se cuida aquí es que el mismo documento escrito de dos
 * maneras acabe guardado igual. Si no, el cliente que se registró con
 * «01234567-8» y el que vino a ventanilla con «012345678» son dos
 * clientes distintos para cualquier búsqueda, y nadie se entera hasta
 * que ya hay dos expedientes del mismo señor.
 */

Pruebas::grupo('Documento · el mismo número se guarda siempre igual');

Pruebas::igual(
    '012345678',
    Documento::de('dui', '01234567-8')->crudo(),
    'el DUI con guion se guarda sin guion'
);

Pruebas::igual(
    Documento::de('dui', '01234567-8')->crudo(),
    Documento::de('dui', '012345678')->crudo(),
    'con guion y sin guion son el mismo documento'
);

Pruebas::igual(
    '012345678',
    Documento::de('dui', ' 0123 4567 8 ')->crudo(),
    'los espacios de por medio no cuentan'
);

Pruebas::igual(
    '01234567-8',
    Documento::de('dui', '012345678')->formateado(),
    'y se enseña con su guion'
);

Pruebas::grupo('Documento · DUI');

Pruebas::lanza(
    InvalidArgumentException::class,
    fn() => Documento::de('dui', '01234567'),
    'ocho dígitos no son un DUI'
);

Pruebas::lanza(
    InvalidArgumentException::class,
    fn() => Documento::de('dui', '0123456789'),
    'diez dígitos tampoco'
);

/* El verificador NO se comprueba, y es a propósito: el RNPN no publica
   el algoritmo y las versiones que circulan no coinciden entre sí.
   Rechazar con una fórmula sin confirmar deja fuera a un cliente de
   verdad, en la ventanilla, sin manera de que nadie lo arregle. */
Pruebas::igual(
    '00000000-0',
    Documento::de('dui', '000000000')->formateado(),
    'el dígito verificador no se comprueba'
);

Pruebas::grupo('Documento · NIT, homologado con el DUI');

Pruebas::igual(
    '0614-280598-102-5',
    Documento::de('nit', '06142805981025')->formateado(),
    'catorce dígitos con sus tres guiones'
);

/* Desde 2022 el NIT de la persona natural ES su DUI. Si alguien lo
   registra como NIT, se guarda como lo que es; de otro modo el mismo
   número quedaría con dos tipos distintos en dos registros. */
Pruebas::igual(
    'dui',
    Documento::de('nit', '012345678')->tipo(),
    'un NIT de nueve dígitos es un DUI'
);

Pruebas::cierto(
    Documento::de('nit', '012345678')->esHomologado(),
    'y queda marcado como homologado'
);

Pruebas::lanza(
    InvalidArgumentException::class,
    fn() => Documento::de('nit', '06142805981'),
    'once dígitos no son un NIT'
);

Pruebas::lanza(
    InvalidArgumentException::class,
    fn() => Documento::de('nit', '061428059810255'),
    'quince tampoco'
);

Pruebas::grupo('Documento · carné de residente');

Pruebas::igual(
    'AB12345CD',
    Documento::de('residente', ' ab12-345 cd ')->crudo(),
    'sin máscara, en mayúsculas y sin separadores'
);

Pruebas::lanza(
    InvalidArgumentException::class,
    fn() => Documento::de('residente', 'AB12'),
    'cuatro caracteres es muy corto'
);

Pruebas::lanza(
    InvalidArgumentException::class,
    fn() => Documento::de('residente', str_repeat('A', 21)),
    'veintiuno es muy largo'
);

Pruebas::grupo('Documento · lo que tiene que rechazar');

Pruebas::lanza(
    InvalidArgumentException::class,
    fn() => Documento::de('pasaporte', '012345678'),
    'un tipo que no existe'
);

Pruebas::lanza(
    InvalidArgumentException::class,
    fn() => Documento::de('dui', ''),
    'sin número no hay documento'
);

Pruebas::lanza(
    InvalidArgumentException::class,
    fn() => Documento::de('dui', '   '),
    'solo espacios tampoco'
);

Pruebas::grupo('Documento · oNada(), para lo opcional');

Pruebas::igual(null, Documento::oNada('dui', ''), 'vacío devuelve null');
Pruebas::igual(null, Documento::oNada(null, '012345678'), 'sin tipo devuelve null');
Pruebas::igual(null, Documento::oNada('dui', 'esto no es un DUI'), 'basura devuelve null');

Pruebas::igual(
    '012345678',
    Documento::oNada('dui', '012345678')?->crudo(),
    'uno bueno sí vuelve'
);

Pruebas::grupo('Documento · lo que se enseña');

Pruebas::igual('DUI', Documento::de('dui', '012345678')->nombre(), 'el nombre del DUI');
Pruebas::igual('NIT', Documento::de('nit', '06142805981025')->nombre(), 'el nombre del NIT');

Pruebas::igual(
    'Carné de residente',
    Documento::de('residente', 'AB12345CD')->nombre(),
    'el nombre del carné'
);
