<?php

declare(strict_types=1);

namespace CashMan\Dominio\Credito;

/**
 * El orden de las cláusulas del contrato de crédito, en un solo sitio.
 *
 * POR QUÉ EXISTE ESTA CLASE
 *
 * El contrato numeraba sus cláusulas a mano —'SEXTA. MORA.',
 * 'DECIMA. IMPUTACION DE PAGOS.'— y los correos las citaban también a
 * mano: «conforme a la cláusula DÉCIMA se imputó primero el recargo»,
 * «fundamento: cláusula DÉCIMA CUARTA». Mientras nadie tocara el
 * contrato, cuadraba.
 *
 * El día que se inserte una cláusula en medio —y hay que insertarla,
 * porque un crédito de vehículo necesita decir qué vehículo— todas las
 * de abajo corren un número. El contrato sale bien, porque se escribe
 * entero de nuevo. Los correos siguen citando el número viejo y pasan
 * a señalar la cláusula equivocada: el aviso de una compensación
 * fundamentada en la cláusula de domicilio y jurisdicción.
 *
 * Eso no es un detalle de forma. Un aviso que cita mal el fundamento
 * de un cargo es un aviso que el cliente puede impugnar, y con razón.
 *
 * CÓMO SE USA
 *
 *     Clausulado::numeral('mora')        // 'SEXTA'
 *     Clausulado::titulo('mora')         // 'SEXTA. MORA.'
 *     Clausulado::deCredito('vehiculo')  // el orden completo
 *
 * El contrato se imprime recorriendo la lista y los correos piden el
 * numeral por su clave. Insertar una cláusula es añadir una línea
 * aquí: el documento y los avisos se reacomodan juntos, porque leen
 * lo mismo.
 *
 * LAS CLÁUSULAS CAMBIAN SEGÚN LA MODALIDAD
 *
 * Un crédito de vehículo lleva una cláusula que describe el vehículo
 * —marca, modelo, VIN, chasis— y las obligaciones sobre él: no
 * traspasarlo, no cambiarle el motor, mantenerlo asegurado. Un crédito
 * educativo no tiene nada de eso y sí tiene otras: acreditar la
 * matrícula cada ciclo, avisar si se abandona la carrera.
 *
 * Por eso el orden no es uno solo: se pide el de la modalidad, y la
 * numeración sale de ahí.
 */
final class Clausulado
{
    /**
     * Los numerales escritos, que es como se citan en un contrato.
     *
     * Hasta veinticuatro: ningún contrato de esta casa llega ahí, pero
     * quedarse corto obligaría a imprimir «CLAUSULA 21» en medio de un
     * documento que hasta entonces decía «VIGESIMA».
     */
    private const NUMERALES = [
        'PRIMERA', 'SEGUNDA', 'TERCERA', 'CUARTA', 'QUINTA', 'SEXTA',
        'SEPTIMA', 'OCTAVA', 'NOVENA', 'DECIMA',
        'DECIMA PRIMERA', 'DECIMA SEGUNDA', 'DECIMA TERCERA', 'DECIMA CUARTA',
        'DECIMA QUINTA', 'DECIMA SEXTA', 'DECIMA SEPTIMA', 'DECIMA OCTAVA',
        'DECIMA NOVENA', 'VIGESIMA',
        'VIGESIMA PRIMERA', 'VIGESIMA SEGUNDA', 'VIGESIMA TERCERA', 'VIGESIMA CUARTA',
    ];

    /**
     * Las cláusulas comunes a todo crédito, en su orden.
     *
     * El orden no es arbitrario: va de lo que se pactó —objeto,
     * destino, plazo, precio— a lo que pasa si algo sale mal —mora,
     * caducidad, cobro— y termina en las de régimen —datos,
     * jurisdicción, aceptación—. Es el orden en que se lee un contrato
     * y el que espera quien lo revisa.
     *
     * @var array<string,string> clave => título
     */
    private const COMUNES = [
        'objeto'        => 'OBJETO Y ENTREGA',
        'destino'       => 'DESTINO',
        // Aquí entra la cláusula propia de la modalidad.
        'plazo'         => 'PLAZO Y FORMA DE PAGO',
        'intereses'     => 'INTERESES',
        'seguros'       => 'SEGUROS OBLIGATORIOS',
        'mora'          => 'MORA',
        'caducidad'     => 'CADUCIDAD DEL PLAZO',
        'garantias'     => 'GARANTIAS',
        'anticipados'   => 'PAGOS ANTICIPADOS',
        'imputacion'    => 'IMPUTACION DE PAGOS',
        'cobro'         => 'GESTION DE COBRO',
        'datos'         => 'TRATAMIENTO DE DATOS',
        'jurisdiccion'  => 'DOMICILIO Y JURISDICCION',
        'compensacion'  => 'COMPENSACION',
        'aceptacion'    => 'ACEPTACION',
    ];

    /**
     * La cláusula propia de cada modalidad, y dónde entra.
     *
     * Va justo después del destino, porque es su detalle: el destino
     * dice «compra de vehículo» y ésta dice cuál. Ponerla al final la
     * dejaría lejos de lo que explica.
     *
     * @var array<string,array{clave:string,titulo:string}>
     */
    private const PROPIA = [
        'vehiculo' => ['clave' => 'bien', 'titulo' => 'IDENTIFICACION DEL VEHICULO'],
        'vivienda' => ['clave' => 'bien', 'titulo' => 'IDENTIFICACION DEL INMUEBLE'],
        'estudios' => ['clave' => 'bien', 'titulo' => 'ESTUDIOS FINANCIADOS'],
        'negocio'  => ['clave' => 'bien', 'titulo' => 'ACTIVIDAD FINANCIADA'],
    ];

    /**
     * El orden completo para una modalidad.
     *
     * @return array<string,string> clave => título, en orden
     */
    public static function deCredito(string $modalidad = 'personal'): array
    {
        $orden = [];

        foreach (self::COMUNES as $clave => $titulo) {
            $orden[$clave] = $titulo;

            /* La propia de la modalidad entra detrás del destino. */
            if ($clave === 'destino' && isset(self::PROPIA[$modalidad])) {
                $orden[self::PROPIA[$modalidad]['clave']] = self::PROPIA[$modalidad]['titulo'];
            }
        }

        return $orden;
    }

    /**
     * El numeral de una cláusula: 'SEXTA'.
     *
     * Si la clave no existe devuelve cadena vacía en vez de reventar:
     * un correo que no encuentra su cláusula tiene que poder salir sin
     * el número antes que no salir.
     */
    public static function numeral(string $clave, string $modalidad = 'personal'): string
    {
        $orden = array_keys(self::deCredito($modalidad));
        $i     = array_search($clave, $orden, true);

        if ($i === false || !isset(self::NUMERALES[$i])) {
            return '';
        }

        return self::NUMERALES[$i];
    }

    /** El encabezado tal como se imprime: 'SEXTA. MORA.' */
    public static function titulo(string $clave, string $modalidad = 'personal'): string
    {
        $orden = self::deCredito($modalidad);

        if (!isset($orden[$clave])) {
            return '';
        }

        return self::numeral($clave, $modalidad) . '. ' . $orden[$clave] . '.';
    }

    /**
     * El numeral para citarlo dentro de una frase, con acentos.
     *
     * En el PDF se escribe sin tildes porque el generador trabaja en
     * Latin-1 y las comía; en un correo HTML se escriben, que es como
     * se escribe en español.
     */
    public static function numeralConTildes(string $clave, string $modalidad = 'personal'): string
    {
        return strtr(self::numeral($clave, $modalidad), [
            'DECIMA'  => 'DÉCIMA',
            'SEPTIMA' => 'SÉPTIMA',
            'VIGESIMA' => 'VIGÉSIMA',
        ]);
    }

    /**
     * La modalidad que corresponde a un producto, por su código.
     *
     * Se decide por el código y no por el nombre: el nombre lo cambia
     * cualquiera desde el catálogo, y entonces un crédito de vehículo
     * dejaría de llevar la cláusula del vehículo sin que nadie lo
     * notara hasta ver un contrato impreso.
     */
    public static function modalidadDe(string $codigoProducto): string
    {
        return match (strtoupper(trim($codigoProducto))) {
            'CRE-VEH' => 'vehiculo',
            'CRE-HIP' => 'vivienda',
            'CRE-EDU' => 'estudios',
            'CRE-PYM' => 'negocio',
            default   => 'personal',
        };
    }
}
