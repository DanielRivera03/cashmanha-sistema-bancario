<?php

declare(strict_types=1);

/**
 * Transferencias a otros bancos, de punta a punta.
 *
 * QUÉ COMPRUEBA
 *
 * Lo único que de verdad importa en esto: que el dinero acabe donde
 * tiene que acabar, y que cuando no llega, vuelva.
 *
 *   · que un destino nuevo NO sirve hasta pasar el enfriamiento
 *   · que al enviar, el dinero sale del cliente y entra a la cámara
 *   · que un rechazo lo devuelve, con asiento de reverso y sin borrar
 *     el original
 *   · que al propio banco llega en el acto, sin pasar por la cámara
 *   · que el límite que el cliente se puso a sí mismo se respeta
 *   · que la partida doble cuadra en todos los casos
 *
 * POR QUÉ ASÍ
 *
 * Porque una transferencia interbancaria es la única operación de este
 * sistema en la que el dinero pasa por un estado en el que ya no es
 * del cliente y todavía no es de nadie más. Equivocarse ahí no produce
 * un error visible: produce un descuadre que aparece semanas después,
 * cuando ya nadie recuerda qué lo causó.
 *
 * Todo ocurre dentro de una transacción que se deshace: no deja ni un
 * centavo movido ni un beneficiario registrado.
 */

use CashMan\Aplicacion\Servicio\ServicioBeneficiarios;
use CashMan\Aplicacion\Servicio\ServicioInterbancario;
use CashMan\Aplicacion\Servicio\ServicioLimitesCliente;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Interbancario\RedDePagos;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Sesion;

Pruebas::grupo('Interbancario · Transfer365 y la cámara');

// =====================================================================
// 1. La red de pagos: reglas puras, sin base de datos
// =====================================================================
Pruebas::cierto(
    RedDePagos::celularValido('7123-4567') && RedDePagos::celularValido('+503 6111 2222'),
    'un celular salvadoreño vale con guiones y con código de país'
);

Pruebas::falso(
    RedDePagos::celularValido('2234-5678'),
    'un número fijo no sirve para Transfer365 Móvil'
);

Pruebas::cierto(
    RedDePagos::normalizarCelular('+503 7123-4567') === '71234567',
    'y se guarda siempre como los ocho dígitos del abonado'
);

Pruebas::cierto(
    RedDePagos::cuentaValida('12345678') && RedDePagos::cuentaValida('0011 2233 4455 6677'),
    'una cuenta de otro banco vale entre 8 y 20 dígitos'
);

Pruebas::falso(
    RedDePagos::cuentaValida('123') || RedDePagos::cuentaValida('ABC12345'),
    'y no vale ni corta ni con letras'
);

Pruebas::cierto(
    RedDePagos::titularCoincide('Jose Antonio Ramirez', 'JOSÉ RAMÍREZ LÓPEZ'),
    'el titular coincide aunque cambien tildes, mayúsculas y un apellido'
);

Pruebas::falso(
    RedDePagos::titularCoincide('Maria Fernanda Perez', 'Carlos Alberto Gomez'),
    'pero dos personas distintas no coinciden'
);

/* La ventana de UNI no abre en fin de semana: es el servicio antiguo y
   solo compensa en día hábil. Se comprueba con un sábado fijo para que
   la prueba no dependa del día en que se ejecute. */
$sabado = (int) strtotime('2026-11-14 10:00:00');

Pruebas::falso(
    RedDePagos::ventanaAbierta('uni', $sabado),
    'UNI no opera en sábado'
);

Pruebas::cierto(
    RedDePagos::ventanaAbierta('transfer365', $sabado),
    'pero Transfer365 sí: corre los siete días'
);

Pruebas::cierto(
    (int) date('N', RedDePagos::proximaVentana('uni', $sabado)) === 1,
    'y lo que entra el sábado por UNI sale el lunes'
);

// =====================================================================
// 2. El dinero
// =====================================================================

/* Un cliente de la siembra con saldo suficiente y sin beneficiarios. */
$cliente = BaseDatos::unaFila(
    'SELECT u.id, u.usuario, u.rol_id, c.id AS cuenta_id, c.saldo_cent, c.numero_cuenta
       FROM usuarios u
       JOIN cuentas c ON c.usuario_id = u.id AND c.es_tecnica = 0 AND c.estado = "activa"
      WHERE u.estado = "activo" AND c.saldo_cent > 200000
      ORDER BY c.saldo_cent DESC
      LIMIT 1'
);

if ($cliente === null) {
    Pruebas::cierto(false, 'hay un cliente con saldo para probar');
    return;
}

$uid     = (int) $cliente['id'];
$cuentaId = (int) $cliente['cuenta_id'];

try {
    BaseDatos::enTransaccion(static function () use ($uid, $cuentaId, $cliente): void {
        $huella = new ReflectionMethod(Sesion::class, 'huellaCliente');
        $huella->setAccessible(true);

        $_SESSION['usuario_id']       = $uid;
        $_SESSION['rol_id']           = (int) $cliente['rol_id'];
        $_SESSION['usuario']          = (string) $cliente['usuario'];
        $_SESSION['creada_en']        = time();
        $_SESSION['ultima_actividad'] = time();
        $_SESSION['huella']           = $huella->invoke(null);

        $benefs = new ServicioBeneficiarios();
        $inter  = new ServicioInterbancario();
        $limites = new ServicioLimitesCliente();

        /* El segundo factor se pone a mano: emitirlo de verdad mandaría
           un correo, y lo que se prueba aquí no es el correo. */
        $ponerOtp = static function (string $proposito) use ($uid): string {
            $codigo = '424242';

            $svc  = new \CashMan\Aplicacion\Servicio\ServicioDesafio();
            $hash = new ReflectionMethod($svc, 'hashCodigo');
            $hash->setAccessible(true);

            BaseDatos::ejecutar(
                'INSERT INTO desafios
                   (usuario_id, proposito, codigo_hash, max_intentos, contexto_hash, expira_en)
                 VALUES (?, ?, ?, 3, NULL, DATE_ADD(NOW(), INTERVAL 10 MINUTE))',
                [$uid, $proposito, $hash->invoke($svc, $codigo, $uid)]
            );

            return $codigo;
        };

        $bac = BaseDatos::unaFila('SELECT * FROM bancos_participantes WHERE codigo = "BAC"');
        $propio = BaseDatos::unaFila('SELECT * FROM bancos_participantes WHERE es_propio = 1');

        // -------------------------------------------------------------
        // 2.1 El alta de un destino y su enfriamiento
        // -------------------------------------------------------------
        $alta = $benefs->registrar([
            'alias'          => 'Prueba BAC',
            'banco_id'       => (int) $bac['id'],
            'numero_cuenta'  => '201900123456',
            'titular_nombre' => 'Ana Lucia Mejia',
            'codigo_otp'     => $ponerOtp('beneficiario'),
        ]);

        Pruebas::cierto(
            $alta['estado'] === 'en_enfriamiento',
            'un destino nuevo en otro banco nace en enfriamiento'
        );

        $uuidBenef = (string) $alta['uuid'];

        // Enviar antes de tiempo tiene que fallar.
        $frenado = false;

        try {
            $inter->enviar([
                'cuenta_origen_id'   => $cuentaId,
                'beneficiario_uuid'  => $uuidBenef,
                'canal'              => 'transfer365',
                'monto'              => '25.00',
                'codigo_otp'         => $ponerOtp('interbancaria'),
                'clave_idempotencia' => 'test-pronto-' . uniqid(),
            ]);
        } catch (\RuntimeException $e) {
            $frenado = str_contains($e->getMessage(), 'espera');
        }

        Pruebas::cierto($frenado, 'y NO se le puede enviar dinero hasta que pase el enfriamiento');

        /* Se adelanta el reloj del enfriamiento, que es lo que haría el
           paso de las horas. */
        BaseDatos::ejecutar(
            'UPDATE beneficiarios SET enfriamiento_hasta = DATE_SUB(NOW(), INTERVAL 1 MINUTE),
                    estado = "activo" WHERE uuid = ?',
            [$uuidBenef]
        );

        // -------------------------------------------------------------
        // 2.2 El envío a otro banco pasa por la cámara
        // -------------------------------------------------------------
        $camaraId = (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE numero_cuenta = "0000000006"'
        );

        $saldoAntes = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]);
        $camaraAntes = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$camaraId]);

        $envio = $inter->enviar([
            'cuenta_origen_id'   => $cuentaId,
            'beneficiario_uuid'  => $uuidBenef,
            'canal'              => 'transfer365',
            'monto'              => '75.50',
            'concepto'           => 'Prueba de envío',
            'codigo_otp'         => $ponerOtp('interbancaria'),
            'clave_idempotencia' => 'test-envio-' . uniqid(),
        ]);

        Pruebas::cierto(
            in_array($envio['estado'], ['enviada', 'creada'], true),
            'el envío a otro banco queda enviado o encolado, nunca aplicado de golpe'
        );

        $saldoDespues = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]);
        $camaraDespues = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$camaraId]);

        Pruebas::cierto(
            $saldoAntes - $saldoDespues === 7550,
            'al cliente se le debitan exactamente los $75.50'
        );

        Pruebas::cierto(
            $camaraDespues - $camaraAntes === 7550,
            'y los mismos $75.50 entran a la cámara: el asiento cuadra'
        );

        // Idempotencia: el mismo envío repetido no mueve dinero otra vez.
        $clave = 'test-idem-' . uniqid();

        $primero = $inter->enviar([
            'cuenta_origen_id'   => $cuentaId,
            'beneficiario_uuid'  => $uuidBenef,
            'canal'              => 'transfer365',
            'monto'              => '10.00',
            'codigo_otp'         => $ponerOtp('interbancaria'),
            'clave_idempotencia' => $clave,
        ]);

        $saldoTrasPrimero = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]);

        $repetido = $inter->enviar([
            'cuenta_origen_id'   => $cuentaId,
            'beneficiario_uuid'  => $uuidBenef,
            'canal'              => 'transfer365',
            'monto'              => '10.00',
            'codigo_otp'         => $ponerOtp('interbancaria'),
            'clave_idempotencia' => $clave,
        ]);

        Pruebas::cierto(
            $repetido['estado'] === 'duplicado'
            && (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]) === $saldoTrasPrimero,
            'reenviar la misma operación no cobra dos veces'
        );

        // -------------------------------------------------------------
        // 2.3 El rechazo devuelve el dinero, sin borrar nada
        // -------------------------------------------------------------
        $folio = (string) $envio['folio'];

        $antesDevolver = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]);

        $inter->devolver($folio, RedDePagos::RECHAZO_CUENTA_INEXISTENTE);

        $t = BaseDatos::unaFila(
            'SELECT * FROM transferencias_interbancarias WHERE folio = ?',
            [$folio]
        );

        Pruebas::cierto(
            $t['estado'] === 'rechazada',
            'un rechazo de la red deja la transferencia como rechazada'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId])
                - $antesDevolver === 7550,
            'y el dinero vuelve entero a la cuenta del cliente'
        );

        Pruebas::cierto(
            $t['asiento_envio_id'] !== null && $t['asiento_reverso_id'] !== null,
            'quedan los DOS asientos: el de salida y el de vuelta'
        );

        $reverso = BaseDatos::unaFila(
            'SELECT reversa_a FROM asientos WHERE id = ?',
            [(int) $t['asiento_reverso_id']]
        );

        Pruebas::cierto(
            (int) $reverso['reversa_a'] === (int) $t['asiento_envio_id'],
            'y el de vuelta apunta al original, que no se tocó'
        );

        // -------------------------------------------------------------
        // 2.4 Al propio banco llega en el acto
        // -------------------------------------------------------------
        $otraCuenta = BaseDatos::unaFila(
            'SELECT c.id, c.numero_cuenta, c.usuario_id, c.saldo_cent
               FROM cuentas c
              WHERE c.es_tecnica = 0 AND c.estado = "activa" AND c.usuario_id <> ?
              ORDER BY c.id LIMIT 1',
            [$uid]
        );

        $altaPropia = $benefs->registrar([
            'alias'          => 'Prueba mismo banco',
            'banco_id'       => (int) $propio['id'],
            'numero_cuenta'  => (string) $otraCuenta['numero_cuenta'],
            'titular_nombre' => 'Cliente De CashMan',
            'codigo_otp'     => $ponerOtp('beneficiario'),
        ]);

        BaseDatos::ejecutar(
            'UPDATE beneficiarios SET enfriamiento_hasta = DATE_SUB(NOW(), INTERVAL 1 MINUTE),
                    estado = "activo" WHERE uuid = ?',
            [(string) $altaPropia['uuid']]
        );

        $destinoAntes = (int) $otraCuenta['saldo_cent'];
        $camaraAntes2 = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$camaraId]);

        $envioPropio = $inter->enviar([
            'cuenta_origen_id'   => $cuentaId,
            'beneficiario_uuid'  => (string) $altaPropia['uuid'],
            'canal'              => 'transfer365',
            'monto'              => '31.25',
            'codigo_otp'         => $ponerOtp('interbancaria'),
            'clave_idempotencia' => 'test-propio-' . uniqid(),
        ]);

        Pruebas::cierto(
            $envioPropio['estado'] === 'aplicada',
            'un envío a este mismo banco nace ya aplicado: no hay viaje'
        );

        /* El dinero entra en el acto. Pero si quien recibe arrastra mora
           vencida, la compensación la aplica de inmediato sobre lo que
           acaba de entrar —y es correcto que lo haga—, así que el saldo
           puede subir menos, o incluso bajar, aunque el abono haya
           llegado entero. Lo que se comprueba, entonces, es que llegaron
           los 31.25: el cambio de saldo MÁS lo que la compensación se
           llevó para la deuda. (Antes se exigía que el saldo subiera
           justo 3125, y eso solo se cumple cuando el destinatario no debe
           nada; con datos reales, cualquiera con mora rompía la prueba.) */
        $destinoAhora = (int) BaseDatos::unValor(
            'SELECT saldo_cent FROM cuentas WHERE id = ?',
            [(int) $otraCuenta['id']]
        );
        $compensado = (int) ($envioPropio['compensacion']['aplicado_cent'] ?? 0);

        Pruebas::cierto(
            ($destinoAhora - $destinoAntes) + $compensado === 3125,
            'el destinatario recibe el dinero en el acto'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$camaraId]) === $camaraAntes2,
            'y la cámara no se toca: el dinero no salió del banco'
        );

        // -------------------------------------------------------------
        // 2.5 Dinero que entra desde otro banco
        // -------------------------------------------------------------
        $antesRecibir = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]);

        $inter->recibir(
            $cuentaId,
            (int) $bac['id'],
            Monto::deCentavos(12000),
            'Roberto Nunez Flores',
            '987654321',
            'Pago de alquiler'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId])
                - $antesRecibir === 12000,
            'una transferencia recibida de otro banco acredita al cliente'
        );

        // -------------------------------------------------------------
        // 2.6 El límite que el cliente se pone a sí mismo
        // -------------------------------------------------------------
        $limites->fijar($uid, 'transfer365', 1000, 2000, 5, $uid);

        $topado = false;

        try {
            $inter->enviar([
                'cuenta_origen_id'   => $cuentaId,
                'beneficiario_uuid'  => $uuidBenef,
                'canal'              => 'transfer365',
                'monto'              => '50.00',
                'codigo_otp'         => $ponerOtp('interbancaria'),
                'clave_idempotencia' => 'test-limite-' . uniqid(),
            ]);
        } catch (\RuntimeException $e) {
            $topado = str_contains($e->getMessage(), 'límite');
        }

        Pruebas::cierto($topado, 'el límite que el cliente se puso a sí mismo lo frena');

        /* Y no puede saltárselo pidiendo más del techo del banco. */
        $alTecho = $limites->fijar($uid, 'transfer365', 99999999, 99999999, 999, $uid);

        Pruebas::cierto(
            (bool) $alTecho['recortado'] === true,
            'y si pide más de lo que permite el banco, se le recorta al techo'
        );

        // -------------------------------------------------------------
        // 2.7 La partida doble, al final de todo
        // -------------------------------------------------------------
        $descuadre = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM (
                 SELECT asiento_id FROM movimientos
                  GROUP BY asiento_id HAVING SUM(importe_cent) <> 0
             ) x'
        );

        Pruebas::cierto(
            $descuadre === 0,
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
    (int) BaseDatos::unValor(
        'SELECT COUNT(*) FROM beneficiarios WHERE alias LIKE "Prueba %"'
    ) === 0,
    'la prueba no deja beneficiarios ni dinero movido'
);
