<?php
/**
 * Detalle de un turno de caja, con su arqueo.
 *
 * Es el documento que se mira cuando una caja no cuadró. Por eso lleva
 * el desglose por denominación tal como se contó: con el total solo no
 * se encuentra nunca de dónde salió la diferencia.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $sesion
 * @var array  $movimientos
 * @var array  $arqueo
 * @var array  $resumen
 * @var bool   $puedeArquear
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$dif = $sesion['diferencia_cent'] === null ? null : (int) $sesion['diferencia_cent'];

$etiquetaTipo = static fn(string $t): string => match ($t) {
    'fondo_inicial'  => 'Fondo inicial',
    'deposito'       => 'Depósito recibido',
    'retiro'         => 'Retiro entregado',
    'pago_credito'   => 'Pago de cuota',
    'pago_tarjeta'   => 'Pago de tarjeta',
    'nota_abono'     => 'Nota de abono',
    'nota_cargo'     => 'Nota de cargo',
    'remesa_entrada' => 'Remesa recibida',
    'remesa_salida'  => 'Remesa a bóveda',
    default          => ucfirst(str_replace('_', ' ', $t)),
};
?>

<div class="row">
  <div class="col-lg-4 mb-4">
    <div class="card h-100 mb-0">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0"><?= P::e($sesion['folio']) ?></h4>
        <span class="badge badge-<?= $sesion['estado'] === 'cuadrada' ? 'success'
              : ($sesion['estado'] === 'descuadrada' ? 'danger' : 'primary') ?>">
          <?= P::e($sesion['estado']) ?>
        </span>
      </div>
      <div class="card-body">
        <div class="row">
          <div class="col-6 mb-3">
            <small class="text-muted d-block">Cajero</small>
            <span class="font-weight-bold"><?= P::e($sesion['cajero']) ?></span>
          </div>
          <div class="col-6 mb-3">
            <small class="text-muted d-block">Ventanilla</small>
            <span class="font-weight-bold"><?= P::e($sesion['caja_nombre']) ?></span>
          </div>
          <div class="col-6 mb-3">
            <small class="text-muted d-block">Abierto</small>
            <span><?= P::e(P::fecha((string) $sesion['abierta_en'])) ?></span>
          </div>
          <div class="col-6 mb-3">
            <small class="text-muted d-block">Cerrado</small>
            <span><?= $sesion['cerrada_en'] !== null
                      ? P::e(P::fecha((string) $sesion['cerrada_en'])) : '—' ?></span>
          </div>
        </div>

        <hr>

        <div class="row">
          <div class="col-6 mb-2">
            <small class="text-muted d-block">Fondo inicial</small>
            <b><?= P::e(P::money((int) $sesion['fondo_inicial_cent'])) ?></b>
          </div>
          <div class="col-6 mb-2">
            <small class="text-muted d-block">Operaciones</small>
            <b><?= (int) ($resumen['operaciones'] ?? 0) ?></b>
          </div>
          <div class="col-6 mb-2">
            <small class="text-muted d-block">Esperado</small>
            <b class="cm-mono"><?= P::e(P::money((int) $sesion['esperado_cent'])) ?></b>
          </div>
          <div class="col-6 mb-2">
            <small class="text-muted d-block">Contado</small>
            <b class="cm-mono"><?= $sesion['contado_cent'] === null ? '—'
                : P::e(P::money((int) $sesion['contado_cent'])) ?></b>
          </div>
        </div>

        <?php if ($dif !== null): ?>
        <div class="cm-dif <?= $dif === 0 ? 'cm-dif--ok' : ($dif > 0 ? 'cm-dif--sobra' : 'cm-dif--falta') ?>">
          <small class="d-block">Diferencia</small>
          <b style="font-size:1.4rem">
            <?= $dif > 0 ? '+' : '' ?><?= P::e(P::money($dif)) ?>
          </b>
          <small class="d-block">
            <?= $dif === 0 ? 'Cuadró exacto' : ($dif > 0 ? 'Sobrante' : 'Faltante') ?>
          </small>
        </div>
        <?php endif; ?>

        <?php if ($sesion['arqueada_en'] !== null): ?>
        <div class="alert alert-light border mt-3 mb-0 py-2" style="font-size:.8rem">
          Arqueado por <strong><?= P::e($sesion['arqueador'] ?? '') ?></strong>
          el <?= P::e(P::fecha((string) $sesion['arqueada_en'])) ?>.
        </div>
        <?php endif; ?>

        <?php if (!empty($sesion['observaciones'])): ?>
        <div class="mt-3">
          <small class="text-muted d-block mb-1">Observaciones</small>
          <div class="cm-nota" style="white-space:pre-wrap;font-size:.82rem"><?= P::e($sesion['observaciones']) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($puedeArquear): ?>
        <hr>
        <form method="post" data-cm-ajax data-cm-recargar
              action="<?= P::attr($rutas->url('caja/' . $sesion['codigo'] . '/arquear')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="caja_turno">
          <div class="form-group">
            <label style="font-size:.84rem">Observaciones del arqueo</label>
            <textarea class="form-control form-control-sm" name="observaciones" rows="2"
                      placeholder="Lo que convenga dejar dicho de la revisión"></textarea>
          </div>
          <button class="btn btn-primary btn-block"
                  data-cm-confirmar="Dará por revisado el turno <?= P::attr($sesion['folio']) ?>. ¿Continuar?">
            Dar por arqueado
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8 mb-4">
    <?php if ($arqueo !== []): ?>
    <div class="card mb-4">
      <div class="card-header">
        <h4 class="card-title mb-0">Arqueo: efectivo contado</h4>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead>
              <tr><th>Denominación</th><th class="cm-num">Piezas</th><th class="cm-num">Subtotal</th></tr>
            </thead>
            <tbody>
            <?php $tot = 0; foreach ($arqueo as $a):
              $sub = (int) $a['denominacion_cent'] * (int) $a['cantidad'];
              $tot += $sub;
            ?>
              <tr>
                <td class="cm-mono"><?= P::e(P::money((int) $a['denominacion_cent'])) ?></td>
                <td class="cm-num"><?= (int) $a['cantidad'] ?></td>
                <td class="cm-num cm-mono"><?= P::e(P::money($sub)) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr class="font-weight-bold">
                <td>Total contado</td><td></td>
                <td class="cm-num cm-mono"><?= P::e(P::money($tot)) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="card mb-0">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Movimientos del turno</h4>
        <span class="badge badge-light"><?= count($movimientos) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($movimientos === []): ?>
          <div class="cm-vacio"><p class="mb-0">Sin movimientos.</p></div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr><th>Operación</th><th>Cliente</th><th>Concepto</th>
                  <th>Hora</th><th class="cm-num">Efectivo</th></tr>
            </thead>
            <tbody>
            <?php foreach ($movimientos as $m): $i = (int) $m['importe_cent']; ?>
              <tr>
                <td style="font-size:.82rem"><?= P::e($etiquetaTipo((string) $m['tipo'])) ?></td>
                <td style="font-size:.82rem"><?= P::e($m['cliente'] ?? '—') ?></td>
                <td class="text-muted" style="font-size:.8rem"><?= P::e($m['concepto']) ?></td>
                <td class="text-muted" style="font-size:.78rem"><?= P::e(P::fecha((string) $m['creado_en'], 'H:i')) ?></td>
                <td class="cm-num <?= $i >= 0 ? 'cm-pos' : 'cm-neg' ?>">
                  <?= $i >= 0 ? '+' : '' ?><?= P::e(P::money($i)) ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
