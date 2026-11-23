<?php
/**
 * Límites operativos por rol.
 *
 * Esta pantalla no existía en el sistema anterior: allí el tope de
 * $2,000 por transferencia vivía en el JavaScript del formulario, de
 * modo que cualquiera con las herramientas del navegador podía
 * saltárselo. Aquí los límites están en la base, por rol y operación,
 * y es el servidor quien los aplica antes de mover un centavo.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $porOperacion
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$nombreOperacion = static fn(string $o): string => match ($o) {
    'transferencia'       => 'Transferencias',
    'transferencia_terceros' => 'Transferencias a terceros',
    'deposito'            => 'Depósitos',
    'retiro'              => 'Retiros',
    'pago_cuota'          => 'Pago de cuotas',
    'pago_tarjeta'        => 'Pago de tarjeta',
    default               => ucfirst(str_replace('_', ' ', $o)),
};

/** Un valor en cero significa "sin tope". */
$importe = static fn(int $c): string => $c > 0 ? P::money($c) : 'sin tope';
?>

<div class="card">
  <div class="card-body">
    <div class="cm-aviso cm-aviso--info mb-0">
      <?= cm_icono('shield', 17) ?>
      <span>
        <strong>Estos límites los aplica el servidor.</strong>
        En el sistema anterior el tope de $2,000 estaba escrito en el JavaScript
        del formulario: bastaba con editarlo desde el navegador para saltárselo.
        Aquí cada operación se comprueba contra estos valores antes de ejecutarse,
        y cada cambio queda en la bitácora de auditoría con su antes y su después.
      </span>
    </div>
  </div>
</div>

<?php if ($porOperacion === []): ?>
<div class="card">
  <div class="card-body">
    <div class="cm-vacio">
      <?= cm_icono('shield', 40) ?>
      <p class="mb-0 mt-2">No hay límites configurados.</p>
    </div>
  </div>
</div>
<?php endif; ?>

<?php foreach ($porOperacion as $operacion => $filas): ?>
<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0"><?= P::e($nombreOperacion((string) $operacion)) ?></h4>
  </div>

  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Rol</th>
            <th class="text-right">Por operación</th>
            <th class="text-right">Diario</th>
            <th class="text-right">Mensual</th>
            <th class="text-center">Ops/día</th>
            <th class="text-center">Código OTP</th>
            <th class="text-right">Doble control</th>
            <th>Estado</th>
            <th class="text-right" style="width:1%;white-space:nowrap">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($filas as $l): ?>
          <tr>
            <td><strong style="font-size:.86rem"><?= P::e($l['rol']) ?></strong></td>
            <td class="text-right cm-mono"><?= P::e($importe((int) $l['max_por_operacion_cent'])) ?></td>
            <td class="text-right cm-mono"><?= P::e($importe((int) $l['max_diario_cent'])) ?></td>
            <td class="text-right cm-mono"><?= P::e($importe((int) $l['max_mensual_cent'])) ?></td>
            <td class="text-center">
              <?= (int) $l['max_ops_diarias'] > 0 ? (int) $l['max_ops_diarias'] : '<span class="text-muted">—</span>' ?>
            </td>
            <td class="text-center">
              <?php if ((int) $l['requiere_otp'] === 1): ?>
                <span class="badge badge-success">exigido</span>
              <?php else: ?>
                <span class="badge badge-light">no</span>
              <?php endif; ?>
            </td>
            <td class="text-right cm-mono">
              <?= (int) $l['umbral_doble_control_cent'] > 0
                  ? P::e(P::money((int) $l['umbral_doble_control_cent']))
                  : '<span class="text-muted">—</span>' ?>
            </td>
            <td>
              <span class="badge badge-<?= (int) $l['activo'] === 1 ? 'success' : 'secondary' ?>">
                <?= (int) $l['activo'] === 1 ? 'activo' : 'inactivo' ?>
              </span>
            </td>
            <td class="text-right">
              <button class="btn btn-sm btn-outline-primary btn-editar"
                      style="font-size:.74rem;padding:.2rem .6rem"
                      data-limite='<?= P::attr(json_encode([
                          'codigo'        => $l['codigo'],
                          'rol'           => $l['rol'],
                          'operacion'     => $nombreOperacion((string) $operacion),
                          'por_operacion' => number_format((int) $l['max_por_operacion_cent'] / 100, 2, '.', ''),
                          'diario'        => number_format((int) $l['max_diario_cent'] / 100, 2, '.', ''),
                          'mensual'       => number_format((int) $l['max_mensual_cent'] / 100, 2, '.', ''),
                          'ops'           => (int) $l['max_ops_diarias'],
                          'otp'           => (int) $l['requiere_otp'],
                          'umbral'        => number_format((int) $l['umbral_doble_control_cent'] / 100, 2, '.', ''),
                          'activo'        => (int) $l['activo'],
                      ], JSON_UNESCAPED_UNICODE)) ?>'>
                Editar
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- ==================== EDICIÓN ==================== -->
<div class="card d-none" id="caja-edicion">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0" id="titulo-edicion">Editar límite</h4>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-cerrar">Cerrar</button>
  </div>

  <div class="card-body">
    <form id="form-limite" method="post">
      <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
      <input type="hidden" name="_csrf_proposito" value="catalogo">

      <div class="form-row">
        <div class="form-group col-lg-3 col-md-6">
          <label for="max_por_operacion">Máximo por operación</label>
          <input type="text" class="form-control" id="max_por_operacion"
                 name="max_por_operacion" inputmode="decimal">
          <small class="form-text text-muted" style="font-size:.72rem">0 = sin tope</small>
        </div>

        <div class="form-group col-lg-3 col-md-6">
          <label for="max_diario">Máximo diario</label>
          <input type="text" class="form-control" id="max_diario" name="max_diario" inputmode="decimal">
        </div>

        <div class="form-group col-lg-3 col-md-6">
          <label for="max_mensual">Máximo mensual</label>
          <input type="text" class="form-control" id="max_mensual" name="max_mensual" inputmode="decimal">
        </div>

        <div class="form-group col-lg-3 col-md-6">
          <label for="max_ops_diarias">Operaciones por día</label>
          <input type="number" class="form-control" id="max_ops_diarias"
                 name="max_ops_diarias" min="0" max="9999">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group col-lg-4 col-md-6">
          <label for="umbral_doble_control">Umbral de doble control</label>
          <input type="text" class="form-control" id="umbral_doble_control"
                 name="umbral_doble_control" inputmode="decimal">
          <small class="form-text text-muted" style="font-size:.72rem">
            A partir de este importe, la operación necesita una segunda aprobación.
          </small>
        </div>

        <div class="form-group col-lg-3 col-md-6">
          <label for="requiere_otp">Código de verificación</label>
          <select class="form-control" id="requiere_otp" name="requiere_otp">
            <option value="1">Exigir siempre</option>
            <option value="0">No exigir</option>
          </select>
        </div>

        <div class="form-group col-lg-3 col-md-6">
          <label for="activo">Estado</label>
          <select class="form-control" id="activo" name="activo">
            <option value="1">Activo</option>
            <option value="0">Inactivo</option>
          </select>
        </div>

        <div class="form-group col-lg-2 col-md-12 d-flex align-items-end">
          <button type="submit" class="btn btn-primary btn-block">Guardar</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var BASE  = <?= P::js($rutas->url('limites/')) ?>;
  var caja  = document.getElementById('caja-edicion');
  var form  = document.getElementById('form-limite');
  var titulo = document.getElementById('titulo-edicion');

  document.querySelectorAll('.btn-editar').forEach(function (b) {
    b.addEventListener('click', function () {
      var l = JSON.parse(b.dataset.limite);

      form.action = BASE + l.codigo;
      titulo.textContent = l.operacion + ' — ' + l.rol;

      document.getElementById('max_por_operacion').value    = l.por_operacion;
      document.getElementById('max_diario').value           = l.diario;
      document.getElementById('max_mensual').value          = l.mensual;
      document.getElementById('max_ops_diarias').value      = l.ops;
      document.getElementById('umbral_doble_control').value = l.umbral;
      document.getElementById('requiere_otp').value         = String(l.otp);
      document.getElementById('activo').value               = String(l.activo);

      caja.classList.remove('d-none');
      caja.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  });

  document.getElementById('btn-cerrar').addEventListener('click', function () {
    caja.classList.add('d-none');
  });

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();

    window.CMAviso.confirmar({
      titulo:  'Cambiar un límite operativo',
      mensaje: 'Afecta a todas las operaciones de ese rol a partir de ahora. '
             + 'El cambio queda registrado en la auditoría.',
      confirmar: 'Guardar el límite'
    }).then(function (ok) {
      if (!ok) { return; }

      var espera = window.CMAviso.cargando('Guardando…');

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
  });
})();
</script>
