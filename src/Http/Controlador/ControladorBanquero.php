<?php

declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioAtencionVentanilla;
use CashMan\Aplicacion\Servicio\ServicioCartera;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * El portal del banquero.
 *
 * La banca preferente le promete al cliente patrimonial «una persona
 * asignada que lleva su relación con la institución». Esa persona
 * existía, tenía clientes asignados y no tenía dónde verlos.
 *
 * Aquí ve su cartera ordenada por lo que reclama atención, y entra a
 * la ficha de cada cliente para ver EXACTAMENTE las mismas gestiones
 * que el cliente ve de su lado. Ese es el encargo: que los dos miren
 * lo mismo, no dos listas parecidas.
 *
 * La restricción a «sus» clientes se aplica aquí y no en el rol: el
 * permiso le deja ver expedientes, y es la aplicación la que sabe
 * cuáles son los suyos.
 */
final class ControladorBanquero extends ControladorBase
{
    /** La cartera: a quién le toca atender hoy. */
    public function cartera(array $parametros = []): void
    {
        Autorizacion::exigir('cliente.ver_expediente');
        $banqueroId = Sesion::exigirAutenticado();

        $svc = new ServicioCartera();
        $r   = $svc->de($banqueroId);

        $this->render('banquero/cartera', [
            'titulo'     => 'Mi cartera · CashMan H.A.',
            'encabezado' => 'Mi cartera',
            'clientes'   => $r['clientes'],
            'resumen'    => $r['resumen'],
        ]);
    }

    /**
     * La ficha de un cliente de la cartera.
     *
     * Reutiliza la misma ficha que usa la ventanilla —cuentas,
     * tarjetas, créditos, avisos— porque es la misma información y
     * tener dos versiones acabaría con una diciendo algo distinto.
     * Encima van las gestiones, que es lo propio de esta relación.
     */
    public function cliente(array $parametros = []): void
    {
        Autorizacion::exigir('cliente.ver_expediente');
        $banqueroId = Sesion::exigirAutenticado();

        $clienteId = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'usuario');

        if ($clienteId === 0) {
            throw new RecursoNoEncontrado('Cliente no encontrado.');
        }

        $svc = new ServicioCartera();

        /* Suyo o nada. El permiso le deja ver expedientes; CUÁLES lo
           decide la asignación, y sin esta comprobación bastaría con
           cambiar el código de la URL para abrir el de cualquiera. */
        if (!$svc->esSuyo($banqueroId, $clienteId)) {
            throw new RecursoNoEncontrado('Ese cliente no está en su cartera.');
        }

        $ficha = (new ServicioAtencionVentanilla())->ficha($clienteId);

        if ($ficha === null) {
            throw new RecursoNoEncontrado('Cliente no encontrado.');
        }

        $this->render('banquero/cliente', [
            'titulo'     => $ficha['nombre'] . ' · Mi cartera',
            'encabezado' => $ficha['nombre'],
            'ficha'      => $ficha,
            'gestiones'  => $svc->gestionesDe($clienteId),
        ]);
    }
}
