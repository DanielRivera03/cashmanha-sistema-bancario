<?php

declare(strict_types=1);

/**
 * Depósitos a plazo fijo.
 *
 * QUÉ COMPRUEBA
 *
 *   · que el interés se calcula sobre base 360, como manda el oficio
 *   · que la retención del art. 159 se aplica sobre los INTERESES y
 *     nunca sobre el capital, y que respeta la exención
 *   · que el capital sale de la cuenta del cliente y entra a la del
 *     depósito, con su asiento
 *   · que NO se puede transferir desde un plazo fijo
 *   · que al vencer se paga capital más intereses netos
 *   · que cancelar antes cuesta intereses, nunca capital
 *   · que la partida doble cuadra
 *
 * POR QUÉ LO DEL CAPITAL
 *
 * Penalizar el capital de alguien que retira antes de tiempo no es una
 * penalidad: es quedarse con su dinero, y ningún contrato de depósito
 * lo permite. Se le pagan menos intereses, que es otra cosa.
 *
 * Todo ocurre dentro de una transacción que se deshace.
 */

use CashMan\Aplicacion\Servicio\PlazoInvalido;
use CashMan\Aplicacion\Servicio\ServicioPlazoFijo;
use CashMan\Aplicacion\Servicio\ServicioTransferencia;
use CashMan\Dominio\Plazo\CalculoPlazo;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\AccesoDenegado;
use CashMan\Infraestructura\Seguridad\Sesion;

Pruebas::grupo('Plazo fijo · cálculo y constitución');

// =====================================================================
// 1. El cálculo puro
// =====================================================================
Pruebas::cierto(
    CalculoPlazo::diasBase() === 360,
    'los intereses se calculan sobre un año de 360 días, como la banca'
);

/* $10,000 al 5.50% durante 360 días = $550.00 exactos. */
Pruebas::cierto(
    CalculoPlazo::interes(1000000, 550, 360) === 55000,
    '$10,000 al 5.50% a 360 días dan $550.00 exactos'
);

/* La mitad del plazo, la mitad del interés. */
Pruebas::cierto(
    CalculoPlazo::interes(1000000, 550, 180) === 27500,
    'y a 180 días, la mitad'
);

Pruebas::cierto(
    CalculoPlazo::interes(1000000, 0, 360) === 0
    && CalculoPlazo::interes(0, 550, 360) === 0
    && CalculoPlazo::interes(1000000, 550, 0) === 0,
    'sin capital, sin tasa o sin días no hay interés'
);

// --- La retención -----------------------------------------------------
Pruebas::cierto(
    CalculoPlazo::retencionBp() === 1000,
    'la retención de renta es del 10%, según el artículo 159'
);

/* Capital por debajo del umbral: exento. */
Pruebas::cierto(
    CalculoPlazo::retencion(55000, 1000000) === 0,
    'un depósito de $10,000 está exento: no supera los $25,000'
);

/* Por encima del umbral: se retiene el 10% de los INTERESES. */
Pruebas::cierto(
    CalculoPlazo::retencion(165000, 3000000) === 16500,
    'uno de $30,000 sí retiene, y retiene el 10% de los intereses'
);

Pruebas::cierto(
    CalculoPlazo::retencion(0, 9000000) === 0,
    'sin intereses no hay nada que retener, por grande que sea el capital'
);

/* La comprobación que de verdad importa: la retención JAMÁS toca el
   capital. Se prueba con un caso extremo. */
$p = CalculoPlazo::proyeccion(5000000, 650, 720);

Pruebas::cierto(
    $p['total_cent'] > 5000000,
    'por mucha retención que haya, el cliente nunca recibe menos de su capital'
);

Pruebas::cierto(
    $p['retencion_cent'] === intdiv($p['interes_cent'], 10),
    'la retención es exactamente el 10% de los intereses, ni un centavo del capital'
);

Pruebas::cierto(
    $p['total_cent'] === $p['capital_cent'] + $p['interes_cent'] - $p['retencion_cent'],
    'y el total cuadra: capital + intereses - retención'
);

// --- La cancelación anticipada ---------------------------------------
$c = CalculoPlazo::cancelacionAnticipada(
    1000000, 550, date('Y-m-d', strtotime('-180 days'))
);

Pruebas::cierto(
    $c['dias_transcurridos'] === 180,
    'la cancelación cuenta los días transcurridos de verdad'
);

Pruebas::cierto(
    $c['penalidad_cent'] === intdiv($c['interes_devengado_cent'], 2),
    'y la penalidad se lleva la mitad de los intereses devengados'
);

Pruebas::cierto(
    $c['total_cent'] >= 1000000,
    'pero el capital vuelve ENTERO: penalizar el capital sería quedarse con su dinero'
);

// =====================================================================
// 2. El dinero
// =====================================================================
$cliente = BaseDatos::unaFila(
    'SELECT u.id, u.usuario, u.rol_id, c.id AS cuenta_id, c.saldo_cent
       FROM usuarios u
       JOIN cuentas c ON c.usuario_id = u.id AND c.es_tecnica = 0 AND c.estado = "activa"
       JOIN productos p ON p.id = c.producto_id AND p.tipo <> "plazo"
      WHERE u.estado = "activo" AND c.saldo_cent > 200000
      ORDER BY c.saldo_cent DESC LIMIT 1'
);

if ($cliente === null) {
    Pruebas::cierto(false, 'hay un cliente con saldo para probar');
    return;
}

try {
    BaseDatos::enTransaccion(static function () use ($cliente): void {
        $huella = new ReflectionMethod(Sesion::class, 'huellaCliente');
        $huella->setAccessible(true);

        $_SESSION['usuario_id']       = (int) $cliente['id'];
        $_SESSION['rol_id']           = (int) $cliente['rol_id'];
        $_SESSION['usuario']          = (string) $cliente['usuario'];
        $_SESSION['creada_en']        = time();
        $_SESSION['ultima_actividad'] = time();
        $_SESSION['huella']           = $huella->invoke(null);

        $servicio = new ServicioPlazoFijo();
        $cuentaId = (int) $cliente['cuenta_id'];

        // --- La simulación ------------------------------------------
        $sim = $servicio->simular(100000, 360);

        Pruebas::cierto(
            $sim['tasa_bp'] === 550 && $sim['interes_cent'] === 5500,
            'simular $1,000 a 360 días da el 5.50% y $55.00 de interés'
        );

        Pruebas::cierto(
            isset($sim['total_cent'], $sim['retencion_cent'], $sim['tasa_efectiva_bp']),
            'y le dice al cliente lo que va a RECIBIR, no solo una tasa que interpretar'
        );

        $sinTasa = false;

        try {
            $servicio->simular(100000, 7);
        } catch (PlazoInvalido $e) {
            $sinTasa = true;
        }

        Pruebas::cierto($sinTasa, 'un plazo que no está en el tarifario se rechaza');

        // --- Constituir ----------------------------------------------
        $antes = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]);

        $d = $servicio->constituir([
            'cuenta_origen_id' => $cuentaId,
            'monto'            => '1000.00',
            'dias'             => 360,
            'renovacion'       => 'no',
        ]);

        Pruebas::cierto(
            $antes - (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId])
                === 100000,
            'el capital sale de la cuenta del cliente'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor(
                'SELECT saldo_cent FROM cuentas WHERE id = ?', [(int) $d['cuenta_id']]
            ) === 100000,
            'y entra a la cuenta del depósito, donde el libro mayor lo vigila'
        );

        Pruebas::cierto(
            str_starts_with((string) $d['numero'], '9'),
            'la cuenta del depósito se distingue por su numeración'
        );

        // --- NO se puede transferir desde un plazo fijo --------------
        $bloqueado = false;

        try {
            (new ServicioTransferencia())->ejecutar([
                'cuenta_origen_id'   => (int) $d['cuenta_id'],
                'cuenta_destino_id'  => $cuentaId,
                'monto'              => '10.00',
                'clave_idempotencia' => 'test-dpf-' . uniqid(),
            ]);
        } catch (AccesoDenegado $e) {
            $bloqueado = str_contains($e->getMessage(), 'plazo fijo');
        } catch (\RuntimeException $e) {
            /* Cualquier otro rechazo también cuenta como bloqueo, pero
               se quiere EL mensaje: el cliente tiene que entender por
               qué no puede, y qué puede hacer en su lugar. */
            $bloqueado = false;
        }

        Pruebas::cierto(
            $bloqueado,
            'NO se puede transferir desde un plazo fijo, y se explica por qué'
        );

        // --- Liquidar al vencimiento ---------------------------------
        $antesAbono = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]);

        $liq = $servicio->liquidar((string) $d['folio'], (int) $cliente['id'], false);

        Pruebas::cierto(
            (int) $liq['capital_cent'] === 100000 && (int) $liq['interes_cent'] === 5500,
            'al vencer se pagan los días PACTADOS, no los transcurridos'
        );

        Pruebas::cierto(
            (int) $liq['retencion_cent'] === 0,
            'y $1,000 está exento de retención'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId])
                - $antesAbono === 105500,
            'el cliente recibe su capital más los intereses netos'
        );

        Pruebas::cierto(
            (string) BaseDatos::unValor(
                'SELECT estado FROM cuentas WHERE id = ?', [(int) $d['cuenta_id']]
            ) === 'cerrada',
            'y la cuenta del depósito se cierra: ya no tiene razón de existir'
        );

        // --- Cancelación anticipada ----------------------------------
        $d2 = $servicio->constituir([
            'cuenta_origen_id' => $cuentaId,
            'monto'            => '1000.00',
            'dias'             => 360,
        ]);

        /* Se retrocede la fecha de inicio para simular medio plazo. */
        BaseDatos::ejecutar(
            'UPDATE depositos_plazo SET fecha_inicio = DATE_SUB(CURDATE(), INTERVAL 180 DAY)
              WHERE folio = ?',
            [$d2['folio']]
        );

        $antesCancel = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]);

        $can = $servicio->liquidar((string) $d2['folio'], (int) $cliente['id'], true);

        Pruebas::cierto(
            (int) $can['penalidad_cent'] > 0,
            'cancelar antes de tiempo tiene penalidad'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId])
                - $antesCancel >= 100000,
            'pero el capital vuelve entero: la penalidad son intereses, no capital'
        );

        // --- La partida doble ----------------------------------------
        Pruebas::cierto(
            (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM (
                     SELECT asiento_id FROM movimientos
                      GROUP BY asiento_id HAVING SUM(importe_cent) <> 0
                 ) x'
            ) === 0,
            'después de todo esto, no hay un solo asiento que no sume cero'
        );

        throw new \RuntimeException('deshacer la prueba');
    });
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== 'deshacer la prueba') {
        throw $e;
    }
}

Pruebas::cierto(
    (int) BaseDatos::unValor('SELECT COUNT(*) FROM depositos_plazo') === 0,
    'la prueba no deja depósitos ni cuentas nuevas'
);
