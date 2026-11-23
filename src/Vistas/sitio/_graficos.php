<?php

/**
 * La biblioteca gráfica del sitio público.
 *
 * Un banco no se vende con texto. La competencia local llena su portada
 * de color, ilustración y producto que se ve; aquí había siete tipos de
 * letra sobre blanco y diecisiete iconos de un solo trazo. Esto es lo
 * que faltaba para que la cara pública se parezca a lo que hay detrás.
 *
 * TODO ES SVG ESCRITO A MANO, Y HAY UNA RAZÓN
 * -------------------------------------------
 * No hay fotografías ni ilustraciones compradas, así que la alternativa
 * era dejarlo plano o dibujarlo. Dibujado en SVG pesa unos kilobytes,
 * se adapta a cualquier pantalla sin pixelarse, se le cambia el color
 * desde la hoja de estilos y no depende de ningún servicio de fuera —lo
 * que además mantiene la política de seguridad de contenido cerrada,
 * sin abrir la mano a dominios de terceros para traer imágenes.
 *
 * Nada de esto imita la marca de ningún banco: los colores, las formas
 * y el logotipo son los de CashMan H.A.
 *
 * QUÉ DEFINE
 * ----------
 *   $ico($trazo, $tam)        un icono de línea
 *   $icoDuo($clave, $tam)     un icono de dos tonos, con relleno
 *   $D                        el diccionario de trazos
 *   $ilustra($clave, $alto)   una escena completa
 *   $ornamento($clave)        formas de fondo, decorativas
 */

/* ---------------------------------------------------------------- */
/*  ICONOS DE LÍNEA                                                  */
/* ---------------------------------------------------------------- */

/** @var callable $ico */
$ico = static function (string $d, int $t = 20): string {
    return '<svg class="ico" width="' . $t . '" height="' . $t . '" viewBox="0 0 24 24" '
         . 'fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" '
         . 'stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
};

/** @var array<string,string> $D */
$D = [
    'ahorro'    => '<path d="M3 9.5L12 3l9 6.5V20a1 1 0 01-1 1h-5v-7H9v7H4a1 1 0 01-1-1V9.5z"/>',
    'corriente' => '<rect x="2" y="6" width="20" height="13" rx="2.5"/><path d="M2 10h20M17 14.5h.01"/>',
    'credito'   => '<path d="M3 17l5.5-5.5 3.5 3.5L21 6"/><path d="M15 6h6v6"/>',
    'tarjeta'   => '<rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20M6 15h3"/>',
    'escudo'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'reloj'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'movil'     => '<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/>',
    'check'     => '<path d="M20 6L9 17l-5-5"/>',
    'candado'   => '<rect x="3" y="11" width="18" height="11" rx="2.5"/><path d="M7 11V7a5 5 0 0110 0v4"/>',
    'libro'     => '<path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>',
    'mapa'      => '<path d="M12 21s7-6.2 7-11a7 7 0 10-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.6"/>',
    'telefono'  => '<path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .4 1.9.7 2.8a2 2 0 01-.5 2.1L8.1 9.9a16 16 0 006 6l1.3-1.3a2 2 0 012.1-.5c.9.3 1.8.6 2.8.7a2 2 0 011.7 2z"/>',
    'correo'    => '<rect x="2" y="4" width="20" height="16" rx="2.5"/><path d="M3 7l9 6 9-6"/>',
    'usuarios'  => '<path d="M17 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M22 21v-2a4 4 0 00-3-3.9"/>',
    'maletin'   => '<rect x="2" y="7" width="20" height="14" rx="2.5"/><path d="M8 7V5a2 2 0 012-2h4a2 2 0 012 2v2"/>',
    'grafica'   => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    'rayo'      => '<path d="M13 2L4 14h7l-1 8 9-12h-7l1-8z"/>',
    'flecha'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    'estrella'  => '<path d="M12 3l2.9 5.9 6.5.9-4.7 4.6 1.1 6.4-5.8-3-5.8 3 1.1-6.4L2.6 9.8l6.5-.9L12 3z"/>',
    'casa'      => '<path d="M3 10.5L12 3l9 7.5V21H3V10.5z"/><path d="M9.5 21v-6h5v6"/>',
    'carro'     => '<path d="M5 17h14M4 17V11l2-5h12l2 5v6"/><circle cx="7.5" cy="17" r="2"/><circle cx="16.5" cy="17" r="2"/>',
    'birrete'   => '<path d="M12 4L2 9l10 5 10-5-10-5z"/><path d="M6 11.5V17c0 1.5 2.7 3 6 3s6-1.5 6-3v-5.5"/>',
    'tienda'    => '<path d="M3 9l1.5-5h15L21 9M3 9h18M3 9v11h18V9"/><path d="M9 20v-6h6v6"/>',
    'reloj24'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
    'sinpapel'  => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/><path d="M8 17l8-8"/>',
    'sucursal'  => '<path d="M3 21h18M4 21V9l8-6 8 6v12"/><path d="M10 21v-6h4v6"/>',
];

/* ---------------------------------------------------------------- */
/*  ICONOS DE DOS TONOS                                              */
/* ---------------------------------------------------------------- */

/**
 * Un icono con una forma de fondo rellena y el trazo encima.
 *
 * Es lo que separa un icono que decora de uno que se ve: el relleno da
 * peso y color, el trazo da el detalle. Los dos tonos salen del color
 * que herede del contenedor, así que una misma pieza sirve para todas
 * las familias de producto sin duplicarla.
 *
 * @var callable $icoDuo
 */
$icoDuo = static function (string $clave, int $t = 44) use ($D): string {
    $fondos = [
        'ahorro'    => '<path d="M12 2.6l9.6 7v11a1.4 1.4 0 01-1.4 1.4h-5.4v-7.4H9.2V22H3.8A1.4 1.4 0 012.4 20.6v-11l9.6-7z"/>',
        'tarjeta'   => '<rect x="1.6" y="4.4" width="20.8" height="15.2" rx="3"/>',
        'credito'   => '<path d="M2.6 20.4L9 14l3.6 3.6L21.4 8.8V20.4H2.6z"/>',
        'escudo'    => '<path d="M12 22.4s8.6-4.3 8.6-10.7V4.7L12 1.4 3.4 4.7v7c0 6.4 8.6 10.7 8.6 10.7z"/>',
        'movil'     => '<rect x="5.4" y="1.4" width="13.2" height="21.2" rx="3"/>',
        'rayo'      => '<path d="M13.6 1.2L3.2 14.6h7.4l-1.2 8.6 10.4-13.4h-7.4l1.2-8.6z"/>',
        'reloj'     => '<circle cx="12" cy="12" r="9.6"/>',
        'usuarios'  => '<circle cx="9.5" cy="7" r="4.6"/><path d="M17.6 21.6v-2.2a4.6 4.6 0 00-4.6-4.6H6a4.6 4.6 0 00-4.6 4.6v2.2z"/>',
        'mapa'      => '<path d="M12 21.6s7.4-6.5 7.4-11.6a7.4 7.4 0 10-14.8 0c0 5.1 7.4 11.6 7.4 11.6z"/>',
        'maletin'   => '<rect x="1.6" y="6.6" width="20.8" height="14.8" rx="3"/>',
        'tienda'    => '<path d="M2.6 8.6L4.2 3.4h15.6l1.6 5.2v12H2.6v-12z"/>',
        'candado'   => '<rect x="2.6" y="10.6" width="18.8" height="11.8" rx="3"/>',
    ];

    $fondo = $fondos[$clave] ?? '<circle cx="12" cy="12" r="9.6"/>';

    /* `currentColor` con poca opacidad para el fondo y color pleno para
       el trazo: así el icono se tiñe entero con una sola variable y no
       hay que mantener una paleta por icono. */
    return '<svg class="ico-duo" width="' . $t . '" height="' . $t . '" viewBox="0 0 24 24" '
         . 'aria-hidden="true" focusable="false">'
         . '<g fill="currentColor" opacity=".16">' . $fondo . '</g>'
         . '<g fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" '
         . 'stroke-linejoin="round">' . ($D[$clave] ?? '') . '</g>'
         . '</svg>';
};

/* ---------------------------------------------------------------- */
/*  EL PLÁSTICO, POR PRODUCTO                                        */
/* ---------------------------------------------------------------- */

/**
 * Dibuja una tarjeta en perspectiva, con la identidad de su nivel.
 *
 * Una lista de tarjetas en la que todas se ven igual no es un catálogo:
 * es una tabla de precios. Lo que hace que alguien distinga la Clásica
 * de la Platinum antes de leer una palabra es que cada una tenga su
 * color, su textura y su acabado, como lo tiene el plástico de verdad.
 *
 * El giro en perspectiva se hace con una matriz, no con `transform` de
 * CSS: así la imagen es la misma en cualquier navegador y no depende de
 * que el motor soporte 3D.
 *
 * @param string $nivel  clasica | oro | platinum | signature | obsidiana | debito
 * @var callable $arteTarjeta
 */
$arteTarjeta = static function (string $nivel, string $marca = 'visa'): string {
    /* Cada nivel con su paleta: el fondo, el degradado de encima y el
       tono del detalle. Son los colores de CashMan H.A., no los de
       ninguna otra institución. */
    $paletas = [
        'clasica'   => ['#5b2bd6', '#2f1070', '#cfc4ef'],
        'oro'       => ['#b8860f', '#6d4a04', '#ffe6a8'],
        'platinum'  => ['#4a5568', '#1f2733', '#dbe2ec'],
        'signature' => ['#1f3a5f', '#0b1a2e', '#9fc3e8'],
        'obsidiana' => ['#2a2a30', '#0c0c0f', '#b9b9c4'],
        'debito'    => ['#0f766e', '#063f3a', '#9fe3d8'],
        'inicia'    => ['#c2410c', '#6b2208', '#ffd3b0'],
        'cashback'  => ['#be185d', '#6b0d35', '#ffc2dd'],
        'empresa'   => ['#334155', '#111827', '#cbd5e1'],
    ];

    [$a, $b, $detalle] = $paletas[$nivel] ?? $paletas['clasica'];
    $id = 'art' . substr(md5($nivel . $marca), 0, 6);

    /* El emblema de la marca. Son círculos y trazos genéricos: la forma
       universal de "esto es una tarjeta", sin reproducir el logotipo
       registrado de ninguna red de pago. */
    $emblema = $marca === 'mastercard'
        ? '<circle cx="300" cy="196" r="19" fill="#fff" opacity=".82"/>'
          . '<circle cx="324" cy="196" r="19" fill="' . $detalle . '" opacity=".85"/>'
        : '<path d="M292 186h46l-9 22h-28z" fill="#fff" opacity=".85"/>'
          . '<path d="M296 206h40" stroke="' . $detalle . '" stroke-width="4" stroke-linecap="round"/>';

    return <<<SVG
<svg class="plastico" viewBox="0 0 420 260" role="img" aria-label="Tarjeta {$nivel}">
  <defs>
    <linearGradient id="{$id}f" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="{$a}"/><stop offset="1" stop-color="{$b}"/>
    </linearGradient>
    <linearGradient id="{$id}b" x1="0" y1="0" x2=".8" y2="1">
      <stop offset="0" stop-color="#fff" stop-opacity=".30"/>
      <stop offset=".5" stop-color="#fff" stop-opacity="0"/>
    </linearGradient>
    <pattern id="{$id}t" width="26" height="26" patternUnits="userSpaceOnUse"
             patternTransform="rotate(32)">
      <path d="M0 13h26" stroke="#fff" stroke-opacity=".07" stroke-width="7"/>
    </pattern>
  </defs>

  <!-- matrix: gira y acuesta la tarjeta sin depender del 3D del navegador -->
  <g transform="matrix(0.94,-0.17,0.30,0.96,-16,34)">
    <rect x="34" y="30" width="356" height="212" rx="24" fill="url(#{$id}f)"/>
    <rect x="34" y="30" width="356" height="212" rx="24" fill="url(#{$id}t)"/>
    <rect x="34" y="30" width="356" height="212" rx="24" fill="url(#{$id}b)"/>

    <rect x="68" y="92" width="48" height="36" rx="7" fill="{$detalle}" opacity=".92"/>
    <path d="M68 104h48M68 116h48M92 92v36" stroke="{$b}" stroke-width="1.5" opacity=".5"/>

    <g fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" opacity=".7">
      <path d="M134 99a15 15 0 010 24"/><path d="M144 92a25 25 0 010 38"/>
    </g>

    <g fill="#fff" opacity=".9">
      <rect x="68" y="158" width="48" height="9" rx="4.5"/>
      <rect x="124" y="158" width="48" height="9" rx="4.5"/>
      <rect x="180" y="158" width="48" height="9" rx="4.5"/>
      <rect x="236" y="158" width="48" height="9" rx="4.5"/>
    </g>

    <g fill="#fff" opacity=".55">
      <rect x="68" y="188" width="96" height="7" rx="3.5"/>
      <rect x="68" y="203" width="62" height="7" rx="3.5"/>
    </g>

    {$emblema}
  </g>
</svg>
SVG;
};

/* ---------------------------------------------------------------- */
/*  ESCENAS                                                          */
/* ---------------------------------------------------------------- */

/**
 * Ilustraciones completas, para cabeceras y bloques destacados.
 *
 * Cada una se dibuja con los colores de la marca puestos en variables
 * CSS, de modo que si mañana cambia el morado, cambian todas sin tocar
 * este archivo.
 *
 * @var callable $ilustra
 */
$ilustra = static function (string $clave): string {
    switch ($clave) {

        /* Una tarjeta de crédito en perspectiva, con su banda, su chip
           y el brillo. Es el producto que mejor se deja dibujar y el
           que más se busca en la portada de un banco. */
        case 'tarjeta':
            return <<<'SVG'
<svg class="il il--tarjeta" viewBox="0 0 420 270" role="img" aria-label="Tarjeta de crédito CashMan">
  <defs>
    <linearGradient id="ilTarA" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#7a2ae0"/><stop offset="1" stop-color="#3d0f78"/>
    </linearGradient>
    <linearGradient id="ilTarB" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#ffd980"/><stop offset="1" stop-color="#c99a2e"/>
    </linearGradient>
    <linearGradient id="ilTarBrillo" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#fff" stop-opacity=".34"/>
      <stop offset=".55" stop-color="#fff" stop-opacity="0"/>
    </linearGradient>
  </defs>

  <!-- La de atrás, apenas asomando: sugiere que hay más de una -->
  <g transform="rotate(-9 210 135)" opacity=".5">
    <rect x="52" y="48" width="300" height="186" rx="22" fill="url(#ilTarA)"/>
  </g>

  <!-- La de delante -->
  <g transform="rotate(-4 210 135)">
    <rect x="42" y="38" width="300" height="186" rx="22" fill="url(#ilTarA)"/>
    <rect x="42" y="38" width="300" height="186" rx="22" fill="url(#ilTarBrillo)"/>

    <!-- chip -->
    <rect x="72" y="92" width="44" height="33" rx="6" fill="url(#ilTarB)"/>
    <path d="M72 103h44M72 114h44M94 92v33" stroke="#9a7320" stroke-width="1.4" opacity=".55"/>

    <!-- contactless -->
    <g fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" opacity=".75">
      <path d="M132 97a14 14 0 010 23"/>
      <path d="M141 90a24 24 0 010 37"/>
    </g>

    <!-- número -->
    <g fill="#fff" opacity=".92">
      <rect x="72" y="150" width="46" height="8" rx="4"/>
      <rect x="126" y="150" width="46" height="8" rx="4"/>
      <rect x="180" y="150" width="46" height="8" rx="4"/>
      <rect x="234" y="150" width="46" height="8" rx="4"/>
    </g>

    <g fill="#fff" opacity=".6">
      <rect x="72" y="178" width="92" height="6" rx="3"/>
      <rect x="72" y="192" width="58" height="6" rx="3"/>
    </g>

    <!-- marca -->
    <circle cx="296" cy="188" r="17" fill="#fff" opacity=".9"/>
    <circle cx="277" cy="188" r="17" fill="#ffd980" opacity=".85"/>
  </g>
</svg>
SVG;

        /* El teléfono con la banca abierta. Dice "esto se hace desde
           donde esté" mejor que un párrafo. */
        case 'movil':
            return <<<'SVG'
<svg class="il il--movil" viewBox="0 0 300 420" role="img" aria-label="La banca en línea en el teléfono">
  <defs>
    <linearGradient id="ilMovFondo" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#7a2ae0"/><stop offset="1" stop-color="#41107f"/>
    </linearGradient>
  </defs>

  <rect x="56" y="18" width="188" height="384" rx="30" fill="#1b1d24"/>
  <rect x="64" y="26" width="172" height="368" rx="24" fill="#fff"/>
  <rect x="122" y="34" width="56" height="7" rx="3.5" fill="#1b1d24" opacity=".45"/>

  <!-- cabecera con el saldo -->
  <path d="M64 50h172v104a24 24 0 01-24 24H88a24 24 0 01-24-24V50z" fill="url(#ilMovFondo)"/>
  <g fill="#fff">
    <rect x="86" y="72" width="74" height="7" rx="3.5" opacity=".6"/>
    <rect x="86" y="90" width="118" height="17" rx="6"/>
    <rect x="86" y="118" width="52" height="6" rx="3" opacity=".45"/>
  </g>

  <!-- accesos rápidos -->
  <g>
    <rect x="84" y="196" width="46" height="46" rx="14" fill="#f4eefc"/>
    <rect x="127" y="196" width="46" height="46" rx="14" fill="#f4eefc"/>
    <rect x="170" y="196" width="46" height="46" rx="14" fill="#f4eefc"/>
    <g fill="none" stroke="#6418c3" stroke-width="2" stroke-linecap="round">
      <path d="M99 219h16M107 211v16"/>
      <path d="M142 213h16M158 213l-5-4M158 213l-5 4M158 225h-16M142 225l5-4M142 225l5 4"/>
      <path d="M185 225l5-5 4 4 7-8"/>
    </g>
  </g>

  <!-- movimientos -->
  <g>
    <rect x="84" y="262" width="132" height="10" rx="5" fill="#e6e9ef"/>
    <rect x="84" y="286" width="96" height="8" rx="4" fill="#eef0f5"/>
    <rect x="186" y="286" width="30" height="8" rx="4" fill="#d9f2e4"/>
    <rect x="84" y="312" width="110" height="8" rx="4" fill="#eef0f5"/>
    <rect x="186" y="312" width="30" height="8" rx="4" fill="#fde3e3"/>
    <rect x="84" y="338" width="84" height="8" rx="4" fill="#eef0f5"/>
    <rect x="186" y="338" width="30" height="8" rx="4" fill="#eef0f5"/>
  </g>

  <rect x="118" y="374" width="64" height="5" rx="2.5" fill="#1b1d24" opacity=".25"/>
</svg>
SVG;

        /* El escudo con la llave: la seguridad, que es de lo que más
           desconfía quien abre una cuenta por internet. */
        case 'seguridad':
            return <<<'SVG'
<svg class="il il--seguridad" viewBox="0 0 260 260" role="img" aria-label="Su dinero protegido">
  <defs>
    <linearGradient id="ilSegA" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#7a2ae0"/><stop offset="1" stop-color="#41107f"/>
    </linearGradient>
  </defs>
  <circle cx="130" cy="130" r="112" fill="#f4eefc"/>
  <circle cx="130" cy="130" r="86" fill="#fff"/>
  <path d="M130 48l62 23v50c0 44-62 74-62 74s-62-30-62-74V71l62-23z" fill="url(#ilSegA)"/>
  <g fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round">
    <path d="M104 128v-13a26 26 0 0152 0v13"/>
  </g>
  <rect x="97" y="128" width="66" height="46" rx="11" fill="#ffd980"/>
  <circle cx="130" cy="147" r="7" fill="#41107f"/>
  <path d="M130 152v9" stroke="#41107f" stroke-width="5" stroke-linecap="round"/>
</svg>
SVG;

        /* El crecimiento del ahorro, para la familia de cuentas. */
        case 'crecer':
            return <<<'SVG'
<svg class="il il--crecer" viewBox="0 0 300 220" role="img" aria-label="Su ahorro creciendo">
  <defs>
    <linearGradient id="ilCreA" x1="0" y1="1" x2="0" y2="0">
      <stop offset="0" stop-color="#7a2ae0" stop-opacity=".12"/>
      <stop offset="1" stop-color="#7a2ae0" stop-opacity=".45"/>
    </linearGradient>
  </defs>
  <path d="M20 180h260" stroke="#e6e9ef" stroke-width="2"/>
  <path d="M20 180V40M20 140h260M20 100h260M20 60h260" stroke="#eef0f5" stroke-width="1.4"/>
  <path d="M28 160l44-22 42 14 44-42 46-20 48-38v128H28z" fill="url(#ilCreA)"/>
  <path d="M28 160l44-22 42 14 44-42 46-20 48-38"
        fill="none" stroke="#6418c3" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
  <g fill="#fff" stroke="#6418c3" stroke-width="3.4">
    <circle cx="72" cy="138" r="6.5"/><circle cx="114" cy="152" r="6.5"/>
    <circle cx="158" cy="110" r="6.5"/><circle cx="204" cy="90" r="6.5"/>
  </g>
  <circle cx="252" cy="52" r="10" fill="#c99a2e"/>
  <g fill="#c99a2e" opacity=".9">
    <circle cx="238" cy="24" r="4"/><circle cx="268" cy="28" r="3"/>
  </g>
</svg>
SVG;

        default:
            return '';
    }
};

/* ---------------------------------------------------------------- */
/*  ESCENARIOS DE EL SALVADOR                                        */
/* ---------------------------------------------------------------- */

/**
 * El telón de fondo de cada cabecera.
 *
 * Un banco salvadoreño con una cabecera de degradado morado y nada más
 * podría ser de cualquier parte. Esto la ancla aquí: el Salvador del
 * Mundo, los volcanes, la costa del Pacífico, el cafetal.
 *
 * CÓMO ESTÁ HECHO
 * ---------------
 * Capas de silueta, de la más lejana a la más cercana, con opacidades
 * distintas. Esa diferencia es la que da la sensación de profundidad
 * sin dibujar un solo detalle: el cerro del fondo se ve más claro que
 * el de delante porque entre los dos hay aire.
 *
 * Va abajo y el texto arriba a la izquierda, de modo que nunca se
 * pisan por mucho que cambie el largo del titular.
 *
 * El movimiento lo pone la hoja de estilos, no un guion: nubes que
 * cruzan, un sol que respira y el propio degradado que se desplaza.
 * Con `prefers-reduced-motion` se queda todo quieto.
 *
 * SOBRE LOS MONUMENTOS
 * --------------------
 * Son siluetas estilizadas de lugares públicos y accidentes
 * geográficos, no reproducciones. Ninguna lleva marca, escudo ni
 * emblema oficial.
 *
 * @var callable $escenario
 */
$escenario = static function (string $clave): string {
    /* Las nubes y los pájaros se repiten en varias escenas: se arman
       una vez y se insertan donde toque. */
    $nubes = '<g class="esc-nubes" fill="#fff" opacity=".13">'
           . '<ellipse cx="190" cy="92" rx="54" ry="16"/>'
           . '<ellipse cx="232" cy="86" rx="38" ry="13"/>'
           . '<ellipse cx="760" cy="64" rx="46" ry="14"/>'
           . '<ellipse cx="800" cy="59" rx="32" ry="11"/>'
           . '<ellipse cx="1180" cy="104" rx="60" ry="17"/>'
           . '</g>';

    $aves = '<g class="esc-aves" fill="none" stroke="#fff" stroke-opacity=".30" '
          . 'stroke-width="2.2" stroke-linecap="round">'
          . '<path d="M520 76c6-7 12-7 18 0M538 76c6-7 12-7 18 0"/>'
          . '<path d="M596 112c5-6 10-6 15 0M611 112c5-6 10-6 15 0"/>'
          . '</g>';

    switch ($clave) {

        /* El Salvador del Mundo: la esfera sobre el pedestal con la
           figura encima. Es el punto de referencia de San Salvador y
           el que cualquiera reconoce de inmediato. */
        case 'salvador-mundo':
            return <<<SVG
<div class="esc esc--mundo" aria-hidden="true">
  <svg viewBox="0 0 1440 320" preserveAspectRatio="xMidYMax slice">
    <circle class="esc-sol" cx="1150" cy="92" r="58" fill="#ffd980" opacity=".22"/>
    <circle class="esc-sol esc-sol--nucleo" cx="1150" cy="92" r="30" fill="#ffe9b4" opacity=".4"/>
    {$nubes}
    {$aves}

    <!-- cerros del fondo -->
    <path fill="#fff" opacity=".07"
          d="M0 250l180-92 150 66 170-108 200 120 190-78 250 104 300-60v218H0z"/>

    <!-- el monumento, sobre su plaza -->
    <g class="esc-monumento">
      <!-- pedestal escalonado -->
      <path fill="#fff" opacity=".22" d="M688 320V236h104v84z"/>
      <path fill="#fff" opacity=".18" d="M664 320v-28h152v28z"/>
      <path fill="#fff" opacity=".26" d="M700 236v-52h80v52z"/>
      <!-- la esfera -->
      <circle cx="740" cy="150" r="40" fill="#fff" opacity=".3"/>
      <ellipse cx="740" cy="150" rx="40" ry="14" fill="none"
               stroke="#fff" stroke-opacity=".22" stroke-width="2"/>
      <path d="M740 110v80" stroke="#fff" stroke-opacity=".22" stroke-width="2"/>
      <!-- la figura -->
      <g fill="#fff" opacity=".34">
        <circle cx="740" cy="92" r="9"/>
        <path d="M733 101h14l5 24h-24z"/>
        <path d="M716 108h48v6h-48z"/>
      </g>
    </g>

    <!-- la plaza, delante -->
    <path fill="#fff" opacity=".13" d="M0 300c220-26 420-18 640 4s460 18 800-10v26H0z"/>
  </svg>
</div>
SVG;

        /* Los volcanes, con la laguna al pie. */
        case 'volcanes':
            return <<<SVG
<div class="esc esc--volcanes" aria-hidden="true">
  <svg viewBox="0 0 1440 320" preserveAspectRatio="xMidYMax slice">
    <circle class="esc-sol" cx="1180" cy="80" r="62" fill="#ffd980" opacity=".2"/>
    <circle class="esc-sol esc-sol--nucleo" cx="1180" cy="80" r="32" fill="#ffe9b4" opacity=".38"/>
    {$nubes}
    {$aves}

    <!-- cordillera lejana -->
    <path fill="#fff" opacity=".07"
          d="M0 270l210-120 140 74 180-96 200 108 230-128 230 134 250-70v148H0z"/>

    <!-- volcán principal, con su penacho -->
    <g class="esc-humo">
      <ellipse cx="700" cy="118" rx="16" ry="9" fill="#fff" opacity=".16"/>
      <ellipse cx="716" cy="98" rx="12" ry="7" fill="#fff" opacity=".11"/>
      <ellipse cx="734" cy="82" rx="9" ry="5" fill="#fff" opacity=".07"/>
    </g>
    <path fill="#fff" opacity=".2" d="M690 128l150 192H540z"/>
    <path fill="#fff" opacity=".1" d="M690 128l-28 36 40 22 36-26z"/>

    <!-- segundo volcán -->
    <path fill="#fff" opacity=".16" d="M980 168l126 152H854z"/>

    <!-- laguna -->
    <path class="esc-agua" fill="#fff" opacity=".12"
          d="M0 288c180-20 340-12 520 6s420 16 920-10v36H0z"/>
    <g class="esc-brillo" fill="#fff" opacity=".1">
      <rect x="180" y="300" width="90" height="3" rx="1.5"/>
      <rect x="320" y="308" width="60" height="3" rx="1.5"/>
      <rect x="1040" y="302" width="76" height="3" rx="1.5"/>
    </g>
  </svg>
</div>
SVG;

        /* La costa del Pacífico. */
        case 'costa':
            return <<<SVG
<div class="esc esc--costa" aria-hidden="true">
  <svg viewBox="0 0 1440 320" preserveAspectRatio="xMidYMax slice">
    <circle class="esc-sol" cx="250" cy="96" r="60" fill="#ffd980" opacity=".22"/>
    <circle class="esc-sol esc-sol--nucleo" cx="250" cy="96" r="31" fill="#ffe9b4" opacity=".4"/>
    {$nubes}
    {$aves}

    <path fill="#fff" opacity=".07" d="M0 236l260-70 300 44 320-74 560 86v98H0z"/>

    <!-- palmeras -->
    <g fill="#fff" opacity=".22">
      <path d="M1180 320V214" stroke="#fff" stroke-opacity=".22" stroke-width="7" stroke-linecap="round"/>
      <path d="M1180 214c-26-16-50-12-64 4 20-4 36 0 48 10z"/>
      <path d="M1180 214c26-16 50-12 64 4-20-4-36 0-48 10z"/>
      <path d="M1180 212c-6-26 4-46 24-54-10 18-12 36-8 50z"/>
    </g>
    <g fill="#fff" opacity=".16">
      <path d="M1296 320V238" stroke="#fff" stroke-opacity=".16" stroke-width="6" stroke-linecap="round"/>
      <path d="M1296 238c-20-13-40-10-52 3 16-3 29 0 39 8z"/>
      <path d="M1296 238c20-13 40-10 52 3-16-3-29 0-39 8z"/>
    </g>

    <!-- olas, una detrás de otra -->
    <path class="esc-ola esc-ola--1" fill="#fff" opacity=".11"
          d="M-200 272c120 22 240 22 360 0s240-22 360 0 240 22 360 0 240-22 360 0v60h-1440z"/>
    <path class="esc-ola esc-ola--2" fill="#fff" opacity=".14"
          d="M-200 292c120 20 240 20 360 0s240-20 360 0 240 20 360 0 240-20 360 0v40h-1440z"/>
  </svg>
</div>
SVG;

        /* La ciudad, para la cara de empresas. */
        case 'ciudad':
            return <<<SVG
<div class="esc esc--ciudad" aria-hidden="true">
  <svg viewBox="0 0 1440 320" preserveAspectRatio="xMidYMax slice">
    <circle class="esc-sol" cx="1100" cy="86" r="54" fill="#ffd980" opacity=".18"/>
    {$nubes}

    <!-- volcán de fondo, que en San Salvador siempre está ahí -->
    <path fill="#fff" opacity=".06" d="M0 250l250-130 200 110 240-92 300 112 450-80v150H0z"/>

    <!-- edificios, dos planos -->
    <g fill="#fff" opacity=".12">
      <rect x="90"  y="206" width="78"  height="114" rx="4"/>
      <rect x="196" y="178" width="60"  height="142" rx="4"/>
      <rect x="284" y="222" width="88"  height="98"  rx="4"/>
      <rect x="1020" y="192" width="70" height="128" rx="4"/>
      <rect x="1118" y="224" width="92" height="96"  rx="4"/>
      <rect x="1240" y="170" width="62" height="150" rx="4"/>
    </g>
    <g fill="#fff" opacity=".2">
      <rect x="440" y="150" width="84"  height="170" rx="5"/>
      <rect x="552" y="196" width="100" height="124" rx="5"/>
      <rect x="680" y="124" width="76"  height="196" rx="5"/>
      <rect x="784" y="182" width="110" height="138" rx="5"/>
      <rect x="922" y="214" width="72"  height="106" rx="5"/>
    </g>

    <!-- ventanas encendidas -->
    <g class="esc-ventanas" fill="#ffe9b4" opacity=".4">
      <rect x="456" y="172" width="9" height="12" rx="2"/>
      <rect x="478" y="172" width="9" height="12" rx="2"/>
      <rect x="500" y="196" width="9" height="12" rx="2"/>
      <rect x="456" y="220" width="9" height="12" rx="2"/>
      <rect x="696" y="150" width="9" height="12" rx="2"/>
      <rect x="718" y="178" width="9" height="12" rx="2"/>
      <rect x="696" y="214" width="9" height="12" rx="2"/>
      <rect x="800" y="206" width="9" height="12" rx="2"/>
      <rect x="840" y="240" width="9" height="12" rx="2"/>
      <rect x="576" y="226" width="9" height="12" rx="2"/>
    </g>
  </svg>
</div>
SVG;

        /* El cafetal en ladera. */
        case 'cafetal':
            return <<<SVG
<div class="esc esc--cafetal" aria-hidden="true">
  <svg viewBox="0 0 1440 320" preserveAspectRatio="xMidYMax slice">
    <circle class="esc-sol" cx="1160" cy="88" r="56" fill="#ffd980" opacity=".2"/>
    <circle class="esc-sol esc-sol--nucleo" cx="1160" cy="88" r="29" fill="#ffe9b4" opacity=".36"/>
    {$nubes}
    {$aves}

    <path fill="#fff" opacity=".07" d="M0 254l240-104 220 86 260-96 300 104 420-66v142H0z"/>
    <path fill="#fff" opacity=".13" d="M0 290l300-62 280 54 320-48 540 44v42H0z"/>

    <!-- hileras de cafetos -->
    <g fill="#fff" opacity=".18">
      <circle cx="140" cy="288" r="13"/><circle cx="206" cy="282" r="13"/>
      <circle cx="272" cy="286" r="13"/><circle cx="338" cy="278" r="13"/>
      <circle cx="404" cy="284" r="13"/><circle cx="470" cy="276" r="13"/>
      <circle cx="980" cy="280" r="13"/><circle cx="1046" cy="286" r="13"/>
      <circle cx="1112" cy="278" r="13"/><circle cx="1178" cy="284" r="13"/>
      <circle cx="1244" cy="276" r="13"/>
    </g>
    <g fill="#fff" opacity=".1">
      <rect x="138" y="296" width="4" height="24" rx="2"/>
      <rect x="204" y="290" width="4" height="30" rx="2"/>
      <rect x="270" y="294" width="4" height="26" rx="2"/>
      <rect x="978" y="288" width="4" height="32" rx="2"/>
      <rect x="1110" y="286" width="4" height="34" rx="2"/>
    </g>
  </svg>
</div>
SVG;

        /* El mercado, para la tienda. Toldos, puestos y el cerro
           detrás: lo que se ve en cualquier pueblo del país un sábado
           por la mañana, que es de lo que va comprar. */
        case 'mercado':
            return <<<SVG
<div class="esc esc--mercado" aria-hidden="true">
  <svg viewBox="0 0 1440 320" preserveAspectRatio="xMidYMax slice">
    <circle class="esc-sol" cx="1190" cy="84" r="56" fill="#ffd980" opacity=".2"/>
    <circle class="esc-sol esc-sol--nucleo" cx="1190" cy="84" r="29" fill="#ffe9b4" opacity=".36"/>
    {$nubes}
    {$aves}

    <!-- el cerro, siempre al fondo -->
    <path fill="#fff" opacity=".07" d="M0 248l260-112 230 92 250-104 300 112 400-72v156H0z"/>

    <!-- banderines, de poste a poste -->
    <g class="esc-banderines" fill="#fff" opacity=".2">
      <path d="M120 196l22 30 22-30zM164 198l22 30 22-30zM208 200l22 30 22-30z"/>
      <path d="M1140 198l22 30 22-30zM1184 196l22 30 22-30zM1228 198l22 30 22-30z"/>
    </g>
    <path d="M110 192q120 18 240 6" fill="none" stroke="#fff" stroke-opacity=".16" stroke-width="2"/>
    <path d="M1130 194q120 16 240 2" fill="none" stroke="#fff" stroke-opacity=".16" stroke-width="2"/>

    <!-- puestos, con sus toldos -->
    <g>
      <path fill="#fff" opacity=".24" d="M300 238h190l-24-40H324z"/>
      <path fill="#fff" opacity=".14" d="M318 238h154v82H318z"/>
      <path fill="#fff" opacity=".2"  d="M560 244h210l-26-44H586z"/>
      <path fill="#fff" opacity=".12" d="M580 244h170v76H580z"/>
      <path fill="#fff" opacity=".24" d="M840 236h196l-24-42H864z"/>
      <path fill="#fff" opacity=".14" d="M858 236h160v84H858z"/>
    </g>

    <!-- la fruta de los puestos, que es lo que les da vida -->
    <g class="esc-fruta" fill="#ffe9b4" opacity=".34">
      <circle cx="350" cy="256" r="7"/><circle cx="370" cy="252" r="6"/>
      <circle cx="390" cy="257" r="7"/><circle cx="412" cy="253" r="6"/>
      <circle cx="612" cy="262" r="7"/><circle cx="634" cy="258" r="6"/>
      <circle cx="656" cy="263" r="7"/>
      <circle cx="890" cy="254" r="7"/><circle cx="912" cy="250" r="6"/>
      <circle cx="934" cy="255" r="7"/><circle cx="956" cy="251" r="6"/>
    </g>

    <!-- la calle -->
    <path fill="#fff" opacity=".1" d="M0 306c240-16 460-10 700 2s480 10 740-6v18H0z"/>
  </svg>
</div>
SVG;

        default:
            return '';
    }
};

/* ---------------------------------------------------------------- */
/*  ORNAMENTOS                                                       */
/* ---------------------------------------------------------------- */

/**
 * Formas de fondo, puramente decorativas.
 *
 * Llevan `aria-hidden` porque no dicen nada: están para que una sección
 * no sea un rectángulo de color plano. Quien navega con lector de
 * pantalla no se pierde nada al saltarlas.
 *
 * @var callable $ornamento
 */
$ornamento = static function (string $clave): string {
    switch ($clave) {
        case 'puntos':
            return '<svg class="orn orn--puntos" viewBox="0 0 120 120" aria-hidden="true">'
                 . '<defs><pattern id="ornPt" width="16" height="16" patternUnits="userSpaceOnUse">'
                 . '<circle cx="2.4" cy="2.4" r="2.4" fill="currentColor"/></pattern></defs>'
                 . '<rect width="120" height="120" fill="url(#ornPt)"/></svg>';

        case 'anillos':
            return '<svg class="orn orn--anillos" viewBox="0 0 200 200" aria-hidden="true">'
                 . '<g fill="none" stroke="currentColor" stroke-width="1.6">'
                 . '<circle cx="100" cy="100" r="42"/><circle cx="100" cy="100" r="64"/>'
                 . '<circle cx="100" cy="100" r="86"/></g></svg>';

        case 'onda':
            return '<svg class="orn orn--onda" viewBox="0 0 1440 120" preserveAspectRatio="none" aria-hidden="true">'
                 . '<path d="M0 64c160 40 320 52 480 36s320-60 480-60 320 28 480 44v36H0z" fill="currentColor"/>'
                 . '</svg>';

        case 'mancha':
            return '<svg class="orn orn--mancha" viewBox="0 0 200 200" aria-hidden="true">'
                 . '<path fill="currentColor" d="M44 -62C58 -48 70 -30 74 -10c4 20 0 42 -12 58c-12 16 -32 26 -52 30'
                 . 'c-20 4 -40 2 -56 -10C-62 56 -74 34 -76 12c-2 -22 6 -44 20 -60c14 -16 34 -26 54 -28c20 -2 32 12 46 14z"'
                 . ' transform="translate(100 100)"/></svg>';

        default:
            return '';
    }
};
