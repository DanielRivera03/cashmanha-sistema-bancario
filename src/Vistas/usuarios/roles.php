<?php
/**
 * Roles y permisos.
 *
 * Reúne `consulta-general-roles-usuarios`, `registrar-nuevos-roles` y
 * `modificar-roles-usuarios` del sistema anterior. La matriz se edita
 * celda a celda: así se ve el efecto al momento y cada concesión queda
 * auditada por separado.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $roles
 * @var array  $grupos
 * @var array  $matriz
 * @var int    $total
 * @var int    $miNivel
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

/** ¿Puede el actor tocar este rol? Solo los de nivel inferior. */
$editable = static fn(array $r): bool => (int) $r['nivel'] < $miNivel;
?>

<!-- ==================== LOS ROLES ==================== -->
<div class="card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h4 class="card-title mb-0">
      Roles <span class="badge badge-light ml-1"><?= count($roles) ?></span>
    </h4>
    <button class="btn btn-sm btn-primary" id="btn-nuevo-rol">Nuevo rol</button>
  </div>

  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Rol</th>
            <th>Clave</th>
            <th class="text-center">Nivel</th>
            <th class="text-center">Usuarios</th>
            <th class="text-center">Permisos</th>
            <th>Descripción</th>
            <th class="text-right"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($roles as $r):
            $id = (int) $r['id'];
            $permisos = count($matriz[$id] ?? []);
            $puedo = $editable($r);
          ?>
          <tr<?= $puedo ? '' : ' class="table-light"' ?>>
            <td>
              <strong style="font-size:.88rem"><?= P::e($r['nombre']) ?></strong>
              <?php if (!$puedo): ?>
                <small class="text-muted d-block" style="font-size:.7rem">
                  su nivel no alcanza para editarlo
                </small>
              <?php endif; ?>
            </td>
            <td class="cm-mono text-muted" style="font-size:.78rem"><?= P::e($r['clave']) ?></td>
            <td class="text-center">
              <span class="badge badge-<?= $puedo ? 'secondary' : 'dark' ?>"><?= (int) $r['nivel'] ?></span>
            </td>
            <td class="text-center">
              <?php if ((int) ($r['usuarios'] ?? 0) > 0): ?>
                <span class="badge badge-light"><?= (int) $r['usuarios'] ?></span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <span class="badge badge-primary"><?= $permisos ?></span>
              <small class="text-muted" style="font-size:.7rem">/ <?= (int) $total ?></small>
            </td>
            <td class="text-muted" style="font-size:.8rem">
              <?= P::e(mb_strimwidth((string) ($r['descripcion'] ?? ''), 0, 55, '…')) ?>
            </td>
            <td class="text-right" style="white-space:nowrap">
              <?php if ($puedo): ?>
              <button class="btn btn-sm btn-outline-primary btn-editar-rol"
                      style="font-size:.73rem;padding:.18rem .55rem"
                      data-rol='<?= P::attr(json_encode([
                          'id'          => $id,
                          'nombre'      => $r['nombre'],
                          'nivel'       => (int) $r['nivel'],
                          'descripcion' => $r['descripcion'],
                      ], JSON_UNESCAPED_UNICODE)) ?>'>Editar</button>

                <?php if ((int) ($r['usuarios'] ?? 0) === 0): ?>
                <button class="btn btn-sm btn-outline-danger btn-borrar-rol"
                        style="font-size:.73rem;padding:.18rem .55rem"
                        data-id="<?= $id ?>"
                        data-cm-peligro
                        data-cm-confirmar="El rol <?= P::attr($r['nombre']) ?> se eliminará junto con sus permisos.">
                  Eliminar
                </button>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ==================== ALTA / EDICIÓN ==================== -->
<div class="card d-none" id="caja-rol">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0" id="titulo-rol">Nuevo rol</h4>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-cerrar-rol">Cerrar</button>
  </div>

  <div class="card-body">
    <form id="form-rol" method="post" action="<?= P::attr($rutas->url('roles')) ?>">
      <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
      <input type="hidden" name="_csrf_proposito" value="rol">

      <div class="form-row">
        <div class="form-group col-md-4">
          <label for="nombre">Nombre</label>
          <input type="text" class="form-control" id="nombre" name="nombre"
                 maxlength="80" required placeholder="Auditor interno">
        </div>

        <div class="form-group col-md-3" id="caja-clave">
          <label for="clave">Clave</label>
          <input type="text" class="form-control cm-mono" id="clave" name="clave"
                 maxlength="40" placeholder="auditor_interno">
          <small class="form-text text-muted" style="font-size:.72rem">
            No se puede cambiar después.
          </small>
        </div>

        <div class="form-group col-md-2">
          <label for="nivel">Nivel</label>
          <input type="number" class="form-control" id="nivel" name="nivel"
                 min="1" max="<?= max(1, (int) $miNivel - 1) ?>" value="10" required>
          <small class="form-text text-muted" style="font-size:.72rem">
            Máximo <?= max(1, (int) $miNivel - 1) ?> (el suyo es <?= (int) $miNivel ?>).
          </small>
        </div>

        <div class="form-group col-md-3 d-flex align-items-end">
          <button type="submit" class="btn btn-primary btn-block" id="btn-guardar-rol">
            Crear rol
          </button>
        </div>
      </div>

      <div class="form-group mb-0">
        <label for="descripcion">Descripción</label>
        <input type="text" class="form-control" id="descripcion" name="descripcion"
               maxlength="255" placeholder="Qué hace quien lleva este rol">
      </div>
    </form>

    <div class="cm-aviso cm-aviso--info mt-3 mb-0">
      <?= cm_icono('info', 17) ?>
      <span>
        <strong>Sobre el nivel.</strong> Decide a quién puede tocar quien lleve
        el rol: solo a usuarios y roles de nivel inferior al suyo. Por eso no
        puede crear uno de nivel igual o superior al suyo — sería fabricarse un
        rol para después asignárselo.
      </span>
    </div>
  </div>
</div>

<!-- ==================== MATRIZ ==================== -->
<div class="card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h4 class="card-title mb-0">Matriz de permisos</h4>
    <span class="badge badge-light"><?= (int) $total ?> permisos</span>
  </div>

  <div class="card-body pb-0">
    <p class="text-muted mb-2" style="font-size:.86rem">
      Pulsa una celda para dar o quitar el permiso. El sistema comprueba el
      permiso en cada operación, nunca el nombre del rol. Al cambiar uno se
      cierran las sesiones abiertas de ese rol, porque llevan los permisos
      viejos cargados.
    </p>
  </div>

  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-bordered mb-0 cm-matriz" style="font-size:.8rem">
        <thead style="position:sticky;top:0;z-index:3;background:#fff">
          <tr>
            <th style="min-width:15rem">Permiso</th>
            <?php foreach ($roles as $r): ?>
            <th class="text-center" style="min-width:5.5rem">
              <?= P::e($r['nombre']) ?>
              <small class="d-block text-muted" style="font-size:.68rem;font-weight:400">
                nivel <?= (int) $r['nivel'] ?>
              </small>
            </th>
            <?php endforeach; ?>
          </tr>
        </thead>

        <tbody>
          <?php foreach ($grupos as $modulo => $permisos): ?>
          <tr class="table-light">
            <th colspan="<?= count($roles) + 1 ?>"
                style="text-transform:uppercase;letter-spacing:.05em;font-size:.72rem">
              <?= P::e(str_replace('_', ' ', (string) $modulo)) ?>
            </th>
          </tr>

          <?php foreach ($permisos as $p): ?>
          <tr>
            <td>
              <span class="cm-mono" style="font-size:.76rem"><?= P::e($p['clave']) ?></span>
              <small class="text-muted d-block" style="font-size:.72rem">
                <?= P::e($p['descripcion']) ?>
              </small>
            </td>

            <?php foreach ($roles as $r):
              $rid   = (int) $r['id'];
              $tiene = isset($matriz[$rid][(int) $p['id']]);
              $puedo = $editable($r);
            ?>
            <td class="text-center cm-celda <?= $puedo ? 'cm-celda--editable' : '' ?>"
                <?php if ($puedo): ?>
                data-rol="<?= $rid ?>"
                data-permiso="<?= P::attr($p['clave']) ?>"
                data-tiene="<?= $tiene ? '1' : '0' ?>"
                title="Pulsa para <?= $tiene ? 'quitar' : 'conceder' ?>"
                <?php else: ?>
                title="Tu nivel no alcanza para editar este rol"
                <?php endif; ?>>
              <span class="cm-marca"><?= $tiene ? '&#10003;' : '&middot;' ?></span>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var BASE = <?= P::js($rutas->url('roles')) ?>;
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
    fd.append('_csrf_proposito', 'rol');
    Object.keys(datos || {}).forEach(function (k) { fd.append(k, datos[k]); });

    return fetch(url, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); });
  }

  // ---------- Alta y edición ----------
  var caja    = document.getElementById('caja-rol');
  var form    = document.getElementById('form-rol');
  var titulo  = document.getElementById('titulo-rol');
  var btnG    = document.getElementById('btn-guardar-rol');
  var cajaCl  = document.getElementById('caja-clave');
  var campoCl = document.getElementById('clave');

  function modoAlta() {
    form.reset();
    form.action = BASE;
    titulo.textContent = 'Nuevo rol';
    btnG.textContent = 'Crear rol';
    cajaCl.style.display = '';
    campoCl.required = true;
    caja.classList.remove('d-none');
    document.getElementById('nombre').focus();
  }

  document.getElementById('btn-nuevo-rol').addEventListener('click', function () {
    modoAlta();
    caja.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });

  document.getElementById('btn-cerrar-rol').addEventListener('click', function () {
    caja.classList.add('d-none');
  });

  document.querySelectorAll('.btn-editar-rol').forEach(function (b) {
    b.addEventListener('click', function () {
      var r = JSON.parse(b.dataset.rol);

      form.action = BASE + '/' + r.id;
      titulo.textContent = 'Editando: ' + r.nombre;
      btnG.textContent = 'Guardar cambios';

      // La clave identifica al rol en el código: no se cambia.
      cajaCl.style.display = 'none';
      campoCl.required = false;

      document.getElementById('nombre').value      = r.nombre || '';
      document.getElementById('nivel').value       = r.nivel;
      document.getElementById('descripcion').value = r.descripcion || '';

      caja.classList.remove('d-none');
      caja.scrollIntoView({ behavior: 'smooth', block: 'center' });
      document.getElementById('nombre').focus();
    });
  });

  // La clave se sugiere a partir del nombre, pero se puede cambiar.
  document.getElementById('nombre').addEventListener('input', function () {
    if (campoCl.dataset.tocado === '1' || cajaCl.style.display === 'none') { return; }
    campoCl.value = this.value.toLowerCase()
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 40);
  });
  campoCl.addEventListener('input', function () { campoCl.dataset.tocado = '1'; });

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var espera = window.CMAviso.cargando('Guardando el rol…');

    fetch(form.action, {
      method: 'POST', body: new FormData(form), credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      espera.cerrar();
      if (j.csrf) { form.querySelector('[name="_csrf"]').value = j.csrf; }
      if (j.ok) { setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1000); }
    })
    .catch(function () { espera.cerrar(); });
  });

  // ---------- Eliminar ----------
  document.querySelectorAll('.btn-borrar-rol').forEach(function (b) {
    b.addEventListener('click', function () {
      post(BASE + '/' + b.dataset.id + '/eliminar', {}).then(function (j) {
        if (j.ok) { setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1000); }
      });
    });
  });

  // ---------- Matriz ----------
  document.querySelectorAll('.cm-celda--editable').forEach(function (celda) {
    celda.addEventListener('click', function () {
      if (celda.dataset.ocupada === '1') { return; }
      celda.dataset.ocupada = '1';

      var marca = celda.querySelector('.cm-marca');
      var antes = marca.innerHTML;
      marca.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

      post(BASE + '/' + celda.dataset.rol + '/permiso',
           { permiso: celda.dataset.permiso })
        .then(function (j) {
          celda.dataset.ocupada = '0';

          if (!j.ok) { marca.innerHTML = antes; return; }

          // Se actualiza la celda en el sitio: recargar la matriz
          // entera perdería el desplazamiento y la posición.
          var concedido = j.datos && j.datos.concedido;
          marca.innerHTML = concedido ? '&#10003;' : '&middot;';
          celda.dataset.tiene = concedido ? '1' : '0';
          celda.title = 'Pulsa para ' + (concedido ? 'quitar' : 'conceder');
        })
        .catch(function () {
          celda.dataset.ocupada = '0';
          marca.innerHTML = antes;
        });
    });
  });
})();
</script>
