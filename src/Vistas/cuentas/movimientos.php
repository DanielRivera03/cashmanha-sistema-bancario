<?php
/**
 * Movimientos.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $movimientos
 * @var int   $total
 * @var int   $pagina
 * @var int   $paginas
 * @var array $paginacion
 * @var bool  $vistaTodas
 * @var array $resumen
 * @var array $tipos
 * @var array $filtros
 */
use CashMan\Http\Plantilla as P;

$hayFiltro = ($filtros['tipo'] ?? '') !== '' || ($filtros['desde'] ?? '') !== ''
          || ($filtros['hasta'] ?? '') !== '' || ($filtros['q'] ?? '') !== '';

/** Conserva los filtros al cambiar de página. */
$conFiltros = static function (array $extra) use ($filtros): string {
    $p = array_filter(array_merge($filtros, $extra), static fn($v) => $v !== '' && $v !== null);
    return $p === [] ? '' : '?' . http_build_query($p);
};

$neto = (int) $resumen['entradas'] - (int) $resumen['salidas'];
?>

<div class="row">
  <div class="col-xl-3 col-lg-6 col-sm-6">
    <div class="card">
      <div class="card-body d-flex align-items-center">
        <div class="cm-kpi__icono bg-primary text-white mr-3"><?= cm_icono('swap', 22) ?></div>
        <div>
          <h4 class="mb-0 font-weight-bold" style="font-size:1.25rem"><?= number_format($total) ?></h4>
          <small class="text-muted" style="font-size:.76rem">
            movimiento<?= $total === 1 ? '' : 's' ?><?= $hayFiltro ? ' (filtrados)' : '' ?>
          </small>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-3 col-lg-6 col-sm-6">
    <div class="card">
      <div class="card-body d-flex align-items-center">
        <div class="cm-kpi__icono bg-success text-white mr-3"><?= cm_icono('down', 22) ?></div>
        <div>
          <h4 class="mb-0 font-weight-bold text-success" style="font-size:1.25rem">
            <?= P::e(P::money((int) $resumen['entradas'])) ?>
          </h4>
          <small class="text-muted" style="font-size:.76rem">entradas</small>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-3 col-lg-6 col-sm-6">
    <div class="card">
      <div class="card-body d-flex align-items-center">
        <div class="cm-kpi__icono bg-danger text-white mr-3"><?= cm_icono('up', 22) ?></div>
        <div>
          <h4 class="mb-0 font-weight-bold text-danger" style="font-size:1.25rem">
            <?= P::e(P::money((int) $resumen['salidas'])) ?>
          </h4>
          <small class="text-muted" style="font-size:.76rem">salidas</small>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-3 col-lg-6 col-sm-6">
    <div class="card">
      <div class="card-body d-flex align-items-center">
        <div class="cm-kpi__icono bg-<?= $neto >= 0 ? 'info' : 'warning' ?> text-white mr-3">
          <?= cm_icono('invest', 22) ?>
        </div>
        <div>
          <h4 class="mb-0 font-weight-bold" style="font-size:1.25rem">
            <?= P::e(P::money($neto)) ?>
          </h4>
          <small class="text-muted" style="font-size:.76rem">neto del periodo</small>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">
      <?= $vistaTodas ? 'Libro de movimientos' : 'Historial de movimientos' ?>
    </h4>
  </div>

  <div class="card-body pb-2">
    <form method="get" class="form-row align-items-end mb-2">
      <?php if ($vistaTodas): ?>
      <div class="form-group col-lg-3 col-md-6">
        <label class="small mb-1">Buscar</label>
        <input type="search" name="q" class="form-control form-control-sm"
               value="<?= P::attr($filtros['q'] ?? '') ?>"
               placeholder="Cuenta, titular o referencia">
      </div>
      <?php endif; ?>

      <div class="form-group col-lg-2 col-md-4">
        <label class="small mb-1">Tipo</label>
        <select name="tipo" class="form-control form-control-sm">
          <option value="">Todos</option>
          <?php foreach ($tipos as $t): ?>
          <option value="<?= P::attr($t) ?>" <?= ($filtros['tipo'] ?? '') === $t ? 'selected' : '' ?>>
            <?= P::e(ucfirst(str_replace('_', ' ', (string) $t))) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group col-lg-2 col-md-4">
        <label class="small mb-1">Desde</label>
        <input type="date" name="desde" class="form-control form-control-sm"
               value="<?= P::attr($filtros['desde'] ?? '') ?>">
      </div>

      <div class="form-group col-lg-2 col-md-4">
        <label class="small mb-1">Hasta</label>
        <input type="date" name="hasta" class="form-control form-control-sm"
               value="<?= P::attr($filtros['hasta'] ?? '') ?>">
      </div>

      <div class="form-group col-lg-2 col-md-6">
        <button class="btn btn-outline-primary btn-sm btn-block">Filtrar</button>
      </div>

      <?php if ($hayFiltro): ?>
      <div class="form-group col-lg-1 col-md-6">
        <a href="<?= P::attr($rutas->url('movimientos')) ?>"
           class="btn btn-outline-secondary btn-sm btn-block">Limpiar</a>
      </div>
      <?php endif; ?>
    </form>
  </div>

  <div class="card-body pt-0">
    <?php if ($movimientos === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('swap', 40) ?>
        <p class="mb-1 mt-2">
          <?= $hayFiltro
              ? 'Ningún movimiento en ese rango.'
              : ($vistaTodas
                  ? 'Todavía no se ha registrado ningún movimiento.'
                  : 'Aún no tiene movimientos.') ?>
        </p>
        <?php if ($hayFiltro): ?>
          <a href="<?= P::attr($rutas->url('movimientos')) ?>"
             class="btn btn-sm btn-outline-secondary mt-2">Ver todos</a>
        <?php elseif (!$vistaTodas): ?>
          <p class="text-muted mb-0" style="font-size:.84rem">
            Aquí aparecerán sus depósitos, retiros y transferencias en cuanto haya alguno.
          </p>
        <?php endif; ?>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Concepto</th>
            <?php if ($vistaTodas): ?><th>Titular</th><?php endif; ?>
            <th>Cuenta</th>
            <th>Referencia</th>
            <th class="text-right">Importe</th>
            <th class="text-right">Saldo</th>
            <th class="text-right" style="width:1%;white-space:nowrap">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($movimientos as $m): $imp = (int) $m['importe_cent']; ?>
          <tr>
            <td style="white-space:nowrap;font-size:.8rem">
              <?= P::e(P::fecha((string) $m['creado_en'], 'd/m/Y')) ?>
              <small class="text-muted d-block" style="font-size:.72rem">
                <?= P::e(P::fecha((string) $m['creado_en'], 'H:i')) ?>
              </small>
            </td>
            <td>
              <?= P::e($m['descripcion']) ?>
              <small class="text-muted d-block" style="font-size:.72rem">
                <?= P::e(ucfirst(str_replace('_', ' ', (string) $m['tipo']))) ?>
              </small>
            </td>
            <?php if ($vistaTodas): ?>
            <td style="font-size:.82rem"><?= P::e($m['titular'] ?? '') ?></td>
            <?php endif; ?>
            <td class="cm-mono" style="font-size:.8rem"><?= P::e($m['numero_cuenta']) ?></td>
            <td class="cm-mono text-muted" style="font-size:.74rem"><?= P::e($m['referencia']) ?></td>
            <td class="text-right cm-mono font-weight-bold <?= $imp >= 0 ? 'text-success' : 'text-danger' ?>">
              <?= $imp >= 0 ? '+' : '' ?><?= P::e(P::money($imp)) ?>
            </td>
            <td class="text-right cm-mono text-muted">
              <?= P::e(P::money((int) $m['saldo_posterior_cent'])) ?>
            </td>
            <td class="text-right">
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

    <?php
    $pag          = $paginacion;
    $enlace       = static fn(int $p): string
                    => $rutas->url('movimientos') . $conFiltros(['p' => $p]);
    $enlaceTamano = static fn(int $t): string
                    => $rutas->url('movimientos') . $conFiltros(['pp' => $t, 'p' => 1]);

    include __DIR__ . '/../layout/paginacion.php';
    ?>
    <?php endif; ?>
  </div>
</div>
