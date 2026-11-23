<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Http\Paginador;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Cuotas por cobrar.
 *
 * Replica lo que el sistema anterior llamaba
 * `consulta-cuotas-creditos-sistema-pagos` y `orden-pagos-cuotas`: la
 * vista transversal de todas las cuotas de todos los créditos, que es
 * la herramienta de quien cobra. Sin ella hay que entrar crédito por
 * crédito para saber qué vence esta semana.
 *
 * El cliente ve las suyas, con lo que le toca pagar y cuándo.
 */
final class ControladorCuotas extends ControladorBase
{
    public function listar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $todas     = Autorizacion::puede('credito.ver_todos');

        $vista   = $this->get('v', 'pendientes');
        $buscar  = $todas ? $this->get('q') : '';
        $desde   = $this->get('desde');
        $hasta   = $this->get('hasta');

        $condiciones = [];
        $args        = [];

        if (!$todas) {
            $condiciones[] = 'cr.usuario_id = ?';
            $args[]        = $usuarioId;
        }

        // Las vistas son las preguntas que de verdad se hacen al cobrar.
        switch ($vista) {
            case 'vencidas':
                $condiciones[] = "q.estado NOT IN ('pagada','condonada') AND q.fecha_vencimiento < CURDATE()";
                break;
            case 'semana':
                $condiciones[] = "q.estado NOT IN ('pagada','condonada')
                                  AND q.fecha_vencimiento BETWEEN CURDATE()
                                      AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
                break;
            case 'mes':
                $condiciones[] = "q.estado NOT IN ('pagada','condonada')
                                  AND q.fecha_vencimiento BETWEEN CURDATE()
                                      AND LAST_DAY(CURDATE())";
                break;
            case 'pagadas':
                $condiciones[] = "q.estado = 'pagada'";
                break;
            case 'todas':
                break;
            case 'pendientes':
            default:
                $vista = 'pendientes';
                $condiciones[] = "q.estado NOT IN ('pagada','condonada')";
                break;
        }

        if ($buscar !== '') {
            $condiciones[] = '(cr.folio LIKE ? OR CONCAT(u.nombres," ",u.apellidos) LIKE ?
                               OR u.usuario LIKE ?)';
            $like = '%' . $buscar . '%';
            $args[] = $like; $args[] = $like; $args[] = $like;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) === 1) {
            $condiciones[] = 'q.fecha_vencimiento >= ?';
            $args[] = $desde;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) === 1) {
            $condiciones[] = 'q.fecha_vencimiento <= ?';
            $args[] = $hasta;
        }

        $donde = $condiciones === [] ? '1=1' : implode(' AND ', $condiciones);

        $de = "FROM cuotas   q
               JOIN creditos cr ON cr.id = q.credito_id
               JOIN productos p ON p.id = cr.producto_id
               JOIN usuarios  u ON u.id = cr.usuario_id
              WHERE {$donde}";

        // Los totales se calculan sobre TODO lo filtrado, no sobre la
        // página. Sumando solo lo traído, el "por cobrar" cambiaría al
        // pasar de página, que es peor que no tenerlo.
        //
        // Las restas van convertidas a SIGNED a propósito: las columnas
        // de dinero son BIGINT UNSIGNED y en MySQL la resta de dos sin
        // signo no da negativo, da la vuelta al rango. Una sola cuota con
        // un centavo pagado de más bastaría para inventar millones.
        $resumen = BaseDatos::unaFila(
            "SELECT COUNT(*) AS filas,
                    COALESCE(SUM(CASE WHEN q.estado NOT IN ('pagada','condonada')
                         THEN GREATEST(0, CAST(q.total_cent AS SIGNED) - CAST(q.pagado_cent AS SIGNED))
                         ELSE 0 END), 0) AS por_cobrar,
                    COALESCE(SUM(CASE WHEN q.estado NOT IN ('pagada','condonada')
                         THEN q.mora_cent ELSE 0 END), 0) AS mora,
                    COALESCE(SUM(q.estado NOT IN ('pagada','condonada')
                         AND q.fecha_vencimiento < CURDATE()), 0) AS vencidas,
                    COALESCE(SUM(CASE WHEN q.estado = 'pagada'
                         THEN q.pagado_cent ELSE 0 END), 0) AS cobrado
               {$de}",
            $args
        ) ?? [];

        $paginador = new Paginador(
            (int) ($resumen['filas'] ?? 0),
            $this->get('p', '1'),
            $this->get('pp', (string) Paginador::POR_DEFECTO)
        );

        $cuotas = BaseDatos::todas(
            "SELECT q.id, q.numero, q.capital_cent, q.interes_cent, q.mora_cent,
                    q.seguro_vida_cent, q.seguro_empleo_cent,
                    q.total_cent, q.pagado_cent, q.fecha_vencimiento, q.estado,
                    q.pagada_en, q.dias_mora,
                    cr.id AS credito_id, cr.folio, cr.plazo_meses,
                    p.nombre AS producto,
                    CONCAT(u.nombres,' ',u.apellidos) AS cliente,
                    u.usuario AS cliente_usuario,
                    DATEDIFF(CURDATE(), q.fecha_vencimiento) AS dias_vencida,
                    /* De dónde salió lo abonado, cuando vino de una
                       compensación. El cliente no hizo un pago: el
                       sistema tomó saldo de su cuenta y lo aplicó, y
                       eso hay que decirlo con esa palabra. */
                    (SELECT COALESCE(SUM(cp.importe_cent), 0) FROM compensaciones cp
                      WHERE cp.cuota_id = q.id) AS compensado_cent,
                    (SELECT cp.origen FROM compensaciones cp
                      WHERE cp.cuota_id = q.id
                      ORDER BY cp.id DESC LIMIT 1) AS compensado_origen
               {$de}
              ORDER BY q.fecha_vencimiento, cr.folio, q.numero, q.id
              LIMIT {$paginador->porPagina} OFFSET {$paginador->desplazamiento}",
            $args
        );

        $totales = [
            'cuotas'     => (int) ($resumen['filas'] ?? 0),
            'por_cobrar' => (int) ($resumen['por_cobrar'] ?? 0),
            'mora'       => (int) ($resumen['mora'] ?? 0),
            'vencidas'   => (int) ($resumen['vencidas'] ?? 0),
            'cobrado'    => (int) ($resumen['cobrado'] ?? 0),
        ];

        foreach ($cuotas as &$q) {
            $q['codigo']         = Hashid::codificar((int) $q['id'], 'cuota');
            $q['credito_codigo'] = Hashid::codificar((int) $q['credito_id'], 'credito');
            $q['pendiente_cent'] = max(0, (int) $q['total_cent'] - (int) $q['pagado_cent']);
            $q['vencida']        = $q['estado'] !== 'pagada' && (int) $q['dias_vencida'] > 0;

            unset($q['id'], $q['credito_id']);
        }
        unset($q);

        // Conteos de cada pestaña, para saber qué hay antes de entrar.
        $base = $todas ? '' : ' AND cr.usuario_id = ' . (int) $usuarioId;

        $conteos = BaseDatos::unaFila(
            "SELECT
               SUM(q.estado NOT IN ('pagada','condonada')) pendientes,
               SUM(q.estado NOT IN ('pagada','condonada') AND q.fecha_vencimiento < CURDATE()) vencidas,
               SUM(q.estado NOT IN ('pagada','condonada') AND q.fecha_vencimiento BETWEEN CURDATE()
                   AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) semana,
               SUM(q.estado NOT IN ('pagada','condonada') AND q.fecha_vencimiento BETWEEN CURDATE()
                   AND LAST_DAY(CURDATE())) mes,
               SUM(q.estado = 'pagada') pagadas,
               COUNT(*) todas
             FROM cuotas q
             JOIN creditos cr ON cr.id = q.credito_id
            WHERE 1=1{$base}"
        ) ?? [];

        $this->render('creditos/cuotas', [
            'titulo'     => 'Cuotas · CashMan H.A.',
            'encabezado' => $todas ? 'Cuotas por cobrar' : 'Mis cuotas',
            'cuotas'     => $cuotas,
            'totales'    => $totales,
            'conteos'    => $conteos,
            'vistaTodas' => $todas,
            'vista'      => $vista,
            'filtros'    => ['q' => $buscar, 'desde' => $desde, 'hasta' => $hasta],
            'paginacion' => $paginador->aArreglo(),
            'csrf'       => Csrf::token('credito'),
        ]);
    }
}
