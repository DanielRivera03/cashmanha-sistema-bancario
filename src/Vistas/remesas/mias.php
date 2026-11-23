<?php
/**
 * Las remesas del cliente.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $remesas
 * @var array $cuentas
 * @var string $csrf
 */

use CashMan\Http\Plantilla as P;

$disponibles = array_values(array_filter(
    $remesas,
    static fn(array $r): bool => $r['estado'] === 'disponible'
));

$color = [
    'disponible' => ['success', 'disponible'],
    'pagada'     => ['secondary', 'cobrada'],
    'devuelta'   => ['danger', 'devuelta'],
    'retenida'   => ['warning', 'retenida'],
];
?>
<div class="row">
  <div class="col-lg-8 col-md-12">

    <?php if ($disponibles !== []): ?>
    <div class="card border-success">
      <div class="card-header">
        <h4 class="card-title mb-0">
          Tiene <?= count($disponibles) ?> remesa<?= count($disponibles) === 1 ? '' : 's' ?> por cobrar
        </h4>
      </div>
      <div class="card-body">

        <?php foreach ($disponibles as $r): ?>
        <div class="border rounded p-3 mb-3" data-folio="<?= P::attr($r['folio']) ?>">
          <div class="d-flex justify-content-between align-items-start flex-wrap">
            <div>
              <h4 class="mb-0 text-success">
                $<?= number_format($r['monto_cent'] / 100, 2) ?>
              </h4>
              <small class="text-muted">
                <?= P::e($r['remesadora']) ?>
                <?php if ($r['remitente'] !== ''): ?>
                  · enviados por <strong><?= P::e($r['remitente']) ?></strong>
                <?php endif; ?>
              </small>
            </div>
            <div class="text-right">
              <small class="text-muted d-block"><?= P::e($r['etiqueta']) ?></small>
              <span class="cm-mono"><?= P::e($r['codigo']) ?></span>
              <br><small class="text-muted">folio <?= P::e($r['folio']) ?></small>
            </div>
          </div>

          <?php if ($r['expira_en'] !== null): ?>
          <?php
          $dias = (int) ((strtotime((string) $r['expira_en']) - strtotime('today')) / 86400);
          ?>
          <div class="mt-2">
            <?php if ($dias <= 5): ?>
              <span class="badge badge-warning">
                vence en <?= max(0, $dias) ?> día<?= $dias === 1 ? '' : 's' ?>
              </span>
              <small class="text-muted">
                Pasado el plazo vuelve a la remesadora y quien la envió tendrá que reenviarla.
              </small>
            <?php else: ?>
              <small class="text-muted">
                Disponible hasta el <?= P::e(date('d/m/Y', strtotime((string) $r['expira_en']))) ?>.
              </small>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($cuentas !== []): ?>
          <div class="form-row mt-3 align-items-end">
            <div class="form-group col-md-7 mb-0">
              <label style="font-size:.82rem">Acreditar a</label>
              <select class="form-control form-control-sm" data-cuenta>
                <?php foreach ($cuentas as $c): ?>
                <option value="<?= P::attr($c['codigo']) ?>">
                  <?= P::e($c['numero_cuenta']) ?> &mdash; <?= P::e($c['producto']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-5 mb-0">
              <button class="btn btn-success btn-block btn-sm" data-acreditar>
                Acreditar a mi cuenta
              </button>
            </div>
          </div>
          <small class="form-text text-muted">
            También puede cobrarla en efectivo en cualquier agencia, presentando su documento.
          </small>
          <?php else: ?>
          <div class="alert alert-info mt-3 mb-0" style="font-size:.88rem">
            Cóbrela en efectivo en cualquier agencia presentando su documento,
            o abra una cuenta para recibirlas directamente.
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Historial de remesas</h4></div>
      <div class="card-body">
        <?php if ($remesas === []): ?>
          <p class="text-muted mb-0">Todavía no le ha llegado ninguna remesa.</p>
        <?php else: ?>

        <div class="table-responsive">
          <table class="table table-sm cm-tabla">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Remesadora</th>
                <th>De</th>
                <th><?= P::e('Código') ?></th>
                <th class="text-right">Monto</th>
                <th class="text-center">Estado</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($remesas as $r):
                $e = $color[$r['estado']] ?? ['secondary', $r['estado']];
            ?>
              <tr>
                <td><?= P::e(date('d/m/Y', strtotime((string) $r['disponible_en']))) ?></td>
                <td><?= P::e($r['remesadora']) ?></td>
                <td><?= P::e($r['remitente'] !== '' ? $r['remitente'] : '—') ?></td>
                <td class="cm-mono"><?= P::e($r['codigo']) ?></td>
                <td class="text-right">$<?= number_format($r['monto_cent'] / 100, 2) ?></td>
                <td class="text-center">
                  <span class="badge badge-<?= P::attr($e[0]) ?>"><?= P::e($e[1]) ?></span>
                  <?php if ($r['forma_pago'] !== null): ?>
                    <br><small class="text-muted">
                      <?= $r['forma_pago'] === 'cuenta' ? 'a cuenta' : 'en efectivo' ?>
                    </small>
                  <?php endif; ?>
                  <?php if ($r['motivo'] !== ''): ?>
                    <br><small class="text-muted"><?= P::e($r['motivo']) ?></small>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Cómo funciona</h4></div>
      <div class="card-body" style="font-size:.88rem">
        <p>Cuando alguien le envía dinero desde el extranjero, la remesadora
           nos avisa y aparece aquí. No tiene que llamar a preguntar.</p>

        <p>Puede <strong>acreditarla a su cuenta</strong> desde esta pantalla, o
           <strong>cobrarla en efectivo</strong> en cualquier agencia presentando
           su documento de identidad.</p>

        <hr>

        <p class="mb-0 text-muted">
          Por su seguridad nunca mostramos el código de control completo, ni aquí
          ni en los correos: quien tiene ese código puede cobrar la remesa.
        </p>
      </div>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var BASE = <?= P::js($rutas->url('remesas/')) ?>;

  var CSRF_SEMILLA = <?= P::js($csrf) ?>;

  function tokenVigente() {
    return (window.CMToken && window.CMToken.de('remesas')) || CSRF_SEMILLA;
  }

  document.addEventListener('click', function (ev) {
    if (!ev.target.hasAttribute || !ev.target.hasAttribute('data-acreditar')) { return; }

    var caja  = ev.target.closest('[data-folio]');
    var folio = caja.getAttribute('data-folio');
    var sel   = caja.querySelector('[data-cuenta]');

    var boton = ev.target;

    window.CMAviso.confirmar({
      titulo: '¿Acreditar esta remesa a su cuenta?',
      texto: 'El dinero quedará disponible de inmediato.',
      alAceptar: function () {
        boton.disabled = true;

        var fd = new FormData();
        fd.append('_csrf', tokenVigente());
        fd.append('_csrf_proposito', 'remesas');
        fd.append('cuenta', sel.value);

        fetch(BASE + folio + '/acreditar', {
          method: 'POST', body: fd, credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            boton.disabled = false;

            if (!j.ok) { window.avisar('No se pudo', j.mensaje, 'critico'); return; }

            window.avisar('Remesa acreditada', j.mensaje, 'exito');

            /* La pantalla cambia entera —la remesa deja de estar
               disponible y el saldo sube— así que se repinta. */
            if (window.CMVista) { window.CMVista.refrescar(); }
            else { location.reload(); }
          })
          .catch(function () {
            boton.disabled = false;
            window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
          });
      }
    });
  });
})();
</script>
