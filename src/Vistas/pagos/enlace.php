<?php
/**
 * La página que abre el cliente desde el enlace de su correo.
 *
 * Deliberadamente escueta. Quien llega aquí tiene un enlace, no una
 * sesión, así que no se le enseña su cartera ni su saldo ni su
 * dirección: solo qué se le cobra, cuánto, y el formulario de tarjeta.
 *
 * El tono también es a propósito. Es alguien con una deuda atrasada que
 * ha decidido ponerse al día: el momento de reprochárselo ya pasó, y lo
 * único que toca es ponérselo fácil.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $enlace
 * @var string $token
 * @var bool   $activa
 * @var bool   $esPrueba
 * @var string $publica
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;
?>

<div class="enl">
  <div class="enl-tarjeta">
    <div class="enl-cabecera">
      <span class="enl-marca">CashMan H.A.</span>
      <h1 class="enl-titulo">Pagar su crédito</h1>
    </div>

    <div class="enl-cuerpo">
      <p class="enl-saludo">
        Hola, <strong><?= P::e((string) $enlace['cliente']) ?></strong>.
        Gracias por ponerse al día.
      </p>

      <dl class="enl-detalle">
        <div>
          <dt>Concepto</dt>
          <dd><?= P::e((string) $enlace['concepto']) ?></dd>
        </div>
        <?php if (!empty($enlace['credito_folio'])): ?>
        <div>
          <dt>Crédito</dt>
          <dd class="tda-mono"><?= P::e((string) $enlace['credito_folio']) ?></dd>
        </div>
        <?php endif; ?>
        <div>
          <dt>Válido hasta</dt>
          <dd><?= P::e(P::fecha((string) $enlace['vence_el'], 'd/m/Y')) ?></dd>
        </div>
        <div class="enl-total">
          <dt>Total a pagar</dt>
          <dd><?= P::e(P::money((int) $enlace['importe_cent'])) ?></dd>
        </div>
      </dl>

      <?php if ($esPrueba): ?>
      <div class="enl-aviso">
        <strong>Entorno de pruebas.</strong> No se cobra dinero real. Puede usar
        4242&nbsp;4242&nbsp;4242&nbsp;4242 con cualquier fecha futura y cualquier CVC.
      </div>
      <?php endif; ?>

      <?php if (!$activa): ?>
      <div class="enl-aviso enl-aviso--mal">
        El cobro con tarjeta no está disponible en este momento. Vuelva a
        intentarlo más tarde o comuníquese con nosotros.
      </div>
      <?php else: ?>

      <form id="enl-form">
        <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
        <input type="hidden" name="_csrf_proposito" value="enlace_pago">

        <label class="enl-etiqueta">Datos de su tarjeta</label>
        <div id="enl-tarjeta-campo" class="enl-campo"></div>
        <div id="enl-error" class="enl-error" role="alert"></div>

        <button type="submit" class="enl-boton" id="enl-pagar">
          Pagar <?= P::e(P::money((int) $enlace['importe_cent'])) ?>
        </button>
      </form>

      <p class="enl-nota">
        Aceptamos Visa, Mastercard y American Express de cualquier banco.
        Sus datos viajan cifrados directamente a la pasarela: ni nosotros
        ni nuestro personal llegamos a verlos.
      </p>
      <?php endif; ?>
    </div>
  </div>

  <div id="enl-listo" class="enl-tarjeta enl-tarjeta--exito" hidden>
    <div class="enl-cuerpo text-center">
      <div class="enl-tic">✓</div>
      <h2>Pago recibido</h2>
      <p>
        Gracias. Le enviamos el comprobante a su correo y su crédito
        quedará actualizado en unos minutos.
      </p>
    </div>
  </div>
</div>

<?php if ($activa): ?>
<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>
        src="https://js.stripe.com/v3/"></script>
<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function (global) {
  'use strict';

  // `betas`/`link` fuera: el monedero Link de Stripe abre un diálogo
  // propio pidiendo correo para crear cuenta, encima del formulario.
  // A alguien que entra a pagar una deuda se le está pidiendo abrir una
  // cuenta en un tercero antes de poder pagar.
  var stripe   = Stripe(<?= P::js($publica) ?>);
  var elements = stripe.elements();
  var tarjeta  = elements.create('card', {
    style: { base: { fontSize: '16px', color: '#1d2433' } },
    // El código postal no se pide: es un dato estadounidense que aquí
    // no significa nada y frena el pago sin aportar seguridad.
    hidePostalCode: true,
    disableLink: true
  });

  tarjeta.mount('#enl-tarjeta-campo');

  var form  = document.getElementById('enl-form');
  var boton = document.getElementById('enl-pagar');
  var error = document.getElementById('enl-error');
  var URL   = <?= P::js($rutas->url('pagar-enlace/' . $token)) ?>;

  // El token de seguridad vigente. Cada respuesta del servidor trae uno
  // nuevo porque son de un solo uso, y el cierre del cobro necesita el
  // fresco, no el que venía en el formulario.
  var csrfVivo = <?= P::js($csrf) ?>;

  tarjeta.on('change', function (ev) {
    error.textContent = ev.error ? ev.error.message : '';
  });

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    boton.disabled = true;
    boton.textContent = 'Procesando…';
    error.textContent = '';

    // El importe lo pone el servidor desde el enlace. Si lo mandara
    // este formulario, bastaría con editarlo para saldar una deuda de
    // mil dólares pagando uno.
    fetch(URL, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { throw new Error(j.mensaje || 'No se pudo preparar el cobro.'); }

        /* El token de seguridad es de UN SOLO USO.
         *
         * Reenviar el mismo en la segunda llamada hacía que el servidor
         * la rechazara: el cobro se hacía en la pasarela, el cierre se
         * caía por token gastado y el crédito se quedaba sin actualizar.
         * Desde fuera parecía el mismo fallo de antes.
         *
         * Cada respuesta trae uno nuevo; se guarda para el cierre. */
        csrfVivo = j.csrf || csrfVivo;

        return stripe.confirmCardPayment(j.datos.clave, {
          payment_method: { card: tarjeta }
        });
      })
      .then(function (res) {
        if (res.error) { throw new Error(res.error.message); }

        if (res.paymentIntent && res.paymentIntent.status === 'succeeded') {
          /* El cobro salió bien en la pasarela; ahora hay que APLICARLO.
           *
           * Esto faltaba, y es lo que hacía que un finiquito se pagara
           * y el crédito siguiera vigente: Stripe cobraba, la página
           * decía «pago recibido» y del lado del servidor no pasaba
           * nada. El webhook tampoco salvaba la situación, porque en una
           * instalación local Stripe no puede alcanzar el servidor.
           *
           * El servidor no se fía de esta llamada: vuelve a preguntarle
           * a Stripe por el intento y solo aplica si la propia pasarela
           * dice que está cobrado. */
          // Se arma desde cero con el token vivo, no copiando el
          // formulario: ese todavía lleva el que ya se gastó.
          var cierre = new FormData();
          cierre.append('_csrf', csrfVivo);
          cierre.append('_csrf_proposito', 'enlace_pago');
          cierre.append('intento', res.paymentIntent.id);

          return fetch(URL + '/confirmar', {
            method: 'POST',
            body: cierre,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
          })
            .then(function (r) { return r.json(); })
            .then(function (j) {
              document.querySelector('.enl-tarjeta').hidden = true;
              document.getElementById('enl-listo').hidden = false;

              // Aunque la aplicación fallara, el cobro ya se hizo: no se
              // le pide al cliente que lo repita. Queda para el webhook
              // y para revisión interna.
              if (!j.ok && window.console && console.warn) {
                console.warn('[CashMan] cobro hecho, aplicacion pendiente:', j.mensaje);
              }
            });
        }

        boton.disabled = false;
        boton.textContent = 'Reintentar';
        error.textContent = 'El pago quedó en proceso. Le avisaremos por correo '
                          + 'en cuanto se confirme.';
      })
      .catch(function (e) {
        boton.disabled = false;
        boton.textContent = 'Reintentar';
        error.textContent = e.message || 'No se pudo completar el pago.';
      });
  });
})();
</script>
<?php endif; ?>
