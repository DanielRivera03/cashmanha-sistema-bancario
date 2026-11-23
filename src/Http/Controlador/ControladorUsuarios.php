<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Dominio\Cliente\Documento;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\ServicioPassword;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Gestión de usuarios y roles.
 */
final class ControladorUsuarios extends ControladorBase
{
    public function listar(array $parametros = []): void
    {
        Autorizacion::exigir('usuario.ver_todos');

        $q      = trim($this->get('q'));
        $rolId  = (int) $this->get('r', '0');
        $where  = '1=1';
        $args   = [];

        if ($q !== '') {
            $where .= ' AND (CONCAT(u.nombres," ",u.apellidos) LIKE ? OR u.correo LIKE ? OR u.usuario LIKE ?)';
            $like = '%' . $q . '%';
            $args = [$like, $like, $like];
        }
        if ($rolId > 0) {
            $where .= ' AND u.rol_id = ?';
            $args[] = $rolId;
        }

        $usuarios = BaseDatos::todas(
            "SELECT u.id, u.usuario, u.correo, u.nombres, u.apellidos, u.estado,
                    u.rol_id, u.ultimo_acceso_en, u.intentos_fallidos, u.bloqueado_hasta,
                    u.requiere_cambio_password, u.creado_en, r.nombre AS rol
               FROM usuarios u JOIN roles r ON r.id = u.rol_id
              WHERE {$where}
              ORDER BY u.rol_id, u.nombres
              LIMIT 300",
            $args
        );

        foreach ($usuarios as &$u) {
            $u['codigo']   = Hashid::codificar((int) $u['id'], 'usuario');
            $u['bloqueado'] = $u['bloqueado_hasta'] !== null
                && new \DateTimeImmutable((string) $u['bloqueado_hasta']) > new \DateTimeImmutable();
            unset($u['id']);
        }

        $roles = BaseDatos::todas(
            'SELECT r.id, r.nombre, r.descripcion, r.nivel,
                    (SELECT COUNT(*) FROM usuarios WHERE rol_id = r.id) AS usuarios,
                    (SELECT COUNT(*) FROM rol_permiso WHERE rol_id = r.id) AS permisos
               FROM roles r ORDER BY r.id'
        );

        $this->render('usuarios/listar', [
            'titulo'      => 'Usuarios · CashMan H.A.',
            'encabezado'  => 'Usuarios del sistema',
            'usuarios'    => $usuarios,
            'roles'       => $roles,
            'busqueda'    => $q,
            'rolFiltro'   => $rolId,
            'puedeCrear'  => Autorizacion::puede('usuario.crear'),
            'puedeEditar' => Autorizacion::puede('usuario.modificar'),
            'csrf'        => Csrf::token('usuario'),
        ]);
    }

    public function formulario(array $parametros = []): void
    {
        Autorizacion::exigir('usuario.crear');

        $roles = BaseDatos::todas('SELECT id, nombre, descripcion FROM roles ORDER BY id');

        $this->render('usuarios/nuevo', [
            'titulo'     => 'Registrar usuario · CashMan H.A.',
            'encabezado' => 'Registrar usuario',
            'roles'      => $roles,
            'csrf'       => Csrf::token('usuario'),
        ]);
    }

    public function crear(array $parametros = []): void
    {
        Autorizacion::exigir('usuario.crear');
        $actorId = Sesion::exigirAutenticado();

        $nombres   = mb_substr($this->post('nombres'), 0, 120);
        $apellidos = mb_substr($this->post('apellidos'), 0, 120);
        $correo    = mb_strtolower(trim($this->post('correo')));
        $rolId     = (int) $this->post('rol', '5');

        if ($nombres === '' || $apellidos === '') {
            Respuesta::error('Nombres y apellidos son obligatorios.', 422);
        }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            Respuesta::error('Correo electrónico inválido.', 422);
        }

        $rol = BaseDatos::unaFila('SELECT id, nivel, nombre FROM roles WHERE id = ?', [$rolId]);
        if ($rol === null) {
            Respuesta::error('Rol no válido.', 422);
        }

        // Nadie puede crear un usuario de nivel superior al suyo: evita
        // que alguien se fabrique un administrador desde un rol menor.
        $miNivel = (int) BaseDatos::unValor(
            'SELECT r.nivel FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?',
            [$actorId]
        );
        if ((int) $rol['nivel'] > $miNivel) {
            Auditoria::registrar('usuario.escalada_denegada', Auditoria::DENEGADO,
                $actorId, 'rol', (string) $rolId);
            Respuesta::error('No puede crear usuarios con un rol superior al suyo.', 403);
        }

        // Nombre de usuario único a partir del nombre.
        $base = preg_replace('/[^a-z0-9]/', '',
            mb_strtolower($this->sinAcentos(explode(' ', $nombres)[0])))
            . '.' . preg_replace('/[^a-z0-9]/', '',
            mb_strtolower($this->sinAcentos(explode(' ', $apellidos)[0])));
        $base = mb_substr($base ?: 'usuario', 0, 50);

        $usuario = $base;
        $n = 1;
        while (BaseDatos::unValor('SELECT 1 FROM usuarios WHERE usuario = ?', [$usuario]) !== null) {
            $usuario = $base . (++$n);
        }

        if (BaseDatos::unValor('SELECT 1 FROM usuarios WHERE correo = ?', [$correo]) !== null) {
            Respuesta::error('Ya existe un usuario con ese correo.', 422);
        }

        // Contraseña temporal fuerte, generada al azar.
        $temporal = $this->generarTemporal();
        $svc      = new ServicioPassword();

        /* El documento, con su tipo y normalizado.
         *
         * Si viene mal escrito se para aquí: en el alta hay un empleado
         * con el documento delante, y es el momento de corregirlo. Más
         * adelante, cuando el cliente venga a ventanilla y no lo
         * encuentren por su número, ya no hay quien lo arregle. */
        $docAlta = null;

        if (trim((string) $this->post('documento')) !== '') {
            try {
                $docAlta = Documento::de(
                    (string) ($this->post('tipo_documento') ?: 'dui'),
                    (string) $this->post('documento')
                );
            } catch (\InvalidArgumentException $e) {
                Respuesta::error($e->getMessage(), 422);
            }
        }

        BaseDatos::ejecutar(
            'INSERT INTO usuarios
               (uuid, usuario, correo, nombres, apellidos, password_hash,
                password_algoritmo, password_actualizado_en, requiere_cambio_password,
                rol_id, registrado_por, estado, telefono, documento_identidad,
                tipo_documento)
             VALUES (UUID(),?,?,?,?,?, "argon2id", NOW(), 1, ?,?, "activo", ?,?,?)',
            [
                $usuario, $correo, $nombres, $apellidos, $svc->hashear($temporal),
                $rolId, $actorId,
                $this->post('telefono') ?: null,
                $docAlta?->crudo(),
                $docAlta?->tipo(),
            ]
        );
        $nuevoId = BaseDatos::ultimoId();

        Auditoria::registrar('usuario.creado', Auditoria::EXITO, $actorId,
            'usuario', (string) $nuevoId, ['usuario' => $usuario, 'rol' => $rol['nombre']]);

        // Correo de bienvenida; si falla, la contraseña se muestra en
        // pantalla para que el operador pueda entregarla.
        $enviado = (new Correo())->enviar(
            $correo,
            'Su cuenta en CashMan H.A. está lista',
            PlantillaCorreo::bienvenida($nombres, $usuario, $this->url('entrar')),
            '',
            $nombres
        );

        Respuesta::exito([
            'codigo'   => Hashid::codificar($nuevoId, 'usuario'),
            'usuario'  => $usuario,
            'temporal' => $temporal,
            'correo_enviado' => $enviado,
        ], 'Usuario ' . $usuario . ' creado.');
    }

    public function cambiarEstado(array $parametros = []): void
    {
        Autorizacion::exigir('usuario.bloquear');
        $actorId = Sesion::exigirAutenticado();

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'usuario');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Usuario no encontrado.');
        }

        // No puede bloquearte a ti mismo: dejarías el sistema sin acceso.
        if ($id === $actorId) {
            Respuesta::error('No puede cambiar el estado de tu propia cuenta.', 422);
        }

        $u = BaseDatos::unaFila(
            'SELECT u.id, u.usuario, u.estado, r.nivel
               FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?',
            [$id]
        );
        if ($u === null) {
            throw new RecursoNoEncontrado('Usuario no encontrado.');
        }

        $miNivel = (int) BaseDatos::unValor(
            'SELECT r.nivel FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?',
            [$actorId]
        );
        if ((int) $u['nivel'] > $miNivel) {
            Respuesta::error('No puede modificar a un usuario de rol superior.', 403);
        }

        $nuevo = $this->post('estado');
        if (!in_array($nuevo, ['activo', 'inactivo', 'bloqueado'], true)) {
            Respuesta::error('Estado no válido.', 422);
        }

        // El último administrador activo no se puede desactivar.
        if ($nuevo !== 'activo') {
            $admins = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM usuarios WHERE rol_id = 1 AND estado = "activo" AND id <> ?',
                [$id]
            );
            $esAdmin = (int) BaseDatos::unValor('SELECT rol_id FROM usuarios WHERE id = ?', [$id]) === 1;
            if ($esAdmin && $admins === 0) {
                Respuesta::error('No puede desactivar al último administrador activo.', 422);
            }
        }

        BaseDatos::ejecutar(
            'UPDATE usuarios SET estado = ?, intentos_fallidos = 0,
                    bloqueado_hasta = IF(? = "activo", NULL, bloqueado_hasta)
              WHERE id = ?',
            [$nuevo, $nuevo, $id]
        );

        // Al bloquear o desactivar, se cierran sus sesiones abiertas.
        if ($nuevo !== 'activo') {
            Sesion::revocarTodas($id, 'estado_' . $nuevo);
        }

        Auditoria::registrar('usuario.estado_cambiado', Auditoria::EXITO, $actorId,
            'usuario', (string) $id, ['de' => $u['estado'], 'a' => $nuevo]);

        Respuesta::exito(['estado' => $nuevo], 'Usuario ' . $u['usuario'] . ' ahora está ' . $nuevo . '.');
    }

    public function restablecerClave(array $parametros = []): void
    {
        Autorizacion::exigir('usuario.modificar');
        $actorId = Sesion::exigirAutenticado();

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'usuario');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Usuario no encontrado.');
        }

        $u = BaseDatos::unaFila(
            'SELECT u.id, u.usuario, u.correo, u.nombres, r.nivel
               FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?',
            [$id]
        );
        if ($u === null) {
            throw new RecursoNoEncontrado('Usuario no encontrado.');
        }

        $miNivel = (int) BaseDatos::unValor(
            'SELECT r.nivel FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?',
            [$actorId]
        );
        if ((int) $u['nivel'] > $miNivel) {
            Respuesta::error('No puede restablecer la clave de un usuario de rol superior.', 403);
        }

        $temporal = $this->generarTemporal();

        BaseDatos::ejecutar(
            'UPDATE usuarios
                SET password_hash = ?, password_algoritmo = "argon2id",
                    password_actualizado_en = NOW(), requiere_cambio_password = 1,
                    intentos_fallidos = 0, bloqueado_hasta = NULL
              WHERE id = ?',
            [(new ServicioPassword())->hashear($temporal), $id]
        );

        // Se cierran todas sus sesiones: si la clave se restablece es
        // porque pudo estar comprometida.
        Sesion::revocarTodas($id, 'clave_restablecida');

        Auditoria::registrar('usuario.clave_restablecida', Auditoria::EXITO, $actorId,
            'usuario', (string) $id);

        $enviado = (new Correo())->enviar(
            (string) $u['correo'],
            'Su contraseña fue restablecida',
            PlantillaCorreo::base(
                'Contraseña restablecida',
                'Hola ' . $u['nombres'] . ',',
                '<p>Un administrador restableció su contraseña. Usa la clave temporal '
                . 'para entrar; se te pedirá cambiarla de inmediato.</p>',
                [['etiqueta' => 'Usuario', 'valor' => (string) $u['usuario']]],
                $temporal,
                'Ingresar', $this->url('entrar'),
                'Si no solicitaste este cambio, comuníquese de inmediato con soporte.'
            ),
            '',
            (string) $u['nombres']
        );

        Respuesta::exito(
            ['temporal' => $temporal, 'correo_enviado' => $enviado],
            'Contraseña restablecida para ' . $u['usuario'] . '.'
        );
    }

    /** Matriz de roles y permisos. */
    public function roles(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('sistema.configurar');

        // El nivel del actor marca qué roles puede tocar.
        $miNivel = (int) BaseDatos::unValor(
            'SELECT r.nivel FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?',
            [$actorId]
        );

        $roles = BaseDatos::todas(
            'SELECT r.*, (SELECT COUNT(*) FROM usuarios u WHERE u.rol_id = r.id) AS usuarios
               FROM roles r ORDER BY r.nivel DESC'
        );
        $permisos = BaseDatos::todas('SELECT * FROM permisos ORDER BY clave');

        $matriz = [];
        foreach (BaseDatos::todas('SELECT rol_id, permiso_id FROM rol_permiso') as $rp) {
            $matriz[(int) $rp['rol_id']][(int) $rp['permiso_id']] = true;
        }

        // Agrupar por módulo (lo que va antes del punto en la clave).
        $grupos = [];
        foreach ($permisos as $p) {
            $modulo = explode('.', (string) $p['clave'])[0];
            $grupos[$modulo][] = $p;
        }
        ksort($grupos);

        $this->render('usuarios/roles', [
            'titulo'     => 'Roles y permisos · CashMan H.A.',
            'encabezado' => 'Roles y permisos',
            'roles'      => $roles,
            'grupos'     => $grupos,
            'matriz'     => $matriz,
            'total'      => count($permisos),
            'miNivel'    => $miNivel,
            'csrf'       => Csrf::token('rol'),
        ]);
    }

    // -----------------------------------------------------------------

    /**
     * Crea un rol.
     *
     * Es el `registrar-nuevos-roles-usuarios` del sistema anterior. El
     * nivel es lo que de verdad importa: decide a quién puede tocar
     * quien lleve ese rol, así que nadie puede crear uno por encima del
     * suyo —sería fabricarse un rol para después asignárselo.
     */
    public function crearRol(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('sistema.configurar');

        $nombre = mb_substr(trim($this->post('nombre')), 0, 80);
        if (mb_strlen($nombre) < 3) {
            Respuesta::error('El nombre del rol debe tener al menos 3 caracteres.', 422);
        }

        $clave = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $this->post('clave')) ?? '');
        if (strlen($clave) < 3 || strlen($clave) > 40) {
            Respuesta::error('La clave debe tener entre 3 y 40 caracteres (letras, números y _).', 422);
        }

        if (BaseDatos::unValor('SELECT id FROM roles WHERE clave = ?', [$clave]) !== null) {
            Respuesta::error('Ya existe un rol con esa clave.', 422);
        }

        $nivel = (int) $this->post('nivel', '10');
        if ($nivel < 1 || $nivel > 100) {
            Respuesta::error('El nivel debe estar entre 1 y 100.', 422);
        }

        $miNivel = (int) BaseDatos::unValor(
            'SELECT r.nivel FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?',
            [$actorId]
        );

        if ($nivel >= $miNivel) {
            Auditoria::registrar('rol.creacion_denegada', Auditoria::DENEGADO,
                $actorId, 'rol', '', ['nivel_pedido' => $nivel, 'mi_nivel' => $miNivel]);
            Respuesta::error(
                'No puede crear un rol de nivel ' . $nivel
                . ': el suyo es ' . $miNivel . ' y solo puede crear por debajo.',
                403
            );
        }

        BaseDatos::ejecutar(
            'INSERT INTO roles (clave, nombre, descripcion, nivel) VALUES (?,?,?,?)',
            [$clave, $nombre, mb_substr($this->post('descripcion'), 0, 255), $nivel]
        );
        $id = BaseDatos::ultimoId();

        Auditoria::registrar('rol.creado', Auditoria::EXITO, $actorId,
            'rol', (string) $id, ['clave' => $clave, 'nombre' => $nombre, 'nivel' => $nivel]);

        Respuesta::exito(
            ['id' => $id],
            'Rol creado sin permisos. Asígnaselos en la matriz de abajo.'
        );
    }

    /** Cambia nombre, descripción y nivel de un rol. */
    public function modificarRol(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('sistema.configurar');

        $id = (int) ($parametros['id'] ?? 0);
        if ($id <= 0) {
            throw new RecursoNoEncontrado('Rol no encontrado.');
        }

        $antes = BaseDatos::unaFila('SELECT * FROM roles WHERE id = ?', [$id]);
        if ($antes === null) {
            throw new RecursoNoEncontrado('Rol no encontrado.');
        }

        $miNivel = (int) BaseDatos::unValor(
            'SELECT r.nivel FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?',
            [$actorId]
        );

        // Un rol de nivel igual o superior al propio no se toca.
        if ((int) $antes['nivel'] >= $miNivel) {
            Auditoria::registrar('rol.modificacion_denegada', Auditoria::DENEGADO,
                $actorId, 'rol', (string) $id);
            Respuesta::error(
                'No puede modificar un rol de nivel igual o superior al suyo.', 403
            );
        }

        $nombre = mb_substr(trim($this->post('nombre')), 0, 80);
        if (mb_strlen($nombre) < 3) {
            Respuesta::error('El nombre del rol debe tener al menos 3 caracteres.', 422);
        }

        $nivel = (int) $this->post('nivel', (string) (int) $antes['nivel']);
        if ($nivel < 1 || $nivel >= $miNivel) {
            Respuesta::error(
                'El nivel debe estar entre 1 y ' . ($miNivel - 1) . '.', 422
            );
        }

        BaseDatos::ejecutar(
            'UPDATE roles SET nombre = ?, descripcion = ?, nivel = ? WHERE id = ?',
            [$nombre, mb_substr($this->post('descripcion'), 0, 255), $nivel, $id]
        );

        Auditoria::registrar('rol.modificado', Auditoria::EXITO, $actorId,
            'rol', (string) $id, [
                'antes'   => ['nombre' => $antes['nombre'], 'nivel' => (int) $antes['nivel']],
                'despues' => ['nombre' => $nombre, 'nivel' => $nivel],
            ]);

        Respuesta::exito(null, 'Rol actualizado.');
    }

    /**
     * Da o quita un permiso a un rol.
     *
     * Es el `modificar-roles-usuarios` del original, pero por celda en
     * vez de por formulario completo: así se ve el efecto al momento y
     * cada cambio queda auditado por separado, que es lo que hace falta
     * para revisar después quién concedió qué.
     */
    public function permisoRol(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('sistema.configurar');

        $rolId = (int) ($parametros['id'] ?? 0);
        $rol   = BaseDatos::unaFila('SELECT * FROM roles WHERE id = ?', [$rolId]);

        if ($rol === null) {
            throw new RecursoNoEncontrado('Rol no encontrado.');
        }

        $clave   = $this->post('permiso');
        $permiso = BaseDatos::unaFila(
            'SELECT id, clave, descripcion FROM permisos WHERE clave = ?', [$clave]
        );

        if ($permiso === null) {
            Respuesta::error('Permiso no válido.', 422);
        }

        $miNivel = (int) BaseDatos::unValor(
            'SELECT r.nivel FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?',
            [$actorId]
        );

        // Ni siquiera sobre el propio rol: alguien podría concederse
        // `sistema.configurar` a sí mismo y quedarse con todo.
        if ((int) $rol['nivel'] >= $miNivel) {
            Auditoria::registrar('rol.permiso_denegado', Auditoria::DENEGADO,
                $actorId, 'rol', (string) $rolId,
                ['permiso' => $clave, 'motivo' => 'nivel igual o superior al propio']);
            Respuesta::error(
                'No puede cambiar los permisos de un rol de nivel igual o superior al suyo.',
                403
            );
        }

        $tiene = BaseDatos::unValor(
            'SELECT 1 FROM rol_permiso WHERE rol_id = ? AND permiso_id = ?',
            [$rolId, (int) $permiso['id']]
        ) !== null;

        if ($tiene) {
            BaseDatos::ejecutar(
                'DELETE FROM rol_permiso WHERE rol_id = ? AND permiso_id = ?',
                [$rolId, (int) $permiso['id']]
            );
        } else {
            BaseDatos::ejecutar(
                'INSERT INTO rol_permiso (rol_id, permiso_id) VALUES (?,?)',
                [$rolId, (int) $permiso['id']]
            );
        }

        // Las sesiones abiertas llevan los permisos viejos en memoria:
        // se cierran para que el cambio tenga efecto de verdad.
        $cerradas = BaseDatos::ejecutar(
            'UPDATE sesiones
                SET revocada_en = NOW(), motivo_revocacion = "cambio_de_permisos"
              WHERE usuario_id IN (SELECT id FROM usuarios WHERE rol_id = ?)
                AND revocada_en IS NULL',
            [$rolId]
        );

        Auditoria::registrar(
            $tiene ? 'rol.permiso_retirado' : 'rol.permiso_concedido',
            Auditoria::EXITO, $actorId, 'rol', (string) $rolId,
            ['rol' => $rol['nombre'], 'permiso' => $clave, 'sesiones_cerradas' => $cerradas]
        );

        Respuesta::exito(
            ['concedido' => !$tiene],
            ($tiene ? 'Permiso retirado de ' : 'Permiso concedido a ') . $rol['nombre']
            . ($cerradas > 0
                ? '. Se cerraron ' . $cerradas . ' sesión(es) para que surta efecto.'
                : '.')
        );
    }

    /** Elimina un rol que nadie usa. */
    public function eliminarRol(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('sistema.configurar');

        $id  = (int) ($parametros['id'] ?? 0);
        $rol = BaseDatos::unaFila('SELECT * FROM roles WHERE id = ?', [$id]);

        if ($rol === null) {
            throw new RecursoNoEncontrado('Rol no encontrado.');
        }

        $miNivel = (int) BaseDatos::unValor(
            'SELECT r.nivel FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?',
            [$actorId]
        );

        if ((int) $rol['nivel'] >= $miNivel) {
            Respuesta::error('No puede eliminar un rol de nivel igual o superior al suyo.', 403);
        }

        // Un rol con usuarios asignados no se borra: dejaría a esas
        // personas sin permisos y con una clave foránea rota.
        $usuarios = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM usuarios WHERE rol_id = ?', [$id]
        );

        if ($usuarios > 0) {
            Respuesta::error(
                'El rol tiene ' . $usuarios . ' usuario(s) asignado(s). '
                . 'Cámbialos de rol antes de eliminarlo.',
                422
            );
        }

        BaseDatos::enTransaccion(static function () use ($id): void {
            BaseDatos::ejecutar('DELETE FROM rol_permiso WHERE rol_id = ?', [$id]);
            BaseDatos::ejecutar('DELETE FROM roles WHERE id = ?', [$id]);
        });

        Auditoria::registrar('rol.eliminado', Auditoria::EXITO, $actorId,
            'rol', (string) $id, ['clave' => $rol['clave'], 'nombre' => $rol['nombre']]);

        Respuesta::exito(null, 'Rol ' . $rol['nombre'] . ' eliminado.');
    }

    // -----------------------------------------------------------------

    private function generarTemporal(): string

    {
        // Sin caracteres ambiguos: quien la dicte por teléfono no se
        // enredará con O/0 ni l/I/1.
        $may = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $min = 'abcdefghijkmnpqrstuvwxyz';
        $num = '23456789';
        $sim = '#$%&*+=?@';

        $clave = $may[random_int(0, strlen($may) - 1)]
               . $min[random_int(0, strlen($min) - 1)]
               . $num[random_int(0, strlen($num) - 1)]
               . $sim[random_int(0, strlen($sim) - 1)];

        $todos = $may . $min . $num . $sim;
        for ($i = 0; $i < 10; $i++) {
            $clave .= $todos[random_int(0, strlen($todos) - 1)];
        }

        // Mezclar para que el patrón no sea predecible.
        $a = str_split($clave);
        for ($i = count($a) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }

        return implode('', $a);
    }

    private function sinAcentos(string $t): string
    {
        return strtr($t, [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U',
        ]);
    }
    /**
     * Ficha de un usuario.
     *
     * Reúne `modificar-usuarios-administrador` y
     * `registrar-detalles-usuarios-administrador` del sistema anterior:
     * los datos de acceso y el expediente personal en una sola pantalla,
     * que es como se consultan en la práctica.
     */
    public function ver(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('usuario.ver_todos');

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'usuario');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Usuario no encontrado.');
        }

        $u = BaseDatos::unaFila(
            'SELECT u.*, r.nombre AS rol, r.nivel AS rol_nivel
               FROM usuarios u
               JOIN roles r ON r.id = u.rol_id
              WHERE u.id = ?',
            [$id]
        );

        if ($u === null) {
            throw new RecursoNoEncontrado('Usuario no encontrado.');
        }

        $detalle = BaseDatos::unaFila(
            'SELECT * FROM detalle_usuarios WHERE usuario_id = ?', [$id]
        ) ?? [];

        // Nivel del actor: determina a quién puede tocar.
        $miNivel = (int) BaseDatos::unValor(
            'SELECT r.nivel FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?',
            [$actorId]
        );

        // Los roles que puede asignar son los de su nivel o menos.
        $roles = BaseDatos::todas(
            'SELECT id, nombre, nivel FROM roles WHERE nivel <= ? ORDER BY nivel DESC',
            [$miNivel]
        );

        // Resumen de lo que el usuario tiene contratado: da contexto
        // antes de tocar nada.
        $resumen = BaseDatos::unaFila(
            'SELECT
               (SELECT COUNT(*) FROM cuentas  WHERE usuario_id = ? AND es_tecnica = 0) cuentas,
               (SELECT COUNT(*) FROM tarjetas WHERE usuario_id = ? AND estado <> "cancelada") tarjetas,
               (SELECT COUNT(*) FROM creditos WHERE usuario_id = ?) creditos,
               (SELECT COALESCE(SUM(saldo_cent),0) FROM cuentas
                 WHERE usuario_id = ? AND es_tecnica = 0) saldo,
               (SELECT COALESCE(SUM(saldo_capital_cent),0) FROM creditos
                 WHERE usuario_id = ? AND estado = "vigente") deuda,
               (SELECT COUNT(*) FROM sesiones
                 WHERE usuario_id = ? AND revocada_en IS NULL AND expira_en > NOW()) sesiones',
            [$id, $id, $id, $id, $id, $id]
        ) ?? [];

        $accesos = BaseDatos::todas(
            'SELECT exitoso, ip, navegador, sistema, dispositivo, creado_en
               FROM accesos WHERE usuario_id = ?
              ORDER BY id DESC LIMIT 10',
            [$id]
        );

        foreach ($accesos as &$ac) {
            $ac['ip_texto'] = $ac['ip'] !== null
                ? ClienteIp::desdeBinaria((string) $ac['ip']) : '';
            unset($ac['ip']);
        }
        unset($ac);

        $u['codigo'] = Hashid::codificar($id, 'usuario');
        $u['es_yo']  = $id === $actorId;

        // Nunca al navegador: el hash y el secreto del segundo factor.
        unset($u['id'], $u['password_hash'], $u['totp_secreto_cifrado']);

        $this->render('usuarios/ver', [
            'titulo'      => 'Usuario ' . $u['usuario'] . ' · CashMan H.A.',
            'encabezado'  => trim((string) $u['nombres'] . ' ' . (string) $u['apellidos']),
            'usuario'     => $u,
            'detalle'     => $detalle,
            'roles'       => $roles,
            'resumen'     => $resumen,
            'accesos'     => $accesos,
            // Un usuario de nivel igual o superior no se toca: así nadie
            // degrada a su par ni a su jefe.
            'puedeEditar' => Autorizacion::puede('usuario.modificar')
                           && ((int) $u['rol_nivel'] < $miNivel || $id === $actorId),
            'puedeExpediente' => Autorizacion::puede('cliente.editar_expediente'),
            'puedeBloquear'   => Autorizacion::puede('usuario.bloquear')
                               && (int) $u['rol_nivel'] < $miNivel,
            'csrf'        => Csrf::token('usuario'),
        ]);
    }

    /** Modifica los datos de acceso. */
    public function modificar(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('usuario.modificar');

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'usuario');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Usuario no encontrado.');
        }

        $antes = BaseDatos::unaFila(
            'SELECT u.*, r.nivel AS rol_nivel, r.nombre AS rol
               FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?',
            [$id]
        );

        if ($antes === null) {
            throw new RecursoNoEncontrado('Usuario no encontrado.');
        }

        $miNivel = (int) BaseDatos::unValor(
            'SELECT r.nivel FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?',
            [$actorId]
        );

        if ((int) $antes['rol_nivel'] >= $miNivel && $id !== $actorId) {
            Auditoria::registrar('usuario.modificacion_denegada', Auditoria::DENEGADO,
                $actorId, 'usuario', (string) $id,
                ['motivo' => 'nivel igual o superior al propio']);
            Respuesta::error(
                'No puede modificar a un usuario de tu mismo nivel o superior.', 403
            );
        }

        $nombres   = mb_substr($this->post('nombres'), 0, 120);
        $apellidos = mb_substr($this->post('apellidos'), 0, 120);
        $correo    = mb_strtolower(trim($this->post('correo')));
        $telefono  = mb_substr($this->post('telefono'), 0, 20);

        if ($nombres === '' || $apellidos === '') {
            Respuesta::error('Nombres y apellidos son obligatorios.', 422);
        }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            Respuesta::error('Correo electrónico inválido.', 422);
        }

        // El correo identifica al usuario para recuperar la contraseña:
        // si se repite, dos personas competirían por el mismo enlace.
        $repetido = BaseDatos::unValor(
            'SELECT id FROM usuarios WHERE correo = ? AND id <> ?', [$correo, $id]
        );
        if ($repetido !== null) {
            Respuesta::error('Ese correo ya está en uso por otro usuario.', 422);
        }

        // Cambio de rol: mismas reglas de nivel que al crear.
        $rolId = (int) $this->post('rol', (string) (int) $antes['rol_id']);
        $rol   = BaseDatos::unaFila('SELECT id, nivel, nombre FROM roles WHERE id = ?', [$rolId]);

        if ($rol === null) {
            Respuesta::error('Rol no válido.', 422);
        }
        if ((int) $rol['nivel'] > $miNivel) {
            Auditoria::registrar('usuario.escalada_denegada', Auditoria::DENEGADO,
                $actorId, 'rol', (string) $rolId);
            Respuesta::error('No puede asignar un rol superior al suyo.', 403);
        }

        // Y nadie se cambia su propio rol: sería saltarse el control.
        if ($id === $actorId && $rolId !== (int) $antes['rol_id']) {
            Auditoria::registrar('usuario.autoascenso_denegado', Auditoria::DENEGADO,
                $actorId, 'usuario', (string) $id);
            Respuesta::error('No puede cambiar su propio rol.', 403);
        }

        BaseDatos::ejecutar(
            'UPDATE usuarios
                SET nombres = ?, apellidos = ?, correo = ?, telefono = ?, rol_id = ?
              WHERE id = ?',
            [$nombres, $apellidos, $correo, $telefono, $rolId, $id]
        );

        // Si cambió el rol, las sesiones abiertas llevan los permisos
        // viejos: se cierran para que el cambio surta efecto de verdad.
        if ($rolId !== (int) $antes['rol_id']) {
            BaseDatos::ejecutar(
                'UPDATE sesiones
                    SET revocada_en = NOW(), motivo_revocacion = "cambio_de_rol"
                  WHERE usuario_id = ? AND revocada_en IS NULL',
                [$id]
            );
        }

        Auditoria::registrar('usuario.modificado', Auditoria::EXITO, $actorId,
            'usuario', (string) $id, [
                'antes' => [
                    'nombres' => $antes['nombres'], 'apellidos' => $antes['apellidos'],
                    'correo'  => $antes['correo'],  'rol' => $antes['rol'],
                ],
                'despues' => [
                    'nombres' => $nombres, 'apellidos' => $apellidos,
                    'correo'  => $correo,  'rol' => $rol['nombre'],
                ],
            ]);

        Respuesta::exito(
            null,
            $rolId !== (int) $antes['rol_id']
                ? 'Usuario actualizado. Al cambiar el rol se cerraron sus sesiones abiertas.'
                : 'Usuario actualizado.'
        );
    }

    /** Guarda el expediente personal. */
    public function expediente(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('cliente.editar_expediente');

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'usuario');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Usuario no encontrado.');
        }

        if (BaseDatos::unValor('SELECT id FROM usuarios WHERE id = ?', [$id]) === null) {
            throw new RecursoNoEncontrado('Usuario no encontrado.');
        }

        $dui = trim($this->post('dui'));
        $nit = trim($this->post('nit'));

        // El DUI salvadoreño son 9 dígitos con guion antes del último.
        if ($dui !== '' && preg_match('/^\d{8}-\d$/', $dui) !== 1) {
            Respuesta::error('El DUI debe tener el formato 00000000-0.', 422);
        }
        if ($nit !== '' && preg_match('/^\d{4}-\d{6}-\d{3}-\d$/', $nit) !== 1) {
            Respuesta::error('El NIT debe tener el formato 0000-000000-000-0.', 422);
        }

        // Un DUI repetido significa dos expedientes de la misma persona.
        if ($dui !== '') {
            $otro = BaseDatos::unValor(
                'SELECT usuario_id FROM detalle_usuarios WHERE dui = ? AND usuario_id <> ?',
                [$dui, $id]
            );
            if ($otro !== null) {
                Respuesta::error('Ese DUI ya figura en el expediente de otro cliente.', 422);
            }
        }

        $ingreso = (float) str_replace([',', '$', ' '], '', $this->post('ingreso_mensual', '0'));
        if ($ingreso < 0) {
            Respuesta::error('El ingreso mensual no puede ser negativo.', 422);
        }

        $nacimiento = $this->post('fecha_nacimiento');
        if ($nacimiento !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $nacimiento) !== 1) {
            Respuesta::error('Fecha de nacimiento no válida.', 422);
        }

        // Un cliente menor de edad no puede contratar.
        if ($nacimiento !== '') {
            $edad = (new \DateTimeImmutable($nacimiento))
                ->diff(new \DateTimeImmutable('today'))->y;
            if ($edad < 18) {
                Respuesta::error('El titular debe ser mayor de edad (' . $edad . ' años).', 422);
            }
            if ($edad > 120) {
                Respuesta::error('Revise la fecha de nacimiento.', 422);
            }
        }

        $genero = $this->post('genero');
        if ($genero !== '' && !in_array($genero, ['M', 'F', 'O'], true)) {
            $genero = '';
        }

        $civil = $this->post('estado_civil');
        if ($civil !== '' && !in_array($civil, ['soltero', 'casado', 'divorciado', 'viudo', 'union_libre'], true)) {
            $civil = '';
        }

        $campos = [
            'dui'                  => $dui,
            'nit'                  => $nit,
            'telefono'             => mb_substr($this->post('telefono'), 0, 20),
            'celular'              => mb_substr($this->post('celular'), 0, 20),
            'telefono_trabajo'     => mb_substr($this->post('telefono_trabajo'), 0, 20),
            'direccion'            => mb_substr($this->post('direccion'), 0, 255),
            'empresa'              => mb_substr($this->post('empresa'), 0, 150),
            'cargo'                => mb_substr($this->post('cargo'), 0, 120),
            'direccion_trabajo'    => mb_substr($this->post('direccion_trabajo'), 0, 255),
            'ingreso_mensual_cent' => (int) round($ingreso * 100),
            'fecha_nacimiento'     => $nacimiento !== '' ? $nacimiento : null,
            'genero'               => $genero !== '' ? $genero : null,
            'estado_civil'         => $civil !== '' ? $civil : null,
        ];

        // Se considera completo cuando están los datos que de verdad
        // hacen falta para abrir un producto.
        $completo = $dui !== '' && $campos['direccion'] !== ''
                 && $campos['celular'] !== '' && $campos['ingreso_mensual_cent'] > 0 ? 1 : 0;

        $existe = BaseDatos::unValor(
            'SELECT id FROM detalle_usuarios WHERE usuario_id = ?', [$id]
        );

        if ($existe === null) {
            $columnas = array_keys($campos);
            BaseDatos::ejecutar(
                'INSERT INTO detalle_usuarios (usuario_id, `'
                . implode('`,`', $columnas) . '`, perfil_completo) VALUES (?'
                . str_repeat(',?', count($columnas)) . ',?)',
                array_merge([$id], array_values($campos), [$completo])
            );
        } else {
            $sets = [];
            foreach (array_keys($campos) as $col) { $sets[] = "`{$col}` = ?"; }

            BaseDatos::ejecutar(
                'UPDATE detalle_usuarios SET ' . implode(', ', $sets)
                . ', perfil_completo = ?, actualizado_en = NOW() WHERE usuario_id = ?',
                array_merge(array_values($campos), [$completo, $id])
            );
        }

        // La bandera del usuario sigue a la del expediente.
        BaseDatos::ejecutar(
            'UPDATE usuarios SET perfil_completo = ? WHERE id = ?', [$completo, $id]
        );

        Auditoria::registrar('expediente.actualizado', Auditoria::EXITO, $actorId,
            'usuario', (string) $id, ['completo' => (bool) $completo]);

        Respuesta::exito(
            ['completo' => (bool) $completo],
            $completo === 1
                ? 'Expediente guardado y marcado como completo.'
                : 'Expediente guardado. Faltan datos para considerarlo completo: '
                  . 'DUI, dirección, celular e ingreso mensual.'
        );
    }
}
