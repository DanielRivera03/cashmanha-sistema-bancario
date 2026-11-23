<?php
/**
 * Pedir la clave para consultar un pedido.
 *
 * Con el folio solo no basta: quien adivinara uno vería el pedido de
 * otra persona, con su nombre y su dirección de entrega. Por eso se pide
 * la clave que se entregó al comprar, salvo que el pedido sea de quien
 * está dentro con su propia sesión.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var string $folio
 * @var bool   $intento
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

require __DIR__ . "/_piezas.php";
?>

<div class="tda-clave">
  <h1>Consultar su pedido</h1>

  <p>
    Para ver el pedido <span class="tda-mono"><?= P::e($folio) ?></span> necesitamos
    la clave de seguimiento que le dimos al comprar; también se la enviamos por
    correo junto con el comprobante.
  </p>

  <?php if ($intento): ?>
  <div class="tda-aviso">
    Esa clave no corresponde a este pedido. Revísela en el correo de confirmación,
    por favor.
  </div>
  <?php endif; ?>

  <form method="post" action="<?= P::attr($rutas->url('tienda/pedido/' . $folio)) ?>">
    <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
    <input type="hidden" name="_csrf_proposito" value="tienda_publica">

    <label class="tda-etiqueta" for="clave">Clave de seguimiento</label>
    <input type="text" id="clave" name="clave" required maxlength="12"
           class="tda-mono" autocomplete="off" placeholder="Doce caracteres">

    <button class="sitio-btn sitio-btn--primario" type="submit">Ver mi pedido</button>
  </form>

  <p class="tda-clave__pie">
    ¿Es cliente de CashMan H.A.?
    <a href="<?= P::attr($rutas->url('entrar')) ?>">Inicie sesión</a>
    y verá sus pedidos sin necesidad de clave.
  </p>

  <p class="tda-clave__pie">
    ¿Llegó aquí con otro folio?
    <a href="<?= P::attr($rutas->url('tienda/seguimiento')) ?>">Consulte cualquier pedido</a>
    escribiendo su folio y su clave.
  </p>
</div>

<?php $tdaCarritoFlotante($carritoUnidades ?? 0); ?>
