<?php

declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioPerfilDeclarado;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Sesion;
use CashMan\Http\Respuesta;

/**
 * La declaración que el cliente hace una sola vez, al entrar.
 *
 * Es una pantalla de paso obligado: mientras no esté hecha, el
 * guardián de `index.php` trae aquí cualquier otra ruta. Por eso no
 * lleva menú ni enlaces a ningún lado — salir de aquí es llenarla, o
 * cerrar sesión.
 */
final class ControladorPerfilDeclarado extends ControladorBase
{
    public function formulario(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        /* Si ya declaró, aquí no hay nada que hacer. Puede haber
           llegado por el enlace de un correo viejo o por el botón
           «atrás» del navegador. */
        if (ServicioPerfilDeclarado::yaDeclaro($usuarioId)) {
            Respuesta::redirigir($this->enrutador->url('panel'));
        }

        $this->render('perfil/declarar', [
            'titulo'  => 'Complete su perfil · CashMan H.A.',
            'fuentes' => ServicioPerfilDeclarado::FUENTES,
            'csrf'    => Csrf::token('perfil_declarar'),
        ]);
    }

    public function guardar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $destino   = $this->enrutador->url('mi-perfil/declarar');

        try {
            $r = (new ServicioPerfilDeclarado())->declarar($usuarioId, [
                'fuente_ingreso'   => $this->post('fuente_ingreso'),
                'ocupacion'        => $this->post('ocupacion'),
                'empresa'          => $this->post('empresa'),
                'cargo'            => $this->post('cargo'),
                'antiguedad_meses' => $this->postEntero('antiguedad_meses'),
                'ingreso'          => $this->post('ingreso'),
                'direccion'        => $this->post('direccion'),
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $this->responderSegunCliente(
            true,
            'Listo. Ya puede usar su banca y solicitar productos.',
            $this->enrutador->url('panel'),
            $r + ['redirigir' => $this->enrutador->url('panel')]
        );
    }
}
