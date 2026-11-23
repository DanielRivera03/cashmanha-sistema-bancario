<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $cuentas
 * @var array|null $limite
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$maxOp  = (int) ($limite['max_por_operacion_cent'] ?? 0);
$maxDia = (int) ($limite['max_diario_cent'] ?? 0);
$reqOtp = (bool) ($limite['requiere_otp'] ?? true);
?>
<div class="row">

  <div class="col-lg-7 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title">Nueva transferencia</h4></div>
      <div class="card-body">

        <?php if ($cuentas === []): ?>
          <div class="alert alert-warning mb-0">No tiene cuentas activas para transferir.</div>
        <?php else: ?>
        <form id="form-trf">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="transferencia">
          <input type="hidden" name="_idem" id="idem" value="">

          <div class="form-group">
            <label for="origen">Cuenta de origen</label>
            <select class="form-control" id="origen" name="origen" required>
              <?php foreach ($cuentas as $c): ?>
              <option value="<?= P::attr($c['codigo']) ?>" data-disp="<?= (int) $c['disponible_cent'] ?>">
                <?= P::e($c['numero_cuenta']) ?> &mdash; <?= P::e($c['producto']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small class="form-text text-muted" id="disp-origen"></small>
          </div>

          <div class="form-group">
            <label for="num-destino">Cuenta destino</label>
            <input type="text" class="form-control" id="num-destino" inputmode="numeric"
                   placeholder="Número de cuenta" autocomplete="off" spellcheck="false">
            <input type="hidden" name="destino" id="destino">

            <!-- ----------------------------------------------------------
                 Resultado de la validación.

                 Antes esto era un `<small>` de una línea. Quien tecleaba
                 una cuenta inexistente no se enteraba: el aviso cabía en
                 un renglón gris y pasaba desapercibido, y luego el botón
                 del código "no hacía nada" —en realidad avisaba de que
                 faltaba validar la cuenta—. Ahora el estado se ve: a
                 quién pertenece la cuenta, o que no existe.
                 ---------------------------------------------------------- -->
            <div id="destino-estado" class="cm-destino" hidden>
              <div class="cm-destino__icono" id="destino-icono"></div>
              <div class="cm-destino__texto">
                <b id="destino-titulo"></b>
                <span id="destino-detalle"></span>
              </div>
            </div>

            <small class="form-text text-muted" id="info-destino">
              Al escribir el número comprobamos a quién pertenece la cuenta.
            </small>
          </div>

          <div class="form-group">
            <label for="monto">Monto (USD)</label>
            <div class="input-group">
              <div class="input-group-prepend"><span class="input-group-text">$</span></div>
              <input type="text" class="form-control" id="monto" name="monto"
                     inputmode="decimal" placeholder="0.00" required autocomplete="off">
            </div>
            <small class="form-text text-muted">
              Máximo por operación <strong><?= P::e(P::money($maxOp)) ?></strong> &middot;
              diario <strong><?= P::e(P::money($maxDia)) ?></strong>.
              Ambos los valida el servidor.
            </small>
          </div>

          <div class="form-group">
            <label for="concepto">Concepto</label>
            <input type="text" class="form-control" id="concepto" name="concepto"
                   maxlength="160" placeholder="Motivo de la transferencia">
          </div>

          <?php if ($reqOtp): ?>
          <div id="bloque-otp" class="d-none">
            <div class="form-group">
              <label for="codigo-otp">Código de verificación</label>
              <input type="text" class="form-control text-center" id="codigo-otp" name="codigo"
                     inputmode="numeric" maxlength="6" autocomplete="one-time-code"
                     placeholder="000000"
                     style="font-size:1.5rem;letter-spacing:.55em;font-family:Consolas,monospace">
              <small class="form-text text-muted" id="info-otp"></small>
            </div>
          </div>

          <button type="button" class="btn btn-outline-primary btn-block mb-3" id="btn-codigo">
            Solicitar código de verificación
          </button>
          <?php endif; ?>

          <button type="submit" class="btn btn-primary btn-block" id="btn-trf"
                  <?= $reqOtp ? 'disabled' : '' ?>>Transferir</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5 col-md-12">
    <!-- ---------- EL SALDO, EN VIVO ----------
         Antes había que salir de esta pantalla para ver cuánto quedaba
         disponible, y volver. Aquí el detalle se refresca solo por el
         canal en tiempo real: en cuanto entra o sale dinero —incluida
         la transferencia que se acaba de hacer— la cifra cambia sin
         recargar. -->
    <?php foreach ($cuentas as $cu): ?>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0"><?= P::e($cu['numero_cuenta']) ?></h4>
        <small class="text-muted cm-envivo">
          <span class="cm-envivo__punto"></span> en vivo
        </small>
      </div>
      <div class="card-body">
        <small class="text-muted d-block">Disponible para transferir</small>
        <div class="font-weight-bold text-primary mb-3" style="font-size:1.8rem;line-height:1.1"
             data-vivo="disponible" data-cuenta="<?= P::attr($cu['codigo']) ?>">
          <?= P::e(P::money((int) $cu['disponible_cent'])) ?>
        </div>

        <div class="row">
          <div class="col-6 mb-2">
            <small class="text-muted d-block">Saldo contable</small>
            <span class="font-weight-bold"
                  data-vivo="saldo" data-cuenta="<?= P::attr($cu['codigo']) ?>">
              <?= P::e(P::money((int) $cu['saldo_cent'])) ?>
            </span>
          </div>
          <div class="col-6 mb-2">
            <small class="text-muted d-block">Retenido</small>
            <span data-vivo="retenido" data-cuenta="<?= P::attr($cu['codigo']) ?>">
              <?= P::e(P::money((int) ($cu['saldo_retenido_cent'] ?? 0))) ?>
            </span>
          </div>
          <div class="col-6">
            <small class="text-muted d-block">Saldo mínimo</small>
            <span data-vivo="minimo" data-cuenta="<?= P::attr($cu['codigo']) ?>">
              <?= P::e(P::money((int) ($cu['saldo_minimo_cent'] ?? 0))) ?>
            </span>
          </div>
          <div class="col-6">
            <small class="text-muted d-block">Producto</small>
            <span style="font-size:.86rem"><?= P::e($cu['producto'] ?? '') ?></span>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="card">
      <div class="card-header"><h4 class="card-title">Antes de confirmar</h4></div>
      <div class="card-body text-muted" style="font-size:.9rem">
        <p>Le pedimos revisar con cuidado el número de la cuenta destino: una
           transferencia procesada no se revierte sola, y recuperarla depende
           de que la otra persona acceda a devolverla.</p>
        <p>En cada operación el sistema comprueba:</p>
        <ul class="pl-3">
          <li>Que la cuenta de origen sea suya</li>
          <li>Que haya fondos suficientes</li>
          <li>Los límites por operación, diarios y mensuales</li>
          <li>El código de verificación de un solo uso</li>
        </ul>
        <p class="mb-0">Cada operación queda asentada en el libro mayor con su
           referencia única, y puede descargar el comprobante cuando quiera.</p>
      </div>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';
  var form = document.getElementById('form-trf');
  if (!form) return;

  var numDest    = document.getElementById('num-destino');
  var destino    = document.getElementById('destino');
  var infoDest   = document.getElementById('info-destino');
  var btnCod     = document.getElementById('btn-codigo');
  var btnTrf     = document.getElementById('btn-trf');
  var bloqueOtp  = document.getElementById('bloque-otp');
  var origen     = document.getElementById('origen');
  var dispOrigen = document.getElementById('disp-origen');

  var URL_VALIDA = <?= P::js($rutas->url('transferir/validar-cuenta')) ?>;
  var URL_CODIGO = <?= P::js($rutas->url('transferir/codigo')) ?>;
  var URL_TRANSF = <?= P::js($rutas->url('transferir')) ?>;

  function nuevaIdem() {
    var a = new Uint8Array(16);
    crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  document.getElementById('idem').value = nuevaIdem();

  function actualizarDisponible() {
    var o = origen.options[origen.selectedIndex];
    if (o) dispOrigen.textContent = 'Disponible: ' +
      window.CMUtil.money(parseInt(o.getAttribute('data-disp'), 10) || 0);
  }
  origen.addEventListener('change', actualizarDisponible);
  actualizarDisponible();

  /* ------------------------------------------------------------------
     Validación de la cuenta destino.

     Tres estados visibles, no uno: comprobando, encontrada (con el
     titular) y no encontrada. El renglón gris de antes no se veía, y el
     cliente seguía adelante creyendo que la cuenta era buena.
     ------------------------------------------------------------------ */
  var estado   = document.getElementById('destino-estado');
  var icono    = document.getElementById('destino-icono');
  var titulo   = document.getElementById('destino-titulo');
  var detalle  = document.getElementById('destino-detalle');

  var SVG_OK = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
    'stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>';
  var SVG_NO = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
    'stroke-width="2.3" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>';
  var SVG_ESP = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
    'stroke-width="2.3" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';

  function pintarEstado(clase, svg, texto, sub) {
    estado.hidden    = false;
    estado.className = 'cm-destino cm-destino--' + clase;
    icono.innerHTML  = svg;
    titulo.textContent  = texto;
    detalle.textContent = sub || '';
  }

  function limpiarEstado() {
    estado.hidden = true;
    destino.value = '';
  }

  var t;
  numDest.addEventListener('input', function () {
    clearTimeout(t);
    limpiarEstado();

    var v = numDest.value.replace(/[^0-9]/g, '');

    if (v.length < 6) {
      if (v.length > 0) {
        pintarEstado('espera', SVG_ESP, 'Siga escribiendo',
          'Un número de cuenta tiene al menos 6 dígitos.');
      }
      return;
    }

    pintarEstado('espera', SVG_ESP, 'Comprobando la cuenta…', '');

    t = setTimeout(function () {
      var fd = new FormData();
      fd.append('numero', v);

      fetch(URL_VALIDA, { method:'POST', body:fd, credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          // La respuesta puede llegar tarde: si el número ya cambió, se
          // descarta en vez de pintar el titular de otra cuenta.
          if (numDest.value.replace(/[^0-9]/g, '') !== v) { return; }

          if (j.ok && j.datos) {
            destino.value = j.datos.codigo;
            pintarEstado('ok', SVG_OK, j.datos.titular,
              'Cuenta ' + j.datos.numero + ' · ' + (j.datos.moneda || 'USD'));
          } else {
            pintarEstado('no', SVG_NO, 'Esta cuenta no existe',
              j.mensaje || 'Verifique el número con la persona que la recibe.');
          }
        })
        .catch(function () {
          pintarEstado('no', SVG_NO, 'No se pudo comprobar',
            'Revise su conexión e intente de nuevo.');
        });
    }, 450);
  });

  if (btnCod) btnCod.addEventListener('click', function () {
    if (!destino.value) {
      window.avisar('Falta la cuenta destino',
        'Escriba un número de cuenta válido: le mostraremos a quién pertenece antes de continuar.',
        'alerta');
      numDest.focus();
      return;
    }
    var monto = document.getElementById('monto').value.trim();
    if (!monto) { window.avisar('Atención', 'Indique el monto.', 'alerta'); return; }

    btnCod.disabled = true; btnCod.textContent = 'Enviando…';
    var fd = new FormData();
    fd.append('_csrf', form._csrf.value);
    fd.append('_csrf_proposito', 'transferencia');
    fd.append('origen', origen.value);
    fd.append('destino', destino.value);
    fd.append('monto', monto);

    fetch(URL_CODIGO, { method:'POST', body:fd, credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        btnCod.disabled = false; btnCod.textContent = 'Reenviar código';
        if (j.ok) {
          bloqueOtp.classList.remove('d-none');
          btnTrf.disabled = false;
          document.getElementById('info-otp').textContent =
            'Enviado a ' + ((j.datos && j.datos.enviado_a) || 'su correo');
          document.getElementById('codigo-otp').focus();
          window.avisar('Código enviado', j.mensaje, 'exito');
        } else { window.avisar('Error', j.mensaje, 'critico'); }
      })
      .catch(function () {
        btnCod.disabled = false; btnCod.textContent = 'Solicitar código de verificación';
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  });

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (!destino.value) {
      window.avisar('Falta la cuenta destino',
        'Escriba un número de cuenta válido antes de transferir.', 'alerta');
      numDest.focus();
      return;
    }

    btnTrf.disabled = true;
    btnTrf.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>Procesando…';

    /* Los pasos de la operación, mientras corre.

       El botón gris no dice si se mandó, si se colgó o si conviene
       apretar otra vez; y apretar otra vez, en un movimiento de
       dinero, es la peor respuesta posible. */
    var pr = CMProceso.abrir('transferencia');
    pr.avanzar();

    fetch(URL_TRANSF, { method:'POST', body:new FormData(form), credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        btnTrf.disabled = false; btnTrf.textContent = 'Transferir';
        if (j.ok) { pr.listo(j.mensaje || ''); }
        else { pr.fallo(j.mensaje || 'No se pudo completar la operación.', 'validando'); }
        if (j.ok) {
          window.avisar('Transferencia realizada',
            j.mensaje + ' Ref: ' + ((j.datos && j.datos.referencia) || ''), 'exito');
          form.reset(); limpiarEstado();
          document.getElementById('idem').value = nuevaIdem();
          if (bloqueOtp) { bloqueOtp.classList.add('d-none'); btnTrf.disabled = true; }
          actualizarDisponible();
          if (window.Vivo) window.Vivo.refrescar();
        }
        /* El fallo ya lo dijo el panel de proceso, en rojo y sobre el
           paso que se rompió. Repetirlo aquí era decirlo dos veces. */
      })
      .catch(function () {
        btnTrf.disabled = false; btnTrf.textContent = 'Transferir';
        pr.fallo('No se pudo contactar con el servidor. Revise su conexión.');
      });
  });
})();
</script>
