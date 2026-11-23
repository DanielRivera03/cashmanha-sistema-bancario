<?php
/**
 * El catálogo de la tienda, de cara al público.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $articulos
 * @var array  $comercios
 * @var string $busca
 * @var string $comercioSel
 * @var string $orden
 * @var int    $carritoUnidades
 */
use CashMan\Http\Plantilla as P;

require __DIR__ . '/_piezas.php';
/* La biblioteca del sitio público, para la cabecera. No choca con
   `_piezas.php`: aquella define las piezas de la tienda —el
   artículo, el comercio, la imagen— y esta los gráficos. */
require __DIR__ . '/../../sitio/_graficos.php';
?>

<?php /* La cabecera de la tienda, con el mismo trato que las del sitio:
         antes era un título negro sobre blanco y se notaba que la
         tienda venía de otra parte. */ ?>
<section class="tda-hero">
  <?= P::crudo($escenario('mercado')) ?>

  <div class="tda-hero__texto">
    <h1>La tienda de CashMan H.A.</h1>
    <p>
      Compre en los comercios afiliados y pague como mejor le convenga: con su
      tarjeta CashMan H.A. —y hasta 24 cuotas sin intereses según el comercio— o
      con la tarjeta de cualquier otro banco. No hace falta tener cuenta con
      nosotros para comprar.
    </p>
  </div>

  <!-- El carrito ya no va aquí: flota en todas las pantallas, así que
       repetirlo arriba sería el mismo botón dos veces en la misma
       pantalla, y uno de los dos desaparecería al bajar. -->
</section>

<?php $tdaComercios($comercios, $comercioSel); ?>

<form class="tda-filtros" method="get" action="<?= P::attr($rutas->url('tienda')) ?>">
  <?php if ($comercioSel !== ''): ?>
  <input type="hidden" name="c" value="<?= P::attr($comercioSel) ?>">
  <?php endif; ?>

  <div class="tda-filtros__buscar">
    <label class="tda-etiqueta" for="q">¿Qué está buscando?</label>
    <input type="search" id="q" name="q" value="<?= P::attr($busca) ?>"
           placeholder="Nombre del artículo, marca o descripción">
  </div>

  <div class="tda-filtros__orden">
    <label class="tda-etiqueta" for="orden">Ordenar por</label>
    <select id="orden" name="orden">
      <option value="" <?= $orden === '' ? 'selected' : '' ?>>Lo que recomendamos</option>
      <option value="barato" <?= $orden === 'barato' ? 'selected' : '' ?>>Precio: de menor a mayor</option>
      <option value="caro" <?= $orden === 'caro' ? 'selected' : '' ?>>Precio: de mayor a menor</option>
      <option value="oferta" <?= $orden === 'oferta' ? 'selected' : '' ?>>Ofertas primero</option>
      <option value="nombre" <?= $orden === 'nombre' ? 'selected' : '' ?>>Nombre</option>
    </select>
  </div>

  <button class="sitio-btn sitio-btn--primario" type="submit">Buscar</button>
</form>

<?php if ($articulos === []): ?>
<div class="tda-vacio">
  <p>No encontramos artículos con esa búsqueda.</p>
  <a class="sitio-btn sitio-btn--primario" href="<?= P::attr($rutas->url('tienda')) ?>">
    Ver todo el catálogo
  </a>
</div>
<?php else: ?>

<p class="tda-conteo">
  <?= count($articulos) ?> artículo<?= count($articulos) === 1 ? '' : 's' ?>
  <?php if ($busca !== ''): ?>para «<?= P::e($busca) ?>»<?php endif; ?>
</p>

<div class="tda-grid">
  <?php foreach ($articulos as $a) { $tdaArticulo($a); } ?>
</div>
<?php endif; ?>

<section class="tda-nota">
  <h2>Cómo funciona</h2>
  <div class="tda-nota__cols">
    <div>
      <h3>Elija y agregue</h3>
      <p>Cada pedido es de un solo comercio, porque cada tienda tiene su propio
         envío y sus propios meses sin intereses.</p>
    </div>
    <div>
      <h3>Pague como quiera</h3>
      <p>Con su tarjeta CashMan H.A. o con la de cualquier otro banco. Aceptamos
         Visa, Mastercard y American Express.</p>
    </div>
    <div>
      <h3>Siga su pedido</h3>
      <p>Le damos un folio y una clave para consultar el estado cuando quiera, y
         le avisamos por correo en cada paso.</p>
    </div>
  </div>
</section>

<?php $tdaCarritoFlotante($carritoUnidades ?? 0); ?>
