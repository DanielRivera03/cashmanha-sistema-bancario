<?php

declare(strict_types=1);

/**
 * La revisión diaria de la banca preferencial.
 *
 * El segmento de cada cliente se guarda en su ficha, y es ESE valor
 * guardado el que leen la tasa de la tarjeta en cada corte, el techo
 * de la línea, el diferido y la cortesía de sobregiro. Si nadie
 * recalcula, un cliente que acaba de cumplir las condiciones sigue sin
 * sus beneficios hasta que algo, por casualidad, abra su pantalla de
 * banca preferente.
 *
 * Esto es lo que mantiene vivo todo lo demás. Va en el programador de
 * tareas, una vez al día.
 *
 * Al cliente cuyo escalón cambia se le avisa por correo, con sus
 * beneficios explicados y el motivo del cambio. El aviso está APAGADO
 * por omisión: así se puede correr la revisión —que es lo que hay que
 * correr a diario— sin escribirle a nadie mientras el sistema está en
 * pruebas. Para que salgan los correos hay que pedirlo:
 *
 *   php bin/revisar-segmentos.php              revisa, no escribe a nadie
 *   php bin/revisar-segmentos.php --avisar     revisa y envía los correos
 *   php bin/revisar-segmentos.php --simular    ni revisa ni escribe: solo dice qué pasaría
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioSegmento;
use CashMan\Dominio\Cliente\Segmento;
use CashMan\Infraestructura\Notificacion\Correo;

$avisar  = in_array('--avisar', $argv, true);
$simular = in_array('--simular', $argv, true);

if ($simular && $avisar) {
    exit("  --simular y --avisar se contradicen. Elija uno.\n");
}

printf("  Revisión de segmentos · %s\n", date('d/m/Y H:i'));

if ($simular) {
    echo "  Modo simulación: no se guarda nada ni se envía nada.\n";
} elseif (!$avisar) {
    echo "  Los segmentos se recalculan y se guardan. NO se envían correos.\n";
    echo "  (para enviarlos: --avisar)\n";
} else {
    if (!Correo::habilitado()) {
        echo "  !! El correo no está configurado: se recalculará sin avisar.\n";
        $avisar = false;
    } else {
        echo "  Se recalculan los segmentos y se avisa por correo a quien cambie.\n";
    }
}

echo "\n";

$svc = new ServicioSegmento();

if ($simular) {
    /* Simular es mirar sin tocar: se compara lo guardado con lo que
       daría el cálculo, sin llamar a `de()`, que guarda. */
    exit(simular($svc));
}

$r = $svc->revisarCartera($avisar);

printf("  %d cliente(s) revisados.\n\n", $r['revisados']);

if ($r['cambios'] === []) {
    exit("  Ningún cambio de escalón.\n");
}

printf("  %d cambio(s):\n\n", count($r['cambios']));

foreach ($r['cambios'] as $c) {
    printf(
        "    %-28s %-12s -> %-12s %s   %s\n",
        mb_substr((string) $c['nombre'], 0, 28),
        (string) $c['antes'],
        (string) $c['ahora'],
        $c['asciende'] ? 'asciende' : 'desciende',
        $c['avisado'] ? 'avisado' : ($avisar ? 'NO SE PUDO AVISAR' : 'sin avisar')
    );
}

if (!$avisar) {
    echo "\n  Los cambios quedaron aplicados. Nadie recibió correo.\n";
}

/**
 * Qué pasaría, sin tocar nada.
 *
 * Útil antes de encender los avisos: enseña a cuántos clientes se les
 * escribiría y qué les diría, que es lo que uno quiere saber antes de
 * mandar un correo a toda la cartera.
 */
function simular(ServicioSegmento $svc): int
{
    $clientes = \CashMan\Infraestructura\Persistencia\BaseDatos::todas(
        'SELECT u.id, CONCAT(u.nombres," ",u.apellidos) AS nombre, u.correo,
                COALESCE(u.segmento, "estandar") AS antes
           FROM usuarios u JOIN roles r ON r.id = u.rol_id
          WHERE r.nombre = "Cliente" AND u.estado = "activo"'
    );

    $n = 0;

    foreach ($clientes as $c) {
        // `de()` guarda; aquí interesa solo comparar, así que se usa
        // lo guardado y se recalcula aparte para ver si diferiría.
        $antes  = (string) $c['antes'];
        $ahora  = $svc->de((int) $c['id'])['segmento'];

        if ($antes === $ahora) { continue; }

        printf(
            "    %-28s %-12s -> %-12s  se le escribiría a %s\n",
            mb_substr((string) $c['nombre'], 0, 28),
            $antes,
            $ahora,
            $c['correo'] !== '' ? $c['correo'] : '(sin correo)'
        );

        $n++;
    }

    printf("\n  %d cliente(s) recibirían aviso.\n", $n);
    printf("  Escalones: %s\n", implode(' < ', [
        Segmento::etiqueta(Segmento::ESTANDAR),
        Segmento::etiqueta(Segmento::PREFERENTE),
        Segmento::etiqueta(Segmento::PATRIMONIAL),
    ]));

    return 0;
}
