<?php
/**
 * Los reportes a la UIF.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $reportes
 * @var bool $puedeReportar
 * @var string $csrf
 */

use CashMan\Http\Plantilla as P;

$etiquetaTipo = [
    'rte'  => ['RTE', 'Transacción en efectivo', 'info'],
    'ros'  => ['ROS', 'Operación sospechosa', 'danger'],
    'rtos' => ['RTOS', 'Tentativa de operación sospechosa', 'warning'],
];

$colorEstado = [
    'borrador' => 'warning',
    'listo'    => 'info',
    'enviado'  => 'success',
    'anulado'  => 'secondary',
];
?>
<div class="row">
  <div class="col-12">

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Reportes a la UIF</h4>
        <a class="btn btn-sm btn-outline-secondary"
           href="<?= P::attr($rutas->url('cumplimiento')) ?>">Volver a las alertas</a>
      </div>

      <div class="card-body">

        <div class="alert alert-warning" style="font-size:.88rem">
          <strong>Este sistema no envía nada a la UIF.</strong>
          El envío se hace por los canales de la Fiscalía General de la República, con
          credenciales que un sistema no se da a sí mismo. Lo que hay aquí es el
          expediente armado y el plazo contado.
          <br><br>
          Cuando el reporte se remita de verdad, regístrelo con su acuse: ese acuse es
          la prueba de que la obligación se cumplió.
        </div>

        <?php if ($reportes === []): ?>
          <p class="text-muted mb-0">Todavía no se ha armado ningún reporte.</p>
        <?php else: ?>

        <div class="table-responsive">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Folio</th>
                <th>Tipo</th>
                <th>Cliente</th>
                <th>Periodo</th>
                <th class="text-right">Monto</th>
                <th>Plazo</th>
                <th class="text-center">Estado</th>
                <?php if ($puedeReportar): ?><th></th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($reportes as $r):
                $t    = $etiquetaTipo[$r['tipo']] ?? [strtoupper((string) $r['tipo']), '', 'secondary'];
                $dias = $r['dias_restantes'] === null ? null : (int) $r['dias_restantes'];
            ?>
              <tr>
                <td class="cm-mono"><?= P::e($r['folio']) ?>
                  <?php if ((int) $r['confidencial'] === 1): ?>
                    <br><span class="badge badge-dark" title="No se le comunica al cliente: la ley lo prohíbe">confidencial</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge badge-<?= P::attr($t[2]) ?>"><?= P::e($t[0]) ?></span>
                  <br><small class="text-muted"><?= P::e($t[1]) ?></small>
                </td>
                <td><?= P::e($r['cliente']) ?></td>
                <td style="font-size:.84rem">
                  <?= P::e(date('d/m/Y', strtotime((string) $r['periodo_desde']))) ?>
                  <?php if ($r['periodo_desde'] !== $r['periodo_hasta']): ?>
                    &ndash; <?= P::e(date('d/m/Y', strtotime((string) $r['periodo_hasta']))) ?>
                  <?php endif; ?>
                </td>
                <td class="text-right">
                  <?= (int) $r['monto_cent'] > 0
                      ? '$' . number_format((int) $r['monto_cent'] / 100, 2)
                      : '—' ?>
                </td>
                <td style="font-size:.84rem">
                  <?= P::e(date('d/m/Y', strtotime((string) $r['vence_en']))) ?>
                  <?php if ($r['estado'] === 'borrador' && $dias !== null): ?>
                    <br>
                    <?php if ($dias < 0): ?>
                      <span class="badge badge-danger">vencido hace <?= abs($dias) ?> d</span>
                    <?php elseif ($dias <= 2): ?>
                      <span class="badge badge-warning">quedan <?= $dias ?> d</span>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <span class="badge badge-<?= P::attr($colorEstado[$r['estado']] ?? 'secondary') ?>">
                    <?= P::e($r['estado']) ?>
                  </span>
                  <?php if ($r['acuse'] !== ''): ?>
                    <br><small class="text-muted cm-mono"><?= P::e($r['acuse']) ?></small>
                  <?php endif; ?>
                </td>
                <?php if ($puedeReportar): ?>
                <td class="text-right">
                  <?php if ($r['estado'] === 'borrador'): ?>
                  <button class="btn btn-sm btn-outline-success"
                          data-enviado="<?= P::attr($r['folio']) ?>">
                    Registrar remisión
                  </button>
                  <?php endif; ?>
                </td>
                <?php endif; ?>
              </tr>
              <tr>
                <td colspan="<?= $puedeReportar ? 8 : 7 ?>" class="pt-0">
                  <small class="text-muted"><?= P::e($r['relato']) ?></small>
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

<?php if ($puedeReportar): ?>
<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  /* La semilla: el token con el que se pintó la pantalla.

     NO se usa directamente. Los tokens son de un solo uso, así
     que esta copia se gasta en la primera acción y de la segunda
     en adelante todo fallaría con «el token de seguridad venció».
     El vigente lo mantiene `token.js`. */
  var CSRF_SEMILLA = <?= P::js($csrf) ?>;

  function tokenVigente() {
    return (window.CMToken && window.CMToken.de('cumplimiento')) || CSRF_SEMILLA;
  }

  var BASE = <?= P::js($rutas->url('cumplimiento/reporte/')) ?>;

  document.addEventListener('click', function (ev) {
    var folio = ev.target.getAttribute && ev.target.getAttribute('data-enviado');
    if (!folio) { return; }

    var acuse = window.prompt(
      'Número de acuse que devolvió la UIF.\n\n'
      + 'Es la prueba de que el reporte se presentó: sin él, el registro no demuestra nada.'
    );

    if (acuse === null) { return; }

    if (acuse.trim() === '') {
      window.avisar('Falta el acuse',
        'Sin el acuse, marcar el reporte como enviado no prueba nada.', 'alerta');
      return;
    }

    var fd = new FormData();
    fd.append('_csrf', tokenVigente());
    fd.append('_csrf_proposito', 'cumplimiento');
    fd.append('acuse', acuse);

    fetch(BASE + folio + '/enviado', {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { window.avisar('No se pudo', j.mensaje, 'critico'); return; }

        window.avisar('Registrado', j.mensaje, 'exito');

        if (window.CMVista) { window.CMVista.refrescar(); }
        else { location.reload(); }
      })
      .catch(function () {
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  });
})();
</script>
<?php endif; ?>
