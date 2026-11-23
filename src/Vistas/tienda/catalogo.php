<?php
/**
 * La tienda: comercios afiliados y su catálogo.
 *
 * El carrito vive en la propia página —no hace falta guardarlo en el
 * servidor para una compra de un solo comercio— y se envía entero al
 * confirmar. Así no hay estado a medias entre pantallas: o se compra o
 * no se compra.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $articulos
 * @var array  $comercios
 * @var array  $tarjetas
 * @var string $busca
 * @var string $comercioSel
 * @var bool   $puedeComprar
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;
?>

<!-- ==================== LOS COMERCIOS ==================== -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0">Comercios afiliados</h4>
    <a class="btn btn-sm btn-outline-secondary" href="<?= P::attr($rutas->url('tienda/pedidos')) ?>">
      Mis pedidos
    </a>
  </div>
  <div class="card-body">
    <div class="cm-comercios">
      <a class="cm-comercio <?= $comercioSel === '' ? 'cm-comercio--activo' : '' ?>"
         href="<?= P::attr($rutas->url('tienda')) ?>">
        <span class="cm-comercio__logo" style="background:#475467">TD</span>
        <span class="cm-comercio__nombre">Todos</span>
        <span class="cm-comercio__rubro"><?= count($articulos) ?> artículos</span>
      </a>

      <?php foreach ($comercios as $c): ?>
      <a class="cm-comercio <?= $comercioSel === $c['codigo'] ? 'cm-comercio--activo' : '' ?>"
         href="<?= P::attr($rutas->url('tienda') . '?c=' . $c['codigo']) ?>"
         title="<?= P::attr($c['descripcion'] ?? '') ?>">
        <!-- El logo es un monograma dibujado con CSS: así el catálogo no
             depende de imágenes que alguien tenga que subir, ni usa
             marcas que no son nuestras. -->
        <span class="cm-comercio__logo" style="background:<?= P::attr($c['color']) ?>">
          <?= P::e($c['iniciales']) ?>
        </span>
        <span class="cm-comercio__nombre"><?= P::e($c['nombre']) ?></span>
        <span class="cm-comercio__rubro"><?= P::e($c['rubro']) ?></span>
        <?php if ((int) $c['cuotas_sin_intereses'] > 0): ?>
        <span class="cm-comercio__msi">
          hasta <?= (int) $c['cuotas_sin_intereses'] ?> cuotas sin intereses
        </span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ==================== BUSCADOR ==================== -->
<div class="card">
  <div class="card-body py-3">
    <form method="get" action="<?= P::attr($rutas->url('tienda')) ?>" class="form-row align-items-end">
      <?php if ($comercioSel !== ''): ?>
      <input type="hidden" name="c" value="<?= P::attr($comercioSel) ?>">
      <?php endif; ?>
      <div class="form-group col-md-9 mb-0">
        <label style="font-size:.8rem">¿Qué está buscando?</label>
        <input type="text" class="form-control" name="q" value="<?= P::attr($busca) ?>"
               placeholder="Nombre del artículo, marca o descripción">
      </div>
      <div class="form-group col-md-3 mb-0">
        <button class="btn btn-primary btn-block">Buscar</button>
      </div>
    </form>
  </div>
</div>

<?php if (!$puedeComprar): ?>
<div class="alert alert-light border">
  Esta es la vitrina de los comercios afiliados. Para comprar hace falta una
  tarjeta de crédito emitida por CashMan H.A.
</div>
<?php elseif ($tarjetas === []): ?>
<div class="alert alert-warning">
  <strong>Todavía no tiene una tarjeta de crédito activa.</strong>
  Con mucho gusto le mostramos
  <a href="<?= P::attr($rutas->url('tarjetas/catalogo')) ?>">nuestro catálogo de tarjetas</a>;
  la solicitud se resuelve en pocos pasos.
</div>
<?php endif; ?>

<!-- ==================== EL CATÁLOGO ==================== -->
<?php if ($articulos === []): ?>
<div class="card">
  <div class="card-body">
    <div class="cm-vacio">
      <p class="mb-0">No encontramos artículos con esa búsqueda. Pruebe con otra palabra.</p>
    </div>
  </div>
</div>
<?php else: ?>

<form id="form-compra" method="post" data-cm-ajax
      action="<?= P::attr($rutas->url('tienda/comprar')) ?>">
  <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
  <input type="hidden" name="_csrf_proposito" value="tienda">

  <div class="cm-articulos">
    <?php foreach ($articulos as $a):
      $oferta = (int) $a['antes_cent'] > (int) $a['precio_cent'];
      $pct = $oferta
        ? (int) round((1 - (int) $a['precio_cent'] / (int) $a['antes_cent']) * 100)
        : 0;
    ?>
    <article class="cm-articulo" data-comercio="<?= P::attr($a['comercio_codigo']) ?>">
      <div class="cm-articulo__cab">
        <span class="cm-articulo__logo" style="background:<?= P::attr($a['color']) ?>">
          <?= P::e($a['iniciales']) ?>
        </span>
        <div class="cm-articulo__tienda">
          <span><?= P::e($a['comercio']) ?></span>
          <small><?= P::e($a['marca'] ?? '') ?></small>
        </div>
        <?php if ($oferta): ?>
        <span class="cm-articulo__oferta">-<?= $pct ?>%</span>
        <?php endif; ?>
      </div>

      <h5 class="cm-articulo__nombre"><?= P::e($a['nombre']) ?></h5>
      <p class="cm-articulo__desc"><?= P::e($a['descripcion'] ?? '') ?></p>

      <div class="cm-articulo__precio">
        <b><?= P::e(P::money((int) $a['precio_cent'])) ?></b>
        <?php if ($oferta): ?>
        <s><?= P::e(P::money((int) $a['antes_cent'])) ?></s>
        <?php endif; ?>
      </div>

      <?php if ((int) $a['cuotas_sin_intereses'] > 0): ?>
      <small class="cm-articulo__msi">
        o <?= (int) $a['cuotas_sin_intereses'] ?> cuotas de
        <?= P::e(P::money((int) round((int) $a['precio_cent'] / (int) $a['cuotas_sin_intereses']))) ?>
        sin intereses
      </small>
      <?php endif; ?>

      <div class="cm-articulo__pie">
        <small class="text-muted">
          <?= (int) $a['existencias'] > 0
                ? (int) $a['existencias'] . ' disponibles'
                : 'agotado' ?>
        </small>

        <?php if ($puedeComprar && (int) $a['existencias'] > 0): ?>
        <div class="cm-cant">
          <button type="button" class="cm-cant__btn" data-cm-menos
                  aria-label="Quitar uno">−</button>
          <input type="number" class="cm-cant__campo"
                 name="cant[<?= P::attr($a['codigo']) ?>]"
                 value="0" min="0" max="<?= min(20, (int) $a['existencias']) ?>"
                 data-precio="<?= (int) $a['precio_cent'] ?>"
                 data-nombre="<?= P::attr($a['nombre']) ?>"
                 data-tienda="<?= P::attr($a['comercio']) ?>"
                 data-envio="<?= (int) $a['envio_cent'] ?>"
                 data-msi="<?= (int) $a['cuotas_sin_intereses'] ?>"
                 aria-label="Cantidad de <?= P::attr($a['nombre']) ?>">
          <button type="button" class="cm-cant__btn" data-cm-mas
                  aria-label="Agregar uno">+</button>
        </div>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>

  <!-- ==================== EL CARRITO ==================== -->
  <?php if ($puedeComprar && $tarjetas !== []): ?>
  <div class="cm-carrito" id="carrito" hidden>
    <div class="cm-carrito__cuerpo">
      <div class="cm-carrito__lista" id="carrito-lista"></div>

      <div class="cm-carrito__totales">
        <div><span>Subtotal</span><b id="car-subtotal">$0.00</b></div>
        <div><span>Envío</span><b id="car-envio">$0.00</b></div>
        <div class="cm-carrito__total"><span>Total</span><b id="car-total">$0.00</b></div>
      </div>

      <div class="form-group mb-2">
        <label style="font-size:.78rem">Pagar con</label>
        <select class="form-control form-control-sm" name="tarjeta" required>
          <?php foreach ($tarjetas as $t): ?>
          <option value="<?= P::attr($t['codigo']) ?>"
                  data-tope="<?= (int) $t['tope_cent'] ?>">
            <?= P::e(strtoupper((string) $t['marca'])) ?> ····<?= P::e($t['ultimos4']) ?>
            · disponible <?= P::e(P::money((int) $t['disponible_cent'])) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group mb-2" id="caja-cuotas" hidden>
        <label style="font-size:.78rem">¿A cuántas cuotas?</label>
        <select class="form-control form-control-sm" name="cuotas" id="sel-cuotas">
          <option value="0">De contado</option>
        </select>
      </div>

      <div class="form-group mb-2">
        <label style="font-size:.78rem">Dirección de entrega</label>
        <input type="text" class="form-control form-control-sm" name="direccion"
               maxlength="400" placeholder="Dónde le llevamos el pedido">
      </div>

      <div id="car-aviso" class="cm-carrito__aviso" hidden></div>

      <button type="submit" class="btn btn-primary btn-block" id="btn-comprar">
        Confirmar compra
      </button>
    </div>
  </div>
  <?php endif; ?>
</form>
<?php endif; ?>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var form = document.getElementById('form-compra');
  var carrito = document.getElementById('carrito');

  if (!form || !carrito) { return; }

  var campos = form.querySelectorAll('.cm-cant__campo');
  var lista = document.getElementById('carrito-lista');
  var elSub = document.getElementById('car-subtotal');
  var elEnv = document.getElementById('car-envio');
  var elTot = document.getElementById('car-total');
  var aviso = document.getElementById('car-aviso');
  var selTar = form.querySelector('[name="tarjeta"]');
  var cajaCuotas = document.getElementById('caja-cuotas');
  var selCuotas = document.getElementById('sel-cuotas');
  var btn = document.getElementById('btn-comprar');

  function recalcular() {
    var sub = 0, envio = 0, msi = 0, tienda = null, mezcla = false;
    var items = [];

    campos.forEach(function (c) {
      var n = parseInt(c.value, 10);
      if (isNaN(n) || n <= 0) { return; }

      var precio = parseInt(c.dataset.precio, 10) || 0;
      sub += precio * n;

      items.push({ nombre: c.dataset.nombre, n: n, importe: precio * n });

      /* Un pedido, un comercio.
         Cada tienda tiene su propio envío y sus propios meses sin
         intereses; mezclarlos obligaría a repartir el envío entre dos y
         a decidir cuál de los dos planes manda. */
      if (tienda === null) {
        tienda = c.dataset.tienda;
        envio = parseInt(c.dataset.envio, 10) || 0;
        msi = parseInt(c.dataset.msi, 10) || 0;
      } else if (tienda !== c.dataset.tienda) {
        mezcla = true;
      }
    });

    if (items.length === 0) {
      carrito.hidden = true;
      return;
    }

    carrito.hidden = false;

    lista.innerHTML = items.map(function (i) {
      return '<div class="cm-carrito__fila">'
        + '<span>' + i.n + ' × ' + CMUtil.escapar(i.nombre) + '</span>'
        + '<b>' + CMUtil.money(i.importe) + '</b>'
        + '</div>';
    }).join('');

    var total = sub + envio;

    elSub.textContent = CMUtil.money(sub);
    elEnv.textContent = CMUtil.money(envio);
    elTot.textContent = CMUtil.money(total);

    // Las cuotas que ofrece la tienda del carrito.
    if (msi > 0 && !mezcla) {
      cajaCuotas.hidden = false;
      var actual = selCuotas.value;
      var html = '<option value="0">De contado</option>';

      [3, 6, 9, 12, 15, 18, 24].forEach(function (k) {
        if (k <= msi) {
          html += '<option value="' + k + '">' + k + ' cuotas de '
            + CMUtil.money(Math.round(total / k)) + ' sin intereses</option>';
        }
      });

      selCuotas.innerHTML = html;
      selCuotas.value = actual && actual <= msi ? actual : '0';
    } else {
      cajaCuotas.hidden = true;
      selCuotas.value = '0';
    }

    // Avisos antes de pulsar, no después.
    var mensaje = '';

    if (mezcla) {
      mensaje = 'Su carrito tiene artículos de más de un comercio. '
        + 'Cada pedido es de una sola tienda: deje solo los de una y '
        + 'haga la otra compra después.';
    } else if (selTar && selTar.selectedOptions.length) {
      var tope = parseInt(selTar.selectedOptions[0].dataset.tope, 10) || 0;

      if (total > tope) {
        mensaje = 'El cupo de esa tarjeta no alcanza para ' + CMUtil.money(total)
          + '. Puede abonar a su tarjeta, elegir otra o quitar algún artículo.';
      }
    }

    aviso.textContent = mensaje;
    aviso.hidden = mensaje === '';
    if (btn) { btn.disabled = mensaje !== ''; }
  }

  campos.forEach(function (c) {
    c.addEventListener('input', recalcular);
    c.addEventListener('change', recalcular);
  });

  if (selTar) { selTar.addEventListener('change', recalcular); }

  // Los botones de más y menos.
  form.addEventListener('click', function (ev) {
    var mas = ev.target.closest('[data-cm-mas]');
    var menos = ev.target.closest('[data-cm-menos]');

    if (!mas && !menos) { return; }

    ev.preventDefault();

    var caja = (mas || menos).parentElement;
    var campo = caja.querySelector('.cm-cant__campo');

    if (!campo) { return; }

    var n = parseInt(campo.value, 10) || 0;
    var max = parseInt(campo.max, 10) || 20;

    campo.value = Math.max(0, Math.min(max, n + (mas ? 1 : -1)));
    recalcular();
  });

  recalcular();
})();
</script>
