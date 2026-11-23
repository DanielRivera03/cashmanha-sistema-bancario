<?php
/**
 * Pagar con tarjeta a través de la pasarela.
 *
 * Son dos pasos a propósito: primero el sistema calcula cuánto se debe
 * y prepara el cobro, y solo después se piden los datos de la tarjeta.
 * El importe NUNCA lo decide esta pantalla —se calcula en el servidor a
 * partir de lo que de verdad se adeuda—, porque si viniera del
 * formulario bastaría con editarlo para pagar un dólar por una cuota de
 * mil.
 *
 * Los datos de la tarjeta no pasan por este servidor en ningún momento:
 * el campo lo dibuja la procesadora dentro de un marco propio y lo que
 * vuelve es un secreto de un solo uso. Aquí nunca hay un número de
 * tarjeta que guardar ni que filtrar.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var bool   $activa
 * @var bool   $esPrueba
 * @var string $publica
 * @var array  $cuotas
 * @var array  $tarjetas
 * @var array  $cuentas
 * @var array  $historial
 * @var string $csrf
 * @var string $preConcepto
 * @var string $preCuota
 * @var string $preTarjeta
 */
use CashMan\Http\Plantilla as P;

/* El adelanto de varias cuotas, cuando el cliente llega desde la
   tabla de amortización habiendo seleccionado más de una. */
$adelanto = $adelanto ?? null;

$hayAlgoQuePagar = $cuotas !== [] || $tarjetas !== [] || $cuentas !== [];

$colorEstado = static fn(string $e): string => match ($e) {
    'confirmado', 'exitoso' => 'success',
    'pendiente', 'procesando' => 'warning',
    'fallido', 'rechazado'    => 'danger',
    'reversado'               => 'secondary',
    default                   => 'light',
};
?>

<?php if (!$activa): ?>
<div class="alert alert-warning">
  <strong>El pago con tarjeta no está disponible en este momento.</strong>
  Con mucho gusto puede pagar debitando de su cuenta, o acercarse a cualquiera
  de nuestras agencias. Lamentamos la molestia.
</div>
<?php else: ?>

<?php if ($esPrueba): ?>
<!-- Que se vea desde fuera que esto es el entorno de pruebas: un cobro
     de verdad y uno de prueba se parecen demasiado en pantalla. -->
<div class="alert alert-info py-2" style="font-size:.84rem">
  <strong>Entorno de pruebas.</strong> No se cobra dinero real. Puede usar
  <span class="cm-mono">4242 4242 4242 4242</span> (Visa),
  <span class="cm-mono">5555 5555 5555 4444</span> (Mastercard) o
  <span class="cm-mono">3782 822463 10005</span> (American Express),
  con cualquier fecha futura y cualquier CVC.
</div>
<?php endif; ?>

<div class="row">
  <div class="col-lg-7 col-md-12 mb-4">
    <div class="card mb-0">
      <div class="card-header">
        <h4 class="card-title mb-0">¿Qué desea pagar?</h4>
      </div>
      <div class="card-body">
        <?php if (!$hayAlgoQuePagar): ?>
          <div class="cm-vacio">
            <p class="mb-0">No tiene nada pendiente de pago. Muchas gracias por estar al día.</p>
          </div>
        <?php else: ?>

        <!-- ---------- PASO 1: QUÉ SE PAGA ---------- -->
        <form id="form-pasarela">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="pasarela">

          <div class="form-group">
            <label for="concepto">Concepto</label>
            <select class="form-control" id="concepto" name="concepto">
              <?php
                /* El concepto puede venir ya elegido desde otra pantalla.
                   Si el cliente pulsó «Pagar con tarjeta» en una cuota
                   concreta, hacerle buscarla aquí otra vez es pedirle que
                   repita lo que acaba de decir. */
              ?>
              <?php if ($cuotas !== []): ?>
              <option value="cuota_credito"<?= $preConcepto === 'cuota_credito' ? ' selected' : '' ?>>
                Cuota de crédito
              </option>
              <?php endif; ?>
              <?php if ($tarjetas !== []): ?>
              <option value="saldo_tarjeta"<?= $preConcepto === 'saldo_tarjeta' ? ' selected' : '' ?>>
                Saldo de tarjeta de crédito
              </option>
              <?php endif; ?>
              <?php if ($cuentas !== []): ?>
              <option value="deposito">Depósito a mi cuenta</option>
              <?php endif; ?>
            </select>
          </div>

          <!-- ---------- Cuotas de crédito ---------- -->

          <?php /* ---------------------------------------------------------
               El adelanto de varias cuotas.

               Antes esta pantalla enseñaba un desplegable con UNA cuota
               aunque el cliente hubiera seleccionado doce: veía
               «cuota 10 · $12,017.73» después de haber pedido doce por
               $20,247.99, y nada le avisaba de que iba a pagar otra
               cosa.

               Cuando viene un adelanto se enseña el desglose entero y
               se esconde el desplegable: no hay nada que elegir, ya
               eligió.
               --------------------------------------------------------- */ ?>
          <?php if ($adelanto !== null): ?>
          <div class="form-group cm-bloque" data-concepto="cuota_credito"
               <?= $preConcepto !== '' && $preConcepto !== 'cuota_credito' ? 'hidden' : '' ?>>

            <input type="hidden" name="credito" value="<?= P::attr($adelanto['credito_codigo']) ?>">
            <input type="hidden" name="cuantas" value="<?= (int) $adelanto['cuantas'] ?>">

            <div class="cm-adelanto-resumen">
              <div class="cm-adelanto-resumen__cab">
                <div>
                  <strong>
                    Adelanto de <?= (int) $adelanto['cuantas'] ?> cuota(s)
                  </strong>
                  <small>
                    <?= P::e($adelanto['producto']) ?> ·
                    <span class="cm-mono"><?= P::e($adelanto['folio']) ?></span> ·
                    de la <?= (int) $adelanto['desde'] ?> a la <?= (int) $adelanto['hasta'] ?>
                  </small>
                </div>
                <div class="text-right">
                  <small>Total a pagar</small>
                  <strong><?= P::e(P::money((int) $adelanto['total_cent'])) ?></strong>
                </div>
              </div>

              <ul class="cm-adelanto-resumen__lista">
                <?php foreach ($adelanto['cuotas'] as $q): ?>
                <li>
                  <span>Cuota <?= (int) $q['numero'] ?></span>
                  <span class="cm-adelanto-resumen__fecha">
                    vence <?= P::e(P::fecha((string) $q['vence'], 'd/m/Y')) ?>
                    <?= $q['vencida'] ? '· vencida' : '' ?>
                  </span>
                  <span class="cm-adelanto-resumen__monto">
                    <?= P::e(P::money((int) $q['total_cent'])) ?>
                    <?php if ((int) $q['mora_cent'] > 0): ?>
                      <i>incluye <?= P::e(P::money((int) $q['mora_cent'])) ?> de mora</i>
                    <?php endif; ?>
                  </span>
                </li>
                <?php endforeach; ?>
              </ul>
            </div>

            <small class="form-text text-muted">
              Se cobran desde la más antigua hacia adelante, sin dejar ninguna
              por el camino. Si el cobro no alcanzara para la última, queda
              abonada en parte y se le dice cuánto falta.
            </small>
          </div>
          <?php endif; ?>

          <?php if ($cuotas !== [] && $adelanto === null): ?>
          <div class="form-group cm-bloque" data-concepto="cuota_credito"
               <?= $preConcepto !== '' && $preConcepto !== 'cuota_credito' ? 'hidden' : '' ?>>
            <label for="cuota">Cuota por pagar</label>
            <select class="form-control" id="cuota" name="cuota">
              <?php foreach ($cuotas as $q): ?>
              <option value="<?= P::attr($q['codigo']) ?>"
                      data-importe="<?= (int) $q['exigible_cent'] ?>"
                      data-numero="<?= (int) $q['numero'] ?>"
                      data-folio="<?= P::attr($q['folio']) ?>"
                      data-producto="<?= P::attr($q['producto']) ?>"
                      data-vence="<?= P::attr(P::fecha((string) $q['fecha_vencimiento'], 'd/m/Y')) ?>"
                      data-vencida="<?= $q['vencida'] ? '1' : '0' ?>"
                      data-capital="<?= (int) $q['capital_cent'] ?>"
                      data-interes="<?= (int) $q['interes_cent'] ?>"
                      data-seguros="<?= (int) $q['seguros_cent'] ?>"
                      data-mora="<?= (int) $q['mora_viva_cent'] ?>"
                      data-pendiente="<?= (int) $q['pendiente_cent'] ?>"
                      data-exigible="<?= (int) $q['exigible_cent'] ?>"
                      <?= $preCuota === $q['codigo'] ? 'selected' : '' ?>>
                <?= P::e($q['folio']) ?> · cuota <?= (int) $q['numero'] ?>
                · vence <?= P::e(P::fecha((string) $q['fecha_vencimiento'], 'd/m/Y')) ?>
                · <?= P::e(P::money((int) $q['exigible_cent'])) ?>
                <?= $q['vencida'] ? ' (vencida)' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>

            <?php /* El detalle de la cuota elegida, como el del adelanto.
                     Antes esta pantalla solo enseñaba una línea en el
                     desplegable y el cliente ponía su tarjeta sin ver de
                     qué se componía lo que iba a pagar. Se arma desde los
                     datos de la opción seleccionada (lo pinta el script de
                     abajo) para que siempre refleje la que está elegida. */ ?>
            <div id="cuota-detalle" class="cm-adelanto-resumen mt-3" hidden></div>

            <small class="form-text text-muted">
              El importe lo calcula el sistema con lo que de verdad se debe, con su
              mora si la cuota está vencida. Se aceptan Visa, Mastercard y American
              Express, de esta institución o de cualquier otro banco.
            </small>
          </div>
          <?php endif; ?>

          <!-- ---------- Saldo de tarjeta ---------- -->
          <?php if ($tarjetas !== []): ?>
          <div class="form-group cm-bloque" data-concepto="saldo_tarjeta"
               <?= $preConcepto === 'saldo_tarjeta' ? '' : 'hidden' ?>>
            <label for="tarjeta">Tarjeta por pagar</label>
            <select class="form-control" id="tarjeta" name="tarjeta">
              <?php foreach ($tarjetas as $t): ?>
              <option value="<?= P::attr($t['codigo']) ?>"
                      data-importe="<?= (int) $t['saldo_usado_cent'] ?>"
                      <?= $preTarjeta === $t['codigo'] ? 'selected' : '' ?>>
                <?= P::e(strtoupper((string) $t['marca'])) ?> ····<?= P::e($t['ultimos4']) ?>
                · saldo <?= P::e(P::money((int) $t['saldo_usado_cent'])) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small class="form-text text-muted">
              Se paga el saldo completo de la tarjeta.
            </small>
          </div>
          <?php endif; ?>

          <!-- ---------- Depósito a cuenta ---------- -->
          <?php if ($cuentas !== []): ?>
          <div class="cm-bloque" data-concepto="deposito" hidden>
            <div class="form-group">
              <label for="cuenta">Cuenta de destino</label>
              <select class="form-control" id="cuenta" name="cuenta">
                <?php foreach ($cuentas as $c): ?>
                <option value="<?= P::attr($c['codigo']) ?>">
                  <?= P::e($c['numero_cuenta']) ?> · <?= P::e($c['producto']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="monto-pasarela">Monto a depositar</label>
              <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" id="monto-pasarela" name="monto"
                       inputmode="decimal" placeholder="0.00">
              </div>
              <small class="form-text text-muted">
                Es el único concepto en el que usted elige el monto: en los demás, el
                importe sale de lo que se adeuda.
              </small>
            </div>
          </div>
          <?php endif; ?>

          <button type="submit" class="btn btn-primary">
            <?= cm_icono('card', 15, 'mr-1') ?> Continuar
          </button>
        </form>

        <!-- ---------- PASO 2: LA TARJETA ---------- -->
        <div id="paso-tarjeta" hidden>
          <div class="alert alert-light border mb-3">
            <div class="d-flex justify-content-between align-items-center">
              <span>Total a cobrar</span>
              <strong id="importe-final" style="font-size:1.3rem">—</strong>
            </div>
          </div>

          <div class="form-group">
            <label>Datos de su tarjeta</label>
            <!-- Este recuadro lo dibuja la procesadora: el número no
                 pasa por nuestro servidor en ningún momento. -->
            <div id="tarjeta-elemento" class="cm-stripe-caja"></div>
            <div id="tarjeta-error" class="text-danger mt-2" style="font-size:.84rem"></div>
          </div>

          <div class="d-flex" style="gap:.5rem">
            <button type="button" class="btn btn-outline-secondary" id="btn-volver">
              Atrás
            </button>
            <button type="button" class="btn btn-primary flex-grow-1" id="btn-pagar">
              Pagar ahora
            </button>
          </div>

          <small class="d-block text-muted mt-3" style="font-size:.76rem">
            El cobro se procesa de forma cifrada por la procesadora. CashMan H.A. no
            almacena en ningún momento el número de su tarjeta ni su código de
            seguridad.
          </small>
        </div>

        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ---------- HISTORIAL ---------- -->
  <div class="col-lg-5 col-md-12 mb-4">
    <div class="card h-100 mb-0">
      <div class="card-header">
        <h4 class="card-title mb-0">Sus pagos con tarjeta</h4>
        <small class="text-muted d-block mt-1" style="font-size:.76rem">
          <?= count($historial) ?> operación(es) registradas
        </small>
      </div>

      <div class="card-body p-0">
        <?php if ($historial === []): ?>
          <div class="cm-vacio">
            <?= cm_icono('card', 36) ?>
            <p class="mb-0 mt-2">Todavía no hay pagos con tarjeta.</p>
          </div>
        <?php else: ?>

        <?php
        /* Los periodos que de verdad tienen pagos.
           Un desplegable con los doce meses ofrece once puertas a
           listas vacías: aquí solo salen los que existen. */
        $aniosPago = [];
        $mesesPago = [];

        foreach ($historial as $h) {
            $t = strtotime((string) $h['creado_en']);
            $aniosPago[date('Y', $t)] = true;
            $mesesPago[(int) date('n', $t)] = true;
        }

        krsort($aniosPago);
        ksort($mesesPago);

        $MES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
        ?>

        <div class="px-3 pt-3 pb-2 border-bottom">
          <div class="form-row">
            <div class="form-group col-5 mb-2">
              <label class="small mb-1" for="h-anio">Año</label>
              <select class="form-control form-control-sm" id="h-anio">
                <option value="">Todos</option>
                <?php foreach (array_keys($aniosPago) as $a): ?>
                <option value="<?= (int) $a ?>"><?= (int) $a ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-7 mb-2">
              <label class="small mb-1" for="h-mes">Mes</label>
              <select class="form-control form-control-sm" id="h-mes">
                <option value="">Todos</option>
                <?php foreach (array_keys($mesesPago) as $m): ?>
                <option value="<?= (int) $m ?>"><?= P::e($MES[(int) $m]) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="d-flex justify-content-between align-items-center">
            <small class="text-muted" id="h-rango" style="font-size:.74rem"></small>
            <button type="button" class="btn btn-link btn-sm p-0" id="h-limpiar"
                    style="font-size:.76rem">Quitar filtro</button>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0" id="tabla-pagos">
            <thead>
              <tr><th>Concepto</th><th class="cm-num">Importe</th><th>Estado</th></tr>
            </thead>
            <tbody>
            <?php foreach ($historial as $h): $t = strtotime((string) $h['creado_en']); ?>
              <tr data-anio="<?= date('Y', $t) ?>" data-mes="<?= (int) date('n', $t) ?>">
                <td style="font-size:.82rem">
                  <?= P::e(ucfirst(str_replace('_', ' ', (string) $h['concepto']))) ?>
                  <small class="text-muted d-block cm-mono" style="font-size:.7rem">
                    <?= P::e($h['referencia'] ?? '') ?>
                  </small>
                  <small class="text-muted d-block" style="font-size:.7rem">
                    <?= P::e(P::fecha((string) $h['creado_en'], 'd/m/Y H:i')) ?>
                  </small>
                </td>
                <td class="cm-num cm-mono"><?= P::e(P::money((int) $h['importe_cent'])) ?></td>
                <td>
                  <span class="badge badge-<?= $colorEstado((string) $h['estado']) ?>">
                    <?= P::e($h['estado']) ?>
                  </span>
                  <?php if (!empty($h['detalle_error'])): ?>
                  <small class="d-block text-muted" style="font-size:.68rem;line-height:1.3">
                    <?= P::e($h['detalle_error']) ?>
                  </small>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <div class="cm-vacio py-4" id="h-vacio" hidden>
            <p class="mb-0" style="font-size:.86rem">No hay pagos en ese periodo.</p>
          </div>
        </div>

        <div class="card-footer d-flex justify-content-center py-2" id="h-pie" hidden>
          <nav aria-label="Páginas de sus pagos">
            <ul class="pagination pagination-sm mb-0" id="h-paginas"></ul>
          </nav>
        </div>

        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://js.stripe.com/v3/"></script>
<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var form = document.getElementById('form-pasarela');
  if (!form) { return; }

  /* Los bloques que se enseñan según el concepto.
     Sin esto, el formulario pediría a la vez la cuota, la tarjeta y la
     cuenta, y tres de cada cuatro campos sobrarían siempre. */
  var concepto = document.getElementById('concepto');
  var bloques = document.querySelectorAll('.cm-bloque');

  function mostrarBloques() {
    var v = concepto ? concepto.value : '';

    bloques.forEach(function (b) {
      var suyo = b.getAttribute('data-concepto') === v;
      b.hidden = !suyo;

      // Un campo oculto no debe enviarse: el servidor decide el importe
      // por el concepto, y un valor de más solo confunde.
      b.querySelectorAll('input, select').forEach(function (c) {
        c.disabled = !suyo;
      });
    });
  }

  if (concepto) {
    concepto.addEventListener('change', mostrarBloques);
    mostrarBloques();
  }

  /* El detalle de la cuota elegida: capital, interés, seguros y la mora
     viva si está vencida, con el total que DE VERDAD se cobrará. Se arma
     desde la opción seleccionada del desplegable y se vuelve a pintar al
     cambiarla. Corre aunque la procesadora no cargue —es información, no
     cobro—, para que el cliente nunca ponga su tarjeta sin ver de qué se
     compone lo que paga. */
  (function detalleCuota() {
    var sel  = document.getElementById('cuota');
    var caja = document.getElementById('cuota-detalle');
    if (!sel || !caja || !window.CMUtil) { return; }

    function fila(etq, cent, total) {
      if (cent <= 0 && !total) { return ''; }
      var e = total ? '<strong>' + etq + '</strong>' : etq;
      var m = total ? '<strong>' + window.CMUtil.money(cent) + '</strong>'
                    : window.CMUtil.money(cent);
      return '<li><span class="cm-adelanto-resumen__fecha">' + e + '</span>'
           + '<span class="cm-adelanto-resumen__monto">' + m + '</span></li>';
    }

    function pintar() {
      var o = sel.options[sel.selectedIndex];
      if (!o || !o.dataset.numero) { caja.hidden = true; caja.innerHTML = ''; return; }

      var d = o.dataset;
      var vencida  = d.vencida === '1';
      var exigible = parseInt(d.exigible, 10) || 0;

      caja.innerHTML =
          '<div class="cm-adelanto-resumen__cab">'
        +   '<div><strong>Cuota ' + d.numero + '</strong>'
        +     '<small>' + d.producto + ' · <span class="cm-mono">' + d.folio + '</span>'
        +       ' · vence ' + d.vence + (vencida ? ' · vencida' : '') + '</small></div>'
        +   '<div class="text-right"><small>Total a pagar</small>'
        +     '<strong>' + window.CMUtil.money(exigible) + '</strong></div>'
        + '</div>'
        + '<ul class="cm-adelanto-resumen__lista">'
        +   fila('Capital',  parseInt(d.capital, 10) || 0)
        +   fila('Interés',  parseInt(d.interes, 10) || 0)
        +   fila('Seguros',  parseInt(d.seguros, 10) || 0)
        +   (vencida ? fila('Mora al día de hoy', parseInt(d.mora, 10) || 0) : '')
        +   fila('Total exigible', exigible, true)
        + '</ul>';

      caja.hidden = false;
    }

    sel.addEventListener('change', pintar);
    pintar();
  })();

  if (typeof Stripe === 'undefined') {
    /* Si la librería de la procesadora no cargó (sin conexión, un
       bloqueador, un CSP ajeno…), el botón no debe hacer un envío nativo
       que recarga la página en silencio y deja la sensación de que «no
       hizo nada». Se intercepta y se avisa. */
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (window.CMAviso) {
        window.CMAviso.error(
          'Pago con tarjeta no disponible',
          'No se pudo cargar el componente seguro de la procesadora. Revise su '
          + 'conexión e intente de nuevo en un momento.'
        );
      }
    });
    return;
  }

  var URL_INICIAR   = <?= P::js($rutas->url('pagar/iniciar')) ?>;
  var URL_CONFIRMAR = <?= P::js($rutas->url('pagar/confirmar')) ?>;
  var pasoTar  = document.getElementById('paso-tarjeta');
  var btnPagar = document.getElementById('btn-pagar');
  var btnAtras = document.getElementById('btn-volver');
  var cajaErr  = document.getElementById('tarjeta-error');

  var stripe   = Stripe(<?= P::js($publica) ?>);
  var elements = stripe.elements();
  var campo    = elements.create('card', {
    style: { base: { fontSize: '15px', color: '#16181d', fontFamily: 'Poppins, sans-serif' } },
    // El codigo postal es un dato estadounidense que aqui no
    // significa nada: el cliente no sabe que poner y el cobro
    // se le rechaza. Link abre un dialogo pidiendo correo para
    // crear cuenta en un tercero antes de dejarle pagar.
    hidePostalCode: true,
    disableLink: true
  });

  var montado = false;
  var secreto = null;

  /* El token con el que se cerrará el cobro.
     El del formulario ya se gastó al preparar el intento —son de un
     solo uso—, así que el servidor devuelve el reemplazo en la misma
     respuesta y es ese el que vale para confirmar. */
  var csrfVivo = <?= P::js($csrf) ?>;

  // --- Paso 1: preparar el cobro ------------------------------------
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();

    var boton = form.querySelector('[type="submit"]');
    if (boton) { boton.disabled = true; boton.classList.add('cm-ocupado'); }

    /* Preparar el cobro habla con la procesadora, y eso tarda lo suyo.
       Sin señal en pantalla el usuario cree que el botón no respondió
       y vuelve a pulsar: dos intentos de cobro preparados para la
       misma deuda. */
    var finPreparar = window.CMAviso.esperar({
      titulo:  'Preparando el cobro',
      mensaje: 'Estamos reservando la operación con la procesadora.',
      icono:   'tarjeta',
      tope:    30000
    });

    /* Corte duro de la petición. Si el servidor no contesta a tiempo
       —por lo que sea—, se aborta en vez de dejar el velo oscuro puesto
       para siempre: una pantalla bloqueada sin salida es lo peor que
       puede pasarle aquí al usuario. Da margen de sobra (el cobro suele
       prepararse en menos de un segundo). */
    var aborto = ('AbortController' in window) ? new AbortController() : null;
    var corte  = setTimeout(function () {
      if (aborto) { aborto.abort(); }
    }, 28000);

    fetch(URL_INICIAR, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      signal: aborto ? aborto.signal : undefined,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) {
        // Una respuesta que no es JSON significa que algo se rompió en
        // el servidor, no que no haya conexión: decirlo bien ahorra
        // media hora de buscar en el sitio equivocado.
        return r.text().then(function (texto) {
          try {
            return JSON.parse(texto);
          } catch (err) {
            if (window.console) {
              console.error('[CashMan] Respuesta no JSON de ' + URL_INICIAR
                + ' (HTTP ' + r.status + '):', texto.slice(0, 2000));
            }
            return {
              ok: false,
              mensaje: 'El servidor respondió algo que no se pudo interpretar '
                + '(HTTP ' + r.status + '). No se realizó ningún cobro.'
            };
          }
        });
      })
      .then(function (j) {
        finPreparar();
        if (boton) { boton.disabled = false; boton.classList.remove('cm-ocupado'); }

        if (!j.ok) {
          window.CMAviso.error(
            'No se pudo preparar el cobro',
            j.mensaje || 'Vuelva a intentarlo en un momento.'
          );
          return;
        }

        secreto = j.datos.secreto_cliente;
        // El relevo viaja en la raíz de la respuesta, junto a `ok`, no
        // dentro de `datos`: ahí es donde lo pone el servidor.
        if (j.csrf) { csrfVivo = j.csrf; }
        document.getElementById('importe-final').textContent = j.datos.importe_formateado;

        form.hidden    = true;
        pasoTar.hidden = false;

        if (!montado) { campo.mount('#tarjeta-elemento'); montado = true; }
      })
      .catch(function (err) {
        finPreparar();
        if (boton) { boton.disabled = false; boton.classList.remove('cm-ocupado'); }

        if (err && err.name === 'AbortError') {
          window.CMAviso.error(
            'El cobro tardó demasiado',
            'No se recibió respuesta a tiempo, así que se canceló el intento '
            + 'para no dejar la pantalla bloqueada. No se realizó ningún cobro; '
            + 'vuelva a intentarlo. Si se repite, cierre las demás pestañas del '
            + 'sistema y recargue esta página.'
          );
          return;
        }

        if (window.console) { console.error('[CashMan] La petición no llegó:', err); }
        window.CMAviso.error(
          'Sin respuesta del servidor',
          'No se realizó ningún cobro. Revise su conexión e inténtelo de nuevo.'
        );
      })
      .then(function () { clearTimeout(corte); });
  });

  if (btnAtras) {
    btnAtras.addEventListener('click', function () {
      pasoTar.hidden = true;
      form.hidden = false;
    });
  }

  // --- Paso 2: cobrar -----------------------------------------------
  if (btnPagar) {
    btnPagar.addEventListener('click', function () {
      if (!secreto) { return; }

      btnPagar.disabled = true;
      btnPagar.classList.add('cm-ocupado');
      cajaErr.textContent = '';

      /* Aquí sí hay dinero de por medio, y el velo tapa la pantalla a
         propósito: mientras el cobro está a medias no debería poder
         tocarse nada, ni volver atrás, ni cambiar el importe. */
      var finCobro = window.CMAviso.esperar({
        titulo:  'Procesando su pago',
        mensaje: 'Estamos autorizando el cobro con su banco. No cierre esta ventana.',
        icono:   'tarjeta',
        pie:     'Puede tardar unos segundos.',
        tope:    90000
      });

      stripe.confirmCardPayment(secreto, { payment_method: { card: campo } })
        .then(function (res) {
          btnPagar.disabled = false;
          btnPagar.classList.remove('cm-ocupado');

          if (res.error) {
            finCobro();
            cajaErr.textContent = res.error.message;
            return;
          }

          if (res.paymentIntent && res.paymentIntent.status === 'succeeded') {
            /* La pasarela ya cobró; ahora hay que APLICARLO.
             *
             * Esto faltaba. Se avisaba «se reflejará cuando la
             * procesadora lo confirme» y se dejaba al webhook, que en
             * una instalación sin dirección pública no llega nunca: el
             * cobro se quedaba en «procesando» para siempre y la cuota
             * seguía debiéndose.
             *
             * El servidor no se fía de esta llamada: vuelve a
             * preguntarle a Stripe por el intento y solo aplica si la
             * propia pasarela dice que está cobrado. */
            btnPagar.disabled = true;
            btnPagar.classList.add('cm-ocupado');

            // El cobro ya salió de su tarjeta; ahora se aplica.
            finCobro();

            var finAplicar = window.CMAviso.esperar({
              titulo:  'Aplicando su pago',
              mensaje: 'El cobro se autorizó. Estamos registrándolo en su cuenta.',
              icono:   'reloj',
              tope:    60000
            });

            var cierre = new FormData();
            cierre.append('_csrf', csrfVivo);
            cierre.append('_csrf_proposito', 'pasarela');
            cierre.append('intento', res.paymentIntent.id);

            fetch(URL_CONFIRMAR, {
              method: 'POST',
              body: cierre,
              credentials: 'same-origin',
              headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            })
              .then(function (r) { return r.json(); })
              .then(function (j) {
                finAplicar();

                window.CMAviso[j.ok ? 'exito' : 'alerta'](
                  j.ok ? 'Pago aplicado' : 'Pago recibido',
                  j.mensaje || 'Su pago se registró correctamente.'
                );

                setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1800);
              })
              .catch(function () {
                finAplicar();

                window.CMAviso.alerta(
                  'Pago recibido',
                  'El cobro se realizó. Si no lo ve aplicado en unos minutos, '
                  + 'comuníquese con nosotros indicando la referencia '
                  + res.paymentIntent.id + '.'
                );
              });
          } else {
            finCobro();

            window.CMAviso.info(
              'Pago en proceso',
              'Su banco pidió un paso más. Le avisaremos en cuanto se confirme.'
            );
          }
        })
        .catch(function () {
          finCobro();
          btnPagar.disabled = false;
          btnPagar.classList.remove('cm-ocupado');
          cajaErr.textContent = 'No se pudo completar el pago. Intente de nuevo.';
        });
    });
  }
})();
</script>

<?php /* El historial: filtrar por periodo y pasar de diez en diez.
         Con la lista entera a la vista, el pago de hace dos meses
         quedaba a seis pantallazos de distancia y había que bajar a
         buscarlo. */ ?>
<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var tabla = document.getElementById('tabla-pagos');
  if (!tabla) { return; }

  var POR_PAGINA = 10;

  var filas   = Array.prototype.slice.call(tabla.querySelectorAll('tbody tr'));
  var selAnio = document.getElementById('h-anio');
  var selMes  = document.getElementById('h-mes');
  var rango   = document.getElementById('h-rango');
  var vacio   = document.getElementById('h-vacio');
  var pie     = document.getElementById('h-pie');
  var paginas = document.getElementById('h-paginas');
  var limpiar = document.getElementById('h-limpiar');

  var pagina = 1;

  function visibles() {
    var anio = selAnio ? selAnio.value : '';
    var mes  = selMes ? selMes.value : '';

    return filas.filter(function (f) {
      return (anio === '' || f.getAttribute('data-anio') === anio)
          && (mes === ''  || f.getAttribute('data-mes')  === mes);
    });
  }

  function pintar() {
    var hay    = visibles();
    var total  = Math.max(1, Math.ceil(hay.length / POR_PAGINA));

    if (pagina > total) { pagina = total; }
    if (pagina < 1) { pagina = 1; }

    var desde = (pagina - 1) * POR_PAGINA;
    var hasta = desde + POR_PAGINA;

    filas.forEach(function (f) { f.hidden = true; });
    hay.slice(desde, hasta).forEach(function (f) { f.hidden = false; });

    if (vacio) { vacio.hidden = hay.length > 0; }
    if (tabla) { tabla.hidden = hay.length === 0; }

    if (rango) {
      rango.textContent = hay.length === 0
        ? 'Sin resultados'
        : (desde + 1) + '–' + Math.min(hasta, hay.length) + ' de ' + hay.length;
    }

    pintarPaginas(total, hay.length);
  }

  function pintarPaginas(total, cuantos) {
    if (!paginas || !pie) { return; }

    paginas.innerHTML = '';
    pie.hidden = cuantos <= POR_PAGINA;

    if (pie.hidden) { return; }

    /* Una ventana alrededor de la página actual. Con veinte páginas,
       listarlas todas ocupa más que la tabla. */
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
      });
    }

    li.appendChild(a);
    paginas.appendChild(li);
  }

  [selAnio, selMes].forEach(function (s) {
    if (s) { s.addEventListener('change', function () { pagina = 1; pintar(); }); }
  });

  if (limpiar) {
    limpiar.addEventListener('click', function () {
      if (selAnio) { selAnio.value = ''; }
      if (selMes) { selMes.value = ''; }
      pagina = 1;
      pintar();
    });
  }

  pintar();
})();
</script>
<?php endif; ?>
