<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $documentos
 * @var array $tipos
 * @var array|null $titular
 * @var string $codigoTitular
 * @var bool $puedeSubir
 * @var bool $puedeVerificar
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;
?>

<?php if ($puedeSubir): ?>
<div class="card">
  <div class="card-header"><h4 class="card-title mb-0">Subir documento</h4></div>
  <div class="card-body">
    <form id="form-doc" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
      <input type="hidden" name="_csrf_proposito" value="documento">
      <?php if ($codigoTitular !== ''): ?>
      <input type="hidden" name="cliente" value="<?= P::attr($codigoTitular) ?>">
      <?php endif; ?>

      <div class="form-row align-items-end">
        <div class="form-group col-md-4">
          <label class="small">Tipo de documento</label>
          <select class="form-control form-control-sm" name="tipo" required>
            <?php foreach ($tipos as $k => $v): ?>
            <option value="<?= P::attr($k) ?>"><?= P::e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group col-md-5">
          <label class="small">Archivo</label>
          <?php /* El control propio. El botón que pinta el navegador
                   para `type="file"` lo dibuja el sistema operativo:
                   no admite estilos, ignora el tema —en modo noche era
                   un rectángulo claro en medio del panel oscuro— y no
                   dice cuánto pesa lo elegido, que es justo lo que hay
                   que saber antes de subir cuando el límite es 5 MB. */ ?>
          <div class="cm-archivo" data-cm-archivo data-pista="JPG, PNG, WEBP o PDF">
            <input type="file" name="archivo" id="archivo" required data-max-mb="5"
                   accept="image/jpeg,image/png,image/webp,application/pdf">
          </div>
        </div>
        <div class="form-group col-md-3">
          <button type="submit" class="btn btn-primary btn-block btn-sm" id="btn-subir">
            Subir documento
          </button>
        </div>
      </div>

      <div class="progress d-none" id="barra" style="height:5px">
        <div class="progress-bar bg-success" style="width:0%"></div>
      </div>
    </form>

    <div class="alert alert-light border mt-3 mb-0" style="font-size:.82rem">
      <strong>Cómo se procesan.</strong> El tipo se verifica por el contenido real
      del archivo, no por su extensión. Las imágenes se reconstruyen desde sus
      píxeles —lo que elimina metadatos y cualquier contenido incrustado— y se
      guardan fuera del directorio público con un nombre generado por el servidor.
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0">
      Documentos
      <?php if ($titular !== null && $codigoTitular !== ''): ?>
        <small class="text-muted">de <?= P::e($titular['nombre']) ?></small>
      <?php endif; ?>
    </h4>
    <span class="badge badge-light"><?= count($documentos) ?></span>
  </div>

  <div class="card-body">
    <?php if ($documentos === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('files', 40) ?>
        <p class="mb-0 mt-2">Sin documentos en el expediente.</p>
      </div>
    <?php else: ?>
    <div class="row">
      <?php foreach ($documentos as $d): ?>
      <div class="col-xl-3 col-lg-4 col-md-6 mb-3">
        <div class="card mb-0 h-100 <?= (int) $d['verificado'] === 1 ? 'border-success' : '' ?>">
          <div class="card-body p-2 text-center">
            <?php if ($d['es_imagen']): ?>
              <a href="<?= P::attr($rutas->url('documentos/' . $d['codigo'])) ?>" target="_blank">
                <img src="<?= P::attr($rutas->url('documentos/' . $d['codigo'])) ?>"
                     alt="<?= P::attr($d['tipo_nombre']) ?>"
                     style="width:100%;height:130px;object-fit:cover;border-radius:6px">
              </a>
            <?php else: ?>
              <a href="<?= P::attr($rutas->url('documentos/' . $d['codigo'])) ?>" target="_blank"
                 class="d-flex align-items-center justify-content-center text-muted"
                 style="height:130px;background:#f4f7f9;border-radius:6px">
                <?= cm_icono('files', 42) ?>
              </a>
            <?php endif; ?>
          </div>

          <div class="card-body p-2 pt-0">
            <div class="font-weight-bold" style="font-size:.84rem">
              <?= P::e($d['tipo_nombre']) ?>
            </div>
            <small class="text-muted d-block" style="font-size:.74rem">
              <?= P::e(P::fecha((string) $d['creado_en'], 'd/m/Y')) ?> ·
              <?= number_format((int) $d['tamano_bytes'] / 1024, 0) ?> KB
            </small>
            <small class="text-muted d-block" style="font-size:.72rem">
              Visto <?= (int) $d['veces_visto'] ?> vez/veces
            </small>

            <div class="mt-2">
              <?php if ((int) $d['verificado'] === 1): ?>
                <span class="badge badge-success">verificado</span>
              <?php else: ?>
                <span class="badge badge-warning">sin verificar</span>
              <?php endif; ?>
            </div>

            <?php if ($puedeVerificar || true): ?>
            <div class="mt-2 d-flex" style="gap:.25rem">
              <?php if ($puedeVerificar): ?>
              <button class="btn btn-sm btn-outline-success flex-fill btn-verificar"
                      data-cod="<?= P::attr($d['codigo']) ?>" style="font-size:.72rem">
                <?= (int) $d['verificado'] === 1 ? 'Quitar' : 'Verificar' ?>
              </button>
              <?php endif; ?>
              <button class="btn btn-sm btn-outline-danger btn-eliminar"
                      data-cod="<?= P::attr($d['codigo']) ?>" style="font-size:.72rem"
                      data-cm-confirmar="El documento se eliminará del expediente.">
                Eliminar
              </button>
            </div>
            <?php endif; ?>
          </div>
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
  /* El token de seguridad se lee vivo, no congelado.

     Aquí había una copia del token tomada al
     dibujar la página. Como el token es de un solo uso, la primera
     acción lo gastaba y la segunda fallaba siempre con «el token de
     seguridad venció»: pedir el código llegaba, pulsar «Ver datos» no.

     El servidor manda el reemplazo en cada respuesta y `cm.js` lo
     guarda en `window.CM_CSRF`. Leer de ahí vale para toda la vida de
     la pantalla, por muchas acciones que se encadenen. */
  window.CM_CSRF = window.CM_CSRF || <?= P::js($csrf) ?>;
  var BASE = <?= P::js($rutas->url('documentos/')) ?>;
  var URL_SUBIR = <?= P::js($rutas->url('documentos')) ?>;

  // ---------- Subida con barra de progreso ----------
  var form = document.getElementById('form-doc');
  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();

      var archivo = document.getElementById('archivo').files[0];
      if (!archivo) { window.avisar('Atención', 'Seleccione un archivo.', 'alerta'); return; }

      // Comprobación previa en el navegador: no sustituye la del
      // servidor, solo evita subir 5 MB para que lo rechacen.
      if (archivo.size > 5 * 1024 * 1024) {
        window.avisar('Archivo muy grande', 'El máximo son 5 MB.', 'alerta');
        return;
      }

      var btn = document.getElementById('btn-subir');
      var barra = document.getElementById('barra');
      var relleno = barra.querySelector('.progress-bar');

      btn.disabled = true;
      btn.textContent = 'Subiendo…';
      barra.classList.remove('d-none');

      var xhr = new XMLHttpRequest();
      xhr.open('POST', URL_SUBIR);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.setRequestHeader('Accept', 'application/json');

      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable) {
          relleno.style.width = Math.round((e.loaded / e.total) * 100) + '%';
        }
      };

      xhr.onload = function () {
        btn.disabled = false;
        btn.textContent = 'Subir documento';
        barra.classList.add('d-none');
        relleno.style.width = '0%';

        var j;
        try { j = JSON.parse(xhr.responseText); }
        catch (e) { window.avisar('Error', 'Respuesta inesperada del servidor.', 'critico'); return; }

        window.avisar(j.ok ? 'Subido' : 'No se pudo subir', j.mensaje, j.ok ? 'exito' : 'critico');
        if (j.ok) { setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1200); }
      };

      xhr.onerror = function () {
        btn.disabled = false;
        btn.textContent = 'Subir documento';
        barra.classList.add('d-none');
        window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
      };

      xhr.send(new FormData(form));
    });
  }

  function post(url, datos) {
    var fd = new FormData();
    fd.append('_csrf', window.CM_CSRF);
    fd.append('_csrf_proposito', 'documento');
    Object.keys(datos || {}).forEach(function (k) { fd.append(k, datos[k]); });
    return fetch(url, { method:'POST', body:fd, credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} })
      .then(function (r) { return r.json(); });
  }

  document.querySelectorAll('.btn-verificar').forEach(function (b) {
    b.addEventListener('click', function () {
      post(BASE + b.dataset.cod + '/verificar', {}).then(function (j) {
        window.avisar(j.ok ? 'Listo' : 'Error', j.mensaje, j.ok ? 'exito' : 'critico');
        if (j.ok) setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 900);
      });
    });
  });

  document.querySelectorAll('.btn-eliminar').forEach(function (b) {
    b.addEventListener('click', function () {
      post(BASE + b.dataset.cod + '/eliminar', {}).then(function (j) {
        window.avisar(j.ok ? 'Eliminado' : 'Error', j.mensaje, j.ok ? 'exito' : 'critico');
        if (j.ok) setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 900);
      });
    });
  });
})();
</script>
