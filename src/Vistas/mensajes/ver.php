<?php
/** @var array $mensaje */
use CashMan\Http\Plantilla as P;
?>
<div class="row justify-content-center">
  <div class="col-lg-9 col-md-12">
    <div class="card">
      <div class="card-header">
        <div style="flex:1;min-width:0">
          <h4 class="card-title mb-1"><?= P::e($mensaje['asunto']) ?></h4>
          <small class="text-muted">
            De <strong><?= P::e($mensaje['remitente']) ?></strong>
            para <strong><?= P::e($mensaje['destinatario']) ?></strong>
            &middot; <?= P::e(P::fecha((string) $mensaje['creado_en'])) ?>
          </small>
        </div>
      </div>

      <div class="card-body">
        <div style="white-space:pre-wrap;line-height:1.65"><?= P::e($mensaje['cuerpo']) ?></div>
      </div>

      <div class="card-footer d-flex" style="gap:.5rem">
        <a href="<?= P::attr($rutas->url('mensajes/nuevo')) ?>?responde=<?= P::attr($mensaje['codigo']) ?>"
           class="btn btn-sm btn-primary">Responder</a>
        <form data-cm-ajax method="post"
              action="<?= P::attr($rutas->url('mensajes/' . $mensaje['codigo'] . '/archivar')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="mensaje">
          <button class="btn btn-sm btn-outline-secondary">Archivar</button>
        </form>
        <a href="<?= P::attr($rutas->url('mensajes')) ?>" class="btn btn-sm btn-outline-secondary ml-auto">
          Volver
        </a>
      </div>
    </div>
  </div>
</div>
