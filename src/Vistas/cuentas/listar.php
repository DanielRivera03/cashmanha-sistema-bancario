<?php
/**
 * Listado de cuentas.
 *
 * Una misma vista sirve a dos públicos: el cliente, que ve las suyas
 * como tarjetas de saldo, y el personal con `cuenta.ver_todas`, que ve
 * la cartera de captaciones con totales, filtros y titular.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $cuentas
 * @var array $totales
 * @var bool  $vistaTodas
 * @var array $filtros
 * @var bool  $puedeAbrir
 */
use CashMan\Http\Plantilla as P;

$badge = static fn(string $e): string => match ($e) {
    'activa'    => 'success',
    'inactiva'  => 'secondary',
    'bloqueada' => 'danger',
    'cerrada'   => 'dark',
    default     => 'light',
};

$hayFiltro = ($filtros['q'] ?? '') !== ''
          || ($filtros['estado'] ?? '') !== ''
          || ($filtros['tipo'] ?? '') !== '';
?>

<?php if ($vistaTodas): ?>
<!-- ==================== VISTA INSTITUCIONAL ==================== -->

<div class="row">
  <?php
  $kpis = [
    ['Cuentas',     number_format((float) $totales['cuentas']), 'wallet', 'primary',
     $totales['activas'] . ' activas'],
    ['Captaciones', P::money((int) $totales['saldo']),          'invest', 'success',
     'saldo total en cuentas'],
    ['Disponible',  P::money((int) $totales['disponible']),     'card',   'info',
     'libre de retenciones y mínimos'],
    ['Retenido',    P::money((int) $totales['retenido']),       'lock',   'warning',
     'bloqueado por operaciones'],
  ];
  foreach ($kpis as [$titulo, $valor, $icono, $color, $pie]): ?>
  <div class="col-xl-3 col-lg-6 col-sm-6">
    <div class="card">
      <div class="card-body d-flex align-items-center">
        <div class="cm-kpi__icono bg-<?= $color ?> text-white mr-3">
          <?= cm_icono($icono, 22) ?>
        </div>
        <div style="min-width:0">
          <h4 class="mb-0 font-weight-bold" style="font-size:1.25rem"><?= P::e($valor) ?></h4>
          <small class="text-muted d-block" style="font-size:.76rem"><?= P::e($titulo) ?></small>
          <small class="text-muted" style="font-size:.7rem"><?= P::e($pie) ?></small>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h4 class="card-title mb-0">Cartera de cuentas</h4>
    <?php if ($puedeAbrir): ?>
    <a href="<?= P::attr($rutas->url('cuentas/abrir')) ?>" class="btn btn-primary btn-sm">
      Abrir cuenta
    </a>
    <?php endif; ?>
  </div>

  <div class="card-body pb-2">
    <form method="get" class="form-row align-items-end mb-2">
      <div class="form-group col-md-5">
        <label class="small mb-1">Buscar</label>
        <input type="search" name="q" class="form-control form-control-sm"
               value="<?= P::attr($filtros['q'] ?? '') ?>"
               placeholder="Número de cuenta, titular o usuario">
      </div>
      <div class="form-group col-md-3">
        <label class="small mb-1">Estado</label>
        <select name="estado" class="form-control form-control-sm">
          <option value="">Todos</option>
          <?php foreach (['activa', 'inactiva', 'bloqueada', 'cerrada'] as $e): ?>
          <option value="<?= $e ?>" <?= ($filtros['estado'] ?? '') === $e ? 'selected' : '' ?>>
            <?= ucfirst($e) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group col-md-2">
        <label class="small mb-1">Tipo</label>
        <select name="tipo" class="form-control form-control-sm">
          <option value="">Todos</option>
          <option value="ahorro"    <?= ($filtros['tipo'] ?? '') === 'ahorro'    ? 'selected' : '' ?>>Ahorro</option>
          <option value="corriente" <?= ($filtros['tipo'] ?? '') === 'corriente' ? 'selected' : '' ?>>Corriente</option>
        </select>
      </div>
      <div class="form-group col-md-2">
        <button class="btn btn-outline-primary btn-sm btn-block">Filtrar</button>
      </div>
    </form>
  </div>

  <div class="card-body pt-0">
    <?php if ($cuentas === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('wallet', 40) ?>
        <p class="mb-1 mt-2">
          <?= $hayFiltro
              ? 'Ninguna cuenta coincide con el filtro.'
              : 'Todavía no hay cuentas abiertas.' ?>
        </p>
        <?php if ($hayFiltro): ?>
          <a href="<?= P::attr($rutas->url('cuentas')) ?>" class="btn btn-sm btn-outline-secondary mt-2">
            Quitar filtros
          </a>
        <?php elseif ($puedeAbrir): ?>
          <a href="<?= P::attr($rutas->url('cuentas/abrir')) ?>" class="btn btn-sm btn-primary mt-2">
            Abrir la primera cuenta
          </a>
        <?php endif; ?>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0" data-cm-tabla>
        <thead>
          <tr>
            <th>Cuenta</th>
            <th>Titular</th>
            <th>Producto</th>
            <th class="text-right">Saldo</th>
            <th class="text-right">Disponible</th>
            <th>Estado</th>
            <th class="text-right">Apertura</th>
            <?php /* Rotulada, no una celda en blanco: quien mira una
                     tabla tiene que saber qué hay en cada columna. */ ?>
            <th class="text-right" style="width:1%;white-space:nowrap">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cuentas as $c): ?>
          <tr>
            <td class="cm-mono font-weight-bold"><?= P::e($c['numero_cuenta']) ?></td>
            <td>
              <?= P::e($c['titular']) ?>
              <small class="text-muted d-block" style="font-size:.72rem">
                <?= P::e($c['titular_usuario']) ?>
                <?php if (!empty($c['es_mia'])): ?>
                  <span class="badge badge-light">mía</span>
                <?php endif; ?>
              </small>
            </td>
            <td>
              <?= P::e($c['producto']) ?>
              <small class="text-muted d-block" style="font-size:.72rem"><?= P::e($c['tipo']) ?></small>
            </td>
            <td class="text-right cm-mono"><?= P::e(P::money((int) $c['saldo_cent'])) ?></td>
            <td class="text-right cm-mono <?= (int) $c['disponible_cent'] === 0 ? 'text-muted' : '' ?>">
              <?= P::e(P::money((int) $c['disponible_cent'])) ?>
              <?php if ((int) $c['saldo_retenido_cent'] > 0): ?>
                <small class="d-block text-warning" style="font-size:.68rem">
                  <?= P::e(P::money((int) $c['saldo_retenido_cent'])) ?> retenido
                </small>
              <?php endif; ?>
            </td>
            <td><span class="badge badge-<?= $badge((string) $c['estado']) ?>"><?= P::e($c['estado']) ?></span></td>
            <td class="text-right text-muted" style="font-size:.78rem">
              <?= P::e(P::fecha((string) $c['apertura_en'], 'd/m/Y')) ?>
            </td>
            <td class="text-right">
              <?= P::crudo(cm_acciones([
                    ['Ver', $rutas->url('cuentas/' . $c['codigo']), 'search',
                     'estilo' => 'primary'],
                    ['Movimientos', $rutas->url('cuentas/' . $c['codigo'] . '/movimientos'),
                     'swap', 'titulo' => 'Ver los movimientos de esta cuenta'],
                  ])) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (count($cuentas) >= 300): ?>
    <p class="text-muted mt-3 mb-0" style="font-size:.78rem">
      Se muestran las primeras 300 cuentas. Afina la búsqueda para ver el resto.
    </p>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<!-- ==================== VISTA DEL CLIENTE ==================== -->

<?php if ($cuentas === []): ?>
<div class="card">
  <div class="card-body">
    <div class="cm-vacio">
      <?= cm_icono('wallet', 40) ?>
      <h5 class="mt-3 mb-1" style="font-size:1rem">Todavía no tiene cuentas</h5>

      <?php /* El botón mandaba a abrir un ticket de soporte.
               Desde que existe la Cuenta Digital eso es dar un rodeo
               por donde no hay que ir: la cuenta se abre aquí mismo,
               en un par de minutos y sin depósito. La agencia sigue
               siendo el camino para la cuenta de ahorro tradicional,
               y por eso se menciona, pero de segundo. */ ?>
      <p class="text-muted mb-3" style="font-size:.88rem;max-width:32rem;margin:0 auto">
        Abra su <strong>Cuenta Digital</strong> aquí mismo: sin monto de apertura,
        sin ir a una agencia y con tarjeta de débito virtual desde el primer día.
      </p>

      <a href="<?= P::attr($rutas->url('abrir-cuenta')) ?>" class="btn btn-primary">
        Abrir mi Cuenta Digital
      </a>

      <p class="text-muted mt-3 mb-0" style="font-size:.8rem">
        ¿Prefiere una cuenta de ahorro o corriente tradicional?
        <a href="<?= P::attr($rutas->url('tickets/nuevo')) ?>">Escríbanos</a>
        o acérquese a cualquier agencia.
      </p>
    </div>
  </div>
</div>

<?php else: ?>

<div class="row">
  <div class="col-xl-4 col-lg-6 col-sm-6">
    <div class="card bg-primary text-white">
      <div class="card-body">
        <small class="d-block" style="opacity:.85;font-size:.76rem">Saldo total</small>
        <h3 class="text-white mb-0 font-weight-bold"><?= P::e(P::money((int) $totales['saldo'])) ?></h3>
        <small style="opacity:.85;font-size:.74rem">
          <?= P::e(P::money((int) $totales['disponible'])) ?> disponible en
          <?= (int) $totales['cuentas'] ?> cuenta(s)
        </small>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <?php foreach ($cuentas as $c): ?>
  <div class="col-xl-4 col-lg-6">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div style="min-width:0">
            <h5 class="mb-1" style="font-size:.95rem"><?= P::e($c['producto']) ?></h5>
            <span class="cm-mono text-muted" style="font-size:.84rem">
              <?= P::e($c['numero_cuenta']) ?>
            </span>
          </div>
          <span class="badge badge-<?= $badge((string) $c['estado']) ?>"><?= P::e($c['estado']) ?></span>
        </div>

        <div class="mb-1">
          <small class="text-muted d-block" style="font-size:.74rem">Saldo</small>
          <h3 class="mb-0 font-weight-bold" style="font-size:1.55rem">
            <?= P::e(P::money((int) $c['saldo_cent'])) ?>
          </h3>
        </div>

        <!-- Disponible no es lo mismo que saldo, y la diferencia hay que
             explicarla: ver $1,925.00 de saldo y $1,924.00 disponible sin
             decir por qué solo genera desconfianza. -->
        <div class="d-flex justify-content-between border-top pt-2 mt-3" style="font-size:.78rem">
          <span class="text-muted">Disponible</span>
          <span class="cm-mono font-weight-bold"
                data-vivo="disponible" data-cuenta="<?= P::attr($c['codigo']) ?>">
            <?= P::e(P::money((int) $c['disponible_cent'])) ?>
          </span>
        </div>
        <?php if ((int) ($c['saldo_minimo_cent'] ?? 0) > 0): ?>
        <div class="d-flex justify-content-between" style="font-size:.74rem">
          <span class="text-muted">Saldo mínimo que se conserva</span>
          <span class="cm-mono text-muted">
            <?= P::e(P::money((int) $c['saldo_minimo_cent'])) ?>
          </span>
        </div>
        <?php endif; ?>
        <?php if ((int) $c['saldo_retenido_cent'] > 0): ?>
        <div class="d-flex justify-content-between" style="font-size:.78rem">
          <span class="text-muted">Retenido</span>
          <span class="cm-mono text-warning">
            <?= P::e(P::money((int) $c['saldo_retenido_cent'])) ?>
          </span>
        </div>
        <?php endif; ?>
      </div>

      <div class="card-body pt-0 d-flex" style="gap:.4rem">
        <a href="<?= P::attr($rutas->url('cuentas/' . $c['codigo'])) ?>"
           class="btn btn-sm btn-outline-primary flex-fill">Movimientos</a>
        <?php if ($c['estado'] === 'activa'): ?>
        <a href="<?= P::attr($rutas->url('transferir')) ?>"
           class="btn btn-sm btn-primary flex-fill">Transferir</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>
<?php endif; ?>
