<?php
/**
 * Constancias por cobrar en ventanilla.
 *
 * Es la otra mitad del trámite: atención al cliente prepara la
 * solicitud y el cliente llega aquí con el folio. El cajero lo busca,
 * cobra y la constancia nace en ese momento.
 *
 * LO QUE ESTA PANTALLA ENSEÑA ANTES DE COBRAR
 *
 * El nombre y el documento del cliente. Quien paga tiene que ser quien
 * pidió la constancia —lleva sus saldos dentro—, y el cajero no tiene
 * otra forma de comprobarlo que mirar el DUI que le ponen en el
 * mostrador.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $pendientes
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;
?>

<div class="row">
  <div class="col-xl-5 col-lg-6 col-md-12">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title mb-0">Cobrar una constancia</h4>
      </div>
      <div class="card-body">
        <form id="form-cobro">
          <div class="form-group">
            <label for="folio">Folio de la solicitud</label>
            <input type="text" class="form-control cm-mono" id="folio" name="folio"
                   placeholder="SCT-261113-XXXXXXXXXX" autocomplete="off"
                   maxlength="24" required>
            <small class="form-text text-muted">
              Se lo dictó el cliente o se lo enseña en su teléfono.
            </small>
          </div>

          <div class="form-group">
            <label for="medio">¿Con qué paga?</label>
            <select class="form-control" id="medio" name="medio">
              <option value="efectivo">Efectivo</option>
              <option value="tarjeta">Tarjeta CashMan H.A.</option>
              <option value="cheque">Cheque</option>
            </select>
            <small class="form-text text-muted">
              El efectivo entra en su arqueo; el cheque se remesa y no se cuenta
              como billete en la gaveta.
            </small>
          </div>

          <button type="submit" class="btn btn-primary btn-block" id="btn-cobrar">
            Cobrar y emitir
          </button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h4 class="card-title mb-0">Antes de cobrar</h4>
      </div>
      <div class="card-body" style="font-size:.88rem">
        <p>Compruebe que <strong>quien paga es quien pidió la constancia</strong>.
           El documento lleva sus saldos dentro; entregárselo a otra persona es
           entregarle los saldos de un cliente.</p>

        <p>La constancia <strong>nace al cobrarla</strong>, no antes. Si el cobro
           falla, no queda ningún documento suelto.</p>

        <p class="mb-0 text-muted">Una solicitud sin pagar caduca. Pasado su plazo
           certificaría los datos de otro día, así que hay que pedirla de nuevo
           —sin costo de trámite adicional.</p>
      </div>
    </div>
  </div>

  <div class="col-xl-7 col-lg-6 col-md-12">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title mb-0">Pendientes de pago</h4>
      </div>
      <div class="card-body">
        <div id="lista-pendientes"></div>
      </div>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var form = document.getElementById('form-cobro');

  var URL_COBRAR = <?= P::js($rutas->url('caja/constancias/cobrar')) ?>;
  var BASE_PDF   = <?= P::js($rutas->url('constancias/')) ?>;

  var CSRF_SEMILLA = <?= P::js($csrf) ?>;

  function tokenVigente() {
    return (window.CMToken && window.CMToken.de('constancias-caja')) || CSRF_SEMILLA;
  }

  var PENDIENTES = JSON.parse(<?= P::js(json_encode($pendientes, JSON_UNESCAPED_UNICODE)) ?>);

  function esc(t) {
    return String(t == null ? '' : t)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function dinero(cent) {
    return '$' + (Number(cent || 0) / 100).toFixed(2);
  }

  /* Cuánto le queda. En minutos cuando queda poco: decir «0 horas» a
     quien tiene cuarenta minutos es decirle que ya no sirve. */
  function plazo(min) {
    var m = Number(min || 0);

    if (m <= 0)  { return 'vencida'; }
    if (m < 60)  { return 'quedan ' + m + ' min'; }

    var h = Math.floor(m / 60);
    return 'quedan ' + h + (h === 1 ? ' hora' : ' horas');
  }

  function pintar(lista) {
    PENDIENTES = lista || [];

    var caja = document.getElementById('lista-pendientes');

    if (!PENDIENTES.length) {
      caja.innerHTML = '<p class="text-muted mb-0">No hay constancias esperando cobro.</p>';
      return;
    }

    var html = '';

    PENDIENTES.forEach(function (s) {
      var urge = Number(s.minutos_restantes || 0) < 120;

      html += '<div class="border rounded p-3 mb-2' + (urge ? ' border-warning' : '') + '">'
        + '<div class="d-flex justify-content-between align-items-start flex-wrap">'
        + '<div>'
        + '<strong>' + esc(s.cliente) + '</strong>'
        + '<br><small class="text-muted">' + esc(s.documento_identidad) + '</small>'
        + '<br><small>' + esc(s.tipo_nombre) + '</small>'
        + '<br><span class="cm-mono" style="font-size:.9rem">' + esc(s.folio) + '</span>'
        + '</div>'
        + '<div class="text-right">'
        + '<strong style="font-size:1.05rem">' + dinero(s.comision_cent) + '</strong>'
        + '<br><small class="' + (urge ? 'text-warning' : 'text-muted') + '">'
        + esc(plazo(s.minutos_restantes)) + '</small>'
        + '<br><button type="button" class="btn btn-sm btn-outline-primary mt-2 js-llenar" '
        + 'data-folio="' + esc(s.folio) + '">Cobrar</button>'
        + '</div></div>'
        + '<small class="text-muted d-block mt-2">la preparó '
        + esc(s.generada_por_nombre) + '</small>'
        + '</div>';
    });

    caja.innerHTML = html;
  }

  pintar(PENDIENTES);

  /* Pulsar «Cobrar» en la lista solo RELLENA el folio; no cobra. El
     cajero todavía tiene que elegir el medio y confirmar, y así un clic
     de más no se lleva el dinero de nadie. */
  document.getElementById('lista-pendientes').addEventListener('click', function (ev) {
    var b = ev.target.closest('.js-llenar');

    if (!b) { return; }

    var campo = document.getElementById('folio');
    campo.value = b.getAttribute('data-folio') || '';
    campo.focus();
  });

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();

    var boton = document.getElementById('btn-cobrar');
    boton.disabled = true;

    var fd = new FormData(form);
    fd.set('_csrf', tokenVigente());

    fetch(URL_COBRAR, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        boton.disabled = false;

        if (!j.ok) { window.avisar('No se pudo cobrar', j.mensaje, 'critico'); return; }

        var d = j.datos || {};

        window.avisar('Cobrado', j.mensaje, 'exito');

        if (d.pendientes) { pintar(d.pendientes); }

        form.reset();

        /* Y se abre el PDF para imprimirlo y entregarlo: es lo
           siguiente que el cajero va a hacer, siempre. */
        if (d.folio) {
          window.open(BASE_PDF + d.folio + '/pdf', '_blank', 'noopener');
        }

        if (window.Vivo) { window.Vivo.refrescar(); }
      })
      .catch(function () {
        boton.disabled = false;
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  });
})();
</script>
