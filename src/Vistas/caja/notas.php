<?php
/**
 * Notas de abono y de cargo.
 *
 * Una nota no se aplica sola: la emite quien atiende y la autoriza
 * alguien por encima. Hasta entonces no mueve ni un centavo. Por eso
 * esta pantalla pone «pendiente» como primera pestaña: es la única que
 * tiene a alguien esperando del otro lado.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $notas
 * @var array  $conteos
 * @var string $estado
 * @var bool   $puedeEmitir
 * @var bool   $puedeAutorizar
 * @var bool   $puedeAnular
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$pestanas = [
    'pendiente' => ['Pendientes', 'pendiente'],
    ''          => ['Todas',      'todas'],
    'aplicada'  => ['Aplicadas',  'aplicada'],
    'rechazada' => ['Rechazadas', 'rechazada'],
    'anulada'   => ['Anuladas',   'anulada'],
];

$color = static fn(string $e): string => match ($e) {
    'aplicada'  => 'success',
    'pendiente' => 'warning',
    'rechazada' => 'danger',
    'anulada'   => 'light',
    default     => 'light',
};
?>

<?php if ($puedeEmitir): ?>
<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">Emitir una nota</h4>
  </div>
  <div class="card-body">
    <p class="text-muted mb-3" style="font-size:.85rem">
      Con una nota se corrige o se ajusta algo sin tocar un asiento ya registrado:
      se emite otro que lo compensa, con su folio y su motivo. La nota queda
      <strong>pendiente</strong> hasta que alguien con autorización la apruebe, así
      que aquí no se mueve dinero todavía.
    </p>

    <form method="post" data-cm-ajax data-cm-recargar
          action="<?= P::attr($rutas->url('caja/notas/emitir')) ?>">
      <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
      <input type="hidden" name="_csrf_proposito" value="nota">

      <div class="form-row">
        <div class="form-group col-md-3">
          <label>Tipo de nota</label>
          <select class="form-control" name="tipo" required>
            <option value="abono">Abono — se le acredita al cliente</option>
            <option value="cargo">Cargo — se le debita al cliente</option>
          </select>
        </div>
        <div class="form-group col-md-4">
          <label for="nota-cuenta">Cuenta del cliente</label>
          <input type="text" class="form-control" id="nota-cuenta" name="cuenta" required
                 inputmode="numeric" autocomplete="off" placeholder="Número de cuenta">
          <small class="form-text" id="nota-info">El número de la libreta sirve.</small>
        </div>
        <div class="form-group col-md-2">
          <label>Importe</label>
          <div class="input-group">
            <div class="input-group-prepend"><span class="input-group-text">$</span></div>
            <input type="text" class="form-control" name="monto" inputmode="decimal"
                   required placeholder="0.00">
          </div>
        </div>
        <div class="form-group col-md-3">
          <label>Concepto</label>
          <input type="text" class="form-control" name="concepto" maxlength="190"
                 placeholder="Lo que verá el cliente">
        </div>
      </div>

      <?php /* A quién se le va a tocar el dinero.
               Era el único formulario de la ventanilla donde la cuenta
               se escribía a ciegas: se tecleaba un número y se emitía
               una nota sin que nadie confirmara de quién era. Un dígito
               cambiado y el abono le cae a otro cliente, que es un error
               que hay que deshacer con otra nota. */ ?>
      <div class="card bg-light d-none mb-3" id="nota-ficha">
        <div class="card-body py-2">
          <div class="row align-items-center">
            <div class="col-5">
              <small class="text-muted d-block">Titular</small>
              <strong id="nota-titular"></strong>
            </div>
            <div class="col-3">
              <small class="text-muted d-block">Saldo actual</small>
              <span id="nota-saldo"></span>
            </div>
            <div class="col-4 text-right">
              <small class="text-muted d-block">Quedará en</small>
              <strong id="nota-despues">—</strong>
            </div>
          </div>
        </div>
      </div>

      <div class="form-group">
        <label>Motivo</label>
        <textarea class="form-control" name="motivo" rows="2" required minlength="10"
                  placeholder="Por qué se emite esta nota. Lo leerá quien la autorice y quien la audite."></textarea>
      </div>

      <button type="submit" class="btn btn-primary" id="nota-enviar" disabled>
        Emitir nota
      </button>
      <small class="text-muted ml-2" style="font-size:.8rem">
        Queda pendiente: no mueve dinero hasta que alguien la autorice.
      </small>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-body pb-0 pt-2">
    <ul class="nav nav-tabs cm-pestanas" style="border-bottom:1px solid #eaecf0">
      <?php foreach ($pestanas as $clave => [$etiqueta, $campo]):
        $n = (int) ($conteos[$campo] ?? 0);
        $activa = (string) $estado === (string) $clave;
      ?>
      <li class="nav-item">
        <a class="nav-link <?= $activa ? 'active' : '' ?>"
           href="<?= P::attr($rutas->url('caja/notas') . ($clave !== '' ? '?estado=' . $clave : '')) ?>">
          <?= P::e($etiqueta) ?>
          <span class="badge badge-<?= $activa ? 'primary' : 'light' ?> ml-1"><?= $n ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="card-body pt-3">
    <?php if ($notas === []): ?>
      <div class="cm-vacio"><p class="mb-0">No hay notas en este estado.</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Folio</th><th>Tipo</th><th>Cliente</th><th>Cuenta</th>
            <th class="text-right">Importe</th><th>Motivo</th>
            <th>Emitió / autorizó</th><th>Estado</th>
            <th class="cm-acciones">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($notas as $n): ?>
          <tr>
            <td class="cm-mono cm-nowrap" style="font-size:.78rem">
              <?= P::e($n['folio']) ?>
              <small class="text-muted d-block" style="font-size:.7rem">
                <?= P::e(P::fecha((string) $n['creada_en'], 'd/m/Y H:i')) ?>
              </small>
            </td>
            <td>
              <span class="badge badge-<?= $n['tipo'] === 'abono' ? 'success' : 'warning' ?>">
                <?= P::e($n['tipo']) ?>
              </span>
            </td>
            <td style="font-size:.84rem"><?= P::e($n['cliente']) ?></td>
            <td class="cm-mono text-muted" style="font-size:.78rem"><?= P::e($n['numero_cuenta']) ?></td>
            <td class="text-right cm-mono cm-nowrap font-weight-bold">
              <?= P::e(P::money((int) $n['importe_cent'])) ?>
            </td>
            <td class="text-muted" style="font-size:.78rem;max-width:18rem">
              <?= P::e($n['concepto']) ?>
              <small class="d-block" style="font-size:.72rem"><?= P::e($n['motivo']) ?></small>
              <?php if (!empty($n['motivo_anulacion'])): ?>
              <small class="d-block text-danger" style="font-size:.72rem">
                <?= P::e($n['motivo_anulacion']) ?>
              </small>
              <?php endif; ?>
            </td>
            <td style="font-size:.76rem" class="text-muted">
              <?= P::e($n['emisor']) ?>
              <?php if (!empty($n['autorizador'])): ?>
              <small class="d-block">→ <?= P::e($n['autorizador']) ?></small>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-<?= $color((string) $n['estado']) ?>">
                <?= P::e($n['estado']) ?>
              </span>
            </td>
            <td class="cm-acciones cm-nowrap">
              <?php if ($n['estado'] === 'pendiente' && $puedeAutorizar): ?>
              <form method="post" data-cm-ajax data-cm-recargar style="display:inline"
                    action="<?= P::attr($rutas->url('caja/notas/' . $n['codigo'] . '/autorizar')) ?>">
                <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
                <input type="hidden" name="_csrf_proposito" value="nota">
                <button class="btn btn-sm btn-primary"
                        data-cm-confirmar="Se aplicará la nota <?= P::attr($n['folio']) ?> por <?= P::attr(P::money((int) $n['importe_cent'])) ?> y se avisará al cliente. ¿Continuar?">
                  Autorizar
                </button>
              </form>

              <details class="cm-rechazo">
                <summary class="btn btn-sm btn-outline-danger">Rechazar</summary>
                <form method="post" data-cm-ajax data-cm-recargar class="cm-rechazo__caja"
                      action="<?= P::attr($rutas->url('caja/notas/' . $n['codigo'] . '/rechazar')) ?>">
                  <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
                  <input type="hidden" name="_csrf_proposito" value="nota">
                  <textarea class="form-control form-control-sm" name="motivo" rows="2"
                            required minlength="5"
                            placeholder="Por qué no se autoriza"></textarea>
                  <button class="btn btn-sm btn-danger btn-block mt-2" data-cm-peligro>
                    Confirmar rechazo
                  </button>
                </form>
              </details>

              <?php elseif ($n['estado'] === 'aplicada' && $puedeAnular): ?>
              <details class="cm-rechazo">
                <summary class="btn btn-sm btn-outline-danger">Anular</summary>
                <form method="post" data-cm-ajax data-cm-recargar class="cm-rechazo__caja"
                      action="<?= P::attr($rutas->url('caja/notas/' . $n['codigo'] . '/anular')) ?>">
                  <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
                  <input type="hidden" name="_csrf_proposito" value="nota">
                  <textarea class="form-control form-control-sm" name="motivo" rows="2"
                            required minlength="10"
                            placeholder="Motivo de la anulación"></textarea>
                  <button class="btn btn-sm btn-danger btn-block mt-2" data-cm-peligro
                          data-cm-confirmar="Se emitirá el reverso contable y el saldo del cliente volverá a como estaba. ¿Continuar?">
                    Confirmar anulación
                  </button>
                </form>
              </details>
              <?php else: ?>
              <span class="text-muted" style="font-size:.76rem">—</span>
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

<div class="alert alert-light border" style="font-size:.84rem">
  <strong>Por qué hacen falta dos firmas.</strong> Quien emite la nota no puede
  autorizarla. Sin esa separación, quien recibe el dinero en ventanilla podría
  abonarse cualquier cantidad a sí mismo —o a quien quisiera— y la contabilidad
  seguiría cuadrando perfectamente. Cuadrar no es lo mismo que estar bien.
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';
  var campo = document.getElementById('nota-cuenta');
  if (!campo) { return; }

  var info   = document.getElementById('nota-info');
  var ficha  = document.getElementById('nota-ficha');
  var btn    = document.getElementById('nota-enviar');
  var tipo   = document.querySelector('select[name="tipo"]');
  var monto  = document.querySelector('input[name="monto"]');
  var URL_BUSCA = <?= P::js($rutas->url('caja/buscar-cuenta')) ?>;

  var saldoCent = null;
  var espera;

  /* Lo que quedará en la cuenta, calculado mientras se teclea.
     Un abono y un cargo se parecen en el formulario y son opuestos en
     el dinero: ver el saldo resultante antes de emitir es lo que
     distingue el uno del otro de un vistazo. */
  function pintarDespues() {
    var caja = document.getElementById('nota-despues');
    if (!caja) { return; }

    if (saldoCent === null || !monto.value) { caja.textContent = '—'; return; }

    var v = parseFloat(String(monto.value).replace(/[^0-9.]/g, ''));
    if (isNaN(v)) { caja.textContent = '—'; return; }

    var cent  = Math.round(v * 100);
    var signo = tipo.value === 'cargo' ? -1 : 1;
    var fin   = saldoCent + signo * cent;

    caja.textContent = '$' + (fin / 100).toLocaleString('en-US', {
      minimumFractionDigits: 2, maximumFractionDigits: 2
    });
    caja.className = fin < 0 ? 'cm-neg' : (signo < 0 ? '' : 'cm-pos');
  }

  campo.addEventListener('input', function () {
    clearTimeout(espera);
    saldoCent = null;
    btn.disabled = true;
    ficha.classList.add('d-none');
    info.className = 'form-text text-muted';
    info.textContent = 'El número de la libreta sirve.';

    var v = campo.value.replace(/[^0-9]/g, '');
    if (v.length < 6) { return; }

    espera = setTimeout(function () {
      var fd = new FormData();
      fd.append('numero', v);

      fetch(URL_BUSCA, {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) {
            info.className = 'form-text text-danger';
            info.textContent = j.mensaje || 'No se encontró esa cuenta.';
            return;
          }

          var d = j.datos;

          if (!d.operable) {
            info.className = 'form-text text-warning';
            info.textContent = 'La cuenta no está operable (' + d.estado + ').';
            return;
          }

          document.getElementById('nota-titular').textContent = d.titular;
          document.getElementById('nota-saldo').textContent   = d.saldo.formateado;
          saldoCent = d.saldo.centavos;

          ficha.classList.remove('d-none');
          info.className = 'form-text text-success';
          info.textContent = '✓ Cuenta encontrada';
          btn.disabled = false;
          pintarDespues();
        })
        .catch(function () {
          info.className = 'form-text text-danger';
          info.textContent = 'No se pudo consultar la cuenta.';
        });
    }, 350);
  });

  tipo.addEventListener('change', pintarDespues);
  monto.addEventListener('input', pintarDespues);
})();
</script>