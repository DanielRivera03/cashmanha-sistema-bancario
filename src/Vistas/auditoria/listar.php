<?php
use CashMan\Http\Plantilla as P;
$COLOR = ['exito'=>'success','fallo'=>'warning','denegado'=>'danger'];
?>
<div class="row">
  <?php foreach ([
    ['Eventos (30 días)',(int)($resumen['total'] ?? 0),'dark',''],
    ['Exitosos',(int)($resumen['exitos'] ?? 0),'success','exito'],
    ['Fallidos',(int)($resumen['fallos'] ?? 0),'warning','fallo'],
    ['Denegados',(int)($resumen['denegados'] ?? 0),'danger','denegado'],
  ] as [$et,$v,$c,$f]): ?>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <a href="<?= P::attr($rutas->url('auditoria')) ?><?= $f!==''?'?r='.$f:'' ?>"
       class="cm-kpi d-block text-decoration-none<?= $fResultado===$f && $f!==''?' border-primary':'' ?>">
      <div class="cm-kpi__et"><?= P::e($et) ?></div>
      <div class="cm-kpi__val text-<?= P::attr($c) ?>"><?= number_format($v) ?></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
    <h4 class="card-title mb-0">Bitácora de auditoría</h4>
    <a href="<?= P::attr($rutas->url('auditoria/integridad')) ?>" class="btn btn-sm btn-outline-primary">
      <?= cm_icono('shield',15,'mr-1') ?> Verificar integridad
    </a>
  </div>

  <div class="card-body pb-0">
    <a href="<?= P::attr($rutas->url('auditoria')) ?>"
       class="btn btn-sm mr-1 mb-2 <?= $fAccion===''?'btn-primary':'btn-outline-secondary' ?>">Todas</a>
    <?php foreach ($acciones as $a): ?>
    <a href="<?= P::attr($rutas->url('auditoria')) ?>?a=<?= P::attr((string)$a['modulo']) ?>"
       class="btn btn-sm mr-1 mb-2 <?= $fAccion===$a['modulo']?'btn-primary':'btn-outline-secondary' ?>">
      <?= P::e((string)$a['modulo']) ?> <span class="badge badge-light"><?= (int)$a['n'] ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="card-body p-0">
    <?php if ($filas === []): ?>
      <div class="cm-vacio"><p class="mb-0">Sin eventos registrados.</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Entidad</th>
          <th>Resultado</th><th>IP</th><th>Detalle</th></tr></thead>
        <tbody>
        <?php foreach ($filas as $f): ?>
          <tr>
            <td class="text-muted" style="white-space:nowrap">
              <?= P::e(P::fecha((string)$f['creado_en'], 'd/m H:i:s')) ?></td>
            <td><?= P::e($f['usuario'] ?: '—') ?></td>
            <td class="cm-mono"><?= P::e($f['accion']) ?></td>
            <td class="text-muted">
              <?= P::e($f['entidad'] ?: '—') ?>
              <?php if (!empty($f['entidad_id'])): ?>
                <span class="cm-mono">#<?= P::e($f['entidad_id']) ?></span>
              <?php endif; ?>
            </td>
            <td><span class="badge badge-<?= P::attr($COLOR[$f['resultado']] ?? 'light') ?>">
              <?= P::e($f['resultado']) ?></span></td>
            <td class="cm-mono text-muted"><?= P::e($f['ip_txt']) ?></td>
            <td class="text-muted" style="font-size:.78rem;max-width:260px">
              <?php if (is_array($f['detalle'])): ?>
                <?php foreach (array_slice($f['detalle'],0,3) as $k=>$v): ?>
                  <span class="badge badge-light"><?= P::e((string)$k) ?>: <?= P::e(is_scalar($v)?(string)$v:'…') ?></span>
                <?php endforeach; ?>
              <?php else: ?>—<?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php if (($total ?? 0) > 0): ?>
  <div class="card-footer pt-0">
    <?php
    /* Los enlaces anteriores arrastraban solo el filtro de acción: al
       pasar de página con un resultado filtrado, el filtro se perdía. */
    $conEstado = static function (array $extra) use ($fAccion, $fResultado, $paginacion): string {
        $base = [
            'a'  => (string) $fAccion,
            'r'  => (string) $fResultado,
            'pp' => (int) $paginacion['porPagina'] === 50 ? '' : (string) $paginacion['porPagina'],
        ];
        $todo = array_filter(array_merge($base, $extra), static fn($v) => $v !== '' && $v !== null);

        return '?' . http_build_query($todo);
    };

    $pag          = $paginacion;
    $enlace       = static fn(int $p): string => $conEstado(['p' => $p]);
    $enlaceTamano = static fn(int $t): string => $conEstado(['pp' => $t, 'p' => 1]);

    include __DIR__ . '/../layout/paginacion.php';
    ?>
  </div>
  <?php endif; ?>
</div>
