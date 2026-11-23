<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $ticket
 * @var array $respuestas
 * @var bool $gestiona
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$COLOR_EST = [
  'abierto' => 'primary', 'en_proceso' => 'warning', 'resuelto' => 'success',
  'no_resuelto' => 'danger', 'cerrado' => 'secondary',
];
$COLOR_PRI = ['baja' => 'light', 'media' => 'info', 'alta' => 'warning', 'critica' => 'danger'];
$cerrado   = $ticket['estado'] === 'cerrado';
$ruta      = $rutas->url('tickets/' . $ticket['codigo']);

/** Las iniciales, para el avatar. */
$iniciales = static function (string $nombre): string {
    $p = preg_split('/\s+/', trim($nombre)) ?: [''];

    return mb_strtoupper(mb_substr($p[0] ?? '', 0, 1) . mb_substr($p[1] ?? '', 0, 1));
};

/* El hilo completo: la descripción original primero y luego las
   respuestas. Una conversación empieza por lo que alguien dijo, no por
   lo que le contestaron; tener la descripción en un panel aparte la
   partía en dos. */
$hilo = [[
    'autor'     => (string) $ticket['solicitante'],
    'rol_id'    => 5,
    'cuerpo'    => (string) $ticket['descripcion'],
    'creado_en' => (string) $ticket['creado_en'],
    'interna'   => 0,
    'apertura'  => true,
]];

foreach ($respuestas as $r) {
    $hilo[] = $r + ['apertura' => false];
}

/* De qué lado va cada mensaje. El personal ve los suyos a un lado y
   los del cliente al otro; el cliente, al revés. Saber de un vistazo
   quién dijo qué es la diferencia entre leer una conversación y
   reconstruirla. */
$soyPersonal = $gestiona;
?>
<div class="row">
  <div class="col-lg-8 col-md-12">

    <div class="card">
      <div class="card-header">
        <div style="flex:1;min-width:0">
          <h4 class="card-title mb-1"><?= P::e($ticket['titulo']) ?></h4>
          <small class="text-muted">
            <span class="cm-mono"><?= P::e($ticket['folio']) ?></span> &middot;
            <?= P::e($ticket['solicitante']) ?> &middot;
            <?= P::e(P::fecha((string) $ticket['creado_en'])) ?>
          </small>
        </div>
        <span class="badge badge-<?= P::attr($COLOR_EST[$ticket['estado']] ?? 'light') ?>">
          <?= P::e(str_replace('_', ' ', (string) $ticket['estado'])) ?>
        </span>
      </div>

    </div>

    <!-- Conversación -->
    <div class="card">
      <div class="card-header">
        <h4 class="card-title mb-0">Conversación
          <span class="badge badge-light ml-1"><?= count($respuestas) ?></span>
        </h4>
      </div>
      <div class="card-body cm-hilo">
        <?php foreach ($hilo as $m): ?>
        <?php
          $esCliente = (int) ($m['rol_id'] ?? 5) === 5;
          $mio       = $soyPersonal ? !$esCliente : $esCliente;
          $interna   = (int) ($m['interna'] ?? 0) === 1;
        ?>
        <div class="cm-hilo__msg<?= $mio ? ' is-mio' : '' ?><?= $interna ? ' is-interna' : '' ?>">
          <span class="cm-avatar cm-hilo__avatar"
                <?= $esCliente ? '' : 'style="background:#175cd3"' ?>>
            <?= P::e($iniciales((string) $m['autor'])) ?>
          </span>

          <div class="cm-hilo__burbuja">
            <div class="cm-hilo__cab">
              <strong><?= P::e((string) $m['autor']) ?></strong>
              <?php if (!$esCliente): ?>
                <span class="cm-hilo__quien">equipo CashMan</span>
              <?php endif; ?>
              <?php if ($interna): ?>
                <span class="badge badge-warning">nota interna</span>
              <?php endif; ?>
              <?php if (!empty($m['apertura'])): ?>
                <span class="cm-hilo__quien">abrió la gestión</span>
              <?php endif; ?>
              <time datetime="<?= P::attr((string) $m['creado_en']) ?>">
                <?= P::e(P::hace((string) $m['creado_en'])) ?>
              </time>
            </div>
            <div class="cm-hilo__cuerpo"><?= P::e((string) $m['cuerpo']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>

        <?php /* El cierre, como última entrada del hilo. Una gestión que
                 termina sin decir cómo terminó obliga a preguntar por
                 qué se cerró, y esa pregunta llega siempre. */ ?>
        <?php if ($cerrado): ?>
        <div class="cm-hilo__cierre">
          <div>
            <strong>
              Gestión <?= P::e(str_replace('_', ' ', (string) $ticket['estado'])) ?><?php
                if (!empty($ticket['cerrado_en'])): ?>
                el <?= P::e(P::fecha((string) $ticket['cerrado_en'])) ?><?php
                endif; ?>
            </strong>
            <?php if (!empty($ticket['resolucion'])): ?>
              <p class="mb-0"><?= P::e((string) $ticket['resolucion']) ?></p>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!$cerrado): ?>
      <div class="card-footer">
        <form data-cm-ajax data-cm-recargar method="post" action="<?= P::attr($ruta . '/responder') ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="ticket">
          <div class="form-group mb-2">
            <textarea class="form-control" name="cuerpo" rows="3" maxlength="4000" required
                      placeholder="<?= $gestiona ? 'Escriba su respuesta al cliente...' : 'Escriba aquí y le respondemos...' ?>"></textarea>
          </div>
          <div class="d-flex align-items-center">
            <?php if ($gestiona): ?>
            <div class="custom-control custom-checkbox mr-3">
              <input type="checkbox" class="custom-control-input" id="interna" name="interna" value="1">
              <label class="custom-control-label small" for="interna">
                Nota interna (el cliente no la ve)
              </label>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-sm btn-primary ml-auto"><?= $gestiona ? 'Responder al cliente' : 'Enviar' ?></button>
          </div>
        </form>
      </div>
      <?php else: ?>
      <div class="card-footer text-muted small">Esta gestión está cerrada y no admite más respuestas. El historial queda completo arriba. Si necesita algo más sobre lo mismo, abra una nueva.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Panel lateral -->
  <div class="col-lg-4 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Detalles</h4></div>
      <div class="card-body">
        <div class="mb-2">
          <small class="text-muted d-block">Categoría</small>
          <span class="badge badge-light"><?= P::e(ucfirst((string) $ticket['categoria'])) ?></span>
        </div>
        <div class="mb-2">
          <small class="text-muted d-block">Prioridad</small>
          <span class="badge badge-<?= P::attr($COLOR_PRI[$ticket['prioridad']] ?? 'light') ?>">
            <?= P::e(ucfirst((string) $ticket['prioridad'])) ?>
          </span>
        </div>
        <?php if (!empty($ticket['via']) && $ticket['via'] !== 'general'): ?>
        <div class="mb-2">
          <small class="text-muted d-block">Atención</small>
          <strong style="font-size:.88rem">
            <?= $ticket['via'] === 'banquero'
                ? 'Su banquero asignado'
                : 'Prioritaria por su categoría' ?>
          </strong>
        </div>
        <?php endif; ?>
        <div class="mb-2">
          <small class="text-muted d-block">Responsable</small>
          <strong style="font-size:.88rem"><?= P::e($ticket['responsable'] ?: '— sin asignar —') ?></strong>
        </div>
        <div class="mb-2">
          <small class="text-muted d-block">Última actividad</small>
          <strong style="font-size:.88rem"><?= P::e(P::hace((string) $ticket['ultima_actividad'])) ?></strong>
        </div>
        <?php if (!empty($ticket['resolucion'])): ?>
        <div class="alert alert-success mt-3 mb-0" style="font-size:.85rem">
          <strong>Resolución</strong><br><?= P::e($ticket['resolucion']) ?>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($gestiona && !$cerrado): ?>
      <div class="card-footer">
        <form data-cm-ajax data-cm-recargar method="post" action="<?= P::attr($ruta . '/estado') ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="ticket">
          <div class="form-group">
            <label class="small">Cambiar estado</label>
            <select class="form-control form-control-sm" name="estado" required>
              <?php foreach (['abierto','en_proceso','resuelto','no_resuelto','cerrado'] as $e): ?>
              <option value="<?= P::attr($e) ?>"<?= $ticket['estado'] === $e ? ' selected' : '' ?>>
                <?= P::e(ucfirst(str_replace('_', ' ', $e))) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="small">Resolución (opcional)</label>
            <textarea class="form-control form-control-sm" name="resolucion" rows="2"
                      placeholder="Qué se hizo..."></textarea>
          </div>
          <button type="submit" class="btn btn-sm btn-primary btn-block">Actualizar</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <a href="<?= P::attr($rutas->url('tickets')) ?>" class="btn btn-outline-secondary btn-block">
      Volver a la lista
    </a>
  </div>
</div>
