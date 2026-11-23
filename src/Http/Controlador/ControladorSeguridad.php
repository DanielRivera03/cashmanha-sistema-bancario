<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioTotp;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\LimitadorTasa;
use CashMan\Infraestructura\Seguridad\Sesion;
use CashMan\Infraestructura\Seguridad\Totp;

/**
 * Segundo factor de autenticación.
 */
final class ControladorSeguridad extends ControladorBase
{
    public function ver(array $parametros = []): void
    {
        Autorizacion::exigir('seguridad.totp');
        Sesion::exigirAutenticado();

        $this->render('seguridad/totp', [
            'titulo'     => 'Seguridad · CashMan H.A.',
            'encabezado' => 'Seguridad de la cuenta',
            'estado'     => (new ServicioTotp())->estado(),
            'csrf'       => Csrf::token('seguridad'),
        ]);
    }

    /** Paso 1: genera el secreto y devuelve la URI para el QR. */
    public function iniciarTotp(array $parametros = []): void
    {
        Autorizacion::exigir('seguridad.totp');
        $usuarioId = Sesion::exigirAutenticado();

        (new LimitadorTasa(6, 600, 600))->exigir('totp-alta:' . $usuarioId);

        try {
            $r = (new ServicioTotp())->iniciarAlta();
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        // El secreto no debe quedar en ninguna caché intermedia.
        Respuesta::json(['ok' => true, 'datos' => $r], 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma'        => 'no-cache',
        ]);
    }

    /** Paso 2: confirma con un código y entrega los códigos de respaldo. */
    public function confirmarTotp(array $parametros = []): void
    {
        Autorizacion::exigir('seguridad.totp');
        $usuarioId = Sesion::exigirAutenticado();

        (new LimitadorTasa(8, 600, 900))->exigir('totp-confirma:' . $usuarioId);

        try {
            $r = (new ServicioTotp())->confirmarAlta($this->post('codigo'));
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::json([
            'ok'      => true,
            'mensaje' => 'Segundo factor activado. Guarda los códigos de respaldo.',
            'datos'   => $r,
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function desactivarTotp(array $parametros = []): void
    {
        Autorizacion::exigir('seguridad.totp');
        $usuarioId = Sesion::exigirAutenticado();

        (new LimitadorTasa(5, 600, 900))->exigir('totp-baja:' . $usuarioId);

        try {
            (new ServicioTotp())->desactivar($this->post('codigo'));
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(null, 'Segundo factor desactivado.');
    }

    public function regenerarRespaldo(array $parametros = []): void
    {
        Autorizacion::exigir('seguridad.totp');
        $usuarioId = Sesion::exigirAutenticado();

        (new LimitadorTasa(3, 3600, 3600))->exigir('totp-respaldo:' . $usuarioId);

        try {
            $codigos = (new ServicioTotp())->regenerarRespaldo($this->post('codigo'));
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::json([
            'ok'      => true,
            'mensaje' => 'Códigos de respaldo regenerados. Los anteriores ya no sirven.',
            'datos'   => ['codigos_respaldo' => $codigos],
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    /** Segundos que faltan para que cambie el código, para el contador. */
    public function pulsoTotp(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Respuesta::exito(['restantes' => Totp::segundosRestantes()]);
    }
}
