<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $creditos
 * @var array $resumen
 */
use CashMan\Http\Plantilla as P;

$otorgado   = (int) ($resumen['otorgado'] ?? 0);
$recuperado = (int) ($resumen['recuperado'] ?? 0);
$pct        = $otorgado > 0 ? round(($recuperado / $otorgado) * 100, 1) : 0.0;
?>
<div class="row">
  <?php foreach ([
    ['Créditos activos', number_format((int) ($resumen['creditos'] ?? 0)), 'dark'],
    ['Monto otorgado',   P::money($otorgado),                              'info'],
    ['Saldo pendiente',  P::money((int) ($resumen['saldo'] ?? 0)),          'danger'],
    ['Recuperado',       P::money($recuperado),                            'success'],
  ] as [$et, $v, $c]): ?>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et"><?= P::e($et) ?></div>
      <div class="cm-kpi__val text-<?= P::attr($c) ?>" style="font-size:1.35rem"><?= P::e($v) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-body">
    <div class="d-flex justify-content-between mb-1">
      <small class="text-muted">Recuperación de la cartera</small>
      <small class="font-weight-bold"><?= $pct ?>%</small>
    </div>
    <div class="progress" style="height:10px">
      <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0">Detalle de la cartera</h4>
    <span class="badge badge-light"><?= count($creditos) ?> créditos</span>
  </div>
  <div class="card-body p-0">
    <?php if ($creditos === []): ?>
      <div class="cm-vacio"><p class="mb-0">Sin créditos activos.</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" data-cm-tabla>
        <thead>
          <tr><th>Folio</th><th>Cliente</th><th>Producto</th><th>Clase</th><th>Avance</th>
              <th class="cm-num">Tasa</th><th class="cm-num">Monto</th>
              <th class="cm-num">Saldo</th><th class="cm-num">Vencidas</th></tr>
        </thead>
        <tbody>
        <?php foreach ($creditos as $c): ?>
          <tr<?= (int) $c['vencidas'] > 0 ? ' class="table-warning"' : '' ?>>
            <td class="cm-mono"><?= P::e($c['folio'] ?: '—') ?></td>
            <td><?= P::e($c['cliente']) ?></td>
            <td class="text-muted"><?= P::e($c['producto']) ?></td>
            <td>
              <span class="cm-riesgo cm-riesgo--<?= P::attr((string) $c['clasificacion']) ?>">
                <?= P::e((string) $c['clasificacion']) ?>
              </span>
            </td>
            <td class="text-muted"><?= (int) $c['cuotas_pagadas'] ?>/<?= (int) $c['plazo_meses'] ?></td>
            <td class="cm-num"><?= number_format((int) $c['tasa_bp'] / 100, 2) ?>%</td>
            <td class="cm-num"><?= P::e(P::money((int) $c['monto_cent'])) ?></td>
            <td class="cm-num font-weight-bold cm-neg"><?= P::e(P::money((int) $c['saldo_capital_cent'])) ?></td>
            <td class="cm-num">
              <?php if ((int) $c['vencidas'] > 0): ?>
                <span class="badge badge-danger"><?= (int) $c['vencidas'] ?></span>
              <?php else: ?>
                <span class="text-muted">—</span>
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
