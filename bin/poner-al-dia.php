#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Pone al día un crédito atrasado con un solo pago.
 *
 * QUÉ HACE, EN ORDEN
 *
 *   1. Cobra TODO lo vencido en una sola operación: capital, interés,
 *      seguros y recargo por mora de cada cuota atrasada. Un pago, un
 *      comprobante, un asiento. Es lo que ocurre en ventanilla cuando
 *      alguien llega a ponerse al corriente: no hace dieciséis pagos,
 *      hace uno.
 *
 *   2. Recalcula la clasificación. Un crédito sin una sola cuota
 *      vencida no puede seguir en la categoría de uno con un año de
 *      atraso: la clasificación describe el riesgo de HOY.
 *
 *   3. Cierra el caso de cobranza como recuperado, con su anotación.
 *      Un expediente judicial abierto sobre una deuda ya pagada es un
 *      problema serio: de ahí salen las demandas que no debieron
 *      seguir su curso.
 *
 *   4. Ofrece, si corresponde, una oferta preaprobada. Quien se pone
 *      al día es exactamente el cliente al que un banco quiere volver
 *      a prestarle.
 *
 * POR QUÉ ES UN GUION Y NO UNA PANTALLA
 *
 * Porque es una excepción, no una operación de todos los días. Lo
 * normal es cobrar cuota a cuota desde la caja. Esto es para los
 * casos en que hay que regularizar un expediente completo, y por eso
 * deja rastro en la auditoría igual que cualquier otra operación.
 *
 * Uso:
 *     php bin/poner-al-dia.php harold.garcia
 *     php bin/poner-al-dia.php harold.garcia --medio=efectivo
 *     php bin/poner-al-dia.php harold.garcia --sin-oferta
 *     php bin/poner-al-dia.php harold.garcia --ensayo
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioCredito;
use CashMan\Aplicacion\Servicio\ServicioOfertaPreaprobada;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Sesion;

/* El actor y su sesión, ANTES de escribir una sola línea.
 *
 * `Sesion::iniciar()` ajusta opciones con `ini_set`, y eso solo se
 * puede hacer mientras no se haya enviado nada. En consola no hay
 * cabeceras, pero PHP lleva la misma cuenta: con un `echo` por
 * delante, la sesión ya no arranca y la operación se queda sin actor
 * —y sin actor no hay operación, porque todo lo que mueve dinero en
 * este sistema lleva nombre—. */
$actor = BaseDatos::unaFila(
    'SELECT u.id, u.usuario, u.rol_id FROM usuarios u
       JOIN roles r ON r.id = u.rol_id
      WHERE r.nivel >= 100 AND u.estado = "activo"
      ORDER BY u.id LIMIT 1'
);

if ($actor === null) {
    exit("  No hay un administrador activo que firme la operacion.\n\n");
}

Sesion::autenticar((int) $actor['id'], (int) $actor['rol_id'], (string) $actor['usuario']);

/* Los argumentos se leen a mano, y no con `getopt()`.
 *
 * `getopt()` deja de procesar en cuanto encuentra un argumento que no
 * es una opción: con `poner-al-dia.php harold.garcia --ensayo` no
 * llega a ver el `--ensayo`, devuelve un arreglo vacío y el ensayo se
 * convierte en un cobro de verdad. Pasó, y por trescientos mil
 * dólares. Un «no hagas nada» que hace algo es la peor clase de
 * error, así que aquí se leen todos los argumentos, estén donde
 * estén. */
$opciones = [];
$usuario  = '';

foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        $par = explode('=', substr($a, 2), 2);
        $opciones[$par[0]] = $par[1] ?? true;
        continue;
    }

    if ($usuario === '') { $usuario = $a; }
}

if ($usuario === '') {
    exit("  Uso: php bin/poner-al-dia.php <usuario> [--medio=efectivo] [--sin-oferta] [--ensayo]\n");
}

$medio  = (string) ($opciones['medio'] ?? 'efectivo');
$ensayo = isset($opciones['ensayo']);

echo "\n  PONER AL DIA\n";
echo '  ' . str_repeat('=', 68) . "\n\n";

// =====================================================================
// El cliente y su crédito
// =====================================================================

$cli = BaseDatos::unaFila(
    'SELECT id, usuario, nombres, apellidos, correo FROM usuarios WHERE usuario = ?',
    [$usuario]
);

if ($cli === null) { exit("  No hay ningún usuario «{$usuario}».\n\n"); }

printf("  Cliente: %s %s  (%s)\n\n", $cli['nombres'], $cli['apellidos'], $cli['usuario']);

$creditos = BaseDatos::todas(
    'SELECT c.id, c.folio, c.clasificacion, c.saldo_capital_cent, c.cuotas_pagadas,
            c.plazo_meses, c.cuenta_desembolso_id, p.nombre AS producto
       FROM creditos c
       JOIN productos p ON p.id = c.producto_id
      WHERE c.usuario_id = ? AND c.estado IN ("vigente","desembolsado")',
    [(int) $cli['id']]
);

if ($creditos === []) { exit("  No tiene créditos vigentes.\n\n"); }

printf("  Opera: %s\n\n", $actor['usuario']);

$servicio = new ServicioCredito();
$total    = 0;
$puestos  = 0;

foreach ($creditos as $c) {
    $cuotas = BaseDatos::todas(
        'SELECT id, numero, total_cent, pagado_cent, mora_cent, dias_mora, fecha_vencimiento
           FROM cuotas
          WHERE credito_id = ? AND estado IN ("vencida","parcial")
          ORDER BY numero',
        [(int) $c['id']]
    );

    printf("  %s · %s\n", $c['folio'], $c['producto']);

    if ($cuotas === []) {
        echo "     Ya estaba al día.\n\n";
        continue;
    }

    $deuda = 0;
    $mora  = 0;

    foreach ($cuotas as $q) {
        $deuda += (int) $q['total_cent'] - (int) $q['pagado_cent'];
        $mora  += (int) $q['mora_cent'];
    }

    printf("     %d cuota(s) vencida(s), de la %d a la %d\n",
        count($cuotas), (int) $cuotas[0]['numero'], (int) $cuotas[count($cuotas) - 1]['numero']);
    printf("     Cuotas .......... %s\n", Monto::deCentavos($deuda)->formateado());
    printf("     Recargo por mora  %s\n", Monto::deCentavos($mora)->formateado());
    printf("     A pagar ......... %s\n", Monto::deCentavos($deuda + $mora)->formateado());

    if ($ensayo) {
        echo "     (ensayo: no se cobró nada)\n\n";
        continue;
    }

    /* Un solo cobro para todas. `pagarVarias` arma un único asiento y
       un único comprobante, que es lo que hace de esto «un pago» y no
       dieciséis seguidos. */
    $r = $servicio->pagarVarias(
        array_map(static fn(array $q): int => (int) $q['id'], $cuotas),
        (int) $c['cuenta_desembolso_id'],
        'alDia:' . $c['folio'] . ':' . date('Ymd') . ':' . count($cuotas),
        ['medio' => $medio]
    );

    /* Lo cobrado de verdad.
     *
     * El servicio devuelve su propio resumen y las claves cambian
     * según la ruta que haya seguido el cobro. Se prueban las que
     * usa y, si ninguna trae cifra, se cae a lo que se acaba de
     * calcular, que es lo que se mandó cobrar. Un cero aquí dejaba el
     * expediente de cobranza diciendo que se recuperaron cero
     * dólares de una deuda que sí se pagó. */
    $cobrado = (int) ($r['total_cent']
        ?? $r['cobrado_cent']
        ?? $r['importe_cent']
        ?? ($deuda + $mora));

    if ($cobrado <= 0) { $cobrado = $deuda + $mora; }
    $total  += $cobrado;
    $puestos++;

    printf("     COBRADO %s en efectivo, comprobante %s\n",
        Monto::deCentavos($cobrado)->formateado(),
        (string) ($r['comprobante'] ?? $r['referencia'] ?? '—'));

    // ---------- La clasificación, al día de hoy ----------
    //
    // Un crédito sin cuotas vencidas no puede seguir clasificado como
    // uno con un año de atraso. La categoría describe el riesgo de
    // ahora, no el del mes pasado.
    $quedan = (int) BaseDatos::unValor(
        'SELECT COUNT(*) FROM cuotas WHERE credito_id = ? AND estado IN ("vencida","parcial")',
        [(int) $c['id']]
    );

    if ($quedan === 0 && $c['clasificacion'] !== 'A') {
        BaseDatos::ejecutar(
            'UPDATE creditos SET clasificacion = "A" WHERE id = ?',
            [(int) $c['id']]
        );

        printf("     Clasificación %s → A\n", $c['clasificacion']);
    }

    // ---------- El expediente de cobranza ----------
    //
    // Dejar abierto un caso judicial sobre una deuda ya pagada es de
    // los errores que terminan en una demanda que no debió seguir.
    $casos = BaseDatos::todas(
        'SELECT id, folio, etapa FROM cobranza_casos
          WHERE credito_id = ? AND cerrado_en IS NULL',
        [(int) $c['id']]
    );

    foreach ($casos as $caso) {
        BaseDatos::ejecutar(
            'UPDATE cobranza_casos
                SET etapa = "recuperado", recuperado_cent = ?, cerrado_en = NOW(),
                    cerrado_por = ?, motivo_cierre = ?, dias_mora = 0,
                    cuotas_vencidas = 0, mora_cent = 0, actualizado_en = NOW()
              WHERE id = ?',
            [
                $cobrado,
                (int) $actor['id'],
                'El deudor pagó la totalidad de lo vencido en un solo abono y el '
                . 'crédito quedó al día. Se cierra la gestión y, de haberse iniciado '
                . 'acciones judiciales, se instruye desistir de ellas.',
                (int) $caso['id'],
            ]
        );

        BaseDatos::ejecutar(
            'INSERT INTO cobranza_gestiones
               (caso_id, tipo, automatica, etapa, asunto, detalle, resultado, registrada_por)
             VALUES (?, "nota", 0, "recuperado", ?, ?, "pago_recibido", ?)',
            [
                (int) $caso['id'],
                'Pago total de lo vencido; caso recuperado',
                'Se recibió ' . Monto::deCentavos($cobrado)->formateado()
                . ' que cubre la totalidad de las cuotas vencidas y sus recargos. '
                . 'El crédito queda al día y el expediente se cierra como recuperado.',
                (int) $actor['id'],
            ]
        );

        printf("     Cobranza %s (%s) → recuperado y cerrado\n", $caso['folio'], $caso['etapa']);
    }

    echo "\n";
}

if ($ensayo) {
    echo "  Ensayo terminado: no se tocó nada.\n\n";
    exit(0);
}

if ($puestos === 0) {
    /* Nada que cobrar no es nada que hacer: el cliente puede haber
       quedado al día en una corrida anterior y faltar todavía la
       oferta. Salir aquí obligaba a emitirla por otro camino. */
    echo "  No había nada que cobrar: ya estaba al día.\n\n";
} else {
    Auditoria::registrar(
        'credito.puesto_al_dia',
        Auditoria::EXITO,
        (int) $actor['id'],
        'usuario',
        (string) $cli['usuario'],
        ['creditos' => $puestos, 'cobrado_cent' => $total, 'medio' => $medio]
    );

    printf("  %d crédito(s) al día. Cobrado en total: %s\n\n",
        $puestos, Monto::deCentavos($total)->formateado());
}

// =====================================================================
// Y la oferta, que es la otra mitad de ponerse al día
// =====================================================================

if (isset($opciones['sin-oferta'])) {
    echo "  Sin oferta, por petición.\n\n";
    exit(0);
}

echo "  Evaluando una oferta preaprobada…\n";

try {
    $r = (new ServicioOfertaPreaprobada())->evaluar((int) $cli['id'], true);

    $n = is_array($r) ? (int) ($r['ofertas'] ?? count($r)) : 0;

    if ($n > 0) {
        printf("  %d oferta(s) emitidas y notificadas por el barrido.\n\n", $n);
        exit(0);
    }

    /* El barrido no lo propone, y hace bien: mira CON QUÉ ATRASO se
       pagaron esas cuotas, y acaban de liquidarse con meses de
       retraso. Ese criterio protege la cartera y no se toca.
    *
     * Pero quien pone sobre la mesa todo lo vencido de una vez es
     * justo el cliente con el que un banco quiere volver a hablar.
     * Para eso está la oferta por decisión: se otorga, se dice que
     * fue por decisión y se dice por qué. Lo que no se hace es
     * maquillar el historial para que el barrido pase por el aro. */
    echo "  El barrido no lo propone (el atraso con que se pagaron esas cuotas\n";
    echo "  pesa en su historial, y ese criterio no se toca).\n";
    echo "  Se emite una oferta por decisión, con su motivo.\n\n";

    $d = (new ServicioOfertaPreaprobada())->ofrecerPorDecision(
        (int) $cli['id'],
        'El cliente liquidó en un solo abono la totalidad de sus cuotas vencidas '
        . 'y sus recargos, quedando al día y con su expediente de cobro cerrado '
        . 'como recuperado. Se le ofrece por esa demostración de capacidad y '
        . 'voluntad de pago, no por su historial de puntualidad.',
        true
    );

    printf("  %d oferta(s) emitidas por decisión y notificadas.\n\n", (int) $d['ofertas']);
} catch (\Throwable $e) {
    echo "  No se pudo evaluar la oferta: " . $e->getMessage() . "\n\n";
}
