<?php
/**
 * Los destinos guardados.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $beneficiarios
 * @var array $bancos
 * @var int $enfriamiento
 * @var string $csrf
 */

use CashMan\Http\Plantilla as P;
?>
<div class="row">

  <div class="col-lg-7 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Mis destinos</h4></div>
      <div class="card-body">

        <div class="alert alert-info" style="font-size:.88rem">
          <strong>Por qué hay una espera.</strong>
          Un destino nuevo puede recibir dinero
          <strong><?= (int) $enfriamiento ?> horas</strong> después de registrarlo.
          En ese tiempo le avisamos por sus canales, de modo que si alguien
          entrara a su cuenta y registrara un destino suyo, usted pueda
          bloquearlo antes de que se mueva un centavo.
          <br><br>
          Sus propias cuentas de CashMan H.A. no tienen espera.
        </div>

        <div id="lista-destinos"></div>

      </div>
    </div>
  </div>

  <div class="col-lg-5 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Agregar un destino</h4></div>
      <div class="card-body">

        <form id="form-benef">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="interbancario">

          <div class="form-group">
            <label for="alias">¿Cómo quiere llamarlo?</label>
            <input type="text" class="form-control" id="alias" name="alias"
                   maxlength="60" placeholder="Mamá, Alquiler, Proveedor…" required>
          </div>

          <div class="form-group">
            <label for="banco_id">Institución</label>
            <select class="form-control" id="banco_id" name="banco_id" required>
              <?php foreach ($bancos as $b): ?>
              <option value="<?= (int) $b['id'] ?>"
                      data-movil="<?= (int) $b['transfer365_movil'] ?>"
                      data-propio="<?= (int) $b['es_propio'] ?>">
                <?= P::e($b['nombre_corto']) ?><?= (int) $b['es_propio'] === 1 ? ' (mi banco)' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label>¿Cómo lo va a identificar?</label>
            <div class="btn-group btn-block" role="group">
              <button type="button" class="btn btn-outline-primary active" id="modo-cuenta">
                Número de cuenta
              </button>
              <button type="button" class="btn btn-outline-primary" id="modo-celular">
                Celular
              </button>
            </div>
          </div>

          <div class="form-group" id="campo-cuenta">
            <label for="numero_cuenta">Número de cuenta</label>
            <input type="text" class="form-control" id="numero_cuenta" name="numero_cuenta"
                   inputmode="numeric" maxlength="24" autocomplete="off" spellcheck="false">
            <small class="form-text text-muted">Entre 8 y 20 dígitos, sin espacios ni guiones.</small>
          </div>

          <div class="form-group d-none" id="campo-celular">
            <label for="celular">Celular</label>
            <input type="text" class="form-control" id="celular" name="celular"
                   inputmode="tel" maxlength="13" placeholder="7123-4567" autocomplete="off">
            <small class="form-text text-muted">
              Solo funciona si el titular asoció ese celular a su cuenta en su banco.
            </small>
          </div>

          <div class="form-group">
            <label for="tipo_cuenta">Tipo de cuenta</label>
            <select class="form-control" id="tipo_cuenta" name="tipo_cuenta">
              <option value="ahorro">Ahorro</option>
              <option value="corriente">Corriente</option>
            </select>
          </div>

          <div class="form-group">
            <label for="titular_nombre">Nombre del titular</label>
            <input type="text" class="form-control" id="titular_nombre" name="titular_nombre"
                   maxlength="140" required>
            <small class="form-text text-muted">
              Como aparece en el banco destino. Si no coincide, la institución
              receptora puede devolver el envío.
            </small>
          </div>

          <div class="form-group">
            <label for="titular_documento">DUI o NIT <span class="text-muted">(opcional)</span></label>
            <input type="text" class="form-control" id="titular_documento"
                   name="titular_documento" maxlength="20" autocomplete="off">
          </div>

          <button type="button" class="btn btn-outline-primary btn-block" id="btn-codigo-benef">
            Solicitar código de verificación
          </button>

          <div class="d-none mt-3" id="bloque-otp-benef">
            <div class="form-group">
              <label for="codigo-benef">Código de verificación</label>
              <input type="text" class="form-control" id="codigo-benef" name="codigo"
                     inputmode="numeric" maxlength="6" autocomplete="one-time-code">
              <small class="form-text text-muted" id="info-otp-benef"></small>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-block mt-3" id="btn-guardar" disabled>
            Guardar destino
          </button>
        </form>

      </div>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var form = document.getElementById('form-benef');
  if (!form) { return; }

  var URL_GUARDAR = <?= P::js($rutas->url('otros-bancos/agenda')) ?>;
  var URL_CODIGO  = <?= P::js($rutas->url('otros-bancos/agenda/codigo')) ?>;
  var BASE        = <?= P::js($rutas->url('otros-bancos/agenda/')) ?>;
  /* La semilla: el token con el que se pintó la pantalla.

     NO se usa directamente. Los tokens son de un solo uso, así
     que esta copia se gasta en la primera acción y de la segunda
     en adelante todo fallaría con «el token de seguridad venció».
     El vigente lo mantiene `token.js`. */
  var CSRF_SEMILLA = <?= P::js($csrf) ?>;

  function tokenVigente() {
    return (window.CMToken && window.CMToken.de('interbancario')) || CSRF_SEMILLA;
  }


  var AGENDA = JSON.parse(<?= P::js(json_encode($beneficiarios, JSON_UNESCAPED_UNICODE)) ?>);

  function esc(t) {
    return String(t == null ? '' : t)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // --- La lista, repintada desde los datos ---------------------------
  function pintar(lista) {
    AGENDA = lista;

    var caja = document.getElementById('lista-destinos');

    if (!lista.length) {
      caja.innerHTML = '<p class="text-muted mb-0">Todavía no tiene destinos guardados.</p>';
      return;
    }

    var html = '<div class="table-responsive"><table class="table table-sm mb-0">'
             + '<thead><tr><th>Destino</th><th>Institución</th>'
             + '<th class="text-center">Estado</th><th></th></tr></thead><tbody>';

    lista.forEach(function (b) {
      var estado;

      if (b.estado === 'bloqueado') {
        estado = '<span class="badge badge-danger">bloqueado</span>';
      } else if (b.usable) {
        estado = '<span class="badge badge-success">listo</span>';
      } else {
        var h = Math.floor(b.faltan_min / 60);
        var m = b.faltan_min % 60;

        estado = '<span class="badge badge-warning">en espera</span>'
               + '<br><small class="text-muted">faltan '
               + (h > 0 ? h + ' h ' + m + ' min' : m + ' min') + '</small>';
      }

      html += '<tr>'
        + '<td><strong>' + esc(b.alias) + '</strong><br>'
        + '<small class="text-muted">' + esc(b.titular) + ' · ' + esc(b.destino)
        + (b.por_celular ? ' <span class="badge badge-light">celular</span>' : '')
        + '</small></td>'
        + '<td>' + esc(b.banco) + '</td>'
        + '<td class="text-center">' + estado + '</td>'
        + '<td class="text-right">'
        + (b.estado !== 'bloqueado'
            ? '<button class="btn btn-sm btn-outline-warning mr-1" data-bloquear="'
              + esc(b.uuid) + '">Bloquear</button>'
            : '')
        + '<button class="btn btn-sm btn-outline-danger" data-eliminar="'
        + esc(b.uuid) + '">Eliminar</button>'
        + '</td></tr>';
    });

    caja.innerHTML = html + '</tbody></table></div>';
  }

  pintar(AGENDA);

  /* Un solo manejador para toda la tabla: así los botones que se pintan
     después siguen funcionando sin volver a enganchar nada. */
  document.getElementById('lista-destinos').addEventListener('click', function (ev) {
    var bloquear = ev.target.getAttribute && ev.target.getAttribute('data-bloquear');
    var eliminar = ev.target.getAttribute && ev.target.getAttribute('data-eliminar');

    if (bloquear) { accion(bloquear, 'bloquear', '¿Bloquear este destino?'); }
    if (eliminar) { accion(eliminar, 'eliminar', '¿Eliminar este destino de su agenda?'); }
  });

  function accion(uuid, cual, pregunta) {
    window.CMAviso.confirmar({
      titulo: pregunta,
      texto: cual === 'eliminar'
        ? 'Las transferencias que ya le hizo no se borran: siguen en su historial.'
        : 'Podrá eliminarlo después si quiere.',
      alAceptar: function () {
        var fd = new FormData();
        fd.append('_csrf', tokenVigente());
        fd.append('_csrf_proposito', 'interbancario');

        fetch(BASE + uuid + '/' + cual, {
          method: 'POST', body: fd, credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!j.ok) { window.avisar('No se pudo', j.mensaje, 'critico'); return; }

            pintar((j.datos && j.datos.agenda) || []);
            window.avisar('Listo', j.mensaje, 'exito');

            if (window.Vivo) { window.Vivo.refrescar(); }
          })
          .catch(function () {
            window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
          });
      }
    });
  }

  // --- Cuenta o celular ----------------------------------------------
  var porCelular = false;

  function modo(celular) {
    porCelular = celular;

    document.getElementById('campo-cuenta').classList.toggle('d-none', celular);
    document.getElementById('campo-celular').classList.toggle('d-none', !celular);

    document.getElementById('modo-cuenta').classList.toggle('active', !celular);
    document.getElementById('modo-celular').classList.toggle('active', celular);
  }

  document.getElementById('modo-cuenta').addEventListener('click', function () { modo(false); });
  document.getElementById('modo-celular').addEventListener('click', function () { modo(true); });

  // --- El código ------------------------------------------------------
  var btnCod  = document.getElementById('btn-codigo-benef');
  var btnGuar = document.getElementById('btn-guardar');

  btnCod.addEventListener('click', function () {
    btnCod.disabled = true;
    btnCod.textContent = 'Enviando…';

    var fd = new FormData();
    fd.append('_csrf', tokenVigente());
    fd.append('_csrf_proposito', 'interbancario');
    fd.append('banco_id', document.getElementById('banco_id').value);
    fd.append('numero_cuenta', porCelular ? '' : document.getElementById('numero_cuenta').value);
    fd.append('celular', porCelular ? document.getElementById('celular').value : '');

    fetch(URL_CODIGO, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        btnCod.disabled = false;
        btnCod.textContent = 'Reenviar código';

        if (!j.ok) { window.avisar('No se pudo', j.mensaje, 'critico'); return; }

        document.getElementById('bloque-otp-benef').classList.remove('d-none');
        btnGuar.disabled = false;

        document.getElementById('info-otp-benef').textContent =
          'Enviado a ' + ((j.datos && j.datos.enviado_a) || 'su correo');

        document.getElementById('codigo-benef').focus();

        window.avisar('Código enviado', j.mensaje, 'exito');
      })
      .catch(function () {
        btnCod.disabled = false;
        btnCod.textContent = 'Solicitar código de verificación';
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  });

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();

    btnGuar.disabled = true;

    var fd = new FormData(form);

    /* El que no se esté usando va vacío: el servidor decide por lo que
       le llega, no por lo que esté visible en la pantalla. */
    fd.set('numero_cuenta', porCelular ? '' : document.getElementById('numero_cuenta').value);
    fd.set('celular', porCelular ? document.getElementById('celular').value : '');

    fetch(URL_GUARDAR, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        btnGuar.disabled = false;

        if (!j.ok) { window.avisar('No se pudo guardar', j.mensaje, 'critico'); return; }

        pintar((j.datos && j.datos.agenda) || AGENDA);

        form.reset();
        modo(false);
        document.getElementById('bloque-otp-benef').classList.add('d-none');
        btnGuar.disabled = true;
        btnCod.textContent = 'Solicitar código de verificación';

        window.avisar('Destino guardado', j.mensaje, 'exito');

        if (window.Vivo) { window.Vivo.refrescar(); }
      })
      .catch(function () {
        btnGuar.disabled = false;
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  });
})();
</script>
