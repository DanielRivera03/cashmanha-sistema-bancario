<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioArreglo;
use CashMan\Aplicacion\Servicio\ServicioCobranza;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Expedientes de cobro.
 *
 * La lista de cartera morosa dice cuánto se debe. Esto dice qué se ha
 * hecho para cobrarlo: a quién se llamó, qué contestó, qué prometió y en
 * qué punto del proceso va. Es la diferencia entre un informe y una
 * herramienta de trabajo.
 */
final class ControladorCobranza extends ControladorBase
{
    public function listar(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('cobranza.ver');

        $etapa = $this->get('etapa');

        $donde = "k.etapa NOT IN ('recuperado','castigado')";
        $args  = [];

        if ($etapa === 'cerrados') {
            $donde = "k.etapa IN ('recuperado','castigado')";
        } elseif (in_array($etapa, ['preventiva', 'administrativa', 'extrajudicial',
                                    'judicial', 'sentencia'], true)) {
            $donde  = 'k.etapa = ?';
            $args[] = $etapa;
        }

        $casos = BaseDatos::todas(
            "SELECT k.id, k.folio, k.etapa, k.dias_mora, k.cuotas_vencidas,
                    k.deuda_cent, k.mora_cent, k.abierto_en, k.juzgado,
                    k.referencia_judicial, k.demanda_en, k.cerrado_en,
                    k.recuperado_cent,
                    c.id AS credito_id, c.folio AS credito_folio,
                    c.monto_cent, c.saldo_capital_cent,
                    p.nombre AS producto,
                    CONCAT(u.nombres,' ',u.apellidos) AS cliente,
                    u.usuario, u.correo, u.telefono,
                    CONCAT(g.nombres,' ',g.apellidos) AS gestor,
                    (SELECT COUNT(*) FROM cobranza_gestiones x WHERE x.caso_id = k.id) AS gestiones,
                    (SELECT COUNT(*) FROM cobranza_promesas x
                      WHERE x.caso_id = k.id AND x.estado = 'incumplida') AS promesas_rotas,
                    (SELECT MAX(x.creada_en) FROM cobranza_gestiones x
                      WHERE x.caso_id = k.id) AS ultima_gestion
               FROM cobranza_casos k
               JOIN creditos c  ON c.id = k.credito_id
               JOIN productos p ON p.id = c.producto_id
               JOIN usuarios  u ON u.id = k.usuario_id
               LEFT JOIN usuarios g ON g.id = k.gestor_id
              WHERE {$donde}
              ORDER BY k.dias_mora DESC, k.deuda_cent DESC
              LIMIT 300",
            $args
        );

        foreach ($casos as &$k) {
            $k['codigo'] = Hashid::codificar((int) $k['id'], 'cobranza');

            // Para poder saltar al credito desde la propia lista,
            // sin pasar antes por el expediente.
            $k['credito_codigo'] = Hashid::codificar((int) $k['credito_id'], 'credito');

            unset($k['id'], $k['credito_id']);
        }
        unset($k);

        $conteos = BaseDatos::unaFila(
            "SELECT SUM(etapa = 'preventiva')     AS preventiva,
                    SUM(etapa = 'administrativa') AS administrativa,
                    SUM(etapa = 'extrajudicial')  AS extrajudicial,
                    SUM(etapa = 'judicial')       AS judicial,
                    SUM(etapa = 'sentencia')      AS sentencia,
                    SUM(etapa IN ('recuperado','castigado')) AS cerrados,
                    SUM(etapa NOT IN ('recuperado','castigado')) AS abiertos
               FROM cobranza_casos"
        ) ?? [];

        $totales = BaseDatos::unaFila(
            "SELECT COALESCE(SUM(deuda_cent),0) AS deuda_cent,
                    COALESCE(SUM(mora_cent),0)  AS mora_cent,
                    COALESCE(MAX(dias_mora),0)  AS peor
               FROM cobranza_casos
              WHERE etapa NOT IN ('recuperado','castigado')"
        ) ?? [];

        if ($this->esperaJson()) {
            Respuesta::jsonConEtag(['casos' => $casos, 'conteos' => $conteos]);
        }

        $this->render('cobranza/listar', [
            'titulo'      => 'Expedientes de cobro · CashMan H.A.',
            'encabezado'  => 'Gestión de cobranza',
            'casos'       => $casos,
            'conteos'     => $conteos,
            'totales'     => $totales,
            'etapa'       => $etapa,
            'puedeGestionar' => Autorizacion::puede('cobranza.gestionar'),
            'puedeEscalar'   => Autorizacion::puede('cobranza.escalar'),
            'puedeJuridico'  => Autorizacion::puede('cobranza.juridico'),
            'csrf'        => Csrf::token('cobranza'),
        ]);
    }

    /**
     * El expediente completo de un deudor.
     *
     * Aquí está lo que pedía la pantalla de cartera: el cliente con sus
     * datos de contacto, el crédito con su tabla de amortización y las
     * cuotas vencidas una por una —el «producto dañado»—, la bitácora de
     * gestiones y, si llegó a juicio, el expediente judicial.
     */
    public function ver(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        Autorizacion::exigir('cobranza.ver');

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'cobranza');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Expediente no encontrado.');
        }

        $caso = BaseDatos::unaFila(
            'SELECT k.*, c.folio AS credito_folio, c.monto_cent, c.saldo_capital_cent,
                    c.tasa_bp, c.plazo_meses, c.cuota_cent, c.destino, c.estado AS credito_estado,
                    c.aprobado_en, c.desembolsado_en, c.id AS credito_id_real,
                    p.nombre AS producto, p.tipo AS producto_tipo,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente,
                    u.usuario, u.correo, u.telefono, u.documento_identidad,
                    d.direccion, d.cargo, d.empresa, d.ingreso_mensual_cent,
                    CONCAT(g.nombres," ",g.apellidos) AS gestor,
                    CONCAT(z.nombres," ",z.apellidos) AS cerrador
               FROM cobranza_casos k
               JOIN creditos  c ON c.id = k.credito_id
               JOIN productos p ON p.id = c.producto_id
               JOIN usuarios  u ON u.id = k.usuario_id
               LEFT JOIN detalle_usuarios d ON d.usuario_id = u.id
               LEFT JOIN usuarios g ON g.id = k.gestor_id
               LEFT JOIN usuarios z ON z.id = k.cerrado_por
              WHERE k.id = ?',
            [$id]
        );

        if ($caso === null) {
            throw new RecursoNoEncontrado('Expediente no encontrado.');
        }

        $creditoId = (int) $caso['credito_id_real'];

        // Las cuotas vencidas, una por una. Es el detalle que no daba la
        // lista: cuál se dejó de pagar, cuándo y por cuánto.
        $cuotas = BaseDatos::todas(
            'SELECT numero, fecha_vencimiento, total_cent, pagado_cent, mora_cent,
                    estado, dias_mora, pagada_en,
                    DATEDIFF(CURDATE(), fecha_vencimiento) AS atraso
               FROM cuotas
              WHERE credito_id = ?
              ORDER BY numero',
            [$creditoId]
        );

        $gestiones = BaseDatos::todas(
            'SELECT g.*, CONCAT(u.nombres," ",u.apellidos) AS autor
               FROM cobranza_gestiones g
               LEFT JOIN usuarios u ON u.id = g.registrada_por
              WHERE g.caso_id = ?
              ORDER BY g.id DESC',
            [$id]
        );

        $promesas = BaseDatos::todas(
            'SELECT * FROM cobranza_promesas WHERE caso_id = ? ORDER BY fecha_promesa DESC',
            [$id]
        );

        // Otros créditos y tarjetas del mismo cliente: un deudor rara vez
        // tiene un solo producto, y conviene verlo entero antes de
        // decidir si se demanda.
        $otrosProductos = BaseDatos::todas(
            'SELECT c.folio, p.nombre AS producto, c.estado,
                    c.saldo_capital_cent, c.cuota_cent
               FROM creditos c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.id <> ?
              ORDER BY c.id DESC',
            [(int) $caso['usuario_id'], $creditoId]
        );

        $tarjetas = BaseDatos::todas(
            'SELECT ultimos4, marca, tipo, estado, limite_cent, saldo_usado_cent
               FROM tarjetas WHERE usuario_id = ? ORDER BY id DESC',
            [(int) $caso['usuario_id']]
        );

        $caso['codigo'] = Hashid::codificar($id, 'cobranza');
        $caso['codigo_credito'] = Hashid::codificar($creditoId, 'credito');
        $caso['codigo_cliente'] = Hashid::codificar((int) $caso['usuario_id'], 'usuario');

        $this->render('cobranza/expediente', [
            'titulo'      => 'Expediente ' . $caso['folio'] . ' · CashMan H.A.',
            'encabezado'  => 'Expediente ' . $caso['folio'],
            'caso'        => $caso,
            'cuotas'      => $cuotas,
            'gestiones'   => $gestiones,
            'promesas'    => $promesas,
            'otros'       => $otrosProductos,
            'tarjetas'    => $tarjetas,
            'puedeGestionar' => Autorizacion::puede('cobranza.gestionar'),
            'puedeEscalar'   => Autorizacion::puede('cobranza.escalar'),
            'puedeJuridico'  => Autorizacion::puede('cobranza.juridico'),
            'puedeCerrar'    => Autorizacion::puede('cobranza.cerrar'),
            // Los arreglos pactados sobre el crédito de este expediente:
            // qué se le ofreció al cliente, quién lo autorizó y cuánto
            // se condonó. Es lo que después hay que poder justificar.
            'arreglos'    => (new ServicioArreglo())->deCredito($creditoId),
            'csrf'        => Csrf::token('cobranza'),
        ]);
    }

    public function gestionar(array $parametros = []): void
    {
        $id = $this->caso($parametros);
        $destino = $this->enrutador->url('cobranza/' . ($parametros['codigo'] ?? ''));

        try {
            $r = (new ServicioCobranza())->registrarGestion($id, [
                'tipo'          => $this->post('tipo'),
                'asunto'        => $this->post('asunto'),
                'detalle'       => $this->post('detalle'),
                'resultado'     => $this->post('resultado'),
                'promesa_fecha' => $this->post('promesa_fecha'),
                'promesa_monto' => $this->post('promesa_monto'),
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $this->responderSegunCliente(true, 'Gestión registrada en el expediente.', $destino, $r);
    }

    public function escalar(array $parametros = []): void
    {
        $id = $this->caso($parametros);
        $destino = $this->enrutador->url('cobranza/' . ($parametros['codigo'] ?? ''));

        try {
            $r = (new ServicioCobranza())->escalar(
                $id,
                $this->post('etapa'),
                $this->post('nota')
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $this->responderSegunCliente(
            true,
            'El expediente ' . $r['folio'] . ' pasó a ' . str_replace('_', ' ', $r['etapa']) . '.',
            $destino,
            $r
        );
    }

    public function judicial(array $parametros = []): void
    {
        $id = $this->caso($parametros);
        $destino = $this->enrutador->url('cobranza/' . ($parametros['codigo'] ?? ''));

        try {
            $r = (new ServicioCobranza())->datosJudiciales($id, [
                'juzgado'          => $this->post('juzgado'),
                'referencia'       => $this->post('referencia'),
                'abogado'          => $this->post('abogado'),
                'demanda_en'       => $this->post('demanda_en'),
                'emplazamiento_en' => $this->post('emplazamiento_en'),
                'sentencia_en'     => $this->post('sentencia_en'),
                'sentencia_favorable' => $this->post('sentencia_favorable'),
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $this->responderSegunCliente(true, 'Expediente judicial actualizado.', $destino, $r);
    }

    public function cerrar(array $parametros = []): void
    {
        $id = $this->caso($parametros);
        $destino = $this->enrutador->url('cobranza');

        try {
            $r = (new ServicioCobranza())->cerrar(
                $id,
                $this->post('como'),
                $this->post('motivo')
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(
                false,
                $e->getMessage(),
                $this->enrutador->url('cobranza/' . ($parametros['codigo'] ?? '')),
                null,
                [],
                422
            );
            return;
        }

        $this->responderSegunCliente(
            true,
            'Expediente ' . $r['folio'] . ' cerrado como ' . $r['etapa'] . '.',
            $destino,
            $r
        );
    }

    // -----------------------------------------------------------------

    private function caso(array $parametros): int
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'cobranza');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Expediente no encontrado.');
        }

        return $id;
    }
}
