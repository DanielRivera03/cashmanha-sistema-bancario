<?php
/**
 * Historial de notificaciones.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $notificaciones
 * @var array  $conteos
 * @var array  $tipos
 * @var string $vista
 * @var array  $filtros
 * @var int    $total
 * @var int    $pagina
 * @var int    $paginas
 * @var array  $paginacion
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$hayFiltro = ($filtros['tipo'] ?? '') !== '' || ($filtros['q'] ?? '') !== '';

$pestanas = [
    'todas'    => ['Todas',      'todas'],
    'sin_leer' => ['Sin leer',   'sin_leer'],
    'critico'  => ['Críticas',   'critico'],
    'alerta'   => ['Avisos',     'alerta'],
    'exito'    => ['Operaciones','exito'],
    'leidas'   => ['Leídas',     'leidas'],
];

$estilo = [
    'exito'   => ['success', 'check'],
    'critico' => ['danger',  'warning'],
    'alerta'  => ['warning', 'warning'],
    'info'    => ['info',    'info'],
];

/** Mantiene los filtros al paginar. */
$conFiltros = static function (array $extra) use ($filtros, $vista): string {
    $p = array_filter(
        array_merge($filtros, ['v' => $vista], $extra),
        static fn($v) => $v !== '' && $v !== null
    );
    return $p === [] ? '' : '?' . http_build_query($p);
};
?>

<div class="card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h4 class="card-title mb-0">
      Notificaciones
      <?php if ((int) ($conteos['sin_leer'] ?? 0) > 0): ?>
        <span class="badge badge-danger ml-1"><?= (int) $conteos['sin_leer'] ?> sin leer</span>
      <?php endif; ?>
    </h4>

    <?php if ((int) ($conteos['sin_leer'] ?? 0) > 0): ?>
    <button class="btn btn-sm btn-outline-primary" id="btn-todas"
            data-cm-confirmar="Se marcarán como leídas las <?= (int) $conteos['sin_leer'] ?> notificaciones pendientes.">
      Marcar todas como leídas
    </button>
    <?php endif; ?>
  </div>

  <div class="card-body pb-0 pt-2">
    <ul class="nav nav-tabs cm-pestanas" style="border-bottom:1px solid #eaecf0">
      <?php foreach ($pestanas as $clave => [$etiqueta, $campo]):
        $n = (int) ($conteos[$campo] ?? 0);
        if ($n === 0 && $vista !== $clave) { continue; }
        $activa = $vista === $clave;
      ?>
      <li class="nav-item">
        <a class="nav-link <?= $activa ? 'active' : '' ?>"
           href="<?= P::attr($rutas->url('notificaciones') . '?v=' . $clave) ?>">
          <?= P::e($etiqueta) ?>
          <span class="badge badge-<?= $activa ? 'primary' : 'light' ?> ml-1"><?= $n ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="card-body pb-2">
    <form method="get" class="form-row align-items-end mb-0">
      <input type="hidden" name="v" value="<?= P::attr($vista) ?>">

      <div class="form-group col-lg-5 col-md-6">
        <label class="small mb-1">Buscar</label>
        <input type="search" name="q" class="form-control form-control-sm"
               value="<?= P::attr($filtros['q'] ?? '') ?>"
               placeholder="Texto del título o del mensaje">
      </div>

      <div class="form-group col-lg-3 col-md-4">
        <label class="small mb-1">Tipo</label>
        <select name="tipo" class="form-control form-control-sm">
          <option value="">Todos</option>
          <?php foreach ($tipos as $t): ?>
          <option value="<?= P::attr($t['tipo']) ?>"
                  <?= ($filtros['tipo'] ?? '') === $t['tipo'] ? 'selected' : '' ?>>
            <?= P::e(ucfirst(str_replace(['_', '.'], ' ', (string) $t['tipo']))) ?>
            (<?= (int) $t['n'] ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group col-lg-2 col-md-3">
        <button class="btn btn-outline-primary btn-sm btn-block">Filtrar</button>
      </div>

      <?php if ($hayFiltro): ?>
      <div class="form-group col-lg-2 col-md-3">
        <a href="<?= P::attr($rutas->url('notificaciones') . '?v=' . $vista) ?>"
           class="btn btn-outline-secondary btn-sm btn-block">Limpiar</a>
      </div>
      <?php endif; ?>
    </form>
  </div>

  <div class="card-body pt-0">
    <?php if ($notificaciones === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('bell', 40) ?>
        <p class="mb-1 mt-2">
          <?= match ($vista) {
              'sin_leer' => 'No tiene notificaciones sin leer. Todo al día.',
              'critico'  => 'Ninguna notificación crítica.',
              'alerta'   => 'Ningún aviso pendiente.',
              default    => $hayFiltro
                            ? 'Ninguna notificación coincide con el filtro.'
                            : 'Todavía no tiene notificaciones.',
          } ?>
        </p>
        <?php if ($hayFiltro): ?>
        <a href="<?= P::attr($rutas->url('notificaciones') . '?v=' . $vista) ?>"
           class="btn btn-sm btn-outline-secondary mt-2">Quitar filtros</a>
        <?php endif; ?>
      </div>
    <?php else: ?>

    <div class="cm-notis">
      <?php foreach ($notificaciones as $n):
        [$color, $icono] = $estilo[$n['severidad']] ?? ['info', 'info'];
      ?>
      <div class="cm-noti <?= $n['leida'] ? '' : 'cm-noti--nueva' ?>"
           data-codigo="<?= P::attr($n['codigo']) ?>">

        <span class="cm-noti__icono bg-<?= $color ?> text-white">
          <?= cm_icono($icono, 17) ?>
        </span>

        <div class="cm-noti__cuerpo">
          <div class="d-flex justify-content-between align-items-start" style="gap:.6rem">
            <strong class="cm-noti__titulo"><?= P::e($n['titulo']) ?></strong>
            <small class="text-muted" style="white-space:nowrap;font-size:.72rem">
              <?= P::e(P::hace((string) $n['creada_en'])) ?>
            </small>
          </div>

          <p class="cm-noti__texto"><?= P::e($n['mensaje']) ?></p>

          <div class="cm-noti__pie">
            <span class="badge badge-light"><?= P::e(str_replace(['_', '.'], ' ', (string) $n['tipo'])) ?></span>
            <span class="text-muted" style="font-size:.72rem">
              <?= P::e(P::fecha((string) $n['creada_en'], 'd/m/Y H:i')) ?>
            </span>

            <?php if ($n['destino'] !== ''): ?>
            <a href="<?= P::attr($n['destino']) ?>" class="cm-noti__ir">Ver detalle &rarr;</a>
            <?php endif; ?>

            <?php if (!$n['leida']): ?>
            <button type="button" class="cm-noti__marcar btn-marcar">Marcar como leída</button>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php
    $pag          = $paginacion;
    $enlace       = static fn(int $p): string
                    => $rutas->url('notificaciones') . $conFiltros(['p' => $p]);
    $enlaceTamano = static fn(int $t): string
                    => $rutas->url('notificaciones') . $conFiltros(['pp' => $t, 'p' => 1]);

    include __DIR__ . '/../layout/paginacion.php';
    ?>
    <?php endif; ?>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var BASE = <?= P::js($rutas->url('notificaciones/')) ?>;
  /* El token de seguridad se lee vivo, no congelado.

     Aquí había una copia del token tomada al
     dibujar la página. Como el token es de un solo uso, la primera
     acción lo gastaba y la segunda fallaba siempre con «el token de
     seguridad venció»: pedir el código llegaba, pulsar «Ver datos» no.

     El servidor manda el reemplazo en cada respuesta y `cm.js` lo
     guarda en `window.CM_CSRF`. Leer de ahí vale para toda la vida de
     la pantalla, por muchas acciones que se encadenen. */
  window.CM_CSRF = window.CM_CSRF || <?= P::js($csrf) ?>;

  function enviar(url) {
    var fd = new FormData();
    fd.append('_csrf', window.CM_CSRF || window.CM_CSRF);
    fd.append('_csrf_proposito', 'notificacion');

    return fetch(url, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); });
  }

  document.querySelectorAll('.btn-marcar').forEach(function (b) {
    b.addEventListener('click', function () {
      var caja = b.closest('.cm-noti');

      enviar(BASE + caja.dataset.codigo + '/leer').then(function (j) {
        if (!j.ok) { return; }
        // Se actualiza en el sitio: recargar perdería el desplazamiento.
        caja.classList.remove('cm-noti--nueva');
        b.remove();
      });
    });
  });

  var btnTodas = document.getElementById('btn-todas');
  if (btnTodas) {
    btnTodas.addEventListener('click', function () {
      enviar(BASE + 'leer-todas').then(function (j) {
        if (j.ok) { setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 900); }
      });
    });
  }
})();
</script>
