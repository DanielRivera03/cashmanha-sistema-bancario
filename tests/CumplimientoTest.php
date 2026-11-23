<?php

declare(strict_types=1);

/**
 * La vigilancia de lavado de dinero.
 *
 * QUÉ COMPRUEBA
 *
 * Que los umbrales de la ley son los de la ley, que el cómputo de días
 * hábiles no se come un fin de semana, que una operación sobre el
 * umbral levanta alerta y que levantarla dos veces no duplica nada.
 *
 * Y lo que más importa en un área de cumplimiento: que una alerta no
 * se pueda cerrar sin escribir por qué, y que un reporte nazca en
 * borrador y nunca como enviado.
 *
 * POR QUÉ ESO ÚLTIMO
 *
 * Porque un sistema que marcara un reporte como enviado sin haberlo
 * enviado sería peor que no tener nada: daría por cumplida una
 * obligación legal que seguiría pendiente, y nadie volvería a mirarla.
 *
 * Todo ocurre dentro de una transacción que se deshace.
 */

use CashMan\Aplicacion\Servicio\AlertaInvalida;
use CashMan\Aplicacion\Servicio\ServicioCumplimiento;
use CashMan\Dominio\Cumplimiento\UmbralesUIF;
use CashMan\Infraestructura\Persistencia\BaseDatos;

Pruebas::grupo('Cumplimiento · vigilancia de lavado');

// =====================================================================
// 1. Los umbrales y los plazos de la ley
// =====================================================================
Pruebas::cierto(
    UmbralesUIF::EFECTIVO_CENT === 1000000,
    'el umbral en efectivo son los US$10,000 del artículo 9'
);

Pruebas::cierto(
    UmbralesUIF::OTROS_MEDIOS_CENT === 2500000,
    'y por otros medios, US$25,000'
);

Pruebas::cierto(
    UmbralesUIF::DIAS_HABILES_REPORTE === 5 && UmbralesUIF::DIAS_HABILES_ANALISIS === 15,
    'cinco días hábiles para reportar y quince para analizar'
);

/* Un viernes más cinco días hábiles es el viernes siguiente, no el
   miércoles: contar corrido se comería el fin de semana y daría una
   fecha límite anterior a la que manda la ley. */
$viernes = new DateTimeImmutable('2026-11-13'); // viernes

Pruebas::cierto(
    (int) $viernes->format('N') === 5,
    'la fecha de referencia es un viernes'
);

$vence = UmbralesUIF::sumarHabiles($viernes, 5);

Pruebas::cierto(
    $vence->format('Y-m-d') === '2026-11-20',
    'cinco días hábiles desde un viernes caen el viernes siguiente, no el miércoles'
);

Pruebas::cierto(
    (int) UmbralesUIF::sumarHabiles(new DateTimeImmutable('2026-11-14'), 1)->format('N') === 1,
    'y un día hábil desde el sábado es el lunes'
);

/* El plazo de la acumulación mensual cuenta desde el cierre del mes,
   no desde la operación que cruzó el umbral. */
Pruebas::cierto(
    UmbralesUIF::venceReporteMensual(2026, 10)->format('Y-m-d') === '2026-11-06',
    'el plazo del acumulado de octubre corre desde el cierre de octubre'
);

// =====================================================================
// 2. El cotejo de nombres contra listas
// =====================================================================
Pruebas::cierto(
    UmbralesUIF::normalizarNombre('José  Peña-Ramírez') === 'jose pena ramirez',
    'los nombres se normalizan sin tildes, signos ni dobles espacios'
);

Pruebas::cierto(
    UmbralesUIF::nombresCoinciden('José Antonio Peña Ramírez', 'PENA RAMIREZ, JOSE'),
    'coincide aunque cambie el orden, las tildes y las mayúsculas'
);

Pruebas::falso(
    UmbralesUIF::nombresCoinciden('María Fernanda López', 'José Antonio Peña'),
    'y dos personas distintas no coinciden'
);

/* Deliberadamente estrecho: si bastara una parte del nombre, la lista
   marcaría a media cartera y se dejaría de mirar en una semana. */
Pruebas::falso(
    UmbralesUIF::nombresCoinciden('Carlos Ramírez Mejía', 'Ramírez'),
    'un apellido suelto no marca a nadie: daría cientos de falsos'
);

Pruebas::falso(
    UmbralesUIF::nombresCoinciden('Carlos Ramírez Mejía', 'Carlos Ramírez Portillo'),
    'y si una parte de la lista no está en el cliente, no coincide'
);

// =====================================================================
// 3. La detección, sobre datos de verdad
// =====================================================================
$sesion = BaseDatos::unaFila(
    'SELECT cs.id, cs.caja_id FROM caja_sesiones cs ORDER BY cs.id DESC LIMIT 1'
);

$cliente = BaseDatos::unaFila(
    'SELECT u.id, CONCAT(u.nombres," ",u.apellidos) AS nombre
       FROM usuarios u
       JOIN cuentas c ON c.usuario_id = u.id AND c.es_tecnica = 0
      WHERE u.estado = "activo" LIMIT 1'
);

if ($sesion === null || $cliente === null) {
    Pruebas::cierto(false, 'hay una sesión de caja y un cliente para probar');
    return;
}

try {
    BaseDatos::enTransaccion(static function () use ($sesion, $cliente): void {
        $servicio = new ServicioCumplimiento();
        $uid      = (int) $cliente['id'];

        /* Un día que no se solapa con nada real, para que lo que se
           detecte sea exactamente lo que esta prueba metió. */
        $dia = '2026-11-12';

        $meter = static function (int $cent, string $cuando) use ($sesion, $uid): void {
            BaseDatos::ejecutar(
                'INSERT INTO caja_movimientos
                   (sesion_id, tipo, medio, importe_cent, cliente_id, referencia,
                    concepto, creado_en)
                 VALUES (?, "deposito", "efectivo", ?, ?, ?, "Prueba de cumplimiento", ?)',
                [
                    (int) $sesion['id'],
                    $cent,
                    $uid,
                    'PRB-' . bin2hex(random_bytes(6)),
                    $cuando,
                ]
            );
        };

        // --- 3.1 Por debajo del umbral NO alerta -------------------
        $meter(900000, $dia . ' 09:00:00'); // $9,000

        $conteo = $servicio->vigilar($dia, true);

        Pruebas::cierto(
            ($conteo['umbral_efectivo_diario'] ?? 0) === 0,
            '$9,000 en efectivo no llega al umbral y no levanta alerta'
        );

        // --- 3.2 Cruzarlo sí ---------------------------------------
        $meter(250000, $dia . ' 14:00:00'); // suman $11,500

        $conteo = $servicio->vigilar($dia, true);

        Pruebas::cierto(
            ($conteo['umbral_efectivo_diario'] ?? 0) === 1,
            'al pasar de $10,000 en el día, se levanta la alerta'
        );

        $alerta = BaseDatos::unaFila(
            'SELECT * FROM alertas_cumplimiento
              WHERE usuario_id = ? AND tipo = "umbral_efectivo_diario" AND periodo_desde = ?',
            [$uid, $dia]
        );

        Pruebas::cierto(
            $alerta !== null && (int) $alerta['monto_cent'] === 1150000,
            'y la alerta anota el total movido en el día, no solo la operación que lo cruzó'
        );

        Pruebas::cierto(
            $alerta !== null && (int) $alerta['operaciones'] === 2,
            'con las dos operaciones que lo componen'
        );

        Pruebas::cierto(
            $alerta !== null && str_contains((string) $alerta['detalle'], 'artículo 9'),
            'y dice en qué se funda, no solo que pasó algo'
        );

        /* El plazo del análisis tiene que haber quedado puesto: una
           alerta sin fecha límite es una alerta que nadie prioriza. */
        Pruebas::cierto(
            $alerta !== null && $alerta['analisis_vence'] !== null
            && $alerta['analisis_vence'] > date('Y-m-d'),
            'con su plazo de análisis ya calculado'
        );

        // --- 3.3 Volver a barrer no duplica ------------------------
        $conteo = $servicio->vigilar($dia, true);

        Pruebas::cierto(
            ($conteo['umbral_efectivo_diario'] ?? 0) === 0,
            'barrer otra vez el mismo día no levanta la alerta por segunda vez'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM alertas_cumplimiento
                  WHERE usuario_id = ? AND tipo = "umbral_efectivo_diario" AND periodo_desde = ?',
                [$uid, $dia]
            ) === 1,
            'y sigue habiendo una sola: el panel no se llena de repetidos'
        );

        // --- 3.4 Fraccionamiento -----------------------------------
        $otro = (int) BaseDatos::unValor(
            'SELECT u.id FROM usuarios u
               JOIN cuentas c ON c.usuario_id = u.id AND c.es_tecnica = 0
              WHERE u.estado = "activo" AND u.id <> ? LIMIT 1',
            [$uid]
        );

        foreach ([['950000', '2026-11-10 10:00:00'], ['940000', '2026-11-12 11:00:00']] as $op) {
            BaseDatos::ejecutar(
                'INSERT INTO caja_movimientos
                   (sesion_id, tipo, medio, importe_cent, cliente_id, referencia,
                    concepto, creado_en)
                 VALUES (?, "deposito", "efectivo", ?, ?, ?, "Prueba fraccionamiento", ?)',
                [(int) $sesion['id'], (int) $op[0], $otro, 'PRB-' . bin2hex(random_bytes(6)), $op[1]]
            );
        }

        $conteo = $servicio->vigilar($dia, true);

        Pruebas::cierto(
            ($conteo['fraccionamiento'] ?? 0) >= 1,
            'dos depósitos de ~$9,450 en la misma semana levantan fraccionamiento'
        );

        $frac = BaseDatos::unaFila(
            'SELECT * FROM alertas_cumplimiento
              WHERE usuario_id = ? AND tipo = "fraccionamiento"
              ORDER BY id DESC LIMIT 1',
            [$otro]
        );

        Pruebas::cierto(
            $frac !== null && $frac['severidad'] === 'alta',
            'y pesa alto: ese patrón no ocurre por casualidad'
        );

        // --- 3.5 No se cierra una alerta sin decir por qué ---------
        $folio = (string) $alerta['folio'];

        $exigioRazon = false;

        try {
            $servicio->descartar($folio, $uid, 'ok');
        } catch (AlertaInvalida $e) {
            $exigioRazon = true;
        }

        Pruebas::cierto(
            $exigioRazon,
            'una alerta no se puede descartar con un «ok»: hay que escribir por qué'
        );

        // --- 3.6 Escalar crea un BORRADOR, nunca un enviado --------
        $r = $servicio->escalar(
            $folio,
            $uid,
            'El cliente depositó once mil quinientos dólares en efectivo en dos '
            . 'operaciones del mismo día sin justificar el origen de los fondos.',
            'ros'
        );

        $reporte = BaseDatos::unaFila(
            'SELECT * FROM reportes_uif WHERE folio = ?',
            [$r['folio']]
        );

        Pruebas::cierto(
            $reporte !== null && $reporte['estado'] === 'borrador',
            'escalar crea el reporte en BORRADOR: el sistema no envía nada a la UIF'
        );

        Pruebas::cierto(
            $reporte !== null && $reporte['enviado_en'] === null && $reporte['acuse'] === '',
            'y sin fecha de envío ni acuse, porque todavía no salió'
        );

        Pruebas::cierto(
            $reporte !== null && (int) $reporte['confidencial'] === 1,
            'un ROS queda marcado como confidencial: avisarle al cliente es delito'
        );

        Pruebas::cierto(
            $reporte !== null && $reporte['vence_en'] > date('Y-m-d'),
            'con su plazo legal de cinco días hábiles ya calculado'
        );

        Pruebas::cierto(
            (string) BaseDatos::unValor(
                'SELECT estado FROM alertas_cumplimiento WHERE folio = ?',
                [$folio]
            ) === 'escalada',
            'y la alerta queda marcada como escalada'
        );

        // --- 3.7 Un RTE no es confidencial -------------------------
        $folioFrac = (string) $frac['folio'];

        $r2 = $servicio->escalar(
            $folioFrac,
            $uid,
            'Acumulación de depósitos en efectivo que supera el umbral del artículo 9 '
            . 'de la Ley Contra el Lavado de Dinero y de Activos.',
            'rte'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor(
                'SELECT confidencial FROM reportes_uif WHERE folio = ?',
                [$r2['folio']]
            ) === 0,
            'un RTE no es confidencial: informar un monto no implica sospechar de nadie'
        );

        // --- 3.8 Marcar enviado exige el acuse ---------------------
        $exigioAcuse = false;

        try {
            $servicio->marcarEnviado((string) $r['folio'], $uid, '');
        } catch (AlertaInvalida $e) {
            $exigioAcuse = true;
        }

        Pruebas::cierto(
            $exigioAcuse,
            'no se puede dar por enviado un reporte sin el acuse que lo prueba'
        );

        $servicio->marcarEnviado((string) $r['folio'], $uid, 'UIF-2026-00123');

        Pruebas::cierto(
            (string) BaseDatos::unValor(
                'SELECT estado FROM alertas_cumplimiento WHERE folio = ?',
                [$folio]
            ) === 'reportada',
            'y al registrarlo, la alerta pasa a reportada'
        );

        throw new \RuntimeException('deshacer la prueba');
    });
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== 'deshacer la prueba') {
        throw $e;
    }
}

Pruebas::cierto(
    (int) BaseDatos::unValor(
        'SELECT COUNT(*) FROM caja_movimientos WHERE referencia LIKE "PRB-%"'
    ) === 0,
    'la prueba no deja movimientos ni alertas'
);
