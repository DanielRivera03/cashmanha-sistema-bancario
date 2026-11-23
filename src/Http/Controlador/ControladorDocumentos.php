<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Infraestructura\Almacenamiento\ArchivoInvalido;
use CashMan\Infraestructura\Almacenamiento\ServicioArchivos;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\LimitadorTasa;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Documentos del expediente.
 *
 * Los archivos viven en storage/uploads/, fuera del webroot. Se sirven
 * por este controlador tras verificar que quien los pide es el titular
 * o tiene permiso sobre expedientes ajenos, y cada descarga se
 * registra: un documento de identidad es dato sensible y conviene
 * saber quién lo consultó.
 */
final class ControladorDocumentos extends ControladorBase
{
    private const TIPOS = [
        'dui_frontal'         => 'DUI (frente)',
        'dui_reverso'         => 'DUI (reverso)',
        'nit'                 => 'NIT',
        'firma'               => 'Firma',
        'comprobante_ingreso' => 'Comprobante de ingresos',
        'recibo_servicio'     => 'Recibo de servicio',
        'foto_perfil'         => 'Foto de perfil',
        'otro'                => 'Otro documento',
    ];

    public function listar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        // Si viene un cliente en la URL, se requiere permiso sobre
        // expedientes ajenos; si no, son los documentos propios.
        $codigo = $this->get('c');
        $titular = $usuarioId;

        if ($codigo !== '') {
            Autorizacion::exigir('documento.ver_todos');
            $titular = $this->idDesde($codigo, 'usuario');
            if ($titular === 0) {
                throw new RecursoNoEncontrado('Cliente no encontrado.');
            }
        } else {
            Autorizacion::exigir('documento.ver_propio');
        }

        $docs = BaseDatos::todas(
            'SELECT * FROM v_expediente_documentos WHERE usuario_id = ? ORDER BY tipo, id DESC',
            [$titular]
        );

        foreach ($docs as &$d) {
            $d['codigo']      = Hashid::codificar((int) $d['id'], 'documento');
            $d['tipo_nombre'] = self::TIPOS[$d['tipo']] ?? $d['tipo'];
            $d['es_imagen']   = str_starts_with((string) $d['mime'], 'image/');
            unset($d['id'], $d['nombre_archivo'], $d['usuario_id']);
        }

        $persona = BaseDatos::unaFila(
            'SELECT CONCAT(nombres," ",apellidos) AS nombre, usuario FROM usuarios WHERE id = ?',
            [$titular]
        );

        $this->render('documentos/listar', [
            'titulo'      => 'Documentos · CashMan H.A.',
            'encabezado'  => $titular === $usuarioId
                ? 'Mis documentos'
                : 'Documentos de ' . ($persona['nombre'] ?? ''),
            'documentos'  => $docs,
            'tipos'       => self::TIPOS,
            'titular'     => $persona,
            'codigoTitular' => $titular !== $usuarioId ? Hashid::codificar($titular, 'usuario') : '',
            'puedeSubir'  => Autorizacion::puede('documento.subir'),
            'puedeVerificar' => Autorizacion::puede('documento.verificar'),
            'csrf'        => Csrf::token('documento'),
        ]);
    }

    public function subir(array $parametros = []): void
    {
        Autorizacion::exigir('documento.subir');
        $usuarioId = Sesion::exigirAutenticado();

        // Sin límite, alguien podría llenar el disco con subidas.
        (new LimitadorTasa(20, 3600, 1800))->exigir('subida:' . $usuarioId);

        // El titular por defecto es uno mismo; subir al expediente de
        // otro requiere permiso explícito.
        $titular = $usuarioId;
        $codigo  = $this->post('cliente');

        if ($codigo !== '') {
            Autorizacion::exigir('documento.ver_todos');
            $titular = $this->idDesde($codigo, 'usuario');
            if ($titular === 0) {
                Respuesta::error('Cliente no válido.', 422);
            }
        }

        $tipo = $this->post('tipo', 'otro');
        if (!isset(self::TIPOS[$tipo])) {
            Respuesta::error('Tipo de documento no válido.', 422);
        }

        if (!isset($_FILES['archivo'])) {
            Respuesta::error('No se recibió ningún archivo.', 422);
        }

        try {
            $r = (new ServicioArchivos())->guardar($_FILES['archivo'], 'documentos');
        } catch (ArchivoInvalido $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        // Si ya existe un documento idéntico, no duplicar el registro.
        $duplicado = BaseDatos::unValor(
            'SELECT id FROM documentos WHERE usuario_id = ? AND hash_sha256 = ?',
            [$titular, $r['hash']]
        );

        if ($duplicado !== null) {
            (new ServicioArchivos())->eliminar($r['nombre'], 'documentos');
            Respuesta::error('Este documento ya fue subido anteriormente.', 422);
        }

        BaseDatos::ejecutar(
            'INSERT INTO documentos
               (uuid, usuario_id, subido_por, tipo, nombre_archivo, mime,
                tamano_bytes, hash_sha256, descripcion)
             VALUES (UUID(),?,?,?,?,?,?,?,?)',
            [
                $titular, $usuarioId, $tipo, $r['nombre'], $r['tipo'],
                $r['tamano'], $r['hash'],
                mb_substr($this->post('descripcion'), 0, 255),
            ]
        );
        $id = BaseDatos::ultimoId();

        Auditoria::registrar('documento.registrado', Auditoria::EXITO, $usuarioId,
            'documento', (string) $id, ['titular' => $titular, 'tipo' => $tipo]);

        Respuesta::exito([
            'codigo' => Hashid::codificar($id, 'documento'),
            'tipo'   => self::TIPOS[$tipo],
            'tamano' => $r['tamano'],
        ], 'Documento subido correctamente.');
    }

    /** Entrega el archivo. Aquí está la verificación que v1 no hacía. */
    public function ver(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'documento');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Documento no encontrado.');
        }

        $d = BaseDatos::unaFila('SELECT * FROM documentos WHERE id = ?', [$id]);

        if ($d === null) {
            throw new RecursoNoEncontrado('Documento no encontrado.');
        }

        // Titular o permiso explícito. En v1 bastaba conocer la URL.
        if ((int) $d['usuario_id'] !== $usuarioId && !Autorizacion::puede('documento.ver_todos')) {
            Auditoria::registrar('documento.acceso_denegado', Auditoria::DENEGADO,
                $usuarioId, 'documento', (string) $id);
            throw new RecursoNoEncontrado('Documento no encontrado.');
        }

        $archivos = new ServicioArchivos();
        $ruta = $archivos->rutaDe((string) $d['nombre_archivo'], 'documentos');

        if ($ruta === null) {
            throw new RecursoNoEncontrado('El archivo ya no está disponible.');
        }

        // Un documento de identidad es dato sensible: queda constancia
        // de cada consulta, incluso del propio titular.
        BaseDatos::ejecutar(
            'INSERT INTO documento_accesos (documento_id, usuario_id, ip) VALUES (?,?,?)',
            [$id, $usuarioId, ClienteIp::binaria()]
        );

        $mime = $archivos->tipoMime($ruta);

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            // inline para poder previsualizar; nunca como text/html,
            // que permitiría ejecutar script en el origen del sitio.
            header('Content-Disposition: inline; filename="documento.'
                 . pathinfo($ruta, PATHINFO_EXTENSION) . '"');
            header('Content-Length: ' . (string) filesize($ruta));
            header('X-Content-Type-Options: nosniff');
            header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; object-src \'none\'');
            header('Cache-Control: private, no-store, max-age=0');

            /* El acuse para la pantalla que lo pidió: es lo que apaga
               su indicador de espera. No se usa `Respuesta::ver` porque
               este archivo necesita además su propia política de
               contenido —lo subió un cliente y no debe ejecutar nada. */
            $ficha = (string) ($_GET['listo'] ?? '');

            if ($ficha !== '' && preg_match('/^[A-Za-z0-9]{6,32}$/', $ficha) === 1) {
                setcookie('cm_doc_' . $ficha, '1', [
                    'expires'  => time() + 120, 'path' => '/',
                    'secure'   => !empty($_SERVER['HTTPS']),
                    'httponly' => false, 'samesite' => 'Lax',
                ]);
            }
        }

        readfile($ruta);
        exit;
    }

    public function verificar(array $parametros = []): void
    {
        Autorizacion::exigir('documento.verificar');
        $usuarioId = Sesion::exigirAutenticado();

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'documento');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Documento no encontrado.');
        }

        $d = BaseDatos::unaFila('SELECT id, verificado, subido_por FROM documentos WHERE id = ?', [$id]);
        if ($d === null) {
            throw new RecursoNoEncontrado('Documento no encontrado.');
        }

        $nuevo = (int) $d['verificado'] === 1 ? 0 : 1;

        BaseDatos::ejecutar(
            'UPDATE documentos
                SET verificado = ?, verificado_por = ?, verificado_en = IF(? = 1, NOW(), NULL)
              WHERE id = ?',
            [$nuevo, $nuevo === 1 ? $usuarioId : null, $nuevo, $id]
        );

        Auditoria::registrar(
            $nuevo === 1 ? 'documento.verificado' : 'documento.verificacion_retirada',
            Auditoria::EXITO, $usuarioId, 'documento', (string) $id
        );

        Respuesta::exito(['verificado' => $nuevo === 1],
            $nuevo === 1 ? 'Documento verificado.' : 'Verificación retirada.');
    }

    public function eliminar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'documento');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Documento no encontrado.');
        }

        $d = BaseDatos::unaFila('SELECT * FROM documentos WHERE id = ?', [$id]);
        if ($d === null) {
            throw new RecursoNoEncontrado('Documento no encontrado.');
        }

        // El titular puede borrar lo suyo mientras no esté verificado;
        // un documento ya verificado solo lo quita quien tiene permiso.
        $esMio = (int) $d['usuario_id'] === $usuarioId;

        if (!$esMio || (int) $d['verificado'] === 1) {
            Autorizacion::exigir('documento.eliminar');
        }

        BaseDatos::ejecutar('DELETE FROM documentos WHERE id = ?', [$id]);
        (new ServicioArchivos())->eliminar((string) $d['nombre_archivo'], 'documentos');

        Auditoria::registrar('documento.eliminado', Auditoria::EXITO, $usuarioId,
            'documento', (string) $id, ['tipo' => $d['tipo']]);

        Respuesta::exito(null, 'Documento eliminado.');
    }
}
