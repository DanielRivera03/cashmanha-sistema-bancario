<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;


use CashMan\Dominio\Credito\Clausulado;
use CashMan\Dominio\Credito\DestinoDeCredito;
use CashMan\Aplicacion\Servicio\ServicioCredito;
use CashMan\Dominio\Comun\MontoInvalido;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Solicitudes de crédito y su flujo de aprobación por etapas.
 */
final class ControladorSolicitudes extends ControladorBase
{
    private const ETAPAS = [
        'capturada'            => ['Capturada',    'solicitud.revisar_atencion'],
        'revision_atencion'    => ['Atención',     'solicitud.revisar_gerencia'],
        'revision_gerencia'    => ['Gerencia',     'solicitud.revisar_presidencia'],
        'revision_presidencia' => ['Presidencia',  'credito.aprobar'],
        'aprobada'             => ['Aprobada',     null],
        'rechazada'            => ['Rechazada',    null],
        'desembolsada'         => ['Desembolsada', null],
    ];

    public function listar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $revisa = Autorizacion::puedeAlguno([
            'solicitud.revisar_atencion', 'solicitud.revisar_gerencia',
            'solicitud.revisar_presidencia', 'credito.aprobar',
        ]);

        $where = $revisa ? '1=1' : 's.usuario_id = ?';
        $args  = $revisa ? [] : [$usuarioId];

        $etapa = $this->get('e');
        if ($etapa !== '' && isset(self::ETAPAS[$etapa])) {
            $where .= ' AND s.etapa = ?';
            $args[] = $etapa;
        }

        $solicitudes = BaseDatos::todas(
            "SELECT s.*, p.nombre AS producto, p.tasa_interes_bp,
                    CONCAT(u.nombres,' ',u.apellidos) AS cliente, u.correo
               FROM solicitudes_credito s
               JOIN productos p ON p.id = s.producto_id
               JOIN usuarios  u ON u.id = s.usuario_id
              WHERE {$where}
              ORDER BY FIELD(s.etapa,'capturada','revision_atencion','revision_gerencia',
                                     'revision_presidencia','aprobada','desembolsada','rechazada'),
                       s.id DESC
              LIMIT 200",
            $args
        );

        foreach ($solicitudes as &$s) {
            $s['codigo']        = Hashid::codificar((int) $s['id'], 'solicitud');
            $s['etapa_nombre']  = self::ETAPAS[$s['etapa']][0] ?? $s['etapa'];
            // Solo marca "puede actuar" si el rol tiene el permiso de ESA etapa.
            $permiso            = self::ETAPAS[$s['etapa']][1] ?? null;
            $s['puede_revisar'] = $permiso !== null && Autorizacion::puede($permiso);
            unset($s['id']);
        }

        $resumen = BaseDatos::unaFila(
            'SELECT COUNT(*) total,
                    SUM(etapa NOT IN ("aprobada","rechazada","desembolsada")) en_tramite,
                    SUM(etapa = "aprobada") aprobadas,
                    SUM(etapa = "rechazada") rechazadas
               FROM solicitudes_credito'
            . ($revisa ? '' : ' WHERE usuario_id = ?'),
            $revisa ? [] : [$usuarioId]
        );

        $this->render('solicitudes/listar', [
            'titulo'      => 'Solicitudes de crédito · CashMan H.A.',
            'encabezado'  => $revisa ? 'Solicitudes de crédito' : 'Mis solicitudes',
            'solicitudes' => $solicitudes,
            'resumen'     => $resumen ?? [],
            'revisa'      => $revisa,
            'filtro'      => $etapa,
            'etapas'      => self::ETAPAS,
        ]);
    }

    public function formulario(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        $this->exigirPerfilParaProductos();

        if (!Autorizacion::puedeAlguno(['credito.solicitar', 'solicitud.capturar'])) {
            throw new \CashMan\Infraestructura\Seguridad\AccesoDenegado(
                'No tiene permiso para solicitar créditos.'
            );
        }

        // Mover la tasa es una concesión comercial, no parte de capturar.
        $puedeAjustarTasa = Autorizacion::puede('credito.ajustar_tasa');

        /* Cada producto con SU rango.
         *
         * El formulario ofrecía el mismo techo para todo y los mismos
         * doce plazos: un hipotecario a seis meses no existe, y un
         * consumo a tres millones tampoco. El rango es una condición
         * del producto, igual que su tasa, y estaba en una línea del
         * HTML. */
        $productos = BaseDatos::todas(
            'SELECT id, codigo, nombre, descripcion, tasa_interes_bp,
                    tasa_min_bp, tasa_max_bp,
                    monto_minimo_cent, monto_maximo_cent,
                    plazo_minimo, plazo_maximo,
                    ingreso_minimo_cent, "" AS requisitos
               FROM productos WHERE activo = 1 AND tipo = "credito"
              ORDER BY monto_maximo_cent'
        );

        /* Los plazos del sector, recortados a lo que cada producto
           admite. Una lista fija en la vista no puede saber que un
           hipotecario arranca en 60 meses. */
        $escala = [6, 12, 18, 24, 36, 48, 60, 72, 84, 96, 120, 180, 240, 300, 360];

        foreach ($productos as &$p) {
            $p['codigo_h'] = Hashid::codificar((int) $p['id'], 'producto');

            /* Qué clase de crédito es, para saber qué preguntar.
             *
             * Sale del CÓDIGO del producto y no de su nombre: el
             * nombre lo cambia cualquiera desde el catálogo, y
             * entonces un crédito de vehículo dejaría de pedir el VIN
             * sin que nadie lo note hasta ver un contrato impreso. */
            $p['modalidad'] = Clausulado::modalidadDe((string) $p['codigo']);
            $p['destinos']  = DestinoDeCredito::opciones($p['modalidad']);

            $p['plazos'] = array_values(array_filter(
                $escala,
                static fn(int $n): bool => $n >= (int) $p['plazo_minimo']
                                        && $n <= (int) $p['plazo_maximo']
            ));

            unset($p['id']);
        }
        unset($p);

        // El personal puede capturar para un cliente.
        $clientes = [];
        if (Autorizacion::puede('solicitud.capturar')) {
            $clientes = BaseDatos::todas(
                'SELECT u.id, u.nombres, u.apellidos, u.correo,
                        COALESCE(d.ingreso_mensual_cent,0) AS ingreso_cent
                   FROM usuarios u
              LEFT JOIN detalle_usuarios d ON d.usuario_id = u.id
                  WHERE u.rol_id = 5 AND u.estado = "activo"
                  ORDER BY u.nombres'
            );
            foreach ($clientes as &$c) {
                $c['codigo'] = Hashid::codificar((int) $c['id'], 'usuario');
                unset($c['id']);
            }
        }

        $this->render('solicitudes/nueva', [
            'titulo'     => 'Solicitar crédito · CashMan H.A.',
            'encabezado' => 'Solicitud de crédito',
            'productos'  => $productos,
            'clientes'   => $clientes,
            'csrf'       => Csrf::token('solicitud'),
            'puedeTasa'  => $puedeAjustarTasa,
        ]);
    }

    public function crear(array $parametros = []): void
    {
        $this->exigirPerfilParaProductos();

        $productoId = $this->idDesde($this->post('producto'), 'producto');
        if ($productoId === 0) {
            Respuesta::error('Seleccione un producto crediticio.', 422);
        }

        $datos = [
            'producto_id' => $productoId,
            'monto'       => $this->post('monto'),
            'plazo'       => (int) $this->post('plazo', '12'),
            'destino'     => $this->post('destino'),
            'ingreso'     => $this->post('ingreso'),
            // El servicio comprueba el permiso y el rango; aquí solo
            // se traslada lo que llegó.
            'tasa_bp'     => $this->post('tasa_bp'),
        ];

        $cliente = $this->post('cliente');
        if ($cliente !== '') {
            $datos['usuario_id'] = $this->idDesde($cliente, 'usuario');
        }

        /* Los datos del bien que se financia.
         *
         * Se recogen todos los que la pantalla puede mandar y el
         * servicio se queda con los de la modalidad que corresponda:
         * filtrar aquí obligaría a que el controlador conociera las
         * cinco modalidades, y ésa no es su tarea. */
        $datos['objeto'] = [];

        foreach ([
            'veh_marca', 'veh_modelo', 'veh_anio', 'veh_color', 'veh_placa',
            'veh_motor', 'veh_chasis', 'veh_vin', 'veh_clase', 'veh_combustible',
            'veh_condicion', 'veh_vendedor',
            'inm_tipo', 'inm_direccion', 'inm_municipio', 'inm_departamento',
            'inm_matricula', 'inm_area_m2', 'inm_construccion_m2', 'inm_vendedor',
            'inm_tasacion_en',
            'edu_institucion', 'edu_carrera', 'edu_nivel', 'edu_duracion', 'edu_modalidad',
            'neg_actividad', 'neg_nrc', 'neg_direccion', 'neg_aplicacion',
            'detalle',
        ] as $campo) {
            $valor = trim($this->post($campo));

            if ($valor !== '') { $datos['objeto'][$campo] = $valor; }
        }

        try {
            $r = (new ServicioCredito())->solicitar($datos);
        } catch (\InvalidArgumentException | \RuntimeException | MontoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito($r, 'Solicitud ' . $r['folio'] . ' registrada. Cuota estimada: '
            . $r['cuota']['formateado']);
    }

    public function ver(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'solicitud');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Solicitud no encontrada.');
        }

        $s = BaseDatos::unaFila(
            'SELECT s.*, p.nombre AS producto, p.tasa_interes_bp,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente, u.correo,
                    CONCAT(ra.nombres," ",ra.apellidos) AS rev_atencion,
                    CONCAT(rg.nombres," ",rg.apellidos) AS rev_gerencia,
                    CONCAT(rp.nombres," ",rp.apellidos) AS rev_presidencia,
                    d.dui, d.nit, d.telefono, d.empresa, d.cargo, d.ingreso_mensual_cent
               FROM solicitudes_credito s
               JOIN productos p ON p.id = s.producto_id
               JOIN usuarios  u ON u.id = s.usuario_id
          LEFT JOIN detalle_usuarios d ON d.usuario_id = u.id
          LEFT JOIN usuarios ra ON ra.id = s.revisado_atencion_por
          LEFT JOIN usuarios rg ON rg.id = s.revisado_gerencia_por
          LEFT JOIN usuarios rp ON rp.id = s.revisado_presidencia_por
              WHERE s.id = ?',
            [$id]
        );

        $revisa = Autorizacion::puedeAlguno([
            'solicitud.revisar_atencion', 'solicitud.revisar_gerencia',
            'solicitud.revisar_presidencia', 'credito.aprobar',
        ]);

        if ($s === null || ((int) $s['usuario_id'] !== $usuarioId && !$revisa)) {
            throw new RecursoNoEncontrado('Solicitud no encontrada.');
        }

        // Simulación de la tabla para que el revisor vea el impacto.
        $simulacion = null;
        try {
            $simulacion = (new ServicioCredito())->simular(
                number_format((int) $s['monto_cent'] / 100, 2, '.', ''),
                (int) $s['plazo_meses'],
                (int) $s['producto_id']
            );
        } catch (\Throwable) {
            // Si falla la simulación, la ficha se muestra igual.
        }

        $permiso = self::ETAPAS[$s['etapa']][1] ?? null;

        $s['codigo']        = Hashid::codificar($id, 'solicitud');
        $s['etapa_nombre']  = self::ETAPAS[$s['etapa']][0] ?? $s['etapa'];
        $s['puede_revisar'] = $permiso !== null && Autorizacion::puede($permiso);
        unset($s['id']);

        $this->render('solicitudes/ver', [
            'titulo'      => 'Solicitud ' . $s['folio'] . ' · CashMan H.A.',
            'encabezado'  => 'Solicitud ' . $s['folio'],
            'solicitud'   => $s,
            'simulacion'  => $simulacion,
            'etapas'      => self::ETAPAS,
            'csrf'        => Csrf::token('solicitud'),
        ]);
    }

    public function avanzar(array $parametros = []): void
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'solicitud');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Solicitud no encontrada.');
        }

        try {
            $r = (new ServicioCredito())->avanzarEtapa($id, $this->post('observacion'));
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito($r, $r['etapa'] === 'aprobada'
            ? 'Solicitud aprobada. El crédito quedó listo para desembolso.'
            : 'Solicitud enviada a la siguiente etapa.');
    }

    public function rechazar(array $parametros = []): void
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'solicitud');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Solicitud no encontrada.');
        }

        try {
            $r = (new ServicioCredito())->rechazar($id, $this->post('motivo'));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito($r, 'Solicitud rechazada.');
    }
}
