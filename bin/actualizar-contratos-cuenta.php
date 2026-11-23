#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Pone al día los contratos de cuenta anteriores a un cambio de
 * condiciones.
 *
 * EL PROBLEMA QUE RESUELVE
 *
 * El contrato se genera UNA vez, al abrir la cuenta, y se archiva. El
 * enlace de «ver contrato» sirve ese archivo, no lo vuelve a generar
 * — y está bien que sea así: un contrato que cambia bajo los pies del
 * cliente no es un contrato.
 *
 * Pero cuando se agregan condiciones nuevas —la compensación con
 * cuotas vencidas, el cierre por inactividad— los clientes que ya
 * tenían cuenta se quedan con un papel que no las menciona. Si el
 * sistema empieza a compensar o a cerrar cuentas, para ellos lo
 * estaría haciendo sin estar pactado, que es exactamente el agujero
 * que esas cláusulas vinieron a tapar.
 *
 * LO QUE HACE, Y LO QUE NO
 *
 * Regenera el contrato de las cuentas cuyo contrato archivado es
 * ANTERIOR a la fecha que se le indique, lo archiva como una versión
 * nueva y avisa al titular de que sus condiciones cambiaron.
 *
 * NO toca los contratos FIRMADOS. Un documento con la firma del
 * cliente encima es prueba de lo que aceptó ese día: reemplazarlo por
 * otro texto es falsificarlo, por mucho que el texto nuevo sea mejor.
 * Esos salen listados aparte para que alguien decida — lo que
 * corresponde ahí es una adenda, con su preaviso y su aceptación, no
 * una regeneración silenciosa.
 *
 * El contrato anterior tampoco se borra: queda en la base y en disco.
 * Poder enseñar qué decía el contrato de alguien en una fecha dada es
 * justo lo que hace falta el día que se discute.
 *
 * Uso:
 *     php bin/actualizar-contratos-cuenta.php --desde=2026-11-12
 *     php bin/actualizar-contratos-cuenta.php --desde=2026-11-12 --ensayo
 */

require_once __DIR__ . '/../src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioNotificacion;
use CashMan\Infraestructura\Documento\ServicioDocumentos;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

$opciones = getopt('', ['desde::', 'ensayo', 'dry-run', 'ayuda']);

if (isset($opciones['ayuda'])) {
    echo "Uso: php bin/actualizar-contratos-cuenta.php --desde=AAAA-MM-DD [--ensayo]\n\n"
       . "  --desde   regenera los contratos creados ANTES de esa fecha\n"
       . "  --ensayo  solo dice que haria\n";
    exit(0);
}

$ensayo = isset($opciones['ensayo']) || isset($opciones['dry-run']);
$desde  = (string) ($opciones['desde'] ?? '');

/* Con hora, si se quiere.
 *
 * Un cambio de condiciones ocurre a una hora concreta, no a medianoche.
 * Sin poder decirla, un contrato generado esa misma mañana —antes del
 * cambio— quedaba fuera del barrido, que es justo el caso que más
 * importa el día que se despliega. */
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) === 1) {
    $corte = $desde . ' 00:00:00';
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $desde) === 1) {
    $corte = str_replace('T', ' ', $desde) . (strlen($desde) === 16 ? ':00' : '');
} else {
    exit("  Falta --desde=AAAA-MM-DD o --desde=\"AAAA-MM-DD HH:MM\".\n");
}

echo "\n  Contratos de cuenta anteriores a {$desde}"
    . ($ensayo ? ' (ENSAYO, no aplica nada)' : '') . "\n";
echo '  ' . str_repeat('-', 70) . "\n\n";

/* Los firmados, aparte: esos no se tocan. */
$firmados = BaseDatos::todas(
    'SELECT ct.id, ct.cuenta_id, c.numero_cuenta, ct.firmado_en
       FROM contratos ct
       JOIN cuentas c ON c.id = ct.cuenta_id
      WHERE ct.tipo = "cuenta"
        AND ct.firmado_en IS NOT NULL
        AND ct.creado_en < ?
      ORDER BY ct.id',
    [$corte]
);

if ($firmados !== []) {
    echo "  FIRMADOS — no se tocan. Requieren adenda con preaviso:\n";

    foreach ($firmados as $f) {
        printf("    contrato #%-3d cuenta %-12s firmado %s\n",
            $f['id'], $f['numero_cuenta'], substr((string) $f['firmado_en'], 0, 10));
    }

    echo "\n";
}

/* Los que sí se pueden regenerar. */
$pendientes = BaseDatos::todas(
    'SELECT ct.id, ct.cuenta_id, ct.usuario_id, ct.creado_en,
            c.numero_cuenta, c.estado,
            u.nombres, u.apellidos
       FROM contratos ct
       JOIN cuentas  c ON c.id = ct.cuenta_id
       JOIN usuarios u ON u.id = ct.usuario_id
      WHERE ct.tipo = "cuenta"
        AND ct.firmado_en IS NULL
        AND ct.creado_en < ?
        AND c.estado <> "cerrada"
      ORDER BY ct.id',
    [$corte]
);

if ($pendientes === []) {
    echo "  No hay contratos que poner al dia.\n\n";
    exit(0);
}

printf("  %-12s %-26s %-12s %s\n", 'CUENTA', 'TITULAR', 'CREADO', 'RESULTADO');

$svc   = new ServicioDocumentos();
$notif = new ServicioNotificacion();
$hechos = 0;

foreach ($pendientes as $p) {
    $quien = mb_substr(trim($p['nombres'] . ' ' . $p['apellidos']), 0, 26);

    if ($ensayo) {
        printf("  %-12s %-26s %-12s %s\n",
            $p['numero_cuenta'], $quien, substr((string) $p['creado_en'], 0, 10),
            'se regeneraria');
        $hechos++;
        continue;
    }

    try {
        $doc = $svc->contratoCuenta((int) $p['cuenta_id']);

        BaseDatos::ejecutar(
            'INSERT INTO contratos
               (uuid, cuenta_id, usuario_id, tipo, ruta_pdf, hash_documento)
             VALUES (UUID(),?,?, "cuenta", ?, ?)',
            [(int) $p['cuenta_id'], (int) $p['usuario_id'], $doc['ruta'], $doc['hash']]
        );

        $nuevo = BaseDatos::ultimoId();

        Auditoria::registrar(
            'contrato.actualizado',
            Auditoria::EXITO,
            null,
            'contrato',
            (string) $nuevo,
            [
                'cuenta'   => $p['numero_cuenta'],
                'anterior' => (int) $p['id'],
                'motivo'   => 'Cambio de condiciones del ' . $desde,
                'hash'     => $doc['hash'],
            ]
        );

        /* Se le avisa. Un cambio de condiciones que el cliente no sabe
           que ocurrio no es un cambio pactado: es una sorpresa que le
           espera el dia que le afecte. */
        $notif->crear(
            (int) $p['usuario_id'],
            'cuenta.contrato',
            'Actualizamos las condiciones de su cuenta ' . $p['numero_cuenta'],
            'Su contrato incorpora dos condiciones nuevas: la compensacion de saldo '
            . 'con cuotas vencidas —incluidos los abonos parciales— y el cierre por '
            . 'inactividad. Puede leerlo completo en «Mis documentos».',
            ['cuenta' => (string) $p['numero_cuenta']],
            'info'
        );

        printf("  %-12s %-26s %-12s %s\n",
            $p['numero_cuenta'], $quien, substr((string) $p['creado_en'], 0, 10),
            'contrato #' . $nuevo . ' archivado y avisado');

        $hechos++;
    } catch (\Throwable $e) {
        printf("  %-12s %-26s %-12s !! %s\n",
            $p['numero_cuenta'], $quien, substr((string) $p['creado_en'], 0, 10),
            mb_substr($e->getMessage(), 0, 60));
    }
}

printf(
    "\n  %d contrato(s) %s. El anterior de cada uno se conserva.\n\n",
    $hechos,
    $ensayo ? 'se pondrian al dia' : 'puestos al dia'
);
