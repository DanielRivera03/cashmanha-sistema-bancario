<?php
/**
 * Panel de inicio — con bienvenida y clima, como el sistema original.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $cuentas
 * @var array $tarjetas
 * @var array $ultimos
 * @var array|null $clima
 */
use CashMan\Http\Plantilla as P;
use CashMan\Infraestructura\Notificacion\ServicioClima;

$Pl = $rutas->url('assets/plantilla/');
$permisos = $usuario['permisos'] ?? [];
$tiene = static fn(string $p): bool => in_array($p, $permisos, true);

$saldoTotal = 0;
foreach ($cuentas as $c) { $saldoTotal += (int) $c['saldo_cent']; }

$deudaTarjetas = 0; $limiteTotal = 0;
foreach ($tarjetas as $t) {
    $deudaTarjetas += (int) $t['saldo_usado_cent'];
    $limiteTotal   += (int) $t['limite_cent'];
}

$ind = $indicadores ?? null;
$mor = $morosos_resumen ?? null;
?>

<!-- ============ BIENVENIDA ============ -->
<div class="cm-bienvenida">
  <div class="cm-bienvenida__cont row align-items-center">
    <div class="col-lg-7 col-md-12">
      <h2><?= P::e(ServicioClima::saludo()) ?>, <?= P::e(explode(' ', (string) ($usuario['nombres'] ?? ''))[0]) ?></h2>
      <p><?= P::e(ucfirst(ServicioClima::fechaLarga())) ?></p>
      <p class="mt-2" style="font-size:.88rem">
        <?php if (!empty($usuario['ultimo_acceso_en'])): ?>
          Tu último acceso fue <?= P::e(P::hace((string) $usuario['ultimo_acceso_en'])) ?>.
        <?php else: ?>
          Bienvenido por primera vez a su banca en línea.
        <?php endif; ?>
      </p>
    </div>

    <?php if (!empty($clima)): ?>
    <div class="col-lg-5 col-md-12 mt-3 mt-lg-0 text-lg-right">
      <div class="cm-bienvenida__clima">
        <img src="<?= P::attr($Pl) ?>images/icon-weather/<?= P::attr($clima['icono']) ?>.svg"
             alt="<?= P::attr($clima['descripcion']) ?>" onerror="this.style.display='none'">
        <div class="text-left">
          <div class="cm-bienvenida__temp"><?= (int) $clima['temperatura'] ?>&deg;C</div>
          <div class="cm-bienvenida__det">
            <?= P::e($clima['descripcion']) ?><br>
            <?= P::e($clima['ciudad']) ?> &middot; sensación <?= (int) $clima['sensacion'] ?>&deg;
          </div>
        </div>
      </div>
      <div class="mt-2" style="font-size:.72rem;color:#cdb4f0">
        <?php if ($clima['amanecer']): ?>
          Amanecer <?= P::e($clima['amanecer']) ?> &middot;
          Atardecer <?= P::e($clima['atardecer']) ?> &middot;
        <?php endif; ?>
        Humedad <?= (int) $clima['humedad'] ?>% &middot; Viento <?= P::e((string) $clima['viento']) ?> km/h
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============ INDICADORES ============ -->
<div class="row">
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi d-flex align-items-center">
      <div class="cm-kpi__icono mr-3" style="background:#f0e6fb;color:#6418c3">
        <?= cm_icono("wallet", 22) ?>
      </div>
      <div>
        <div class="cm-kpi__et">Saldo disponible</div>
        <div class="cm-kpi__val" data-vivo="saldo-total"><?= P::e(P::money($saldoTotal)) ?></div>
        <div class="cm-kpi__pie"><?= count($cuentas) ?> cuenta<?= count($cuentas) === 1 ? '' : 's' ?></div>
      </div>
    </div>
  </div>

  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi d-flex align-items-center">
      <div class="cm-kpi__icono mr-3" style="background:#e0eaff;color:#175cd3">
        <?= cm_icono("card", 22) ?>
      </div>
      <div>
        <div class="cm-kpi__et">Tarjetas</div>
        <div class="cm-kpi__val"><?= count($tarjetas) ?></div>
        <div class="cm-kpi__pie">
          <?= $limiteTotal > 0
                ? P::e(P::money($deudaTarjetas)) . ' de ' . P::e(P::money($limiteTotal))
                : 'Sin tarjetas' ?>
        </div>
      </div>
    </div>
  </div>

  <?php if ($ind !== null): ?>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi d-flex align-items-center">
      <div class="cm-kpi__icono mr-3" style="background:#fef0c7;color:#b45309">
        <?= cm_icono("users", 22) ?>
      </div>
      <div>
        <div class="cm-kpi__et">Clientes activos</div>
        <div class="cm-kpi__val"><?= number_format((int) $ind['clientes_activos']) ?></div>
        <div class="cm-kpi__pie"><?= number_format((int) $ind['cuentas_activas']) ?> cuentas</div>
      </div>
    </div>
  </div>

  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi d-flex align-items-center">
      <div class="cm-kpi__icono mr-3" style="background:#d1fadf;color:#12b76a">
        <?= cm_icono("chart", 22) ?>
      </div>
      <div>
        <div class="cm-kpi__et">Captaciones</div>
        <div class="cm-kpi__val"><?= P::e(P::money((int) $ind['captaciones_cent'])) ?></div>
        <div class="cm-kpi__pie"><?= number_format((int) $ind['operaciones_hoy']) ?> operaciones hoy</div>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi d-flex align-items-center">
      <div class="cm-kpi__icono mr-3" style="background:#fef0c7;color:#b45309">
        <?= cm_icono("invest", 22) ?>
      </div>
      <div>
        <div class="cm-kpi__et">Créditos</div>
        <div class="cm-kpi__val"><?= (int) ($creditos_activos ?? 0) ?></div>
        <div class="cm-kpi__pie">
          <?= ($deuda_creditos ?? 0) > 0 ? P::e(P::money((int) $deuda_creditos)) . ' pendiente' : 'Sin créditos' ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi d-flex align-items-center">
      <div class="cm-kpi__icono mr-3" style="background:#d1fadf;color:#12b76a">
        <?= cm_icono("check", 22) ?>
      </div>
      <div>
        <div class="cm-kpi__et">Estado</div>
        <div class="cm-kpi__val" style="font-size:1.1rem;padding-top:.3rem">
          <?php if (($cuotas_vencidas ?? 0) > 0): ?>
            <span class="badge badge-warning"><?= (int) $cuotas_vencidas ?> cuota(s) vencida(s)</span>
          <?php else: ?>
            <span class="badge badge-success">Al día</span>
          <?php endif; ?>
        </div>
        <div class="cm-kpi__pie">Tu situación crediticia</div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($mor !== null && (int) $mor['clientes'] > 0): ?>
<div class="alert alert-warning d-flex align-items-center" role="alert">
  <?= cm_icono("warning", 22, "mr-3") ?>
  <div class="flex-grow-1">
    <strong><?= number_format((int) $mor['clientes']) ?> clientes en mora</strong> —
    <?= P::e(P::money((int) $mor['deuda_cent'])) ?> vencidos,
    hasta <?= (int) $mor['peor_atraso'] ?> días de atraso.
  </div>
  <a href="<?= P::attr($rutas->url('cartera')) ?>" class="btn btn-sm btn-warning">Ver cartera</a>
</div>
<?php endif; ?>

<!-- ============ CUENTAS ============ -->
<?php if ($cuentas !== []): ?>
<h4 class="mb-3 mt-2">Mis cuentas</h4>
<div class="row">
  <?php foreach ($cuentas as $c): ?>
  <div class="col-xl-4 col-lg-6 col-md-6 mb-4">
    <a href="<?= P::attr($rutas->url('cuentas/' . $c['codigo'])) ?>" class="cm-cuenta">
      <div class="cm-cuenta__tipo"><?= P::e($c['producto']) ?></div>
      <div class="cm-cuenta__num"><?= P::e(chunk_split((string) $c['numero_cuenta'], 4, ' ')) ?></div>
      <div class="cm-cuenta__saldo" data-vivo="saldo" data-cuenta="<?= P::attr($c['codigo']) ?>">
        <?= P::e(P::money((int) $c['saldo_cent'])) ?>
      </div>
      <div class="cm-cuenta__pie">
        <span style="font-size:.74rem;color:#cdb4f0"><?= P::e($c['moneda']) ?></span>
        <span class="badge badge-<?= $c['estado'] === 'activa' ? 'success' : 'warning' ?>">
          <?= P::e(ucfirst((string) $c['estado'])) ?>
        </span>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ============ TARJETAS ============ -->
<?php if ($tarjetas !== []): ?>
<h4 class="mb-3 mt-2">Mis tarjetas</h4>
<div class="row">
  <?php $logosTarjeta = $rutas->url('assets/plantilla/images'); ?>
  <?php foreach ($tarjetas as $t): ?>
  <div class="col-xl-4 col-lg-6 col-md-6 mb-4">
    <a href="<?= P::attr($rutas->url('tarjetas/' . $t['codigo'])) ?>"
       style="text-decoration:none;display:block">
      <?= P::crudo(cm_plastico($t, ['assets' => $logosTarjeta])) ?>
    </a>
    <?php /* El cupo va DEBAJO, no dentro: el plástico es el plástico, y
             una tarjeta real no lleva su saldo impreso. */ ?>
    <div class="d-flex justify-content-between mt-2" style="font-size:.78rem">
      <span class="text-muted">
        <?= $t['tipo'] === 'credito' ? 'Cupo disponible' : 'Tarjeta de débito' ?>
      </span>
      <?php if ($t['tipo'] === 'credito'): ?>
      <strong><?= P::e(P::money((int) $t['disponible_cent'])) ?></strong>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ============ MOVIMIENTOS ============ -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0">Movimientos recientes</h4>
    <span class="cm-vivo conectado"><span class="cm-vivo__punto"></span> en vivo</span>
  </div>
  <div class="card-body p-0">
    <?php if ($ultimos === []): ?>
      <div class="cm-vacio"><?= cm_icono("reload", 36) ?>Sin movimientos todavía.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Concepto</th><th>Referencia</th><th>Cuenta</th><th>Fecha</th>
            <th class="cm-num">Importe</th><th class="cm-num">Saldo</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($ultimos as $m): $i = (int) $m['importe_cent']; ?>
          <tr>
            <td><?= P::e($m['descripcion'] ?: ucfirst((string) $m['tipo'])) ?></td>
            <td class="cm-mono text-muted"><?= P::e($m['referencia']) ?></td>
            <td class="cm-mono text-muted"><?= P::e($m['numero_cuenta']) ?></td>
            <td class="text-muted"><?= P::e(P::hace((string) $m['creado_en'])) ?></td>
            <td class="cm-num <?= $i >= 0 ? 'cm-pos' : 'cm-neg' ?>">
              <?= $i >= 0 ? '+' : '' ?><?= P::e(P::money($i)) ?>
            </td>
            <td class="cm-num text-muted"><?= P::e(P::money((int) $m['saldo_posterior_cent'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($ultimos !== []): ?>
  <div class="card-footer">
    <a href="<?= P::attr($rutas->url('movimientos')) ?>">Ver todos los movimientos &rarr;</a>
  </div>
  <?php endif; ?>
</div>
