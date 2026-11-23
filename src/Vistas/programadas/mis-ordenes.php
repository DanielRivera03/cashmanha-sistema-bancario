<?php
/**
 * Órdenes programadas y débito automático.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $ordenes
 * @var array $cuentas
 * @var array $tarjetas
 * @var string $csrf
 */

use CashMan\Http\Plantilla as P;
?>
<div class="row">

  <div class="col-lg-5 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Programar una orden</h4></div>
      <div class="card-body">

        <?php if ($cuentas === []): ?>
          <div class="alert alert-warning mb-0">
            Necesita una cuenta activa para programar órdenes.
          </div>
        <?php else: ?>

        <form id="form-orden">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="programadas">

          <div class="form-group">
            <label for="tipo">¿Qué quiere que se pague solo?</label>
            <select class="form-control" id="tipo" name="tipo">
              <option value="transferencia">Transferir a otra cuenta de CashMan</option>
              <?php if ($tarjetas !== []): ?>
              <option value="tarjeta">Pagar mi tarjeta de crédito</option>
              <?php endif; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="alias">¿Cómo quiere llamarla?</label>
            <input type="text" class="form-control" id="alias" name="alias"
                   maxlength="60" placeholder="Alquiler, Colegio, Tarjeta…">
          </div>

          <div class="form-group" data-para="transferencia">
            <label for="cuenta_destino">Cuenta destino</label>
            <select class="form-control" id="cuenta_destino" name="cuenta_destino">
              <?php foreach ($cuentas as $c): ?>
              <option value="<?= P::attr($c['codigo']) ?>">
                <?= P::e($c['numero_cuenta']) ?> &mdash; <?= P::e($c['producto']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small class="form-text text-muted">
              Para mandar a otro banco, primero guarde el destino en su agenda.
            </small>
          </div>

          <div class="form-group d-none" data-para="tarjeta">
            <label for="tarjeta">¿Cuál tarjeta?</label>
            <select class="form-control" id="tarjeta" name="tarjeta">
              <?php foreach ($tarjetas as $t): ?>
              <option value="<?= P::attr($t['codigo']) ?>">
                ••••<?= P::e($t['ultimos4']) ?> &mdash; debe
                $<?= number_format((int) $t['saldo_usado_cent'] / 100, 2) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group d-none" data-para="tarjeta">
            <label for="modo_monto">¿Cuánto pagar?</label>
            <select class="form-control" id="modo_monto" name="modo_monto">
              <option value="minimo">El pago mínimo del corte</option>
              <option value="total">El saldo completo</option>
              <option value="fijo">Un monto fijo</option>
            </select>
            <small class="form-text text-muted">
              El mínimo es el que evita la mora. Un monto fijo puede quedarse corto
              si el mínimo sube.
            </small>
          </div>

          <div class="form-group" id="grupo-monto">
            <label for="monto">Monto</label>
            <div class="input-group">
              <div class="input-group-prepend"><span class="input-group-text">$</span></div>
              <input type="text" class="form-control" id="monto" name="monto"
                     inputmode="decimal" placeholder="0.00">
            </div>
          </div>

          <div class="form-group d-none" id="grupo-tope">
            <label for="tope">Tope por si acaso <span class="text-muted">(opcional)</span></label>
            <div class="input-group">
              <div class="input-group-prepend"><span class="input-group-text">$</span></div>
              <input type="text" class="form-control" id="tope" name="tope"
                     inputmode="decimal" placeholder="Sin tope">
            </div>
            <small class="form-text text-muted">
              Si el cargo supera este monto, <strong>no se cobra nada</strong> y le
              avisamos. Protege de un mes con una compra grande.
            </small>
          </div>

          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="frecuencia">¿Cada cuánto?</label>
              <select class="form-control" id="frecuencia" name="frecuencia">
                <option value="mensual">Cada mes</option>
                <option value="quincenal">Cada quincena</option>
                <option value="semanal">Cada semana</option>
                <option value="bimestral">Cada dos meses</option>
                <option value="trimestral">Cada tres meses</option>
                <option value="semestral">Cada seis meses</option>
                <option value="anual">Cada año</option>
                <option value="unica">Una sola vez</option>
              </select>
            </div>
            <div class="form-group col-md-6" id="grupo-dia">
              <label for="dia_mes">¿Qué día del mes?</label>
              <input type="number" class="form-control" id="dia_mes" name="dia_mes"
                     min="1" max="31" placeholder="5">
              <small class="form-text text-muted">
                El 31 significa «el último día».
              </small>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="fecha_inicio">Empezar el</label>
              <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio"
                     value="<?= P::attr(date('Y-m-d')) ?>" min="<?= P::attr(date('Y-m-d')) ?>">
            </div>
            <div class="form-group col-md-6">
              <label for="fecha_fin">Hasta <span class="text-muted">(opcional)</span></label>
              <input type="date" class="form-control" id="fecha_fin" name="fecha_fin">
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-block">Programar</button>
        </form>

        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Qué pasa si no hay saldo</h4></div>
      <div class="card-body" style="font-size:.88rem">
        <p>No nos rendimos al primer intento: <strong>reintentamos tres días</strong>.
           Que no haya fondos el día 5 no significa que no los haya el 6.</p>

        <p>Si tras esos intentos sigue sin poder cobrarse, <strong>le avisamos</strong>,
           ese periodo queda sin pagar y la orden sigue viva para el siguiente. Nunca
           acumulamos periodos: un cargo sorpresa de tres meses de golpe sería peor
           que el problema.</p>

        <p class="mb-0">Y <strong>nunca cobramos dos veces</strong> el mismo periodo,
           aunque el sistema corra el proceso varias veces.</p>
      </div>
    </div>
  </div>

  <div class="col-lg-7 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Mis órdenes</h4></div>
      <div class="card-body">
        <div id="lista-ordenes"></div>
      </div>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var URL_CREAR = <?= P::js($rutas->url('programadas/crear')) ?>;
  var BASE      = <?= P::js($rutas->url('programadas/')) ?>;

  var CSRF_SEMILLA = <?= P::js($csrf) ?>;

  function tokenVigente() {
    return (window.CMToken && window.CMToken.de('programadas')) || CSRF_SEMILLA;
  }

  var ORDENES = JSON.parse(<?= P::js(json_encode($ordenes, JSON_UNESCAPED_UNICODE)) ?>);

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

  var FREC = {
    unica: 'una vez', semanal: 'cada semana', quincenal: 'cada quincena',
    mensual: 'cada mes', bimestral: 'cada 2 meses', trimestral: 'cada 3 meses',
    semestral: 'cada 6 meses', anual: 'cada año'
  };

  var RESULTADO = {
    exitosa: ['success', 'se cobró'],
    sin_fondos: ['warning', 'sin saldo'],
    omitida: ['secondary', 'omitida'],
    rechazada: ['danger', 'rechazada'],
    error: ['danger', 'error']
  };

  function pintar(lista) {
    ORDENES = lista || [];

    var caja = document.getElementById('lista-ordenes');

    if (!ORDENES.length) {
      caja.innerHTML = '<p class="text-muted mb-0">Todavía no tiene órdenes programadas.</p>';
      return;
    }

    var html = '';

    ORDENES.forEach(function (o) {
      var activa = o.estado === 'activa';

      var destino = o.destino_cuenta ? ('cuenta ' + o.destino_cuenta)
        : (o.tarjeta ? ('tarjeta ••••' + o.tarjeta)
        : (o.convenio || o.beneficiario || ''));

      html += '<div class="border rounded p-3 mb-3" data-folio="' + esc(o.folio) + '">'
        + '<div class="d-flex justify-content-between align-items-start flex-wrap">'
        + '<div><strong>' + esc(o.alias || o.folio) + '</strong>'
        + '<br><small class="text-muted">'
        + (o.modo_monto === 'fijo' ? dinero(o.monto_cent)
           : (o.modo_monto === 'minimo' ? 'el pago mínimo' : 'el saldo completo'))
        + ' · ' + esc(FREC[o.frecuencia] || o.frecuencia)
        + (destino ? ' · a ' + esc(destino) : '')
        + '</small></div>'
        + '<div class="text-right">'
        + '<span class="badge badge-' + (activa ? 'success'
            : (o.estado === 'pausada' ? 'warning' : 'secondary')) + '">'
        + esc(o.estado) + '</span>'
        + (activa ? '<br><small class="text-muted">próxima: ' + esc(o.proxima_en) + '</small>' : '')
        + '</div></div>';

      if (Number(o.tope_cent) > 0) {
        html += '<small class="text-muted d-block mt-1">Tope: '
              + dinero(o.tope_cent) + '</small>';
      }

      if (Number(o.fallos_consecutivos) > 0) {
        html += '<div class="alert alert-warning mt-2 mb-0" style="font-size:.84rem">'
          + 'Lleva ' + esc(o.fallos_consecutivos) + ' periodo(s) sin poder cobrarse.'
          + '</div>';
      }

      /* Las últimas ejecuciones: es lo que responde «¿por qué no se
         pagó el mes pasado?», que es lo que la gente pregunta. */
      if (o.ultimas && o.ultimas.length) {
        html += '<div class="mt-2" style="font-size:.8rem">';

        o.ultimas.forEach(function (e) {
          var r = RESULTADO[e.resultado] || ['secondary', e.resultado];

          html += '<div class="d-flex justify-content-between border-top py-1">'
            + '<span>' + esc(e.prevista_para) + ' · '
            + '<span class="badge badge-' + r[0] + '">' + esc(r[1]) + '</span></span>'
            + '<span class="text-muted">' + dinero(e.monto_cent) + '</span>'
            + '</div>'
            + (e.detalle ? '<div class="text-muted pl-1">' + esc(e.detalle) + '</div>' : '');
        });

        html += '</div>';
      }

      html += '<div class="mt-2">';

      if (activa) {
        html += '<button class="btn btn-sm btn-outline-warning mr-1" data-accion="pausar">Pausar</button>';
      } else if (o.estado === 'pausada') {
        html += '<button class="btn btn-sm btn-outline-success mr-1" data-accion="reanudar">Reanudar</button>';
      }

      if (activa || o.estado === 'pausada') {
        html += '<button class="btn btn-sm btn-outline-danger" data-accion="cancelar">Cancelar</button>';
      }

      html += '</div></div>';
    });

    caja.innerHTML = html;
  }

  pintar(ORDENES);

  // --- El formulario cambia según el tipo --------------------------
  var tipo = document.getElementById('tipo');

  function ajustar() {
    if (!tipo) { return; }

    var t = tipo.value;

    Array.prototype.forEach.call(
      document.querySelectorAll('[data-para]'),
      function (el) {
        el.classList.toggle('d-none', el.getAttribute('data-para') !== t);
      }
    );

    var modo = document.getElementById('modo_monto');
    var esFijo = t !== 'tarjeta' || !modo || modo.value === 'fijo';

    document.getElementById('grupo-monto').classList.toggle('d-none', !esFijo);
    document.getElementById('grupo-tope').classList.toggle('d-none', esFijo);
  }

  if (tipo) {
    tipo.addEventListener('change', ajustar);

    var modo = document.getElementById('modo_monto');
    if (modo) { modo.addEventListener('change', ajustar); }

    document.getElementById('frecuencia').addEventListener('change', function () {
      /* El día del mes solo tiene sentido en frecuencias mensuales o
         mayores: preguntarlo para una semanal confunde. */
      var mensuales = ['mensual', 'bimestral', 'trimestral', 'semestral', 'anual'];

      document.getElementById('grupo-dia').classList.toggle(
        'd-none', mensuales.indexOf(this.value) === -1
      );
    });

    ajustar();
  }

  var form = document.getElementById('form-orden');

  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();

      var fd = new FormData(form);
      fd.set('_csrf', tokenVigente());

      fetch(URL_CREAR, {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { window.avisar('No se pudo programar', j.mensaje, 'critico'); return; }

          window.avisar('Orden programada', j.mensaje, 'exito');

          if (j.datos && j.datos.ordenes) { pintar(j.datos.ordenes); }

          form.reset();
          ajustar();

          if (window.Vivo) { window.Vivo.refrescar(); }
        })
        .catch(function () {
          window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
        });
    });
  }

  // --- Pausar, reanudar, cancelar ----------------------------------
  document.getElementById('lista-ordenes').addEventListener('click', function (ev) {
    var accion = ev.target.getAttribute && ev.target.getAttribute('data-accion');
    if (!accion) { return; }

    var folio = ev.target.closest('[data-folio]').getAttribute('data-folio');

    var hacer = function () {
      var fd = new FormData();
      fd.append('_csrf', tokenVigente());
      fd.append('_csrf_proposito', 'programadas');

      fetch(BASE + folio + '/' + accion, {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { window.avisar('No se pudo', j.mensaje, 'critico'); return; }

          window.avisar('Listo', j.mensaje, 'exito');

          if (j.datos && j.datos.ordenes) { pintar(j.datos.ordenes); }
        })
        .catch(function () {
          window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
        });
    };

    /* Cancelar es irreversible; pausar no. Solo se pregunta por lo
       que no se puede deshacer. */
    if (accion === 'cancelar') {
      window.CMAviso.confirmar({
        titulo: '¿Cancelar esta orden?',
        texto: 'No se podrá reactivar: habría que crearla de nuevo. '
             + 'Si solo quiere pararla un tiempo, use Pausar.',
        alAceptar: hacer
      });

      return;
    }

    hacer();
  });
})();
</script>
