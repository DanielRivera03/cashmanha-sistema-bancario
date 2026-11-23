<?php

declare(strict_types=1);

/**
 * Cómo se paga una constancia.
 *
 * Son tres caminos y cada uno tiene su regla:
 *
 *   · DE LA CUENTA, desde la banca en línea. El de siempre.
 *
 *   · CON TARJETA, pero SOLO tarjetas CashMan H.A. Aceptar la de otro
 *     banco significaría pasar el cobro por una red de adquirencia y
 *     pagarle a ese banco entre el 2% y el 3% por cobrar un arancel
 *     propio de $5.00.
 *
 *   · EN EFECTIVO, que no se cobra por internet. Atención al cliente
 *     prepara una solicitud, el cliente la paga en caja, y la
 *     constancia nace en ese momento.
 *
 * Lo que esta prueba vigila de verdad:
 *
 *   · que la tarjeta ajena se rechaza, y con un mensaje que lo explica;
 *   · que el cargo cae donde el cliente dijo y no en su cuenta;
 *   · que la constancia NO existe mientras la solicitud no se pague;
 *   · que el efectivo entra en el arqueo del cajero;
 *   · que una solicitud no se puede cobrar dos veces;
 *   · que una solicitud caducada no se cobra.
 *
 * Todo ocurre dentro de una transacción que se deshace.
 */

use CashMan\Aplicacion\Servicio\ConstanciaInvalida;
use CashMan\Aplicacion\Servicio\ServicioConstancias;
use CashMan\Aplicacion\Servicio\ServicioSolicitudConstancia;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

Pruebas::grupo('Constancias · cómo se pagan');

// ---------------------------------------------------------------------
// Lo que el esquema promete
// ---------------------------------------------------------------------

$formas = (string) BaseDatos::unValor(
    'SELECT COLUMN_TYPE FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = "constancias"
        AND column_name = "forma_pago"'
);

Pruebas::cierto(
    str_contains($formas, 'efectivo') && str_contains($formas, 'tarjeta')
    && str_contains($formas, 'cuenta') && str_contains($formas, 'exenta'),
    'la constancia guarda con qué se pagó'
);

$tiposCaja = (string) BaseDatos::unValor(
    'SELECT COLUMN_TYPE FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = "caja_movimientos"
        AND column_name = "tipo"'
);

Pruebas::cierto(
    str_contains($tiposCaja, 'constancia'),
    'la caja puede anotar este cobro: sin eso, el efectivo no entraría en el arqueo'
);

$permiso = (int) BaseDatos::unValor(
    'SELECT COUNT(*) FROM rol_permiso rp
       JOIN permisos p ON p.id = rp.permiso_id
       JOIN roles r ON r.id = rp.rol_id
      WHERE p.clave = "constancia.cobrar" AND r.clave = "cajero"'
);

Pruebas::cierto($permiso === 1, 'el cajero puede cobrarlas');

// ---------------------------------------------------------------------
// Con quién probar
// ---------------------------------------------------------------------

/* Un cliente con tarjeta de crédito CashMan activa y cuenta. La de
   crédito, porque es la que NO toca la cuenta: así se puede comprobar
   que el cargo cayó donde el cliente dijo. */
$cliente = BaseDatos::unaFila(
    'SELECT u.id, u.usuario, u.rol_id,
            c.id AS cuenta_id, c.saldo_cent,
            t.id AS tarjeta_id, t.limite_cent, t.saldo_usado_cent
       FROM usuarios u
       JOIN cuentas  c ON c.usuario_id = u.id AND c.es_tecnica = 0 AND c.estado = "activa"
       JOIN tarjetas t ON t.usuario_id = u.id AND t.estado = "activa"
                      AND t.tipo = "credito"
                      AND (t.congelada_hasta IS NULL OR t.congelada_hasta <= NOW())
      WHERE u.estado = "activo"
        AND c.saldo_cent > 10000
        AND t.limite_cent - t.saldo_usado_cent > 10000
      ORDER BY u.id
      LIMIT 1'
);

$cajero = BaseDatos::unaFila(
    'SELECT u.id, u.usuario, u.rol_id
       FROM usuarios u
       JOIN rol_permiso rp ON rp.rol_id = u.rol_id
       JOIN permisos p ON p.id = rp.permiso_id AND p.clave = "constancia.cobrar"
       JOIN rol_permiso rp2 ON rp2.rol_id = u.rol_id
       JOIN permisos p2 ON p2.id = rp2.permiso_id AND p2.clave = "caja.operar"
      WHERE u.estado = "activo"
      ORDER BY u.id LIMIT 1'
);

$oficial = BaseDatos::unaFila(
    'SELECT u.id, u.usuario, u.rol_id
       FROM usuarios u
       JOIN rol_permiso rp ON rp.rol_id = u.rol_id
       JOIN permisos p ON p.id = rp.permiso_id AND p.clave = "constancia.emitir_terceros"
      WHERE u.estado = "activo"
      ORDER BY u.id LIMIT 1'
);

if ($cliente === null || $cajero === null || $oficial === null) {
    Pruebas::cierto(
        false,
        'hay cliente con tarjeta de crédito, cajero y oficial para probar'
        . ' (cliente: ' . ($cliente === null ? 'no' : 'sí')
        . ', cajero: ' . ($cajero === null ? 'no' : 'sí')
        . ', oficial: ' . ($oficial === null ? 'no' : 'sí') . ')'
    );
    return;
}

$huella = new ReflectionMethod(Sesion::class, 'huellaCliente');
$huella->setAccessible(true);

/** Entra en el sistema como quien sea, para el resto de la prueba. */
$entrarComo = static function (array $u) use ($huella): void {
    $_SESSION['usuario_id']       = (int) $u['id'];
    $_SESSION['rol_id']           = (int) $u['rol_id'];
    $_SESSION['usuario']          = (string) $u['usuario'];
    $_SESSION['creada_en']        = time();
    $_SESSION['ultima_actividad'] = time();
    $_SESSION['huella']           = $huella->invoke(null);

    Autorizacion::limpiarCache();
};

try {
    BaseDatos::enTransaccion(static function () use ($cliente, $cajero, $oficial, $entrarComo): void {

        // =============================================================
        // 1. Con tarjeta CashMan
        // =============================================================
        $entrarComo($cliente);

        $servicio = new ServicioConstancias();

        $saldoAntes  = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?',
                                                [(int) $cliente['cuenta_id']]);
        $usadoAntes  = (int) BaseDatos::unValor('SELECT saldo_usado_cent FROM tarjetas WHERE id = ?',
                                                [(int) $cliente['tarjeta_id']]);

        $r = $servicio->emitir([
            'tipo'       => 'referencia_bancaria',
            'forma_pago' => 'tarjeta',
            'tarjeta_id' => (int) $cliente['tarjeta_id'],
        ]);

        $comision = (int) $r['comision_cent'];

        Pruebas::cierto(
            $comision > 0,
            'la referencia bancaria sí lleva arancel, que es lo que hace útil esta prueba'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?',
                                     [(int) $cliente['cuenta_id']]) === $saldoAntes,
            'pagando con tarjeta, la cuenta del cliente no se toca'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_usado_cent FROM tarjetas WHERE id = ?',
                                     [(int) $cliente['tarjeta_id']]) === $usadoAntes + $comision,
            'y el cargo aparece en la tarjeta, por el importe exacto del arancel'
        );

        /* Que entre en el corte es la diferencia entre un cargo que el
           cliente ve en su estado de cuenta y uno que nadie le factura
           nunca. */
        $mov = BaseDatos::unaFila(
            'SELECT tipo, estado, importe_cent FROM tarjeta_movimientos
              WHERE tarjeta_id = ? ORDER BY id DESC LIMIT 1',
            [(int) $cliente['tarjeta_id']]
        );

        Pruebas::cierto(
            $mov !== null && $mov['estado'] === 'liquidada'
            && (int) $mov['importe_cent'] === $comision,
            'el consumo queda liquidado, que es lo que el corte recoge'
        );

        $guardada = BaseDatos::unaFila(
            'SELECT forma_pago, tarjeta_id, pago_referencia FROM constancias WHERE folio = ?',
            [$r['folio']]
        );

        Pruebas::cierto(
            $guardada !== null && $guardada['forma_pago'] === 'tarjeta'
            && (int) $guardada['tarjeta_id'] === (int) $cliente['tarjeta_id'],
            'la constancia deja escrito con qué tarjeta se pagó'
        );

        Pruebas::cierto(
            $guardada !== null && (string) $guardada['pago_referencia'] !== '',
            'y con qué referencia, para poder responderle si reclama el cargo'
        );

        // =============================================================
        // 2. La tarjeta que no es nuestra
        // =============================================================

        /* Un identificador que no existe en `tarjetas` es, por
           definición, una tarjeta que este banco no emitió. */
        $inexistente = ((int) BaseDatos::unValor('SELECT COALESCE(MAX(id),0) FROM tarjetas')) + 1000;

        $mensaje = '';

        try {
            $servicio->emitir([
                'tipo'       => 'referencia_bancaria',
                'forma_pago' => 'tarjeta',
                'tarjeta_id' => $inexistente,
            ]);
        } catch (ConstanciaInvalida $e) {
            $mensaje = $e->getMessage();
        }

        Pruebas::cierto(
            str_contains($mensaje, 'CashMan') && str_contains($mensaje, 'otros bancos'),
            'una tarjeta que no es CashMan se rechaza, y el mensaje dice por qué'
        );

        // --- La tarjeta de otro cliente ------------------------------
        $ajena = BaseDatos::unValor(
            'SELECT id FROM tarjetas WHERE usuario_id <> ? AND estado = "activa" LIMIT 1',
            [(int) $cliente['id']]
        );

        if ($ajena !== null) {
            $m2 = '';

            try {
                $servicio->emitir([
                    'tipo'       => 'referencia_bancaria',
                    'forma_pago' => 'tarjeta',
                    'tarjeta_id' => (int) $ajena,
                ]);
            } catch (ConstanciaInvalida $e) {
                $m2 = $e->getMessage();
            }

            Pruebas::cierto(
                str_contains($m2, 'no es del titular'),
                'y la tarjeta de otro cliente tampoco pasa'
            );
        }

        // =============================================================
        // 3. El efectivo no se cobra por internet
        // =============================================================
        $m3 = '';

        try {
            $servicio->emitir([
                'tipo'       => 'referencia_bancaria',
                'forma_pago' => 'efectivo',
            ]);
        } catch (ConstanciaInvalida $e) {
            $m3 = $e->getMessage();
        }

        Pruebas::cierto(
            str_contains($m3, 'caja'),
            'el efectivo no se cobra desde la banca: manda a caja, que es donde hay gaveta'
        );

        // =============================================================
        // 4. El camino de agencia
        // =============================================================
        $entrarComo($oficial);

        $solicitudes = new ServicioSolicitudConstancia();

        $constanciasAntes = (int) BaseDatos::unValor('SELECT COUNT(*) FROM constancias');

        $sol = $solicitudes->generar([
            'tipo'       => 'referencia_bancaria',
            'usuario_id' => (int) $cliente['id'],
            'dirigida_a' => 'Ministerio de Trabajo',
            'proposito'  => 'trámite laboral',
        ]);

        Pruebas::falso(
            (bool) ($sol['directa'] ?? false),
            'una constancia con arancel no se emite en atención: se manda a caja'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT COUNT(*) FROM constancias') === $constanciasAntes,
            'y mientras no se pague, la constancia NO existe: nada que anular después'
        );

        Pruebas::cierto(
            (string) BaseDatos::unValor(
                'SELECT estado FROM constancia_solicitudes WHERE folio = ?',
                [$sol['folio']]
            ) === 'pendiente_pago',
            'queda pendiente de pago, con su folio para la ventanilla'
        );

        // --- La gratis sí sale en el acto ----------------------------
        $gratis = $solicitudes->generar([
            'tipo'       => 'no_adeudo',
            'usuario_id' => (int) $cliente['id'],
        ]);

        Pruebas::cierto(
            (bool) ($gratis['directa'] ?? false) === true,
            'la que no cobra nada se emite ahí mismo: hacer cola para pagar cero no tiene sentido'
        );

        // =============================================================
        // 5. La caja la cobra
        // =============================================================
        $entrarComo($cajero);

        /* El turno. Sin caja abierta no hay dónde anotar el efectivo, y
           el servicio se planta antes de tocar nada. */
        $sinTurno = '';

        try {
            $solicitudes->cobrar((string) $sol['folio']);
        } catch (ConstanciaInvalida $e) {
            $sinTurno = $e->getMessage();
        }

        $yaHabiaTurno = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM caja_sesiones WHERE usuario_id = ? AND estado = "abierta"',
            [(int) $cajero['id']]
        ) > 0;

        if (!$yaHabiaTurno) {
            Pruebas::cierto(
                str_contains($sinTurno, 'Abra su caja'),
                'sin turno abierto no se cobra: un cobro fuera del arqueo le sale al cajero como sobrante'
            );

            $cajaId = (int) BaseDatos::unValor('SELECT id FROM cajas ORDER BY id LIMIT 1');

            BaseDatos::ejecutar(
                'INSERT INTO caja_sesiones (uuid, folio, caja_id, usuario_id,
                                            fondo_inicial_cent, abierta_en, estado)
                 VALUES (UUID(), ?, ?, ?, 0, NOW(), "abierta")',
                ['PRB-' . bin2hex(random_bytes(6)), $cajaId, (int) $cajero['id']]
            );
        }

        $sesionId = (int) BaseDatos::unValor(
            'SELECT id FROM caja_sesiones WHERE usuario_id = ? AND estado = "abierta" LIMIT 1',
            [(int) $cajero['id']]
        );

        $enGavetaAntes = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(importe_cent),0) FROM caja_movimientos
              WHERE sesion_id = ? AND medio = "efectivo"',
            [$sesionId]
        );

        $cobro = $solicitudes->cobrar((string) $sol['folio']);

        Pruebas::cierto(
            isset($cobro['folio']) && $cobro['folio'] !== '',
            'al cobrar, la constancia nace con su folio'
        );

        Pruebas::cierto(
            (string) BaseDatos::unValor(
                'SELECT forma_pago FROM constancias WHERE folio = ?',
                [$cobro['folio']]
            ) === 'efectivo',
            'y queda dicho que se pagó en efectivo'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor(
                'SELECT COALESCE(SUM(importe_cent),0) FROM caja_movimientos
                  WHERE sesion_id = ? AND medio = "efectivo"',
                [$sesionId]
            ) === $enGavetaAntes + (int) $sol['comision_cent'],
            'el efectivo entra en el arqueo del cajero, por el importe del arancel'
        );

        Pruebas::cierto(
            (string) BaseDatos::unValor(
                'SELECT tipo FROM caja_movimientos WHERE sesion_id = ? ORDER BY id DESC LIMIT 1',
                [$sesionId]
            ) === 'constancia',
            'anotado como lo que es, no camuflado de depósito'
        );

        Pruebas::cierto(
            (string) BaseDatos::unValor(
                'SELECT estado FROM constancia_solicitudes WHERE folio = ?',
                [$sol['folio']]
            ) === 'emitida',
            'y la solicitud queda cerrada'
        );

        // --- Dos veces, no -------------------------------------------
        $m4 = '';

        try {
            $solicitudes->cobrar((string) $sol['folio']);
        } catch (ConstanciaInvalida $e) {
            $m4 = $e->getMessage();
        }

        Pruebas::cierto(
            str_contains($m4, 'ya se cobró'),
            'la misma solicitud no se cobra dos veces: el folio se dicta a mano y se repite'
        );

        // =============================================================
        // 6. La que caducó
        // =============================================================
        $entrarComo($oficial);

        $vieja = $solicitudes->generar([
            'tipo'       => 'referencia_bancaria',
            'usuario_id' => (int) $cliente['id'],
        ]);

        BaseDatos::ejecutar(
            'UPDATE constancia_solicitudes SET vence_en = DATE_SUB(NOW(), INTERVAL 1 HOUR)
              WHERE folio = ?',
            [$vieja['folio']]
        );

        $entrarComo($cajero);

        $m5 = '';

        try {
            $solicitudes->cobrar((string) $vieja['folio']);
        } catch (ConstanciaInvalida $e) {
            $m5 = $e->getMessage();
        }

        Pruebas::cierto(
            str_contains($m5, 'caducó'),
            'una solicitud vencida no se cobra: certificaría los datos de otro día'
        );

        Pruebas::cierto(
            (string) BaseDatos::unValor(
                'SELECT estado FROM constancia_solicitudes WHERE folio = ?',
                [$vieja['folio']]
            ) === 'vencida',
            'y queda marcada como vencida en el mismo intento'
        );

        // =============================================================
        // 7. La partida doble aguanta todo esto
        // =============================================================
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

Autorizacion::limpiarCache();
