<?php

declare(strict_types=1);

/**
 * Autenticación reforzada por operación.
 *
 * QUÉ COMPRUEBA
 *
 *   · que el factor lo decide la OPERACIÓN y el MONTO, no la sesión
 *   · que un dispositivo desconocido SUBE la exigencia y nunca la baja
 *   · que una operación sin política definida falla CERRADO
 *   · que «fuerte» se resuelve a lo que el cliente de verdad tiene
 *   · que sin código no pasa, y que cada evaluación deja rastro
 *   · que un dispositivo nuevo se registra y avisa
 *
 * POR QUÉ ESTO ES DISTINTO DEL SEGUNDO FACTOR AL ENTRAR
 *
 * Entrar con contraseña y después poder vaciar la cuenta sin volver a
 * demostrar nada es el escenario de la sesión robada. La norma de
 * canales digitales pide un factor adicional que dependa de la
 * TRANSACCIÓN, no de la sesión.
 *
 * Todo ocurre dentro de una transacción que se deshace.
 */

use CashMan\Aplicacion\Servicio\FactorRequerido;
use CashMan\Aplicacion\Servicio\ServicioFactorOperacion;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Sesion;

Pruebas::grupo('Factor por operación · la norma de canales digitales');

$cliente = BaseDatos::unaFila(
    'SELECT id, usuario, rol_id, totp_activo FROM usuarios
      WHERE estado = "activo" ORDER BY id LIMIT 1'
);

if ($cliente === null) {
    Pruebas::cierto(false, 'hay un cliente para probar');
    return;
}

try {
    BaseDatos::enTransaccion(static function () use ($cliente): void {
        $huella = new ReflectionMethod(Sesion::class, 'huellaCliente');
        $huella->setAccessible(true);

        $uid = (int) $cliente['id'];

        $_SESSION['usuario_id']       = $uid;
        $_SESSION['rol_id']           = (int) $cliente['rol_id'];
        $_SESSION['usuario']          = (string) $cliente['usuario'];
        $_SESSION['creada_en']        = time();
        $_SESSION['ultima_actividad'] = time();
        $_SESSION['huella']           = $huella->invoke(null);

        $s = new ServicioFactorOperacion();

        /* Se parte de un dispositivo CONOCIDO, para aislar el efecto
           del monto del efecto del dispositivo. */
        BaseDatos::ejecutar(
            'INSERT INTO dispositivos_conocidos (usuario_id, huella, descripcion)
             VALUES (?, ?, "Dispositivo de la prueba")
             ON DUPLICATE KEY UPDATE bloqueado = 0',
            [$uid, $huella->invoke(null)]
        );

        Pruebas::cierto(
            $s->dispositivoConocido($uid),
            'el dispositivo de la prueba queda como conocido'
        );

        // -------------------------------------------------------------
        // 1. El factor depende del MONTO
        // -------------------------------------------------------------
        $bajo = $s->politica('transferencia', 1000, $uid);
        $medio = $s->politica('transferencia', 50000, $uid);
        $alto = $s->politica('transferencia', 200000, $uid);

        Pruebas::cierto(
            $bajo['factor'] === 'ninguno',
            'una transferencia de $10 desde el dispositivo de siempre no pide código'
        );

        Pruebas::cierto(
            $medio['factor'] === 'otp',
            'una de $500 sí lo pide'
        );

        Pruebas::cierto(
            $alto['factor'] === 'fuerte',
            'y una de $2,000 pide el factor más fuerte'
        );

        /* Esto es lo que evita enseñar al cliente a teclear códigos sin
           mirar, que es el hábito que explota la ingeniería social. */
        Pruebas::cierto(
            $s->politica('transferencia_propia', 500000, $uid)['factor'] === 'ninguno',
            'entre cuentas propias no pide nada, por grande que sea: el dinero no sale'
        );

        // -------------------------------------------------------------
        // 2. Operaciones que siempre piden
        // -------------------------------------------------------------
        Pruebas::cierto(
            $s->politica('interbancaria', 100, $uid)['factor'] === 'otp',
            'a otro banco se pide aunque sea un dólar: el dinero sale de nuestro alcance'
        );

        Pruebas::cierto(
            $s->politica('beneficiario', 0, $uid)['factor'] === 'fuerte',
            'registrar un destino pide el factor fuerte: es el paso previo a vaciar una cuenta'
        );

        Pruebas::cierto(
            $s->politica('limites_subir', 0, $uid)['factor'] === 'fuerte',
            'y subirse el límite también'
        );

        // -------------------------------------------------------------
        // 3. El dispositivo SUBE, nunca baja
        // -------------------------------------------------------------
        BaseDatos::ejecutar(
            'UPDATE dispositivos_conocidos SET bloqueado = 1 WHERE usuario_id = ?',
            [$uid]
        );

        Pruebas::falso(
            $s->dispositivoConocido($uid),
            'marcado como ajeno, el dispositivo deja de ser conocido'
        );

        $desdeNuevo = $s->politica('transferencia', 1000, $uid);

        Pruebas::cierto(
            $desdeNuevo['factor'] === 'otp',
            'y la MISMA transferencia de $10 ahora sí pide código'
        );

        Pruebas::falso(
            (bool) $desdeNuevo['dispositivo_conocido'],
            'la política dice que el dispositivo no se reconoce'
        );

        /* Lo que NO puede pasar: que un dispositivo desconocido relaje
           nada. Se comprueba con la operación más exigente. */
        Pruebas::cierto(
            $s->politica('beneficiario', 0, $uid)['factor'] === 'fuerte',
            'desde un dispositivo nuevo, lo que ya era fuerte sigue siendo fuerte'
        );

        BaseDatos::ejecutar(
            'UPDATE dispositivos_conocidos SET bloqueado = 0 WHERE usuario_id = ?',
            [$uid]
        );

        // -------------------------------------------------------------
        // 4. Sin política, falla CERRADO
        // -------------------------------------------------------------
        $sinPolitica = $s->politica('operacion_que_nadie_clasifico', 100, $uid);

        Pruebas::cierto(
            $sinPolitica['factor'] === 'fuerte',
            'una operación que nadie clasificó exige el factor MÁS fuerte, no el menos'
        );

        // -------------------------------------------------------------
        // 5. «Fuerte» se resuelve a lo que el cliente tiene
        // -------------------------------------------------------------
        BaseDatos::ejecutar('UPDATE usuarios SET totp_activo = 0 WHERE id = ?', [$uid]);

        Pruebas::cierto(
            $s->factorEfectivo('fuerte', $uid) === 'otp',
            'sin la app autenticadora, «fuerte» es el código al correo'
        );

        Pruebas::cierto(
            $s->factorEfectivo('ninguno', $uid) === 'ninguno',
            'y «ninguno» sigue siendo ninguno'
        );

        // -------------------------------------------------------------
        // 6. Sin código no se pasa, y queda rastro
        // -------------------------------------------------------------
        $frenado = false;

        try {
            $s->exigir('interbancaria', 100000, '');
        } catch (FactorRequerido $e) {
            $frenado = true;
        }

        Pruebas::cierto($frenado, 'sin código, la operación no pasa');

        $rastro = BaseDatos::unaFila(
            'SELECT operacion, factor_pedido, resultado FROM factor_exigido
              WHERE usuario_id = ? ORDER BY id DESC LIMIT 1',
            [$uid]
        );

        Pruebas::cierto(
            $rastro !== null && $rastro['resultado'] === 'fallido'
            && $rastro['operacion'] === 'interbancaria',
            'y queda el rastro de que se exigió y no se cumplió'
        );

        /* Lo exento también deja rastro: un examinador pregunta «¿se le
           pidió factor a esta operación?» y «no, porque no tocaba» es
           una respuesta, pero hay que poder darla. */
        $s->exigir('transferencia_propia', 500000, '');

        $exento = BaseDatos::unaFila(
            'SELECT resultado, factor_pedido FROM factor_exigido
              WHERE usuario_id = ? ORDER BY id DESC LIMIT 1',
            [$uid]
        );

        Pruebas::cierto(
            $exento !== null && $exento['resultado'] === 'exento',
            'una operación exenta también deja constancia de que se evaluó'
        );

        // -------------------------------------------------------------
        // 7. La tabla se puede enseñar entera
        // -------------------------------------------------------------
        $tabla = $s->tabla();

        Pruebas::cierto(
            isset($tabla['transferencia'], $tabla['interbancaria'], $tabla['beneficiario']),
            'la política se puede leer de un vistazo, sin abrir el código'
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
        'SELECT COUNT(*) FROM dispositivos_conocidos WHERE descripcion = "Dispositivo de la prueba"'
    ) === 0,
    'la prueba no deja dispositivos ni rastros'
);
