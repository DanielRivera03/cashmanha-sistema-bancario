<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $creditos
 * @var array $resumen
 * @var bool $todos
 * @var string $filtro
 * @var array $porEstado
 * @var array $estados
 */
use CashMan\Http\Plantilla as P;

$COLOR = [
  'vigente' => 'success', 'aprobado' => 'info', 'cancelado' => 'secondary',
  'rechazado' => 'danger', 'castigado' => 'dark', 'solicitado' => 'warning',
];
?>

<div class="row">
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et"><?= $todos ? 'Total créditos' : 'Mis créditos' ?></div>
      <div class="cm-kpi__val"><?= number_format((int) ($resumen['total'] ?? 0)) ?></div>
    </div>
  </div>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Vigentes</div>
      <div class="cm-kpi__val text-success"><?= number_format((int) ($resumen['vigentes'] ?? 0)) ?></div>
    </div>
  </div>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et"><?= $todos ? 'Cartera' : 'Saldo pendiente' ?></div>
      <div class="cm-kpi__val"><?= P::e(P::money((int) ($resumen['cartera'] ?? 0))) ?></div>
    </div>
  </div>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Por desembolsar</div>
      <div class="cm-kpi__val text-info"><?= number_format((int) ($resumen['por_desembolsar'] ?? 0)) ?></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
    <h4 class="card-title mb-0">
      <?= $todos ? 'Cartera de créditos' : 'Mis créditos' ?>
      <?php if ($filtro !== ''): ?>
        <span class="badge badge-light ml-1"><?= P::e($filtro) ?></span>
        <a href="<?= P::attr($rutas->url('creditos')) ?>" class="small ml-1">quitar</a>
      <?php endif; ?>
    </h4>
    <a href="<?= P::attr($rutas->url('solicitudes/nueva')) ?>" class="btn btn-sm btn-primary">
      <?= cm_icono('plus', 15, 'mr-1') ?> Solicitar
    </a>
  </div>

  <?php
  /* Pestañas por estado.
     El sistema anterior dedicaba una pantalla entera a cada uno
     —activos en curso, cancelados, denegados, históricos— con el mismo
     código repetido cuatro veces. Aquí son filtros sobre la misma
     tabla, con el número de cada estado a la vista: así se sabe si hay
     algo que mirar antes de pulsar. */
  $pestanas = [
      ''             => 'Todos',
      'vigente'      => 'Vigentes',
      'aprobado'     => 'Por desembolsar',
      'solicitado'   => 'Solicitados',
      'en_revision'  => 'En revisión',
      'cancelado'    => 'Cancelados',
      'rechazado'    => 'Denegados',
      'castigado'    => 'Castigados',
  ];
  $totalTodos = array_sum($porEstado ?? []);
  ?>
  <div class="card-body pb-0 pt-2">
    <ul class="nav nav-tabs cm-pestanas" style="border-bottom:1px solid #eaecf0">
      <?php foreach ($pestanas as $clave => $etiqueta):
        $n = $clave === '' ? $totalTodos : (int) ($porEstado[$clave] ?? 0);

        // Un estado sin un solo registro no merece pestaña: sería una
        // puerta a una pantalla vacía.
        if ($clave !== '' && $n === 0 && $filtro !== $clave) { continue; }

        $activa = $filtro === $clave;
      ?>
      <li class="nav-item">
        <a class="nav-link <?= $activa ? 'active' : '' ?>"
           href="<?= P::attr($rutas->url('creditos') . ($clave === '' ? '' : '?e=' . $clave)) ?>">
          <?= P::e($etiqueta) ?>
          <span class="badge badge-<?= $activa ? 'primary' : 'light' ?> ml-1"><?= $n ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="card-body p-0">
    <?php if ($creditos === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('invest', 40) ?>
        <p class="mb-0 mt-2">No hay créditos<?= $filtro !== '' ? ' en este estado' : '' ?>.</p>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0" data-cm-tabla>
        <thead>
          <tr>
            <th>Folio</th>
            <?php if ($todos): ?><th>Cliente</th><?php endif; ?>
            <th>Producto</th><th class="cm-num">Monto</th><th class="cm-num">Saldo</th>
            <th class="cm-num">Cuota</th><th>Avance</th><th>Próxima</th><th>Estado</th>
            <?php /* La columna se dibuja solo si quien mira puede hacer
                     algo: una cuyas filas salen todas vacías es ancho
                     desperdiciado. */ ?>
            <?= P::crudo(cm_th_acciones([
                  ['Ver',      '#', 'search'],
                  ['Cobranza', '#', 'warning', 'permiso' => 'cobranza.ver'],
                  ['Estado',   '#', 'files'],
                ])) ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($creditos as $c): ?>
          <tr<?= (int) $c['vencidas'] > 0 ? ' class="table-warning"' : '' ?>>
            <td>
              <a href="<?= P::attr($rutas->url('creditos/' . $c['codigo'])) ?>" class="cm-mono font-weight-bold">
                <?= P::e($c['folio'] ?: '—') ?>
              </a>
              <?php if ((int) $c['vencidas'] > 0): ?>
                <span class="badge badge-danger ml-1"><?= (int) $c['vencidas'] ?> vencida(s)</span>
              <?php endif; ?>
            </td>
            <?php if ($todos): ?><td><?= P::e($c['cliente']) ?></td><?php endif; ?>
            <td class="text-muted"><?= P::e($c['producto']) ?></td>
            <td class="cm-num"><?= P::e(P::money((int) $c['monto_cent'])) ?></td>
            <td class="cm-num font-weight-bold"><?= P::e(P::money((int) $c['saldo_capital_cent'])) ?></td>
            <td class="cm-num"><?= P::e(P::money((int) $c['cuota_cent'])) ?></td>
            <td style="min-width:110px">
              <div class="progress" style="height:6px">
                <div class="progress-bar bg-success" style="width:<?= (int) $c['avance'] ?>%"></div>
              </div>
              <small class="text-muted"><?= (int) $c['cuotas_pagadas'] ?>/<?= (int) $c['plazo_meses'] ?></small>
            </td>
            <td class="text-muted"><?= P::e(P::fecha((string) ($c['proxima_cuota'] ?? ''), 'd/m/Y')) ?></td>
            <td>
              <span class="badge badge-<?= P::attr($COLOR[$c['estado']] ?? 'light') ?>">
                <?= P::e(ucfirst((string) $c['estado'])) ?>
              </span>
            </td>
            <td class="text-right">
              <?php /* Las acciones dependen del ESTADO, no solo del rol.
                       «Cobranza» salía en un crédito ya cancelado, que es
                       ofrecer cobrarle a quien no debe nada; y al que sí
                       quedó cancelado le faltaba lo único que necesita,
                       su finiquito. */ ?>
              <?= P::crudo(cm_acciones([
                    ['Ver el crédito', $rutas->url('creditos/' . $c['codigo']), 'search'],

                    ['Plan de pagos',
                     $rutas->url('creditos/' . $c['codigo'] . '/amortizacion-pdf'),
                     'download', 'nueva' => true,
                     'documento' => 'Preparando la tabla de amortización',
                     'titulo' => 'Descargar la tabla de amortización en PDF'],

                    ['Contrato', $rutas->url('creditos/' . $c['codigo'] . '/contrato'),
                     'files', 'metodo' => 'post', 'csrf' => $csrf ?? '',
                     'proposito' => 'credito', 'permiso' => 'contrato.generar'],

                    ['Emitir finiquito',
                     $rutas->url('creditos/' . $c['codigo'] . '/finiquito'),
                     'check', 'estilo' => 'success',
                     'metodo' => 'post', 'csrf' => $csrf ?? '',
                     'proposito' => 'credito', 'permiso' => 'contrato.generar',
                     'si' => $c['estado'] === 'cancelado',
                     'titulo' => 'El crédito está cancelado: emitir su finiquito'],

                    ['Ir a cobranza', $rutas->url('cobranza'), 'warning',
                     'estilo' => 'warning', 'permiso' => 'cobranza.ver',
                     'si' => (int) $c['vencidas'] > 0 && $c['estado'] !== 'cancelado',
                     'titulo' => 'Ver el expediente de cobro'],
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
