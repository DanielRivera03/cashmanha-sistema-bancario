<?php

declare(strict_types=1);

/**
 * Los cajeros de la red.
 *
 * La migración 054 creó diez agencias con veintiséis ventanillas, y
 * ventanillas sin nadie detrás no sirven para probar nada: el módulo
 * de caja no se puede ejercitar sin cajeros que abran turno, cobren y
 * cierren cuadrando.
 *
 * Esto no va en la migración porque no puede: la contraseña pasa por
 * un HMAC con un pepper que vive en `.env`, fuera de la base, antes
 * de llegar a Argon2id. Un INSERT de SQL no tiene forma de aplicarlo,
 * y un hash hecho sin el pepper no deja entrar a nadie —cosa que ya
 * costó un rato averiguar una vez—.
 *
 * Es idempotente: se puede correr las veces que haga falta y no
 * duplica a nadie ni le cambia la contraseña a quien ya existe.
 *
 *   php bin/sembrar-cajeros.php              (crea lo que falte)
 *   php bin/sembrar-cajeros.php --listar     (solo enseña qué hay)
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Seguridad\ServicioPassword;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/* La contraseña de los cajeros sembrados.
 *
 * Es la MISMA para todos y se exige cambiarla al primer ingreso. Son
 * usuarios de prueba de un entorno de desarrollo; poner una distinta
 * para cada uno solo conseguiría que hubiera que apuntarlas en algún
 * sitio, que es peor. En producción estos usuarios no se siembran:
 * los da de alta Recursos Humanos uno por uno. */
const CLAVE = 'CashMan2026#Caja';

/**
 * Nombres salvadoreños, dos por agencia en las grandes y uno en las
 * pequeñas. No salen de un generador: un listado de «Usuario 1,
 * Usuario 2» hace que las pantallas de caja parezcan una hoja de
 * cálculo y no un banco.
 *
 * @var array<string, list<array{0:string,1:string}>>
 */
const PERSONAL = [
    'SUC-01' => [
        ['Rosa Elena',       'Mejía Portillo'],      // ya existe
        ['Óscar Antonio',    'Lemus Hernández'],     // ya existe
        ['Karla Beatriz',    'Alfaro Rivas'],
        ['Mauricio José',    'Ventura Campos'],
    ],
    'SUC-02' => [
        ['Gabriela Lissette', 'Cruz Menjívar'],
        ['Nelson Enrique',    'Ayala Deras'],
        ['Wendy Carolina',    'Sandoval Pineda'],
    ],
    'SUC-03' => [
        ['Ana Gabriela',      'Cornejo Flores'],
        ['Rodrigo Ernesto',   'Martínez Bonilla'],
    ],
    'SUC-04' => [
        ['Silvia Margarita',  'Quintanilla Rosa'],
        ['Jorge Alberto',     'Peña Castaneda'],
        ['Yesenia del Carmen','Molina Serrano'],
    ],
    'SUC-05' => [
        ['Claudia Verónica',  'Benítez Argueta'],
        ['Héctor Rolando',    'Chávez Zelaya'],
        ['Marvin Alexander',  'Reyes Granados'],
    ],
    'SUC-06' => [
        ['Lorena Isabel',     'Granados Melara'],
        ['Carlos Humberto',   'Orellana Vásquez'],
        ['Sandra Patricia',   'Interiano Lazo'],
    ],
    'SUC-07' => [
        ['Diana Michelle',    'Escobar Rivera'],
        ['Fernando Josué',    'Guevara Barahona'],
        ['Alejandra María',   'Linares Trejo'],
    ],
    'SUC-08' => [
        ['Mirna Guadalupe',   'Salguero Díaz'],
        ['Édgar Antonio',     'Villalta Ramos'],
    ],
    'SUC-09' => [
        ['Reina Esperanza',   'Hernández Gómez'],
        ['Julio César',       'Mendoza Alvarenga'],
    ],
    'SUC-10' => [
        ['Tatiana Elizabeth', 'Rivas Contreras'],
        ['Walter Giovanni',   'Fuentes Aguilar'],
    ],
];

// ---------------------------------------------------------------------

$soloListar = in_array('--listar', $argv, true);

$rolCajero = (int) BaseDatos::unValor("SELECT id FROM roles WHERE nombre = 'Cajero'");

if ($rolCajero === 0) {
    exit("  No existe el rol «Cajero». Revise la siembra de roles.\n");
}

/** Un nombre de usuario corto y legible: nombre.apellido, sin tildes. */
function clave_usuario(string $nombres, string $apellidos): string
{
    $limpia = static function (string $s): string {
        $s = (string) iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        $s = strtolower(preg_replace('/[^a-zA-Z]/', '', $s) ?? '');
        return $s;
    };

    $n = explode(' ', trim($nombres))[0]   ?? '';
    $a = explode(' ', trim($apellidos))[0] ?? '';

    return $limpia($n) . '.' . $limpia($a);
}

$pass = new ServicioPassword();
$hash = $soloListar ? '' : $pass->hashear(CLAVE);

$creados = 0;
$yaEstaban = 0;

foreach (PERSONAL as $codigoSuc => $gente) {
    $suc = BaseDatos::unaFila(
        'SELECT id, nombre FROM sucursales WHERE codigo = ?',
        [$codigoSuc]
    );

    if ($suc === null) {
        printf("  %-8s sin sucursal, se omite\n", $codigoSuc);
        continue;
    }

    printf("\n  %s · %s\n", $codigoSuc, $suc['nombre']);

    foreach ($gente as [$nombres, $apellidos]) {
        $usuario = clave_usuario($nombres, $apellidos);

        $existe = BaseDatos::unaFila(
            'SELECT id, usuario FROM usuarios WHERE usuario = ? OR
               (nombres = ? AND apellidos = ?)',
            [$usuario, $nombres, $apellidos]
        );

        if ($existe !== null) {
            printf("    ya estaba   %-22s #%d\n", $existe['usuario'], $existe['id']);
            $yaEstaban++;
            continue;
        }

        if ($soloListar) {
            printf("    faltaría    %-22s %s %s\n", $usuario, $nombres, $apellidos);
            continue;
        }

        /* El correo es del dominio de la institución y no de uno real:
           estos usuarios no deben recibir nada, y un dominio de verdad
           acabaría mandándole correos de prueba a alguien. */
        $correo = $usuario . '@cashmanha.sv';

        BaseDatos::ejecutar(
            'INSERT INTO usuarios
               (uuid, usuario, correo, nombres, apellidos, password_hash,
                password_algoritmo, password_actualizado_en,
                requiere_cambio_password, rol_id, estado, primer_ingreso,
                perfil_completo, creado_en)
             VALUES (UUID(), ?, ?, ?, ?, ?, "argon2id", NOW(), 1, ?, "activo", 1, 1, NOW())',
            [$usuario, $correo, $nombres, $apellidos, $hash, $rolCajero]
        );

        printf("    creado      %-22s %s %s\n", $usuario, $nombres, $apellidos);
        $creados++;
    }
}

printf("\n  %d creado(s), %d ya estaban.\n", $creados, $yaEstaban);

if ($creados > 0) {
    printf("  Contraseña para los nuevos: %s (se les pide cambiarla al entrar).\n", CLAVE);
}

/* Y el recuento de la red, que es lo que se vino a conseguir. */
$red = BaseDatos::todas(
    'SELECT s.codigo, s.nombre,
            (SELECT COUNT(*) FROM cajas c WHERE c.sucursal_id = s.id AND c.activa = 1) AS cajas
       FROM sucursales s WHERE s.activa = 1 ORDER BY s.codigo'
);

printf("\n  La red:\n");

$totalCajas = 0;

foreach ($red as $r) {
    printf("    %-8s %-32s %d ventanilla(s)\n", $r['codigo'], $r['nombre'], $r['cajas']);
    $totalCajas += (int) $r['cajas'];
}

printf("    %d agencias · %d ventanillas · %d cajeros\n",
    count($red),
    $totalCajas,
    (int) BaseDatos::unValor('SELECT COUNT(*) FROM usuarios WHERE rol_id = ?', [$rolCajero])
);
