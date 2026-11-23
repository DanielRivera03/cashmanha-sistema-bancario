<?php

/**
 * La cartera del banquero.
 *
 * Ordenada por lo que reclama atención, no por apellido. Un listado
 * alfabético de cuarenta clientes no dice a quién atender primero y
 * obliga a entrar en cada uno para descubrirlo.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var list<array<string,mixed>> $clientes
 * @var array<string,int> $resumen
 */

use CashMan\Http\Plantilla as P;
?>

<?php if ($clientes === []): ?>
<div class="card">
  <div class="card-body">
    <div class="cm-vacio">
      <p class="mb-1">Todavía no tiene clientes asignados.</p>
      <small class="text-muted">
        La cartera se reparte automáticamente entre los banqueros según su carga.
      </small>
    </div>
  </div>
</div>
<?php else: ?>

<div class="row">
  <?php
    $tarjetas = [
      ['Clientes',          (string) $resumen['clientes'],      'users',   'En su cartera'],
      ['Esperan respuesta', (string) $resumen['sin_contestar'], 'mail',    'Nadie les ha contestado'],
      ['Gestiones abiertas',(string) $resumen['gestiones'],     'support', 'En curso'],
      ['Con mora',          (string) $resumen['con_mora'],      'warning', 'Clientes con cuotas vencidas'],
    ];
  ?>
  <?php foreach ($tarjetas as [$et, $val, $ico, $pie]): ?>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="card h-100">
      <div class="card-body cm-kpi d-flex align-items-center">
        <div class="cm-kpi__icono mr-3"><?= cm_icono($ico, 20) ?></div>
        <div class="flex-grow-1">
          <div class="cm-kpi__et"><?= P::e($et) ?></div>
          <div class="cm-kpi__val"><?= P::e($val) ?></div>
          <div class="cm-kpi__pie"><?= P::e($pie) ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">Sus clientes</h4>
    <small class="text-muted">
      Ordenados por lo que reclama su atención, no por apellido.
    </small>
  </div>
  <div class="card-body p-0">
    <?php foreach ($clientes as $c): ?>
    <a class="cm-cartera-fila"
       href="<?= P::attr($rutas->url('cartera/' . $c['codigo'])) ?>">

      <span class="cm-avatar">
        <?= P::e(mb_strtoupper(mb_substr((string) $c['nombre'], 0, 2))) ?>
      </span>

      <span class="cm-cartera-fila__quien">
        <strong><?= P::e((string) $c['nombre']) ?></strong>
        <small>
          <?= P::e(ucfirst((string) $c['segmento'])) ?>
          <?php if ($c['telefono'] !== ''): ?>
            · <?= P::e((string) $c['telefono']) ?>
          <?php endif; ?>
        </small>
      </span>

      <?php /* Las señales, en orden de lo que de verdad pesa. Una
               gestión sin contestar es alguien esperando a que le
               respondan, y eso es justo lo que esta persona tiene
               asignado; la mora la trabaja cobranza aparte. */ ?>
      <span class="cm-cartera-fila__senales">
        <?php if ((int) $c['sin_contestar'] > 0): ?>
          <span class="cm-senal is-urgente">
            <?= (int) $c['sin_contestar'] ?> sin contestar
          </span>
        <?php elseif ((int) $c['gestiones'] > 0): ?>
          <span class="cm-senal">
            <?= (int) $c['gestiones'] ?> gestión(es)
          </span>
        <?php endif; ?>

        <?php if ((int) $c['solicitudes'] > 0): ?>
          <span class="cm-senal is-aviso">
            <?= (int) $c['solicitudes'] ?> solicitud(es)
          </span>
        <?php endif; ?>

        <?php if ((int) $c['cuotas_vencidas'] > 0): ?>
          <span class="cm-senal is-mora">
            <?= (int) $c['cuotas_vencidas'] ?> vencida(s)
            · <?= P::e(P::money((int) $c['vencido_cent'])) ?>
          </span>
        <?php endif; ?>

        <?php if ((int) $c['sin_contestar'] === 0 && (int) $c['gestiones'] === 0
                  && (int) $c['solicitudes'] === 0 && (int) $c['cuotas_vencidas'] === 0): ?>
          <span class="cm-senal is-tranquilo">al día</span>
        <?php endif; ?>
      </span>

      <span class="cm-cartera-fila__saldo">
        <small>En depósitos</small>
        <strong><?= P::e(P::money((int) $c['depositos_cent'])) ?></strong>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
  <div class="card-footer text-muted small">
    Total en depósitos de la cartera:
    <strong><?= P::e(P::money((int) $resumen['depositos_cent'])) ?></strong>
  </div>
</div>

<?php endif; ?>
