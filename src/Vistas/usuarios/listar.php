<?php
/** @var array $usuarios */
/** @var array $roles */
use CashMan\Http\Plantilla as P;
?>
<div class="row">
  <?php foreach ($roles as $r): ?>
  <div class="col-xl col-lg-4 col-sm-6 mb-4">
    <a href="<?= P::attr($rutas->url('usuarios')) ?>?r=<?= (int)$r['id'] ?>"
       class="cm-kpi d-block text-decoration-none<?= $rolFiltro===(int)$r['id']?' border-primary':'' ?>">
      <div class="cm-kpi__et"><?= P::e($r['nombre']) ?></div>
      <div class="cm-kpi__val"><?= (int)$r['usuarios'] ?></div>
      <div class="cm-kpi__pie"><?= (int)$r['permisos'] ?> permisos</div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
    <h4 class="card-title mb-0">Usuarios
      <?php if ($rolFiltro>0): ?>
        <a href="<?= P::attr($rutas->url('usuarios')) ?>" class="small ml-1">quitar filtro</a>
      <?php endif; ?>
    </h4>
    <div class="d-flex" style="gap:.5rem">
      <form method="get" class="form-inline">
        <input type="text" class="form-control form-control-sm mr-2" name="q"
               value="<?= P::attr($busqueda) ?>" placeholder="Buscar usuario">
        <button class="btn btn-sm btn-outline-primary">Buscar</button>
      </form>
      <?php if ($puedeCrear): ?>
      <a href="<?= P::attr($rutas->url('usuarios/nuevo')) ?>" class="btn btn-sm btn-primary">
        <?= cm_icono('plus',15,'mr-1') ?> Registrar
      </a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card-body p-0">
    <?php if ($usuarios === []): ?>
      <div class="cm-vacio"><p class="mb-0">Sin usuarios que coincidan.</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0" data-cm-tabla>
        <thead><tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Último acceso</th>
          <th>Estado</th><th class="cm-acciones">Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
          <tr>
            <td class="cm-mono font-weight-bold"><?= P::e($u['usuario']) ?></td>
            <td><?= P::e(trim($u['nombres'].' '.$u['apellidos'])) ?><br>
              <small class="text-muted"><?= P::e($u['correo']) ?></small></td>
            <td><span class="badge badge-light"><?= P::e($u['rol']) ?></span></td>
            <td class="text-muted">
              <?= $u['ultimo_acceso_en'] ? P::e(P::hace((string)$u['ultimo_acceso_en'])) : 'nunca' ?>
            </td>
            <td>
              <span class="badge badge-<?= $u['estado']==='activo'?'success':($u['estado']==='bloqueado'?'danger':'secondary') ?>">
                <?= P::e($u['estado']) ?></span>
              <?php if ($u['bloqueado']): ?>
                <span class="badge badge-warning">bloqueo temporal</span>
              <?php endif; ?>
              <?php if ((int)$u['requiere_cambio_password']===1): ?>
                <span class="badge badge-info">clave temporal</span>
              <?php endif; ?>
            </td>
            <td class="cm-acciones">
              <?php
              /* Cambiar el estado y restablecer la clave los atiende el
                 script del pie de esta vista; se engancha por clase y
                 lee el código del `data-`, así que esas dos opciones
                 llevan `clase` y `datos` en vez de una URL. */
              $accionesFila = [
                  ['Ver usuario', $rutas->url('usuarios/' . $u['codigo']), 'search'],
                  ['separador' => true, 'si' => $puedeEditar],
              ];

              foreach (['activo' => 'Activar',
                        'inactivo' => 'Desactivar',
                        'bloqueado' => 'Bloquear'] as $destino => $etiqueta) {
                  if ($u['estado'] === $destino) { continue; }

                  $accionesFila[] = [
                      $etiqueta, '#',
                      $destino === 'activo' ? 'check' : 'lock',
                      'si'     => $puedeEditar,
                      'estilo' => $destino === 'bloqueado' ? 'danger' : '',
                      'clase'  => 'btn-estado',
                      'datos'  => ['cod' => $u['codigo'], 'estado' => $destino],
                  ];
              }

              $accionesFila[] = ['separador' => true, 'si' => $puedeEditar];
              $accionesFila[] = [
                  'Restablecer contraseña', '#', 'key',
                  'si'    => $puedeEditar,
                  'clase' => 'btn-clave',
                  'datos' => ['cod' => $u['codigo']],
              ];
              ?>
              <?= P::crudo(cm_acciones($accionesFila)) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="m-clave" tabindex="-1"><div class="modal-dialog modal-dialog-centered">
  <div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Contraseña temporal</h5>
      <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
    <div class="modal-body" id="cuerpo-clave"></div>
  </div>
</div></div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function(){
  /* El token de seguridad se lee vivo, no congelado.

     Aquí había una copia del token tomada al
     dibujar la página. Como el token es de un solo uso, la primera
     acción lo gastaba y la segunda fallaba siempre con «el token de
     seguridad venció»: pedir el código llegaba, pulsar «Ver datos» no.

     El servidor manda el reemplazo en cada respuesta y `cm.js` lo
     guarda en `window.CM_CSRF`. Leer de ahí vale para toda la vida de
     la pantalla, por muchas acciones que se encadenen. */
  window.CM_CSRF = window.CM_CSRF || <?= P::js($csrf) ?>;
  var BASE = <?= P::js($rutas->url('usuarios/')) ?>;
  function post(url, datos){
    var fd = new FormData(); fd.append('_csrf',window.CM_CSRF); fd.append('_csrf_proposito','usuario');
    Object.keys(datos||{}).forEach(function(k){ fd.append(k,datos[k]); });
    return fetch(url,{method:'POST',body:fd,credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(function(r){return r.json();});
  }
  document.querySelectorAll('.btn-estado').forEach(function(a){
    a.addEventListener('click',function(e){
      e.preventDefault();
      post(BASE+a.dataset.cod+'/estado',{estado:a.dataset.estado}).then(function(j){
        window.avisar(j.ok?'Listo':'Error', j.mensaje, j.ok?'exito':'critico');
        if(j.ok) setTimeout(function(){(window.CMVista ? window.CMVista.refrescar() : location.reload());},1000);
      });
    });
  });
  document.querySelectorAll('.btn-clave').forEach(function(a){
    a.addEventListener('click',function(e){
      e.preventDefault();
      post(BASE+a.dataset.cod+'/clave',{}).then(function(j){
        if(!j.ok){ window.avisar('Error',j.mensaje,'critico'); return; }
        document.getElementById('cuerpo-clave').innerHTML =
          '<p>Entrega esta contraseña al usuario. Se le pedirá cambiarla al entrar.</p>' +
          '<div class="alert alert-light border text-center cm-mono" style="font-size:1.2rem;letter-spacing:.05em">' +
          window.CMUtil.escapar(j.datos.temporal) + '</div>' +
          '<small class="text-muted">' + (j.datos.correo_enviado
            ? 'También se envió por correo.' : 'El correo no pudo enviarse; entrégala en persona.') + '</small>';
        $('#m-clave').modal('show');
      });
    });
  });
})();
</script>
