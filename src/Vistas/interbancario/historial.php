<?php
/**
 * El historial de envíos y recepciones por la red de pagos.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $transferencias
 * @var string $csrf
 */

use CashMan\Http\Plantilla as P;

$etiqueta = [
    'transfer365'       => 'Transfer365',
    'transfer365_movil' => 'Transfer365 Móvil',
    'uni'               => 'UNI',
];

/* Cada estado con el color que le corresponde. Una transferencia
   devuelta no es un éxito en gris: es algo que el cliente tiene que
   poder encontrar de un vistazo en una lista larga. */
$color = [
    'creada'    => ['secondary', 'programada'],
    'enviada'   => ['info',      'en camino'],
    'aplicada'  => ['success',   'aplicada'],
    'rechazada' => ['danger',    'rechazada'],
    'devuelta'  => ['danger',    'devuelta'],
];
?>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Transferencias a otros bancos</h4>
        <a class="btn btn-sm btn-primary" href="<?= P::attr($rutas->url('otros-bancos')) ?>">
          Enviar
        </a>
      </div>

      <div class="card-body">
        <?php if ($transferencias === []): ?>
          <p class="text-muted mb-0">Todavía no ha enviado ni recibido por la red de pagos.</p>
        <?php else: ?>

        <div class="table-responsive">
          <table class="table table-sm cm-tabla" id="tabla-inter">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Folio</th>
                <th>Servicio</th>
                <th>Contraparte</th>
                <th class="text-right">Monto</th>
                <th class="text-center">Estado</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($transferencias as $t):
                $e = $color[$t['estado']] ?? ['secondary', $t['estado']];
                $entra = $t['sentido'] === 'recibida';
            ?>
              <tr>
                <td><?= P::e(date('d/m/Y H:i', strtotime((string) $t['creada_en']))) ?></td>
                <td class="cm-mono"><?= P::e($t['folio']) ?></td>
                <td><?= P::e($etiqueta[$t['canal']] ?? $t['canal']) ?></td>
                <td>
                  <?= P::e($t['alias'] ?? $t['titular_contraparte']) ?>
                  <br><small class="text-muted"><?= P::e($t['banco']) ?></small>
                </td>
                <td class="text-right <?= $entra ? 'text-success' : '' ?>">
                  <?= $entra ? '+' : '&minus;' ?>$<?= number_format((int) $t['monto_cent'] / 100, 2) ?>
                </td>
                <td class="text-center">
                  <span class="badge badge-<?= P::attr($e[0]) ?>"><?= P::e($e[1]) ?></span>
                  <?php if ($t['motivo'] !== ''): ?>
                    <br><small class="text-muted"><?= P::e($t['motivo']) ?></small>
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
