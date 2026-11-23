<?php

declare(strict_types=1);

/**
 * Remesas familiares, de punta a punta.
 *
 * QUÉ COMPRUEBA
 *
 *   · que el código de control se guarda CIFRADO y nunca en claro
 *   · que se puede buscar por código sin descifrar la tabla
 *   · que un mismo código no entra dos veces: pagar dos veces la misma
 *     remesa es un agujero que no se ve hasta que la remesadora reclama
 *   · que se puede cobrar a cuenta Y en efectivo, porque así cobra la
 *     mayoría
 *   · que el dinero sale de la remesadora, no del banco
 *   · que una remesa vencida no se puede cobrar
 *   · que la partida doble cuadra en los dos caminos
 *
 * Todo ocurre dentro de una transacción que se deshace.
 */

use CashMan\Aplicacion\Servicio\RemesaInvalida;
use CashMan\Aplicacion\Servicio\ServicioRemesas;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Sesion;

Pruebas::grupo('Remesas · familiares');

$cliente = BaseDatos::unaFila(
    'SELECT u.id, u.usuario, u.rol_id, u.documento_identidad,
            CONCAT(u.nombres, " ", u.apellidos) AS nombre,
            c.id AS cuenta_id, c.saldo_cent
       FROM usuarios u
       JOIN cuentas c ON c.usuario_id = u.id AND c.es_tecnica = 0 AND c.estado = "activa"
      WHERE u.estado = "activo" AND u.documento_identidad <> ""
      ORDER BY u.id LIMIT 1'
);

$sesionCaja = BaseDatos::unaFila(
    'SELECT id FROM caja_sesiones WHERE cerrada_en IS NULL ORDER BY id DESC LIMIT 1'
);

if ($cliente === null) {
    Pruebas::cierto(false, 'hay un cliente con documento para probar');
    return;
}

try {
    BaseDatos::enTransaccion(static function () use ($cliente, $sesionCaja): void {
        $huella = new ReflectionMethod(Sesion::class, 'huellaCliente');
        $huella->setAccessible(true);

        $_SESSION['usuario_id']       = (int) $cliente['id'];
        $_SESSION['rol_id']           = (int) $cliente['rol_id'];
        $_SESSION['usuario']          = (string) $cliente['usuario'];
        $_SESSION['creada_en']        = time();
        $_SESSION['ultima_actividad'] = time();
        $_SESSION['huella']           = $huella->invoke(null);

        $servicio = new ServicioRemesas();

        $tecnicaId = (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE numero_cuenta = "0000000008"'
        );

        // -------------------------------------------------------------
        // 1. Llega una remesa
        // -------------------------------------------------------------
        $codigo = '1234567890'; // MTCN de Western Union: diez dígitos

        $r = $servicio->registrarLlegada([
            'remesadora'             => 'WU',
            'codigo'                 => $codigo,
            'beneficiario_nombre'    => (string) $cliente['nombre'],
            'beneficiario_documento' => (string) $cliente['documento_identidad'],
            'monto'                  => '250.00',
            'remitente_nombre'       => 'Carlos Alberto Mejía',
            'remitente_pais'         => 'US',
        ]);

        Pruebas::cierto(
            $r['estado'] === 'disponible' && $r['monto_cent'] === 25000,
            'una remesa que llega queda disponible, sin mover dinero todavía'
        );

        Pruebas::cierto(
            (bool) $r['es_cliente'] === true,
            'y si el beneficiario ya es cliente, se le ata a su usuario'
        );

        // --- El código NO está en claro en ninguna parte -------------
        $fila = BaseDatos::unaFila(
            'SELECT codigo_cifrado, codigo_hash, codigo_cola FROM remesas WHERE folio = ?',
            [$r['folio']]
        );

        Pruebas::falso(
            str_contains((string) $fila['codigo_cifrado'], $codigo),
            'el código de control se guarda cifrado: quien lo tiene, cobra'
        );

        Pruebas::cierto(
            $fila['codigo_cola'] === '7890',
            'y solo los últimos cuatro quedan en claro, para identificarla en pantalla'
        );

        // -------------------------------------------------------------
        // 2. Se encuentra por el código, y por el documento
        // -------------------------------------------------------------
        $hallada = $servicio->porCodigo('WU', '1234-567890');

        Pruebas::cierto(
            $hallada !== null && $hallada['folio'] === $r['folio'],
            'se encuentra por el código aunque venga escrito con guiones'
        );

        $porDoc = $servicio->porDocumento((string) $cliente['documento_identidad']);

        Pruebas::cierto(
            count($porDoc) >= 1,
            'y por el documento, que es como llega la gente a ventanilla'
        );

        // -------------------------------------------------------------
        // 3. El mismo código no entra dos veces
        // -------------------------------------------------------------
        $rechazado = false;

        try {
            $servicio->registrarLlegada([
                'remesadora'             => 'WU',
                'codigo'                 => $codigo,
                'beneficiario_nombre'    => 'Otra Persona',
                'beneficiario_documento' => '99999999-9',
                'monto'                  => '100.00',
            ]);
        } catch (RemesaInvalida $e) {
            $rechazado = str_contains($e->getMessage(), 'ya está registrado');
        }

        Pruebas::cierto(
            $rechazado,
            'el mismo código no se registra dos veces: sería pagar la misma remesa dos veces'
        );

        // -------------------------------------------------------------
        // 4. Cobrarla a cuenta
        // -------------------------------------------------------------
        $cuentaId = (int) $cliente['cuenta_id'];

        $antesCliente = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]);
        $antesTecnica = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$tecnicaId]);

        $servicio->acreditar((string) $r['folio'], $cuentaId, (int) $cliente['id']);

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId])
                - $antesCliente === 25000,
            'al acreditarla, el cliente recibe los $250.00'
        );

        Pruebas::cierto(
            $antesTecnica - (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$tecnicaId])
                === 25000,
            'y salen de la remesadora, no del banco'
        );

        Pruebas::cierto(
            (string) BaseDatos::unValor('SELECT estado FROM remesas WHERE folio = ?', [$r['folio']])
                === 'pagada',
            'la remesa queda pagada'
        );

        // Y no se puede cobrar dos veces.
        $doble = false;

        try {
            $servicio->acreditar((string) $r['folio'], $cuentaId, (int) $cliente['id']);
        } catch (RemesaInvalida $e) {
            $doble = true;
        }

        Pruebas::cierto($doble, 'y no se puede volver a cobrar');

        // -------------------------------------------------------------
        // 5. Cobrarla en efectivo, que es como cobra la mayoría
        // -------------------------------------------------------------
        if ($sesionCaja !== null) {
            $r2 = $servicio->registrarLlegada([
                'remesadora'             => 'MG',
                'codigo'                 => '87654321',
                'beneficiario_nombre'    => 'Rosa Elena Campos',
                'beneficiario_documento' => '04567890-1',
                'monto'                  => '120.00',
                'remitente_nombre'       => 'Juan Campos',
            ]);

            Pruebas::falso(
                (bool) $r2['es_cliente'],
                'una remesa puede llegar a nombre de alguien que no es cliente'
            );

            $antesTecnica2 = (int) BaseDatos::unValor(
                'SELECT saldo_cent FROM cuentas WHERE id = ?', [$tecnicaId]
            );

            $servicio->pagarEnEfectivo(
                (string) $r2['folio'],
                (int) $sesionCaja['id'],
                (int) $cliente['id']
            );

            Pruebas::cierto(
                $antesTecnica2 - (int) BaseDatos::unValor(
                    'SELECT saldo_cent FROM cuentas WHERE id = ?', [$tecnicaId]
                ) === 12000,
                'pagarla en efectivo también la saca de la remesadora'
            );

            Pruebas::cierto(
                (int) BaseDatos::unValor(
                    'SELECT COUNT(*) FROM caja_movimientos
                      WHERE referencia = ? AND tipo = "remesa_salida" AND medio = "efectivo"',
                    [$r2['folio']]
                ) === 1,
                'y deja el movimiento de caja, para que el arqueo cuadre'
            );
        } else {
            Pruebas::cierto(true, '(sin caja abierta: no se probó el pago en efectivo)');
        }

        // -------------------------------------------------------------
        // 6. Una remesa vencida no se cobra
        // -------------------------------------------------------------
        $r3 = $servicio->registrarLlegada([
            'remesadora'             => 'RIA',
            'codigo'                 => '12345678901',
            'beneficiario_nombre'    => (string) $cliente['nombre'],
            'beneficiario_documento' => (string) $cliente['documento_identidad'],
            'monto'                  => '75.00',
        ]);

        BaseDatos::ejecutar(
            'UPDATE remesas SET expira_en = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE folio = ?',
            [$r3['folio']]
        );

        $vencida = false;

        try {
            $servicio->acreditar((string) $r3['folio'], $cuentaId, (int) $cliente['id']);
        } catch (RemesaInvalida $e) {
            $vencida = str_contains($e->getMessage(), 'venció');
        }

        Pruebas::cierto($vencida, 'una remesa vencida no se puede cobrar: la remesadora ya la reclamó');

        $vencidas = $servicio->vencerCaducadas();

        Pruebas::cierto(
            $vencidas >= 1
            && (string) BaseDatos::unValor('SELECT estado FROM remesas WHERE folio = ?', [$r3['folio']])
               === 'devuelta',
            'y el barrido las marca como devueltas, en vez de dejarlas como disponibles'
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
    (int) BaseDatos::unValor('SELECT COUNT(*) FROM remesas') === 0,
    'la prueba no deja ninguna remesa'
);
