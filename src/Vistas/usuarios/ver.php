<?php
/**
 * Ficha de usuario: acceso y expediente.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $usuario
 * @var array  $detalle
 * @var array  $roles
 * @var array  $resumen
 * @var array  $accesos
 * @var bool   $puedeEditar
 * @var bool   $puedeExpediente
 * @var bool   $puedeBloquear
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$iniciales = mb_strtoupper(
    mb_substr((string) $usuario['nombres'], 0, 1) .
    mb_substr((string) $usuario['apellidos'], 0, 1)
);

$badgeEstado = match ((string) $usuario['estado']) {
    'activo'    => 'success',
    'inactivo'  => 'secondary',
    'bloqueado' => 'danger',
    'pendiente' => 'warning',
    default     => 'light',
};

/** Centavos a texto editable, sin separador de miles. */
$editable = static fn(?int $c): string => number_format((int) $c / 100, 2, '.', '');
?>

<div class="row">
  <!-- ==================== IDENTIDAD ==================== -->
  <div class="col-xl-4 col-lg-5">
    <div class="card">
      <div class="card-body text-center">
        <div class="cm-avatar mx-auto mb-3"
             style="width:72px;height:72px;font-size:1.5rem"><?= P::e($iniciales) ?></div>

        <h5 class="mb-1" style="font-size:1.05rem">
          <?= P::e($usuario['nombres'] . ' ' . $usuario['apellidos']) ?>
        </h5>
        <p class="text-muted mb-2" style="font-size:.84rem"><?= P::e($usuario['correo']) ?></p>

        <div class="mb-3">
          <span class="badge badge-primary"><?= P::e($usuario['rol']) ?></span>
          <span class="badge badge-<?= $badgeEstado ?>"><?= P::e($usuario['estado']) ?></span>
          <?php if ((int) $usuario['perfil_completo'] === 1): ?>
            <span class="badge badge-info">expediente completo</span>
          <?php endif; ?>
          <?php if ((int) $usuario['totp_activo'] === 1): ?>
            <span class="badge badge-success">2FA activo</span>
          <?php endif; ?>
          <?php if (!empty($usuario['es_yo'])): ?>
            <span class="badge badge-dark">tú</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="card-body pt-0">
        <table class="table table-sm mb-0" style="font-size:.82rem">
          <tr>
            <td class="text-muted">Usuario</td>
            <td class="text-right cm-mono"><?= P::e($usuario['usuario']) ?></td>
          </tr>
          <tr>
            <td class="text-muted">Teléfono</td>
            <td class="text-right"><?= P::e($usuario['telefono'] ?: '—') ?></td>
          </tr>
          <tr>
            <td class="text-muted">Alta</td>
            <td class="text-right"><?= P::e(P::fecha((string) $usuario['creado_en'], 'd/m/Y')) ?></td>
          </tr>
          <tr>
            <td class="text-muted">Último acceso</td>
            <td class="text-right">
              <?= $usuario['ultimo_acceso_en'] !== null
                  ? P::e(P::hace((string) $usuario['ultimo_acceso_en']))
                  : 'nunca' ?>
            </td>
          </tr>
          <tr>
            <td class="text-muted">Intentos fallidos</td>
            <td class="text-right">
              <?php if ((int) $usuario['intentos_fallidos'] > 0): ?>
                <span class="text-danger font-weight-bold"><?= (int) $usuario['intentos_fallidos'] ?></span>
              <?php else: ?>
                0
              <?php endif; ?>
            </td>
          </tr>
          <?php if ($usuario['bloqueado_hasta'] !== null): ?>
          <tr>
            <td class="text-muted">Bloqueado hasta</td>
            <td class="text-right text-danger">
              <?= P::e(P::fecha((string) $usuario['bloqueado_hasta'], 'd/m/Y H:i')) ?>
            </td>
          </tr>
          <?php endif; ?>
          <tr>
            <td class="text-muted">Sesiones abiertas</td>
            <td class="text-right"><?= (int) ($resumen['sesiones'] ?? 0) ?></td>
          </tr>
        </table>
      </div>
    </div>

    <!-- Lo que tiene contratado -->
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Productos contratados</h4></div>
      <div class="card-body">
        <div class="row text-center">
          <div class="col-4">
            <h4 class="mb-0 font-weight-bold"><?= (int) ($resumen['cuentas'] ?? 0) ?></h4>
            <small class="text-muted" style="font-size:.72rem">cuentas</small>
          </div>
          <div class="col-4">
            <h4 class="mb-0 font-weight-bold"><?= (int) ($resumen['tarjetas'] ?? 0) ?></h4>
            <small class="text-muted" style="font-size:.72rem">tarjetas</small>
          </div>
          <div class="col-4">
            <h4 class="mb-0 font-weight-bold"><?= (int) ($resumen['creditos'] ?? 0) ?></h4>
            <small class="text-muted" style="font-size:.72rem">créditos</small>
          </div>
        </div>

        <hr>

        <div class="d-flex justify-content-between" style="font-size:.84rem">
          <span class="text-muted">Saldo en cuentas</span>
          <strong class="cm-mono text-success">
            <?= P::e(P::money((int) ($resumen['saldo'] ?? 0))) ?>
          </strong>
        </div>
        <div class="d-flex justify-content-between" style="font-size:.84rem">
          <span class="text-muted">Deuda vigente</span>
          <strong class="cm-mono text-danger">
            <?= P::e(P::money((int) ($resumen['deuda'] ?? 0))) ?>
          </strong>
        </div>
      </div>

      <div class="card-body pt-0">
        <a href="<?= P::attr($rutas->url('clientes/' . $usuario['codigo'])) ?>"
           class="btn btn-sm btn-outline-primary btn-block">Ver expediente completo</a>
      </div>
    </div>
  </div>

  <!-- ==================== EDICIÓN ==================== -->
  <div class="col-xl-8 col-lg-7">

    <?php if ($puedeEditar): ?>
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Datos de acceso</h4></div>
      <div class="card-body">
        <form id="form-usuario" method="post"
              action="<?= P::attr($rutas->url('usuarios/' . $usuario['codigo'])) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="usuario">

          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="nombres">Nombres</label>
              <input type="text" class="form-control" id="nombres" name="nombres"
                     value="<?= P::attr($usuario['nombres']) ?>" maxlength="120" required>
            </div>
            <div class="form-group col-md-6">
              <label for="apellidos">Apellidos</label>
              <input type="text" class="form-control" id="apellidos" name="apellidos"
                     value="<?= P::attr($usuario['apellidos']) ?>" maxlength="120" required>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group col-md-5">
              <label for="correo">Correo electrónico</label>
              <input type="email" class="form-control" id="correo" name="correo"
                     value="<?= P::attr($usuario['correo']) ?>" maxlength="190" required>
              <small class="form-text text-muted" style="font-size:.72rem">
                Es por donde recupera su contraseña.
              </small>
            </div>

            <div class="form-group col-md-3">
              <label for="telefono">Teléfono</label>
              <input type="text" class="form-control" id="telefono" name="telefono"
                     value="<?= P::attr($usuario['telefono'] ?? '') ?>" maxlength="20">
            </div>

            <div class="form-group col-md-4">
              <label for="rol">Rol</label>
              <select class="form-control" id="rol" name="rol"
                      <?= !empty($usuario['es_yo']) ? 'disabled' : '' ?>>
                <?php foreach ($roles as $r): ?>
                <option value="<?= (int) $r['id'] ?>"
                        <?= (int) $r['id'] === (int) $usuario['rol_id'] ? 'selected' : '' ?>>
                  <?= P::e($r['nombre']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <small class="form-text text-muted" style="font-size:.72rem">
                <?= !empty($usuario['es_yo'])
                    ? 'No puede cambiar su propio rol.'
                    : 'Al cambiarlo se cierran sus sesiones abiertas.' ?>
              </small>
            </div>
          </div>

          <button type="submit" class="btn btn-primary">Guardar cambios</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($puedeExpediente): ?>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Expediente personal</h4>
        <?php if ((int) ($detalle['perfil_completo'] ?? 0) === 1): ?>
          <span class="badge badge-success">completo</span>
        <?php else: ?>
          <span class="badge badge-warning">incompleto</span>
        <?php endif; ?>
      </div>

      <div class="card-body">
        <form id="form-expediente" method="post"
              action="<?= P::attr($rutas->url('usuarios/' . $usuario['codigo'] . '/expediente')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="usuario">

          <h6 class="text-muted mb-3" style="font-size:.74rem;letter-spacing:.06em">IDENTIFICACIÓN</h6>

          <div class="form-row">
            <div class="form-group col-md-3">
              <label for="dui">DUI</label>
              <input type="text" class="form-control" id="dui" name="dui"
                     value="<?= P::attr($detalle['dui'] ?? '') ?>" placeholder="00000000-0">
            </div>
            <div class="form-group col-md-3">
              <label for="nit">NIT</label>
              <input type="text" class="form-control" id="nit" name="nit"
                     value="<?= P::attr($detalle['nit'] ?? '') ?>" placeholder="0000-000000-000-0">
            </div>
            <div class="form-group col-md-3">
              <label for="fecha_nacimiento">Nacimiento</label>
              <input type="date" class="form-control" id="fecha_nacimiento" name="fecha_nacimiento"
                     value="<?= P::attr($detalle['fecha_nacimiento'] ?? '') ?>">
            </div>
            <div class="form-group col-md-3">
              <label for="genero">Género</label>
              <select class="form-control" id="genero" name="genero">
                <option value="">—</option>
                <option value="M" <?= ($detalle['genero'] ?? '') === 'M' ? 'selected' : '' ?>>Masculino</option>
                <option value="F" <?= ($detalle['genero'] ?? '') === 'F' ? 'selected' : '' ?>>Femenino</option>
                <option value="O" <?= ($detalle['genero'] ?? '') === 'O' ? 'selected' : '' ?>>Otro</option>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group col-md-3">
              <label for="estado_civil">Estado civil</label>
              <select class="form-control" id="estado_civil" name="estado_civil">
                <option value="">—</option>
                <?php foreach (['soltero' => 'Soltero/a', 'casado' => 'Casado/a',
                                'divorciado' => 'Divorciado/a', 'viudo' => 'Viudo/a',
                                'union_libre' => 'Unión libre'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= ($detalle['estado_civil'] ?? '') === $k ? 'selected' : '' ?>>
                  <?= $v ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-3">
              <label for="celular">Celular</label>
              <input type="text" class="form-control" id="celular" name="celular"
                     value="<?= P::attr($detalle['celular'] ?? '') ?>" maxlength="20">
            </div>
            <div class="form-group col-md-3">
              <label for="telefono_exp">Teléfono fijo</label>
              <input type="text" class="form-control" id="telefono_exp" name="telefono"
                     value="<?= P::attr($detalle['telefono'] ?? '') ?>" maxlength="20">
            </div>
            <div class="form-group col-md-3">
              <label for="telefono_trabajo">Teléfono del trabajo</label>
              <input type="text" class="form-control" id="telefono_trabajo" name="telefono_trabajo"
                     value="<?= P::attr($detalle['telefono_trabajo'] ?? '') ?>" maxlength="20">
            </div>
          </div>

          <div class="form-group">
            <label for="direccion">Dirección de residencia</label>
            <input type="text" class="form-control" id="direccion" name="direccion"
                   value="<?= P::attr($detalle['direccion'] ?? '') ?>" maxlength="255">
          </div>

          <hr>
          <h6 class="text-muted mb-3" style="font-size:.74rem;letter-spacing:.06em">ACTIVIDAD ECONÓMICA</h6>

          <div class="form-row">
            <div class="form-group col-md-5">
              <label for="empresa">Empresa</label>
              <input type="text" class="form-control" id="empresa" name="empresa"
                     value="<?= P::attr($detalle['empresa'] ?? '') ?>" maxlength="150">
            </div>
            <div class="form-group col-md-4">
              <label for="cargo">Cargo</label>
              <input type="text" class="form-control" id="cargo" name="cargo"
                     value="<?= P::attr($detalle['cargo'] ?? '') ?>" maxlength="120">
            </div>
            <div class="form-group col-md-3">
              <label for="ingreso_mensual">Ingreso mensual</label>
              <input type="text" class="form-control" id="ingreso_mensual" name="ingreso_mensual"
                     value="<?= P::attr($editable((int) ($detalle['ingreso_mensual_cent'] ?? 0))) ?>"
                     inputmode="decimal">
              <small class="form-text text-muted" style="font-size:.72rem">
                Base de la capacidad de pago.
              </small>
            </div>
          </div>

          <div class="form-group">
            <label for="direccion_trabajo">Dirección del trabajo</label>
            <input type="text" class="form-control" id="direccion_trabajo" name="direccion_trabajo"
                   value="<?= P::attr($detalle['direccion_trabajo'] ?? '') ?>" maxlength="255">
          </div>

          <button type="submit" class="btn btn-primary">Guardar expediente</button>

          <?php if ((int) ($detalle['perfil_completo'] ?? 0) !== 1): ?>
          <div class="cm-aviso cm-aviso--alerta mt-3 mb-0">
            <?= cm_icono('info', 17) ?>
            <span>
              Para marcarlo como completo hacen falta DUI, dirección, celular e
              ingreso mensual. Sin eso no se puede evaluar una solicitud de crédito.
            </span>
          </div>
          <?php endif; ?>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Últimos accesos -->
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Últimos accesos</h4></div>
      <div class="card-body p-0">
        <?php if ($accesos === []): ?>
          <div class="cm-vacio"><p class="mb-0">Sin accesos registrados.</p></div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0">
            <thead>
              <tr><th>Cuándo</th><th>Resultado</th><th>IP</th><th>Navegador</th><th>Equipo</th></tr>
            </thead>
            <tbody>
              <?php foreach ($accesos as $a): ?>
              <tr>
                <td style="font-size:.8rem">
                  <?= P::e(P::fecha((string) $a['creado_en'], 'd/m/Y H:i')) ?>
                </td>
                <td>
                  <span class="badge badge-<?= (int) $a['exitoso'] === 1 ? 'success' : 'danger' ?>">
                    <?= (int) $a['exitoso'] === 1 ? 'éxito' : 'fallo' ?>
                  </span>
                </td>
                <td class="cm-mono text-muted" style="font-size:.76rem">
                  <?= P::e($a['ip_texto'] ?: '—') ?>
                </td>
                <td class="text-muted" style="font-size:.76rem">
                  <?= P::e($a['navegador'] ?: '—') ?>
                  <small class="d-block" style="font-size:.7rem"><?= P::e($a['sistema'] ?: '') ?></small>
                </td>
                <td class="text-muted" style="font-size:.76rem">
                  <?= P::e($a['dispositivo'] ?: '—') ?>
                </td>
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

  ['form-usuario', 'form-expediente'].forEach(function (id) {
    var form = document.getElementById(id);
    if (!form) { return; }

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();

      var espera = window.CMAviso.cargando('Guardando…');
      var btn = form.querySelector('[type="submit"]');
      if (btn) { btn.disabled = true; }

      // Un select deshabilitado no viaja en el FormData, que es justo
      // lo que queremos cuando el rol no se puede cambiar.
      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        espera.cerrar();
        if (btn) { btn.disabled = false; }

        if (j.csrf) {
          form.querySelectorAll('[name="_csrf"]').forEach(function (c) { c.value = j.csrf; });
        }

        if (j.ok) { setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1100); }
      })
      .catch(function () {
        espera.cerrar();
        if (btn) { btn.disabled = false; }
      });
    });
  });

  // Formato del DUI mientras se escribe: 8 dígitos, guion, 1 dígito.
  var dui = document.getElementById('dui');
  if (dui) {
    dui.addEventListener('input', function () {
      var d = dui.value.replace(/[^0-9]/g, '').slice(0, 9);
      dui.value = d.length > 8 ? d.slice(0, 8) + '-' + d.slice(8) : d;
    });
  }

  var nit = document.getElementById('nit');
  if (nit) {
    nit.addEventListener('input', function () {
      var d = nit.value.replace(/[^0-9]/g, '').slice(0, 14);
      var p = [d.slice(0, 4), d.slice(4, 10), d.slice(10, 13), d.slice(13, 14)]
        .filter(function (x) { return x !== ''; });
      nit.value = p.join('-');
    });
  }
})();
</script>
