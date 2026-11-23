<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioNotaContable;
use CashMan\Aplicacion\Servicio\ServicioSesionCaja;
use CashMan\Dominio\Comun\Monto;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * La ventanilla: el turno del cajero y sus notas.
 *
 * Es la pantalla donde un cajero ve de un vistazo lo único que de
 * verdad le importa durante el día: cuánto efectivo debería tener en el
 * cajón ahora mismo y qué operaciones lo movieron.
 */
final class ControladorCajaTurno extends ControladorBase
{
    /** Las denominaciones que circulan en El Salvador, en centavos. */
    private const DENOMINACIONES = [
        10000, 5000, 2000, 1000, 500, 200, 100, 25, 10, 5, 1,
    ];

    public function panel(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        if (!Autorizacion::puedeAlguno(['caja.operar', 'caja.ver_todas'])) {
            Autorizacion::exigir('caja.operar');
        }

        $svc   = new ServicioSesionCaja();
        $turno = $svc->turnoActual($usuarioId);

        // Cajas libres, para poder abrir turno.
        $cajas = BaseDatos::todas(
            'SELECT c.id, c.codigo, c.nombre, c.agencia, c.tope_efectivo_cent,
                    (SELECT COUNT(*) FROM caja_sesiones s
                      WHERE s.caja_id = c.id AND s.estado = "abierta") AS ocupada
               FROM cajas c WHERE c.activa = 1 ORDER BY c.codigo'
        );

        $movimientos = [];

        if ($turno !== null) {
            // El formulario de cierre apunta al turno por su código, no
            // por su id: los identificadores no viajan en claro.
            $turno['codigo_sesion'] = Hashid::codificar((int) $turno['id'], 'caja_sesion');

            $movimientos = BaseDatos::todas(
                'SELECT m.tipo, m.medio, m.cheque_banco, m.cheque_numero,
                        m.importe_cent, m.concepto, m.referencia, m.creado_en,
                        CONCAT(u.nombres," ",u.apellidos) AS cliente
                   FROM caja_movimientos m
                   LEFT JOIN usuarios u ON u.id = m.cliente_id
                  WHERE m.sesion_id = ?
                  ORDER BY m.id DESC',
                [(int) $turno['id']]
            );
        }

        // Turnos recientes: los suyos, o los de todos si supervisa.
        $verTodas = Autorizacion::puede('caja.ver_todas');

        $historial = BaseDatos::todas(
            'SELECT s.id, s.folio, s.estado, s.fondo_inicial_cent, s.esperado_cent,
                    s.contado_cent, s.diferencia_cent, s.abierta_en, s.cerrada_en,
                    s.arqueada_en, c.nombre AS caja,
                    CONCAT(u.nombres," ",u.apellidos) AS cajero,
                    CONCAT(a.nombres," ",a.apellidos) AS arqueador
               FROM caja_sesiones s
               JOIN cajas    c ON c.id = s.caja_id
               JOIN usuarios u ON u.id = s.usuario_id
               LEFT JOIN usuarios a ON a.id = s.arqueada_por
              WHERE ' . ($verTodas ? '1=1' : 's.usuario_id = ?') . '
              ORDER BY s.id DESC LIMIT 25',
            $verTodas ? [] : [$usuarioId]
        );

        foreach ($historial as &$h) {
            $h['codigo'] = Hashid::codificar((int) $h['id'], 'caja_sesion');
            unset($h['id']);
        }
        unset($h);

        $this->render('caja/turno', [
            'titulo'      => 'Mi caja · CashMan H.A.',
            'encabezado'  => 'Caja de ventanilla',
            'turno'       => $turno,
            'cajas'       => $cajas,
            'movimientos' => $movimientos,
            'historial'   => $historial,
            'denominaciones' => self::DENOMINACIONES,
            'fondoSugerido'  => Parametros::entero('caja.fondo_sugerido_cent', 20000),
            'tolerancia'     => Parametros::entero('caja.diferencia_tolerada_cent', 100),
            'puedeArquear'   => Autorizacion::puede('caja.arquear'),
            'csrf'        => Csrf::token('caja_turno'),
        ]);
    }

    public function abrir(array $parametros = []): void
    {
        $destino = $this->enrutador->url('caja');

        try {
            $r = (new ServicioSesionCaja())->abrir(
                $this->postEntero('caja'),
                $this->post('fondo', '0')
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $this->responderSegunCliente(
            true,
            'Turno ' . $r['folio'] . ' abierto en ' . $r['caja']
            . ' con un fondo de ' . Monto::deCentavos(
                (int) ($r['fondo']['centavos'] ?? 0)
            )->formateado() . '.',
            $destino,
            $r
        );
    }

    public function cerrar(array $parametros = []): void
    {
        $destino = $this->enrutador->url('caja');

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'caja_sesion');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Turno no encontrado.');
        }

        /* El conteo llega como `d[centavos] = cantidad`.
         *
         * Solo se aceptan las denominaciones que de verdad circulan: sin
         * ese filtro, un campo manipulado podría declarar «3 billetes de
         * $7.33» y el arqueo cuadraría con dinero que no existe. */
        $conteo = [];

        foreach ((array) ($_POST['d'] ?? []) as $cent => $cantidad) {
            $cent = (int) $cent;

            if (in_array($cent, self::DENOMINACIONES, true)) {
                $conteo[$cent] = max(0, (int) $cantidad);
            }
        }

        try {
            $r = (new ServicioSesionCaja())->cerrar(
                $id,
                $conteo,
                $this->post('observaciones')
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $dif = (int) ($r['diferencia']['centavos'] ?? 0);

        $this->responderSegunCliente(
            true,
            $r['estado'] === 'cuadrada'
                ? 'Turno ' . $r['folio'] . ' cerrado y cuadrado. '
                  . 'Muchas gracias por el cuidado.'
                : 'Turno ' . $r['folio'] . ' cerrado con una diferencia de '
                  . ($r['diferencia']['formateado'] ?? '')
                  . ' (' . ($dif > 0 ? 'sobrante' : 'faltante') . '). '
                  . 'Ya se avisó a gerencia para el arqueo.',
            $destino,
            $r
        );
    }

    public function arquear(array $parametros = []): void
    {
        $destino = $this->enrutador->url('caja');

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'caja_sesion');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Turno no encontrado.');
        }

        try {
            $r = (new ServicioSesionCaja())->arquear($id, $this->post('observaciones'));
        } catch (\RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $this->responderSegunCliente(
            true,
            'Turno ' . $r['folio'] . ' arqueado. Se le avisó al cajero.',
            $destino,
            $r
        );
    }

    /** Detalle de un turno cerrado, con su arqueo. */
    public function detalle(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'caja_sesion');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Turno no encontrado.');
        }

        $s = BaseDatos::unaFila(
            'SELECT s.*, c.codigo AS caja_codigo, c.nombre AS caja_nombre,
                    CONCAT(u.nombres," ",u.apellidos) AS cajero,
                    CONCAT(a.nombres," ",a.apellidos) AS arqueador
               FROM caja_sesiones s
               JOIN cajas    c ON c.id = s.caja_id
               JOIN usuarios u ON u.id = s.usuario_id
               LEFT JOIN usuarios a ON a.id = s.arqueada_por
              WHERE s.id = ?',
            [$id]
        );

        if ($s === null) {
            throw new RecursoNoEncontrado('Turno no encontrado.');
        }

        if ((int) $s['usuario_id'] !== $usuarioId && !Autorizacion::puede('caja.ver_todas')) {
            throw new RecursoNoEncontrado('Turno no encontrado.');
        }

        $movimientos = BaseDatos::todas(
            'SELECT m.tipo, m.medio, m.cheque_banco, m.cheque_numero,
                        m.importe_cent, m.concepto, m.referencia, m.creado_en,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente
               FROM caja_movimientos m
               LEFT JOIN usuarios u ON u.id = m.cliente_id
              WHERE m.sesion_id = ? ORDER BY m.id',
            [$id]
        );

        $arqueo = BaseDatos::todas(
            'SELECT denominacion_cent, cantidad FROM caja_arqueo
              WHERE sesion_id = ? ORDER BY denominacion_cent DESC',
            [$id]
        );

        $s['codigo'] = Hashid::codificar($id, 'caja_sesion');

        $this->render('caja/detalle', [
            'titulo'      => 'Turno ' . $s['folio'] . ' · CashMan H.A.',
            'encabezado'  => 'Turno ' . $s['folio'],
            'sesion'      => $s,
            'movimientos' => $movimientos,
            'arqueo'      => $arqueo,
            'resumen'     => (new ServicioSesionCaja())->resumen($id),
            'puedeArquear'=> Autorizacion::puede('caja.arquear')
                             && (int) $s['usuario_id'] !== $usuarioId
                             && $s['arqueada_en'] === null
                             && (string) $s['estado'] !== 'abierta',
            'csrf'        => Csrf::token('caja_turno'),
        ]);
    }

    // =================================================================
    // NOTAS DE ABONO Y CARGO
    // =================================================================

    public function notas(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        if (!Autorizacion::puedeAlguno(['nota.emitir', 'nota.autorizar'])) {
            Autorizacion::exigir('nota.emitir');
        }

        $estado = $this->get('estado');

        $donde = '1=1';
        $args  = [];

        if (in_array($estado, ['pendiente', 'aplicada', 'rechazada', 'anulada'], true)) {
            $donde  = 'n.estado = ?';
            $args[] = $estado;
        }

        $notas = BaseDatos::todas(
            "SELECT n.id, n.folio, n.tipo, n.importe_cent, n.concepto, n.motivo,
                    n.estado, n.creada_en, n.autorizada_en, n.motivo_anulacion,
                    c.numero_cuenta,
                    CONCAT(cl.nombres,' ',cl.apellidos) AS cliente,
                    CONCAT(e.nombres,' ',e.apellidos)   AS emisor,
                    CONCAT(a.nombres,' ',a.apellidos)   AS autorizador
               FROM notas_contables n
               JOIN cuentas  c  ON c.id = n.cuenta_id
               JOIN usuarios cl ON cl.id = n.usuario_id
               JOIN usuarios e  ON e.id = n.emitida_por
               LEFT JOIN usuarios a ON a.id = n.autorizada_por
              WHERE {$donde}
              ORDER BY n.id DESC LIMIT 200",
            $args
        );

        foreach ($notas as &$n) {
            $n['codigo'] = Hashid::codificar((int) $n['id'], 'nota');
            unset($n['id']);
        }
        unset($n);

        $conteos = BaseDatos::unaFila(
            "SELECT SUM(estado = 'pendiente') AS pendiente,
                    SUM(estado = 'aplicada')  AS aplicada,
                    SUM(estado = 'rechazada') AS rechazada,
                    SUM(estado = 'anulada')   AS anulada,
                    COUNT(*)                  AS todas
               FROM notas_contables"
        ) ?? [];

        $this->render('caja/notas', [
            'titulo'      => 'Notas de abono y cargo · CashMan H.A.',
            'encabezado'  => 'Notas de abono y cargo',
            'notas'       => $notas,
            'conteos'     => $conteos,
            'estado'      => $estado,
            'puedeEmitir' => Autorizacion::puede('nota.emitir'),
            'puedeAutorizar' => Autorizacion::puede('nota.autorizar'),
            'puedeAnular' => Autorizacion::puede('nota.anular'),
            'csrf'        => Csrf::token('nota'),
        ]);
    }

    public function emitirNota(array $parametros = []): void
    {
        $destino = $this->enrutador->url('caja/notas');

        try {
            $r = (new ServicioNotaContable())->emitir([
                'tipo'      => $this->post('tipo'),
                'cuenta_id' => $this->resolverCuenta($this->post('cuenta')),
                'monto'     => $this->post('monto'),
                'concepto'  => $this->post('concepto'),
                'motivo'    => $this->post('motivo'),
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $this->responderSegunCliente(
            true,
            'Nota ' . $r['folio'] . ' emitida por ' . ($r['monto']['formateado'] ?? '')
            . '. Queda pendiente de autorización.',
            $destino,
            $r
        );
    }

    public function autorizarNota(array $parametros = []): void
    {
        $destino = $this->enrutador->url('caja/notas');
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'nota');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Nota no encontrada.');
        }

        try {
            $r = (new ServicioNotaContable())->autorizar($id);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $this->responderSegunCliente(
            true,
            'Nota ' . $r['folio'] . ' autorizada y aplicada. Se avisó al cliente.',
            $destino,
            $r
        );
    }

    public function rechazarNota(array $parametros = []): void
    {
        $destino = $this->enrutador->url('caja/notas');
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'nota');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Nota no encontrada.');
        }

        try {
            $r = (new ServicioNotaContable())->rechazar($id, $this->post('motivo'));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $this->responderSegunCliente(
            true,
            'Nota ' . $r['folio'] . ' rechazada. Se avisó a quien la emitió.',
            $destino,
            $r
        );
    }

    public function anularNota(array $parametros = []): void
    {
        $destino = $this->enrutador->url('caja/notas');
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'nota');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Nota no encontrada.');
        }

        try {
            $r = (new ServicioNotaContable())->anular($id, $this->post('motivo'));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $this->responderSegunCliente(
            true,
            'Nota ' . $r['folio'] . ' anulada con su reverso contable.',
            $destino,
            $r
        );
    }

    // -----------------------------------------------------------------

    /**
     * Acepta tanto el código ofuscado como el número de cuenta.
     *
     * En ventanilla el cajero tiene delante la libreta del cliente, no
     * un identificador del sistema: obligarle a buscar el código para
     * emitir una nota es pedirle que haga de traductor.
     */
    private function resolverCuenta(string $entrada): int
    {
        $entrada = trim($entrada);

        if ($entrada === '') {
            return 0;
        }

        $id = Hashid::decodificar($entrada, 'cuenta');

        if ($id !== null && $id > 0) {
            return $id;
        }

        $soloDigitos = preg_replace('/\D+/', '', $entrada) ?? '';

        if ($soloDigitos === '') {
            return 0;
        }

        return (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE numero_cuenta = ? AND es_tecnica = 0',
            [$soloDigitos]
        );
    }
}
