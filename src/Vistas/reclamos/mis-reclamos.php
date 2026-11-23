<?php
/**
 * Los reclamos del cliente.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $reclamos
 * @var array $cuentas
 * @var int $dias
 * @var string $csrf
 */

use CashMan\Http\Plantilla as P;

$estados = [
    'recibido'              => ['info',      'recibido'],
    'en_analisis'           => ['primary',   'en análisis'],
    'espera_cliente'        => ['warning',   'esperando su respuesta'],
    'resuelto_favorable'    => ['success',   'resuelto a su favor'],
    'resuelto_desfavorable' => ['secondary', 'resuelto'],
    'desistido'             => ['secondary', 'desistido'],
    'escalado'              => ['dark',      'en la Defensoría'],
];
?>
<div class="row">

  <div class="col-lg-5 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Presentar un reclamo</h4></div>
      <div class="card-body">

        <form id="form-reclamo">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="reclamos">

          <div class="form-group">
            <label for="categoria">¿De qué se trata?</label>
            <select class="form-control" id="categoria" name="categoria">
              <option value="cargo_indebido">Un cargo que no corresponde</option>
              <option value="operacion_no_reconocida">Una operación que yo no hice</option>
              <option value="pago_mal_aplicado">Un pago mío que aplicaron mal</option>
              <option value="cobro_de_intereses">Intereses mal cobrados</option>
              <option value="comisiones">Comisiones</option>
              <option value="tarjeta">Mi tarjeta</option>
              <option value="credito">Mi crédito</option>
              <option value="cuenta">Mi cuenta</option>
              <option value="informacion_crediticia">Mi récord crediticio</option>
              <option value="atencion">La atención que recibí</option>
              <option value="otro">Otra cosa</option>
            </select>
          </div>

          <div class="form-group">
            <label for="asunto">En una línea</label>
            <input type="text" class="form-control" id="asunto" name="asunto"
                   maxlength="160" required
                   placeholder="Me cobraron una comisión que no corresponde">
          </div>

          <div class="form-group">
            <label for="relato">Cuéntenos qué pasó</label>
            <textarea class="form-control" id="relato" name="relato" rows="5" required
                      placeholder="Cuándo pasó, por cuánto, y qué espera que hagamos."></textarea>
            <small class="form-text text-muted">
              Cuanto más completo, más rápido se resuelve: si falta algo tendremos que
              pedírselo y el plazo se alarga.
            </small>
          </div>

          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="monto">¿Cuánto reclama? <span class="text-muted">(opcional)</span></label>
              <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" id="monto" name="monto"
                       inputmode="decimal" placeholder="0.00">
              </div>
            </div>
            <div class="form-group col-md-6">
              <label for="referencia">Referencia <span class="text-muted">(opcional)</span></label>
              <input type="text" class="form-control" id="referencia" name="referencia"
                     maxlength="64" placeholder="Folio del movimiento">
            </div>
          </div>

          <?php if ($cuentas !== []): ?>
          <div class="form-group">
            <label for="objeto">¿Sobre cuál cuenta? <span class="text-muted">(opcional)</span></label>
            <select class="form-control" id="objeto" name="objeto">
              <option value="">No aplica</option>
              <?php foreach ($cuentas as $c): ?>
              <option value="<?= P::attr($c['codigo']) ?>">
                <?= P::e($c['numero_cuenta']) ?> &mdash; <?= P::e($c['producto']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <button type="submit" class="btn btn-primary btn-block">Presentar reclamo</button>
        </form>

      </div>
    </div>

    <!-- ----------------------------------------------------------------
         EL DERECHO QUE NO SE PUEDE ESCONDER

         El consumidor puede acudir a la Defensoría SIN agotar antes el
         proceso con el proveedor. Un sistema que diera a entender lo
         contrario estaría restringiendo un derecho.
         ---------------------------------------------------------------- -->
    <div class="card border-info">
      <div class="card-header"><h4 class="card-title mb-0">Sus derechos</h4></div>
      <div class="card-body" style="font-size:.88rem">
        <p>Le responderemos en un plazo de <strong><?= (int) $dias ?> días hábiles</strong>.
           Si el caso lo amerita podemos prorrogarlo una vez, y se lo diremos con el
           motivo.</p>

        <p>Si le pedimos información, el plazo se pausa hasta que nos conteste. No
           cuenta en su contra ni en el nuestro.</p>

        <p class="mb-0"><strong>No tiene que esperarnos.</strong> Puede acudir a la
           Defensoría del Consumidor en cualquier momento, sin agotar antes este
           proceso. Y si nuestra resolución no le convence, también.</p>
      </div>
    </div>
  </div>

  <div class="col-lg-7 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Mis reclamos</h4></div>
      <div class="card-body">

        <?php if ($reclamos === []): ?>
          <p class="text-muted mb-0">No ha presentado ningún reclamo.</p>
        <?php else: ?>

        <?php foreach ($reclamos as $r):
            $e = $estados[$r['estado']] ?? ['secondary', $r['estado']];
            $abierto = in_array($r['estado'], ['recibido', 'en_analisis', 'espera_cliente'], true);
        ?>
        <div class="border rounded p-3 mb-3" data-folio="<?= P::attr($r['folio']) ?>">
          <div class="d-flex justify-content-between align-items-start flex-wrap">
            <div>
              <strong><?= P::e($r['asunto']) ?></strong>
              <br><small class="text-muted">
                <?= P::e($r['folio']) ?> ·
                presentado el <?= P::e(date('d/m/Y', strtotime((string) $r['recibido_en']))) ?>
                <?php if ((int) $r['monto_cent'] > 0): ?>
                  · reclama $<?= number_format((int) $r['monto_cent'] / 100, 2) ?>
                <?php endif; ?>
              </small>
            </div>
            <div class="text-right">
              <span class="badge badge-<?= P::attr($e[0]) ?>"><?= P::e($e[1]) ?></span>
              <?php if ($abierto): ?>
                <br><small class="text-muted">
                  le respondemos antes del
                  <?= P::e(date('d/m/Y', strtotime((string) $r['vence_en']))) ?>
                </small>
              <?php endif; ?>
            </div>
          </div>

          <?php if ((int) $r['compensacion_cent'] > 0): ?>
          <div class="alert alert-success mt-2 mb-0" style="font-size:.86rem">
            Se le devolvieron
            <strong>$<?= number_format((int) $r['compensacion_cent'] / 100, 2) ?></strong>
            a su cuenta.
          </div>
          <?php endif; ?>

          <!-- La conversación completa: es lo que sostiene todo -->
          <div class="mt-3">
            <?php foreach ($r['mensajes'] as $m): ?>
            <div class="mb-2 p-2 rounded <?= $m['de'] === 'banco' ? 'bg-light' : '' ?>"
                 style="font-size:.88rem">
              <small class="text-muted d-block">
                <?= $m['de'] === 'banco' ? 'CashMan H.A.' : 'Usted' ?> ·
                <?= P::e(date('d/m/Y H:i', strtotime((string) $m['creado_en']))) ?>
              </small>
              <?= nl2br(P::e($m['texto'])) ?>
            </div>
            <?php endforeach; ?>
          </div>

          <?php if ($r['estado'] === 'espera_cliente'): ?>
          <form data-aportar class="mt-2">
            <div class="input-group input-group-sm">
              <input type="text" class="form-control" name="texto"
                     placeholder="Escriba su respuesta…" required>
              <div class="input-group-append">
                <button class="btn btn-primary" type="submit">Enviar</button>
              </div>
            </div>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var URL_NUEVO = <?= P::js($rutas->url('reclamos/presentar')) ?>;
  var BASE      = <?= P::js($rutas->url('reclamos/')) ?>;

  var CSRF_SEMILLA = <?= P::js($csrf) ?>;

  function tokenVigente() {
    return (window.CMToken && window.CMToken.de('reclamos')) || CSRF_SEMILLA;
  }

  var form = document.getElementById('form-reclamo');

  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();

      var fd = new FormData(form);
      fd.set('_csrf', tokenVigente());

      fetch(URL_NUEVO, {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { window.avisar('No se pudo presentar', j.mensaje, 'critico'); return; }

          window.avisar('Reclamo recibido', j.mensaje, 'exito');

          form.reset();

          /* La pantalla cambia entera —aparece el reclamo con su
             conversación— así que se repinta. */
          if (window.CMVista) { window.CMVista.refrescar(); }
          else { location.reload(); }
        })
        .catch(function () {
          window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
        });
    });
  }

  document.addEventListener('submit', function (ev) {
    if (!ev.target.hasAttribute || !ev.target.hasAttribute('data-aportar')) { return; }

    ev.preventDefault();

    var folio = ev.target.closest('[data-folio]').getAttribute('data-folio');

    var fd = new FormData(ev.target);
    fd.append('_csrf', tokenVigente());
    fd.append('_csrf_proposito', 'reclamos');

    fetch(BASE + folio + '/aportar', {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { window.avisar('No se pudo enviar', j.mensaje, 'critico'); return; }

        window.avisar('Enviado', j.mensaje, 'exito');

        if (window.CMVista) { window.CMVista.refrescar(); }
        else { location.reload(); }
      })
      .catch(function () {
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  });
})();
</script>
