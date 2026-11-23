<?php
/**
 * Los pedidos de la tienda.
 *
 * El cliente ve los suyos; quien atiende, los de todos. Cada pedido
 * enseña su referencia de tarjeta, que es la que aparece en el estado
 * de cuenta: es lo que permite reconocer un cargo cuando alguien llama
 * a preguntar por él.
 *
 * Un pedido que no prosperó dice POR QUÉ. «Anulado» a secas obliga al
 * cliente a llamar para averiguar si le rechazaron la tarjeta o si se
 * le pasó el tiempo, y «pago pendiente» eterno es peor todavía, porque
 * no dice nada y además deja la compra en el aire.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $pedidos
 * @var bool   $todos
 * @var bool   $puedeVerTodos
 * @var bool   $puedeGestionar
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$colorEstado = static fn(string $e): string => match ($e) {
    'pagado'         => 'info',
    'preparando'     => 'warning',
    'enviado'        => 'primary',
    'entregado'      => 'success',
    'anulado'        => 'danger',
    'pago_pendiente' => 'secondary',
    default          => 'light',
};

$nombreEstado = static fn(string $e): string => match ($e) {
    'pago_pendiente' => 'Esperando el pago',
    'pagado'         => 'Pagado',
    'preparando'     => 'En preparación',
    'enviado'        => 'Enviado',
    'entregado'      => 'Entregado',
    'anulado'        => 'Anulado',
    default          => ucfirst($e),
};

$siguiente = static fn(string $e): ?string => match ($e) {
    'pagado'     => 'preparando',
    'preparando' => 'enviado',
    'enviado'    => 'entregado',
    default      => null,
};

/* Lo que de verdad pasó con el cobro, dicho al cliente.
   El estado del pedido dice dónde quedó; esto dice por qué. */
$explicacion = static function (array $p) use ($nombreEstado): string {
    if ((string) $p['estado'] === 'anulado') {
        return (string) ($p['cerrado_motivo'] ?: 'El pedido se anuló.');
    }

    if ((string) $p['estado'] === 'pago_pendiente') {
        return match ((string) ($p['cobro_estado'] ?? '')) {
            'fallido', 'cancelado' => (string) ($p['cobro_error']
                ?: 'El cobro no prosperó. Puede volver a intentarlo desde la tienda.'),
            'procesando', 'iniciado' => 'Estamos esperando que la procesadora confirme el '
                . 'cobro. En cuanto lo haga, el pedido pasa a prepararse.',
            default => 'El pago todavía no se ha completado. Mientras tanto los artículos '
                . 'quedan apartados para usted.',
        };
    }

    return '';
};

$ESTADOS = ['pago_pendiente', 'pagado', 'preparando', 'enviado', 'entregado', 'anulado'];

/* Los periodos que de verdad tienen pedidos: un desplegable con los
   doce meses ofrece once puertas a listas vacías. */
$anios = [];
$meses = [];
$porEstado = [];

foreach ($pedidos as $p) {
    $t = strtotime((string) $p['creado_en']);
    $anios[date('Y', $t)] = true;
    $meses[(int) date('n', $t)] = true;
    $porEstado[(string) $p['estado']] = ($porEstado[(string) $p['estado']] ?? 0) + 1;
}

krsort($anios);
ksort($meses);

$MES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap"
       style="gap:.6rem">
    <div>
      <h4 class="card-title mb-0"><?= $todos ? 'Todos los pedidos' : 'Mis pedidos' ?></h4>
      <small class="text-muted d-block mt-1" style="font-size:.78rem">
        <?= count($pedidos) ?> pedido(s) registrados
      </small>
    </div>
    <div style="display:flex;gap:.4rem">
      <?php if ($puedeVerTodos): ?>
      <a class="btn btn-sm btn-outline-secondary"
         href="<?= P::attr($rutas->url('tienda/pedidos') . ($todos ? '' : '?todos=1')) ?>">
        <?= $todos ? 'Ver solo los míos' : 'Ver los de todos' ?>
      </a>
      <?php endif; ?>
      <a class="btn btn-sm btn-primary" href="<?= P::attr($rutas->url('tienda')) ?>">
        <?= cm_icono('package', 15, 'mr-1') ?> Ir a la tienda
      </a>
    </div>
  </div>

  <?php if ($pedidos !== []): ?>
  <div class="card-body pb-2 pt-3">
    <div class="form-row align-items-end">
      <div class="form-group col-md-3 col-6 mb-2">
        <label class="small mb-1" for="f-anio">Año</label>
        <select class="form-control form-control-sm" id="f-anio">
          <option value="">Todos</option>
          <?php foreach (array_keys($anios) as $a): ?>
          <option value="<?= (int) $a ?>"><?= (int) $a ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group col-md-3 col-6 mb-2">
        <label class="small mb-1" for="f-mes">Mes</label>
        <select class="form-control form-control-sm" id="f-mes">
          <option value="">Todos</option>
          <?php foreach (array_keys($meses) as $m): ?>
          <option value="<?= (int) $m ?>"><?= P::e($MES[(int) $m]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group col-md-4 col-8 mb-2">
        <label class="small mb-1" for="f-estado">Estado</label>
        <select class="form-control form-control-sm" id="f-estado">
          <option value="">Todos</option>
          <?php foreach ($ESTADOS as $e): if (!isset($porEstado[$e])) { continue; } ?>
          <option value="<?= P::attr($e) ?>">
            <?= P::e($nombreEstado($e)) ?> (<?= (int) $porEstado[$e] ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group col-md-2 col-4 mb-2">
        <button type="button" class="btn btn-outline-secondary btn-sm btn-block" id="f-limpiar">
          Quitar
        </button>
      </div>
    </div>

    <small class="text-muted" id="f-rango" style="font-size:.78rem"></small>
  </div>
  <?php endif; ?>
</div>

<?php if ($pedidos === []): ?>
<div class="card">
  <div class="card-body">
    <div class="cm-vacio">
      <?= cm_icono('package', 40) ?>
      <p class="mb-0 mt-2">
        Todavía no hay pedidos.
        <a href="<?= P::attr($rutas->url('tienda')) ?>">Dese una vuelta por la tienda</a>.
      </p>
    </div>
  </div>
</div>
<?php else: ?>

<div id="lista-pedidos">
<?php foreach ($pedidos as $p):
  $t      = strtotime((string) $p['creado_en']);
  $estado = (string) $p['estado'];
  $porque = $explicacion($p);
  $muerto = $estado === 'anulado';
?>
<div class="card cm-pedido<?= $muerto ? ' cm-pedido--anulado' : '' ?>"
     data-anio="<?= date('Y', $t) ?>" data-mes="<?= (int) date('n', $t) ?>"
     data-estado="<?= P::attr($estado) ?>">
  <div class="card-body">
    <div class="cm-pedido__cab">
      <span class="cm-articulo__logo" style="background:<?= P::attr($p['color']) ?>">
        <?= P::e($p['iniciales']) ?>
      </span>

      <div class="flex-grow-1" style="min-width:0">
        <div class="font-weight-bold"><?= P::e($p['comercio']) ?></div>
        <small class="text-muted cm-mono"><?= P::e($p['folio']) ?></small>
        <?php if ($todos): ?>
        <small class="text-muted d-block"><?= P::e($p['cliente']) ?></small>
        <?php endif; ?>
      </div>

      <div class="text-right cm-nowrap">
        <div class="font-weight-bold" style="font-size:1.1rem">
          <?= P::e(P::money((int) $p['total_cent'])) ?>
        </div>
        <span class="badge badge-<?= $colorEstado($estado) ?>">
          <?= P::e($nombreEstado($estado)) ?>
        </span>
      </div>
    </div>

    <?php /* Por qué quedó así. Es lo que evita la llamada. */ ?>
    <?php if ($porque !== ''): ?>
    <div class="cm-pedido__porque cm-pedido__porque--<?= $muerto ? 'malo' : 'espera' ?>">
      <?= cm_icono($muerto ? 'warning' : 'clock', 15, 'mr-2') ?>
      <span><?= P::e($porque) ?></span>
    </div>
    <?php endif; ?>

    <div class="cm-pedido__lineas">
      <?php foreach ($p['lineas'] as $l): ?>
      <div class="cm-carrito__fila">
        <span><?= (int) $l['cantidad'] ?> × <?= P::e($l['nombre']) ?></span>
        <b><?= P::e(P::money((int) $l['precio_cent'] * (int) $l['cantidad'])) ?></b>
      </div>
      <?php endforeach; ?>

      <?php if ((int) $p['envio_cent'] > 0): ?>
      <div class="cm-carrito__fila text-muted">
        <span>Envío</span><b><?= P::e(P::money((int) $p['envio_cent'])) ?></b>
      </div>
      <?php endif; ?>
    </div>

    <div class="cm-pedido__pie">
      <small class="text-muted">
        <?= P::e(P::fecha((string) $p['creado_en'])) ?>
        <?php if (!empty($p['ultimos4'])): ?>
          · <?= P::e(strtoupper((string) $p['marca'])) ?> ····<?= P::e($p['ultimos4']) ?>
        <?php endif; ?>
        <?php if ((int) $p['cuotas'] > 0): ?>
          · <?= (int) $p['cuotas'] ?> cuotas sin intereses
        <?php endif; ?>
      </small>

      <!-- La referencia es la que sale en el estado de cuenta: con ella
           el cliente reconoce el cargo cuando lo ve en su tarjeta. -->
      <small class="text-muted cm-mono"><?= P::e($p['referencia']) ?></small>
    </div>

    <?php if (!empty($p['direccion'])): ?>
    <small class="text-muted d-block mt-1">
      Entrega en: <?= P::e($p['direccion']) ?>
    </small>
    <?php endif; ?>

    <?php /* Pagada con una tarjeta de la casa no deja asiento: un
             consumo no mueve el libro mayor hasta el corte. La
             constancia de esa compra es el estado de cuenta, y eso se
             le dice en lugar de ofrecer un botón que no llevaría a
             ninguna parte. */ ?>
    <?php if (!empty($p['enEstadoDeCuenta'])): ?>
    <small class="text-muted d-block mt-2" style="font-size:.78rem">
      <?= cm_icono('files', 13, 'mr-1') ?>
      Esta compra aparece como consumo en el estado de cuenta de su
      tarjeta ····<?= P::e($p['ultimos4'] ?? '') ?>, con la referencia
      <span class="cm-mono"><?= P::e($p['referencia']) ?></span>.
    </small>
    <?php endif; ?>

    <?php
    $gestionable = $puedeGestionar && $siguiente($estado) !== null;
    ?>
    <?php if ($p['comprobante'] !== null || $gestionable): ?>
    <div class="mt-3" style="display:flex;gap:.4rem;flex-wrap:wrap">

      <?php if ($p['comprobante'] !== null): ?>
      <a class="btn btn-sm btn-outline-secondary"
         href="<?= P::attr($rutas->url('comprobante/' . $p['comprobante'])) ?>"
         target="_blank" rel="noopener"
         data-cm-doc data-cm-doc-titulo="Preparando el comprobante"
         data-cm-doc-texto="Se abrirá en una pestaña nueva."
         title="Comprobante de la compra <?= P::attr($p['folio']) ?>">
        <?= cm_icono('files', 14, 'mr-1') ?>Ver comprobante
      </a>
      <?php endif; ?>

      <?php if ($gestionable && $siguiente($estado) === 'enviado'): ?>
      <?php /* Despachar no es apretar un botón: es decir quién lo lleva.
               Antes «enviado» se ponía a ciegas y al cliente le llegaba
               «va en camino» sin con quién ni con qué número rastrearlo,
               que es exactamente la llamada que después recibe la
               tienda. Aquí se pide al momento de despachar, porque es
               cuando se sabe. */ ?>
      <details class="tda-despacho">
        <summary class="btn btn-sm btn-primary">Despachar este pedido</summary>

        <form method="post" data-cm-ajax data-cm-recargar class="tda-despacho__form"
              action="<?= P::attr($rutas->url('tienda/pedidos/' . $p['codigo'] . '/estado')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="tienda">
          <input type="hidden" name="estado" value="enviado">

          <div class="form-row">
            <div class="form-group col-md-6">
              <label class="small mb-1" for="tr-<?= P::attr($p['codigo']) ?>">
                ¿Quién lo lleva? <span class="text-danger">*</span>
              </label>
              <input type="text" class="form-control form-control-sm" required
                     id="tr-<?= P::attr($p['codigo']) ?>" name="transportista" maxlength="120"
                     placeholder="Encomiendas, motorista propio, etc."
                     value="<?= P::attr((string) ($p['transportista'] ?? '')) ?>">
            </div>

            <div class="form-group col-md-6">
              <label class="small mb-1" for="gu-<?= P::attr($p['codigo']) ?>">
                Número de guía
              </label>
              <input type="text" class="form-control form-control-sm"
                     id="gu-<?= P::attr($p['codigo']) ?>" name="guia" maxlength="60"
                     placeholder="Si no hay, déjelo vacío"
                     value="<?= P::attr((string) ($p['guia'] ?? '')) ?>">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group col-md-5">
              <label class="small mb-1" for="fe-<?= P::attr($p['codigo']) ?>">
                Entrega estimada
              </label>
              <?php /* Es lo primero que pregunta quien compró, y lo
                       único que hasta ahora no se podía contestar. */ ?>
              <input type="date" class="form-control form-control-sm"
                     id="fe-<?= P::attr($p['codigo']) ?>" name="entrega_estimada"
                     min="<?= P::attr(date('Y-m-d')) ?>"
                     value="<?= P::attr((string) ($p['entrega_estimada'] ?? '')) ?>">
            </div>

            <div class="form-group col-md-7">
              <label class="small mb-1" for="no-<?= P::attr($p['codigo']) ?>">
                Nota para el cliente
              </label>
              <input type="text" class="form-control form-control-sm"
                     id="no-<?= P::attr($p['codigo']) ?>" name="nota_despacho" maxlength="300"
                     placeholder="«Entregamos de 8 a 12», por ejemplo"
                     value="<?= P::attr((string) ($p['nota_despacho'] ?? '')) ?>">
            </div>
          </div>

          <button class="btn btn-sm btn-primary">
            Marcar como enviado y avisarle
          </button>
        </form>
      </details>

      <?php elseif ($gestionable): ?>
      <form method="post" data-cm-ajax data-cm-recargar style="display:inline"
            action="<?= P::attr($rutas->url('tienda/pedidos/' . $p['codigo'] . '/estado')) ?>">
        <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
        <input type="hidden" name="_csrf_proposito" value="tienda">
        <input type="hidden" name="estado" value="<?= P::attr($siguiente($estado)) ?>">
        <button class="btn btn-sm btn-primary">
          Marcar como <?= P::e($nombreEstado($siguiente($estado))) ?>
        </button>
      </form>
      <?php endif; ?>

      <?php /* Anular se puede siempre que el pedido sea gestionable, en
               el paso que sea: por eso vuelve a abrirse la condición en
               vez de quedar colgando del `elseif` de arriba. */ ?>
      <?php if ($gestionable): ?>

      <form method="post" data-cm-ajax data-cm-recargar style="display:inline"
            action="<?= P::attr($rutas->url('tienda/pedidos/' . $p['codigo'] . '/estado')) ?>">
        <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
        <input type="hidden" name="_csrf_proposito" value="tienda">
        <input type="hidden" name="estado" value="anulado">
        <button class="btn btn-sm btn-outline-danger" data-cm-peligro
                data-cm-confirmar="Se anulará el pedido <?= P::attr($p['folio']) ?> y se avisará al cliente. ¿Continuar?">
          Anular
        </button>
      </form>
      <?php endif; ?>

    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>

<div class="card" id="p-vacio" hidden>
  <div class="card-body">
    <div class="cm-vacio">
      <p class="mb-0">No hay pedidos con ese filtro.</p>
    </div>
  </div>
</div>

<nav class="d-flex justify-content-center mb-4" id="p-pie" hidden
     aria-label="Páginas de pedidos">
  <ul class="pagination pagination-sm mb-0" id="p-paginas"></ul>
</nav>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var lista = document.getElementById('lista-pedidos');
  if (!lista) { return; }

  var POR_PAGINA = 10;

  var tarjetas = Array.prototype.slice.call(lista.querySelectorAll('.cm-pedido'));
  var fAnio   = document.getElementById('f-anio');
  var fMes    = document.getElementById('f-mes');
  var fEstado = document.getElementById('f-estado');
  var rango   = document.getElementById('f-rango');
  var vacio   = document.getElementById('p-vacio');
  var pie     = document.getElementById('p-pie');
  var paginas = document.getElementById('p-paginas');
  var limpiar = document.getElementById('f-limpiar');

  var pagina = 1;

  function visibles() {
    var anio   = fAnio ? fAnio.value : '';
    var mes    = fMes ? fMes.value : '';
    var estado = fEstado ? fEstado.value : '';

    return tarjetas.filter(function (c) {
      return (anio === ''   || c.getAttribute('data-anio')   === anio)
          && (mes === ''    || c.getAttribute('data-mes')    === mes)
          && (estado === '' || c.getAttribute('data-estado') === estado);
    });
  }

  function pintar() {
    var hay   = visibles();
    var total = Math.max(1, Math.ceil(hay.length / POR_PAGINA));

    if (pagina > total) { pagina = total; }
    if (pagina < 1) { pagina = 1; }

    var desde = (pagina - 1) * POR_PAGINA;
    var hasta = desde + POR_PAGINA;

    tarjetas.forEach(function (c) { c.hidden = true; });
    hay.slice(desde, hasta).forEach(function (c) { c.hidden = false; });

    if (vacio) { vacio.hidden = hay.length > 0; }

    if (rango) {
      rango.textContent = hay.length === 0
        ? 'Sin resultados'
        : 'Mostrando ' + (desde + 1) + '–' + Math.min(hasta, hay.length)
          + ' de ' + hay.length;
    }

    pintarPaginas(total, hay.length);
  }

  function pintarPaginas(total, cuantos) {
    if (!paginas || !pie) { return; }

    paginas.innerHTML = '';
    pie.hidden = cuantos <= POR_PAGINA;

    if (pie.hidden) { return; }

    /* Una ventana alrededor de la página actual: con veinte páginas,
       listarlas todas ocupa más que los propios pedidos. */
    var desde = Math.max(1, pagina - 2);
    var hasta = Math.min(total, desde + 4);
    desde = Math.max(1, hasta - 4);

    agregar('‹', pagina - 1, pagina === 1, false, 'Página anterior');

    if (desde > 1) { agregar('1', 1, false, pagina === 1); }
    if (desde > 2) { agregar('…', 0, true, false); }

    for (var i = desde; i <= hasta; i++) {
      agregar(String(i), i, false, i === pagina);
    }

    if (hasta < total - 1) { agregar('…', 0, true, false); }
    if (hasta < total) { agregar(String(total), total, false, pagina === total); }

    agregar('›', pagina + 1, pagina === total, false, 'Página siguiente');
  }

  function agregar(texto, ir, inerte, activa, etiqueta) {
    var li = document.createElement('li');
    li.className = 'page-item' + (inerte ? ' disabled' : '') + (activa ? ' active' : '');

    var a = document.createElement('a');
    a.className = 'page-link';
    a.href = '#';
    a.textContent = texto;

    if (etiqueta) { a.setAttribute('aria-label', etiqueta); }
    if (activa) { a.setAttribute('aria-current', 'page'); }

    if (!inerte) {
      a.addEventListener('click', function (ev) {
        ev.preventDefault();
        pagina = ir;
        pintar();
        // Al cambiar de página se vuelve arriba: si no, se aterriza
        // a media lista sin saber que cambió.
        lista.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }

    li.appendChild(a);
    paginas.appendChild(li);
  }

  [fAnio, fMes, fEstado].forEach(function (s) {
    if (s) { s.addEventListener('change', function () { pagina = 1; pintar(); }); }
  });

  if (limpiar) {
    limpiar.addEventListener('click', function () {
      if (fAnio) { fAnio.value = ''; }
      if (fMes) { fMes.value = ''; }
      if (fEstado) { fEstado.value = ''; }
      pagina = 1;
      pintar();
    });
  }

  pintar();
})();
</script>

<?php endif; ?>
