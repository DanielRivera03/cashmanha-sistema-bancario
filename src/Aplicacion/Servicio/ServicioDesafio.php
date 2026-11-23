<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

/**
 * Códigos de un solo uso (OTP).
 *
 * El sistema anterior tenía cuatro problemas acumulados:
 *   - El código se generaba con rand(), no criptográfico y predecible.
 *   - Se guardaba en claro en la tabla.
 *   - Nunca se marcaba como consumido: servía una y otra vez.
 *   - No estaba atado a ninguna operación concreta.
 *
 * Y el peor de todos: se validaba solo para decidir si dibujar el campo
 * del monto. El endpoint que ejecutaba la transferencia jamás lo miraba.
 *
 * Aquí:
 *   - random_int(), criptográficamente seguro.
 *   - Se guarda el HASH del código, nunca el código.
 *   - Un solo uso, con caducidad y máximo de intentos.
 *   - Atado al contexto: un código emitido para transferir $100 a la
 *     cuenta X no sirve para transferir $5,000 a la cuenta Y.
 */
final class ServicioDesafio
{
    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    /**
     * Emite un código y lo entrega por el canal correspondiente.
     *
     * @return array{expira_en:string, enviado_a:string, por_correo:bool, mensaje:string}
     */
    public function emitir(
        int $usuarioId,
        string $proposito,
        ?string $contextoHash = null,
        ?string $descripcionOperacion = null
    ): array {
        // Invalidar los códigos previos del mismo propósito: evita que
        // queden varios vivos a la vez.
        BaseDatos::ejecutar(
            'UPDATE desafios SET consumido_en = NOW()
              WHERE usuario_id = ? AND proposito = ? AND consumido_en IS NULL',
            [$usuarioId, $proposito]
        );

        // random_int() usa el generador criptográfico del sistema.
        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $vigencia = Config::entero('OTP_VIGENCIA_SEG', 300);
        $expira   = (new \DateTimeImmutable())->modify("+{$vigencia} seconds");

        BaseDatos::ejecutar(
            'INSERT INTO desafios
               (usuario_id, proposito, codigo_hash, max_intentos, contexto_hash, expira_en)
             VALUES (?,?,?,?,?,?)',
            [
                $usuarioId,
                $proposito,
                $this->hashCodigo($codigo, $usuarioId),
                Config::entero('OTP_MAX_INTENTOS', 3),
                $contextoHash,
                $expira->format('Y-m-d H:i:s'),
            ]
        );

        $usuario = BaseDatos::unaFila('SELECT correo, nombres FROM usuarios WHERE id = ?', [$usuarioId]);
        $correo  = (string) ($usuario['correo'] ?? '');

        $enviado = $this->entregar($usuarioId, $correo, $codigo, $proposito, $descripcionOperacion, $expira);

        Auditoria::registrar(
            'otp.emitido',
            Auditoria::EXITO,
            $usuarioId,
            'desafio',
            $proposito
        );

        $enmascarado = $this->enmascararCorreo($correo);

        return [
            'expira_en'  => $expira->format('c'),
            'enviado_a'  => $enmascarado,
            'por_correo' => $enviado,
            /* El código SIEMPRE queda en la campanita (lo crea entregar()),
               tenga o no correo. Por eso el mensaje puede prometerlo sin
               mentir. Cuando el correo está apagado —como en este
               despliegue— la campanita es la ÚNICA vía, y decirlo evita
               que el cliente espere un correo que nunca llega y queme sus
               intentos con códigos inventados. */
            'mensaje'    => $enviado
                ? "Le enviamos un código a su correo {$enmascarado} y a sus notificaciones (la campana)."
                : 'Su código de verificación está en sus notificaciones (la campana, arriba a la derecha); use el más reciente.',
        ];
    }

    /**
     * Valida y consume el código.
     *
     * @throws DesafioInvalido
     */
    public function consumir(int $usuarioId, string $proposito, string $codigo, ?string $contextoHash = null): void
    {
        $codigo = trim($codigo);

        if ($codigo === '' || !preg_match('/^\d{6}$/', $codigo)) {
            throw new DesafioInvalido('Debe ingresar el código de 6 dígitos.');
        }

        /* El veredicto se decide y se PERSISTE dentro de la transacción,
           y el error se lanza DESPUÉS de confirmarla.

           Antes el `throw` salía desde dentro de `enTransaccion`, que al
           capturar la excepción hace ROLLBACK: el `intentos = intentos + 1`
           del código incorrecto se deshacía junto con ella. Efecto: el
           contador nunca subía, "le quedan N intentos" se quedaba clavado,
           el tope de intentos jamás se alcanzaba —un código se podía
           probar sin límite— y los registros de auditoría de los fallos
           también se borraban en el rollback. Aquí la transacción SIEMPRE
           confirma el cambio de estado (el incremento, o el consumido), y
           recién entonces, ya fuera de ella, se lanza el error. */
        $error = BaseDatos::enTransaccion(function () use ($usuarioId, $proposito, $codigo, $contextoHash): ?string {
            $d = BaseDatos::unaFila(
                'SELECT id, codigo_hash, intentos, max_intentos, contexto_hash, expira_en
                   FROM desafios
                  WHERE usuario_id = ? AND proposito = ? AND consumido_en IS NULL
                  ORDER BY id DESC LIMIT 1
                  FOR UPDATE',
                [$usuarioId, $proposito]
            );

            if ($d === null) {
                Auditoria::registrar('otp.sin_codigo_activo', Auditoria::FALLO, $usuarioId, 'desafio', $proposito);
                return 'No hay un código activo. Solicita uno nuevo.';
            }

            if (new \DateTimeImmutable((string) $d['expira_en']) < new \DateTimeImmutable()) {
                BaseDatos::ejecutar('UPDATE desafios SET consumido_en = NOW() WHERE id = ?', [$d['id']]);
                return 'El código expiró. Solicita uno nuevo.';
            }

            if ((int) $d['intentos'] >= (int) $d['max_intentos']) {
                BaseDatos::ejecutar('UPDATE desafios SET consumido_en = NOW() WHERE id = ?', [$d['id']]);
                Auditoria::registrar('otp.intentos_agotados', Auditoria::DENEGADO, $usuarioId, 'desafio', $proposito);
                return 'Agotaste los intentos. Solicita un código nuevo.';
            }

            // El código debe corresponder a ESTA operación.
            if ($d['contexto_hash'] !== null
                && !hash_equals((string) $d['contexto_hash'], (string) $contextoHash)) {
                BaseDatos::ejecutar('UPDATE desafios SET consumido_en = NOW() WHERE id = ?', [$d['id']]);
                Auditoria::registrar(
                    'otp.contexto_no_coincide',
                    Auditoria::DENEGADO,
                    $usuarioId,
                    'desafio',
                    $proposito
                );
                return 'El código no corresponde a esta operación. Reinicia el proceso.';
            }

            if (!hash_equals((string) $d['codigo_hash'], $this->hashCodigo($codigo, $usuarioId))) {
                BaseDatos::ejecutar('UPDATE desafios SET intentos = intentos + 1 WHERE id = ?', [$d['id']]);
                $restantes = (int) $d['max_intentos'] - (int) $d['intentos'] - 1;
                Auditoria::registrar('otp.codigo_incorrecto', Auditoria::FALLO, $usuarioId, 'desafio', $proposito);
                return 'Código incorrecto.' . ($restantes > 0 ? " Le quedan {$restantes} intentos." : '');
            }

            // Un solo uso: se marca consumido dentro de la misma transacción.
            BaseDatos::ejecutar('UPDATE desafios SET consumido_en = NOW() WHERE id = ?', [$d['id']]);

            Auditoria::registrar('otp.consumido', Auditoria::EXITO, $usuarioId, 'desafio', $proposito);

            return null;
        });

        if ($error !== null) {
            throw new DesafioInvalido($error);
        }
    }

    /** El hash incluye el id de usuario: el mismo código no vale para otro. */
    private function hashCodigo(string $codigo, int $usuarioId): string
    {
        return hash_hmac(
            'sha256',
            $codigo . '|' . $usuarioId,
            Config::claveBinaria('APP_CLAVE')
        );
    }

    private function entregar(
        int $usuarioId,
        string $correo,
        string $codigo,
        string $proposito,
        ?string $descripcion,
        \DateTimeImmutable $expira
    ): bool {
        $titulo = match ($proposito) {
            'transferencia' => 'Código para tu transferencia',
            'pago_tarjeta'  => 'Código para el pago de tarjeta',
            'recuperacion'  => 'Código de recuperación',
            default         => 'Código de seguridad',
        };

        $minutos = max(1, (int) round(($expira->getTimestamp() - time()) / 60));

        // --- Envío real por SMTP --------------------------------------
        // Si falla, NO se interrumpe la operación: el usuario todavía
        // ve el código en pantalla y puede reintentar el envío.
        $nombre = trim((string) (BaseDatos::unValor(
            'SELECT nombres FROM usuarios WHERE id = ?',
            [$usuarioId]
        ) ?? ''));

        $enviado = (new \CashMan\Infraestructura\Notificacion\Correo())->enviar(
            $correo,
            $titulo,
            \CashMan\Infraestructura\Notificacion\PlantillaCorreo::codigoSeguridad(
                $nombre !== '' ? $nombre : 'cliente',
                $codigo,
                $descripcion ?? 'Verificación de identidad',
                $minutos
            ),
            '',
            $nombre
        );

        if ($enviado) {
            Auditoria::registrar('otp.correo_enviado', Auditoria::EXITO, $usuarioId, 'desafio', $proposito);
        }

        /* El aviso queda en la campanita, y queda para siempre.
           Es el comprobante de que se pidio un codigo, cuando y para
           que: eso es lo que sirve despues para reclamar o auditar.
           El codigo va dentro porque es de un solo uso y caduca en
           minutos; pasado ese rato el aviso es historia, no una
           credencial. Y ademas sale por correo, que es el canal que
           deja constancia fuera de este sistema. */
        $this->notificaciones->crear(
            $usuarioId,
            'otp.' . $proposito,
            $titulo,
            "Código: {$codigo} — válido {$minutos} minuto(s). "
            . ($descripcion !== null && $descripcion !== '' ? $descripcion . '. ' : '')
            . ($enviado
                ? 'Tambien se lo enviamos a ' . $this->enmascararCorreo($correo) . '.'
                : 'No se pudo enviar el correo; use el codigo de este aviso.'),
            [
                'proposito'  => $proposito,
                'expira_en'  => $expira->format('c'),
                'correo'     => $this->enmascararCorreo($correo),
                'por_correo' => $enviado,
            ],
            'alerta'
        );

        return $enviado;
    }

    private function enmascararCorreo(string $correo): string
    {
        $pos = strpos($correo, '@');
        if ($pos === false || $pos < 1) {
            return '***';
        }
        $usuario = substr($correo, 0, $pos);
        $dominio = substr($correo, $pos);
        $visible = mb_substr($usuario, 0, min(2, mb_strlen($usuario)));
        return $visible . str_repeat('*', max(3, mb_strlen($usuario) - 2)) . $dominio;
    }

    /** Mantenimiento: elimina desafíos caducados. */
    public static function purgar(): int
    {
        return BaseDatos::ejecutar(
            'DELETE FROM desafios
              WHERE (consumido_en IS NOT NULL AND consumido_en < DATE_SUB(NOW(), INTERVAL 7 DAY))
                 OR (expira_en < DATE_SUB(NOW(), INTERVAL 1 DAY))'
        );
    }
}
