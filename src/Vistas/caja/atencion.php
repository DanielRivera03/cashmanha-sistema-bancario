<?php

/**
 * Atención en ventanilla.
 *
 * El módulo de caja estaba organizado por OPERACIÓN: una pantalla para
 * depositar, otra para retirar, otra para abrir cuenta, y cada una
 * pedía el número de cuenta otra vez. Eso describe lo que hace el
 * sistema, no lo que pasa en una ventanilla.
 *
 * Lo que pasa en una ventanilla es que llega una persona. Trae su DUI,
 * una libreta, una tarjeta o solo su nombre. El cajero la identifica
 * con lo que sea que traiga, la ve entera, y actúa desde ahí.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array|null $ficha
 * @var list<array<string,mixed>> $coincidencias
 * @var string $buscado
 * @var array|null $turno
 */

use CashMan\Http\Plantilla as P;
?>

<?php /* ---------- El turno, siempre a la vista ----------
         Un cajero que cobra con la caja cerrada tiene un problema que
         solo descubre al cuadrar. Esto se lo dice antes. */ ?>
<?php if ($turno === null): ?>
<div class="alert alert-warning d-flex align-items-center">
  <?= cm_icono('warning', 18, 'mr-2') ?>
  <div>
    <strong>No tiene una caja abierta.</strong>
    Puede consultar, pero no podrá cobrar ni pagar hasta abrir su turno.
    <a href="<?= P::attr($rutas->url('caja')) ?>" class="ml-2">Abrir caja</a>
  </div>
</div>
<?php else: ?>
<div class="cm-turno-tira">
  <span class="cm-turno-tira__punto" aria-hidden="true"></span>
  <span>
    Caja <strong><?= P::e((string) $turno['caja']) ?></strong>
    <?php if (!empty($turno['sucursal'])): ?>
      · <?= P::e((string) $turno['sucursal']) ?>
    <?php endif; ?>
    · abierta desde <?= P::e(P::fecha((string) $turno['abierta_en'], 'H:i')) ?>
  </span>
  <span class="ml-auto">
    En caja <strong><?= P::e(P::money((int) $turno['efectivo_cent'])) ?></strong>
    · <?= (int) $turno['movimientos'] ?> movimiento(s)
  </span>
  <a href="<?= P::attr($rutas->url('caja/' . $turno['codigo'])) ?>">Ver el turno</a>
</div>
<?php endif; ?>

<?php /* ---------- La búsqueda ----------
         Una sola caja. No se le pide al cajero que diga QUÉ está
         escribiendo: con gente esperando, elegir el tipo de búsqueda
         en un desplegable antes de teclear es una pulsación de más. */ ?>
<div class="card">
  <div class="card-body">
    <form method="get" action="<?= P::attr($rutas->url('caja/atencion')) ?>"
          class="cm-buscador" role="search">
      <label class="sr-only" for="q">Buscar al cliente</label>
      <div class="cm-buscador__campo">
        <?= cm_icono('search', 18) ?>
        <input type="search" id="q" name="q" class="form-control"
               value="<?= P::attr($buscado) ?>" autofocus autocomplete="off"
               placeholder="DUI, cuenta, folio del crédito, últimos 4 de la tarjeta o nombre">
      </div>
      <button type="submit" class="btn btn-primary">Buscar</button>
    </form>
    <small class="form-text text-muted mt-2">
      Escriba lo que el cliente traiga. No hace falta decir qué es.
    </small>
  </div>
</div>

<?php /* ---------- Varias coincidencias ---------- */ ?>
<?php if ($ficha === null && $coincidencias !== []): ?>
<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">
      <?= count($coincidencias) ?> coincidencia(s) para «<?= P::e($buscado) ?>»
    </h4>
  </div>
  <div class="card-body p-0">
    <?php foreach ($coincidencias as $c): ?>
    <a class="cm-coincidencia"
       href="<?= P::attr($rutas->url('caja/atencion') . '?cliente=' . $c['codigo']) ?>">
      <span class="cm-avatar"><?= P::e(mb_strtoupper(mb_substr($c['nombre'], 0, 2))) ?></span>
      <span class="cm-coincidencia__texto">
        <strong><?= P::e($c['nombre']) ?></strong>
        <small>
          <?= $c['documento'] !== '' ? P::e($c['documento']) . ' · ' : '' ?>
          <?= P::e($c['usuario']) ?>
        </small>
      </span>
      <?php if ($c['segmento'] !== 'estandar'): ?>
        <span class="badge badge-primary"><?= P::e(ucfirst($c['segmento'])) ?></span>
      <?php endif; ?>
      <?php if ($c['estado'] !== 'activo'): ?>
        <span class="badge badge-danger"><?= P::e($c['estado']) ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($ficha === null && $buscado !== '' && $coincidencias === []): ?>
<div class="card">
  <div class="card-body">
    <div class="cm-vacio">
      <p class="mb-1">Nadie coincide con «<?= P::e($buscado) ?>».</p>
      <small class="text-muted">
        Pruebe con el DUI completo, el número de cuenta, el folio del crédito (CR-2026-…) o el apellido.
      </small>
    </div>
  </div>
</div>
<?php endif; ?>

<?php /* ---------- La ficha ---------- */ ?>
<?php if ($ficha !== null): ?>

<div class="card cm-ficha-cliente">
  <div class="card-body">
    <div class="d-flex align-items-start flex-wrap" style="gap:1rem">
      <span class="cm-avatar cm-avatar--grande">
        <?= P::e(mb_strtoupper(mb_substr($ficha['nombre'], 0, 2))) ?>
      </span>

      <div style="flex:1;min-width:14rem">
        <h4 class="mb-1" style="font-size:1.15rem"><?= P::e($ficha['nombre']) ?></h4>
        <div class="cm-ficha-cliente__datos">
          <?php if ($ficha['documento'] !== ''): ?>
            <span><?= cm_icono('user', 14) ?> <?= P::e($ficha['documento']) ?></span>
          <?php endif; ?>
          <?php if ($ficha['telefono'] !== ''): ?>
            <span><?= cm_icono('device', 14) ?> <?= P::e($ficha['telefono']) ?></span>
          <?php endif; ?>
          <span><?= cm_icono('clock', 14) ?>
            cliente desde <?= P::e(P::fecha((string) $ficha['cliente_desde'], 'm/Y')) ?></span>
        </div>
      </div>

      <div class="text-right">
        <?php if ($ficha['segmento'] !== 'estandar'): ?>
          <span class="badge badge-primary mb-1"><?= P::e(ucfirst($ficha['segmento'])) ?></span><br>
        <?php endif; ?>
        <small class="text-muted d-block">En depósitos</small>
        <strong style="font-size:1.1rem">
          <?= P::e(P::money((int) $ficha['totales']['depositos_cent'])) ?>
        </strong>
      </div>
    </div>

    <?php /* Lo que le impide operar, arriba. Un cajero que lo descubre
             a mitad de una operación ya le hizo contar el dinero. */ ?>
    <?php if ($ficha['avisos'] !== []): ?>
    <div class="cm-avisos-ventanilla mt-3">
      <?php foreach ($ficha['avisos'] as $a): ?>
      <div class="cm-avisos-ventanilla__item is-<?= P::attr($a['nivel']) ?>">
        <?= cm_icono($a['nivel'] === 'alto' ? 'warning' : 'info', 15) ?>
        <span><?= P::e($a['texto']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="row">
  <?php /* ---------- Cuentas ---------- */ ?>
  <div class="col-xl-6">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title mb-0">Cuentas <span class="badge badge-light ml-1"><?= count($ficha['cuentas']) ?></span></h4>
      </div>
      <div class="card-body p-0">
        <?php if ($ficha['cuentas'] === []): ?>
          <div class="cm-vacio"><p class="mb-0">Sin cuentas.</p></div>
        <?php else: ?>
        <?php foreach ($ficha['cuentas'] as $c): ?>
        <div class="cm-linea-ventanilla">
          <div style="flex:1;min-width:0">
            <strong class="cm-mono"><?= P::e($c['numero_cuenta']) ?></strong>
            <small class="d-block text-muted"><?= P::e($c['producto']) ?></small>
          </div>
          <div class="text-right mr-3">
            <strong><?= P::e(P::money((int) $c['saldo_cent'])) ?></strong>
            <small class="d-block text-muted">
              disponible <?= P::e(P::money((int) $c['disponible'])) ?>
            </small>
          </div>
          <?php if ($c['operable'] && $turno !== null): ?>
          <div class="cm-linea-ventanilla__acciones">
            <a class="btn btn-sm btn-primary"
               href="<?= P::attr($rutas->url('deposito') . '?cuenta=' . $c['numero_cuenta']) ?>">
              Depositar
            </a>
            <a class="btn btn-sm btn-outline-secondary"
               href="<?= P::attr($rutas->url('retiro') . '?cuenta=' . $c['numero_cuenta']) ?>">
              Retirar
            </a>
          </div>
          <?php else: ?>
            <span class="badge badge-secondary"><?= P::e($c['estado']) ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php /* ---------- Créditos ---------- */ ?>
  <div class="col-xl-6">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title mb-0">Créditos <span class="badge badge-light ml-1"><?= count($ficha['creditos']) ?></span></h4>
      </div>
      <div class="card-body p-0">
        <?php if ($ficha['creditos'] === []): ?>
          <div class="cm-vacio"><p class="mb-0">Sin créditos con saldo pendiente.</p></div>
        <?php else: ?>
        <?php foreach ($ficha['creditos'] as $cr): ?>
        <div class="cm-linea-ventanilla">
          <div style="flex:1;min-width:0">
            <strong><?= P::e($cr['producto']) ?></strong>
            <?php /* Un crédito castigado se cobra igual, pero el cajero
                     tiene que saber que lo es: no se le aplica un abono
                     normal sin avisar a cobranza. */ ?>
            <?php if (($cr['estado'] ?? '') === 'castigado'): ?>
            <span class="badge badge-danger ml-1">castigado</span>
            <?php endif; ?>
            <small class="d-block text-muted cm-mono"><?= P::e($cr['folio']) ?></small>
          </div>
          <div class="text-right mr-3">
            <?php if ((int) $cr['vencido_cent'] > 0): ?>
              <strong class="text-danger"><?= P::e(P::money((int) $cr['vencido_cent'])) ?></strong>
              <small class="d-block text-danger"><?= (int) $cr['vencidas'] ?> vencida(s)</small>
            <?php else: ?>
              <strong><?= P::e(P::money((int) $cr['cuota_cent'])) ?></strong>
              <small class="d-block text-muted">
                vence <?= P::e(P::fecha((string) $cr['proxima'], 'd/m/Y')) ?>
              </small>
            <?php endif; ?>
          </div>
          <a class="btn btn-sm btn-outline-primary"
             href="<?= P::attr($rutas->url('creditos/' . $cr['codigo'])) ?>">Ver</a>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php /* ---------- Tarjetas ---------- */ ?>
<?php if ($ficha['tarjetas'] !== []): ?>
<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">Tarjetas <span class="badge badge-light ml-1"><?= count($ficha['tarjetas']) ?></span></h4>
  </div>
  <div class="card-body p-0">
    <?php foreach ($ficha['tarjetas'] as $t): ?>
    <div class="cm-linea-ventanilla">
      <div style="flex:1;min-width:0">
        <strong>···· <?= P::e($t['ultimos4']) ?></strong>
        <small class="d-block text-muted">
          <?= P::e(ucfirst((string) $t['marca'])) ?> ·
          <?= P::e((string) ($t['producto'] ?: ucfirst((string) $t['tipo']))) ?>
        </small>
      </div>
      <?php if ((string) $t['tipo'] === 'credito'): ?>
      <div class="text-right mr-3">
        <strong><?= P::e(P::money((int) $t['saldo_usado_cent'])) ?></strong>
        <small class="d-block text-muted">
          de <?= P::e(P::money((int) $t['limite_cent'])) ?>
        </small>
      </div>
      <?php endif; ?>
      <span class="badge badge-<?= $t['estado'] === 'activa' ? 'success' : 'secondary' ?>">
        <?= P::e((string) $t['estado']) ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>
