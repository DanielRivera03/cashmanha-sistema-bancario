<?php
/**
 * Detalle de un artículo.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $articulo
 * @var array $relacionados
 * @var array $comercios
 */
use CashMan\Http\Plantilla as P;

require __DIR__ . '/_piezas.php';

$a = $articulo;
$oferta = (int) $a['antes_cent'] > (int) $a['precio_cent'];
$pct = $oferta ? (int) round((1 - (int) $a['precio_cent'] / (int) $a['antes_cent']) * 100) : 0;
$src = $tdaImagen($a);
$hay = (int) $a['existencias'] > 0;
?>

<nav class="tda-migas" aria-label="Ruta">
  <a href="<?= P::attr($rutas->url('tienda')) ?>">Tienda</a>
  <span>›</span>
  <a href="<?= P::attr($rutas->url('tienda') . '?c=' . $a['comercio_codigo']) ?>">
    <?= P::e($a['comercio']) ?>
  </a>
  <span>›</span>
  <strong><?= P::e($a['nombre']) ?></strong>
</nav>

<div class="tda-detalle">
  <div class="tda-detalle__foto">
    <?php if ($src !== ''): ?>
    <img src="<?= P::attr($src) ?>" alt="<?= P::attr($a['imagen_alt'] ?? $a['nombre']) ?>"
         width="400" height="300">
    <?php else: ?>
    <span class="tda-card__sinfoto" style="background:<?= P::attr($a['color']) ?>">
      <?= P::e($a['iniciales']) ?>
    </span>
    <?php endif; ?>

    <?php if ($oferta): ?>
    <span class="tda-card__oferta">-<?= $pct ?>%</span>
    <?php endif; ?>
  </div>

  <?php if (!empty($a['imagen_credito'])): ?>
  <!-- La licencia de la foto pide atribuir al autor; en el detalle
       cabe tambien el enlace a su origen, que es lo que permite
       comprobarla. -->
  <p class="tda-detalle__credito">
    Foto:
    <?php if (!empty($a['imagen_origen'])): ?>
      <a href="<?= P::attr($a['imagen_origen']) ?>" target="_blank" rel="noopener nofollow">
        <?= P::e($a['imagen_credito']) ?>
      </a>
    <?php else: ?>
      <?= P::e($a['imagen_credito']) ?>
    <?php endif; ?>
  </p>
  <?php endif; ?>

  <div class="tda-detalle__datos">
    <a class="tda-detalle__tienda"
       href="<?= P::attr($rutas->url('tienda') . '?c=' . $a['comercio_codigo']) ?>">
      <span class="tda-tienda__logo" style="background:<?= P::attr($a['color']) ?>">
        <?= P::e($a['iniciales']) ?>
      </span>
      <span>
        <strong><?= P::e($a['comercio']) ?></strong>
        <small><?= P::e($a['rubro']) ?></small>
      </span>
    </a>

    <h1><?= P::e($a['nombre']) ?></h1>

    <?php if (!empty($a['marca'])): ?>
    <p class="tda-detalle__marca">Marca: <strong><?= P::e($a['marca']) ?></strong></p>
    <?php endif; ?>

    <div class="tda-detalle__precio">
      <b><?= P::e(P::money((int) $a['precio_cent'])) ?></b>
      <?php if ($oferta): ?>
      <s><?= P::e(P::money((int) $a['antes_cent'])) ?></s>
      <span class="tda-detalle__ahorro">
        ahorra <?= P::e(P::money((int) $a['antes_cent'] - (int) $a['precio_cent'])) ?>
      </span>
      <?php endif; ?>
    </div>

    <?php if ((int) $a['cuotas_sin_intereses'] > 0): ?>
    <p class="tda-detalle__msi">
      o hasta <strong><?= (int) $a['cuotas_sin_intereses'] ?> cuotas</strong> de
      <strong><?= P::e(P::money((int) round((int) $a['precio_cent'] / (int) $a['cuotas_sin_intereses']))) ?></strong>
      sin intereses, pagando con su tarjeta CashMan H.A.
    </p>
    <?php endif; ?>

    <p class="tda-detalle__desc"><?= P::e($a['descripcion'] ?? '') ?></p>

    <ul class="tda-detalle__datos-lista">
      <li>
        <span>Disponibilidad</span>
        <strong class="<?= $hay ? '' : 'tda-agotado' ?>">
          <?= $hay ? (int) $a['existencias'] . ' en existencia' : 'Agotado' ?>
        </strong>
      </li>
      <li>
        <span>Envío</span>
        <strong>
          <?= (int) $a['envio_cent'] > 0
                ? P::e(P::money((int) $a['envio_cent']))
                : 'Gratis' ?>
        </strong>
      </li>
      <li>
        <span>Código</span>
        <strong class="tda-mono"><?= P::e($a['sku']) ?></strong>
      </li>
    </ul>

    <?php if ($hay): ?>
    <form class="tda-detalle__comprar" method="post"
          action="<?= P::attr($rutas->url('tienda/agregar')) ?>">
      <input type="hidden" name="_csrf" value="<?= P::attr(\CashMan\Infraestructura\Seguridad\Csrf::token('tienda_publica')) ?>">
      <input type="hidden" name="_csrf_proposito" value="tienda_publica">
      <input type="hidden" name="articulo" value="<?= P::attr($a['codigo']) ?>">

      <label class="tda-etiqueta" for="cantidad">Cantidad</label>
      <input type="number" id="cantidad" name="cantidad" value="1" min="1"
             max="<?= min(20, (int) $a['existencias']) ?>">

      <button class="sitio-btn sitio-btn--primario" type="submit">
        Agregar al carrito
      </button>
    </form>
    <?php else: ?>
    <p class="tda-agotado-aviso">
      Este artículo está agotado por el momento. Con gusto le mostramos
      <a href="<?= P::attr($rutas->url('tienda') . '?c=' . $a['comercio_codigo']) ?>">
        el resto del catálogo de <?= P::e($a['comercio']) ?></a>.
    </p>
    <?php endif; ?>
  </div>
</div>

<?php if ($relacionados !== []): ?>
<section class="tda-relacionados">
  <h2>También de <?= P::e($a['comercio']) ?></h2>
  <div class="tda-grid">
    <?php foreach ($relacionados as $r) { $tdaArticulo($r); } ?>
  </div>
</section>
<?php endif; ?>

<?php $tdaCarritoFlotante($carritoUnidades ?? 0); ?>
