<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $filas
 * @var array $candidatos
 * @var bool $puedeSolicitar
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$COLOR = ['solicitada' => 'warning', 'aplicada' => 'success', 'rechazada' => 'danger'];
?>

<?php if ($puedeSolicitar && $candidatos !== []): ?>
<div class="card">
  <div class="card-header"><h4 class="card-title mb-0">Solicitar reestructuración</h4></div>
  <div class="card-body">
    <p class="text-muted" style="font-size:.88rem">
      Reestructurar toma el saldo pendiente y arma un plan nuevo, normalmente
      a mayor plazo y menor cuota. Alivia el pago mensual, pero suele
      encarecer el crédito en total — la simulación lo muestra sin maquillarlo.
    </p>

    <form id="form-rees">
      <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
      <input type="hidden" name="_csrf_proposito" value="reestructura">

      <div class="form-row">
        <div class="form-group col-md-5">
          <label class="small">Crédito</label>
          <select class="form-control form-control-sm" name="credito" id="sel-credito" required>
            <option value="">— Seleccione —</option>
            <?php foreach ($candidatos as $c): ?>
            <option value="<?= P::attr($c['codigo']) ?>"
                    data-restantes="<?= (int) $c['plazo_meses'] - (int) $c['cuotas_pagadas'] ?>">
              <?= P::e($c['folio'] ?: '—') ?> · <?= P::e($c['cliente']) ?>
              · saldo <?= P::e(P::money((int) $c['saldo_capital_cent'])) ?>
              <?php if ((int) $c['vencidas'] > 0): ?> · <?= (int) $c['vencidas'] ?> vencida(s)<?php endif; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group col-md-3">
          <label class="small">Plazo nuevo</label>
          <select class="form-control form-control-sm" name="plazo" id="sel-plazo">
            <?php /* Hasta donde llega el servicio: 360 meses. Quedarse
                     en 72 dejaba fuera justo los plazos que alivian un
                     crédito largo —con 171 cuotas por delante, 72 meses
                     triplica la cuota en vez de bajarla—. */
            foreach ([12,18,24,36,48,60,72,84,96,120,144,180,240,300,360] as $n): ?>
            <option value="<?= $n ?>"<?= $n === 36 ? ' selected' : '' ?>>
              <?= $n ?> meses<?= $n % 12 === 0 ? ' · ' . intdiv($n, 12) . ' año' . ($n > 12 ? 's' : '') : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group col-md-2">
          <label class="small">Tasa nueva (%)</label>
          <input type="text" class="form-control form-control-sm" name="tasa" id="in-tasa"
                 inputmode="decimal" placeholder="igual">
        </div>
        <div class="form-group col-md-2">
          <label class="small">&nbsp;</label>
          <button type="button" class="btn btn-sm btn-outline-primary btn-block" id="btn-simular">
            Simular
          </button>
        </div>
      </div>

      <div id="resultado-sim" class="d-none">
        <div class="row">
          <div class="col-md-8">
            <div class="table-responsive">
              <table class="table table-sm mb-2">
                <thead><tr><th></th><th class="cm-num">Plan actual</th>
                  <th class="cm-num">Plan nuevo</th></tr></thead>
                <tbody id="tb-sim"></tbody>
              </table>
            </div>
          </div>
          <div class="col-md-4">
            <div class="cm-kpi text-center">
              <div class="cm-kpi__et" id="sim-alivio-et">Alivio mensual</div>
              <div class="cm-kpi__val text-success" id="sim-alivio">—</div>
              <div class="cm-kpi__pie" id="sim-alivio-pct"></div>
            </div>
            <div class="alert alert-warning mt-2 mb-0" style="font-size:.8rem" id="sim-costo"></div>
          </div>
        </div>

        <div class="form-group">
          <label class="small">Motivo de la reestructuración</label>
          <textarea class="form-control form-control-sm" name="motivo" rows="2" maxlength="500"
                    required placeholder="Ej. Reducción de ingresos del cliente"></textarea>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">Solicitar reestructuración</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0">Historial de reestructuraciones</h4>
    <span class="badge badge-light"><?= count($filas) ?></span>
  </div>
  <div class="card-body p-0">
    <?php if ($filas === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('reload', 40) ?>
        <p class="mb-0 mt-2">Sin reestructuraciones registradas.</p>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0" data-cm-tabla>
        <thead>
          <tr><th>Crédito</th><th>Cliente</th><th class="cm-num">Saldo</th>
              <th class="cm-num">Cuota ant.</th><th class="cm-num">Cuota nueva</th>
              <th class="cm-num">Plazo</th><th>Solicitó</th><th>Estado</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($filas as $f): ?>
          <tr>
            <td class="cm-mono"><?= P::e($f['credito_folio'] ?: '—') ?></td>
            <td><?= P::e($f['cliente']) ?></td>
            <td class="cm-num"><?= P::e(P::money((int) $f['saldo_anterior_cent'])) ?></td>
            <td class="cm-num text-muted"><?= P::e(P::money((int) $f['cuota_anterior_cent'])) ?></td>
            <td class="cm-num cm-pos font-weight-bold"><?= P::e(P::money((int) $f['cuota_nueva_cent'])) ?></td>
            <td class="cm-num text-muted">
              <?= (int) $f['plazo_anterior'] ?> &rarr; <?= (int) $f['plazo_nuevo'] ?>
            </td>
            <td class="text-muted" style="font-size:.82rem">
              <?= P::e($f['solicitante']) ?>
              <?php if ($f['es_mia']): ?><span class="badge badge-light ml-1">tú</span><?php endif; ?>
            </td>
            <td>
              <span class="badge badge-<?= P::attr($COLOR[$f['estado']] ?? 'light') ?>">
                <?= P::e(ucfirst((string) $f['estado'])) ?>
              </span>
            </td>
            <td class="text-right">
              <?php if ($f['puede_aprobar']): ?>
                <button class="btn btn-sm btn-success btn-aprobar"
                        data-cod="<?= P::attr($f['codigo']) ?>"
                        data-cm-confirmar="Se reemplazarán las cuotas pendientes por el plan nuevo.">
                  Aprobar
                </button>
                <button class="btn btn-sm btn-outline-danger btn-rechazar"
                        data-cod="<?= P::attr($f['codigo']) ?>">Rechazar</button>
              <?php elseif ($f['estado'] === 'solicitada' && $f['es_mia']): ?>
                <small class="text-muted">Requiere otro aprobador</small>
              <?php elseif (!empty($f['aprobador'])): ?>
                <small class="text-muted"><?= P::e($f['aprobador']) ?></small>
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

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';
  /* El token de seguridad se lee vivo, no congelado.

     Aquí había una copia del token tomada al
     dibujar la página. Como el token es de un solo uso, la primera
     acción lo gastaba y la segunda fallaba siempre con «el token de
     seguridad venció»: pedir el código llegaba, pulsar «Ver datos» no.

     El servidor manda el reemplazo en cada respuesta y `cm.js` lo
     guarda en `window.CM_CSRF`. Leer de ahí vale para toda la vida de
     la pantalla, por muchas acciones que se encadenen. */
  window.CM_CSRF = window.CM_CSRF || <?= P::js($csrf) ?>;
  var URL_SIM = <?= P::js($rutas->url('reestructuraciones/simular')) ?>;
  var URL_SOL = <?= P::js($rutas->url('reestructuraciones/solicitar')) ?>;
  var BASE    = <?= P::js($rutas->url('reestructuraciones/')) ?>;

  function post(url, datos) {
    var fd = new FormData();
    fd.append('_csrf', window.CM_CSRF); fd.append('_csrf_proposito', 'reestructura');
    Object.keys(datos || {}).forEach(function (k) { fd.append(k, datos[k]); });
    return fetch(url, { method:'POST', body:fd, credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} })
      .then(function (r) { return r.json(); });
  }

  /* Al elegir un crédito se propone el primer plazo que de verdad
     alivie: el más corto que pase de las cuotas que le quedan. Dejar
     36 fijo hacía que la primera simulación de un crédito largo
     saliera siempre encareciendo, y había que adivinar hacia dónde
     moverse. */
  var selCred = document.getElementById('sel-credito');
  var selPlazo = document.getElementById('sel-plazo');

  if (selCred && selPlazo) {
    selCred.addEventListener('change', function () {
      var op = selCred.options[selCred.selectedIndex];
      var quedan = parseInt(op && op.getAttribute('data-restantes'), 10);

      if (!quedan) { return; }

      for (var i = 0; i < selPlazo.options.length; i++) {
        if (parseInt(selPlazo.options[i].value, 10) > quedan) {
          selPlazo.selectedIndex = i;
          return;
        }
      }

      selPlazo.selectedIndex = selPlazo.options.length - 1;
    });
  }

  var btnSim = document.getElementById('btn-simular');
  var form   = document.getElementById('form-rees');

  if (btnSim && form) {
    btnSim.addEventListener('click', function () {
      var cred = document.getElementById('sel-credito').value;
      if (!cred) { window.avisar('Atención', 'Seleccione un crédito.', 'alerta'); return; }

      post(URL_SIM, {
        credito: cred,
        plazo:   document.getElementById('sel-plazo').value,
        tasa:    document.getElementById('in-tasa').value
      }).then(function (j) {
        if (!j.ok) { window.avisar('Error', j.mensaje, 'critico'); return; }
        var d = j.datos;

        document.getElementById('tb-sim').innerHTML =
          fila('Cuota mensual', d.cuota_anterior.formateado, d.cuota_nueva.formateado) +
          fila('Cuotas restantes', d.plazo_anterior, d.plazo_nuevo) +
          fila('Tasa anual', d.tasa_anterior + '%', d.tasa_nueva + '%') +
          fila('Interés por pagar', d.interes_pendiente.formateado, d.interes_nuevo.formateado);

        /* El panel dice lo que sale, no lo que convendría que saliera.
           Acortar el plazo sube la cuota: antes eso se mostraba como
           «$0.00 de alivio» con un «-264.7% menos» debajo que lo
           desmentía. */
        var val = document.getElementById('sim-alivio');

        document.getElementById('sim-alivio-et').textContent =
          d.alivia ? 'Alivio mensual' : 'Aumento de la cuota';
        val.textContent = d.alivio_mensual.formateado;
        val.className = 'cm-kpi__val ' + (d.alivia ? 'text-success' : 'text-danger');
        document.getElementById('sim-alivio-pct').textContent =
          d.alivio_pct + (d.alivia ? '% menos por mes' : '% más por mes');

        var costo = document.getElementById('sim-costo');
        costo.className = 'alert mt-2 mb-0 ' +
          (d.encarece ? 'alert-warning' : 'alert-success');
        costo.innerHTML = d.encarece
          ? '<strong>Costo adicional:</strong> ' + d.costo_adicional.formateado +
            '<br><small>Es lo que el cliente pagará de más en intereses ' +
            'por extender el plazo.</small>'
          : '<strong>Ahorro en intereses:</strong> ' + d.costo_adicional.formateado +
            '<br><small>El plan nuevo cuesta menos en total que el vigente.</small>';

        document.getElementById('resultado-sim').classList.remove('d-none');
      });
    });

    function fila(et, a, b) {
      return '<tr><td>' + et + '</td><td class="cm-num text-muted">' + a +
             '</td><td class="cm-num font-weight-bold">' + b + '</td></tr>';
    }

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var fd = new FormData(form);
      post(URL_SOL, {
        credito: fd.get('credito'), plazo: fd.get('plazo'),
        tasa: fd.get('tasa'), motivo: fd.get('motivo')
      }).then(function (j) {
        window.avisar(j.ok ? 'Solicitada' : 'Error', j.mensaje, j.ok ? 'exito' : 'critico');
        if (j.ok) setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1400);
      });
    });
  }

  document.querySelectorAll('.btn-aprobar').forEach(function (b) {
    b.addEventListener('click', function () {
      post(BASE + b.dataset.cod + '/aprobar', {}).then(function (j) {
        window.avisar(j.ok ? 'Aplicada' : 'Error', j.mensaje, j.ok ? 'exito' : 'critico');
        if (j.ok) setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1400);
      });
    });
  });

  document.querySelectorAll('.btn-rechazar').forEach(function (b) {
    b.addEventListener('click', function () {
      /* El motivo, en un diálogo del sistema y no en el `prompt()` del
         navegador: lo va a leer quien audite esta decisión más
         adelante, así que conviene que se pueda escribir con calma y
         que no se acepte vacío. */
      window.CMAviso.pedirTexto('Rechazar la reestructuración', {
        mensaje: 'Quedará registrado junto con su nombre y la fecha.',
        etiqueta: 'Motivo del rechazo',
        pista: 'Por qué no procede',
        boton: 'Rechazar',
        peligro: true,
        minimo: 10
      }).then(function (m) {
        if (!m) { return; }

        post(BASE + b.dataset.cod + '/rechazar', { motivo: m }).then(function (j) {
          window.avisar(j.ok ? 'Rechazada' : 'Error', j.mensaje, j.ok ? 'exito' : 'critico');
          if (j.ok) setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1200);
        });
      });
    });
  });
})();
</script>
