<?php
/**
 * Procesos periódicos.
 *
 * Lo que se muestra arriba es una simulación: el sistema calcula qué
 * haría hoy sin escribir nada. Solo al pulsar el botón se aplica.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array|null $mora
 * @var array|null $corte
 * @var bool  $puedeMora
 * @var bool  $puedeCorte
 * @var array $parametros
 * @var array $ultimas
 * @var int   $cargoDiario
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;
?>

<div class="alert alert-light border" style="font-size:.86rem">
  <strong>Cómo funciona esto.</strong> Estos dos procesos están pensados para
  correr solos una vez al día desde el servidor. Lo que ve aquí es una
  <em>simulación</em>: el cálculo de lo que harían hoy, sin tocar nada. Úselo
  para revisar antes de aplicar, o para lanzarlos a mano el día que la tarea
  programada no se haya ejecutado.
</div>

<div class="row">

  <!-- ---------------- Mora ---------------- -->
  <?php if ($puedeMora): ?>
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title mb-0">Recargo por mora</h4>
      </div>
      <div class="card-body">

        <?php if ($mora === null || $mora['cuotas'] === 0): ?>
          <div class="cm-vacio">
            <?= cm_icono('check', 36) ?>
            <p class="mb-0 mt-2">No hay mora pendiente de devengar hoy.</p>
          </div>
        <?php else: ?>
          <div class="row text-center mb-3">
            <div class="col-4">
              <h3 class="mb-0 text-primary"><?= (int) $mora['cuotas'] ?></h3>
              <small class="text-muted">cuotas</small>
            </div>
            <div class="col-4">
              <h3 class="mb-0"><?= (int) $mora['dias'] ?></h3>
              <small class="text-muted">días</small>
            </div>
            <div class="col-4">
              <h3 class="mb-0 cm-neg"><?= P::e(P::money((int) $mora['cargo_cent'])) ?></h3>
              <small class="text-muted">se añadiría</small>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th>Crédito</th>
                  <th class="text-center">Cuota</th>
                  <th class="text-center">Días</th>
                  <th class="text-right">Se añade</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_slice($mora['detalle'], 0, 25) as $d): ?>
                <tr>
                  <td class="cm-mono cm-nowrap" style="font-size:.78rem"><?= P::e($d['folio']) ?></td>
                  <td class="text-center"><?= (int) $d['cuota'] ?></td>
                  <td class="text-center"><?= (int) $d['nuevos'] ?></td>
                  <td class="text-right cm-mono cm-nowrap"><?= P::e(P::money((int) $d['incremento'])) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if (count($mora['detalle']) > 25): ?>
          <small class="text-muted d-block mt-2">
            Y <?= count($mora['detalle']) - 25 ?> cuota(s) más.
          </small>
          <?php endif; ?>
        <?php endif; ?>

        <form method="post" data-cm-ajax class="mt-3"
              action="<?= P::attr($rutas->url('procesos/mora')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="procesos">
          <button class="btn btn-primary"
                  data-cm-confirmar="Se aplicará el recargo a <?= (int) ($mora['cuotas'] ?? 0) ?> cuota(s). ¿Desea continuar?">
            Aplicar el devengo de hoy
          </button>
        </form>

        <small class="text-muted d-block mt-2">
          Recargo vigente: <strong><?= P::e(P::money($cargoDiario)) ?></strong> por día y por cuota.
          Repetirlo el mismo día no duplica nada.
        </small>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ---------------- Cortes ---------------- -->
  <?php if ($puedeCorte): ?>
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title mb-0">Corte de tarjetas</h4>
      </div>
      <div class="card-body">

        <?php if ($corte === null || $corte['cortes'] === 0): ?>
          <div class="cm-vacio">
            <?= cm_icono('check', 36) ?>
            <p class="mb-0 mt-2">Ninguna tarjeta tiene corte pendiente.</p>
          </div>
        <?php else: ?>
          <div class="row text-center mb-3">
            <div class="col-4">
              <h3 class="mb-0 text-primary"><?= (int) $corte['cortes'] ?></h3>
              <small class="text-muted">cortes</small>
            </div>
            <div class="col-4">
              <h3 class="mb-0"><?= P::e(P::money((int) $corte['intereses_cent'])) ?></h3>
              <small class="text-muted">intereses</small>
            </div>
            <div class="col-4">
              <h3 class="mb-0"><?= P::e(P::money((int) $corte['seguros_cent'])) ?></h3>
              <small class="text-muted">seguros</small>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th>Tarjeta</th>
                  <th>Corte</th>
                  <th class="text-right">Saldo</th>
                  <th class="text-right">Mínimo</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($corte['detalle'] as $d): ?>
                <tr>
                  <td class="cm-nowrap" style="font-size:.78rem"><?= P::e($d['tarjeta']) ?></td>
                  <td class="cm-nowrap" style="font-size:.78rem">
                    <?= P::e(P::fecha((string) $d['fecha_corte'], 'd/m/Y')) ?>
                  </td>
                  <td class="text-right cm-mono cm-nowrap"><?= P::e(P::money((int) $d['saldo_final_cent'])) ?></td>
                  <td class="text-right cm-mono cm-nowrap"><?= P::e(P::money((int) $d['pago_minimo_cent'])) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <form method="post" data-cm-ajax class="mt-3"
              action="<?= P::attr($rutas->url('procesos/cortes')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="procesos">
          <button class="btn btn-primary"
                  data-cm-confirmar="Se cerrará el periodo de <?= (int) ($corte['cortes'] ?? 0) ?> tarjeta(s) y se avisará a cada cliente. ¿Desea continuar?">
            Generar los cortes de hoy
          </button>
        </form>

        <small class="text-muted d-block mt-2">
          Al cerrar un periodo se calcula el pago mínimo, se aplican los seguros
          y se le avisa al cliente.
        </small>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ---------------- Parámetros ---------------- -->

<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">Parámetros</h4>
  </div>
  <div class="card-body">
    <p class="text-muted" style="font-size:.86rem">
      Son los números con los que trabajan los procesos de arriba. Cambiarlos
      aquí tiene efecto inmediato y queda registrado en la bitácora.
    </p>

    <div class="row">
      <?php foreach ($parametros as $p): ?>
      <div class="col-lg-6">
        <form method="post" data-cm-ajax class="form-group"
              action="<?= P::attr($rutas->url('procesos/parametro')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="procesos">
          <input type="hidden" name="clave" value="<?= P::attr($p['clave']) ?>">

          <label for="p-<?= P::attr($p['clave']) ?>" class="small mb-1">
            <?= P::e($p['etiqueta']) ?>
          </label>

          <div class="input-group">
            <input type="<?= $p['esFecha'] ? 'date' : 'number' ?>"
                   class="form-control form-control-sm"
                   id="p-<?= P::attr($p['clave']) ?>"
                   name="valor" value="<?= P::attr($p['valor']) ?>">
            <div class="input-group-append">
              <button class="btn btn-sm btn-outline-primary">Guardar</button>
            </div>
          </div>

          <small class="form-text text-muted" style="font-size:.74rem">
            <?= P::e($p['ayuda']) ?>
          </small>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ---------------- Historial ---------------- -->

<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">Últimas ejecuciones</h4>
  </div>
  <div class="card-body pt-0">
    <?php if ($ultimas === []): ?>
      <p class="text-muted mb-0 mt-2" style="font-size:.88rem">
        Todavía no se ha ejecutado ninguno de estos procesos.
      </p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Cuándo</th>
            <th>Proceso</th>
            <th>Resultado</th>
            <th>Detalle</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ultimas as $u):
            $d = $u['detalle_json'] !== null
                 ? (json_decode((string) $u['detalle_json'], true) ?: [])
                 : [];
          ?>
          <tr>
            <td class="cm-nowrap" style="font-size:.8rem">
              <?= P::e(P::fecha((string) $u['creado_en'], 'd/m/Y H:i')) ?>
            </td>
            <td style="font-size:.82rem">
              <?= P::e(match ((string) $u['accion']) {
                  'mora.devengada'        => 'Devengo de mora',
                  'tarjeta.corte_mensual' => 'Corte de tarjetas',
                  'parametro.modificado'  => 'Cambio de parámetro',
                  default                 => (string) $u['accion'],
              }) ?>
            </td>
            <td>
              <span class="badge badge-<?= $u['resultado'] === 'exito' ? 'success' : 'danger' ?>">
                <?= P::e($u['resultado']) ?>
              </span>
            </td>
            <td style="font-size:.78rem" class="text-muted">
              <?php
              $partes = [];
              foreach ($d as $k => $v) {
                  if (is_scalar($v)) { $partes[] = $k . ': ' . $v; }
              }
              echo P::e(implode(' · ', array_slice($partes, 0, 4)));
              ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
