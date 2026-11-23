<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioEnlacePago;
use CashMan\Aplicacion\Servicio\ServicioPasarela;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Pasarela\PasarelaError;
use CashMan\Infraestructura\Pasarela\Stripe;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;

/**
 * La página que abre el cliente desde su correo.
 *
 * No exige iniciar sesión, y es deliberado: el cliente que lleva cuatro
 * años sin pagar tampoco se acuerda de su contraseña, y obligarle a
 * recuperarla antes de poder pagar es poner un obstáculo justo en el
 * momento en que por fin quiere pagar.
 *
 * Lo que protege la página es el token: 32 bytes aleatorios, guardado
 * en la base solo como hash, de un solo uso y con fecha de caducidad.
 * Y lo que se puede hacer con él es una sola cosa —pagar una cantidad
 * ya fijada—, no consultar el expediente ni ver otros datos.
 *
 * Por eso tampoco se muestra aquí nada más que el concepto y el importe:
 * quien tenga el enlace no tiene por qué ver el saldo del cliente, su
 * dirección ni el resto de su cartera.
 */
final class ControladorEnlacePago extends ControladorBase
{
    public function abrir(array $parametros = []): void
    {
        $enlace = (new ServicioEnlacePago())->abrir(
            (string) ($parametros['token'] ?? '')
        );

        /* Un enlace caducado, uno ya pagado y uno inventado dan todos la
         * misma página. Distinguirlos confirmaría a quien prueba tokens
         * cuáles son válidos. */
        if ($enlace === null) {
            $this->renderPublico('pagos/enlace_invalido', [
                'titulo'     => 'Enlace no disponible · CashMan H.A.',
                'encabezado' => 'Enlace no disponible',
            ]);
            return;
        }

        $this->renderPublico('pagos/enlace', [
            'titulo'     => 'Pagar · CashMan H.A.',
            'encabezado' => 'Pagar',
            'enlace'     => $enlace,
            'token'      => (string) $parametros['token'],
            'activa'     => Stripe::habilitado(),
            'esPrueba'   => Stripe::esPrueba(),
            'publica'    => Stripe::clavePublica(),
            'csrf'       => Csrf::token('enlace_pago'),
        ]);
    }

    /**
     * Prepara el cobro en la pasarela.
     *
     * El importe NO viene del navegador: se lee del enlace. Si lo
     * mandara el formulario, bastaría con editarlo para saldar una deuda
     * de mil dólares pagando uno.
     */
    public function iniciar(array $parametros = []): void
    {
        $enlace = (new ServicioEnlacePago())->abrir(
            (string) ($parametros['token'] ?? '')
        );

        if ($enlace === null) {
            Respuesta::error('Este enlace ya no está disponible.', 410);
            return;
        }

        if (!Stripe::habilitado()) {
            Respuesta::error('El cobro con tarjeta no está disponible en este momento.', 503);
            return;
        }

        try {
            $intento = (new Stripe())->crearIntento(
                (int) $enlace['importe_cent'],
                (string) $enlace['concepto'],
                // Un intento por enlace: si el cliente recarga la página
                // o pulsa dos veces, Stripe devuelve el mismo en lugar
                // de cobrarle dos veces.
                'enlace:' . $enlace['uuid'],
                [
                    'enlace_id'  => (string) $enlace['id'],
                    'credito_id' => (string) ($enlace['credito_id'] ?? ''),
                    'arreglo_id' => (string) ($enlace['arreglo_id'] ?? ''),
                ]
            );
        } catch (PasarelaError $e) {
            Respuesta::error($e->getMessage(), 502);
            return;
        }

        /* El cobro se registra ANTES de que el cliente teclee la tarjeta.
         *
         * Antes solo se creaba el intento en Stripe. Cuando el pago
         * salía bien, el webhook buscaba el cobro en `pagos_pasarela`,
         * no encontraba nada y lo descartaba como «cobro sin intento
         * registrado en este sistema»: el dinero entraba y el arreglo
         * se quedaba sin aplicar, el crédito sin cancelar y el finiquito
         * sin emitir.
         *
         * `INSERT ... ON DUPLICATE KEY` porque la referencia es la del
         * intento y Stripe devuelve el mismo si el cliente recarga la
         * página: un segundo registro sería un cobro duplicado.
         *
         * El estado es `iniciado`, que es lo que admite el ENUM de la
         * columna junto con procesando, cobrado, fallido, cancelado y
         * devuelto. Con un valor fuera de esa lista MySQL rechaza la
         * fila y la excepción acaba en el navegador como página de
         * error HTML donde se esperaba JSON.
         *
         * Todo dentro de try/catch: un endpoint que promete JSON tiene
         * que responder JSON también cuando algo falla. Si no, el
         * cliente recibe «Unexpected token '<' … is not valid JSON» y
         * no hay forma de saber qué pasó desde la pantalla. */
        try {
            BaseDatos::ejecutar(
                'INSERT INTO pagos_pasarela
                   (uuid, usuario_id, concepto, importe_cent, moneda, pasarela,
                    estado, referencia, credito_id, enlace_id, ip)
                 VALUES (UUID(), ?, "enlace_cobro", ?, "USD", "stripe",
                         "iniciado", ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE importe_cent = VALUES(importe_cent)',
                [
                    (int) $enlace['usuario_id'],
                    (int) $enlace['importe_cent'],
                    (string) $intento['id'],
                    $enlace['credito_id'] !== null ? (int) $enlace['credito_id'] : null,
                    (int) $enlace['id'],
                    ClienteIp::binaria(),
                ]
            );
        } catch (\Throwable $e) {
            /* Sin este registro, el cobro llegaría a Stripe y nadie
             * podría aplicarlo después: mejor parar aquí, antes de que
             * el cliente ponga su tarjeta. */
            error_log('[EnlacePago] no se pudo registrar el cobro: ' . $e->getMessage());

            Respuesta::error(
                'No se pudo preparar el cobro. Vuelva a intentarlo en unos minutos.',
                500
            );
            return;
        }

        BaseDatos::ejecutar(
            'UPDATE enlaces_pago SET abierto_en = COALESCE(abierto_en, NOW()) WHERE id = ?',
            [(int) $enlace['id']]
        );

        Respuesta::exito([
            'clave'     => $intento['client_secret'] ?? '',
            'intento'   => $intento['id'],
            'importe'   => (int) $enlace['importe_cent'],
        ], 'Listo para cobrar.');
    }

    /**
     * Cierra el cobro cuando el navegador dice que salió bien.
     *
     * No se fía de lo que diga el navegador: vuelve a preguntarle a
     * Stripe por el intento y solo aplica si la propia pasarela dice que
     * está cobrado. Sin esa verificación, cualquiera podría llamar a
     * esta ruta y dar por pagado un crédito sin haber pagado nada.
     *
     * Existe porque el webhook no siempre llega —en una instalación
     * local Stripe no puede alcanzar el servidor— y porque, cuando
     * llega, puede tardar. Las dos vías terminan en el mismo sitio y la
     * clave de idempotencia impide que el cobro se aplique dos veces.
     */
    public function confirmar(array $parametros = []): void
    {
        $enlace = (new ServicioEnlacePago())->abrir(
            (string) ($parametros['token'] ?? '')
        );

        if ($enlace === null) {
            Respuesta::error('Este enlace ya no está disponible.', 410);
            return;
        }

        $intentoId = $this->post('intento');

        if (!preg_match('/^pi_[A-Za-z0-9_]+$/', $intentoId)) {
            Respuesta::error('Referencia de cobro no válida.', 422);
            return;
        }

        // El cobro tiene que ser de ESTE enlace, no de otro cualquiera.
        $pago = BaseDatos::unaFila(
            'SELECT id, estado FROM pagos_pasarela
              WHERE referencia = ? AND enlace_id = ?',
            [$intentoId, (int) $enlace['id']]
        );

        if ($pago === null) {
            Respuesta::error('Ese cobro no corresponde a este enlace.', 422);
            return;
        }

        try {
            $estado = (new Stripe())->consultarIntento($intentoId);
        } catch (PasarelaError $e) {
            Respuesta::error('No se pudo verificar el cobro con la pasarela.', 502);
            return;
        }

        if (($estado['status'] ?? '') !== 'succeeded') {
            Respuesta::error(
                'La pasarela todavía no confirma el cobro. Le avisaremos por correo '
                . 'en cuanto lo haga.',
                409
            );
            return;
        }

        /* Aquí el cobro YA se hizo: el dinero entró en la pasarela.
         *
         * Si la aplicación falla —una cuota bloqueada, un arreglo que
         * caducó entre medias—, no se le puede decir al cliente que
         * reintente el pago: pagaría dos veces. Se le confirma que su
         * pago se recibió, se deja constancia y el webhook o la revisión
         * interna lo terminan de aplicar. */
        try {
            $r = (new ServicioPasarela())->aplicarCobroConfirmado($intentoId);
        } catch (\Throwable $e) {
            error_log('[EnlacePago] cobro ' . $intentoId
                . ' recibido pero sin aplicar: ' . $e->getMessage());

            Respuesta::exito(
                ['aplicado' => false],
                'Recibimos su pago. La actualización de su crédito puede tardar unos '
                . 'minutos; le avisaremos por correo en cuanto se refleje.'
            );
            return;
        }

        Respuesta::exito(['detalle' => $r, 'aplicado' => true], 'Pago aplicado.');
    }

    /**
     * Dibuja con el marco público, no con el del panel.
     *
     * @param array<string,mixed> $datos
     */
    private function renderPublico(string $vista, array $datos): void
    {
        $datos['rutas']  ??= $this->enrutador;
        $datos['seccion'] ??= '';

        $datos['contenido'] = '<section class="sitio-seccion">'
            . '<div class="sitio-ancho sitio-ancho--estrecho">'
            . $this->vista->render($vista, $datos)
            . '</div></section>';

        Respuesta::html($this->vista->render('layout/publico', $datos));
    }
}
