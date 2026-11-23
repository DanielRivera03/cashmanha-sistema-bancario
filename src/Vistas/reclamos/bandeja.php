<?php
/**
 * La bandeja del área de atención.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $reclamos
 * @var array $panorama
 * @var bool $puedeResolver
 * @var string $csrf
 */

use CashMan\Http\Plantilla as P;

$estados = [
    'recibido'       => ['info',    'recibido'],
    'en_analisis'    => ['primary', 'en análisis'],
    'espera_cliente' => ['warning', 'esperando al cliente'],
    'escalado'       => ['dark',    'en la Defensoría'],
];
?>
<div class="row">

  <!-- ---------- Lo que corre prisa, arriba ---------- -->
  <div class="col-12">
    <div class="row">
      <?php
      $tarjetas = [
          ['Vencidos', count($panorama['vencidos']), 'danger',
           'Fuera del plazo legal de respuesta'],
          ['Por vencer', count($panorama['por_vencer']), 'warning',
           'Vencen en los próximos días'],
          ['Esperando al cliente', count($panorama['esperando']), 'secondary',
           'Lleva demasiado sin contestar'],
          ['Abiertos', count($reclamos), 'primary', 'En total'],
      ];

      foreach ($tarjetas as $t): ?>
      <div class="col-lg-3 col-md-6">
        <div class="card">
          <div class="card-body">
            <small class="text-muted d-block"><?= P::e($t[0]) ?></small>
            <h3 class="mb-0 text-<?= P::attr($t[2]) ?>"><?= (int) $t[1] ?></h3>
            <small class="text-muted"><?= P::e($t[3]) ?></small>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($panorama['vencidos'] !== []): ?>
  <div class="col-12">
    <div class="alert alert-danger">
      <strong>Hay <?= count($panorama['vencidos']) ?> reclamo(s) fuera del plazo legal
      de respuesta.</strong>
      Un reclamo vencido no es un retraso interno: es un incumplimiento, y el cliente
      puede llevarlo a la Defensoría del Consumidor con esa fecha en la mano.
    </div>
  </div>
  <?php endif; ?>

  <div class="col-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Reclamos abiertos</h4></div>
      <div class="card-body">

        <?php if ($reclamos === []): ?>
          <p class="text-muted mb-0">No hay reclamos abiertos.</p>
        <?php else: ?>

        <div class="table-responsive">
          <table class="table table-sm cm-tabla">
            <thead>
              <tr>
                <th>Folio</th>
                <th>Cliente</th>
                <th>Asunto</th>
                <th class="text-right">Reclama</th>
                <th>Plazo</th>
                <th class="text-center">Estado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($reclamos as $r):
                $e = $estados[$r['estado']] ?? ['secondary', $r['estado']];
                $dias = (int) $r['dias_restantes'];
            ?>
              <tr data-folio="<?= P::attr($r['folio']) ?>">
                <td class="cm-mono"><?= P::e($r['folio']) ?></td>
                <td>
                  <?= P::e($r['cliente']) ?>
                  <br><small class="text-muted"><?= P::e($r['documento_identidad']) ?></small>
                </td>
                <td style="max-width:22rem">
                  <?= P::e($r['asunto']) ?>
                  <br><small class="text-muted"><?= P::e($r['categoria']) ?></small>
                </td>
                <td class="text-right">
                  <?= (int) $r['monto_cent'] > 0
                      ? '$' . number_format((int) $r['monto_cent'] / 100, 2)
                      : '—' ?>
                </td>
                <td style="font-size:.84rem">
                  <?= P::e(date('d/m/Y', strtotime((string) $r['vence_en']))) ?>
                  <br>
                  <?php if ($dias < 0): ?>
                    <span class="badge badge-danger">vencido hace <?= abs($dias) ?> d</span>
                  <?php elseif ($dias <= 5): ?>
                    <span class="badge badge-warning">quedan <?= $dias ?> d</span>
                  <?php else: ?>
                    <small class="text-muted">quedan <?= $dias ?> d</small>
                  <?php endif; ?>
                  <?php if ((int) $r['prorrogado'] === 1): ?>
                    <br><small class="text-muted">prórroga usada</small>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <span class="badge badge-<?= P::attr($e[0]) ?>"><?= P::e($e[1]) ?></span>
                </td>
                <td class="text-right">
                  <button class="btn btn-sm btn-outline-primary" data-abrir>Abrir</button>
                </td>
              </tr>
              <tr class="d-none" data-detalle>
                <td colspan="7" class="bg-light"></td>
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

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var BASE = <?= P::js($rutas->url('reclamos/')) ?>;

  var PUEDE_RESOLVER = <?= $puedeResolver ? 'true' : 'false' ?>;

  var CSRF_SEMILLA = <?= P::js($csrf) ?>;

  function tokenVigente() {
    return (window.CMToken && window.CMToken.de('reclamos')) || CSRF_SEMILLA;
  }

  function esc(t) {
    return String(t == null ? '' : t)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function enviar(folio, que, fd, alOk) {
    fd.append('_csrf', tokenVigente());
    fd.append('_csrf_proposito', 'reclamos');

    fetch(BASE + folio + '/' + que, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { window.avisar('No se pudo', j.mensaje, 'critico'); return; }
        alOk(j);
      })
      .catch(function () {
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  }

  document.addEventListener('click', function (ev) {
    var fila = ev.target.closest ? ev.target.closest('[data-folio]') : null;
    if (!fila) { return; }

    var folio = fila.getAttribute('data-folio');

    // --- Abrir el detalle --------------------------------------------
    if (ev.target.hasAttribute('data-abrir')) {
      var detalle = fila.nextElementSibling;

      if (!detalle.classList.contains('d-none')) {
        detalle.classList.add('d-none');
        return;
      }

      fetch(BASE + folio + '/detalle', {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { return; }

          pintarDetalle(detalle.querySelector('td'), j.datos || {});
          detalle.classList.remove('d-none');
        })
        .catch(function () {});

      return;
    }

    // --- Las acciones -------------------------------------------------
    if (ev.target.hasAttribute('data-responder')) {
      var texto = window.prompt('Respuesta para el cliente:');
      if (texto === null || texto.trim().length < 10) { return; }

      var fd = new FormData();
      fd.append('texto', texto);

      enviar(folio, 'responder', fd, function (j) {
        window.avisar('Enviado', j.mensaje, 'exito');
        if (window.CMVista) { window.CMVista.refrescar(); } else { location.reload(); }
      });
    }

    if (ev.target.hasAttribute('data-nota')) {
      var nota = window.prompt('Nota interna. El cliente NO la ve:');
      if (nota === null || nota.trim().length < 10) { return; }

      var fdn = new FormData();
      fdn.append('texto', nota);
      fdn.append('interno', '1');

      enviar(folio, 'responder', fdn, function (j) {
        window.avisar('Guardada', j.mensaje, 'exito');
      });
    }

    if (ev.target.hasAttribute('data-pedir')) {
      var que = window.prompt(
        'Qué se le pide al cliente.\n\nEl plazo queda en pausa hasta que conteste.'
      );

      if (que === null || que.trim().length < 10) { return; }

      var fdp = new FormData();
      fdp.append('texto', que);

      enviar(folio, 'pedir-informacion', fdp, function (j) {
        window.avisar('Pedido', j.mensaje, 'exito');
        if (window.CMVista) { window.CMVista.refrescar(); } else { location.reload(); }
      });
    }

    if (ev.target.hasAttribute('data-prorrogar')) {
      var motivo = window.prompt(
        'Por qué hace falta más tiempo.\n\nEs la ÚNICA prórroga posible, y el cliente lo va a leer.'
      );

      if (motivo === null || motivo.trim().length < 15) { return; }

      var fdx = new FormData();
      fdx.append('motivo', motivo);

      enviar(folio, 'prorrogar', fdx, function (j) {
        window.avisar('Prorrogado', j.mensaje, 'exito');
        if (window.CMVista) { window.CMVista.refrescar(); } else { location.reload(); }
      });
    }

    if (ev.target.hasAttribute('data-resolver')) {
      var aFavor = ev.target.getAttribute('data-resolver') === 'favor';

      var texto2 = window.prompt(
        'Resolución.\n\nQué se revisó, qué se encontró y por qué se decide así. '
        + 'El cliente la va a leer y puede llevarla a la Defensoría.'
      );

      if (texto2 === null || texto2.trim().length < 40) { return; }

      var comp = '0';

      if (aFavor) {
        comp = window.prompt('¿Cuánto se le devuelve? Deje 0 si no hay devolución.', '0');
        if (comp === null) { return; }
      }

      var fdr = new FormData();
      fdr.append('a_favor', aFavor ? '1' : '0');
      fdr.append('resolucion', texto2);
      fdr.append('compensacion', comp);

      enviar(folio, 'resolver', fdr, function (j) {
        window.avisar('Resuelto', j.mensaje, 'exito');
        if (window.CMVista) { window.CMVista.refrescar(); } else { location.reload(); }
      });
    }
  });

  function pintarDetalle(caja, r) {
    var html = '<div class="p-2">'
      + '<p style="font-size:.9rem"><strong>Relato:</strong><br>'
      + esc(r.relato).replace(/\n/g, '<br>') + '</p>';

    if (r.prorrogado === 1 || r.prorrogado === '1') {
      html += '<div class="alert alert-warning py-2" style="font-size:.84rem">'
        + '<strong>Prórroga usada:</strong> ' + esc(r.prorroga_motivo) + '</div>';
    }

    if (r.mensajes && r.mensajes.length) {
      html += '<div class="mt-2">';

      r.mensajes.forEach(function (m) {
        var interno = m.interno === 1 || m.interno === '1';

        html += '<div class="mb-2 p-2 rounded '
          + (interno ? 'border border-warning' : (m.de === 'banco' ? 'bg-white' : ''))
          + '" style="font-size:.86rem">'
          + '<small class="text-muted d-block">'
          + (m.de === 'banco' ? esc(m.autor || 'Banco') : 'Cliente')
          + ' · ' + esc(String(m.creado_en).slice(0, 16))
          + (interno ? ' · <strong>nota interna</strong>' : '')
          + '</small>'
          + esc(m.texto).replace(/\n/g, '<br>')
          + '</div>';
      });

      html += '</div>';
    }

    html += '<div class="mt-2">'
      + '<button class="btn btn-sm btn-outline-primary mr-1" data-responder>Responder</button>'
      + '<button class="btn btn-sm btn-outline-secondary mr-1" data-nota>Nota interna</button>'
      + '<button class="btn btn-sm btn-outline-warning mr-1" data-pedir>Pedir información</button>'
      + (r.prorrogado === 1 || r.prorrogado === '1'
          ? ''
          : '<button class="btn btn-sm btn-outline-info mr-1" data-prorrogar>Prorrogar</button>');

    if (PUEDE_RESOLVER) {
      html += '<button class="btn btn-sm btn-success mr-1" data-resolver="favor">'
        + 'Resolver a favor</button>'
        + '<button class="btn btn-sm btn-outline-danger" data-resolver="contra">'
        + 'Resolver en contra</button>';
    }

    caja.innerHTML = html + '</div></div>';
  }
})();
</script>
