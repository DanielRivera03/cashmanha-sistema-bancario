<?php
/**
 * Constancias desde atención al cliente.
 *
 * Esta pantalla es para cuando el cliente está de pie en el mostrador.
 * Se le busca, se prepara su constancia y sale un folio que lleva a la
 * caja para pagarlo.
 *
 * POR QUÉ NO SE COBRA AQUÍ
 *
 * Porque atención al cliente no tiene gaveta ni arqueo. El efectivo lo
 * recibe la caja y solo la caja: un módulo que cobrara en efectivo sin
 * cuadrar al final del día sería un agujero por donde el dinero sale
 * sin que nadie lo note.
 *
 * POR QUÉ LA CONSTANCIA NO SALE HASTA QUE SE PAGA
 *
 * Porque la constancia ES el producto. Emitirla y cobrar después
 * dejaría un documento auténtico circulando con el cobro pendiente, y
 * anularlo con el cliente ya enseñándolo no es una opción.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $tipos
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;
?>

<div class="row">
  <div class="col-xl-5 col-lg-6 col-md-12">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title mb-0">¿Para quién es?</h4>
      </div>
      <div class="card-body">
        <form id="form-buscar">
          <div class="form-group mb-0">
            <label for="busca">Documento o número de cuenta</label>
            <div class="input-group">
              <input type="text" class="form-control" id="busca" name="texto"
                     placeholder="04212345-6 o 106741235" autocomplete="off" required>
              <div class="input-group-append">
                <button class="btn btn-primary" type="submit" id="btn-buscar">Buscar</button>
              </div>
            </div>
          </div>
        </form>

        <div id="ficha" class="mt-3 d-none">
          <div class="border rounded p-3">
            <strong id="ficha-nombre"></strong>
            <br><small class="text-muted" id="ficha-documento"></small>
          </div>

          <div id="ficha-pendientes" class="mt-2"></div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h4 class="card-title mb-0">Cómo funciona</h4>
      </div>
      <div class="card-body" style="font-size:.88rem">
        <p><strong>1.</strong> Se busca al cliente y se prepara la constancia.</p>
        <p><strong>2.</strong> Sale un folio. El cliente pasa a caja y paga.</p>
        <p><strong>3.</strong> El cajero cobra y la constancia se emite e imprime
           en ese momento.</p>

        <p class="mb-0 text-muted">Las que no llevan arancel —la de no adeudo— se
           emiten aquí mismo: mandar a alguien a hacer cola para pagar cero no
           tiene sentido.</p>
      </div>
    </div>
  </div>

  <div class="col-xl-7 col-lg-6 col-md-12">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title mb-0">La constancia</h4>
      </div>
      <div class="card-body">
        <div id="sin-cliente" class="text-muted">
          Busque primero al cliente.
        </div>

        <form id="form-solicitud" class="d-none">
          <div class="form-group">
            <label for="tipo">¿Qué constancia necesita?</label>
            <select class="form-control" id="tipo" name="tipo" required>
              <?php foreach ($tipos as $t): ?>
              <option value="<?= P::attr((string) $t['tipo']) ?>"
                      data-comision="<?= (int) $t['comision_cent'] ?>"
                      data-desc="<?= P::attr((string) $t['descripcion']) ?>"
                      data-objeto="<?= P::attr(match ((string) $t['tipo']) {
                          'saldo'               => 'cuenta',
                          'referencia_bancaria' => 'cuenta',
                          'solvencia'           => 'credito',
                          default               => '',
                      }) ?>">
                <?= P::e((string) $t['nombre']) ?>
                <?= (int) $t['comision_cent'] > 0
                      ? '— $' . number_format((int) $t['comision_cent'] / 100, 2)
                      : '— sin costo' ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small class="form-text text-muted" id="desc-tipo"></small>
          </div>

          <div class="form-group d-none" id="grupo-cuenta">
            <label for="obj-cuenta">¿Sobre cuál de sus cuentas?</label>
            <select class="form-control" id="obj-cuenta"></select>
          </div>

          <div class="form-group d-none" id="grupo-credito">
            <label for="obj-credito">¿Sobre cuál de sus créditos?</label>
            <select class="form-control" id="obj-credito"></select>
          </div>

          <div class="form-group">
            <label for="dirigida_a">¿A quién va dirigida?</label>
            <input type="text" class="form-control" id="dirigida_a" name="dirigida_a"
                   maxlength="160" placeholder="Embajada, patrono, otro banco…">
            <small class="form-text text-muted">
              Una constancia «a quien interese» vale menos que una con destinatario.
            </small>
          </div>

          <div class="form-group">
            <label for="proposito">¿Para qué la necesita?</label>
            <input type="text" class="form-control" id="proposito" name="proposito"
                   maxlength="255" placeholder="Trámite de visa, solicitud de empleo…">
          </div>

          <div class="alert alert-light border" id="aviso-pago">
            <span id="texto-pago"></span>
          </div>

          <button type="submit" class="btn btn-primary btn-block" id="btn-generar">
            Enviar a caja
          </button>
        </form>

        <div id="resultado" class="mt-3"></div>
      </div>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var URL_BUSCAR    = <?= P::js($rutas->url('constancias/buscar')) ?>;
  var URL_SOLICITAR = <?= P::js($rutas->url('constancias/solicitar')) ?>;
  var BASE_PDF      = <?= P::js($rutas->url('constancias/')) ?>;

  var CSRF_SEMILLA = <?= P::js($csrf) ?>;

  function tokenVigente() {
    return (window.CMToken && window.CMToken.de('constancias-agencia')) || CSRF_SEMILLA;
  }

  function esc(t) {
    return String(t == null ? '' : t)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  var CLIENTE = null;

  var formBuscar    = document.getElementById('form-buscar');
  var formSolicitud = document.getElementById('form-solicitud');
  var tipo          = document.getElementById('tipo');

  // --- Buscar al cliente -----------------------------------------------
  formBuscar.addEventListener('submit', function (ev) {
    ev.preventDefault();

    var boton = document.getElementById('btn-buscar');
    boton.disabled = true;

    var fd = new FormData(formBuscar);
    fd.set('_csrf', tokenVigente());

    fetch(URL_BUSCAR, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        boton.disabled = false;

        if (!j.ok) {
          window.avisar('No encontrado', j.mensaje, 'alerta');
          return;
        }

        CLIENTE = j.datos || null;
        pintarFicha();
      })
      .catch(function () {
        boton.disabled = false;
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  });

  function pintarFicha() {
    if (!CLIENTE) { return; }

    document.getElementById('ficha').classList.remove('d-none');
    document.getElementById('ficha-nombre').textContent    = CLIENTE.nombre || '';
    document.getElementById('ficha-documento').textContent = CLIENTE.documento || '';

    /* Lo que ya tiene esperando en caja. Sin esto, atención le
       generaría una segunda solicitud al cliente que ya tiene una sin
       pagar, y acabaría pagando dos veces por el mismo papel. */
    var pend = (CLIENTE.pendientes || []).filter(function (p) {
      return p.estado === 'pendiente_pago';
    });

    var caja = document.getElementById('ficha-pendientes');

    if (!pend.length) {
      caja.innerHTML = '';
    } else {
      var h = '<div class="alert alert-warning mb-0" style="font-size:.86rem">'
            + '<strong>Ya tiene ' + pend.length
            + (pend.length === 1 ? ' solicitud' : ' solicitudes')
            + ' esperando pago en caja:</strong><br>';

      pend.forEach(function (p) {
        h += '<span class="cm-mono">' + esc(p.folio) + '</span> · '
           + esc(p.tipo_nombre) + ' · $'
           + (Number(p.comision_cent || 0) / 100).toFixed(2) + '<br>';
      });

      caja.innerHTML = h + '</div>';
    }

    // Las cuentas y créditos del cliente, en sus desplegables.
    var selC = document.getElementById('obj-cuenta');
    selC.innerHTML = '';

    (CLIENTE.cuentas || []).forEach(function (c) {
      var o = document.createElement('option');
      o.value = c.codigo;
      o.textContent = c.numero_cuenta + ' — ' + c.producto;
      selC.appendChild(o);
    });

    var selK = document.getElementById('obj-credito');
    selK.innerHTML = '';

    (CLIENTE.creditos || []).forEach(function (k) {
      var o = document.createElement('option');
      o.value = k.codigo;
      o.textContent = k.folio + ' — ' + k.producto;
      selK.appendChild(o);
    });

    document.getElementById('sin-cliente').classList.add('d-none');
    formSolicitud.classList.remove('d-none');

    ajustar();
  }

  // --- Qué pide cada tipo ----------------------------------------------
  function ajustar() {
    var o = tipo.options[tipo.selectedIndex];
    if (!o) { return; }

    var necesita = o.getAttribute('data-objeto') || '';
    var comision = parseInt(o.getAttribute('data-comision'), 10) || 0;

    document.getElementById('grupo-cuenta').classList.toggle('d-none', necesita !== 'cuenta');
    document.getElementById('grupo-credito').classList.toggle('d-none', necesita !== 'credito');
    document.getElementById('desc-tipo').textContent = o.getAttribute('data-desc') || '';

    document.getElementById('texto-pago').textContent = comision > 0
      ? 'El cliente paga $' + (comision / 100).toFixed(2)
        + ' en caja. La constancia se emite y se le entrega ahí mismo.'
      : 'Esta constancia no lleva arancel: se emite aquí mismo, sin pasar por caja.';

    document.getElementById('btn-generar').textContent = comision > 0
      ? 'Enviar a caja por $' + (comision / 100).toFixed(2)
      : 'Emitir ahora';
  }

  tipo.addEventListener('change', ajustar);
  ajustar();

  // --- Generar ----------------------------------------------------------
  formSolicitud.addEventListener('submit', function (ev) {
    ev.preventDefault();

    if (!CLIENTE) { return; }

    var o = tipo.options[tipo.selectedIndex];
    var necesita = o.getAttribute('data-objeto') || '';

    var boton = document.getElementById('btn-generar');
    boton.disabled = true;

    var fd = new FormData(formSolicitud);
    fd.set('_csrf', tokenVigente());
    fd.set('cliente', CLIENTE.codigo);

    if (necesita === 'cuenta') {
      var sc = document.getElementById('obj-cuenta');

      if (!sc.value) {
        boton.disabled = false;
        window.avisar('Sin cuentas', 'Este cliente no tiene cuentas activas que certificar.', 'alerta');
        return;
      }

      fd.set('objeto', sc.value);
      fd.set('objeto_espacio', 'cuenta');
    } else if (necesita === 'credito') {
      var sk = document.getElementById('obj-credito');

      if (!sk.value) {
        boton.disabled = false;
        window.avisar('Sin créditos', 'Este cliente no tiene créditos sobre los que certificar solvencia.', 'alerta');
        return;
      }

      fd.set('objeto', sk.value);
      fd.set('objeto_espacio', 'credito');
    }

    fetch(URL_SOLICITAR, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        boton.disabled = false;

        if (!j.ok) { window.avisar('No se pudo', j.mensaje, 'critico'); return; }

        var d = j.datos || {};

        window.avisar('Listo', j.mensaje, 'exito');

        /* El folio, grande. Es lo que el empleado le dicta al cliente
           para que lo lleve a la ventanilla; en letra pequeña se
           dictaría mal. */
        document.getElementById('resultado').innerHTML = d.directa
          ? '<div class="alert alert-success mb-0">'
            + '<strong>Constancia emitida.</strong><br>'
            + '<a class="btn btn-sm btn-outline-primary mt-2" target="_blank" '
            + 'rel="noopener" href="' + esc(BASE_PDF + d.folio + '/pdf') + '">Imprimir</a>'
            + '</div>'
          : '<div class="alert alert-info mb-0">'
            + '<small class="d-block">Dígale al cliente que pase a caja con este folio</small>'
            + '<span class="cm-mono" style="font-size:1.25rem;letter-spacing:.06em">'
            + esc(d.folio) + '</span>'
            + '<br><small>$' + (Number(d.comision_cent || 0) / 100).toFixed(2)
            + ' · vence en ' + esc(String(d.horas || 24)) + ' horas</small>'
            + '</div>';

        formSolicitud.reset();
        ajustar();

        if (window.Vivo) { window.Vivo.refrescar(); }
      })
      .catch(function () {
        boton.disabled = false;
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  });
})();
</script>
