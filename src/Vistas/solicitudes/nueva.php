<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $productos
 * @var array $clientes
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;
?>
<div class="row">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><h4 class="card-title">Solicitud de crédito</h4></div>
      <div class="card-body">

        <form id="form-sol" data-cm-ajax method="post"
              action="<?= P::attr($rutas->url('solicitudes/nueva')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="solicitud">

          <?php if ($clientes !== []): ?>
          <div class="form-group">
            <label for="cliente">Cliente</label>
            <select class="form-control" id="cliente" name="cliente">
              <option value="">— Para mí —</option>
              <?php foreach ($clientes as $c): ?>
              <option value="<?= P::attr($c['codigo']) ?>" data-ingreso="<?= (int) $c['ingreso_cent'] ?>">
                <?= P::e(trim($c['nombres'] . ' ' . $c['apellidos'])) ?> — <?= P::e($c['correo']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small class="form-text text-muted">Déjelo vacío si la solicitud es para usted.</small>
          </div>
          <?php endif; ?>

          <div class="form-group">
            <label for="producto">Producto crediticio</label>
            <select class="form-control" id="producto" name="producto" required>
              <?php foreach ($productos as $p): ?>
              <?php /* Los límites viajan con la opción: al cambiar de
                       producto, el deslizador y los plazos se rehacen
                       sin ir al servidor. */ ?>
              <option value="<?= P::attr($p['codigo_h']) ?>"
                      data-tasa="<?= (int) $p['tasa_interes_bp'] ?>"
                      data-min="<?= (int) $p['tasa_min_bp'] ?>"
                      data-max="<?= (int) $p['tasa_max_bp'] ?>"
                      data-monto-min="<?= (int) $p['monto_minimo_cent'] ?>"
                      data-monto-max="<?= (int) $p['monto_maximo_cent'] ?>"
                      data-plazos="<?= P::attr(implode(',', $p['plazos'])) ?>"
                      data-desc="<?= P::attr((string) $p['descripcion']) ?>"
                      data-modalidad="<?= P::attr($p['modalidad']) ?>"
                      data-destinos="<?= P::attr(implode('|', $p['destinos'])) ?>">
                <?= P::e($p['nombre']) ?> — <?= number_format((int) $p['tasa_interes_bp'] / 100, 2) ?>% anual
                · hasta <?= P::e(P::money((int) $p['monto_maximo_cent'])) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small class="form-text text-muted" id="desc-producto"></small>
          </div>

          <?php if (!empty($puedeTasa)): ?>
          <!-- --------------------------------------------------------
               Tasa ajustable.

               Reproduce el deslizador del sistema anterior
               (`rangointereses`), que permitía mover la tasa al armar el
               crédito y recalculaba la cuota al instante. El rango sale
               del producto y el servidor vuelve a comprobarlo: el `min`
               y el `max` de aquí son una ayuda visual, no una garantía.
               -------------------------------------------------------- -->
          <div class="form-group cm-tasa" id="bloque-tasa">
            <div class="d-flex justify-content-between align-items-baseline">
              <label for="tasa" class="mb-0">Tasa de interés anual</label>
              <span class="cm-tasa__valor" id="tasa-texto">—</span>
            </div>

            <input type="range" class="cm-tasa__rango" id="tasa" name="tasa_bp"
                   min="0" max="100" step="5" value="0">

            <div class="cm-tasa__limites">
              <span id="tasa-min">—</span>
              <button type="button" class="btn btn-link p-0 cm-tasa__reset" id="tasa-reset">
                Volver a la del producto
              </button>
              <span id="tasa-max">—</span>
            </div>

            <small class="form-text text-muted">
              Solo puede moverse dentro del rango autorizado para el producto.
              La tasa que quede aquí es la que se pacta y queda en el contrato.
            </small>
          </div>
          <?php endif; ?>

          <?php /* El monto, con deslizador.
                   Buscar «cuánto puedo pedir» tecleando cifras y
                   esperando a que recalcule es trabajo del cliente que
                   debería hacer la pantalla. Arrastrando se recorre el
                   rango entero en dos segundos. */ ?>
          <div class="form-group cm-monto">
            <div class="d-flex justify-content-between align-items-baseline mb-1">
              <label for="monto" class="mb-0">Monto solicitado</label>
              <span class="cm-monto__valor" id="monto-texto">$0.00</span>
            </div>

            <div class="input-group mb-2">
              <div class="input-group-prepend"><span class="input-group-text">$</span></div>
              <input type="text" class="form-control" id="monto" name="monto"
                     inputmode="decimal" placeholder="0.00" required autocomplete="off">
            </div>

            <?php /* Sin valores fijos: los pone el producto elegido.
                     Un hipotecario y un consumo no comparten techo, y
                     tenerlo escrito aquí era lo que topaba todo en
                     cincuenta mil. */ ?>
            <input type="range" class="cm-monto__rango" id="monto-rango"
                   min="100" max="10000" step="100" value="1000"
                   aria-label="Monto solicitado">

            <div class="cm-monto__limites">
              <span id="monto-piso">—</span>
              <span id="monto-techo">—</span>
            </div>

            <?php /* El techo del PRODUCTO y el del CLIENTE son dos cosas
                     distintas, y el que manda es el segundo. */ ?>
            <small class="cm-monto__capacidad" id="monto-capacidad" hidden></small>
          </div>

          <?php /* El plazo, en botones.
                   Doce opciones en un desplegable obligan a abrirlo y
                   elegir a ciegas; aquí se ven todas y se prueban de un
                   clic viendo cómo se mueve la cuota. */ ?>
          <div class="form-group">
            <label class="mb-1">Plazo</label>
            <input type="hidden" id="plazo" name="plazo" value="24">

            <?php /* Los pinta el guion con los del producto: una lista
                     fija aquí ofrecía seis meses en un hipotecario. */ ?>
            <div class="cm-plazos__opciones" id="plazos-caja"></div>
          </div>

          <div class="form-group">
            <label for="ingreso">Ingreso mensual comprobable</label>
            <div class="input-group">
              <div class="input-group-prepend"><span class="input-group-text">$</span></div>
              <input type="text" class="form-control" id="ingreso" name="ingreso"
                     inputmode="decimal" placeholder="0.00" autocomplete="off">
            </div>
            <small class="form-text text-muted">
              Se usa para evaluar la capacidad de pago. La cuota no debe superar
              el 40% del ingreso disponible.
            </small>
          </div>

          <?php /* El destino sale de una lista, no de una caja libre.
                   Escrito a mano, un crédito de vehículo acababa
                   diciendo «estudios universitarios» en la cláusula
                   del destino mientras la de arriba decía «modalidad
                   de crédito de vehículo»: el contrato se contradecía
                   a sí mismo dos líneas más abajo. */ ?>
          <div class="form-group">
            <label for="destino">Destino del crédito</label>
            <select class="form-control" id="destino" name="destino" required>
              <!-- Lo rellena `objeto-credito.js` con los del producto. -->
            </select>
            <small class="form-text text-muted">
              Las opciones cambian según el producto: el destino tiene que
              corresponder con la clase de crédito que se pide.
            </small>
          </div>

          <?php
          /* Los datos del bien que se financia.
           *
           * Cada bloque pertenece a una modalidad y solo se ve el que
           * toca. Son los datos con los que ese bien se identifica de
           * verdad: un vehículo por su chasis y su VIN, un inmueble
           * por su matrícula del Centro Nacional de Registros. Sin
           * ellos el contrato remite al expediente, y un contrato que
           * remite al expediente no sirve para inscribir una prenda
           * ni para ejecutar nada.
           *
           * Ninguno es obligatorio en el formulario: en una captura de
           * ventanilla puede faltar el número de motor y la solicitud
           * tiene que poder entrar igual. Lo que no puede es faltar
           * sin que nadie lo haya pedido.
           */
          $bloques = [
              'vehiculo' => ['Datos del vehículo', [
                  ['veh_marca', 'Marca', 'text', 'Toyota', 'col-md-6'],
                  ['veh_modelo', 'Modelo', 'text', 'Corolla LE', 'col-md-6'],
                  ['veh_anio', 'Año', 'number', '2021', 'col-md-4'],
                  ['veh_color', 'Color', 'text', 'Gris plata', 'col-md-4'],
                  ['veh_clase', 'Clase', 'text', 'Automóvil sedán', 'col-md-4'],
                  ['veh_vin', 'VIN', 'text', '17 caracteres', 'col-md-6'],
                  ['veh_chasis', 'Número de chasis', 'text', '', 'col-md-6'],
                  ['veh_motor', 'Número de motor', 'text', '', 'col-md-6'],
                  ['veh_placa', 'Placa', 'text', 'P 123-456', 'col-md-6'],
                  ['veh_combustible', 'Combustible', 'text', 'Gasolina', 'col-md-6'],
                  ['veh_vendedor', 'Vendedor', 'text', 'Nombre del distribuidor', 'col-md-6'],
              ]],

              'vivienda' => ['Datos del inmueble', [
                  ['inm_tipo', 'Tipo de inmueble', 'text', 'Casa', 'col-md-6'],
                  ['inm_matricula', 'Matrícula del CNR', 'text', 'La del Registro de la Propiedad', 'col-md-6'],
                  ['inm_direccion', 'Dirección', 'text', '', 'col-md-12'],
                  ['inm_municipio', 'Municipio', 'text', '', 'col-md-6'],
                  ['inm_departamento', 'Departamento', 'text', '', 'col-md-6'],
                  ['inm_area_m2', 'Área del terreno (m²)', 'number', '', 'col-md-4'],
                  ['inm_construccion_m2', 'Construcción (m²)', 'number', '', 'col-md-4'],
                  ['inm_tasacion_en', 'Fecha de tasación', 'date', '', 'col-md-4'],
                  ['inm_vendedor', 'Vendedor', 'text', '', 'col-md-12'],
              ]],

              'estudios' => ['Datos de los estudios', [
                  ['edu_institucion', 'Institución', 'text', 'Universidad', 'col-md-6'],
                  ['edu_carrera', 'Carrera o programa', 'text', '', 'col-md-6'],
                  ['edu_nivel', 'Nivel', 'text', 'Grado, maestría…', 'col-md-4'],
                  ['edu_duracion', 'Duración', 'text', '10 ciclos', 'col-md-4'],
                  ['edu_modalidad', 'Modalidad', 'text', 'Presencial', 'col-md-4'],
              ]],

              'negocio' => ['Datos del negocio', [
                  ['neg_actividad', 'Actividad económica', 'text', '', 'col-md-6'],
                  ['neg_nrc', 'NRC', 'text', 'Registro de contribuyente', 'col-md-6'],
                  ['neg_direccion', 'Dirección del negocio', 'text', '', 'col-md-12'],
                  ['neg_aplicacion', 'Aplicación de los fondos', 'text', 'Capital de trabajo, maquinaria…', 'col-md-12'],
              ]],
          ];
          ?>

          <?php foreach ($bloques as $modalidad => [$titulo, $campos]): ?>
          <div class="cm-bien" data-modalidad="<?= P::attr($modalidad) ?>" hidden>
            <h6 class="cm-bien__titulo"><?= P::e($titulo) ?></h6>
            <div class="form-row">
              <?php foreach ($campos as [$nombre, $etiqueta, $tipo, $pista, $col]): ?>
              <div class="form-group <?= P::attr($col) ?>">
                <label for="<?= P::attr($nombre) ?>" class="small mb-1"><?= P::e($etiqueta) ?></label>
                <input type="<?= P::attr($tipo) ?>" class="form-control form-control-sm"
                       id="<?= P::attr($nombre) ?>" name="<?= P::attr($nombre) ?>"
                       maxlength="160"
                       <?= $pista !== '' ? 'placeholder="' . P::attr($pista) . '"' : '' ?>>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>

          <button type="submit" class="btn btn-primary">Enviar solicitud</button>
          <a href="<?= P::attr($rutas->url('solicitudes')) ?>" class="btn btn-outline-secondary">Cancelar</a>
        </form>
      </div>
    </div>
  </div>

  <!-- Simulador en vivo -->
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><h4 class="card-title mb-0">Simulación</h4></div>
      <div class="card-body" id="simulacion">
        <p class="text-muted mb-0" style="font-size:.9rem">
          Ingrese un monto y un plazo para ver la cuota estimada.
        </p>
      </div>
    </div>

    <div class="alert alert-light border" style="font-size:.84rem">
      <strong>Cómo se evalúa.</strong> La solicitud pasa por tres revisiones:
      atención a clientes, gerencia y presidencia. Cada etapa la revisa un rol
      distinto, y queda registrado quién aprobó qué.
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';
  var URL_SIM = <?= P::js($rutas->url('creditos/simular')) ?>;
  var monto = document.getElementById('monto');
  var plazo = document.getElementById('plazo');
  var prod  = document.getElementById('producto');
  var caja  = document.getElementById('simulacion');
  var cli   = document.getElementById('cliente');

  if (cli) {
    cli.addEventListener('change', function () {
      var o = cli.options[cli.selectedIndex];
      var ing = parseInt(o.getAttribute('data-ingreso') || '0', 10);
      if (ing > 0) {
        document.getElementById('ingreso').value = (ing / 100).toFixed(2);
      }
    });
  }

  /* ------------------------------------------------------------------
     Tasa ajustable

     El rango depende del producto, así que cada vez que cambia hay que
     reajustar el deslizador. Si el producto no admite ajuste —mínimo y
     máximo iguales— el bloque se esconde: dejar un control que no se
     puede mover solo confunde.
     ------------------------------------------------------------------ */
  var tasa    = document.getElementById('tasa');
  var bloque  = document.getElementById('bloque-tasa');
  var txtTasa = document.getElementById('tasa-texto');

  function pintarTasa() {
    if (!tasa) { return; }
    txtTasa.textContent = (parseInt(tasa.value, 10) / 100).toFixed(2) + ' %';
  }

  function aplicarRango() {
    if (!tasa) { return; }

    var o    = prod.options[prod.selectedIndex];
    var base = parseInt(o.getAttribute('data-tasa') || '0', 10);
    var min  = parseInt(o.getAttribute('data-min')  || '0', 10);
    var max  = parseInt(o.getAttribute('data-max')  || '0', 10);

    if (max <= min) {
      bloque.style.display = 'none';
      // Sin rango no se envía nada: el servidor usa la del producto.
      tasa.disabled = true;
      return;
    }

    bloque.style.display = '';
    tasa.disabled = false;
    tasa.min = min;
    tasa.max = max;
    tasa.step = 5;                 // 0.05 % por paso, como el original
    tasa.value = base;

    document.getElementById('tasa-min').textContent = (min / 100).toFixed(2) + ' %';
    document.getElementById('tasa-max').textContent = (max / 100).toFixed(2) + ' %';
    pintarTasa();
  }

  if (tasa) {
    tasa.addEventListener('input', function () { pintarTasa(); simular(); });
    document.getElementById('tasa-reset').addEventListener('click', function () {
      var o = prod.options[prod.selectedIndex];
      tasa.value = parseInt(o.getAttribute('data-tasa') || '0', 10);
      pintarTasa();
      simular();
    });
    prod.addEventListener('change', aplicarRango);
    aplicarRango();
  }

  var t;
  function simular() {
    clearTimeout(t);
    var m = monto.value.trim();
    if (!m) { return; }

    t = setTimeout(function () {
      var fd = new FormData();
      fd.append('monto', m);
      fd.append('plazo', plazo.value);
      fd.append('producto', prod.value);
      if (tasa && !tasa.disabled) { fd.append('tasa_bp', tasa.value); }

      fetch(URL_SIM, { method:'POST', body:fd, credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) {
            caja.innerHTML = '<p class="text-danger mb-0" style="font-size:.9rem">' +
                             window.CMUtil.escapar(j.mensaje) + '</p>';
            return;
          }
          var d = j.datos;
          caja.innerHTML =
            // Lo que de verdad paga el cliente cada mes: la cuota más los
            // seguros obligatorios. Enseñar solo la cuota prometería menos
            // de lo que se le va a cobrar.
            '<div class="text-center mb-3">' +
              '<small class="text-muted d-block">Total a pagar al mes</small>' +
              '<h2 class="mb-0 text-primary">' + d.cuota_total.formateado + '</h2>' +
              '<small class="text-muted">' + d.plazo + ' cuotas · ' + d.tasa_anual + '% anual</small>' +
            '</div>' +
            '<div class="d-flex justify-content-between py-1 border-bottom">' +
              '<small class="text-muted">Cuota (capital + interés)</small>' +
              '<small class="font-weight-bold">' + d.cuota.formateado + '</small></div>' +
            '<div class="d-flex justify-content-between py-1 border-bottom">' +
              '<small class="text-muted">Seguro de vida</small>' +
              '<small>' + d.seguro_vida.formateado + '</small></div>' +
            '<div class="d-flex justify-content-between py-1 border-bottom">' +
              '<small class="text-muted">Seguro de desempleo</small>' +
              '<small>' + d.seguro_empleo.formateado + '</small></div>' +
            '<div class="d-flex justify-content-between py-1 border-bottom">' +
              '<small class="text-muted">Capital</small>' +
              '<small class="font-weight-bold">' + window.CMUtil.money(
                  d.total_pagar.centavos - d.interes_total.centavos) + '</small></div>' +
            '<div class="d-flex justify-content-between py-1 border-bottom">' +
              '<small class="text-muted">Intereses</small>' +
              '<small class="font-weight-bold cm-neg">' + d.interes_total.formateado + '</small></div>' +
            '<div class="d-flex justify-content-between py-1 border-bottom">' +
              '<small class="text-muted">Total a pagar</small>' +
              '<small class="font-weight-bold">' + d.total_pagar.formateado + '</small></div>' +
            '<div class="d-flex justify-content-between py-1 border-bottom">' +
              '<small class="text-muted">Costo sobre el capital</small>' +
              '<small class="font-weight-bold">' + d.costo_pct + '%</small></div>' +
            // Cargos fijos del contrato y lo que de verdad recibe el
            // cliente. El sistema anterior los enseñaba aquí mismo y sin
            // ellos la cifra de arriba cuenta solo media historia.
            // Capacidad de pago. En lugar de limitarse a avisar que la
            // cuota no cabe, dice cuánto SÍ cabe y ofrece ajustarlo de un
            // clic: era lo que antes había que averiguar probando montos.
            (d.capacidad ? (
              '<div class="cm-capacidad ' +
                (d.capacidad.cabe ? 'cm-capacidad--ok' : 'cm-capacidad--no') + '">' +
                '<div class="cm-capacidad__fila"><span>Ingreso declarado</span><b>' +
                  d.capacidad.ingreso.formateado + '</b></div>' +
                '<div class="cm-capacidad__fila"><span>Ya comprometido al mes</span><b>' +
                  d.capacidad.compromisos.formateado + '</b></div>' +
                '<div class="cm-capacidad__fila"><span>Cuota máxima admitida</span><b>' +
                  d.capacidad.cuota_maxima.formateado + '</b></div>' +
                '<div class="cm-capacidad__fila cm-capacidad__tope">' +
                  '<span>Monto máximo según su capacidad</span><b>' +
                  d.capacidad.monto_maximo.formateado + '</b></div>' +
                (d.capacidad.cabe
                  ? '<p class="cm-capacidad__nota">La cuota representa el ' +
                    d.capacidad.relacion_pct +
                    ' % de su ingreso disponible: dentro de lo admitido.</p>'
                  : '<p class="cm-capacidad__nota">La cuota representa el ' +
                    d.capacidad.relacion_pct +
                    ' % de su ingreso disponible y pasa del máximo. Puede bajar el ' +
                    'monto o ampliar el plazo.</p>' +
                    '<button type="button" class="btn btn-sm btn-outline-primary" ' +
                    'id="ajustar-monto" data-monto="' +
                    (d.capacidad.monto_maximo.centavos / 100).toFixed(2) + '">' +
                    'Ajustar al máximo que admite</button>') +
              '</div>'
            ) : '') +
            '<div class="cm-sim__cargos">' +
              '<div class="d-flex justify-content-between py-1">' +
                '<small class="text-muted">Gastos administrativos</small>' +
                '<small>' + d.gastos.formateado + '</small></div>' +
              '<div class="d-flex justify-content-between pt-2 mt-1 cm-sim__desembolso">' +
                '<span>Se desembolsa</span>' +
                '<b>' + d.desembolso.formateado + '</b></div>' +
            '</div>';

          /* El techo del cliente, bajo el deslizador: es el que
             de verdad acota. El del producto solo dice hasta
             dónde llega la casa. */
          var cap = document.getElementById('monto-capacidad');

          if (cap) {
            if (d.capacidad && d.capacidad.monto_maximo) {
              cap.hidden = false;
              cap.className = 'cm-monto__capacidad'
                + (d.capacidad.cabe ? '' : ' cm-monto__capacidad--pasa');
              cap.textContent = d.capacidad.cabe
                ? 'Su capacidad de pago admite hasta '
                  + d.capacidad.monto_maximo.formateado + '.'
                : 'Se pasa de su capacidad: hoy admite hasta '
                  + d.capacidad.monto_maximo.formateado + '.';
            } else {
              cap.hidden = true;
            }
          }
        })
        .catch(function () {});
    }, 450);
  }

  /* Delegación en el contenedor: el botón nace y muere con cada
     simulación, de modo que engancharlo directamente solo funcionaría la
     primera vez. */
  caja.addEventListener('click', function (ev) {
    var b = ev.target.closest('#ajustar-monto');
    if (!b) { return; }

    monto.value = b.getAttribute('data-monto');
    monto.dispatchEvent(new Event('input', { bubbles: true }));

    if (window.CMAviso) {
      window.CMAviso.info('Monto ajustado a lo que admite la capacidad de pago del cliente.');
    }
  });
  [monto, plazo, prod].forEach(function (el) {
    if (el) { el.addEventListener('input', simular); el.addEventListener('change', simular); }
  });

  /* ------------------------------------------------------------------
     El monto: campo, deslizador y rótulo, los tres en sincronía
     ------------------------------------------------------------------ */
  var rango     = document.getElementById('monto-rango');
  var rotulo    = document.getElementById('monto-texto');

  function numero(v) {
    // Se quita todo lo que no sea dígito o punto: el usuario escribe
    // «1,500.00» y eso no es un número para `parseFloat`.
    var n = parseFloat(String(v).replace(/[^0-9.]/g, ''));
    return isFinite(n) ? n : 0;
  }

  function pintarRotulo(n) {
    if (rotulo) { rotulo.textContent = window.CMUtil.money(Math.round(n * 100)); }
  }

  if (rango && monto) {
    // Del deslizador al campo.
    rango.addEventListener('input', function () {
      monto.value = parseFloat(rango.value).toFixed(2);
      pintarRotulo(numero(rango.value));
      simular();
    });

    // Del campo al deslizador. Si el cliente teclea más de lo que el
    // deslizador alcanza no se le recorta el campo: se estira el tope,
    // porque el límite real lo pone su capacidad, no este control.
    monto.addEventListener('input', function () {
      var n = numero(monto.value);

      if (n > parseFloat(rango.max)) {
        rango.max = String(Math.ceil(n / 10000) * 10000);
        var tope = document.querySelector('.cm-monto__limites span:last-child');
        if (tope) { tope.textContent = window.CMUtil.money(parseFloat(rango.max) * 100); }
      }

      rango.value = String(Math.max(parseFloat(rango.min), Math.min(parseFloat(rango.max), n)));
      pintarRotulo(n);
    });

    pintarRotulo(numero(monto.value));
  }

  /* ------------------------------------------------------------------
     El plazo, en botones que pone el producto
     ------------------------------------------------------------------ */
  var cajaPlazos = document.getElementById('plazos-caja');

  function pintarPlazos(lista, elegido) {
    if (!cajaPlazos) { return; }

    cajaPlazos.innerHTML = '';

    lista.forEach(function (n) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'cm-plazo' + (n === elegido ? ' cm-plazo--activo' : '');
      b.dataset.plazo = String(n);
      b.innerHTML = '<strong>' + n + '</strong><small>meses</small>';

      b.addEventListener('click', function () {
        cajaPlazos.querySelectorAll('.cm-plazo').forEach(function (o) {
          o.classList.toggle('cm-plazo--activo', o === b);
        });

        if (plazo) { plazo.value = b.dataset.plazo; simular(); }
      });

      cajaPlazos.appendChild(b);
    });

    if (plazo) { plazo.value = String(elegido); }
  }

  /* ------------------------------------------------------------------
     Al cambiar de producto se rehace todo: el rango del deslizador, el
     monto si se queda fuera y los plazos que ese producto admite.
     ------------------------------------------------------------------ */
  function ajustarAlProducto() {
    if (!prod || !rango) { return; }

    var o    = prod.options[prod.selectedIndex];
    var piso = (parseInt(o.dataset.montoMin, 10) || 10000) / 100;
    var tope = (parseInt(o.dataset.montoMax, 10) || 1000000) / 100;

    rango.min  = String(piso);
    rango.max  = String(tope);
    // Un paso proporcional: de cien en cien no se recorren tres
    // millones, y de mil en mil no se elige bien un crédito de $500.
    rango.step = String(Math.max(50, Math.round(tope / 1000 / 50) * 50));

    var txtPiso  = document.getElementById('monto-piso');
    var txtTecho = document.getElementById('monto-techo');

    if (txtPiso)  { txtPiso.textContent  = window.CMUtil.money(piso * 100); }
    if (txtTecho) { txtTecho.textContent = window.CMUtil.money(tope * 100); }

    // Si el monto que había se sale del producto nuevo, se ajusta: un
    // hipotecario no se pide por $300.
    var actual = numero(monto.value);

    if (actual < piso || actual > tope) {
      actual = Math.min(tope, Math.max(piso, actual || piso));
      monto.value = actual.toFixed(2);
    }

    rango.value = String(actual);
    pintarRotulo(actual);

    // Los plazos del producto, con uno sensato elegido de salida.
    var lista = (o.dataset.plazos || '24').split(',')
      .map(function (x) { return parseInt(x, 10); })
      .filter(function (x) { return x > 0; });

    var actualPlazo = plazo ? parseInt(plazo.value, 10) : 0;
    var elegido = lista.indexOf(actualPlazo) >= 0
      ? actualPlazo
      : lista[Math.min(lista.length - 1, Math.floor(lista.length / 2))];

    pintarPlazos(lista, elegido);

    var desc = document.getElementById('desc-producto');
    if (desc) { desc.textContent = o.dataset.desc || ''; }

    simular();
  }

  if (prod) {
    prod.addEventListener('change', ajustarAlProducto);
    ajustarAlProducto();
  }
})();
</script>

<?php /* El guion que ensena los campos del bien segun el producto. */ ?>
<?php $ocv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/objeto-credito.js') ?: time(); ?>
<script src="<?= P::attr($rutas->url('assets/js/objeto-credito.js') . '?v=' . $ocv) ?>"></script>
