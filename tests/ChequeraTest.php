<?php

declare(strict_types=1);

/**
 * Chequeras y cheques.
 *
 * QUÉ COMPRUEBA
 *
 *   · que los cheques existen desde que se IMPRIMEN, en blanco
 *   · que un papel con un número que no está en la tabla NO es un
 *     cheque de este banco
 *   · que el mismo cheque NO se puede pagar dos veces
 *   · que cada motivo de rechazo es el que corresponde, y que el más
 *     grave gana
 *   · que la orden de no pago frena el cobro
 *   · que al rechazar por culpa del librador se cobra comisión, y al
 *     rechazar porque él mismo lo suspendió, NO
 *   · que la partida doble cuadra
 *
 * POR QUÉ LO DEL DOBLE PAGO
 *
 * Es el fraude clásico con cheques y el que no se nota hasta que el
 * cliente reclama su estado de cuenta. Lo único que lo impide es que
 * el número sea único por cuenta.
 *
 * Todo ocurre dentro de una transacción que se deshace.
 */

use CashMan\Aplicacion\Servicio\ChequeraInvalida;
use CashMan\Aplicacion\Servicio\ServicioChequera;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Sesion;

Pruebas::grupo('Chequeras · cheques como títulos valor');

/* Hace falta una cuenta CORRIENTE: una de ahorro no gira cheques.
 *
 * La siembra solo trae cuentas de ahorro, así que la prueba se
 * provisiona la suya dentro de la transacción que después se deshace.
 * Es preferible a relajar la regla para que la prueba pase: la regla
 * es correcta y lo que faltaba eran los datos. */
$cliente = BaseDatos::unaFila(
    'SELECT u.id, u.usuario, u.rol_id
       FROM usuarios u
       JOIN cuentas c ON c.usuario_id = u.id AND c.es_tecnica = 0 AND c.estado = "activa"
      WHERE u.estado = "activo" AND c.saldo_cent > 100000
      ORDER BY c.saldo_cent DESC LIMIT 1'
);

$productoCorriente = (int) (BaseDatos::unValor(
    'SELECT id FROM productos WHERE tipo = "corriente" AND activo = 1 AND publico = 1
      ORDER BY id LIMIT 1'
) ?? 0);

if ($cliente === null || $productoCorriente === 0) {
    Pruebas::cierto(false, 'hay un cliente y un producto de cuenta corriente');
    return;
}

try {
    BaseDatos::enTransaccion(static function () use ($cliente, $productoCorriente): void {
        $huella = new ReflectionMethod(Sesion::class, 'huellaCliente');
        $huella->setAccessible(true);

        $_SESSION['usuario_id']       = (int) $cliente['id'];
        $_SESSION['rol_id']           = 1; // administrador: puede todo
        $_SESSION['usuario']          = (string) $cliente['usuario'];
        $_SESSION['creada_en']        = time();
        $_SESSION['ultima_actividad'] = time();
        $_SESSION['huella']           = $huella->invoke(null);

        \CashMan\Infraestructura\Seguridad\Autorizacion::limpiarCache();

        /* La cuenta corriente de la prueba, con fondos. Se crea aquí
           dentro y se deshace con todo lo demás. */
        BaseDatos::ejecutar(
            'INSERT INTO cuentas (uuid, numero_cuenta, usuario_id, producto_id,
                                  moneda, saldo_cent, estado, es_tecnica)
             VALUES (?, ?, ?, ?, "USD", 0, "activa", 0)',
            [
                \CashMan\Dominio\Comun\LibroMayor::uuid4(),
                '8' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
                (int) $cliente['id'],
                $productoCorriente,
            ]
        );

        $cuentaId = BaseDatos::ultimoId();

        /* Los fondos entran por un asiento de verdad: meterlos con un
           UPDATE dejaría la cuenta descuadrada contra el libro mayor y
           la comprobación final del cuadre lo cazaría. */
        $tecnica = (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
        );

        \CashMan\Dominio\Comun\LibroMayor::bloquearCuentas([$cuentaId, $tecnica]);

        \CashMan\Dominio\Comun\LibroMayor::registrar(
            'deposito',
            [
                ['cuenta_id' => $cuentaId, 'importe' => \CashMan\Dominio\Comun\Monto::deCentavos(500000),
                 'descripcion' => 'Fondeo de la cuenta de prueba'],
                ['cuenta_id' => $tecnica, 'importe' => \CashMan\Dominio\Comun\Monto::deCentavos(-500000),
                 'descripcion' => 'Contrapartida de la prueba'],
            ],
            (int) $cliente['id'],
            'prueba-chequera-' . uniqid(),
            'Fondeo de prueba'
        );

        $servicio = new ServicioChequera();

        // -------------------------------------------------------------
        // 1. Solicitar e imprimir
        // -------------------------------------------------------------
        $ch = $servicio->solicitar($cuentaId);

        Pruebas::cierto(
            (int) $ch['cantidad'] === 25 && (int) $ch['desde'] >= 1,
            'una chequera trae 25 cheques, numerados de corrido'
        );

        /* Antes de imprimir no hay cheques: el papel no existe. */
        Pruebas::cierto(
            (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM cheques WHERE chequera_id = ?', [(int) $ch['id']]
            ) === 0,
            'una chequera solicitada todavía no tiene cheques: no se han impreso'
        );

        $creados = $servicio->imprimir((string) $ch['folio']);

        Pruebas::cierto($creados === 25, 'al imprimirla nacen los 25 cheques, en blanco');

        Pruebas::cierto(
            (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM cheques WHERE chequera_id = ? AND estado = "en_blanco"',
                [(int) $ch['id']]
            ) === 25,
            'y los 25 están en blanco: existen para el banco antes de que nadie los llene'
        );

        /* Una segunda chequera no puede pedirse hasta retirar la
           primera: cincuenta cheques en blanco sueltos no. */
        $frenada = false;

        try {
            $servicio->solicitar($cuentaId);
        } catch (ChequeraInvalida $e) {
            $frenada = str_contains($e->getMessage(), 'Ya hay una chequera');
        }

        Pruebas::cierto($frenada, 'no se pueden pedir dos chequeras a la vez');

        $servicio->entregar((string) $ch['folio'], 'Daniel Alberto Rivera', (int) $cliente['id']);

        Pruebas::cierto(
            (string) BaseDatos::unValor(
                'SELECT entregada_a FROM chequeras WHERE folio = ?', [$ch['folio']]
            ) === 'Daniel Alberto Rivera',
            'y al entregarla se anota EN MANOS DE QUIÉN salieron del banco'
        );

        // -------------------------------------------------------------
        // 2. Un papel que no existe no es un cheque
        // -------------------------------------------------------------
        $inexistente = false;

        try {
            $servicio->presentar($cuentaId, 999999, 5000, 'Quien sea');
        } catch (ChequeraInvalida $e) {
            $inexistente = str_contains($e->getMessage(), 'no fue emitido');
        }

        Pruebas::cierto(
            $inexistente,
            'un papel con un número que no está en la tabla NO es un cheque de este banco'
        );

        // -------------------------------------------------------------
        // 3. Pagar uno
        // -------------------------------------------------------------
        $primero = (int) $ch['desde'];

        $antes = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]);

        $r = $servicio->presentar($cuentaId, $primero, 5000, 'Ferretería La Esperanza');

        Pruebas::cierto($r['estado'] === 'pagado', 'un cheque con fondos se paga');

        Pruebas::cierto(
            $antes - (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId])
                === 5000,
            'y salen exactamente los $50.00 de la cuenta del librador'
        );

        // --- NO se paga dos veces ----------------------------------
        $r2 = $servicio->presentar($cuentaId, $primero, 5000, 'Ferretería La Esperanza');

        Pruebas::cierto(
            $r2['estado'] === 'rechazado' && $r2['motivo'] === 'alterado',
            'presentar el MISMO cheque otra vez se rechaza: es el fraude clásico'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId])
                === $antes - 5000,
            'y no sale un centavo más de la cuenta'
        );

        // -------------------------------------------------------------
        // 4. La orden de no pago
        // -------------------------------------------------------------
        $suspendido = $primero + 1;

        $servicio->suspender($cuentaId, $suspendido, 'Se me perdió la chequera en el bus');

        Pruebas::cierto(
            (string) BaseDatos::unValor(
                'SELECT estado FROM cheques WHERE cuenta_id = ? AND numero = ?',
                [$cuentaId, $suspendido]
            ) === 'suspendido',
            'el titular puede dar orden de no pago sobre un cheque suyo'
        );

        $antesSusp = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]);

        $r3 = $servicio->presentar($cuentaId, $suspendido, 3000, 'Alguien');

        Pruebas::cierto(
            $r3['estado'] === 'rechazado' && $r3['motivo'] === 'suspendido_por_titular',
            'y si alguien lo presenta, no se paga'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId])
                === $antesSusp,
            'sin cobrarle comisión: frenar un robo no se castiga'
        );

        /* Y no se puede suspender lo que ya se pagó. */
        $tarde = false;

        try {
            $servicio->suspender($cuentaId, $primero, 'Ya no lo quiero pagar');
        } catch (ChequeraInvalida $e) {
            $tarde = str_contains($e->getMessage(), 'ya se pagó');
        }

        Pruebas::cierto($tarde, 'no se puede suspender un cheque que ya salió');

        // -------------------------------------------------------------
        // 5. Sin fondos: se rechaza Y se cobra comisión
        // -------------------------------------------------------------
        $grande = $primero + 2;

        $saldoActual = (int) BaseDatos::unValor(
            'SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]
        );

        $antesCom = $saldoActual;

        $r4 = $servicio->presentar($cuentaId, $grande, $saldoActual + 1000000, 'Alguien');

        Pruebas::cierto(
            $r4['estado'] === 'rechazado' && $r4['motivo'] === 'fondos_insuficientes',
            'un cheque por más de lo que hay se rechaza por fondos insuficientes'
        );

        Pruebas::cierto(
            $antesCom - (int) BaseDatos::unValor(
                'SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]
            ) === 1000,
            'y ahí sí se cobra la comisión: la culpa es del librador'
        );

        // -------------------------------------------------------------
        // 6. Las explicaciones son distintas entre sí
        // -------------------------------------------------------------
        Pruebas::cierto(
            ServicioChequera::explicarRechazo('sin_fondos')
                !== ServicioChequera::explicarRechazo('firma_no_coincide'),
            'cada motivo de rechazo se explica distinto: ante un juez no son lo mismo'
        );

        Pruebas::cierto(
            str_contains(ServicioChequera::explicarRechazo('caducado'), 'seis meses'),
            'y el de caducidad dice el plazo que manda el Código de Comercio'
        );

        // -------------------------------------------------------------
        // 7. La partida doble
        // -------------------------------------------------------------
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
    (int) BaseDatos::unValor('SELECT COUNT(*) FROM chequeras') === 0
    && (int) BaseDatos::unValor('SELECT COUNT(*) FROM cheques') === 0,
    'la prueba no deja chequeras ni cheques'
);
