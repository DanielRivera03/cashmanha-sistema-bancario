<?php

declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\RemesaInvalida;
use CashMan\Aplicacion\Servicio\ServicioRemesas;
use CashMan\Dominio\Comun\MontoInvalido;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\AccesoDenegado;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Remesas familiares.
 *
 * Dos pantallas distintas porque son dos oficios distintos:
 *
 *   · El CLIENTE ve las suyas y las acredita a su cuenta.
 *   · La VENTANILLA busca por código o documento y paga en efectivo.
 *
 * Nunca se muestra el código de control completo: quien lo tiene,
 * cobra.
 */
final class ControladorRemesas extends ControladorBase
{
    // =================================================================
    // El cliente
    // =================================================================

    public function mias(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        if (!Autorizacion::puedeAlguno(['remesa.ver_propia', 'remesa.ver'])) {
            throw new AccesoDenegado('No tiene permiso para ver remesas.');
        }

        $servicio = new ServicioRemesas();

        $this->render('remesas/mias', [
            'titulo'     => 'Mis remesas · CashMan H.A.',
            'encabezado' => 'Mis remesas',
            'remesas'    => $this->paraPantalla($servicio->deUsuario($usuarioId)),
            'cuentas'    => $this->cuentasDe($usuarioId),
            'csrf'       => Csrf::token('remesas'),
        ]);
    }

    public function acreditar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $cuentaId = $this->idDesde($this->post('cuenta'), 'cuenta');

        if ($cuentaId === 0) {
            Respuesta::error('Elija la cuenta a la que acreditar.', 422);
            return;
        }

        $folio = (string) ($parametros['folio'] ?? '');

        /* La remesa tiene que ser SUYA, o tener permiso para operar las
           de otros. Sin esta comprobación, cambiar el folio en la
           petición acreditaría la remesa de un tercero. */
        $duena = BaseDatos::unValor(
            'SELECT usuario_id FROM remesas WHERE folio = ?',
            [$folio]
        );

        if ($duena === null) {
            Respuesta::error('No existe esa remesa.', 404);
            return;
        }

        if ((int) $duena !== $usuarioId && !Autorizacion::puede('remesa.pagar')) {
            throw new AccesoDenegado('Esa remesa no es suya.');
        }

        /* Y la cuenta destino también: acreditar a una cuenta ajena
           sería regalar la remesa. */
        Autorizacion::exigirCuenta($cuentaId, $usuarioId, 'remesa.pagar');

        try {
            $r = (new ServicioRemesas())->acreditar($folio, $cuentaId, $usuarioId);
        } catch (RemesaInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        $servicio = new ServicioRemesas();

        $r['remesas'] = $this->paraPantalla($servicio->deUsuario($usuarioId));
        $r['cuentas'] = $this->cuentasDe($usuarioId);

        Respuesta::exito($r, 'Remesa acreditada a su cuenta.');
    }

    // =================================================================
    // La ventanilla
    // =================================================================

    public function ventanilla(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('remesa.ver');

        $this->render('remesas/ventanilla', [
            'titulo'      => 'Remesas · CashMan H.A.',
            'encabezado'  => 'Pago de remesas',
            'remesadoras' => BaseDatos::todas(
                'SELECT codigo, nombre_corto, etiqueta_codigo,
                        codigo_largo_min, codigo_largo_max
                   FROM remesadoras WHERE activa = 1 ORDER BY nombre_corto'
            ),
            'puedePagar'     => Autorizacion::puede('remesa.pagar'),
            'puedeRegistrar' => Autorizacion::puede('remesa.registrar'),
            'sesionAbierta'  => BaseDatos::unaFila(
                'SELECT id FROM caja_sesiones
                  WHERE usuario_id = ? AND cerrada_en IS NULL
                  ORDER BY id DESC LIMIT 1',
                [Sesion::usuarioId()]
            ) !== null,
            'csrf'        => Csrf::token('remesas'),
        ]);
    }

    public function buscar(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('remesa.ver');

        $servicio = new ServicioRemesas();

        $codigo     = (string) $this->post('codigo');
        $remesadora = (string) $this->post('remesadora');
        $documento  = (string) $this->post('documento');

        try {
            if ($codigo !== '' && $remesadora !== '') {
                $r = $servicio->porCodigo($remesadora, $codigo);

                $filas = $r === null ? [] : [$r];
            } elseif ($documento !== '') {
                $filas = $servicio->porDocumento($documento);
            } else {
                Respuesta::error('Indique el código de control o el documento.', 422);
                return;
            }
        } catch (RemesaInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(
            ['remesas' => $this->paraPantalla($filas)],
            $filas === [] ? 'No se encontró ninguna remesa.' : count($filas) . ' encontrada(s).',
            ['silencio' => true]
        );
    }

    public function pagarEfectivo(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('remesa.pagar');

        $sesion = BaseDatos::unaFila(
            'SELECT id FROM caja_sesiones
              WHERE usuario_id = ? AND cerrada_en IS NULL
              ORDER BY id DESC LIMIT 1',
            [$actorId]
        );

        if ($sesion === null) {
            Respuesta::error(
                'No tiene una caja abierta. Abra su caja antes de pagar remesas en efectivo.',
                422
            );
            return;
        }

        try {
            $r = (new ServicioRemesas())->pagarEnEfectivo(
                (string) ($parametros['folio'] ?? ''),
                (int) $sesion['id'],
                $actorId
            );
        } catch (RemesaInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito($r, 'Remesa pagada en efectivo. Entregue el comprobante.');
    }

    public function registrar(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('remesa.registrar');

        try {
            $r = (new ServicioRemesas())->registrarLlegada([
                'remesadora'             => (string) $this->post('remesadora'),
                'codigo'                 => (string) $this->post('codigo'),
                'beneficiario_nombre'    => (string) $this->post('beneficiario_nombre'),
                'beneficiario_documento' => (string) $this->post('beneficiario_documento'),
                'monto'                  => $this->post('monto'),
                'remitente_nombre'       => (string) $this->post('remitente_nombre'),
                'remitente_pais'         => (string) $this->post('remitente_pais', 'US'),
            ]);
        } catch (MontoInvalido | RemesaInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(
            $r,
            'Remesa registrada con folio ' . $r['folio'] . '.'
            . ($r['es_cliente'] ? ' Se avisó al beneficiario.' : '')
        );
    }

    // =================================================================
    // Interno
    // =================================================================

    /**
     * Prepara las remesas para la pantalla.
     *
     * Se quita todo lo que no debe salir: el código cifrado, el hash y
     * los identificadores internos. Queda la cola de cuatro dígitos,
     * que basta para reconocerla.
     *
     * @param array<int,array<string,mixed>> $filas
     * @return array<int,array<string,mixed>>
     */
    private function paraPantalla(array $filas): array
    {
        $limpias = [];

        foreach ($filas as $f) {
            $limpias[] = [
                'folio'        => $f['folio'],
                'remesadora'   => $f['remesadora'],
                'etiqueta'     => $f['etiqueta_codigo'] ?? 'Código',
                'codigo'       => '••••' . $f['codigo_cola'],
                'beneficiario' => $f['beneficiario_nombre'],
                'documento'    => $f['beneficiario_documento'],
                'remitente'    => $f['remitente_nombre'],
                'pais'         => $f['remitente_pais'],
                'monto_cent'   => (int) $f['monto_cent'],
                'estado'       => $f['estado'],
                'forma_pago'   => $f['forma_pago'],
                'motivo'       => $f['motivo'],
                'disponible_en' => $f['disponible_en'],
                'expira_en'    => $f['expira_en'],
                'pagada_en'    => $f['pagada_en'],
            ];
        }

        return $limpias;
    }

    /** @return array<int,array<string,mixed>> */
    private function cuentasDe(int $usuarioId): array
    {
        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, p.nombre AS producto
               FROM cuentas c
               JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.estado = "activa" AND c.es_tecnica = 0
              ORDER BY c.id',
            [$usuarioId]
        );

        foreach ($cuentas as &$c) {
            $c['codigo'] = Hashid::codificar((int) $c['id'], 'cuenta');
            unset($c['id']);
        }

        return $cuentas;
    }
}
