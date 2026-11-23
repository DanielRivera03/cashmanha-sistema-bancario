<?php

declare(strict_types=1);

/**
 * El segundo factor, de punta a punta.
 *
 * QUÉ COMPRUEBA
 *
 * Que activar la verificación en dos pasos sirve de algo: que el alta
 * entrega un secreto que las aplicaciones entienden, que un código
 * equivocado no activa nada, y que una vez activa la cuenta exige el
 * código para dejar entrar.
 *
 * POR QUÉ HACE FALTA
 *
 * Porque el código QR estuvo roto desde el principio y nadie lo notó:
 * salía un cuadrado impecable que ninguna aplicación reconocía. Como
 * nadie conseguía activar el segundo factor, el camino de después —el
 * que de verdad protege la cuenta— no se recorrió nunca. Un mecanismo
 * de seguridad que no se puede activar es un mecanismo que no existe.
 *
 * QUÉ NO CUBRE, Y POR QUÉ
 *
 * El paso por `ServicioAutenticacion::iniciarSesion()` no se prueba
 * aquí: regenera el identificador de sesión, y eso en consola —con la
 * salida ya empezada— el propio PHP no lo permite. Se comprueba la
 * pieza que ese método consulta para decidir, que es `verificarLogin`.
 *
 * Todo ocurre dentro de una transacción que se deshace: no deja ni un
 * usuario con el segundo factor activado.
 */

use CashMan\Aplicacion\Servicio\ServicioTotp;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Sesion;
use CashMan\Infraestructura\Seguridad\Totp;

Pruebas::grupo('Doble factor · activar y exigir');

/* Un cliente de la siembra que todavía no lo tenga. */
$usuario = BaseDatos::unaFila(
    'SELECT id, usuario FROM usuarios
      WHERE usuario LIKE "sem2026.%" AND estado = "activo" AND totp_activo = 0
      ORDER BY id LIMIT 1'
);

if ($usuario === null) {
    Pruebas::cierto(false, 'hay un cliente de prueba disponible');
    return;
}

$uid = (int) $usuario['id'];

try {
    BaseDatos::enTransaccion(static function () use ($uid, $usuario): void {
        /* El actor, puesto a mano.
         *
         * `Sesion::autenticar()` regenera el identificador de sesión y
         * eso no se puede hacer con la salida ya empezada. Aquí no
         * hace falta un inicio de sesión de verdad: basta con que los
         * servicios encuentren a quien actúa. La huella va incluida
         * porque la sesión la comprueba en cada lectura. */
        $huella = new ReflectionMethod(Sesion::class, 'huellaCliente');
        $huella->setAccessible(true);

        $_SESSION['usuario_id']       = $uid;
        $_SESSION['rol_id']           = 5;
        $_SESSION['usuario']          = (string) $usuario['usuario'];
        $_SESSION['creada_en']        = time();
        $_SESSION['ultima_actividad'] = time();
        $_SESSION['huella']           = $huella->invoke(null);

        $servicio = new ServicioTotp();

        // --- 1. El alta entrega lo que la aplicación necesita -------
        $alta    = $servicio->iniciarAlta();
        $secreto = (string) ($alta['secreto'] ?? '');
        $uri     = (string) ($alta['uri'] ?? '');

        Pruebas::cierto(
            preg_match('/^[A-Z2-7]+$/', $secreto) === 1,
            'el secreto está en Base32, que es lo único que leen las apps'
        );

        Pruebas::cierto(
            strlen($secreto) === 32,
            'y mide 32 caracteres: los 160 bits que recomienda la norma'
        );

        Pruebas::cierto(
            str_starts_with($uri, 'otpauth://totp/') && str_contains($uri, 'secret=' . $secreto),
            'la URI del QR lleva el esquema y el secreto correctos'
        );

        /* Volver a pulsar «Activar» NO cambia el secreto.
         *
         * Antes cada pulsación generaba uno nuevo, y quien lo
         * intentaba varias veces acababa con cinco entradas de
         * este banco en su aplicación autenticadora: cuatro
         * muertas y ninguna manera de distinguirlas. */
        Pruebas::cierto(
            (string) ($servicio->iniciarAlta()['secreto'] ?? '') === $secreto,
            'pulsar Activar otra vez da el mismo secreto: una sola entrada'
        );

        // --- 2. Un código equivocado no activa nada -----------------
        $rechazado = false;

        try {
            $servicio->confirmarAlta('000000');
        } catch (\Throwable) {
            $rechazado = true;
        }

        Pruebas::cierto($rechazado, 'un código equivocado no activa el segundo factor');

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT totp_activo FROM usuarios WHERE id = ?', [$uid]) === 0,
            'y la cuenta sigue sin él'
        );

        // --- 3. El código de la aplicación sí lo activa -------------
        $r = $servicio->confirmarAlta(Totp::codigo($secreto));

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT totp_activo FROM usuarios WHERE id = ?', [$uid]) === 1,
            'el código correcto lo activa'
        );

        Pruebas::cierto(
            is_array($r['codigos_respaldo'] ?? null) && count((array) $r['codigos_respaldo']) > 0,
            'y entrega códigos de respaldo por si se pierde el teléfono'
        );

        // --- 4. La puerta del login ---------------------------------
        //
        // Es justo lo que `iniciarSesion()` consulta antes de dejar
        // pasar a nadie.
        Pruebas::falso(
            $servicio->verificarLogin($uid, '000000'),
            'en el login, un código equivocado no abre'
        );

        /* El código que acaba de usarse para activar NO vale otra
           vez. Es deliberado: si valiera, a quien mire la pantalla
           por encima del hombro le bastarían esos seis dígitos
           durante el resto del medio minuto. */
        Pruebas::falso(
            $servicio->verificarLogin($uid, Totp::codigo($secreto)),
            'un código ya usado no se puede reutilizar'
        );

        Pruebas::cierto(
            $servicio->verificarLogin($uid, Totp::codigo($secreto, time() + 30)),
            'y el código siguiente de la aplicación sí abre'
        );

        /* --- 5. Un telefono con el reloj desfasado -------------
         *
         * El caso real que lo motivo: tres minutos de atraso. La
         * aplicacion mostraba el codigo correcto de tres minutos
         * antes y el sistema lo rechazaba, porque la ventana es de
         * treinta segundos y tiene que serlo.
         *
         * Ahora el desfase se mide al dar de alta y el login compara
         * contra la hora del telefono. */
        BaseDatos::ejecutar(
            'UPDATE usuarios SET totp_activo = 0, totp_confirmado_en = NULL,
                    totp_ultimo_contador = NULL, totp_desfase_seg = 0 WHERE id = ?',
            [$uid]
        );

        $atrasado = time() - 180;

        $r2 = $servicio->confirmarAlta(Totp::codigo($secreto, $atrasado));

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT totp_activo FROM usuarios WHERE id = ?', [$uid]) === 1,
            'un telefono tres minutos atrasado tambien puede activarlo'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT totp_desfase_seg FROM usuarios WHERE id = ?', [$uid]) === -180,
            'y el sistema anota que su reloj va 180 segundos atrasado'
        );

        Pruebas::cierto(
            $servicio->verificarLogin($uid, Totp::codigo($secreto, time() - 180 + 30)),
            'y a partir de ahi entra con los codigos de SU reloj'
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
        'SELECT COUNT(*) FROM usuarios WHERE id = ? AND totp_activo = 1',
        [$uid]
    ) === 0,
    'la prueba no deja a nadie con el segundo factor activado'
);
