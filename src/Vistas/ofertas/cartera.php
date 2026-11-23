<?php
/**
 * La campaña, para quien dirige la cartera.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $ofertas
 * @var array  $porEstado
 * @var array  $criterios
 * @var string $filtro
 * @var bool   $puedeGenerar
 * @var bool   $puedeAnular
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$COLOR = [
    'vigente' => 'primary', 'aceptada' => 'success', 'rechazada' => 'secondary',
    'caducada' => 'light',  'anulada'  => 'dark',
];
?>

<div class="row">
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Vigentes</div>
      <div class="cm-kpi__val text-primary"><?= number_format((int) ($porEstado['vigente'] ?? 0)) ?></div>
    </div>
  </div>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Aceptadas</div>
      <div class="cm-kpi__val text-success"><?= number_format((int) ($porEstado['aceptada'] ?? 0)) ?></div>
    </div>
  </div>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Ofrecido vigente</div>
      <div class="cm-kpi__val"><?= P::e(P::money((int) ($porEstado['ofrecido'] ?? 0))) ?></div>
    </div>
  </div>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Colocado</div>
      <div class="cm-kpi__val text-success"><?= P::e(P::money((int) ($porEstado['colocado'] ?? 0))) ?></div>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start flex-wrap">
      <div style="max-width:46rem">
        <h5 class="mb-2">Con qué criterio se ofrece</h5>
        <p class="text-muted mb-0" style="font-size:.86rem">
          Califica el cliente activo que haya pagado al menos
          <strong><?= (int) $criterios['min_cuotas'] ?> cuotas</strong>, ninguna con más de
          <strong><?= (int) $criterios['max_mora'] ?> día(s)</strong> de atraso, que hoy no
          tenga cuotas vencidas ni expediente de cobro abierto. De su capacidad de pago
          libre se ofrece el <strong><?= number_format((float) $criterios['margen'], 0) ?>%</strong>
          —no el total, para no dejarlo en su propio techo—, a
          <strong><?= (int) $criterios['plazo'] ?> meses</strong>, y la oferta vive
          <strong><?= (int) $criterios['vigencia'] ?> días</strong>. Todo son parámetros:
          se cambian sin tocar el sistema.
        </p>
      </div>

      <?php if ($puedeGenerar): ?>
      <form method="post" data-cm-ajax data-cm-recargar
            action="<?= P::attr($rutas->url('ofertas/generar')) ?>">
        <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
        <input type="hidden" name="_csrf_proposito" value="oferta">
        <button type="submit" class="btn btn-primary"
                data-cm-confirmar="Se evaluará la cartera y las ofertas nuevas se enviarán por correo. ¿Continuar?">
          <?= cm_icono('invest', 16, 'mr-1') ?> Evaluar la cartera
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">Ofertas</h4>
  </div>

  <div class="card-body pb-0 pt-2">
    <ul class="nav nav-tabs cm-pestanas" style="border-bottom:1px solid #eaecf0">
      <?php
      $pestanas = ['' => 'Todas', 'vigente' => 'Vigentes', 'aceptada' => 'Aceptadas',
                   'rechazada' => 'Rechazadas', 'caducada' => 'Caducadas', 'anulada' => 'Anuladas'];
      foreach ($pestanas as $clave => $etiqueta):
        $n = $clave === '' ? array_sum(array_map('intval', array_slice($porEstado, 0, 5)))
                           : (int) ($porEstado[$clave] ?? 0);
        if ($clave !== '' && $n === 0 && $filtro !== $clave) { continue; }
        $activa = $filtro === $clave;
      ?>
      <li class="nav-item">
        <a class="nav-link <?= $activa ? 'active' : '' ?>"
           href="<?= P::attr($rutas->url('ofertas/cartera') . ($clave === '' ? '' : '?e=' . $clave)) ?>">
          <?= P::e($etiqueta) ?>
          <span class="badge badge-<?= $activa ? 'primary' : 'light' ?> ml-1"><?= $n ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="card-body p-0">
    <?php if ($ofertas === []): ?>
      <div class="cm-vacio">
        <p class="mb-0">No hay ofertas<?= $filtro !== '' ? ' en este estado' : '' ?>.</p>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0" data-cm-tabla>
        <thead>
          <tr>
            <th>Oferta</th><th>Cliente</th><th>Producto</th>
            <th class="cm-num">Importe</th><th class="cm-num">Cuota</th>
            <th>Criterio</th><th>Vence</th><th>Estado</th>
            <?= P::crudo(cm_th_acciones([
                  ['Anular', '#', 'warning', 'permiso' => 'oferta.anular'],
                ])) ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($ofertas as $o): ?>
          <tr>
            <td class="cm-mono cm-nowrap" style="font-size:.78rem"><?= P::e($o['folio']) ?></td>
            <td style="font-size:.85rem">
              <?= P::e($o['cliente']) ?>
              <small class="text-muted d-block" style="font-size:.72rem"><?= P::e($o['usuario']) ?></small>
            </td>
            <td class="text-muted" style="font-size:.84rem">
              <?= P::e(match ($o['tipo']) {
                    'credito'             => 'Crédito personal',
                    'extrafinanciamiento' => 'Efectivo sobre tarjeta',
                    default               => 'Tarjeta de crédito',
                  }) ?>
              <?php if ($o['plazo_meses'] > 0): ?>
                <small class="d-block text-muted" style="font-size:.72rem">
                  <?= (int) $o['plazo_meses'] ?> meses &middot;
                  <?= number_format((int) $o['tasa_bp'] / 100, 2) ?>%
                </small>
              <?php endif; ?>
            </td>
            <td class="cm-num font-weight-bold"><?= P::e(P::money((int) $o['monto_cent'])) ?></td>
            <td class="cm-num text-muted">
              <?= (int) $o['cuota_cent'] > 0 ? P::e(P::money((int) $o['cuota_cent'])) : '—' ?>
            </td>
            <td style="font-size:.76rem" class="text-muted">
              <?= (int) ($o['criterios']['cuotas_pagadas'] ?? 0) ?> cuotas
              <small class="d-block">atraso máx. <?= (int) ($o['criterios']['atraso_maximo_dias'] ?? 0) ?>d</small>
            </td>
            <td class="text-muted cm-nowrap" style="font-size:.8rem">
              <?= P::e(P::fecha((string) $o['vence_el'], 'd/m/Y')) ?>
            </td>
            <td>
              <span class="badge badge-<?= P::attr($COLOR[$o['estado']] ?? 'light') ?>">
                <?= P::e(ucfirst((string) $o['estado'])) ?>
              </span>
              <?php if ($o['credito_folio'] !== null): ?>
                <small class="d-block text-muted cm-mono" style="font-size:.68rem">
                  <?= P::e($o['credito_folio']) ?>
                </small>
              <?php elseif ($o['ultimos4'] !== null): ?>
                <small class="d-block text-muted cm-mono" style="font-size:.68rem">
                  &bull;&bull;&bull;&bull;<?= P::e($o['ultimos4']) ?>
                </small>
              <?php endif; ?>
            </td>
            <td class="text-right">
              <?= P::crudo(cm_acciones([
                    ['Ver al cliente',
                     $rutas->url('clientes/' . \CashMan\Infraestructura\Seguridad\Hashid::codificar(
                         (int) $o['usuario_id'], 'usuario')),
                     'search', 'permiso' => 'cliente.ver_expediente'],

                    ['Anular la oferta',
                     $rutas->url('ofertas/' . $o['codigo'] . '/anular'),
                     'warning', 'estilo' => 'warning',
                     'metodo' => 'post', 'csrf' => $csrf, 'proposito' => 'oferta',
                     'permiso' => 'oferta.anular',
                     'si' => $o['estado'] === 'vigente',
                     'campos' => ['motivo' => 'Retirada por la institución'],
                     'confirmar' => 'La oferta dejará de estar disponible para el cliente. ¿Anularla?'],
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
