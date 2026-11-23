<?php
/**
 * Referencias del expediente.
 *
 * Este es el único sitio desde donde se registran, modifican y eliminan.
 * Pertenecen al cliente, no al trámite: sobreviven a la cancelación de
 * una solicitud o a la baja de un crédito, y se reutilizan en el
 * siguiente trámite sin volver a pedirlas.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $titular
 * @var array  $referencias
 * @var int    $verificadas
 * @var int    $minimo
 * @var array  $tipos
 * @var bool   $esMio
 * @var bool   $puedeRegistrar
 * @var bool   $puedeVerificar
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$total  = count($referencias);
$faltan = max(0, $minimo - $total);

$colorTipo = static fn(string $t): string => match ($t) {
    'personal'  => 'primary',
    'laboral'   => 'info',
    'familiar'  => 'success',
    'comercial' => 'warning',
    default     => 'secondary',
};

$accion = $esMio
    ? $rutas->url('referencias')
    : $rutas->url('clientes/' . $titular['codigo'] . '/referencias');
?>

<!-- ==================== ESTADO ==================== -->
<div class="row">
  <div class="col-xl-4 col-lg-6 col-sm-6">
    <div class="card">
      <div class="card-body d-flex align-items-center">
        <div class="cm-kpi__icono bg-primary text-white mr-3"><?= cm_icono('users', 22) ?></div>
        <div>
          <h4 class="mb-0 font-weight-bold" style="font-size:1.3rem"><?= $total ?></h4>
          <small class="text-muted" style="font-size:.76rem">en el expediente</small>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-4 col-lg-6 col-sm-6">
    <div class="card">
      <div class="card-body d-flex align-items-center">
        <div class="cm-kpi__icono bg-success text-white mr-3"><?= cm_icono('check', 22) ?></div>
        <div>
          <h4 class="mb-0 font-weight-bold text-success" style="font-size:1.3rem"><?= $verificadas ?></h4>
          <small class="text-muted" style="font-size:.76rem">verificadas por teléfono</small>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-4 col-lg-12 col-sm-12">
    <div class="card">
      <div class="card-body d-flex align-items-center">
        <div class="cm-kpi__icono bg-<?= $faltan > 0 ? 'danger' : 'info' ?> text-white mr-3">
          <?= cm_icono($faltan > 0 ? 'warning' : 'shield', 22) ?>
        </div>
        <div style="min-width:0">
          <h4 class="mb-0 font-weight-bold" style="font-size:1.3rem">
            <?= $faltan > 0 ? $faltan : 'Cumple' ?>
          </h4>
          <small class="text-muted d-block" style="font-size:.76rem">
            <?= $faltan > 0
                ? 'faltan para el mínimo de ' . (int) $minimo
                : 'mínimo de ' . (int) $minimo . ' cubierto' ?>
          </small>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="cm-aviso cm-aviso--info mb-0">
      <?= cm_icono('info', 17) ?>
      <span>
        <strong>Las referencias son del expediente, no del trámite.</strong>
        Se conservan aunque se cancele una solicitud o se dé de baja un crédito,
        y se reutilizan en el siguiente trámite sin volver a pedirlas ni a
        verificarlas. Solo se modifican o eliminan desde aquí.
      </span>
    </div>
  </div>
</div>

<!-- ==================== ALTA / EDICIÓN ==================== -->
<?php if ($puedeRegistrar): ?>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0" id="titulo-form">Registrar referencia</h4>
    <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="btn-cancelar">
      Cancelar edición
    </button>
  </div>

  <div class="card-body">
    <form id="form-referencia" method="post" action="<?= P::attr($accion) ?>"
          data-alta="<?= P::attr($accion) ?>">
      <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
      <input type="hidden" name="_csrf_proposito" value="referencia">

      <div class="form-row">
        <div class="form-group col-md-3">
          <label for="tipo">Tipo</label>
          <select class="form-control" id="tipo" name="tipo" required>
            <?php foreach ($tipos as $k => $v): ?>
            <option value="<?= P::attr($k) ?>"><?= P::e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group col-md-4">
          <label for="nombres">Nombres</label>
          <input type="text" class="form-control" id="nombres" name="nombres"
                 maxlength="120" required>
        </div>
        <div class="form-group col-md-5">
          <label for="apellidos">Apellidos</label>
          <input type="text" class="form-control" id="apellidos" name="apellidos"
                 maxlength="120" required>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group col-md-3">
          <label for="telefono">Teléfono</label>
          <input type="text" class="form-control" id="telefono" name="telefono" maxlength="20">
        </div>
        <div class="form-group col-md-3">
          <label for="celular">Celular</label>
          <input type="text" class="form-control" id="celular" name="celular" maxlength="20">
        </div>
        <div class="form-group col-md-4">
          <label for="correo">Correo</label>
          <input type="email" class="form-control" id="correo" name="correo" maxlength="190">
        </div>
        <div class="form-group col-md-2">
          <label for="anios_conocer">Años</label>
          <input type="number" class="form-control" id="anios_conocer" name="anios_conocer"
                 min="0" max="100" title="Años de conocerse">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group col-md-3">
          <label for="parentesco">Relación</label>
          <input type="text" class="form-control" id="parentesco" name="parentesco"
                 maxlength="60" placeholder="Amigo, hermano, jefe…">
        </div>
        <div class="form-group col-md-4">
          <label for="empresa">Empresa</label>
          <input type="text" class="form-control" id="empresa" name="empresa" maxlength="160">
        </div>
        <div class="form-group col-md-3">
          <label for="profesion">Profesión</label>
          <input type="text" class="form-control" id="profesion" name="profesion" maxlength="120">
        </div>
        <div class="form-group col-md-2 d-flex align-items-end">
          <button type="submit" class="btn btn-primary btn-block" id="btn-guardar">Registrar</button>
        </div>
      </div>

      <div class="form-group mb-0">
        <label for="direccion">Dirección</label>
        <input type="text" class="form-control" id="direccion" name="direccion" maxlength="255">
      </div>
    </form>

    <small class="text-muted d-block mt-2" style="font-size:.78rem">
      Hace falta al menos un teléfono o celular: la referencia se verifica
      llamando, no con los datos escritos.
    </small>
  </div>
</div>
<?php endif; ?>

<!-- ==================== LISTADO ==================== -->
<div class="card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h4 class="card-title mb-0">
      <?= $esMio ? 'Mis referencias' : 'Referencias de ' . P::e($titular['nombre']) ?>
      <span class="badge badge-light ml-1"><?= $total ?></span>
    </h4>
    <?php if (!$esMio): ?>
    <a href="<?= P::attr($rutas->url('clientes/' . $titular['codigo'])) ?>"
       class="btn btn-sm btn-outline-primary">Ver expediente completo</a>
    <?php endif; ?>
  </div>

  <div class="card-body">
    <?php if ($referencias === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('users', 40) ?>
        <h5 class="mt-3 mb-1" style="font-size:1rem">
          <?= $esMio ? 'No tiene referencias registradas' : 'Sin referencias en el expediente' ?>
        </h5>
        <p class="text-muted mb-0" style="font-size:.88rem;max-width:34rem;margin:0 auto">
          <?php if ($puedeRegistrar): ?>
            Usa el formulario de arriba para añadir las <?= (int) $minimo ?> que
            exige la política de crédito. Quedan en el expediente y sirven para
            todas las solicitudes futuras.
          <?php else: ?>
            Las referencias las registra el personal de atención al cliente.
          <?php endif; ?>
        </p>
      </div>
    <?php else: ?>

    <div class="row">
      <?php foreach ($referencias as $r):
        $ok = (int) $r['verificada'] === 1;
      ?>
      <div class="col-xl-6 mb-3">
        <div class="card mb-0 h-100 <?= $ok ? 'border-success' : '' ?>">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div style="min-width:0">
                <strong style="font-size:.92rem"><?= P::e($r['nombre_completo']) ?></strong>
                <small class="d-block text-muted" style="font-size:.76rem">
                  <?= P::e($r['parentesco'] ?: 'sin relación indicada') ?>
                  <?php if (($r['anios_conocer'] ?? null) !== null): ?>
                    · <?= (int) $r['anios_conocer'] ?> año(s) de conocerse
                  <?php endif; ?>
                </small>
              </div>
              <span class="badge badge-<?= $colorTipo((string) $r['tipo']) ?>">
                <?= P::e($r['tipo_nombre']) ?>
              </span>
            </div>

            <table class="table table-sm mb-2" style="font-size:.8rem">
              <?php foreach ([
                  'Teléfono'  => $r['telefono'] ?? '',
                  'Celular'   => $r['celular'] ?? '',
                  'Correo'    => $r['correo'] ?? '',
                  'Empresa'   => $r['empresa'] ?? '',
                  'Profesión' => $r['profesion'] ?? '',
              ] as $et => $valor):
                if ((string) $valor === '') { continue; } ?>
              <tr>
                <td class="text-muted border-0 py-1" style="width:35%"><?= $et ?></td>
                <td class="text-right border-0 py-1 <?= in_array($et, ['Teléfono','Celular'], true) ? 'cm-mono' : '' ?>"
                    style="font-size:.78rem">
                  <?= P::e($valor) ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </table>

            <?php if (($r['solicitud_folio'] ?? '') !== '' || ($r['credito_folio'] ?? '') !== ''): ?>
            <small class="text-muted d-block mb-2" style="font-size:.72rem">
              Respalda:
              <?php if (($r['credito_folio'] ?? '') !== ''): ?>
                crédito <span class="cm-mono"><?= P::e($r['credito_folio']) ?></span>
              <?php endif; ?>
              <?php if (($r['solicitud_folio'] ?? '') !== ''): ?>
                solicitud <span class="cm-mono"><?= P::e($r['solicitud_folio']) ?></span>
              <?php endif; ?>
            </small>
            <?php endif; ?>

            <?php if ($ok): ?>
            <div class="cm-aviso cm-aviso--exito mb-0" style="font-size:.78rem;padding:.6rem .7rem">
              <?= cm_icono('check', 15) ?>
              <span>
                <strong>Verificada</strong>
                <?php if (($r['verificador'] ?? '') !== ''): ?>
                  por <?= P::e($r['verificador']) ?>
                <?php endif; ?>
                el <?= P::e(P::fecha((string) $r['verificada_en'], 'd/m/Y')) ?>.
                <?php if (($r['notas'] ?? '') !== ''): ?>
                  <br><em><?= P::e($r['notas']) ?></em>
                <?php endif; ?>
              </span>
            </div>
            <?php else: ?>
            <span class="badge badge-warning">sin verificar</span>
            <?php endif; ?>
          </div>

          <?php if ($puedeRegistrar || $puedeVerificar): ?>
          <div class="card-body pt-0 d-flex flex-wrap" style="gap:.4rem">
            <?php if ($puedeRegistrar): ?>
            <button class="btn btn-sm btn-outline-primary btn-editar"
                    style="font-size:.76rem"
                    data-ref='<?= P::attr(json_encode([
                        'codigo'     => $r['codigo'],
                        'tipo'       => $r['tipo'],
                        'nombres'    => $r['nombres'],
                        'apellidos'  => $r['apellidos'],
                        'telefono'   => $r['telefono'],
                        'celular'    => $r['celular'],
                        'correo'     => $r['correo'],
                        'direccion'  => $r['direccion'],
                        'parentesco' => $r['parentesco'],
                        'empresa'    => $r['empresa'],
                        'profesion'  => $r['profesion'],
                        'anios'      => $r['anios_conocer'],
                        'verificada' => $ok,
                    ], JSON_UNESCAPED_UNICODE)) ?>'>
              Editar
            </button>
            <?php endif; ?>

            <?php if ($puedeVerificar && !$esMio): ?>
            <button class="btn btn-sm btn-outline-<?= $ok ? 'secondary' : 'success' ?> btn-verificar"
                    data-codigo="<?= P::attr($r['codigo']) ?>"
                    data-verificada="<?= $ok ? '1' : '0' ?>"
                    data-nombre="<?= P::attr($r['nombre_completo']) ?>"
                    style="font-size:.76rem">
              <?= $ok ? 'Retirar verificación' : 'Verificar' ?>
            </button>
            <?php endif; ?>

            <?php if ($puedeRegistrar && !$ok): ?>
            <button class="btn btn-sm btn-outline-danger btn-eliminar"
                    data-codigo="<?= P::attr($r['codigo']) ?>"
                    data-cm-peligro
                    data-cm-confirmar="<?= P::attr($r['nombre_completo']) ?> se eliminará del expediente."
                    style="font-size:.76rem">
              Eliminar
            </button>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var BASE_R = <?= P::js($rutas->url('referencias/')) ?>;
  /* El token de seguridad se lee vivo, no congelado.

     Aquí había una copia del token tomada al
     dibujar la página. Como el token es de un solo uso, la primera
     acción lo gastaba y la segunda fallaba siempre con «el token de
     seguridad venció»: pedir el código llegaba, pulsar «Ver datos» no.

     El servidor manda el reemplazo en cada respuesta y `cm.js` lo
     guarda en `window.CM_CSRF`. Leer de ahí vale para toda la vida de
     la pantalla, por muchas acciones que se encadenen. */
  window.CM_CSRF = window.CM_CSRF || <?= P::js($csrf) ?>;

  function post(url, datos) {
    var fd = new FormData();
    fd.append('_csrf', window.CM_CSRF || window.CM_CSRF);
    fd.append('_csrf_proposito', 'referencia');
    Object.keys(datos || {}).forEach(function (k) { fd.append(k, datos[k]); });

    return fetch(url, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); });
  }

  // ---------- Alta y edición en el mismo formulario ----------
  var form = document.getElementById('form-referencia');

  if (form) {
    var titulo = document.getElementById('titulo-form');
    var btnG   = document.getElementById('btn-guardar');
    var btnX   = document.getElementById('btn-cancelar');

    function modoAlta() {
      form.reset();
      form.action = form.dataset.alta;
      titulo.textContent = 'Registrar referencia';
      btnG.textContent = 'Registrar';
      btnX.classList.add('d-none');
    }

    btnX.addEventListener('click', modoAlta);

    document.querySelectorAll('.btn-editar').forEach(function (b) {
      b.addEventListener('click', function () {
        var r = JSON.parse(b.dataset.ref);

        form.action = BASE_R + r.codigo;
        titulo.textContent = 'Editando: ' + r.nombres + ' ' + r.apellidos;
        btnG.textContent = 'Guardar cambios';
        btnX.classList.remove('d-none');

        document.getElementById('tipo').value          = r.tipo;
        document.getElementById('nombres').value        = r.nombres || '';
        document.getElementById('apellidos').value      = r.apellidos || '';
        document.getElementById('telefono').value       = r.telefono || '';
        document.getElementById('celular').value        = r.celular || '';
        document.getElementById('correo').value         = r.correo || '';
        document.getElementById('direccion').value      = r.direccion || '';
        document.getElementById('parentesco').value     = r.parentesco || '';
        document.getElementById('empresa').value        = r.empresa || '';
        document.getElementById('profesion').value      = r.profesion || '';
        document.getElementById('anios_conocer').value  = r.anios || '';

        if (r.verificada) {
          window.CMAviso.alerta('Esta referencia está verificada',
            'Si cambias el nombre o los teléfonos, la verificación se anula: '
            + 'lo que se comprobó fue a esa persona en ese número.');
        }

        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        document.getElementById('nombres').focus();
      });
    });

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var espera = window.CMAviso.cargando('Guardando…');

      fetch(form.action, {
        method: 'POST', body: new FormData(form), credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        espera.cerrar();
        if (j.csrf) { form.querySelector('[name="_csrf"]').value = j.csrf; }
        if (j.ok) { setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1100); }
      })
      .catch(function () { espera.cerrar(); });
    });

    modoAlta();
  }

  // ---------- Verificar ----------
  document.querySelectorAll('.btn-verificar').forEach(function (b) {
    b.addEventListener('click', function () {
      if (b.dataset.verificada === '1') {
        post(BASE_R + b.dataset.codigo + '/verificar', {}).then(function (j) {
          if (j.ok) { setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 900); }
        });
        return;
      }

      var modal = document.createElement('div');
      modal.className = 'cm-modal cm-modal--visible';
      modal.innerHTML =
        '<div class="cm-modal__caja" style="max-width:30rem">' +
          '<div class="cm-modal__icono cm-modal__icono--aviso">' +
            '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" ' +
            'stroke-width="2.3"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 ' +
            '19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 ' +
            '12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 ' +
            '012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>' +
          '</div>' +
          '<h5 class="cm-modal__titulo">Verificar a ' + window.CMUtil.escapar(b.dataset.nombre) + '</h5>' +
          '<p class="cm-modal__texto">Anota qué confirmó al contactarla: si conoce al ' +
            'titular, desde cuándo y qué dijo. Sin eso, la verificación no aporta nada ' +
            'a quien evalúe el expediente.</p>' +
          '<textarea class="form-control mb-3" id="campo-notas" rows="3" ' +
            'placeholder="Confirmó que lo conoce desde hace 6 años, compañeros de trabajo…"></textarea>' +
          '<div class="cm-modal__pie">' +
            '<button type="button" class="btn btn-outline-secondary" id="ver-no">Cancelar</button>' +
            '<button type="button" class="btn btn-primary" id="ver-si">Verificar</button>' +
          '</div>' +
        '</div>';

      document.body.appendChild(modal);
      document.body.style.overflow = 'hidden';

      var campo = modal.querySelector('#campo-notas');
      campo.focus();

      function cerrar() { document.body.style.overflow = ''; modal.remove(); }

      modal.querySelector('#ver-no').addEventListener('click', cerrar);
      modal.addEventListener('click', function (ev) { if (ev.target === modal) { cerrar(); } });

      modal.querySelector('#ver-si').addEventListener('click', function () {
        var notas = campo.value.trim();
        if (notas.length < 10) {
          window.CMAviso.alerta('Falta la nota',
            'Escriba al menos una frase sobre lo que confirmó la referencia.');
          campo.focus();
          return;
        }

        var espera = window.CMAviso.cargando('Guardando la verificación…');
        cerrar();

        post(BASE_R + b.dataset.codigo + '/verificar', { notas: notas }).then(function (j) {
          espera.cerrar();
          if (j.ok) { setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1000); }
        }).catch(function () { espera.cerrar(); });
      });
    });
  });

  // ---------- Eliminar ----------
  document.querySelectorAll('.btn-eliminar').forEach(function (b) {
    b.addEventListener('click', function () {
      post(BASE_R + b.dataset.codigo + '/eliminar', {}).then(function (j) {
        if (j.ok) { setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 900); }
      });
    });
  });
})();
</script>
