<?php
/**
 * Expedientes de cobro.
 *
 * Ordenados por días de mora: lo primero que se ve es lo que más urge.
 * Las promesas rotas salen marcadas porque son la señal más clara de
 * que ya no sirve seguir llamando.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $casos
 * @var array  $conteos
 * @var array  $totales
 * @var string $etapa
 * @var bool   $puedeGestionar
 * @var bool   $puedeEscalar
 * @var bool   $puedeJuridico
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$pestanas = [
    ''               => ['Abiertos',      'abiertos'],
    'administrativa' => ['Administrativa','administrativa'],
    'extrajudicial'  => ['Extrajudicial', 'extrajudicial'],
    'judicial'       => ['Judicial',      'judicial'],
    'sentencia'      => ['Sentencia',     'sentencia'],
    'cerrados'       => ['Cerrados',      'cerrados'],
];

$colorEtapa = static fn(string $e): string => match ($e) {
    'preventiva'     => 'light',
    'administrativa' => 'info',
    'extrajudicial'  => 'warning',
    'judicial'       => 'danger',
    'sentencia'      => 'dark',
    'recuperado'     => 'success',
    'castigado'      => 'secondary',
    default          => 'light',
};
?>

<div class="row">
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Expedientes abiertos</div>
      <div class="cm-kpi__val"><?= number_format((int) ($conteos["abiertos"] ?? 0)) ?></div>
      <div class="cm-kpi__pie"><?= (int) ($conteos["cerrados"] ?? 0) ?> cerrados</div>
    </div>
  </div>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Saldo vencido en gestion</div>
      <div class="cm-kpi__val cm-neg"><?= P::e(P::money((int) ($totales["deuda_cent"] ?? 0))) ?></div>
      <div class="cm-kpi__pie">mas <?= P::e(P::money((int) ($totales["mora_cent"] ?? 0))) ?> de mora</div>
    </div>
  </div>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Peor atraso</div>
      <div class="cm-kpi__val"><?= number_format((int) ($totales["peor"] ?? 0)) ?></div>
      <div class="cm-kpi__pie">dias del caso mas antiguo</div>
    </div>
  </div>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">En sede judicial</div>
      <div class="cm-kpi__val"><?= (int) ($conteos["judicial"] ?? 0) + (int) ($conteos["sentencia"] ?? 0) ?></div>
      <div class="cm-kpi__pie"><?= (int) ($conteos["extrajudicial"] ?? 0) ?> en extrajudicial</div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body pb-0 pt-2">
    <ul class="nav nav-tabs cm-pestanas" style="border-bottom:1px solid #eaecf0">
      <?php foreach ($pestanas as $clave => [$etiqueta, $campo]):
        $n = (int) ($conteos[$campo] ?? 0);
        $activa = (string) $etapa === (string) $clave;
      ?>
      <li class="nav-item">
        <a class="nav-link <?= $activa ? 'active' : '' ?>"
           href="<?= P::attr($rutas->url('cobranza') . ($clave !== '' ? '?etapa=' . $clave : '')) ?>">
          <?= P::e($etiqueta) ?>
          <span class="badge badge-<?= $activa ? 'primary' : 'light' ?> ml-1"><?= $n ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="card-body pt-3 p-0">
    <?php if ($casos === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('check', 40) ?>
        <p class="mb-0 mt-2">No hay expedientes en esta etapa. Buena noticia.</p>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0" data-cm-tabla>
        <thead>
          <tr>
            <th>Expediente</th>
            <th>Cliente</th>
            <th>Crédito</th>
            <th>Etapa</th>
            <th class="cm-num">Días</th>
            <th class="cm-num">Saldo vencido</th>
            <th class="cm-num">Mora</th>
            <th>Gestiones</th>
            <th class="cm-acciones" style="width:1%;white-space:nowrap">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($casos as $k): ?>
          <tr>
            <td class="cm-mono cm-nowrap" style="font-size:.78rem">
              <a href="<?= P::attr($rutas->url('cobranza/' . $k['codigo'])) ?>">
                <?= P::e($k['folio']) ?>
              </a>
              <small class="text-muted d-block" style="font-size:.7rem">
                desde <?= P::e(P::fecha((string) $k['abierto_en'], 'd/m/Y')) ?>
              </small>
            </td>
            <td style="font-size:.84rem">
              <?= P::e($k['cliente']) ?>
              <small class="text-muted d-block" style="font-size:.72rem">
                <?= P::e($k['telefono'] ?: $k['usuario']) ?>
              </small>
            </td>
            <td style="font-size:.82rem">
              <?= P::e($k['credito_folio']) ?>
              <small class="text-muted d-block" style="font-size:.72rem">
                <?= P::e($k['producto']) ?>
              </small>
            </td>
            <td class="cm-nowrap">
              <span class="badge badge-<?= $colorEtapa((string) $k['etapa']) ?>">
                <?= P::e(str_replace('_', ' ', (string) $k['etapa'])) ?>
              </span>
              <?php if (!empty($k['referencia_judicial'])): ?>
              <small class="text-muted d-block cm-mono" style="font-size:.68rem">
                <?= P::e($k['referencia_judicial']) ?>
              </small>
              <?php endif; ?>
            </td>
            <td class="cm-num font-weight-bold <?= (int) $k['dias_mora'] > 120 ? 'text-danger' : '' ?>">
              <?= (int) $k['dias_mora'] ?>
              <small class="text-muted d-block" style="font-size:.68rem">
                <?= (int) $k['cuotas_vencidas'] ?> cuota(s)
              </small>
            </td>
            <td class="cm-num cm-mono text-danger font-weight-bold">
              <?= P::e(P::money((int) $k['deuda_cent'])) ?>
            </td>
            <td class="cm-num cm-mono text-muted">
              <?= P::e(P::money((int) $k['mora_cent'])) ?>
            </td>
            <td style="font-size:.78rem">
              <?= (int) $k['gestiones'] ?>
              <?php if ((int) $k['promesas_rotas'] > 0): ?>
              <!-- Una promesa rota dice más que diez llamadas. -->
              <small class="d-block text-danger" style="font-size:.7rem">
                <?= (int) $k['promesas_rotas'] ?> promesa(s) incumplida(s)
              </small>
              <?php endif; ?>
              <?php if ($k['ultima_gestion'] !== null): ?>
              <small class="d-block text-muted" style="font-size:.68rem">
                últ. <?= P::e(P::fecha((string) $k['ultima_gestion'], 'd/m H:i')) ?>
              </small>
              <?php endif; ?>
            </td>
            <td class="cm-acciones">
              <?= P::crudo(cm_acciones([
                    ['Ver expediente', $rutas->url('cobranza/' . $k['codigo']), 'search',
                     'titulo' => 'Gestiones, promesas y estado del caso'],

                    ['Ver el crédito', $rutas->url('creditos/' . $k['credito_codigo']),
                     'invest', 'si' => !empty($k['credito_codigo']),
                     'titulo' => 'Ir al crédito de este expediente'],
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

<div class="alert alert-light border" style="font-size:.84rem">
  <strong>Cómo avanza un caso.</strong> Los expedientes se abren y cambian de etapa
  solos según los días de mora: <em>administrativa</em> desde el primer día,
  <em>extrajudicial</em> a los 61 —ahí pasa al Departamento Jurídico— y
  <em>judicial</em> a los 121. Los correos de cobro se mandan por etapa, dejando
  días de separación entre uno y otro, y nunca a quien ya se puso al día: la deuda
  se vuelve a comprobar contra las cuotas reales antes de cada envío.
</div>
