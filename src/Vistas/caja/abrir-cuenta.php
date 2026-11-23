<?php
/** @var array $clientes */
/** @var array $productos */
use CashMan\Http\Plantilla as P;
?>
<div class="row justify-content-center">
  <div class="col-lg-8 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title">Abrir cuenta nueva</h4></div>
      <div class="card-body">

        <div id="resultado-apertura" class="alert alert-success d-none"></div>

        <form id="form-apertura" data-cm-ajax
              method="post" action="<?= P::attr($rutas->url('cuentas/abrir')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="caja">

          <div class="form-group">
            <label for="cliente">Cliente</label>
            <select class="form-control" id="cliente" name="cliente" required>
              <option value="">— Seleccione el cliente —</option>
              <?php foreach ($clientes as $c): ?>
              <option value="<?= P::attr($c['codigo']) ?>">
                <?= P::e(trim($c['nombres'] . ' ' . $c['apellidos'])) ?> — <?= P::e($c['correo']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="producto">Producto</label>
            <select class="form-control" id="producto" name="producto" required>
              <?php foreach ($productos as $p): ?>
              <option value="<?= P::attr($p['codigo_h']) ?>" data-min="<?= (int) $p['saldo_minimo_cent'] ?>">
                <?= P::e($p['nombre']) ?>
                — mínimo <?= P::e(P::money((int) $p['saldo_minimo_cent'])) ?>
                <?php if ((int) $p['tasa_interes_bp'] > 0): ?>
                  &middot; <?= number_format((int) $p['tasa_interes_bp'] / 100, 2) ?>% anual
                <?php endif; ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small class="form-text text-muted" id="info-minimo"></small>
          </div>

          <?php /* La tarjeta sale con la cuenta.
                   Antes había que pedirla aparte, lo que significaba
                   que el cliente se iba sin ella y volvía otro día.
                   Lo único que hay que preguntarle es la marca. */ ?>
          <div class="form-group">
            <label for="marca">Tarjeta de débito</label>
            <select class="form-control" id="marca" name="marca">
              <option value="visa" selected>Visa</option>
              <option value="mastercard">Mastercard</option>
              <option value="">No emitir ninguna por ahora</option>
            </select>
            <small class="form-text text-muted">
              Se emite junto con la cuenta. Una por cuenta de ahorro.
            </small>
          </div>

          <div class="form-group">
            <label for="monto_inicial">Depósito inicial (opcional)</label>
            <div class="input-group">
              <div class="input-group-prepend"><span class="input-group-text">$</span></div>
              <input type="text" class="form-control" id="monto_inicial" name="monto_inicial"
                     inputmode="decimal" placeholder="0.00">
            </div>
            <small class="form-text text-muted">
              Si lo dejas vacío, la cuenta se abre en cero.
            </small>
          </div>

          <button type="submit" class="btn btn-primary">Abrir cuenta</button>
          <a href="<?= P::attr($rutas->url('cuentas')) ?>" class="btn btn-outline-secondary">Cancelar</a>
        </form>
      </div>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  var sel = document.getElementById('producto');
  var inf = document.getElementById('info-minimo');
  function actualizar() {
    var o = sel.options[sel.selectedIndex];
    if (!o) return;
    var min = parseInt(o.getAttribute('data-min'), 10) || 0;
    inf.textContent = min > 0
      ? 'Este producto requiere un depósito inicial mínimo de ' + window.CMUtil.money(min) + '.'
      : 'Este producto no requiere depósito inicial.';
  }
  sel.addEventListener('change', actualizar); actualizar();

  var f = document.getElementById('form-apertura');
  f.addEventListener('cm:exito', function (ev) {
    var d = ev.detail.datos || {};
    var c = document.getElementById('resultado-apertura');
    c.classList.remove('d-none');
    c.innerHTML = '<strong>Cuenta abierta.</strong><br>Número: <span class="cm-mono">' +
                  window.CMUtil.escapar(d.numero || '') + '</span> — ' +
                  window.CMUtil.escapar(d.producto || '');
    f.reset(); actualizar();
  });
})();
</script>
