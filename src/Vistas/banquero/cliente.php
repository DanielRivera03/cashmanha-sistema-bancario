<?php

/**
 * Un cliente de la cartera, visto por su banquero.
 *
 * Las gestiones que salen aquí son LAS MISMAS que el cliente ve en su
 * «Soporte»: el mismo hilo, el mismo objeto. Ese era el encargo —que
 * los dos miren lo mismo— y por eso el enlace lleva a la misma
 * pantalla de la gestión, no a una versión para empleados.
 *
 * La ficha de abajo es la de ventanilla, reutilizada entera. Tener dos
 * versiones de «los datos del cliente» acabaría con una diciendo algo
 * distinto de la otra.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $ficha
 * @var list<array<string,mixed>> $gestiones
 */

use CashMan\Http\Plantilla as P;

$COLOR_EST = [
  'abierto' => 'primary', 'en_proceso' => 'warning', 'resuelto' => 'success',
  'no_resuelto' => 'danger', 'cerrado' => 'secondary',
];
?>

<div class="mb-3">
  <a href="<?= P::attr($rutas->url('cartera')) ?>" class="btn btn-sm btn-outline-secondary">
    &larr; Volver a mi cartera
  </a>
</div>

<div class="card cm-ficha-cliente">
  <div class="card-body">
    <div class="d-flex align-items-start flex-wrap" style="gap:1rem">
      <span class="cm-avatar cm-avatar--grande">
        <?= P::e(mb_strtoupper(mb_substr((string) $ficha['nombre'], 0, 2))) ?>
      </span>

      <div style="flex:1;min-width:14rem">
        <h4 class="mb-1" style="font-size:1.15rem"><?= P::e((string) $ficha['nombre']) ?></h4>
        <div class="cm-ficha-cliente__datos">
          <?php if ($ficha['documento'] !== ''): ?>
            <span><?= cm_icono('user', 14) ?> <?= P::e((string) $ficha['documento']) ?></span>
          <?php endif; ?>
          <?php if ($ficha['telefono'] !== ''): ?>
            <span><?= cm_icono('device', 14) ?> <?= P::e((string) $ficha['telefono']) ?></span>
          <?php endif; ?>
          <span><?= cm_icono('mail', 14) ?> <?= P::e((string) $ficha['correo']) ?></span>
        </div>
      </div>

      <div class="text-right">
        <span class="badge badge-primary mb-1"><?= P::e(ucfirst((string) $ficha['segmento'])) ?></span><br>
        <small class="text-muted d-block">En depósitos</small>
        <strong style="font-size:1.1rem">
          <?= P::e(P::money((int) $ficha['totales']['depositos_cent'])) ?>
        </strong>
      </div>
    </div>

    <?php if ($ficha['avisos'] !== []): ?>
    <div class="cm-avisos-ventanilla mt-3">
      <?php foreach ($ficha['avisos'] as $a): ?>
      <div class="cm-avisos-ventanilla__item is-<?= P::attr((string) $a['nivel']) ?>">
        <?= cm_icono($a['nivel'] === 'alto' ? 'warning' : 'info', 15) ?>
        <span><?= P::e((string) $a['texto']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="mt-3">
      <a class="btn btn-sm btn-primary"
         href="<?= P::attr($rutas->url('mensajes/nuevo')) ?>">
        <?= cm_icono('mail', 14, 'mr-1') ?> Escribirle
      </a>
      <a class="btn btn-sm btn-outline-secondary"
         href="<?= P::attr($rutas->url('preferente') . '?cliente=' . $ficha['codigo']) ?>">
        Ver sus condiciones
      </a>
    </div>
  </div>
</div>

<?php /* ---------- Las gestiones ---------- */ ?>
<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">
      Sus gestiones
      <span class="badge badge-light ml-1"><?= count($gestiones) ?></span>
    </h4>
    <small class="text-muted">
      Las mismas que él ve en su banca. Abrir una lleva al mismo hilo.
    </small>
  </div>
  <div class="card-body p-0">
    <?php if ($gestiones === []): ?>
      <div class="cm-vacio"><p class="mb-0">No ha abierto ninguna gestión.</p></div>
    <?php else: ?>
    <?php foreach ($gestiones as $g): ?>
    <a class="cm-cartera-fila"
       href="<?= P::attr($rutas->url('tickets/' . $g['codigo'])) ?>">

      <span class="cm-cartera-fila__quien" style="flex:1">
        <strong><?= P::e((string) $g['titulo']) ?></strong>
        <small>
          <span class="cm-mono"><?= P::e((string) $g['folio']) ?></span>
          · abierta <?= P::e(P::hace((string) $g['creado_en'])) ?>
          <?php if ((int) $g['respuestas'] > 0): ?>
            · <?= (int) $g['respuestas'] ?> respuesta(s)
          <?php endif; ?>
          <?php if (!empty($g['via']) && $g['via'] !== 'general'): ?>
            · <?= $g['via'] === 'banquero' ? 'asignada a usted' : 'prioritaria' ?>
          <?php endif; ?>
        </small>
      </span>

      <span class="cm-cartera-fila__senales">
        <?php if (!empty($g['espera_respuesta'])): ?>
          <span class="cm-senal is-urgente">esperando su respuesta</span>
        <?php endif; ?>
        <span class="badge badge-<?= P::attr($COLOR_EST[$g['estado']] ?? 'light') ?>">
          <?= P::e(str_replace('_', ' ', (string) $g['estado'])) ?>
        </span>
      </span>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php /* ---------- Lo que tiene ---------- */ ?>
<div class="row">
  <div class="col-xl-6">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title mb-0">Cuentas y tarjetas</h4>
      </div>
      <div class="card-body p-0">
        <?php foreach ($ficha['cuentas'] as $c): ?>
        <div class="cm-linea-ventanilla">
          <div style="flex:1;min-width:0">
            <strong class="cm-mono"><?= P::e((string) $c['numero_cuenta']) ?></strong>
            <small class="d-block text-muted"><?= P::e((string) $c['producto']) ?></small>
          </div>
          <strong><?= P::e(P::money((int) $c['saldo_cent'])) ?></strong>
        </div>
        <?php endforeach; ?>

        <?php foreach ($ficha['tarjetas'] as $t): ?>
        <div class="cm-linea-ventanilla">
          <div style="flex:1;min-width:0">
            <strong>···· <?= P::e((string) $t['ultimos4']) ?></strong>
            <small class="d-block text-muted">
              <?= P::e(ucfirst((string) $t['marca'])) ?> ·
              <?= P::e((string) ($t['producto'] ?: ucfirst((string) $t['tipo']))) ?>
            </small>
          </div>
          <?php if ((string) $t['tipo'] === 'credito'): ?>
          <div class="text-right">
            <strong><?= P::e(P::money((int) $t['saldo_usado_cent'])) ?></strong>
            <small class="d-block text-muted">
              de <?= P::e(P::money((int) $t['limite_cent'])) ?>
            </small>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-xl-6">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Créditos</h4></div>
      <div class="card-body p-0">
        <?php if ($ficha['creditos'] === []): ?>
          <div class="cm-vacio"><p class="mb-0">Sin créditos vigentes.</p></div>
        <?php else: ?>
        <?php foreach ($ficha['creditos'] as $cr): ?>
        <div class="cm-linea-ventanilla">
          <div style="flex:1;min-width:0">
            <strong><?= P::e((string) $cr['producto']) ?></strong>
            <small class="d-block text-muted cm-mono"><?= P::e((string) $cr['folio']) ?></small>
          </div>
          <div class="text-right">
            <?php if ((int) $cr['vencido_cent'] > 0): ?>
              <strong class="text-danger"><?= P::e(P::money((int) $cr['vencido_cent'])) ?></strong>
              <small class="d-block text-danger"><?= (int) $cr['vencidas'] ?> vencida(s)</small>
            <?php else: ?>
              <strong><?= P::e(P::money((int) $cr['cuota_cent'])) ?></strong>
              <small class="d-block text-muted">al día</small>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
