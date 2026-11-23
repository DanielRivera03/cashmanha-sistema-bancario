<?php
/**
 * La caja del cajero.
 *
 * Durante el turno al cajero solo le importan dos cosas: cuánto
 * efectivo debería tener en el cajón ahora mismo y qué lo movió. Todo
 * lo demás es consulta.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array|null $turno
 * @var array  $cajas
 * @var array  $movimientos
 * @var array  $historial
 * @var array  $denominaciones
 * @var int    $fondoSugerido
 * @var int    $tolerancia
 * @var bool   $puedeArquear
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$etiquetaTipo = static fn(string $t): string => match ($t) {
    'fondo_inicial'  => 'Fondo inicial',
    'deposito'       => 'Depósito recibido',
    'retiro'         => 'Retiro entregado',
    'pago_credito'   => 'Pago de cuota',
    'pago_tarjeta'   => 'Pago de tarjeta',
    'nota_abono'     => 'Nota de abono',
    'nota_cargo'     => 'Nota de cargo',
    'remesa_entrada' => 'Remesa recibida',
    'remesa_salida'  => 'Remesa a bóveda',
    default          => ucfirst(str_replace('_', ' ', $t)),
};

/* Con qué entró o salió el dinero.
   Desde que la ventanilla admite cheques, la columna no puede llamarse
   «Efectivo» a secas: un cheque de $149.78 etiquetado como efectivo le
   dice al cajero que tiene en la gaveta un dinero que no está ahí. */
$etiquetaMedio = static fn(string $m): string => match ($m) {
    'efectivo'      => 'Efectivo',
    'cheque'        => 'Cheque',
    'transferencia' => 'Transferencia',
    'tarjeta'       => 'Tarjeta',
    default         => ucfirst($m),
};

$colorEstado = static fn(string $e): string => match ($e) {
    'abierta'      => 'primary',
    'cuadrada'     => 'success',
    'descuadrada'  => 'danger',
    default        => 'light',
};
?>

<?php if ($turno === null): ?>
<!-- ============ SIN TURNO: ABRIR CAJA ============ -->
<div class="row">
  <div class="col-lg-7 col-md-12 mb-4">
    <div class="card mb-0">
      <div class="card-header">
        <h4 class="card-title mb-0">Abrir turno de caja</h4>
      </div>
      <div class="card-body">
        <p class="text-muted" style="font-size:.88rem">
          Buenos días. Para atender en ventanilla primero abrimos su turno con el
          fondo con el que empieza —el sencillo para dar cambio—. A partir de ahí,
          cada billete que reciba o entregue queda anotado a su nombre, y al final
          del día cuadramos la caja juntos.
        </p>

        <form method="post" data-cm-ajax data-cm-recargar
              action="<?= P::attr($rutas->url('caja/abrir')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="caja_turno">

          <div class="form-row">
            <div class="form-group col-md-7">
              <label>Ventanilla</label>
              <select class="form-control" name="caja" required>
                <option value="">— Elija una ventanilla —</option>
                <?php foreach ($cajas as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (int) $c['ocupada'] > 0 ? 'disabled' : '' ?>>
                  <?= P::e($c['codigo'] . ' · ' . $c['nombre'] . ' — ' . $c['agencia']) ?>
                  <?= (int) $c['ocupada'] > 0 ? ' (ocupada)' : '' ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-5">
              <label>Fondo inicial</label>
              <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" name="fondo" inputmode="decimal"
                       value="<?= P::attr(number_format($fondoSugerido / 100, 2, '.', '')) ?>">
              </div>
            </div>
          </div>

          <button type="submit" class="btn btn-primary">
            <?= cm_icono('wallet', 15, 'mr-1') ?> Abrir mi caja
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5 col-md-12 mb-4">
    <div class="alert alert-light border mb-0" style="font-size:.84rem">
      <strong>Cómo funciona.</strong>
      <ul class="mb-0 mt-2 pl-3">
        <li>Una ventanilla admite un solo turno abierto a la vez: así nunca hay
            dudas sobre de quién es un faltante.</li>
        <li>Los depósitos, retiros y cobros que haga mientras su turno esté abierto
            se anotan solos en su caja.</li>
        <li>Al cerrar se cuenta el efectivo por denominación. Una diferencia de
            hasta <?= P::e(P::money($tolerancia)) ?> se considera cuadre.</li>
        <li>Su turno lo arquea otra persona. Nadie revisa su propia caja.</li>
      </ul>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ============ TURNO ABIERTO ============ -->
<div class="row">
  <div class="col-xl-4 col-lg-5 mb-4">
    <div class="card h-100 mb-0">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0"><?= P::e($turno['caja_nombre']) ?></h4>
        <span class="badge badge-primary"><?= P::e($turno['folio']) ?></span>
      </div>
      <div class="card-body">
        <small class="text-muted d-block">Efectivo que debe haber en caja</small>
        <div class="font-weight-bold text-primary mb-3" style="font-size:2rem;line-height:1.1">
          <?= P::e(P::money((int) $turno['esperado_cent'])) ?>
        </div>

        <div class="row">
          <div class="col-6 mb-2">
            <small class="text-muted d-block">Fondo inicial</small>
            <span class="font-weight-bold"><?= P::e(P::money((int) $turno['fondo_inicial_cent'])) ?></span>
          </div>
          <div class="col-6 mb-2">
            <small class="text-muted d-block">Operaciones</small>
            <span class="font-weight-bold"><?= (int) $turno['operaciones'] ?></span>
          </div>
          <div class="col-6 mb-2">
            <small class="text-muted d-block">Entradas</small>
            <span class="font-weight-bold cm-pos">+<?= P::e(P::money((int) $turno['entradas_cent'])) ?></span>
          </div>
          <div class="col-6 mb-2">
            <small class="text-muted d-block">Salidas</small>
            <span class="font-weight-bold cm-neg">-<?= P::e(P::money((int) $turno['salidas_cent'])) ?></span>
          </div>
        </div>

        <?php
        /* Los cheques, aparte del efectivo.
           No están en la gaveta —se remesan al banco— así que no se
           cuentan a mano ni entran en el arqueo, pero el cajero sí
           tiene que entregarlos al cerrar y necesita ver cuánto lleva. */
        $cheques = (int) ($turno['cheques_cent'] ?? 0);
        ?>
        <?php if ($cheques !== 0): ?>
        <div class="d-flex justify-content-between align-items-center border-top pt-2 mt-1">
          <small class="text-muted">
            Cheques recibidos
            <span class="d-block" style="font-size:.74rem">Se remesan; no se cuentan en el arqueo</span>
          </small>
          <span class="font-weight-bold"><?= P::e(P::money($cheques)) ?></span>
        </div>
        <?php endif; ?>

        <?php
          $tope = (int) $turno['tope_efectivo_cent'];
          $hay  = (int) $turno['esperado_cent'];
        ?>
        <?php if ($tope > 0 && $hay > $tope): ?>
        <!-- Una caja con demasiado efectivo es un riesgo, no una comodidad. -->
        <div class="alert alert-warning mt-2 mb-0 py-2" style="font-size:.8rem">
          La caja pasó su tope de <?= P::e(P::money($tope)) ?>. Conviene hacer una
          remesa a bóveda antes de seguir recibiendo efectivo.
        </div>
        <?php endif; ?>

        <small class="text-muted d-block mt-3">
          Abierto desde <?= P::e(P::fecha((string) $turno['abierta_en'])) ?>
        </small>
      </div>
    </div>
  </div>

  <div class="col-xl-8 col-lg-7 mb-4">
    <div class="card h-100 mb-0">
      <div class="card-header">
        <h4 class="card-title mb-0">Cerrar el turno</h4>
      </div>
      <div class="card-body">
        <p class="text-muted mb-3" style="font-size:.85rem">
          Cuente el efectivo y anote cuántas piezas hay de cada denominación.
          Contar solo el total no sirve para encontrar un error; con el desglose
          se ve enseguida si lo que baila es un billete de veinte o unas monedas.
        </p>

        <?php
        /* Los cheques se entregan, no se cuentan.
         *
         * Están en la gaveta físicamente, así que quien cuente sin saber
         * esto los sumará al efectivo y le saldrá un sobrante por su
         * importe exacto. Se listan aquí, con su banco y su número, para
         * que el cajero los aparte antes de contar y los entregue con su
         * remesa. El arqueo no los pide porque no son suyos que cuadrar. */
        $cheques = array_values(array_filter(
            $movimientos,
            static fn(array $m): bool => (string) ($m['medio'] ?? '') === 'cheque'
        ));

        $totalCheques = array_sum(array_map(
            static fn(array $m): int => (int) $m['importe_cent'],
            $cheques
        ));
        ?>

        <?php if ($cheques !== []): ?>
        <div class="alert alert-light border mb-3" style="font-size:.84rem">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <strong>Aparte los cheques antes de contar</strong>
            <span class="cm-mono"><?= P::e(P::money((int) $totalCheques)) ?></span>
          </div>
          <p class="text-muted mb-2" style="font-size:.8rem">
            No son efectivo: se entregan en fajo con su remesa y no entran en el
            arqueo. Si los cuenta con los billetes, le saldrá un sobrante por
            este importe.
          </p>
          <ul class="mb-0 pl-3">
            <?php foreach ($cheques as $ch): ?>
            <li style="font-size:.8rem">
              <?= P::e(P::money((int) $ch['importe_cent'])) ?>
              <?php if (!empty($ch['cheque_banco'])): ?>
                · <?= P::e((string) $ch['cheque_banco']) ?>
              <?php endif; ?>
              <?php if (!empty($ch['cheque_numero'])): ?>
                n.º <?= P::e((string) $ch['cheque_numero']) ?>
              <?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <form method="post" data-cm-ajax data-cm-recargar
              action="<?= P::attr($rutas->url('caja/' . $turno['codigo_sesion'] . '/cerrar')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="caja_turno">

          <div class="cm-arqueo">
            <?php foreach ($denominaciones as $d): ?>
            <div class="cm-arqueo__fila">
              <span class="cm-arqueo__den"><?= P::e(P::money($d)) ?></span>
              <input type="number" class="form-control form-control-sm cm-arqueo__cant"
                     name="d[<?= (int) $d ?>]" min="0" step="1" value="0"
                     data-cm-den="<?= (int) $d ?>"
                     aria-label="Cantidad de <?= P::attr(P::money($d)) ?>">
              <span class="cm-arqueo__sub" data-cm-sub="<?= (int) $d ?>">$0.00</span>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="cm-arqueo__total mt-3">
            <div>
              <small class="text-muted d-block">Contado</small>
              <b id="arqueo-contado" style="font-size:1.25rem">$0.00</b>
            </div>
            <div class="text-right">
              <small class="text-muted d-block">Diferencia</small>
              <b id="arqueo-dif" style="font-size:1.25rem">$0.00</b>
            </div>
          </div>

          <div class="form-group mt-3">
            <label style="font-size:.84rem">Observaciones (opcional)</label>
            <textarea class="form-control" name="observaciones" rows="2"
                      placeholder="Cualquier cosa que convenga dejar dicha sobre el turno"></textarea>
          </div>

          <button type="submit" class="btn btn-primary"
                  data-cm-confirmar="Se cerrará su turno con el efectivo que acaba de contar. ¿Continuar?">
            Cerrar y cuadrar mi caja
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ---------- MOVIMIENTOS DEL TURNO ---------- -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0">Movimientos de este turno</h4>
    <span class="badge badge-light"><?= count($movimientos) ?></span>
  </div>
  <div class="card-body p-0">
    <?php if ($movimientos === []): ?>
      <div class="cm-vacio"><p class="mb-0">Todavía no hay movimientos en su caja.</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr><th>Operación</th><th>Cliente</th><th>Concepto</th>
              <th>Medio</th><th>Referencia</th><th>Hora</th>
              <th class="cm-num">Importe</th></tr>
        </thead>
        <tbody>
        <?php foreach ($movimientos as $m): $i = (int) $m['importe_cent']; ?>
          <tr>
            <td style="font-size:.84rem"><?= P::e($etiquetaTipo((string) $m['tipo'])) ?></td>
            <td style="font-size:.84rem"><?= P::e($m['cliente'] ?? '—') ?></td>
            <td class="text-muted" style="font-size:.82rem"><?= P::e($m['concepto']) ?></td>
            <?php $med = (string) ($m['medio'] ?? 'efectivo'); ?>
            <td style="font-size:.8rem">
              <?php if ($med === 'efectivo'): ?>
                <span class="text-muted"><?= P::e($etiquetaMedio($med)) ?></span>
              <?php else: ?>
                <span class="badge badge-light"><?= P::e($etiquetaMedio($med)) ?></span>
                <?php if ($med === 'cheque' && !empty($m['cheque_banco'])): ?>
                <small class="d-block text-muted" style="font-size:.72rem">
                  <?= P::e((string) $m['cheque_banco']) ?>
                  <?php if (!empty($m['cheque_numero'])): ?>
                    n.º <?= P::e((string) $m['cheque_numero']) ?>
                  <?php endif; ?>
                </small>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="cm-mono text-muted" style="font-size:.76rem"><?= P::e($m['referencia']) ?></td>
            <td class="text-muted" style="font-size:.8rem"><?= P::e(P::fecha((string) $m['creado_en'], 'H:i')) ?></td>
            <?php /* Lo que no es efectivo se atenúa: está en la cinta
                     porque pasó por la ventanilla, pero no es lo que el
                     cajero va a contar al cerrar. */ ?>
            <td class="cm-num <?= $med !== 'efectivo' ? 'text-muted' : ($i >= 0 ? 'cm-pos' : 'cm-neg') ?>">
              <?= $i >= 0 ? '+' : '' ?><?= P::e(P::money($i)) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ---------- HISTORIAL DE TURNOS ---------- -->
<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">Turnos recientes</h4>
  </div>
  <div class="card-body p-0">
    <?php if ($historial === []): ?>
      <div class="cm-vacio"><p class="mb-0">Sin turnos registrados todavía.</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr><th>Folio</th><th>Caja</th><th>Cajero</th>
              <th class="cm-num">Esperado</th><th class="cm-num">Contado</th>
              <th class="cm-num">Diferencia</th><th>Estado</th><th>Arqueo</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($historial as $h):
          $dif = $h['diferencia_cent'] === null ? null : (int) $h['diferencia_cent'];
        ?>
          <tr>
            <td class="cm-mono cm-nowrap" style="font-size:.78rem"><?= P::e($h['folio']) ?></td>
            <td style="font-size:.82rem"><?= P::e($h['caja']) ?></td>
            <td style="font-size:.82rem"><?= P::e($h['cajero']) ?></td>
            <td class="cm-num cm-mono"><?= P::e(P::money((int) $h['esperado_cent'])) ?></td>
            <td class="cm-num cm-mono">
              <?= $h['contado_cent'] === null ? '—' : P::e(P::money((int) $h['contado_cent'])) ?>
            </td>
            <td class="cm-num cm-mono <?= $dif === null ? '' : ($dif === 0 ? '' : ($dif > 0 ? 'cm-pos' : 'cm-neg')) ?>">
              <?= $dif === null ? '—' : (($dif > 0 ? '+' : '') . P::e(P::money($dif))) ?>
            </td>
            <td>
              <span class="badge badge-<?= $colorEstado((string) $h['estado']) ?>">
                <?= P::e($h['estado']) ?>
              </span>
            </td>
            <td style="font-size:.78rem" class="text-muted">
              <?= $h['arqueada_en'] !== null
                    ? P::e($h['arqueador'] ?? '') . '<br>' . P::e(P::fecha((string) $h['arqueada_en'], 'd/m H:i'))
                    : '<span class="text-muted">pendiente</span>' ?>
            </td>
            <td class="cm-acciones cm-nowrap">
              <a class="btn btn-sm btn-outline-secondary"
                 href="<?= P::attr($rutas->url('caja/' . $h['codigo'])) ?>">Ver</a>
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

  /* El arqueo se va sumando mientras se cuenta.
     Un cajero no debería tener que multiplicar doce por cien de cabeza
     para saber si le cuadra, ni enterarse del faltante al pulsar el
     botón: se ve mientras cuenta. */
  var esperado = <?= (int) ($turno['esperado_cent'] ?? 0) ?>;
  var campos = document.querySelectorAll('[data-cm-den]');
  var contado = document.getElementById('arqueo-contado');
  var difEl = document.getElementById('arqueo-dif');

  if (!campos.length || !contado || !difEl) { return; }

  function recalcular() {
    var total = 0;

    campos.forEach(function (c) {
      var den = parseInt(c.getAttribute('data-cm-den'), 10) || 0;
      var n = parseInt(c.value, 10);
      if (isNaN(n) || n < 0) { n = 0; }

      var sub = den * n;
      total += sub;

      var destino = document.querySelector('[data-cm-sub="' + den + '"]');
      if (destino) { destino.textContent = CMUtil.money(sub); }
    });

    contado.textContent = CMUtil.money(total);

    var dif = total - esperado;
    difEl.textContent = (dif > 0 ? '+' : '') + CMUtil.money(dif);
    difEl.className = dif === 0 ? '' : (dif > 0 ? 'cm-pos' : 'cm-neg');
  }

  campos.forEach(function (c) {
    c.addEventListener('input', recalcular);
    c.addEventListener('change', recalcular);
  });

  recalcular();
})();
</script>
