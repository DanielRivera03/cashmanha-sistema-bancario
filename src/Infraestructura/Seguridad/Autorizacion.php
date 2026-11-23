<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Seguridad;

use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * Autorización en dos niveles.
 *
 * NIVEL 1 — ¿puede este ROL ejecutar esta ACCIÓN?
 *     Es lo único que tenía el sistema anterior, y para transferencias
 *     la comprobación era `$_SESSION['id_rol'] >= 1 && <= 5`, es decir,
 *     "cualquiera que haya iniciado sesión".
 *
 * NIVEL 2 — ¿puede este USUARIO actuar sobre ESTE OBJETO concreto?
 *     Esto NO EXISTÍA. La cuenta de origen de una transferencia venía
 *     en un campo oculto del formulario y nadie comprobaba que fuera
 *     del usuario. Cambiando ese número se transfería desde la cuenta
 *     de otro cliente.
 *
 * Los dos niveles son necesarios. El primero sin el segundo deja
 * abierta la puerta del IDOR; el segundo sin el primero permite que
 * un cliente ejecute operaciones de gerencia sobre sus propios datos.
 */
final class Autorizacion
{
    /** @var array<int, array<string,bool>> Caché de permisos por rol */
    /** @var array<int,int> Nivel de cada rol, resuelto una vez. */
    private static array $cacheNivel = [];

    private static array $cachePermisos = [];

    // =================================================================
    // NIVEL 1 — Permisos por rol
    // =================================================================

    public static function puede(string $permiso, ?int $rolId = null): bool
    {
        $rolId ??= Sesion::rolId();
        if ($rolId === null) {
            return false;
        }

        if (!isset(self::$cachePermisos[$rolId])) {
            $filas = BaseDatos::todas(
                'SELECT p.clave
                   FROM rol_permiso rp
                   JOIN permisos p ON p.id = rp.permiso_id
                  WHERE rp.rol_id = ?',
                [$rolId]
            );
            $mapa = [];
            foreach ($filas as $f) {
                $mapa[(string) $f['clave']] = true;
            }
            self::$cachePermisos[$rolId] = $mapa;
        }

        return isset(self::$cachePermisos[$rolId][$permiso]);
    }

    /**
     * Mando total: el rol que no tiene puertas cerradas.
     *
     * Hay una diferencia entre «tener todos los permisos» y «poder
     * operar sobre lo de cualquiera», y el sistema las trataba
     * igual. El administrador tenía los 105 permisos del catálogo y
     * aun así media pantalla de la tarjeta de un cliente no le
     * aparecía, porque esas secciones no preguntan por un permiso:
     * preguntan si el objeto es suyo.
     *
     * Esto responde a esa otra pregunta. Es deliberado y es una
     * decisión de quien manda en el sistema, no un descuido: todo lo
     * que haga queda en la auditoría con su nombre y su hora, que es
     * lo que hace que un poder así se pueda rendir después.
     *
     * Se mide por NIVEL y no por el nombre del rol: renombrar un rol
     * es cosa de un minuto y no debería abrir ni cerrar nada.
     */
    public static function mandoTotal(?int $rolId = null): bool
    {
        $rolId ??= Sesion::rolId();

        if ($rolId === null) {
            return false;
        }

        if (!isset(self::$cacheNivel[$rolId])) {
            self::$cacheNivel[$rolId] = (int) BaseDatos::unValor(
                'SELECT nivel FROM roles WHERE id = ?',
                [$rolId]
            );
        }

        return self::$cacheNivel[$rolId] >= 100;
    }

    /** @throws AccesoDenegado */
    public static function exigir(string $permiso, ?int $rolId = null): void
    {
        if (!self::puede($permiso, $rolId)) {
            Auditoria::registrar(
                'autorizacion.denegada',
                Auditoria::DENEGADO,
                Sesion::usuarioId(),
                'permiso',
                $permiso
            );
            throw new AccesoDenegado("No tiene permiso para esta acción ({$permiso}).");
        }
    }

    /** @param array<int,string> $permisos */
    public static function puedeAlguno(array $permisos, ?int $rolId = null): bool
    {
        foreach ($permisos as $p) {
            if (self::puede($p, $rolId)) {
                return true;
            }
        }
        return false;
    }

    // =================================================================
    // NIVEL 2 — Propiedad del objeto
    // =================================================================

    /**
     * Verifica que la cuenta pertenezca al usuario, o que tenga
     * permiso explícito sobre cuentas de terceros.
     *
     * Esta es la comprobación cuya ausencia permitía vaciar la cuenta
     * de cualquier cliente.
     *
     * @throws AccesoDenegado|RecursoNoEncontrado
     * @return array<string,mixed> La cuenta, ya verificada
     */
    public static function exigirCuenta(int $cuentaId, ?int $usuarioId = null, string $permisoTerceros = 'cuenta.ver_todas'): array
    {
        $usuarioId ??= Sesion::exigirAutenticado();

        $cuenta = BaseDatos::unaFila(
            'SELECT c.*, p.nombre AS producto_nombre, p.tipo AS producto_tipo,
                    p.saldo_minimo_cent
               FROM cuentas c
               JOIN productos p ON p.id = c.producto_id
              WHERE c.id = ? AND c.es_tecnica = 0',
            [$cuentaId]
        );

        if ($cuenta === null) {
            throw new RecursoNoEncontrado('Cuenta no encontrada.');
        }

        if ((int) $cuenta['usuario_id'] === $usuarioId) {
            return $cuenta;
        }

        if (self::puede($permisoTerceros)) {
            Auditoria::registrar(
                'acceso.cuenta_terceros',
                Auditoria::EXITO,
                $usuarioId,
                'cuenta',
                (string) $cuentaId
            );
            return $cuenta;
        }

        // Intento de acceder a una cuenta ajena sin permiso: esto es
        // exactamente el ataque que el sistema anterior permitía.
        Auditoria::registrar(
            'acceso.cuenta_ajena_denegado',
            Auditoria::DENEGADO,
            $usuarioId,
            'cuenta',
            (string) $cuentaId,
            ['propietario_real' => (int) $cuenta['usuario_id']]
        );

        // Se responde "no encontrada", no "no autorizada": así no se
        // confirma que la cuenta exista.
        throw new RecursoNoEncontrado('Cuenta no encontrada.');
    }

    /**
     * @throws AccesoDenegado|RecursoNoEncontrado
     * @return array<string,mixed>
     */
    public static function exigirTarjeta(int $tarjetaId, ?int $usuarioId = null): array
    {
        $usuarioId ??= Sesion::exigirAutenticado();

        $tarjeta = BaseDatos::unaFila('SELECT * FROM tarjetas WHERE id = ?', [$tarjetaId]);

        if ($tarjeta === null) {
            throw new RecursoNoEncontrado('Tarjeta no encontrada.');
        }

        if ((int) $tarjeta['usuario_id'] === $usuarioId || self::puede('tarjeta.ver_todas')) {
            return $tarjeta;
        }

        Auditoria::registrar(
            'acceso.tarjeta_ajena_denegado',
            Auditoria::DENEGADO,
            $usuarioId,
            'tarjeta',
            (string) $tarjetaId
        );

        throw new RecursoNoEncontrado('Tarjeta no encontrada.');
    }

    /**
     * @throws RecursoNoEncontrado
     * @return array<string,mixed>
     */
    public static function exigirEstadoCuenta(int $estadoId, ?int $usuarioId = null): array
    {
        $usuarioId ??= Sesion::exigirAutenticado();

        $ec = BaseDatos::unaFila('SELECT * FROM estados_cuenta WHERE id = ?', [$estadoId]);

        if ($ec === null) {
            throw new RecursoNoEncontrado('Estado de cuenta no encontrado.');
        }

        if ((int) $ec['usuario_id'] === $usuarioId || self::puede('estado_cuenta.ver_todos')) {
            return $ec;
        }

        Auditoria::registrar(
            'acceso.estado_cuenta_ajeno_denegado',
            Auditoria::DENEGADO,
            $usuarioId,
            'estado_cuenta',
            (string) $estadoId
        );

        throw new RecursoNoEncontrado('Estado de cuenta no encontrado.');
    }

    /** La cuenta debe estar operable para mover dinero. */
    public static function exigirCuentaOperable(array $cuenta): void
    {
        if ($cuenta['estado'] !== 'activa') {
            throw new AccesoDenegado(match ($cuenta['estado']) {
                'bloqueada' => 'La cuenta está bloqueada.',
                'congelada' => 'La cuenta está congelada por orden judicial o administrativa.',
                'cerrada'   => 'La cuenta está cerrada.',
                default     => 'La cuenta no está operable.',
            });
        }

        /* Un depósito a plazo no se toca hasta que vence.
         *
         * Si se pudiera transferir desde él, pagar servicios o
         * retirarlo en ventanilla, no sería un plazo fijo: sería una
         * cuenta de ahorro con mejor tasa, y el banco estaría pagando
         * por una certeza que no tiene.
         *
         * El bloqueo va AQUÍ y no escondiendo la cuenta de los
         * desplegables, porque esto es por donde pasan todas las
         * operaciones. Esconderla de la pantalla lo salta cualquiera
         * cambiando un identificador en la petición.
         *
         * Para sacarlo antes de tiempo está la cancelación anticipada,
         * que es otra cosa: tiene su penalidad y deja su rastro. */
        if (($cuenta['producto_tipo'] ?? '') === 'plazo') {
            throw new AccesoDenegado(
                'Es un depósito a plazo fijo: no admite movimientos hasta su vencimiento. '
                . 'Si necesita el dinero antes, puede cancelarlo anticipadamente desde '
                . 'la pantalla de Depósitos a plazo, con la penalidad que corresponda.'
            );
        }
    }

    public static function limpiarCache(): void
    {
        self::$cachePermisos = [];
        self::$cacheNivel    = [];
    }

    /** @return array<int,string> Permisos del rol actual, para la interfaz. */
    public static function permisosActuales(): array
    {
        $rolId = Sesion::rolId();
        if ($rolId === null) {
            return [];
        }
        self::puede('__calentar_cache__', $rolId);
        return array_keys(self::$cachePermisos[$rolId] ?? []);
    }
}
