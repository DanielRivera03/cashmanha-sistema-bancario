<?php
/** @var \CashMan\Http\Enrutador $rutas */
/** @var array $cuenta */
/** @var array $movimientos */
use CashMan\Http\Plantilla as P;
?>
<div class="row">
  <div class="col-lg-5 col-md-12 mb-4">
    <div class="cm-cuenta h-100">
      <div class="cm-cuenta__tipo"><?= P::e($cuenta['producto_nombre'] ?? 'Cuenta') ?></div>
      <div class="cm-cuenta__num"><?= P::e(chunk_split((string) $cuenta['numero_cuenta'], 4, ' ')) ?></div>
      <div class="cm-cuenta__saldo" data-vivo="saldo" data-cuenta="<?= P::attr($cuenta['codigo']) ?>">
        <?= P::e(P::money((int) $cuenta['saldo_cent'])) ?>
      </div>
      <div class="cm-cuenta__pie">
        <span style="font-size:.74rem;color:rgba(255,255,255,.75)"><?= P::e($cuenta['moneda']) ?></span>
        <span class="badge badge-<?= $cuenta['estado'] === 'activa' ? 'success' : 'warning' ?>">
          <?= P::e(ucfirst((string) $cuenta['estado'])) ?>
        </span>
      </div>
    </div>
  </div>

  <div class="col-lg-7 col-md-12 mb-4">
    <div class="card h-100 mb-0">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Información de la cuenta</h4>
        <small class="text-muted cm-envivo">
          <span class="cm-envivo__punto"></span> en vivo
        </small>
      </div>
      <div class="card-body">
        <!-- ------------------------------------------------------------
             Los atributos `data-vivo` los refresca el canal en tiempo
             real: cuando entra o sale dinero, estas cifras cambian solas
             y destellan, sin recargar la página.
             ------------------------------------------------------------ -->
        <div class="row">
          <div class="col-6 mb-3">
            <small class="text-muted d-block">Disponible para usar</small>
            <span class="font-weight-bold text-primary" style="font-size:1.05rem"
                  data-vivo="disponible" data-cuenta="<?= P::attr($cuenta['codigo']) ?>">
              <?= P::e(P::money($disponible)) ?>
            </span>
          </div>
          <div class="col-6 mb-3">
            <small class="text-muted d-block">Saldo contable</small>
            <span class="font-weight-bold" style="font-size:1.05rem"
                  data-vivo="saldo" data-cuenta="<?= P::attr($cuenta['codigo']) ?>">
              <?= P::e(P::money((int) $cuenta['saldo_cent'])) ?>
            </span>
          </div>
          <div class="col-6 mb-3">
            <small class="text-muted d-block">Saldo retenido</small>
            <span class="font-weight-bold"
                  data-vivo="retenido" data-cuenta="<?= P::attr($cuenta['codigo']) ?>">
              <?= P::e(P::money((int) $cuenta['saldo_retenido_cent'])) ?>
            </span>
          </div>
          <div class="col-6 mb-3">
            <small class="text-muted d-block">Saldo mínimo requerido</small>
            <span class="font-weight-bold"
                  data-vivo="minimo" data-cuenta="<?= P::attr($cuenta['codigo']) ?>">
              <?= P::e(P::money((int) ($cuenta['saldo_minimo_cent'] ?? 0))) ?>
            </span>
          </div>
          <div class="col-6 mb-3">
            <small class="text-muted d-block">Fecha de apertura</small>
            <span class="font-weight-bold"><?= P::e(P::fecha((string) $cuenta['apertura_en'], 'd/m/Y')) ?></span>
          </div>
          <div class="col-6 mb-3">
            <small class="text-muted d-block">Tipo de producto</small>
            <span class="font-weight-bold"><?= P::e(ucfirst((string) ($cuenta['producto_tipo'] ?? ''))) ?></span>
          </div>
        </div>

        <!-- Último movimiento: un saldo que cambia sin decir por qué
             deja al cliente adivinando. -->
        <div class="cm-ultimo">
          <small class="text-muted d-block mb-1">Último movimiento</small>
          <div class="cm-ultimo__fila">
            <span data-vivo="ultimo-concepto"><?= P::e($ultimoMov['descripcion'] ?? '—') ?></span>
            <b data-vivo="ultimo-importe" class="<?= ($ultimoMov !== null && (int) $ultimoMov['importe_cent'] < 0) ? 'cm-neg' : 'cm-pos' ?>">
              <?php if ($ultimoMov !== null): ?>
                <?= (int) $ultimoMov['importe_cent'] < 0 ? '' : '+' ?><?= P::e(P::money((int) $ultimoMov['importe_cent'])) ?>
              <?php else: ?>—<?php endif; ?>
            </b>
          </div>
          <small class="text-muted" data-vivo="ultimo-referencia">
            <?= P::e($ultimoMov['referencia'] ?? '') ?>
          </small>
        </div>

        <div class="mt-3">
          <a href="<?= P::attr($rutas->url('transferir')) ?>" class="btn btn-primary btn-sm">Transferir</a>
          <a href="<?= P::attr($rutas->url('estados')) ?>" class="btn btn-outline-secondary btn-sm">Estado de cuenta</a>

        </div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h4 class="card-title mb-0">Movimientos de la cuenta</h4>
    <span class="cm-vivo conectado"><span class="cm-vivo__punto"></span> en vivo</span>
  </div>
  <div class="card-body p-0">
    <?php if ($movimientos === []): ?>
      <div class="cm-vacio"><p class="mb-0">Sin movimientos en esta cuenta.</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0" data-cm-tabla>
        <thead>
          <tr><th>Concepto</th><th>Referencia</th><th>Fecha</th>
              <th class="cm-num">Importe</th><th class="cm-num">Saldo</th>
              <th class="cm-acciones">Comprobante</th></tr>
        </thead>
        <tbody>
        <?php foreach ($movimientos as $m): $i = (int) $m['importe_cent']; ?>
          <tr>
            <td><?= P::e($m['descripcion'] ?: ucfirst((string) $m['tipo'])) ?></td>
            <td class="cm-mono text-muted"><?= P::e($m['referencia']) ?></td>
            <td class="text-muted"><?= P::e(P::fecha((string) $m['creado_en'])) ?></td>
            <td class="cm-num <?= $i >= 0 ? 'cm-pos' : 'cm-neg' ?>">
              <?= $i >= 0 ? '+' : '' ?><?= P::e(P::money($i)) ?>
            </td>
            <td class="cm-num text-muted"><?= P::e(P::money((int) $m['saldo_posterior_cent'])) ?></td>
            <td class="cm-acciones">
              <!-- El comprobante de cada movimiento, en PDF y con su
                   sello de integridad. La ruta verifica que la cuenta
                   sea suya antes de generar nada: el código del asiento
                   no alcanza para ver el de otro. -->
              <?php if (!empty($m['comprobante'])): ?>
              <a class="btn btn-sm btn-outline-secondary"
                 href="<?= P::attr($rutas->url('comprobante/' . $m['comprobante'])) ?>"
                 target="_blank" rel="noopener"
                 data-cm-doc data-cm-doc-titulo="Preparando el comprobante"
                 target="_blank" rel="noopener"
                 title="Ver el comprobante de esta transacción">
                Ver
              </a>
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

<?php
/* --- Tarjetas de débito de esta cuenta ---------------------------------
   Van aquí, no en un catálogo aparte: una tarjeta de débito no es un
   producto que luego se ata a algo, es el plástico de ESTA cuenta.
   Pedirla desde otro sitio obligaría a elegir después a cuál se liga,
   que es justo el dato que aquí ya se sabe. */
$debitos     = $debitos     ?? [];
$topeDebito  = (int) ($topeDebito ?? 2);
$puedeDebito = (bool) ($puedeDebito ?? false);
$marcas      = $marcas ?? ['visa'];
$logosTarjeta = $rutas->url('assets/plantilla/images');
$activa      = (string) $cuenta['estado'] === 'activa';
?>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
    <h4 class="card-title mb-0">Tarjeta de débito</h4>
    <span class="badge badge-light"><?= count($debitos) ?> de <?= $topeDebito ?></span>
  </div>

  <div class="card-body">
    <?php if ($debitos === []): ?>
      <p class="text-muted mb-3" style="font-size:.88rem;max-width:42rem">
        Una tarjeta de débito paga con el dinero de esta cuenta: lo que gaste sale de
        aquí en el momento. No tiene línea de crédito, ni intereses, ni fecha de corte,
        y por lo mismo no admite compras a cuotas.
      </p>
    <?php else: ?>
    <div class="row">
      <?php foreach ($debitos as $d): ?>
      <div class="col-lg-5 col-md-6 mb-3">
        <a href="<?= P::attr($rutas->url('tarjetas/' . $d['codigo'])) ?>"
           style="text-decoration:none;display:block">
          <?= P::crudo(cm_plastico($d, ['assets' => $logosTarjeta])) ?>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($puedeDebito && $activa && count($debitos) < $topeDebito): ?>
    <form method="post" data-cm-ajax data-cm-recargar class="form-row align-items-end"
          action="<?= P::attr($rutas->url('cuentas/' . $cuenta['codigo'] . '/tarjeta-debito')) ?>">
      <input type="hidden" name="_csrf" value="<?= P::attr($csrf ?? '') ?>">
      <input type="hidden" name="_csrf_proposito" value="cuenta">

      <div class="form-group col-md-4">
        <label class="small mb-1">Marca</label>
        <select name="marca" class="form-control form-control-sm">
          <?php foreach ($marcas as $m): ?>
          <option value="<?= P::attr($m) ?>"><?= P::e(match ($m) {
              'mastercard' => 'Mastercard',
              'amex'       => 'American Express',
              default      => 'Visa',
          }) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group col-md-4">
        <button type="submit" class="btn btn-primary btn-sm btn-block"
                data-cm-confirmar="Se emitirá una tarjeta de débito ligada a esta cuenta. ¿Continuar?">
          <?= cm_icono('card', 15, 'mr-1') ?> Emitir tarjeta de débito
        </button>
      </div>
    </form>
    <?php elseif ($puedeDebito && $activa): ?>
      <p class="text-muted mb-0" style="font-size:.84rem">
        Esta cuenta ya tiene <?= $topeDebito ?> tarjeta(s) de débito, que es el máximo.
        Si extravió una, cancélela desde su ficha y podrá emitir otra.
      </p>
    <?php endif; ?>
  </div>
</div>
