<?php
/**
 * Enviar dinero a otro banco.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $cuentas
 * @var array $beneficiarios
 * @var array $bancos
 * @var array $limites
 * @var array $ventanas
 * @var string $csrf
 */

use CashMan\Http\Plantilla as P;

/* El límite de Transfer365, que es lo que el cliente necesita ver sin
   tener que ir a otra pantalla a buscarlo. */
$limT365 = [];

foreach ($limites as $l) {
    if ($l['canal'] === 'transfer365') {
        $limT365 = $l;
        break;
    }
}

$usables = array_values(array_filter(
    $beneficiarios,
    static fn(array $b): bool => $b['usable'] === true
));

$esperando = array_values(array_filter(
    $beneficiarios,
    static fn(array $b): bool => $b['usable'] !== true && $b['estado'] === 'en_enfriamiento'
));
?>
<div class="row">

  <div class="col-lg-7 col-md-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Transfer365</h4>
        <?php if ($ventanas['transfer365']['abierta']): ?>
          <span class="badge badge-success">Servicio abierto</span>
        <?php else: ?>
          <span class="badge badge-secondary">Cerrado hasta las
            <?= P::e(date('H:i', strtotime((string) $ventanas['transfer365']['abre_a']))) ?></span>
        <?php endif; ?>
      </div>

      <div class="card-body">

        <?php if ($cuentas === []): ?>
          <div class="alert alert-warning mb-0">
            No tiene cuentas activas desde las que enviar.
          </div>

        <?php elseif ($usables === []): ?>
          <!-- ----------------------------------------------------------
               Sin destinos no hay nada que hacer aquí, y decirlo con un
               formulario vacío y gris sería dejar al cliente adivinando.
               Se le dice qué falta y se le da el botón que lo resuelve.
               ---------------------------------------------------------- -->
          <div class="text-center py-4">
            <p class="mb-2"><strong>Todavía no tiene destinos disponibles.</strong></p>
            <p class="text-muted" style="font-size:.9rem">
              Para enviar dinero a otro banco, primero guarde el destino en su
              agenda. Por seguridad, un destino nuevo puede usarse
              <?= (int) \CashMan\Aplicacion\Servicio\ServicioBeneficiarios::ENFRIAMIENTO_HORAS ?>
              horas después de registrarlo.
            </p>

            <?php if ($esperando !== []): ?>
            <div class="alert alert-info d-inline-block text-left" style="font-size:.88rem">
              Tiene <strong><?= count($esperando) ?></strong> destino(s) en espera.
              El primero estará disponible en
              <strong><?= P::e($this_faltan = (function (array $e): string {
                  $min = min(array_map(static fn(array $b): int => (int) $b['faltan_min'], $e));
                  $h   = intdiv($min, 60);
                  return $h > 0 ? $h . ' h ' . ($min % 60) . ' min' : $min . ' min';
              })($esperando)) ?></strong>.
            </div>
            <?php endif; ?>

            <div>
              <a class="btn btn-primary" href="<?= P::attr($rutas->url('otros-bancos/agenda')) ?>">
                <?= cm_icono('user', 16, 'mr-1') ?> Ir a mis destinos
              </a>
            </div>
          </div>

        <?php else: ?>
        <form id="form-inter">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="interbancario">
          <input type="hidden" name="_idem" id="idem" value="">

          <div class="form-group">
            <label for="origen">Cuenta de origen</label>
            <select class="form-control" id="origen" name="origen" required>
              <?php foreach ($cuentas as $c): ?>
              <option value="<?= P::attr($c['codigo']) ?>" data-disp="<?= (int) $c['disponible_cent'] ?>">
                <?= P::e($c['numero_cuenta']) ?> &mdash; <?= P::e($c['producto']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small class="form-text text-muted" id="disp-origen"></small>
          </div>

          <div class="form-group">
            <label for="beneficiario">Destino</label>
            <select class="form-control" id="beneficiario" name="beneficiario" required>
              <?php foreach ($usables as $b): ?>
              <option value="<?= P::attr($b['uuid']) ?>"
                      data-canales="<?= P::attr(implode(',', $b['canales'])) ?>"
                      data-banco="<?= P::attr($b['banco']) ?>"
                      data-titular="<?= P::attr($b['titular']) ?>"
                      data-destino="<?= P::attr($b['destino']) ?>">
                <?= P::e($b['alias']) ?> &mdash; <?= P::e($b['banco']) ?>
                <?= P::e($b['destino']) ?>
              </option>
              <?php endforeach; ?>
            </select>

            <!-- A quién le está mandando, en grande y en palabras.
                 Un número de cuenta no se revisa; un nombre sí. -->
            <div class="alert alert-light border mt-2 mb-0" id="ficha-destino">
              <div style="font-size:.92rem"><strong id="fd-titular"></strong></div>
              <div class="text-muted" style="font-size:.85rem">
                <span id="fd-banco"></span> · <span id="fd-destino"></span>
              </div>
            </div>
          </div>

          <div class="form-group">
            <label for="canal">Servicio</label>
            <select class="form-control" id="canal" name="canal" required></select>
            <small class="form-text text-muted" id="nota-canal"></small>
          </div>

          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="monto">Monto</label>
              <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" id="monto" name="monto"
                       inputmode="decimal" placeholder="0.00" autocomplete="off" required>
              </div>
            </div>
            <div class="form-group col-md-6">
              <label for="concepto">Concepto</label>
              <input type="text" class="form-control" id="concepto" name="concepto"
                     maxlength="160" placeholder="Opcional" autocomplete="off">
            </div>
          </div>

          <button type="button" class="btn btn-outline-primary btn-block" id="btn-codigo">
            Solicitar código de verificación
          </button>

          <div class="d-none mt-3" id="bloque-otp">
            <div class="form-group">
              <label for="codigo-otp">Código de verificación</label>
              <input type="text" class="form-control" id="codigo-otp" name="codigo"
                     inputmode="numeric" maxlength="6" autocomplete="one-time-code">
              <small class="form-text text-muted" id="info-otp"></small>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-block mt-3" id="btn-enviar" disabled>
            Enviar
          </button>
        </form>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <div class="col-lg-5 col-md-12">

    <!-- Lo que le queda hoy, sin ir a otra pantalla a buscarlo -->
    <?php if ($limT365 !== []): ?>
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Su límite de hoy</h4></div>
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <span class="text-muted" style="font-size:.88rem">Disponible hoy</span>
          <strong id="lim-restante">
            $<?= number_format((int) $limT365['restante_cent'] / 100, 2) ?>
          </strong>
        </div>
        <div class="progress mt-2" style="height:6px">
          <?php
          $usado = (int) $limT365['usado_hoy_cent'];
          $tope  = max(1, (int) $limT365['max_diario_cent']);
          $pct   = min(100, (int) round($usado * 100 / $tope));
          ?>
          <div class="progress-bar" id="lim-barra" style="width:<?= $pct ?>%"></div>
        </div>
        <small class="form-text text-muted">
          Usó $<?= number_format($usado / 100, 2) ?> de
          $<?= number_format($tope / 100, 2) ?> ·
          <?= (int) $limT365['ops_hoy'] ?> de <?= (int) $limT365['max_ops_diarias'] ?> envíos
        </small>

        <a class="btn btn-sm btn-outline-secondary btn-block mt-3"
           href="<?= P::attr($rutas->url('otros-bancos/limites')) ?>">
          Cambiar mis límites
        </a>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Cómo funciona</h4></div>
      <div class="card-body" style="font-size:.88rem">
        <p><strong>Transfer365</strong> es el sistema de pagos del Banco Central
           de Reserva. Mueve dinero entre instituciones del sistema financiero
           salvadoreño los siete días de la semana, de
           <?= P::e(substr(\CashMan\Dominio\Interbancario\RedDePagos::T365_ABRE, 0, 5)) ?> a
           <?= P::e(substr(\CashMan\Dominio\Interbancario\RedDePagos::T365_CIERRA, 0, 5)) ?>,
           feriados incluidos. No tiene costo.</p>

        <p><strong>Transfer365 Móvil</strong> hace lo mismo contra el número de
           celular asociado a la cuenta destino, sin que usted necesite saber el
           número de cuenta.</p>

        <p><strong>UNI</strong> es el servicio anterior: solo días hábiles, de
           <?= P::e(substr(\CashMan\Dominio\Interbancario\RedDePagos::UNI_ABRE, 0, 5)) ?> a
           <?= P::e(substr(\CashMan\Dominio\Interbancario\RedDePagos::UNI_CIERRA, 0, 5)) ?>.</p>

        <hr>

        <p class="mb-0 text-muted">Si la institución destino rechaza el envío,
           el dinero vuelve a su cuenta automáticamente y le avisamos. Verá las
           dos líneas en su estado de cuenta: la salida y la devolución.</p>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Mis destinos</h4>
        <a class="btn btn-sm btn-outline-primary"
           href="<?= P::attr($rutas->url('otros-bancos/agenda')) ?>">Administrar</a>
      </div>
      <div class="card-body">
        <?php if ($beneficiarios === []): ?>
          <p class="text-muted mb-0" style="font-size:.88rem">Todavía no tiene destinos guardados.</p>
        <?php else: ?>
        <ul class="list-unstyled mb-0" style="font-size:.88rem">
          <?php foreach (array_slice($beneficiarios, 0, 6) as $b): ?>
          <li class="d-flex justify-content-between align-items-center py-1">
            <span>
              <strong><?= P::e($b['alias']) ?></strong>
              <span class="text-muted"><?= P::e($b['banco']) ?></span>
            </span>
            <?php if ($b['usable']): ?>
              <span class="badge badge-success">listo</span>
            <?php elseif ($b['estado'] === 'bloqueado'): ?>
              <span class="badge badge-danger">bloqueado</span>
            <?php else: ?>
              <span class="badge badge-warning">en espera</span>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var form = document.getElementById('form-inter');
  if (!form) { return; }

  var origen   = document.getElementById('origen');
  var benef    = document.getElementById('beneficiario');
  var canal    = document.getElementById('canal');
  var btnCod   = document.getElementById('btn-codigo');
  var btnEnv   = document.getElementById('btn-enviar');
  var bloqueOtp = document.getElementById('bloque-otp');

  var URL_CODIGO = <?= P::js($rutas->url('otros-bancos/codigo')) ?>;
  var URL_ENVIAR = <?= P::js($rutas->url('otros-bancos/enviar')) ?>;

  var VENTANAS = <?= P::js(json_encode($ventanas, JSON_UNESCAPED_UNICODE)) ?>;
  VENTANAS = JSON.parse(VENTANAS);

  var ETIQUETA = {
    transfer365:       'Transfer365',
    transfer365_movil: 'Transfer365 Móvil',
    uni:               'UNI'
  };

  function nuevaIdem() {
    var a = new Uint8Array(16);
    crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) {
      return ('0' + b.toString(16)).slice(-2);
    }).join('');
  }

  document.getElementById('idem').value = nuevaIdem();

  function dinero(cent) {
    return (window.CMUtil && window.CMUtil.money)
      ? window.CMUtil.money(cent)
      : '$' + (cent / 100).toFixed(2);
  }

  function actualizarDisponible() {
    var o = origen.options[origen.selectedIndex];
    if (!o) { return; }

    document.getElementById('disp-origen').textContent =
      'Disponible: ' + dinero(parseInt(o.getAttribute('data-disp'), 10) || 0);
  }

  /* ------------------------------------------------------------------
     Los servicios que admite ESTE destino.

     No todos los bancos participan en todos los servicios, y un destino
     sin celular no puede recibir por Transfer365 Móvil. Ofrecer una
     opción que el servidor va a rechazar es mandar al cliente contra
     una pared: se construye la lista a partir de lo que de verdad
     acepta el destino elegido.
     ------------------------------------------------------------------ */
  function actualizarCanales() {
    var o = benef.options[benef.selectedIndex];
    if (!o) { return; }

    document.getElementById('fd-titular').textContent = o.getAttribute('data-titular') || '';
    document.getElementById('fd-banco').textContent   = o.getAttribute('data-banco') || '';
    document.getElementById('fd-destino').textContent = o.getAttribute('data-destino') || '';

    var disponibles = (o.getAttribute('data-canales') || '').split(',').filter(Boolean);

    canal.innerHTML = '';

    disponibles.forEach(function (c) {
      var op = document.createElement('option');
      op.value = c;
      op.textContent = ETIQUETA[c] || c;
      canal.appendChild(op);
    });

    notaCanal();
  }

  /* Si el servicio está cerrado se dice AHORA, antes de que el cliente
     teclee el monto y pida un código, no después de enviar. */
  function notaCanal() {
    var nota = document.getElementById('nota-canal');
    var v    = VENTANAS[canal.value];

    if (!v) { nota.textContent = ''; return; }

    if (v.abierta) {
      nota.textContent = 'El servicio está abierto: la transferencia sale de inmediato.';
      nota.className   = 'form-text text-muted';
      return;
    }

    var d = new Date(v.abre_a);

    nota.textContent = 'El servicio está cerrado. Su transferencia quedará programada '
      + 'y saldrá el ' + d.toLocaleDateString('es-SV')
      + ' a las ' + d.toLocaleTimeString('es-SV', { hour: '2-digit', minute: '2-digit' }) + '.';
    nota.className = 'form-text text-warning';
  }

  origen.addEventListener('change', actualizarDisponible);
  benef.addEventListener('change', actualizarCanales);
  canal.addEventListener('change', notaCanal);

  actualizarDisponible();
  actualizarCanales();

  // --- El código de un solo uso --------------------------------------
  btnCod.addEventListener('click', function () {
    var monto = document.getElementById('monto').value.trim();

    if (!monto) {
      window.avisar('Falta el monto', 'Indique cuánto quiere enviar.', 'alerta');
      document.getElementById('monto').focus();
      return;
    }

    btnCod.disabled = true;
    btnCod.textContent = 'Enviando…';

    var fd = new FormData();
    fd.append('_csrf', form._csrf.value);
    fd.append('_csrf_proposito', 'interbancario');
    fd.append('origen', origen.value);
    fd.append('beneficiario', benef.value);
    fd.append('monto', monto);

    fetch(URL_CODIGO, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        btnCod.disabled = false;
        btnCod.textContent = 'Reenviar código';

        if (!j.ok) {
          window.avisar('No se pudo enviar el código', j.mensaje, 'critico');
          return;
        }

        bloqueOtp.classList.remove('d-none');
        btnEnv.disabled = false;

        document.getElementById('info-otp').textContent =
          'Enviado a ' + ((j.datos && j.datos.enviado_a) || 'su correo');

        document.getElementById('codigo-otp').focus();

        window.avisar('Código enviado', j.mensaje, 'exito');
      })
      .catch(function () {
        btnCod.disabled = false;
        btnCod.textContent = 'Solicitar código de verificación';
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      });
  });

  // --- El envío -------------------------------------------------------
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();

    btnEnv.disabled = true;

    var pr = CMProceso.abrir('interbancaria');
    pr.avanzar();

    fetch(URL_ENVIAR, {
      method: 'POST', body: new FormData(form), credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        btnEnv.disabled = false;

        if (!j.ok) {
          pr.fallo(j.mensaje || 'No se pudo completar la operación.', 'validando');
          return;
        }

        pr.listo(j.mensaje || '');

        var d = j.datos || {};

        window.avisar(
          d.estado === 'creada' ? 'Transferencia programada' : 'Transferencia enviada',
          j.mensaje + (d.folio ? ' Folio ' + d.folio + '.' : ''),
          'exito'
        );

        form.reset();
        document.getElementById('idem').value = nuevaIdem();
        bloqueOtp.classList.add('d-none');
        btnEnv.disabled = true;

        /* Los saldos y el límite consumido vuelven en la respuesta: la
           pantalla se pone al día sin recargar. */
        if (d.cuentas) { refrescarCuentas(d.cuentas); }
        if (d.limites) { refrescarLimite(d.limites); }

        actualizarCanales();

        if (window.Vivo) { window.Vivo.refrescar(); }
      })
      .catch(function () {
        btnEnv.disabled = false;
        pr.fallo('No se pudo contactar con el servidor. Revise su conexión.');
      });
  });

  function refrescarCuentas(cuentas) {
    cuentas.forEach(function (c) {
      for (var i = 0; i < origen.options.length; i++) {
        if (origen.options[i].value === c.codigo) {
          origen.options[i].setAttribute('data-disp', c.disponible_cent);
        }
      }
    });

    actualizarDisponible();
  }

  function refrescarLimite(limites) {
    var l = null;

    limites.forEach(function (x) { if (x.canal === 'transfer365') { l = x; } });

    if (!l) { return; }

    var rest = document.getElementById('lim-restante');
    var barra = document.getElementById('lim-barra');

    if (rest)  { rest.textContent = dinero(l.restante_cent); }
    if (barra) {
      barra.style.width =
        Math.min(100, Math.round(l.usado_hoy_cent * 100 / Math.max(1, l.max_diario_cent))) + '%';
    }
  }
})();
</script>
