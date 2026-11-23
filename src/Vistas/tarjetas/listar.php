<?php
/**
 * Tarjetas.
 *
 * El personal con `tarjeta.ver_todas` ve el parque emitido con sus
 * totales y filtros; el cliente ve las suyas como plásticos.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $tarjetas
 * @var array  $totales
 * @var bool   $vistaTodas
 * @var bool   $puedeVerTodas
 * @var array  $filtros
 * @var bool   $puedeEmitir
 * @var array  $clientes
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$hayFiltro = ($filtros['q'] ?? '') !== '' || ($filtros['marca'] ?? '') !== ''
          || ($filtros['tipo'] ?? '') !== '' || ($filtros['estado'] ?? '') !== '';

$badge = static fn(string $e): string => match ($e) {
    'activa'               => 'success',
    'pendiente_activacion' => 'warning',
    'bloqueada'            => 'danger',
    'vencida'              => 'secondary',
    default                => 'light',
};

/**
 * El ciclo de una tarjeta: corte, fecha límite y días que faltan.
 *
 * La fecha límite se calcula —corte más los días de gracia del
 * producto— en lugar de guardarse, para que no pueda quedar vieja
 * cuando el corte avance. Devuelve [corte, limite, dias] con cadenas
 * vacías si la tarjeta todavía no tiene corte asignado.
 *
 * @return array{0:string,1:string,2:int|null}
 */
$cicloTarjeta = static function (array $t): array {
    $corte = (string) ($t['proximo_corte'] ?? '');

    if ($corte === '') {
        return ['', '', null];
    }

    try {
        $lim = (new DateTimeImmutable($corte))
            ->modify('+' . max(1, (int) ($t['dias_para_pago'] ?? 20)) . ' days');
    } catch (Throwable) {
        return [$corte, '', null];
    }

    return [
        $corte,
        $lim->format('Y-m-d'),
        (int) (new DateTimeImmutable('today'))->diff($lim)->format('%r%a'),
    ];
};
?>

<?php if ($vistaTodas): ?>
<!-- ==================== TOTALES ==================== -->
<div class="row">
  <?php
  $kpis = [
    ['Tarjetas emitidas', number_format((float) $totales['tarjetas']), 'card', 'primary',
     $totales['activas'] . ' activas'],
    ['Línea otorgada',    P::money((int) $totales['limite']),         'invest', 'info',
     $totales['credito'] . ' de crédito · ' . $totales['debito'] . ' de débito'],
    ['Saldo utilizado',   P::money((int) $totales['usado']),          'swap', 'warning',
     $totales['limite'] > 0
        ? round($totales['usado'] / $totales['limite'] * 100, 1) . '% de la línea'
        : 'sin consumo'],
    ['Disponible',        P::money((int) $totales['disponible']),     'check', 'success',
     'crédito sin usar'],
  ];
  foreach ($kpis as [$titulo, $valor, $icono, $color, $pie]): ?>
  <div class="col-xl-3 col-lg-6 col-sm-6">
    <div class="card">
      <div class="card-body d-flex align-items-center">
        <div class="cm-kpi__icono bg-<?= $color ?> text-white mr-3"><?= cm_icono($icono, 22) ?></div>
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
<?php endif; ?>

<?php if ($puedeEmitir): ?>
<!-- ==================== EMISIÓN ==================== -->
<div class="card">
  <div class="card-header"><h4 class="card-title mb-0">Emitir tarjeta</h4></div>
  <div class="card-body">
    <form id="form-emitir" data-cm-ajax data-cm-recargar
          action="<?= P::attr($rutas->url('tarjetas/emitir')) ?>" method="post">
      <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
      <input type="hidden" name="_csrf_proposito" value="tarjeta">

      <div class="form-row align-items-end">
        <div class="form-group col-lg-4 col-md-6">
          <label for="cliente">Titular</label>
          <select class="form-control" id="cliente" name="cliente">
            <option value="">— Para mí —</option>
            <?php foreach ($clientes as $c): ?>
            <option value="<?= P::attr(P::hid((int) $c['id'], 'usuario')) ?>">
              <?= P::e($c['nombre']) ?> — <?= P::e($c['usuario']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group col-lg-2 col-md-3">
          <label for="marca">Marca</label>
          <select class="form-control" id="marca" name="marca">
            <option value="visa">Visa</option>
            <option value="mastercard">Mastercard</option>
            <option value="amex">American Express</option>
          </select>
        </div>

        <div class="form-group col-lg-2 col-md-3">
          <label for="tipo">Tipo</label>
          <select class="form-control" id="tipo" name="tipo">
            <option value="credito">Crédito</option>
            <option value="debito">Débito</option>
          </select>
        </div>

        <div class="form-group col-lg-2 col-md-6" id="caja-limite">
          <label for="limite">Límite (USD)</label>
          <input type="text" class="form-control" id="limite" name="limite"
                 value="1000.00" inputmode="decimal">
        </div>

        <div class="form-group col-lg-2 col-md-6">
          <button type="submit" class="btn btn-primary btn-block">Emitir</button>
        </div>
      </div>
    </form>

    <small class="text-muted d-block" style="font-size:.78rem">
      El número se genera con el algoritmo de Luhn. Tanto el número como el CVV
      se guardan cifrados y solo el titular puede verlos.
    </small>
  </div>
</div>
<?php endif; ?>

<?php if ($vistaTodas || $puedeVerTodas): ?>
<!-- ==================== FILTROS ==================== -->
<div class="card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h4 class="card-title mb-0">
      <?= $vistaTodas ? 'Parque de tarjetas' : 'Mis tarjetas' ?>
      <span class="badge badge-light ml-1"><?= (int) $totales['tarjetas'] ?></span>
    </h4>
    <?php if ($puedeVerTodas): ?>
    <div class="btn-group btn-group-sm">
      <a href="<?= P::attr($rutas->url('tarjetas')) ?>"
         class="btn btn-<?= $vistaTodas ? 'primary' : 'outline-primary' ?>">Todas</a>
      <a href="<?= P::attr($rutas->url('tarjetas?mias=1')) ?>"
         class="btn btn-<?= $vistaTodas ? 'outline-primary' : 'primary' ?>">Solo mías</a>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($vistaTodas): ?>
  <div class="card-body pb-2">
    <form method="get" class="form-row align-items-end mb-0">
      <div class="form-group col-lg-4 col-md-6">
        <label class="small mb-1">Buscar</label>
        <input type="search" name="q" class="form-control form-control-sm"
               value="<?= P::attr($filtros['q'] ?? '') ?>"
               placeholder="Últimos 4 dígitos, titular o usuario">
      </div>
      <div class="form-group col-lg-2 col-md-3">
        <label class="small mb-1">Marca</label>
        <select name="marca" class="form-control form-control-sm">
          <option value="">Todas</option>
          <?php foreach (['visa' => 'Visa', 'mastercard' => 'Mastercard',
                       'amex' => 'American Express'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= ($filtros['marca'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group col-lg-2 col-md-3">
        <label class="small mb-1">Tipo</label>
        <select name="tipo" class="form-control form-control-sm">
          <option value="">Todos</option>
          <option value="credito" <?= ($filtros['tipo'] ?? '') === 'credito' ? 'selected' : '' ?>>Crédito</option>
          <option value="debito"  <?= ($filtros['tipo'] ?? '') === 'debito'  ? 'selected' : '' ?>>Débito</option>
        </select>
      </div>
      <div class="form-group col-lg-2 col-md-3">
        <label class="small mb-1">Estado</label>
        <select name="estado" class="form-control form-control-sm">
          <option value="">Todos</option>
          <?php foreach (['activa' => 'Activa', 'bloqueada' => 'Bloqueada',
                          'vencida' => 'Vencida',
                          'pendiente_activacion' => 'Por activar'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= ($filtros['estado'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group col-lg-2 col-md-3">
        <button class="btn btn-outline-primary btn-sm btn-block">Filtrar</button>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tarjetas === []): ?>
<!-- ==================== SIN TARJETAS ==================== -->
<div class="card">
  <div class="card-body">
    <div class="cm-vacio">
      <?= cm_icono('card', 40) ?>
      <?php if ($hayFiltro): ?>
        <p class="mb-2 mt-2">Ninguna tarjeta coincide con el filtro.</p>
        <a href="<?= P::attr($rutas->url('tarjetas')) ?>"
           class="btn btn-sm btn-outline-secondary">Quitar filtros</a>
      <?php elseif ($vistaTodas): ?>
        <p class="mb-1 mt-2">Todavía no se ha emitido ninguna tarjeta.</p>
        <p class="text-muted mb-0" style="font-size:.85rem">
          Use el formulario de arriba para emitir la primera.
        </p>
      <?php else: ?>
        <h5 class="mt-3 mb-1" style="font-size:1rem">Todavía no tiene tarjetas</h5>
        <p class="text-muted mb-3" style="font-size:.88rem;max-width:30rem;margin:0 auto">
          Puede solicitar una tarjeta de débito asociada a su cuenta, o una de
          crédito sujeta a evaluación. Vea nuestro catálogo y con mucho gusto le
          ayudamos a elegir la que mejor le convenga.
        </p>
        <a href="<?= P::attr($rutas->url('tarjetas/catalogo')) ?>" class="btn btn-primary">
          Ver el catálogo de tarjetas
        </a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php else: ?>

<!-- ==================== PLÁSTICOS ==================== -->
<div class="row">
  <?php $logosTarjeta = $rutas->url('assets/plantilla/images'); ?>
  <?php foreach ($tarjetas as $t): ?>
  <div class="col-xl-4 col-lg-6 col-md-6 mb-4">
    <?php
      // El ciclo, bajo el plástico: es lo que se consulta de un vistazo.
      [$fc, $fl, $fd] = $cicloTarjeta($t);

      $esCredito = (string) $t['tipo'] === 'credito';
      $esDebito  = (string) $t['tipo'] === 'debito';
      $conCiclo  = $esCredito && $fc !== '';
    ?>
    <?php /* Plástico y ficha, en UN solo bloque.
             El plástico tiene ancho máximo propio —una tarjeta no crece
             sin límite—, pero los datos de abajo ocupaban la columna
             entera: en pantalla ancha sobresalían por los dos lados y
             el nombre y las fechas parecían sueltos fuera de la tarjeta.
             Dentro del mismo contenedor comparten ancho y bordes. */ ?>
    <div class="cm-ficha">
      <a href="<?= P::attr($rutas->url('tarjetas/' . $t['codigo'])) ?>"
         style="text-decoration:none;display:block">
        <?= P::crudo(cm_plastico($t, ['assets' => $logosTarjeta])) ?>
      </a>

      <?php /* El titular y el cupo van DEBAJO, no dentro: el plastico es
               el plastico, y una tarjeta real no lleva su saldo impreso. */ ?>
      <div class="cm-ficha__datos<?= $conCiclo ? ' cm-ficha__datos--sigue' : '' ?>">
        <span class="cm-ficha__titular">
          <?php /* Débito o crédito, dicho. Son dos productos que se
                   usan distinto —uno gasta su dinero, el otro una línea
                   prestada— y en el listado se distinguían solo por el
                   color del plástico. */ ?>
          <span class="cm-ficha__tipo cm-ficha__tipo--<?= P::attr((string) $t['tipo']) ?>">
            <?= $esCredito ? 'Crédito' : ($esDebito ? 'Débito' : 'Prepago') ?>
          </span>

          <?php /* El mismo acortado por palabras que el plastico: cortar
                   la cadena deja «Jenifer Abigail Castañe…», que no es un
                   nombre sino una cadena rota. */ ?>
          <span class="cm-ficha__nombre"><?= P::e(cm_plastico_titular($t)) ?></span>
          <?php if (!empty($t['es_mia'])): ?>
          <span class="badge badge-light">mía</span>
          <?php endif; ?>
        </span>

        <?php if ($esCredito): ?>
        <span class="cm-ficha__cifra">
          <small>Disponible</small>
          <strong><?= P::e(P::money((int) $t['disponible_cent'])) ?></strong>
        </span>

        <?php /* En una de débito el dinero es suyo y está en su cuenta:
                 enseñar ahí un cupo sería inventar una línea que no
                 existe, y no enseñar nada deja la ficha muda. */ ?>
        <?php elseif ($esDebito && $t['cuenta_saldo_cent'] !== null): ?>
        <span class="cm-ficha__cifra">
          <small>En su cuenta</small>
          <strong><?= P::e(P::money((int) $t['cuenta_saldo_cent'])) ?></strong>
        </span>
        <?php endif; ?>
      </div>

      <?php if ($conCiclo): ?>
      <div class="cm-ciclo">
        <div>
          <small>Corte</small>
          <b><?= P::e(P::fecha($fc, 'd/m')) ?></b>
        </div>
        <div class="text-right">
          <small>Pagar antes del</small>
          <b class="<?= $fd !== null && $fd < 0 && (int) $t['saldo_usado_cent'] > 0 ? 'text-danger' : '' ?>">
            <?= $fl !== '' ? P::e(P::fecha($fl, 'd/m')) : '—' ?>
          </b>
        </div>
      </div>

      <?php /* La cuenta de la que tira, en la de débito: es el dato que
               le dice de dónde va a salir el dinero. */ ?>
      <?php elseif ($esDebito && !empty($t['cuenta_numero'])): ?>
      <div class="cm-ciclo cm-ciclo--debito">
        <div>
          <small>Cuenta ligada</small>
          <b class="cm-mono"><?= P::e($t['cuenta_numero']) ?></b>
        </div>
        <div class="text-right">
          <small>Sin intereses</small>
          <b>Paga con su dinero</b>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ==================== TABLA ==================== -->
<div class="card">
  <div class="card-header"><h4 class="card-title mb-0">Detalle</h4></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" data-cm-tabla>
        <thead>
          <tr>
            <th>Tarjeta</th>
            <?php if ($vistaTodas): ?><th>Cliente</th><?php endif; ?>
            <th>En relieve</th>
            <th>Marca</th>
            <th>Tipo</th>
            <th>Vence</th>
            <!-- Las dos fechas del ciclo. Antes no salía ninguna de las
                 dos, y son lo primero que pregunta un cliente por
                 teléfono: cuándo corta y hasta cuándo tiene para pagar. -->
            <th>Corte</th>
            <th>Límite de pago</th>
            <th>Estado</th>
            <th class="text-right">Límite</th>
            <th class="text-right">Usado</th>
            <th class="text-right">Disponible</th>
            <th class="text-right" style="width:1%;white-space:nowrap">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($tarjetas as $t): ?>
          <tr>
            <td>
              <a href="<?= P::attr($rutas->url('tarjetas/' . $t['codigo'])) ?>" class="cm-mono">
                &bull;&bull;&bull;&bull; <?= P::e($t['ultimos4']) ?>
              </a>
            </td>
            <?php if ($vistaTodas): ?>
            <td style="font-size:.84rem">
              <?= P::e($t['cliente'] ?? '') ?>
              <small class="text-muted d-block" style="font-size:.72rem">
                <?= P::e($t['titular_usuario'] ?? '') ?>
              </small>
            </td>
            <?php endif; ?>
            <td class="text-muted" style="font-size:.82rem"><?= P::e($t['titular']) ?></td>
            <td><?= P::e(ucfirst((string) $t['marca'])) ?></td>
            <td><?= P::e(ucfirst((string) $t['tipo'])) ?></td>
            <td class="text-muted" style="font-size:.82rem">
              <?= str_pad((string) $t['expira_mes'], 2, '0', STR_PAD_LEFT) ?>/<?= P::e((string) $t['expira_anio']) ?>
            </td>
            <?php
              // Una tarjeta de débito no corta ni tiene fecha límite:
              // el dinero ya es del cliente.
              $esCred = (string) $t['tipo'] === 'credito';
              [$fCorte, $fLimite, $faltan] = $cicloTarjeta($t);
            ?>
            <td class="text-muted cm-nowrap" style="font-size:.82rem">
              <?= $esCred && $fCorte !== '' ? P::e(P::fecha($fCorte, 'd/m/Y')) : '—' ?>
            </td>
            <td class="cm-nowrap" style="font-size:.82rem">
              <?php if ($esCred && $fLimite !== ''): ?>
                <?= P::e(P::fecha($fLimite, 'd/m/Y')) ?>
                <?php if ($faltan !== null && (int) $t['saldo_usado_cent'] > 0): ?>
                <small class="d-block <?= $faltan < 0 ? 'text-danger' : ($faltan <= 5 ? 'text-warning' : 'text-muted') ?>"
                       style="font-size:.7rem">
                  <?= $faltan < 0
                        ? 'venció hace ' . abs($faltan) . ' d.'
                        : ($faltan === 0 ? 'vence hoy' : 'faltan ' . $faltan . ' d.') ?>
                </small>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-<?= $badge((string) $t['estado']) ?>">
                <?= P::e(str_replace('_', ' ', (string) $t['estado'])) ?>
              </span>
            </td>
            <td class="text-right cm-mono"><?= P::e(P::money((int) $t['limite_cent'])) ?></td>
            <td class="text-right cm-mono text-danger"><?= P::e(P::money((int) $t['saldo_usado_cent'])) ?></td>
            <td class="text-right cm-mono text-success"><?= P::e(P::money((int) $t['disponible_cent'])) ?></td>
            <td class="text-right">
              <?= P::crudo(cm_acciones([
                    ['Ver', $rutas->url('tarjetas/' . $t['codigo']), 'search',
                     'estilo' => 'primary'],
                    ['Pagar', $rutas->url('tarjetas/' . $t['codigo'] . '/pago'), 'card',
                     'si' => (int) $t['saldo_usado_cent'] > 0
                             && (string) $t['estado'] === 'activa'
                             && (string) $t['tipo'] === 'credito',
                     'titulo' => 'Pagar el saldo de esta tarjeta'],
                  ])) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var f = document.getElementById('form-emitir');
  if (!f) { return; }

  // Una tarjeta de débito no lleva línea de crédito: el campo sobra.
  var tipo  = document.getElementById('tipo');
  var caja  = document.getElementById('caja-limite');

  function ajustar() {
    var esCredito = tipo.value === 'credito';
    caja.style.display = esCredito ? '' : 'none';
    document.getElementById('limite').required = esCredito;
  }

  tipo.addEventListener('change', ajustar);
  ajustar();
})();
</script>
