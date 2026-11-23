<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $perfil
 * @var array|null $detalle
 * @var array $accesos
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;
?>
<div class="row">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-body text-center">
        <div class="cm-avatar mx-auto mb-3" style="width:76px;height:76px;font-size:1.6rem">
          <?= P::e(mb_strtoupper(
                mb_substr((string) $perfil['nombres'], 0, 1)
              . mb_substr((string) $perfil['apellidos'], 0, 1))) ?>
        </div>
        <h5 class="mb-1"><?= P::e(trim($perfil['nombres'] . ' ' . $perfil['apellidos'])) ?></h5>
        <p class="text-muted mb-2" style="font-size:.86rem"><?= P::e($perfil['rol']) ?></p>
        <span class="badge badge-success"><?= P::e($perfil['estado']) ?></span>
      </div>

      <div class="card-body border-top">
        <?php foreach ([
          'Usuario'           => $perfil['usuario'],
          'Correo'            => $perfil['correo'],
          'Último acceso'     => $perfil['ultimo_acceso_en']
                                   ? P::hace((string) $perfil['ultimo_acceso_en']) : 'ahora',
          'Desde IP'          => $perfil['ultimo_acceso_ip_txt'],
          'Clave actualizada' => $perfil['password_actualizado_en']
                                   ? P::fecha((string) $perfil['password_actualizado_en'], 'd/m/Y') : '—',
          'Cuenta creada'     => P::fecha((string) $perfil['creado_en'], 'd/m/Y'),
        ] as $et => $v): ?>
        <div class="d-flex justify-content-between py-1 border-bottom">
          <small class="text-muted"><?= P::e($et) ?></small>
          <small class="font-weight-bold text-right"><?= P::e((string) $v) ?></small>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Datos de contacto</h4></div>
      <div class="card-body">
        <form data-cm-ajax method="post" action="<?= P::attr($rutas->url('perfil')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="perfil">

          <div class="form-row">
            <div class="form-group col-md-7">
              <label>Correo electrónico</label>
              <input type="email" class="form-control" name="correo" required
                     value="<?= P::attr($perfil['correo']) ?>">
              <small class="form-text text-muted">
                Aquí llegan los códigos de verificación y los comprobantes.
              </small>
            </div>
            <div class="form-group col-md-5">
              <label>Teléfono</label>
              <input type="text" class="form-control" name="telefono" maxlength="20"
                     value="<?= P::attr($perfil['telefono'] ?? '') ?>">
            </div>
          </div>

          <button type="submit" class="btn btn-primary">Guardar cambios</button>
          <a href="<?= P::attr($rutas->url('clave')) ?>" class="btn btn-outline-secondary">
            Cambiar contraseña
          </a>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Accesos recientes</h4>
        <a href="<?= P::attr($rutas->url('perfil/sesiones')) ?>" class="btn btn-sm btn-outline-secondary">
          Ver dispositivos
        </a>
      </div>
      <div class="card-body p-0">
        <?php if ($accesos === []): ?>
          <div class="cm-vacio"><p class="mb-0">Sin accesos registrados.</p></div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th>Fecha</th><th>IP</th><th>Sistema</th><th>Resultado</th></tr></thead>
            <tbody>
            <?php foreach ($accesos as $a): ?>
              <tr>
                <td class="text-muted"><?= P::e(P::fecha((string) $a['creado_en'])) ?></td>
                <td class="cm-mono"><?= P::e($a['ip_txt']) ?></td>
                <td class="text-muted"><?= P::e($a['sistema'] ?: '—') ?></td>
                <td>
                  <span class="badge badge-<?= (int) $a['exitoso'] === 1 ? 'success' : 'danger' ?>">
                    <?= (int) $a['exitoso'] === 1 ? 'exitoso' : 'fallido' ?>
                  </span>
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
