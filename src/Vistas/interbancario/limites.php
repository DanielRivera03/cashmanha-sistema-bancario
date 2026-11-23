<?php
/**
 * Los límites que el cliente administra.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $limites
 * @var string $csrf
 */

use CashMan\Http\Plantilla as P;
?>
<div class="row">
  <div class="col-lg-8 col-md-12">

    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Mis límites por canal</h4></div>
      <div class="card-body">

        <div class="alert alert-info" style="font-size:.88rem">
          <strong>Para qué sirve bajar un límite.</strong>
          Si alguien consiguiera su contraseña y su teléfono, lo máximo que
          podría sacarle en un día es el límite que usted tenga puesto. Quien
          casi no transfiere no pierde nada poniéndolo bajo, y gana mucho.
          <br><br>
          <strong>Bajarlo es inmediato. Subirlo pide un código</strong>, porque
          subirlo es lo primero que haría quien le robara la sesión.
        </div>

        <?php foreach ($limites as $l): ?>
        <div class="border rounded p-3 mb-3" data-canal="<?= P::attr($l['canal']) ?>">

          <div class="d-flex justify-content-between align-items-start">
            <div>
              <strong><?= P::e($l['descripcion']) ?></strong>
              <?php if (!$l['propio']): ?>
                <br><small class="text-muted">Usando el máximo del banco</small>
              <?php endif; ?>
            </div>
            <div class="text-right">
              <small class="text-muted d-block">Usado hoy</small>
              <strong data-usado>$<?= number_format((int) $l['usado_hoy_cent'] / 100, 2) ?></strong>
              <small class="text-muted">de
                $<?= number_format((int) $l['max_diario_cent'] / 100, 2) ?></small>
            </div>
          </div>

          <div class="form-row mt-3">
            <div class="form-group col-md-4 mb-2">
              <label style="font-size:.82rem">Por operación</label>
              <div class="input-group input-group-sm">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" data-campo="operacion"
                       value="<?= number_format((int) $l['max_operacion_cent'] / 100, 2, '.', '') ?>"
                       inputmode="decimal">
              </div>
              <small class="text-muted">
                máx. $<?= number_format((int) $l['techo_operacion_cent'] / 100, 2) ?>
              </small>
            </div>

            <div class="form-group col-md-4 mb-2">
              <label style="font-size:.82rem">Por día</label>
              <div class="input-group input-group-sm">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" data-campo="diario"
                       value="<?= number_format((int) $l['max_diario_cent'] / 100, 2, '.', '') ?>"
                       inputmode="decimal">
              </div>
              <small class="text-muted">
                máx. $<?= number_format((int) $l['techo_diario_cent'] / 100, 2) ?>
              </small>
            </div>

            <div class="form-group col-md-4 mb-2">
              <label style="font-size:.82rem">Operaciones al día</label>
              <input type="number" class="form-control form-control-sm" data-campo="ops"
                     min="1" max="<?= (int) $l['techo_ops_diarias'] ?>"
                     value="<?= (int) $l['max_ops_diarias'] ?>">
              <small class="text-muted">máx. <?= (int) $l['techo_ops_diarias'] ?></small>
            </div>
          </div>

          <!-- El código solo aparece si el cambio es una subida -->
          <div class="form-row d-none" data-otp>
            <div class="form-group col-md-6 mb-2">
              <label style="font-size:.82rem">Código de verificación</label>
              <input type="text" class="form-control form-control-sm" data-campo="codigo"
                     inputmode="numeric" maxlength="6" autocomplete="one-time-code">
            </div>
          </div>

          <button class="btn btn-sm btn-primary" data-guardar>Guardar</button>
          <span class="text-muted ml-2" style="font-size:.82rem" data-aviso></span>

        </div>
        <?php endforeach; ?>

      </div>
    </div>

  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var URL_GUARDAR = <?= P::js($rutas->url('otros-bancos/limites')) ?>;
  var URL_CODIGO  = <?= P::js($rutas->url('otros-bancos/limites/codigo')) ?>;
  /* La semilla: el token con el que se pintó la pantalla.

     NO se usa directamente. Los tokens son de un solo uso, así
     que esta copia se gasta en la primera acción y de la segunda
     en adelante todo fallaría con «el token de seguridad venció».
     El vigente lo mantiene `token.js`. */
  var CSRF_SEMILLA = <?= P::js($csrf) ?>;

  function tokenVigente() {
    return (window.CMToken && window.CMToken.de('interbancario')) || CSRF_SEMILLA;
  }


  /* Lo que rige ahora mismo, para saber si un cambio es subida sin
     tener que preguntárselo al servidor en cada tecla. */
  var VIGENTE = {};

  JSON.parse(<?= P::js(json_encode($limites, JSON_UNESCAPED_UNICODE)) ?>).forEach(function (l) {
    VIGENTE[l.canal] = l;
  });

  function cent(valor) {
    var n = parseFloat(String(valor).replace(/[^0-9.]/g, ''));
    return isNaN(n) ? 0 : Math.round(n * 100);
  }

  function campo(caja, nombre) {
    return caja.querySelector('[data-campo="' + nombre + '"]');
  }

  function esSubida(caja, canal) {
    var v = VIGENTE[canal];
    if (!v) { return true; }

    return cent(campo(caja, 'operacion').value) > v.max_operacion_cent
        || cent(campo(caja, 'diario').value)    > v.max_diario_cent
        || parseInt(campo(caja, 'ops').value, 10) > v.max_ops_diarias;
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-canal]'), function (caja) {
    var canal = caja.getAttribute('data-canal');
    var otp   = caja.querySelector('[data-otp]');
    var aviso = caja.querySelector('[data-aviso]');

    /* Mientras teclea se le dice si va a necesitar código, en vez de
       descubrirlo al pulsar Guardar. */
    function revisar() {
      var sube = esSubida(caja, canal);

      otp.classList.toggle('d-none', !sube);

      aviso.textContent = sube
        ? 'Está subiendo el límite: hace falta un código.'
        : '';

      return sube;
    }

    ['operacion', 'diario', 'ops'].forEach(function (n) {
      campo(caja, n).addEventListener('input', revisar);
    });

    revisar();

    caja.querySelector('[data-guardar]').addEventListener('click', function () {
      var boton = this;
      var sube  = revisar();
      var codigo = sube ? campo(caja, 'codigo').value.trim() : '';

      /* Si sube y aún no pidió el código, se le pide ahora: un botón
         que no hace nada es peor que uno que explica qué falta. */
      if (sube && codigo === '') {
        pedirCodigo(caja, canal, boton);
        return;
      }

      boton.disabled = true;

      var fd = new FormData();
      fd.append('_csrf', tokenVigente());
      fd.append('_csrf_proposito', 'interbancario');
      fd.append('canal', canal);
      fd.append('max_operacion', (cent(campo(caja, 'operacion').value) / 100).toFixed(2));
      fd.append('max_diario', (cent(campo(caja, 'diario').value) / 100).toFixed(2));
      fd.append('max_ops', campo(caja, 'ops').value);
      fd.append('codigo', codigo);

      fetch(URL_GUARDAR, {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          boton.disabled = false;

          if (!j.ok) { window.avisar('No se pudo guardar', j.mensaje, 'critico'); return; }

          var d = j.datos || {};

          (d.limites || []).forEach(function (l) { VIGENTE[l.canal] = l; });

          /* Si el banco recortó la petición, los campos tienen que
             mostrar lo que de verdad quedó guardado, no lo que se
             pidió: si no, el cliente cree que tiene un límite que no
             tiene. */
          if (d.max_operacion_cent) {
            campo(caja, 'operacion').value = (d.max_operacion_cent / 100).toFixed(2);
            campo(caja, 'diario').value    = (d.max_diario_cent / 100).toFixed(2);
            campo(caja, 'ops').value       = d.max_ops_diarias;
          }

          campo(caja, 'codigo').value = '';
          revisar();

          window.avisar(
            d.recortado ? 'Guardado con ajuste' : 'Límites actualizados',
            j.mensaje,
            d.recortado ? 'alerta' : 'exito'
          );

          if (window.Vivo) { window.Vivo.refrescar(); }
        })
        .catch(function () {
          boton.disabled = false;
          window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
        });
    });
  });

  function pedirCodigo(caja, canal, boton) {
    boton.disabled = true;

    var fd = new FormData();
    fd.append('_csrf', tokenVigente());
    fd.append('_csrf_proposito', 'interbancario');
    fd.append('canal', canal);
    fd.append('max_operacion', (cent(campo(caja, 'operacion').value) / 100).toFixed(2));
    fd.append('max_diario', (cent(campo(caja, 'diario').value) / 100).toFixed(2));
    fd.append('max_ops', campo(caja, 'ops').value);

    fetch(URL_CODIGO, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        boton.disabled = false;

        if (!j.ok) { window.avisar('No se pudo', j.mensaje, 'critico'); return; }

        caja.querySelector('[data-otp]').classList.remove('d-none');
        campo(caja, 'codigo').focus();

        window.avisar('Código enviado',
          'Escriba el código y pulse Guardar otra vez.', 'exito');
      })
      .catch(function () {
        boton.disabled = false;
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  }
})();
</script>
