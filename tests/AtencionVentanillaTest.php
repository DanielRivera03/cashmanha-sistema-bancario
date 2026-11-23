<?php

declare(strict_types=1);

/**
 * A quién encuentra el cajero en la ventanilla.
 *
 * EL FALLO QUE ESTA PRUEBA IMPIDE REPETIR
 *
 * La búsqueda filtraba por `rol = cliente`. Pero el rol dice lo que
 * alguien puede HACER dentro del sistema, no si es cliente del banco.
 * El administrador que tiene su cuenta de ahorro aquí —en El Salvador
 * es lo normal, la planilla se paga así— no aparecía: el cajero no
 * podía encontrarlo ni para recibirle un depósito en efectivo.
 *
 * La regla correcta es tener un PRODUCTO: una cuenta no técnica, una
 * tarjeta o un crédito. Eso trae al empleado que sí es cliente y deja
 * fuera al que no lo es, que es lo que impide que la ventanilla se
 * convierta en un directorio del personal.
 *
 * Y las cuentas TÉCNICAS no cuentan. Son del banco, aunque figuren a
 * nombre de alguien: si contaran, el cajero vería la contrapartida
 * general como si fuera el dinero de esa persona.
 */

use CashMan\Aplicacion\Servicio\ServicioAtencionVentanilla;
use CashMan\Infraestructura\Persistencia\BaseDatos;

Pruebas::grupo('Ventanilla · a quién encuentra el cajero');

$v = new ServicioAtencionVentanilla();

// ---------------------------------------------------------------------
// 1. El empleado que ADEMÁS es cliente
// ---------------------------------------------------------------------

$empleadoCliente = BaseDatos::unaFila(
    'SELECT u.id, CONCAT(u.nombres," ",u.apellidos) AS nombre,
            (SELECT c.numero_cuenta FROM cuentas c
              WHERE c.usuario_id = u.id AND c.es_tecnica = 0
              ORDER BY c.id LIMIT 1) AS cuenta
       FROM usuarios u
       JOIN roles r ON r.id = u.rol_id
      WHERE r.clave <> "cliente"
        AND u.estado = "activo"
        AND EXISTS (SELECT 1 FROM cuentas c WHERE c.usuario_id = u.id AND c.es_tecnica = 0)
      ORDER BY u.id LIMIT 1'
);

if ($empleadoCliente === null) {
    Pruebas::cierto(true, 'no hay empleados con cuenta propia: nada que comprobar aquí');
} else {
    $r = $v->buscar((string) $empleadoCliente['cuenta']);

    Pruebas::cierto(
        count($r) >= 1,
        'un empleado con cuenta propia se encuentra por su número de cuenta'
        . ' (' . $empleadoCliente['nombre'] . ')'
    );

    $encontrado = false;

    foreach ($r as $x) {
        if ($x['nombre'] === $empleadoCliente['nombre']) { $encontrado = true; }
    }

    Pruebas::cierto($encontrado, 'y es él, no otro parecido');

    Pruebas::cierto(
        $v->ficha((int) $empleadoCliente['id']) !== null,
        'y su ficha se abre: encontrarlo y no poder atenderlo sería peor que no encontrarlo'
    );
}

// ---------------------------------------------------------------------
// 2. El empleado que NO es cliente
// ---------------------------------------------------------------------

$soloEmpleado = BaseDatos::unaFila(
    'SELECT u.id, CONCAT(u.nombres," ",u.apellidos) AS nombre, u.usuario
       FROM usuarios u
       JOIN roles r ON r.id = u.rol_id
      WHERE r.clave <> "cliente"
        AND NOT EXISTS (SELECT 1 FROM cuentas  c WHERE c.usuario_id = u.id AND c.es_tecnica = 0)
        AND NOT EXISTS (SELECT 1 FROM tarjetas t WHERE t.usuario_id = u.id)
        AND NOT EXISTS (SELECT 1 FROM creditos k WHERE k.usuario_id = u.id)
      ORDER BY u.id LIMIT 1'
);

if ($soloEmpleado === null) {
    Pruebas::cierto(true, 'no hay empleados sin productos: nada que comprobar aquí');
} else {
    Pruebas::cierto(
        $v->buscar((string) $soloEmpleado['nombre']) === [],
        'un empleado sin productos NO sale: la ventanilla no es un directorio del personal'
    );

    Pruebas::cierto(
        $v->buscar((string) $soloEmpleado['usuario']) === [],
        'ni buscándolo por su usuario'
    );

    Pruebas::cierto(
        $v->ficha((int) $soloEmpleado['id']) === null,
        'y su ficha no se abre ni sabiendo su identificador'
    );
}

// ---------------------------------------------------------------------
// 3. Las cuentas técnicas son del banco
// ---------------------------------------------------------------------

$tecnica = BaseDatos::unaFila(
    'SELECT c.numero_cuenta, c.usuario_id
       FROM cuentas c WHERE c.es_tecnica = 1 ORDER BY c.id LIMIT 1'
);

if ($tecnica !== null) {
    Pruebas::cierto(
        $v->buscar((string) $tecnica['numero_cuenta']) === [],
        'el número de una cuenta técnica no identifica a nadie: es del banco'
    );

    $f = $v->ficha((int) $tecnica['usuario_id']);

    if ($f !== null) {
        $tecnicasVisibles = 0;

        foreach ($f['cuentas'] as $c) {
            if (in_array((string) $c['numero_cuenta'], [(string) $tecnica['numero_cuenta']], true)) {
                $tecnicasVisibles++;
            }
        }

        Pruebas::cierto(
            $tecnicasVisibles === 0,
            'y aunque figure a nombre de alguien, su ficha no la enseña como dinero suyo'
        );
    }
}

// ---------------------------------------------------------------------
// 4. Un cliente normal sigue encontrándose
// ---------------------------------------------------------------------

$cliente = BaseDatos::unaFila(
    'SELECT u.id, CONCAT(u.nombres," ",u.apellidos) AS nombre, u.documento_identidad,
            (SELECT c.numero_cuenta FROM cuentas c
              WHERE c.usuario_id = u.id AND c.es_tecnica = 0 ORDER BY c.id LIMIT 1) AS cuenta
       FROM usuarios u
       JOIN roles r ON r.id = u.rol_id
      WHERE r.clave = "cliente" AND u.estado = "activo"
        AND u.documento_identidad IS NOT NULL AND u.documento_identidad <> ""
        AND EXISTS (SELECT 1 FROM cuentas c WHERE c.usuario_id = u.id AND c.es_tecnica = 0)
      ORDER BY u.id LIMIT 1'
);

if ($cliente === null) {
    Pruebas::cierto(false, 'hay un cliente con cuenta y documento para probar');
    return;
}

Pruebas::cierto(
    $v->buscar((string) $cliente['cuenta']) !== [],
    'un cliente se encuentra por su número de cuenta'
);

Pruebas::cierto(
    $v->buscar((string) $cliente['documento_identidad']) !== [],
    'y por su documento tal como está escrito en el DUI, con guion'
);

Pruebas::cierto(
    $v->buscar(str_replace('-', '', (string) $cliente['documento_identidad'])) !== [],
    'y también tecleado sin guion, que es como lo teclea medio mundo'
);

Pruebas::cierto(
    $v->buscar(mb_substr((string) $cliente['nombre'], 0, 6)) !== [],
    'y por el principio de su nombre, cuando llega sin papeles'
);

Pruebas::cierto(
    $v->buscar('ab') === [],
    'con menos de tres letras no se busca: devolvería media agencia'
);

// ---------------------------------------------------------------------
// 5. Por el folio del crédito
// ---------------------------------------------------------------------
//
// Quien llega a abonar a su préstamo trae el recibo o el plan de pagos
// en la mano, y lo que está impreso ahí es el FOLIO. No su DUI, ni el
// número de una cuenta que a lo mejor ni tiene.
//
// Se acepta como venga escrito, porque el cajero lo copia de un papel,
// de una pantalla o de lo que el cliente le dicta.

$credito = BaseDatos::unaFila(
    'SELECT k.folio, CONCAT(u.nombres," ",u.apellidos) AS nombre
       FROM creditos k
       JOIN usuarios u ON u.id = k.usuario_id
      WHERE u.estado = "activo"
      ORDER BY k.id DESC LIMIT 1'
);

if ($credito === null) {
    Pruebas::cierto(true, 'no hay créditos: nada que comprobar aquí');
} else {
    $folio = (string) $credito['folio'];
    $plano = str_replace('-', '', $folio);

    /** ¿Sale ESE cliente y no otro? */
    $saleEl = static function (array $r) use ($credito): bool {
        foreach ($r as $x) {
            if ($x['nombre'] === $credito['nombre']) { return true; }
        }
        return false;
    };

    Pruebas::cierto(
        $saleEl($v->buscar($folio)),
        'un crédito se encuentra por su folio tal como está impreso'
    );

    Pruebas::cierto(
        $saleEl($v->buscar(mb_strtolower($folio))),
        'y en minúsculas, que es como lo teclea quien va deprisa'
    );

    Pruebas::cierto(
        $saleEl($v->buscar($plano)),
        'y sin los guiones'
    );

    Pruebas::cierto(
        $saleEl($v->buscar(' ' . $folio . ' ')),
        'y con espacios de sobra, que es lo que deja un copiar y pegar'
    );

    Pruebas::cierto(
        $saleEl($v->buscar(substr($plano, -7))),
        'y dictando solo el final, que es como se dice cuando ya se sabe el año'
    );

    /* Lo que NO debe pasar: que la parte común a todos los folios
       devuelva la cartera entera. */
    Pruebas::cierto(
        $v->buscar('CR-' . date('Y')) === [],
        'pero el prefijo que comparten todos los créditos no devuelve a nadie'
    );

    Pruebas::cierto(
        $v->buscar('CR') === [],
        'ni dos letras sueltas'
    );
}

/* Y el crédito que se buscó tiene que VERSE en la ficha. Encontrar a
   la persona por un folio que después no aparece por ningún lado deja
   al cajero sin poder cobrar lo que el cliente vino a pagar. */
$conCastigado = BaseDatos::unaFila(
    'SELECT k.folio, k.usuario_id
       FROM creditos k JOIN usuarios u ON u.id = k.usuario_id
      WHERE k.estado = "castigado" AND u.estado = "activo"
      ORDER BY k.id LIMIT 1'
);

if ($conCastigado === null) {
    Pruebas::cierto(true, 'no hay créditos castigados: nada que comprobar aquí');
} else {
    Pruebas::cierto(
        $v->buscar((string) $conCastigado['folio']) !== [],
        'un crédito castigado se encuentra por su folio: la deuda sigue existiendo'
    );

    $f = $v->ficha((int) $conCastigado['usuario_id']);

    $enLaFicha = false;

    foreach ($f['creditos'] ?? [] as $cr) {
        if ((string) $cr['folio'] === (string) $conCastigado['folio']) { $enLaFicha = true; }
    }

    Pruebas::cierto(
        $enLaFicha,
        'y se VE en su ficha, que es donde el cajero tiene que poder cobrarlo'
    );
}
