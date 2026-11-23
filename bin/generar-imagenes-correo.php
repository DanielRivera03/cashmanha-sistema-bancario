<?php

declare(strict_types=1);

/**
 * Las imágenes que viajan dentro de los correos.
 *
 * Dos problemas que esto resuelve:
 *
 *   · El logotipo original pesa 99 KB y mide 3.500 píxeles de ancho.
 *     En la cabecera del correo se enseña a 34 píxeles de alto. Mandar
 *     99 KB en cada aviso para pintar una imagen de 34 píxeles es
 *     desperdicio, y los correos pesados acaban recortados por Gmail
 *     («mensaje truncado») justo donde está el pie.
 *
 *   · Los iconos. En un correo no se puede usar SVG —Gmail lo quita— ni
 *     una fuente de iconos —no se cargan—, así que o son PNG o no son.
 *     Se dibujan aquí con GD, una vez, en lugar de depender de un
 *     archivo que alguien tenga que mantener.
 *
 * Se guardan en `public/assets/correo/` y el enviador los incrusta con
 * `cid:`, que es lo único que se ve sin pedirle permiso al cliente.
 *
 *   php bin/generar-imagenes-correo.php
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

const DESTINO = __DIR__ . '/../public/assets/correo/';

if (!is_dir(DESTINO)) {
    mkdir(DESTINO, 0775, true);
}

/* La paleta, la misma de la plantilla de correo. */
const MARCA  = [100, 24, 195];
const VERDE  = [2, 122, 72];
const ROJO   = [180, 35, 24];
const AMBAR  = [181, 123, 10];
const AZUL   = [23, 92, 211];

// =====================================================================
// 1. El logotipo, al tamaño que se usa
// =====================================================================
//
// A 380 píxeles de ancho: el doble de los 190 que ocupa como mucho en
// la cabecera, para que no se vea borroso en pantallas de densidad
// doble, y nada más.

function reducirLogo(string $origen, string $destino, int $anchoMax): void
{
    $im = @imagecreatefrompng($origen);

    if (!$im) {
        echo "  !! no pude abrir " . basename($origen) . "\n";
        return;
    }

    $w = imagesx($im);
    $h = imagesy($im);

    $nuevoW = min($anchoMax, $w);
    $nuevoH = (int) round($h * $nuevoW / $w);

    $out = imagecreatetruecolor($nuevoW, $nuevoH);

    // La transparencia se conserva: el logotipo va sobre morado, y un
    // fondo blanco pegado alrededor se vería como un parche.
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagealphablending($out, true);

    imagecopyresampled($out, $im, 0, 0, 0, 0, $nuevoW, $nuevoH, $w, $h);

    imagepng($out, $destino, 9);

    printf("  %-22s %4dx%-4d  %6.1f KB  (antes %6.1f KB)\n",
        basename($destino), $nuevoW, $nuevoH,
        filesize($destino) / 1024, filesize($origen) / 1024);

    imagedestroy($im);
    imagedestroy($out);
}

$imgs = dirname(__DIR__) . '/public/assets/plantilla/images/';

echo "  Logotipo:\n";

/* El logotipo CON el texto. En la cabecera de un correo el emblema
   solo no identifica a nadie, y el nombre de la institución es
   justamente lo que distingue un aviso legítimo de uno falso. En
   reverso, porque la banda es morado oscuro. */
reducirLogo($imgs . 'logo-text-reverso.png', DESTINO . 'logo.png', 540);

/* El emblema suelto, pequeño, para el pie. */
reducirLogo($imgs . 'logo-reverso.png', DESTINO . 'emblema.png', 120);

// =====================================================================
// 2. Los iconos
// =====================================================================
//
// Un círculo de color con un símbolo blanco dentro. Se dibujan a 4x y
// se reducen: GD no suaviza los bordes al dibujar, y un círculo de 96
// píxeles reducido a 24 sí queda limpio.

function icono(string $nombre, array $color, callable $dibujo): void
{
    $lado = 96;   // 4x de los 24 que mide en el correo

    $im = imagecreatetruecolor($lado, $lado);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);

    $fondo  = imagecolorallocate($im, ...$color);
    $blanco = imagecolorallocate($im, 255, 255, 255);

    imagefilledellipse($im, $lado / 2, $lado / 2, $lado - 4, $lado - 4, $fondo);

    imagesetthickness($im, 8);
    $dibujo($im, $blanco, $lado);

    // A su tamaño final.
    $out = imagecreatetruecolor(48, 48);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagealphablending($out, true);
    imagecopyresampled($out, $im, 0, 0, 0, 0, 48, 48, $lado, $lado);

    $ruta = DESTINO . 'icono-' . $nombre . '.png';
    imagepng($out, $ruta, 9);

    printf("  %-22s %6.1f KB\n", basename($ruta), filesize($ruta) / 1024);

    imagedestroy($im);
    imagedestroy($out);
}

echo "\n  Iconos:\n";

/* La paloma del éxito: dos trazos, como se dibuja a mano. */
icono('exito', VERDE, static function ($im, $c, $l): void {
    imageline($im, (int) ($l * .28), (int) ($l * .52), (int) ($l * .44), (int) ($l * .68), $c);
    imageline($im, (int) ($l * .44), (int) ($l * .68), (int) ($l * .73), (int) ($l * .34), $c);
});

/* La admiración del aviso. */
icono('aviso', AMBAR, static function ($im, $c, $l): void {
    imageline($im, (int) ($l * .5), (int) ($l * .27), (int) ($l * .5), (int) ($l * .58), $c);
    imagefilledellipse($im, (int) ($l * .5), (int) ($l * .72), 11, 11, $c);
});

/* La misma figura en rojo para lo que salió mal. */
icono('error', ROJO, static function ($im, $c, $l): void {
    imageline($im, (int) ($l * .34), (int) ($l * .34), (int) ($l * .66), (int) ($l * .66), $c);
    imageline($im, (int) ($l * .66), (int) ($l * .34), (int) ($l * .34), (int) ($l * .66), $c);
});

/* La «i» de la información. */
icono('info', AZUL, static function ($im, $c, $l): void {
    imagefilledellipse($im, (int) ($l * .5), (int) ($l * .3), 11, 11, $c);
    imageline($im, (int) ($l * .5), (int) ($l * .44), (int) ($l * .5), (int) ($l * .72), $c);
});

/* El dinero: una tarjeta, que es por lo que llega la mayoría de estos
   correos. */
icono('movimiento', MARCA, static function ($im, $c, $l): void {
    imagesetthickness($im, 6);
    imagerectangle($im, (int) ($l * .26), (int) ($l * .34), (int) ($l * .74), (int) ($l * .66), $c);
    imagefilledrectangle($im, (int) ($l * .26), (int) ($l * .42), (int) ($l * .74), (int) ($l * .48), $c);
});

echo "\n  Listo. El enviador las incrusta con «cid:» y se ven sin que el\n";
echo "  cliente tenga que permitir imágenes remotas.\n";
