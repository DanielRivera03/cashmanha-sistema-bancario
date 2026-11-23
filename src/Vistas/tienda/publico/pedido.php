<?php
/**
 * Seguimiento de un pedido.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $pedido
 * @var array $lineas
 * @var array $hermanos  Otros pedidos del mismo cobro.
 */
use CashMan\Http\Plantilla as P;

require __DIR__ . "/_piezas.php";

$p = $pedido;

$pasos = ['pagado' => 1, 'preparando' => 2, 'enviado' => 3, 'entregado' => 4];
$actual = $pasos[(string) $p['estado']] ?? 0;
?>

<div class="tda-gracias">
  <?php if ((string) $p['estado'] === 'pago_pendiente'): ?>
  <h1>Su pedido está apartado</h1>
  <p>
    Estamos esperando la confirmación de la procesadora. En cuanto llegue le
    avisamos por correo; normalmente es cuestión de segundos.
  </p>
  <?php elseif ((string) $p['estado'] === 'anulado'): ?>
  <h1>Este pedido fue anulado</h1>
  <p>
    Si el cobro no se completó, no se le cobró nada y los artículos volvieron al
    catálogo. Si cree que se trata de un error, con gusto lo revisamos.
  </p>
  <?php else: ?>
  <h1>¡Gracias por su compra!</h1>
  <p>Recibimos su pedido y ya lo estamos atendiendo.</p>
  <?php endif; ?>
</div>

<div class="tda-pedido">
  <div class="tda-pedido__cab">
    <span class="tda-tienda__logo" style="background:<?= P::attr($p['color']) ?>">
      <?= P::e($p['iniciales']) ?>
    </span>
    <div>
      <strong><?= P::e($p['comercio']) ?></strong>
      <small class="tda-mono"><?= P::e($p['folio']) ?></small>
    </div>
    <div class="tda-pedido__total">
      <?= P::e(P::money((int) $p['total_cent'])) ?>
    </div>
  </div>

  <?php if ($actual > 0): ?>
  <!-- El estado como una línea de pasos, CON LA FECHA de cada uno.
       Con una palabra suelta nadie sabe si faltan dos días o dos
       semanas. Y con la fecha, esta pantalla es la que manda: si llega
       un correo viejo que dice otra cosa, aquí se ve cuándo pasó de
       verdad cada paso, o que todavía no pasó. -->
  <?php
  $cuando = [
      1 => $p['creado_en']    ?? null,
      2 => $p['preparando_en'] ?? null,
      3 => $p['enviado_en']    ?? null,
      4 => $p['entregado_en']  ?? null,
  ];
  ?>
  <ol class="tda-pasos">
    <?php foreach (['Pagado', 'En preparación', 'Enviado', 'Entregado'] as $i => $texto): ?>
    <?php $n = $i + 1; $hecho = $n <= $actual; ?>
    <li class="<?= $hecho ? 'tda-pasos--hecho' : '' ?>">
      <span><?= $n ?></span>
      <em><?= P::e($texto) ?></em>
      <?php if ($hecho && !empty($cuando[$n])): ?>
      <small><?= P::e(P::fecha((string) $cuando[$n])) ?></small>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>

  <p class="tda-pasos__nota">
    Esta pantalla es la que vale: se actualiza sola cuando la tienda mueve su
    pedido. Si recibió un correo que dice otra cosa, fíese de lo que ve aquí.
  </p>
  <?php endif; ?>

  <ul class="tda-resumen__lineas">
    <?php foreach ($lineas as $l): ?>
    <li>
      <span><?= (int) $l['cantidad'] ?> × <?= P::e($l['nombre']) ?></span>
      <b><?= P::e(P::money((int) $l['precio_cent'] * (int) $l['cantidad'])) ?></b>
    </li>
    <?php endforeach; ?>

    <?php if ((int) $p['envio_cent'] > 0): ?>
    <li><span>Envío</span><b><?= P::e(P::money((int) $p['envio_cent'])) ?></b></li>
    <?php endif; ?>
  </ul>

  <dl class="tda-pedido__datos">
    <div>
      <dt>Fecha</dt>
      <dd><?= P::e(P::fecha((string) $p['creado_en'])) ?></dd>
    </div>
    <div>
      <dt>Pagado con</dt>
      <dd>
        <?php if (!empty($p['ultimos4'])): ?>
          <?= P::e(strtoupper((string) $p['marca'])) ?> ····<?= P::e($p['ultimos4']) ?>
        <?php else: ?>
          Tarjeta por la pasarela
        <?php endif; ?>
      </dd>
    </div>
    <?php if ((int) $p['cuotas'] > 0): ?>
    <div>
      <dt>Cuotas</dt>
      <dd><?= (int) $p['cuotas'] ?> sin intereses</dd>
    </div>
    <?php endif; ?>
    <div>
      <dt>Referencia</dt>
      <dd class="tda-mono"><?= P::e($p['referencia']) ?></dd>
    </div>

    <?php /* El envío. Es lo que de verdad se viene a mirar aquí, y
             hasta ahora no estaba: «enviado» a secas no dice con quién
             va, ni con qué número rastrearlo, ni para cuándo. */ ?>
    <?php if (!empty($p['transportista'])): ?>
    <div>
      <dt>Lo lleva</dt>
      <dd><?= P::e((string) $p['transportista']) ?></dd>
    </div>
    <?php endif; ?>

    <?php if (!empty($p['guia'])): ?>
    <div>
      <dt>Número de guía</dt>
      <dd class="tda-mono"><?= P::e((string) $p['guia']) ?></dd>
    </div>
    <?php endif; ?>

    <?php if (!empty($p['entrega_estimada'])): ?>
    <div>
      <dt>Entrega estimada</dt>
      <dd><?= P::e(date('d/m/Y', strtotime((string) $p['entrega_estimada']))) ?></dd>
    </div>
    <?php endif; ?>

    <?php if (!empty($p['enviado_en'])): ?>
    <div>
      <dt>Salió de la tienda</dt>
      <dd><?= P::e(P::fecha((string) $p['enviado_en'])) ?></dd>
    </div>
    <?php endif; ?>

    <?php if (!empty($p['entregado_en'])): ?>
    <div>
      <dt>Entregado</dt>
      <dd><?= P::e(P::fecha((string) $p['entregado_en'])) ?></dd>
    </div>
    <?php endif; ?>

    <?php if (!empty($p['nota_despacho'])): ?>
    <div class="tda-campo--ancho">
      <dt>De la tienda</dt>
      <dd><?= P::e((string) $p['nota_despacho']) ?></dd>
    </div>
    <?php endif; ?>
    <?php if (!empty($p['direccion'])): ?>
    <div class="tda-campo--ancho">
      <dt>Entrega en</dt>
      <dd><?= P::e($p['direccion']) ?></dd>
    </div>
    <?php endif; ?>
  </dl>

  <?php if (!empty($p['clave_consulta'])): ?>
  <div class="tda-aviso tda-aviso--info">
    <strong>Guarde esta clave:</strong>
    <span class="tda-mono"><?= P::e($p['clave_consulta']) ?></span>.
    Con el folio <span class="tda-mono"><?= P::e($p['folio']) ?></span> y esta clave
    puede consultar el estado de su pedido cuando quiera, sin iniciar sesión.
  </div>
  <?php endif; ?>

  <?php if (!empty($hermanos)): ?>
  <?php /* Un carrito con varias tiendas se cobra una vez y abre un
           pedido por tienda. Sin esta lista, el comprador se iba con el
           folio de una sola y la otra quedaba invisible: su clave no
           vuelve a aparecer en ninguna otra pantalla. */ ?>
  <div class="tda-hermanos">
    <h3 class="tda-hermanos__titulo">
      <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
        <path d="M3 7h18M3 12h18M3 17h18" fill="none" stroke="currentColor"
              stroke-width="2" stroke-linecap="round"/>
      </svg>
      En este mismo pago compró en <?= count($hermanos) + 1 ?> tiendas
    </h3>

    <p class="tda-hermanos__nota">
      Se le cobró una sola vez. Cada tienda prepara y envía lo suyo por
      separado, así que cada pedido tiene su propio folio y su propia clave.
    </p>

    <ul class="tda-hermanos__lista">
      <?php foreach ($hermanos as $h): ?>
      <li>
        <span class="tda-hermanos__sello" style="--sello: <?= P::attr((string) $h['color']) ?>">
          <?= P::e((string) $h['iniciales']) ?>
        </span>
        <span class="tda-hermanos__datos">
          <b><?= P::e((string) $h['comercio']) ?></b>
          <small>
            Folio <span class="tda-mono"><?= P::e((string) $h['folio']) ?></span>
            · clave <span class="tda-mono"><?= P::e((string) $h['clave_consulta']) ?></span>
            · <?= P::e(P::money((int) $h['total_cent'])) ?>
          </small>
        </span>
        <a class="sitio-btn sitio-btn--linea sitio-btn--chico"
           href="<?= P::attr($rutas->url('tienda/pedido/' . $h['folio']) . '?clave=' . rawurlencode((string) $h['clave_consulta'])) ?>">
          Ver
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
</div>

<div class="tda-gracias__pie">
  <a class="sitio-btn sitio-btn--primario" href="<?= P::attr($rutas->url('tienda')) ?>">
    Seguir comprando
  </a>
</div>

<?php $tdaCarritoFlotante($carritoUnidades ?? 0); ?>
