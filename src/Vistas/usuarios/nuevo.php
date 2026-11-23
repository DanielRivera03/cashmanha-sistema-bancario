<?php
/** @var array $roles */
use CashMan\Http\Plantilla as P;
?>
<div class="row justify-content-center">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header"><h4 class="card-title">Registrar usuario</h4></div>
      <div class="card-body">
        <div id="res-usuario" class="alert alert-success d-none"></div>

        <form id="form-usuario" data-cm-ajax method="post"
              action="<?= P::attr($rutas->url('usuarios/nuevo')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="usuario">

          <div class="form-row">
            <div class="form-group col-md-6">
              <label>Nombres</label>
              <input type="text" class="form-control" name="nombres" maxlength="120" required>
            </div>
            <div class="form-group col-md-6">
              <label>Apellidos</label>
              <input type="text" class="form-control" name="apellidos" maxlength="120" required>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group col-md-7">
              <label>Correo electrónico</label>
              <input type="email" class="form-control" name="correo" maxlength="190" required>
            </div>
            <div class="form-group col-md-5">
              <label>Teléfono</label>
              <input type="text" class="form-control" name="telefono" maxlength="20">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group col-md-6">
              <label>Rol</label>
              <select class="form-control" name="rol" required>
                <?php foreach ($roles as $r): ?>
                <option value="<?= (int)$r['id'] ?>"<?= (int)$r['id']===5?' selected':'' ?>>
                  <?= P::e($r['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
              <small class="form-text text-muted">
                No puede asignar un rol superior al suyo.
              </small>
            </div>
            <?php /* Qué documento es, y después el número con su
                     máscara. Quien da de alta a un cliente en
                     ventanilla tiene el documento delante: lo que no
                     puede pasar es que cada quien lo teclee a su
                     manera y luego nadie encuentre al cliente. */ ?>
            <div class="form-group col-md-3">
              <label for="tipo_documento">Documento</label>
              <select class="form-control" id="tipo_documento" name="tipo_documento"
                      data-cm-documento-tipo>
                <option value="dui">DUI</option>
                <option value="nit">NIT</option>
                <option value="residente">Carné de residente</option>
              </select>
            </div>
            <div class="form-group col-md-3">
              <label for="documento">Número</label>
              <input type="text" class="form-control" id="documento" name="documento"
                     maxlength="20" data-cm-documento-valor autocomplete="off">
              <small class="form-text text-muted" data-cm-documento-nota></small>
            </div>
          </div>

          <div class="alert alert-light border" style="font-size:.86rem">
            El sistema genera el nombre de usuario y una contraseña temporal fuerte.
            Se envía por correo y se pedirá cambiarla al primer ingreso.
          </div>

          <button type="submit" class="btn btn-primary">Registrar usuario</button>
          <a href="<?= P::attr($rutas->url('usuarios')) ?>" class="btn btn-outline-secondary">Cancelar</a>
        </form>
      </div>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
document.getElementById('form-usuario').addEventListener('cm:exito', function (ev) {
  var d = ev.detail.datos || {};
  var c = document.getElementById('res-usuario');
  c.classList.remove('d-none');
  c.innerHTML = '<strong>Usuario creado.</strong><br>' +
    'Usuario: <span class="cm-mono">' + window.CMUtil.escapar(d.usuario||'') + '</span><br>' +
    'Contraseña temporal: <span class="cm-mono" style="font-size:1.05rem">' +
    window.CMUtil.escapar(d.temporal||'') + '</span><br>' +
    '<small>' + (d.correo_enviado ? 'Enviada también por correo.'
      : 'El correo no pudo enviarse; entrégala en persona.') + '</small>';
});
</script>
