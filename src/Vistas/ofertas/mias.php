<?php
/**
 * Las ofertas del cliente.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $ofertas
 * @var array  $historial
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;
?>

<?php if ($ofertas === []): ?>
  <div class="card">
    <div class="card-body">
      <div class="cm-vacio">
        <?= cm_icono('invest', 40) ?>
        <p class="mb-1 mt-2 font-weight-bold">No tiene ofertas vigentes en este momento.</p>
        <p class="mb-0 text-muted" style="font-size:.88rem;max-width:34rem;margin:0 auto">
          Las ofertas preaprobadas salen del historial de pago: se generan solas cuando
          un cliente acumula cuotas pagadas a tiempo, sin atrasos y sin gestiones de
          cobro abiertas. No hay que solicitarlas.
        </p>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php foreach ($ofertas as $o):
  $esCredito = $o['tipo'] === 'credito';
  $esExtra   = $o['tipo'] === 'extrafinanciamiento';
  $c = $o['criterios'];

  $titulo = match ($o['tipo']) {
      'credito'            => 'Crédito personal',
      'extrafinanciamiento'=> 'Efectivo sobre su tarjeta',
      default              => 'Tarjeta de crédito',
  };
?>
<div class="card mb-4 cm-oferta">
  <div class="cm-oferta__cinta"></div>

  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start flex-wrap mb-3">
      <div>
        <span class="badge badge-success mb-2">Preaprobado</span>
        <h4 class="mb-1">
          <?= P::e($titulo) ?> de <?= P::e(P::money((int) $o['monto_cent'])) ?>
        </h4>
        <p class="text-muted mb-0" style="font-size:.86rem">
          Oferta <span class="cm-mono"><?= P::e($o['folio']) ?></span>
          &middot; vigente hasta el <?= P::e(P::fecha((string) $o['vence_el'], 'd/m/Y')) ?>
          <?php if ($o['dias'] <= 7): ?>
            <span class="badge badge-warning ml-1"><?= (int) $o['dias'] ?> día(s)</span>
          <?php endif; ?>
        </p>
      </div>

      <div class="text-right">
        <div class="cm-kpi__et">
          <?= $esCredito || $esExtra ? 'Cuota mensual' : 'Línea aprobada' ?>
        </div>
        <div class="cm-kpi__val">
          <?= P::e(P::money((int) ($esCredito || $esExtra ? $o['cuota_cent'] : $o['monto_cent']))) ?>
          <?php if ($esCredito || $esExtra): ?><small class="text-muted" style="font-size:.8rem">/mes</small><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="row">
      <div class="col-lg-7">
        <?php if ($esExtra): ?>
        <table class="table table-sm mb-3">
          <tr><td class="text-muted">Efectivo a recibir</td>
              <td class="cm-num font-weight-bold"><?= P::e(P::money((int) $o['monto_cent'])) ?></td></tr>
          <tr><td class="text-muted">Plazo</td>
              <td class="cm-num"><?= (int) $o['plazo_meses'] ?> meses</td></tr>
          <tr><td class="text-muted">Tasa anual</td>
              <td class="cm-num"><?= number_format((int) $o['tasa_bp'] / 100, 2) ?>%</td></tr>
          <tr><td class="text-muted">Cuota mensual</td>
              <td class="cm-num"><?= P::e(P::money((int) $o['cuota_cent'])) ?></td></tr>
          <tr><td class="text-muted">Total a pagar</td>
              <td class="cm-num"><?= P::e(P::money((int) $o['cuota_cent'] * (int) $o['plazo_meses'])) ?></td></tr>
        </table>
        <p class="text-muted" style="font-size:.84rem">
          Es efectivo contra la línea de su tarjeta
          <?php if (!empty($c['tarjeta'])): ?>
            <strong><?= P::e($c['tarjeta']) ?></strong>
          <?php endif; ?>:
          al aceptar, el dinero se acredita en su cuenta de ahorro en el acto, y la cuota
          aparece cada mes en el estado de cuenta de esa misma tarjeta hasta liquidarlo.
          Mientras tanto ocupa su línea, así que su cupo disponible baja en ese importe.
        </p>
        <?php elseif ($esCredito): ?>
        <table class="table table-sm mb-3">
          <tr><td class="text-muted">Monto preaprobado</td>
              <td class="cm-num font-weight-bold"><?= P::e(P::money((int) $o['monto_cent'])) ?></td></tr>
          <tr><td class="text-muted">Tasa anual</td>
              <td class="cm-num"><?= number_format((int) $o['tasa_bp'] / 100, 2) ?>%</td></tr>
        </table>

        <?php /* El plazo lo elige el cliente.
                 A doce meses la cuota aprieta y se pagan menos
                 intereses; a sesenta, al revés. Es una decisión suya,
                 no nuestra, y con las cifras de cada opción delante
                 deja de ser una apuesta. El monto y la tasa no se
                 tocan: salieron de su capacidad y de su perfil. */ ?>
        <?php if (!empty($o['plazos'])): ?>
        <div class="cm-plazos" data-oferta="<?= P::attr($o['codigo']) ?>">
          <div class="cm-plazos__et">Elija su plazo</div>

          <div class="cm-plazos__opciones">
            <?php foreach ($o['plazos'] as $p):
              $elegido = (int) $p['plazo'] === (int) $o['plazo_meses'];
            ?>
            <button type="button"
                    class="cm-plazo<?= $elegido ? ' cm-plazo--activo' : '' ?><?= $p['cabe'] ? '' : ' cm-plazo--aprieta' ?>"
                    data-plazo="<?= (int) $p['plazo'] ?>"
                    data-cuota="<?= P::attr(P::money((int) $p['cuota_cent'])) ?>"
                    data-total="<?= P::attr(P::money((int) $p['total_cent'])) ?>"
                    data-interes="<?= P::attr(P::money((int) $p['interes_cent'])) ?>"
                    data-cabe="<?= $p['cabe'] ? '1' : '0' ?>"
                    data-maxima="<?= P::attr(P::money((int) $p['cuota_maxima_cent'])) ?>"
                    <?= $p['cabe'] ? '' : 'disabled' ?>>
              <strong><?= (int) $p['plazo'] ?></strong>
              <small>meses</small>
              <span><?= P::e(P::money((int) $p['cuota_cent'])) ?></span>
            </button>
            <?php endforeach; ?>
          </div>

          <table class="table table-sm mb-0 cm-plazos__detalle">
            <tr><td class="text-muted">Cuota de capital e intereses</td>
                <td class="cm-num font-weight-bold" data-campo="cuota">
                  <?= P::e(P::money((int) $o['cuota_cent'])) ?></td></tr>
            <tr><td class="text-muted">Intereses del plazo</td>
                <td class="cm-num" data-campo="interes">—</td></tr>
            <tr><td class="text-muted">Total a pagar</td>
                <td class="cm-num" data-campo="total">—</td></tr>
          </table>

          <p class="cm-plazos__aviso" data-campo="aviso" hidden></p>
        </div>
        <?php endif; ?>
        <p class="text-muted" style="font-size:.84rem">
          A la cuota se suman las primas de seguro de vida y desempleo que exige el
          contrato, calculadas sobre el saldo con el que arranca cada periodo. Al
          aceptar, el monto se acredita en su cuenta de ahorro en el acto.
        </p>
        <?php else: ?>
        <table class="table table-sm mb-3">
          <tr><td class="text-muted">Línea preaprobada</td>
              <td class="cm-num font-weight-bold"><?= P::e(P::money((int) $o['monto_cent'])) ?></td></tr>
          <tr><td class="text-muted">Tasa anual</td>
              <td class="cm-num"><?= number_format((int) $o['tasa_bp'] / 100, 2) ?>%</td></tr>
        </table>
        <p class="text-muted" style="font-size:.84rem">
          Al aceptar, la tarjeta se emite en el acto y queda disponible en su banca en
          línea. El plástico se entrega después por los canales habituales.
        </p>
        <?php endif; ?>
      </div>

      <div class="col-lg-5">
        <?php /* Por qué se le ofrece. Una preaprobación sin su criterio
                 a la vista se lee como publicidad, y esto no lo es. */ ?>
        <div class="cm-oferta__motivo">
          <strong style="font-size:.85rem">Por qué se le ofrece</strong>
          <ul class="mb-0 mt-2" style="font-size:.82rem;padding-left:1.1rem">
            <li><?= (int) ($c['cuotas_pagadas'] ?? 0) ?> cuotas pagadas</li>
            <li>Atraso máximo de <?= (int) ($c['atraso_maximo_dias'] ?? 0) ?> día(s)</li>
            <li>Sin cuotas vencidas hoy</li>
            <li>Sin gestiones de cobro abiertas</li>
          </ul>
        </div>
      </div>
    </div>

    <div class="alert alert-light py-2 mt-3" style="font-size:.82rem">
      Al aceptar se vuelve a verificar su capacidad de pago con sus compromisos de
      ese día. Si hubiera tomado otras obligaciones y el monto ya no le correspondiera,
      se lo diremos con las cifras a la vista en lugar de otorgarle algo distinto de lo
      ofrecido. Aceptar genera una obligación de pago exigible; no aceptar no tiene
      ninguna consecuencia sobre sus productos ni su historial.
    </div>

    <div class="d-flex flex-wrap" style="gap:.6rem">
      <form method="post" data-cm-ajax
            action="<?= P::attr($rutas->url('ofertas/' . $o['codigo'] . '/aceptar')) ?>">
        <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
        <input type="hidden" name="_csrf_proposito" value="oferta">
        <?php /* El plazo elegido viaja con la aceptación. El servicio
                 lo valida contra su lista: esto es un campo del
                 navegador, no una fuente de verdad. */ ?>
        <input type="hidden" name="plazo" value="<?= (int) $o['plazo_meses'] ?>"
               data-campo="plazo">
        <button type="submit" class="btn btn-success"
                data-cm-confirmar="Al aceptar se formaliza la operación de inmediato. ¿Continuar?">
          <?= cm_icono('check', 16, 'mr-1') ?>
          Aceptar <?= $esCredito ? 'el crédito' : ($esExtra ? 'el efectivo' : 'la tarjeta') ?>
        </button>
      </form>

      <form method="post" data-cm-ajax data-cm-recargar
            action="<?= P::attr($rutas->url('ofertas/' . $o['codigo'] . '/rechazar')) ?>">
        <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
        <input type="hidden" name="_csrf_proposito" value="oferta">
        <button type="submit" class="btn btn-outline-secondary">No me interesa</button>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php if ($historial !== []): ?>
<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">Ofertas anteriores</h4>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" data-cm-tabla>
        <thead>
          <tr><th>Oferta</th><th>Producto</th><th class="cm-num">Importe</th>
              <th>Estado</th><th>Resultado</th></tr>
        </thead>
        <tbody>
        <?php foreach ($historial as $h): ?>
          <tr>
            <td class="cm-mono"><?= P::e($h['folio']) ?></td>
            <td><?= P::e(match ($h['tipo']) {
                  'credito'             => 'Crédito personal',
                  'extrafinanciamiento' => 'Efectivo sobre tarjeta',
                  default               => 'Tarjeta de crédito',
                }) ?></td>
            <td class="cm-num"><?= P::e(P::money((int) $h['monto_cent'])) ?></td>
            <td>
              <span class="badge badge-<?= $h['estado'] === 'aceptada' ? 'success' : 'light' ?>">
                <?= P::e(ucfirst((string) $h['estado'])) ?>
              </span>
            </td>
            <td class="text-muted" style="font-size:.82rem">
              <?php if ($h['credito_folio'] !== null): ?>
                Crédito <?= P::e($h['credito_folio']) ?>
              <?php elseif ($h['ultimos4'] !== null): ?>
                Tarjeta &bull;&bull;&bull;&bull;<?= P::e($h['ultimos4']) ?>
              <?php else: ?>
                <?= P::e($h['cerrada_motivo'] ?: '—') ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php /* El plazo, en caliente.
         Las cifras de cada opción ya vienen calculadas por el servidor
         —no se recalculan aquí, que sería una segunda verdad—: el
         guion solo las enseña y apunta cuál eligió. */ ?>
<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  document.querySelectorAll('.cm-plazos').forEach(function (caja) {
    var tarjeta = caja.closest('.cm-oferta');
    if (!tarjeta) { return; }

    var campoCuota   = caja.querySelector('[data-campo="cuota"]');
    var campoInteres = caja.querySelector('[data-campo="interes"]');
    var campoTotal   = caja.querySelector('[data-campo="total"]');
    var aviso        = caja.querySelector('[data-campo="aviso"]');
    var oculto       = tarjeta.querySelector('[data-campo="plazo"]');

    // El importe grande de la cabecera también cambia: es lo primero
    // que mira el cliente, y dejarlo con la cuota del plazo anterior
    // sería enseñarle una cifra que ya no corresponde.
    var destacado = tarjeta.querySelector('.cm-kpi__val');

    function elegir(boton) {
      caja.querySelectorAll('.cm-plazo').forEach(function (b) {
        b.classList.toggle('cm-plazo--activo', b === boton);
      });

      if (campoCuota)   { campoCuota.textContent   = boton.dataset.cuota; }
      if (campoInteres) { campoInteres.textContent = boton.dataset.interes; }
      if (campoTotal)   { campoTotal.textContent   = boton.dataset.total; }
      if (oculto)       { oculto.value = boton.dataset.plazo; }

      if (destacado) {
        destacado.childNodes[0].nodeValue = boton.dataset.cuota + ' ';
      }

      if (aviso) {
        var cabe = boton.dataset.cabe === '1';
        aviso.hidden = cabe;
        aviso.textContent = cabe ? '' :
          'Con ese plazo la cuota queda en ' + boton.dataset.cuota
          + ', por encima de lo que hoy admite su capacidad ('
          + boton.dataset.maxima + ' al mes). Elija un plazo mayor.';
      }
    }

    caja.addEventListener('click', function (ev) {
      var b = ev.target.closest ? ev.target.closest('.cm-plazo') : null;
      if (b && !b.disabled) { elegir(b); }
    });

    var activo = caja.querySelector('.cm-plazo--activo')
              || caja.querySelector('.cm-plazo:not([disabled])');

    if (activo) { elegir(activo); }
  });
})();
</script>
