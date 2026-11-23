<?php
declare(strict_types=1);

namespace CashMan\Dominio\Comun;

/**
 * Cantidad de dinero. Inmutable.
 *
 * Se guarda como ENTERO de centavos. Nunca float.
 *
 * Por qué importa: 0.1 + 0.2 no es 0.3 en coma flotante. En un sistema
 * financiero eso se traduce en saldos que no cuadran. El repositorio
 * original ya había chocado con esto —el issue que documenta el parche
 * de DECIMAL(9,2) a DECIMAL(15,6) en el trigger de saldos es exactamente
 * este problema—. Con enteros el error es imposible por construcción.
 *
 * El constructor es privado: no se puede crear un Monto inválido.
 * Si el valor no supera la validación, no existe el objeto.
 */
final class Monto implements \JsonSerializable
{
    /** 99,999,999,999.99 — suficiente para cualquier operación real. */
    private const MAX_CENTAVOS = 9999999999999;

    private function __construct(
        public readonly int $centavos,
        public readonly string $moneda
    ) {
    }

    public static function deCentavos(int $centavos, string $moneda = 'USD'): self
    {
        if (abs($centavos) > self::MAX_CENTAVOS) {
            throw new MontoInvalido('El monto excede el máximo permitido por el sistema.');
        }
        return new self($centavos, strtoupper($moneda));
    }

    public static function cero(string $moneda = 'USD'): self
    {
        return new self(0, strtoupper($moneda));
    }

    /**
     * Construye desde la entrada del usuario.
     *
     * Aquí mueren los montos negativos, los ceros, el texto y la
     * notación científica. Es el punto donde se detiene lo que el
     * sistema anterior dejaba pasar directo a la base de datos.
     */
    public static function deEntradaUsuario(mixed $entrada, string $moneda = 'USD'): self
    {
        if (is_int($entrada)) {
            $texto = (string) $entrada;
        } elseif (is_float($entrada)) {
            // Un float puede llegar por JSON. Se acepta, pero se
            // normaliza a texto de inmediato para no arrastrar el error.
            $texto = sprintf('%.2F', $entrada);
        } elseif (is_string($entrada)) {
            $texto = trim($entrada);
        } else {
            throw new MontoInvalido('El monto debe ser un número.');
        }

        if ($texto === '') {
            throw new MontoInvalido('Debe indicar un monto.');
        }

        // Separadores de miles y símbolo de moneda.
        $texto = str_replace(['$', ' ', ','], '', $texto);

        // Solo dígitos con hasta dos decimales. Sin signo: los montos
        // que entran por la interfaz son siempre positivos; el signo lo
        // decide el libro mayor, no el usuario.
        if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $texto)) {
            throw new MontoInvalido('Formato de monto inválido. Use por ejemplo: 1250.00');
        }

        $partes   = explode('.', $texto);
        $enteros  = (int) $partes[0];
        $decimal  = isset($partes[1]) ? str_pad($partes[1], 2, '0') : '00';
        $centavos = $enteros * 100 + (int) $decimal;

        if ($centavos <= 0) {
            throw new MontoInvalido('El monto debe ser mayor que cero.');
        }
        if ($centavos > self::MAX_CENTAVOS) {
            throw new MontoInvalido('El monto excede el máximo permitido.');
        }

        return new self($centavos, strtoupper($moneda));
    }

    // -----------------------------------------------------------------
    // Aritmética — siempre devuelve instancias nuevas
    // -----------------------------------------------------------------

    public function mas(self $otro): self
    {
        $this->exigirMismaMoneda($otro);
        return self::deCentavos($this->centavos + $otro->centavos, $this->moneda);
    }

    public function menos(self $otro): self
    {
        $this->exigirMismaMoneda($otro);
        return self::deCentavos($this->centavos - $otro->centavos, $this->moneda);
    }

    public function negado(): self
    {
        return new self(-$this->centavos, $this->moneda);
    }

    public function absoluto(): self
    {
        return new self(abs($this->centavos), $this->moneda);
    }

    /**
     * Multiplica por un factor. Redondeo bancario (half-even).
     *
     * El redondeo half-even evita el sesgo acumulado que introduce el
     * redondeo normal cuando se aplica a millones de operaciones: con
     * half-up, el medio centavo siempre favorece al mismo lado.
     */
    public function por(float $factor): self
    {
        $resultado = $this->centavos * $factor;
        return self::deCentavos((int) round($resultado, 0, PHP_ROUND_HALF_EVEN), $this->moneda);
    }

    /** Aplica una tasa expresada en puntos base (550 = 5.50%). */
    public function porPuntosBase(int $puntosBase): self
    {
        return $this->por($puntosBase / 10000);
    }

    /**
     * Reparte el monto en n partes sin perder ni un centavo.
     *
     * Reparte el residuo entre las primeras cuotas, de modo que la suma
     * de las partes es EXACTAMENTE el total. Es lo que evita que un
     * crédito de $1,000 a 3 cuotas genere 3 × $333.33 = $999.99.
     *
     * @return array<int,self>
     */
    public function repartir(int $partes): array
    {
        if ($partes < 1) {
            throw new \InvalidArgumentException('El número de partes debe ser al menos 1.');
        }

        $base    = intdiv($this->centavos, $partes);
        $residuo = $this->centavos % $partes;

        $resultado = [];
        for ($i = 0; $i < $partes; $i++) {
            $resultado[] = new self($base + ($i < $residuo ? 1 : 0), $this->moneda);
        }

        return $resultado;
    }

    // -----------------------------------------------------------------
    // Comparación
    // -----------------------------------------------------------------

    public function igualA(self $otro): bool
    {
        return $this->moneda === $otro->moneda && $this->centavos === $otro->centavos;
    }

    public function mayorQue(self $otro): bool
    {
        $this->exigirMismaMoneda($otro);
        return $this->centavos > $otro->centavos;
    }

    public function mayorOIgualQue(self $otro): bool
    {
        $this->exigirMismaMoneda($otro);
        return $this->centavos >= $otro->centavos;
    }

    public function menorQue(self $otro): bool
    {
        $this->exigirMismaMoneda($otro);
        return $this->centavos < $otro->centavos;
    }

    public function esCero(): bool
    {
        return $this->centavos === 0;
    }

    public function esPositivo(): bool
    {
        return $this->centavos > 0;
    }

    public function esNegativo(): bool
    {
        return $this->centavos < 0;
    }

    // -----------------------------------------------------------------
    // Presentación
    // -----------------------------------------------------------------

    /** "1250.00" — para campos de formulario y cálculos en JavaScript. */
    public function aDecimal(): string
    {
        $signo    = $this->centavos < 0 ? '-' : '';
        $absoluto = abs($this->centavos);
        return $signo . intdiv($absoluto, 100) . '.' . str_pad((string) ($absoluto % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "$1,250.00" — para mostrar al usuario. */
    public function formateado(bool $conSimbolo = true): string
    {
        $signo    = $this->centavos < 0 ? '-' : '';
        $absoluto = abs($this->centavos);
        $entero   = number_format(intdiv($absoluto, 100), 0, '.', ',');
        $decimal  = str_pad((string) ($absoluto % 100), 2, '0', STR_PAD_LEFT);

        $simbolo = $conSimbolo ? self::simboloDe($this->moneda) : '';

        return $signo . $simbolo . $entero . '.' . $decimal;
    }

    private static function simboloDe(string $moneda): string
    {
        return match ($moneda) {
            'USD'   => '$',
            'EUR'   => '€',
            'GBP'   => '£',
            default => $moneda . ' ',
        };
    }

    public function __toString(): string
    {
        return $this->formateado();
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'centavos'   => $this->centavos,
            'decimal'    => $this->aDecimal(),
            'formateado' => $this->formateado(),
            'moneda'     => $this->moneda,
        ];
    }

    private function exigirMismaMoneda(self $otro): void
    {
        if ($this->moneda !== $otro->moneda) {
            throw new MontoInvalido(
                "No se pueden operar montos de distinta moneda ({$this->moneda} y {$otro->moneda})."
            );
        }
    }
}
