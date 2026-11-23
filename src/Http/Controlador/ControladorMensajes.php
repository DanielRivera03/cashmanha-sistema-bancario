<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioDestinatarios;

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
 * Mensajería interna.
 *
 * En v1 cualquier usuario podía leer un mensaje cambiando el id en la
 * URL: no se comprobaba que fuera el destinatario. Aquí toda lectura
 * verifica que quien pide sea remitente o destinatario.
 */
final class ControladorMensajes extends ControladorBase
{
    public function bandeja(array $parametros = []): void
    {
        Autorizacion::exigir('mensaje.ver');
        $id = Sesion::exigirAutenticado();

        $mensajes = BaseDatos::todas(
            'SELECT m.id, m.uuid, m.asunto, LEFT(m.cuerpo,180) AS extracto,
                    m.leido_en, m.creado_en,
                    CONCAT(u.nombres," ",u.apellidos) AS remitente, u.usuario AS remitente_usuario
               FROM mensajes m JOIN usuarios u ON u.id = m.remitente_id
              WHERE m.destinatario_id = ? AND m.archivado_dest = 0
              ORDER BY m.id DESC LIMIT 100',
            [$id]
        );

        foreach ($mensajes as &$m) {
            $m['codigo'] = Hashid::codificar((int) $m['id'], 'mensaje');
            unset($m['id']);
        }

        $this->render('mensajes/bandeja', [
            'titulo'     => 'Bandeja de entrada · CashMan H.A.',
            'encabezado' => 'Mensajería',
            'mensajes'   => $mensajes,
            'vista'      => 'entrada',
        ]);
    }

    public function enviados(array $parametros = []): void
    {
        Autorizacion::exigir('mensaje.ver');
        $id = Sesion::exigirAutenticado();

        $mensajes = BaseDatos::todas(
            'SELECT m.id, m.uuid, m.asunto, LEFT(m.cuerpo,180) AS extracto,
                    m.leido_en, m.creado_en,
                    CONCAT(u.nombres," ",u.apellidos) AS remitente, u.usuario AS remitente_usuario
               FROM mensajes m JOIN usuarios u ON u.id = m.destinatario_id
              WHERE m.remitente_id = ? AND m.archivado_rem = 0
              ORDER BY m.id DESC LIMIT 100',
            [$id]
        );

        foreach ($mensajes as &$m) {
            $m['codigo'] = Hashid::codificar((int) $m['id'], 'mensaje');
            unset($m['id']);
        }

        $this->render('mensajes/bandeja', [
            'titulo'     => 'Enviados · CashMan H.A.',
            'encabezado' => 'Mensajes enviados',
            'mensajes'   => $mensajes,
            'vista'      => 'enviados',
        ]);
    }

    public function ver(array $parametros = []): void
    {
        Autorizacion::exigir('mensaje.ver');
        $usuarioId = Sesion::exigirAutenticado();

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'mensaje');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Mensaje no encontrado.');
        }

        $m = BaseDatos::unaFila(
            'SELECT m.*,
                    CONCAT(r.nombres," ",r.apellidos) AS remitente, r.usuario AS remitente_usuario,
                    CONCAT(d.nombres," ",d.apellidos) AS destinatario, d.usuario AS destinatario_usuario
               FROM mensajes m
               JOIN usuarios r ON r.id = m.remitente_id
               JOIN usuarios d ON d.id = m.destinatario_id
              WHERE m.id = ?',
            [$id]
        );

        // Solo remitente o destinatario. En v1 esto no se comprobaba.
        if ($m === null
            || ((int) $m['remitente_id'] !== $usuarioId && (int) $m['destinatario_id'] !== $usuarioId)) {
            Auditoria::registrar('mensaje.acceso_denegado', Auditoria::DENEGADO, $usuarioId, 'mensaje', (string) $id);
            throw new RecursoNoEncontrado('Mensaje no encontrado.');
        }

        if ((int) $m['destinatario_id'] === $usuarioId && $m['leido_en'] === null) {
            BaseDatos::ejecutar('UPDATE mensajes SET leido_en = NOW() WHERE id = ?', [$id]);
            $m['leido_en'] = date('Y-m-d H:i:s');
        }

        $m['codigo'] = Hashid::codificar($id, 'mensaje');

        $this->render('mensajes/ver', [
            'titulo'     => P_titulo($m['asunto']),
            'encabezado' => 'Mensaje',
            'mensaje'    => $m,
            'esDestino'  => (int) $m['destinatario_id'] === $usuarioId,
            'csrf'       => Csrf::token('mensaje'),
        ]);
    }

    public function formulario(array $parametros = []): void
    {
        Autorizacion::exigir('mensaje.enviar');
        $usuarioId = Sesion::exigirAutenticado();

        /* A quién puede escribir.
         *
         * Al cliente se le ofrecía CUALQUIER empleado activo: nombre y
         * apellidos de administradores, presidencia, gerencia y
         * cajeros, en un desplegable. Con dos empleados de prueba
         * pasaba desapercibido; al poblar la red con diez agencias se
         * convirtió en lo que siempre fue, el directorio completo de
         * la plantilla a disposición de cualquiera con una cuenta.
         *
         * Un cliente se dirige a un DEPARTAMENTO. La excepción es su
         * banquero, que sí es una persona concreta y a quien se le
         * asigna justamente para eso.
         *
         * Entre el personal no cambia nada: trabajan juntos y necesitan
         * escribirle a alguien en particular. */
        $quienes = (new ServicioDestinatarios())->para($usuarioId);

        $esCliente     = $quienes['es_cliente'];
        $destinatarios = $quienes['opciones'];

        $responde = null;
        $codigoResp = $this->get('responde');
        if ($codigoResp !== '') {
            $rid = $this->idDesde($codigoResp, 'mensaje');
            if ($rid > 0) {
                $orig = BaseDatos::unaFila(
                    'SELECT m.id, m.asunto, m.remitente_id, m.destinatario_id,
                            CONCAT(u.nombres," ",u.apellidos) AS remitente
                       FROM mensajes m JOIN usuarios u ON u.id = m.remitente_id
                      WHERE m.id = ? AND (m.remitente_id = ? OR m.destinatario_id = ?)',
                    [$rid, $usuarioId, $usuarioId]
                );
                if ($orig !== null) {
                    $responde = $orig;
                    $responde['codigo_destino'] = Hashid::codificar((int) $orig['remitente_id'], 'usuario');
                }
            }
        }

        $this->render('mensajes/nuevo', [
            'titulo'        => 'Redactar mensaje · CashMan H.A.',
            'encabezado'    => 'Redactar mensaje',
            'destinatarios' => $destinatarios,
            'es_cliente'    => $esCliente,
            'responde'      => $responde,
            'csrf'          => Csrf::token('mensaje'),
        ]);
    }

    public function enviar(array $parametros = []): void
    {
        Autorizacion::exigir('mensaje.enviar');
        $usuarioId = Sesion::exigirAutenticado();

        /* Lo elegido se resuelve en una persona, y se comprueba contra
           lo que a ESTE remitente se le habría ofrecido. Validar lo que
           llega contra la lista de quien lo envía, y no simplemente
           decodificar el identificador, es lo que impide escribirle a
           cualquiera cambiando el valor del formulario. */
        $destId = (new ServicioDestinatarios())->resolver(
            $usuarioId,
            (string) $this->post('destinatario')
        );
        $asunto = mb_substr($this->post('asunto'), 0, 160);
        $cuerpo = trim((string) ($_POST['cuerpo'] ?? ''));

        if ($destId === 0) {
            // O no eligió, o eligió algo que no se le ofreció.
            Respuesta::error('Elija a quién dirige su mensaje.', 422);
        }
        if ($asunto === '' || $cuerpo === '') {
            Respuesta::error('Complete el asunto y el mensaje.', 422);
        }
        if (mb_strlen($cuerpo) > 5000) {
            Respuesta::error('El mensaje no puede superar los 5.000 caracteres.', 422);
        }
        if ($destId === $usuarioId) {
            Respuesta::error('No puede enviarte un mensaje a ti mismo.', 422);
        }

        $dest = BaseDatos::unaFila(
            'SELECT id, rol_id, nombres FROM usuarios WHERE id = ? AND estado = "activo"',
            [$destId]
        );
        if ($dest === null) {
            Respuesta::error('El destinatario no existe o no está activo.', 422);
        }

        // Un cliente no puede escribir a otro cliente.
        if (Sesion::rolId() === 5 && (int) $dest['rol_id'] === 5) {
            Respuesta::error('Solo puede escribir al personal de la institución.', 403);
        }

        $respondeA = null;
        $codResp = $this->post('responde_a');
        if ($codResp !== '') {
            $rid = $this->idDesde($codResp, 'mensaje');
            if ($rid > 0) {
                $ok = BaseDatos::unValor(
                    'SELECT 1 FROM mensajes WHERE id = ? AND (remitente_id = ? OR destinatario_id = ?)',
                    [$rid, $usuarioId, $usuarioId]
                );
                if ($ok !== null) { $respondeA = $rid; }
            }
        }

        BaseDatos::ejecutar(
            'INSERT INTO mensajes (uuid, remitente_id, destinatario_id, asunto, cuerpo, responde_a)
             VALUES (UUID(),?,?,?,?,?)',
            [$usuarioId, $destId, $asunto, $cuerpo, $respondeA]
        );
        $nuevo = BaseDatos::ultimoId();

        Auditoria::registrar('mensaje.enviado', Auditoria::EXITO, $usuarioId, 'mensaje', (string) $nuevo);

        (new ServicioNotificacion())->crear(
            $destId, 'mensaje.nuevo', 'Mensaje nuevo',
            'Tiene un mensaje nuevo: ' . $asunto,
            ['codigo' => Hashid::codificar($nuevo, 'mensaje')],
            'info',
            $this->url('mensajes/' . Hashid::codificar($nuevo, 'mensaje'))
        );

        Respuesta::exito(
            ['redirigir' => $this->url('mensajes')],
            'Mensaje enviado a ' . $dest['nombres'] . '.'
        );
    }

    public function archivar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'mensaje');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Mensaje no encontrado.');
        }

        // El WHERE con el usuario hace la verificación de propiedad.
        $filas = BaseDatos::ejecutar(
            'UPDATE mensajes
                SET archivado_dest = IF(destinatario_id = ?, 1, archivado_dest),
                    archivado_rem  = IF(remitente_id   = ?, 1, archivado_rem)
              WHERE id = ? AND (destinatario_id = ? OR remitente_id = ?)',
            [$usuarioId, $usuarioId, $id, $usuarioId, $usuarioId]
        );

        if ($filas === 0) {
            throw new RecursoNoEncontrado('Mensaje no encontrado.');
        }

        Respuesta::exito(['redirigir' => $this->url('mensajes')], 'Mensaje archivado.');
    }
}

/** Título seguro para la pestaña del navegador. */
function P_titulo(string $asunto): string
{
    return mb_substr($asunto, 0, 60) . ' · CashMan H.A.';
}
