<?php
/**
 * @var array $estado
 * @var array|null $cuenta
 * @var array $movimientos
 */
use CashMan\Http\Plantilla as P;
?>
<div class="card">
  <div class="card-body">

    <div class="d-flex justify-content-between flex-wrap mb-4">
      <div>
        <h3 class="mb-1">Estado de cuenta</h3>
        <p class="text-muted mb-0">
          Periodo del <?= P::e(P::fecha((string) $estado['periodo_inicio'], 'd/m/Y')) ?>
          al <?= P::e(P::fecha((string) $estado['periodo_fin'], 'd/m/Y')) ?>
        </p>
      </div>
      <div class="text-right">
        <div class="font-weight-bold">CashMan H.A. S.A. de C.V.</div>
        <small class="text-muted">Generado el <?= P::e(P::fecha((string) $estado['generado_en'])) ?></small>
      </div>
    </div>

    <?php if ($cuenta !== null): ?>
    <div class="row border-top border-bottom py-3 mb-4">
      <div class="col-md-6">
        <small class="text-muted text-uppercase" style="letter-spacing:.06em">Titular</small>
        <div class="font-weight-bold"><?= P::e(trim($cuenta['nombres'] . ' ' . $cuenta['apellidos'])) ?></div>
        <div class="text-muted"><?= P::e($cuenta['correo']) ?></div>
      </div>
      <div class="col-md-6">
        <small class="text-muted text-uppercase" style="letter-spacing:.06em">Cuenta</small>
        <div class="font-weight-bold cm-mono"><?= P::e($cuenta['numero_cuenta']) ?></div>
        <div class="text-muted"><?= P::e($cuenta['producto']) ?> &middot; <?= P::e($cuenta['moneda']) ?></div>
      </div>
    </div>
    <?php endif; ?>

    <div class="row mb-4">
      <div class="col-md-3 col-6 mb-3">
        <div class="cm-kpi">
          <div class="cm-kpi__et">Saldo inicial</div>
          <div class="cm-kpi__val" style="font-size:1.2rem"><?= P::e(P::money((int) $estado['saldo_inicial_cent'])) ?></div>
        </div>
      </div>
      <div class="col-md-3 col-6 mb-3">
        <div class="cm-kpi">
          <div class="cm-kpi__et">Cargos</div>
          <div class="cm-kpi__val cm-neg" style="font-size:1.2rem"><?= P::e(P::money((int) $estado['total_cargos_cent'])) ?></div>
        </div>
      </div>
      <div class="col-md-3 col-6 mb-3">
        <div class="cm-kpi">
          <div class="cm-kpi__et">Abonos</div>
          <div class="cm-kpi__val cm-pos" style="font-size:1.2rem"><?= P::e(P::money((int) $estado['total_abonos_cent'])) ?></div>
        </div>
      </div>
      <div class="col-md-3 col-6 mb-3">
        <div class="cm-kpi">
          <div class="cm-kpi__et">Saldo final</div>
          <div class="cm-kpi__val" style="font-size:1.2rem"><?= P::e(P::money((int) $estado['saldo_final_cent'])) ?></div>
        </div>
      </div>
    </div>

    <h5 class="mb-3">Detalle de movimientos</h5>

    <?php if ($movimientos === []): ?>
      <div class="cm-vacio"><p class="mb-0">Sin movimientos en este periodo.</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover">
        <thead>
          <tr><th>Fecha</th><th>Concepto</th><th>Referencia</th>
              <th class="cm-num">Importe</th><th class="cm-num">Saldo</th></tr>
        </thead>
        <tbody>
        <?php foreach ($movimientos as $m): $i = (int) $m['importe_cent']; ?>
          <tr>
            <td class="text-muted"><?= P::e(P::fecha((string) $m['creado_en'], 'd/m/Y H:i')) ?></td>
            <td><?= P::e($m['descripcion'] ?: ucfirst((string) $m['tipo'])) ?></td>
            <td class="cm-mono text-muted"><?= P::e($m['referencia']) ?></td>
            <td class="cm-num <?= $i >= 0 ? 'cm-pos' : 'cm-neg' ?>">
              <?= $i >= 0 ? '+' : '' ?><?= P::e(P::money($i)) ?>
            </td>
            <td class="cm-num text-muted"><?= P::e(P::money((int) $m['saldo_posterior_cent'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <div class="border-top pt-3 mt-4">
      <small class="text-muted d-block font-weight-bold">Verificación de integridad</small>
      <small class="cm-mono text-muted" style="word-break:break-all">
        SHA-256: <?= P::e($estado['hash_documento'] ?? 'no disponible') ?>
      </small>
      <p class="text-muted mt-2 mb-0" style="font-size:.82rem">
        Este código identifica el contenido exacto del estado. Si el documento se altera,
        el código deja de coincidir.
      </p>
    </div>

    <div class="mt-3">
      <a href="<?= P::attr($rutas->url("estados/" . $estado["codigo"] . "/pdf")) ?>"
         target="_blank" rel="noopener"
         data-cm-doc data-cm-doc-titulo="Preparando el estado de cuenta"
         class="btn btn-primary btn-sm">Descargar PDF</a>
      <button data-cm-imprimir class="btn btn-outline-primary btn-sm">
        Imprimir o guardar en PDF
      </button>
    </div>

  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
  if (new URLSearchParams(location.search).get('imprimir') === '1') {
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });
  }
</script>
