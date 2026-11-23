<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Estados de cuenta con corte, movimientos y saldos.
 *
 * Cada estado generado guarda el hash SHA-256 de su contenido: si
 * alguien altera el documento después, el hash deja de coincidir y
 * queda demostrado. Es lo que convierte el estado en un comprobante
 * y no en una impresión cualquiera.
 */
final class ControladorEstadosCuenta extends ControladorBase
{
    /**
     * Estados de cuenta.
     *
     * Dos arreglos respecto a la versión anterior: el personal con
     * `estado_cuenta.ver_todos` entra directamente a los de toda la
     * institución —antes hacía falta `?todos=1` a mano— y el selector
     * de cuentas para generar incluye las de todos los clientes cuando
     * se tiene `estado_cuenta.generar`. Antes solo ofrecía las propias,
     * así que el personal, que no tiene cuentas, no podía generar nada.
     */
    public function listar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $verTodos = Autorizacion::puede('estado_cuenta.ver_todos')
                 && $this->get('mios') !== '1';

        $generaAjenos = Autorizacion::puede('estado_cuenta.generar');

        // ---------- Listado ----------
        $condiciones = [];
        $args        = [];

        if (!$verTodos) {
            $condiciones[] = 'e.usuario_id = ?';
            $args[]        = $usuarioId;
        }

        $buscar = $verTodos ? $this->get('q') : '';
        if ($buscar !== '') {
            $condiciones[] = '(CONCAT(u.nombres," ",u.apellidos) LIKE ? OR u.usuario LIKE ?
                               OR c.numero_cuenta LIKE ?)';
            $like = '%' . $buscar . '%';
            $args[] = $like; $args[] = $like; $args[] = $like;
        }

        // Filtro por cliente concreto: se llega aquí desde su
        // expediente, y entonces solo interesan los suyos.
        $cliente = $verTodos ? $this->get('cliente') : '';
        if ($cliente !== '') {
            $idCliente = $this->idDesde($cliente, 'usuario');
            if ($idCliente > 0) {
                $condiciones[] = 'e.usuario_id = ?';
                $args[]        = $idCliente;
            }
        }

        // Y por tipo de producto: un estado de cuenta de ahorro y uno
        // de tarjeta no se leen igual ni los pide la misma persona.
        $tipoProducto = $this->get('producto');
        if (in_array($tipoProducto, ['ahorro', 'corriente', 'credito', 'tarjeta'], true)) {
            $condiciones[] = 'p.tipo = ?';
            $args[]        = $tipoProducto;
        }

        // Por periodo, que es como se archivan.
        $anio = (int) $this->get('anio', '0');
        if ($anio >= 2000 && $anio <= 2100) {
            $condiciones[] = 'YEAR(e.periodo_fin) = ?';
            $args[]        = $anio;
        }

        $donde = $condiciones === [] ? '1=1' : implode(' AND ', $condiciones);

        $estados = BaseDatos::todas(
            "SELECT e.*,
                    CONCAT(u.nombres,' ',u.apellidos) AS cliente,
                    u.usuario AS cliente_usuario,
                    c.numero_cuenta,
                    p.nombre AS producto, p.tipo AS producto_tipo
               FROM estados_cuenta e
               JOIN usuarios u ON u.id = e.usuario_id
               LEFT JOIN cuentas  c ON c.id = e.cuenta_id
               LEFT JOIN productos p ON p.id = c.producto_id
              WHERE {$donde}
              ORDER BY e.periodo_fin DESC, e.id DESC
              LIMIT " . ($verTodos ? 200 : 60),
            $args
        );

        foreach ($estados as &$e) {
            $e['codigo']  = Hashid::codificar((int) $e['id'], 'estado');
            $e['es_mio']  = (int) $e['usuario_id'] === $usuarioId;
            unset($e['id'], $e['usuario_id'], $e['cuenta_id']);
        }
        unset($e);

        // ---------- Cuentas para generar ----------
        $cuentas = BaseDatos::todas(
            $generaAjenos
                ? "SELECT c.id, c.numero_cuenta, p.nombre AS producto,
                          CONCAT(u.nombres,' ',u.apellidos) AS titular
                     FROM cuentas c
                     JOIN productos p ON p.id = c.producto_id
                     JOIN usuarios  u ON u.id = c.usuario_id
                    WHERE c.es_tecnica = 0 AND c.estado <> 'cerrada'
                    ORDER BY u.nombres, c.numero_cuenta"
                : "SELECT c.id, c.numero_cuenta, p.nombre AS producto, '' AS titular
                     FROM cuentas c
                     JOIN productos p ON p.id = c.producto_id
                    WHERE c.usuario_id = ? AND c.es_tecnica = 0
                    ORDER BY c.id",
            $generaAjenos ? [] : [$usuarioId]
        );

        foreach ($cuentas as &$c) {
            $c['codigo'] = Hashid::codificar((int) $c['id'], 'cuenta');
            unset($c['id']);
        }
        unset($c);

        $this->render('estados/listar', [
            'titulo'      => 'Estados de cuenta · CashMan H.A.',
            'encabezado'  => $verTodos ? 'Estados de cuenta emitidos' : 'Mis estados de cuenta',
            'estados'     => $estados,
            'cuentas'     => $cuentas,
            'csrf'        => Csrf::token('estado'),
            'vistaTodos'  => $verTodos,
            'puedeVerTodos' => Autorizacion::puede('estado_cuenta.ver_todos'),
            'generaAjenos'  => $generaAjenos,
            'filtros'     => [
                'q'        => $buscar,
                'cliente'  => $cliente,
                'producto' => $tipoProducto,
                'anio'     => $anio > 0 ? (string) $anio : '',
            ],
            // Para los desplegables del filtro.
            'clientesConEstado' => $verTodos
                ? BaseDatos::todas(
                    'SELECT DISTINCT u.id, CONCAT(u.nombres," ",u.apellidos) AS nombre,
                            u.usuario, COUNT(e.id) AS emitidos
                       FROM estados_cuenta e
                       JOIN usuarios u ON u.id = e.usuario_id
                      GROUP BY u.id, u.nombres, u.apellidos, u.usuario
                      ORDER BY u.nombres'
                  )
                : [],
            'anios' => BaseDatos::todas(
                $verTodos
                    ? 'SELECT DISTINCT YEAR(periodo_fin) a FROM estados_cuenta ORDER BY a DESC'
                    : 'SELECT DISTINCT YEAR(periodo_fin) a FROM estados_cuenta
                        WHERE usuario_id = ? ORDER BY a DESC',
                $verTodos ? [] : [$usuarioId]
            ),
            'puedeGenerar' => $generaAjenos
                           || Autorizacion::puede('estado_cuenta.ver_propio'),
        ]);
    }

    public function ver(array $parametros = []): void
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'estado');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Estado de cuenta no encontrado.');
        }

        $estado = Autorizacion::exigirEstadoCuenta($id);

        BaseDatos::ejecutar(
            'UPDATE estados_cuenta SET visto_en = COALESCE(visto_en, NOW()) WHERE id = ?',
            [$id]
        );

        $movimientos = BaseDatos::todas(
            'SELECT m.importe_cent, m.descripcion, m.saldo_posterior_cent, m.creado_en,
                    a.referencia, a.tipo
               FROM movimientos m
               JOIN asientos a ON a.id = m.asiento_id
              WHERE m.cuenta_id = ?
                AND DATE(m.creado_en) BETWEEN ? AND ?
              ORDER BY m.id ASC',
            [$estado['cuenta_id'], $estado['periodo_inicio'], $estado['periodo_fin']]
        );

        $cuenta = BaseDatos::unaFila(
            'SELECT c.numero_cuenta, c.moneda, p.nombre AS producto,
                    u.nombres, u.apellidos, u.correo, u.documento_identidad
               FROM cuentas c
               JOIN productos p ON p.id = c.producto_id
               JOIN usuarios  u ON u.id = c.usuario_id
              WHERE c.id = ?',
            [$estado['cuenta_id']]
        );

        $estado['codigo'] = Hashid::codificar($id, 'estado');

        $this->render('estados/ver', [
            'titulo'      => 'Estado de cuenta · CashMan H.A.',
            'encabezado'  => 'Estado de cuenta',
            'estado'      => $estado,
            'cuenta'      => $cuenta,
            'movimientos' => $movimientos,
        ]);
    }

    public function generar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $cuentaId = $this->idDesde($this->post('cuenta'), 'cuenta');
        if ($cuentaId === 0) {
            Respuesta::error('Seleccione una cuenta.', 422);
        }

        $cuenta = Autorizacion::exigirCuenta($cuentaId, $usuarioId, 'estado_cuenta.ver_todos');

        /* Periodo.
         *
         * El formulario envía `desde` y `hasta`, pero aquí solo se leía
         * `mes`. Como `mes` nunca llegaba, siempre se caía al valor por
         * defecto —el mes anterior completo— y las fechas que el cliente
         * elegía se descartaban en silencio. Pedía noviembre y recibía
         * octubre, con los ceros de un mes en que la cuenta ni existía.
         *
         * Se acepta cualquiera de las dos formas: el rango de fechas, que
         * es lo que manda el formulario, o `mes` en formato AAAA-MM, que
         * es lo que usa el proceso que los genera por lote.
         */
        $desde = trim((string) $this->post('desde'));
        $hasta = trim((string) $this->post('hasta'));
        $mes   = trim((string) $this->post('mes'));

        $esFecha = static fn(string $f): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) === 1
            && checkdate((int) substr($f, 5, 2), (int) substr($f, 8, 2), (int) substr($f, 0, 4));

        if ($esFecha($desde) && $esFecha($hasta)) {
            $inicio = $desde;
            $fin    = $hasta;
        } elseif ($mes !== '') {
            if (preg_match('/^\d{4}-\d{2}$/', $mes) !== 1) {
                Respuesta::error('El periodo debe tener el formato AAAA-MM.', 422);
            }
            $inicio = $mes . '-01';
            $fin    = date('Y-m-t', strtotime($inicio));
        } else {
            // Sin nada indicado: el mes anterior completo.
            $inicio = date('Y-m-01', strtotime('-1 month'));
            $fin    = date('Y-m-t', strtotime($inicio));
        }

        if (strtotime($inicio) > strtotime($fin)) {
            Respuesta::error('La fecha inicial no puede ser posterior a la final.', 422);
        }

        if (strtotime($inicio) > time()) {
            Respuesta::error('No se puede generar un estado de un periodo futuro.', 422);
        }

        // Un rango desmedido produce un PDF de cientos de páginas y una
        // consulta que bloquea la tabla de movimientos.
        if ((strtotime($fin) - strtotime($inicio)) > 400 * 86400) {
            Respuesta::error('El periodo no puede abarcar más de un año.', 422);
        }

        // Saldo inicial: suma de todo lo anterior al periodo.
        $saldoInicial = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(importe_cent), 0) FROM movimientos
              WHERE cuenta_id = ? AND DATE(creado_en) < ?',
            [$cuentaId, $inicio]
        );

        $totales = LibroMayor::totalesPeriodo($cuentaId, $inicio, $fin);

        $saldoFinal = $saldoInicial
                    + $totales['abonos']->centavos
                    - $totales['cargos']->centavos;

        $contenido = implode('|', [
            $cuentaId, $inicio, $fin, $saldoInicial,
            $totales['cargos']->centavos, $totales['abonos']->centavos,
            $saldoFinal, $totales['cantidad'],
        ]);
        $hashDoc = hash('sha256', $contenido);

        try {
            BaseDatos::ejecutar(
                'INSERT INTO estados_cuenta
                   (uuid, usuario_id, cuenta_id, tipo, periodo_inicio, periodo_fin, fecha_corte,
                    saldo_inicial_cent, total_cargos_cent, total_abonos_cent, saldo_final_cent,
                    cantidad_movimientos, hash_documento)
                 VALUES (UUID(),?,?, "cuenta", ?,?,?, ?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    saldo_inicial_cent = VALUES(saldo_inicial_cent),
                    total_cargos_cent  = VALUES(total_cargos_cent),
                    total_abonos_cent  = VALUES(total_abonos_cent),
                    saldo_final_cent   = VALUES(saldo_final_cent),
                    cantidad_movimientos = VALUES(cantidad_movimientos),
                    hash_documento     = VALUES(hash_documento),
                    generado_en        = NOW()',
                [
                    (int) $cuenta['usuario_id'], $cuentaId,
                    $inicio, $fin, $fin,
                    $saldoInicial,
                    $totales['cargos']->centavos,
                    $totales['abonos']->centavos,
                    $saldoFinal,
                    $totales['cantidad'],
                    $hashDoc,
                ]
            );
        } catch (\PDOException $e) {
            Respuesta::error('No se pudo generar el estado de cuenta.', 500);
            return;
        }

        $id = BaseDatos::ultimoId();
        if ($id === 0) {
            $id = (int) BaseDatos::unValor(
                'SELECT id FROM estados_cuenta
                  WHERE cuenta_id = ? AND periodo_fin = ? AND tipo = "cuenta" LIMIT 1',
                [$cuentaId, $fin]
            );
        }

        Auditoria::registrar(
            'estado_cuenta.generado',
            Auditoria::EXITO,
            $usuarioId,
            'estado_cuenta',
            (string) $id,
            ['periodo' => $mes, 'movimientos' => $totales['cantidad']]
        );

        Respuesta::exito([
            'codigo'       => Hashid::codificar($id, 'estado'),
            'periodo'      => $mes,
            'movimientos'  => $totales['cantidad'],
            'saldo_inicial'=> Monto::deCentavos($saldoInicial)->jsonSerialize(),
            'cargos'       => $totales['cargos']->jsonSerialize(),
            'abonos'       => $totales['abonos']->jsonSerialize(),
            'saldo_final'  => Monto::deCentavos($saldoFinal)->jsonSerialize(),
            'url'          => $this->url('estados/' . Hashid::codificar($id, 'estado')),
        ], 'Estado de cuenta generado.');
    }

    public function descargar(array $parametros = []): void
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'estado');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Estado de cuenta no encontrado.');
        }

        // La verificación de propiedad ocurre ANTES de generar nada:
        // el PDF se guarda en disco y no debe crearse para alguien que
        // no tiene derecho a verlo.
        $estado = Autorizacion::exigirEstadoCuenta($id);

        try {
            $doc = (new \CashMan\Infraestructura\Documento\ServicioDocumentos())->estadoCuenta($id);
        } catch (\Throwable $e) {
            error_log('[PDF] ' . $e->getMessage());
            Respuesta::error('No se pudo generar el documento.', 500);
            return;
        }

        Auditoria::registrar('estado_cuenta.descargado', Auditoria::EXITO,
            Sesion::usuarioId(), 'estado_cuenta', (string) $id);

        $nombre = 'estado-cuenta-' . substr((string) $estado['periodo_fin'], 0, 7) . '.pdf';

        // Se sirve por PHP, no por Apache: storage/ está fuera del webroot.
        Respuesta::ver($doc['ruta'], $nombre);
    }

    public function enviar(array $parametros = []): void
    {
        Autorizacion::exigir('estado_cuenta.generar');

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'estado');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Estado de cuenta no encontrado.');
        }

        $estado = Autorizacion::exigirEstadoCuenta($id);

        BaseDatos::ejecutar('UPDATE estados_cuenta SET enviado_en = NOW() WHERE id = ?', [$id]);

        (new \CashMan\Aplicacion\Servicio\ServicioNotificacion())->crear(
            (int) $estado['usuario_id'],
            'estado_cuenta.disponible',
            'Tu estado de cuenta está listo',
            'Periodo ' . $estado['periodo_inicio'] . ' al ' . $estado['periodo_fin'] . '.',
            ['codigo' => Hashid::codificar($id, 'estado')],
            'info',
            $this->url('estados/' . Hashid::codificar($id, 'estado'))
        );

        Auditoria::registrar(
            'estado_cuenta.enviado',
            Auditoria::EXITO,
            Sesion::usuarioId(),
            'estado_cuenta',
            (string) $id
        );

        Respuesta::exito(null, 'Estado de cuenta enviado al cliente.');
    }
}
