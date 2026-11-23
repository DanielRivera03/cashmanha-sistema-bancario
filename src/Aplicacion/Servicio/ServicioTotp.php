<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Cifrado;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\Sesion;
use CashMan\Infraestructura\Seguridad\Totp;

/**
 * Alta, verificación y baja del segundo factor TOTP.
 *
 * El secreto se guarda cifrado con AES-256-GCM, atado al id del
 * usuario como dato asociado: un secreto copiado a otra fila no
 * descifra.
 */
final class ServicioTotp
{
    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    private function cifra(): Cifrado
    {
        return new Cifrado(Config::claveBinaria('APP_CLAVE'));
    }

    /**
     * Paso 1: genera el secreto y lo deja pendiente de confirmación.
     *
     * El secreto no se activa hasta que el usuario demuestre que su
     * app lo guardó bien, escribiendo un código válido.
     *
     * @return array{secreto:string, legible:string, uri:string, qr:string}
     */
    /**
     * El secreto de un alta a medias, si lo hay y sigue siendo legible.
     *
     * Devuelve null cuando no hay ninguno guardado o cuando el que
     * hay no se puede descifrar. Lo segundo pasa si cambio la clave
     * del sistema: ese secreto ya no vale para nada y lo correcto es
     * empezar de cero, no reventar en la cara del usuario.
     */
    private function secretoPendiente(int $usuarioId): ?string
    {
        $guardado = BaseDatos::unValor(
            'SELECT totp_secreto_cifrado FROM usuarios
              WHERE id = ? AND totp_activo = 0 AND totp_secreto_cifrado IS NOT NULL',
            [$usuarioId]
        );

        if ($guardado === null || (string) $guardado === '') {
            return null;
        }

        try {
            $secreto = $this->cifra()->descifrar((string) $guardado, 'totp:' . $usuarioId);
        } catch (\Throwable) {
            return null;
        }

        /* Y que sea un secreto de verdad: si lo guardado no es
           Base32 valido, la aplicacion del telefono no podria
           usarlo y mas vale generar otro. */
        return preg_match('/^[A-Z2-7]{16,}$/', $secreto) === 1 ? $secreto : null;
    }

    public function iniciarAlta(): array
    {
        $usuarioId = Sesion::exigirAutenticado();

        $u = BaseDatos::unaFila(
            'SELECT usuario, correo, totp_activo FROM usuarios WHERE id = ?',
            [$usuarioId]
        );
        if ($u === null) {
            throw new \RuntimeException('Usuario no encontrado.');
        }
        if ((int) $u['totp_activo'] === 1) {
            throw new \RuntimeException('Ya tiene el segundo factor activo.');
        }

        /* SE REUTILIZA EL SECRETO PENDIENTE, si lo hay.
         *
         * Antes, cada pulsación de «Activar» generaba uno nuevo. Quien
         * volvía a la pantalla —o lo intentaba otra vez porque la
         * primera no había funcionado— acababa con cinco entradas
         * distintas de este banco en su aplicación autenticadora,
         * cuatro de ellas muertas y sin forma de saber cuál servía.
         *
         * Mientras el alta no se confirme, el secreto es el mismo: al
         * escanear de nuevo, la aplicación reconoce la misma cuenta en
         * lugar de añadir otra. Solo se genera uno nuevo si no hay
         * ninguno pendiente, o si el guardado ya no se puede descifrar
         * —porque cambió la clave del sistema— y entonces no sirve.
         *
         * Esto no debilita nada: un secreto a medio dar de alta no
         * protege todavía ninguna cuenta, y se sustituye entero en
         * cuanto alguien desactiva el segundo factor. */
        $secreto = $this->secretoPendiente($usuarioId);

        if ($secreto === null) {
            $secreto = Totp::generarSecreto();

            BaseDatos::ejecutar(
                'UPDATE usuarios
                    SET totp_secreto_cifrado = ?, totp_activo = 0, totp_confirmado_en = NULL
                  WHERE id = ?',
                [$this->cifra()->cifrar($secreto, 'totp:' . $usuarioId), $usuarioId]
            );

            Auditoria::registrar('totp.alta_iniciada', Auditoria::EXITO, $usuarioId);
        }

        $uri = Totp::uri($secreto, (string) $u['usuario']);

        return [
            'secreto' => $secreto,
            'legible' => Totp::secretoLegible($secreto),
            'uri'     => $uri,
            // QR generado en el navegador a partir de la URI, para no
            // enviar el secreto a ningún servicio externo.
            'qr'      => $uri,
        ];
    }

    /**
     * Paso 2: confirma el alta con un código de la app.
     *
     * @return array{codigos_respaldo:array<int,string>}
     */
    /**
     * Cuantos segundos va desfasado el reloj del telefono.
     *
     * Busca el codigo en una ventana de cuatro minutos a cada lado
     * y devuelve el desplazamiento del periodo donde encaja. Null si
     * no encaja en ninguno: entonces el codigo es de otra cuenta o
     * esta sencillamente mal, y no hay desfase que medir.
     *
     * Cuatro minutos cubren el desajuste tipico de un telefono sin
     * hora automatica. Mas alla de eso lo razonable es que el
     * cliente ponga su reloj en hora, y el mensaje se lo dice.
     */
    private function medirDesfase(string $secreto, string $codigo): ?int
    {
        $codigo = preg_replace('/\D/', '', $codigo) ?? '';

        if (strlen($codigo) !== 6) { return null; }

        $ahora = time();

        /* Del mas cercano al mas lejano: si por casualidad el mismo
           codigo apareciera dos veces, se queda el desfase menor. */
        for ($p = 0; $p <= 8; $p++) {
            foreach ($p === 0 ? [0] : [$p, -$p] as $d) {
                if (hash_equals(Totp::codigo($secreto, $ahora + $d * 30), $codigo)) {
                    return $d * 30;
                }
            }
        }

        return null;
    }

    public function confirmarAlta(string $codigo): array
    {
        $usuarioId = Sesion::exigirAutenticado();

        $u = BaseDatos::unaFila(
            'SELECT totp_secreto_cifrado, totp_activo, nombres, correo
               FROM usuarios WHERE id = ?',
            [$usuarioId]
        );

        if ($u === null || $u['totp_secreto_cifrado'] === null) {
            throw new \RuntimeException('No hay un alta en curso. Por favor, vuelva a empezar.');
        }
        if ((int) $u['totp_activo'] === 1) {
            throw new \RuntimeException('El segundo factor ya está activo.');
        }

        $secreto  = $this->cifra()->descifrar((string) $u['totp_secreto_cifrado'], 'totp:' . $usuarioId);

        /* EL DESFASE DEL RELOJ, medido aqui y una sola vez.
         *
         * Los codigos se calculan a partir de la hora. Si el reloj
         * del telefono no coincide con el del servidor, los numeros
         * tampoco, y el sistema rechaza un codigo que la aplicacion
         * del cliente muestra como bueno. Ocurrio con tres minutos de
         * diferencia.
         *
         * Aqui se puede buscar en una ventana amplia sin regalar
         * nada: el cliente YA esta autenticado, el secreto acaba de
         * crearse para el, y no hay ningun atacante a quien ayudar.
         * Lo que se mide se guarda, y el login vuelve a su ventana
         * estrecha de siempre contra la hora del telefono. */
        $desfase  = $this->medirDesfase($secreto, $codigo);
        $contador = Totp::verificar($secreto, $codigo, null, $desfase ?? 0);

        if ($contador === null) {
            Auditoria::registrar('totp.confirmacion_fallida', Auditoria::FALLO, $usuarioId);
            throw new \RuntimeException(
                'El código no es válido. Revise que la hora de su teléfono esté correcta.'
            );
        }

        // Códigos de respaldo: la salida si se pierde el teléfono.
        $respaldo = Totp::generarRespaldo(8);

        $desfaseGuardar = (int) ($desfase ?? 0);

        BaseDatos::enTransaccion(function () use ($usuarioId, $contador, $respaldo, $desfaseGuardar): void {
            BaseDatos::ejecutar(
                'UPDATE usuarios
                    SET totp_activo = 1, totp_confirmado_en = NOW(), totp_ultimo_contador = ?,
                        totp_desfase_seg = ?
                  WHERE id = ?',
                [$contador, $desfaseGuardar, $usuarioId]
            );

            BaseDatos::ejecutar('DELETE FROM totp_respaldo WHERE usuario_id = ?', [$usuarioId]);

            foreach ($respaldo as $c) {
                BaseDatos::ejecutar(
                    'INSERT INTO totp_respaldo (usuario_id, codigo_hash) VALUES (?,?)',
                    [$usuarioId, $this->hashRespaldo($c, $usuarioId)]
                );
            }
        });

        Auditoria::registrar('totp.activado', Auditoria::EXITO, $usuarioId);

        $this->notificaciones->crear(
            $usuarioId, 'seguridad.totp', 'Segundo factor activado',
            'Su cuenta ahora pide un código de su aplicación autenticadora al entrar.',
            null, 'exito'
        );

        // Los códigos se devuelven UNA vez: en la base solo queda el hash.
        return ['codigos_respaldo' => $respaldo];
    }

    /**
     * Verifica un código durante el login.
     *
     * Acepta tanto un código de la app como uno de respaldo.
     */
    public function verificarLogin(int $usuarioId, string $codigo): bool
    {
        $u = BaseDatos::unaFila(
            'SELECT totp_secreto_cifrado, totp_activo, totp_ultimo_contador, totp_desfase_seg
               FROM usuarios WHERE id = ?',
            [$usuarioId]
        );

        if ($u === null || (int) $u['totp_activo'] !== 1) {
            return true;   // sin segundo factor configurado
        }

        $codigo = trim($codigo);

        // ¿Es un código de respaldo? Formato XXXXX-XXXXX.
        if (preg_match('/^[A-F0-9]{5}-[A-F0-9]{5}$/i', $codigo)) {
            return $this->consumirRespaldo($usuarioId, strtoupper($codigo));
        }

        $secreto  = $this->cifra()->descifrar((string) $u['totp_secreto_cifrado'], 'totp:' . $usuarioId);
        $ultimo   = $u['totp_ultimo_contador'] !== null ? (int) $u['totp_ultimo_contador'] : null;

        /* Contra la hora del TELEFONO del cliente, que es la que su
           aplicacion usa para calcular el codigo. El desfase se midio
           al dar de alta; la ventana sigue siendo la de siempre. */
        $contador = Totp::verificar(
            $secreto,
            $codigo,
            $ultimo,
            (int) ($u['totp_desfase_seg'] ?? 0)
        );

        if ($contador === null) {
            Auditoria::registrar('totp.codigo_invalido', Auditoria::FALLO, $usuarioId);
            return false;
        }

        // Marcar el periodo como consumido: el mismo código no sirve
        // dos veces aunque siga dentro de la ventana de tiempo.
        BaseDatos::ejecutar(
            'UPDATE usuarios SET totp_ultimo_contador = ? WHERE id = ?',
            [$contador, $usuarioId]
        );

        Auditoria::registrar('totp.verificado', Auditoria::EXITO, $usuarioId);
        return true;
    }

    public function desactivar(string $codigo): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        // Desactivar el segundo factor también exige el segundo factor:
        // si no, alguien con la sesión abierta podría quitarlo.
        if (!$this->verificarLogin($usuarioId, $codigo)) {
            throw new \RuntimeException('Código incorrecto. No se desactivó el segundo factor.');
        }

        BaseDatos::enTransaccion(function () use ($usuarioId): void {
            BaseDatos::ejecutar(
                'UPDATE usuarios
                    SET totp_activo = 0, totp_secreto_cifrado = NULL,
                        totp_confirmado_en = NULL, totp_ultimo_contador = NULL
                  WHERE id = ?',
                [$usuarioId]
            );
            BaseDatos::ejecutar('DELETE FROM totp_respaldo WHERE usuario_id = ?', [$usuarioId]);
        });

        Auditoria::registrar('totp.desactivado', Auditoria::EXITO, $usuarioId);

        $this->notificaciones->crear(
            $usuarioId, 'seguridad.totp', 'Segundo factor desactivado',
            'Su cuenta ya no pide código al entrar. Si no fue usted, actívalo de nuevo y cambia su contraseña.',
            null, 'alerta'
        );
    }

    /** @return array{activo:bool, confirmado_en:?string, respaldo_disponibles:int} */
    public function estado(?int $usuarioId = null): array
    {
        $usuarioId ??= Sesion::exigirAutenticado();

        $u = BaseDatos::unaFila(
            'SELECT totp_activo, totp_confirmado_en FROM usuarios WHERE id = ?',
            [$usuarioId]
        );

        $disponibles = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM totp_respaldo WHERE usuario_id = ? AND usado_en IS NULL',
            [$usuarioId]
        );

        return [
            'activo'               => (int) ($u['totp_activo'] ?? 0) === 1,
            'confirmado_en'        => $u['totp_confirmado_en'] ?? null,
            'respaldo_disponibles' => $disponibles,
        ];
    }

    /** @return array<int,string> */
    public function regenerarRespaldo(string $codigo): array
    {
        $usuarioId = Sesion::exigirAutenticado();

        if (!$this->verificarLogin($usuarioId, $codigo)) {
            throw new \RuntimeException('Código incorrecto.');
        }

        $respaldo = Totp::generarRespaldo(8);

        BaseDatos::enTransaccion(function () use ($usuarioId, $respaldo): void {
            BaseDatos::ejecutar('DELETE FROM totp_respaldo WHERE usuario_id = ?', [$usuarioId]);
            foreach ($respaldo as $c) {
                BaseDatos::ejecutar(
                    'INSERT INTO totp_respaldo (usuario_id, codigo_hash) VALUES (?,?)',
                    [$usuarioId, $this->hashRespaldo($c, $usuarioId)]
                );
            }
        });

        Auditoria::registrar('totp.respaldo_regenerado', Auditoria::EXITO, $usuarioId);

        return $respaldo;
    }

    // -----------------------------------------------------------------

    private function consumirRespaldo(int $usuarioId, string $codigo): bool
    {
        return BaseDatos::enTransaccion(function () use ($usuarioId, $codigo): bool {
            $fila = BaseDatos::unaFila(
                'SELECT id FROM totp_respaldo
                  WHERE usuario_id = ? AND codigo_hash = ? AND usado_en IS NULL
                  FOR UPDATE',
                [$usuarioId, $this->hashRespaldo($codigo, $usuarioId)]
            );

            if ($fila === null) {
                Auditoria::registrar('totp.respaldo_invalido', Auditoria::FALLO, $usuarioId);
                return false;
            }

            BaseDatos::ejecutar(
                'UPDATE totp_respaldo SET usado_en = NOW(), usado_ip = ? WHERE id = ?',
                [ClienteIp::binaria(), $fila['id']]
            );

            $quedan = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM totp_respaldo WHERE usuario_id = ? AND usado_en IS NULL',
                [$usuarioId]
            );

            Auditoria::registrar(
                'totp.respaldo_usado', Auditoria::EXITO, $usuarioId,
                'usuario', (string) $usuarioId, ['restantes' => $quedan]
            );

            $this->notificaciones->crear(
                $usuarioId, 'seguridad.respaldo', 'Código de respaldo utilizado',
                'Se usó un código de respaldo para entrar. Le quedan ' . $quedan . '.',
                null, $quedan <= 2 ? 'alerta' : 'info'
            );

            return true;
        });
    }

    /** El hash incluye el id: un código de otro usuario no sirve aquí. */
    private function hashRespaldo(string $codigo, int $usuarioId): string
    {
        return hash_hmac(
            'sha256',
            strtoupper($codigo) . '|' . $usuarioId,
            Config::claveBinaria('PASSWORD_PEPPER')
        );
    }
}
