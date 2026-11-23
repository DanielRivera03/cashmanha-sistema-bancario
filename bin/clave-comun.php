#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Pone la misma contraseña a todos los usuarios.
 *
 * PARA QUÉ SIRVE Y PARA QUÉ NO
 *
 * Es una herramienta de ENTORNO DE PRUEBAS. Una instalación donde
 * todo el mundo comparte contraseña no es un banco: es una maqueta
 * donde se puede entrar como cualquiera para ver cómo se comporta el
 * sistema con cada rol. En producción esto no se ejecuta nunca, y por
 * eso avisa y pide confirmación.
 *
 * LO QUE HACE BIEN
 *
 *   - Cifra con `ServicioPassword`, que aplica el pepper. Un hash
 *     escrito por SQL a mano no validaría jamás.
 *
 *   - Da a cada usuario su PROPIO hash. La misma contraseña con sal
 *     distinta produce hashes distintos, que es como debe ser: dos
 *     filas idénticas en la tabla dirían a quien la robe que esos dos
 *     comparten clave.
 *
 *   - Apunta cada una en el historial, de modo que la política de «no
 *     repetir contraseña» sigue funcionando a partir de aquí.
 *
 *   - NO marca el cambio obligatorio. Es justo lo contrario de lo que
 *     se quiere aquí: pedir cambio al entrar haría inservible una
 *     clave pensada para entrar y mirar.
 *
 * Uso:
 *     php bin/clave-comun.php "CashMan2026#Banca"
 *     php bin/clave-comun.php "CashMan2026#Banca" --solo-clientes
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioPoliticaPassword;
use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\ServicioPassword;

$clave = $argv[1] ?? '';

if ($clave === '') {
    exit("  Uso: php bin/clave-comun.php \"LaClave#2026\" [--solo-clientes]\n");
}

$soloClientes = in_array('--solo-clientes', $argv, true);

$sp     = new ServicioPassword();
$fallos = $sp->validarPolitica($clave);

if ($fallos !== []) {
    echo "  Esa contraseña no cumple la política:\n";
    foreach ($fallos as $f) { echo "    · {$f}\n"; }
    exit(1);
}

/* En producción esto no se corre. El aviso no es decorativo: dejar a
   todos los usuarios de un banco con la misma clave es el peor fallo
   de seguridad imaginable, y conviene que cueste hacerlo sin querer. */
if (Config::obtener('APP_ENTORNO', '') === 'produccion') {
    echo "\n  AVISO: este sistema está marcado como PRODUCCIÓN.\n";
    echo "  Poner la misma contraseña a todos los usuarios deja el banco abierto\n";
    echo "  a cualquiera que conozca una sola clave.\n\n";
    echo "  Si de verdad es un entorno de pruebas con la marca mal puesta,\n";
    echo "  vuelva a ejecutarlo añadiendo --si-estoy-seguro\n\n";

    if (!in_array('--si-estoy-seguro', $argv, true)) {
        exit(1);
    }

    echo "  (continuando bajo su responsabilidad)\n\n";
}

$donde = $soloClientes
    ? 'WHERE r.clave = "cliente"'
    : 'WHERE 1=1';

$usuarios = BaseDatos::todas(
    "SELECT u.id, u.usuario, r.nombre AS rol
       FROM usuarios u JOIN roles r ON r.id = u.rol_id
      {$donde}
      ORDER BY r.nivel DESC, u.id"
);

if ($usuarios === []) { exit("  No hay usuarios que cambiar.\n"); }

printf("\n  Poniendo la misma contraseña a %d usuario(s)…\n\n", count($usuarios));

$politica = new ServicioPoliticaPassword($sp);
$hechos   = 0;
$arranque = microtime(true);

foreach ($usuarios as $u) {
    /* Un hash por usuario, no uno reutilizado: la sal de Argon2 hace
       que la misma contraseña dé hashes distintos, y eso es lo que
       impide ver de un vistazo quiénes comparten clave. */
    $hash = $sp->hashear($clave);

    BaseDatos::ejecutar(
        'UPDATE usuarios
            SET password_hash = ?, password_algoritmo = "argon2id",
                password_actualizado_en = NOW(), requiere_cambio_password = 0,
                intentos_fallidos = 0, bloqueado_hasta = NULL
          WHERE id = ?',
        [$hash, (int) $u['id']]
    );

    $politica->recordar((int) $u['id'], $hash);

    $hechos++;

    if ($hechos % 10 === 0) {
        printf("    %d de %d…\n", $hechos, count($usuarios));
    }
}

Auditoria::registrar(
    'password.clave_comun',
    Auditoria::EXITO,
    null,
    'usuario',
    'todos',
    ['usuarios' => $hechos, 'solo_clientes' => $soloClientes]
);

printf(
    "\n  %d usuario(s) con la contraseña nueva, en %.1f s.\n",
    $hechos,
    microtime(true) - $arranque
);

echo "  Ninguno queda marcado para cambio obligatorio.\n\n";

/* Quién puede entrar y con qué rol, que es lo que se quiere saber
   después de correr esto. */
echo "  Un usuario de cada rol, para entrar y mirar:\n\n";

foreach (BaseDatos::todas(
    'SELECT r.nombre AS rol, MIN(u.usuario) AS usuario, COUNT(*) AS cuantos
       FROM usuarios u JOIN roles r ON r.id = u.rol_id
      GROUP BY r.id ORDER BY r.nivel DESC'
) as $r) {
    printf("    %-24s %-22s (%d en total)\n", $r['rol'], $r['usuario'], $r['cuantos']);
}

echo "\n";
