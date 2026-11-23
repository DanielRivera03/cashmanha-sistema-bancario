<?php
/**
 * Cuotas por cobrar.
 *
 * La tabla llevaba once columnas —fecha, folio, cliente, número y cinco
 * de dinero— y en pantalla normal no caben: los importes se partían a
 * media cifra ("$16,388." / "89") y el botón de acción se rompía en dos
 * líneas. Capital, interés y mora van ahora apilados en una sola celda:
 * es la misma información, se lee mejor que en columnas de 60 px y deja
 * sitio para que los totales no se corten.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $cuotas
 * @var array  $totales
 * @var array  $conteos
 * @var bool   $vistaTodas
 * @var string $vista
 * @var array  $filtros
 * @var array  $paginacion
 * @var string $csrf
 */
use CashMan\Http\Paginador;
use CashMan\Http\Plantilla as P;

$hayFiltro = ($filtros['q'] ?? '') !== '' || ($filtros['desde'] ?? '') !== ''
          || ($filtros['hasta'] ?? '') !== '';

$pestanas = [
    'pendientes' => ['Pendientes',      'pendientes'],
    'vencidas'   => ['Vencidas',        'vencidas'],
    'semana'     => ['Vencen en 7 días', 'semana'],
    'mes'        => ['Vencen este mes',  'mes'],
    'pagadas'    => ['Pagadas',          'pagadas'],
    'todas'      => ['Todas',            'todas'],
];

$badge = static fn(string $e, bool $vencida): string => match (true) {
    $e === 'pagada'  => 'success',
    $e === 'parcial' => 'info',
    $vencida         => 'danger',
    default          => 'light',
};

/**
 * Enlaces que conservan el estado.
 *
 * Al paginar hay que arrastrar la pestaña y los filtros: sin esto, pasar
 * a la página 2 de "vencidas" devolvía la 2 de "pendientes" sin filtrar.
 */
$conEstado = static function (array $extra) use ($rutas, $vista, $filtros, $paginacion): string {
    $base = [
        'v'     => $vista,
        'q'     => (string) ($filtros['q'] ?? ''),
        'desde' => (string) ($filtros['desde'] ?? ''),
        'hasta' => (string) ($filtros['hasta'] ?? ''),
        'pp'    => (int) $paginacion['porPagina'] === Paginador::POR_DEFECTO
                   ? '' : (string) $paginacion['porPagina'],
    ];

    $todo = array_filter(array_merge($base, $extra), static fn($v) => $v !== '' && $v !== null);

    return $rutas->url('cuotas') . '?' . http_build_query($todo);
};

$enlace       = static fn(int $p): string => $conEstado(['p' => $p]);
$enlaceTamano = static fn(int $t): string => $conEstado(['pp' => $t, 'p' => 1]);
?>

<div class="row">
  <?php
  $kpis = [
    ['Cuotas', number_format((float) $totales['cuotas']),    'files',   'primary',
     $hayFiltro || $vista !== 'todas' ? 'en este filtro' : 'en la cartera'],
    ['Por cobrar',   P::money((int) $totales['por_cobrar']), 'invest',  'warning', 'capital + interés pendiente'],
    ['Mora acumulada', P::money((int) $totales['mora']),     'warning', 'danger',
     number_format((float) $totales['vencidas']) . ' cuota(s) vencida(s)'],
    ['Cobrado',      P::money((int) $totales['cobrado']),    'check',   'success', 'cuotas ya saldadas'],
  ];
  foreach ($kpis as [$t, $v, $i, $c, $pie]): ?>
  <div class="col-xl-3 col-lg-6 col-sm-6">
    <div class="card">
      <div class="card-body d-flex align-items-center">
        <div class="cm-kpi__icono bg-<?= $c ?> text-white mr-3"><?= cm_icono($i, 22) ?></div>
        <div style="min-width:0">
          <h4 class="mb-0 font-weight-bold" style="font-size:1.25rem"><?= P::e($v) ?></h4>
          <small class="text-muted d-block" style="font-size:.76rem"><?= P::e($t) ?></small>
          <?php if ($pie !== ''): ?>
          <small class="text-muted" style="font-size:.7rem"><?= P::e($pie) ?></small>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">
      <?= $vistaTodas ? 'Cuotas de la cartera' : 'Mis cuotas' ?>
    </h4>
  </div>

  <div class="card-body pb-0 pt-2">
    <ul class="nav nav-tabs cm-pestanas" style="border-bottom:1px solid #eaecf0">
      <?php foreach ($pestanas as $clave => [$etiqueta, $campo]):
        $n = (int) ($conteos[$campo] ?? 0);
        if ($n === 0 && $vista !== $clave) { continue; }
        $activa = $vista === $clave;
      ?>
      <li class="nav-item">
        <a class="nav-link <?= $activa ? 'active' : '' ?>"
           href="<?= P::attr($rutas->url('cuotas') . '?v=' . $clave) ?>">
          <?= P::e($etiqueta) ?>
          <span class="badge badge-<?= $activa ? 'primary' : 'light' ?> ml-1"><?= $n ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="card-body pb-2">
    <form method="get" class="form-row align-items-end mb-0">
      <input type="hidden" name="v" value="<?= P::attr($vista) ?>">
      <?php if ((int) $paginacion['porPagina'] !== Paginador::POR_DEFECTO): ?>
      <input type="hidden" name="pp" value="<?= (int) $paginacion['porPagina'] ?>">
      <?php endif; ?>

      <?php if ($vistaTodas): ?>
      <div class="form-group col-lg-4 col-md-6">
        <label class="small mb-1">Buscar</label>
        <input type="search" name="q" class="form-control form-control-sm"
               value="<?= P::attr($filtros['q'] ?? '') ?>"
               placeholder="Folio del crédito, cliente o usuario">
      </div>
      <?php endif; ?>

      <div class="form-group col-lg-2 col-md-3">
        <label class="small mb-1">Vence desde</label>
        <input type="date" name="desde" class="form-control form-control-sm"
               value="<?= P::attr($filtros['desde'] ?? '') ?>">
      </div>

      <div class="form-group col-lg-2 col-md-3">
        <label class="small mb-1">Vence hasta</label>
        <input type="date" name="hasta" class="form-control form-control-sm"
               value="<?= P::attr($filtros['hasta'] ?? '') ?>">
      </div>

      <div class="form-group col-lg-2 col-md-3">
        <button class="btn btn-outline-primary btn-sm btn-block">Filtrar</button>
      </div>

      <?php if ($hayFiltro): ?>
      <div class="form-group col-lg-2 col-md-3">
        <a href="<?= P::attr($rutas->url('cuotas') . '?v=' . $vista) ?>"
           class="btn btn-outline-secondary btn-sm btn-block">Limpiar</a>
      </div>
      <?php endif; ?>
    </form>
  </div>

  <div class="card-body pt-0">
    <?php if ($cuotas === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('check', 40) ?>
        <p class="mb-1 mt-2">
          <?= match ($vista) {
              'vencidas'   => 'No hay cuotas vencidas. La cartera está al día.',
              'semana'     => 'No vence ninguna cuota en los próximos 7 días.',
              'mes'        => 'No queda ninguna cuota por vencer este mes.',
              'pagadas'    => 'Todavía no se ha pagado ninguna cuota.',
              default      => $hayFiltro
                              ? 'Ninguna cuota coincide con el filtro.'
                              : 'No hay cuotas pendientes.',
          } ?>
        </p>
        <?php if ($hayFiltro): ?>
        <a href="<?= P::attr($rutas->url('cuotas') . '?v=' . $vista) ?>"
           class="btn btn-sm btn-outline-secondary mt-2">Quitar filtros</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Vence</th>
            <th>Crédito</th>
            <?php if ($vistaTodas): ?><th>Cliente</th><?php endif; ?>
            <th class="text-center">Cuota</th>
            <th class="text-right">Desglose</th>
            <?php /* «Cuota» ya es el numero (10/180) en la columna de la
                     izquierda. Esta es el importe, y llamarlas igual
                     obligaba a mirar dos veces cual era cual. */ ?>
            <th class="text-right">Importe</th>
            <th class="text-right">Mora</th>
            <th class="text-right">Total a pagar</th>
            <th>Estado</th>
            <th class="cm-acciones">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cuotas as $q): ?>
          <tr<?= $q['vencida'] ? ' class="table-warning"' : '' ?>>
            <td class="cm-nowrap">
              <span style="font-size:.82rem">
                <?= P::e(P::fecha((string) $q['fecha_vencimiento'], 'd/m/Y')) ?>
              </span>
              <?php if ($q['vencida']): ?>
              <small class="text-danger d-block font-weight-bold" style="font-size:.7rem">
                <?= (int) $q['dias_vencida'] ?> día(s) de atraso
              </small>
              <?php elseif ($q['estado'] === 'pagada' && !empty($q['pagada_en'])): ?>
              <small class="text-success d-block" style="font-size:.7rem">
                pagada <?= P::e(P::fecha((string) $q['pagada_en'], 'd/m/Y')) ?>
              </small>
              <?php endif; ?>
            </td>

            <td>
              <a href="<?= P::attr($rutas->url('creditos/' . $q['credito_codigo'])) ?>"
                 class="cm-mono font-weight-bold" style="font-size:.8rem">
                <?= P::e($q['folio']) ?>
              </a>
              <small class="text-muted d-block" style="font-size:.72rem">
                <?= P::e($q['producto']) ?>
              </small>
            </td>

            <?php if ($vistaTodas): ?>
            <td style="font-size:.84rem">
              <?= P::e($q['cliente']) ?>
              <small class="text-muted d-block" style="font-size:.72rem">
                <?= P::e($q['cliente_usuario']) ?>
              </small>
            </td>
            <?php endif; ?>

            <td class="text-center cm-nowrap">
              <span class="badge badge-light">
                <?= (int) $q['numero'] ?>/<?= (int) $q['plazo_meses'] ?>
              </span>
            </td>

            <td class="text-right">
              <?php /* La mora ya NO va aquí. Estaba listada junto a
                       capital e interés, como si las tres sumaran el
                       total, y el total solo sumaba dos: quien sumara
                       las tres con una calculadora encontraba otra
                       cifra. La mora tiene su propia columna, porque
                       no es parte de la cuota sino un recargo encima. */ ?>
              <div class="cm-desglose">
                <span><i>Capital</i><b class="cm-mono"><?= P::e(P::money((int) $q['capital_cent'])) ?></b></span>
                <span><i>Interés</i><b class="cm-mono"><?= P::e(P::money((int) $q['interes_cent'])) ?></b></span>
                <?php if ((int) $q['seguro_vida_cent'] + (int) $q['seguro_empleo_cent'] > 0): ?>
                <span><i>Seguros</i><b class="cm-mono"><?= P::e(P::money(
                    (int) $q['seguro_vida_cent'] + (int) $q['seguro_empleo_cent']
                )) ?></b></span>
                <?php endif; ?>
              </div>
            </td>

            <?php /* La cuota: capital + interés + seguros. Es lo que
                     suma el desglose de al lado, ni más ni menos. */ ?>
            <td class="text-right cm-mono cm-nowrap">
              <?= P::e(P::money((int) $q['total_cent'])) ?>
            </td>

            <td class="text-right cm-mono cm-nowrap">
              <?php if ((int) $q['mora_cent'] > 0): ?>
                <span class="text-danger"><?= P::e(P::money((int) $q['mora_cent'])) ?></span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>

            <?php /* Lo que de verdad hay que pagar hoy: lo que queda de
                     la cuota más la mora acumulada. Es la cifra por la
                     que pregunta el cliente y la que se cobra.

                     Y debajo, lo que ya entró. Antes la fila decía
                     total $20,408.05 y pendiente $12,017.73 sin decir
                     que entre medias habían entrado $8,390.32: el
                     cliente veía que debía menos sin saber por qué. */ ?>
            <td class="text-right cm-mono cm-nowrap font-weight-bold">
              <?php
                $aPagar  = (int) $q['pendiente_cent'] + (int) $q['mora_cent'];
                $abonado = max(0, (int) $q['total_cent'] - (int) $q['pendiente_cent']);
                $compen  = (int) ($q['compensado_cent'] ?? 0);
              ?>
              <?= $aPagar > 0
                  ? P::e(P::money($aPagar))
                  : '<span class="text-success font-weight-normal">saldada</span>' ?>

              <?php if ($abonado > 0 && $aPagar > 0): ?>
              <small class="cm-abonado">
                <?php if ($compen > 0): ?>
                  <?= P::e(P::money($compen)) ?> por compensación
                  <?php if (($q['compensado_origen'] ?? '') === 'barrido'): ?>
                    <i>(barrido automático)</i>
                  <?php elseif (($q['compensado_origen'] ?? '') === 'abono'): ?>
                    <i>(al entrar saldo)</i>
                  <?php elseif (($q['compensado_origen'] ?? '') === 'manual'): ?>
                    <i>(aplicada en agencia)</i>
                  <?php endif; ?>
                  <?php if ($compen < $abonado): ?>
                    <br>y <?= P::e(P::money($abonado - $compen)) ?> de otros abonos
                  <?php endif; ?>
                <?php else: ?>
                  abonado <?= P::e(P::money($abonado)) ?>
                <?php endif; ?>
              </small>
              <?php endif; ?>
            </td>

            <td class="cm-nowrap">
              <span class="badge badge-<?= $badge((string) $q['estado'], (bool) $q['vencida']) ?>">
                <?= $q['vencida'] && $q['estado'] !== 'pagada' ? 'vencida' : P::e($q['estado']) ?>
              </span>
            </td>

            <td class="text-right">
              <?php /* Sin `title`: el botón ya dice lo que hace, y el
                       recuadro del navegador tapaba la fila siguiente
                       cada vez que el puntero pasaba por encima. */ ?>
              <?= P::crudo(cm_acciones([
                    ['Ver crédito',
                     $rutas->url('creditos/' . $q['credito_codigo']),
                     'search', 'estilo' => 'primary'],
                    ['Cobranza',
                     $rutas->url('cobranza'),
                     'warning', 'estilo' => 'warning',
                     'permiso' => 'cobranza.ver',
                     'si' => (bool) $q['vencida'] && $q['estado'] !== 'pagada'],
                  ])) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php
    $pag = $paginacion;
    include __DIR__ . '/../layout/paginacion.php';
    ?>
    <?php endif; ?>
  </div>
</div>
