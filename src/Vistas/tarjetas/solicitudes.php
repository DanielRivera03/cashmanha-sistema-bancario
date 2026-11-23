<?php
/**
 * Bandeja de solicitudes de tarjeta.
 *
 * Muestra, junto al límite pedido, el que admite la capacidad de pago
 * del cliente. Es el dato con el que se decide: aprobar lo pedido cuando
 * cabe, y saber de antemano a cuánto se va a recortar cuando no.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $solicitudes
 * @var array  $conteos
 * @var string $etapa
 * @var bool   $puedeAtencion
 * @var bool   $puedeGerencia
 * @var bool   $puedeEmitir
 * @var array  $productos
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$pestanas = [
    ''                  => ['Todas',          'todas'],
    'capturada'         => ['Recibidas',      'capturada'],
    'revision_atencion' => ['En atención',    'revision_atencion'],
    'revision_gerencia' => ['En gerencia',    'revision_gerencia'],
    'aprobada'          => ['Aprobadas',      'aprobada'],
    // Aprobar emite la tarjeta en el acto, así que la etapa final normal
    // es «emitida»: sin esta pestaña, una solicitud resuelta desaparecía
    // de la bandeja y parecía que se había perdido.
    'emitida'           => ['Emitidas',       'emitida'],
    'rechazada'         => ['Rechazadas',     'rechazada'],
];

$color = static fn(string $e): string => match ($e) {
    'aprobada', 'emitida' => 'success',
    'rechazada'           => 'danger',
    'revision_gerencia'   => 'primary',
    'revision_atencion'   => 'info',
    default               => 'light',
};

/** Quién puede mover esta etapa. */
$puedeMover = static function (string $e) use ($puedeAtencion, $puedeGerencia, $puedeEmitir): bool {
    return match ($e) {
        'capturada', 'revision_atencion' => $puedeAtencion,
        'revision_gerencia'              => $puedeGerencia,
        // Aprobada y sin plástico: son las de antes de que la emisión
        // fuera automática. Se cierran emitiendo, no volviendo a aprobar.
        'aprobada'                       => $puedeEmitir,
        default                          => false,
    };
};

/** Qué dice el botón según lo que va a pasar al pulsarlo. */
$etiquetaAvance = static fn(string $e): string => $e === 'aprobada'
    ? 'Emitir tarjeta'
    : 'Avanzar';

$confirmaAvance = static fn(string $e, string $folio): string => $e === 'aprobada'
    ? 'Se emitirá la tarjeta de la solicitud ' . $folio
      . ' con el límite ya aprobado. ¿Continuar?'
    : '¿Avanzar la solicitud ' . $folio . ' a la siguiente etapa?';

/** Las solicitudes aprobadas sin tarjeta: quedaron a medias. */
$aprobadaSinTarjeta = static fn(array $s): bool =>
    (string) $s['etapa'] === 'aprobada' && empty($s['tarjeta_codigo']);
?>

<div class="card">
  <div class="card-body pb-0 pt-2">
    <ul class="nav nav-tabs cm-pestanas" style="border-bottom:1px solid #eaecf0">
      <?php foreach ($pestanas as $clave => [$etiqueta, $campo]):
        $n = (int) ($conteos[$campo] ?? 0);
        $activa = (string) $etapa === (string) $clave;
      ?>
      <li class="nav-item">
        <a class="nav-link <?= $activa ? 'active' : '' ?>"
           href="<?= P::attr($rutas->url('tarjetas/solicitudes') . ($clave !== '' ? '?etapa=' . $clave : '')) ?>">
          <?= P::e($etiqueta) ?>
          <span class="badge badge-<?= $activa ? 'primary' : 'light' ?> ml-1"><?= $n ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="card-body pt-3">
    <?php if ($solicitudes === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('card', 40) ?>
        <p class="mb-0 mt-2">No hay solicitudes en este estado.</p>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0" data-cm-tabla>
        <thead>
          <tr>
            <th>Folio</th>
            <th class="cm-nombre">Cliente</th>
            <th>Tarjeta</th>
            <th class="text-right">Ingreso</th>
            <th class="text-right">Pide</th>
            <th class="text-right">Capacidad</th>
            <th>Estado</th>
            <th class="cm-acciones">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($solicitudes as $s):
            $pide  = (int) $s['limite_solicitado_cent'];
            $cap   = (int) ($s['capacidad_maxima_cent'] ?? 0);
            $ajusta = $cap > 0 && $pide > $cap;
          ?>
          <tr>
            <td class="cm-mono cm-nowrap" style="font-size:.78rem">
              <?= P::e($s['folio']) ?>
              <small class="text-muted d-block" style="font-size:.7rem">
                <?= P::e(P::fecha((string) $s['creado_en'], 'd/m/Y')) ?>
              </small>
            </td>
            <td class="cm-nombre" style="font-size:.84rem">
              <?= P::e($s['cliente']) ?>
              <small class="text-muted d-block" style="font-size:.72rem"><?= P::e($s['usuario']) ?></small>
            </td>
            <td style="font-size:.82rem">
              <?= P::e($s['producto']) ?>
              <small class="text-muted d-block" style="font-size:.72rem">
                <?= P::e(ucfirst((string) $s['marca'])) ?>
              </small>
            </td>
            <td class="text-right cm-mono cm-nowrap"><?= P::e(P::money((int) $s['ingreso_cent'])) ?></td>
            <td class="text-right cm-mono cm-nowrap"><?= P::e(P::money($pide)) ?></td>
            <td class="text-right cm-mono cm-nowrap <?= $ajusta ? 'text-danger font-weight-bold' : '' ?>">
              <?= $cap > 0 ? P::e(P::money($cap)) : '—' ?>
              <?php if ($ajusta): ?>
              <small class="d-block text-danger" style="font-size:.68rem">se recortará</small>
              <?php endif; ?>
            </td>
            <td class="cm-nowrap">
              <span class="badge badge-<?= $color((string) $s['etapa']) ?>">
                <?= P::e(str_replace('_', ' ', (string) $s['etapa'])) ?>
              </span>
              <?php if (!empty($s['motivo_rechazo'])): ?>
              <small class="text-muted d-block" style="font-size:.68rem;max-width:14rem">
                <?= P::e($s['motivo_rechazo']) ?>
              </small>
              <?php endif; ?>
              <?php if ($s['limite_aprobado_cent'] !== null): ?>
              <small class="text-success d-block" style="font-size:.7rem">
                aprobado <?= P::e(P::money((int) $s['limite_aprobado_cent'])) ?>
              </small>
              <?php endif; ?>
            </td>
            <td class="cm-nowrap" style="font-size:.8rem">
              <!-- La tarjeta que salió de esta solicitud. Es la prueba de
                   que la aprobación terminó en algo real y el atajo para
                   ir a verla sin buscarla en el parque de tarjetas. -->
              <?php if (!empty($s['tarjeta_codigo'])): ?>
                <a href="<?= P::attr($rutas->url('tarjetas/' . $s['tarjeta_codigo'])) ?>"
                   class="cm-mono" title="Ver la tarjeta emitida">
                  ···· <?= P::e((string) $s['tarjeta_ultimos4']) ?>
                </a>
                <small class="text-muted d-block" style="font-size:.68rem">emitida</small>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="cm-acciones">
              <?php if ($puedeMover((string) $s['etapa'])): ?>
              <form method="post" data-cm-ajax style="display:inline"
                    action="<?= P::attr($rutas->url('tarjetas/solicitudes/' . $s['codigo'] . '/avanzar')) ?>">
                <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
                <input type="hidden" name="_csrf_proposito" value="solicitud_tarjeta">
                <button class="btn btn-sm btn-primary"
                        data-cm-confirmar="<?= P::attr($confirmaAvance((string) $s['etapa'], (string) $s['folio'])) ?>">
                  <?= P::e($etiquetaAvance((string) $s['etapa'])) ?>
                </button>
              </form>

              <?php if ($aprobadaSinTarjeta($s)): ?>
              <!-- Ya no se rechaza lo que el cliente dio por aprobado. -->
              <?php else: ?>
              <!-- ---------- CORREGIR Y RECHAZAR ----------
                   Los dos abren una ventana, no un desplegable dentro de
                   la celda. Un panel de cinco campos colgando de un <td>
                   lo recorta el desplazamiento de la tabla y además la
                   ensancha: se veía cortado por la derecha y empujando
                   una barra horizontal. Una ventana tiene sitio de sobra
                   y no depende del ancho de la columna. -->
              <button type="button" class="btn btn-sm btn-outline-secondary"
                      data-cm-corregir
                      data-codigo="<?= P::attr($s['codigo']) ?>"
                      data-folio="<?= P::attr($s['folio']) ?>"
                      data-cliente="<?= P::attr($s['cliente']) ?>"
                      data-producto="<?= P::attr((string) ($s['producto_id'] ?? '')) ?>"
                      data-marca="<?= P::attr($s['marca']) ?>"
                      data-limite="<?= P::attr(number_format((int) $s['limite_solicitado_cent'] / 100, 2, '.', '')) ?>"
                      data-ingreso="<?= P::attr(number_format((int) $s['ingreso_cent'] / 100, 2, '.', '')) ?>">
                Corregir
              </button>

              <button type="button" class="btn btn-sm btn-outline-danger"
                      data-cm-rechazar
                      data-codigo="<?= P::attr($s['codigo']) ?>"
                      data-folio="<?= P::attr($s['folio']) ?>"
                      data-cliente="<?= P::attr($s['cliente']) ?>">
                Rechazar
              </button>
              <?php endif; ?>
              <?php else: ?>
              <span class="text-muted" style="font-size:.76rem">—</span>
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

<div class="alert alert-light border" style="font-size:.84rem">
  <strong>Sobre el límite.</strong> La columna «Capacidad» es el máximo que
  admite el ingreso del cliente descontando lo que ya paga cada mes. Al
  aprobar, el sistema otorga el menor entre lo pedido y esa cifra: nadie puede
  aprobar por encima de la capacidad, ni por descuido ni a propósito.
</div>

<!-- ==================== CORREGIR ==================== -->
<div class="modal fade" id="modal-corregir" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Corregir la solicitud <span id="cor-folio"></span></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="post" data-cm-ajax data-cm-recargar id="form-corregir" action="">
        <div class="modal-body">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="solicitud_tarjeta">

          <p class="text-muted mb-3" style="font-size:.85rem">
            Para <strong id="cor-cliente"></strong>. Lo que cambie queda escrito en
            el expediente, de modo que quien apruebe después vea que se corrigió y
            por qué.
          </p>

          <div class="form-row">
            <div class="form-group col-md-7">
              <label>Tarjeta</label>
              <select class="form-control" name="producto" id="cor-producto">
                <?php foreach ($productos as $pr): ?>
                <option value="<?= P::attr($pr['codigo']) ?>" data-id="<?= (int) $pr['id'] ?>">
                  <?= P::e($pr['nombre']) ?>
                  <?php if ((int) $pr['ingreso_minimo_cent'] > 0): ?>
                    — desde <?= P::e(P::money((int) $pr['ingreso_minimo_cent'])) ?> de ingreso
                  <?php endif; ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-5">
              <label>Marca</label>
              <select class="form-control" name="marca" id="cor-marca">
                <option value="visa">Visa</option>
                <option value="mastercard">Mastercard</option>
                <option value="amex">American Express</option>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group col-md-6">
              <label>Límite que pide</label>
              <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" name="limite" id="cor-limite"
                       inputmode="decimal">
              </div>
            </div>

            <?php if ($puedeGerencia): ?>
            <!-- El ingreso solo lo corrige gerencia: de ese dato cuelga la
                 capacidad de pago, y por tanto el límite que se puede
                 aprobar. Quien captura no debería poder subirlo. -->
            <div class="form-group col-md-6">
              <label>Ingreso declarado</label>
              <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" name="ingreso" id="cor-ingreso"
                       inputmode="decimal">
              </div>
              <small class="form-text text-muted">
                Al cambiarlo se recalcula la capacidad de pago.
              </small>
            </div>
            <?php endif; ?>
          </div>

          <div class="form-group mb-0">
            <label>¿Por qué se corrige?</label>
            <textarea class="form-control" name="observacion" rows="2"
                      placeholder="Ej. el cliente pidió la Oro y se capturó la Clásica"></textarea>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">Guardar corrección</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ==================== RECHAZAR ==================== -->
<div class="modal fade" id="modal-rechazar" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Rechazar la solicitud <span id="rec-folio"></span></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="post" data-cm-ajax data-cm-recargar id="form-rechazar" action="">
        <div class="modal-body">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="solicitud_tarjeta">

          <!-- El motivo se escribe a la vista, como en las solicitudes de
               crédito: el cliente lo va a recibir tal cual, así que quien
               rechaza debe verlo mientras lo redacta. -->
          <p class="text-muted mb-2" style="font-size:.85rem">
            <strong id="rec-cliente"></strong> recibirá este motivo tal como lo
            escriba. Antes de rechazar, valore si una corrección resuelve el caso.
          </p>

          <div class="form-group mb-0">
            <label>Motivo que verá el cliente</label>
            <textarea class="form-control" name="motivo" rows="3" required minlength="5"
                      placeholder="Ej. el ingreso declarado no cubre el requisito del producto solicitado"></textarea>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger" data-cm-peligro
                  data-cm-confirmar="Se rechazará la solicitud y se le avisará al cliente con este motivo. ¿Continuar?">
            Confirmar rechazo
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var BASE = <?= P::js($rutas->url('tarjetas/solicitudes/')) ?>;

  /* Una sola ventana para todas las filas.
     Repetir el formulario en cada fila multiplicaba el HTML y, sobre
     todo, metía cinco campos dentro de un <td> que la tabla recortaba. */
  document.addEventListener('click', function (ev) {
    var cor = ev.target.closest('[data-cm-corregir]');

    if (cor) {
      var d = cor.dataset;

      document.getElementById('form-corregir').action = BASE + d.codigo + '/editar';
      document.getElementById('cor-folio').textContent = d.folio;
      document.getElementById('cor-cliente').textContent = d.cliente;
      document.getElementById('cor-limite').value = d.limite;

      var ing = document.getElementById('cor-ingreso');
      if (ing) { ing.value = d.ingreso; }

      document.getElementById('cor-marca').value = d.marca;

      // El producto llega por id; el <option> viaja por código.
      var sel = document.getElementById('cor-producto');
      for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].dataset.id === d.producto) { sel.selectedIndex = i; break; }
      }

      $('#modal-corregir').modal('show');
      return;
    }

    var rec = ev.target.closest('[data-cm-rechazar]');

    if (rec) {
      document.getElementById('form-rechazar').action = BASE + rec.dataset.codigo + '/rechazar';
      document.getElementById('rec-folio').textContent = rec.dataset.folio;
      document.getElementById('rec-cliente').textContent = rec.dataset.cliente;
      $('#modal-rechazar').modal('show');
    }
  });
})();
</script>
