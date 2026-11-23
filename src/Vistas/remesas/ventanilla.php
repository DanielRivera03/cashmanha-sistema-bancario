<?php
/**
 * El pago de remesas en ventanilla.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $remesadoras
 * @var bool $puedePagar
 * @var bool $puedeRegistrar
 * @var bool $sesionAbierta
 * @var string $csrf
 */

use CashMan\Http\Plantilla as P;
?>
<div class="row">

  <div class="col-lg-7 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Buscar una remesa</h4></div>
      <div class="card-body">

        <div class="alert alert-light border" style="font-size:.88rem">
          La gente llega de dos maneras: con el <strong>código</strong> que le
          dictaron por teléfono, o solo con su <strong>documento</strong> porque
          lo perdió. Las dos tienen que servir.
        </div>

        <ul class="nav nav-tabs" role="tablist">
          <li class="nav-item">
            <a class="nav-link active" data-toggle="tab" href="#por-codigo" role="tab">
              Por código
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" data-toggle="tab" href="#por-documento" role="tab">
              Por documento
            </a>
          </li>
        </ul>

        <div class="tab-content pt-3">

          <div class="tab-pane fade show active" id="por-codigo" role="tabpanel">
            <div class="form-row">
              <div class="form-group col-md-5">
                <label for="remesadora">Remesadora</label>
                <select class="form-control" id="remesadora">
                  <?php foreach ($remesadoras as $r): ?>
                  <option value="<?= P::attr($r['codigo']) ?>"
                          data-etiqueta="<?= P::attr($r['etiqueta_codigo']) ?>">
                    <?= P::e($r['nombre_corto']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-md-7">
                <label for="codigo" id="eti-codigo">Código de control</label>
                <div class="input-group">
                  <input type="text" class="form-control cm-mono" id="codigo"
                         inputmode="numeric" autocomplete="off" spellcheck="false">
                  <div class="input-group-append">
                    <button class="btn btn-primary" type="button" data-buscar="codigo">
                      Buscar
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="tab-pane fade" id="por-documento" role="tabpanel">
            <div class="form-group">
              <label for="documento">Documento del beneficiario</label>
              <div class="input-group">
                <input type="text" class="form-control" id="documento"
                       placeholder="DUI o NIT" autocomplete="off">
                <div class="input-group-append">
                  <button class="btn btn-primary" type="button" data-buscar="documento">
                    Buscar
                  </button>
                </div>
              </div>
              <small class="form-text text-muted">
                Se busca con o sin guion: da igual cómo lo tenga escrito.
              </small>
            </div>
          </div>

        </div>

        <hr>

        <div id="resultado"></div>

      </div>
    </div>
  </div>

  <div class="col-lg-5 col-md-12">

    <?php if (!$sesionAbierta && $puedePagar): ?>
    <div class="alert alert-warning" style="font-size:.9rem">
      <strong>No tiene una caja abierta.</strong>
      Puede consultar remesas, pero para pagarlas en efectivo hay que abrir caja
      primero: si no, el efectivo que sale no entraría en su arqueo.
    </div>
    <?php endif; ?>

    <?php if ($puedeRegistrar): ?>
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Registrar una remesa</h4></div>
      <div class="card-body">
        <p class="text-muted" style="font-size:.86rem">
          Para las que llegan por archivo o aviso de la remesadora y todavía no
          están en el sistema.
        </p>

        <form id="form-registro">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="remesas">

          <div class="form-group">
            <label for="r-remesadora">Remesadora</label>
            <select class="form-control form-control-sm" id="r-remesadora" name="remesadora">
              <?php foreach ($remesadoras as $r): ?>
              <option value="<?= P::attr($r['codigo']) ?>"><?= P::e($r['nombre_corto']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="r-codigo">Código de control</label>
            <input type="text" class="form-control form-control-sm cm-mono"
                   id="r-codigo" name="codigo" autocomplete="off" required>
          </div>

          <div class="form-group">
            <label for="r-beneficiario">Beneficiario</label>
            <input type="text" class="form-control form-control-sm"
                   id="r-beneficiario" name="beneficiario_nombre" maxlength="140" required>
          </div>

          <div class="form-group">
            <label for="r-documento">Documento del beneficiario</label>
            <input type="text" class="form-control form-control-sm"
                   id="r-documento" name="beneficiario_documento" maxlength="20" required>
          </div>

          <div class="form-row">
            <div class="form-group col-7">
              <label for="r-remitente">Remitente</label>
              <input type="text" class="form-control form-control-sm"
                     id="r-remitente" name="remitente_nombre" maxlength="140">
            </div>
            <div class="form-group col-5">
              <label for="r-monto">Monto</label>
              <div class="input-group input-group-sm">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" id="r-monto" name="monto"
                       inputmode="decimal" required>
              </div>
            </div>
          </div>

          <button type="submit" class="btn btn-sm btn-primary btn-block">Registrar</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Antes de entregar</h4></div>
      <div class="card-body" style="font-size:.88rem">
        <ol class="pl-3 mb-0">
          <li>Compruebe que el <strong>nombre</strong> del documento coincide con el
              del beneficiario.</li>
          <li>Verifique el <strong>documento físico</strong>, no una foto.</li>
          <li>Entregue el comprobante impreso.</li>
        </ol>
        <hr>
        <p class="mb-0 text-muted">
          Una remesa entregada a quien no era no se recupera: el dinero ya salió
          y la remesadora lo reclama al banco.
        </p>
      </div>
    </div>

  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var URL_BUSCAR = <?= P::js($rutas->url('remesas/buscar')) ?>;
  var BASE       = <?= P::js($rutas->url('remesas/')) ?>;
  var URL_REG    = <?= P::js($rutas->url('remesas/registrar')) ?>;

  var PUEDE_PAGAR = <?= $puedePagar ? 'true' : 'false' ?>;
  var HAY_CAJA    = <?= $sesionAbierta ? 'true' : 'false' ?>;

  var CSRF_SEMILLA = <?= P::js($csrf) ?>;

  function tokenVigente() {
    return (window.CMToken && window.CMToken.de('remesas')) || CSRF_SEMILLA;
  }

  function esc(t) {
    return String(t == null ? '' : t)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function dinero(cent) {
    return (window.CMUtil && window.CMUtil.money)
      ? window.CMUtil.money(cent)
      : '$' + (Number(cent) / 100).toFixed(2);
  }

  /* La etiqueta del código cambia por remesadora: Western Union lo
     llama MTCN, MoneyGram número de referencia. El beneficiario llega
     diciendo el nombre que le dieron por teléfono. */
  var sel = document.getElementById('remesadora');

  function ajustarEtiqueta() {
    var o = sel.options[sel.selectedIndex];
    if (!o) { return; }

    document.getElementById('eti-codigo').textContent =
      o.getAttribute('data-etiqueta') || 'Código de control';
  }

  sel.addEventListener('change', ajustarEtiqueta);
  ajustarEtiqueta();

  // --- Buscar ---------------------------------------------------------
  document.addEventListener('click', function (ev) {
    var por = ev.target.getAttribute && ev.target.getAttribute('data-buscar');
    if (!por) { return; }

    var fd = new FormData();
    fd.append('_csrf', tokenVigente());
    fd.append('_csrf_proposito', 'remesas');

    if (por === 'codigo') {
      fd.append('remesadora', sel.value);
      fd.append('codigo', document.getElementById('codigo').value);
    } else {
      fd.append('documento', document.getElementById('documento').value);
    }

    ev.target.disabled = true;

    fetch(URL_BUSCAR, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        ev.target.disabled = false;

        if (!j.ok) { window.avisar('No se pudo buscar', j.mensaje, 'alerta'); return; }

        pintar((j.datos && j.datos.remesas) || []);
      })
      .catch(function () {
        ev.target.disabled = false;
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  });

  function pintar(lista) {
    var caja = document.getElementById('resultado');

    if (!lista.length) {
      caja.innerHTML = '<p class="text-muted mb-0">'
        + 'No se encontró ninguna remesa con esos datos.</p>';
      return;
    }

    var html = '';

    lista.forEach(function (r) {
      var disponible = r.estado === 'disponible';

      html += '<div class="border rounded p-3 mb-3" data-folio="' + esc(r.folio) + '">'
        + '<div class="d-flex justify-content-between align-items-start flex-wrap">'
        + '<div><h4 class="mb-0">' + dinero(r.monto_cent) + '</h4>'
        + '<small class="text-muted">' + esc(r.remesadora)
        + (r.remitente ? ' · de ' + esc(r.remitente) : '') + '</small></div>'
        + '<div class="text-right">'
        + '<span class="badge badge-' + (disponible ? 'success' : 'secondary') + '">'
        + esc(r.estado) + '</span>'
        + '<br><small class="text-muted cm-mono">' + esc(r.codigo) + '</small>'
        + '</div></div>'

        + '<hr class="my-2">'

        + '<div style="font-size:.9rem">'
        + '<strong>' + esc(r.beneficiario) + '</strong><br>'
        + '<span class="text-muted">Documento ' + esc(r.documento) + '</span>'
        + '</div>';

      if (r.motivo) {
        html += '<div class="text-muted mt-1" style="font-size:.84rem">'
              + esc(r.motivo) + '</div>';
      }

      if (disponible && PUEDE_PAGAR) {
        html += HAY_CAJA
          ? '<button class="btn btn-sm btn-success mt-3" data-pagar>'
            + 'Pagar en efectivo</button>'
          : '<div class="alert alert-warning mt-3 mb-0" style="font-size:.85rem">'
            + 'Abra su caja para poder entregar efectivo.</div>';
      }

      html += '</div>';
    });

    caja.innerHTML = html;
  }

  // --- Pagar ----------------------------------------------------------
  document.addEventListener('click', function (ev) {
    if (!ev.target.hasAttribute || !ev.target.hasAttribute('data-pagar')) { return; }

    var caja  = ev.target.closest('[data-folio]');
    var folio = caja.getAttribute('data-folio');
    var boton = ev.target;

    window.CMAviso.confirmar({
      titulo: '¿Entregar esta remesa en efectivo?',
      texto: 'Compruebe el documento FÍSICO del beneficiario antes de aceptar. '
           + 'Una remesa entregada a quien no era no se recupera.',
      alAceptar: function () {
        boton.disabled = true;

        var fd = new FormData();
        fd.append('_csrf', tokenVigente());
        fd.append('_csrf_proposito', 'remesas');

        fetch(BASE + folio + '/efectivo', {
          method: 'POST', body: fd, credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            boton.disabled = false;

            if (!j.ok) { window.avisar('No se pudo pagar', j.mensaje, 'critico'); return; }

            window.avisar('Remesa pagada', j.mensaje, 'exito');

            caja.querySelector('[data-pagar]').remove();

            if (window.Vivo) { window.Vivo.refrescar(); }
          })
          .catch(function () {
            boton.disabled = false;
            window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
          });
      }
    });
  });

  // --- Registrar ------------------------------------------------------
  var reg = document.getElementById('form-registro');

  if (reg) {
    reg.addEventListener('submit', function (ev) {
      ev.preventDefault();

      var fd = new FormData(reg);
      fd.set('_csrf', tokenVigente());

      fetch(URL_REG, {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { window.avisar('No se pudo registrar', j.mensaje, 'critico'); return; }

          window.avisar('Remesa registrada', j.mensaje, 'exito');
          reg.reset();
        })
        .catch(function () {
          window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
        });
    });
  }
})();
</script>
