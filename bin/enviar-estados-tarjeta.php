#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Envía por correo los estados de cuenta de tarjeta ya emitidos.
 *
 * PARA QUÉ EXISTE APARTE DEL CORTE
 *
 * Normalmente el aviso sale solo, en cuanto el corte se genera. Esto
 * es para cuando hay que REENVIAR: los documentos se reemitieron
 * porque los anteriores salieron con el periodo y el saldo mal, y los
 * clientes que ya los habían recibido tienen que ver los buenos.
 *
 * POR DEFECTO, SOLO EL ÚLTIMO DE CADA TARJETA
 *
 * Reenviar once meses de historia son once correos seguidos a la misma
 * persona por documentos que ya vio. El que importa es el vigente: es
 * el que dice cuánto pagar y cuándo. Los anteriores quedan corregidos
 * en el sistema y se consultan cuando hagan falta.
 *
 * `--todos` reenvía la historia entera, y `--meses=3` los últimos
 * tres de cada tarjeta. Las dos opciones existen porque la decisión
 * es de quien manda en el banco, no de este guion.
 *
 * SE DICE CUÁNTOS VAN A SALIR ANTES DE MANDAR NINGUNO
 *
 * Un correo no se puede recoger. Así que primero cuenta, luego pide
 * confirmación con `--si`, y solo entonces envía.
 *
 * Uso:
 *     php bin/enviar-estados-tarjeta.php              dice qué haría
 *     php bin/enviar-estados-tarjeta.php --si         envía el último de cada una
 *     php bin/enviar-estados-tarjeta.php --meses=3 --si
 *     php bin/enviar-estados-tarjeta.php --todos --si
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioCorteTarjeta;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

$opciones = [];

foreach (array_slice($argv, 1) as $a) {
    if (!str_starts_with($a, '--')) { continue; }

    $par = explode('=', substr($a, 2), 2);
    $opciones[$par[0]] = $par[1] ?? true;
}

$deVerdad = isset($opciones['si']);
$todos    = isset($opciones['todos']);
$meses    = isset($opciones['meses']) ? max(1, (int) $opciones['meses']) : 1;

if ($todos) { $meses = 999; }

echo "\n  ENVÍO DE ESTADOS DE CUENTA DE TARJETA\n";
echo '  ' . str_repeat('=', 68) . "\n\n";

if (!Correo::habilitado()) {
    echo "  El correo está deshabilitado (CORREO_HABILITADO en .env).\n";
    echo "  Nada que hacer aquí hasta que se encienda.\n\n";
    exit(1);
}

/* Qué cortes entran: los `n` más recientes de cada tarjeta. La
   numeración por tarjeta se hace en PHP y no en SQL porque la versión
   de MariaDB de esta casa no tiene funciones de ventana. */
$filas = BaseDatos::todas(
    'SELECT c.id, c.tarjeta_id, c.fecha_corte, c.periodo_inicio, c.periodo_fin,
            c.saldo_final_cent, t.ultimos4, u.correo,
            CONCAT(u.nombres," ",u.apellidos) AS cliente
       FROM tarjeta_cortes c
       JOIN tarjetas t ON t.id = c.tarjeta_id
       JOIN usuarios u ON u.id = t.usuario_id
      WHERE u.estado = "activo" AND u.correo <> ""
      ORDER BY c.tarjeta_id, c.fecha_corte DESC'
);

$porTarjeta = [];
$elegidos   = [];

foreach ($filas as $f) {
    $tid = (int) $f['tarjeta_id'];

    $porTarjeta[$tid] = ($porTarjeta[$tid] ?? 0) + 1;

    if ($porTarjeta[$tid] <= $meses) { $elegidos[] = $f; }
}

if ($elegidos === []) { exit("  No hay estados de cuenta que enviar.\n\n"); }

printf("  %d tarjeta(s) con correo, %d estado(s) de cuenta a enviar.\n",
    count($porTarjeta), count($elegidos));

printf("  (%s)\n\n", $todos
    ? 'toda la historia'
    : ($meses === 1 ? 'solo el último de cada tarjeta' : "los últimos {$meses} de cada tarjeta"));

/* Y a quién. Con la copia oculta puesta, cada uno de estos sale
   además a la bandeja que la tenga configurada: conviene verlo antes
   de disparar. */
$destinos = array_unique(array_column($elegidos, 'correo'));

printf("  Destinatarios distintos: %d\n", count($destinos));

foreach (array_slice($destinos, 0, 5) as $d) { printf("    · %s\n", $d); }

if (count($destinos) > 5) { printf("    · … y %d más\n", count($destinos) - 5); }

echo "\n";

if (!$deVerdad) {
    echo "  ENSAYO: no se envió nada.\n";
    echo "  Para enviarlos de verdad, repita con --si\n\n";
    exit(0);
}

// =====================================================================
// A enviar
// =====================================================================

$servicio = new ServicioCorteTarjeta();

/* `avisarCorte` es privado, y con razón: nadie debería mandar un
   estado de cuenta suelto desde cualquier parte. Aquí se abre a
   propósito, desde una herramienta que existe para eso y que deja
   constancia en la auditoría. */
$avisar = new ReflectionMethod($servicio, 'avisarCorte');
$avisar->setAccessible(true);

$enviados = 0;
$fallidos = 0;

foreach ($elegidos as $i => $c) {
    try {
        $ok = (bool) $avisar->invoke($servicio, (int) $c['id']);

        if ($ok) {
            $enviados++;
        } else {
            $fallidos++;
        }

        printf("  [%3d/%3d] ····%s  %s al %s  %s\n",
            $i + 1, count($elegidos), $c['ultimos4'],
            $c['periodo_inicio'], $c['periodo_fin'],
            $ok ? 'enviado' : 'no salió');
    } catch (\Throwable $e) {
        $fallidos++;
        printf("  [%3d/%3d] ····%s  falló: %s\n",
            $i + 1, count($elegidos), $c['ultimos4'], $e->getMessage());
    }
}

Auditoria::registrar(
    'tarjeta.estados_reenviados',
    Auditoria::EXITO,
    null,
    'tarjeta',
    'todas',
    ['enviados' => $enviados, 'fallidos' => $fallidos, 'meses' => $meses]
);

printf("\n  %d enviado(s), %d sin salir.\n\n", $enviados, $fallidos);
