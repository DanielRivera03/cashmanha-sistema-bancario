<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $ind
 * @var array $serie
 * @var array $porTipo
 * @var array $cartera
 * @var array $productos
 * @var array $contable
 */
use CashMan\Http\Plantilla as P;

$cuadrado = (int) ($contable['descuadrados'] ?? 0) === 0
         && (int) ($contable['sin_conciliar'] ?? 0) === 0
         && (int) ($contable['balance'] ?? 0) === 0;
?>

<!-- Estado contable, siempre a la vista -->
<div class="alert alert-<?= $cuadrado ? 'success' : 'danger' ?> d-flex align-items-center">
  <?= cm_icono($cuadrado ? 'check' : 'warning', 22, 'mr-3') ?>
  <div class="flex-grow-1">
    <strong><?= $cuadrado ? 'Contabilidad cuadrada.' : 'Hay descuadres contables.' ?></strong>
    <?= (int) ($contable['descuadrados'] ?? 0) ?> asientos descuadrados ·
    <?= (int) ($contable['sin_conciliar'] ?? 0) ?> saldos sin conciliar ·
    balance <?= P::e(P::money((int) ($contable['balance'] ?? 0))) ?>
  </div>
</div>

<div class="row">
  <?php foreach ([
    ['Clientes activos',  number_format((int) ($ind['clientes_activos'] ?? 0)),         'users'],
    ['Captaciones',       P::money((int) ($ind['captaciones_cent'] ?? 0)),              'wallet'],
    ['Cartera de crédito',P::money((int) ($ind['cartera_cent'] ?? 0)),                  'invest'],
    ['Mora',              P::money((int) ($ind['mora_cent'] ?? 0)),                     'warning'],
  ] as [$et, $val, $ico]): ?>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi d-flex align-items-center">
      <div class="cm-kpi__icono mr-3" style="background:#f0e6fb;color:#6418c3">
        <?= cm_icono($ico, 22) ?>
      </div>
      <div style="min-width:0">
        <div class="cm-kpi__et"><?= P::e($et) ?></div>
        <div class="cm-kpi__val" style="font-size:1.35rem"><?= P::e($val) ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row">
  <?php foreach ([
    ['Cuentas activas',     (int) ($ind['cuentas_activas'] ?? 0)],
    ['Créditos vigentes',   (int) ($ind['creditos_vigentes'] ?? 0)],
    ['Tarjetas activas',    (int) ($ind['tarjetas_activas'] ?? 0)],
    ['Operaciones hoy',     (int) ($ind['operaciones_hoy'] ?? 0)],
    ['Solicitudes abiertas',(int) ($ind['solicitudes_pendientes'] ?? 0)],
    ['Tickets abiertos',    (int) ($ind['tickets_abiertos'] ?? 0)],
  ] as [$et, $v]): ?>
  <div class="col-xl-2 col-lg-4 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et"><?= P::e($et) ?></div>
      <div class="cm-kpi__val" style="font-size:1.4rem"><?= number_format($v) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row">
  <!-- Operaciones por día -->
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Operaciones (últimos 30 días)</h4></div>
      <div class="card-body">
        <?php if ($serie === []): ?>
          <div class="cm-vacio"><p class="mb-0">Sin operaciones en el periodo.</p></div>
        <?php else: ?>
          <canvas id="g-serie" height="110"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Operaciones por tipo -->
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Operaciones por tipo</h4></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th>Tipo</th><th class="cm-num">Operaciones</th><th class="cm-num">Monto</th></tr></thead>
            <tbody>
            <?php foreach ($porTipo as $t): ?>
              <tr>
                <td><?= P::e(ucfirst(str_replace('_', ' ', (string) $t['tipo']))) ?></td>
                <td class="cm-num"><?= number_format((int) $t['n']) ?></td>
                <td class="cm-num"><?= P::e(P::money((int) $t['monto'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <!-- Cartera por clasificación -->
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Cartera por clasificación</h4></div>
      <div class="card-body p-0">
        <?php if ($cartera === []): ?>
          <div class="cm-vacio"><p class="mb-0">Sin cartera en mora.</p></div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th>Clase</th><th class="cm-num">Casos</th><th class="cm-num">Deuda</th></tr></thead>
            <tbody>
            <?php foreach ($cartera as $c): ?>
              <tr>
                <td><span class="cm-riesgo cm-riesgo--<?= P::attr((string) $c['clase']) ?>">
                  <?= P::e((string) $c['clase']) ?></span></td>
                <td class="cm-num"><?= number_format((int) $c['casos']) ?></td>
                <td class="cm-num cm-neg"><?= P::e(P::money((int) $c['deuda'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Productos -->
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Productos</h4></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th>Producto</th><th>Tipo</th><th class="cm-num">Cuentas</th>
              <th class="cm-num">Créditos</th><th class="cm-num">Saldo</th></tr></thead>
            <tbody>
            <?php foreach ($productos as $p): ?>
              <tr>
                <td><?= P::e($p['nombre']) ?></td>
                <td><span class="badge badge-light"><?= P::e($p['tipo']) ?></span></td>
                <td class="cm-num"><?= number_format((int) $p['cuentas']) ?></td>
                <td class="cm-num"><?= number_format((int) $p['creditos']) ?></td>
                <td class="cm-num"><?= P::e(P::money((int) $p['saldo'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card-footer">
        <a href="<?= P::attr($rutas->url('reportes/transacciones')) ?>" class="btn btn-sm btn-outline-primary">
          Reporte de transacciones
        </a>
        <a href="<?= P::attr($rutas->url('reportes/cartera')) ?>" class="btn btn-sm btn-outline-primary">
          Reporte de cartera
        </a>
      </div>
    </div>
  </div>
</div>

<?php if ($serie !== []): ?>
<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  var ctx = document.getElementById('g-serie');
  if (!ctx || !window.Chart) return;

  var datos = <?= P::js(array_map(static fn(array $s): array => [
      'dia' => substr((string) $s['dia'], 5),
      'ops' => (int) $s['operaciones'],
      'trf' => (int) $s['transferencias'],
      'dep' => (int) $s['depositos'],
  ], $serie)) ?>;

  new Chart(ctx, {
    type: 'line',
    data: {
      labels: datos.map(function (d) { return d.dia; }),
      datasets: [
        { label: 'Operaciones', data: datos.map(function (d) { return d.ops; }),
          borderColor: '#6418c3', backgroundColor: 'rgba(100,24,195,.12)',
          fill: true, tension: .32, borderWidth: 2, pointRadius: 2 },
        { label: 'Transferencias', data: datos.map(function (d) { return d.trf; }),
          borderColor: '#175cd3', backgroundColor: 'transparent',
          tension: .32, borderWidth: 2, pointRadius: 2 }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      legend: { display: true, position: 'bottom' },
      scales: { yAxes: [{ ticks: { beginAtZero: true, precision: 0 } }] }
    }
  });
})();
</script>
<?php endif; ?>
