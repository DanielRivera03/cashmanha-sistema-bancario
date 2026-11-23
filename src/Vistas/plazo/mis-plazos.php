<?php
/**
 * Depósitos a plazo fijo.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $depositos
 * @var array $cuentas
 * @var array $tarifario
 * @var array $retencion
 * @var string $csrf
 */

use CashMan\Http\Plantilla as P;

$vigentes = array_values(array_filter(
    $depositos,
    static fn(array $d): bool => $d['estado'] === 'vigente'
));
?>
<div class="row">

  <div class="col-lg-5 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Constituir un depósito</h4></div>
      <div class="card-body">

        <?php if ($cuentas === []): ?>
          <div class="alert alert-warning mb-0">
            Necesita una cuenta activa con fondos para constituir un depósito.
          </div>
        <?php else: ?>

        <form id="form-plazo">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="plazo">

          <div class="form-group">
            <label for="monto">¿Cuánto quiere depositar?</label>
            <div class="input-group">
              <div class="input-group-prepend"><span class="input-group-text">$</span></div>
              <input type="text" class="form-control" id="monto" name="monto"
                     inputmode="decimal" placeholder="1,000.00" autocomplete="off" required>
            </div>
          </div>

          <div class="form-group">
            <label for="dias">¿Por cuánto tiempo?</label>
            <select class="form-control" id="dias" name="dias" required>
              <?php foreach ($tarifario as $t): ?>
              <option value="<?= (int) $t['dias_min'] ?>"
                      data-tasa="<?= (int) $t['tasa_bp'] ?>"
                      data-minimo="<?= (int) $t['monto_minimo_cent'] ?>">
                <?= (int) $t['dias_min'] ?> días —
                <?= number_format((int) $t['tasa_bp'] / 100, 2) ?>% anual
                <?php if ((int) $t['monto_minimo_cent'] > 10000): ?>
                  (desde $<?= number_format((int) $t['monto_minimo_cent'] / 100, 0) ?>)
                <?php endif; ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- ----------------------------------------------------------
               Lo que va a RECIBIR, no la tasa.

               Una tasa anual en puntos base no le dice a nadie si le
               conviene. El número que importa es cuánto le van a
               entregar al final, con la retención ya descontada.
               ---------------------------------------------------------- -->
          <div class="card bg-light mb-3 d-none" id="caja-proyeccion">
            <div class="card-body py-3">
              <div class="d-flex justify-content-between">
                <span class="text-muted">Capital</span>
                <strong id="p-capital">—</strong>
              </div>
              <div class="d-flex justify-content-between">
                <span class="text-muted">Intereses</span>
                <strong class="text-success" id="p-interes">—</strong>
              </div>
              <div class="d-flex justify-content-between" id="fila-retencion">
                <span class="text-muted">Retención de renta (10%)</span>
                <strong class="text-danger" id="p-retencion">—</strong>
              </div>
              <hr class="my-2">
              <div class="d-flex justify-content-between">
                <span><strong>Recibirá el</strong> <span id="p-fecha"></span></span>
                <h4 class="mb-0" id="p-total">—</h4>
              </div>
              <small class="form-text text-muted" id="p-nota"></small>
            </div>
          </div>

          <div class="form-group">
            <label for="cuenta_origen">El dinero sale de</label>
            <select class="form-control" id="cuenta_origen" name="cuenta_origen" required>
              <?php foreach ($cuentas as $c): ?>
              <option value="<?= P::attr($c['codigo']) ?>" data-disp="<?= (int) $c['disponible_cent'] ?>">
                <?= P::e($c['numero_cuenta']) ?> &mdash; disponible
                $<?= number_format((int) $c['disponible_cent'] / 100, 2) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="renovacion">Al vencer</label>
            <select class="form-control" id="renovacion" name="renovacion">
              <option value="no">Devolverme todo a mi cuenta</option>
              <option value="capital">Renovar solo el capital y abonarme los intereses</option>
              <option value="capital_intereses">Renovar capital e intereses</option>
            </select>
            <small class="form-text text-muted">
              Puede cambiarlo cancelando y volviendo a constituir.
            </small>
          </div>

          <button type="submit" class="btn btn-primary btn-block" id="btn-constituir" disabled>
            Constituir depósito
          </button>
          <small class="form-text text-muted text-center">
            Escriba un monto para ver lo que recibiría.
          </small>
        </form>

        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Lo que conviene saber</h4></div>
      <div class="card-body" style="font-size:.88rem">
        <p><strong>El dinero queda inmovilizado.</strong> No se puede transferir ni
           retirar desde un depósito a plazo: ésa es la razón por la que paga más que
           una cuenta de ahorro.</p>

        <p><strong>Se puede cancelar antes</strong>, pero cuesta la mitad de los
           intereses que lleve devengados. <em>El capital siempre vuelve entero</em>:
           la penalidad son intereses, nunca su dinero.</p>

        <p class="mb-0"><strong>La retención de renta</strong> es del
           <?= number_format((int) $retencion['bp'] / 100, 0) ?>% sobre los intereses,
           según el artículo 159 del Código Tributario. Los depósitos de hasta
           $<?= number_format((int) $retencion['exento_cent'] / 100, 0) ?> están exentos.</p>
      </div>
    </div>
  </div>

  <div class="col-lg-7 col-md-12">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title mb-0">
          Mis depósitos
          <?php if ($vigentes !== []): ?>
            <span class="badge badge-success"><?= count($vigentes) ?> vigente(s)</span>
          <?php endif; ?>
        </h4>
      </div>
      <div class="card-body">
        <div id="lista-plazos"></div>
      </div>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var URL_SIM  = <?= P::js($rutas->url('plazos/simular')) ?>;
  var URL_NEW  = <?= P::js($rutas->url('plazos/constituir')) ?>;
  var BASE     = <?= P::js($rutas->url('plazos/')) ?>;

  var CSRF_SEMILLA = <?= P::js($csrf) ?>;

  function tokenVigente() {
    return (window.CMToken && window.CMToken.de('plazo')) || CSRF_SEMILLA;
  }

  var DEPOSITOS = JSON.parse(<?= P::js(json_encode($depositos, JSON_UNESCAPED_UNICODE)) ?>);

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

  // --- La lista --------------------------------------------------------
  function pintar(lista) {
    DEPOSITOS = lista || [];

    var caja = document.getElementById('lista-plazos');

    if (!DEPOSITOS.length) {
      caja.innerHTML = '<p class="text-muted mb-0">'
        + 'Todavía no tiene depósitos a plazo.</p>';
      return;
    }

    var html = '';

    DEPOSITOS.forEach(function (d) {
      var vigente = d.estado === 'vigente';

      html += '<div class="border rounded p-3 mb-3" data-folio="' + esc(d.folio) + '">'
        + '<div class="d-flex justify-content-between align-items-start flex-wrap">'
        + '<div>'
        + '<h4 class="mb-0">' + dinero(d.monto_cent) + '</h4>'
        + '<small class="text-muted">' + esc(d.folio) + ' · '
        + (Number(d.tasa_bp) / 100).toFixed(2) + '% a ' + esc(d.plazo_dias) + ' días</small>'
        + '</div>'
        + '<div class="text-right">'
        + '<span class="badge badge-' + (vigente ? 'success'
            : (d.estado === 'cancelado' ? 'warning' : 'secondary')) + '">'
        + esc(d.estado) + '</span>'
        + '<br><small class="text-muted">vence el '
        + esc(String(d.fecha_vence)) + '</small>'
        + '</div></div>';

      if (vigente && d.proyeccion) {
        html += '<div class="row mt-2" style="font-size:.86rem">'
          + '<div class="col-6"><span class="text-muted">Recibirá</span><br>'
          + '<strong class="text-success">' + dinero(d.proyeccion.total_cent) + '</strong></div>'
          + '<div class="col-6 text-right"><span class="text-muted">Faltan</span><br>'
          + '<strong>' + esc(d.dias_restantes) + ' días</strong></div>'
          + '</div>';

        /* Cuánto pierde si lo cancela hoy, dicho ANTES de que pulse
           nada. Es la cifra que hace falta para decidir. */
        if (d.cancelacion) {
          html += '<div class="alert alert-light border mt-2 mb-0" style="font-size:.82rem">'
            + 'Si lo cancela hoy recibiría <strong>'
            + dinero(d.cancelacion.total_cent) + '</strong>'
            + ' (deja de devengar ' + dinero(d.cancelacion.penalidad_cent) + ').'
            + '</div>'
            + '<button class="btn btn-sm btn-outline-danger mt-2" data-cancelar>'
            + 'Cancelar anticipadamente</button>';
        }
      } else if (d.estado !== 'vigente') {
        html += '<div class="row mt-2" style="font-size:.86rem">'
          + '<div class="col-6"><span class="text-muted">Intereses</span><br>'
          + dinero(d.intereses_cent) + '</div>'
          + '<div class="col-6 text-right"><span class="text-muted">Retención</span><br>'
          + dinero(d.retencion_cent) + '</div>'
          + '</div>';
      }

      html += '</div>';
    });

    caja.innerHTML = html;
  }

  pintar(DEPOSITOS);

  var form = document.getElementById('form-plazo');
  if (!form) { return; }

  // --- La proyección, mientras teclea ----------------------------------
  var monto = document.getElementById('monto');
  var dias  = document.getElementById('dias');
  var caja  = document.getElementById('caja-proyeccion');
  var boton = document.getElementById('btn-constituir');

  var temporizador = null;

  function proyectar() {
    if (!monto.value.trim()) {
      caja.classList.add('d-none');
      boton.disabled = true;
      return;
    }

    var fd = new FormData();
    fd.append('_csrf', tokenVigente());
    fd.append('_csrf_proposito', 'plazo');
    fd.append('monto', monto.value);
    fd.append('dias', dias.value);

    fetch(URL_SIM, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) {
          caja.classList.add('d-none');
          boton.disabled = true;
          document.getElementById('p-nota').textContent = j.mensaje || '';
          return;
        }

        var p = j.datos || {};

        document.getElementById('p-capital').textContent   = dinero(p.capital_cent);
        document.getElementById('p-interes').textContent   = '+ ' + dinero(p.interes_cent);
        document.getElementById('p-retencion').textContent = '− ' + dinero(p.retencion_cent);
        document.getElementById('p-total').textContent     = dinero(p.total_cent);
        document.getElementById('p-fecha').textContent     = p.fecha_vence;

        /* La fila de retención solo aparece si de verdad hay
           retención: enseñar «− $0.00» confunde más que ayudar. */
        document.getElementById('fila-retencion').classList.toggle(
          'd-none', Number(p.retencion_cent) === 0
        );

        document.getElementById('p-nota').textContent = p.exento
          ? 'Exento de retención: no supera el umbral de ley.'
          : 'Tasa efectiva después de la retención: '
            + (Number(p.tasa_efectiva_bp) / 100).toFixed(2) + '% anual.';

        caja.classList.remove('d-none');
        boton.disabled = false;
      })
      .catch(function () {
        caja.classList.add('d-none');
        boton.disabled = true;
      });
  }

  function proyectarPronto() {
    clearTimeout(temporizador);
    temporizador = setTimeout(proyectar, 350);
  }

  monto.addEventListener('input', proyectarPronto);
  dias.addEventListener('change', proyectar);

  // --- Constituir -------------------------------------------------------
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();

    boton.disabled = true;

    var fd = new FormData(form);
    fd.set('_csrf', tokenVigente());

    fetch(URL_NEW, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        boton.disabled = false;

        if (!j.ok) { window.avisar('No se pudo constituir', j.mensaje, 'critico'); return; }

        var d = j.datos || {};

        window.avisar('Depósito constituido', j.mensaje, 'exito');

        if (d.depositos) { pintar(d.depositos); }

        form.reset();
        caja.classList.add('d-none');
        boton.disabled = true;

        if (window.Vivo) { window.Vivo.refrescar(); }
      })
      .catch(function () {
        boton.disabled = false;
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  });

  // --- Cancelar ---------------------------------------------------------
  document.getElementById('lista-plazos').addEventListener('click', function (ev) {
    if (!ev.target.hasAttribute || !ev.target.hasAttribute('data-cancelar')) { return; }

    var fila  = ev.target.closest('[data-folio]');
    var folio = fila.getAttribute('data-folio');
    var botonC = ev.target;

    var dep = null;
    DEPOSITOS.forEach(function (d) { if (d.folio === folio) { dep = d; } });

    window.CMAviso.confirmar({
      titulo: '¿Cancelar este depósito antes de tiempo?',
      texto: dep && dep.cancelacion
        ? 'Recibirá ' + dinero(dep.cancelacion.total_cent)
          + ' en vez de ' + dinero(dep.proyeccion.total_cent)
          + '. Deja de devengar ' + dinero(dep.cancelacion.penalidad_cent)
          + '. Su capital vuelve entero.'
        : 'Perderá parte de los intereses devengados. El capital vuelve entero.',
      alAceptar: function () {
        botonC.disabled = true;

        var fd = new FormData();
        fd.append('_csrf', tokenVigente());
        fd.append('_csrf_proposito', 'plazo');

        fetch(BASE + folio + '/cancelar', {
          method: 'POST', body: fd, credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            botonC.disabled = false;

            if (!j.ok) { window.avisar('No se pudo cancelar', j.mensaje, 'critico'); return; }

            window.avisar('Depósito cancelado', j.mensaje, 'exito');

            if (j.datos && j.datos.depositos) { pintar(j.datos.depositos); }

            if (window.Vivo) { window.Vivo.refrescar(); }
          })
          .catch(function () {
            botonC.disabled = false;
            window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
          });
      }
    });
  });
})();
</script>
