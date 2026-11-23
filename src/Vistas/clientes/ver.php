<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $cliente
 * @var array $cuentas
 * @var array $tarjetas
 * @var array $creditos
 * @var array $accesos
 * @var array $riesgo
 * @var bool $puedeEditar
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$ruta = $rutas->url('clientes/' . $cliente['codigo']);
?>
<div class="row">
  <div class="col-lg-4">
    <!-- Ficha -->
    <div class="card">
      <div class="card-body text-center">
        <div class="cm-avatar mx-auto mb-3" style="width:72px;height:72px;font-size:1.5rem">
          <?= P::e(mb_strtoupper(mb_substr((string) $cliente['nombres'], 0, 1)
                 . mb_substr((string) $cliente['apellidos'], 0, 1))) ?>
        </div>
        <h5 class="mb-1"><?= P::e(trim($cliente['nombres'] . ' ' . $cliente['apellidos'])) ?></h5>
        <p class="text-muted mb-2" style="font-size:.86rem"><?= P::e($cliente['correo']) ?></p>
        <span class="badge badge-<?= $cliente['estado'] === 'activo' ? 'success' : 'secondary' ?>">
          <?= P::e(ucfirst((string) $cliente['estado'])) ?>
        </span>
        <span class="badge badge-<?= (int) $cliente['perfil_completo'] === 1 ? 'info' : 'warning' ?>">
          Expediente <?= (int) $cliente['perfil_completo'] === 1 ? 'completo' : 'incompleto' ?>
        </span>
      </div>
      <div class="card-body border-top pt-3">
        <?php foreach ([
          'Usuario'        => $cliente['usuario'],
          'DUI'            => $cliente['dui'] ?: '—',
          'NIT'            => $cliente['nit'] ?: '—',
          'Teléfono'       => $cliente['telefono'] ?: '—',
          'Nacimiento'     => $cliente['fecha_nacimiento'] ? P::fecha((string) $cliente['fecha_nacimiento'], 'd/m/Y') : '—',
          'Ingreso mensual'=> P::money((int) ($cliente['ingreso_mensual_cent'] ?? 0)),
          'Empresa'        => $cliente['empresa'] ?: '—',
          'Cargo'          => $cliente['cargo'] ?: '—',
          'Cliente desde'  => P::fecha((string) $cliente['registrado_en'], 'd/m/Y'),
          'Último acceso'  => $cliente['ultimo_acceso_en'] ? P::hace((string) $cliente['ultimo_acceso_en']) : 'nunca',
        ] as $et => $val): ?>
        <div class="d-flex justify-content-between py-1 border-bottom">
          <small class="text-muted"><?= P::e($et) ?></small>
          <small class="font-weight-bold text-right"><?= P::e((string) $val) ?></small>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Resumen financiero -->
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Resumen financiero</h4></div>
      <div class="card-body">
        <div class="d-flex justify-content-between py-1">
          <small class="text-muted">Saldo en cuentas</small>
          <strong class="cm-pos"><?= P::e(P::money((int) $cliente['saldo_total_cent'])) ?></strong>
        </div>
        <div class="d-flex justify-content-between py-1">
          <small class="text-muted">Deuda en créditos</small>
          <strong class="cm-neg"><?= P::e(P::money((int) $cliente['deuda_cent'])) ?></strong>
        </div>
        <div class="d-flex justify-content-between py-1 border-top mt-1 pt-2">
          <small class="text-muted">Posición neta</small>
          <strong><?= P::e(P::money((int) $cliente['saldo_total_cent'] - (int) $cliente['deuda_cent'])) ?></strong>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-body pb-0">
        <ul class="nav nav-tabs">
          <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#t-prod">Productos</a></li>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#t-riesgo">
            Riesgo
            <span class="cm-riesgo cm-riesgo--<?= P::attr((string) $riesgo['banda']) ?> ml-1">
              <?= P::e((string) $riesgo['banda']) ?>
            </span>
          </a></li>
          <?php if ($puedeEditar): ?>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#t-exp">Editar expediente</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#t-acc">Accesos</a></li>
        </ul>
      </div>

      <div class="tab-content">
        <!-- Productos -->
        <div class="tab-pane fade show active" id="t-prod">
          <div class="card-body">
            <h6 class="text-muted" style="font-size:.78rem;letter-spacing:.05em">CUENTAS</h6>
            <?php if ($cuentas === []): ?>
              <p class="text-muted" style="font-size:.88rem">Sin cuentas.</p>
            <?php else: ?>
            <div class="table-responsive mb-3">
              <table class="table table-sm mb-0">
                <thead><tr><th>Cuenta</th><th>Producto</th><th>Estado</th><th class="cm-num">Saldo</th></tr></thead>
                <tbody>
                <?php foreach ($cuentas as $c): ?>
                  <tr>
                    <td><a href="<?= P::attr($rutas->url('cuentas/' . $c['codigo'])) ?>" class="cm-mono">
                      <?= P::e($c['numero_cuenta']) ?></a></td>
                    <td class="text-muted"><?= P::e($c['producto']) ?></td>
                    <td><span class="badge badge-<?= $c['estado'] === 'activa' ? 'success' : 'warning' ?>">
                      <?= P::e($c['estado']) ?></span></td>
                    <td class="cm-num font-weight-bold"><?= P::e(P::money((int) $c['saldo_cent'])) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>

            <h6 class="text-muted" style="font-size:.78rem;letter-spacing:.05em">TARJETAS</h6>
            <?php if ($tarjetas === []): ?>
              <p class="text-muted" style="font-size:.88rem">Sin tarjetas.</p>
            <?php else: ?>
            <div class="table-responsive mb-3">
              <table class="table table-sm mb-0">
                <thead><tr><th>Tarjeta</th><th>Marca</th><th>Tipo</th><th>Estado</th>
                  <th class="cm-num">Límite</th><th class="cm-num">Usado</th></tr></thead>
                <tbody>
                <?php foreach ($tarjetas as $t): ?>
                  <tr>
                    <td><a href="<?= P::attr($rutas->url('tarjetas/' . $t['codigo'])) ?>" class="cm-mono">
                      &bull;&bull;&bull;&bull; <?= P::e($t['ultimos4']) ?></a></td>
                    <td><?= P::e(ucfirst((string) $t['marca'])) ?></td>
                    <td><?= P::e(ucfirst((string) $t['tipo'])) ?></td>
                    <td><span class="badge badge-<?= $t['estado'] === 'activa' ? 'success' : 'warning' ?>">
                      <?= P::e($t['estado']) ?></span></td>
                    <td class="cm-num"><?= P::e(P::money((int) $t['limite_cent'])) ?></td>
                    <td class="cm-num cm-neg"><?= P::e(P::money((int) $t['saldo_usado_cent'])) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>

            <h6 class="text-muted" style="font-size:.78rem;letter-spacing:.05em">CRÉDITOS</h6>
            <?php if ($creditos === []): ?>
              <p class="text-muted mb-0" style="font-size:.88rem">Sin créditos.</p>
            <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm mb-0">
                <thead><tr><th>Folio</th><th>Producto</th><th>Avance</th><th>Clase</th>
                  <th class="cm-num">Monto</th><th class="cm-num">Saldo</th><th>Estado</th></tr></thead>
                <tbody>
                <?php foreach ($creditos as $cr): ?>
                  <tr>
                    <td><a href="<?= P::attr($rutas->url('creditos/' . $cr['codigo'])) ?>" class="cm-mono">
                      <?= P::e($cr['folio'] ?: '—') ?></a></td>
                    <td class="text-muted"><?= P::e($cr['producto']) ?></td>
                    <td class="text-muted"><?= (int) $cr['cuotas_pagadas'] ?>/<?= (int) $cr['plazo_meses'] ?></td>
                    <td><span class="cm-riesgo cm-riesgo--<?= P::attr((string) $cr['clasificacion']) ?>">
                      <?= P::e((string) $cr['clasificacion']) ?></span></td>
                    <td class="cm-num"><?= P::e(P::money((int) $cr['monto_cent'])) ?></td>
                    <td class="cm-num cm-neg"><?= P::e(P::money((int) $cr['saldo_capital_cent'])) ?></td>
                    <td><span class="badge badge-<?= $cr['estado'] === 'vigente' ? 'success' : 'secondary' ?>">
                      <?= P::e($cr['estado']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- ---------------- RIESGO ---------------- -->
        <?php /* La clasificación, con el detalle de cómo se llegó a ella.
                 Una banda sola no sirve para atender a nadie: quien
                 tiene al cliente delante necesita poder decirle qué
                 pesó en contra y qué tendría que cambiar. */ ?>
        <div class="tab-pane fade" id="t-riesgo">
          <div class="card-body">

            <div class="cm-riesgo-cab">
              <div class="cm-riesgo-cab__banda cm-riesgo--<?= P::attr((string) $riesgo['banda']) ?>">
                <?= P::e((string) $riesgo['banda']) ?>
              </div>
              <div style="min-width:0">
                <div class="cm-riesgo-cab__puntaje">
                  <?= (int) $riesgo['puntaje'] ?><small> / 1000</small>
                </div>
                <div class="text-muted" style="font-size:.84rem">
                  <?= P::e((string) $riesgo['etiqueta']) ?>
                  &middot; modelo v<?= P::e((string) $riesgo['version']) ?>
                </div>
              </div>
              <div class="ml-auto text-right">
                <span class="badge badge-<?= !empty($riesgo['aprueba']) ? 'success' : 'danger' ?>">
                  <?= !empty($riesgo['aprueba']) ? 'Admite crédito' : 'No admite crédito' ?>
                </span>
              </div>
            </div>

            <p class="text-muted mt-3" style="font-size:.88rem;line-height:1.6">
              <?= P::e((string) $riesgo['narrativa']) ?>
            </p>

            <?php if (!empty($riesgo['vetos'])): ?>
            <div class="alert alert-danger py-2" style="font-size:.84rem">
              <strong>Impedimentos que no se compensan con puntaje</strong>
              <ul class="mb-0 mt-1" style="padding-left:1.1rem">
                <?php foreach ($riesgo['vetos'] as $v): ?>
                <li><?= P::e((string) $v) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
            <?php endif; ?>

            <h6 class="text-muted mt-4 mb-3" style="font-size:.78rem;letter-spacing:.05em">
              CÓMO SE COMPONE
            </h6>

            <?php foreach ($riesgo['razones'] as $r):
              $cuenta = ($r['aplica'] ?? true) !== false;
              $pct    = $cuenta && (int) $r['tope'] > 0
                  ? max(0, min(100, 100 * (int) $r['puntos'] / (int) $r['tope'])) : 0;
              $tono   = $pct >= 70 ? 'bg-success' : ($pct >= 40 ? 'bg-warning' : 'bg-danger');
            ?>
            <div class="cm-riesgo-factor<?= $cuenta ? '' : ' cm-riesgo-factor--fuera' ?>">
              <div class="cm-riesgo-factor__cab">
                <span class="cm-mono cm-riesgo-factor__codigo"><?= P::e((string) $r['codigo']) ?></span>
                <strong>
                  <?= $cuenta
                      ? (int) $r['puntos'] . ' / ' . (int) $r['tope'] . ' pts'
                      : 'no cuenta' ?>
                </strong>
              </div>
              <div class="progress" style="height:5px">
                <div class="progress-bar <?= $tono ?>" style="width:<?= number_format($pct, 1) ?>%"></div>
              </div>
              <small class="text-muted"><?= P::e((string) $r['texto']) ?></small>
            </div>
            <?php endforeach; ?>

            <?php $c = $riesgo['condiciones']; ?>
            <?php if (!empty($riesgo['aprueba'])
                      && ($c['monto_factor'] < 1 || $c['recargo_bp'] > 0
                          || $c['exige_garantia'] || $c['requiere_comite'])): ?>
            <div class="alert alert-warning py-2 mt-3" style="font-size:.84rem">
              <strong>Condiciones que aplican a este perfil</strong>
              <ul class="mb-0 mt-1" style="padding-left:1.1rem">
                <?php if ($c['monto_factor'] < 1): ?>
                <li>Se otorga hasta el <?= number_format($c['monto_factor'] * 100, 0) ?>%
                    del monto solicitado.</li>
                <?php endif; ?>
                <?php if ($c['plazo_factor'] < 1): ?>
                <li>El plazo se recorta al <?= number_format($c['plazo_factor'] * 100, 0) ?>%
                    del pedido.</li>
                <?php endif; ?>
                <?php if ($c['recargo_bp'] > 0): ?>
                <li>Recargo de <?= number_format($c['recargo_bp'] / 100, 2) ?> puntos sobre
                    la tasa del producto, dentro del rango autorizado.</li>
                <?php endif; ?>
                <?php if ($c['exige_garantia']): ?><li>Exige garantía o codeudor.</li><?php endif; ?>
                <?php if ($c['requiere_comite']): ?><li>Requiere aprobación de comité.</li><?php endif; ?>
              </ul>
            </div>
            <?php endif; ?>

            <small class="text-muted d-block mt-3" style="font-size:.76rem;line-height:1.55">
              La clasificación se calcula con una regla escrita y versionada: el mismo
              expediente da siempre el mismo resultado, y cada factor deja su código de
              razón. Cada decisión que se toma con ella queda guardada con los datos de
              ese momento, de modo que puede reconstruirse después.
            </small>
          </div>
        </div>

        <!-- Editar expediente -->
        <?php if ($puedeEditar): ?>
        <div class="tab-pane fade" id="t-exp">
          <div class="card-body">
            <form data-cm-ajax method="post" action="<?= P::attr($ruta) ?>">
              <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
              <input type="hidden" name="_csrf_proposito" value="cliente">

              <div class="form-row">
                <div class="form-group col-md-4">
                  <label class="small">DUI</label>
                  <input type="text" class="form-control form-control-sm" name="dui" maxlength="12"
                         value="<?= P::attr($cliente['dui'] ?? '') ?>" placeholder="9 dígitos">
                </div>
                <div class="form-group col-md-4">
                  <label class="small">NIT</label>
                  <input type="text" class="form-control form-control-sm" name="nit" maxlength="20"
                         value="<?= P::attr($cliente['nit'] ?? '') ?>" placeholder="14 dígitos">
                </div>
                <div class="form-group col-md-4">
                  <label class="small">Fecha de nacimiento</label>
                  <input type="date" class="form-control form-control-sm" name="fecha_nacimiento"
                         value="<?= P::attr($cliente['fecha_nacimiento'] ?? '') ?>">
                </div>
              </div>

              <div class="form-row">
                <div class="form-group col-md-4">
                  <label class="small">Teléfono</label>
                  <input type="text" class="form-control form-control-sm" name="telefono"
                         value="<?= P::attr($cliente['telefono'] ?? '') ?>">
                </div>
                <div class="form-group col-md-4">
                  <label class="small">Celular</label>
                  <input type="text" class="form-control form-control-sm" name="celular"
                         value="<?= P::attr($cliente['celular'] ?? '') ?>">
                </div>
                <div class="form-group col-md-4">
                  <label class="small">Ingreso mensual</label>
                  <input type="text" class="form-control form-control-sm" name="ingreso" inputmode="decimal"
                         value="<?= P::attr(number_format((int) ($cliente['ingreso_mensual_cent'] ?? 0) / 100, 2, '.', '')) ?>">
                </div>
              </div>

              <div class="form-group">
                <label class="small">Dirección</label>
                <input type="text" class="form-control form-control-sm" name="direccion" maxlength="255"
                       value="<?= P::attr($cliente['direccion'] ?? '') ?>">
              </div>

              <div class="form-row">
                <div class="form-group col-md-6">
                  <label class="small">Empresa</label>
                  <input type="text" class="form-control form-control-sm" name="empresa" maxlength="255"
                         value="<?= P::attr($cliente['empresa'] ?? '') ?>">
                </div>
                <div class="form-group col-md-6">
                  <label class="small">Cargo</label>
                  <input type="text" class="form-control form-control-sm" name="cargo" maxlength="255"
                         value="<?= P::attr($cliente['cargo'] ?? '') ?>">
                </div>
              </div>

              <div class="form-row">
                <div class="form-group col-md-6">
                  <label class="small">Género</label>
                  <select class="form-control form-control-sm" name="genero">
                    <option value="">—</option>
                    <?php foreach (['M'=>'Masculino','F'=>'Femenino','O'=>'Otro'] as $k=>$v): ?>
                    <option value="<?= $k ?>"<?= ($cliente['genero'] ?? '') === $k ? ' selected' : '' ?>>
                      <?= P::e($v) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group col-md-6">
                  <label class="small">Estado civil</label>
                  <select class="form-control form-control-sm" name="estado_civil">
                    <option value="">—</option>
                    <?php foreach (['Soltero/a','Casado/a','Divorciado/a','Viudo/a','Unión libre'] as $v): ?>
                    <option<?= ($cliente['estado_civil'] ?? '') === $v ? ' selected' : '' ?>><?= P::e($v) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <button type="submit" class="btn btn-primary btn-sm">Guardar expediente</button>
            </form>
          </div>
        </div>
        <?php endif; ?>

        <!-- Accesos -->
        <div class="tab-pane fade" id="t-acc">
          <?php if ($accesos === []): ?>
            <div class="cm-vacio"><p class="mb-0">Sin accesos registrados.</p></div>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead><tr><th>Fecha</th><th>IP</th><th>Sistema</th><th>Dispositivo</th><th>Resultado</th></tr></thead>
              <tbody>
              <?php foreach ($accesos as $a): ?>
                <tr>
                  <td class="text-muted"><?= P::e(P::fecha((string) $a['creado_en'])) ?></td>
                  <td class="cm-mono"><?= P::e($a['ip_txt']) ?></td>
                  <td class="text-muted"><?= P::e($a['sistema'] ?: '—') ?></td>
                  <td class="text-muted"><?= P::e($a['dispositivo'] ?: '—') ?></td>
                  <td><span class="badge badge-<?= (int) $a['exitoso'] === 1 ? 'success' : 'danger' ?>">
                    <?= (int) $a['exitoso'] === 1 ? 'exitoso' : 'fallido' ?></span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <a href="<?= P::attr($rutas->url('clientes')) ?>" class="btn btn-outline-secondary">Volver a clientes</a>
  </div>
</div>
