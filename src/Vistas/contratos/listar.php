<?php
/**
 * Contratos y finiquitos.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $contratos
 * @var array  $sinContrato
 * @var array  $conteos
 * @var array  $tipos
 * @var string $vista
 * @var array  $filtros
 * @var bool   $vistaTodos
 * @var bool   $puedeGenerar
 * @var bool   $puedeFirmar
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$hayFiltro = ($filtros['q'] ?? '') !== '';

$pestanas = [
    'todos'      => ['Todos',      'todos'],
    'sin_firmar' => ['Sin firmar', 'sin_firmar'],
    'firmados'   => ['Firmados',   'firmados'],
];
?>

<?php if ($puedeGenerar && $sinContrato !== []): ?>
<!-- ============ CRÉDITOS SIN CONTRATO ============ -->
<div class="card border-warning">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0">
      Créditos sin contrato
      <span class="badge badge-warning ml-1"><?= count($sinContrato) ?></span>
    </h4>
  </div>

  <div class="card-body pb-0">
    <p class="text-muted mb-3" style="font-size:.85rem">
      Estos créditos están aprobados, desembolsados o vigentes pero no tienen
      contrato emitido. Conviene generarlo antes de que el cliente pida su copia.
    </p>
  </div>

  <div class="card-body pt-0 p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Folio</th>
            <th>Cliente</th>
            <th>Producto</th>
            <th class="text-right">Monto</th>
            <th>Estado</th>
            <th class="text-right"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sinContrato as $sc): ?>
          <tr>
            <td class="cm-mono font-weight-bold" style="font-size:.82rem">
              <?= P::e($sc['folio']) ?>
            </td>
            <td style="font-size:.84rem"><?= P::e($sc['cliente']) ?></td>
            <td style="font-size:.84rem"><?= P::e($sc['producto']) ?></td>
            <td class="text-right cm-mono"><?= P::e(P::money((int) $sc['monto_cent'])) ?></td>
            <td><span class="badge badge-info"><?= P::e($sc['estado']) ?></span></td>
            <td class="text-right">
              <button class="btn btn-sm btn-primary btn-generar"
                      style="font-size:.74rem;padding:.2rem .6rem"
                      data-codigo="<?= P::attr($sc['codigo']) ?>"
                      data-folio="<?= P::attr($sc['folio']) ?>">
                Generar contrato
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ============ LISTADO ============ -->
<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">
      <?= $vistaTodos ? 'Contratos emitidos' : 'Mis contratos' ?>
      <?php if ((int) ($conteos['sin_firmar'] ?? 0) > 0): ?>
        <span class="badge badge-warning ml-1">
          <?= (int) $conteos['sin_firmar'] ?> sin firmar
        </span>
      <?php endif; ?>
    </h4>
  </div>

  <div class="card-body pb-0 pt-2">
    <ul class="nav nav-tabs cm-pestanas" style="border-bottom:1px solid #eaecf0">
      <?php foreach ($pestanas as $clave => [$etiqueta, $campo]):
        $n = (int) ($conteos[$campo] ?? 0);
        if ($n === 0 && $vista !== $clave && $clave !== 'todos') { continue; }
        $activa = $vista === $clave;
      ?>
      <li class="nav-item">
        <a class="nav-link <?= $activa ? 'active' : '' ?>"
           href="<?= P::attr($rutas->url('contratos') . '?v=' . $clave) ?>">
          <?= P::e($etiqueta) ?>
          <span class="badge badge-<?= $activa ? 'primary' : 'light' ?> ml-1"><?= $n ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <?php if ($vistaTodos): ?>
  <div class="card-body pb-2">
    <form method="get" class="form-row align-items-end mb-0">
      <input type="hidden" name="v" value="<?= P::attr($vista) ?>">
      <div class="form-group col-md-5">
        <label class="small mb-1">Buscar</label>
        <input type="search" name="q" class="form-control form-control-sm"
               value="<?= P::attr($filtros['q'] ?? '') ?>"
               placeholder="Folio, cliente o número de cuenta">
      </div>
      <div class="form-group col-md-2">
        <button class="btn btn-outline-primary btn-sm btn-block">Filtrar</button>
      </div>
      <?php if ($hayFiltro): ?>
      <div class="form-group col-md-2">
        <a href="<?= P::attr($rutas->url('contratos') . '?v=' . $vista) ?>"
           class="btn btn-outline-secondary btn-sm btn-block">Limpiar</a>
      </div>
      <?php endif; ?>
    </form>
  </div>
  <?php endif; ?>

  <div class="card-body <?= $vistaTodos ? 'pt-0' : '' ?>">
    <?php if ($contratos === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('files', 40) ?>
        <p class="mb-1 mt-2">
          <?= match ($vista) {
              'sin_firmar' => 'No hay contratos pendientes de firma.',
              'firmados'   => 'Todavía no se ha firmado ningún contrato.',
              default      => $hayFiltro
                              ? 'Ningún contrato coincide con la búsqueda.'
                              : ($vistaTodos
                                  ? 'Todavía no se ha emitido ningún contrato.'
                                  : 'No tiene contratos emitidos.'),
          } ?>
        </p>
        <?php if (!$vistaTodos): ?>
        <p class="text-muted mb-0" style="font-size:.85rem">
          Al contratar un crédito o una cuenta, el contrato aparecerá aquí
          para que lo revises y lo firmes.
        </p>
        <?php endif; ?>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0" data-cm-tabla>
        <thead>
          <tr>
            <th>Documento</th>
            <?php if ($vistaTodos): ?><th>Cliente</th><?php endif; ?>
            <th>Referencia</th>
            <th>Emitido</th>
            <th>Firma</th>
            <th>Integridad</th>
            <th class="text-right">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($contratos as $k): ?>
          <tr>
            <td>
              <strong style="font-size:.84rem"><?= P::e($k['tipo_nombre']) ?></strong>
              <small class="text-muted d-block cm-mono" style="font-size:.7rem">
                <?= P::e(substr((string) $k['uuid'], 0, 8)) ?>
              </small>
            </td>

            <?php if ($vistaTodos): ?>
            <td style="font-size:.84rem">
              <?= P::e($k['cliente']) ?>
              <?php if (!empty($k['es_mio'])): ?>
                <span class="badge badge-light">mío</span>
              <?php endif; ?>
              <small class="text-muted d-block" style="font-size:.72rem">
                <?= P::e($k['cliente_usuario']) ?>
              </small>
            </td>
            <?php endif; ?>

            <td class="cm-mono" style="font-size:.8rem">
              <?= P::e($k['folio'] ?? $k['numero_cuenta'] ?? '—') ?>
            </td>

            <td class="text-muted" style="font-size:.8rem">
              <?= P::e(P::fecha((string) $k['creado_en'], 'd/m/Y')) ?>
            </td>

            <td>
              <?php if ($k['firmado']): ?>
                <span class="badge badge-success">firmado</span>
                <small class="text-muted d-block" style="font-size:.7rem">
                  <?= P::e(P::fecha((string) $k['firmado_en'], 'd/m/Y H:i')) ?>
                  <?php if (($k['ip_firma_texto'] ?? '') !== ''): ?>
                    · <?= P::e($k['ip_firma_texto']) ?>
                  <?php endif; ?>
                </small>
              <?php else: ?>
                <span class="badge badge-warning">pendiente</span>
              <?php endif; ?>
            </td>

            <td>
              <?php if (!$k['existe']): ?>
                <span class="badge badge-danger">archivo ausente</span>
              <?php else: ?>
                <small class="cm-mono text-muted" style="font-size:.68rem"
                       title="SHA-256 archivado al emitirlo">
                  <?= P::e(substr((string) $k['hash_documento'], 0, 12)) ?>…
                </small>
              <?php endif; ?>
            </td>

            <td class="text-right" style="white-space:nowrap">
              <?php if ($k['existe']): ?>
              <a href="<?= P::attr($rutas->url('contratos/' . $k['codigo'] . '/pdf')) ?>"
                 target="_blank" rel="noopener"
                 data-cm-doc data-cm-doc-titulo="Preparando el contrato"
                 class="btn btn-sm btn-outline-primary" style="font-size:.73rem;padding:.18rem .55rem"
                 target="_blank">PDF</a>
              <?php endif; ?>

              <?php if ($puedeFirmar && !empty($k['firmable']) && !$k['firmado']
                        && !empty($k['es_mio']) && $k['existe']): ?>
              <button class="btn btn-sm btn-primary btn-firmar"
                      style="font-size:.73rem;padding:.18rem .55rem"
                      data-codigo="<?= P::attr($k['codigo']) ?>">
                Firmar
              </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($vistaTodos): ?>
    <div class="cm-aviso cm-aviso--info mt-3 mb-0">
      <?= cm_icono('shield', 17) ?>
      <span>
        <strong>Sobre la columna de integridad.</strong>
        Es el SHA-256 del PDF tal como se archivó. Cada vez que alguien abre el
        documento se vuelve a calcular y se compara: si no coincide, queda una
        incidencia en la bitácora de auditoría. Es lo que permite sostener que
        el contrato firmado y el que se muestra son el mismo.
      </span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var BASE_C = <?= P::js($rutas->url('creditos/')) ?>;
  var BASE_K = <?= P::js($rutas->url('contratos/')) ?>;
  /* El token de seguridad se lee vivo, no congelado.

     Aquí había una copia del token tomada al
     dibujar la página. Como el token es de un solo uso, la primera
     acción lo gastaba y la segunda fallaba siempre con «el token de
     seguridad venció»: pedir el código llegaba, pulsar «Ver datos» no.

     El servidor manda el reemplazo en cada respuesta y `cm.js` lo
     guarda en `window.CM_CSRF`. Leer de ahí vale para toda la vida de
     la pantalla, por muchas acciones que se encadenen. */
  window.CM_CSRF = window.CM_CSRF || <?= P::js($csrf) ?>;

  function post(url, extra) {
    var fd = new FormData();
    fd.append('_csrf', window.CM_CSRF || window.CM_CSRF);
    fd.append('_csrf_proposito', 'contrato');
    Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });

    return fetch(url, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); });
  }

  // ---------- Generar ----------
  document.querySelectorAll('.btn-generar').forEach(function (b) {
    b.addEventListener('click', function () {
      window.CMAviso.confirmar({
        titulo:  'Generar el contrato',
        mensaje: 'Se emitirá el contrato del crédito ' + b.dataset.folio
               + ' y quedará archivado con su huella digital.',
        confirmar: 'Generar'
      }).then(function (ok) {
        if (!ok) { return; }

        var espera = window.CMAviso.cargando('Generando el documento…');
        b.disabled = true;

        post(BASE_C + b.dataset.codigo + '/contrato').then(function (j) {
          espera.cerrar();
          b.disabled = false;
          if (j.ok) { setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1000); }
        }).catch(function () { espera.cerrar(); b.disabled = false; });
      });
    });
  });

  // ---------- Firmar ----------
  document.querySelectorAll('.btn-firmar').forEach(function (b) {
    b.addEventListener('click', function () {
      // La firma pide escribir el nombre completo a propósito: un botón
      // de "acepto" no deja constancia de nada.
      var modal = document.createElement('div');
      modal.className = 'cm-modal cm-modal--visible';
      modal.innerHTML =
        '<div class="cm-modal__caja" style="max-width:28rem">' +
          '<div class="cm-modal__icono cm-modal__icono--aviso">' +
            '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" ' +
            'stroke-width="2.3"><path d="M12 19l7-7 3 3-7 7-3-3z"/>' +
            '<path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/></svg>' +
          '</div>' +
          '<h5 class="cm-modal__titulo">Firmar el contrato</h5>' +
          '<p class="cm-modal__texto">Escriba su nombre completo tal como aparece ' +
            'en el documento. Quedará registrado junto con la fecha, su dirección ' +
            'IP y la huella del contrato.</p>' +
          '<input type="text" class="form-control mb-3" id="campo-firma" ' +
            'placeholder="Nombre y apellidos" autocomplete="off">' +
          '<div class="cm-modal__pie">' +
            '<button type="button" class="btn btn-outline-secondary" id="firma-no">Cancelar</button>' +
            '<button type="button" class="btn btn-primary" id="firma-si">Firmar</button>' +
          '</div>' +
        '</div>';

      document.body.appendChild(modal);
      document.body.style.overflow = 'hidden';

      var campo = modal.querySelector('#campo-firma');
      campo.focus();

      function cerrar() {
        document.body.style.overflow = '';
        modal.remove();
      }

      modal.querySelector('#firma-no').addEventListener('click', cerrar);
      modal.addEventListener('click', function (ev) { if (ev.target === modal) { cerrar(); } });

      function enviar() {
        var firma = campo.value.trim();
        if (firma.length < 5) {
          window.CMAviso.alerta('Falta su nombre', 'Escriba su nombre completo para firmar.');
          campo.focus();
          return;
        }

        var espera = window.CMAviso.cargando('Registrando la firma…');
        cerrar();

        post(BASE_K + b.dataset.codigo + '/firmar', { firma: firma }).then(function (j) {
          espera.cerrar();
          if (j.ok) { setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1100); }
        }).catch(function () { espera.cerrar(); });
      }

      modal.querySelector('#firma-si').addEventListener('click', enviar);
      campo.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); enviar(); }
      });
    });
  });
})();
</script>
