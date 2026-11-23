<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $movs
 * @var array $resumen
 * @var string $desde
 * @var string $hasta
 * @var string $tipo
 */
use CashMan\Http\Plantilla as P;
?>
<div class="card">
  <div class="card-header"><h4 class="card-title mb-0">Filtros</h4></div>
  <div class="card-body">
    <form method="get" class="form-row align-items-end">
      <div class="form-group col-md-3">
        <label class="small">Desde</label>
        <input type="date" class="form-control form-control-sm" name="desde" value="<?= P::attr($desde) ?>">
      </div>
      <div class="form-group col-md-3">
        <label class="small">Hasta</label>
        <input type="date" class="form-control form-control-sm" name="hasta" value="<?= P::attr($hasta) ?>">
      </div>
      <div class="form-group col-md-3">
        <label class="small">Tipo</label>
        <select class="form-control form-control-sm" name="t">
          <option value="">Todos</option>
          <?php foreach (['transferencia','deposito','retiro','pago_cuota','pago_tarjeta','ajuste'] as $t): ?>
          <option value="<?= $t ?>"<?= $tipo === $t ? ' selected' : '' ?>>
            <?= P::e(ucfirst(str_replace('_', ' ', $t))) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group col-md-3">
        <button class="btn btn-sm btn-primary">Aplicar</button>
        <a href="?desde=<?= P::attr($desde) ?>&hasta=<?= P::attr($hasta) ?>&t=<?= P::attr($tipo) ?>&csv=1"
           class="btn btn-sm btn-outline-secondary">Exportar CSV</a>
      </div>
    </form>
  </div>
</div>

<div class="row">
  <?php foreach ([
    ['Operaciones', number_format((int) ($resumen['operaciones'] ?? 0)), 'dark'],
    ['Entradas',    P::money((int) ($resumen['entradas'] ?? 0)),         'success'],
    ['Salidas',     P::money((int) ($resumen['salidas'] ?? 0)),          'danger'],
  ] as [$et, $v, $c]): ?>
  <div class="col-md-4 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et"><?= P::e($et) ?></div>
      <div class="cm-kpi__val text-<?= P::attr($c) ?>" style="font-size:1.4rem"><?= P::e($v) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0">Movimientos</h4>
    <span class="badge badge-light"><?= count($movs) ?> registros</span>
  </div>
  <div class="card-body p-0">
    <?php if ($movs === []): ?>
      <div class="cm-vacio"><p class="mb-0">Sin movimientos en el periodo.</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" data-cm-tabla>
        <thead>
          <tr><th>Fecha</th><th>Referencia</th><th>Tipo</th><th>Cuenta</th>
              <th>Titular</th><th>Operador</th><th class="cm-num">Importe</th>
              <th class="text-right" style="width:1%;white-space:nowrap">Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach ($movs as $m): $i = (int) $m['importe_cent']; ?>
          <tr>
            <td class="text-muted" style="white-space:nowrap">
              <?= P::e(P::fecha((string) $m['creado_en'], 'd/m/Y H:i')) ?>
            </td>
            <td class="cm-mono text-muted"><?= P::e($m['referencia']) ?></td>
            <td><span class="badge badge-light">
              <?= P::e(ucfirst(str_replace('_', ' ', (string) $m['tipo']))) ?></span></td>
            <td class="cm-mono"><?= P::e($m['numero_cuenta']) ?></td>
            <td><?= P::e($m['titular']) ?></td>
            <td class="text-muted" style="font-size:.82rem"><?= P::e($m['operador']) ?></td>
            <td class="cm-num <?= $i >= 0 ? 'cm-pos' : 'cm-neg' ?>">
              <?= $i >= 0 ? '+' : '' ?><?= P::e(P::money($i)) ?>
            </td>
            <td class="text-right">
              <?php /* El enlace va con el hashid del asiento, no con su
                       referencia: la referencia es lo que se lee, el
                       hashid es lo que la ruta sabe descodificar. */ ?>
              <?= P::crudo(cm_acciones([
                    ['Comprobante',
                     $rutas->url('comprobante/' . $m['comprobante']),
                     'download', 'nueva' => true,
                     'documento' => 'Preparando el comprobante',
                     'titulo' => 'Descargar el comprobante en PDF'],
                  ])) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
