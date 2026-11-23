<?php
/**
 * Conciliación de la pasarela.
 *
 * Responde a una pregunta concreta: ¿lo que la pasarela dice haber
 * cobrado coincide con lo que hay en su cuenta técnica? Si no cuadra,
 * hay cobros contabilizados que la procesadora no liquidó, o al revés.
 *
 * El saldo de la cuenta técnica es negativo mientras la procesadora no
 * deposita: se abonó al cliente antes de recibir los fondos. Eso no es
 * un error, es el desfase propio de la liquidación.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $resumen
 * @var array $pagos
 * @var array $eventos
 * @var bool  $activa
 * @var bool  $esPrueba
 * @var bool  $puedeConciliar
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$badge = static fn(string $e): string => match ($e) {
    'cobrado', 'aplicado' => 'success',
    'procesando' => 'info',
    'iniciado'   => 'light',
    'devuelto'   => 'warning',
    'cancelado'  => 'secondary',
    default      => 'danger',
};

/* Cobros a medio cerrar. Son los que la pasarela aceptó y de este lado
   nadie aplicó: mientras estén aquí, alguien pagó y su deuda sigue
   igual. Es el dato que justifica el botón de abajo. */
$enElAire = 0;

foreach ($pagos as $cobro) {
    if (in_array((string) $cobro['estado'], ['iniciado', 'procesando'], true)) {
        $enElAire++;
    }
}

$cobrado  = (int) ($resumen['cobrado_cent'] ?? 0);
$devuelto = (int) ($resumen['devuelto_cent'] ?? 0);
$tecnica  = (int) ($resumen['saldo_tecnica_cent'] ?? 0);

// Lo cobrado menos lo devuelto debería corresponderse con lo que la
// cuenta técnica tiene pendiente de liquidar.
$esperado   = $cobrado - $devuelto;
$diferencia = $esperado + $tecnica;
?>

<?php if (!$activa): ?>
<div class="alert alert-warning" style="font-size:.88rem">
  <strong>La pasarela no está activa.</strong> Lo que aparece abajo es el
  histórico; no se están recibiendo cobros nuevos.
</div>
<?php elseif ($esPrueba): ?>
<div class="alert alert-info" style="font-size:.86rem">
  <strong>Modo de prueba.</strong> Las cifras corresponden a cobros simulados
  por la procesadora, no a dinero real.
</div>
<?php endif; ?>

<div class="row">
  <?php
  $kpis = [
    ['Cobrado por la pasarela', P::money($cobrado),  'invest',  'success',
     'confirmado por la procesadora'],
    ['Devuelto',                P::money($devuelto), 'reload',  'warning',
     'reembolsos marcados'],
    ['En curso',                P::money((int) ($resumen['en_curso_cent'] ?? 0)), 'clock', 'info',
     'iniciados sin confirmar'],
    ['Cobros fallidos',         number_format((float) ($resumen['fallidos'] ?? 0)), 'warning', 'danger',
     'rechazados por la procesadora'],
  ];
  foreach ($kpis as [$t, $v, $i, $c, $pie]): ?>
  <div class="col-xl-3 col-lg-6 col-sm-6">
    <div class="card">
      <div class="card-body d-flex align-items-center">
        <div class="cm-kpi__icono bg-<?= $c ?> text-white mr-3"><?= cm_icono($i, 22) ?></div>
        <div style="min-width:0">
          <h4 class="mb-0 font-weight-bold" style="font-size:1.2rem"><?= P::e($v) ?></h4>
          <small class="text-muted d-block" style="font-size:.76rem"><?= P::e($t) ?></small>
          <small class="text-muted" style="font-size:.7rem"><?= P::e($pie) ?></small>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ---------------- Cuadre ---------------- -->

<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">Cuadre con la cuenta técnica</h4>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table mb-0">
        <tbody>
          <tr>
            <td>Cobrado menos devuelto</td>
            <td class="text-right cm-mono cm-nowrap"><?= P::e(P::money($esperado)) ?></td>
          </tr>
          <tr>
            <td>
              Saldo de la cuenta técnica <code>0000000001</code>
              <small class="text-muted d-block" style="font-size:.74rem">
                Negativo mientras la procesadora no liquida: el abono al cliente
                se hizo antes de recibir los fondos.
              </small>
            </td>
            <td class="text-right cm-mono cm-nowrap"><?= P::e(P::money($tecnica)) ?></td>
          </tr>
          <tr class="<?= $diferencia === 0 ? 'table-success' : 'table-warning' ?>">
            <td class="font-weight-bold">Diferencia</td>
            <td class="text-right cm-mono cm-nowrap font-weight-bold">
              <?= P::e(P::money($diferencia)) ?>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p class="mb-0 mt-3" style="font-size:.84rem">
      <?php if ($diferencia === 0): ?>
        <span class="text-success font-weight-bold">Cuadra.</span>
        Todo lo cobrado está reflejado en la cuenta técnica.
      <?php else: ?>
        <span class="text-danger font-weight-bold">No cuadra.</span>
        Revise los cobros marcados como devueltos y los avisos sin procesar:
        un reembolso no se revierte solo, queda señalado para que alguien
        decida qué hacer con él.
      <?php endif; ?>
    </p>
  </div>
</div>

<!-- ---------------- Cobros ---------------- -->

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap"
       style="gap:.75rem">
    <div style="min-width:0">
      <h4 class="card-title mb-0">Últimos cobros</h4>
      <?php if ($enElAire > 0): ?>
      <small class="text-muted d-block mt-1" style="font-size:.78rem">
        <?= $enElAire ?> sin cerrar: la pasarela los aceptó y aquí nadie los aplicó todavía.
      </small>
      <?php endif; ?>
    </div>

    <?php if ($activa && $puedeConciliar): ?>
    <?php /* El aviso de la procesadora no siempre llega —a una
             instalación sin dirección pública, nunca— y el navegador
             del cliente se cierra. Esto le pregunta a la pasarela por
             cada cobro en duda y lo cierra según lo que ella conteste:
             es la red de seguridad, no el camino normal. */ ?>
    <form method="post" data-cm-ajax data-cm-recargar
          action="<?= P::attr($rutas->url('pagos/conciliacion/sincronizar')) ?>">
      <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
      <input type="hidden" name="_csrf_proposito" value="pasarela">
      <button type="submit" class="btn btn-primary btn-sm"
              data-cm-confirmar="Se consultará en la pasarela cada cobro sin cerrar y se aplicará el que esté cobrado. ¿Continuar?">
        <?= cm_icono('reload', 15, 'mr-1') ?> Consultar en la pasarela
      </button>
    </form>
    <?php endif; ?>
  </div>
  <div class="card-body pt-0">
    <?php if ($pagos === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('card', 40) ?>
        <p class="mb-0 mt-2">Todavía no se ha procesado ningún cobro por este medio.</p>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Cliente</th>
            <th>Concepto</th>
            <th class="text-right">Importe</th>
            <th>Estado</th>
            <th>Referencia</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pagos as $p): ?>
          <tr>
            <td class="cm-nowrap" style="font-size:.82rem">
              <?= P::e(P::fecha((string) $p['creado_en'], 'd/m/Y H:i')) ?>
              <?php if (!empty($p['confirmado_en'])): ?>
              <small class="text-success d-block" style="font-size:.7rem">
                confirmado <?= P::e(P::fecha((string) $p['confirmado_en'], 'd/m/Y H:i')) ?>
              </small>
              <?php endif; ?>
            </td>
            <td style="font-size:.84rem"><?= P::e($p['cliente']) ?></td>
            <td style="font-size:.82rem">
              <?= P::e($p['concepto']) ?>
              <?php if (!empty($p['detalle_error'])): ?>
              <small class="text-danger d-block" style="font-size:.72rem">
                <?= P::e($p['detalle_error']) ?>
              </small>
              <?php endif; ?>
            </td>
            <td class="text-right cm-mono cm-nowrap font-weight-bold">
              <?= P::e(P::money((int) $p['importe_cent'])) ?>
            </td>
            <td class="cm-nowrap">
              <span class="badge badge-<?= $badge((string) $p['estado']) ?>">
                <?= P::e($p['estado']) ?>
              </span>
            </td>
            <td class="cm-mono" style="font-size:.7rem;word-break:break-all">
              <?= P::e($p['referencia'] ?? '—') ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ---------------- Avisos recibidos ---------------- -->

<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">Avisos recibidos de la procesadora</h4>
  </div>
  <div class="card-body pt-0">
    <?php if ($eventos === []): ?>
      <p class="text-muted mb-0 mt-2" style="font-size:.86rem">
        No ha llegado ningún aviso todavía. Si se están haciendo cobros y esto
        sigue vacío, falta configurar <code>STRIPE_WEBHOOK_SECRETO</code> o la
        procesadora no alcanza la dirección del webhook.
      </p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Recibido</th>
            <th>Tipo</th>
            <th>Resultado</th>
            <th>Identificador</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($eventos as $e): ?>
          <tr>
            <td class="cm-nowrap" style="font-size:.82rem">
              <?= P::e(P::fecha((string) $e['recibido_en'], 'd/m/Y H:i')) ?>
            </td>
            <td class="cm-mono" style="font-size:.76rem"><?= P::e($e['tipo']) ?></td>
            <td style="font-size:.8rem">
              <?= P::e($e['resultado'] ?? 'sin procesar') ?>
            </td>
            <td class="cm-mono" style="font-size:.7rem;word-break:break-all">
              <?= P::e($e['evento_id']) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
