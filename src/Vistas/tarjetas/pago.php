<?php
/**
 * Pago de tarjeta de crédito.
 *
 * Dos decisiones del cliente, en este orden: cuánto paga y con qué.
 *
 * El monto se resuelve con los tres botones que de verdad usa la gente
 * —mínimo, total, otro— en vez de dejarle una caja vacía y que calcule
 * él. El medio son dos: debitar de su cuenta o pagar con la tarjeta de
 * otro banco a través de la pasarela.
 *
 * Los importes que se envían los vuelve a comprobar el servidor contra
 * la deuda real. Lo que se elija aquí es una comodidad, no la fuente de
 * la verdad.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array      $tarjeta
 * @var array|null $corte
 * @var int        $minimo_cent
 * @var array      $cuentas
 * @var array      $posteriores
 * @var bool       $pasarela
 * @var string     $csrf
 * @var string     $csrfPasarela
 */
use CashMan\Http\Plantilla as P;

$saldo = (int) $tarjeta['saldo_cent'];
$deuda = $saldo > 0;

$vencida = $corte !== null
    && $corte['fecha_limite_pago'] !== null
    && $corte['fecha_limite_pago'] < date('Y-m-d')
    && $deuda;
?>

<div class="row">
  <div class="col-lg-7">

    <!-- ---------------- Resumen del estado de cuenta ---------------- -->
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">
          <?= P::e(mb_strtoupper((string) $tarjeta['marca'])) ?>
          ····<?= P::e($tarjeta['ultimos4']) ?>
        </h4>
        <?php if ($vencida): ?>
        <span class="badge badge-danger">Fecha límite vencida</span>
        <?php elseif (!$deuda): ?>
        <span class="badge badge-success">Al día</span>
        <?php endif; ?>
      </div>

      <div class="card-body">
        <?php if (!$deuda): ?>
          <div class="cm-vacio">
            <?= cm_icono('check', 40) ?>
            <p class="mb-0 mt-2">
              Esta tarjeta no tiene saldo pendiente. No hay nada que pagar.
            </p>
          </div>
        <?php else: ?>

        <div class="row text-center mb-3">
          <div class="col-md-4">
            <small class="text-muted d-block">Saldo actual</small>
            <h3 class="mb-0 cm-neg"><?= P::e(P::money($saldo)) ?></h3>
          </div>
          <div class="col-md-4">
            <small class="text-muted d-block">Pago mínimo</small>
            <h3 class="mb-0 text-primary"><?= P::e(P::money($minimo_cent)) ?></h3>
          </div>
          <div class="col-md-4">
            <small class="text-muted d-block">Fecha límite</small>
            <h3 class="mb-0" style="font-size:1.2rem">
              <?= $corte !== null && $corte['fecha_limite_pago'] !== null
                  ? P::e(P::fecha((string) $corte['fecha_limite_pago'], 'd/m/Y'))
                  : '—' ?>
            </h3>
          </div>
        </div>

        <?php if ($corte !== null): ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <tbody>
              <tr>
                <td>Corte del <?= P::e(P::fecha((string) $corte['fecha_corte'], 'd/m/Y')) ?></td>
                <td class="text-right cm-mono cm-nowrap"><?= P::e(P::money((int) $corte['saldo_final_cent'])) ?></td>
              </tr>
              <tr>
                <td class="text-muted">Consumos del periodo</td>
                <td class="text-right cm-mono cm-nowrap"><?= P::e(P::money((int) $corte['consumos_cent'])) ?></td>
              </tr>
              <tr>
                <td class="text-muted">Intereses</td>
                <td class="text-right cm-mono cm-nowrap"><?= P::e(P::money((int) $corte['intereses_cent'])) ?></td>
              </tr>
              <tr>
                <td class="text-muted">Seguro de vida</td>
                <td class="text-right cm-mono cm-nowrap"><?= P::e(P::money((int) $corte['seguro_vida_cent'])) ?></td>
              </tr>
              <tr>
                <td class="text-muted">Seguro de desempleo</td>
                <td class="text-right cm-mono cm-nowrap"><?= P::e(P::money((int) $corte['seguro_empleo_cent'])) ?></td>
              </tr>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="alert alert-light border mb-0" style="font-size:.84rem">
          Esta tarjeta todavía no tiene un corte generado, así que no hay pago
          mínimo calculado. Puede abonar el monto que desee sobre su saldo.
        </div>
        <?php endif; ?>

        <?php if ($posteriores !== []): ?>
        <h5 class="mt-4 mb-2" style="font-size:.95rem">Movimientos aún no facturados</h5>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <tbody>
              <?php foreach ($posteriores as $m): ?>
              <tr>
                <td class="cm-nowrap" style="font-size:.8rem">
                  <?= P::e(P::fecha((string) $m['creado_en'], 'd/m/Y')) ?>
                </td>
                <td style="font-size:.82rem">
                  <?= P::e($m['comercio'] ?: ucfirst((string) $m['tipo'])) ?>
                </td>
                <td class="text-right cm-mono cm-nowrap <?= $m['tipo'] === 'pago' ? 'text-success' : '' ?>">
                  <?= $m['tipo'] === 'pago' ? '−' : '' ?><?= P::e(P::money((int) $m['importe_cent'])) ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <small class="text-muted d-block mt-2" style="font-size:.76rem">
          Entrarán en su próximo corte.
        </small>
        <?php endif; ?>

        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ---------------- Formulario de pago ---------------- -->
  <?php if ($deuda): ?>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">¿Cuánto desea pagar?</h4></div>
      <div class="card-body">

<?php
/* --- Cómo va el pago del mes ------------------------------------------
   El mínimo es del PERIODO: lo que decide si hay mora es cuánto se
   abonó desde el corte. Enseñar el mínimo entero a quien ya pagó parte
   es no decirle lo único que necesita saber: cuánto le falta. */
$pm = $pagoMes ?? ['hay_corte' => false];
?>
<?php if (!empty($pm['hay_corte'])): ?>
<?php
  $pmClase = $pm['cubierto'] ? 'bien' : ($pm['vencido'] ? 'grave' : 'ojo');
  $pmPct   = (int) $pm['minimo_cent'] > 0
      ? min(100, (int) round((int) $pm['abonado_cent'] * 100 / (int) $pm['minimo_cent']))
      : 100;
?>
<div class="cm-pagomes cm-pagomes--<?= $pmClase ?>">
  <div class="cm-pagomes__alto">
    <strong>
      <?php if ($pm['cubierto']): ?>
        Pago mínimo cubierto
      <?php elseif ($pm['vencido']): ?>
        El pago mínimo venció
      <?php else: ?>
        Le falta para el pago mínimo
      <?php endif; ?>
    </strong>
    <span class="cm-pagomes__cifra">
      <?= P::e(P::money((int) $pm['abonado_cent'])) ?>
      de <?= P::e(P::money((int) $pm['minimo_cent'])) ?>
    </span>
  </div>

  <div class="cm-pagomes__barra">
    <span style="width:<?= $pmPct ?>%"></span>
  </div>

  <p class="cm-pagomes__texto">
    <?php if ($pm['cubierto']): ?>
      Ya abonó el mínimo de este corte. Si además paga
      <strong><?= P::e(P::money((int) $pm['contado_cent'])) ?></strong> antes del
      <?= P::e(P::fecha((string) $pm['fecha_limite'], 'd/m/Y')) ?>, los consumos del
      periodo no generan ningún interés.
    <?php elseif ($pm['vencido']): ?>
      Faltaron <strong><?= P::e(P::money((int) $pm['faltante_cent'])) ?></strong> y la
      fecha límite (<?= P::e(P::fecha((string) $pm['fecha_limite'], 'd/m/Y')) ?>) ya pasó.
      El saldo vencido genera intereses moratorios y el atraso queda en su historial
      crediticio. Abone cuanto antes para detenerlo.
    <?php else: ?>
      Ha abonado <strong><?= P::e(P::money((int) $pm['abonado_cent'])) ?></strong>, pero el
      mínimo de este corte es <strong><?= P::e(P::money((int) $pm['minimo_cent'])) ?></strong>:
      le faltan <strong><?= P::e(P::money((int) $pm['faltante_cent'])) ?></strong>.
      Tiene hasta el <strong><?= P::e(P::fecha((string) $pm['fecha_limite'], 'd/m/Y')) ?></strong><?php
        if ((int) $pm['dias'] >= 0): ?> &mdash; <?= (int) $pm['dias'] ?> día(s)<?php endif; ?>.
      Si no lo completa, la tarjeta entra en mora: intereses moratorios sobre lo vencido
      y el atraso en su historial crediticio.
    <?php endif; ?>
  </p>
</div>
<?php endif; ?>

        <div class="cm-opciones" role="group" aria-label="Monto a pagar">
          <?php if ($minimo_cent > 0): ?>
          <button type="button" class="cm-opcion" data-monto="<?= number_format($minimo_cent / 100, 2, '.', '') ?>">
            <span>Pago mínimo</span>
            <b><?= P::e(P::money($minimo_cent)) ?></b>
          </button>
          <?php endif; ?>

          <button type="button" class="cm-opcion cm-opcion--activa" data-monto="<?= number_format($saldo / 100, 2, '.', '') ?>">
            <span>Saldo total</span>
            <b><?= P::e(P::money($saldo)) ?></b>
            <small>evita intereses</small>
          </button>

          <button type="button" class="cm-opcion" data-monto="">
            <span>Otro monto</span>
            <b>Yo lo indico</b>
          </button>
        </div>

        <div class="form-group mt-3">
          <label for="monto">Monto a pagar</label>
          <div class="input-group">
            <div class="input-group-prepend"><span class="input-group-text">$</span></div>
            <input type="text" class="form-control" id="monto" name="monto"
                   inputmode="decimal" autocomplete="off"
                   value="<?= number_format($saldo / 100, 2, '.', '') ?>">
          </div>
          <small class="form-text text-muted">
            No puede exceder el saldo de <?= P::e(P::money($saldo)) ?>.
          </small>

          <?php /* El aviso cambia con lo que se escribe. Va aquí, pegado
                   al campo, y no en un mensaje al confirmar: para que
                   sirva tiene que leerse ANTES de decidir la cifra. */ ?>
          <div id="aviso-monto" class="cm-aviso-pago d-none" role="status"></div>
        </div>

        <hr>
        <h5 class="mb-3" style="font-size:.95rem">¿Con qué desea pagarlo?</h5>

        <!-- ---------- Medio 1: debitar de una cuenta ---------- -->
        <form id="form-cuenta" data-cm-ajax method="post"
              action="<?= P::attr($rutas->url('tarjetas/' . $tarjeta['codigo'] . '/pagar')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="tarjeta">
          <input type="hidden" name="monto" id="monto-cuenta" value="">

          <?php if ($cuentas === []): ?>
            <div class="alert alert-light border" style="font-size:.84rem">
              No hay cuentas activas con las que debitar. Puede pagar con la
              tarjeta de otro banco.
            </div>
          <?php else: ?>
            <div class="form-group">
              <label for="cuenta">Debitar de mi cuenta</label>
              <select class="form-control" id="cuenta" name="cuenta">
                <?php foreach ($cuentas as $c): ?>
                <option value="<?= P::attr($c['codigo']) ?>">
                  <?= P::e($c['numero_cuenta']) ?> · <?= P::e($c['producto']) ?>
                  — disponible <?= P::e(P::money((int) $c['disponible_cent'])) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <button type="submit" class="btn btn-primary btn-block">
              <?= cm_icono('wallet', 16) ?> Pagar debitando de mi cuenta
            </button>
          <?php endif; ?>
        </form>

        <!-- ---------- Medio 2: tarjeta de otro banco ---------- -->
        <?php if ($pasarela): ?>
        <div class="cm-separador"><span>o</span></div>

        <form id="form-pasarela">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrfPasarela) ?>">
          <input type="hidden" name="_csrf_proposito" value="pasarela">
          <input type="hidden" name="concepto" value="saldo_tarjeta">
          <input type="hidden" name="tarjeta" value="<?= P::attr($tarjeta['codigo']) ?>">
          <input type="hidden" name="importe" id="monto-pasarela" value="">

          <button type="submit" class="btn btn-outline-primary btn-block">
            <?= cm_icono('card', 16) ?> Pagar con la tarjeta de otro banco
          </button>
        </form>

        <div id="paso-tarjeta" class="mt-3" hidden>
          <p class="text-muted" style="font-size:.82rem">
            Se cobrarán <strong id="importe-final">—</strong> a la tarjeta que
            indique. Sus datos viajan cifrados a la procesadora; nosotros no los
            recibimos ni los guardamos.
          </p>
          <div id="tarjeta-elemento" class="cm-stripe-caja"></div>
          <div id="tarjeta-error" class="text-danger mt-2" style="font-size:.82rem"></div>

          <button class="btn btn-success btn-block mt-3" id="btn-pagar">
            <?= cm_icono('lock', 16) ?> Confirmar el pago
          </button>
          <button class="btn btn-link btn-block" id="btn-volver" type="button">Cancelar</button>
        </div>
        <?php else: ?>
        <small class="text-muted d-block mt-3" style="font-size:.78rem">
          El pago con tarjeta de otro banco no está disponible en este momento.
        </small>
        <?php endif; ?>

      </div>
    </div>

    <div class="alert alert-light border" style="font-size:.82rem">
      <strong>Sobre los intereses.</strong> Si paga el saldo total antes de la
      fecha límite, el periodo no genera intereses. Si paga solo el mínimo, el
      resto se financia a la tasa de su tarjeta.
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($deuda): ?>
<?php if ($pasarela): ?><script src="https://js.stripe.com/v3/"></script><?php endif; ?>
<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var monto    = document.getElementById('monto');
  var SALDO    = <?= (int) $saldo ?> / 100;
  var MINIMO   = <?= (int) $minimo_cent ?> / 100;
  var TASA     = <?= (int) ($tarjeta['tasa_bp'] ?? 4400) ?> / 100;
  var opciones = document.querySelectorAll('.cm-opcion');

  /* ---------- Qué pasa según cuánto se pague ----------
     La pantalla dejaba escribir cualquier cifra y solo se quejaba si
     pasaba del saldo: pagar menos del mínimo se aceptaba sin una
     palabra, y el cliente se enteraba al mes siguiente con el recargo
     encima. El aviso se actualiza mientras escribe, porque para que
     sirva tiene que leerse antes de decidir. */
  var avisoCaja = document.getElementById('aviso-monto');

  function dinero(n) {
    return '$' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function avisarSegunMonto() {
    if (!avisoCaja) { return; }

    var v = parseFloat(String(monto.value).replace(/[^0-9.]/g, ''));

    if (!v || v <= 0) { avisoCaja.classList.add('d-none'); return; }

    var clase, texto;

    if (MINIMO > 0 && v < MINIMO - 0.005) {
      clase = 'cm-aviso-pago--grave';
      texto = '<strong>Esto es menos que el pago mínimo.</strong> Le faltan '
        + dinero(MINIMO - v) + ' para cubrirlo. Si al '
        + <?= P::js(P::fecha((string) ($corte['fecha_limite_pago'] ?? ''), 'd/m/Y')) ?>
        + ' no ha pagado al menos ' + dinero(MINIMO)
        + ', la tarjeta entra en mora: se cobran intereses moratorios sobre lo '
        + 'vencido, puede restringirse el uso de la línea y el atraso queda en su '
        + 'historial crediticio.';
    } else if (v < SALDO - 0.005) {
      var resto = SALDO - v;
      var mes   = resto * (TASA / 100) / 12;

      clase = 'cm-aviso-pago--ojo';
      texto = '<strong>No cubre el saldo total.</strong> Quedarían '
        + dinero(resto) + ' financiados, que desde el próximo corte generan '
        + 'intereses al ' + TASA.toFixed(2) + '% anual: alrededor de '
        + dinero(mes) + ' el primer mes, y más mientras no se liquide. '
        + 'No entra en mora, pero deja de ser gratis.';
    } else {
      clase = 'cm-aviso-pago--bien';
      texto = '<strong>Cubre el saldo total.</strong> Los consumos de este periodo '
        + 'no generan ningún interés.';
    }

    avisoCaja.className = 'cm-aviso-pago ' + clase;
    avisoCaja.innerHTML = texto;
  }

  monto.addEventListener('input', avisarSegunMonto);
  avisarSegunMonto();

  /* Los tres botones solo rellenan la caja: así el cliente ve siempre la
     cifra exacta que va a pagar antes de confirmar, y puede corregirla. */
  opciones.forEach(function (b) {
    b.addEventListener('click', function () {
      opciones.forEach(function (o) { o.classList.remove('cm-opcion--activa'); });
      b.classList.add('cm-opcion--activa');

      var v = b.getAttribute('data-monto');
      if (v === '') { monto.value = ''; monto.focus(); }
      else { monto.value = v; }

      avisarSegunMonto();
    });
  });

  function montoValido() {
    var v = parseFloat(String(monto.value).replace(/[^0-9.]/g, ''));

    if (!v || v <= 0) {
      window.CMAviso.error('Indique cuánto desea pagar.');
      return null;
    }
    if (v > SALDO + 0.001) {
      window.CMAviso.error('El monto no puede exceder el saldo de la tarjeta.');
      return null;
    }

    /* Pagar por debajo del mínimo se permite —es dinero suyo y abonar
       algo es mejor que no abonar nada— pero no en silencio: se le dice
       lo que acarrea antes de cobrarlo, no después. */
    if (MINIMO > 0 && v < MINIMO - 0.005) {
      window.CMAviso.alerta(
        'Menos que el pago mínimo',
        'Está por abonar ' + dinero(v) + ', y el mínimo de este corte es '
        + dinero(MINIMO) + '. Esto no evita la mora: el saldo vencido generará '
        + 'intereses moratorios y el atraso quedará en su historial. '
        + 'Puede continuar si así lo desea.'
      );
    }

    return v.toFixed(2);
  }

  /* ---------- Debitar de la cuenta ---------- */
  var formCuenta = document.getElementById('form-cuenta');

  if (formCuenta) {
    formCuenta.addEventListener('submit', function (ev) {
      var v = montoValido();
      if (v === null) { ev.preventDefault(); ev.stopImmediatePropagation(); return; }
      document.getElementById('monto-cuenta').value = v;
    }, true);
  }

  /* ---------- Tarjeta de otro banco ---------- */
  var formPas = document.getElementById('form-pasarela');
  if (!formPas || typeof Stripe === 'undefined') { return; }

  var URL_INICIAR   = <?= P::js($rutas->url('pagar/iniciar')) ?>;
  var URL_CONFIRMAR = <?= P::js($rutas->url('pagar/confirmar')) ?>;
  var CSRF_PASARELA = <?= P::js($csrfPasarela ?? '') ?>;
  var pasoTar  = document.getElementById('paso-tarjeta');
  var btnPagar = document.getElementById('btn-pagar');
  var cajaErr  = document.getElementById('tarjeta-error');

  var stripe   = Stripe(<?= P::js(\CashMan\Infraestructura\Pasarela\Stripe::clavePublica()) ?>);
  var elements = stripe.elements();
  var campo    = elements.create('card', {
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

  formPas.addEventListener('submit', function (ev) {
    ev.preventDefault();

    var v = montoValido();
    if (v === null) { return; }

    document.getElementById('monto-pasarela').value = v;

    fetch(URL_INICIAR, {
      method: 'POST',
      body: new FormData(formPas),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { window.CMAviso.error(j.mensaje || 'No se pudo preparar el cobro.'); return; }

        secreto = j.datos.secreto_cliente;
        document.getElementById('importe-final').textContent = j.datos.importe_formateado;

        formPas.hidden  = true;
        pasoTar.hidden  = false;

        if (!montado) { campo.mount('#tarjeta-elemento'); montado = true; }
      })
      .catch(function () { window.CMAviso.error('No se pudo contactar con el servidor.'); });
  });

  document.getElementById('btn-volver').addEventListener('click', function () {
    pasoTar.hidden = true;
    formPas.hidden = false;
  });

  btnPagar.addEventListener('click', function () {
    if (!secreto) { return; }

    btnPagar.disabled = true;
    cajaErr.textContent = '';

    stripe.confirmCardPayment(secreto, { payment_method: { card: campo } })
      .then(function (res) {
        btnPagar.disabled = false;

        if (res.error) { cajaErr.textContent = res.error.message; return; }
        if (res.paymentIntent && res.paymentIntent.status === 'succeeded') {
          /* El cobro ya se hizo; ahora hay que APLICARLO.
             Antes se avisaba «se reflejará cuando la procesadora lo
             confirme» y se dejaba al webhook, que en una instalación
             sin acceso desde fuera no llega: el dinero salía y el saldo
             no se movía. Se le pide al servidor que lo verifique contra
             la pasarela y lo aplique. */
          btnPagar.disabled = true;

          var fd = new FormData();
          fd.append('_csrf', CSRF_PASARELA);
          fd.append('_csrf_proposito', 'pasarela');
          fd.append('intento', res.paymentIntent.id);

          fetch(URL_CONFIRMAR, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
          })
            .then(function (r) { return r.json(); })
            .then(function (j) {
              window.CMAviso[j.ok ? 'exito' : 'alerta'](
                j.ok ? 'Pago aplicado' : 'Pago recibido',
                j.mensaje || 'Su pago se registró correctamente.'
              );

              setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1600);
            })
            .catch(function () {
              window.CMAviso.alerta(
                'Pago recibido',
                'El cobro se realizó. Si no ve el saldo actualizado en unos minutos, '
                + 'comuníquese con nosotros indicando la referencia.'
              );
            });
        } else {
          window.CMAviso.info('El pago quedó en proceso. Le avisaremos al confirmarse.');
        }
      })
      .catch(function () {
        btnPagar.disabled = false;
        cajaErr.textContent = 'No se pudo completar el pago. Intente de nuevo.';
      });
  });
})();
</script>
<?php endif; ?>
