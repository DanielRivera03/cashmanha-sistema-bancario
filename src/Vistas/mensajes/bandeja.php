<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $mensajes
 * @var string $vista
 */
use CashMan\Http\Plantilla as P;

$esEntrada = ($vista ?? 'entrada') === 'entrada';
?>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <ul class="nav nav-pills">
          <li class="nav-item">
            <a class="nav-link<?= $esEntrada ? ' active' : '' ?>"
               href="<?= P::attr($rutas->url('mensajes')) ?>">Bandeja de entrada</a>
          </li>
          <li class="nav-item">
            <a class="nav-link<?= $esEntrada ? '' : ' active' ?>"
               href="<?= P::attr($rutas->url('mensajes/enviados')) ?>">Enviados</a>
          </li>
        </ul>
        <a href="<?= P::attr($rutas->url('mensajes/nuevo')) ?>" class="btn btn-sm btn-primary">
          <?= cm_icono('plus', 15, 'mr-1') ?> Redactar
        </a>
      </div>

      <div class="card-body p-0">
        <?php if ($mensajes === []): ?>
          <div class="cm-vacio">
            <?= cm_icono('mail', 40) ?>
            <p class="mb-0 mt-2">
              <?= $esEntrada ? 'No tiene mensajes en su bandeja.' : 'No has enviado mensajes.' ?>
            </p>
          </div>
        <?php else: ?>
          <?php foreach ($mensajes as $m): ?>
          <a href="<?= P::attr($rutas->url('mensajes/' . $m['codigo'])) ?>"
             class="cm-msg<?= ($esEntrada && $m['leido_en'] === null) ? ' no-leido' : '' ?>">
            <div class="cm-avatar" style="width:38px;height:38px;font-size:.78rem">
              <?php
                $p = preg_split('/\s+/', trim((string) $m['remitente'])) ?: [''];
                echo P::e(mb_strtoupper(
                    mb_substr($p[0] ?? '', 0, 1) . mb_substr($p[1] ?? '', 0, 1)
                ));
              ?>
            </div>
            <div style="flex:1;min-width:0">
              <div class="d-flex justify-content-between align-items-start">
                <div class="cm-msg__asunto"><?= P::e($m['asunto']) ?></div>
                <small class="text-muted ml-2" style="white-space:nowrap">
                  <?= P::e(P::hace((string) $m['creado_en'])) ?>
                </small>
              </div>
              <div class="text-muted" style="font-size:.82rem">
                <?= $esEntrada ? 'De' : 'Para' ?>: <?= P::e($m['remitente']) ?>
              </div>
              <div class="cm-msg__extracto"><?= P::e(mb_substr((string) $m['extracto'], 0, 120)) ?>…</div>
            </div>
            <?php if ($esEntrada && $m['leido_en'] === null): ?>
              <span class="badge badge-primary align-self-center">nuevo</span>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
