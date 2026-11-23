<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Seguridad;

use CashMan\Infraestructura\Config;

/**
 * Codificador de identificadores para URLs.
 *
 * Convierte:   4  →  "Hq4TmP9xRk"
 * Revierte: "Hq4TmP9xRk" →  4
 *
 * Para qué sirve:
 *   - Las URLs dejan de exponer ids secuenciales. Nadie deduce cuántos
 *     clientes hay viendo /cuentas/1847, ni navega a /cuentas/1848 por
 *     curiosidad.
 *   - Cada tipo de entidad usa un espacio distinto ('cuenta', 'tarjeta'),
 *     así que un código válido para una cuenta no vale para una tarjeta.
 *   - Lleva suma de verificación: un código manipulado se rechaza antes
 *     de tocar la base de datos.
 *
 * ──────────────────────────────────────────────────────────────────
 * DISEÑO: LONGITUD FIJA
 *
 * Una versión anterior rellenaba hasta un mínimo y al decodificar
 * probaba longitudes hasta que la suma de verificación cuadrara. Con
 * una suma de un solo carácter sobre un alfabeto de 49 símbolos, cada
 * intento tenía ~2% de falso positivo; probando 7 longitudes, la
 * colisión era casi segura. En la práctica el id 4 decodificaba como
 * 211356329869.
 *
 * Ahora el formato es rígido: 8 caracteres de cuerpo + 2 de
 * verificación. No hay nada que adivinar, así que no hay colisión
 * posible por ambigüedad de longitud.
 * ──────────────────────────────────────────────────────────────────
 *
 * ESTO NO ES UNA MEDIDA DE SEGURIDAD. Es ofuscación: impide la
 * enumeración casual y mejora el aspecto de las URLs, nada más. La
 * autorización real —comprobar que el objeto pertenece a quien lo
 * pide— sigue siendo obligatoria en TODOS los casos.
 */
final class Hashid
{
    // Sin vocales (evita palabras no deseadas) ni ambigüedades (0/O, 1/l/I).
    private const ALFABETO  = '23456789BCDFGHJKLMNPQRSTVWXYZbcdfghjkmnpqrstvwxyz';
    private const LONG_CUERPO = 8;   // 49^8 ≈ 3.3e13, cubre de sobra 2^40
    private const LONG_SUMA   = 2;   // 49^2 = 2401 combinaciones
    private const MAX_ID      = 1099511627775;  // 2^40 - 1

    private static ?string $clave = null;

    private static function clave(): string
    {
        if (self::$clave === null) {
            self::$clave = Config::claveBinaria('APP_CLAVE');
        }
        return self::$clave;
    }

    /**
     * @param string $espacio Tipo de entidad: 'cuenta', 'tarjeta', 'estado'…
     */
    public static function codificar(int $id, string $espacio = 'general'): string
    {
        if ($id < 0 || $id > self::MAX_ID) {
            throw new \InvalidArgumentException('Identificador fuera de rango.');
        }

        // Máscara por espacio: el mismo id produce códigos distintos
        // según el tipo de entidad.
        $valor  = $id ^ self::mascara($espacio);
        $cuerpo = self::aBaseFija($valor);

        return $cuerpo . self::suma($cuerpo, $espacio);
    }

    /** @return int|null null si el código es inválido o fue manipulado. */
    public static function decodificar(string $codigo, string $espacio = 'general'): ?int
    {
        $codigo = trim($codigo);

        // Longitud exacta: ni más, ni menos.
        if (strlen($codigo) !== self::LONG_CUERPO + self::LONG_SUMA) {
            return null;
        }

        // Solo caracteres del alfabeto.
        if (strspn($codigo, self::ALFABETO) !== strlen($codigo)) {
            return null;
        }

        $cuerpo = substr($codigo, 0, self::LONG_CUERPO);
        $suma   = substr($codigo, self::LONG_CUERPO);

        // Comparación en tiempo constante.
        if (!hash_equals(self::suma($cuerpo, $espacio), $suma)) {
            return null;
        }

        $valor = self::desdeBase($cuerpo);
        if ($valor === null) {
            return null;
        }

        $id = $valor ^ self::mascara($espacio);

        return ($id >= 0 && $id <= self::MAX_ID) ? $id : null;
    }

    /**
     * Decodifica o lanza excepción. Para controladores, donde un código
     * inválido debe terminar en 404.
     */
    public static function exigir(string $codigo, string $espacio = 'general'): int
    {
        $id = self::decodificar($codigo, $espacio);
        if ($id === null || $id === 0) {
            throw new \InvalidArgumentException('Identificador inválido.');
        }
        return $id;
    }

    // -----------------------------------------------------------------

    private static function mascara(string $espacio): int
    {
        $h = hash_hmac('sha256', 'espacio:' . $espacio, self::clave(), true);
        // 40 bits, dentro del rango representable por el cuerpo.
        return (int) (unpack('J', "\x00\x00\x00" . substr($h, 0, 5))[1]);
    }

    private static function suma(string $cuerpo, string $espacio): string
    {
        $h   = hash_hmac('sha256', $espacio . '|' . $cuerpo, self::clave(), true);
        $len = strlen(self::ALFABETO);

        $salida = '';
        for ($i = 0; $i < self::LONG_SUMA; $i++) {
            $salida .= self::ALFABETO[ord($h[$i]) % $len];
        }
        return $salida;
    }

    /** Base-49 con longitud FIJA, rellenando por la izquierda. */
    private static function aBaseFija(int $numero): string
    {
        $base   = strlen(self::ALFABETO);
        $salida = '';

        for ($i = 0; $i < self::LONG_CUERPO; $i++) {
            $salida = self::ALFABETO[$numero % $base] . $salida;
            $numero = intdiv($numero, $base);
        }

        return $salida;
    }

    private static function desdeBase(string $texto): ?int
    {
        $base  = strlen(self::ALFABETO);
        $valor = 0;
        $len   = strlen($texto);

        for ($i = 0; $i < $len; $i++) {
            $pos = strpos(self::ALFABETO, $texto[$i]);
            if ($pos === false) {
                return null;
            }
            if ($valor > intdiv(PHP_INT_MAX - $pos, $base)) {
                return null;   // desbordamiento
            }
            $valor = $valor * $base + $pos;
        }

        return $valor;
    }
}
