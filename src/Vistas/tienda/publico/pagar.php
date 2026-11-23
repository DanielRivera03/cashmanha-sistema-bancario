<?php
/**
 * Finalizar la compra.
 *
 * Dos caminos, y el que se ofrece primero depende de quién esté al otro
 * lado: a un cliente con sesión se le ofrece su tarjeta CashMan H.A. ya
 * identificada, y a cualquiera, pagar con la tarjeta de otro banco.
 *
 * Los datos de un cliente con sesión vienen llenos: pedirle el nombre y
 * el correo a quien acaba de iniciar sesión es hacerle teclear lo que ya
 * sabemos, y da pie a que lo escriba distinto.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array      $carrito
 * @var array|null $cliente
 * @var array      $tarjetas
 * @var bool       $pasarela
 * @var bool       $esPrueba
 * @var string     $publica
 * @var string     $csrf
 */
use CashMan\Http\Plantilla as P;

require __DIR__ . "/_piezas.php";

$nombrePrevio = $cliente !== null
    ? trim($cliente['nombres'] . ' ' . $cliente['apellidos'])
    : '';
?>

<h1 class="tda-titulo">Finalizar su compra</h1>

<?php if ($esPrueba): ?>
<div class="tda-aviso tda-aviso--info">
  <strong>Entorno de pruebas.</strong> No se cobra dinero real.
  <br>
  Con <em>una tarjeta de CashMan H.A.</em> use su número, su vencimiento y su
  CVV reales: se comprueban contra el banco y el consumo aparece en su estado
  de cuenta.
  <br>
  Con <em>una tarjeta de otro banco</em>, la procesadora solo admite sus
  propios números de prueba:
  <span class="tda-mono">4242 4242 4242 4242</span> (Visa),
  <span class="tda-mono">5555 5555 5555 4444</span> (Mastercard) o
  <span class="tda-mono">3782 822463 10005</span> (American Express), con
  cualquier fecha futura y cualquier CVC.
</div>
<?php endif; ?>

<div class="tda-pagar">
  <div class="tda-pagar__form">

    <?php if ($cliente === null): ?>
    <div class="tda-aviso">
      ¿Ya es cliente?
      <a href="<?= P::attr($rutas->url('entrar')) ?>">Inicie sesión</a>
      y llenamos sus datos solos, además de poder pagar con su tarjeta CashMan H.A.
      y diferir a cuotas sin intereses. Si prefiere comprar como invitado,
      continúe aquí abajo.
    </div>
    <?php endif; ?>

    <!-- ============ DATOS DE ENTREGA ============ -->
    <section class="tda-bloque">
      <h2>¿A dónde se lo enviamos?</h2>

      <div class="tda-campos" id="datos-entrega">
        <div class="tda-campo tda-campo--ancho">
          <label class="tda-etiqueta" for="nombre">Nombre completo</label>
          <input type="text" id="nombre" name="nombre" required maxlength="140"
                 value="<?= P::attr($nombrePrevio) ?>"
                 <?= $cliente !== null ? 'readonly' : '' ?>>
        </div>

        <div class="tda-campo">
          <label class="tda-etiqueta" for="correo">Correo electrónico</label>
          <input type="email" id="correo" name="correo" required maxlength="190"
                 value="<?= P::attr($cliente['correo'] ?? '') ?>"
                 <?= $cliente !== null ? 'readonly' : '' ?>>
          <small>Le enviaremos el comprobante y los avisos del envío.</small>
        </div>

        <div class="tda-campo">
          <label class="tda-etiqueta" for="telefono">Teléfono</label>
          <input type="tel" id="telefono" name="telefono" maxlength="30"
                 value="<?= P::attr($cliente['telefono'] ?? '') ?>">
        </div>

        <?php /* El tipo manda la máscara, igual que en el registro: el
                 mismo DUI escrito de dos maneras son dos clientes
                 distintos para cualquier búsqueda posterior. */ ?>
        <div class="tda-campo" data-cm-documento>
          <label class="tda-etiqueta" for="tipo_documento">Documento de identidad</label>
          <div class="tda-doc">
            <select id="tipo_documento" name="tipo_documento" data-cm-documento-tipo
                    aria-label="Tipo de documento">
              <?php foreach (['dui' => 'DUI', 'nit' => 'NIT', 'residente' => 'Carné'] as $cod => $et): ?>
              <option value="<?= P::attr($cod) ?>"
                <?= ($cliente['tipo_documento'] ?? 'dui') === $cod ? 'selected' : '' ?>>
                <?= P::e($et) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <input type="text" id="documento" name="documento" maxlength="20"
                   class="tda-mono" data-cm-documento-valor autocomplete="off"
                   value="<?= P::attr($cliente['documento_identidad'] ?? '') ?>">
          </div>
          <small data-cm-documento-nota>Opcional. Nos ayuda a verificar la entrega.</small>
        </div>

        <div class="tda-campo tda-campo--ancho">
          <label class="tda-etiqueta" for="direccion">Dirección de entrega</label>
          <textarea id="direccion" name="direccion" rows="2" required
                    placeholder="Calle, número, colonia, municipio y departamento"><?= P::e($cliente['direccion'] ?? '') ?></textarea>
        </div>

      </div>
    </section>

    <!-- ============ CON QUÉ PAGA ============ -->
    <section class="tda-bloque">
      <h2>¿Con qué desea pagar?</h2>

      <?php if ($tarjetas !== []): ?>
      <!-- ---------- Tarjeta emitida aquí ---------- -->
      <form id="form-propia" method="post"
            action="<?= P::attr($rutas->url('tienda/pagar/tarjeta')) ?>">
        <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
        <input type="hidden" name="_csrf_proposito" value="tienda_publica">
        <input type="hidden" name="direccion" id="prop-direccion">

        <div class="tda-campo tda-campo--ancho">
          <label class="tda-etiqueta" for="tarjeta">Mi tarjeta CashMan H.A.</label>
          <select id="tarjeta" name="tarjeta" required>
            <?php foreach ($tarjetas as $t): ?>
            <option value="<?= P::attr($t['codigo']) ?>"
                    data-tope="<?= (int) $t['tope_cent'] ?>">
              <?= P::e(strtoupper((string) $t['marca'])) ?> ····<?= P::e($t['ultimos4']) ?>
              · disponible <?= P::e(P::money((int) $t['disponible_cent'])) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php /* El plazo va DENTRO de este formulario a propósito.
                 Estaba fuera, en un campo oculto que un script rellenaba
                 al enviar; cuando esa copia no ocurría, la compra salía
                 al contado sin avisar y el cliente tenía que diferirla a
                 mano después. Aquí lo envía el navegador. */ ?>
        <?php if ((int) $carrito['cuotas_disponibles'] > 0): ?>
        <div class="tda-campo tda-campo--ancho">
          <label class="tda-etiqueta" for="cuotas">¿Desea diferirlo a cuotas?</label>
          <select id="cuotas" name="cuotas">
            <option value="0">De contado</option>
            <?php foreach ([3, 6, 9, 12, 15, 18, 24] as $k):
              if ($k > (int) $carrito['cuotas_disponibles']) { continue; }
            ?>
            <option value="<?= $k ?>">
              <?= $k ?> cuotas de
              <?= P::e(P::money((int) round((int) $carrito['total_cent'] / $k))) ?>
              sin intereses
            </option>
            <?php endforeach; ?>
          </select>
          <small>
            El plan se crea <strong>automáticamente</strong> al confirmar la compra, con
            el plazo que elija aquí: no hay que hacer ningún trámite después. Paga
            exactamente el importe de la compra, sin intereses ni primas de seguro sobre
            ese saldo. Puede mantener hasta
            <strong><?= (int) ($topePlanes ?? 5) ?> compras diferidas a la vez</strong>
            en una misma tarjeta; al terminar de pagar alguna se libera el cupo.
          </small>
        </div>
        <?php endif; ?>

        <button class="sitio-btn sitio-btn--primario" type="submit">
          Pagar <?= P::e(P::money((int) $carrito['total_cent'])) ?> con mi tarjeta
        </button>
      </form>

      <div class="tda-separador"><span>o</span></div>
      <?php endif; ?>

      <!-- ---------- Tarjeta de CashMan H.A., tecleada ---------- -->
      <?php /* El camino de quien tiene una tarjeta del banco pero no la
               sesión abierta: la saca de la cartera y escribe lo que hay
               impreso, como en cualquier tienda. Antes el número que el
               banco entregaba no servía para nada fuera de la banca en
               línea de su propio titular. */ ?>
      <form id="form-numero" class="tda-numero">
        <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
        <input type="hidden" name="_csrf_proposito" value="tienda_publica">
        <input type="hidden" name="direccion" id="num-direccion">
        <input type="hidden" name="cuotas"    id="num-cuotas" value="0">

        <p class="tda-pagar__marcas">
          ¿Tiene una tarjeta de CashMan H.A.? Escriba sus datos y páguela aquí,
          sin iniciar sesión.
        </p>

        <div class="tda-campos">
          <div class="tda-campo tda-campo--ancho">
            <label class="tda-etiqueta" for="num-numero">
              Número de la tarjeta
              <span id="num-marca" class="tda-marca" hidden></span>
            </label>
            <?php /* El número se tapa mientras se escribe: en pantalla
                     quedan puntos y los últimos cuatro, como en un
                     comprobante. Lo que viaja es un campo oculto que
                     pone `tarjeta-campo.js`; por eso este no lleva
                     `name` ni autocompletado. */ ?>
            <input type="text" id="num-numero" name="numero" class="tda-mono"
                   inputmode="numeric" autocomplete="off" maxlength="27"
                   placeholder="0000 0000 0000 0000" required>
            <small>Solo se ven los últimos cuatro dígitos.</small>
          </div>

          <div class="tda-campo">
            <label class="tda-etiqueta" for="num-mes">Vence</label>
            <div class="tda-vence">
              <input type="text" id="num-mes" name="mes" class="tda-mono"
                     inputmode="numeric" autocomplete="cc-exp-month" maxlength="2"
                     placeholder="MM" required aria-label="Mes de vencimiento">
              <span aria-hidden="true">/</span>
              <input type="text" id="num-anio" name="anio" class="tda-mono"
                     inputmode="numeric" autocomplete="cc-exp-year" maxlength="4"
                     placeholder="AAAA" required aria-label="Año de vencimiento">
            </div>
          </div>

          <div class="tda-campo">
            <label class="tda-etiqueta" for="num-cvv">CVV</label>
            <?php /* Nunca se ve, ni a medias: es el dato que convierte
                     un número de tarjeta en un cobro. El tope de
                     dígitos lo pone la marca —tres, o cuatro en
                     American Express—, no un «hasta cuatro» que deja
                     mandar un CVV incompleto. */ ?>
            <input type="password" id="num-cvv" name="cvv" class="tda-mono"
                   inputmode="numeric" autocomplete="off" maxlength="3"
                   placeholder="000" required>
            <small id="num-cvv-pista">Los tres dígitos del dorso. Cuatro en American Express.</small>
          </div>
        </div>

        <div id="num-error" class="tda-error"></div>

        <button class="sitio-btn sitio-btn--primario" type="submit">
          Pagar <?= P::e(P::money((int) $carrito['total_cent'])) ?> con mi tarjeta CashMan
        </button>

        <small class="tda-pagar__nota">
          Los datos viajan cifrados y no se guardan: el CVV no se almacena en
          ningún momento, ni siquiera cifrado.
        </small>
      </form>

      <div class="tda-separador"><span>o</span></div>

      <!-- ---------- Tarjeta de cualquier banco ---------- -->
      <?php if (!$pasarela): ?>
      <div class="tda-aviso">
        El pago con tarjeta de otro banco no está disponible en este momento.
        Lamentamos la molestia.
      </div>
      <?php else: ?>
      <form id="form-pasarela">
        <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
        <input type="hidden" name="_csrf_proposito" value="tienda_publica">
        <input type="hidden" name="nombre"    id="pas-nombre">
        <input type="hidden" name="correo"    id="pas-correo">
        <input type="hidden" name="telefono"  id="pas-telefono">
        <input type="hidden" name="documento" id="pas-documento">
        <input type="hidden" name="tipo_documento" id="pas-tipo-documento">
        <input type="hidden" name="direccion" id="pas-direccion">
        <?php /* Por la pasarela se paga con tarjeta de otro banco: no hay
                 plan que crear, asi que no se ofrecen cuotas. Ofrecerlas
                 era prometer algo que no iba a existir. */ ?>
        <input type="hidden" name="cuotas"    id="pas-cuotas" value="0">

        <p class="tda-pagar__marcas">
          Aceptamos Visa, Mastercard y American Express de cualquier banco.
        </p>

        <button class="sitio-btn sitio-btn--primario" type="submit">
          Pagar <?= P::e(P::money((int) $carrito['total_cent'])) ?> con tarjeta
        </button>
      </form>

      <div id="paso-tarjeta" hidden>
        <div class="tda-cobro">
          <span>Total a cobrar</span>
          <strong id="importe-final">—</strong>
        </div>

        <label class="tda-etiqueta">Datos de su tarjeta</label>
        <!-- Este recuadro lo dibuja la procesadora: el número de la
             tarjeta no pasa por nuestro servidor en ningún momento. -->
        <div id="tarjeta-elemento" class="tda-stripe"></div>
        <div id="tarjeta-error" class="tda-error"></div>

        <div class="tda-pagar__botones">
          <button type="button" class="sitio-btn sitio-btn--claro" id="btn-volver">Atrás</button>
          <button type="button" class="sitio-btn sitio-btn--primario" id="btn-pagar">
            Pagar ahora
          </button>
        </div>

        <small class="tda-pagar__nota">
          El cobro se procesa de forma cifrada por la procesadora. CashMan H.A. no
          almacena en ningún momento el número de su tarjeta ni su código de
          seguridad.
        </small>
      </div>
      <?php endif; ?>
    </section>
  </div>

  <!-- ============ RESUMEN ============ -->
  <aside class="tda-resumen">
    <h2>Su pedido</h2>

    <?php if ($carrito['comercio'] !== null): ?>
    <div class="tda-resumen__tienda">
      <span class="tda-tienda__logo" style="background:<?= P::attr($carrito['comercio']['color']) ?>">
        <?= P::e($carrito['comercio']['iniciales']) ?>
      </span>
      <strong><?= P::e($carrito['comercio']['nombre']) ?></strong>
    </div>
    <?php endif; ?>

    <ul class="tda-resumen__lineas">
      <?php foreach ($carrito['lineas'] as $l): ?>
      <li>
        <span><?= (int) $l['cantidad'] ?> × <?= P::e($l['nombre']) ?></span>
        <b><?= P::e(P::money((int) $l['importe_cent'])) ?></b>
      </li>
      <?php endforeach; ?>
    </ul>

    <dl class="tda-resumen__totales">
      <div><dt>Subtotal</dt><dd><?= P::e(P::money((int) $carrito['subtotal_cent'])) ?></dd></div>
      <div>
        <dt>Envío</dt>
        <dd><?= (int) $carrito['envio_cent'] > 0
                  ? P::e(P::money((int) $carrito['envio_cent']))
                  : 'Gratis' ?></dd>
      </div>
      <div class="tda-resumen__total">
        <dt>Total</dt><dd><?= P::e(P::money((int) $carrito['total_cent'])) ?></dd>
      </div>
    </dl>

    <a class="tda-resumen__seguir" href="<?= P::attr($rutas->url('tienda/carrito')) ?>">
      Volver al carrito
    </a>
  </aside>
</div>

<?php if ($pasarela): ?>
<?php $tjv = @filemtime(dirname(__DIR__, 4) . '/public/assets/js/tarjeta-campo.js') ?: time(); ?>
<script src="<?= P::attr($rutas->url('assets/js/tarjeta-campo.js') . '?v=' . $tjv) ?>"></script>
<script src="https://js.stripe.com/v3/"></script>
<?php endif; ?>
<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var TOTAL = <?= (int) $carrito['total_cent'] ?>;

  function val(id) {
    var e = document.getElementById(id);
    return e ? e.value.trim() : '';
  }

  /* Los datos de entrega se escriben una sola vez.
     Están fuera de los dos formularios de pago a propósito: repetirlos
     en cada uno obligaría al cliente a llenarlos dos veces, o a nosotros
     a mantenerlos sincronizados a mano. Se copian al enviar. */
  function copiarA(prefijo) {
    /* El plazo ya no se copia: vive dentro del formulario de la
       tarjeta propia y lo envia el navegador. Copiarlo a mano era
       justo lo que fallaba. */
    /* El tipo va con el número: sin él, el servidor no sabe si
       «012345678» es un DUI o un NIT, y lo guarda mal. */
    [['nombre', 'nombre'], ['correo', 'correo'], ['telefono', 'telefono'],
     ['documento', 'documento'], ['tipo_documento', 'tipo-documento'],
     ['direccion', 'direccion']]
      .forEach(function (par) {
        var destino = document.getElementById(prefijo + '-' + par[1]);
        if (destino) { destino.value = val(par[0]); }
      });
  }

  function faltan() {
    if (val('nombre').length < 5) {
      return 'Indíquenos su nombre completo, por favor.';
    }
    if (val('correo').indexOf('@') < 1) {
      return 'Necesitamos un correo válido para enviarle el comprobante.';
    }
    if (val('direccion').length < 10) {
      return 'Necesitamos una dirección de entrega con algo de detalle.';
    }
    return '';
  }

  function avisar(m) {
    /* Sin `alert()` de respaldo: ese recuadro negro con «localhost:90
       dice» en una pantalla de pago parece un error del sistema, no un
       dato que falta. `avisos.js` se carga en el marco público, así
       que `CMAviso` existe siempre; si algún día no cargara, el
       mensaje va a la consola y el formulario sigue funcionando. */
    if (window.CMAviso) { window.CMAviso.error('Revise sus datos', m); }
    else { console.warn('[pago] ' + m); }
  }

  // --- Pagar con la tarjeta de la casa ------------------------------
  var propia = document.getElementById('form-propia');

  if (propia) {
    propia.addEventListener('submit', function (ev) {
      var falta = faltan();

      if (falta !== '') { ev.preventDefault(); avisar(falta); return; }

      copiarA('prop');

      var sel = document.getElementById('tarjeta');

      if (sel && sel.selectedOptions.length) {
        var tope = parseInt(sel.selectedOptions[0].dataset.tope, 10) || 0;

        if (TOTAL > tope) {
          ev.preventDefault();
          avisar('El cupo de esa tarjeta no alcanza para esta compra. '
            + 'Puede abonar a su tarjeta, elegir otra o pagar con la de otro banco.');
          return;
        }
      }

      /* Este formulario se manda a la vieja usanza, con recarga de
         página. El panel se queda hasta que el navegador cambie de
         pantalla; si el servidor contesta con un error, viene en la
         página nueva y el panel se va con la anterior. */
      CMProceso.abrir('compra').avanzar();
    });
  }

  // --- Pagar tecleando una tarjeta de la casa -----------------------
  var fNum = document.getElementById('form-numero');

  if (fNum) {
    var errNum = document.getElementById('num-error');
    var URL_NUM = <?= P::js($rutas->url('tienda/pagar/numero')) ?>;

    /* El número y el CVV los lleva `tarjeta-campo.js`: tapa lo que se
       escribe, agrupa como viene impreso en el plástico y ajusta el
       CVV a la marca. Aquí solo quedan el mes y el año. */
    CMTarjeta.proteger({
      numero:   '#num-numero',
      cvv:      '#num-cvv',
      marca:    '#num-marca',
      pistaCvv: '#num-cvv-pista'
    });

    ['num-mes', 'num-anio'].forEach(function (id) {
      var c = document.getElementById(id);
      c.addEventListener('input', function () { c.value = c.value.replace(/\D+/g, ''); });
    });

    fNum.addEventListener('submit', function (ev) {
      ev.preventDefault();
      errNum.textContent = '';

      var falta = faltan();
      if (falta !== '') { avisar(falta); return; }

      copiarA('num');

      /* El plazo se toma del selector de la tarjeta propia si está en
         pantalla; si no, la compra va a un solo pago. */
      var selCuotas = document.getElementById('cuotas');
      document.getElementById('num-cuotas').value = selCuotas ? selCuotas.value : '0';

      var btn = fNum.querySelector('button[type="submit"]');
      btn.disabled = true;

      /* Los pasos, mientras tanto. Entre apretar y que conteste la red
         de tarjetas hay varios segundos, y en esos segundos el botón
         gris no dice si se mandó, si se colgó, o si conviene volver a
         apretar. Volver a apretar, aquí, es cobrar dos veces. */
      var pr = CMProceso.abrir('compra');
      pr.avanzar();

      fetch(URL_NUM, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(fNum)
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (res.ok && res.j && res.j.exito) {
            pr.listo('¡Listo! Lo llevamos a su pedido.');
            window.location = res.j.datos.redirigir;
            return;
          }

          /* El motivo se enseña en el panel y, al cerrarlo, queda
             también junto al formulario para poder releerlo. */
          pr.fallo(
            (res.j && res.j.mensaje) || 'No se pudo completar el cobro.',
            'autorizando'
          );

          /* El motivo se enseña junto al formulario, no en un aviso que
             se va solo: quien acaba de que le rechacen una tarjeta
             necesita poder releer por qué mientras corrige. */
          var m = (res.j && res.j.mensaje) || 'No se pudo completar el cobro.';
          var d = (res.j && res.j.datos) || {};

          errNum.textContent = d.ultimos4
            ? 'Tarjeta ····' + d.ultimos4 + ': ' + m
            : m;

          btn.disabled = false;
        })
        .catch(function () {
          pr.fallo('No se pudo contactar con el servidor. Revise su conexión.');
          errNum.textContent = 'No pudimos comunicarnos con el banco. Intente de nuevo.';
          btn.disabled = false;
        });
    });
  }

  // --- Pagar con cualquier tarjeta ----------------------------------
  var form = document.getElementById('form-pasarela');

  if (!form || typeof Stripe === 'undefined') { return; }

  var URL = <?= P::js($rutas->url('tienda/pagar/pasarela')) ?>;
  var URL_CONFIRMAR = <?= P::js($rutas->url('tienda/pagar/confirmar')) ?>;

  /* El token con el que se cerrará el cobro.
     El del formulario se gasta al apartar el pedido —son de un solo
     uso—, así que el servidor devuelve el reemplazo en esa misma
     respuesta y es ese el que vale después. */
  var csrfVivo = <?= P::js($csrf) ?>;
  var paso = document.getElementById('paso-tarjeta');
  var btnPagar = document.getElementById('btn-pagar');
  var btnAtras = document.getElementById('btn-volver');
  var cajaErr = document.getElementById('tarjeta-error');

  var stripe = Stripe(<?= P::js($publica) ?>);
  var campo = stripe.elements().create('card', {
    style: { base: { fontSize: '15px', color: '#16181d', fontFamily: 'Poppins, sans-serif' } },
    // El codigo postal es un dato estadounidense que aqui no
    // significa nada: el cliente no sabe que poner y el cobro
    // se le rechaza. Link abre un dialogo pidiendo correo para
    // crear cuenta en un tercero antes de dejarle pagar.
    hidePostalCode: true,
    disableLink: true
  });

  var montado = false;
  var secreto = null;
  var seguimiento = '';

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();

    var falta = faltan();
    if (falta !== '') { avisar(falta); return; }

    copiarA('pas');

    var boton = form.querySelector('[type="submit"]');
    if (boton) { boton.disabled = true; }

    /* Este tramo aparta la mercadería y abre el cobro con la
       procesadora; el panel se cierra al llegar al formulario de la
       tarjeta, porque taparlo justo cuando hay que teclear dieciséis
       dígitos no ayuda a nadie. */
    var prep = CMProceso.abrir('pasarela_apartar');
    prep.avanzar();

    fetch(URL, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) {
        // Una respuesta que no es JSON no es «no hay conexión»: es el
        // servidor devolviendo su página de error. Decirlo bien ahorra
        // buscar en el sitio equivocado.
        return r.text().then(function (txt) {
          try { return JSON.parse(txt); }
          catch (e) {
            if (window.console) { console.error('[Tienda] respuesta no JSON:', txt.slice(0, 2000)); }
            return { ok: false, mensaje: 'El servidor respondió algo que no se pudo '
              + 'interpretar (HTTP ' + r.status + '). No se realizó ningún cobro.' };
          }
        });
      })
      .then(function (j) {
        if (boton) { boton.disabled = false; }

        if (!j.ok) {
          prep.fallo(j.mensaje || 'No se pudo preparar el cobro.', 'apartando');
          return;
        }

        prep.paso('preparando');

        secreto = j.datos.secreto_cliente;
        seguimiento = j.datos.seguimiento || '';
        // El relevo viaja en la raíz de la respuesta, junto a `ok`, no
        // dentro de `datos`: ahí es donde lo pone el servidor.
        if (j.csrf) { csrfVivo = j.csrf; }
        document.getElementById('importe-final').textContent = j.datos.importe_formateado;

        form.hidden = true;
        document.getElementById('datos-entrega').hidden = true;
        paso.hidden = false;

        if (!montado) { campo.mount('#tarjeta-elemento'); montado = true; }

        prep.listo('Sus artículos quedaron apartados. Complete los datos de su tarjeta.');
      })
      .catch(function (err) {
        if (boton) { boton.disabled = false; }
        if (window.console) { console.error('[Tienda] la petición no llegó:', err); }
        prep.fallo('No se pudo contactar con el servidor. Revise su conexión.');
      });
  });

  if (btnAtras) {
    btnAtras.addEventListener('click', function () {
      paso.hidden = true;
      form.hidden = false;
      document.getElementById('datos-entrega').hidden = false;
    });
  }

  btnPagar.addEventListener('click', function () {
    if (!secreto) { return; }

    btnPagar.disabled = true;
    cajaErr.textContent = '';

    /* Aquí sí se tapa la pantalla, y a propósito: mientras la
       procesadora decide no hay nada que tocar, y lo peor que puede
       pasar es que alguien recargue a media autorización. */
    var cobro = CMProceso.abrir('pasarela_cobrar');

    stripe.confirmCardPayment(secreto, { payment_method: { card: campo } })
      .then(function (res) {
        btnPagar.disabled = false;

        if (res.error) {
          cobro.fallo(res.error.message, 'cobrando');
          cajaErr.textContent = res.error.message;
          return;
        }

        if (res.paymentIntent && res.paymentIntent.status === 'succeeded') {
          /* La pasarela cobró; ahora hay que CONFIRMAR el pedido.
           *
           * Antes se iba directo al seguimiento y el pedido se quedaba
           * apartado: el aviso de Stripe es quien lo cerraba, y a una
           * instalación sin dirección pública no llega nunca. El
           * comprador pagaba y su pedido no avanzaba.
           *
           * El servidor no se fía de esta llamada: vuelve a preguntar
           * a Stripe por el intento. */
          btnPagar.disabled = true;
          cajaErr.textContent = '';

          /* El dinero ya salió de la tarjeta: lo que queda es cerrar
             el pedido de este lado. */
          cobro.paso('confirmando');

          var cierre = new FormData();
          cierre.append('_csrf', csrfVivo);
          cierre.append('_csrf_proposito', 'tienda_publica');
          cierre.append('intento', res.paymentIntent.id);

          fetch(URL_CONFIRMAR, {
            method: 'POST',
            body: cierre,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
          })
            .then(function () {
              // Pase lo que pase, el cobro se hizo: al comprador se le
              // lleva a su pedido, que es donde ve en qué quedó.
              cobro.listo('¡Pago recibido! Lo llevamos a su pedido.');
              if (seguimiento !== '') { window.location.assign(seguimiento); }
              else { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }
            })
            .catch(function () {
              cobro.listo('Su pago se recibió. Lo llevamos a su pedido.');
              if (seguimiento !== '') { window.location.assign(seguimiento); }
              else { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }
            });
        } else {
          /* Ni cobrado ni rechazado: la procesadora lo dejó en
             proceso. No es un fallo, así que no va en rojo. */
          cobro.nota('El pago quedó en proceso. Le avisaremos por correo en cuanto se confirme.');
          cobro.cerrar();

          cajaErr.textContent = 'El pago quedó en proceso. Le avisaremos por correo '
            + 'en cuanto se confirme.';
        }
      })
      .catch(function () {
        btnPagar.disabled = false;
        cobro.fallo('No se pudo completar el pago. Intente de nuevo.', 'cobrando');
        cajaErr.textContent = 'No se pudo completar el pago. Intente de nuevo.';
      });
  });
})();
</script>

<?php $tdaCarritoFlotante($carritoUnidades ?? 0); ?>
