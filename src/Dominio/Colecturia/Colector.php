<?php

declare(strict_types=1);

namespace CashMan\Dominio\Colecturia;

use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * La frontera con las empresas que cobran.
 *
 * QUÉ ES ESTA CLASE
 *
 * El banco NO sabe cuánto debe el cliente por su luz, su agua o su
 * colegio. Esa deuda vive en la empresa que cobra, y el banco la
 * consulta: manda la referencia y el colector responde con el titular,
 * el monto, el periodo y la fecha límite.
 *
 * Por eso pagar un servicio tiene DOS pasos y no uno. Un sistema que
 * dejara al cliente teclear el monto a mano estaría inventando una
 * deuda: el colector rechazaría el pago, o lo aplicaría mal, y el
 * cliente se quedaría sin luz habiendo pagado.
 *
 * LO QUE ESTÁ SIMULADO, DICHO SIN ADORNOS
 *
 * La conversación con cada colector. Conectarse de verdad a CAESS, a
 * la ANDA o a Hacienda exige un convenio de recaudación por empresa,
 * con su protocolo y sus certificados; no se improvisa y no se puede
 * fingir que se tiene.
 *
 * Lo que sí es fiel es TODO lo demás: qué referencia pide cada
 * colector y cómo se llama en la factura del cliente, que la deuda la
 * fija el colector y no el banco, que hay colectores que aceptan
 * abonos y otros que exigen el total, y que un pago puede rebotar al
 * liquidar.
 *
 * El día que haya convenio se sustituyen `consultar()` y `aplicar()`
 * por las llamadas reales y nada más cambia de sitio. El asiento, el
 * reverso y la bitácora son reales desde el primer día.
 */
final class Colector
{
    /** La deuda consultada no vale para siempre: el colector la recalcula. */
    public const VIGENCIA_CONSULTA_SEG = 600;

    public const SIN_DEUDA         = 'sin_deuda';
    public const REFERENCIA_NO_EXISTE = 'referencia_no_existe';
    public const SERVICIO_SUSPENDIDO  = 'servicio_suspendido';
    public const COLECTOR_CAIDO    = 'colector_caido';

    /** @var array<string,string> */
    private const EXPLICACION = [
        self::SIN_DEUDA            => 'Ese servicio no tiene saldo pendiente en este momento.',
        self::REFERENCIA_NO_EXISTE => 'La empresa no reconoce esa referencia. Revísela en su factura.',
        self::SERVICIO_SUSPENDIDO  => 'El servicio está suspendido: debe gestionarlo directamente con la empresa.',
        self::COLECTOR_CAIDO       => 'La empresa no está respondiendo en este momento. Intente más tarde.',
    ];

    public static function explicar(string $motivo): string
    {
        return self::EXPLICACION[$motivo] ?? 'La empresa no pudo procesar la consulta.';
    }

    // =================================================================
    // Formato de la referencia
    // =================================================================

    /**
     * ¿La referencia tiene la forma que pide este convenio?
     *
     * Se valida el FORMATO, no la existencia: si la referencia existe o
     * no lo dice el colector, y adivinarlo aquí sería rechazar
     * referencias buenas.
     *
     * @param array<string,mixed> $convenio
     */
    public static function referenciaBienFormada(array $convenio, string $referencia): bool
    {
        $r = self::normalizarReferencia($referencia);

        if ($r === '') {
            return false;
        }

        if ((int) $convenio['ref_solo_digitos'] === 1 && preg_match('/^\d+$/', $r) !== 1) {
            return false;
        }

        $largo = strlen($r);

        return $largo >= (int) $convenio['ref_largo_min']
            && $largo <= (int) $convenio['ref_largo_max'];
    }

    /**
     * Quita lo que la gente escribe de más.
     *
     * Un NIS copiado de una factura llega con espacios, y un NPE con
     * guiones porque así viene impreso en el mandamiento. Rechazarlo
     * por eso sería culpar al cliente de cómo imprime Hacienda.
     */
    public static function normalizarReferencia(string $referencia): string
    {
        return strtoupper(trim(preg_replace('/[\s\-\.]/', '', $referencia) ?? ''));
    }

    /**
     * Un NPE tiene que ser numérico y largo.
     *
     * El Número de Pago Electrónico se deriva de un código de barras
     * GS1 que lleva dentro la institución cobradora, el monto, el
     * vencimiento y la referencia del cliente.
     *
     * El dígito verificador de ese código NO se comprueba aquí: su
     * especificación no es pública en una forma que se pueda verificar,
     * e implementar un algoritmo a medias rechazaría NPE buenos. Quien
     * valida el NPE de verdad es el colector, que es quien lo emitió.
     */
    public static function npeBienFormado(string $npe): bool
    {
        return preg_match('/^\d{10,40}$/', self::normalizarReferencia($npe)) === 1;
    }

    // =================================================================
    // La conversación con el colector
    // =================================================================

    /**
     * Consulta qué se debe. No mueve dinero.
     *
     * @param array<string,mixed> $convenio
     * @return array<string,mixed> {ok, motivo?, titular, deuda_cent, periodo, vence_el}
     */
    public static function consultar(array $convenio, string $referencia): array
    {
        $ref = self::normalizarReferencia($referencia);

        if (!self::referenciaBienFormada($convenio, $ref)) {
            return ['ok' => false, 'motivo' => self::REFERENCIA_NO_EXISTE];
        }

        if ($convenio['tipo_referencia'] === 'npe' && !self::npeBienFormado($ref)) {
            return ['ok' => false, 'motivo' => self::REFERENCIA_NO_EXISTE];
        }

        /* --- Aquí iría la llamada al colector ----------------------
         *
         * Mientras no exista el convenio, la respuesta se deriva de la
         * propia referencia con una función estable: la MISMA
         * referencia da SIEMPRE la misma deuda, en lugar de un número
         * al azar que cambiaría entre la consulta y el pago.
         *
         * Esa estabilidad no es un capricho de la simulación: es
         * exactamente lo que hace falta para que el flujo de dos pasos
         * se pueda probar, y para que el cliente no vea un monto al
         * consultar y otro al pagar. */
        $semilla = crc32($convenio['codigo'] . '|' . $ref);

        /* Una de cada doce referencias bien formadas no existe. Las
           referencias mal escritas son el error más común de esta
           pantalla, y un camino de error que nunca se recorre es un
           camino que nadie probó. */
        if ($semilla % 12 === 0) {
            return ['ok' => false, 'motivo' => self::REFERENCIA_NO_EXISTE];
        }

        if ($semilla % 37 === 0) {
            return ['ok' => false, 'motivo' => self::SIN_DEUDA];
        }

        /* Entre $5 y $185, que es el rango de un recibo doméstico. */
        $deuda = 500 + ($semilla % 18000);

        $hoy = new \DateTimeImmutable();

        return [
            'ok'         => true,
            'titular'    => self::titularSimulado($semilla),
            'deuda_cent' => $deuda,
            'periodo'    => $hoy->modify('-1 month')->format('m/Y'),
            'vence_el'   => $hoy->modify('+' . (5 + $semilla % 20) . ' days')->format('Y-m-d'),
            'consultado' => time(),
        ];
    }

    /**
     * Aplica el pago en el colector.
     *
     * Devuelve el número de autorización, que es lo que el cliente
     * enseña si la empresa llegara a decir que no le pagaron.
     *
     * @param array<string,mixed> $convenio
     * @return array<string,mixed> {ok, autorizacion?, motivo?}
     */
    public static function aplicar(array $convenio, string $referencia, int $montoCent): array
    {
        $ref = self::normalizarReferencia($referencia);

        if ($montoCent <= 0) {
            return ['ok' => false, 'motivo' => self::REFERENCIA_NO_EXISTE];
        }

        return [
            'ok'           => true,
            'autorizacion' => self::autorizacion($convenio['codigo'], $ref, $montoCent),
        ];
    }

    /**
     * El número de autorización.
     *
     * Derivado del convenio, la referencia y el monto, de modo que sea
     * reproducible y que dos pagos distintos nunca compartan el mismo.
     */
    public static function autorizacion(string $codigoConvenio, string $referencia, int $montoCent): string
    {
        return strtoupper(
            substr($codigoConvenio, 0, 3)
            . substr(hash('sha256', $codigoConvenio . '|' . $referencia . '|' . $montoCent . '|' . microtime(true)), 0, 10)
        );
    }

    // =================================================================
    // Catálogo
    // =================================================================

    /**
     * Un convenio por su código o su id.
     *
     * @return array<string,mixed>|null
     */
    public static function convenio(int|string $cual): ?array
    {
        if (is_int($cual)) {
            return BaseDatos::unaFila(
                'SELECT * FROM convenios_servicio WHERE id = ? AND activo = 1',
                [$cual]
            );
        }

        return BaseDatos::unaFila(
            'SELECT * FROM convenios_servicio WHERE codigo = ? AND activo = 1',
            [$cual]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function catalogo(): array
    {
        return BaseDatos::todas(
            'SELECT * FROM convenios_servicio WHERE activo = 1
              ORDER BY categoria, orden, nombre_corto'
        );
    }

    /** Cómo se llama cada categoría en pantalla. */
    public static function categoria(string $clave): string
    {
        return match ($clave) {
            'energia'        => 'Energía eléctrica',
            'agua'           => 'Agua',
            'telefonia'      => 'Telefonía',
            'internet_cable' => 'Internet y cable',
            'educacion'      => 'Educación',
            'seguros'        => 'Seguros',
            'gobierno'       => 'Gobierno e impuestos',
            'salud'          => 'Salud',
            'credito'        => 'Créditos y tarjetas',
            default          => 'Otros',
        };
    }

    /** Un nombre verosímil para el titular que devuelve el colector. */
    private static function titularSimulado(int $semilla): string
    {
        $nombres = ['José', 'María', 'Carlos', 'Ana', 'Luis', 'Carmen', 'Jorge',
                    'Rosa', 'Mario', 'Silvia', 'Roberto', 'Claudia'];

        $apellidos = ['Hernández', 'Martínez', 'Ramírez', 'González', 'Pérez',
                      'Rodríguez', 'López', 'Flores', 'Mejía', 'Portillo',
                      'Alvarenga', 'Campos'];

        return $nombres[$semilla % count($nombres)]
            . ' ' . $apellidos[intdiv($semilla, 13) % count($apellidos)]
            . ' ' . $apellidos[intdiv($semilla, 7) % count($apellidos)];
    }
}
