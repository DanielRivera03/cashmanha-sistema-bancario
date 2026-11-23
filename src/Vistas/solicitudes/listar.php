<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $solicitudes
 * @var array $resumen
 * @var bool $revisa
 * @var string $filtro
 * @var array $etapas
 */
use CashMan\Http\Plantilla as P;

$COLOR = [
  'capturada' => 'secondary', 'revision_atencion' => 'info',
  'revision_gerencia' => 'primary', 'revision_presidencia' => 'warning',
  'aprobada' => 'success', 'rechazada' => 'danger', 'desembolsada' => 'dark',
];
?>

<div class="row">
  <?php foreach ([
    ['Total',      (int) ($resumen['total'] ?? 0),      'dark'],
    ['En trámite', (int) ($resumen['en_tramite'] ?? 0), 'warning'],
    ['Aprobadas',  (int) ($resumen['aprobadas'] ?? 0),  'success'],
    ['Rechazadas', (int) ($resumen['rechazadas'] ?? 0), 'danger'],
  ] as [$et, $val, $color]): ?>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et"><?= P::e($et) ?></div>
      <div class="cm-kpi__val text-<?= P::attr($color) ?>"><?= number_format($val) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
    <h4 class="card-title mb-0">
      <?= $revisa ? 'Solicitudes de crédito' : 'Mis solicitudes' ?>
      <?php if ($filtro !== ''): ?>
        <span class="badge badge-light ml-1"><?= P::e($etapas[$filtro][0] ?? $filtro) ?></span>
        <a href="<?= P::attr($rutas->url('solicitudes')) ?>" class="small ml-1">quitar</a>
      <?php endif; ?>
    </h4>
    <a href="<?= P::attr($rutas->url('solicitudes/nueva')) ?>" class="btn btn-sm btn-primary">
      <?= cm_icono('plus', 15, 'mr-1') ?> Nueva solicitud
    </a>
  </div>

  <?php if ($revisa): ?>
  <div class="card-body pb-0">
    <?php foreach (['capturada','revision_atencion','revision_gerencia','revision_presidencia'] as $e): ?>
    <a href="<?= P::attr($rutas->url('solicitudes')) ?>?e=<?= P::attr($e) ?>"
       class="btn btn-sm mr-1 mb-2 <?= $filtro === $e ? 'btn-primary' : 'btn-outline-secondary' ?>">
      <?= P::e($etapas[$e][0] ?? $e) ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="card-body p-0">
    <?php if ($solicitudes === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('files', 40) ?>
        <p class="mb-0 mt-2">No hay solicitudes<?= $filtro !== '' ? ' en esta etapa' : '' ?>.</p>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0" data-cm-tabla>
        <thead>
          <tr>
            <th>Folio</th>
            <?php if ($revisa): ?><th>Cliente</th><?php endif; ?>
            <th>Producto</th><th class="cm-num">Monto</th><th class="cm-num">Plazo</th>
            <th>Etapa</th><th>Fecha</th>
            <th class="text-right" style="width:1%;white-space:nowrap">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($solicitudes as $s): ?>
          <tr<?= $s['puede_revisar'] ? ' class="table-info"' : '' ?>>
            <td>
              <a href="<?= P::attr($rutas->url('solicitudes/' . $s['codigo'])) ?>"
                 class="cm-mono font-weight-bold"><?= P::e($s['folio']) ?></a>
            </td>
            <?php if ($revisa): ?>
            <td><?= P::e($s['cliente']) ?><br>
              <small class="text-muted"><?= P::e($s['correo']) ?></small></td>
            <?php endif; ?>
            <td class="text-muted"><?= P::e($s['producto']) ?></td>
            <td class="cm-num font-weight-bold"><?= P::e(P::money((int) $s['monto_cent'])) ?></td>
            <td class="cm-num"><?= (int) $s['plazo_meses'] ?> m</td>
            <td>
              <span class="badge badge-<?= P::attr($COLOR[$s['etapa']] ?? 'light') ?>">
                <?= P::e($s['etapa_nombre']) ?>
              </span>
            </td>
            <td class="text-muted"><?= P::e(P::fecha((string) $s['creado_en'], 'd/m/Y')) ?></td>
            <td class="text-right">
              <?php if ($s['puede_revisar']): ?>
                <a href="<?= P::attr($rutas->url('solicitudes/' . $s['codigo'])) ?>"
                   class="btn btn-sm btn-primary">Revisar</a>
              <?php else: ?>
                <a href="<?= P::attr($rutas->url('solicitudes/' . $s['codigo'])) ?>"
                   class="btn btn-sm btn-outline-secondary">Ver</a>
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
