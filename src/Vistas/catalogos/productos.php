<?php
/**
 * Catálogo de productos.
 *
 * Reúne en una pantalla lo que el sistema anterior repartía entre
 * `consulta-general-productos`, `consulta-especifica-productos`,
 * `registro-nuevos-productos` y `modificar-productos`: el listado con
 * su uso real, el alta y la edición en línea.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $productos
 * @var array  $totales
 * @var array  $tipos
 * @var array  $filtros
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$hayFiltro = ($filtros['tipo'] ?? '') !== '' || ($filtros['q'] ?? '') !== '';

$colorTipo = static fn(string $t): string => match ($t) {
    'ahorro'    => 'success',
    'corriente' => 'info',
    'credito'   => 'warning',
    'tarjeta'   => 'primary',
    default     => 'secondary',
};
?>

<div class="row">
  <?php
  $kpis = [
    ['Productos',   number_format((float) $totales['productos']),  'package', 'primary',
     $totales['activos'] . ' activos'],
    ['Contratados', number_format((float) $totales['contratados']), 'files',  'info',
     'cuentas y créditos vivos'],
    ['Captaciones', P::money((int) $totales['saldo']),              'invest', 'success',
     'saldo en productos de depósito'],
    ['Inactivos',   number_format((float) ($totales['productos'] - $totales['activos'])), 'block', 'secondary',
     'no contratables'],
  ];
  foreach ($kpis as [$t, $v, $i, $c, $pie]): ?>
  <div class="col-xl-3 col-lg-6 col-sm-6">
    <div class="card">
      <div class="card-body d-flex align-items-center">
        <div class="cm-kpi__icono bg-<?= $c ?> text-white mr-3"><?= cm_icono($i, 22) ?></div>
        <div style="min-width:0">
          <h4 class="mb-0 font-weight-bold" style="font-size:1.25rem"><?= P::e($v) ?></h4>
          <small class="text-muted d-block" style="font-size:.76rem"><?= P::e($t) ?></small>
          <small class="text-muted" style="font-size:.7rem"><?= P::e($pie) ?></small>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ==================== ALTA / EDICIÓN ==================== -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0" id="titulo-form">Nuevo producto</h4>
    <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="btn-cancelar">
      Cancelar edición
    </button>
  </div>

  <div class="card-body">
    <form id="form-producto" method="post" action="<?= P::attr($rutas->url('productos')) ?>">
      <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
      <input type="hidden" name="_csrf_proposito" value="catalogo">
      <input type="hidden" name="producto" id="producto-id" value="">

      <div class="form-row">
        <div class="form-group col-lg-2 col-md-3" id="caja-codigo">
          <label for="codigo">Código</label>
          <input type="text" class="form-control" id="codigo" name="codigo"
                 maxlength="40" placeholder="AHO-PER" style="text-transform:uppercase">
          <small class="form-text text-muted" style="font-size:.72rem">No se puede cambiar después.</small>
        </div>

        <div class="form-group col-lg-4 col-md-5">
          <label for="nombre">Nombre</label>
          <input type="text" class="form-control" id="nombre" name="nombre"
                 maxlength="120" required placeholder="Cuenta de Ahorro Personal">
        </div>

        <div class="form-group col-lg-3 col-md-4">
          <label for="tipo">Tipo</label>
          <select class="form-control" id="tipo" name="tipo" required>
            <?php foreach ($tipos as $k => $v): ?>
            <option value="<?= P::attr($k) ?>"><?= P::e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group col-lg-3 col-md-6">
          <label for="activo">Estado</label>
          <select class="form-control" id="activo" name="activo">
            <option value="1">Activo — contratable</option>
            <option value="0">Inactivo — no contratable</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group col-lg-2 col-md-3">
          <label for="tasa">Tasa anual (%)</label>
          <input type="text" class="form-control" id="tasa" name="tasa"
                 value="0.00" inputmode="decimal">
          <small class="form-text text-muted" style="font-size:.72rem">
            Interés que paga o cobra.
          </small>
        </div>

        <div class="form-group col-lg-2 col-md-3">
          <label for="minimo">Saldo mínimo</label>
          <input type="text" class="form-control" id="minimo" name="minimo"
                 value="0.00" inputmode="decimal">
          <small class="form-text text-muted" style="font-size:.72rem">
            Queda inmovilizado.
          </small>
        </div>

        <div class="form-group col-lg-6 col-md-6">
          <label for="descripcion">Descripción</label>
          <input type="text" class="form-control" id="descripcion" name="descripcion"
                 maxlength="500" placeholder="Para qué sirve este producto">
        </div>

        <div class="form-group col-lg-2 col-md-12 d-flex align-items-end">
          <button type="submit" class="btn btn-primary btn-block" id="btn-guardar">
            Crear producto
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ==================== LISTADO ==================== -->
<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">
      Productos <span class="badge badge-light ml-1"><?= count($productos) ?></span>
    </h4>
  </div>

  <div class="card-body pb-2">
    <form method="get" class="form-row align-items-end mb-0">
      <div class="form-group col-md-5">
        <label class="small mb-1">Buscar</label>
        <input type="search" name="q" class="form-control form-control-sm"
               value="<?= P::attr($filtros['q'] ?? '') ?>"
               placeholder="Nombre, código o descripción">
      </div>
      <div class="form-group col-md-3">
        <label class="small mb-1">Tipo</label>
        <select name="tipo" class="form-control form-control-sm">
          <option value="">Todos</option>
          <?php foreach ($tipos as $k => $v): ?>
          <option value="<?= P::attr($k) ?>" <?= ($filtros['tipo'] ?? '') === $k ? 'selected' : '' ?>>
            <?= P::e($v) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group col-md-2">
        <button class="btn btn-outline-primary btn-sm btn-block">Filtrar</button>
      </div>
      <?php if ($hayFiltro): ?>
      <div class="form-group col-md-2">
        <a href="<?= P::attr($rutas->url('productos')) ?>"
           class="btn btn-outline-secondary btn-sm btn-block">Limpiar</a>
      </div>
      <?php endif; ?>
    </form>
  </div>

  <div class="card-body pt-0">
    <?php if ($productos === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('package', 40) ?>
        <p class="mb-2 mt-2">
          <?= $hayFiltro ? 'Ningún producto coincide con el filtro.' : 'No hay productos en el catálogo.' ?>
        </p>
        <?php if ($hayFiltro): ?>
        <a href="<?= P::attr($rutas->url('productos')) ?>"
           class="btn btn-sm btn-outline-secondary">Ver todos</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Código</th>
            <th>Producto</th>
            <th>Tipo</th>
            <th class="text-right">Tasa</th>
            <th class="text-right">Mínimo</th>
            <th class="text-center">Contratos</th>
            <th class="text-right">Saldo</th>
            <th>Estado</th>
            <th class="text-right">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($productos as $p): ?>
          <tr>
            <td class="cm-mono" style="font-size:.8rem"><?= P::e($p['codigo']) ?></td>
            <td>
              <strong style="font-size:.86rem"><?= P::e($p['nombre']) ?></strong>
              <?php if (($p['descripcion'] ?? '') !== ''): ?>
              <small class="text-muted d-block" style="font-size:.73rem">
                <?= P::e(mb_strimwidth((string) $p['descripcion'], 0, 70, '…')) ?>
              </small>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-<?= $colorTipo((string) $p['tipo']) ?>">
                <?= P::e($p['tipo']) ?>
              </span>
            </td>
            <td class="text-right cm-mono">
              <?= number_format((int) $p['tasa_interes_bp'] / 100, 2) ?>%
            </td>
            <td class="text-right cm-mono"><?= P::e(P::money((int) $p['saldo_minimo_cent'])) ?></td>
            <td class="text-center">
              <?php if ($p['en_uso']): ?>
                <span class="badge badge-light"><?= (int) $p['contratados'] ?></span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-right cm-mono">
              <?= (int) $p['saldo_cent'] > 0 ? P::e(P::money((int) $p['saldo_cent'])) : '—' ?>
            </td>
            <td>
              <span class="badge badge-<?= (int) $p['activo'] === 1 ? 'success' : 'secondary' ?>">
                <?= (int) $p['activo'] === 1 ? 'activo' : 'inactivo' ?>
              </span>
            </td>
            <td class="text-right" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary btn-editar"
                      style="font-size:.74rem;padding:.2rem .6rem"
                      data-producto='<?= P::attr(json_encode([
                          'codigo'      => $p['codigo_hash'],
                          'nombre'      => $p['nombre'],
                          'tipo'        => $p['tipo'],
                          'tasa'        => number_format((int) $p['tasa_interes_bp'] / 100, 2, '.', ''),
                          'minimo'      => number_format((int) $p['saldo_minimo_cent'] / 100, 2, '.', ''),
                          'descripcion' => $p['descripcion'],
                          'activo'      => (int) $p['activo'],
                          'en_uso'      => $p['en_uso'],
                      ], JSON_UNESCAPED_UNICODE)) ?>'>
                Editar
              </button>
              <button class="btn btn-sm btn-outline-<?= (int) $p['activo'] === 1 ? 'warning' : 'success' ?> btn-estado"
                      style="font-size:.74rem;padding:.2rem .6rem"
                      data-codigo="<?= P::attr($p['codigo_hash']) ?>"
                      data-cm-confirmar="<?= (int) $p['activo'] === 1
                          ? 'El producto dejará de poder contratarse. Los contratos vigentes siguen su curso.'
                          : 'El producto volverá a estar disponible para contratar.' ?>">
                <?= (int) $p['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var form   = document.getElementById('form-producto');
  var titulo = document.getElementById('titulo-form');
  var btnG   = document.getElementById('btn-guardar');
  var btnX   = document.getElementById('btn-cancelar');
  var cajaC  = document.getElementById('caja-codigo');
  var campoC = document.getElementById('codigo');

  var BASE = <?= P::js($rutas->url('productos/')) ?>;
  /* El token de seguridad se lee vivo, no congelado.

     Aquí había una copia del token tomada al
     dibujar la página. Como el token es de un solo uso, la primera
     acción lo gastaba y la segunda fallaba siempre con «el token de
     seguridad venció»: pedir el código llegaba, pulsar «Ver datos» no.

     El servidor manda el reemplazo en cada respuesta y `cm.js` lo
     guarda en `window.CM_CSRF`. Leer de ahí vale para toda la vida de
     la pantalla, por muchas acciones que se encadenen. */
  window.CM_CSRF = window.CM_CSRF || <?= P::js($csrf) ?>;

  function modoAlta() {
    form.reset();
    document.getElementById('producto-id').value = '';
    titulo.textContent = 'Nuevo producto';
    btnG.textContent = 'Crear producto';
    btnX.classList.add('d-none');
    cajaC.style.display = '';
    campoC.required = true;
    document.getElementById('tipo').disabled = false;
  }

  function modoEdicion(p) {
    document.getElementById('producto-id').value = p.codigo;
    document.getElementById('nombre').value      = p.nombre || '';
    document.getElementById('tipo').value        = p.tipo;
    document.getElementById('tasa').value        = p.tasa;
    document.getElementById('minimo').value      = p.minimo;
    document.getElementById('descripcion').value = p.descripcion || '';
    document.getElementById('activo').value      = String(p.activo);

    titulo.textContent = 'Editando: ' + p.nombre;
    btnG.textContent = 'Guardar cambios';
    btnX.classList.remove('d-none');

    // El código identifica el producto y no se toca una vez creado.
    cajaC.style.display = 'none';
    campoC.required = false;

    // Y el tipo tampoco, si ya hay contratos colgando de él.
    document.getElementById('tipo').disabled = false;
    if (p.en_uso) {
      window.CMAviso.info('Producto en uso',
        'Tiene contratos asociados: no se puede cambiar su tipo.');
    }

    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    document.getElementById('nombre').focus();
  }

  btnX.addEventListener('click', modoAlta);

  document.querySelectorAll('.btn-editar').forEach(function (b) {
    b.addEventListener('click', function () {
      modoEdicion(JSON.parse(b.dataset.producto));
    });
  });

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();

    var espera = window.CMAviso.cargando('Guardando producto…');

    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      espera.cerrar();
      if (j.csrf) { form.querySelector('[name="_csrf"]').value = j.csrf; }
      if (j.ok) { setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 900); }
    })
    .catch(function () { espera.cerrar(); });
  });

  document.querySelectorAll('.btn-estado').forEach(function (b) {
    b.addEventListener('click', function () {
      var fd = new FormData();
      fd.append('_csrf', window.CM_CSRF || window.CM_CSRF);
      fd.append('_csrf_proposito', 'catalogo');

      fetch(BASE + b.dataset.codigo + '/estado', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j.ok) { setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 900); } });
    });
  });

  modoAlta();
})();
</script>
