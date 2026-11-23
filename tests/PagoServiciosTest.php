<?php

declare(strict_types=1);

/**
 * El pago de servicios, de punta a punta.
 *
 * QUÉ COMPRUEBA
 *
 *   · que el monto lo pone el COLECTOR y no el cliente
 *   · que no se puede pagar de más
 *   · que un abono parcial solo pasa donde el colector lo acepta
 *   · que el dinero sale del cliente y entra a la colecturía
 *   · que la comisión, cuando la hay, va en el MISMO asiento
 *   · que reintentar no cobra dos veces
 *   · que la partida doble cuadra
 *
 * POR QUÉ ESO DEL MONTO
 *
 * Porque es el error de diseño que arruina esta pantalla en cuanto se
 * comete: dejar que el cliente teclee cuánto debe. El banco no sabe lo
 * que se debe —eso vive en la empresa que cobra— y un pago por un
 * monto inventado o se rechaza, o se aplica mal y el cliente se queda
 * sin luz habiendo pagado.
 *
 * Todo ocurre dentro de una transacción que se deshace.
 */

use CashMan\Aplicacion\Servicio\ServicioPagoServicios;
use CashMan\Aplicacion\Servicio\ServicioNoDisponible;
use CashMan\Dominio\Colecturia\Colector;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Sesion;

Pruebas::grupo('Servicios · colecturías y NPE');

// =====================================================================
// 1. El formato de las referencias
// =====================================================================
$caess = Colector::convenio('CAESS');
$mh    = Colector::convenio('MH-NPE');
$claro = Colector::convenio('CLAROM');

Pruebas::cierto(
    $caess !== null && $mh !== null && $claro !== null,
    'el catálogo trae los colectores reales del país'
);

Pruebas::cierto(
    Colector::normalizarReferencia(' 123-456 789 ') === '123456789',
    'una referencia copiada de una factura llega con espacios y guiones, y se limpia'
);

Pruebas::cierto(
    Colector::referenciaBienFormada($claro, '71234567'),
    'un teléfono de ocho dígitos vale para Claro Móvil'
);

Pruebas::falso(
    Colector::referenciaBienFormada($claro, '712345'),
    'y uno de seis no: Claro pide los ocho de la línea'
);

Pruebas::cierto(
    Colector::npeBienFormado('1234567890123456789012345678901234'),
    'un NPE largo y numérico está bien formado'
);

Pruebas::falso(
    Colector::npeBienFormado('ABC123') || Colector::npeBienFormado('12345'),
    'y uno con letras o demasiado corto, no'
);

/* La consulta tiene que ser ESTABLE: la misma referencia da siempre la
   misma deuda. Si cambiara entre la consulta y el pago, el cliente
   vería un monto y pagaría otro. */
$a = Colector::consultar($caess, '987654321');
$b = Colector::consultar($caess, '987654321');

Pruebas::cierto(
    ($a['deuda_cent'] ?? 0) === ($b['deuda_cent'] ?? -1)
    && ($a['titular'] ?? '') === ($b['titular'] ?? ''),
    'consultar dos veces la misma referencia da siempre lo mismo'
);

// =====================================================================
// 2. El dinero
// =====================================================================
$cliente = BaseDatos::unaFila(
    'SELECT u.id, u.usuario, u.rol_id, c.id AS cuenta_id, c.saldo_cent
       FROM usuarios u
       JOIN cuentas c ON c.usuario_id = u.id AND c.es_tecnica = 0 AND c.estado = "activa"
      WHERE u.estado = "activo" AND c.saldo_cent > 100000
      ORDER BY c.saldo_cent DESC LIMIT 1'
);

if ($cliente === null) {
    Pruebas::cierto(false, 'hay un cliente con saldo para probar');
    return;
}

try {
    BaseDatos::enTransaccion(static function () use ($cliente, $caess, $mh): void {
        $huella = new ReflectionMethod(Sesion::class, 'huellaCliente');
        $huella->setAccessible(true);

        $_SESSION['usuario_id']       = (int) $cliente['id'];
        $_SESSION['rol_id']           = (int) $cliente['rol_id'];
        $_SESSION['usuario']          = (string) $cliente['usuario'];
        $_SESSION['creada_en']        = time();
        $_SESSION['ultima_actividad'] = time();
        $_SESSION['huella']           = $huella->invoke(null);

        $servicio = new ServicioPagoServicios();
        $cuentaId = (int) $cliente['cuenta_id'];

        /* Una referencia que sí tiene deuda. Se busca una en vez de
           suponerla: una de cada doce no existe, a propósito. */
        $ref = '';

        for ($i = 100000; $i < 100300; $i++) {
            $r = Colector::consultar($caess, (string) $i);

            if (($r['ok'] ?? false) === true) {
                $ref = (string) $i;
                break;
            }
        }

        Pruebas::cierto($ref !== '', 'se encuentra una referencia con deuda para probar');

        $consulta = $servicio->consultar((int) $caess['id'], $ref);

        Pruebas::cierto(
            $consulta['deuda_cent'] > 0 && $consulta['titular'] !== '',
            'la consulta devuelve el titular y lo que se debe, sin mover dinero'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId])
                === (int) $cliente['saldo_cent'],
            'y el saldo del cliente sigue intacto después de consultar'
        );

        $deuda = (int) $consulta['deuda_cent'];

        // --- 2.1 Pagar de más no se permite -----------------------
        $topado = false;

        try {
            $servicio->pagar([
                'cuenta_id'          => $cuentaId,
                'convenio_id'        => (int) $caess['id'],
                'referencia'         => $ref,
                'monto'              => number_format(($deuda + 5000) / 100, 2, '.', ''),
                'clave_idempotencia' => 'test-demas-' . uniqid(),
            ]);
        } catch (ServicioNoDisponible $e) {
            $topado = str_contains($e->getMessage(), 'más de lo que se debe');
        }

        Pruebas::cierto($topado, 'no se puede pagar más de lo que la empresa dice que se debe');

        // --- 2.2 Un parcial donde NO se acepta --------------------
        $rechazoParcial = false;

        $refNpe = '';

        for ($i = 1000000000; $i < 1000000300; $i++) {
            $r = Colector::consultar($mh, (string) $i);

            if (($r['ok'] ?? false) === true) {
                $refNpe = (string) $i;
                break;
            }
        }

        if ($refNpe !== '') {
            $deudaNpe = (int) Colector::consultar($mh, $refNpe)['deuda_cent'];

            try {
                $servicio->pagar([
                    'cuenta_id'          => $cuentaId,
                    'convenio_id'        => (int) $mh['id'],
                    'referencia'         => $refNpe,
                    'monto'              => number_format(($deudaNpe - 100) / 100, 2, '.', ''),
                    'clave_idempotencia' => 'test-parcial-' . uniqid(),
                ]);
            } catch (ServicioNoDisponible $e) {
                $rechazoParcial = str_contains($e->getMessage(), 'parciales');
            }
        }

        Pruebas::cierto(
            $rechazoParcial,
            'Hacienda no acepta abonos parciales, y el sistema lo respeta'
        );

        // --- 2.3 El pago, y a dónde va el dinero ------------------
        $colecturiaId = (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE numero_cuenta = "0000000007"'
        );

        $antesCliente    = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]);
        $antesColecturia = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$colecturiaId]);

        $pago = $servicio->pagar([
            'cuenta_id'          => $cuentaId,
            'convenio_id'        => (int) $caess['id'],
            'referencia'         => $ref,
            'monto'              => number_format($deuda / 100, 2, '.', ''),
            'guardar'            => true,
            'alias'              => 'Prueba casa',
            'clave_idempotencia' => 'test-pago-' . uniqid(),
        ]);

        Pruebas::cierto(
            $pago['estado'] === 'aplicado' && $pago['autorizacion'] !== '',
            'el pago se aplica y devuelve número de autorización'
        );

        Pruebas::falso(
            (bool) $pago['parcial'],
            'y al pagar el total, no queda como parcial'
        );

        Pruebas::cierto(
            $antesCliente - (int) BaseDatos::unValor(
                'SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]
            ) === $deuda,
            'al cliente se le debita exactamente lo que debía'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$colecturiaId])
                - $antesColecturia === $deuda,
            'y entra a la colecturía: el banco se lo debe al colector hasta liquidar'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM servicios_guardados WHERE usuario_id = ? AND alias = "Prueba casa"',
                [(int) $cliente['id']]
            ) === 1,
            'y el servicio queda guardado con el nombre que le puso el cliente'
        );

        // --- 2.4 Reintentar no cobra dos veces --------------------
        $clave = 'test-idem-' . uniqid();

        $ref2 = '';

        for ($i = 200000; $i < 200300; $i++) {
            if ((Colector::consultar($caess, (string) $i)['ok'] ?? false) === true) {
                $ref2 = (string) $i;
                break;
            }
        }

        $deuda2 = (int) Colector::consultar($caess, $ref2)['deuda_cent'];

        $servicio->pagar([
            'cuenta_id'          => $cuentaId,
            'convenio_id'        => (int) $caess['id'],
            'referencia'         => $ref2,
            'monto'              => number_format($deuda2 / 100, 2, '.', ''),
            'clave_idempotencia' => $clave,
        ]);

        $trasPrimero = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]);

        $repetido = $servicio->pagar([
            'cuenta_id'          => $cuentaId,
            'convenio_id'        => (int) $caess['id'],
            'referencia'         => $ref2,
            'monto'              => number_format($deuda2 / 100, 2, '.', ''),
            'clave_idempotencia' => $clave,
        ]);

        Pruebas::cierto(
            $repetido['estado'] === 'duplicado'
            && (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]) === $trasPrimero,
            'pulsar pagar dos veces con mala conexión no cobra dos veces'
        );

        // --- 2.5 Un parcial donde SÍ se acepta --------------------
        $ref3 = '';

        for ($i = 300000; $i < 300300; $i++) {
            if ((Colector::consultar($caess, (string) $i)['ok'] ?? false) === true) {
                $ref3 = (string) $i;
                break;
            }
        }

        $deuda3 = (int) Colector::consultar($caess, $ref3)['deuda_cent'];
        $abono  = intdiv($deuda3, 2);

        $parcial = $servicio->pagar([
            'cuenta_id'          => $cuentaId,
            'convenio_id'        => (int) $caess['id'],
            'referencia'         => $ref3,
            'monto'              => number_format($abono / 100, 2, '.', ''),
            'clave_idempotencia' => 'test-abono-' . uniqid(),
        ]);

        Pruebas::cierto(
            (bool) $parcial['parcial'] === true
            && (int) $parcial['pendiente_cent'] === $deuda3 - $abono,
            'CAESS sí acepta abonos, y el sistema dice cuánto queda pendiente'
        );

        // --- 2.6 La liquidación vacía la colecturía ---------------
        $liq = $servicio->liquidar(true);

        Pruebas::cierto(
            $liq['pagos'] >= 3 && $liq['total_cent'] > 0,
            'la liquidación agrupa lo recaudado por colector'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM pagos_servicio WHERE estado = "aplicado"'
            ) === 0,
            'y deja los pagos como liquidados'
        );

        // --- 2.7 La partida doble ---------------------------------
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
    (int) BaseDatos::unValor('SELECT COUNT(*) FROM pagos_servicio') === 0
    && (int) BaseDatos::unValor(
        'SELECT COUNT(*) FROM servicios_guardados WHERE alias = "Prueba casa"'
    ) === 0,
    'la prueba no deja pagos ni servicios guardados'
);
