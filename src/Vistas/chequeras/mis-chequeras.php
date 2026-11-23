<?php
/**
 * Chequeras y cheques.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $chequeras
 * @var array $corrientes
 * @var bool $puedeAdmin
 * @var bool $puedePagar
 * @var string $csrf
 */

use CashMan\Http\Plantilla as P;
?>
<div class="row">

  <div class="col-lg-5 col-md-12">

    <?php if ($corrientes === []): ?>
    <div class="alert alert-info">
      <strong>Las chequeras son para cuentas corrientes.</strong>
      Una cuenta de ahorro no gira cheques: no es una restricción del sistema,
      es lo que es una cuenta de ahorro.
    </div>
    <?php else: ?>
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Solicitar una chequera</h4></div>
      <div class="card-body">
        <form id="form-chequera">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="chequeras">

          <div class="form-group">
            <label for="cuenta">Cuenta corriente</label>
            <select class="form-control" id="cuenta" name="cuenta" required>
              <?php foreach ($corrientes as $c): ?>
              <option value="<?= P::attr($c['codigo']) ?>">
                <?= P::e($c['numero_cuenta']) ?> &mdash;
                $<?= number_format((int) $c['saldo_cent'] / 100, 2) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <button type="submit" class="btn btn-primary btn-block">
            Solicitar chequera de 25 cheques
          </button>
          <small class="form-text text-muted">
            Le avisaremos cuando pueda retirarla. Se cobra una comisión por la impresión.
          </small>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Si pierde un cheque</h4></div>
      <div class="card-body">
        <p style="font-size:.88rem">
          Puede dar <strong>orden de no pago</strong> sobre cualquier cheque que no se
          haya cobrado todavía. Es gratis e inmediato: frenar un pago nunca puede ser
          más difícil que hacerlo.
        </p>

        <form id="form-suspender">
          <input type="hidden" name="_csrf_proposito" value="chequeras">

          <div class="form-row">
            <div class="form-group col-7 mb-2">
              <label style="font-size:.82rem">Cuenta</label>
              <select class="form-control form-control-sm" name="cuenta">
                <?php foreach ($corrientes as $c): ?>
                <option value="<?= P::attr($c['codigo']) ?>"><?= P::e($c['numero_cuenta']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-5 mb-2">
              <label style="font-size:.82rem">N.º de cheque</label>
              <input type="number" class="form-control form-control-sm" name="numero" min="1" required>
            </div>
          </div>

          <div class="form-group mb-2">
            <label style="font-size:.82rem">¿Qué pasó?</label>
            <input type="text" class="form-control form-control-sm" name="motivo"
                   placeholder="Se me perdió, me lo robaron…" maxlength="255" required>
          </div>

          <button type="submit" class="btn btn-sm btn-outline-danger btn-block">
            Dar orden de no pago
          </button>
        </form>
      </div>
    </div>

    <?php if ($puedePagar): ?>
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Presentar un cheque al cobro</h4></div>
      <div class="card-body">
        <form id="form-cobro">
          <input type="hidden" name="_csrf_proposito" value="chequeras">

          <div class="form-group mb-2">
            <label style="font-size:.82rem">Cuenta del librador</label>
            <input type="text" class="form-control form-control-sm cm-mono"
                   name="numero_cuenta" placeholder="Número impreso en el cheque" required>
          </div>

          <div class="form-row">
            <div class="form-group col-4 mb-2">
              <label style="font-size:.82rem">N.º</label>
              <input type="number" class="form-control form-control-sm" name="numero" min="1" required>
            </div>
            <div class="form-group col-8 mb-2">
              <label style="font-size:.82rem">Monto</label>
              <div class="input-group input-group-sm">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" name="monto" inputmode="decimal" required>
              </div>
            </div>
          </div>

          <div class="form-group mb-2">
            <label style="font-size:.82rem">Beneficiario</label>
            <input type="text" class="form-control form-control-sm" name="beneficiario"
                   maxlength="160" required>
          </div>

          <button type="submit" class="btn btn-sm btn-primary btn-block">Presentar al cobro</button>
          <small class="form-text text-muted">
            El sistema decide si se paga: mira fondos, fechas y órdenes de no pago.
          </small>
        </form>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <div class="col-lg-7 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Mis chequeras</h4></div>
      <div class="card-body">
        <div id="lista-chequeras"></div>
      </div>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var URL_SOL  = <?= P::js($rutas->url('chequeras/solicitar')) ?>;
  var URL_SUSP = <?= P::js($rutas->url('chequeras/suspender')) ?>;
  var URL_COBRO = <?= P::js($rutas->url('chequeras/presentar')) ?>;
  var BASE     = <?= P::js($rutas->url('chequeras/')) ?>;

  var PUEDE_ADMIN = <?= $puedeAdmin ? 'true' : 'false' ?>;

  var CSRF_SEMILLA = <?= P::js($csrf) ?>;

  function tokenVigente() {
    return (window.CMToken && window.CMToken.de('chequeras')) || CSRF_SEMILLA;
  }

  var CHEQUERAS = JSON.parse(<?= P::js(json_encode($chequeras, JSON_UNESCAPED_UNICODE)) ?>);

  function esc(t) {
    return String(t == null ? '' : t)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  var COLOR = {
    solicitada: 'warning', impresa: 'info',
    entregada: 'success', anulada: 'secondary'
  };

  function pintar(lista) {
    CHEQUERAS = lista || [];

    var caja = document.getElementById('lista-chequeras');

    if (!CHEQUERAS.length) {
      caja.innerHTML = '<p class="text-muted mb-0">Todavía no ha pedido ninguna chequera.</p>';
      return;
    }

    var html = '';

    CHEQUERAS.forEach(function (c) {
      var r = c.resumen || {};

      html += '<div class="border rounded p-3 mb-3" data-folio="' + esc(c.folio) + '">'
        + '<div class="d-flex justify-content-between align-items-start flex-wrap">'
        + '<div><strong>Cheques ' + esc(c.desde_numero) + ' al ' + esc(c.hasta_numero) + '</strong>'
        + '<br><small class="text-muted">' + esc(c.folio) + ' · cuenta '
        + esc(c.numero_cuenta) + '</small></div>'
        + '<span class="badge badge-' + (COLOR[c.estado] || 'secondary') + '">'
        + esc(c.estado) + '</span>'
        + '</div>';

      if (c.entregada_a) {
        html += '<small class="text-muted d-block mt-1">Retirada por '
              + esc(c.entregada_a) + '</small>';
      }

      /* El estado de los cheques, en números: es lo que el cliente
         mira para saber cuántos le quedan. */
      if (Number(r.total) > 0) {
        html += '<div class="row mt-2 text-center" style="font-size:.82rem">'
          + '<div class="col"><strong>' + esc(r.en_blanco || 0) + '</strong>'
          + '<br><span class="text-muted">en blanco</span></div>'
          + '<div class="col"><strong>' + esc(r.pagados || 0) + '</strong>'
          + '<br><span class="text-muted">pagados</span></div>'
          + '<div class="col"><strong class="text-danger">' + esc(r.rechazados || 0) + '</strong>'
          + '<br><span class="text-muted">rechazados</span></div>'
          + '<div class="col"><strong class="text-warning">' + esc(r.suspendidos || 0) + '</strong>'
          + '<br><span class="text-muted">suspendidos</span></div>'
          + '</div>';
      }

      html += '<div class="mt-2">'
        + '<button class="btn btn-sm btn-outline-secondary" data-ver>Ver cheques</button>';

      if (PUEDE_ADMIN && c.estado === 'solicitada') {
        html += ' <button class="btn btn-sm btn-outline-primary" data-imprimir>Imprimir</button>';
      }

      if (PUEDE_ADMIN && c.estado === 'impresa') {
        html += ' <button class="btn btn-sm btn-outline-success" data-entregar>Entregar</button>';
      }

      html += '</div><div data-cheques class="mt-2"></div></div>';
    });

    caja.innerHTML = html;
  }

  pintar(CHEQUERAS);

  function enviar(url, fd, alOk) {
    fd.append('_csrf', tokenVigente());
    fd.append('_csrf_proposito', 'chequeras');

    return fetch(url, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { window.avisar('No se pudo', j.mensaje, 'critico'); return; }
        alOk(j);
      })
      .catch(function () {
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  }

  // --- Solicitar --------------------------------------------------------
  var fSol = document.getElementById('form-chequera');

  if (fSol) {
    fSol.addEventListener('submit', function (ev) {
      ev.preventDefault();

      enviar(URL_SOL, new FormData(fSol), function (j) {
        window.avisar('Chequera solicitada', j.mensaje, 'exito');
        if (j.datos && j.datos.chequeras) { pintar(j.datos.chequeras); }
        if (window.Vivo) { window.Vivo.refrescar(); }
      });
    });
  }

  // --- Orden de no pago -------------------------------------------------
  var fSusp = document.getElementById('form-suspender');

  if (fSusp) {
    fSusp.addEventListener('submit', function (ev) {
      ev.preventDefault();

      window.CMAviso.confirmar({
        titulo: '¿Dar orden de no pago?',
        texto: 'Ese cheque no se pagará si alguien lo presenta. '
             + 'Si lo recupera, puede pedir que se levante la orden.',
        alAceptar: function () {
          enviar(URL_SUSP, new FormData(fSusp), function (j) {
            window.avisar('Orden registrada', j.mensaje, 'exito');
            if (j.datos && j.datos.chequeras) { pintar(j.datos.chequeras); }
            fSusp.reset();
          });
        }
      });
    });
  }

  // --- Presentar al cobro ------------------------------------------------
  var fCob = document.getElementById('form-cobro');

  if (fCob) {
    fCob.addEventListener('submit', function (ev) {
      ev.preventDefault();

      enviar(URL_COBRO, new FormData(fCob), function (j) {
        var d = j.datos || {};

        /* Un rechazo no es un fallo técnico: es el resultado. Se
           muestra en rojo pero como respuesta, no como error. */
        window.avisar(
          d.estado === 'pagado' ? 'Cheque pagado' : 'Cheque rechazado',
          j.mensaje,
          d.estado === 'pagado' ? 'exito' : 'alerta'
        );

        if (d.estado === 'pagado') { fCob.reset(); }

        if (window.Vivo) { window.Vivo.refrescar(); }
      });
    });
  }

  // --- La lista ---------------------------------------------------------
  document.getElementById('lista-chequeras').addEventListener('click', function (ev) {
    var fila = ev.target.closest ? ev.target.closest('[data-folio]') : null;
    if (!fila) { return; }

    var folio = fila.getAttribute('data-folio');

    if (ev.target.hasAttribute('data-ver')) {
      fetch(BASE + folio + '/cheques', {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { return; }
          pintarCheques(fila.querySelector('[data-cheques]'), (j.datos || {}).cheques || []);
        })
        .catch(function () {});
    }

    if (ev.target.hasAttribute('data-imprimir')) {
      enviar(BASE + folio + '/imprimir', new FormData(), function (j) {
        window.avisar('Impresa', j.mensaje, 'exito');
        if (window.CMVista) { window.CMVista.refrescar(); } else { location.reload(); }
      });
    }

    if (ev.target.hasAttribute('data-entregar')) {
      var quien = window.prompt(
        'Nombre completo de quien retira la chequera.\n\n'
        + 'Veinticinco cheques en blanco salen del banco: hay que saber en manos de quién.'
      );

      if (quien === null || quien.trim().length < 5) { return; }

      var fd = new FormData();
      fd.append('retira', quien);

      enviar(BASE + folio + '/entregar', fd, function (j) {
        window.avisar('Entregada', j.mensaje, 'exito');
        if (window.CMVista) { window.CMVista.refrescar(); } else { location.reload(); }
      });
    }
  });

  function pintarCheques(caja, cheques) {
    if (!cheques.length) {
      caja.innerHTML = '<small class="text-muted">Todavía no se han impreso.</small>';
      return;
    }

    var colores = {
      en_blanco: 'light', emitido: 'info', pagado: 'success',
      rechazado: 'danger', anulado: 'secondary', suspendido: 'warning'
    };

    var html = '<div class="d-flex flex-wrap" style="gap:.25rem">';

    cheques.forEach(function (c) {
      var titulo = c.estado;

      if (c.beneficiario) { titulo += ' · ' + c.beneficiario; }
      if (c.motivo_rechazo) { titulo += ' · ' + c.motivo_rechazo; }

      html += '<span class="badge badge-' + (colores[c.estado] || 'light') + '"'
        + ' title="' + esc(titulo) + '" style="min-width:2.4rem">'
        + esc(c.numero) + '</span>';
    });

    caja.innerHTML = html + '</div>';
  }
})();
</script>
