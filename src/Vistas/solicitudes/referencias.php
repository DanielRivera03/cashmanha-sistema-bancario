<?php
/**
 * Referencias vistas desde una solicitud.
 *
 * Esta pantalla solo consulta: las referencias pertenecen al expediente
 * del cliente y se gestionan allí. Aquí sirven para que quien evalúa la
 * solicitud vea con qué respaldo cuenta.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $solicitud
 * @var array  $referencias
 * @var int    $verificadas
 * @var int    $minimo
 * @var string $codigoTitular
 * @var bool   $puedeExpediente
 * @var bool   $puedeVerificar
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$total  = count($referencias);
$faltan = max(0, $minimo - $total);

$colorTipo = static fn(string $t): string => match ($t) {
    'personal'  => 'primary',
    'laboral'   => 'info',
    'familiar'  => 'success',
    'comercial' => 'warning',
    default     => 'secondary',
};
?>

<!-- ==================== CONTEXTO ==================== -->
<div class="card">
  <div class="card-body d-flex flex-wrap justify-content-between align-items-center">
    <div style="min-width:0">
      <h5 class="mb-1" style="font-size:1rem">
        Solicitud <span class="cm-mono"><?= P::e($solicitud['folio']) ?></span>
      </h5>
      <p class="text-muted mb-0" style="font-size:.85rem">
        <?= P::e($solicitud['cliente']) ?> ·
        <?= P::e($solicitud['producto']) ?> ·
        <?= P::e(P::money((int) $solicitud['monto_cent'])) ?>
        a <?= (int) $solicitud['plazo_meses'] ?> meses
      </p>
    </div>

    <div class="text-right">
      <span class="badge badge-info">
        <?= P::e(str_replace('_', ' ', (string) $solicitud['etapa'])) ?>
      </span>
      <a href="<?= P::attr($rutas->url('solicitudes/' . $solicitud['codigo'])) ?>"
         class="btn btn-sm btn-outline-primary ml-2">Volver a la solicitud</a>
    </div>
  </div>
</div>

<!-- ==================== RESPALDO ==================== -->
<div class="card">
  <div class="card-body">
    <div class="row text-center">
      <div class="col-md-4 col-6">
        <h4 class="mb-0 font-weight-bold"><?= $total ?></h4>
        <small class="text-muted" style="font-size:.74rem">en el expediente</small>
      </div>
      <div class="col-md-4 col-6">
        <h4 class="mb-0 font-weight-bold text-success"><?= $verificadas ?></h4>
        <small class="text-muted" style="font-size:.74rem">verificadas</small>
      </div>
      <div class="col-md-4 col-12 mt-3 mt-md-0">
        <h4 class="mb-0 font-weight-bold <?= $faltan > 0 ? 'text-danger' : 'text-success' ?>">
          <?= $faltan > 0 ? $faltan : '✓' ?>
        </h4>
        <small class="text-muted" style="font-size:.74rem">
          <?= $faltan > 0 ? 'faltan para evaluar' : 'mínimo cubierto' ?>
        </small>
      </div>
    </div>

    <?php if ($faltan > 0): ?>
    <div class="cm-aviso cm-aviso--alerta mt-3 mb-0">
      <?= cm_icono('warning', 17) ?>
      <span>
        La política de crédito pide al menos <?= (int) $minimo ?> referencias.
        Faltan <?= $faltan ?> en el expediente del cliente.
        <?php if ($puedeExpediente): ?>
          <a href="<?= P::attr($rutas->url('clientes/' . $codigoTitular . '/referencias')) ?>">
            Añadirlas al expediente →
          </a>
        <?php endif; ?>
      </span>
    </div>
    <?php elseif ($verificadas < $total): ?>
    <div class="cm-aviso cm-aviso--info mt-3 mb-0">
      <?= cm_icono('info', 17) ?>
      <span>
        Quedan <?= $total - $verificadas ?> referencia(s) sin verificar.
        Una referencia sin contactar no aporta información a la evaluación.
      </span>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ==================== DÓNDE SE GESTIONAN ==================== -->
<div class="card">
  <div class="card-body">
    <div class="cm-aviso cm-aviso--info mb-0">
      <?= cm_icono('info', 17) ?>
      <span>
        <strong>Estas referencias son del expediente del cliente, no de esta solicitud.</strong>
        Se conservan aunque la solicitud se cancele, y servirán para cualquier
        trámite futuro sin volver a pedirlas.
        <?php if ($puedeExpediente): ?>
          Para añadirlas, modificarlas o eliminarlas, entra en
          <a href="<?= P::attr($rutas->url('clientes/' . $codigoTitular . '/referencias')) ?>">
            sus referencias del expediente</a>.
        <?php endif; ?>
      </span>
    </div>
  </div>
</div>

<!-- ==================== LISTADO ==================== -->
<div class="card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h4 class="card-title mb-0">
      Referencias disponibles <span class="badge badge-light ml-1"><?= $total ?></span>
    </h4>
    <?php if ($puedeExpediente): ?>
    <a href="<?= P::attr($rutas->url('clientes/' . $codigoTitular . '/referencias')) ?>"
       class="btn btn-sm btn-primary">Gestionar en el expediente</a>
    <?php endif; ?>
  </div>

  <div class="card-body">
    <?php if ($referencias === []): ?>
      <div class="cm-vacio">
        <?= cm_icono('users', 40) ?>
        <h5 class="mt-3 mb-1" style="font-size:1rem">
          El cliente no tiene referencias en su expediente
        </h5>
        <p class="text-muted mb-3" style="font-size:.88rem;max-width:34rem;margin:0 auto">
          La política de crédito pide al menos <?= (int) $minimo ?> antes de
          evaluar la solicitud.
        </p>
        <?php if ($puedeExpediente): ?>
        <a href="<?= P::attr($rutas->url('clientes/' . $codigoTitular . '/referencias')) ?>"
           class="btn btn-sm btn-primary">Registrarlas en el expediente</a>
        <?php endif; ?>
      </div>
    <?php else: ?>

    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Referencia</th>
            <th>Tipo</th>
            <th>Relación</th>
            <th>Contacto</th>
            <th>Verificación</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($referencias as $r):
            $ok = (int) $r['verificada'] === 1;
          ?>
          <tr<?= $ok ? '' : ' class="table-warning"' ?>>
            <td>
              <strong style="font-size:.86rem"><?= P::e($r['nombre_completo']) ?></strong>
              <?php if (($r['empresa'] ?? '') !== '' || ($r['profesion'] ?? '') !== ''): ?>
              <small class="text-muted d-block" style="font-size:.73rem">
                <?= P::e(trim((string) ($r['profesion'] ?? '') . ' · ' . (string) ($r['empresa'] ?? ''), ' ·')) ?>
              </small>
              <?php endif; ?>
            </td>

            <td>
              <span class="badge badge-<?= $colorTipo((string) $r['tipo']) ?>">
                <?= P::e($r['tipo_nombre']) ?>
              </span>
            </td>

            <td style="font-size:.82rem">
              <?= P::e($r['parentesco'] ?: '—') ?>
              <?php if (($r['anios_conocer'] ?? null) !== null): ?>
              <small class="text-muted d-block" style="font-size:.72rem">
                <?= (int) $r['anios_conocer'] ?> año(s)
              </small>
              <?php endif; ?>
            </td>

            <td class="cm-mono" style="font-size:.78rem">
              <?= P::e($r['celular'] ?: $r['telefono'] ?: '—') ?>
              <?php if (($r['correo'] ?? '') !== ''): ?>
              <small class="text-muted d-block" style="font-size:.7rem;font-family:inherit">
                <?= P::e($r['correo']) ?>
              </small>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($ok): ?>
                <span class="badge badge-success">verificada</span>
                <small class="text-muted d-block" style="font-size:.7rem">
                  <?= P::e(P::fecha((string) $r['verificada_en'], 'd/m/Y')) ?>
                  <?php if (($r['verificador'] ?? '') !== ''): ?>
                    · <?= P::e($r['verificador']) ?>
                  <?php endif; ?>
                </small>
                <?php if (($r['notas'] ?? '') !== ''): ?>
                <small class="d-block text-muted" style="font-size:.72rem">
                  <em><?= P::e(mb_strimwidth((string) $r['notas'], 0, 70, '…')) ?></em>
                </small>
                <?php endif; ?>
              <?php else: ?>
                <span class="badge badge-warning">sin verificar</span>
                <?php if ($puedeVerificar): ?>
                <a href="<?= P::attr($rutas->url('clientes/' . $codigoTitular . '/referencias')) ?>"
                   class="d-block" style="font-size:.72rem">verificar →</a>
                <?php endif; ?>
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
