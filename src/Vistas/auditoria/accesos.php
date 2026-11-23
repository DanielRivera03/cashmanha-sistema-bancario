<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $accesos
 * @var array $sesiones
 */
use CashMan\Http\Plantilla as P;
?>
<div class="card">
  <div class="card-header"><h4 class="card-title mb-0">Sesiones registradas</h4></div>
  <div class="card-body p-0">
    <?php if ($sesiones === []): ?>
      <div class="cm-vacio"><p class="mb-0">Sin sesiones.</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead>
          <tr><th>Usuario</th><th>IP</th><th>Navegador</th><th>Inicio</th>
              <th>Última actividad</th><th>Estado</th></tr>
        </thead>
        <tbody>
        <?php foreach ($sesiones as $s): ?>
          <tr>
            <td>
              <?= P::e($s['nombre']) ?><br>
              <small class="text-muted cm-mono"><?= P::e($s['usuario']) ?></small>
            </td>
            <td class="cm-mono"><?= P::e($s['ip_txt']) ?></td>
            <td class="text-muted" style="font-size:.78rem;max-width:230px;overflow:hidden;text-overflow:ellipsis">
              <?= P::e(mb_substr((string) $s['user_agent'], 0, 60)) ?>
            </td>
            <td class="text-muted"><?= P::e(P::fecha((string) $s['creada_en'])) ?></td>
            <td class="text-muted"><?= P::e(P::hace((string) $s['ultima_actividad'])) ?></td>
            <td>
              <?php if ($s['activa']): ?>
                <span class="badge badge-success">activa</span>
              <?php else: ?>
                <span class="badge badge-secondary">
                  <?= P::e($s['motivo_revocacion'] ?: 'expirada') ?>
                </span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header"><h4 class="card-title mb-0">Historial de accesos</h4></div>
  <div class="card-body p-0">
    <?php if ($accesos === []): ?>
      <div class="cm-vacio"><p class="mb-0">Sin accesos registrados.</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" data-cm-tabla>
        <thead>
          <tr><th>Fecha</th><th>Usuario</th><th>Rol</th><th>IP</th>
              <th>Sistema</th><th>Resultado</th></tr>
        </thead>
        <tbody>
        <?php foreach ($accesos as $a): ?>
          <tr>
            <td class="text-muted" style="white-space:nowrap">
              <?= P::e(P::fecha((string) $a['creado_en'])) ?>
            </td>
            <td><?= P::e($a['nombre']) ?></td>
            <td><span class="badge badge-light"><?= P::e($a['rol']) ?></span></td>
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
