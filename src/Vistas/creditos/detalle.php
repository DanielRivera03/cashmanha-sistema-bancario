<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $credito
 * @var array $cuotas
 * @var array $referencias
 * @var array $garantias
 * @var array $cuentas
 * @var bool $puedeDesembolsar
 * @var bool $puedeDebitar  Cargar una cuenta: el titular, o ventanilla.
 * @var bool $puedeCobrar   Cobranza: tarjeta del cliente o enlace de pago.
 * @var bool $puedeArreglo  Proponer un arreglo sobre las cuotas vencidas.
 * @var array $arreglos     Arreglos ya pactados sobre este crédito.
 * @var array $deuda        Lo exigible hoy, desglosado.
 * @var int $quitaMaximaBp  Condonación que cobranza concede sin autorización.
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$COLOR_C = ['pendiente'=>'light','parcial'=>'warning','pagada'=>'success','vencida'=>'danger','condonada'=>'info'];
$ruta    = $rutas->url('creditos/' . $credito['codigo']);
$vencidas = 0;
foreach ($cuotas as $q) { if ($q['estado'] !== 'pagada' && (int) $q['dias_vencida'] > 0) { $vencidas++; } }

/* ¿Hace falta la columna de acciones?
   Antes solo existía para quien podía cobrar, y el titular —que no
   puede— se quedaba sin ver el comprobante de sus propias cuotas
   pagadas. La columna aparece si hay algo que poner en ella. */
$hayComprobante = false;

foreach ($cuotas as $q) {
    if (($q['comprobante'] ?? null) !== null) { $hayComprobante = true; break; }
}

$hayAcciones = $puedeDebitar || $puedeCobrar || $hayComprobante;

/* La que toca.
 *
 * Un crédito se paga de la cuota más antigua hacia adelante, y el
 * servidor lo exige. Si la pantalla enseña doce botones «Cobrar»
 * iguales, invita a pulsar el que sea para recibir un error: lo que
 * corresponde es ofrecer solo lo que de verdad se puede hacer. */
$siguienteCuota = null;
$pendientes     = [];

foreach ($cuotas as $q) {
    if (!in_array((string) $q['estado'], ['pagada', 'condonada'], true)) {
        $pendientes[] = $q;

        if ($siguienteCuota === null) {
            $siguienteCuota = (int) $q['numero'];
        }
    }
}
?>

<?php /* El resumen, a lo ancho.
         Las cuatro cifras de cabecera y la barra de avance se leen de
         un vistazo y no compiten con nada: por eso ocupan la fila
         entera en lugar de pelearse el espacio con el panel de datos. */ ?>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div>
          <h4 class="card-title mb-1"><?= P::e($credito['producto']) ?></h4>
          <small class="text-muted">
            <span class="cm-mono"><?= P::e($credito['folio'] ?: '—') ?></span>
            &middot; <?= P::e($credito['cliente']) ?>
          </small>
        </div>
        <div class="d-flex align-items-center" style="gap:.6rem">
          <?php /* Un crédito cancelado necesita su finiquito a mano.
                   El documento se generaba desde hace tiempo y tenía su
                   ruta, pero no había manera de pedirlo desde aquí: el
                   cliente terminaba de pagar y nadie podía entregarle
                   el papel que acredita que no debe nada. */ ?>
          <?php if ($credito['estado'] === 'cancelado'): ?>
          <?= P::crudo(cm_acciones([
                ['Finiquito', $ruta . '/finiquito', 'files',
                 'estilo' => 'success', 'metodo' => 'post', 'csrf' => $csrf,
                 'proposito' => 'credito', 'permiso' => 'contrato.generar',
                 'titulo' => 'Emitir el finiquito de este crédito'],
                ['Contrato', $ruta . '/contrato', 'download',
                 'metodo' => 'post', 'csrf' => $csrf,
                 'proposito' => 'credito', 'permiso' => 'contrato.generar'],
              ])) ?>
          <?php endif; ?>

          <span class="badge badge-<?= $credito['estado'] === 'vigente' ? 'success'
              : ($credito['estado'] === 'cancelado' ? 'success'
              : ($credito['estado'] === 'aprobado' ? 'info' : 'secondary')) ?>">
            <?= P::e(ucfirst((string) $credito['estado'])) ?>
          </span>
        </div>
      </div>

      <div class="card-body">
        <div class="row text-center">
          <div class="col-md-3 col-6 mb-3">
            <small class="text-muted d-block">Monto otorgado</small>
            <h5 class="mb-0"><?= P::e(P::money((int) $credito['monto_cent'])) ?></h5>
          </div>
          <div class="col-md-3 col-6 mb-3">
            <small class="text-muted d-block">Saldo de capital</small>
            <h5 class="mb-0 cm-neg"><?= P::e(P::money((int) $credito['saldo_capital_cent'])) ?></h5>
          </div>
          <div class="col-md-3 col-6 mb-3">
            <small class="text-muted d-block">Cuota mensual</small>
            <h5 class="mb-0"><?= P::e(P::money((int) $credito['cuota_cent'])) ?></h5>
          </div>
          <div class="col-md-3 col-6 mb-3">
            <small class="text-muted d-block">Tasa anual</small>
            <h5 class="mb-0"><?= number_format((int) $credito['tasa_bp'] / 100, 2) ?>%</h5>
          </div>
        </div>

        <div class="progress mt-2" style="height:10px">
          <div class="progress-bar bg-success" style="width:<?= (int) $credito['avance'] ?>%"></div>
        </div>
        <div class="d-flex justify-content-between mt-1">
          <small class="text-muted">
            <?= (int) $credito['cuotas_pagadas'] ?> de <?= (int) $credito['plazo_meses'] ?> cuotas pagadas
          </small>
          <small class="text-muted"><?= (int) $credito['avance'] ?>% completado</small>
        </div>

        <?php if ($vencidas > 0): ?>
        <?php
          /* Lo vencido, con su total y la vía rápida para cobrarlo.
           *
           * Cobrar 53 cuotas de una en una son 53 diálogos para lo que
           * el cliente vive como un solo pago. El botón de aquí las
           * marca todas y abre el cobro con el total ya sumado; quien
           * quiera cobrar solo algunas sigue teniendo las casillas de
           * la tabla. */
          $totalVencido = 0;
          foreach ($cuotas as $q) {
              if ($q['estado'] !== 'pagada' && (int) $q['dias_vencida'] > 0) {
                  $totalVencido += (int) $q['a_pagar_cent'];
              }
          }
        ?>
        <div class="alert alert-danger mt-3 mb-0 d-flex flex-wrap justify-content-between align-items-center">
          <div>
            <strong><?= $vencidas ?> cuota(s) vencida(s)</strong>
            por <strong><?= P::e(P::money($totalVencido)) ?></strong>, mora incluida.
            <div class="small mt-1">
              El recargo se calcula por día sobre el saldo pendiente de cada cuota.
            </div>
          </div>

          <?php if (($puedeDebitar || $puedeCobrar) && $credito['estado'] === 'vigente'): ?>
          <div class="mt-2 mt-md-0 d-flex flex-wrap" style="gap:.4rem">
            <button type="button" class="btn btn-sm btn-danger" id="cm-cobrar-vencidas">
              <?= cm_icono('card', 14, 'mr-1') ?>
              Cobrar las <?= $vencidas ?> de una vez
            </button>

            <?php if ($puedeArreglo): ?>
            <a class="btn btn-sm btn-outline-danger" href="#cm-arreglo">
              <?= cm_icono('swap', 14, 'mr-1') ?> Proponer un arreglo
            </a>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php /* Los datos del crédito, en una fila de tarjetas.
         Antes iban apiladas en una columna lateral estrecha junto a la
         tabla. Al sacar la tabla a su propia fila, esa columna quedó
         sola contra un hueco blanco de dos tercios de pantalla. En
         horizontal ocupan el ancho y se leen igual de bien. */ ?>
<div class="row">
  <div class="col-lg-4 col-md-6">
    <?php if ($puedeDesembolsar): ?>
    <div class="card border-info">
      <div class="card-header"><h4 class="card-title mb-0">Desembolsar crédito</h4></div>
      <div class="card-body">
        <?php if ($cuentas === []): ?>
          <div class="alert alert-warning mb-0">
            El cliente no tiene cuentas activas. Abre una antes de desembolsar.
          </div>
        <?php else: ?>
        <form data-cm-ajax data-cm-recargar method="post" action="<?= P::attr($ruta . '/desembolsar') ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="credito">
          <div class="form-group">
            <label class="small">Cuenta de abono</label>
            <select class="form-control form-control-sm" name="cuenta" required>
              <?php foreach ($cuentas as $cu): ?>
              <option value="<?= P::attr($cu['codigo']) ?>">
                <?= P::e($cu['numero_cuenta']) ?> — <?= P::e($cu['producto']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-info btn-block"
                  data-cm-confirmar="Se acreditarán <?= P::attr(P::money((int) $credito['monto_cent'])) ?> en la cuenta del cliente.">
            Desembolsar <?= P::e(P::money((int) $credito['monto_cent'])) ?>
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card h-100">
      <div class="card-header"><h4 class="card-title mb-0">Información</h4></div>
      <div class="card-body">
        <?php
          /* Lo que se enseña aquí describe el crédito HOY.
           *
           * «Cuotas tardías» contaba solo las pagadas con retraso, así
           * que un crédito con 53 cuotas sin pagar marcaba cero junto a
           * una clasificación «A». Ahora van las dos cifras, cada una
           * diciendo qué cuenta, y la clasificación sale de los días de
           * atraso reales. */
          $claseHoy  = (string) $credito['clasificacion_hoy'];
          $claseFila = (string) $credito['clasificacion'];

          $info = [
            'Plazo'            => (int) $credito['plazo_meses'] . ' meses',
            'Total pagado'     => P::money((int) $credito['total_pagado_cent']),
            'Cuotas vencidas'  => (int) $credito['vencidas'] > 0
                                  ? (int) $credito['vencidas'] . ' · '
                                    . number_format((int) $credito['dias_mora']) . ' días de atraso'
                                  : 'ninguna',
            'Pagadas con retraso' => (string) (int) $credito['cuotas_tardias'],
            'Clasificación'    => $claseHoy
                                  . ($claseHoy !== $claseFila
                                     ? ' (en el registro: ' . $claseFila . ')' : ''),
            'Aprobado'         => P::fecha((string) ($credito['aprobado_en'] ?? ''), 'd/m/Y'),
            'Desembolsado'     => P::fecha((string) ($credito['desembolsado_en'] ?? ''), 'd/m/Y'),
          ];
          if (!empty($credito['destino'])) { $info['Destino'] = (string) $credito['destino']; }
          foreach ($info as $et => $val):
        ?>
        <div class="d-flex justify-content-between py-1 border-bottom">
          <small class="text-muted"><?= P::e($et) ?></small>
          <small class="font-weight-bold"><?= P::e($val) ?></small>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4 col-md-6">
    <?php if ($referencias !== []): ?>
    <div class="card h-100">
      <div class="card-header"><h4 class="card-title mb-0">Referencias</h4></div>
      <div class="card-body p-0">
        <?php foreach ($referencias as $r): ?>
        <div class="px-3 py-2 border-bottom">
          <div class="font-weight-bold" style="font-size:.88rem">
            <?= P::e(trim($r['nombres'] . ' ' . $r['apellidos'])) ?>
          </div>
          <small class="text-muted">
            <?= P::e(ucfirst((string) $r['tipo'])) ?>
            <?php if (!empty($r['telefono'])): ?> &middot; <?= P::e($r['telefono']) ?><?php endif; ?>
            <?php if (!empty($r['empresa'])): ?><br><?= P::e($r['empresa']) ?><?php endif; ?>
          </small>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4 col-md-6">
    <?php if ($garantias !== []): ?>
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Garantías</h4></div>
      <div class="card-body p-0">
        <?php foreach ($garantias as $g): ?>
        <div class="px-3 py-2 border-bottom">
          <div class="font-weight-bold" style="font-size:.88rem">
            <?= P::e(ucfirst((string) $g['tipo'])) ?> —
            <?= P::e(P::money((int) $g['valor_cent'])) ?>
          </div>
          <small class="text-muted"><?= P::e($g['descripcion']) ?></small>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php /* ---------------------------------------------------------
             Arreglos de pago

             Un expediente con 53 cuotas vencidas no se cobra cuota por
             cuota: se negocia. Las dos formas que se usan de verdad son
             «pague un porcentaje de lo vencido y queda al día» y «pague
             una cantidad única y finiquitamos».

             Las dos perdonan parte de la deuda, y perdonar dinero es la
             facultad más delicada que puede tener cobranza. Por eso hay
             un tope que se concede solo, y por encima de él lo tiene que
             mirar otra persona: quien propone no autoriza.
         --------------------------------------------------------- */ ?>
    <?php if ($puedeArreglo || $arreglos !== []): ?>
    <div class="card" id="cm-arreglo">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Arreglos de pago</h4>
        <?php if ($arreglos !== []): ?>
        <span class="badge badge-light"><?= count($arreglos) ?></span>
        <?php endif; ?>
      </div>

      <?php if ($arreglos !== []): ?>
      <div class="card-body p-0">
        <?php foreach (array_slice($arreglos, 0, 4) as $ar):
          $colorAr = [
            'propuesto'  => 'warning', 'autorizado' => 'info',
            'pagado'     => 'success', 'vencido'    => 'secondary',
            'anulado'    => 'secondary',
          ][$ar['estado']] ?? 'light';
        ?>
        <div class="px-3 py-2 border-bottom">
          <div class="d-flex justify-content-between align-items-center">
            <span class="cm-mono" style="font-size:.78rem"><?= P::e($ar['folio']) ?></span>
            <span class="badge badge-<?= P::attr($colorAr) ?>">
              <?= P::e(ucfirst((string) $ar['estado'])) ?>
            </span>
          </div>
          <div class="font-weight-bold" style="font-size:.88rem">
            <?= P::e(P::money((int) $ar['monto_requerido_cent'])) ?>
            <small class="text-muted font-weight-normal">
              <?= $ar['tipo'] === 'finiquito' ? 'finiquito' : 'ponerse al día' ?>
            </small>
          </div>
          <small class="text-muted d-block">
            Condona <?= P::e(P::money((int) $ar['condonado_cent'])) ?>
            de <?= P::e(P::money((int) $ar['exigible_cent'])) ?>
            · vence <?= P::e(P::fecha((string) $ar['vence_el'], 'd/m/Y')) ?>
          </small>
          <small class="text-muted d-block">
            Propuso <?= P::e((string) ($ar['propuso'] ?? '—')) ?>
          </small>

          <?php if ($ar['estado'] === 'propuesto' || $ar['estado'] === 'autorizado'): ?>
          <div class="mt-2">
            <?= P::crudo(cm_acciones([
                  ['Autorizar', $ruta . '/arreglo/' . (int) $ar['id'] . '/autorizar', 'check',
                   'estilo' => 'success', 'metodo' => 'post', 'csrf' => $csrf,
                   'proposito' => 'credito', 'permiso' => 'arreglo.autorizar',
                   'si' => $ar['estado'] === 'propuesto',
                   'confirmar' => 'Autoriza condonar '
                                  . P::money((int) $ar['condonado_cent']) . '.'],
                  ['Enviar enlace', $ruta . '/arreglo/' . (int) $ar['id'] . '/enlace', 'mail',
                   'estilo' => 'primary', 'metodo' => 'post', 'csrf' => $csrf,
                   'proposito' => 'credito', 'permiso' => 'cobro.enlace',
                   'si' => $ar['estado'] === 'autorizado',
                   'confirmar' => 'Se enviará al cliente un enlace para pagar '
                                  . P::money((int) $ar['monto_requerido_cent']) . '.'],
                  ['Anular', $ruta . '/arreglo/' . (int) $ar['id'] . '/anular', 'block',
                   'estilo' => 'danger', 'metodo' => 'post', 'csrf' => $csrf,
                   'proposito' => 'credito', 'permiso' => 'arreglo.anular',
                   'confirmar' => 'Se anula el arreglo y el enlace de pago deja de servir.'],
                ])) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($puedeArreglo): ?>
      <div class="card-body<?= $arreglos !== [] ? ' border-top' : '' ?>">
        <form method="post" action="<?= P::attr($ruta . '/arreglo') ?>"
              data-cm-ajax data-cm-recargar>
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="credito">

          <div class="form-group">
            <label class="small mb-1">Modalidad</label>
            <select class="form-control form-control-sm" name="tipo" id="arr-tipo">
              <option value="al_dia">Ponerse al día — cubre lo vencido</option>
              <option value="finiquito">Finiquito — cancela todo el crédito</option>
            </select>
          </div>

          <div class="form-group">
            <label class="small mb-1">
              Porcentaje que paga
              <strong id="arr-pct-eco">70</strong>% de lo exigible
            </label>
            <input type="range" class="custom-range" name="porcentaje"
                   id="arr-pct" min="10" max="100" step="5" value="70">
            <small class="form-text text-muted">
              Lo que no paga se condona. Por encima del
              <?= number_format($quitaMaximaBp / 100, 0) ?>% de condonación
              lo tiene que autorizar otra persona.
            </small>
          </div>

          <div class="alert alert-light border py-2 mb-2" id="arr-resumen">
            <div class="d-flex justify-content-between">
              <small class="text-muted">Exigible</small>
              <small class="font-weight-bold" id="arr-exigible">—</small>
            </div>
            <div class="d-flex justify-content-between">
              <small class="text-muted">Paga</small>
              <small class="font-weight-bold text-success" id="arr-paga">—</small>
            </div>
            <div class="d-flex justify-content-between">
              <small class="text-muted">Se condona</small>
              <small class="font-weight-bold text-danger" id="arr-condona">—</small>
            </div>
          </div>

          <div class="form-group">
            <label class="small mb-1">Nota (opcional)</label>
            <input type="text" class="form-control form-control-sm" name="nota"
                   maxlength="200" placeholder="Lo acordado con el cliente">
          </div>

          <button type="submit" class="btn btn-sm btn-primary btn-block">
            Proponer el arreglo
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$puedeArreglo && $arreglos === [] && $garantias === []): ?>
    <?php /* Sin garantías ni arreglos esta columna quedaría vacía y la
             fila se vería coja. Lo que de verdad falta aquí es el
             estado del crédito frente al cobro. */ ?>
    <div class="card h-100">
      <div class="card-header"><h4 class="card-title mb-0">Situación de cobro</h4></div>
      <div class="card-body">
        <?php if ($vencidas === 0): ?>
        <p class="mb-0 text-success">
          <?= cm_icono('check', 16, 'mr-1') ?>
          El crédito está al día. No hay cuotas vencidas.
        </p>
        <?php else: ?>
        <p class="mb-1">
          <strong><?= $vencidas ?></strong> cuota(s) vencida(s) por
          <strong><?= P::e(P::money($totalVencido ?? 0)) ?></strong>.
        </p>
        <p class="mb-0 text-muted small">
          El expediente lo lleva el área de cobranza. Si necesita pactar
          un arreglo o enviar un enlace de pago, pídaselo a ellos.
        </p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- La amortización, a lo ancho de la página.
     Once columnas de cifras no caben en una columna de ocho, y
     `table-responsive` acababa poniéndole una barra horizontal
     propia. El desglose completo se pidió entero, así que lo que
     cambia es el ancho disponible, no las columnas. -->
<div class="row">
  <div class="col-12">
    <?php
      /* La tabla de amortización.
       *
       * Antes vivía dentro de una caja de 480 px con su propio scroll.
       * En un crédito a 180 meses eso significa arrastrar una barra
       * diminuta por 180 filas dentro de una página que además tiene su
       * propia barra: dos scrolls anidados, y el de dentro se pierde en
       * cuanto el puntero sale de la caja.
       *
       * Ahora la tabla crece hacia abajo como el resto de la página y se
       * pagina. La paginación es del navegador —las 180 filas ya están
       * en el HTML— porque el crédito completo cabe de sobra y así se
       * puede buscar con Ctrl+F e imprimir entero sin recargar.
       *
       * La página arranca donde está la acción: en un crédito con mora,
       * en la primera cuota vencida; en uno al día, en la próxima a
       * vencer. Abrir siempre por la cuota 1 de 180 obliga a buscar. */
      $porPagina = 12;
      $totalPag  = max(1, (int) ceil(count($cuotas) / $porPagina));

      $indiceFoco = null;
      foreach ($cuotas as $i => $q) {
          if ($q['estado'] !== 'pagada') { $indiceFoco = $i; break; }
      }
      $paginaInicial = $indiceFoco === null
          ? $totalPag
          : (int) floor($indiceFoco / $porPagina) + 1;

      $sumas = ['capital' => 0, 'interes' => 0, 'seguros' => 0,
                'total' => 0, 'pagado' => 0, 'mora' => 0];
      foreach ($cuotas as $q) {
          $sumas['capital'] += (int) $q['capital_cent'];
          $sumas['interes'] += (int) $q['interes_cent'];
          $sumas['seguros'] += (int) $q['seguro_vida_cent'] + (int) $q['seguro_empleo_cent'];
          $sumas['total']   += (int) $q['total_cent'];
          // Lo recibido, no solo la parte que entró a la cuota: así el
          // pie cuadra con lo que se debitó de verdad.
          $sumas['pagado']  += (int) $q['recibido_cent'];
          $sumas['mora']    += (int) $q['mora_viva_cent'];
      }
    ?>
    <div class="card" id="cm-amortizacion"
         data-por-pagina="<?= (int) $porPagina ?>"
         data-pagina-inicial="<?= (int) $paginaInicial ?>">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
        <h4 class="card-title mb-0">Tabla de amortización</h4>

        <?php /* Filtros en pastillas, no en grupo de botones.
                 El `btn-group` con colores de Bootstrap daba un bloque
                 magenta que no se parece a nada más del panel. */ ?>
        <div class="cm-amort-filtros" role="group" aria-label="Filtrar las cuotas">
          <button type="button" class="cm-pildora is-activa" data-filtro="todas">
            Todas <span><?= count($cuotas) ?></span>
          </button>
          <button type="button" class="cm-pildora cm-pildora--mal" data-filtro="vencida">
            Vencidas <span><?= (int) $credito['vencidas'] ?></span>
          </button>
          <button type="button" class="cm-pildora cm-pildora--bien" data-filtro="pagada">
            Pagadas <span><?= (int) $credito['cuotas_pagadas'] ?></span>
          </button>
          <button type="button" class="cm-pildora" data-filtro="pendiente">
            Por vencer
            <span><?= max(0, count($cuotas) - (int) $credito['vencidas']
                               - (int) $credito['cuotas_pagadas']) ?></span>
          </button>
        </div>
      </div>

      <?php if (($puedeDebitar || $puedeCobrar) && $credito['estado'] === 'vigente'): ?>
      <?php /* Cobro de varias cuotas de una vez.
               Con 53 cuotas vencidas, cobrarlas una por una son 53
               confirmaciones y 53 asientos. Aquí se marcan las que
               entran y se cobran en una sola operación. La barra solo
               aparece cuando hay algo marcado: si está siempre, ocupa
               sitio sin hacer nada. */ ?>
      <?php if ($siguienteCuota !== null): ?>
      <div class="cm-adelantar">
        <div class="cm-adelantar__texto">
          <strong>Adelantar cuotas</strong>
          <span>
            Se cobran desde la <?= (int) $siguienteCuota ?> hacia adelante, sin dejar
            ninguna por el camino. Al adelantar no se descuentan los intereses ya
            devengados de cada cuota.
          </span>
        </div>

        <div class="cm-adelantar__control">
          <label for="cm-adelantar-n" class="sr-only">Cuántas cuotas adelantar</label>
          <select class="form-control form-control-sm" id="cm-adelantar-n">
            <?php /* El máximo lo pone el servidor, no esta pantalla: si el
                     número estuviera escrito aquí acabaría discrepando
                     del que de verdad se aplica. */ ?>
            <?php foreach (range(1, min(
                  \CashMan\Http\Controlador\ControladorPasarela::MAXIMO_ADELANTO,
                  count($pendientes)
                )) as $n): ?>
            <option value="<?= $n ?>"><?= $n ?> cuota<?= $n > 1 ? 's' : '' ?></option>
            <?php endforeach; ?>
          </select>

          <div class="cm-adelantar__total">
            <small>Total</small>
            <strong id="cm-adelantar-total">—</strong>
          </div>

          <button type="button" class="btn btn-sm btn-primary" id="cm-adelantar-cobrar">
            <?= cm_icono('card', 14, 'mr-1') ?> Cobrar
          </button>
        </div>
      </div>

      <?php /* La barra vieja de selección libre sigue existiendo para
               que el guion de cobro en lote no se quede sin su sitio,
               pero ya no se marca a dedo: la llena el control de
               arriba. */ ?>
      <div class="cm-amort-lote" id="cm-lote" hidden>
        <div class="cm-amort-lote__texto">
          Se cobrarán <strong id="cm-lote-n">0</strong> cuota(s) ·
          total <strong id="cm-lote-total">$0.00</strong>
        </div>
        <div class="cm-amort-lote__botones">
          <button type="button" class="btn btn-sm btn-outline-secondary" id="cm-lote-limpiar">
            Cancelar
          </button>
          <button type="button" class="btn btn-sm btn-primary" id="cm-lote-cobrar">
            <?= cm_icono('card', 14, 'mr-1') ?> Confirmar el cobro
          </button>
        </div>
      </div>
      <?php endif; ?>
      <?php endif; ?>

      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0 cm-amort">
            <thead>
              <tr>
                <?php if (($puedeDebitar || $puedeCobrar) && $credito['estado'] === 'vigente'): ?>
                <th style="width:1%"></th>
                <?php endif; ?>
                <th>#</th><th>Vencimiento</th>
                <th class="cm-num">Capital</th>
                <th class="cm-num">Interés</th>
                <th class="cm-num">Seguros</th>
                <th class="cm-num">Cuota</th>
                <th class="cm-num">Abonado</th>
                <th class="cm-num">Mora</th>
                <th class="cm-num">Por pagar</th>
                <th class="cm-num">Saldo</th>
                <th>Estado</th>
                <?php if ($hayAcciones): ?>
                <th class="text-right" style="width:1%;white-space:nowrap">Acciones</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($cuotas as $q):
              $vencida = $q['estado'] !== 'pagada' && (int) $q['dias_vencida'] > 0;
              $seguros = (int) $q['seguro_vida_cent'] + (int) $q['seguro_empleo_cent'];
              $clase   = $q['estado'] === 'pagada' ? 'pagada' : ($vencida ? 'vencida' : 'pendiente');
            ?>
              <tr class="cm-amort-fila<?= $vencida ? ' table-warning' : '' ?>"
                  data-estado="<?= P::attr($clase) ?>">
                <?php if (($puedeDebitar || $puedeCobrar) && $credito['estado'] === 'vigente'): ?>
                <td>
                  <?php /* La casilla se marca sola según cuántas cuotas
                           se adelanten: no se eligen a dedo, porque a
                           dedo se dejan huecos. */ ?>
                  <?php if ($q['estado'] !== 'pagada'): ?>
                  <input type="checkbox" class="cm-lote-cuota" disabled
                         value="<?= P::attr($q['codigo']) ?>"
                         data-numero="<?= (int) $q['numero'] ?>"
                         data-monto="<?= (int) $q['a_pagar_cent'] ?>"
                         aria-label="Cuota <?= (int) $q['numero'] ?>">
                  <?php endif; ?>
                </td>
                <?php endif; ?>
                <td class="font-weight-bold"><?= (int) $q['numero'] ?></td>
                <td class="text-muted">
                  <?= P::e(P::fecha((string) $q['fecha_vencimiento'], 'd/m/Y')) ?>
                  <?php if ($vencida): ?>
                    <small class="text-danger d-block">
                      <?= number_format((int) $q['dias_vencida']) ?> días
                    </small>
                  <?php elseif (!empty($q['pagada_en'])): ?>
                    <small class="text-success d-block">
                      pagada <?= P::e(P::fecha((string) $q['pagada_en'], 'd/m/Y')) ?>
                    </small>
                  <?php endif; ?>
                </td>
                <td class="cm-num"><?= P::e(P::money((int) $q['capital_cent'])) ?></td>
                <td class="cm-num text-muted"><?= P::e(P::money((int) $q['interes_cent'])) ?></td>
                <td class="cm-num text-muted">
                  <?php if ($seguros > 0): ?>
                    <span title="Vida <?= P::attr(P::money((int) $q['seguro_vida_cent'])) ?> · Desempleo <?= P::attr(P::money((int) $q['seguro_empleo_cent'])) ?>">
                      <?= P::e(P::money($seguros)) ?>
                    </span>
                  <?php else: ?>
                    <span class="text-muted">&mdash;</span>
                  <?php endif; ?>
                </td>
                <td class="cm-num font-weight-bold"><?= P::e(P::money((int) $q['total_cent'])) ?></td>

                <?php /* Abonado: TODO lo que se recibió contra la cuota.
                         Cuando se cobra por compensación o en ventanilla
                         se cobra la cuota más su recargo, y enseñar solo
                         la parte de la cuota hacía que el cliente viera
                         un abono menor del que se le debitó. El desglose
                         va debajo para que pueda cuadrarlo. */ ?>
                <td class="cm-num">
                  <?php if ((int) $q['recibido_cent'] > 0): ?>
                    <?= P::e(P::money((int) $q['recibido_cent'])) ?>
                    <?php if ((int) $q['mora_cobrada_cent'] > 0): ?>
                    <small class="text-muted d-block" style="font-size:.68rem">
                      <?= P::e(P::money((int) $q['pagado_cent'])) ?> cuota +
                      <?= P::e(P::money((int) $q['mora_cobrada_cent'])) ?> recargo
                    </small>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted">&mdash;</span>
                  <?php endif; ?>
                </td>

                <?php /* Mora: la que se debe HOY, no la ya cobrada. */ ?>
                <td class="cm-num <?= (int) $q['mora_viva_cent'] > 0 ? 'text-danger' : 'text-muted' ?>">
                  <?= (int) $q['mora_viva_cent'] > 0
                      ? P::e(P::money((int) $q['mora_viva_cent']))
                      : '<span class="text-muted">&mdash;</span>' ?>
                </td>

                <td class="cm-num <?= $vencida ? 'text-danger font-weight-bold' : '' ?>">
                  <?= (int) $q['a_pagar_cent'] > 0
                      ? P::e(P::money((int) $q['a_pagar_cent']))
                      : '<span class="text-muted">&mdash;</span>' ?>
                </td>
                <td class="cm-num text-muted">
                  <?= P::e(P::money((int) $q['saldo_posterior_cent'])) ?>
                </td>
                <td>
                  <span class="badge badge-<?= P::attr($COLOR_C[$q['estado']] ?? 'light') ?>">
                    <?= P::e(ucfirst((string) $q['estado'])) ?>
                  </span>
                </td>
                <?php if ($hayAcciones): ?>
                <td class="text-right cm-nowrap">
                  <?php /* Solo la siguiente. Las de más adelante no se
                           pueden pagar sueltas: para eso está el
                           control de adelantar, que las cobra desde
                           esta hacia adelante sin dejar huecos. */ ?>
                  <?php if ($q['estado'] !== 'pagada' && $credito['estado'] === 'vigente'
                            && ($puedeDebitar || $puedeCobrar)
                            && (int) $q['numero'] === $siguienteCuota): ?>
                  <button class="btn btn-sm btn-primary btn-pagar"
                          data-cuota="<?= P::attr($q['codigo']) ?>"
                          data-numero="<?= (int) $q['numero'] ?>"
                          data-monto="<?= (int) $q['a_pagar_cent'] ?>">Cobrar</button>

                  <?php elseif ($q['estado'] !== 'pagada' && $credito['estado'] === 'vigente'
                                && $siguienteCuota !== null): ?>
                  <small class="text-muted cm-nowrap" style="font-size:.74rem">
                    tras la <?= (int) $siguienteCuota ?>
                  </small>

                  <?php /* Pagada y con asiento: aquí está la constancia.
                           Sin esto «Pagada» era una palabra en pantalla,
                           y el cliente que necesitaba demostrar el pago
                           tenía que pedirlo por teléfono. */ ?>
                  <?php elseif (($q['comprobante'] ?? null) !== null): ?>
                  <a class="btn btn-sm btn-outline-secondary"
                     href="<?= P::attr($rutas->url('comprobante/' . $q['comprobante'])) ?>"
                     target="_blank" rel="noopener"
                     data-cm-doc data-cm-doc-titulo="Preparando el comprobante"
                     data-cm-doc-texto="Se abrirá en una pestaña nueva."
                     title="Comprobante del pago de la cuota <?= (int) $q['numero'] ?>">
                    <?= cm_icono('files', 14, 'mr-1') ?>Comprobante
                  </a>
                  <?php else: ?>
                  <span class="text-muted">&mdash;</span>
                  <?php endif; ?>
                </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>

            <?php /* Los totales del plan, para cuadrar de un vistazo. */ ?>
            <tfoot class="cm-amort-totales">
              <tr>
                <th colspan="<?= (($puedeDebitar || $puedeCobrar)
                                   && $credito['estado'] === 'vigente') ? 3 : 2 ?>">
                  Totales del plan
                </th>
                <th class="cm-num"><?= P::e(P::money($sumas['capital'])) ?></th>
                <th class="cm-num"><?= P::e(P::money($sumas['interes'])) ?></th>
                <th class="cm-num"><?= P::e(P::money($sumas['seguros'])) ?></th>
                <th class="cm-num"><?= P::e(P::money($sumas['total'])) ?></th>
                <th class="cm-num"><?= P::e(P::money($sumas['pagado'])) ?></th>
                <th class="cm-num <?= $sumas['mora'] > 0 ? 'text-danger' : '' ?>">
                  <?= P::e(P::money($sumas['mora'])) ?>
                </th>
                <?php /* Por pagar: la suma de lo que queda cuota a cuota.
                         Calcularlo como total − abonado + mora daba de
                         menos, porque «abonado» ahora incluye recargos
                         que no forman parte del total del plan. */ ?>
                <th class="cm-num">
                  <?php $porPagarTotal = 0;
                        foreach ($cuotas as $qq) { $porPagarTotal += (int) $qq['a_pagar_cent']; } ?>
                  <?= P::e(P::money($porPagarTotal)) ?>
                </th>
                <th colspan="<?= $hayAcciones ? 3 : 2 ?>"></th>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <div class="card-footer cm-amort-pie d-flex flex-wrap justify-content-between align-items-center">
        <small class="text-muted cm-amort-rango"></small>
        <nav aria-label="Paginación de la tabla de amortización">
          <ul class="pagination pagination-sm mb-0 cm-amort-paginas"></ul>
        </nav>
      </div>
    </div>
  </div>

  </div>

<!-- ---------------------------------------------------------------------
     Modal de cobro

     Lo que se ofrece aquí depende de quién esté mirando, y no por
     cortesía: el servidor rechaza el cobro de lo ajeno a quien no tenga
     `deposito.registrar`, así que enseñar el botón a cobranza era
     prometer algo que acababa en un 403 sin explicación.

     Al titular     su propia cuenta y su tarjeta.
     A ventanilla   la cuenta del cliente que tiene delante, porque
                    acaba de recibirle el efectivo.
     A cobranza     ninguna de las dos. Cobranza no mete la mano en la
                    cuenta de nadie: cobra con la tarjeta que el cliente
                    le dicta, o le manda un enlace para que pague él.
---------------------------------------------------------------------- -->
<div class="modal fade" id="modal-pago" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Cobrar <span id="p-numero"></span></h5>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>

      <div class="modal-body pb-0">
        <div class="alert alert-light border mb-3">
          A cobrar: <strong id="p-monto"></strong><br>
          <small class="text-muted">
            Incluye el recargo por mora acumulado hasta hoy, calculado por
            día sobre el saldo pendiente de la cuota.
          </small>
        </div>
      </div>

      <?php
      /* El cobro se ofrece si hay cuenta que debitar O si quien atiende
         tiene su caja abierta: en ventanilla el cliente paga en
         efectivo o con cheque y no hace falta ninguna cuenta. */
      $enCaja   = (bool) ($enVentanilla ?? false);
      $hayCobro = $puedeDebitar && ($cuentas !== [] || $enCaja);
      ?>
      <?php if ($hayCobro): ?>
      <form id="form-pago">
        <div class="modal-body pt-0">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="credito">
          <input type="hidden" name="cuota" id="p-cuota">
          <?php /* Varias cuotas a la vez, separadas por coma. El
                   servidor las cobra dentro de una sola transacción:
                   o entran todas o no entra ninguna. */ ?>
          <input type="hidden" name="cuotas" id="p-lote-cuotas">
          <input type="hidden" name="_idem" id="p-idem">

          <?php if ($enCaja): ?>
          <?php /* En ventanilla el cliente trae el dinero. Antes solo
                   se podía debitar su cuenta y la pantalla lo disimulaba
                   diciendo «con el efectivo ya recibido en ventanilla»:
                   el cajero tomaba los billetes y le cargaba la cuenta,
                   así que al cliente le salía en su estado de cuenta un
                   débito que él no hizo, el efectivo no constaba en
                   ningún sitio, y quien no tenía saldo —que es quien
                   viene a pagar en efectivo— no podía pagar. */ ?>
          <div class="form-group">
            <label>¿Con qué paga?</label>
            <select class="form-control" name="medio" id="p-medio">
              <option value="efectivo" selected>Efectivo</option>
              <option value="cheque">Cheque</option>
              <?php if ($cuentas !== []): ?>
              <option value="cuenta">Debitar su cuenta de ahorro</option>
              <?php endif; ?>
            </select>
          </div>

          <div class="form-row d-none" id="p-cheque">
            <div class="form-group col-7">
              <label>Banco del cheque</label>
              <input type="text" class="form-control" name="cheque_banco"
                     maxlength="80" autocomplete="off" placeholder="Banco girado">
            </div>
            <div class="form-group col-5">
              <label>Número</label>
              <input type="text" class="form-control" name="cheque_numero"
                     maxlength="30" autocomplete="off">
            </div>
          </div>
          <?php else: ?>
          <input type="hidden" name="medio" id="p-medio" value="cuenta">
          <?php endif; ?>

          <div class="form-group mb-0 <?= $enCaja ? 'd-none' : '' ?>" id="p-caja-cuenta">
            <label>
              <?= $credito['esMio'] ? 'Cuenta de cargo' : 'Cuenta del cliente a debitar' ?>
            </label>
            <?php if ($cuentas === []): ?>
            <p class="text-muted mb-0" style="font-size:.86rem">
              El cliente no tiene ninguna cuenta activa para debitar.
            </p>
            <?php else: ?>
            <select class="form-control" name="cuenta">
              <?php foreach ($cuentas as $cu): ?>
              <option value="<?= P::attr($cu['codigo']) ?>">
                <?= P::e($cu['numero_cuenta']) ?> — disponible
                <?= P::e(P::money((int) $cu['disponible_cent'])) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <?php if (!$credito['esMio']): ?>
            <small class="form-text text-muted">
              Debitar la cuenta requiere el consentimiento del cliente y queda
              registrado a su nombre.
            </small>
            <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="p-enviar">
            <?php if ($enCaja): ?>
              Registrar el cobro
            <?php else: ?>
              <?= $credito['esMio'] ? 'Debitar de mi cuenta' : 'Debitar la cuenta del cliente' ?>
            <?php endif; ?>
          </button>
        </div>
      </form>
      <?php else: ?>
      <input type="hidden" id="p-cuota" value="">
      <?php if ($puedeDebitar && $cuentas === []): ?>
      <div class="modal-body pt-0">
        <div class="alert alert-light border mb-0" style="font-size:.86rem">
          Para cobrar en efectivo o con cheque hace falta tener la caja
          abierta. Ábrala desde <strong>Caja &rsaquo; Mi caja</strong> y vuelva
          a intentarlo.
        </div>
      </div>
      <?php endif; ?>
      <?php endif; ?>

      <?php if ($credito['esMio']): ?>
      <!-- El titular paga con su tarjeta por la pasarela de siempre. -->
      <div class="modal-body border-top pt-3">
        <div class="text-center text-muted mb-2" style="font-size:.76rem">
          — o pague con tarjeta —
        </div>

        <?php /* Con varias cuotas seleccionadas se manda el CRÉDITO y
                 cuántas, no la lista. El cobro se aplica desde la más
                 antigua hacia adelante, que es como funciona el resto
                 del sistema, y así sobrevive a que entre un abono
                 mientras el cliente está en la pasarela: el importe y
                 el orden siguen valiendo, una lista de identificadores
                 no. */ ?>
        <?php /* Navegación COMPLETA (data-cm-entero), no parcial: se sale
                 del modal de cobro hacia la pasarela. Si la trajera CMVista
                 como trozo, Stripe se inicializaría a medias y —sobre todo—
                 el fondo oscuro del modal podía quedarse pegado. Una carga
                 entera arranca la pasarela igual que abrir su enlace a mano. */ ?>
        <form method="get" action="<?= P::attr($rutas->url('pagar')) ?>" data-cm-entero>
          <input type="hidden" name="concepto" value="cuota_credito">
          <input type="hidden" name="cuota"   id="p-cuota-tar">
          <input type="hidden" name="credito" id="p-credito-tar"
                 value="<?= P::attr($credito['codigo']) ?>">
          <input type="hidden" name="cuantas" id="p-cuantas-tar" value="1">
          <button type="submit" class="btn btn-outline-primary btn-block">
            <?= cm_icono('card', 15, 'mr-1') ?> Pagar con tarjeta
          </button>
        </form>

        <small class="d-block text-muted text-center mt-2" style="font-size:.72rem">
          Se aceptan Visa, Mastercard y American Express, de esta
          institución o de cualquier otro banco.
        </small>
      </div>
      <?php endif; ?>

      <?php if ($puedeCobrar && !$credito['esMio']): ?>
      <!-- Cobranza: las dos vías en que el cliente consiente el cargo. -->
      <div class="modal-body border-top pt-3">
        <div class="text-center text-muted mb-3" style="font-size:.76rem">
          — cobro al cliente —
        </div>

        <?php /* El requerimiento es por TODO lo vencido, siempre.
                 No hay aquí casilla ni selección que lo recorte: un
                 requerimiento parcial obliga a declarar que se cobra una
                 parte y a reservar el resto, y eso es una puerta abierta
                 a mandar un documento que dice «comprende 5 cuotas»
                 cuando el crédito debe 53. El botón dice exactamente
                 cuántas y cuánto antes de pulsarlo. */ ?>
        <form method="post" action="<?= P::attr($ruta . '/enlace-pago') ?>"
              data-cm-ajax>
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="credito">

          <button type="submit" class="btn btn-primary btn-block mb-2"
                  data-cm-confirmar="Se requerirá al cliente la totalidad de lo vencido: <?= (int) $credito['vencidas'] ?> cuota(s) por <?= P::attr(P::money($totalVencido ?? 0)) ?>.">
            <?= cm_icono('mail', 15, 'mr-1') ?>
            Requerir por correo
            <?php if ((int) $credito['vencidas'] > 0): ?>
              · <?= (int) $credito['vencidas'] ?> cuotas ·
              <?= P::e(P::money($totalVencido ?? 0)) ?>
            <?php endif; ?>
          </button>
        </form>

        <small class="d-block text-muted" style="font-size:.72rem">
          Se le requiere <strong>la totalidad de lo vencido</strong>, con su
          recargo por mora calculado al día de hoy. El enlace va al correo
          que consta en su expediente, queda habilitado 2 horas y cada envío
          sustituye al anterior. Lo abre y paga él con su tarjeta: ni usted
          ni nadie de la institución llega a ver el número.
        </small>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';
  var URL_PAGO = <?= P::js($rutas->url('creditos/pagar-cuota')) ?>;

  // -------------------------------------------------------------------
  // Simulador del arreglo de pago
  // -------------------------------------------------------------------
  //
  // Las cifras se calculan aquí mismo mientras se mueve la barra, para
  // que quien negocia vea al instante cuánto paga el cliente y cuánto
  // se está perdonando. Al enviar, el servidor las vuelve a calcular
  // con los datos de la base: esto es una ayuda visual, no la fuente de
  // la verdad. Si el navegador mintiera, el servidor lo corregiría.
  (function simuladorArreglo() {
    var barra = document.getElementById('arr-pct');
    if (!barra) { return; }

    var DEUDA = <?= P::js([
      'vencido'  => (int) $deuda['vencido_cent'],
      'mora'     => (int) $deuda['mora_cent'],
      'capital'  => (int) $deuda['capital_futuro_cent'],
    ]) ?>;

    var tipo = document.getElementById('arr-tipo');

    function pintar() {
      var exigible = DEUDA.vencido + DEUDA.mora
                   + (tipo.value === 'finiquito' ? DEUDA.capital : 0);

      var pct  = parseInt(barra.value, 10) || 0;
      var paga = Math.round(exigible * pct / 100);

      document.getElementById('arr-pct-eco').textContent = String(pct);
      document.getElementById('arr-exigible').textContent = window.CMUtil.money(exigible);
      document.getElementById('arr-paga').textContent     = window.CMUtil.money(paga);
      document.getElementById('arr-condona').textContent  =
        window.CMUtil.money(Math.max(0, exigible - paga));
    }

    barra.addEventListener('input', pintar);
    tipo.addEventListener('change', pintar);
    pintar();
  })();

  function idem() {
    var a = new Uint8Array(16); crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0'+b.toString(16)).slice(-2); }).join('');
  }

  function ponerCuota(id, valor) {
    var el = document.getElementById(id);
    if (el) { el.value = valor; }
  }

  document.querySelectorAll('.btn-pagar').forEach(function (b) {
    b.addEventListener('click', function () {
      // Cada vía de cobro tiene su propio campo, y según el rol de
      // quien mira algunas no están en la página. Por eso se asigna a
      // través de una función que tolera el elemento ausente: antes,
      // un `getElementById(...).value` directo rompía el modal entero
      // en cuanto faltaba uno.
      ponerCuota('p-cuota',        b.dataset.cuota);
      ponerCuota('p-cuota-tar',    b.dataset.cuota);
      // El enlace de pago ya no recibe selección: requiere siempre toda
      // la mora, y el servidor la lee de la cartera. Dejar aquí un
      // campo que la recorte fue lo que produjo un requerimiento por
      // cinco cuotas sobre un crédito que debía cincuenta y tres.
      ponerCuota('p-idem',         idem());

      document.getElementById('p-numero').textContent = b.dataset.numero;
      document.getElementById('p-monto').textContent =
        window.CMUtil.money(parseInt(b.dataset.monto, 10) || 0);

      $('#modal-pago').modal('show');
    });
  });

  // -------------------------------------------------------------------
  // Paginación de la tabla de amortización
  // -------------------------------------------------------------------
  //
  // Las filas ya están en el HTML; aquí solo se decide cuáles se ven.
  // Eso mantiene Ctrl+F y la impresión funcionando sobre el plan
  // completo, que es lo que se pierde cuando la paginación va al
  // servidor.
  (function paginarAmortizacion() {
    var caja = document.getElementById('cm-amortizacion');
    if (!caja) { return; }

    var filas    = Array.prototype.slice.call(caja.querySelectorAll('.cm-amort-fila'));
    var lista    = caja.querySelector('.cm-amort-paginas');
    var rango    = caja.querySelector('.cm-amort-rango');
    var porPag   = parseInt(caja.dataset.porPagina, 10) || 12;
    var filtro   = 'todas';
    var pagina   = parseInt(caja.dataset.paginaInicial, 10) || 1;

    function visibles() {
      return filtro === 'todas'
        ? filas
        : filas.filter(function (f) { return f.dataset.estado === filtro; });
    }

    function boton(etiqueta, destino, activo, apagado) {
      var li = document.createElement('li');
      li.className = 'page-item' + (activo ? ' active' : '') + (apagado ? ' disabled' : '');

      var a = document.createElement('a');
      a.className = 'page-link';
      a.href = '#';
      a.textContent = etiqueta;

      if (!apagado && !activo) {
        a.addEventListener('click', function (ev) {
          ev.preventDefault();
          pagina = destino;
          pintar();
        });
      } else {
        a.addEventListener('click', function (ev) { ev.preventDefault(); });
      }

      li.appendChild(a);
      return li;
    }

    function pintar() {
      var v      = visibles();
      var total  = Math.max(1, Math.ceil(v.length / porPag));
      pagina = Math.min(Math.max(1, pagina), total);

      var desde = (pagina - 1) * porPag;
      var hasta = desde + porPag;

      filas.forEach(function (f) { f.style.display = 'none'; });
      v.slice(desde, hasta).forEach(function (f) { f.style.display = ''; });

      rango.textContent = v.length === 0
        ? 'No hay cuotas en este filtro'
        : 'Cuotas ' + (desde + 1) + ' a ' + Math.min(hasta, v.length) +
          ' de ' + v.length;

      lista.innerHTML = '';
      lista.appendChild(boton('‹', pagina - 1, false, pagina === 1));

      // Con 180 cuotas son 15 páginas: caben todas. Si fueran más, se
      // muestra una ventana alrededor de la actual con los extremos
      // siempre accesibles, que es como se navega sin perder el sitio.
      var ventana = [];
      for (var i = 1; i <= total; i++) {
        if (i === 1 || i === total || Math.abs(i - pagina) <= 2) { ventana.push(i); }
      }

      var previo = 0;
      ventana.forEach(function (i) {
        if (previo && i - previo > 1) {
          lista.appendChild(boton('…', i, false, true));
        }
        lista.appendChild(boton(String(i), i, i === pagina, false));
        previo = i;
      });

      lista.appendChild(boton('›', pagina + 1, false, pagina === total));
    }

    caja.querySelectorAll('.cm-amort-filtros [data-filtro]').forEach(function (b) {
      b.addEventListener('click', function () {
        caja.querySelectorAll('.cm-amort-filtros [data-filtro]').forEach(function (o) {
          o.classList.remove('is-activa');
        });
        b.classList.add('is-activa');
        filtro = b.dataset.filtro;
        pagina = 1;
        pintar();
      });
    });

    pintar();

    // -----------------------------------------------------------------
    // Cobro de varias cuotas
    // -----------------------------------------------------------------
    //
    // Con 53 cuotas vencidas, cobrarlas de una en una son 53 diálogos y
    // 53 asientos para lo que el cliente vive como un solo pago. Aquí se
    // marcan las que entran y se envían juntas; el servidor las cobra
    // dentro de una sola transacción.
    var barra   = document.getElementById('cm-lote');
    var todasCb = document.getElementById('cm-lote-todas');

    if (!barra) { return; }

    function marcadas() {
      return Array.prototype.slice.call(
        caja.querySelectorAll('.cm-lote-cuota:checked')
      );
    }

    function refrescarLote() {
      var m = marcadas();
      var total = m.reduce(function (s, c) {
        return s + (parseInt(c.dataset.monto, 10) || 0);
      }, 0);

      barra.hidden = m.length === 0;
      document.getElementById('cm-lote-n').textContent = String(m.length);
      document.getElementById('cm-lote-total').textContent = window.CMUtil.money(total);
    }

    caja.addEventListener('change', function (ev) {
      if (ev.target.classList.contains('cm-lote-cuota')) { refrescarLote(); }
    });

    /* ---------- Adelantar cuotas ----------
     *
     * No se eligen a dedo: se elige CUÁNTAS, y se marcan las primeras
     * pendientes en orden. Un crédito se paga de la cuota más antigua
     * hacia adelante —el servidor lo exige—, y dejar elegir a dedo solo
     * sirve para que el usuario arme un salto y reciba un error.
     */
    var selN     = document.getElementById('cm-adelantar-n');
    var totalAde = document.getElementById('cm-adelantar-total');
    var btnAde   = document.getElementById('cm-adelantar-cobrar');

    function pendientesEnOrden() {
      // Todas, no solo las de la página visible: el orden del crédito
      // no depende de en qué página de la tabla se esté mirando.
      return Array.prototype.slice.call(caja.querySelectorAll('.cm-lote-cuota'))
        .sort(function (a, b) {
          return (parseInt(a.dataset.numero, 10) || 0) - (parseInt(b.dataset.numero, 10) || 0);
        });
    }

    function previsualizar() {
      if (!selN || !totalAde) { return 0; }

      var cuantas = parseInt(selN.value, 10) || 1;
      var total   = 0;

      pendientesEnOrden().forEach(function (cb, i) {
        var entra = i < cuantas;
        cb.checked = entra;
        if (entra) { total += parseInt(cb.dataset.monto, 10) || 0; }
      });

      totalAde.textContent = window.CMUtil.money(total);
      refrescarLote();

      return total;
    }

    if (selN) {
      selN.addEventListener('change', previsualizar);
      previsualizar();
    }

    if (btnAde) {
      btnAde.addEventListener('click', function () {
        previsualizar();

        // La barra de confirmación ya quedó con lo que se va a cobrar:
        // se lleva al usuario hasta ella para que lo vea antes.
        if (barra && !barra.hidden) {
          barra.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      });
    }

    document.getElementById('cm-lote-limpiar').addEventListener('click', function () {
      marcadas().forEach(function (c) { c.checked = false; });
      if (todasCb) { todasCb.checked = false; }
      refrescarLote();
    });

    function abrirCobroDe(lista) {
      if (lista.length === 0) { return; }

      var total = lista.reduce(function (s, c) {
        return s + (parseInt(c.dataset.monto, 10) || 0);
      }, 0);

      var destino = document.getElementById('p-lote-cuotas');
      if (destino) {
        destino.value = lista.map(function (c) { return c.value; }).join(',');
      }

      document.getElementById('p-numero').textContent = lista.length + ' cuota(s)';
      document.getElementById('p-monto').textContent = window.CMUtil.money(total);

      // La cuota suelta se limpia: si quedara puesta, el servidor
      // recibiría las dos cosas y no sabría cuál atender.
      ponerCuota('p-cuota', '');
      ponerCuota('p-idem', idem());

      /* El pago con tarjeta: la MÁS ANTIGUA de las seleccionadas y
         cuántas son. Antes solo existía el campo de una cuota, y al
         adelantar doce se enviaba vacío: la pasarela recibía
         `cuota=` y ofrecía pagar otra cosa. */
      var antigua = lista.slice().sort(function (a, b) {
        return (parseInt(a.dataset.numero, 10) || 0) - (parseInt(b.dataset.numero, 10) || 0);
      })[0];

      ponerCuota('p-cuota-tar', antigua ? antigua.value : '');
      ponerCuota('p-cuantas-tar', String(lista.length));

      $('#modal-pago').modal('show');
    }

    document.getElementById('cm-lote-cobrar')
      .addEventListener('click', function () { abrirCobroDe(marcadas()); });

    // «Cobrar las N de una vez»: marca todas las vencidas, estén en la
    // página que estén, y abre el cobro con el total ya sumado.
    var botonVencidas = document.getElementById('cm-cobrar-vencidas');

    if (botonVencidas) {
      botonVencidas.addEventListener('click', function () {
        var lista = [];

        caja.querySelectorAll('.cm-amort-fila').forEach(function (fila) {
          if (fila.dataset.estado !== 'vencida') { return; }
          var cb = fila.querySelector('.cm-lote-cuota');
          if (cb) { cb.checked = true; lista.push(cb); }
        });

        refrescarLote();
        abrirCobroDe(lista);
      });
    }
  })();

  var f = document.getElementById('form-pago');
  if (f) {
    /* Cada medio pide lo suyo: el cheque, banco y número; el débito,
       la cuenta. Enseñarlo todo a la vez obliga al cajero a mirar tres
       campos de los que dos no van a usarse. */
    var selMedio  = document.getElementById('p-medio');
    var cajaCheq  = document.getElementById('p-cheque');
    var cajaCta   = document.getElementById('p-caja-cuenta');
    var btnEnviar = document.getElementById('p-enviar');

    if (selMedio && selMedio.tagName === 'SELECT') {
      var ajustarMedio = function () {
        var v = selMedio.value;

        if (cajaCheq) { cajaCheq.classList.toggle('d-none', v !== 'cheque'); }
        if (cajaCta)  { cajaCta.classList.toggle('d-none',  v !== 'cuenta'); }

        if (cajaCheq) {
          cajaCheq.querySelectorAll('input').forEach(function (c) {
            c.required = (v === 'cheque');
          });
        }

        var sel = cajaCta ? cajaCta.querySelector('select[name="cuenta"]') : null;
        if (sel) { sel.required = (v === 'cuenta'); }

        if (btnEnviar) {
          btnEnviar.textContent = v === 'cuenta'
            ? 'Debitar la cuenta del cliente'
            : 'Registrar el cobro';
        }
      };

      selMedio.addEventListener('change', ajustarMedio);
      ajustarMedio();
    }

    f.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = f.querySelector('[type="submit"]');
      // Se guarda el rótulo que tenía: depende del medio elegido,
      // así que reponer uno fijo lo dejaría diciendo otra cosa.
      var textoOriginal = btn.textContent.trim();
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>Procesando…';

      fetch(URL_PAGO, { method:'POST', body:new FormData(f), credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          btn.disabled = false;
          btn.textContent = textoOriginal;
          $('#modal-pago').modal('hide');
          window.avisar(j.ok ? 'Pago registrado' : 'No se pudo pagar', j.mensaje,
                        j.ok ? 'exito' : 'critico');
          if (j.ok) setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1500);
        })
        .catch(function () {
          btn.disabled = false;
          btn.textContent = textoOriginal;
          window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
        });
    });
  }
})();
</script>
