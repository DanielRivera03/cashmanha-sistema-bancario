<?php
declare(strict_types=1);

/**
 * El plástico: una sola forma de dibujar una tarjeta.
 *
 * Antes cada pantalla la pintaba a su manera y la misma tarjeta se veía
 * distinta en el panel, en el listado y en su propia ficha. Aquí se
 * dibuja una vez.
 *
 * Se dibuja por LÍNEA —el producto contratado— y no por marca: una
 * Signature es negra la procese quien la procese. La marca va donde va
 * en una tarjeta real, abajo a la derecha.
 *
 * El logo va TAL CUAL, con sus colores. Es azul marino y gris sobre
 * transparente, así que sobre un plástico oscuro no se leería: va
 * sobre una placa clara, como en las tarjetas co-brandeadas de verdad.
 * Teñirlo de blanco habría sido más fácil, pero entonces ya no sería
 * el logo de la casa.
 *
 * Qué lleva cada tarjeta y por qué:
 *
 *   EL FONDO     Un degradado liso se lee como rectángulo de color. Lo
 *                que convierte eso en tarjeta es la trama: guilloche
 *                en la Oro, fibra en la Signature, curvas de nivel en
 *                la de débito.
 *   LA MARCA DE  El emblema, muy tenue y muy grande, al fondo. Es lo
 *   AGUA         que hace que el plástico se vea impreso y no plano.
 *   EL CHIP      Con sus contactos. Es la pieza que lo vuelve tarjeta.
 *   SIN CONTACTO Solo si está activado: un icono que promete algo
 *                apagado es peor que no ponerlo.
 *   EL NÚMERO    En grupos, tabular y con relieve, como va grabado.
 */

if (!function_exists('cm_plastico_linea')) {
    /**
     * Qué línea es esta tarjeta.
     *
     * Sale del nivel del producto, no del nombre: renombrar «Oro» a
     * «Premium» en el catálogo no debería cambiar cómo se ve.
     *
     * @param array<string,mixed> $t
     */
    function cm_plastico_linea(array $t): string
    {
        if ((string) ($t['tipo'] ?? '') === 'debito') {
            return 'debito';
        }

        /* Las líneas que no se distinguen por nivel se reconocen por su
           código: dos productos pueden compartir escalón y tener
           acabados distintos —Cashback e Inicia están en el mismo nivel
           que la Clásica, y Empresarial comparte el 3 con Platinum. */
        $porCodigo = [
            'TAR-CAS' => 'cashback',
            'TAR-INI' => 'inicia',
            'TAR-EMP' => 'empresarial',
            'TAR-INF' => 'infinite',
            'TAR-OBS' => 'obsidiana',
        ];

        $codigo = strtoupper((string) ($t['producto_codigo'] ?? ''));

        if (isset($porCodigo[$codigo])) {
            return $porCodigo[$codigo];
        }

        return match ((int) ($t['producto_nivel'] ?? 1)) {
            6       => 'obsidiana',
            5       => 'infinite',
            4       => 'signature',
            3       => 'platinum',
            2       => 'oro',
            default => 'clasica',
        };
    }
}

if (!function_exists('cm_plastico_nombre_linea')) {
    function cm_plastico_nombre_linea(string $linea): string
    {
        return match ($linea) {
            'debito'      => 'Débito',
            'cashback'    => 'Cashback',
            'inicia'      => 'Inicia',
            'oro'         => 'Oro',
            'platinum'    => 'Platinum',
            'empresarial' => 'Empresarial',
            'signature'   => 'Signature',
            'infinite'    => 'Infinite',
            'obsidiana'   => 'Obsidiana',
            default     => 'Clásica',
        };
    }
}

if (!function_exists('cm_nombre_tarjeta')) {
    /**
     * El nombre como va impreso en un plástico.
     *
     * En el plástico cabe una línea, no un nombre completo. Recortar la
     * cadena con puntos suspensivos —«MARTA ELENA PORTILLO GUZ…»— es lo
     * que se veía antes, y eso no es un nombre: es una cadena rota.
     *
     * La regla es la de cualquier tarjeta real: dos nombres y un
     * apellido si caben; si no, un nombre y un apellido; y si ni eso,
     * la inicial con el apellido. Siempre palabras enteras.
     */
    function cm_nombre_tarjeta(string $nombres, string $apellidos, int $tope = 22): string
    {
        $partir = static fn(string $t): array => array_values(array_filter(
            preg_split('/\s+/u', trim($t)) ?: []
        ));

        $n = $partir($nombres);
        $a = $partir($apellidos);

        $primerNombre   = $n[0] ?? '';
        $primerApellido = $a[0] ?? '';

        if ($primerNombre === '' && $primerApellido === '') {
            return '';
        }

        // Dos nombres y un apellido, si caben.
        if (isset($n[1])) {
            $dos = trim($primerNombre . ' ' . $n[1] . ' ' . $primerApellido);

            if (mb_strlen($dos) <= $tope) {
                return mb_strtoupper($dos);
            }
        }

        $uno = trim($primerNombre . ' ' . $primerApellido);

        if (mb_strlen($uno) <= $tope) {
            return mb_strtoupper($uno);
        }

        // Ni uno y uno: la inicial. Antes que partir una palabra.
        $inicial = $primerNombre !== '' ? mb_substr($primerNombre, 0, 1) . '.' : '';

        return mb_strtoupper(trim($inicial . ' ' . $primerApellido));
    }
}

if (!function_exists('cm_plastico_titular')) {
    /**
     * El titular de ESTA tarjeta, ya acortado.
     *
     * Con los campos separados se acierta siempre. Sin ellos —una
     * tarjeta vieja que solo guarda la línea entera— se toma el primer
     * nombre y el último apellido: no es perfecto, pero es un nombre y
     * no una cadena partida.
     *
     * @param array<string,mixed> $t
     */
    function cm_plastico_titular(array $t): string
    {
        $nombres   = trim((string) ($t['nombres'] ?? ''));
        $apellidos = trim((string) ($t['apellidos'] ?? ''));

        if ($nombres !== '' || $apellidos !== '') {
            return cm_nombre_tarjeta($nombres, $apellidos);
        }

        $palabras = array_values(array_filter(
            preg_split('/\s+/u', trim((string) ($t['titular'] ?? ''))) ?: []
        ));

        if (count($palabras) <= 2) {
            return mb_strtoupper(implode(' ', $palabras));
        }

        return cm_nombre_tarjeta($palabras[0], (string) end($palabras));
    }
}

if (!function_exists('cm_plastico_sello')) {
    /**
     * El sello de la marca.
     *
     * Formas propias, no el logotipo de la red: aquí hace falta que se
     * reconozca de un vistazo cuál es, no reproducir una marca ajena.
     */
    function cm_plastico_sello(string $marca): string
    {
        return match ($marca) {
            'mastercard' =>
                '<span class="cm-pl__sello cm-pl__sello--mc" aria-label="Mastercard">'
                . '<i class="cm-pl__mc1"></i><i class="cm-pl__mc2"></i>'
                . '<b>mastercard</b></span>',

            'amex' =>
                '<span class="cm-pl__sello cm-pl__sello--amex" aria-label="American Express">'
                . '<i class="cm-pl__amex"></i><b>AMERICAN<br>EXPRESS</b></span>',

            default =>
                '<span class="cm-pl__sello cm-pl__sello--visa" aria-label="Visa">'
                . '<b>VISA</b></span>',
        };
    }
}

if (!function_exists('cm_plastico')) {
    /**
     * Dibuja la tarjeta.
     *
     * @param array<string,mixed> $t     Fila de `v_tarjetas_detalle` o equivalente.
     * @param array<string,mixed> $opts  assets, numero, id, idNumero, idVence,
     *                                   idCvv, tamano
     */
    function cm_plastico(array $t, array $opts = []): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $linea  = cm_plastico_linea($t);
        $marca  = (string) ($t['marca'] ?? 'visa');
        $activa = (string) ($t['estado'] ?? 'activa') === 'activa';

        $congelada = !empty($t['congelada'])
            || (!empty($t['congelada_hasta'])
                && strtotime((string) $t['congelada_hasta']) > time());

        $clases = ['cm-pl', 'cm-pl--' . $linea];

        if (!$activa || $congelada) {
            $clases[] = 'cm-pl--apagada';
        }

        if (($opts['tamano'] ?? '') === 'mini') {
            $clases[] = 'cm-pl--mini';
        }

        /* Los dos archivos del logo: el emblema y la palabra. Se pasa la
           carpeta y no cada ruta porque son siempre los mismos dos
           archivos, y pedirlos uno a uno en cada vista es una ocasión
           más de que una se equivoque. */
        $assets  = rtrim((string) ($opts['assets'] ?? ''), '/');
        $emblema = $assets !== '' ? $assets . '/logo.png' : '';
        $palabra = $assets !== '' ? $assets . '/logo-text.png' : '';

        $ultimos4 = (string) ($t['ultimos4'] ?? '0000');

        $numero = $opts['numero'] ?? null;

        if ($numero === null) {
            // Amex agrupa 4-6-5; las demás, 4-4-4-4.
            $numero = $marca === 'amex'
                ? '•••• •••••• ' . $ultimos4
                : '•••• •••• •••• ' . $ultimos4;
        }

        $vence = str_pad((string) ($t['expira_mes'] ?? 1), 2, '0', STR_PAD_LEFT)
               . '/' . substr((string) ($t['expira_anio'] ?? date('Y')), -2);

        // --- La placa del logo -------------------------------------
        $placa = $emblema !== ''
            ? '<span class="cm-pl__placa">'
              . '<img class="cm-pl__emblema" src="' . $e($emblema) . '" alt="">'
              . '<img class="cm-pl__palabra" src="' . $e($palabra) . '" alt="CashMan H.A.">'
              . '</span>'
            : '<span class="cm-pl__casa">CashMan H.A.</span>';

        /* Dos caras.
           La banda, el panel de firma y el CVV viven en el reverso de
           una tarjeta de verdad; enseñar el CVV pegado al frente era lo
           cómodo, no lo cierto. El giro lo hace el CSS. */
        $html = '<div class="cm-pl-giro'
              . (!empty($opts['volteable']) ? ' cm-pl-giro--activo' : '') . '"'
              . (isset($opts['id']) ? ' id="' . $e((string) $opts['id']) . '"' : '')
              . '><div class="cm-pl-giro__eje">'

              . '<div class="' . implode(' ', $clases) . ' cm-pl--frente">'

              // Capas del fondo: trama, brillo y marca de agua.
              . '<span class="cm-pl__trama" aria-hidden="true"></span>'
              . '<span class="cm-pl__brillo" aria-hidden="true"></span>'
              . ($emblema !== ''
                  ? '<img class="cm-pl__agua" src="' . $e($emblema) . '" alt="" aria-hidden="true">'
                  : '')

              . '<div class="cm-pl__cuerpo">'

              . '<div class="cm-pl__alto">'
              . $placa
              . '<span class="cm-pl__linea">' . $e(cm_plastico_nombre_linea($linea)) . '</span>'
              . '</div>'

              . '<div class="cm-pl__medio">'
              . '<span class="cm-pl__chip" aria-hidden="true"></span>'
              . (!empty($t['contactless'])
                  ? '<span class="cm-pl__nfc" aria-label="Pago sin contacto"></span>'
                  : '')
              . '</div>'

              . '<div class="cm-pl__num"'
              . (isset($opts['idNumero']) ? ' id="' . $e((string) $opts['idNumero']) . '"' : '')
              . '>' . $e((string) $numero) . '</div>'

              . '<div class="cm-pl__pie">'
              . '<div class="cm-pl__campo">'
              . '<span class="cm-pl__et">Titular</span>'
              . '<span class="cm-pl__val">' . $e(cm_plastico_titular($t)) . '</span>'
              . '</div>'
              . '<div class="cm-pl__campo cm-pl__campo--der">'
              . '<span class="cm-pl__et">Vence</span>'
              . '<span class="cm-pl__val"'
              . (isset($opts['idVence']) ? ' id="' . $e((string) $opts['idVence']) . '"' : '')
              . '>' . $e($vence) . '</span>'
              . '</div>'

              . cm_plastico_sello($marca)
              . '</div>'

              . '</div>';

        if ($congelada) {
            $html .= '<span class="cm-pl__sello-estado">CONGELADA</span>';
        } elseif (!$activa) {
            $html .= '<span class="cm-pl__sello-estado">'
                   . $e(mb_strtoupper((string) ($t['estado'] ?? ''))) . '</span>';
        }

        $html .= '</div>';   // cierra la cara de frente

        // --- El reverso -------------------------------------------
        $html .= '<div class="' . implode(' ', $clases) . ' cm-pl--reverso">'
               . '<span class="cm-pl__trama" aria-hidden="true"></span>'
               . '<div class="cm-pl__cuerpo cm-pl__cuerpo--rev">'

               // La banda magnética, de lado a lado como en el plástico.
               . '<span class="cm-pl__banda" aria-hidden="true"></span>'

               . '<div class="cm-pl__firma">'
               . '<span class="cm-pl__firma-panel">'
               . '<i>' . $e(cm_plastico_titular($t)) . '</i>'
               . '</span>'
               . '<span class="cm-pl__cvv">'
               . '<small>CVV</small>'
               . '<b' . (isset($opts['idCvv']) ? ' id="' . $e((string) $opts['idCvv']) . '"' : '')
               . '>•••</b>'
               . '</span>'
               . '</div>'

               . '<div class="cm-pl__legal">'
               . '<span>' . $e((string) ($t['cuenta_numero'] ?? '')) . '</span>'
               . '<span>Uso sujeto al contrato. Si la encuentra, devuélvala a '
               . 'CashMan H.A. S.A. de C.V., San Salvador.</span>'
               . '</div>'

               . '</div></div>';

        return $html . '</div></div>';
    }
}
