#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * El latido del sistema: lo que hace que las tareas corran solas.
 *
 * EL PROBLEMA QUE RESUELVE
 *
 * Las tareas periódicas se ejecutaban aprovechando las visitas: cada
 * petición web miraba si había algo vencido y corría UNA cosa después
 * de responder. Para un banco con uso diario alcanzaba, y está
 * honestamente documentado en `ServicioTareasSistema`.
 *
 * Pero «alcanzaba» no es «siempre». Un fin de semana sin visitas, el
 * devengo de mora no corre y el lunes la ficha del cliente dice una
 * cifra mientras la caja le cobra otra. Pasó: un día sin devengar y
 * treinta y cuatro cuotas quedaron desfasadas.
 *
 * Esto late por su cuenta. Mientras el proceso viva, cada minuto
 * mira si hay algo vencido y lo corre, haya o no usuarios.
 *
 * POR QUÉ NO ES UNA TAREA DEL PROGRAMADOR DE WINDOWS
 *
 * Porque ata el sistema a una máquina y a que alguien se acuerde de
 * registrarla. Al mover el sistema, restaurar una copia o levantarlo
 * en otro servidor, la tarea se queda atrás y nada avisa. Esto viaja
 * con el código.
 *
 * CÓMO ARRANCA SOLO
 *
 * Dos vías, y las dos llevan aquí:
 *
 *   1. Cualquier petición web comprueba si el latido está vivo. Si
 *      lleva más de unos minutos callado, lo levanta en segundo plano
 *      y sigue respondiendo como si nada. Basta con que alguien abra
 *      el sistema una vez para que quede latiendo.
 *
 *   2. A mano: `php bin/latido.php`.
 *
 * UNO SOLO A LA VEZ
 *
 * Un archivo de bloqueo con el identificador del proceso impide que
 * haya dos latidos. Dos devengando a la vez cobrarían el recargo dos
 * veces, y eso es dinero de alguien.
 *
 * SI SE CAE, NO SE PIERDE NADA
 *
 * Cada tarea cuenta días reales, no ciclos: el devengo que no corrió
 * ayer devenga hoy los dos días. Que el latido se detenga retrasa las
 * cosas, no las descuadra. Lo que sí hace es que el sistema lo note,
 * porque el verificador de cuadre comprueba que el latido esté vivo.
 *
 * Uso:
 *     php bin/latido.php                 late hasta que lo paren
 *     php bin/latido.php --cada=30       mirando cada 30 segundos
 *     php bin/latido.php --una-vuelta    una pasada y se va
 *     php bin/latido.php --estado        dice si hay uno vivo
 *     php bin/latido.php --parar         le pide al vivo que se vaya
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioTareasSistema;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/* Sin límite de tiempo: esto vive hasta que lo paren. */
@set_time_limit(0);
@ini_set('memory_limit', '256M');

$opciones = [];

foreach (array_slice($argv, 1) as $a) {
    if (!str_starts_with($a, '--')) { continue; }

    $par = explode('=', substr($a, 2), 2);
    $opciones[$par[0]] = $par[1] ?? true;
}

$cada = max(10, min(600, (int) ($opciones['cada'] ?? 60)));

$dirLatido = dirname(__DIR__) . '/storage/latido';

if (!is_dir($dirLatido)) { @mkdir($dirLatido, 0775, true); }

$archivoBloqueo = $dirLatido . '/latido.pid';
$archivoParada  = $dirLatido . '/parar';

// =====================================================================
// --estado y --parar
// =====================================================================

/** ¿Hay alguien latiendo ahora mismo? */
$vivo = static function () use ($archivoBloqueo): ?array {
    if (!is_file($archivoBloqueo)) { return null; }

    $d = json_decode((string) @file_get_contents($archivoBloqueo), true);

    if (!is_array($d) || !isset($d['pid'], $d['visto'])) { return null; }

    /* Si el último latido tiene más de tres minutos, ese proceso ya no
       está: se murió sin limpiar, que es lo que pasa cuando se apaga
       la máquina de golpe. */
    if (time() - (int) $d['visto'] > 180) { return null; }

    return $d;
};

if (isset($opciones['estado'])) {
    $v = $vivo();

    if ($v === null) {
        echo "  No hay latido vivo.\n";
        exit(1);
    }

    printf(
        "  Latido vivo (proceso %d), visto hace %d segundo(s).\n",
        (int) $v['pid'],
        time() - (int) $v['visto']
    );

    exit(0);
}

if (isset($opciones['parar'])) {
    @file_put_contents($archivoParada, (string) time());
    echo "  Aviso de parada dejado. El latido se irá en la próxima vuelta.\n";
    exit(0);
}

// =====================================================================
// Uno solo a la vez
// =====================================================================

$otro = $vivo();

if ($otro !== null && !isset($opciones['una-vuelta'])) {
    printf("  Ya hay un latido vivo (proceso %d). No arranco otro.\n", (int) $otro['pid']);
    exit(0);
}

@unlink($archivoParada);

$mio = getmypid();

/** Deja constancia de que sigo aquí. */
$marcar = static function () use ($archivoBloqueo, $mio): void {
    @file_put_contents($archivoBloqueo, json_encode([
        'pid'   => $mio,
        'visto' => time(),
        'desde' => date('c'),
    ]));
};

$marcar();

/* Al salir, por donde sea, se limpia: un archivo de bloqueo huérfano
 * impediría que el siguiente latido arranque durante tres minutos.
 *
 * Y SE DEJA UN RELEVO.
 *
 * Un latido que se muere por un fallo —se agotó la memoria, la base se
 * cayó un momento, una excepción se escapó— dejaba al sistema sin
 * tareas hasta que alguien entrara por la web. Eso es justo lo que no
 * puede pasar: la mora no se devenga, los plazos no vencen y las
 * remesas no caducan, todo en silencio.
 *
 * Así que antes de irse, si nadie le pidió parar, lanza a su
 * sustituto. Es lo más parecido a un servicio del sistema operativo
 * que se puede hacer sin depender del sistema operativo.
 *
 * NO cubre un corte de luz ni un reinicio de la máquina: ahí no queda
 * proceso que pueda lanzar nada. Para eso está la otra vía, que ahora
 * también funciona desde la consola: el primer contacto con el
 * sistema —una visita, cualquier `php bin/…`— lo levanta. */
register_shutdown_function(static function () use ($archivoBloqueo, $archivoParada, $mio): void {
    $d = json_decode((string) @file_get_contents($archivoBloqueo), true);

    $eraMio = is_array($d) && (int) ($d['pid'] ?? 0) === $mio;

    if ($eraMio) {
        @unlink($archivoBloqueo);
    }

    /* Si alguien pidió parar, se para de verdad: resucitar entonces
       sería desobedecer una orden expresa.
     *
     * Se mira la CONSTANTE, no el archivo: el bucle borra el archivo
     * al atender la parada, así que para cuando esto corre ya no está.
     * Se comprueban las dos de todas formas, por si la parada llegara
     * por otra vía. */
    if (defined('CM_LATIDO_PARADA_PEDIDA') || is_file($archivoParada)) {
        return;
    }

    /* Solo releva quien estaba latiendo de verdad: una pasada con
       `--una-vuelta` termina porque tiene que terminar. */
    if (!$eraMio || !defined('CM_LATIDO_CONTINUO')) {
        return;
    }

    try {
        ServicioTareasSistema::forzarArranque();

        error_log('[Latido] el proceso ' . $mio . ' terminó y dejó a su relevo');
    } catch (\Throwable $e) {
        error_log('[Latido] no se pudo lanzar el relevo: ' . $e->getMessage());
    }
});

if (function_exists('pcntl_signal')) {
    @pcntl_async_signals(true);

    foreach ([SIGTERM, SIGINT] as $senal) {
        @pcntl_signal($senal, static function (): void { exit(0); });
    }
}

// =====================================================================
// A latir
// =====================================================================

$silencioso = isset($opciones['silencioso']) || isset($opciones['fondo']);

/* La marca que distingue «estoy latiendo» de «vine a dar una vuelta y
   me voy». Solo el primero deja relevo al morir. */
if (!isset($opciones['una-vuelta'])) {
    define('CM_LATIDO_CONTINUO', true);
}

if (!$silencioso) {
    echo "\n  LATIDO DEL SISTEMA\n";
    echo '  ' . str_repeat('=', 68) . "\n\n";
    printf("  Proceso %d, mirando cada %d segundo(s).\n", $mio, $cada);
    echo "  Para pararlo: php bin/latido.php --parar\n\n";
}

$servicio = new ServicioTareasSistema();
$vueltas  = 0;

do {
    $vueltas++;

    try {
        /* TODAS las vencidas, no una.
         *
         * La ejecución por visitas corre una por petición para no
         * convertir la visita de un usuario en el proceso nocturno del
         * banco. Aquí no hay ningún usuario esperando, así que se
         * ponen al día todas: si el sistema estuvo tres días apagado,
         * al arrancar se recupera entero en vez de tardar ocho
         * vueltas. */
        $corridas = [];
        $tope     = 20;

        while ($tope-- > 0) {
            $clave = $servicio->correrUnaPendiente();

            if ($clave === null) { break; }

            $corridas[] = $clave;
        }

        if ($corridas !== [] && !$silencioso) {
            printf("  [%s] %s\n", date('H:i:s'), implode(', ', $corridas));
        }

        if ($corridas !== []) {
            error_log('[Latido] corrió: ' . implode(', ', $corridas));
        }
    } catch (\Throwable $e) {
        /* Un fallo no puede matar el latido: mañana hay que seguir
           devengando aunque hoy una tarea se haya caído. */
        error_log('[Latido] ' . $e->getMessage());

        if (!$silencioso) {
            printf("  [%s] fallo: %s\n", date('H:i:s'), $e->getMessage());
        }
    }

    if (isset($opciones['una-vuelta'])) { break; }

    /* La espera se parte en trozos para poder atender la parada sin
       esperar el minuto entero. */
    for ($i = 0; $i < $cada; $i++) {
        if (is_file($archivoParada)) {
            @unlink($archivoParada);

            /* Se deja constancia de que la salida fue PEDIDA.
             *
             * El cierre de abajo deja un relevo cuando el latido se
             * muere por su cuenta, y mirar el archivo no le sirve:
             * para entonces ya se borró aquí. Sin esta marca, pedir
             * «parar» arrancaría otro latido acto seguido y la orden
             * no se cumpliría nunca. */
            define('CM_LATIDO_PARADA_PEDIDA', true);

            if (!$silencioso) { echo "\n  Parada pedida. Hasta luego.\n\n"; }

            exit(0);
        }

        sleep(1);

        /* La marca se refresca cada pocos segundos: es lo que permite
           al resto del sistema saber que esto sigue vivo. */
        if ($i % 15 === 0) { $marcar(); }
    }

    $marcar();
} while (true);
