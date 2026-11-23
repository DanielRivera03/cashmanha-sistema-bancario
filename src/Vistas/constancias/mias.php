<?php
/**
 * Pedir y ver constancias.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $tipos
 * @var array $constancias
 * @var array $cuentas
 * @var array $creditos
 * @var string $csrf
 */

use CashMan\Http\Plantilla as P;
?>
<div class="row">

  <div class="col-lg-5 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Pedir una constancia</h4></div>
      <div class="card-body">

        <?php if ($cuentas === []): ?>
          <div class="alert alert-warning mb-0">
            Necesita una cuenta activa para pedir constancias.
          </div>
        <?php else: ?>

        <form id="form-constancia">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="constancias">

          <div class="form-group">
            <label for="tipo">¿Qué necesita certificar?</label>
            <select class="form-control" id="tipo" name="tipo" required>
              <?php foreach ($tipos as $t): ?>
              <option value="<?= P::attr($t['tipo']) ?>"
                      data-comision="<?= (int) $t['comision_cent'] ?>"
                      data-dias="<?= (int) $t['dias_vigencia'] ?>"
                      data-objeto="<?= P::attr(match ($t['tipo']) {
                          'saldo'     => 'cuenta',
                          'solvencia' => 'credito',
                          default     => '',
                      }) ?>"
                      data-desc="<?= P::attr($t['descripcion']) ?>">
                <?= P::e($t['nombre']) ?>
                <?= (int) $t['comision_cent'] > 0
                    ? ' — $' . number_format((int) $t['comision_cent'] / 100, 2)
                    : ' — sin costo' ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small class="form-text text-muted" id="desc-tipo"></small>
          </div>

          <div class="form-group d-none" id="grupo-cuenta">
            <label for="obj-cuenta">¿Sobre cuál cuenta?</label>
            <select class="form-control" id="obj-cuenta">
              <?php foreach ($cuentas as $c): ?>
              <option value="<?= P::attr($c['codigo']) ?>">
                <?= P::e($c['numero_cuenta']) ?> &mdash; <?= P::e($c['producto']) ?>
                ($<?= number_format((int) $c['saldo_cent'] / 100, 2) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group d-none" id="grupo-credito">
            <label for="obj-credito">¿Sobre cuál crédito?</label>
            <?php if ($creditos === []): ?>
              <div class="alert alert-info mb-0" style="font-size:.86rem">
                No tiene créditos sobre los que certificar solvencia.
              </div>
            <?php else: ?>
            <select class="form-control" id="obj-credito">
              <?php foreach ($creditos as $c): ?>
              <option value="<?= P::attr($c['codigo']) ?>">
                <?= P::e($c['folio']) ?> &mdash; <?= P::e($c['producto']) ?>
                ($<?= number_format((int) $c['saldo_capital_cent'] / 100, 2) ?>)
              </option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label for="dirigida_a">¿A quién va dirigida?</label>
            <input type="text" class="form-control" id="dirigida_a" name="dirigida_a"
                   maxlength="160" placeholder="Embajada, patrono, otro banco…">
            <small class="form-text text-muted">
              Muchas instituciones exigen que la constancia las nombre. Si lo deja en
              blanco, saldrá «a quien interese».
            </small>
          </div>

          <div class="form-group">
            <label for="proposito">¿Para qué la necesita?</label>
            <input type="text" class="form-control" id="proposito" name="proposito"
                   maxlength="255" placeholder="Trámite de visa, solicitud de empleo…">
          </div>

          <div class="form-group" id="grupo-cargo">
            <label class="d-block">¿Cómo desea pagar el arancel?</label>

            <div class="btn-group btn-group-toggle d-flex mb-2" role="group">
              <label class="btn btn-outline-primary flex-fill active" id="eti-pago-cuenta">
                <input type="radio" name="forma_pago" value="cuenta" checked
                       class="cm-oculto-radio"> De mi cuenta
              </label>
              <label class="btn btn-outline-primary flex-fill<?= $tarjetas === [] ? ' disabled' : '' ?>"
                     id="eti-pago-tarjeta">
                <input type="radio" name="forma_pago" value="tarjeta"
                       <?= $tarjetas === [] ? 'disabled' : '' ?>
                       class="cm-oculto-radio"> Con mi tarjeta
              </label>
            </div>

            <div id="pago-cuenta">
              <select class="form-control" id="cuenta_cargo" name="cuenta_cargo">
                <?php foreach ($cuentas as $c): ?>
                <option value="<?= P::attr($c['codigo']) ?>" data-disp="<?= (int) $c['disponible_cent'] ?>">
                  <?= P::e($c['numero_cuenta']) ?>
                  (disponible $<?= number_format((int) $c['disponible_cent'] / 100, 2) ?>)
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div id="pago-tarjeta" class="d-none">
              <?php if ($tarjetas === []): ?>
              <p class="text-muted mb-0" style="font-size:.86rem">
                No tiene ninguna tarjeta CashMan H.A. activa.
              </p>
              <?php else: ?>
              <select class="form-control" id="tarjeta" name="tarjeta">
                <?php foreach ($tarjetas as $t): ?>
                <option value="<?= P::attr($t['codigo']) ?>">
                  <?= P::e((string) ($t['alias'] !== null && $t['alias'] !== ''
                        ? $t['alias'] : $t['producto'])) ?>
                  ••••<?= P::e((string) $t['ultimos4']) ?>
                  (<?= P::e((string) $t['tipo']) ?>)
                </option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>

              <!-- La regla que más se pregunta en ventanilla, dicha donde
                   se decide, y no después de un rechazo. -->
              <small class="form-text text-muted">
                Solo tarjetas CashMan H.A. No aceptamos tarjetas de otros bancos
                para este cobro.
              </small>
            </div>

            <small class="form-text text-muted mt-2">
              ¿Prefiere pagar en efectivo? Pídala en cualquier agencia: la preparan
              en atención al cliente y usted la paga en caja.
            </small>
          </div>

          <button type="submit" class="btn btn-primary btn-block" id="btn-emitir">
            Emitir constancia
          </button>
        </form>

        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Qué las hace valer</h4></div>
      <div class="card-body" style="font-size:.88rem">
        <p>Cada constancia lleva un <strong>código de verificación</strong>. Quien la
           reciba puede comprobar en nuestra página que es auténtica, de quién es y
           qué decía.</p>

        <p>Un PDF se falsifica en diez minutos. El código, no: por eso lo que vale
           es el código y el papel es solo su copia.</p>

        <p class="mb-0 text-muted">Las constancias caducan. Un saldo de hace ocho meses
           no dice nada de hoy, y una institución que lo acepte estaría engañándose.</p>
      </div>
    </div>
  </div>

  <div class="col-lg-7 col-md-12">
    <?php
    /* Lo que el cliente tiene esperándole en ventanilla. Va arriba del
       todo y solo cuando hay algo: si pidió una constancia en agencia y
       no pasó a pagarla, lo que necesita ver al entrar es el folio y
       cuánto le queda de plazo, no su historial. */
    $enCaja = array_values(array_filter(
        $solicitudes,
        static fn(array $x): bool => $x['estado'] === 'pendiente_pago'
    ));
    ?>
    <?php if ($enCaja !== []): ?>
    <div class="card border-warning">
      <div class="card-header">
        <h4 class="card-title mb-0">Le esperan en caja</h4>
      </div>
      <div class="card-body">
        <p class="text-muted" style="font-size:.88rem">
          Pase a cualquier agencia con el folio y pague en ventanilla. La constancia
          se le entrega ahí mismo.
        </p>

        <?php foreach ($enCaja as $sol): ?>
        <?php $horas = max(0, (int) floor(((int) $sol['minutos_restantes']) / 60)); ?>
        <div class="border rounded p-3 mb-2">
          <div class="d-flex justify-content-between align-items-start flex-wrap">
            <div>
              <strong><?= P::e((string) $sol['tipo_nombre']) ?></strong>
              <br><span class="cm-mono"><?= P::e((string) $sol['folio']) ?></span>
            </div>
            <div class="text-right">
              <strong>$<?= number_format((int) $sol['comision_cent'] / 100, 2) ?></strong>
              <br><small class="text-muted">
                <?= $horas > 0
                      ? 'quedan ' . $horas . ($horas === 1 ? ' hora' : ' horas')
                      : 'vence en menos de una hora' ?>
              </small>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Constancias emitidas</h4></div>
      <div class="card-body">
        <div id="lista-constancias"></div>
      </div>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var form = document.getElementById('form-constancia');

  var URL_EMITIR = <?= P::js($rutas->url('constancias/emitir')) ?>;
  var BASE_PDF   = <?= P::js($rutas->url('constancias/')) ?>;

  var CSRF_SEMILLA = <?= P::js($csrf) ?>;

  function tokenVigente() {
    return (window.CMToken && window.CMToken.de('constancias')) || CSRF_SEMILLA;
  }

  var CONSTANCIAS = JSON.parse(<?= P::js(json_encode($constancias, JSON_UNESCAPED_UNICODE)) ?>);

  function esc(t) {
    return String(t == null ? '' : t)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // --- La lista --------------------------------------------------------
  function pintar(lista) {
    CONSTANCIAS = lista || [];

    var caja = document.getElementById('lista-constancias');

    if (!CONSTANCIAS.length) {
      caja.innerHTML = '<p class="text-muted mb-0">Todavía no ha pedido ninguna constancia.</p>';
      return;
    }

    var hoy = new Date().toISOString().slice(0, 10);
    var html = '';

    CONSTANCIAS.forEach(function (c) {
      var vencida = c.vigente_hasta < hoy;
      var anulada = c.estado === 'anulada';

      html += '<div class="border rounded p-3 mb-3">'
        + '<div class="d-flex justify-content-between align-items-start flex-wrap">'
        + '<div><strong>' + esc(c.tipo_nombre) + '</strong>'
        + '<br><small class="text-muted">folio ' + esc(c.folio)
        + ' · emitida el ' + esc(String(c.emitida_en).slice(0, 10)) + '</small>'
        + (c.dirigida_a ? '<br><small class="text-muted">para ' + esc(c.dirigida_a) + '</small>' : '')
        + '</div>'
        + '<div class="text-right">'
        + (anulada
            ? '<span class="badge badge-danger">anulada</span>'
            : (vencida
                ? '<span class="badge badge-secondary">vencida</span>'
                : '<span class="badge badge-success">vigente</span>'))
        + '<br><small class="text-muted">hasta ' + esc(c.vigente_hasta) + '</small>'
        + '</div></div>'

        /* El código, grande y copiable: es lo que el cliente tiene que
           dictarle a quien recibe el documento. */
        + '<div class="mt-2 p-2 bg-light rounded text-center">'
        + '<small class="text-muted d-block">Código de verificación</small>'
        + '<span class="cm-mono" style="font-size:1.1rem;letter-spacing:.12em">'
        + esc(String(c.codigo_verificacion).replace(/(.{4})/g, '$1 ').trim())
        + '</span></div>'

        + '<div class="mt-2">'
        + '<a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="'
        + esc(BASE_PDF + c.folio + '/pdf') + '">Descargar PDF</a>'
        + '</div>'

        + '</div>';
    });

    caja.innerHTML = html;
  }

  pintar(CONSTANCIAS);

  if (!form) { return; }

  // --- Qué objeto pide cada tipo ---------------------------------------
  var tipo = document.getElementById('tipo');

  function ajustar() {
    var o = tipo.options[tipo.selectedIndex];
    if (!o) { return; }

    var necesita = o.getAttribute('data-objeto') || '';

    document.getElementById('grupo-cuenta').classList.toggle('d-none', necesita !== 'cuenta');
    document.getElementById('grupo-credito').classList.toggle('d-none', necesita !== 'credito');

    document.getElementById('desc-tipo').textContent = o.getAttribute('data-desc') || '';

    /* Si no cuesta nada, no se pregunta de dónde cobrarlo. */
    var comision = parseInt(o.getAttribute('data-comision'), 10) || 0;
    document.getElementById('grupo-cargo').classList.toggle('d-none', comision === 0);

    document.getElementById('btn-emitir').textContent = comision > 0
      ? 'Emitir por $' + (comision / 100).toFixed(2)
      : 'Emitir constancia';
  }

  tipo.addEventListener('change', ajustar);
  ajustar();

  // --- Cuenta o tarjeta ------------------------------------------------
  //
  // El bloque que no se usa se ESCONDE y además se desactiva. Esconderlo
  // a secas dejaría su <select> dentro del FormData, y el servidor
  // recibiría una tarjeta elegida cuando el cliente dijo «de mi cuenta».
  var radios = form.querySelectorAll('input[name="forma_pago"]');

  function ajustarPago() {
    var forma = 'cuenta';

    Array.prototype.forEach.call(radios, function (r) {
      if (r.checked) { forma = r.value; }
    });

    var cuenta  = document.getElementById('pago-cuenta');
    var tarjeta = document.getElementById('pago-tarjeta');

    cuenta.classList.toggle('d-none', forma !== 'cuenta');
    tarjeta.classList.toggle('d-none', forma !== 'tarjeta');

    var selCuenta  = document.getElementById('cuenta_cargo');
    var selTarjeta = document.getElementById('tarjeta');

    if (selCuenta)  { selCuenta.disabled  = forma !== 'cuenta'; }
    if (selTarjeta) { selTarjeta.disabled = forma !== 'tarjeta'; }

    var etiCuenta  = document.getElementById('eti-pago-cuenta');
    var etiTarjeta = document.getElementById('eti-pago-tarjeta');

    if (etiCuenta)  { etiCuenta.classList.toggle('active', forma === 'cuenta'); }
    if (etiTarjeta) { etiTarjeta.classList.toggle('active', forma === 'tarjeta'); }
  }

  Array.prototype.forEach.call(radios, function (r) {
    r.addEventListener('change', ajustarPago);
  });

  ajustarPago();

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();

    var o = tipo.options[tipo.selectedIndex];
    var necesita = o.getAttribute('data-objeto') || '';

    var boton = document.getElementById('btn-emitir');
    boton.disabled = true;

    var fd = new FormData(form);
    fd.set('_csrf', tokenVigente());

    if (necesita === 'cuenta') {
      fd.set('objeto', document.getElementById('obj-cuenta').value);
      fd.set('objeto_espacio', 'cuenta');
    } else if (necesita === 'credito') {
      var sel = document.getElementById('obj-credito');

      if (!sel) {
        boton.disabled = false;
        window.avisar('Sin créditos',
          'No tiene créditos sobre los que certificar solvencia.', 'alerta');
        return;
      }

      fd.set('objeto', sel.value);
      fd.set('objeto_espacio', 'credito');
    }

    fetch(URL_EMITIR, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        boton.disabled = false;
        ajustar();

        if (!j.ok) { window.avisar('No se pudo emitir', j.mensaje, 'critico'); return; }

        var d = j.datos || {};

        window.avisar('Constancia emitida',
          j.mensaje + ' Código de verificación: ' + (d.codigo || ''), 'exito');

        if (d.constancias) { pintar(d.constancias); }

        form.reset();
        ajustar();
        ajustarPago();

        if (window.Vivo) { window.Vivo.refrescar(); }
      })
      .catch(function () {
        boton.disabled = false;
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  });
})();
</script>
