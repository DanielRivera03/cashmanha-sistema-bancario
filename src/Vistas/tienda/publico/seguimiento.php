<?php
/**
 * La puerta de entrada al seguimiento de un pedido.
 *
 * Hasta aquí, para ver un pedido había que tener el enlace: el que
 * salió en pantalla al comprar, o el del correo. Quien cerró la
 * pestaña y borró el correo se quedaba sin manera de llegar, aunque
 * tuviera su folio y su clave apuntados en un papel. Esta pantalla es
 * esa manera.
 *
 * No enseña nada por sí sola: pide el folio y la clave y lleva a la
 * pantalla del pedido, que es la que comprueba de verdad. Aquí no se
 * consulta la base ni se dice si un folio existe —decirlo sería
 * regalarle medio dato a quien está probando folios al azar—.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var string $folio    Lo que venga en la URL, para dejarlo escrito.
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

require __DIR__ . "/_piezas.php";
?>

<div class="tda-clave">
  <h1>Seguimiento de su pedido</h1>

  <p>
    Escriba el folio de su pedido y la clave de seguimiento que le dimos al
    comprar. Las dos vienen en el correo de confirmación.
  </p>

  <form method="get" id="form-seguimiento" class="tda-seguimiento">
    <div class="tda-seguimiento__campos">
      <div>
        <label class="tda-etiqueta" for="folio">Folio del pedido</label>
        <input type="text" id="folio" name="folio" required maxlength="24"
               class="tda-mono" autocomplete="off" placeholder="PED-000000-00000000"
               value="<?= P::attr($folio ?? '') ?>">
      </div>

      <div>
        <label class="tda-etiqueta" for="clave">Clave de seguimiento</label>
        <input type="text" id="clave" name="clave" required maxlength="12"
               class="tda-mono" autocomplete="off" placeholder="Doce caracteres">
      </div>
    </div>

    <button class="sitio-btn sitio-btn--primario" type="submit">Ver mi pedido</button>
  </form>

  <div class="tda-clave__ayuda">
    <h2>¿No encuentra su clave?</h2>
    <ul>
      <li>
        Busque en su correo el mensaje <em>«Compra confirmada»</em>. La clave va
        junto al folio, y el mensaje trae un botón que abre el pedido directo.
      </li>
      <li>
        Si compró con su cuenta de CashMan H.A., no necesita clave:
        <a href="<?= P::attr($rutas->url('entrar')) ?>">inicie sesión</a> y verá
        todos sus pedidos.
      </li>
      <li>
        Si compró en varias tiendas a la vez, recibió <strong>un pedido por
        tienda</strong>, cada uno con su folio y su clave. Desde cualquiera de
        ellos se ven los demás.
      </li>
    </ul>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var f = document.getElementById('form-seguimiento');

  if (!f) { return; }

  var BASE = <?= P::js($rutas->url('tienda/pedido/')) ?>;

  /* El folio va en la ruta y la clave en la consulta, que es como lo
     espera la pantalla del pedido. Se arma aquí para no tener que
     montar una ruta de servidor que no haría nada más que esto.

     El folio se limpia de espacios y se pasa a mayúsculas: lo normal
     es que venga copiado de un correo y traiga un espacio pegado al
     final, y eso no debe parecer una clave equivocada. */
  f.addEventListener('submit', function (ev) {
    ev.preventDefault();

    var folio = document.getElementById('folio').value.trim().toUpperCase();
    var clave = document.getElementById('clave').value.trim().toUpperCase();

    if (folio === '' || clave === '') { return; }

    window.location.assign(
      BASE + encodeURIComponent(folio) + '?clave=' + encodeURIComponent(clave)
    );
  });
})();
</script>

<?php $tdaCarritoFlotante($carritoUnidades ?? 0); ?>
