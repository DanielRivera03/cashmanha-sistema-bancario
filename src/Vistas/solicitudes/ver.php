<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $solicitud
 * @var array|null $simulacion
 * @var array $etapas
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$ruta  = $rutas->url('solicitudes/' . $solicitud['codigo']);
$orden = ['capturada','revision_atencion','revision_gerencia','revision_presidencia','aprobada'];
$pos   = array_search($solicitud['etapa'], $orden, true);
$final = in_array($solicitud['etapa'], ['aprobada','rechazada','desembolsada'], true);
?>

<!-- Línea de etapas -->
<div class="card">
  <div class="card-body">
    <?php if ($solicitud['etapa'] === 'rechazada'): ?>
      <div class="alert alert-danger mb-0">
        <strong>Solicitud rechazada.</strong>
        <?php if (!empty($solicitud['motivo_rechazo'])): ?>
          <br>Motivo: <?= P::e($solicitud['motivo_rechazo']) ?>
        <?php endif; ?>
      </div>
    <?php else: ?>
    <div class="cm-etapas">
      <?php foreach ($orden as $i => $e):
        $hecha  = $pos !== false && $i < $pos;
        $actual = $solicitud['etapa'] === $e;
        $clase  = $hecha ? 'hecha' : ($actual ? 'actual' : '');
        if ($solicitud['etapa'] === 'desembolsada') { $clase = 'hecha'; }
      ?>
      <div class="cm-etapa <?= P::attr($clase) ?>"><?= P::e($etapas[$e][0] ?? $e) ?></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="row">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title mb-0">Datos de la solicitud</h4>
      </div>
      <div class="card-body">
        <div class="row">
          <?php
            $campos = [
              'Folio'            => $solicitud['folio'],
              'Cliente'          => $solicitud['cliente'],
              'Correo'           => $solicitud['correo'],
              'Producto'         => $solicitud['producto'],
              'Monto solicitado' => P::money((int) $solicitud['monto_cent']),
              'Plazo'            => (int) $solicitud['plazo_meses'] . ' meses',
              'Tasa anual'       => number_format((int) $solicitud['tasa_interes_bp'] / 100, 2) . '%',
              'Ingreso declarado'=> P::money((int) $solicitud['ingreso_cent']),
              'Destino'          => $solicitud['destino'] ?: '—',
              'Fecha'            => P::fecha((string) $solicitud['creado_en']),
            ];
            foreach ($campos as $et => $val):
          ?>
          <div class="col-md-6 mb-3">
            <small class="text-muted d-block"><?= P::e($et) ?></small>
            <strong><?= P::e((string) $val) ?></strong>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if (!empty($solicitud['dui']) || !empty($solicitud['empresa'])): ?>
        <hr>
        <h6 class="text-muted" style="font-size:.78rem;letter-spacing:.05em">EXPEDIENTE DEL CLIENTE</h6>
        <div class="row">
          <?php foreach ([
            'DUI'      => $solicitud['dui'] ?? '—',
            'NIT'      => $solicitud['nit'] ?? '—',
            'Teléfono' => $solicitud['telefono'] ?? '—',
            'Empresa'  => $solicitud['empresa'] ?? '—',
            'Cargo'    => $solicitud['cargo'] ?? '—',
          ] as $et => $val): ?>
          <div class="col-md-4 mb-2">
            <small class="text-muted d-block"><?= P::e($et) ?></small>
            <span style="font-size:.9rem"><?= P::e((string) ($val ?: '—')) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($solicitud['observaciones'])): ?>
        <hr>
        <h6 class="text-muted" style="font-size:.78rem;letter-spacing:.05em">OBSERVACIONES</h6>
        <div style="white-space:pre-wrap;font-size:.88rem" class="text-muted">
          <?= P::e($solicitud['observaciones']) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($simulacion !== null): ?>
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Condiciones del crédito</h4></div>
      <div class="card-body">
        <div class="row text-center mb-3">
          <div class="col-3">
            <small class="text-muted d-block">Cuota mensual</small>
            <h5 class="mb-0"><?= P::e($simulacion['cuota']['formateado']) ?></h5>
          </div>
          <div class="col-3">
            <small class="text-muted d-block">Interés total</small>
            <h5 class="mb-0 cm-neg"><?= P::e($simulacion['interes_total']['formateado']) ?></h5>
          </div>
          <div class="col-3">
            <small class="text-muted d-block">Total a pagar</small>
            <h5 class="mb-0"><?= P::e($simulacion['total_pagar']['formateado']) ?></h5>
          </div>
          <div class="col-3">
            <small class="text-muted d-block">Costo</small>
            <h5 class="mb-0"><?= P::e((string) $simulacion['costo_pct']) ?>%</h5>
          </div>
        </div>

        <details>
          <summary class="text-primary" style="cursor:pointer;font-size:.88rem">
            Ver tabla de amortización (<?= count($simulacion['tabla']) ?> cuotas)
          </summary>
          <div class="table-responsive mt-2">
            <table class="table table-sm mb-0">
              <thead style="position:sticky;top:0;background:#fff">
                <tr><th>#</th><th>Vence</th><th class="cm-num">Capital</th>
                    <th class="cm-num">Interés</th><th class="cm-num">Cuota</th>
                    <th class="cm-num">Saldo</th></tr>
              </thead>
              <tbody>
              <?php foreach ($simulacion['tabla'] as $f): ?>
                <tr>
                  <td><?= (int) $f['numero'] ?></td>
                  <td class="text-muted"><?= P::e(P::fecha((string) $f['vencimiento'], 'd/m/Y')) ?></td>
                  <td class="cm-num"><?= P::e(P::money((int) $f['capital'])) ?></td>
                  <td class="cm-num text-muted"><?= P::e(P::money((int) $f['interes'])) ?></td>
                  <td class="cm-num font-weight-bold"><?= P::e(P::money((int) $f['cuota'])) ?></td>
                  <td class="cm-num text-muted"><?= P::e(P::money((int) $f['saldo'])) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Panel de revisión -->
  <div class="col-lg-5">
    <?php if ($solicitud['puede_revisar']): ?>
    <div class="card border-primary">
      <div class="card-header"><h4 class="card-title mb-0">Tu revisión</h4></div>
      <div class="card-body">
        <p class="text-muted" style="font-size:.88rem">
          Etapa actual: <strong><?= P::e($solicitud['etapa_nombre']) ?></strong>.
          Al aprobar, la solicitud pasa a la siguiente etapa.
        </p>

        <form data-cm-ajax data-cm-recargar method="post" action="<?= P::attr($ruta . '/avanzar') ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="solicitud">
          <div class="form-group">
            <label class="small">Observación (opcional)</label>
            <textarea class="form-control form-control-sm" name="observacion" rows="2"
                      maxlength="500" placeholder="Notas de la revisión..."></textarea>
          </div>
          <button type="submit" class="btn btn-success btn-block"
                  data-cm-confirmar="La solicitud avanzará a la siguiente etapa.">
            Aprobar y continuar
          </button>
        </form>

        <hr>

        <form data-cm-ajax data-cm-recargar method="post" action="<?= P::attr($ruta . '/rechazar') ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="solicitud">
          <div class="form-group">
            <label class="small">Motivo del rechazo</label>
            <textarea class="form-control form-control-sm" name="motivo" rows="2"
                      maxlength="500" required placeholder="Explica por qué se rechaza..."></textarea>
          </div>
          <button type="submit" class="btn btn-outline-danger btn-block"
                  data-cm-confirmar="El rechazo es definitivo y se notifica al cliente.">
            Rechazar solicitud
          </button>
        </form>
      </div>
    </div>
    <?php elseif (!$final): ?>
    <div class="alert alert-info">
      Esta solicitud está en <strong><?= P::e($solicitud['etapa_nombre']) ?></strong>.
      No te corresponde actuar en esta etapa.
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Trazabilidad</h4></div>
      <div class="card-body p-0">
        <?php foreach ([
          'Atención a clientes' => $solicitud['rev_atencion'],
          'Gerencia'            => $solicitud['rev_gerencia'],
          'Presidencia'         => $solicitud['rev_presidencia'],
        ] as $etapa => $quien): ?>
        <div class="d-flex justify-content-between px-3 py-2 border-bottom">
          <small class="text-muted"><?= P::e($etapa) ?></small>
          <small class="<?= $quien ? 'font-weight-bold' : 'text-muted' ?>">
            <?= P::e($quien ?: 'pendiente') ?>
          </small>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (!empty($solicitud['credito_id'])): ?>
    <div class="alert alert-success">
      <strong>Crédito generado.</strong>
      <a href="<?= P::attr($rutas->url('creditos')) ?>">Ver en la cartera →</a>
    </div>
    <?php endif; ?>

    <a href="<?= P::attr($rutas->url('solicitudes/' . $solicitud['codigo'] . '/referencias')) ?>"
       class="btn btn-outline-primary btn-block mb-2">
      Referencias de la solicitud
    </a>

    <a href="<?= P::attr($rutas->url('solicitudes')) ?>" class="btn btn-outline-secondary btn-block">
      Volver a la lista
    </a>
  </div>
</div>
