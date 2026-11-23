<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array|null $limite
 * @var array $ultimos
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$maxOp  = (int) ($limite['max_por_operacion_cent'] ?? 0);
/* En ventanilla el cliente está delante y se identifica con su
   documento. El código se emitía contra el CAJERO: lo pedía él,
   le llegaba a él y lo tecleaba él, así que no probaba nada del
   cliente y solo alargaba la fila. `ServicioCaja` aplica la misma
   regla al recibir la petición, no solo esta pantalla. */
$reqOtp = (bool) ($limite['requiere_otp'] ?? true) && !($ventanilla ?? false);
$pre    = $cuenta ?? null;
?>
<?php
/* La tira del cliente al que se está atendiendo.
 *
 * Estas dos pantallas se llegaban en blanco desde el menú y pedían el
 * número de cuenta aunque el cajero viniera de la ficha del cliente,
 * donde acababa de pulsar sobre esa misma cuenta. Ahora que llegan con
 * la cuenta puesta, hay que decir CUÁL es: un formulario que se abre
 * relleno sin nombrar a quién pertenece es peor que uno vacío, porque
 * invita a operar sin mirar.
 *
 * Y un enlace de vuelta: el cajero no ha terminado con esa persona, le
 * va a hacer otra cosa en cuanto acabe con esta. */
?>
<?php if (($pre ?? null) !== null): ?>
<div class="cm-turno-tira mb-3">
  <?= cm_icono('user', 16, 'mr-1') ?>
  <span>
    Atendiendo a <strong><?= P::e((string) $pre['titular']) ?></strong>
    · cuenta <span class="cm-mono"><?= P::e((string) $pre['numero_cuenta']) ?></span>
    <?php if (!empty($pre['producto'])): ?>
      · <?= P::e((string) $pre['producto']) ?>
    <?php endif; ?>
  </span>
  <a class="ml-auto"
     href="<?= P::attr($rutas->url('caja/atencion') . '?q=' . urlencode((string) $pre['numero_cuenta'])) ?>">
    Volver a su ficha
  </a>
</div>
<?php endif; ?>
<div class="row">
  <div class="col-lg-7 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title">Registrar retiro</h4></div>
      <div class="card-body">

        <form id="form-caja">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="caja">
          <input type="hidden" name="cuenta" id="cuenta">
          <input type="hidden" name="_idem" id="idem">

          <div class="form-group">
            <label for="num-cuenta">Número de cuenta</label>
            <input type="text" class="form-control" id="num-cuenta" inputmode="numeric"
                   placeholder="Escriba el número de cuenta" autocomplete="off"
                   value="<?= P::attr($pre['numero_cuenta'] ?? '') ?>">
            <small class="form-text" id="info-cuenta"></small>
          </div>

          <div class="card bg-light d-none mb-3" id="ficha-cuenta">
            <div class="card-body py-2">
              <div class="row">
                <div class="col-5"><small class="text-muted d-block">Titular</small>
                  <strong id="f-titular"></strong></div>
                <div class="col-4"><small class="text-muted d-block">Saldo</small>
                  <span id="f-saldo"></span></div>
                <div class="col-3 text-right"><small class="text-muted d-block">Disponible</small>
                  <strong class="cm-pos" id="f-disp"></strong></div>
              </div>
            </div>
          </div>

          <div class="form-group">
            <label for="monto">Monto a retirar</label>
            <div class="input-group">
              <div class="input-group-prepend"><span class="input-group-text">$</span></div>
              <input type="text" class="form-control form-control-lg" id="monto" name="monto"
                     inputmode="decimal" placeholder="0.00" required autocomplete="off">
            </div>
            <small class="form-text text-muted">
              Máximo por operación: <strong><?= P::e(P::money($maxOp)) ?></strong>.
              El servidor verifica fondos y saldo mínimo.
            </small>
          </div>

          <div class="form-group">
            <label for="medio">Cómo se le entrega</label>
            <select class="form-control" id="medio" name="medio">
              <option value="efectivo" selected>Efectivo</option>
              <option value="cheque">Cheque de caja</option>
            </select>
            <small class="form-text text-muted">
              El cheque de caja no sale de la gaveta: no se le descuenta del
              efectivo que entrega al cerrar su turno.
            </small>
          </div>

          <div id="bloque-cheque" class="form-row d-none">
            <div class="form-group col-7">
              <label for="cheque_banco">Banco del cheque</label>
              <input type="text" class="form-control" id="cheque_banco" name="cheque_banco"
                     maxlength="80" autocomplete="off">
            </div>
            <div class="form-group col-5">
              <label for="cheque_numero">Número</label>
              <input type="text" class="form-control" id="cheque_numero" name="cheque_numero"
                     maxlength="30" autocomplete="off">
            </div>
          </div>

          <div class="form-group">
            <label for="concepto">Concepto</label>
            <input type="text" class="form-control" id="concepto" name="concepto" maxlength="160"
                   value="Retiro en ventanilla">
          </div>

          <?php if ($reqOtp): ?>
          <div id="bloque-otp" class="form-group d-none">
            <label for="codigo-otp">Código de verificación</label>
            <input type="text" class="form-control text-center" id="codigo-otp" name="codigo"
                   maxlength="6" inputmode="numeric" placeholder="000000"
                   style="font-size:1.4rem;letter-spacing:.5em;font-family:Consolas,monospace">
            <small class="form-text text-muted" id="info-otp"></small>
          </div>

          <button type="button" class="btn btn-outline-primary btn-block mb-2" id="btn-codigo" disabled>
            Solicitar código de verificación
          </button>
          <?php endif; ?>

          <button type="submit" class="btn btn-danger btn-lg btn-block" id="btn-caja"
                  <?= $reqOtp ? 'disabled' : '' ?>>Registrar retiro</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title">Últimos retiros</h4></div>
      <div class="card-body p-0">
        <?php if ($ultimos === []): ?>
          <div class="cm-vacio"><p class="mb-0">Sin retiros registrados hoy.</p></div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0">
            <thead><tr><th>Cuenta</th><th>Titular</th><th>Hora</th><th class="cm-num">Monto</th></tr></thead>
            <tbody>
            <?php foreach ($ultimos as $u): ?>
              <tr>
                <td class="cm-mono"><?= P::e($u['numero_cuenta']) ?></td>
                <td class="text-muted" style="font-size:.82rem"><?= P::e($u['titular']) ?></td>
                <td class="text-muted"><?= P::e(P::fecha((string) $u['creado_en'], 'H:i')) ?></td>
                <td class="cm-num cm-neg">-<?= P::e(P::money(abs((int) $u['importe_cent']))) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';
  var form   = document.getElementById('form-caja');
  var num    = document.getElementById('num-cuenta');
  var cuenta = document.getElementById('cuenta');
  var info   = document.getElementById('info-cuenta');
  var ficha  = document.getElementById('ficha-cuenta');
  var btn    = document.getElementById('btn-caja');
  var btnCod = document.getElementById('btn-codigo');
  var otp    = document.getElementById('bloque-otp');

  var URL_BUSCA  = <?= P::js($rutas->url('caja/buscar-cuenta')) ?>;
  var URL_POST   = <?= P::js($rutas->url('retiro')) ?>;
  var URL_CODIGO = <?= P::js($rutas->url('retiro/codigo')) ?>;
  var REQ_OTP    = <?= $reqOtp ? 'true' : 'false' ?>;

  function idem() {
    var a = new Uint8Array(16); crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0'+b.toString(16)).slice(-2); }).join('');
  }
  document.getElementById('idem').value = idem();

  var t;
  num.addEventListener('input', function () {
    clearTimeout(t);
    cuenta.value = ''; info.textContent = ''; info.className = 'form-text';
    ficha.classList.add('d-none');
    btn.disabled = true;
    if (btnCod) btnCod.disabled = true;

    var v = num.value.replace(/[^0-9]/g, '');
    if (v.length < 6) return;

    t = setTimeout(function () {
      var fd = new FormData(); fd.append('numero', v);
      fetch(URL_BUSCA, { method:'POST', body:fd, credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { info.className = 'form-text text-danger'; info.textContent = j.mensaje; return; }
          var d = j.datos;
          if (!d.operable) {
            info.className = 'form-text text-warning';
            info.textContent = 'La cuenta no está operable (' + d.estado + ').';
            return;
          }
          cuenta.value = d.codigo;
          info.className = 'form-text text-success';
          info.textContent = '✓ Cuenta encontrada';
          document.getElementById('f-titular').textContent = d.titular;
          document.getElementById('f-saldo').textContent   = d.saldo.formateado;
          document.getElementById('f-disp').textContent    = d.disponible.formateado;
          ficha.classList.remove('d-none');
          if (btnCod) btnCod.disabled = false; else btn.disabled = false;
          document.getElementById('monto').focus();
        })
        .catch(function () { info.textContent = 'No se pudo consultar.'; });
    }, 420);
  });

  if (btnCod) {
    btnCod.addEventListener('click', function () {
      var monto = document.getElementById('monto').value.trim();
      if (!monto) { window.avisar('Atención', 'Indique el monto.', 'alerta'); return; }

      btnCod.disabled = true; btnCod.textContent = 'Enviando…';
      var fd = new FormData();
      fd.append('_csrf', form._csrf.value);
      fd.append('_csrf_proposito', 'caja');
      fd.append('cuenta', cuenta.value);
      fd.append('monto', monto);

      fetch(URL_CODIGO, { method:'POST', body:fd, credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          btnCod.disabled = false; btnCod.textContent = 'Reenviar código';
          if (j.ok) {
            otp.classList.remove('d-none');
            btn.disabled = false;
            document.getElementById('info-otp').textContent =
              'Enviado a ' + ((j.datos && j.datos.enviado_a) || 'el correo del cliente');
            document.getElementById('codigo-otp').focus();
            window.avisar('Código enviado', j.mensaje, 'exito');
          } else { window.avisar('Error', j.mensaje, 'critico'); }
        });
    });
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (!cuenta.value) { window.avisar('Atención', 'Busca primero la cuenta.', 'alerta'); return; }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>Procesando…';

    /* Los pasos de la operación, mientras corre.

       El botón gris no dice si se mandó, si se colgó o si conviene
       apretar otra vez; y apretar otra vez, en un movimiento de
       dinero, es la peor respuesta posible. */
    var pr = CMProceso.abrir('retiro');
    pr.avanzar();

    fetch(URL_POST, { method:'POST', body:new FormData(form), credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        btn.disabled = false; btn.textContent = 'Registrar retiro';
        if (j.ok) { pr.listo(j.mensaje || ''); }
        else { pr.fallo(j.mensaje || 'No se pudo completar la operación.', 'validando'); }
        // El fallo ya lo dijo el panel; aquí solo el acuse de que salió.
        if (j.ok) { window.avisar('Retiro procesado', j.mensaje, 'exito'); }
        if (j.ok) setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1500);
      })
      .catch(function () {
        btn.disabled = false; btn.textContent = 'Registrar retiro';
        pr.fallo('No se pudo contactar con el servidor. Revise su conexión.');
      });
  });

  /* El cheque pide banco y número; el efectivo, no. */
  var selMedio = document.getElementById('medio');
  var cajaCheq = document.getElementById('bloque-cheque');

  if (selMedio && cajaCheq) {
    selMedio.addEventListener('change', function () {
      var esCheque = selMedio.value === 'cheque';
      cajaCheq.classList.toggle('d-none', !esCheque);
      document.getElementById('cheque_banco').required  = esCheque;
      document.getElementById('cheque_numero').required = esCheque;
    });
  }

  /* La cuenta que venía de la ficha del cliente.
     Se dispara el mismo `input` que teclearla a mano en vez de repetir
     la búsqueda aquí: así la ficha, el botón y los avisos se comportan
     igual por los dos caminos, y no hay dos sitios que mantener. */
  if (num.value.trim() !== '') {
    num.dispatchEvent(new Event('input'));
    var campoMonto = document.getElementById('monto');
    if (campoMonto) { campoMonto.focus(); }
  }
})();
</script>
