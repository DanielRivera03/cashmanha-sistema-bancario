<?php
/**
 * El panel de prevención de lavado.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $panorama
 * @var array $alertas
 * @var array $umbrales
 * @var bool $puedeAnalizar
 * @var bool $puedeReportar
 * @var string $csrf
 */

use CashMan\Http\Plantilla as P;

$etiquetaTipo = [
    'umbral_efectivo_diario'  => 'Efectivo sobre umbral · día',
    'umbral_efectivo_mensual' => 'Efectivo sobre umbral · mes',
    'umbral_otros_medios'     => 'Otros medios sobre umbral',
    'fraccionamiento'         => 'Fraccionamiento',
    'fuera_de_perfil'         => 'Fuera del perfil declarado',
    'pep_movimiento_alto'     => 'PEP con movimiento alto',
    'lista_restrictiva'       => 'Lista restrictiva',
    'cuenta_nueva_activa'     => 'Cuenta nueva muy activa',
    'interbancaria_inusual'   => 'Interbancaria inusual',
];

$colorSeveridad = ['alta' => 'danger', 'media' => 'warning', 'baja' => 'secondary'];
?>
<div class="row">

  <!-- ---------- El estado del área, de un vistazo ---------- -->
  <div class="col-12">
    <div class="row">
      <?php
      $tarjetas = [
          ['Alertas abiertas', (int) $panorama['abiertas'], 'primary',
           (int) $panorama['altas'] . ' de severidad alta'],
          ['Con el plazo vencido', (int) $panorama['vencidas'],
           $panorama['vencidas'] > 0 ? 'danger' : 'success',
           'El análisis tiene ' . (int) $umbrales['analisis'] . ' días hábiles'],
          ['Reportes en borrador', (int) $panorama['borradores'],
           $panorama['borradores_vencidos'] > 0 ? 'danger' : 'secondary',
           (int) $panorama['borradores_vencidos'] . ' fuera de plazo'],
          ['Remitidos a la UIF', (int) $panorama['enviados'], 'success', 'con acuse registrado'],
      ];

      foreach ($tarjetas as $t): ?>
      <div class="col-lg-3 col-md-6">
        <div class="card">
          <div class="card-body">
            <small class="text-muted d-block"><?= P::e($t[0]) ?></small>
            <h3 class="mb-0 text-<?= P::attr($t[2]) ?>" data-panorama="<?= P::attr($t[0]) ?>">
              <?= (int) $t[1] ?>
            </h3>
            <small class="text-muted"><?= P::e($t[3]) ?></small>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ---------- Lo que impide vigilar bien ---------- -->
  <?php if ($panorama['sin_perfil'] > 0 || $panorama['diligencia_vencida'] > 0): ?>
  <div class="col-12">
    <div class="alert alert-warning">
      <strong>Lo que falta para poder vigilar bien.</strong>
      <ul class="mb-0 mt-2" style="font-size:.9rem">
        <?php if ($panorama['sin_perfil'] > 0): ?>
        <li>
          <strong><?= (int) $panorama['sin_perfil'] ?></strong> cliente(s) sin perfil
          transaccional declarado. Sin perfil no se puede decir que algo es inusual:
          inusual es lo que se sale de lo esperado, y lo esperado hay que haberlo
          preguntado.
        </li>
        <?php endif; ?>
        <?php if ($panorama['diligencia_vencida'] > 0): ?>
        <li>
          <strong><?= (int) $panorama['diligencia_vencida'] ?></strong> cliente(s) con la
          debida diligencia vencida. Un expediente que nadie revisa no es diligencia.
        </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>

  <!-- ---------- Las alertas ---------- -->
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Alertas abiertas</h4>
        <a class="btn btn-sm btn-outline-secondary"
           href="<?= P::attr($rutas->url('cumplimiento/reportes')) ?>">
          Ver reportes a la UIF
        </a>
      </div>

      <div class="card-body">

        <div class="alert alert-light border" style="font-size:.86rem">
          Una alerta <strong>no es un reporte</strong>. Es una señal que hay que mirar, y
          la mayoría se descartan con una explicación razonable. Lo que no se puede es
          cerrarla sin escribir por qué: una alerta cerrada en blanco no se distingue de
          una que nadie miró.
          <br><br>
          Nada de lo que hay en esta pantalla se le comunica al cliente. Un reporte de
          operación sospechosa es confidencial por ley.
        </div>

        <div id="lista-alertas"></div>

      </div>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  /* La semilla: el token con el que se pintó la pantalla.

     NO se usa directamente. Los tokens son de un solo uso, así
     que esta copia se gasta en la primera acción y de la segunda
     en adelante todo fallaría con «el token de seguridad venció».
     El vigente lo mantiene `token.js`. */
  var CSRF_SEMILLA = <?= P::js($csrf) ?>;

  function tokenVigente() {
    return (window.CMToken && window.CMToken.de('cumplimiento')) || CSRF_SEMILLA;
  }

  var BASE  = <?= P::js($rutas->url('cumplimiento/alerta/')) ?>;
  var ANALIZA = <?= $puedeAnalizar ? 'true' : 'false' ?>;

  var ETIQUETA = JSON.parse(<?= P::js(json_encode($etiquetaTipo, JSON_UNESCAPED_UNICODE)) ?>);
  var COLOR    = JSON.parse(<?= P::js(json_encode($colorSeveridad, JSON_UNESCAPED_UNICODE)) ?>);

  var ALERTAS = JSON.parse(<?= P::js(json_encode($alertas, JSON_UNESCAPED_UNICODE)) ?>);

  function esc(t) {
    return String(t == null ? '' : t)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function dinero(cent) {
    return (window.CMUtil && window.CMUtil.money)
      ? window.CMUtil.money(cent)
      : '$' + (Number(cent) / 100).toFixed(2);
  }

  function pintar(lista) {
    ALERTAS = lista || [];

    var caja = document.getElementById('lista-alertas');

    if (!ALERTAS.length) {
      caja.innerHTML = '<p class="text-muted mb-0">'
        + 'No hay alertas abiertas. La vigilancia corre todos los días de madrugada.'
        + '</p>';
      return;
    }

    var html = '';

    ALERTAS.forEach(function (a) {
      var dias = parseInt(a.dias_restantes, 10);

      /* El plazo, dicho como lo que es. Un «vence pronto» no sirve
         cuando lo que corre es un plazo legal. */
      var plazo;

      if (isNaN(dias)) {
        plazo = '';
      } else if (dias < 0) {
        plazo = '<span class="badge badge-danger">plazo vencido hace '
              + Math.abs(dias) + ' día(s)</span>';
      } else if (dias <= 3) {
        plazo = '<span class="badge badge-warning">quedan ' + dias + ' día(s)</span>';
      } else {
        plazo = '<span class="text-muted" style="font-size:.8rem">quedan '
              + dias + ' días</span>';
      }

      html += '<div class="border rounded p-3 mb-3" data-folio="' + esc(a.folio) + '">'
        + '<div class="d-flex justify-content-between align-items-start flex-wrap">'
        + '<div>'
        + '<span class="badge badge-' + (COLOR[a.severidad] || 'secondary') + '">'
        + esc(a.severidad) + '</span> '
        + '<strong>' + esc(ETIQUETA[a.tipo] || a.tipo) + '</strong>'
        + '<br><span class="cm-mono text-muted" style="font-size:.8rem">'
        + esc(a.folio) + '</span>'
        + '</div>'
        + '<div class="text-right">'
        + '<strong>' + esc(a.cliente) + '</strong>'
        + (a.pep == 1 ? ' <span class="badge badge-dark">PEP</span>' : '')
        + '<br><small class="text-muted">' + esc(a.documento_identidad || '') + '</small>'
        + '</div>'
        + '</div>'

        + '<p class="mt-2 mb-1" style="font-size:.9rem">' + esc(a.detalle) + '</p>'

        + '<div class="d-flex justify-content-between align-items-center flex-wrap">'
        + '<small class="text-muted">'
        + (Number(a.monto_cent) > 0 ? dinero(a.monto_cent) + ' · ' : '')
        + esc(a.operaciones) + ' operación(es) · '
        + esc(a.periodo_desde) + (a.periodo_desde !== a.periodo_hasta
            ? ' a ' + esc(a.periodo_hasta) : '')
        + '</small>'
        + plazo
        + '</div>';

      if (ANALIZA) {
        html += '<div class="mt-3">'
          + '<button class="btn btn-sm btn-outline-secondary mr-1" data-accion="descartar">'
          + 'Descartar</button>'
          + '<button class="btn btn-sm btn-outline-danger mr-1" data-accion="escalar">'
          + 'Escalar a reporte</button>'
          + (a.prorrogada == 1
              ? '<span class="text-muted" style="font-size:.8rem">prórroga agotada</span>'
              : '<button class="btn btn-sm btn-outline-info" data-accion="prorrogar">'
                + 'Prorrogar</button>')
          + '</div>';
      }

      html += '</div>';
    });

    caja.innerHTML = html;
  }

  pintar(ALERTAS);

  /* Un manejador para toda la lista: los botones repintados siguen
     funcionando sin volver a enganchar nada. */
  document.getElementById('lista-alertas').addEventListener('click', function (ev) {
    var boton = ev.target.closest ? ev.target.closest('[data-accion]') : null;
    if (!boton) { return; }

    var caja  = boton.closest('[data-folio]');
    var folio = caja.getAttribute('data-folio');

    var accion = boton.getAttribute('data-accion');

    if (accion === 'descartar') { descartar(folio); }
    if (accion === 'escalar')   { escalar(folio); }
    if (accion === 'prorrogar') { enviar(folio, 'prorrogar', new FormData()); }
  });

  function descartar(folio) {
    var razon = window.prompt(
      'Por qué se descarta esta alerta. Quedará en el expediente y es lo que '
      + 'demuestra que se miró.'
    );

    if (razon === null) { return; }

    if (razon.trim().length < 15) {
      window.avisar('Falta el motivo',
        'Escriba por qué se descarta: una alerta cerrada en blanco no se distingue '
        + 'de una que nadie miró.', 'alerta');
      return;
    }

    var fd = new FormData();
    fd.append('razon', razon);

    enviar(folio, 'descartar', fd);
  }

  function escalar(folio) {
    var tipo = window.prompt(
      'Tipo de reporte:\n\n'
      + '  ros  = operación sospechosa (confidencial: no se le dice al cliente)\n'
      + '  rte  = transacción en efectivo sobre el umbral (no implica sospecha)\n'
      + '  rtos = tentativa de operación sospechosa',
      'ros'
    );

    if (tipo === null) { return; }

    var relato = window.prompt(
      'Relato de los hechos. Es lo que leerá la UIF y lo que sostiene el reporte.'
    );

    if (relato === null) { return; }

    if (relato.trim().length < 40) {
      window.avisar('Falta el relato',
        'Describa qué se observó: es lo que sostiene el reporte ante la Fiscalía.',
        'alerta');
      return;
    }

    var fd = new FormData();
    fd.append('tipo', tipo.trim().toLowerCase());
    fd.append('relato', relato);

    enviar(folio, 'escalar', fd);
  }

  function enviar(folio, accion, fd) {
    fd.append('_csrf', tokenVigente());
    fd.append('_csrf_proposito', 'cumplimiento');

    fetch(BASE + folio + '/' + accion, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { window.avisar('No se pudo', j.mensaje, 'critico'); return; }

        var d = j.datos || {};

        if (d.alertas) { pintar(d.alertas); }
        if (d.panorama) { refrescarPanorama(d.panorama); }

        window.avisar('Listo', j.mensaje, 'exito');

        if (window.Vivo) { window.Vivo.refrescar(); }
      })
      .catch(function () {
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  }

  function refrescarPanorama(p) {
    var mapa = {
      'Alertas abiertas':     p.abiertas,
      'Con el plazo vencido': p.vencidas,
      'Reportes en borrador': p.borradores,
      'Remitidos a la UIF':   p.enviados
    };

    Object.keys(mapa).forEach(function (k) {
      var el = document.querySelector('[data-panorama="' + k + '"]');
      if (el) { el.textContent = mapa[k]; }
    });
  }
})();
</script>
