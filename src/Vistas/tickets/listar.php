<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $tickets
 * @var array $resumen
 * @var bool $gestiona
 * @var string $filtro
 */
use CashMan\Http\Plantilla as P;

$COLOR_EST = [
  'abierto'     => 'primary',
  'en_proceso'  => 'warning',
  'resuelto'    => 'success',
  'no_resuelto' => 'danger',
  'cerrado'     => 'secondary',
];
$COLOR_PRI = ['baja' => 'light', 'media' => 'info', 'alta' => 'warning', 'critica' => 'danger'];
?>

<div class="row">
  <?php
    $kpis = [
      ['Abiertos',   (int) ($resumen['abiertos'] ?? 0),   'primary', 'abierto'],
      ['En proceso', (int) ($resumen['en_proceso'] ?? 0), 'warning', 'en_proceso'],
      ['Resueltos',  (int) ($resumen['resueltos'] ?? 0),  'success', 'resuelto'],
    ];
    if ($gestiona) { $kpis[] = ['Críticos', (int) ($resumen['criticos'] ?? 0), 'danger', '']; }
    foreach ($kpis as [$et, $val, $color, $f]):
  ?>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <a href="<?= P::attr($rutas->url('tickets')) ?><?= $f !== '' ? '?e=' . $f : '' ?>"
       class="cm-kpi d-block text-decoration-none<?= $filtro === $f && $f !== '' ? ' border-primary' : '' ?>">
      <div class="cm-kpi__et"><?= P::e($et) ?></div>
      <div class="cm-kpi__val text-<?= P::attr($color) ?>"><?= number_format($val) ?></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
    <h4 class="card-title mb-0">
      <?= $gestiona ? 'Todos los tickets' : 'Mis reportes' ?>
      <?php if ($filtro !== ''): ?>
        <span class="badge badge-light ml-1"><?= P::e(str_replace('_', ' ', $filtro)) ?></span>
        <a href="<?= P::attr($rutas->url('tickets')) ?>" class="small ml-1">quitar filtro</a>
      <?php endif; ?>
    </h4>
    <a href="<?= P::attr($rutas->url('tickets/nuevo')) ?>" class="btn btn-sm btn-primary">
      <?= cm_icono('plus', 15, 'mr-1') ?> Reportar problema
    </a>
  </div>

  <div class="card-body p-0">
    <?php if ($tickets === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('support', 40) ?>
        <p class="mb-0 mt-2">No hay tickets<?= $filtro !== '' ? ' en este estado' : '' ?>.</p>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0" data-cm-tabla>
        <thead>
          <tr>
            <th>Folio</th><th>Asunto</th><th>Categoría</th><th>Prioridad</th>
            <?php if ($gestiona): ?><th>Solicitante</th><th>Responsable</th><?php endif; ?>
            <th>Estado</th><th class="cm-num">Resp.</th><th>Actividad</th>
            <th class="text-right" style="width:1%;white-space:nowrap">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($tickets as $t): ?>
          <tr>
            <td>
              <a href="<?= P::attr($rutas->url('tickets/' . $t['codigo'])) ?>" class="cm-mono font-weight-bold">
                <?= P::e($t['folio']) ?>
              </a>
            </td>
            <td><?= P::e(mb_substr((string) $t['titulo'], 0, 60)) ?></td>
            <td><span class="badge badge-light"><?= P::e(ucfirst((string) $t['categoria'])) ?></span></td>
            <td>
              <span class="badge badge-<?= P::attr($COLOR_PRI[$t['prioridad']] ?? 'light') ?>">
                <?= P::e(ucfirst((string) $t['prioridad'])) ?>
              </span>
            </td>
            <?php if ($gestiona): ?>
            <td class="text-muted"><?= P::e($t['solicitante']) ?></td>
            <td class="text-muted"><?= P::e($t['responsable'] ?: '— sin asignar —') ?></td>
            <?php endif; ?>
            <td>
              <span class="badge badge-<?= P::attr($COLOR_EST[$t['estado']] ?? 'light') ?>">
                <?= P::e(str_replace('_', ' ', (string) $t['estado'])) ?>
              </span>
            </td>
            <td class="cm-num"><?= (int) $t['respuestas'] ?></td>
            <td class="text-muted"><?= P::e(P::hace((string) $t['ultima_actividad'])) ?></td>
            <td class="text-right">
              <?= P::crudo(cm_acciones([
                    ['Abrir', $rutas->url('tickets/' . $t['codigo']), 'support',
                     'estilo' => 'primary'],
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
