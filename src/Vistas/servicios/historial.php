<?php
/**
 * Los servicios pagados.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $pagos
 * @var string $csrf
 */

use CashMan\Http\Plantilla as P;

$colorEstado = [
    'aplicado'  => ['info',    'aplicado'],
    'liquidado' => ['success', 'liquidado'],
    'reversado' => ['danger',  'reversado'],
];
?>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Servicios pagados</h4>
        <a class="btn btn-sm btn-primary" href="<?= P::attr($rutas->url('servicios')) ?>">
          Pagar un servicio
        </a>
      </div>

      <div class="card-body">
        <?php if ($pagos === []): ?>
          <p class="text-muted mb-0">Todavía no ha pagado ningún servicio.</p>
        <?php else: ?>

        <p class="text-muted" style="font-size:.86rem">
          La <strong>autorización</strong> es el comprobante que devuelve la empresa.
          Es lo que hay que enseñar si alguna vez dicen que no les pagaron.
        </p>

        <div class="table-responsive">
          <table class="table table-sm cm-tabla">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Servicio</th>
                <th>Referencia</th>
                <th>Periodo</th>
                <th class="text-right">Pagado</th>
                <th>Autorización</th>
                <th class="text-center">Estado</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($pagos as $p):
                $e = $colorEstado[$p['estado']] ?? ['secondary', $p['estado']];
                $parcial = (int) $p['monto_cent'] < (int) $p['deuda_cent'];
            ?>
              <tr>
                <td><?= P::e(date('d/m/Y H:i', strtotime((string) $p['creado_en']))) ?></td>
                <td>
                  <?= P::e($p['convenio']) ?>
                  <br><small class="text-muted"><?= P::e($p['titular']) ?></small>
                </td>
                <td class="cm-mono" style="font-size:.84rem">
                  <?= P::e($p['referencia']) ?>
                  <br><small class="text-muted"><?= P::e($p['etiqueta_referencia']) ?></small>
                </td>
                <td><?= P::e($p['periodo'] !== '' ? $p['periodo'] : '—') ?></td>
                <td class="text-right">
                  $<?= number_format((int) $p['monto_cent'] / 100, 2) ?>
                  <?php if ($parcial): ?>
                    <br><small class="text-muted">
                      abono de $<?= number_format((int) $p['deuda_cent'] / 100, 2) ?>
                    </small>
                  <?php endif; ?>
                  <?php if ((int) $p['comision_cent'] > 0): ?>
                    <br><small class="text-muted">
                      + $<?= number_format((int) $p['comision_cent'] / 100, 2) ?> comisión
                    </small>
                  <?php endif; ?>
                </td>
                <td class="cm-mono" style="font-size:.82rem"><?= P::e($p['autorizacion']) ?></td>
                <td class="text-center">
                  <span class="badge badge-<?= P::attr($e[0]) ?>"><?= P::e($e[1]) ?></span>
                  <?php if ($p['motivo'] !== ''): ?>
                    <br><small class="text-muted"><?= P::e($p['motivo']) ?></small>
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
  </div>
</div>
