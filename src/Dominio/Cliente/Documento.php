<?php

declare(strict_types=1);

namespace CashMan\Dominio\Cliente;

/**
 * El documento con el que se identifica una persona.
 *
 * Hasta aquí el sistema guardaba «el documento» y ya: una cadena de
 * hasta veinte caracteres donde cabía un DUI con guion, un DUI sin
 * guion, un NIT y lo que a cada quien le pareciera. Buscar por
 * documento fallaba según cómo lo hubiera tecleado el que lo registró
 * —por eso `ServicioAtencionVentanilla` busca también con los guiones
 * quitados— y dos registros del mismo cliente podían convivir sin que
 * nada los señalara como el mismo.
 *
 * Aquí el tipo se dice y el número se guarda siempre igual.
 *
 * LOS TRES TIPOS
 *
 *   DUI        Ocho dígitos y el verificador: 01234567-8. Es el
 *              documento de identidad del salvadoreño mayor de edad.
 *
 *   NIT        Catorce dígitos: 0614-280598-102-5. Para personas
 *              naturales está HOMOLOGADO con el DUI desde 2022: el
 *              número de DUI hace de NIT y ya no hace falta tramitar
 *              uno aparte. Se sigue admitiendo porque las personas
 *              jurídicas lo usan, y porque hay clientes que todavía
 *              tienen el suyo de antes.
 *
 *   Residente  El carné del extranjero residente. No tiene formato
 *              fijo —cambia con el país que lo emite y con el año—,
 *              así que no se le pone máscara: se guarda tal cual, sin
 *              espacios y en mayúsculas.
 *
 * SOBRE EL DÍGITO VERIFICADOR
 *
 * Aquí NO se comprueba. El algoritmo del verificador del DUI no está
 * publicado por el RNPN, y las versiones que circulan no coinciden
 * entre sí. Rechazar un documento con una fórmula que no se puede
 * confirmar es peor que no comprobarlo: deja fuera a un cliente de
 * verdad, en la ventanilla, sin manera de que nadie lo arregle. Se
 * comprueba el formato, que es lo que sí se sabe, y la identidad la
 * confirma quien atiende contra el documento físico.
 */
final class Documento implements \JsonSerializable
{
    public const DUI       = 'dui';
    public const NIT       = 'nit';
    public const RESIDENTE = 'residente';

    /** @var array<string,string> */
    private const NOMBRES = [
        self::DUI       => 'DUI',
        self::NIT       => 'NIT',
        self::RESIDENTE => 'Carné de residente',
    ];

    private function __construct(
        private readonly string $tipo,
        private readonly string $crudo,
        private readonly string $formateado
    ) {
    }

    /**
     * Arma un documento a partir de lo que se tecleó.
     *
     * @throws \InvalidArgumentException si el tipo no existe o el
     *         número no tiene la forma que ese tipo exige.
     */
    public static function de(string $tipo, string $valor): self
    {
        $tipo  = strtolower(trim($tipo));
        $valor = trim($valor);

        if (!isset(self::NOMBRES[$tipo])) {
            throw new \InvalidArgumentException(
                'Indique si es DUI, NIT o carné de residente.'
            );
        }

        if ($valor === '') {
            throw new \InvalidArgumentException(
                'Falta el número de ' . self::NOMBRES[$tipo] . '.'
            );
        }

        return match ($tipo) {
            self::DUI => self::dui($valor),
            self::NIT => self::nit($valor),
            default   => self::residente($valor),
        };
    }

    /** Como `de()`, pero devuelve null en vez de reventar. */
    public static function oNada(?string $tipo, ?string $valor): ?self
    {
        if ($tipo === null || $valor === null || trim($valor) === '') {
            return null;
        }

        try {
            return self::de($tipo, $valor);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function dui(string $valor): self
    {
        $d = preg_replace('/\D+/', '', $valor) ?? '';

        if (strlen($d) !== 9) {
            throw new \InvalidArgumentException(
                'El DUI lleva nueve dígitos: ocho, un guion y el verificador. '
                . 'Por ejemplo, 01234567-8.'
            );
        }

        return new self(
            self::DUI,
            $d,
            substr($d, 0, 8) . '-' . substr($d, 8)
        );
    }

    private static function nit(string $valor): self
    {
        $d = preg_replace('/\D+/', '', $valor) ?? '';

        /* Un NIT de nueve dígitos es un DUI homologado: desde 2022 el
           NIT de la persona natural ES su DUI. Se acepta y se guarda
           como lo que es, para que no acabe el mismo número escrito de
           dos maneras en dos registros del mismo cliente. */
        if (strlen($d) === 9) {
            return self::dui($d);
        }

        if (strlen($d) !== 14) {
            throw new \InvalidArgumentException(
                'El NIT lleva catorce dígitos, así: 0614-280598-102-5. '
                . 'Si es persona natural, su NIT es su DUI: elija DUI y escriba ese número.'
            );
        }

        return new self(
            self::NIT,
            $d,
            substr($d, 0, 4) . '-' . substr($d, 4, 6) . '-' . substr($d, 10, 3) . '-' . substr($d, 13)
        );
    }

    private static function residente(string $valor): self
    {
        $v = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $valor));

        if (strlen($v) < 5 || strlen($v) > 20) {
            throw new \InvalidArgumentException(
                'El carné de residente lleva entre 5 y 20 caracteres, sin espacios.'
            );
        }

        return new self(self::RESIDENTE, $v, $v);
    }

    /** El tipo, tal como se guarda en la base. */
    public function tipo(): string
    {
        return $this->tipo;
    }

    /** «DUI», «NIT», «Carné de residente». */
    public function nombre(): string
    {
        return self::NOMBRES[$this->tipo];
    }

    /**
     * Solo los caracteres, sin guiones.
     *
     * Es lo que conviene para buscar y para comparar: dos personas que
     * escriben el mismo DUI con y sin guion tienen que chocar.
     */
    public function crudo(): string
    {
        return $this->crudo;
    }

    /** Con sus guiones, como se imprime y como se enseña. */
    public function formateado(): string
    {
        return $this->formateado;
    }

    /** El NIT de una persona natural es su DUI desde 2022. */
    public function esHomologado(): bool
    {
        return $this->tipo === self::DUI;
    }

    public function __toString(): string
    {
        return $this->formateado;
    }

    /** @return array<string,string> */
    public function jsonSerialize(): array
    {
        return [
            'tipo'       => $this->tipo,
            'nombre'     => $this->nombre(),
            'crudo'      => $this->crudo,
            'formateado' => $this->formateado,
        ];
    }

    /** @return array<string,string> Para pintar un selector. */
    public static function tipos(): array
    {
        return self::NOMBRES;
    }
}
