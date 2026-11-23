<?php
/**
 * Pagar un servicio.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $cuentas
 * @var array $categorias
 * @var array $guardados
 * @var array $limite
 * @var string $csrf
 */

use CashMan\Dominio\Colecturia\Colector;
use CashMan\Http\Plantilla as P;
?>
<div class="row">

  <div class="col-lg-7 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Pagar un servicio</h4></div>
      <div class="card-body">

        <?php if ($cuentas === []): ?>
          <div class="alert alert-warning mb-0">
            No tiene cuentas activas desde las que pagar.
          </div>
        <?php else: ?>

        <form id="form-servicio">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="servicios">
          <input type="hidden" name="_idem" id="idem" value="">

          <div class="form-group">
            <label for="convenio">Servicio</label>
            <select class="form-control" id="convenio" name="convenio_id" required>
              <option value="">Elija el servicio a pagar…</option>
              <?php foreach ($categorias as $clave => $convenios): ?>
              <optgroup label="<?= P::attr(Colector::categoria((string) $clave)) ?>">
                <?php foreach ($convenios as $c): ?>
                <option value="<?= (int) $c['id'] ?>"
                        data-etiqueta="<?= P::attr($c['etiqueta_referencia']) ?>"
                        data-ayuda="<?= P::attr($c['ayuda_referencia']) ?>"
                        data-min="<?= (int) $c['ref_largo_min'] ?>"
                        data-max="<?= (int) $c['ref_largo_max'] ?>"
                        data-parcial="<?= (int) $c['permite_parcial'] ?>">
                  <?= P::e($c['nombre_corto']) ?>
                </option>
                <?php endforeach; ?>
              </optgroup>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="referencia" id="eti-referencia">Número de referencia</label>
            <div class="input-group">
              <input type="text" class="form-control" id="referencia" name="referencia"
                     inputmode="numeric" autocomplete="off" spellcheck="false" required>
              <div class="input-group-append">
                <button class="btn btn-outline-primary" type="button" id="btn-consultar">
                  Consultar
                </button>
              </div>
            </div>
            <small class="form-text text-muted" id="ayuda-referencia"></small>
          </div>

          <!-- ----------------------------------------------------------
               Lo que la empresa dice que se debe.

               El cliente NO teclea el monto: lo pone el colector. Un
               monto inventado se rechaza o se aplica mal, y en los dos
               casos el cliente se queda sin servicio habiendo pagado.
               ---------------------------------------------------------- -->
          <div class="card bg-light d-none mb-3" id="caja-deuda">
            <div class="card-body py-3">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <small class="text-muted d-block">A nombre de</small>
                  <strong id="d-titular"></strong>
                  <div class="text-muted" style="font-size:.84rem">
                    Periodo <span id="d-periodo"></span> ·
                    vence el <span id="d-vence"></span>
                  </div>
                </div>
                <div class="text-right">
                  <small class="text-muted d-block">Se debe</small>
                  <h4 class="mb-0" id="d-deuda"></h4>
                </div>
              </div>

              <hr class="my-2">

              <div class="form-row align-items-end">
                <div class="form-group col-md-6 mb-0">
                  <label style="font-size:.84rem">Monto a pagar</label>
                  <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                    <input type="text" class="form-control" id="monto" name="monto"
                           inputmode="decimal" readonly>
                  </div>
                  <small class="form-text text-muted" id="nota-parcial"></small>
                </div>
                <div class="form-group col-md-6 mb-0">
                  <div class="custom-control custom-checkbox">
                    <input type="checkbox" class="custom-control-input" id="guardar" name="guardar" value="1">
                    <label class="custom-control-label" for="guardar" style="font-size:.88rem">
                      Guardar este servicio
                    </label>
                  </div>
                  <input type="text" class="form-control form-control-sm mt-2 d-none"
                         id="alias" name="alias" maxlength="60"
                         placeholder="Cómo quiere llamarlo: Casa, Mamá…">
                </div>
              </div>
            </div>
          </div>

          <div class="form-group">
            <label for="cuenta">Pagar desde</label>
            <select class="form-control" id="cuenta" name="cuenta" required>
              <?php foreach ($cuentas as $c): ?>
              <option value="<?= P::attr($c['codigo']) ?>" data-disp="<?= (int) $c['disponible_cent'] ?>">
                <?= P::e($c['numero_cuenta']) ?> &mdash; <?= P::e($c['producto']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small class="form-text text-muted" id="disp-cuenta"></small>
          </div>

          <button type="submit" class="btn btn-primary btn-block" id="btn-pagar" disabled>
            Pagar
          </button>
        </form>

        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5 col-md-12">

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Mis servicios</h4>
        <a class="btn btn-sm btn-outline-secondary"
           href="<?= P::attr($rutas->url('servicios/historial')) ?>">Historial</a>
      </div>
      <div class="card-body">
        <p class="text-muted" style="font-size:.86rem">
          Nadie se sabe de memoria el NIS de su contador. Guárdelo una vez
          y páguelo en dos clics el mes que viene.
        </p>
        <div id="lista-guardados"></div>
      </div>
    </div>

    <?php if ($limite !== []): ?>
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Su límite de hoy</h4></div>
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <span class="text-muted" style="font-size:.88rem">Disponible hoy</span>
          <strong id="lim-restante">
            $<?= number_format((int) $limite['restante_cent'] / 100, 2) ?>
          </strong>
        </div>
        <small class="form-text text-muted">
          <?= (int) $limite['ops_hoy'] ?> de <?= (int) $limite['max_ops_diarias'] ?> pagos
        </small>
        <a class="btn btn-sm btn-outline-secondary btn-block mt-2"
           href="<?= P::attr($rutas->url('otros-bancos/limites')) ?>">Cambiar mis límites</a>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var form = document.getElementById('form-servicio');
  if (!form) { return; }

  var URL_CONSULTA = <?= P::js($rutas->url('servicios/consultar')) ?>;
  var URL_PAGO     = <?= P::js($rutas->url('servicios/pagar')) ?>;
  var BASE_GUARD   = <?= P::js($rutas->url('servicios/guardado/')) ?>;

  var CSRF_SEMILLA = <?= P::js($csrf) ?>;

  function tokenVigente() {
    return (window.CMToken && window.CMToken.de('servicios')) || CSRF_SEMILLA;
  }

  var GUARDADOS = JSON.parse(<?= P::js(json_encode($guardados, JSON_UNESCAPED_UNICODE)) ?>);

  var convenio  = document.getElementById('convenio');
  var refCampo  = document.getElementById('referencia');
  var cuenta    = document.getElementById('cuenta');
  var cajaDeuda = document.getElementById('caja-deuda');
  var montoC    = document.getElementById('monto');
  var btnPagar  = document.getElementById('btn-pagar');
  var btnCons   = document.getElementById('btn-consultar');

  /* Lo que devolvió la consulta. Mientras esté vacío no se puede
     pagar: el monto lo pone el colector, no la pantalla. */
  var deuda = null;

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

  function nuevaIdem() {
    var a = new Uint8Array(16);
    crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) {
      return ('0' + b.toString(16)).slice(-2);
    }).join('');
  }

  document.getElementById('idem').value = nuevaIdem();

  // --- Qué se le pide al cliente según el convenio -------------------
  function ajustarCampo() {
    var o = convenio.options[convenio.selectedIndex];

    limpiarDeuda();

    if (!o || !o.value) {
      document.getElementById('eti-referencia').textContent = 'Número de referencia';
      document.getElementById('ayuda-referencia').textContent = '';
      return;
    }

    document.getElementById('eti-referencia').textContent =
      o.getAttribute('data-etiqueta') || 'Número de referencia';

    document.getElementById('ayuda-referencia').textContent =
      o.getAttribute('data-ayuda') || '';

    refCampo.maxLength = parseInt(o.getAttribute('data-max'), 10) || 40;
  }

  function actualizarDisponible() {
    var o = cuenta.options[cuenta.selectedIndex];
    if (!o) { return; }

    document.getElementById('disp-cuenta').textContent =
      'Disponible: ' + dinero(parseInt(o.getAttribute('data-disp'), 10) || 0);
  }

  function limpiarDeuda() {
    deuda = null;
    cajaDeuda.classList.add('d-none');
    btnPagar.disabled = true;
    montoC.value = '';
  }

  convenio.addEventListener('change', ajustarCampo);
  refCampo.addEventListener('input', limpiarDeuda);
  cuenta.addEventListener('change', actualizarDisponible);

  ajustarCampo();
  actualizarDisponible();

  // --- Paso 1: consultar ---------------------------------------------
  btnCons.addEventListener('click', function () {
    if (!convenio.value) {
      window.avisar('Falta el servicio', 'Elija primero qué va a pagar.', 'alerta');
      convenio.focus();
      return;
    }

    if (!refCampo.value.trim()) {
      window.avisar('Falta la referencia',
        'Escriba el ' + (document.getElementById('eti-referencia').textContent || 'número') + '.',
        'alerta');
      refCampo.focus();
      return;
    }

    btnCons.disabled = true;
    btnCons.textContent = 'Consultando…';

    var fd = new FormData();
    fd.append('_csrf', tokenVigente());
    fd.append('_csrf_proposito', 'servicios');
    fd.append('convenio_id', convenio.value);
    fd.append('referencia', refCampo.value);

    fetch(URL_CONSULTA, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        btnCons.disabled = false;
        btnCons.textContent = 'Consultar';

        if (!j.ok) {
          limpiarDeuda();
          window.avisar('No se pudo consultar', j.mensaje, 'alerta');
          return;
        }

        mostrarDeuda(j.datos);
      })
      .catch(function () {
        btnCons.disabled = false;
        btnCons.textContent = 'Consultar';
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  });

  function mostrarDeuda(d) {
    deuda = d;

    document.getElementById('d-titular').textContent = d.titular;
    document.getElementById('d-periodo').textContent = d.periodo;
    document.getElementById('d-deuda').textContent   = dinero(d.deuda_cent);

    var v = new Date(d.vence_el + 'T00:00:00');
    document.getElementById('d-vence').textContent = v.toLocaleDateString('es-SV');

    montoC.value = (d.deuda_cent / 100).toFixed(2);

    /* Solo se deja cambiar el monto donde el colector acepta abonos.
       Ofrecerlo donde no se acepta sería dejar al cliente creyendo que
       pagó algo que le van a devolver. */
    montoC.readOnly = !d.permite_parcial;

    document.getElementById('nota-parcial').textContent = d.permite_parcial
      ? 'Puede abonar menos del total si quiere.'
      : 'Esta empresa no acepta abonos parciales.';

    cajaDeuda.classList.remove('d-none');
    btnPagar.disabled = false;
  }

  document.getElementById('guardar').addEventListener('change', function () {
    document.getElementById('alias').classList.toggle('d-none', !this.checked);
  });

  // --- Paso 2: pagar --------------------------------------------------
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();

    if (!deuda) {
      window.avisar('Consulte primero',
        'Hay que consultar cuánto se debe antes de pagar.', 'alerta');
      return;
    }

    btnPagar.disabled = true;

    var pr = CMProceso.abrir('transferencia');
    pr.avanzar();

    var fd = new FormData(form);
    fd.set('_csrf', tokenVigente());

    fetch(URL_PAGO, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        btnPagar.disabled = false;

        if (!j.ok) {
          pr.fallo(j.mensaje || 'No se pudo completar el pago.', 'validando');
          return;
        }

        pr.listo(j.mensaje || '');

        var d = j.datos || {};

        window.avisar(
          d.parcial ? 'Abono aplicado' : 'Servicio pagado',
          j.mensaje + (d.pendiente_cent > 0
            ? ' Queda pendiente ' + dinero(d.pendiente_cent) + '.' : ''),
          'exito'
        );

        form.reset();
        document.getElementById('idem').value = nuevaIdem();
        document.getElementById('alias').classList.add('d-none');
        limpiarDeuda();
        ajustarCampo();

        if (d.cuentas) { refrescarCuentas(d.cuentas); }
        if (d.guardados) { pintarGuardados(d.guardados); }
        if (d.limite && d.limite.restante_cent !== undefined) {
          var r = document.getElementById('lim-restante');
          if (r) { r.textContent = dinero(d.limite.restante_cent); }
        }

        if (window.Vivo) { window.Vivo.refrescar(); }
      })
      .catch(function () {
        btnPagar.disabled = false;
        pr.fallo('No se pudo contactar con el servidor. Revise su conexión.');
      });
  });

  function refrescarCuentas(cuentas) {
    cuentas.forEach(function (c) {
      for (var i = 0; i < cuenta.options.length; i++) {
        if (cuenta.options[i].value === c.codigo) {
          cuenta.options[i].setAttribute('data-disp', c.disponible_cent);
        }
      }
    });

    actualizarDisponible();
  }

  // --- Los servicios guardados ----------------------------------------
  function pintarGuardados(lista) {
    GUARDADOS = lista || [];

    var caja = document.getElementById('lista-guardados');

    if (!GUARDADOS.length) {
      caja.innerHTML = '<p class="text-muted mb-0" style="font-size:.88rem">'
        + 'Todavía no ha guardado ninguno.</p>';
      return;
    }

    var html = '<ul class="list-unstyled mb-0">';

    GUARDADOS.forEach(function (g) {
      html += '<li class="d-flex justify-content-between align-items-center py-2 border-bottom">'
        + '<span style="font-size:.9rem">'
        + '<strong>' + esc(g.alias) + '</strong><br>'
        + '<small class="text-muted">' + esc(g.convenio) + ' · '
        + esc(g.etiqueta_referencia) + ' ' + esc(g.referencia) + '</small>'
        + '</span>'
        + '<span class="text-nowrap">'
        + '<button class="btn btn-sm btn-outline-primary mr-1" data-usar="'
        + esc(g.uuid) + '">Pagar</button>'
        + '<button class="btn btn-sm btn-outline-danger" data-olvidar="'
        + esc(g.uuid) + '">&times;</button>'
        + '</span></li>';
    });

    caja.innerHTML = html + '</ul>';
  }

  pintarGuardados(GUARDADOS);

  document.getElementById('lista-guardados').addEventListener('click', function (ev) {
    var usar    = ev.target.getAttribute && ev.target.getAttribute('data-usar');
    var olvidar = ev.target.getAttribute && ev.target.getAttribute('data-olvidar');

    if (usar) {
      for (var i = 0; i < GUARDADOS.length; i++) {
        if (GUARDADOS[i].uuid === usar) {
          convenio.value = GUARDADOS[i].convenio_id;
          ajustarCampo();
          refCampo.value = GUARDADOS[i].referencia;
          btnCons.click();
          break;
        }
      }
    }

    if (olvidar) {
      window.CMAviso.confirmar({
        titulo: '¿Quitar este servicio de su lista?',
        texto: 'Los pagos que ya hizo siguen en su historial.',
        alAceptar: function () {
          var fd = new FormData();
          fd.append('_csrf', tokenVigente());
          fd.append('_csrf_proposito', 'servicios');

          fetch(BASE_GUARD + olvidar + '/olvidar', {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
          })
            .then(function (r) { return r.json(); })
            .then(function (j) {
              if (!j.ok) { window.avisar('No se pudo', j.mensaje, 'critico'); return; }
              pintarGuardados((j.datos && j.datos.guardados) || []);
            })
            .catch(function () {
              window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
            });
        }
      });
    }
  });
})();
</script>
