<?php
/**
 * El expediente de cobro de un deudor.
 *
 * Es lo que faltaba en la pantalla de cartera morosa: no solo cuánto
 * debe, sino quién es, cómo contactarlo, qué cuota se dejó de pagar y
 * cuándo, qué se ha hecho para cobrarle y en qué punto del proceso va.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $caso
 * @var array  $cuotas
 * @var array  $gestiones
 * @var array  $promesas
 * @var array  $otros
 * @var array  $tarjetas
 * @var bool   $puedeGestionar
 * @var bool   $puedeEscalar
 * @var bool   $puedeJuridico
 * @var bool   $puedeCerrar
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$ruta = $rutas->url('cobranza/' . $caso['codigo']);

$colorEtapa = static fn(string $e): string => match ($e) {
    'preventiva'     => 'light',
    'administrativa' => 'info',
    'extrajudicial'  => 'warning',
    'judicial'       => 'danger',
    'sentencia'      => 'dark',
    'recuperado'     => 'success',
    'castigado'      => 'secondary',
    default          => 'light',
};

$etiquetaGestion = static fn(string $t): string => match ($t) {
    'correo'        => 'Correo',
    'llamada'       => 'Llamada',
    'mensaje'       => 'Mensaje',
    'carta'         => 'Carta',
    'visita'        => 'Visita',
    'requerimiento' => 'Requerimiento de pago',
    'demanda'       => 'Demanda',
    'audiencia'     => 'Audiencia',
    'escrito'       => 'Escrito judicial',
    'convenio'      => 'Convenio de pago',
    default         => 'Nota',
};

$colorResultado = static fn(string $r): string => match ($r) {
    'pago_recibido'     => 'success',
    'promesa_pago'      => 'info',
    'contactado'        => 'primary',
    'negativa'          => 'danger',
    'no_contesta'       => 'warning',
    'numero_equivocado' => 'warning',
    'enviada'           => 'light',
    default             => 'light',
};

$cerrado = in_array((string) $caso['etapa'], ['recuperado', 'castigado'], true);

// Solo las cuotas que de verdad están en problemas.
$vencidas = array_values(array_filter(
    $cuotas,
    static fn(array $q): bool => in_array((string) $q['estado'], ['vencida', 'parcial'], true)
        || ((string) $q['estado'] === 'pendiente' && (int) $q['atraso'] > 0)
));
?>

<?php /* La columna del cliente acompaña al desplazamiento.
         La ficha del cliente ocupa media pantalla y la columna de al
         lado —crédito, cuotas en mora, arreglos y expediente judicial—
         es varias veces más alta. El resultado era una tira blanca de
         dos tercios de página bajo la ficha, y las tarjetas de abajo
         arrancando a distinta altura: eso es lo que se veía montado.

         Fijándola, el dato del cliente sigue a la vista mientras se
         trabaja el caso, que además es cuando hace falta: para llamarlo
         hay que tener el teléfono delante. */ ?>
<div class="row cm-exp">
  <!-- ============ EL CLIENTE ============ -->
  <div class="col-xl-4 col-lg-5 mb-4 cm-exp__lateral">
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">El cliente</h4>
        <span class="badge badge-<?= $colorEtapa((string) $caso['etapa']) ?>">
          <?= P::e(str_replace('_', ' ', (string) $caso['etapa'])) ?>
        </span>
      </div>
      <div class="card-body">
        <div class="font-weight-bold mb-1" style="font-size:1.05rem">
          <?= P::e($caso['cliente']) ?>
        </div>
        <small class="text-muted d-block mb-3"><?= P::e($caso['usuario']) ?></small>

        <div class="row">
          <div class="col-12 mb-2">
            <small class="text-muted d-block">Documento</small>
            <span class="cm-mono"><?= P::e($caso['documento_identidad'] ?: '—') ?></span>
          </div>
          <div class="col-12 mb-2">
            <small class="text-muted d-block">Teléfono</small>
            <?php if (!empty($caso['telefono'])): ?>
            <a href="tel:<?= P::attr(preg_replace('/\D+/', '', (string) $caso['telefono'])) ?>">
              <?= P::e($caso['telefono']) ?>
            </a>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </div>
          <div class="col-12 mb-2">
            <small class="text-muted d-block">Correo</small>
            <a href="mailto:<?= P::attr((string) $caso['correo']) ?>" style="word-break:break-all">
              <?= P::e($caso['correo']) ?>
            </a>
          </div>
          <div class="col-12 mb-2">
            <small class="text-muted d-block">Dirección</small>
            <span style="font-size:.86rem"><?= P::e($caso['direccion'] ?: '—') ?></span>
          </div>
          <div class="col-12 mb-2">
            <small class="text-muted d-block">Trabajo</small>
            <span style="font-size:.86rem">
              <?= P::e($caso['cargo'] ?: '—') ?><?= !empty($caso['empresa']) ? ' · ' . P::e($caso['empresa']) : '' ?>
            </span>
          </div>
          <div class="col-12">
            <small class="text-muted d-block">Ingreso declarado</small>
            <span class="font-weight-bold">
              <?= P::e(P::money((int) ($caso['ingreso_mensual_cent'] ?? 0))) ?>
            </span>
          </div>
        </div>

        <hr>
        <a class="btn btn-outline-secondary btn-sm btn-block"
           href="<?= P::attr($rutas->url('clientes/' . $caso['codigo_cliente'])) ?>">
          Ver expediente del cliente
        </a>
      </div>
    </div>

    <!-- ---------- OTROS PRODUCTOS ----------
         Un deudor rara vez tiene un solo producto, y conviene verlo
         entero antes de decidir si se demanda. -->
    <?php if ($otros !== [] || $tarjetas !== []): ?>
    <div class="card mb-0">
      <div class="card-header">
        <h4 class="card-title mb-0">Sus otros productos</h4>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <tbody>
          <?php foreach ($otros as $o): ?>
            <tr>
              <td style="font-size:.8rem">
                <?= P::e($o['folio']) ?>
                <small class="text-muted d-block"><?= P::e($o['producto']) ?></small>
              </td>
              <td class="text-right cm-mono" style="font-size:.8rem">
                <?= P::e(P::money((int) $o['saldo_capital_cent'])) ?>
                <small class="text-muted d-block"><?= P::e($o['estado']) ?></small>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php foreach ($tarjetas as $t): ?>
            <tr>
              <td style="font-size:.8rem">
                ···· <?= P::e($t['ultimos4']) ?>
                <small class="text-muted d-block">
                  <?= P::e(ucfirst((string) $t['marca']) . ' ' . $t['tipo']) ?>
                </small>
              </td>
              <td class="text-right cm-mono" style="font-size:.8rem">
                <?= P::e(P::money((int) $t['saldo_usado_cent'])) ?>
                <small class="text-muted d-block"><?= P::e($t['estado']) ?></small>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ============ EL CRÉDITO Y LA DEUDA ============ -->
  <div class="col-xl-8 col-lg-7 mb-4">
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">
          <?= P::e($caso['producto']) ?> · <?= P::e($caso['credito_folio']) ?>
        </h4>
        <a class="btn btn-sm btn-outline-secondary"
           href="<?= P::attr($rutas->url('creditos/' . $caso['codigo_credito'])) ?>">
          Ver el crédito
        </a>
      </div>
      <div class="card-body">
        <div class="row">
          <div class="col-6 col-md-3 mb-3">
            <small class="text-muted d-block">Saldo vencido</small>
            <b class="text-danger" style="font-size:1.1rem">
              <?= P::e(P::money((int) $caso['deuda_cent'])) ?>
            </b>
          </div>
          <div class="col-6 col-md-3 mb-3">
            <small class="text-muted d-block">Mora acumulada</small>
            <b style="font-size:1.1rem"><?= P::e(P::money((int) $caso['mora_cent'])) ?></b>
          </div>
          <div class="col-6 col-md-3 mb-3">
            <small class="text-muted d-block">Total a cobrar</small>
            <b class="text-danger" style="font-size:1.1rem">
              <?= P::e(P::money((int) $caso['deuda_cent'] + (int) $caso['mora_cent'])) ?>
            </b>
          </div>
          <div class="col-6 col-md-3 mb-3">
            <small class="text-muted d-block">Días de atraso</small>
            <b style="font-size:1.1rem"><?= (int) $caso['dias_mora'] ?></b>
          </div>

          <div class="col-6 col-md-3 mb-2">
            <small class="text-muted d-block">Monto otorgado</small>
            <span><?= P::e(P::money((int) $caso['monto_cent'])) ?></span>
          </div>
          <div class="col-6 col-md-3 mb-2">
            <small class="text-muted d-block">Saldo de capital</small>
            <span><?= P::e(P::money((int) $caso['saldo_capital_cent'])) ?></span>
          </div>
          <div class="col-6 col-md-3 mb-2">
            <small class="text-muted d-block">Cuota mensual</small>
            <span><?= P::e(P::money((int) $caso['cuota_cent'])) ?></span>
          </div>
          <div class="col-6 col-md-3 mb-2">
            <small class="text-muted d-block">Tasa anual</small>
            <span><?= P::e(number_format((int) $caso['tasa_bp'] / 100, 2)) ?> %</span>
          </div>
        </div>
      </div>
    </div>

    <!-- ---------- LAS CUOTAS QUE SE DEJARON DE PAGAR ----------
         Es el detalle que la lista no daba: cuál se dejó de pagar,
         cuándo vencía y cuánto se abonó, si se abonó algo. -->
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Cuotas en mora</h4>
        <span class="badge badge-danger"><?= count($vencidas) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($vencidas === []): ?>
          <div class="cm-vacio"><p class="mb-0">No hay cuotas vencidas en este momento.</p></div>
        <?php else: ?>
        <?php /* Sin caja de 22rem con scroll propio: la página ya tiene
                 el suyo, y dos barras anidadas hacen que la de dentro se
                 pierda en cuanto el puntero sale de la caja. */ ?>
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0">
            <thead>
              <tr><th>#</th><th>Vencía</th><th class="cm-num">Cuota</th>
                  <th class="cm-num">Abonado</th><th class="cm-num">Pendiente</th>
                  <th class="cm-num">Mora</th><th class="cm-num">Días</th><th>Estado</th></tr>
            </thead>
            <tbody>
            <?php foreach ($vencidas as $q):
              $pend = (int) $q['total_cent'] - (int) $q['pagado_cent'];
            ?>
              <tr>
                <td class="cm-mono"><?= (int) $q['numero'] ?></td>
                <td class="cm-nowrap" style="font-size:.82rem">
                  <?= P::e(P::fecha((string) $q['fecha_vencimiento'], 'd/m/Y')) ?>
                </td>
                <td class="cm-num cm-mono"><?= P::e(P::money((int) $q['total_cent'])) ?></td>
                <td class="cm-num cm-mono text-success">
                  <?= (int) $q['pagado_cent'] > 0 ? P::e(P::money((int) $q['pagado_cent'])) : '—' ?>
                </td>
                <td class="cm-num cm-mono text-danger font-weight-bold">
                  <?= P::e(P::money($pend)) ?>
                </td>
                <td class="cm-num cm-mono"><?= P::e(P::money((int) $q['mora_cent'])) ?></td>
                <td class="cm-num"><?= (int) $q['atraso'] ?></td>
                <td>
                  <span class="badge badge-<?= (string) $q['estado'] === 'parcial' ? 'warning' : 'danger' ?>">
                    <?= P::e($q['estado']) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ---------- ARREGLOS DE PAGO ----------
         Un expediente con medio centenar de cuotas vencidas no se cobra
         cuota por cuota: se negocia. Aquí consta lo que se le ofreció al
         cliente, quién lo propuso, quién lo autorizó y cuánto se
         condonó, que es lo que después hay que poder justificar. -->
    <div class="card mb-4" id="cm-arreglos">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Arreglos de pago</h4>
        <div class="d-flex align-items-center" style="gap:.5rem">
          <?php if ($arreglos !== []): ?>
          <span class="badge badge-light"><?= count($arreglos) ?></span>
          <?php endif; ?>
          <a class="btn btn-sm btn-outline-primary"
             href="<?= P::attr($rutas->url('creditos/' . $caso["codigo_credito"])) ?>#cm-arreglo">
            <?= cm_icono('plus', 14, 'mr-1') ?> Proponer uno
          </a>
        </div>
      </div>

      <?php if ($arreglos === []): ?>
      <div class="cm-vacio">
        <p class="mb-1">No se ha pactado ningún arreglo en este expediente.</p>
        <small class="text-muted">
          Un arreglo permite cobrar un porcentaje de lo vencido y dar el
          crédito por al día, o cerrar la deuda con un pago único.
        </small>
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead>
            <tr>
              <th>Folio</th><th>Modalidad</th>
              <th class="cm-num">Exigible</th>
              <th class="cm-num">Paga</th>
              <th class="cm-num">Se condona</th>
              <th>Vence</th><th>Propuso</th><th>Autorizó</th><th>Estado</th>
              <?= P::crudo(cm_th_acciones([
                    ['Autorizar', '#', 'check', 'permiso' => 'arreglo.autorizar'],
                    ['Enlace',    '#', 'mail',  'permiso' => 'cobro.enlace'],
                    ['Anular',    '#', 'block', 'permiso' => 'arreglo.anular'],
                  ])) ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($arreglos as $ar):
            $colorAr = [
              'propuesto'  => 'warning', 'autorizado' => 'info',
              'pagado'     => 'success', 'vencido'    => 'secondary',
              'anulado'    => 'secondary',
            ][$ar['estado']] ?? 'light';

            $rutaAr = $rutas->url('creditos/' . $caso["codigo_credito"]
                                  . '/arreglo/' . (int) $ar['id']);
            $quitaBp = (int) $ar['exigible_cent'] > 0
              ? (int) round((int) $ar['condonado_cent'] / (int) $ar['exigible_cent'] * 10000)
              : 0;
          ?>
            <tr>
              <td class="cm-mono" style="font-size:.8rem"><?= P::e($ar['folio']) ?></td>
              <td><?= $ar['tipo'] === 'finiquito' ? 'Finiquito' : 'Ponerse al día' ?></td>
              <td class="cm-num cm-mono"><?= P::e(P::money((int) $ar['exigible_cent'])) ?></td>
              <td class="cm-num cm-mono text-success font-weight-bold">
                <?= P::e(P::money((int) $ar['monto_requerido_cent'])) ?>
              </td>
              <td class="cm-num cm-mono text-danger">
                <?= P::e(P::money((int) $ar['condonado_cent'])) ?>
                <small class="d-block text-muted">
                  <?= number_format($quitaBp / 100, 1) ?>%
                </small>
              </td>
              <td class="cm-nowrap" style="font-size:.82rem">
                <?= P::e(P::fecha((string) $ar['vence_el'], 'd/m/Y')) ?>
              </td>
              <td style="font-size:.82rem"><?= P::e((string) ($ar['propuso'] ?? '—')) ?></td>
              <td style="font-size:.82rem"><?= P::e((string) ($ar['autorizo'] ?? '—')) ?></td>
              <td>
                <span class="badge badge-<?= P::attr($colorAr) ?>">
                  <?= P::e(ucfirst((string) $ar['estado'])) ?>
                </span>
              </td>
              <td class="text-right">
                <?= P::crudo(cm_acciones([
                      ['Autorizar', $rutaAr . '/autorizar', 'check',
                       'estilo' => 'success', 'metodo' => 'post', 'csrf' => $csrf,
                       'proposito' => 'credito', 'permiso' => 'arreglo.autorizar',
                       'si' => $ar['estado'] === 'propuesto',
                       'confirmar' => 'Autoriza condonar '
                                      . P::money((int) $ar['condonado_cent']) . '.'],
                      ['Enlace', $rutaAr . '/enlace', 'mail',
                       'estilo' => 'primary', 'metodo' => 'post', 'csrf' => $csrf,
                       'proposito' => 'credito', 'permiso' => 'cobro.enlace',
                       'si' => $ar['estado'] === 'autorizado',
                       'confirmar' => 'Se le enviará al cliente un enlace para pagar '
                                      . P::money((int) $ar['monto_requerido_cent']) . '.'],
                      ['Anular', $rutaAr . '/anular', 'block',
                       'estilo' => 'danger', 'metodo' => 'post', 'csrf' => $csrf,
                       'proposito' => 'credito', 'permiso' => 'arreglo.anular',
                       'si' => in_array($ar['estado'], ['propuesto', 'autorizado'], true),
                       'confirmar' => 'Se anula el arreglo y su enlace de pago deja de servir.'],
                    ])) ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- ---------- EXPEDIENTE JUDICIAL ---------- -->
    <?php if ($puedeJuridico || !empty($caso['juzgado'])): ?>
    <div class="card mb-4">
      <div class="card-header">
        <h4 class="card-title mb-0">Expediente judicial</h4>
      </div>
      <div class="card-body">
        <?php if ($puedeJuridico && !$cerrado): ?>
        <form method="post" data-cm-ajax data-cm-recargar action="<?= P::attr($ruta . '/judicial') ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="cobranza">

          <div class="form-row">
            <div class="form-group col-md-6">
              <label>Juzgado</label>
              <input type="text" class="form-control" name="juzgado" maxlength="160"
                     value="<?= P::attr($caso['juzgado'] ?? '') ?>"
                     placeholder="Ej. Juzgado Primero de lo Civil y Mercantil de San Salvador">
            </div>
            <div class="form-group col-md-3">
              <label>Referencia</label>
              <input type="text" class="form-control cm-mono" name="referencia" maxlength="80"
                     value="<?= P::attr($caso['referencia_judicial'] ?? '') ?>"
                     placeholder="N.º de expediente">
            </div>
            <div class="form-group col-md-3">
              <label>Abogado a cargo</label>
              <input type="text" class="form-control" name="abogado" maxlength="160"
                     value="<?= P::attr($caso['abogado'] ?? '') ?>">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group col-md-3">
              <label>Demanda presentada</label>
              <input type="date" class="form-control" name="demanda_en"
                     value="<?= P::attr((string) ($caso['demanda_en'] ?? '')) ?>">
            </div>
            <div class="form-group col-md-3">
              <label>Emplazamiento</label>
              <input type="date" class="form-control" name="emplazamiento_en"
                     value="<?= P::attr((string) ($caso['emplazamiento_en'] ?? '')) ?>">
            </div>
            <div class="form-group col-md-3">
              <label>Sentencia</label>
              <input type="date" class="form-control" name="sentencia_en"
                     value="<?= P::attr((string) ($caso['sentencia_en'] ?? '')) ?>">
            </div>
            <div class="form-group col-md-3">
              <label>Resultado</label>
              <select class="form-control" name="sentencia_favorable">
                <option value="">— Sin resolver —</option>
                <option value="1" <?= (int) ($caso['sentencia_favorable'] ?? -1) === 1 ? 'selected' : '' ?>>
                  Favorable a la institución
                </option>
                <option value="0" <?= (int) ($caso['sentencia_favorable'] ?? -1) === 0 ? 'selected' : '' ?>>
                  Desfavorable
                </option>
              </select>
            </div>
          </div>

          <button type="submit" class="btn btn-primary">Guardar el expediente judicial</button>
        </form>
        <?php else: ?>
        <div class="row">
          <div class="col-md-6 mb-2">
            <small class="text-muted d-block">Juzgado</small>
            <span><?= P::e($caso['juzgado'] ?? '—') ?></span>
          </div>
          <div class="col-md-3 mb-2">
            <small class="text-muted d-block">Referencia</small>
            <span class="cm-mono"><?= P::e($caso['referencia_judicial'] ?? '—') ?></span>
          </div>
          <div class="col-md-3 mb-2">
            <small class="text-muted d-block">Abogado</small>
            <span><?= P::e($caso['abogado'] ?? '—') ?></span>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============ ACCIONES Y BITÁCORA ============ -->
<div class="row">
  <?php if ($puedeGestionar && !$cerrado): ?>
  <div class="col-lg-5 mb-4">
    <div class="card h-100 mb-0">
      <div class="card-header">
        <h4 class="card-title mb-0">Registrar una gestión</h4>
      </div>
      <div class="card-body">
        <form method="post" data-cm-ajax data-cm-recargar action="<?= P::attr($ruta . '/gestion') ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="cobranza">

          <div class="form-row">
            <div class="form-group col-6">
              <label>¿Qué se hizo?</label>
              <select class="form-control" name="tipo" required>
                <option value="llamada">Llamada</option>
                <option value="correo">Correo</option>
                <option value="mensaje">Mensaje de texto</option>
                <option value="visita">Visita</option>
                <option value="carta">Carta entregada</option>
                <option value="requerimiento">Requerimiento de pago</option>
                <option value="convenio">Convenio de pago</option>
                <option value="demanda">Demanda</option>
                <option value="audiencia">Audiencia</option>
                <option value="escrito">Escrito judicial</option>
                <option value="nota">Nota interna</option>
              </select>
            </div>
            <div class="form-group col-6">
              <label>¿Qué resultó?</label>
              <select class="form-control" name="resultado" id="cob-resultado">
                <option value="sin_resultado">Sin resultado</option>
                <option value="contactado">Se le contactó</option>
                <option value="promesa_pago">Prometió pagar</option>
                <option value="pago_recibido">Pagó</option>
                <option value="no_contesta">No contesta</option>
                <option value="numero_equivocado">Número equivocado</option>
                <option value="negativa">Se negó a pagar</option>
                <option value="enviada">Enviada</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label>Asunto</label>
            <input type="text" class="form-control" name="asunto" required maxlength="190"
                   placeholder="Resumen en una línea">
          </div>

          <div class="form-group">
            <label>Detalle</label>
            <textarea class="form-control" name="detalle" rows="3"
                      placeholder="Lo que dijo, lo que se acordó, lo que conviene recordar"></textarea>
          </div>

          <!-- La promesa solo tiene sentido si el resultado es una
               promesa: aparece sola al elegirla. -->
          <div class="form-row" id="cob-promesa" hidden>
            <div class="form-group col-6">
              <label>Promete pagar el</label>
              <input type="date" class="form-control" name="promesa_fecha">
            </div>
            <div class="form-group col-6">
              <label>Monto prometido</label>
              <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" name="promesa_monto" inputmode="decimal">
              </div>
            </div>
          </div>

          <button type="submit" class="btn btn-primary">Registrar en el expediente</button>
        </form>

        <?php if ($puedeEscalar || $puedeCerrar): ?>
        <hr>
        <div class="d-flex" style="gap:.5rem;flex-wrap:wrap">
          <?php if ($puedeEscalar): ?>
          <details class="cm-rechazo">
            <summary class="btn btn-sm btn-outline-warning">Cambiar de etapa</summary>
            <form method="post" data-cm-ajax data-cm-recargar class="cm-rechazo__caja"
                  action="<?= P::attr($ruta . '/escalar') ?>">
              <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
              <input type="hidden" name="_csrf_proposito" value="cobranza">
              <select class="form-control form-control-sm mb-2" name="etapa" required>
                <option value="administrativa">Administrativa</option>
                <option value="extrajudicial">Extrajudicial (pasa a jurídico)</option>
                <?php if ($puedeJuridico): ?>
                <option value="judicial">Judicial</option>
                <option value="sentencia">Sentencia</option>
                <?php endif; ?>
              </select>
              <textarea class="form-control form-control-sm" name="nota" rows="2"
                        placeholder="Por qué se mueve de etapa"></textarea>
              <button class="btn btn-sm btn-warning btn-block mt-2">Confirmar cambio</button>
            </form>
          </details>
          <?php endif; ?>

          <?php if ($puedeCerrar): ?>
          <details class="cm-rechazo">
            <summary class="btn btn-sm btn-outline-success">Cerrar expediente</summary>
            <form method="post" data-cm-ajax class="cm-rechazo__caja"
                  action="<?= P::attr($ruta . '/cerrar') ?>">
              <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
              <input type="hidden" name="_csrf_proposito" value="cobranza">
              <select class="form-control form-control-sm mb-2" name="como" required>
                <option value="recuperado">Recuperado — se puso al día</option>
                <option value="castigado">Castigado — se da por incobrable</option>
              </select>
              <textarea class="form-control form-control-sm" name="motivo" rows="2"
                        required minlength="10"
                        placeholder="Motivo del cierre, para la auditoría"></textarea>
              <button class="btn btn-sm btn-success btn-block mt-2" data-cm-peligro
                      data-cm-confirmar="Se cerrará el expediente. ¿Continuar?">
                Confirmar cierre
              </button>
            </form>
          </details>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="col-lg-<?= ($puedeGestionar && !$cerrado) ? '7' : '12' ?> mb-4">
    <?php if ($promesas !== []): ?>
    <div class="card mb-4">
      <div class="card-header">
        <h4 class="card-title mb-0">Promesas de pago</h4>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead>
            <tr><th>Prometió para</th><th class="cm-num">Monto</th><th>Estado</th><th>Nota</th></tr>
          </thead>
          <tbody>
          <?php foreach ($promesas as $pr): ?>
            <tr>
              <td style="font-size:.82rem"><?= P::e(P::fecha((string) $pr['fecha_promesa'], 'd/m/Y')) ?></td>
              <td class="cm-num cm-mono"><?= P::e(P::money((int) $pr['monto_cent'])) ?></td>
              <td>
                <span class="badge badge-<?= (string) $pr['estado'] === 'cumplida' ? 'success'
                      : ((string) $pr['estado'] === 'incumplida' ? 'danger' : 'info') ?>">
                  <?= P::e($pr['estado']) ?>
                </span>
              </td>
              <td class="text-muted" style="font-size:.78rem"><?= P::e($pr['observaciones'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <div class="card mb-0">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Bitácora de gestiones</h4>
        <span class="badge badge-light"><?= count($gestiones) ?></span>
      </div>
      <div class="card-body">
        <?php if ($gestiones === []): ?>
          <div class="cm-vacio"><p class="mb-0">Todavía no hay gestiones registradas.</p></div>
        <?php else: ?>
        <!-- Esto es lo que se enseña cuando un cliente dice «a mí nadie
             me avisó»: cada contacto con su fecha y su resultado. -->
        <ol class="cm-bitacora">
          <?php foreach ($gestiones as $g): ?>
          <li class="cm-bitacora__item">
            <div class="cm-bitacora__cab">
              <strong><?= P::e($etiquetaGestion((string) $g['tipo'])) ?></strong>
              <span class="badge badge-<?= $colorResultado((string) $g['resultado']) ?>">
                <?= P::e(str_replace('_', ' ', (string) $g['resultado'])) ?>
              </span>
              <?php if ((int) $g['automatica'] === 1): ?>
              <span class="badge badge-light">automática</span>
              <?php endif; ?>
              <small class="text-muted ml-auto">
                <?= P::e(P::fecha((string) $g['creada_en'], 'd/m/Y H:i')) ?>
              </small>
            </div>
            <div class="cm-bitacora__asunto"><?= P::e($g['asunto']) ?></div>
            <?php if (!empty($g['detalle'])): ?>
            <div class="cm-bitacora__detalle"><?= P::e($g['detalle']) ?></div>
            <?php endif; ?>
            <small class="text-muted">
              <?= P::e($g['autor'] ?? 'Sistema') ?> · etapa <?= P::e($g['etapa']) ?>
            </small>
          </li>
          <?php endforeach; ?>
        </ol>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($cerrado): ?>
<div class="alert alert-<?= (string) $caso['etapa'] === 'recuperado' ? 'success' : 'secondary' ?>">
  <strong>Expediente cerrado como <?= P::e($caso['etapa']) ?></strong>
  el <?= P::e(P::fecha((string) $caso['cerrado_en'])) ?>
  por <?= P::e($caso['cerrador'] ?? '—') ?>.
  <?php if (!empty($caso['motivo_cierre'])): ?>
  <div class="mt-1" style="font-size:.86rem"><?= P::e($caso['motivo_cierre']) ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  // Los campos de la promesa solo aparecen si el resultado es una
  // promesa: pedir fecha y monto en una llamada que no contestó nadie
  // solo estorba.
  var sel = document.getElementById('cob-resultado');
  var caja = document.getElementById('cob-promesa');

  if (!sel || !caja) { return; }

  function alternar() {
    caja.hidden = sel.value !== 'promesa_pago';
  }

  sel.addEventListener('change', alternar);
  alternar();
})();
</script>
