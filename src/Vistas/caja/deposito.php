<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array|null $limite
 * @var array $ultimos
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$maxOp = (int) ($limite['max_por_operacion_cent'] ?? 0);

/* La cuenta con la que vino el cajero desde la ficha del cliente. */
$pre = $cuenta ?? null;
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
      <div class="card-header"><h4 class="card-title">Registrar depósito</h4></div>
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
                <div class="col-6"><small class="text-muted d-block">Titular</small>
                  <strong id="f-titular"></strong></div>
                <div class="col-3"><small class="text-muted d-block">Producto</small>
                  <span id="f-producto"></span></div>
                <div class="col-3 text-right"><small class="text-muted d-block">Saldo actual</small>
                  <strong id="f-saldo"></strong></div>
              </div>
            </div>
          </div>

          <div class="form-group">
            <label for="monto">Monto a depositar</label>
            <div class="input-group">
              <div class="input-group-prepend"><span class="input-group-text">$</span></div>
              <input type="text" class="form-control form-control-lg" id="monto" name="monto"
                     inputmode="decimal" placeholder="0.00" required autocomplete="off">
            </div>
            <small class="form-text text-muted">
              Máximo por operación: <strong><?= P::e(P::money($maxOp)) ?></strong>
            </small>
          </div>

          <div class="form-group">
            <label for="medio">Con qué paga</label>
            <select class="form-control" id="medio" name="medio">
              <option value="efectivo" selected>Efectivo</option>
              <option value="cheque">Cheque</option>
            </select>
            <small class="form-text text-muted">
              El cheque se abona a la cuenta y se remesa, pero no entra a la
              gaveta: no se le cuenta como efectivo en el arqueo.
            </small>
          </div>

          <div id="bloque-cheque" class="form-row d-none">
            <div class="form-group col-7">
              <label for="cheque_banco">Banco del cheque</label>
              <input type="text" class="form-control" id="cheque_banco" name="cheque_banco"
                     maxlength="80" autocomplete="off" placeholder="Banco girado">
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
                   value="Depósito en ventanilla">
          </div>

          <button type="submit" class="btn btn-primary btn-lg btn-block" id="btn-caja" disabled>
            Registrar depósito
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title">Últimos depósitos</h4></div>
      <div class="card-body p-0">
        <?php if ($ultimos === []): ?>
          <div class="cm-vacio"><p class="mb-0">Sin depósitos registrados hoy.</p></div>
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
                <td class="cm-num cm-pos">+<?= P::e(P::money(abs((int) $u['importe_cent']))) ?></td>
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

  var URL_BUSCA = <?= P::js($rutas->url('caja/buscar-cuenta')) ?>;
  var URL_POST  = <?= P::js($rutas->url('deposito')) ?>;

  function idem() {
    var a = new Uint8Array(16); crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0'+b.toString(16)).slice(-2); }).join('');
  }
  document.getElementById('idem').value = idem();

  var t;
  num.addEventListener('input', function () {
    clearTimeout(t);
    cuenta.value = ''; info.textContent = ''; info.className = 'form-text';
    ficha.classList.add('d-none'); btn.disabled = true;

    var v = num.value.replace(/[^0-9]/g, '');
    if (v.length < 6) return;

    t = setTimeout(function () {
      var fd = new FormData(); fd.append('numero', v);
      fetch(URL_BUSCA, { method:'POST', body:fd, credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) {
            info.className = 'form-text text-danger';
            info.textContent = j.mensaje; return;
          }
          var d = j.datos;
          if (!d.operable) {
            info.className = 'form-text text-warning';
            info.textContent = 'La cuenta no está operable (' + d.estado + ').';
            return;
          }
          cuenta.value = d.codigo;
          info.className = 'form-text text-success';
          info.textContent = '✓ Cuenta encontrada';
          document.getElementById('f-titular').textContent  = d.titular;
          document.getElementById('f-producto').textContent = d.producto;
          document.getElementById('f-saldo').textContent    = d.saldo.formateado;
          ficha.classList.remove('d-none');
          btn.disabled = false;
          document.getElementById('monto').focus();
        })
        .catch(function () { info.textContent = 'No se pudo consultar.'; });
    }, 420);
  });

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (!cuenta.value) { window.avisar('Atención', 'Busca primero la cuenta.', 'alerta'); return; }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>Procesando…';

    /* Los pasos de la operación, mientras corre.

       El botón gris no dice si se mandó, si se colgó o si conviene
       apretar otra vez; y apretar otra vez, en un movimiento de
       dinero, es la peor respuesta posible. */
    var pr = CMProceso.abrir('deposito');
    pr.avanzar();

    fetch(URL_POST, { method:'POST', body:new FormData(form), credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        btn.disabled = false; btn.textContent = 'Registrar depósito';
        if (j.ok) { pr.listo(j.mensaje || ''); }
        else { pr.fallo(j.mensaje || 'No se pudo completar la operación.', 'validando'); }
        // El fallo ya lo dijo el panel; aquí solo el acuse de que salió.
        if (j.ok) { window.avisar('Depósito registrado', j.mensaje, 'exito'); }
        if (j.ok) setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1500);
      })
      .catch(function () {
        btn.disabled = false; btn.textContent = 'Registrar depósito';
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
