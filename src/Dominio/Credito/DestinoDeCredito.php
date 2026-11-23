<?php

declare(strict_types=1);

namespace CashMan\Dominio\Credito;

/**
 * El destino del crédito, atado a la modalidad.
 *
 * LO QUE PASABA
 *
 * `destino` era un campo de texto libre que nadie contrastaba con el
 * producto. Salió impreso en un contrato de verdad:
 *
 *   PRIMERA.  ...un credito por $28,680.50, bajo la modalidad de
 *             CREDITO DE VEHICULO...
 *   SEGUNDA.  EL DEUDOR se obliga a invertir el credito unica y
 *             exclusivamente en ESTUDIOS UNIVERSITARIOS.
 *
 * Y la SEGUNDA remata facultando al acreedor a dar por caducado el
 * plazo si el crédito se emplea «en un fin distinto al pactado». O
 * sea: un contrato que se incumple a sí mismo en la segunda cláusula,
 * y que da pie a caducar el plazo de un cliente que hizo exactamente
 * lo que fue a pedir.
 *
 * CÓMO SE EVITA
 *
 * Cada modalidad tiene su lista de destinos. Lo que llegue se
 * contrasta contra ella:
 *
 *   · si está en la lista, se guarda tal cual;
 *   · si es texto libre que encaja con la modalidad, se respeta —hay
 *     destinos legítimos que ninguna lista prevé: «compra de equipo
 *     de ortodoncia» es un destino de crédito personal perfectamente
 *     razonable—;
 *   · si choca de frente con la modalidad —«estudios» en un crédito
 *     de vehículo— se sustituye por el destino propio del producto,
 *     porque entre dos cláusulas que se contradicen manda el producto
 *     que el cliente contrató.
 *
 * El choque se detecta por palabras: no es un analizador de lenguaje,
 * es una lista de términos que pertenecen inequívocamente a otra
 * modalidad. Prefiere dejar pasar un destino raro antes que corregir
 * uno bueno.
 */
final class DestinoDeCredito
{
    /**
     * Los destinos de cada modalidad. El primero es el que se usa
     * cuando hay que poner uno.
     *
     * @var array<string,list<string>>
     */
    private const OPCIONES = [
        'vehiculo' => [
            'Compra de vehículo',
            'Compra de vehículo nuevo',
            'Compra de vehículo usado',
            'Compra de motocicleta',
            'Reparación o repotenciación de vehículo',
        ],
        'vivienda' => [
            'Compra de vivienda',
            'Compra de lote',
            'Construcción de vivienda',
            'Ampliación o remodelación de vivienda',
            'Compra de vivienda para alquiler',
            'Traslado de deuda hipotecaria',
        ],
        'estudios' => [
            'Estudios universitarios',
            'Estudios técnicos',
            'Posgrado o maestría',
            'Diplomado o certificación',
            'Estudios en el exterior',
        ],
        'negocio' => [
            'Capital de trabajo',
            'Compra de inventario',
            'Compra de maquinaria o equipo',
            'Mejoras al local comercial',
            'Apertura de nuevo punto de venta',
        ],
        'personal' => [
            'Gastos personales',
            'Consolidación de deudas',
            'Gastos médicos',
            'Compra de mobiliario o electrodomésticos',
            'Viaje',
            'Gastos familiares',
            'Celebración o evento familiar',
        ],
    ];

    /**
     * Las palabras que delatan a qué modalidad pertenece un texto.
     *
     * Solo se listan términos que no dejan lugar a duda. «Compra» no
     * está porque se compra un carro, una casa y una refrigeradora.
     *
     * @var array<string,list<string>>
     */
    private const DELATORAS = [
        'vehiculo' => ['vehiculo', 'vehículo', 'carro', 'automovil', 'automóvil',
                       'motocicleta', 'moto ', 'pick-up', 'pickup', 'camioneta'],
        'vivienda' => ['vivienda', 'casa', 'inmueble', 'hipotec', 'lote',
                       'apartamento', 'terreno', 'construccion de', 'construcción de'],
        'estudios' => ['estudio', 'universi', 'carrera', 'maestria', 'maestría',
                       'posgrado', 'diplomado', 'colegiatura', 'matricula academica',
                       'matrícula académica', 'tecnico superior', 'técnico superior'],
        'negocio'  => ['negocio', 'capital de trabajo', 'inventario', 'maquinaria',
                       'local comercial', 'punto de venta', 'mercaderia', 'mercadería'],
    ];

    /** @return list<string> */
    public static function opciones(string $modalidad): array
    {
        return self::OPCIONES[$modalidad] ?? self::OPCIONES['personal'];
    }

    /** El destino que se pone cuando el declarado no sirve. */
    public static function porDefecto(string $modalidad): string
    {
        return self::opciones($modalidad)[0];
    }

    /**
     * ¿Este destino cabe en esta modalidad?
     *
     * Devuelve falso solo cuando el texto pertenece claramente a OTRA
     * modalidad. Un destino que no se reconoce no es un destino malo:
     * es uno que la lista no previó, y se acepta.
     */
    public static function cuadra(string $destino, string $modalidad): bool
    {
        $d = self::normalizarTexto($destino);

        if ($d === '') { return false; }

        // Si habla de lo suyo, cuadra y no hay más que mirar.
        foreach (self::DELATORAS[$modalidad] ?? [] as $p) {
            if (str_contains($d, self::normalizarTexto($p))) { return true; }
        }

        // Si habla de lo de otra, no cuadra.
        foreach (self::DELATORAS as $otra => $palabras) {
            if ($otra === $modalidad) { continue; }

            foreach ($palabras as $p) {
                if (str_contains($d, self::normalizarTexto($p))) { return false; }
            }
        }

        /* Ni lo uno ni lo otro: texto libre que no delata nada. Se
           acepta —salvo en las modalidades con objeto propio, donde el
           contrato va a describir un bien concreto y el destino tiene
           que nombrarlo—. */
        return !in_array($modalidad, ['vehiculo', 'vivienda', 'estudios'], true);
    }

    /**
     * El destino que se va a guardar, ya resuelto.
     *
     * Es la única puerta: la solicitud, el desembolso y el contrato
     * pasan por aquí, de modo que no hay forma de que al documento
     * llegue un destino que contradiga su propia modalidad.
     */
    public static function resolver(string $declarado, string $modalidad): string
    {
        $declarado = trim($declarado);

        if ($declarado !== '' && self::cuadra($declarado, $modalidad)) {
            return $declarado;
        }

        return self::porDefecto($modalidad);
    }

    /** Sin tildes, en minúsculas: para comparar sin sorpresas. */
    private static function normalizarTexto(string $s): string
    {
        $s = mb_strtolower(trim($s));

        return strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);
    }
}
