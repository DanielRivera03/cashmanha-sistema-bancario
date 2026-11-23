<?php
use CashMan\Http\Plantilla as P;
$ok = (bool)$resultado['valida'];
?>
<div class="row justify-content-center">
  <div class="col-lg-8">
    <div class="card border-<?= $ok?'success':'danger' ?>">
      <div class="card-body text-center py-5">
        <div class="mb-3 text-<?= $ok?'success':'danger' ?>">
          <?= cm_icono($ok?'check':'warning', 56) ?>
        </div>
        <h3 class="mb-2"><?= $ok ? 'Cadena íntegra' : 'Cadena comprometida' ?></h3>
        <p class="text-muted mb-4">
          <?php if ($ok): ?>
            Se verificaron <strong><?= number_format((int)$resultado['revisados']) ?></strong>
            registros y todos los hashes encadenan correctamente.
            Ningún evento fue alterado ni borrado.
          <?php else: ?>
            La verificación falló en el registro
            <strong>#<?= (int)($resultado['primera_rota'] ?? 0) ?></strong>.
            Alguien modificó o eliminó datos directamente en la base.
          <?php endif; ?>
        </p>

        <div class="row text-center">
          <div class="col-4">
            <small class="text-muted d-block">Registros</small>
            <h5 class="mb-0"><?= number_format((int)($totales['registros'] ?? 0)) ?></h5>
          </div>
          <div class="col-4">
            <small class="text-muted d-block">Desde</small>
            <h6 class="mb-0"><?= P::e(P::fecha((string)($totales['desde'] ?? ''), 'd/m/Y')) ?></h6>
          </div>
          <div class="col-4">
            <small class="text-muted d-block">Hasta</small>
            <h6 class="mb-0"><?= P::e(P::fecha((string)($totales['hasta'] ?? ''), 'd/m/Y')) ?></h6>
          </div>
        </div>
      </div>
    </div>

    <div class="alert alert-light border" style="font-size:.86rem">
      <strong>Cómo funciona.</strong> Cada evento guarda el hash SHA-256 del evento
      anterior junto con su propio contenido. Para alterar un registro sin que se
      note habría que recalcular todos los hashes posteriores — y aun así la
      verificación contra la semilla inicial fallaría.
    </div>

    <a href="<?= P::attr($rutas->url('auditoria')) ?>" class="btn btn-outline-secondary">
      Volver a la bitácora
    </a>
  </div>
</div>
