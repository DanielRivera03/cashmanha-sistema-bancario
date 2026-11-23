<?php
/**
 * Piezas que comparten las pantallas de la tienda pública.
 *
 * Se incluye desde cada vista en lugar de repetir el mismo marcado
 * cuatro veces: la tarjeta de un artículo aparece en el catálogo, en el
 * detalle como «relacionado» y en el carrito, y las tres tienen que
 * verse igual.
 *
 * @var \CashMan\Http\Enrutador $rutas
 */
use CashMan\Http\Plantilla as P;

/**
 * La imagen de un artículo.
 *
 * Si tiene foto subida se sirve por la ruta de imágenes —los archivos
 * subidos viven fuera del directorio público, de modo que nada de lo que
 * suba alguien pueda ejecutarse—. Si lo que tiene es una ilustración de
 * las que vienen con el catálogo, se sirve directo desde `assets`.
 */
$tdaImagen = static function (array $a) use ($rutas): string {
    $img = (string) ($a['imagen'] ?? '');

    if ($img === '') {
        return '';
    }

    return str_starts_with($img, 'assets/')
        ? $rutas->url($img)
        : $rutas->url('tienda/imagen/' . rawurlencode($img));
};

/** La tarjeta de un artículo en una cuadrícula. */
$tdaArticulo = static function (array $a) use ($rutas, $tdaImagen): void {
    $oferta = (int) ($a['antes_cent'] ?? 0) > (int) $a['precio_cent'];
    $pct = $oferta
        ? (int) round((1 - (int) $a['precio_cent'] / (int) $a['antes_cent']) * 100)
        : 0;
    $url = $rutas->url('tienda/articulo/' . $a['codigo']);
    $src = $tdaImagen($a);
    $agotado = isset($a['existencias']) && (int) $a['existencias'] <= 0;
    ?>
    <article class="tda-card<?= $agotado ? ' tda-card--agotado' : '' ?>">
      <a class="tda-card__foto" href="<?= P::attr($url) ?>">
        <?php if ($src !== ''): ?>
        <img src="<?= P::attr($src) ?>" alt="<?= P::attr($a['imagen_alt'] ?? $a['nombre']) ?>"
             loading="lazy" width="400" height="300">
        <?php else: ?>
        <span class="tda-card__sinfoto" style="background:<?= P::attr($a['color'] ?? '#6418c3') ?>">
          <?= P::e($a['iniciales'] ?? 'CM') ?>
        </span>
        <?php endif; ?>

        <?php if ($oferta): ?>
        <span class="tda-card__oferta">-<?= $pct ?>%</span>
        <?php endif; ?>

        <?php if ($agotado): ?>
        <span class="tda-card__agotado">Agotado</span>
        <?php endif; ?>
      </a>

      <div class="tda-card__cuerpo">
        <span class="tda-card__tienda">
          <i style="background:<?= P::attr($a['color'] ?? '#6418c3') ?>"></i>
          <?= P::e($a['comercio'] ?? '') ?>
        </span>

        <h3 class="tda-card__nombre">
          <a href="<?= P::attr($url) ?>"><?= P::e($a['nombre']) ?></a>
        </h3>

        <div class="tda-card__precio">
          <b><?= P::e(P::money((int) $a['precio_cent'])) ?></b>
          <?php if ($oferta): ?><s><?= P::e(P::money((int) $a['antes_cent'])) ?></s><?php endif; ?>
        </div>

        <?php if ((int) ($a['cuotas_sin_intereses'] ?? 0) > 0): ?>
        <small class="tda-card__msi">
          <?= (int) $a['cuotas_sin_intereses'] ?> cuotas de
          <?= P::e(P::money((int) round((int) $a['precio_cent'] / (int) $a['cuotas_sin_intereses']))) ?>
          sin intereses
        </small>
        <?php endif; ?>

        <?php if (!empty($a['imagen_credito'])): ?>
        <!-- Casi todas las licencias libres piden atribuir al autor, y
             cumplirlo no es un adorno: es la condición bajo la que se
             puede usar la foto. -->
        <small class="tda-card__credito">Foto: <?= P::e($a['imagen_credito']) ?></small>
        <?php endif; ?>
      </div>
    </article>
    <?php
};

/**
 * La tira de comercios, que sirve de filtro.
 *
 * Se desplaza en horizontal en lugar de repartir nueve comercios a lo
 * ancho: apretados en una fila, los nombres largos se partían en tres
 * renglones —«Casa Lempa / Electrodomésticos»— y la tira quedaba
 * desigual, con unas fichas del doble de alto que otras.
 */
$tdaComercios = static function (array $comercios, string $sel) use ($rutas): void {
    ?>
    <!-- Carrusel, no barra de desplazamiento.
         Una barra debajo de las fichas se lee como un desperfecto; dos
         flechas dicen por sí solas que hay más a los lados. Las flechas
         se apagan al llegar a cada extremo, de modo que se ve cuándo se
         acabó el recorrido. -->
    <div class="tda-carrusel" data-tda-carrusel>
      <button type="button" class="tda-carrusel__flecha tda-carrusel__flecha--izq"
              data-tda-ir="-1" aria-label="Ver comercios anteriores" hidden>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M15 18l-6-6 6-6"/>
        </svg>
      </button>

      <div class="tda-tiendas" role="list" data-tda-pista>
        <a class="tda-tienda <?= $sel === '' ? 'tda-tienda--activa' : '' ?>" role="listitem"
           href="<?= P::attr($rutas->url('tienda')) ?>">
          <span class="tda-tienda__logo" style="background:#475467">TD</span>
          <span class="tda-tienda__nombre">Todos</span>
          <span class="tda-tienda__rubro">el catálogo completo</span>
        </a>
        <?php foreach ($comercios as $c): ?>
        <a class="tda-tienda <?= $sel === $c['codigo'] ? 'tda-tienda--activa' : '' ?>" role="listitem"
           href="<?= P::attr($rutas->url('tienda') . '?c=' . $c['codigo']) ?>"
           title="<?= P::attr($c['descripcion'] ?? '') ?>">
          <span class="tda-tienda__logo" style="background:<?= P::attr($c['color']) ?>">
            <?= P::e($c['iniciales']) ?>
          </span>
          <span class="tda-tienda__nombre"><?= P::e($c['nombre']) ?></span>
          <span class="tda-tienda__rubro"><?= P::e($c['rubro']) ?></span>
          <?php if ((int) $c['cuotas_sin_intereses'] > 0): ?>
          <span class="tda-tienda__msi"><?= (int) $c['cuotas_sin_intereses'] ?> cuotas s/i</span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>

      <button type="button" class="tda-carrusel__flecha tda-carrusel__flecha--der"
              data-tda-ir="1" aria-label="Ver más comercios" hidden>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M9 18l6-6-6-6"/>
        </svg>
      </button>
    </div>
    <?php
};

/**
 * El carrito, siempre a la vista.
 *
 * Flota sobre el contenido con el número de artículos. Antes solo
 * aparecía un botón arriba del todo y únicamente si ya había algo
 * dentro: quien agregaba un artículo y seguía bajando por el catálogo
 * perdía de vista el carrito y no tenía forma de llegar a pagar sin
 * volver al principio de la página.
 */
$tdaCarritoFlotante = static function (int $unidades) use ($rutas): void {
    ?>
    <a class="tda-flotante <?= $unidades > 0 ? 'tda-flotante--lleno' : '' ?>"
       href="<?= P::attr($rutas->url('tienda/carrito')) ?>"
       aria-label="Ver mi carrito<?= $unidades > 0 ? ', ' . $unidades . ' artículo(s)' : ' (vacío)' ?>">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
        <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
      </svg>
      <span class="tda-flotante__texto">Mi carrito</span>
      <?php if ($unidades > 0): ?>
      <span class="tda-flotante__n"><?= $unidades ?></span>
      <?php endif; ?>
    </a>
    <?php
};
