<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioSegmento;

use CashMan\Dominio\Comun\Folio;
use CashMan\Aplicacion\Servicio\ServicioNotificacion;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Soporte: reportes de problemas de la plataforma.
 *
 * Reemplaza a `reporteproblemasplataforma` de v1, que no tenía hilo de
 * respuestas ni asignación: el estado se cambiaba a mano y el cliente
 * nunca sabía en qué iba su reporte.
 */
final class ControladorTickets extends ControladorBase
{
    public function listar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $gestiona  = Autorizacion::puede('ticket.gestionar');

        if (!$gestiona) {
            Autorizacion::exigir('ticket.crear');
        }

        $estado = $this->get('e');
        $where  = $gestiona ? '1=1' : 't.usuario_id = ?';
        $args   = $gestiona ? [] : [$usuarioId];

        if ($estado !== '' && in_array($estado, ['abierto','en_proceso','resuelto','no_resuelto','cerrado'], true)) {
            $where .= ' AND t.estado = ?';
            $args[] = $estado;
        }

        $tickets = BaseDatos::todas(
            "SELECT t.id, t.folio, t.titulo, t.categoria, t.prioridad, t.estado,
                    t.creado_en, t.ultima_actividad,
                    CONCAT(u.nombres,' ',u.apellidos) AS solicitante,
                    CONCAT(a.nombres,' ',a.apellidos) AS responsable,
                    (SELECT COUNT(*) FROM ticket_respuestas r WHERE r.ticket_id = t.id) AS respuestas
               FROM tickets t
               JOIN usuarios u ON u.id = t.usuario_id
          LEFT JOIN usuarios a ON a.id = t.asignado_a
              WHERE {$where}
              ORDER BY FIELD(t.prioridad,'critica','alta','media','baja'), t.id DESC
              LIMIT 200",
            $args
        );

        foreach ($tickets as &$t) {
            $t['codigo'] = Hashid::codificar((int) $t['id'], 'ticket');
            unset($t['id']);
        }

        $resumen = BaseDatos::unaFila(
            $gestiona
                ? 'SELECT
                     SUM(estado="abierto")     AS abiertos,
                     SUM(estado="en_proceso")  AS en_proceso,
                     SUM(estado IN ("resuelto","no_resuelto")) AS resueltos,
                     SUM(prioridad="critica" AND estado NOT IN ("cerrado")) AS criticos
                   FROM tickets'
                : 'SELECT
                     SUM(estado="abierto")     AS abiertos,
                     SUM(estado="en_proceso")  AS en_proceso,
                     SUM(estado IN ("resuelto","no_resuelto")) AS resueltos,
                     0 AS criticos
                   FROM tickets WHERE usuario_id = ?',
            $gestiona ? [] : [$usuarioId]
        );

        $this->render('tickets/listar', [
            'titulo'     => 'Soporte · CashMan H.A.',
            'encabezado' => $gestiona ? 'Tickets de soporte' : 'Mis reportes',
            'tickets'    => $tickets,
            'resumen'    => $resumen ?? [],
            'gestiona'   => $gestiona,
            'filtro'     => $estado,
        ]);
    }

    public function formulario(array $parametros = []): void
    {
        Autorizacion::exigir('ticket.crear');

        $this->render('tickets/nuevo', [
            'titulo'     => 'Reportar problema · CashMan H.A.',
            'encabezado' => 'Reportar un problema',
            'csrf'       => Csrf::token('ticket'),
        ]);
    }

    public function crear(array $parametros = []): void
    {
        Autorizacion::exigir('ticket.crear');
        $usuarioId = Sesion::exigirAutenticado();

        $titulo      = mb_substr($this->post('titulo'), 0, 160);
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        $categoria   = $this->post('categoria', 'otro');
        $prioridad   = $this->post('prioridad', 'media');

        if ($titulo === '' || $descripcion === '') {
            Respuesta::error('Completa el título y la descripción.', 422);
        }
        if (mb_strlen($descripcion) > 4000) {
            Respuesta::error('La descripción no puede superar los 4.000 caracteres.', 422);
        }

        $categorias = ['acceso','transaccion','tarjeta','credito','sistema','otro'];
        $prioridades = ['baja','media','alta','critica'];

        if (!in_array($categoria, $categorias, true))  { $categoria = 'otro'; }
        // Un cliente no puede marcar su propio ticket como crítico.
        if (!in_array($prioridad, $prioridades, true)
            || (Sesion::rolId() === 5 && $prioridad === 'critica')) {
            $prioridad = 'media';
        }

        /* ---------------------------------------------------------------
           Por dónde entra esta gestión.

           El botón de banca preferente llevaba a
           `tickets/nuevo?prioridad=alta`. Ese parámetro no lo leía
           nadie, y si lo hubiera leído habría bastado con escribir la
           URL a mano para colarse en la cola prioritaria.

           La prioridad y el encaminamiento los decide el SEGMENTO, en
           el servidor, a partir de lo que el cliente tiene concedido.
           Lo que llegue por la petición no cuenta para esto.
           --------------------------------------------------------------- */
        $via       = 'general';
        $asignadoA = null;

        if (Sesion::rolId() === 5) {
            $segmento = new ServicioSegmento();

            // Su banquero, si lo tiene: la gestión es suya, no de la cola.
            $banquero = BaseDatos::unValor(
                'SELECT u.banquero_id FROM usuarios u
                   JOIN usuarios b ON b.id = u.banquero_id AND b.estado = "activo"
                  WHERE u.id = ?',
                [$usuarioId]
            );

            if ($banquero !== null) {
                $via       = 'banquero';
                $asignadoA = (int) $banquero;
            }

            if ($segmento->beneficio($usuarioId, 'atencion_prioritaria') === true) {
                /* Se sube un escalón, no se fija en «alta»: quien abre
                   algo ya urgente no debe bajar de categoría por tener
                   un beneficio. */
                $prioridad = match ($prioridad) {
                    'baja'  => 'media',
                    'media' => 'alta',
                    default => $prioridad,
                };

                if ($via === 'general') { $via = 'prioritaria'; }
            }
        }

        $folio = Folio::unico('TK', 'tickets');

        BaseDatos::ejecutar(
            'INSERT INTO tickets
               (uuid, folio, usuario_id, asignado_a, categoria, prioridad, via, titulo, descripcion)
             VALUES (UUID(),?,?,?,?,?,?,?,?)',
            [$folio, $usuarioId, $asignadoA, $categoria, $prioridad, $via, $titulo, $descripcion]
        );
        $id = BaseDatos::ultimoId();

        Auditoria::registrar('ticket.creado', Auditoria::EXITO, $usuarioId, 'ticket', (string) $id);

        /* A quién se avisa.
         *
         * Si la gestión tiene dueño, se le avisa a él. Avisar además a
         * los veinte primeros del personal convertiría una asignación
         * en un aviso general, que es lo contrario de asignar. */
        $notif = new ServicioNotificacion();

        $aAvisar = $asignadoA !== null
            ? [['id' => $asignadoA]]
            : BaseDatos::todas(
                'SELECT DISTINCT u.id FROM usuarios u
                   JOIN rol_permiso rp ON rp.rol_id = u.rol_id
                   JOIN permisos p ON p.id = rp.permiso_id
                  WHERE p.clave = "ticket.gestionar" AND u.estado = "activo" LIMIT 20'
            );

        foreach ($aAvisar as $staff) {
            $notif->crear(
                (int) $staff['id'], 'ticket.nuevo',
                'Ticket nuevo: ' . $folio, mb_substr($titulo, 0, 120),
                ['codigo' => Hashid::codificar($id, 'ticket')],
                $prioridad === 'critica' ? 'critico' : 'info',
                $this->url('tickets/' . Hashid::codificar($id, 'ticket'))
            );
        }

        Respuesta::exito(
            ['folio' => $folio, 'redirigir' => $this->url('tickets/' . Hashid::codificar($id, 'ticket'))],
            'Reporte creado con folio ' . $folio . '.'
        );
    }

    public function ver(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'ticket');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Ticket no encontrado.');
        }

        $t = BaseDatos::unaFila(
            'SELECT t.*, CONCAT(u.nombres," ",u.apellidos) AS solicitante, u.correo AS solicitante_correo,
                    CONCAT(a.nombres," ",a.apellidos) AS responsable
               FROM tickets t
               JOIN usuarios u ON u.id = t.usuario_id
          LEFT JOIN usuarios a ON a.id = t.asignado_a
              WHERE t.id = ?',
            [$id]
        );

        $gestiona = Autorizacion::puede('ticket.gestionar');

        if ($t === null || ((int) $t['usuario_id'] !== $usuarioId && !$gestiona)) {
            throw new RecursoNoEncontrado('Ticket no encontrado.');
        }

        // Las notas internas no se muestran al cliente.
        $respuestas = BaseDatos::todas(
            'SELECT r.cuerpo, r.interna, r.creado_en,
                    CONCAT(u.nombres," ",u.apellidos) AS autor, u.rol_id
               FROM ticket_respuestas r JOIN usuarios u ON u.id = r.usuario_id
              WHERE r.ticket_id = ?' . ($gestiona ? '' : ' AND r.interna = 0') . '
              ORDER BY r.id ASC',
            [$id]
        );

        $t['codigo'] = Hashid::codificar($id, 'ticket');

        $this->render('tickets/ver', [
            'titulo'     => $t['folio'] . ' · CashMan H.A.',
            'encabezado' => 'Ticket ' . $t['folio'],
            'ticket'     => $t,
            'respuestas' => $respuestas,
            'gestiona'   => $gestiona,
            'csrf'       => Csrf::token('ticket'),
        ]);
    }

    public function responder(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'ticket');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Ticket no encontrado.');
        }

        $t = BaseDatos::unaFila('SELECT * FROM tickets WHERE id = ?', [$id]);
        $gestiona = Autorizacion::puede('ticket.gestionar');

        if ($t === null || ((int) $t['usuario_id'] !== $usuarioId && !$gestiona)) {
            throw new RecursoNoEncontrado('Ticket no encontrado.');
        }
        if ($t['estado'] === 'cerrado') {
            Respuesta::error('El ticket está cerrado y no admite respuestas.', 422);
        }

        $cuerpo = trim((string) ($_POST['cuerpo'] ?? ''));
        if ($cuerpo === '') {
            Respuesta::error('Escriba una respuesta.', 422);
        }

        // Solo el personal puede dejar notas internas.
        $interna = $gestiona && $this->post('interna') === '1' ? 1 : 0;

        BaseDatos::ejecutar(
            'INSERT INTO ticket_respuestas (ticket_id, usuario_id, cuerpo, interna) VALUES (?,?,?,?)',
            [$id, $usuarioId, mb_substr($cuerpo, 0, 4000), $interna]
        );

        // Al responder el personal, el ticket pasa a "en proceso" y
        // queda asignado a quien respondió.
        if ($gestiona && $t['estado'] === 'abierto') {
            BaseDatos::ejecutar(
                'UPDATE tickets SET estado = "en_proceso", asignado_a = COALESCE(asignado_a, ?) WHERE id = ?',
                [$usuarioId, $id]
            );
        }
        BaseDatos::ejecutar('UPDATE tickets SET ultima_actividad = NOW() WHERE id = ?', [$id]);

        // Avisar a la otra parte.
        if ($interna === 0) {
            $destino = $gestiona ? (int) $t['usuario_id'] : (int) ($t['asignado_a'] ?? 0);
            if ($destino > 0 && $destino !== $usuarioId) {
                (new ServicioNotificacion())->crear(
                    $destino, 'ticket.respuesta',
                    'Respuesta en ' . $t['folio'], mb_substr($cuerpo, 0, 120),
                    ['codigo' => Hashid::codificar($id, 'ticket')], 'info',
                    $this->url('tickets/' . Hashid::codificar($id, 'ticket'))
                );
            }
        }

        Respuesta::exito(['recargar' => true], 'Respuesta agregada.');
    }

    public function cambiarEstado(array $parametros = []): void
    {
        Autorizacion::exigir('ticket.gestionar');
        $usuarioId = Sesion::exigirAutenticado();

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'ticket');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Ticket no encontrado.');
        }

        $nuevo = $this->post('estado');
        if (!in_array($nuevo, ['abierto','en_proceso','resuelto','no_resuelto','cerrado'], true)) {
            Respuesta::error('Estado no válido.', 422);
        }

        $t = BaseDatos::unaFila('SELECT folio, usuario_id FROM tickets WHERE id = ?', [$id]);
        if ($t === null) {
            throw new RecursoNoEncontrado('Ticket no encontrado.');
        }

        BaseDatos::ejecutar(
            'UPDATE tickets
                SET estado = ?, resolucion = COALESCE(NULLIF(?,""), resolucion),
                    cerrado_en = IF(? = "cerrado", NOW(), cerrado_en),
                    asignado_a = COALESCE(asignado_a, ?)
              WHERE id = ?',
            [$nuevo, $this->post('resolucion'), $nuevo, $usuarioId, $id]
        );

        Auditoria::registrar('ticket.estado', Auditoria::EXITO, $usuarioId, 'ticket',
            (string) $id, ['nuevo_estado' => $nuevo]);

        (new ServicioNotificacion())->crear(
            (int) $t['usuario_id'], 'ticket.estado',
            'Tu reporte ' . $t['folio'] . ' cambió de estado',
            'Nuevo estado: ' . str_replace('_', ' ', $nuevo),
            ['codigo' => Hashid::codificar($id, 'ticket')],
            $nuevo === 'resuelto' ? 'exito' : 'info',
            $this->url('tickets/' . Hashid::codificar($id, 'ticket'))
        );

        Respuesta::exito(['recargar' => true], 'Estado actualizado.');
    }
}
