<?php

declare(strict_types=1);

namespace CashMan\Dominio\Interbancario;

/**
 * La frontera con el sistema de pagos del Banco Central de Reserva.
 *
 * QUÉ ES ESTA CLASE Y QUÉ NO ES
 *
 * Transfer365 y UNI son servicios de terceros: el dinero sale de este
 * banco y entra a otro a través de una red que no controlamos. Un
 * sistema serio no esparce ese hecho por todas partes; lo concentra en
 * un punto, y ese punto es esta clase.
 *
 * Aquí viven las reglas de la red —horarios, formatos, comisiones, qué
 * banco acepta qué servicio— y la conversación con ella. Hoy esa
 * conversación está SIMULADA, porque conectarse de verdad al BCR
 * requiere un convenio, certificados y un entorno de homologación que
 * no se improvisan.
 *
 * Lo que importa es que la simulación sea fiel en lo que se puede
 * comprobar: los horarios son los que publican los bancos del país,
 * los formatos de cuenta son verosímiles, y los rechazos ocurren por
 * los motivos por los que ocurren de verdad. El día que haya convenio,
 * se sustituye el cuerpo de `consultarTitular()` y `enviar()` por las
 * llamadas reales y NADA MÁS cambia de sitio.
 *
 * LO QUE NO SE SIMULA
 *
 * El dinero. El asiento contable, la retención, el reverso y el rastro
 * de auditoría son reales y definitivos desde el primer día: es lo
 * único que este banco controla de verdad, y es donde no cabe simular.
 */
final class RedDePagos
{
    /**
     * La ventana de Transfer365.
     *
     * Los bancos del país publican que el servicio corre los siete días
     * de la semana, feriados incluidos, desde las 00:00:01 hasta las
     * 22:00:00. Lo que entra fuera de esa franja se procesa al abrir la
     * siguiente.
     */
    public const T365_ABRE   = '00:00:01';
    public const T365_CIERRA = '22:00:00';

    /**
     * UNI opera en horario bancario y solo días hábiles. Es el servicio
     * antiguo, anterior a Transfer365, y por eso es más estrecho.
     */
    public const UNI_ABRE   = '08:40:00';
    public const UNI_CIERRA = '16:00:00';

    /**
     * Transfer365 es gratuito para el cliente: el BCR lo diseñó así
     * expresamente, para empujar la inclusión financiera. Cobrarlo
     * sería faltar a la realidad del servicio.
     */
    public const COMISION_CENT = 0;

    /** Motivos de rechazo, los que de verdad devuelve una red de pagos. */
    public const RECHAZO_CUENTA_INEXISTENTE = 'cuenta_inexistente';
    public const RECHAZO_CUENTA_CERRADA     = 'cuenta_cerrada';
    public const RECHAZO_TITULAR_NO_COINCIDE = 'titular_no_coincide';
    public const RECHAZO_BANCO_NO_DISPONIBLE = 'banco_no_disponible';
    public const RECHAZO_MONEDA             = 'moneda_no_soportada';

    /** @var array<string,string> Lo que se le dice al cliente en cada caso. */
    private const EXPLICACION = [
        self::RECHAZO_CUENTA_INEXISTENTE  => 'La cuenta destino no existe en esa institución.',
        self::RECHAZO_CUENTA_CERRADA      => 'La cuenta destino está cerrada o no puede recibir fondos.',
        self::RECHAZO_TITULAR_NO_COINCIDE => 'El nombre del titular no coincide con el de la cuenta destino.',
        self::RECHAZO_BANCO_NO_DISPONIBLE => 'La institución destino no está respondiendo en este momento.',
        self::RECHAZO_MONEDA              => 'La cuenta destino no opera en la moneda del envío.',
    ];

    public static function explicar(string $motivo): string
    {
        return self::EXPLICACION[$motivo] ?? 'La institución destino rechazó la operación.';
    }

    // =================================================================
    // Horarios
    // =================================================================

    /**
     * ¿Está abierta la ventana del canal ahora mismo?
     *
     * Devolver `false` NO es un error: es que la transferencia queda
     * encolada para la siguiente apertura, que es exactamente lo que
     * hacen los bancos del país. Al cliente se le dice cuándo saldrá.
     */
    public static function ventanaAbierta(string $canal, ?int $instante = null): bool
    {
        $t = $instante ?? time();

        if ($canal === 'uni') {
            /* Día hábil: de lunes a viernes. La red del BCR no compensa
               UNI en fin de semana. */
            $diaSemana = (int) date('N', $t);

            if ($diaSemana >= 6) {
                return false;
            }

            return self::entre(date('H:i:s', $t), self::UNI_ABRE, self::UNI_CIERRA);
        }

        return self::entre(date('H:i:s', $t), self::T365_ABRE, self::T365_CIERRA);
    }

    /**
     * Cuándo saldrá una transferencia que entró fuera de ventana.
     *
     * Se le dice al cliente con fecha y hora, no con un «más tarde»:
     * quien manda dinero quiere saber cuándo llega.
     */
    public static function proximaVentana(string $canal, ?int $instante = null): int
    {
        $t = $instante ?? time();

        if (self::ventanaAbierta($canal, $t)) {
            return $t;
        }

        $abre = $canal === 'uni' ? self::UNI_ABRE : self::T365_ABRE;

        /* Si aún no abrió hoy, es hoy; si ya cerró, es mañana. */
        $hoyAbre = (int) strtotime(date('Y-m-d', $t) . ' ' . $abre);
        $proximo = $t < $hoyAbre ? $hoyAbre : (int) strtotime('+1 day', $hoyAbre);

        if ($canal === 'uni') {
            /* Y si cae en fin de semana, al lunes. */
            while ((int) date('N', $proximo) >= 6) {
                $proximo = (int) strtotime('+1 day', $proximo);
            }
        }

        return $proximo;
    }

    // =================================================================
    // Formatos
    // =================================================================

    /**
     * Un número de cuenta de otra institución.
     *
     * Cada banco del país usa su propio largo —entre ocho y veinte
     * dígitos— así que no se puede validar contra un formato único sin
     * rechazar cuentas buenas. Se valida lo que sí es universal: que
     * sean dígitos y que el largo esté en el rango del sistema.
     *
     * Ser estricto de más aquí tiene un costo concreto: un cliente que
     * no puede mandarle dinero a su madre porque inventamos una regla.
     */
    public static function cuentaValida(string $numero): bool
    {
        $limpio = preg_replace('/[\s-]/', '', $numero) ?? '';

        return preg_match('/^\d{8,20}$/', $limpio) === 1;
    }

    public static function normalizarCuenta(string $numero): string
    {
        return preg_replace('/[\s-]/', '', $numero) ?? '';
    }

    /**
     * Un celular salvadoreño.
     *
     * Ocho dígitos. Los móviles empiezan en 6 o 7; los fijos en 2, y a
     * un fijo no se le puede asociar una cuenta de Transfer365 Móvil.
     * Se aceptan con o sin el código de país.
     */
    public static function celularValido(string $celular): bool
    {
        return preg_match('/^[67]\d{7}$/', self::normalizarCelular($celular)) === 1;
    }

    public static function normalizarCelular(string $celular): string
    {
        $soloDigitos = preg_replace('/\D/', '', $celular) ?? '';

        /* +503 delante: se quita para guardar siempre los ocho dígitos
           del abonado y que el UNIQUE de la tabla signifique algo. */
        if (strlen($soloDigitos) === 11 && str_starts_with($soloDigitos, '503')) {
            $soloDigitos = substr($soloDigitos, 3);
        }

        return $soloDigitos;
    }

    /** Para mostrarlo: 7123-4567. */
    public static function celularLegible(string $celular): string
    {
        $c = self::normalizarCelular($celular);

        return strlen($c) === 8 ? substr($c, 0, 4) . '-' . substr($c, 4) : $c;
    }

    // =================================================================
    // Comparación de titulares
    // =================================================================

    /**
     * ¿El nombre que escribió el cliente es el titular de esa cuenta?
     *
     * La red devuelve el nombre registrado en el banco destino y hay
     * que compararlo con lo que el cliente escribió. Comparar cadenas
     * crudas rechazaría casi todo: tildes, mayúsculas, un apellido de
     * casada, un segundo nombre que la gente omite.
     *
     * Se exige que coincidan al menos dos partes del nombre. Es el
     * criterio que usan las redes de pago reales: suficiente para
     * atrapar una cuenta equivocada, tolerante con cómo escribe la
     * gente su propio nombre.
     */
    public static function titularCoincide(string $escrito, string $registrado): bool
    {
        $a = self::partesDeNombre($escrito);
        $b = self::partesDeNombre($registrado);

        if ($a === [] || $b === []) {
            return false;
        }

        $comunes = count(array_intersect($a, $b));

        /* Con un nombre de una sola palabra, una coincidencia basta:
           no se le puede exigir dos a quien solo tiene una. */
        return $comunes >= min(2, min(count($a), count($b)));
    }

    /** @return array<int,string> */
    private static function partesDeNombre(string $nombre): array
    {
        $sinTildes = strtr(
            mb_strtolower(trim($nombre), 'UTF-8'),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']
        );

        $partes = preg_split('/\s+/', $sinTildes) ?: [];

        /* Las partículas no identifican a nadie: «de», «del», «la». */
        $partes = array_filter(
            $partes,
            static fn(string $p): bool => mb_strlen($p) > 2
        );

        return array_values(array_unique($partes));
    }

    private static function entre(string $hora, string $desde, string $hasta): bool
    {
        return $hora >= $desde && $hora <= $hasta;
    }
}
