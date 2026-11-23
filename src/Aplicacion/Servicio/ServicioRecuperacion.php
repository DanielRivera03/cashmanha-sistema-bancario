<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\LimitadorTasa;
use CashMan\Infraestructura\Seguridad\ServicioPassword;

/**
 * Recuperación de contraseña.
 *
 * El diseño responde a tres problemas que tiene el flujo habitual:
 *
 *   1. **Enumeración de cuentas.** Pedir recuperación de un correo
 *      inexistente debe responder exactamente igual —mismo texto y
 *      tiempo parecido— que uno existente. Si no, el formulario se
 *      convierte en un verificador de qué direcciones están dadas de
 *      alta, que es el primer paso de cualquier ataque dirigido.
 *
 *   2. **Enlaces que se filtran.** Un enlace que por sí solo cambia la
 *      contraseña se escapa por el historial, por el Referer, por el
 *      proxy corporativo o por el propio buzón si alguien lo tiene
 *      abierto. Aquí el enlace NO basta: lleva un identificador de la
 *      solicitud, y además hay que escribir un código de seis dígitos
 *      que viaja en el cuerpo del mensaje. Son dos piezas distintas.
 *
 *   3. **Fuerza bruta sobre el código.** Seis dígitos son un millón de
 *      combinaciones; sin límite caen en minutos. El contador de
 *      intentos vive en la fila de la solicitud, no en la sesión, así
 *      que no se reinicia borrando cookies.
 *
 * Ni el token ni el código se guardan en claro: en la base solo hay
 * SHA-256 de ambos. Quien lea la tabla no puede fabricar un enlace
 * válido.
 */
final class ServicioRecuperacion
{
    /** Vigencia de la solicitud. */
    private const MINUTOS = 30;

    /** Intentos de código antes de anular la solicitud. */
    private const MAX_INTENTOS = 5;

    /**
     * Crea una solicitud y manda el correo.
     *
     * No informa de nada al llamador: devuelve void a propósito, para
     * que al controlador le resulte imposible filtrar si el correo
     * existía. Si algo falla, queda en la auditoría.
     */
    public function solicitar(string $correo): void
    {
        $correo = mb_strtolower(trim($correo));
        $ip     = ClienteIp::binaria();

        // Dos límites distintos. El de IP frena a quien recorre una
        // lista de correos; el de correo evita que se pueda inundar el
        // buzón de una persona concreta aunque sea desde muchas IP.
        (new LimitadorTasa(10, 3600, 3600))->exigir('recuperar:ip:' . ClienteIp::obtener());
        (new LimitadorTasa(4, 3600, 3600))->exigir('recuperar:correo:' . hash('sha256', $correo));

        if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $usuario = BaseDatos::unaFila(
            'SELECT id, usuario, correo, nombres, apellidos, estado
               FROM usuarios
              WHERE correo = ?',
            [$correo]
        );

        // Se genera el par incluso cuando no hay usuario: así el tiempo
        // de respuesta no delata la diferencia.
        $token  = bin2hex(random_bytes(32));
        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        if ($usuario === null) {
            Auditoria::registrar(
                'recuperacion.correo_desconocido', Auditoria::DENEGADO, null,
                'usuario', '', ['correo_hash' => substr(hash('sha256', $correo), 0, 16)]
            );
            return;
        }

        // Una cuenta bloqueada o inactiva no se recupera sola: que el
        // enlace funcionara sería una forma de reactivarla sin pasar
        // por quien la bloqueó.
        if ((string) $usuario['estado'] !== 'activo') {
            Auditoria::registrar(
                'recuperacion.cuenta_no_activa', Auditoria::DENEGADO,
                (int) $usuario['id'], 'usuario', (string) $usuario['id'],
                ['estado' => $usuario['estado']]
            );
            return;
        }

        // Pedir una nueva solicitud invalida las anteriores: si no, un
        // enlace viejo seguiría sirviendo después de que el titular
        // reaccionara pidiendo otro.
        BaseDatos::ejecutar(
            'UPDATE recuperaciones
                SET usado_en = NOW()
              WHERE usuario_id = ? AND usado_en IS NULL',
            [(int) $usuario['id']]
        );

        BaseDatos::ejecutar(
            'INSERT INTO recuperaciones
               (usuario_id, correo, token_hash, codigo_hash, ip, expira_en)
             VALUES (?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
            [
                (int) $usuario['id'],
                $correo,
                hash('sha256', $token),
                hash('sha256', $codigo),
                $ip,
                self::MINUTOS,
            ]
        );

        $nombre = trim((string) $usuario['nombres'] . ' ' . (string) $usuario['apellidos']);
        $url    = rtrim(Config::obtener('APP_URL', ''), '/') . '/restablecer/' . $token;

        $html = PlantillaCorreo::base(
            'Restablece su contraseña',
            'Hola ' . $nombre . ',',
            '<p>Recibimos una solicitud para restablecer la contraseña de su banca '
            . 'en línea. Abre el enlace y escribe el código que aparece abajo.</p>'
            . '<p>Si no fue usted, <strong>ignora este mensaje</strong>: su contraseña '
            . 'actual sigue siendo válida y nadie podrá cambiarla sin este código.</p>',
            [
                ['etiqueta' => 'Usuario',   'valor' => (string) $usuario['usuario']],
                ['etiqueta' => 'Solicitud', 'valor' => 'desde ' . ClienteIp::obtener()],
                ['etiqueta' => 'Vence',     'valor' => 'en ' . self::MINUTOS . ' minutos'],
            ],
            $codigo,
            'Restablecer mi contraseña',
            $url,
            'El enlace y el código funcionan una sola vez y juntos. Nunca comparta '
            . 'el código: ningún empleado de CashMan te lo pedirá.'
        );

        (new Correo())->enviar(
            $correo,
            'Restablece su contraseña · CashMan H.A.',
            $html,
            '',
            $nombre
        );

        Auditoria::registrar(
            'recuperacion.solicitada', Auditoria::EXITO,
            (int) $usuario['id'], 'usuario', (string) $usuario['id']
        );
    }

    /**
     * Comprueba que el enlace siga vivo, sin consumirlo.
     *
     * @return array{id:int,usuario_id:int,correo:string,nombre:string}|null
     */
    public function solicitudDe(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $f = BaseDatos::unaFila(
            'SELECT r.id, r.usuario_id, r.correo, r.intentos,
                    CONCAT(u.nombres," ",u.apellidos) AS nombre
               FROM recuperaciones r
               JOIN usuarios u ON u.id = r.usuario_id
              WHERE r.token_hash = ?
                AND r.usado_en IS NULL
                AND r.expira_en > NOW()
                AND r.intentos < ?
                AND u.estado = "activo"',
            [hash('sha256', $token), self::MAX_INTENTOS]
        );

        if ($f === null) {
            return null;
        }

        return [
            'id'         => (int) $f['id'],
            'usuario_id' => (int) $f['usuario_id'],
            'correo'     => (string) $f['correo'],
            'nombre'     => (string) $f['nombre'],
        ];
    }

    /**
     * Aplica la contraseña nueva.
     *
     * @throws CredencialesInvalidas enlace vencido, código errado
     * @throws PoliticaPassword      la contraseña no cumple las reglas
     */
    public function confirmar(string $token, string $codigo, string $nueva): string
    {
        (new LimitadorTasa(15, 900, 1800))->exigir('restablecer:' . ClienteIp::obtener());

        $sol = $this->solicitudDe($token);

        if ($sol === null) {
            throw new CredencialesInvalidas(
                'El enlace venció o ya fue utilizado. Solicita uno nuevo.'
            );
        }

        $codigo = preg_replace('/\D/', '', $codigo) ?? '';

        // El intento se anota ANTES de comparar. Si se anotara después,
        // bastaría con cortar la conexión al ver que falla para que no
        // contara.
        BaseDatos::ejecutar(
            'UPDATE recuperaciones SET intentos = intentos + 1 WHERE id = ?',
            [$sol['id']]
        );

        $guardado = (string) BaseDatos::unValor(
            'SELECT codigo_hash FROM recuperaciones WHERE id = ?',
            [$sol['id']]
        );

        if (!hash_equals($guardado, hash('sha256', $codigo))) {
            Auditoria::registrar(
                'recuperacion.codigo_incorrecto', Auditoria::DENEGADO,
                $sol['usuario_id'], 'usuario', (string) $sol['usuario_id']
            );
            throw new CredencialesInvalidas('El código no es correcto.');
        }

        // El código era correcto: se limpia el contador. Quien lo tiene
        // ya demostró acceso al buzón, y lo que viene después —elegir
        // una contraseña que cumpla las reglas— puede costarle varios
        // intentos legítimos. Sin esto, equivocarse cinco veces al
        // teclear la contraseña nueva anulaba el enlace.
        BaseDatos::ejecutar(
            'UPDATE recuperaciones SET intentos = 0 WHERE id = ?',
            [$sol['id']]
        );

        // La política se valida aquí y no solo en el navegador: el
        // formulario es sugerencia, esto es la regla.
        $password = new ServicioPassword();
        $fallos   = $password->validarPolitica($nueva);

        if ($fallos !== []) {
            throw new PoliticaPassword($fallos);
        }

        // Reutilizar la contraseña anterior vacía el sentido del
        // restablecimiento, así que se rechaza.
        $hashActual = (string) BaseDatos::unValor(
            'SELECT password_hash FROM usuarios WHERE id = ?',
            [$sol['usuario_id']]
        );

        if ($hashActual !== '' && $password->verificar($nueva, $hashActual)) {
            throw new PoliticaPassword(['La contraseña nueva debe ser distinta de la anterior.']);
        }

        BaseDatos::enTransaccion(static function () use ($sol, $password, $nueva): void {
            BaseDatos::ejecutar(
                'UPDATE usuarios
                    SET password_hash      = ?,
                        password_algoritmo = "argon2id",
                        password_actualizado_en = NOW(),
                        requiere_cambio_password = 0,
                        intentos_fallidos  = 0,
                        bloqueado_hasta    = NULL
                  WHERE id = ?',
                [$password->hashear($nueva), $sol['usuario_id']]
            );

            BaseDatos::ejecutar(
                'UPDATE recuperaciones SET usado_en = NOW() WHERE id = ?',
                [$sol['id']]
            );

            // Quien tuviera la sesión abierta con la contraseña vieja
            // —incluido quien forzó el restablecimiento— queda fuera.
            BaseDatos::ejecutar(
                'UPDATE sesiones
                    SET revocada_en = NOW(),
                        motivo_revocacion = "restablecimiento_password"
                  WHERE usuario_id = ? AND revocada_en IS NULL',
                [$sol['usuario_id']]
            );
        });

        Auditoria::registrar(
            'recuperacion.completada', Auditoria::EXITO,
            $sol['usuario_id'], 'usuario', (string) $sol['usuario_id']
        );

        // Aviso al titular: si el cambio no fue cosa suya, es el único
        // momento en que puede enterarse a tiempo.
        (new Correo())->enviar(
            $sol['correo'],
            'Su contraseña fue cambiada · CashMan H.A.',
            PlantillaCorreo::alertaSeguridad(
                $sol['nombre'],
                'Restablecimiento de contraseña',
                ClienteIp::obtener(),
                date('d/m/Y H:i')
            ),
            '',
            $sol['nombre']
        );

        return $sol['nombre'];
    }
}
