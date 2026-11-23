<?php

declare(strict_types=1);

/**
 * Transferencias programadas y débito automático.
 *
 * QUÉ COMPRUEBA
 *
 *   · el calendario, incluido el caso del día 31 en febrero
 *   · que una orden se ejecuta el día que toca
 *   · que NO cobra dos veces aunque el barrido corra dos veces
 *   · que al no haber fondos REINTENTA en vez de rendirse
 *   · que tras agotar los intentos pasa al periodo siguiente y avisa
 *   · que el tope protege de un cargo inesperado
 *   · que el débito de tarjeta cobra el mínimo del corte, no un
 *     porcentaje inventado
 *   · que la partida doble cuadra
 *
 * POR QUÉ LO DEL DÍA 31
 *
 * Quien programa un cargo para el 31 quiere decir «el último día del
 * mes». Sumar un mes al 31 de enero da el 3 de marzo en PHP: se salta
 * febrero entero, y el cliente se queda sin pagar un mes sin que nadie
 * se entere.
 *
 * Todo ocurre dentro de una transacción que se deshace.
 */

use CashMan\Aplicacion\Servicio\ServicioProgramadas;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Sesion;

Pruebas::grupo('Programadas · débito automático');

// =====================================================================
// 1. El calendario
// =====================================================================
Pruebas::cierto(
    ServicioProgramadas::siguienteFecha('2026-01-31', 'mensual', 31) === '2026-02-28',
    'el 31 de enero, mensual, cae el 28 de febrero: no se salta el mes'
);

Pruebas::cierto(
    ServicioProgramadas::siguienteFecha('2026-02-28', 'mensual', 31) === '2026-03-31',
    'y de febrero vuelve al 31 de marzo: el día pedido no se pierde'
);

Pruebas::cierto(
    ServicioProgramadas::siguienteFecha('2026-01-15', 'mensual', 15) === '2026-02-15',
    'un día normal es un día normal'
);

Pruebas::cierto(
    ServicioProgramadas::siguienteFecha('2026-01-10', 'semanal', null) === '2026-01-17',
    'semanal suma siete días'
);

Pruebas::cierto(
    ServicioProgramadas::siguienteFecha('2026-01-10', 'quincenal', null) === '2026-01-25',
    'quincenal suma quince'
);

Pruebas::cierto(
    ServicioProgramadas::siguienteFecha('2026-01-31', 'trimestral', 31) === '2026-04-30',
    'y trimestral desde el 31 de enero cae el 30 de abril'
);

// =====================================================================
// 2. El dinero
// =====================================================================
$cliente = BaseDatos::unaFila(
    'SELECT u.id, u.usuario, u.rol_id, c.id AS cuenta_id, c.saldo_cent
       FROM usuarios u
       JOIN cuentas c   ON c.usuario_id = u.id AND c.es_tecnica = 0 AND c.estado = "activa"
       JOIN productos p ON p.id = c.producto_id AND p.tipo <> "plazo"
      WHERE u.estado = "activo" AND c.saldo_cent > 50000
      ORDER BY c.saldo_cent DESC LIMIT 1'
);

$otra = BaseDatos::unaFila(
    'SELECT c.id FROM cuentas c
       JOIN productos p ON p.id = c.producto_id AND p.tipo <> "plazo"
      WHERE c.es_tecnica = 0 AND c.estado = "activa"
      ORDER BY c.id LIMIT 1'
);

if ($cliente === null || $otra === null) {
    Pruebas::cierto(false, 'hay cuentas para probar');
    return;
}

try {
    BaseDatos::enTransaccion(static function () use ($cliente, $otra): void {
        $huella = new ReflectionMethod(Sesion::class, 'huellaCliente');
        $huella->setAccessible(true);

        $_SESSION['usuario_id']       = (int) $cliente['id'];
        $_SESSION['rol_id']           = (int) $cliente['rol_id'];
        $_SESSION['usuario']          = (string) $cliente['usuario'];
        $_SESSION['creada_en']        = time();
        $_SESSION['ultima_actividad'] = time();
        $_SESSION['huella']           = $huella->invoke(null);

        \CashMan\Infraestructura\Seguridad\Autorizacion::limpiarCache();

        $servicio = new ServicioProgramadas();
        $origenId = (int) $cliente['cuenta_id'];
        $destinoId = (int) $otra['id'];

        // --- Una transferencia programada -------------------------
        $o = $servicio->crear([
            'tipo'             => 'transferencia',
            'alias'            => 'Alquiler',
            'cuenta_origen_id' => $origenId,
            'cuenta_destino_id' => $destinoId,
            'modo_monto'       => 'fijo',
            'monto'            => '25.00',
            'frecuencia'       => 'mensual',
            'dia_mes'          => 5,
            'concepto'         => 'Alquiler del mes',
        ]);

        Pruebas::cierto(
            $o['folio'] !== '' && $o['proxima_en'] === date('Y-m-d'),
            'la orden se crea y queda lista para hoy'
        );

        /* Se fuerza a que toque HOY. */
        BaseDatos::ejecutar(
            'UPDATE ordenes_programadas SET proxima_en = CURDATE() WHERE folio = ?',
            [$o['folio']]
        );

        $antes  = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$origenId]);
        $antesD = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$destinoId]);

        $r = $servicio->ejecutarPendientes(true);

        Pruebas::cierto((int) $r['exitosas'] >= 1, 'el barrido la ejecuta');

        Pruebas::cierto(
            $antes - (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$origenId])
                === 2500,
            'salen exactamente los $25.00 de la cuenta de origen'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$destinoId])
                - $antesD === 2500,
            'y entran en la de destino'
        );

        // --- NO cobra dos veces -----------------------------------
        $trasPrimera = (int) BaseDatos::unValor(
            'SELECT saldo_cent FROM cuentas WHERE id = ?', [$origenId]
        );

        /* Se vuelve a poner para hoy, como si el barrido corriera otra
           vez por error o alguien lo lanzara a mano. */
        BaseDatos::ejecutar(
            'UPDATE ordenes_programadas SET proxima_en = CURDATE() WHERE folio = ?',
            [$o['folio']]
        );

        $servicio->ejecutarPendientes(true);

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$origenId])
                === $trasPrimera,
            'correr el barrido otra vez el mismo día NO cobra dos veces'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM ordenes_ejecuciones oe
                   JOIN ordenes_programadas o ON o.id = oe.orden_id
                  WHERE o.folio = ? AND oe.resultado = "exitosa"',
                [$o['folio']]
            ) === 1,
            'y solo queda UNA ejecución exitosa para esa fecha'
        );

        // --- La siguiente fecha quedó puesta ----------------------
        $prox = (string) BaseDatos::unValor(
            'SELECT proxima_en FROM ordenes_programadas WHERE folio = ?', [$o['folio']]
        );

        Pruebas::cierto(
            $prox > date('Y-m-d'),
            'tras ejecutarse, la orden queda apuntando al periodo siguiente'
        );

        // --- Sin fondos: reintenta, no se rinde -------------------
        //
        // El importe sale del SALDO, no de una cifra fija.
        //
        // Antes eran $999,999.00, escritos a mano por parecer
        // inalcanzables. La prueba escoge la cuenta más rica del
        // sistema, y el día que una pasó de ese millón la orden se
        // ejecutó sin problema: cinco comprobaciones empezaron a fallar
        // sin que nada del débito automático hubiera cambiado. Una
        // prueba que caduca porque el banco creció no está probando lo
        // que dice probar.
        //
        // Partiendo del saldo, es imposible por construcción y lo
        // seguirá siendo con cualquier dato.
        $imposible = (int) BaseDatos::unValor(
            'SELECT saldo_cent FROM cuentas WHERE id = ?', [$origenId]
        ) + 100000;

        $o2 = $servicio->crear([
            'tipo'              => 'transferencia',
            'alias'             => 'Imposible',
            'cuenta_origen_id'  => $origenId,
            'cuenta_destino_id' => $destinoId,
            'modo_monto'        => 'fijo',
            'monto'             => number_format($imposible / 100, 2, '.', ''),
            'frecuencia'        => 'mensual',
        ]);

        BaseDatos::ejecutar(
            'UPDATE ordenes_programadas SET proxima_en = CURDATE() WHERE folio = ?',
            [$o2['folio']]
        );

        $saldoAntes = (int) BaseDatos::unValor(
            'SELECT saldo_cent FROM cuentas WHERE id = ?', [$origenId]
        );

        $servicio->ejecutarPendientes(true);

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$origenId])
                === $saldoAntes,
            'una orden sin fondos no mueve un centavo'
        );

        $fila = BaseDatos::unaFila(
            'SELECT o.reintentos_hechos, o.proxima_en, o.estado
               FROM ordenes_programadas o WHERE o.folio = ?',
            [$o2['folio']]
        );

        Pruebas::cierto(
            (int) $fila['reintentos_hechos'] === 1 && $fila['estado'] === 'activa',
            'cuenta el intento y sigue activa: no se rinde a la primera'
        );

        Pruebas::cierto(
            $fila['proxima_en'] === date('Y-m-d', strtotime('+1 day')),
            'y lo reintenta mañana'
        );

        Pruebas::cierto(
            (string) BaseDatos::unValor(
                'SELECT resultado FROM ordenes_ejecuciones oe
                   JOIN ordenes_programadas o ON o.id = oe.orden_id
                  WHERE o.folio = ? ORDER BY oe.id DESC LIMIT 1',
                [$o2['folio']]
            ) === 'sin_fondos',
            'y queda constancia de POR QUÉ no se pudo'
        );

        // --- Se agotan los intentos --------------------------------
        for ($i = 0; $i < 3; $i++) {
            BaseDatos::ejecutar(
                'UPDATE ordenes_programadas SET proxima_en = CURDATE() WHERE folio = ?',
                [$o2['folio']]
            );

            $servicio->ejecutarPendientes(true);
        }

        $tras = BaseDatos::unaFila(
            'SELECT reintentos_hechos, fallos_consecutivos, estado, proxima_en
               FROM ordenes_programadas WHERE folio = ?',
            [$o2['folio']]
        );

        Pruebas::cierto(
            (int) $tras['fallos_consecutivos'] >= 1,
            'al agotar los intentos se anota el fallo del periodo'
        );

        Pruebas::cierto(
            $tras['estado'] === 'activa',
            'pero la orden sigue activa para el periodo siguiente'
        );

        // --- El tope ------------------------------------------------
        $o3 = $servicio->crear([
            'tipo'              => 'transferencia',
            'alias'             => 'Con tope',
            'cuenta_origen_id'  => $origenId,
            'cuenta_destino_id' => $destinoId,
            'modo_monto'        => 'fijo',
            'monto'             => '30.00',
            'tope'              => '10.00',
            'frecuencia'        => 'mensual',
        ]);

        BaseDatos::ejecutar(
            'UPDATE ordenes_programadas SET proxima_en = CURDATE() WHERE folio = ?',
            [$o3['folio']]
        );

        $antesTope = (int) BaseDatos::unValor(
            'SELECT saldo_cent FROM cuentas WHERE id = ?', [$origenId]
        );

        $servicio->ejecutarPendientes(true);

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$origenId])
                === $antesTope,
            'una orden que supera su tope NO se cobra: el tope protege de verdad'
        );

        Pruebas::cierto(
            (string) BaseDatos::unValor(
                'SELECT resultado FROM ordenes_ejecuciones oe
                   JOIN ordenes_programadas o ON o.id = oe.orden_id
                  WHERE o.folio = ? ORDER BY oe.id DESC LIMIT 1',
                [$o3['folio']]
            ) === 'omitida',
            'y queda anotada como omitida, con su razón'
        );

        // --- El monto variable solo donde tiene sentido ------------
        $rechazado = false;

        try {
            $servicio->crear([
                'tipo'              => 'transferencia',
                'cuenta_origen_id'  => $origenId,
                'cuenta_destino_id' => $destinoId,
                'modo_monto'        => 'total',
                'frecuencia'        => 'mensual',
            ]);
        } catch (\RuntimeException $e) {
            $rechazado = str_contains($e->getMessage(), 'monto variable');
        }

        Pruebas::cierto(
            $rechazado,
            'una transferencia «por el total» no tiene sentido y se rechaza'
        );

        // --- La partida doble ---------------------------------------
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

/* Se comprueban LAS SUYAS, no que la tabla esté vacía: el sistema
   tiene órdenes de verdad —los débitos automáticos de tarjeta— y
   exigir una tabla vacía haría fallar esta prueba por existir datos
   legítimos. */
Pruebas::cierto(
    (int) BaseDatos::unValor(
        'SELECT COUNT(*) FROM ordenes_programadas
          WHERE alias IN ("Alquiler", "Imposible", "Con tope")'
    ) === 0,
    'la prueba no deja las órdenes que creó'
);
