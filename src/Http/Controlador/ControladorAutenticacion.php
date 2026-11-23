<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\CredencialesInvalidas;
use CashMan\Aplicacion\Servicio\CuentaNoOperable;
use CashMan\Aplicacion\Servicio\PoliticaPassword;
use CashMan\Aplicacion\Servicio\ServicioAutenticacion;
use CashMan\Aplicacion\Servicio\ServicioRecuperacion;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\LimiteExcedido;
use CashMan\Infraestructura\Seguridad\Sesion;

final class ControladorAutenticacion extends ControladorBase
{
    public function formulario(array $parametros = []): void
    {
        if (Sesion::autenticado()) {
            Respuesta::redirigir($this->url('panel'));
        }

        $this->renderAcceso('auth/entrar', [
            'escena' => 'salvador-mundo',
            'titulo' => 'Ingresar · CashMan H.A.',
            'csrf'   => Csrf::token('login'),
            'error'  => $this->get('e'),
        ]);
    }

    public function procesar(array $parametros = []): void
    {
        // El login valida CSRF aquí y no en el middleware: necesita
        // devolver el error en el propio formulario, no una página 403.
        $token = $this->post('_csrf');
        if (!Csrf::validar($token, 'login')) {
            $this->fallo('El token de seguridad venció. Por favor, inténtelo de nuevo.', 403);
        }

        $identificador = $this->post('usuario');
        $password      = (string) ($_POST['password'] ?? '');

        $servicio = new ServicioAutenticacion();

        try {
            $resultado = $servicio->iniciarSesion(
                $identificador,
                $password,
                $this->post('totp')
            );
        } catch (\CashMan\Aplicacion\Servicio\RequiereSegundoFactor $e) {
            // La contraseña era correcta: la interfaz pide el código
            // sin hacer repetir todo el formulario.
            if ($this->esperaJson()) {
                Respuesta::json([
                    'ok'              => false,
                    'requiere_totp'   => true,
                    'mensaje'         => $e->getMessage(),
                ], 200);
            }
            Respuesta::redirigir($this->url('entrar') . '?totp=1');
            return;
        } catch (CredencialesInvalidas | CuentaNoOperable $e) {
            $this->fallo($e->getMessage(), 401);
            return;
        } catch (LimiteExcedido $e) {
            $this->fallo($e->getMessage(), 429);
            return;
        }

        $destino = $resultado['requiere_cambio'] ? 'clave' : 'panel';

        if ($this->esperaJson()) {
            Respuesta::exito(
                ['redirigir' => $this->url($destino)],
                'Sesión iniciada.'
            );
        }

        Respuesta::redirigir($this->url($destino));
    }

    public function salir(array $parametros = []): void
    {
        (new ServicioAutenticacion())->cerrarSesion();

        if ($this->esperaJson()) {
            Respuesta::exito(['redirigir' => $this->url('entrar')], 'Sesión cerrada.');
        }

        Respuesta::redirigir($this->url('entrar'));
    }

    public function formularioClave(array $parametros = []): void
    {
        $this->renderAcceso('auth/cambiar-clave', [
            'escena' => 'volcanes',
            'titulo'  => 'Cambiar contraseña · CashMan H.A.',
            'csrf'    => Csrf::token('clave'),
            'usuario' => $this->usuarioActual(),
            'aviso'   => $this->get('e') !== '' ? $this->get('e') : $this->get('ok'),
            'avisoTipo' => $this->get('e') !== '' ? 'error' : ($this->get('ok') !== '' ? 'exito' : ''),
            /* El motivo lo deja el guardián al redirigir aquí.
             *
             * «Cambie su contraseña» a secas se lee como un trámite
             * arbitrario. No es lo mismo que se la pidan porque venció
             * el plazo que porque se la dio un empleado y la conoce
             * alguien más: lo primero se entiende, lo segundo asusta
             * —y debe asustar—. Se consume al leerlo, para que no
             * reaparezca en la siguiente visita. */
            'motivo'  => (function (): string {
                $m = (string) ($_SESSION['motivo_cambio_clave'] ?? '');
                unset($_SESSION['motivo_cambio_clave']);
                return $m;
            })(),
        ]);
    }

    public function cambiarClave(array $parametros = []): void
    {
        $token = $this->post('_csrf');
        if (!Csrf::validar($token, 'clave')) {
            Respuesta::error('Token de seguridad vencido. Por favor, recargue la página.', 403);
        }

        $usuarioId = Sesion::exigirAutenticado();
        $servicio  = new ServicioAutenticacion();

        try {
            $servicio->cambiarPassword(
                $usuarioId,
                (string) ($_POST['actual'] ?? ''),
                (string) ($_POST['nueva'] ?? ''),
                (string) ($_POST['confirmacion'] ?? '')
            );
        } catch (PoliticaPassword $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $this->url('clave'), null, ['fallos' => $e->fallos()], 422);
            return;
        } catch (CredencialesInvalidas $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $this->url('clave'), null, [], 401);
            return;
        }

        // La sesión se revocó al cambiar la contraseña: hay que reentrar.
        $this->responderSegunCliente(
            true,
            'Contraseña actualizada. Inicia sesión de nuevo.',
            $this->url('entrar'),
            ['redirigir' => $this->url('entrar')]
        );
    }

    public function recuperar(array $parametros = []): void
    {
        $this->renderAcceso('auth/recuperar', [
            'escena' => 'cafetal',
            'titulo' => 'Recuperar acceso · CashMan H.A.',
            'csrf'   => Csrf::token('recuperar'),
            'aviso'  => $this->get('e') !== '' ? $this->get('e') : $this->get('ok'),
            'avisoTipo' => $this->get('e') !== '' ? 'error' : ($this->get('ok') !== '' ? 'exito' : ''),
            // Misma ilustración que usaba el sistema base.
            'ilustracion' => 'icon-1.1s-507px.svg',
        ]);
    }

    public function procesarRecuperacion(array $parametros = []): void
    {
        if (!Csrf::validar($this->post('_csrf'), 'recuperar')) {
            Respuesta::error('Token de seguridad vencido.', 403);
        }

        try {
            (new ServicioRecuperacion())->solicitar($this->post('correo'));
        } catch (LimiteExcedido $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $this->url('recuperar'), null, [], 429);
            return;
        }

        // Respuesta siempre idéntica, exista o no el correo: de lo
        // contrario el formulario se convierte en un verificador de
        // qué direcciones están registradas.
        $this->responderSegunCliente(
            true,
            'Si el correo está registrado, recibirá instrucciones en los próximos minutos.',
            $this->url('recuperar')
        );
    }

    /** Pantalla a la que lleva el enlace del correo. */
    public function restablecer(array $parametros = []): void
    {
        $token = (string) ($parametros['token'] ?? '');
        $sol   = (new ServicioRecuperacion())->solicitudDe($token);

        $this->renderAcceso('auth/restablecer', [
            'escena' => 'cafetal',
            'titulo'  => 'Restablecer contraseña · CashMan H.A.',
            'csrf'    => Csrf::token('restablecer'),
            'aviso'   => $this->get('e') !== '' ? $this->get('e') : $this->get('ok'),
            'avisoTipo' => $this->get('e') !== '' ? 'error' : ($this->get('ok') !== '' ? 'exito' : ''),
            'token'   => $token,
            'valido'  => $sol !== null,
            // Solo el nombre de pila: el enlace puede acabar en manos
            // ajenas y no hace falta exponer el correo completo.
            'nombre'  => $sol !== null ? explode(' ', $sol['nombre'])[0] : '',
        ]);
    }

    public function procesarRestablecimiento(array $parametros = []): void
    {
        if (!Csrf::validar($this->post('_csrf'), 'restablecer')) {
            Respuesta::error('Token de seguridad vencido. Por favor, recargue la página.', 403);
        }

        try {
            (new ServicioRecuperacion())->confirmar(
                (string) ($parametros['token'] ?? ''),
                $this->post('codigo'),
                (string) ($_POST['nueva'] ?? '')
            );
        } catch (PoliticaPassword $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $this->url('restablecer/' . (string) ($parametros['token'] ?? '')), null, ['fallos' => $e->fallos()], 422);
            return;
        } catch (CredencialesInvalidas $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $this->url('restablecer/' . (string) ($parametros['token'] ?? '')), null, [], 401);
            return;
        } catch (LimiteExcedido $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $this->url('restablecer/' . (string) ($parametros['token'] ?? '')), null, [], 429);
            return;
        }

        $this->responderSegunCliente(
            true,
            'Contraseña actualizada. Ya puede ingresar con ella.',
            $this->url('entrar'),
            ['redirigir' => $this->url('entrar')]
        );
    }

    private function fallo(string $mensaje, int $codigo): void
    {
        if ($this->esperaJson()) {
            Respuesta::error($mensaje, $codigo);
        }
        Respuesta::redirigir($this->url('entrar') . '?e=' . rawurlencode($mensaje));
    }
}
