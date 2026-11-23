<?php
/**
 * Panel de la tienda: comercios, catálogo, precios, existencias y fotos.
 *
 * Una tienda cuyo catálogo solo se puede cambiar editando migraciones no
 * es una tienda, es una maqueta. Todo lo que se administra a diario está
 * aquí: dar de alta un comercio, publicar un artículo, corregir un
 * precio, ajustar existencias y subir la foto.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $comercios
 * @var array  $articulos
 * @var array  $alertas
 * @var array  $totales
 * @var string $comercioSel
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$foto = static function (array $a) use ($rutas): string {
    $img = (string) ($a['imagen'] ?? '');

    if ($img === '') {
        return '';
    }

    return str_starts_with($img, 'assets/')
        ? $rutas->url($img)
        : $rutas->url('tienda/imagen/' . rawurlencode($img));
};
?>

<!-- ==================== RESUMEN ==================== -->
<div class="row">
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Artículos publicados</div>
      <div class="cm-kpi__val"><?= number_format((int) ($totales['activos'] ?? 0)) ?></div>
      <div class="cm-kpi__pie">de <?= number_format((int) ($totales['articulos'] ?? 0)) ?> en total</div>
    </div>
  </div>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Unidades en bodega</div>
      <div class="cm-kpi__val"><?= number_format((int) ($totales['unidades'] ?? 0)) ?></div>
      <div class="cm-kpi__pie"><?= (int) ($totales['agotados'] ?? 0) ?> artículos agotados</div>
    </div>
  </div>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Valor del inventario</div>
      <div class="cm-kpi__val"><?= P::e(P::money((int) ($totales['inventario_cent'] ?? 0))) ?></div>
      <div class="cm-kpi__pie">a precio de venta</div>
    </div>
  </div>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Comercios afiliados</div>
      <div class="cm-kpi__val"><?= count($comercios) ?></div>
      <div class="cm-kpi__pie">
        <a href="<?= P::attr($rutas->url('tienda')) ?>" target="_blank" rel="noopener">
          ver la tienda
        </a>
      </div>
    </div>
  </div>
</div>

<?php if ($alertas !== []): ?>
<!-- Lo que se está agotando: es lo primero que necesita saber quien
     administra, mucho antes que cualquier total. -->
<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">Se están agotando</h4>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead>
          <tr><th>Artículo</th><th>Comercio</th><th class="cm-num">Quedan</th></tr>
        </thead>
        <tbody>
        <?php foreach ($alertas as $al): ?>
          <tr>
            <td style="font-size:.84rem">
              <?= P::e($al['nombre']) ?>
              <small class="text-muted d-block cm-mono" style="font-size:.7rem">
                <?= P::e($al['sku']) ?>
              </small>
            </td>
            <td style="font-size:.82rem"><?= P::e($al['comercio']) ?></td>
            <td class="cm-num font-weight-bold <?= (int) $al['existencias'] === 0 ? 'text-danger' : 'text-warning' ?>">
              <?= (int) $al['existencias'] ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ==================== COMERCIOS ==================== -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0">Comercios afiliados</h4>
    <button type="button" class="btn btn-sm btn-primary" data-cm-comercio data-nuevo="1">
      Nuevo comercio
    </button>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th></th><th class="cm-nombre">Comercio</th><th>Rubro</th>
            <th class="cm-num">Cuotas s/i</th><th class="cm-num">Envío</th>
            <th class="cm-num">Artículos</th><th>Estado</th>
            <th class="cm-acciones"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($comercios as $c): ?>
          <tr>
            <td style="width:3rem">
              <span class="cm-articulo__logo" style="background:<?= P::attr($c['color']) ?>">
                <?= P::e($c['iniciales']) ?>
              </span>
            </td>
            <td class="cm-nombre">
              <a href="<?= P::attr($rutas->url('tienda/admin') . '?c=' . $c['codigo']) ?>">
                <?= P::e($c['nombre']) ?>
              </a>
              <small class="text-muted d-block cm-mono" style="font-size:.7rem">
                <?= P::e($c['codigo_comercio'] ?? $c['codigo']) ?>
              </small>
            </td>
            <td style="font-size:.82rem"><?= P::e($c['rubro']) ?></td>
            <td class="cm-num"><?= (int) $c['cuotas_sin_intereses'] ?: '—' ?></td>
            <td class="cm-num cm-mono">
              <?= (int) $c['envio_cent'] > 0 ? P::e(P::money((int) $c['envio_cent'])) : 'gratis' ?>
            </td>
            <td class="cm-num">
              <?= (int) $c['disponibles'] ?>
              <small class="text-muted d-block" style="font-size:.68rem">
                de <?= (int) $c['articulos'] ?>
              </small>
            </td>
            <td>
              <span class="badge badge-<?= (int) $c['activo'] === 1 ? 'success' : 'secondary' ?>">
                <?= (int) $c['activo'] === 1 ? 'activo' : 'oculto' ?>
              </span>
            </td>
            <td class="cm-acciones">
              <button type="button" class="btn btn-sm btn-outline-secondary"
                      data-cm-comercio
                      data-codigo="<?= P::attr($c['codigo']) ?>"
                      data-nombre="<?= P::attr($c['nombre']) ?>"
                      data-rubro="<?= P::attr($c['rubro']) ?>"
                      data-categoria="<?= P::attr($c['categoria']) ?>"
                      data-descripcion="<?= P::attr($c['descripcion'] ?? '') ?>"
                      data-iniciales="<?= P::attr($c['iniciales']) ?>"
                      data-color="<?= P::attr($c['color']) ?>"
                      data-cuotas="<?= (int) $c['cuotas_sin_intereses'] ?>"
                      data-envio="<?= P::attr(number_format((int) $c['envio_cent'] / 100, 2, '.', '')) ?>"
                      data-activo="<?= (int) $c['activo'] ?>"
                      data-orden="<?= (int) $c['orden'] ?>">
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

<!-- ==================== CATÁLOGO ==================== -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0">
      Catálogo
      <?php if ($comercioSel !== ''): ?>
      <small class="text-muted">· filtrado por comercio</small>
      <?php endif; ?>
    </h4>
    <div style="display:flex;gap:.4rem">
      <?php if ($comercioSel !== ''): ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= P::attr($rutas->url('tienda/admin')) ?>">
        Ver todos
      </a>
      <?php endif; ?>
      <button type="button" class="btn btn-sm btn-primary" data-cm-articulo data-nuevo="1">
        Nuevo artículo
      </button>
    </div>
  </div>
  <div class="card-body p-0">
    <?php if ($articulos === []): ?>
      <div class="cm-vacio"><p class="mb-0">No hay artículos en este comercio todavía.</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Foto</th><th class="cm-nombre">Artículo</th><th>Comercio</th>
            <th class="cm-num">Precio</th><th class="cm-num">Antes</th>
            <th class="cm-num">Existencias</th><th>Estado</th>
            <th class="cm-acciones">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($articulos as $a): $src = $foto($a); ?>
          <tr>
            <td style="width:4.5rem">
              <?php if ($src !== ''): ?>
              <img src="<?= P::attr($src) ?>" alt="<?= P::attr($a['imagen_alt'] ?? $a['nombre']) ?>"
                   class="cm-miniatura" width="56" height="42" loading="lazy">
              <?php else: ?>
              <span class="cm-miniatura cm-miniatura--vacia">sin foto</span>
              <?php endif; ?>
            </td>
            <td class="cm-nombre">
              <?= P::e($a['nombre']) ?>
              <small class="text-muted d-block cm-mono" style="font-size:.7rem">
                <?= P::e($a['sku']) ?> · <?= P::e($a['categoria']) ?>
              </small>
            </td>
            <td style="font-size:.8rem"><?= P::e($a['comercio']) ?></td>
            <td class="cm-num cm-mono font-weight-bold">
              <?= P::e(P::money((int) $a['precio_cent'])) ?>
            </td>
            <td class="cm-num cm-mono text-muted">
              <?= (int) $a['antes_cent'] > 0 ? P::e(P::money((int) $a['antes_cent'])) : '—' ?>
            </td>
            <td class="cm-num font-weight-bold <?= (int) $a['existencias'] === 0 ? 'text-danger'
                  : ((int) $a['existencias'] <= 5 ? 'text-warning' : '') ?>">
              <?= (int) $a['existencias'] ?>
            </td>
            <td>
              <span class="badge badge-<?= (int) $a['activo'] === 1 ? 'success' : 'secondary' ?>">
                <?= (int) $a['activo'] === 1 ? 'publicado' : 'oculto' ?>
              </span>
            </td>
            <td class="cm-acciones cm-nowrap">
              <button type="button" class="btn btn-sm btn-outline-secondary"
                      data-cm-articulo
                      data-codigo="<?= P::attr($a['codigo']) ?>"
                      data-comercio="<?= P::attr($a['comercio_codigo']) ?>"
                      data-nombre="<?= P::attr($a['nombre']) ?>"
                      data-descripcion="<?= P::attr($a['descripcion'] ?? '') ?>"
                      data-marca="<?= P::attr($a['marca'] ?? '') ?>"
                      data-categoria="<?= P::attr($a['categoria']) ?>"
                      data-precio="<?= P::attr(number_format((int) $a['precio_cent'] / 100, 2, '.', '')) ?>"
                      data-antes="<?= P::attr(number_format((int) $a['antes_cent'] / 100, 2, '.', '')) ?>"
                      data-existencias="<?= (int) $a['existencias'] ?>"
                      data-activo="<?= (int) $a['activo'] ?>">
                Editar
              </button>

              <button type="button" class="btn btn-sm btn-outline-primary"
                      data-cm-foto
                      data-codigo="<?= P::attr($a['codigo']) ?>"
                      data-nombre="<?= P::attr($a['nombre']) ?>"
                      data-alt="<?= P::attr($a['imagen_alt'] ?? '') ?>">
                Foto
              </button>

              <form method="post" data-cm-ajax data-cm-recargar style="display:inline"
                    action="<?= P::attr($rutas->url('tienda/admin/articulo/' . $a['codigo'] . '/baja')) ?>">
                <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
                <input type="hidden" name="_csrf_proposito" value="tienda_admin">
                <button class="btn btn-sm btn-outline-<?= (int) $a['activo'] === 1 ? 'danger' : 'success' ?>">
                  <?= (int) $a['activo'] === 1 ? 'Ocultar' : 'Publicar' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ==================== VENTANA: COMERCIO ==================== -->
<div class="modal fade" id="modal-comercio" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="com-titulo">Nuevo comercio</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="post" data-cm-ajax data-cm-recargar
            action="<?= P::attr($rutas->url('tienda/admin/comercio')) ?>">
        <div class="modal-body">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="tienda_admin">
          <input type="hidden" name="codigo" id="com-codigo">

          <div class="form-row">
            <div class="form-group col-md-8">
              <label>Nombre</label>
              <input type="text" class="form-control" name="nombre" id="com-nombre"
                     required maxlength="120">
            </div>
            <div class="form-group col-md-4">
              <label>Rubro</label>
              <input type="text" class="form-control" name="rubro" id="com-rubro"
                     maxlength="60" placeholder="Ej. Supermercado">
            </div>
          </div>

          <div class="form-group">
            <label>Descripción</label>
            <textarea class="form-control" name="descripcion" id="com-descripcion"
                      rows="2" maxlength="400"
                      placeholder="Lo que el cliente lee al pasar el cursor por el comercio"></textarea>
          </div>

          <div class="form-row">
            <div class="form-group col-md-3">
              <label>Iniciales</label>
              <!-- Dos letras y un color bastan para el logo: así no hace
                   falta que alguien suba una imagen para que el comercio
                   se vea presentable desde el primer día. -->
              <input type="text" class="form-control text-center" name="iniciales"
                     id="com-iniciales" maxlength="2" placeholder="AM">
            </div>
            <div class="form-group col-md-3">
              <label>Color</label>
              <input type="color" class="form-control" name="color" id="com-color"
                     value="#6418c3">
            </div>
            <div class="form-group col-md-3">
              <label>Categoría</label>
              <input type="text" class="form-control" name="categoria" id="com-categoria"
                     maxlength="40" placeholder="departamental">
            </div>
            <div class="form-group col-md-3">
              <label>Orden</label>
              <input type="number" class="form-control" name="orden" id="com-orden"
                     min="0" max="999" value="100">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group col-md-4">
              <label>Cuotas sin intereses</label>
              <input type="number" class="form-control" name="cuotas" id="com-cuotas"
                     min="0" max="36" value="0">
            </div>
            <div class="form-group col-md-4">
              <label>Envío</label>
              <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" name="envio" id="com-envio"
                       inputmode="decimal" value="0.00">
              </div>
            </div>
            <div class="form-group col-md-4">
              <label>Estado</label>
              <select class="form-control" name="activo" id="com-activo">
                <option value="1">Activo en la tienda</option>
                <option value="0">Oculto</option>
              </select>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">Guardar comercio</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ==================== VENTANA: ARTÍCULO ==================== -->
<div class="modal fade" id="modal-articulo" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="art-titulo">Nuevo artículo</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="post" data-cm-ajax data-cm-recargar
            action="<?= P::attr($rutas->url('tienda/admin/articulo')) ?>">
        <div class="modal-body">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="tienda_admin">
          <input type="hidden" name="articulo" id="art-codigo">

          <div class="form-row">
            <div class="form-group col-md-5">
              <label>Comercio</label>
              <select class="form-control" name="comercio" id="art-comercio" required>
                <?php foreach ($comercios as $c): ?>
                <option value="<?= P::attr($c['codigo']) ?>"><?= P::e($c['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-7">
              <label>Nombre del artículo</label>
              <input type="text" class="form-control" name="nombre" id="art-nombre"
                     required maxlength="160">
            </div>
          </div>

          <div class="form-group">
            <label>Descripción</label>
            <textarea class="form-control" name="descripcion" id="art-descripcion"
                      rows="2" maxlength="500"></textarea>
          </div>

          <div class="form-row">
            <div class="form-group col-md-4">
              <label>Marca</label>
              <input type="text" class="form-control" name="marca" id="art-marca" maxlength="60">
            </div>
            <div class="form-group col-md-4">
              <label>Categoría</label>
              <input type="text" class="form-control" name="categoria" id="art-categoria"
                     maxlength="60" placeholder="ropa, electronica…">
            </div>
            <div class="form-group col-md-4">
              <label>Existencias</label>
              <input type="number" class="form-control" name="existencias" id="art-existencias"
                     min="0" max="99999" value="0">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group col-md-4">
              <label>Precio</label>
              <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" name="precio" id="art-precio"
                       inputmode="decimal" required placeholder="0.00">
              </div>
            </div>
            <div class="form-group col-md-4">
              <label>Precio anterior</label>
              <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" name="antes" id="art-antes"
                       inputmode="decimal" value="0.00">
              </div>
              <small class="form-text text-muted">
                Para mostrar una oferta. En cero no se muestra descuento.
              </small>
            </div>
            <div class="form-group col-md-4">
              <label>Estado</label>
              <select class="form-control" name="activo" id="art-activo">
                <option value="1">Publicado</option>
                <option value="0">Oculto</option>
              </select>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">Guardar artículo</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ==================== VENTANA: FOTO ==================== -->
<div class="modal fade" id="modal-foto" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Foto de <span id="foto-nombre"></span></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="post" data-cm-ajax data-cm-recargar id="form-foto"
            enctype="multipart/form-data" action="">
        <div class="modal-body">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="tienda_admin">

          <div class="form-group">
            <label>Imagen del artículo</label>
            <input type="file" class="form-control-file" name="foto" id="foto-archivo"
                   accept="image/jpeg,image/png,image/webp" required>
            <small class="form-text text-muted">
              JPG, PNG o WebP, hasta 5 MB. Se ve mejor en formato apaisado,
              alrededor de 800 × 600.
            </small>
          </div>

          <div class="form-group mb-0">
            <label>Texto alternativo</label>
            <input type="text" class="form-control" name="alt" id="foto-alt" maxlength="190"
                   placeholder="Lo que describe la foto">
            <small class="form-text text-muted">
              Es lo que lee en voz alta un lector de pantalla y lo que se muestra
              si la imagen no carga. Si lo deja vacío usamos el nombre del artículo.
            </small>
          </div>

          <img id="foto-previa" class="cm-foto-previa" hidden alt="Vista previa">
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">Subir la foto</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var BASE = <?= P::js($rutas->url('tienda/admin/articulo/')) ?>;

  function poner(id, valor) {
    var e = document.getElementById(id);
    if (e) { e.value = valor === undefined ? '' : valor; }
  }

  document.addEventListener('click', function (ev) {
    // ---------- Comercio ----------
    var com = ev.target.closest('[data-cm-comercio]');

    if (com) {
      var d = com.dataset;
      var nuevo = d.nuevo === '1';

      document.getElementById('com-titulo').textContent =
        nuevo ? 'Nuevo comercio' : 'Editar ' + (d.nombre || 'comercio');

      poner('com-codigo', nuevo ? '' : d.codigo);
      poner('com-nombre', nuevo ? '' : d.nombre);
      poner('com-rubro', nuevo ? '' : d.rubro);
      poner('com-categoria', nuevo ? '' : d.categoria);
      poner('com-descripcion', nuevo ? '' : d.descripcion);
      poner('com-iniciales', nuevo ? '' : d.iniciales);
      poner('com-color', nuevo ? '#6418c3' : d.color);
      poner('com-cuotas', nuevo ? '0' : d.cuotas);
      poner('com-envio', nuevo ? '0.00' : d.envio);
      poner('com-orden', nuevo ? '100' : d.orden);
      poner('com-activo', nuevo ? '1' : d.activo);

      $('#modal-comercio').modal('show');
      return;
    }

    // ---------- Artículo ----------
    var art = ev.target.closest('[data-cm-articulo]');

    if (art) {
      var a = art.dataset;
      var esNuevo = a.nuevo === '1';

      document.getElementById('art-titulo').textContent =
        esNuevo ? 'Nuevo artículo' : 'Editar ' + (a.nombre || 'artículo');

      poner('art-codigo', esNuevo ? '' : a.codigo);
      poner('art-nombre', esNuevo ? '' : a.nombre);
      poner('art-descripcion', esNuevo ? '' : a.descripcion);
      poner('art-marca', esNuevo ? '' : a.marca);
      poner('art-categoria', esNuevo ? '' : a.categoria);
      poner('art-precio', esNuevo ? '' : a.precio);
      poner('art-antes', esNuevo ? '0.00' : a.antes);
      poner('art-existencias', esNuevo ? '0' : a.existencias);
      poner('art-activo', esNuevo ? '1' : a.activo);

      if (!esNuevo && a.comercio) { poner('art-comercio', a.comercio); }

      $('#modal-articulo').modal('show');
      return;
    }

    // ---------- Foto ----------
    var fot = ev.target.closest('[data-cm-foto]');

    if (fot) {
      document.getElementById('form-foto').action = BASE + fot.dataset.codigo + '/foto';
      document.getElementById('foto-nombre').textContent = fot.dataset.nombre;
      poner('foto-alt', fot.dataset.alt);

      var previa = document.getElementById('foto-previa');
      previa.hidden = true;
      previa.removeAttribute('src');

      var campo = document.getElementById('foto-archivo');
      if (campo) { campo.value = ''; }

      $('#modal-foto').modal('show');
    }
  });

  /* Vista previa antes de subir.
     Subir una foto equivocada y descubrirlo en el catálogo obliga a
     repetir el trámite; verla aquí cuesta nada. */
  var campoFoto = document.getElementById('foto-archivo');

  if (campoFoto) {
    campoFoto.addEventListener('change', function () {
      var previa = document.getElementById('foto-previa');
      var f = campoFoto.files && campoFoto.files[0];

      if (!f) { previa.hidden = true; return; }

      previa.src = URL.createObjectURL(f);
      previa.hidden = false;
    });
  }
})();
</script>
