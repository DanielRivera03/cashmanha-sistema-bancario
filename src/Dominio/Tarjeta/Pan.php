<?php
declare(strict_types=1);

namespace CashMan\Dominio\Tarjeta;

/**
 * Número de tarjeta (PAN). Inmutable.
 *
 * Nota sobre Visa y Mastercard: no existe una API gratuita de esas
 * marcas para emitir tarjetas. Visa Developer y Mastercard Developers
 * ofrecen sandboxes, pero cubren servicios puntuales, exigen registro
 * de empresa y la emisión real requiere ser banco emisor o contar con
 * un patrocinador de BIN.
 *
 * Lo que SÍ es estándar público y se usa aquí:
 *   - Algoritmo de Luhn (ISO/IEC 7812-1), para validar el dígito
 *     verificador de cualquier tarjeta.
 *   - Rangos IIN/BIN, para identificar la marca a partir de los
 *     primeros dígitos.
 *
 * Con eso el sistema emite tarjetas propias con numeración válida y
 * queda listo para enchufar un procesador real cambiando solo el
 * adaptador de pasarela.
 */
final class Pan
{
    private function __construct(
        public readonly string $numero,
        public readonly string $marca,
        public readonly string $bin6,
        public readonly string $ultimos4
    ) {
    }

    public static function desde(string $entrada): self
    {
        $numero = preg_replace('/\D/', '', $entrada) ?? '';

        if (strlen($numero) < 12 || strlen($numero) > 19) {
            throw new PanInvalido('El número de tarjeta debe tener entre 12 y 19 dígitos.');
        }

        if (!self::luhnValido($numero)) {
            throw new PanInvalido('El número de tarjeta no es válido.');
        }

        return new self(
            $numero,
            self::detectarMarca($numero),
            substr($numero, 0, 6),
            substr($numero, -4)
        );
    }

    /**
     * Genera un PAN con dígito verificador válido.
     *
     * Los BIN usados son de rangos de prueba, no asignados a ningún
     * emisor real. Sirven para desarrollo y demostración; una emisión
     * real necesita un BIN propio otorgado por la marca.
     */
    public static function generar(string $marca = 'visa'): self
    {
        $bin = match ($marca) {
            'visa'       => '453988',   // rango de pruebas
            'mastercard' => '543210',
            'amex'       => '370000',
            default      => '453988',
        };

        $longitud = $marca === 'amex' ? 15 : 16;

        // Dígitos aleatorios criptográficos hasta dejar espacio al verificador.
        $cuerpo = $bin;
        while (strlen($cuerpo) < $longitud - 1) {
            $cuerpo .= (string) random_int(0, 9);
        }

        return self::desde($cuerpo . self::digitoLuhn($cuerpo));
    }

    /** Algoritmo de Luhn (ISO/IEC 7812-1). */
    public static function luhnValido(string $numero): bool
    {
        $suma  = 0;
        $doble = false;

        for ($i = strlen($numero) - 1; $i >= 0; $i--) {
            $d = (int) $numero[$i];

            if ($doble) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }

            $suma  += $d;
            $doble = !$doble;
        }

        return $suma % 10 === 0;
    }

    /** Dígito verificador que hace válido un número parcial. */
    public static function digitoLuhn(string $parcial): string
    {
        $suma  = 0;
        $doble = true;

        for ($i = strlen($parcial) - 1; $i >= 0; $i--) {
            $d = (int) $parcial[$i];

            if ($doble) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }

            $suma  += $d;
            $doble = !$doble;
        }

        return (string) ((10 - ($suma % 10)) % 10);
    }

    /** Marca según los rangos IIN publicados. */
    public static function detectarMarca(string $numero): string
    {
        $len = strlen($numero);
        $p1  = (int) substr($numero, 0, 1);
        $p2  = (int) substr($numero, 0, 2);
        $p4  = (int) substr($numero, 0, 4);
        $p6  = (int) substr($numero, 0, 6);

        if ($p1 === 4 && in_array($len, [13, 16, 19], true)) {
            return 'visa';
        }
        if (($p2 >= 51 && $p2 <= 55) || ($p6 >= 222100 && $p6 <= 272099)) {
            return 'mastercard';
        }
        if (in_array($p2, [34, 37], true) && $len === 15) {
            return 'amex';
        }
        if ($p4 === 6011 || $p2 === 65 || ($p6 >= 622126 && $p6 <= 622925)) {
            return 'discover';
        }

        return 'desconocida';
    }

    /** "•••• •••• •••• 1234" — lo único que se muestra en pantalla. */
    public function enmascarado(): string
    {
        return '•••• •••• •••• ' . $this->ultimos4;
    }


    /**
     * El CVV de la tarjeta: fijo, único, y guardado en ninguna parte.
     *
     * Un CVV no es un código temporal. Va impreso al dorso del plástico
     * y es el mismo el día que llega y el día que vence: por eso un
     * comercio puede cobrar una suscripción mes a mes y por eso el
     * cliente que lo apunta lo encuentra igual al día siguiente. Esta
     * pantalla llegó a enseñar uno derivado de la hora, que cambiaba
     * cada sesenta minutos, y eso no se parece a ninguna tarjeta.
     *
     * Tampoco se guarda. Las reglas de la industria prohíben almacenar
     * el CVV después de autorizar —ni siquiera cifrado— y el emisor de
     * verdad no lo necesita: lo DERIVA de los datos de la tarjeta con
     * una clave suya cada vez que hace falta comprobarlo. Eso es lo que
     * se hace aquí, con `TARJETA_PEPPER` en el papel de esa clave.
     *
     * Entran el token —único por tarjeta— y la fecha de vencimiento,
     * igual que un emisor real mete el PAN, la caducidad y el código de
     * servicio. Mismo resultado siempre, para la misma tarjeta, sin una
     * fila que robar.
     *
     * `hexdec` sobre 6 dígitos hexadecimales da hasta 16.777.215, y el
     * módulo reparte parejo de sobra para tres dígitos. Se permite el
     * 000: descartarlo dejaría 999 valores en vez de 1.000 y sería
     * regalar información a quien esté adivinando.
     */
    public static function cvvDe(
        string $token,
        int $expiraMes,
        int $expiraAnio,
        string $claveEmisor,
        string $marca = 'visa'
    ): string {
        $digitos = $marca === 'amex' ? 4 : 3;

        $firma = hash_hmac(
            'sha256',
            $token . '|' . $expiraMes . '|' . $expiraAnio . '|cvv',
            $claveEmisor
        );

        return str_pad(
            (string) (hexdec(substr($firma, 0, 6)) % (10 ** $digitos)),
            $digitos,
            '0',
            STR_PAD_LEFT
        );
    }

    /** Para el momento de la emisión: se muestra una vez y nunca más. */
    public function formateado(): string
    {
        return trim(chunk_split($this->numero, 4, ' '));
    }

    /**
     * Evita que el PAN aparezca por accidente en un var_dump,
     * una traza de excepción o un log.
     */
    public function __debugInfo(): array
    {
        return ['pan' => '[PROTEGIDO]', 'marca' => $this->marca, 'ultimos4' => $this->ultimos4];
    }

    public function __toString(): string
    {
        return $this->enmascarado();
    }
}
