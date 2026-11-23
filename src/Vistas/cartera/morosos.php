<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $morosos
 * @var array $resumen
 * @var array $totales
 * @var string $clasificacion
 */
use CashMan\Http\Plantilla as P;

$ETIQ = [
  'A' => ['Normal',        'hasta 30 días'],
  'B' => ['Mención esp.',  '31 a 60 días'],
  'C' => ['Deficiente',    '61 a 90 días'],
  'D' => ['Dudoso',        '91 a 180 días'],
  'E' => ['Irrecuperable', 'más de 180 días'],
];
?>

<div class="row">
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Clientes en mora</div>
      <div class="cm-kpi__val"><?= number_format((int) $totales['clientes']) ?></div>
    </div>
  </div>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Deuda vencida</div>
      <div class="cm-kpi__val cm-neg"><?= P::e(P::money((int) $totales['deuda_cent'])) ?></div>
    </div>
  </div>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Mora acumulada</div>
      <div class="cm-kpi__val"><?= P::e(P::money((int) $totales['mora_cent'])) ?></div>
    </div>
  </div>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Peor atraso</div>
      <div class="cm-kpi__val"><?= number_format((int) $totales['peor']) ?>
        <small class="text-muted" style="font-size:.9rem">días</small></div>
    </div>
  </div>
</div>

<?php if ($resumen !== []): ?>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0">Clasificación de riesgo</h4>
    <a href="<?= P::attr($rutas->url('cartera/exportar')) ?>" class="btn btn-sm btn-outline-secondary">
      Exportar CSV
    </a>
  </div>
  <div class="card-body">
    <a href="<?= P::attr($rutas->url('cartera')) ?>"
       class="btn btn-sm mr-1 mb-1 <?= $clasificacion === '' ? 'btn-primary' : 'btn-outline-secondary' ?>">
      Todas
    </a>
    <?php foreach ($resumen as $r):
      $c = (string) $r['clase'];
      [$nombre, $rango] = $ETIQ[$c] ?? [$c, ''];
    ?>
    <a href="<?= P::attr($rutas->url('cartera')) ?>?c=<?= P::attr($c) ?>"
       class="btn btn-sm mr-1 mb-1 <?= $clasificacion === $c ? 'btn-primary' : 'btn-outline-secondary' ?>"
       title="<?= P::attr($rango) ?>">
      <span class="cm-riesgo cm-riesgo--<?= P::attr($c) ?> mr-1"><?= P::e($c) ?></span>
      <?= P::e($nombre) ?>
      <span class="badge badge-light ml-1"><?= (int) $r['casos'] ?></span>
      <?= P::e(P::money((int) $r['deuda_cent'])) ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0">Detalle de la cartera</h4>
    <span class="badge badge-light"><?= count($morosos) ?> registros</span>
  </div>
  <div class="card-body p-0">
    <?php if ($morosos === []): ?>
      <div class="cm-vacio">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
        <p class="mb-0 mt-2">No hay clientes en mora<?= $clasificacion !== '' ? ' en esta clasificación' : '' ?>.</p>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0" data-cm-tabla>
        <thead>
          <tr><th>Cliente</th><th>Contacto</th><th>Clase</th><th class="cm-num">Cuotas</th>
              <th class="cm-num">Deuda vencida</th><th class="cm-num">Mora</th>
              <th class="cm-num">Días</th><th>Vencimiento</th>
              <th class="cm-acciones" style="width:1%;white-space:nowrap">Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach ($morosos as $m):
          $c = (string) $m['clasificacion_sugerida'];
          [$nombre] = $ETIQ[$c] ?? [$c];
          $dias = (int) $m['dias_mora_max'];
        ?>
          <tr>
            <td class="font-weight-bold"><?= P::e($m['cliente']) ?></td>
            <td class="text-muted" style="font-size:.85rem">
              <?= P::e($m['correo']) ?>
              <?php if (!empty($m['telefono'])): ?><br><?= P::e($m['telefono']) ?><?php endif; ?>
            </td>
            <td>
              <span class="cm-riesgo cm-riesgo--<?= P::attr($c) ?>"><?= P::e($c) ?></span>
              <small class="text-muted ml-1"><?= P::e($nombre) ?></small>
            </td>
            <td class="cm-num"><?= (int) $m['cuotas_vencidas'] ?></td>
            <td class="cm-num cm-neg"><?= P::e(P::money((int) $m['deuda_vencida_cent'])) ?></td>
            <td class="cm-num"><?= P::e(P::money((int) $m['mora_acumulada_cent'])) ?></td>
            <td class="cm-num <?= $dias > 90 ? 'cm-neg font-weight-bold' : '' ?>"><?= $dias ?></td>
            <td class="text-muted"><?= P::e(P::fecha((string) $m['vencimiento_mas_antiguo'], 'd/m/Y')) ?></td>
            <td class="cm-acciones cm-nowrap">
              <?php /* Esta lista dice cuánto se debe. El expediente dice
                       a quién se llamó, qué contestó y en qué punto del
                       proceso va: es donde de verdad se trabaja el caso.
                       Por eso va primero cuando existe. */ ?>
              <?= P::crudo(cm_acciones([
                    ['Ver expediente',
                     $rutas->url('cobranza/' . ($m['codigo_cobranza'] ?? '')), 'warning',
                     'permiso' => 'cobranza.ver',
                     'si' => !empty($m['codigo_cobranza']),
                     'titulo' => 'Gestiones, promesas y estado del cobro'],

                    ['Ver el crédito',
                     $rutas->url('creditos/' . ($m['codigo_credito'] ?? '')), 'invest',
                     'si' => !empty($m['codigo_credito'])],

                    ['Ver al cliente',
                     $rutas->url('clientes/' . ($m['codigo_cliente'] ?? '')), 'search',
                     'permiso' => 'cliente.ver_expediente',
                     'si' => !empty($m['codigo_cliente'])],
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
