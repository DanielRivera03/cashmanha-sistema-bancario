<?php

declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\PoliticaPassword;
use CashMan\Aplicacion\Servicio\ServicioAperturaEnLinea;
use CashMan\Aplicacion\Servicio\ServicioRegistro;
use CashMan\Dominio\Comun\Monto;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\LimitadorTasa;
use CashMan\Infraestructura\Seguridad\LimiteExcedido;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Registro público y apertura de la cuenta en línea.
 *
 * Es el único camino del sistema que empieza con alguien que no es
 * nadie todavía, así que es el único donde no hay sesión de la que
 * fiarse. Lo que lo sostiene es el límite por dirección: sin él, un
 * guion crea mil usuarios en un minuto y llena la base de clientes
 * que no existen.
 */
final class ControladorRegistro extends ControladorBase
{
    public function formulario(array $parametros = []): void
    {
        if (Sesion::autenticado()) {
            Respuesta::redirigir($this->url('panel'));
            return;
        }

        $producto = ServicioAperturaEnLinea::productoDigital();

        $this->renderAcceso('auth/registro', [
            'escena' => 'costa',
            /* Nueve campos no caben en la columna estrecha del resto de
               pantallas de acceso sin volverse una tira que hay que
               recorrer entera. */
            'ancho'  => true,
            'titulo'   => 'Crear mi cuenta · CashMan H.A.',
            'csrf'     => Csrf::token('registro'),
            'abierto'  => ServicioRegistro::abierto(),
            'edad'     => Parametros::entero('registro.edad_minima', 18),
            'producto' => $producto,
            'ventajas' => $producto === null ? [] : array_values(array_filter(
                array_map('trim', explode('|', (string) ($producto['beneficios'] ?? '')))
            )),
        ]);
    }

    public function registrar(array $parametros = []): void
    {
        /* El freno. Diez altas por hora desde una misma dirección:
           suficiente para quien se equivoca al teclear, insuficiente
           para quien automatiza. Sin esto, un guion llena la base de
           clientes que no existen en un minuto. */
        try {
            (new LimitadorTasa(10, 3600, 3600))
                ->exigir('registro:' . ClienteIp::obtener());
        } catch (LimiteExcedido $e) {
            $this->responderSegunCliente(
                false, $e->getMessage(), $this->url('registro'), null, [], 429
            );
            return;
        }

        try {
            $r = (new ServicioRegistro())->registrar([
                'nombres'      => $this->post('nombres'),
                'apellidos'    => $this->post('apellidos'),
                'correo'       => $this->post('correo'),
                'documento'    => $this->post('documento'),
                'tipo_documento' => $this->post('tipo_documento'),
                'telefono'     => $this->post('telefono'),
                'nacimiento'   => $this->post('nacimiento'),
                'password'     => (string) ($_POST['password'] ?? ''),
                'confirmacion' => (string) ($_POST['confirmacion'] ?? ''),
            ]);
        } catch (PoliticaPassword $e) {
            $this->responderSegunCliente(
                false,
                'Revise los datos, por favor.',
                $this->url('registro'),
                null,
                ['fallos' => $e->fallos()],
                422
            );
            return;
        } catch (\RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $this->url('registro'), null, [], 422);
            return;
        }

        /* La misma respuesta exista o no el correo.
         *
         * `creado` viene en falso cuando ya había alguien con ese
         * correo o documento, y aquí NO se distingue: un formulario
         * que dice «ese correo ya está registrado» es una forma cómoda
         * de averiguar quién es cliente de este banco. Al titular de
         * verdad se le avisa por correo, que es donde solo lo lee él. */
        $this->responderSegunCliente(
            true,
            $r['creado']
                ? 'Listo, ' . $r['usuario'] . '. Ya puede entrar con su correo y su contraseña.'
                : 'Si los datos son correctos, le enviamos un correo con los siguientes pasos.',
            $this->url('entrar'),
            ['redirigir' => $this->url('entrar')]
        );
    }

    // -----------------------------------------------------------------

    public function formularioCuenta(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $producto  = ServicioAperturaEnLinea::productoDigital();

        $titular = BaseDatos::unaFila(
            'SELECT nombres, apellidos, documento_identidad FROM usuarios WHERE id = ?',
            [$usuarioId]
        );

        $this->render('cuentas/abrir-en-linea', [
            'titulo'     => 'Abrir mi Cuenta Digital · CashMan H.A.',
            'encabezado' => 'Abrir mi Cuenta Digital',
            'csrf'       => Csrf::token('apertura'),
            'producto'   => $producto,
            'yaLaTiene'  => ServicioAperturaEnLinea::yaLaTiene($usuarioId),
            'faltaDocumento' => trim((string) ($titular['documento_identidad'] ?? '')) === '',
            'marcas'     => ServicioAperturaEnLinea::marcas(),
            'ventajas'   => $producto === null ? [] : array_values(array_filter(
                array_map('trim', explode('|', (string) ($producto['beneficios'] ?? '')))
            )),
            'topeSaldo'  => Monto::deCentavos(
                Parametros::entero('cuenta_digital.saldo_maximo_cent', 300000)
            ),
            'topeDiario' => Monto::deCentavos(
                Parametros::entero('cuenta_digital.movimiento_diario_cent', 100000)
            ),
        ]);
    }

    public function abrirCuenta(array $parametros = []): void
    {
        $this->exigirPerfilParaProductos();

        try {
            $r = (new ServicioAperturaEnLinea())->abrir($this->post('marca', ''));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(
                false, $e->getMessage(), $this->url('abrir-cuenta'), null, [], 422
            );
            return;
        }

        $this->responderSegunCliente(
            true,
            'Su cuenta ' . $r['numero'] . ' está abierta, con su tarjeta de débito virtual '
            . '••••' . $r['ultimos4'] . '. Le enviamos el contrato por correo.',
            $this->url('cuentas'),
            $r + ['redirigir' => $this->url('cuentas')]
        );
    }
}
