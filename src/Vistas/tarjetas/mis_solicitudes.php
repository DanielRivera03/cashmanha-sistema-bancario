<?php

/**
 * Las solicitudes de tarjeta del cliente, con su recorrido.
 *
 * El cliente solo veía la que tenía en trámite, y solo desde el
 * catálogo. Las anteriores —las que le aprobaron, las que no— no
 * estaban en ninguna parte: una vez resuelta, la solicitud desaparecía
 * de su vista. Si quería saber qué le dijeron cuando le negaron una
 * línea hace tres meses, tenía que llamar.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var list<array<string,mixed>> $solicitudes
 */

use CashMan\Http\Plantilla as P;
?>

<?php if ($solicitudes === []): ?>
<div class="card">
  <div class="card-body">
    <div class="cm-vacio">
      <p class="mb-2">Todavía no ha solicitado ninguna tarjeta.</p>
      <a class="btn btn-primary btn-sm" href="<?= P::attr($rutas->url('tarjetas/catalogo')) ?>">
        Ver el catálogo
      </a>
    </div>
  </div>
</div>
<?php else: ?>

<?php foreach ($solicitudes as $s): ?>
<div class="card">
  <div class="card-body">

    <div class="d-flex justify-content-between align-items-start flex-wrap" style="gap:1rem">
      <div style="min-width:0">
        <h5 class="mb-1" style="font-size:1rem">
          <?= P::e((string) $s['producto']) ?>
        </h5>
        <p class="text-muted mb-0" style="font-size:.82rem">
          <span class="cm-mono"><?= P::e((string) $s['folio']) ?></span> ·
          solicitada el <?= P::e(P::fecha((string) $s['creado_en'], 'd/m/Y')) ?>
        </p>
      </div>

      <span class="badge badge-<?= P::attr((string) $s['color']) ?>" style="font-size:.78rem">
        <?= P::e((string) $s['estado']) ?>
      </span>
    </div>

    <?php /* Lo que se pidió y lo que se aprobó, cuando ya se sabe.
             Que no coincidan es normal —el límite se ajusta a la
             capacidad de pago— y verlo evita la llamada preguntando
             por qué le dieron menos de lo que pidió. */ ?>
    <div class="cm-fiesta__datos mt-3 mb-0">
      <div class="cm-fiesta__dato">
        <small>Límite solicitado</small>
        <strong><?= P::e(P::money((int) $s['limite_solicitado_cent'])) ?></strong>
      </div>
      <?php if ($s['limite_aprobado_cent'] !== null): ?>
      <div class="cm-fiesta__dato">
        <small>Límite aprobado</small>
        <strong><?= P::e(P::money((int) $s['limite_aprobado_cent'])) ?></strong>
      </div>
      <?php endif; ?>
      <?php if (!empty($s['tarjeta_codigo'])): ?>
      <div class="cm-fiesta__dato">
        <small>Su tarjeta</small>
        <strong>
          <a href="<?= P::attr($rutas->url('tarjetas/' . $s['tarjeta_codigo'])) ?>">
            ···· <?= P::e((string) $s['tarjeta_ultimos4']) ?>
          </a>
        </strong>
      </div>
      <?php endif; ?>
    </div>

    <?php
      $sol       = $s;
      $historial = $s['historial'];
      require __DIR__ . '/_recorrido.php';
    ?>

  </div>
</div>
<?php endforeach; ?>

<?php endif; ?>
