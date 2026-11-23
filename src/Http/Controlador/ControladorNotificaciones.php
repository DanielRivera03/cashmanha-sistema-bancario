<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Http\Paginador;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Notificaciones.
 *
 * El sistema anterior tenía `notificaciones-recibidas-usuarios` como
 * pantalla propia, repetida una vez por rol. En v2 solo existía el
 * desplegable de la campana, que muestra las últimas y nada más: con
 * 207 notificaciones acumuladas, todo lo anterior era inalcanzable.
 *
 * Aquí está el historial completo, con filtros y marcado de leídas.
 */
final class ControladorNotificaciones extends ControladorBase
{
    public function listar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $vista  = $this->get('v', 'todas');
        $tipo   = $this->get('tipo');
        $buscar = $this->get('q');

        $condiciones = ['n.usuario_id = ?'];
        $args        = [$usuarioId];

        if ($vista === 'sin_leer')     { $condiciones[] = 'n.leida_en IS NULL'; }
        elseif ($vista === 'leidas')   { $condiciones[] = 'n.leida_en IS NOT NULL'; }
        elseif (in_array($vista, ['info', 'exito', 'alerta', 'critico'], true)) {
            $condiciones[] = 'n.severidad = ?';
            $args[]        = $vista;
        }

        if ($tipo !== '' && preg_match('/^[a-z_.]{2,50}$/', $tipo) === 1) {
            $condiciones[] = 'n.tipo = ?';
            $args[]        = $tipo;
        }

        if ($buscar !== '') {
            $condiciones[] = '(n.titulo LIKE ? OR n.mensaje LIKE ?)';
            $like = '%' . $buscar . '%';
            $args[] = $like; $args[] = $like;
        }

        $donde = implode(' AND ', $condiciones);

        $total = (int) BaseDatos::unValor(
            "SELECT COUNT(*) FROM notificaciones n WHERE {$donde}", $args
        );

        // Recorta la página al rango existente: un `?p=999` devolvía una
        // lista vacía en lugar de la última página.
        $pag    = new Paginador($total, $this->get('p', '1'), $this->get('pp', '50'));
        $porPag = $pag->porPagina;
        $offset = $pag->desplazamiento;

        $notificaciones = BaseDatos::todas(
            "SELECT n.* FROM notificaciones n
              WHERE {$donde}
              ORDER BY n.creada_en DESC
              LIMIT {$porPag} OFFSET {$offset}",
            $args
        );

        foreach ($notificaciones as &$n) {
            $n['codigo']  = Hashid::codificar((int) $n['id'], 'notificacion');
            $n['leida']   = $n['leida_en'] !== null;

            // url_destino la escribe el propio sistema, pero se valida
            // igual: una ruta relativa, nunca un salto a otro sitio.
            $destino = (string) ($n['url_destino'] ?? '');
            $n['destino'] = preg_match('#^/[A-Za-z0-9/_?=&.-]*$#', $destino) === 1 ? $destino : '';

            unset($n['id'], $n['usuario_id'], $n['datos_json'], $n['url_destino']);
        }
        unset($n);

        $conteos = BaseDatos::unaFila(
            'SELECT COUNT(*) todas,
                    SUM(leida_en IS NULL)  sin_leer,
                    SUM(leida_en IS NOT NULL) leidas,
                    SUM(severidad = "critico") critico,
                    SUM(severidad = "alerta")  alerta,
                    SUM(severidad = "exito")   exito,
                    SUM(severidad = "info")    info
               FROM notificaciones WHERE usuario_id = ?',
            [$usuarioId]
        ) ?? [];

        $tipos = BaseDatos::todas(
            'SELECT tipo, COUNT(*) n FROM notificaciones
              WHERE usuario_id = ? GROUP BY tipo ORDER BY n DESC LIMIT 25',
            [$usuarioId]
        );

        $this->render('notificaciones/listar', [
            'titulo'          => 'Notificaciones · CashMan H.A.',
            'encabezado'      => 'Notificaciones',
            'notificaciones'  => $notificaciones,
            'conteos'         => $conteos,
            'tipos'           => $tipos,
            'vista'           => $vista,
            'filtros'         => ['tipo' => $tipo, 'q' => $buscar],
            'total'           => $total,
            'pagina'          => $pag->pagina,
            'paginas'         => $pag->paginas,
            'paginacion'      => $pag->aArreglo(),
            'csrf'            => Csrf::token('notificacion'),
        ]);
    }

    /** Marca una como leída. */
    public function marcar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'notificacion');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Notificación no encontrada.');
        }

        // El WHERE lleva el usuario: nadie marca las de otro.
        $n = BaseDatos::ejecutar(
            'UPDATE notificaciones SET leida_en = NOW()
              WHERE id = ? AND usuario_id = ? AND leida_en IS NULL',
            [$id, $usuarioId]
        );

        if ($n === 0) {
            Respuesta::exito(['ya_leida' => true], 'La notificación ya estaba leída.');
        }

        Respuesta::exito(null, 'Notificación marcada como leída.');
    }

    /** Marca todas las pendientes del usuario. */
    public function marcarTodas(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $n = BaseDatos::ejecutar(
            'UPDATE notificaciones SET leida_en = NOW()
              WHERE usuario_id = ? AND leida_en IS NULL',
            [$usuarioId]
        );

        Respuesta::exito(
            ['marcadas' => $n],
            $n === 0
                ? 'No tenías notificaciones sin leer.'
                : $n . ' notificación(es) marcadas como leídas.'
        );
    }
}
