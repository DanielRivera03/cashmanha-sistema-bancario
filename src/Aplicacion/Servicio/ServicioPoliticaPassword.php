<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\ServicioPassword;

/**
 * Lo que un banco exige de una contraseña más allá de su forma.
 *
 * `ServicioPassword` se ocupa de que la contraseña sea fuerte —largo,
 * mayúsculas, símbolos— y de cifrarla. Eso mira UNA contraseña, en el
 * momento de escribirla.
 *
 * Esto mira lo otro: su historia.
 *
 *   - ¿Ya la había usado antes? Entonces no sirve. Obligar a cambiar
 *     cada dos meses sin esto es teatro: el que no quiere cambiarla
 *     alterna entre dos y la política no sirvió de nada.
 *
 *   - ¿Cuánto lleva con la misma? Pasado el plazo hay que cambiarla
 *     antes de hacer nada más.
 *
 * SOBRE EL HISTORIAL
 *
 * Se guardan HASHES, nunca contraseñas. Para saber si una nueva
 * coincide con una vieja se verifica contra cada hash guardado, que
 * es la única forma de hacerlo sin conservar el texto. Eso cuesta:
 * Argon2id está calibrado para ser lento a propósito, y comprobar
 * doce hashes son doce veces ese costo. Por eso el historial tiene
 * tope y se poda.
 *
 * SOBRE LA CADUCIDAD
 *
 * Que caducar contraseñas mejore la seguridad es discutible —el NIST
 * lleva años recomendando NO forzar cambios periódicos, porque la
 * gente acaba poniendo «Clave01», «Clave02»—. Pero es lo que la
 * normativa bancaria pide y lo que el cliente espera de su banco, así
 * que está, con el plazo configurable y la posibilidad de apagarlo
 * poniendo `password.dias_vigencia` en cero. Lo que sí se hace es
 * mitigar el efecto feo: con el historial, «Clave01» y «Clave02» no
 * se pueden alternar.
 */
final class ServicioPoliticaPassword
{
    public function __construct(
        private readonly ServicioPassword $password = new ServicioPassword()
    ) {
    }

    /** Días que vale una contraseña. Cero la deja sin vencimiento. */
    public function diasDeVigencia(): int
    {
        return max(0, Parametros::entero('password.dias_vigencia', 60));
    }

    /** Cuántas contraseñas anteriores se recuerdan. */
    public function cuantasRecuerda(): int
    {
        return max(0, Parametros::entero('password.historial', 12));
    }

    /**
     * ¿Le toca cambiarla ya?
     *
     * Responde que sí por dos razones distintas, y las distingue,
     * porque el mensaje que hay que darle no es el mismo: a quien se
     * le venció se le explica el plazo; a quien entra por primera vez
     * se le dice que estrene la suya.
     *
     * @param array<string,mixed> $u Fila de `usuarios`.
     * @return array{debe:bool, motivo:string, mensaje:string}
     */
    public function revisar(array $u): array
    {
        if ((int) ($u['requiere_cambio_password'] ?? 0) === 1) {
            return [
                'debe'    => true,
                'motivo'  => 'exigido',
                /* El mensaje vale para los dos casos que llevan a aquí,
                 * porque el servicio no puede distinguirlos: a quien le
                 * dieron una clave temporal —ahí sí la conoce alguien
                 * más— y a quien acaba de registrarse y eligió la suya.
                 * Decirle a este último que «alguien más la conoce»
                 * sería mentirle y asustarlo sin motivo. */
                'mensaje' => 'Antes de continuar, defina la contraseña con la que '
                           . 'va a usar su banca. Es el primer paso y solo se hace '
                           . 'una vez.',
            ];
        }

        $dias = $this->diasDeVigencia();

        if ($dias === 0) {
            return ['debe' => false, 'motivo' => '', 'mensaje' => ''];
        }

        $desde = (string) ($u['password_actualizado_en'] ?? '');

        /* Sin fecha no se puede saber la edad de la contraseña. Se pide
           el cambio: es lo prudente, y además deja la fecha puesta
           para que la próxima vez sí se sepa. */
        if ($desde === '') {
            return [
                'debe'    => true,
                'motivo'  => 'sin_fecha',
                'mensaje' => 'No tenemos registro de cuándo cambió su contraseña por '
                           . 'última vez. Defina una nueva para ponernos al día.',
            ];
        }

        $edad = (new \DateTimeImmutable($desde))
            ->diff(new \DateTimeImmutable())
            ->days;

        if ($edad !== false && $edad >= $dias) {
            return [
                'debe'    => true,
                'motivo'  => 'vencida',
                'mensaje' => 'Su contraseña tiene ' . $edad . ' días. Por seguridad '
                           . 'la cambiamos cada ' . $dias . ' días; defina una nueva '
                           . 'para continuar.',
            ];
        }

        return ['debe' => false, 'motivo' => '', 'mensaje' => ''];
    }

    /** Cuántos días le quedan antes de que venza. Null si no vence. */
    public function diasQueLeQuedan(array $u): ?int
    {
        $dias = $this->diasDeVigencia();

        if ($dias === 0) { return null; }

        $desde = (string) ($u['password_actualizado_en'] ?? '');

        if ($desde === '') { return 0; }

        $edad = (new \DateTimeImmutable($desde))
            ->diff(new \DateTimeImmutable())
            ->days;

        return $edad === false ? null : max(0, $dias - $edad);
    }

    /**
     * ¿Ya había usado esta contraseña?
     *
     * Verifica contra los hashes guardados. Devuelve `true` si alguno
     * coincide, que es cuando hay que rechazarla.
     */
    public function yaLaUso(int $usuarioId, string $nueva): bool
    {
        $cuantas = $this->cuantasRecuerda();

        if ($cuantas === 0) { return false; }

        $filas = BaseDatos::todas(
            'SELECT password_hash, algoritmo FROM password_historial
              WHERE usuario_id = ? ORDER BY id DESC LIMIT ' . $cuantas,
            [$usuarioId]
        );

        foreach ($filas as $f) {
            if ($this->password->verificar(
                $nueva,
                (string) $f['password_hash'],
                (string) $f['algoritmo']
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Comprueba la contraseña nueva entera: forma e historia.
     *
     * @return list<string> Los motivos por los que no sirve. Vacío = sirve.
     */
    public function validar(int $usuarioId, string $nueva): array
    {
        $errores = $this->password->validarPolitica($nueva);

        /* El historial se mira solo si la contraseña ya pasó lo demás.
           Verificar doce hashes de Argon2id para una clave que de
           todas formas es muy corta es gastar medio segundo en nada. */
        if ($errores === [] && $this->yaLaUso($usuarioId, $nueva)) {
            $errores[] = 'Ya usó esta contraseña antes. Elija una que no haya tenido, '
                       . 'porque una contraseña vieja puede haberse filtrado sin que '
                       . 'usted lo sepa.';
        }

        return $errores;
    }

    /**
     * Apunta la contraseña que se acaba de poner y poda las viejas.
     *
     * Se llama DESPUÉS de guardarla en `usuarios`. El hash que se
     * guarda aquí es el mismo que quedó allá: así, cuando esta deje
     * de ser la actual, sigue contando como usada.
     */
    public function recordar(int $usuarioId, string $hash, string $algoritmo = 'argon2id'): void
    {
        BaseDatos::ejecutar(
            'INSERT INTO password_historial (usuario_id, password_hash, algoritmo, creado_en)
             VALUES (?,?,?,NOW())',
            [$usuarioId, $hash, $algoritmo]
        );

        $this->podar($usuarioId);
    }

    /**
     * Borra del historial lo que ya no hace falta.
     *
     * Un hash de contraseña es material sensible aunque sea viejo:
     * guardarlo para siempre es acumular algo que ya no cumple
     * ninguna función y que algún día alguien puede llevarse.
     */
    private function podar(int $usuarioId): void
    {
        $cuantas = $this->cuantasRecuerda();

        if ($cuantas === 0) {
            BaseDatos::ejecutar('DELETE FROM password_historial WHERE usuario_id = ?', [$usuarioId]);
            return;
        }

        /* El id del que marca el corte. MySQL no deja borrar de una
           tabla leyendo esa misma tabla en la subconsulta, así que se
           resuelve en dos pasos. */
        $corte = BaseDatos::unValor(
            'SELECT id FROM password_historial
              WHERE usuario_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . $cuantas,
            [$usuarioId]
        );

        if ($corte === null) { return; }

        BaseDatos::ejecutar(
            'DELETE FROM password_historial WHERE usuario_id = ? AND id <= ?',
            [$usuarioId, (int) $corte]
        );
    }
}
