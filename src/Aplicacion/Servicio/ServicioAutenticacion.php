<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\LimitadorTasa;
use CashMan\Infraestructura\Seguridad\LimiteExcedido;
use CashMan\Infraestructura\Seguridad\Sesion;
use CashMan\Infraestructura\Seguridad\ServicioPassword;

/**
 * Autenticación de usuarios.
 *
 * Diferencias con el login anterior, por orden de gravedad:
 *
 *   1. La comparación de contraseña ya NO ocurre en SQL. Antes el
 *      procedimiento hacía `WHERE contrasenia = Pass`, lo que obligaba
 *      a hashes deterministas y hacía imposible usar cualquier
 *      algoritmo moderno. Ahora se recupera el hash y se verifica en
 *      PHP con password_verify().
 *   2. Sentencias preparadas: la inyección SQL deja de ser posible.
 *   3. Límite de intentos por IP y por usuario.
 *   4. Tiempo constante: no se puede distinguir "usuario inexistente"
 *      de "contraseña incorrecta" midiendo la respuesta.
 *   5. session_regenerate_id() al autenticar: muere la fijación de sesión.
 *   6. El estado de la cuenta se evalúa DESPUÉS de verificar la
 *      contraseña, para no filtrar qué cuentas existen.
 *   7. Auditoría con la IP y el user-agent reales del cliente.
 */
final class ServicioAutenticacion
{
    private ServicioPassword $password;
    private LimitadorTasa $limitador;

    public function __construct()
    {
        $this->password  = new ServicioPassword();
        $this->limitador = new LimitadorTasa(
            Config::entero('LOGIN_MAX_INTENTOS', 5),
            Config::entero('LOGIN_VENTANA_SEG', 900),
            Config::entero('LOGIN_BLOQUEO_SEG', 900)
        );
    }

    /**
     * @param string|null $totp Código del segundo factor, si la cuenta lo tiene activo
     * @return array{usuario:array<string,mixed>, requiere_cambio:bool}
     * @throws CredencialesInvalidas|CuentaNoOperable|LimiteExcedido|RequiereSegundoFactor
     */
    public function iniciarSesion(string $identificador, string $passwordPlano, ?string $totp = null): array
    {
        $identificador = trim($identificador);
        $ip            = ClienteIp::obtener();

        // --- 1. Límite de intentos ANTES de tocar la base -------------
        // Por IP: frena al atacante que prueba muchos usuarios.
        $this->limitador->exigir('login:ip:' . $ip, 20);
        // Por identificador: frena al que ataca una cuenta concreta.
        $this->limitador->exigir('login:id:' . mb_strtolower($identificador));

        if ($identificador === '' || $passwordPlano === '') {
            throw new CredencialesInvalidas('Usuario y contraseña son obligatorios.');
        }

        // --- 2. Recuperar, no comparar --------------------------------
        $u = BaseDatos::unaFila(
            'SELECT u.id, u.uuid, u.usuario, u.correo, u.nombres, u.apellidos,
                    u.password_hash, u.password_algoritmo, u.requiere_cambio_password,
                    u.password_actualizado_en,
                    u.rol_id, u.estado, u.foto_perfil, u.bloqueado_hasta,
                    u.intentos_fallidos, u.totp_activo,
                    r.clave AS rol_clave, r.nombre AS rol_nombre
               FROM usuarios u
               JOIN roles r ON r.id = u.rol_id
              WHERE u.usuario = ? OR u.correo = ?
              LIMIT 1',
            [$identificador, $identificador]
        );

        // --- 3. Usuario inexistente: gastar el mismo tiempo -----------
        if ($u === null) {
            $this->password->verificarSenuelo($passwordPlano);
            Auditoria::registrar(
                'login.usuario_inexistente',
                Auditoria::FALLO,
                null,
                'usuario',
                substr($identificador, 0, 64)
            );
            throw new CredencialesInvalidas('Usuario o contraseña incorrectos.');
        }

        $usuarioId = (int) $u['id'];

        // --- 4. Bloqueo temporal por intentos fallidos ---------------
        if (!empty($u['bloqueado_hasta'])
            && new \DateTimeImmutable((string) $u['bloqueado_hasta']) > new \DateTimeImmutable()) {
            Auditoria::registrar('login.cuenta_bloqueada', Auditoria::DENEGADO, $usuarioId);
            throw new CuentaNoOperable(
                'La cuenta está bloqueada temporalmente por intentos fallidos. Intenta más tarde.'
            );
        }

        // --- 5. Verificar la contraseña ------------------------------
        $valida = $this->password->verificar(
            $passwordPlano,
            (string) $u['password_hash'],
            (string) $u['password_algoritmo']
        );

        if (!$valida) {
            $this->registrarFallo($usuarioId, (int) $u['intentos_fallidos']);
            Auditoria::registrar('login.password_incorrecta', Auditoria::FALLO, $usuarioId);
            throw new CredencialesInvalidas('Usuario o contraseña incorrectos.');
        }

        // --- 6. Rehash transparente ----------------------------------
        // El usuario que venía con hash viejo migra a Argon2id sin enterarse.
        if ($this->password->necesitaRehash((string) $u['password_hash'], (string) $u['password_algoritmo'])) {
            BaseDatos::ejecutar(
                'UPDATE usuarios
                    SET password_hash = ?, password_algoritmo = "argon2id",
                        password_actualizado_en = NOW()
                  WHERE id = ?',
                [$this->password->hashear($passwordPlano), $usuarioId]
            );
            Auditoria::registrar('password.rehash_automatico', Auditoria::EXITO, $usuarioId);
        }

        // --- 6b. Segundo factor --------------------------------------
        // Se exige DESPUÉS de validar la contraseña: pedirlo antes
        // revelaría qué cuentas existen y cuáles tienen 2FA.
        if ((int) ($u['totp_activo'] ?? 0) === 1) {
            $codigo = trim($totp ?? '');

            if ($codigo === '') {
                // La contraseña era correcta, pero falta el código.
                throw new RequiereSegundoFactor(
                    'Ingrese el código de su aplicación autenticadora.',
                    $usuarioId
                );
            }

            if (!(new ServicioTotp())->verificarLogin($usuarioId, $codigo)) {
                $this->registrarFallo($usuarioId, (int) $u['intentos_fallidos']);
                Auditoria::registrar('login.totp_incorrecto', Auditoria::FALLO, $usuarioId);
                throw new CredencialesInvalidas('Código de verificación incorrecto.');
            }
        }

        // --- 7. Estado de la cuenta, DESPUÉS de autenticar -----------
        if ($u['estado'] !== 'activo') {
            Auditoria::registrar(
                'login.cuenta_no_activa',
                Auditoria::DENEGADO,
                $usuarioId,
                'usuario',
                (string) $usuarioId,
                ['estado' => $u['estado']]
            );
            throw new CuentaNoOperable(match ($u['estado']) {
                'inactivo'  => 'Su cuenta está inactiva. Comunícate con atención al cliente.',
                'bloqueado' => 'Su cuenta fue bloqueada. Comunícate con atención al cliente.',
                default     => 'Su cuenta aún no ha sido activada.',
            });
        }

        // --- 8. Sesión limpia ----------------------------------------
        Sesion::autenticar($usuarioId, (int) $u['rol_id'], (string) $u['usuario']);

        BaseDatos::ejecutar(
            'UPDATE usuarios
                SET intentos_fallidos = 0, bloqueado_hasta = NULL,
                    ultimo_acceso_en = NOW(), ultimo_acceso_ip = ?
              WHERE id = ?',
            [ClienteIp::binaria(), $usuarioId]
        );

        $this->limitador->limpiar('login:id:' . mb_strtolower($identificador));

        Auditoria::registrar('login.exito', Auditoria::EXITO, $usuarioId, 'usuario', (string) $usuarioId);

        /* El dispositivo desde el que se entró.
         *
         * Si el banco no lo había visto nunca, se le avisa al dueño en
         * el mismo minuto. Es la mitad de la protección contra el robo
         * de credenciales: quien entra con una contraseña robada no
         * puede evitar que el dueño reciba el aviso.
         *
         * Va envuelto porque es información, no autenticación: un
         * fallo aquí no puede impedirle entrar a quien ya demostró
         * quién es. */
        try {
            (new ServicioFactorOperacion())->registrarAcceso($usuarioId);
        } catch (\Throwable $e) {
            error_log('[Dispositivo] ' . $e->getMessage());
        }

        /* ¿Le toca cambiar la contraseña?
         *
         * No basta con la marca `requiere_cambio_password`: una
         * contraseña que ya cumplió su plazo también obliga a
         * cambiarla, aunque nadie haya marcado nada. Lo decide la
         * política, que es quien sabe del plazo, y de paso dice POR
         * QUÉ: a quien se le venció se le explica el plazo, y a quien
         * entra con una clave que le dieron se le dice que estrene la
         * suya. No es el mismo mensaje. */
        $deber = (new ServicioPoliticaPassword($this->password))->revisar($u);

        unset($u['password_hash'], $u['password_algoritmo']);

        return [
            'usuario'         => $u,
            'requiere_cambio' => $deber['debe'],
            'motivo_cambio'   => $deber['motivo'],
            'mensaje_cambio'  => $deber['mensaje'],
        ];
    }

    public function cerrarSesion(): void
    {
        $id = Sesion::usuarioId();
        if ($id !== null) {
            Auditoria::registrar('logout', Auditoria::EXITO, $id);
        }
        Sesion::cerrar();
    }

    /**
     * Cambia la contraseña del usuario autenticado.
     *
     * @throws CredencialesInvalidas|PoliticaPassword
     */
    public function cambiarPassword(int $usuarioId, string $actual, string $nueva, string $confirmacion): void
    {
        if ($nueva !== $confirmacion) {
            throw new PoliticaPassword(['La confirmación no coincide con la contraseña nueva.']);
        }

        /* La forma Y la historia.
         *
         * `validarPolitica()` mira si la contraseña es fuerte; la
         * política mira además si ya la usó antes. Obligar a cambiarla
         * cada dos meses sin lo segundo es teatro: el que no quiere
         * cambiarla alterna entre dos y la regla no sirvió de nada. */
        $politica = new ServicioPoliticaPassword($this->password);

        $fallos = $politica->validar($usuarioId, $nueva);

        if ($fallos !== []) {
            throw new PoliticaPassword($fallos);
        }

        $u = BaseDatos::unaFila(
            'SELECT password_hash, password_algoritmo FROM usuarios WHERE id = ?',
            [$usuarioId]
        );
        if ($u === null) {
            throw new CredencialesInvalidas('Usuario no encontrado.');
        }

        if (!$this->password->verificar($actual, (string) $u['password_hash'], (string) $u['password_algoritmo'])) {
            Auditoria::registrar('password.cambio_fallido', Auditoria::FALLO, $usuarioId);
            throw new CredencialesInvalidas('La contraseña actual no es correcta.');
        }

        if ($this->password->verificar($nueva, (string) $u['password_hash'], (string) $u['password_algoritmo'])) {
            throw new PoliticaPassword(['La contraseña nueva debe ser distinta de la actual.']);
        }

        $hash = $this->password->hashear($nueva);

        BaseDatos::ejecutar(
            'UPDATE usuarios
                SET password_hash = ?, password_algoritmo = "argon2id",
                    password_actualizado_en = NOW(), requiere_cambio_password = 0
              WHERE id = ?',
            [$hash, $usuarioId]
        );

        /* Queda apuntada para que no se pueda volver a ella. El hash es
           el mismo que acaba de guardarse arriba: cuando deje de ser la
           actual, seguirá contando como usada. */
        $politica->recordar($usuarioId, $hash);

        // Cerrar las demás sesiones: si la contraseña cambió porque
        // estaba comprometida, hay que expulsar a quien la tuviera.
        Sesion::revocarTodas($usuarioId, 'cambio_password');

        Auditoria::registrar('password.cambiada', Auditoria::EXITO, $usuarioId);
    }

    private function registrarFallo(int $usuarioId, int $intentosPrevios): void
    {
        $intentos = $intentosPrevios + 1;
        $maximo   = Config::entero('LOGIN_MAX_INTENTOS', 5);

        if ($intentos >= $maximo) {
            $bloqueo = Config::entero('LOGIN_BLOQUEO_SEG', 900);
            BaseDatos::ejecutar(
                'UPDATE usuarios
                    SET intentos_fallidos = ?,
                        bloqueado_hasta = DATE_ADD(NOW(), INTERVAL ? SECOND)
                  WHERE id = ?',
                [$intentos, $bloqueo, $usuarioId]
            );
            Auditoria::registrar(
                'login.bloqueo_automatico',
                Auditoria::DENEGADO,
                $usuarioId,
                'usuario',
                (string) $usuarioId,
                ['intentos' => $intentos]
            );
        } else {
            BaseDatos::ejecutar(
                'UPDATE usuarios SET intentos_fallidos = ? WHERE id = ?',
                [$intentos, $usuarioId]
            );
        }
    }

    public function servicioPassword(): ServicioPassword
    {
        return $this->password;
    }
}
