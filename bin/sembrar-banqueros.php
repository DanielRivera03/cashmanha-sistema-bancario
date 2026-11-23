<?php

declare(strict_types=1);

/**
 * Los banqueros de la cartera, y el reparto de clientes.
 *
 * La pantalla de banca preferente le promete al cliente patrimonial
 * «una persona asignada que lleva su relación con la institución».
 * Hasta ahora era una frase sin nadie detrás: el botón «Escribirle»
 * llevaba a la mensajería general, donde se le ofrecía elegir
 * destinatario de una lista con todo el personal.
 *
 * Esto crea los banqueros y les reparte la cartera. El reparto va por
 * número de clientes y no al azar: una cartera de banca privada se
 * sostiene en que la persona se acuerde de quién es cada cliente, y
 * eso no pasa con cincuenta.
 *
 * Como en los cajeros, la contraseña lleva un pepper de fuera de la
 * base antes del hash, así que esto no puede ser una migración.
 *
 *   php bin/sembrar-banqueros.php
 *   php bin/sembrar-banqueros.php --listar
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\ServicioPassword;

const CLAVE = 'CashMan2026#Banca';

/**
 * Los banqueros. Pocos y con nombre: una cartera no se lleva entre
 * veinte personas, y la gracia del puesto es precisamente que el
 * cliente sepa a quién escribe.
 *
 * @var list<array{0:string,1:string}>
 */
const BANQUEROS = [
    ['María José',      'Alvarenga Soler'],
    ['Ricardo Andrés',  'Zaldívar Umaña'],
    ['Patricia Elena',  'Durán Figueroa'],
];

/** Cuántos clientes como máximo lleva cada uno. */
const CARTERA_MAXIMA = 40;

$soloListar = in_array('--listar', $argv, true);

$rol = (int) BaseDatos::unValor("SELECT id FROM roles WHERE clave = 'banquero'");

if ($rol === 0) {
    exit("  No existe el rol «banquero». Aplique antes la migración 055.\n");
}

function clave_usuario(string $nombres, string $apellidos): string
{
    $limpia = static fn(string $s): string => strtolower(
        preg_replace('/[^a-zA-Z]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $s)) ?? ''
    );

    return $limpia(explode(' ', trim($nombres))[0] ?? '')
         . '.' . $limpia(explode(' ', trim($apellidos))[0] ?? '');
}

// --- 1. Los usuarios ---------------------------------------------------

$pass = new ServicioPassword();
$hash = $soloListar ? '' : $pass->hashear(CLAVE);

$ids = [];
$creados = 0;

echo "  Banqueros\n";

foreach (BANQUEROS as [$nombres, $apellidos]) {
    $usuario = clave_usuario($nombres, $apellidos);

    $existe = BaseDatos::unaFila('SELECT id FROM usuarios WHERE usuario = ?', [$usuario]);

    if ($existe !== null) {
        $ids[] = (int) $existe['id'];
        printf("    ya estaba  %-22s #%d\n", $usuario, $existe['id']);
        continue;
    }

    if ($soloListar) {
        printf("    faltaría   %-22s %s %s\n", $usuario, $nombres, $apellidos);
        continue;
    }

    BaseDatos::ejecutar(
        'INSERT INTO usuarios
           (uuid, usuario, correo, nombres, apellidos, password_hash,
            password_algoritmo, password_actualizado_en, requiere_cambio_password,
            rol_id, estado, primer_ingreso, perfil_completo, creado_en)
         VALUES (UUID(), ?, ?, ?, ?, ?, "argon2id", NOW(), 1, ?, "activo", 1, 1, NOW())',
        [$usuario, $usuario . '@cashmanha.sv', $nombres, $apellidos, $hash, $rol]
    );

    $id = (int) BaseDatos::unValor('SELECT id FROM usuarios WHERE usuario = ?', [$usuario]);
    $ids[] = $id;

    printf("    creado     %-22s %s %s\n", $usuario, $nombres, $apellidos);
    $creados++;
}

if ($soloListar) {
    echo "\n  (solo listado; no se tocó nada)\n";
}

// --- 2. El reparto de cartera ------------------------------------------

/* Quién entra en cartera: los segmentos que el sistema reconoce como
   preferente y patrimonial. El estándar no lleva banquero, y no por
   mezquindad: una persona asignada a todo el mundo no es una persona
   asignada a nadie. */
$enCartera = BaseDatos::todas(
    'SELECT u.id, CONCAT(u.nombres," ",u.apellidos) AS nombre,
            u.segmento, u.banquero_id
       FROM usuarios u
       JOIN roles r ON r.id = u.rol_id
      WHERE r.clave = "cliente"
        AND u.estado = "activo"
        AND u.segmento IN ("preferente","patrimonial")
      ORDER BY FIELD(u.segmento, "patrimonial", "preferente"), u.id'
);

printf("\n  Cartera: %d cliente(s) de segmento preferente o patrimonial\n", count($enCartera));

if ($ids === [] || $soloListar) {
    foreach ($enCartera as $c) {
        printf("    %-30s %-12s %s\n", $c['nombre'], $c['segmento'],
            $c['banquero_id'] ? 'ya asignado' : 'sin banquero');
    }

    exit("\n");
}

/* Cuántos lleva ya cada uno, para repartir por carga y no en bloque. */
$carga = [];

foreach ($ids as $id) {
    $carga[$id] = (int) BaseDatos::unValor(
        'SELECT COUNT(*) FROM usuarios WHERE banquero_id = ?', [$id]
    );
}

$asignados = 0;

foreach ($enCartera as $c) {
    if ($c['banquero_id'] !== null) { continue; }

    // Al que menos lleva.
    asort($carga);
    $elegido = (int) array_key_first($carga);

    if ($carga[$elegido] >= CARTERA_MAXIMA) {
        printf("    !! todas las carteras están llenas (%d). %s se queda sin asignar.\n",
            CARTERA_MAXIMA, $c['nombre']);
        continue;
    }

    BaseDatos::ejecutar(
        'UPDATE usuarios SET banquero_id = ?, banquero_desde = NOW() WHERE id = ?',
        [$elegido, (int) $c['id']]
    );

    $carga[$elegido]++;
    $asignados++;

    $b = BaseDatos::unaFila('SELECT CONCAT(nombres," ",apellidos) n FROM usuarios WHERE id = ?', [$elegido]);

    printf("    %-30s %-12s -> %s\n", $c['nombre'], $c['segmento'], $b['n']);
}

printf("\n  %d banquero(s) creado(s), %d cliente(s) asignado(s).\n", $creados, $asignados);

if ($creados > 0) {
    printf("  Contraseña de los nuevos: %s (se les pide cambiarla al entrar).\n", CLAVE);
}

echo "\n  Carteras:\n";

foreach (BaseDatos::todas(
    'SELECT CONCAT(u.nombres," ",u.apellidos) AS nombre, u.usuario,
            (SELECT COUNT(*) FROM usuarios c WHERE c.banquero_id = u.id) AS clientes
       FROM usuarios u WHERE u.rol_id = ? ORDER BY u.id',
    [$rol]
) as $b) {
    printf("    %-30s %-22s %d cliente(s)\n", $b['nombre'], $b['usuario'], $b['clientes']);
}
