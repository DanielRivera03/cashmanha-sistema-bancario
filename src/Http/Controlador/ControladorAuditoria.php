<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Http\Paginador;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Bitácora de auditoría y accesos.
 *
 * La bitácora está encadenada por hash: cada registro incluye el hash
 * del anterior. Verificar la cadena detecta cualquier alteración o
 * borrado, incluso hecho directamente sobre la base de datos.
 */
final class ControladorAuditoria extends ControladorBase
{
    public function listar(array $parametros = []): void
    {
        Autorizacion::exigir('auditoria.ver');

        $accion    = trim($this->get('a'));
        $resultado = trim($this->get('r'));

        $where = '1=1';
        $args  = [];

        if ($accion !== '') {
            $where .= ' AND a.accion LIKE ?';
            $args[] = $accion . '%';
        }
        if (in_array($resultado, ['exito','fallo','denegado'], true)) {
            $where .= ' AND a.resultado = ?';
            $args[] = $resultado;
        }

        $total = (int) BaseDatos::unValor("SELECT COUNT(*) FROM auditoria a WHERE {$where}", $args);

        // El Paginador recorta la página pedida al rango que existe: antes,
        // un `?p=999` devolvía una tabla vacía en vez de la última página.
        $pag    = new Paginador($total, $this->get('p', '1'), $this->get('pp', '50'));
        $porPag = $pag->porPagina;
        $offset = $pag->desplazamiento;

        $filas  = BaseDatos::todas(
            "SELECT a.id, a.accion, a.entidad, a.entidad_id, a.resultado,
                    a.detalle_json, a.ip, a.user_agent, a.creado_en,
                    u.usuario, CONCAT(u.nombres,' ',u.apellidos) AS nombre
               FROM auditoria a
          LEFT JOIN usuarios u ON u.id = a.usuario_id
              WHERE {$where}
              ORDER BY a.id DESC
              LIMIT {$porPag} OFFSET {$offset}",
            $args
        );

        foreach ($filas as &$f) {
            $f['ip_txt'] = ClienteIp::desdeBinaria((string) $f['ip']);
            $f['detalle'] = $f['detalle_json'] !== null
                ? json_decode((string) $f['detalle_json'], true) : null;
            unset($f['ip'], $f['detalle_json']);
        }

        $resumen = BaseDatos::unaFila(
            'SELECT COUNT(*) total,
                    SUM(resultado = "exito")    exitos,
                    SUM(resultado = "fallo")    fallos,
                    SUM(resultado = "denegado") denegados
               FROM auditoria
              WHERE creado_en > DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );

        // Acciones más frecuentes, para el filtro rápido.
        $acciones = BaseDatos::todas(
            'SELECT SUBSTRING_INDEX(accion, ".", 1) AS modulo, COUNT(*) n
               FROM auditoria GROUP BY modulo ORDER BY n DESC LIMIT 12'
        );

        $this->render('auditoria/listar', [
            'titulo'     => 'Bitácora de auditoría · CashMan H.A.',
            'encabezado' => 'Bitácora de auditoría',
            'filas'      => $filas,
            'resumen'    => $resumen ?? [],
            'acciones'   => $acciones,
            'total'      => $total,
            'pagina'     => $pag->pagina,
            'paginas'    => $pag->paginas,
            'paginacion' => $pag->aArreglo(),
            'fAccion'    => $accion,
            'fResultado' => $resultado,
        ]);
    }

    /**
     * Verifica la cadena de hashes.
     *
     * Si alguien editó o borró una fila directamente en la base, el
     * hash deja de encajar y esta comprobación lo señala.
     */
    public function integridad(array $parametros = []): void
    {
        Autorizacion::exigir('auditoria.ver');

        $r = Auditoria::verificarCadena(0, 20000);

        $totales = BaseDatos::unaFila(
            'SELECT COUNT(*) registros, MIN(creado_en) desde, MAX(creado_en) hasta FROM auditoria'
        );

        Auditoria::registrar('auditoria.integridad_verificada', Auditoria::EXITO,
            Sesion::usuarioId(), 'auditoria', '', ['valida' => $r['valida']]);

        if ($this->esperaJson()) {
            Respuesta::exito($r);
        }

        $this->render('auditoria/integridad', [
            'titulo'     => 'Integridad de la bitácora · CashMan H.A.',
            'encabezado' => 'Verificación de integridad',
            'resultado'  => $r,
            'totales'    => $totales ?? [],
        ]);
    }

    public function accesos(array $parametros = []): void
    {
        Autorizacion::exigir('acceso.ver_bitacora');

        $filas = BaseDatos::todas(
            'SELECT a.ip, a.user_agent, a.navegador, a.sistema, a.dispositivo,
                    a.exitoso, a.creado_en,
                    u.usuario, CONCAT(u.nombres," ",u.apellidos) AS nombre, r.nombre AS rol
               FROM accesos a
               JOIN usuarios u ON u.id = a.usuario_id
               JOIN roles    r ON r.id = u.rol_id
              ORDER BY a.id DESC LIMIT 300'
        );

        foreach ($filas as &$f) {
            $f['ip_txt'] = ClienteIp::desdeBinaria((string) $f['ip']);
            unset($f['ip']);
        }

        $sesiones = BaseDatos::todas(
            'SELECT s.ip, s.user_agent, s.creada_en, s.ultima_actividad, s.expira_en,
                    s.revocada_en, s.motivo_revocacion,
                    u.usuario, CONCAT(u.nombres," ",u.apellidos) AS nombre
               FROM sesiones s JOIN usuarios u ON u.id = s.usuario_id
              ORDER BY s.ultima_actividad DESC LIMIT 60'
        );
        foreach ($sesiones as &$s) {
            $s['ip_txt'] = ClienteIp::desdeBinaria((string) $s['ip']);
            $s['activa'] = $s['revocada_en'] === null
                && new \DateTimeImmutable((string) $s['expira_en']) > new \DateTimeImmutable();
            unset($s['ip']);
        }

        $this->render('auditoria/accesos', [
            'titulo'     => 'Accesos al sistema · CashMan H.A.',
            'encabezado' => 'Accesos al sistema',
            'accesos'    => $filas,
            'sesiones'   => $sesiones,
        ]);
    }
}
