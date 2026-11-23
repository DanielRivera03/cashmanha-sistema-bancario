<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\DesafioInvalido;
use CashMan\Aplicacion\Servicio\FondosInsuficientes;
use CashMan\Aplicacion\Servicio\LimiteExcedidoTransferencia;
use CashMan\Aplicacion\Servicio\ServicioDesafio;
use CashMan\Aplicacion\Servicio\ServicioTransferencia;
use CashMan\Aplicacion\Servicio\TransferenciaRechazada;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Comun\MontoInvalido;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\AccesoDenegado;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\LimitadorTasa;
use CashMan\Infraestructura\Seguridad\Sesion;

final class ControladorTransferencias extends ControladorBase
{
    public function formulario(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        if (!Autorizacion::puedeAlguno(['transferencia.ejecutar', 'transferencia.ejecutar_terceros'])) {
            throw new AccesoDenegado('No tiene permiso para transferir.');
        }

        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, c.saldo_cent, c.moneda, p.nombre AS producto,
                    p.saldo_minimo_cent, c.saldo_retenido_cent
               FROM cuentas c
               JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.estado = "activa" AND c.es_tecnica = 0
              ORDER BY c.id',
            [$usuarioId]
        );

        foreach ($cuentas as &$c) {
            $c['codigo'] = Hashid::codificar((int) $c['id'], 'cuenta');
            $c['disponible_cent'] = max(
                0,
                (int) $c['saldo_cent'] - (int) $c['saldo_retenido_cent'] - (int) $c['saldo_minimo_cent']
            );
            unset($c['id']);
        }

        $limite = BaseDatos::unaFila(
            'SELECT * FROM limites_operacion
              WHERE tipo_operacion = "transferencia" AND activo = 1
                AND (rol_id = ? OR rol_id IS NULL)
              ORDER BY rol_id IS NULL ASC LIMIT 1',
            [Sesion::rolId()]
        );

        $this->render('transferencias/formulario', [
            'titulo'     => 'Transferir · CashMan H.A.',
            'encabezado' => 'Transferir dinero',
            'cuentas'    => $cuentas,
            'limite'     => $limite,
            'csrf'       => Csrf::token('transferencia'),
        ]);
    }

    /**
     * Valida una cuenta destino sin revelar datos de más.
     *
     * Devuelve solo el nombre parcial del titular, lo justo para que
     * el usuario confirme que no se equivocó de número. Nunca el saldo
     * ni los datos completos.
     */
    public function validarCuenta(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        // Sin límite, este endpoint permite enumerar cuentas y titulares.
        (new LimitadorTasa(30, 60, 120))->exigir('valida-cuenta:' . Sesion::usuarioId());

        /* `silencio => true` en todas las respuestas de aquí.

           `cm.js` envuelve `fetch` y avisa en pantalla de toda
           petición que cambie algo y responda `ok: false`. Esto no
           cambia nada —solo comprueba a quién pertenece una cuenta—
           pero es un POST, así que cada número tecleado que no
           existiera disparaba un globo rojo de «No se pudo completar».
           Aparecía mientras el cliente aún estaba escribiendo, y al
           lado de la confirmación de una transferencia que sí había
           salido bien, dando a entender que algo había fallado.

           El resultado de la comprobación se enseña junto al campo,
           que es donde se mira. */
        $numero = preg_replace('/\D/', '', $this->post('numero'));

        if ($numero === '' || strlen($numero) < 6) {
            Respuesta::error('Número de cuenta inválido.', 422, ['silencio' => true]);
        }

        $cuenta = BaseDatos::unaFila(
            'SELECT c.id, c.numero_cuenta, c.estado, c.moneda, u.nombres, u.apellidos
               FROM cuentas c JOIN usuarios u ON u.id = c.usuario_id
              WHERE c.numero_cuenta = ? AND c.es_tecnica = 0',
            [$numero]
        );

        if ($cuenta === null || $cuenta['estado'] !== 'activa') {
            Respuesta::error('La cuenta no existe o no puede recibir fondos.', 404,
                ['silencio' => true]);
        }

        $nombre  = trim((string) $cuenta['nombres']);
        $apellido = trim((string) $cuenta['apellidos']);

        Respuesta::exito([
            'codigo'  => Hashid::codificar((int) $cuenta['id'], 'cuenta'),
            'numero'  => $cuenta['numero_cuenta'],
            'moneda'  => $cuenta['moneda'],
            // Nombre parcial: suficiente para confirmar, no para espiar.
            'titular' => mb_substr($nombre, 0, 1) . str_repeat('*', max(2, mb_strlen($nombre) - 1))
                       . ' ' . mb_substr($apellido, 0, 1)
                       . str_repeat('*', max(2, mb_strlen($apellido) - 1)),
        ], '', ['silencio' => true]);
    }

    public function solicitarCodigo(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        (new LimitadorTasa(5, 300, 300))->exigir('otp-transfer:' . $usuarioId);

        $origenId  = $this->idDesde($this->post('origen'), 'cuenta');
        $destinoId = $this->idDesde($this->post('destino'), 'cuenta');

        if ($origenId === 0 || $destinoId === 0) {
            Respuesta::error('Seleccione las cuentas antes de pedir el código.', 422);
        }

        // Verificar propiedad ANTES de emitir nada.
        Autorizacion::exigirCuenta($origenId, $usuarioId, 'transferencia.ejecutar_terceros');

        try {
            $monto = Monto::deEntradaUsuario($this->post('monto'));
        } catch (MontoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        // El contexto ata el código a esta operación concreta.
        $contexto = hash('sha256', "transferencia|{$origenId}|{$destinoId}|{$monto->centavos}");

        $r = (new ServicioDesafio())->emitir(
            $usuarioId,
            'transferencia',
            $contexto,
            'Transferencia de ' . $monto->formateado()
        );

        Respuesta::exito($r, $r['mensaje']);
    }

    public function ejecutar(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        $origenId  = $this->idDesde($this->post('origen'), 'cuenta');
        $destinoId = $this->idDesde($this->post('destino'), 'cuenta');

        if ($origenId === 0 || $destinoId === 0) {
            Respuesta::error('Cuentas inválidas.', 422);
        }

        $servicio = new ServicioTransferencia();

        try {
            $resultado = $servicio->ejecutar([
                'cuenta_origen_id'   => $origenId,
                'cuenta_destino_id'  => $destinoId,
                'monto'              => $this->post('monto'),
                'concepto'           => $this->post('concepto', 'Transferencia'),
                'codigo_otp'         => $this->post('codigo'),
                // La clave de idempotencia la genera el cliente: si
                // reintenta, llega la misma y no se duplica el cargo.
                'clave_idempotencia' => $this->claveIdempotencia(),
            ]);
        } catch (MontoInvalido | TransferenciaRechazada $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        } catch (FondosInsuficientes $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'fondos_insuficientes']);
            return;
        } catch (LimiteExcedidoTransferencia $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'limite_excedido']);
            return;
        } catch (DesafioInvalido $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'otp_invalido']);
            return;
        }

        Respuesta::exito($resultado, match ($resultado['estado']) {
            'procesada'            => 'Transferencia realizada con éxito.',
            'duplicado'            => 'Esta operación ya se había procesado.',
            'pendiente_aprobacion' => 'La operación quedó pendiente de aprobación.',
            default                => 'Operación registrada.',
        });
    }

    /**
     * Clave de idempotencia.
     *
     * El cliente envía un identificador por intento. Si la red falla y
     * reintenta, llega la misma clave y el libro mayor devuelve el
     * asiento original en lugar de crear uno nuevo.
     */
    private function claveIdempotencia(): string
    {
        $delCliente = $this->post('_idem');

        if ($delCliente !== '' && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $delCliente)) {
            return 'trf:' . Sesion::usuarioId() . ':' . $delCliente;
        }

        return 'trf:' . Sesion::usuarioId() . ':' . bin2hex(random_bytes(16));
    }
}
