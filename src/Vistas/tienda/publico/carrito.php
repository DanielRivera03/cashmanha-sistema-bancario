<?php
/**
 * El carrito.
 *
 * Agrupado por tienda, porque de un carrito salen varios pedidos: uno
 * por comercio, cada uno con su envío y su plazo de cuotas. Enseñarlo
 * todo junto en una lista plana escondía justo lo que el cliente
 * necesita saber antes de pagar: cuánto envío va a pagar y en cuántos
 * meses puede diferir cada cosa.
 *
 * Las cantidades se cambian con los botones de más y menos: en un
 * teléfono, seleccionar un número dentro de un campo y teclear otro es
 * más trabajo que pulsar dos veces. El campo sigue ahí y se puede
 * escribir, para quien prefiera eso o navegue con teclado.
 *
 * Los totales se recalculan mientras se pulsa y el carrito se guarda
 * solo. El número que manda sigue siendo el del servidor: se vuelve a
 * sumar allí al pagar, y si algo cambió de precio o se agotó, lo dice.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $carrito
 * @var array  $comercios
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;
use CashMan\Infraestructura\Seguridad\Hashid;

require __DIR__ . '/_piezas.php';

$grupos = $carrito['grupos'] ?? [];
?>

<h1 class="tda-titulo">Su carrito</h1>

<?php foreach ($carrito['avisos'] as $av): ?>
<div class="tda-aviso"><?= P::e($av) ?></div>
<?php endforeach; ?>

<?php if ($carrito['lineas'] === []): ?>
<div class="tda-vacio">
  <svg viewBox="0 0 24 24" width="54" height="54" fill="none" stroke="currentColor"
       stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <circle cx="9" cy="20" r="1.6"/><circle cx="18" cy="20" r="1.6"/>
    <path d="M2 3h2.5l2.3 12.1a2 2 0 0 0 2 1.6h8.6a2 2 0 0 0 2-1.6L21 7H6"/>
  </svg>
  <p>Su carrito está vacío.</p>
  <a class="sitio-btn sitio-btn--primario" href="<?= P::attr($rutas->url('tienda')) ?>">
    Ir a la tienda
  </a>
</div>
<?php else: ?>

<form id="form-carrito" method="post" action="<?= P::attr($rutas->url('tienda/carrito')) ?>">
  <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
  <input type="hidden" name="_csrf_proposito" value="tienda_publica">

  <div class="tda-carrito">
    <div class="tda-carrito__lineas">

      <?php foreach ($grupos as $g): ?>
      <section class="tda-grupo" data-comercio="<?= (int) $g['comercio_id'] ?>">

        <header class="tda-grupo__cab">
          <span class="tda-tienda__logo" style="background:<?= P::attr($g['color']) ?>">
            <?= P::e($g['iniciales']) ?>
          </span>
          <div class="tda-grupo__quien">
            <strong><?= P::e($g['nombre']) ?></strong>
            <small>
              <?= (int) $g['envio_cent'] > 0
                    ? 'Envío ' . P::e(P::money((int) $g['envio_cent']))
                    : 'Envío gratis' ?>
              <?php if ((int) $g['cuotas'] > 0): ?>
                · hasta <?= (int) $g['cuotas'] ?> cuotas sin intereses
              <?php else: ?>
                · sin cuotas
              <?php endif; ?>
            </small>
          </div>
          <span class="tda-grupo__subtotal" data-grupo-subtotal>
            <?= P::e(P::money((int) $g['subtotal_cent'])) ?>
          </span>
        </header>

        <?php foreach ($g['lineas'] as $l):
          $src = $tdaImagen($l);
          $cod = Hashid::codificar($l['id'], 'articulo');
        ?>
        <div class="tda-linea" data-precio="<?= (int) $l['precio_cent'] ?>">
          <?php if ($src !== ''): ?>
          <img class="tda-linea__foto" src="<?= P::attr($src) ?>"
               alt="<?= P::attr($l['imagen_alt'] ?? $l['nombre']) ?>" width="90" height="68">
          <?php endif; ?>

          <div class="tda-linea__datos">
            <strong><?= P::e($l['nombre']) ?></strong>
            <span class="tda-linea__unit"><?= P::e(P::money((int) $l['precio_cent'])) ?> c/u</span>
          </div>

          <?php /* El par de botones y el número. El campo conserva su
                   `name` de siempre, así que si el guion no cargara, el
                   formulario sigue funcionando con «Guardar cambios». */ ?>
          <div class="tda-paso" role="group"
               aria-label="Cantidad de <?= P::attr($l['nombre']) ?>">
            <button type="button" class="tda-paso__btn" data-paso="-1"
                    aria-label="Quitar uno">&minus;</button>

            <input type="number" class="tda-paso__num"
                   id="c-<?= P::attr($l['sku']) ?>"
                   name="cant[<?= P::attr($cod) ?>]"
                   value="<?= (int) $l['cantidad'] ?>" min="1" max="20"
                   inputmode="numeric"
                   aria-label="Cantidad de <?= P::attr($l['nombre']) ?>">

            <button type="button" class="tda-paso__btn" data-paso="1"
                    aria-label="Agregar uno">+</button>
          </div>

          <div class="tda-linea__importe" data-linea-importe>
            <?= P::e(P::money((int) $l['importe_cent'])) ?>
          </div>

          <?php /* Un clic y fuera. `formnovalidate` para que un número
                   mal escrito en otra línea no impida quitar ésta. */ ?>
          <button class="tda-linea__quitar" type="submit" name="quitar"
                  value="<?= P::attr($cod) ?>" formnovalidate
                  title="Quitar del carrito"
                  aria-label="Quitar <?= P::attr($l['nombre']) ?> del carrito">
            <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true" focusable="false">
              <path d="M6 7h12M10 7V5.5A1.5 1.5 0 0 1 11.5 4h1A1.5 1.5 0 0 1 14 5.5V7
                       M7.5 7l.7 11.1A1.5 1.5 0 0 0 9.7 19.5h4.6a1.5 1.5 0 0 0 1.5-1.4L16.5 7
                       M10.5 10.5v6M13.5 10.5v6"
                    fill="none" stroke="currentColor" stroke-width="1.7"
                    stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span>Quitar</span>
          </button>
        </div>
        <?php endforeach; ?>

      </section>
      <?php endforeach; ?>
    </div>

    <aside class="tda-resumen">
      <h2>Resumen</h2>

      <?php if (count($grupos) > 1): ?>
      <p class="tda-resumen__nota">
        Son <strong><?= count($grupos) ?> tiendas</strong>, así que recibirá
        <?= count($grupos) ?> pedidos. Se paga una sola vez.
      </p>
      <?php endif; ?>

      <dl class="tda-resumen__totales">
        <div>
          <dt>Subtotal</dt>
          <dd data-total-subtotal><?= P::e(P::money((int) $carrito['subtotal_cent'])) ?></dd>
        </div>
        <div>
          <dt>Envío<?= count($grupos) > 1 ? ' · ' . count($grupos) . ' tiendas' : '' ?></dt>
          <dd><?= (int) $carrito['envio_cent'] > 0
                    ? P::e(P::money((int) $carrito['envio_cent']))
                    : 'Gratis' ?></dd>
        </div>
        <div class="tda-resumen__total">
          <dt>Total</dt>
          <dd data-total-general><?= P::e(P::money((int) $carrito['total_cent'])) ?></dd>
        </div>
      </dl>

      <?php if ((int) $carrito['cuotas_disponibles'] > 0): ?>
      <div class="tda-resumen__msi">
        <strong>Hasta <?= (int) $carrito['cuotas_disponibles'] ?> cuotas sin intereses</strong>
        con su tarjeta CashMan H.A.

        <?php if (!empty($carrito['cuotas_dispares'])): ?>
        <?php /* Los plazos difieren entre tiendas: se dice aquí y no al
                 pagar. Prometer el mayor para todas sería prometer por
                 cuenta de quien no lo ofreció. */ ?>
        <small>
          Cada tienda aplica el suyo: pida el plazo que quiera y la que no
          llegue hasta ahí le dará el máximo que ofrece. Lo verá detallado
          antes de confirmar.
        </small>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <button class="sitio-btn sitio-btn--claro" type="submit" formnovalidate
              id="btn-guardar">
        Guardar cambios
      </button>

      <?php /* Ya no se esconde cuando hay varias tiendas: la compra
               múltiple funciona y sale un pedido por comercio. */ ?>
      <a class="sitio-btn sitio-btn--primario tda-resumen__pagar"
         href="<?= P::attr($rutas->url('tienda/pagar')) ?>">
        Finalizar compra
      </a>

      <a class="tda-resumen__seguir" href="<?= P::attr($rutas->url('tienda')) ?>">
        Seguir viendo artículos
      </a>
    </aside>
  </div>
</form>
<?php endif; ?>

<?php $tdaCarritoFlotante($carritoUnidades ?? 0); ?>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var form = document.getElementById('form-carrito');
  if (!form) { return; }

  var envioTotal = <?= (int) ($carrito['envio_cent'] ?? 0) ?>;
  var espera;

  function money(cent) {
    return '$' + (cent / 100).toLocaleString('en-US', {
      minimumFractionDigits: 2, maximumFractionDigits: 2
    });
  }

  /* Recalcula lo que se ve.
     El total que vale sigue siendo el del servidor —se vuelve a sumar
     al pagar—, pero esperar una recarga para saber cuánto suma un
     artículo más hace que el carrito se sienta muerto. */
  function recalcular() {
    var subtotal = 0;

    form.querySelectorAll('.tda-grupo').forEach(function (g) {
      var sub = 0;

      g.querySelectorAll('.tda-linea').forEach(function (l) {
        var precio = parseInt(l.getAttribute('data-precio'), 10) || 0;
        var campo  = l.querySelector('.tda-paso__num');
        var cant   = Math.max(1, Math.min(20, parseInt(campo.value, 10) || 1));
        var importe = precio * cant;

        sub += importe;
        l.querySelector('[data-linea-importe]').textContent = money(importe);
      });

      subtotal += sub;
      g.querySelector('[data-grupo-subtotal]').textContent = money(sub);
    });

    form.querySelector('[data-total-subtotal]').textContent = money(subtotal);
    form.querySelector('[data-total-general]').textContent  = money(subtotal + envioTotal);
  }

  /* Se guarda solo, poco después de dejar de pulsar.
     Sin esto hay que acordarse de «Guardar cambios», y quien no se
     acuerda paga la cantidad vieja. El botón se queda para quien
     navega con teclado y por si el guion fallara. */
  function guardarLuego() {
    clearTimeout(espera);
    espera = setTimeout(function () { form.submit(); }, 700);
  }

  function ajustarTopes(linea) {
    var campo = linea.querySelector('.tda-paso__num');
    var v = parseInt(campo.value, 10) || 1;

    /* En el mínimo, el menos deja de tener sentido: para quitarlo del
       todo está el botón de quitar, que además lo dice con palabras. */
    linea.querySelector('[data-paso="-1"]').disabled = v <= 1;
    linea.querySelector('[data-paso="1"]').disabled  = v >= 20;
  }

  form.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-paso]');
    if (!b) { return; }

    var linea = b.closest('.tda-linea');
    var campo = linea.querySelector('.tda-paso__num');
    var v = (parseInt(campo.value, 10) || 1) + parseInt(b.getAttribute('data-paso'), 10);

    campo.value = Math.max(1, Math.min(20, v));

    ajustarTopes(linea);
    recalcular();
    guardarLuego();
  });

  form.addEventListener('input', function (ev) {
    if (!ev.target.classList.contains('tda-paso__num')) { return; }

    ajustarTopes(ev.target.closest('.tda-linea'));
    recalcular();
    guardarLuego();
  });

  // El estado inicial, sin esperar al primer clic.
  form.querySelectorAll('.tda-linea').forEach(ajustarTopes);
})();
</script>
