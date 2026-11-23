<?php
/**
 * Estados de cuenta.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $estados
 * @var array  $cuentas
 * @var string $csrf
 * @var bool   $puedeGenerar
 * @var bool   $vistaTodos
 * @var bool   $puedeVerTodos
 * @var bool   $generaAjenos
 * @var array  $filtros
 * @var array  $clientesConEstado
 * @var array  $anios
 */
use CashMan\Http\Plantilla as P;

$hayFiltro = ($filtros['q'] ?? '') !== ''
          || ($filtros['cliente'] ?? '') !== ''
          || ($filtros['producto'] ?? '') !== ''
          || ($filtros['anio'] ?? '') !== '';

// Por defecto, el periodo del mes pasado: es lo que se pide el 95% de
// las veces y evita teclear dos fechas cada vez.
$finPorDefecto   = date('Y-m-d', (int) strtotime('last day of previous month'));
$inicioPorDefecto = date('Y-m-d', (int) strtotime('first day of previous month'));
?>

<?php if ($puedeGenerar && $cuentas !== []): ?>
<div class="card">
  <div class="card-header"><h4 class="card-title mb-0">Generar estado de cuenta</h4></div>
  <div class="card-body">
    <form data-cm-ajax data-cm-recargar method="post"
          action="<?= P::attr($rutas->url('estados/generar')) ?>">
      <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
      <input type="hidden" name="_csrf_proposito" value="estado">

      <div class="form-row align-items-end">
        <div class="form-group col-lg-5 col-md-6">
          <label for="cuenta">Cuenta</label>
          <select class="form-control" id="cuenta" name="cuenta" required>
            <?php foreach ($cuentas as $c): ?>
            <option value="<?= P::attr($c['codigo']) ?>">
              <?= P::e($c['numero_cuenta']) ?> — <?= P::e($c['producto']) ?>
              <?= ($c['titular'] ?? '') !== '' ? ' · ' . P::e($c['titular']) : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group col-lg-2 col-md-3">
          <label for="desde">Desde</label>
          <input type="date" class="form-control" id="desde" name="desde"
                 value="<?= P::attr($inicioPorDefecto) ?>" required>
        </div>

        <div class="form-group col-lg-2 col-md-3">
          <label for="hasta">Hasta</label>
          <input type="date" class="form-control" id="hasta" name="hasta"
                 value="<?= P::attr($finPorDefecto) ?>" required>
        </div>

        <div class="form-group col-lg-3 col-md-6">
          <button type="submit" class="btn btn-primary btn-block">Generar PDF</button>
        </div>
      </div>
    </form>

    <small class="text-muted d-block" style="font-size:.78rem">
      El documento se genera con el detalle de movimientos del periodo y
      queda archivado con su huella SHA-256, de modo que pueda comprobarse
      después que no se alteró.
    </small>
  </div>
</div>

<?php elseif ($puedeGenerar && $cuentas === []): ?>
<div class="card">
  <div class="card-body">
    <div class="cm-aviso cm-aviso--info mb-0">
      <?= cm_icono('info', 17) ?>
      <span>
        No hay cuentas disponibles para generar un estado. Abre una cuenta
        primero desde <a href="<?= P::attr($rutas->url('cuentas/abrir')) ?>">Abrir cuenta</a>.
      </span>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h4 class="card-title mb-0">
      <?= $vistaTodos ? 'Estados emitidos' : 'Mis estados' ?>
      <span class="badge badge-light ml-1"><?= count($estados) ?></span>
    </h4>

    <?php if ($puedeVerTodos): ?>
    <div class="btn-group btn-group-sm">
      <a href="<?= P::attr($rutas->url('estados')) ?>"
         class="btn btn-<?= $vistaTodos ? 'primary' : 'outline-primary' ?>">Todos</a>
      <a href="<?= P::attr($rutas->url('estados?mios=1')) ?>"
         class="btn btn-<?= $vistaTodos ? 'outline-primary' : 'primary' ?>">Solo míos</a>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($vistaTodos): ?>
  <div class="card-body pb-2">
    <form method="get" class="form-row align-items-end mb-0">
      <div class="form-group col-lg-3 col-md-6">
        <label class="small mb-1">Buscar</label>
        <input type="search" name="q" class="form-control form-control-sm"
               value="<?= P::attr($filtros['q'] ?? '') ?>"
               placeholder="Cliente, usuario o cuenta">
      </div>

      <div class="form-group col-lg-3 col-md-6">
        <label class="small mb-1">Cliente</label>
        <select name="cliente" class="form-control form-control-sm">
          <option value="">Todos los clientes</option>
          <?php foreach ($clientesConEstado as $cl): ?>
          <option value="<?= P::attr(P::hid((int) $cl['id'], 'usuario')) ?>"
                  <?= ($filtros['cliente'] ?? '') === P::hid((int) $cl['id'], 'usuario') ? 'selected' : '' ?>>
            <?= P::e($cl['nombre']) ?> (<?= (int) $cl['emitidos'] ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group col-lg-2 col-md-4">
        <label class="small mb-1">Producto</label>
        <select name="producto" class="form-control form-control-sm">
          <option value="">Todos</option>
          <?php foreach (['ahorro' => 'Ahorro', 'corriente' => 'Corriente',
                          'credito' => 'Crédito', 'tarjeta' => 'Tarjeta'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= ($filtros['producto'] ?? '') === $k ? 'selected' : '' ?>>
            <?= $v ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group col-lg-2 col-md-4">
        <label class="small mb-1">Año</label>
        <select name="anio" class="form-control form-control-sm">
          <option value="">Todos</option>
          <?php foreach ($anios as $an): ?>
          <option value="<?= (int) $an['a'] ?>"
                  <?= ($filtros['anio'] ?? '') === (string) (int) $an['a'] ? 'selected' : '' ?>>
            <?= (int) $an['a'] ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group col-lg-1 col-md-2">
        <button class="btn btn-outline-primary btn-sm btn-block">Filtrar</button>
      </div>

      <?php if ($hayFiltro): ?>
      <div class="form-group col-lg-1 col-md-2">
        <a href="<?= P::attr($rutas->url('estados')) ?>"
           class="btn btn-outline-secondary btn-sm btn-block">Limpiar</a>
      </div>
      <?php endif; ?>
    </form>
  </div>
  <?php endif; ?>

  <div class="card-body <?= $vistaTodos ? 'pt-0' : '' ?>">
    <?php if ($estados === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('files', 40) ?>
        <?php if ($hayFiltro): ?>
          <p class="mb-2 mt-2">Ningún estado coincide con la búsqueda.</p>
          <a href="<?= P::attr($rutas->url('estados')) ?>"
             class="btn btn-sm btn-outline-secondary">Ver todos</a>
        <?php elseif ($puedeGenerar && $cuentas !== []): ?>
          <h5 class="mt-3 mb-1" style="font-size:1rem">Todavía no hay estados generados</h5>
          <p class="text-muted mb-0" style="font-size:.88rem;max-width:32rem;margin:0 auto">
            Usa el formulario de arriba para generar el primero. Por defecto
            viene preparado para el mes pasado.
          </p>
        <?php else: ?>
          <h5 class="mt-3 mb-1" style="font-size:1rem">Aún no tiene estados de cuenta</h5>
          <p class="text-muted mb-0" style="font-size:.88rem;max-width:32rem;margin:0 auto">
            Los estados se emiten al cierre de cada periodo. En cuanto haya uno
            disponible lo verá aquí y podrá descargarlo en PDF.
          </p>
        <?php endif; ?>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0" data-cm-tabla>
        <thead>
          <tr>
            <th>Periodo</th>
            <?php if ($vistaTodos): ?><th>Cliente</th><?php endif; ?>
            <th>Cuenta</th>
            <th class="text-center">Movs.</th>
            <th class="text-right">Saldo inicial</th>
            <th class="text-right">Cargos</th>
            <th class="text-right">Abonos</th>
            <th class="text-right">Saldo final</th>
            <th>Estado</th>
            <th class="text-right">Acción</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($estados as $e): ?>
          <tr>
            <td style="white-space:nowrap">
              <strong style="font-size:.84rem">
                <?= P::e(P::fecha((string) $e['periodo_fin'], 'M Y')) ?>
              </strong>
              <small class="text-muted d-block" style="font-size:.72rem">
                <?= P::e(P::fecha((string) $e['periodo_inicio'], 'd/m')) ?>
                – <?= P::e(P::fecha((string) $e['periodo_fin'], 'd/m/Y')) ?>
              </small>
            </td>

            <?php if ($vistaTodos): ?>
            <td style="font-size:.84rem">
              <?= P::e($e['cliente'] ?? '') ?>
              <?php if (!empty($e['es_mio'])): ?>
                <span class="badge badge-light">mío</span>
              <?php endif; ?>
              <small class="text-muted d-block" style="font-size:.72rem">
                <?= P::e($e['cliente_usuario'] ?? '') ?>
              </small>
            </td>
            <?php endif; ?>

            <td>
              <span class="cm-mono" style="font-size:.8rem">
                <?= P::e($e['numero_cuenta'] ?? '—') ?>
              </span>
              <?php if (($e['producto'] ?? '') !== ''): ?>
              <small class="text-muted d-block" style="font-size:.72rem">
                <?= P::e($e['producto']) ?>
              </small>
              <?php endif; ?>
            </td>
            <td class="text-center text-muted"><?= (int) $e['cantidad_movimientos'] ?></td>
            <td class="text-right cm-mono"><?= P::e(P::money((int) $e['saldo_inicial_cent'])) ?></td>
            <td class="text-right cm-mono text-danger"><?= P::e(P::money((int) $e['total_cargos_cent'])) ?></td>
            <td class="text-right cm-mono text-success"><?= P::e(P::money((int) $e['total_abonos_cent'])) ?></td>
            <td class="text-right cm-mono font-weight-bold"><?= P::e(P::money((int) $e['saldo_final_cent'])) ?></td>

            <td>
              <?php if (!empty($e['enviado_en'])): ?>
                <span class="badge badge-success">enviado</span>
              <?php elseif (!empty($e['visto_en'])): ?>
                <span class="badge badge-info">visto</span>
              <?php else: ?>
                <span class="badge badge-secondary">generado</span>
              <?php endif; ?>
              <small class="text-muted d-block" style="font-size:.7rem">
                <?= P::e(P::fecha((string) $e['generado_en'], 'd/m/Y')) ?>
              </small>
            </td>

            <td class="text-right" style="white-space:nowrap">
              <a href="<?= P::attr($rutas->url('estados/' . $e['codigo'])) ?>"
                 class="btn btn-sm btn-outline-primary" style="font-size:.74rem;padding:.2rem .6rem">
                Ver
              </a>
              <?php if (!empty($e['ruta_pdf'])): ?>
              <a href="<?= P::attr($rutas->url('estados/' . $e['codigo'] . '/pdf')) ?>"
                 target="_blank" rel="noopener"
                 data-cm-doc data-cm-doc-titulo="Preparando el estado de cuenta"
                 class="btn btn-sm btn-primary" style="font-size:.74rem;padding:.2rem .6rem">
                PDF
              </a>
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
