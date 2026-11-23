<?php
/**
 * La declaración que el cliente hace una sola vez.
 *
 * Es una pantalla de paso obligado, y eso obliga a que se explique: a
 * nadie le gusta que le pregunten cuánto gana sin decirle para qué.
 * Por eso arriba va el porqué antes que el primer campo.
 *
 * LA FUENTE DE INGRESO NO ES UN DESPLEGABLE
 *
 * Eran siete opciones dentro de un `<select>`: hay que abrirlo para
 * saber qué hay, y lo que hay son frases, no palabras sueltas. En
 * tarjetas se ven las siete de un vistazo, se eligen de un toque y
 * cada una lleva su dibujo, que es lo que hace que se reconozca la
 * propia sin leerlas todas.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array<string,string> $fuentes
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

/* El dibujo de cada fuente. Van aquí y no en el servicio porque son
   decoración de esta pantalla: el servicio no tiene por qué saber
   cómo se dibuja un maletín. */
$iconos = [
    'empleo' => '<path d="M20 7h-4V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2H4a2 2 0 00-2 2v9a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M10 7V5h4v2"/>',
    'negocio_propio' => '<path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v11h16V9"/><path d="M3 9h18"/><path d="M9 20v-6h6v6"/>',
    'honorarios' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4z"/>',
    'remesas' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 010 18 15 15 0 010-18z"/>',
    'pension' => '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'rentas' => '<path d="M3 10l9-7 9 7v10a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><path d="M9 22V12h6v10"/>',
    'otro' => '<circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 015.8 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
];
?>

<div class="cm-declarar">

  <!-- ==================== LA CABECERA ==================== -->
  <header class="cm-declarar__cab">
    <div class="cm-declarar__brillo" aria-hidden="true"></div>

    <span class="cm-declarar__sello" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor"
           stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>
    </span>

    <h4>Un último paso y ya está</h4>

    <p>
      Para poder ofrecerle productos necesitamos saber de qué vive. Es lo que
      usamos para calcular cuánto puede pagar sin ahogarse, y por eso se lo
      preguntamos antes y no después.
    </p>

    <p class="cm-declarar__unavez">
      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
           stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
        <circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>
      </svg>
      <span>
        Se llena <strong>una sola vez</strong>. Después, cualquier cambio se hace
        en agencia con su documento de identidad.
      </span>
    </p>
  </header>

  <form id="form-declarar" method="post" class="cm-declarar__cuerpo"
        action="<?= P::attr($rutas->url('mi-perfil/declarar')) ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
    <input type="hidden" name="_csrf_proposito" value="perfil_declarar">

    <!-- ==================== DE DÓNDE VIVE ==================== -->
    <fieldset class="cm-declarar__bloque">
      <legend>
        <span class="cm-declarar__n">1</span>
        ¿De dónde vienen sus ingresos?
      </legend>

      <div class="cm-fuentes" role="radiogroup" aria-label="Fuente de ingresos">
        <?php foreach ($fuentes as $cod => $texto): ?>
        <label class="cm-fuente">
          <input type="radio" name="fuente_ingreso" value="<?= P::attr($cod) ?>" required>
          <span class="cm-fuente__caja">
            <span class="cm-fuente__icono">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor"
                   stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <?= P::crudo($iconos[$cod] ?? $iconos['otro']) ?>
              </svg>
            </span>
            <span class="cm-fuente__texto"><?= P::e($texto) ?></span>
            <span class="cm-fuente__tic" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"
                   stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 6L9 17l-5-5"/>
              </svg>
            </span>
          </span>
        </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <!-- ==================== A QUÉ SE DEDICA ==================== -->
    <fieldset class="cm-declarar__bloque">
      <legend>
        <span class="cm-declarar__n">2</span>
        ¿A qué se dedica?
      </legend>

      <div class="form-row">
        <div class="form-group col-md-7">
          <label class="text-label" for="ocupacion">Su ocupación</label>
          <input type="text" class="form-control" id="ocupacion" name="ocupacion"
                 maxlength="120" required placeholder="Contadora, albañil, estudiante…">
          <small class="form-text text-muted">En sus propias palabras.</small>
        </div>

        <div class="form-group col-md-5">
          <label class="text-label" for="antiguedad_meses">¿Hace cuánto?</label>
          <div class="input-group">
            <input type="number" class="form-control" id="antiguedad_meses"
                   name="antiguedad_meses" min="0" max="840" step="1" placeholder="0">
            <div class="input-group-append">
              <span class="input-group-text">meses</span>
            </div>
          </div>
          <small class="form-text text-muted" id="antiguedad-dicho">&nbsp;</small>
        </div>
      </div>

      <?php /* Solo para quien dijo que trabaja para alguien. Pedirle el
               nombre de su empresa a un pensionado es pedirle un dato
               que no tiene. */ ?>
      <div class="form-row cm-declarar__empleo" id="bloque-empleo" hidden>
        <div class="form-group col-md-7">
          <label class="text-label" for="empresa">¿Dónde trabaja?</label>
          <input type="text" class="form-control" id="empresa" name="empresa"
                 maxlength="255" placeholder="Nombre de la empresa">
        </div>

        <div class="form-group col-md-5">
          <label class="text-label" for="cargo">¿Con qué cargo?</label>
          <input type="text" class="form-control" id="cargo" name="cargo"
                 maxlength="255" placeholder="Opcional">
        </div>
      </div>
    </fieldset>

    <!-- ==================== CUÁNTO ==================== -->
    <fieldset class="cm-declarar__bloque">
      <legend>
        <span class="cm-declarar__n">3</span>
        ¿Cuánto recibe al mes?
      </legend>

      <div class="cm-declarar__plata">
        <div class="input-group input-group-lg">
          <div class="input-group-prepend"><span class="input-group-text">$</span></div>
          <input type="text" class="form-control" id="ingreso" name="ingreso"
                 inputmode="decimal" required placeholder="0.00" autocomplete="off">
        </div>

        <small class="form-text text-muted">
          Si varía de mes a mes, ponga un promedio. No hace falta que sea exacto.
        </small>
      </div>

      <div class="form-group mb-0">
        <label class="text-label" for="direccion">Dirección de residencia</label>
        <textarea class="form-control" id="direccion" name="direccion" rows="2"
                  maxlength="255" required
                  placeholder="Colonia, calle, número de casa y municipio"></textarea>
      </div>
    </fieldset>

    <button type="submit" class="btn btn-primary btn-block btn-lg" id="btn-declarar">
      Guardar y continuar
    </button>

    <p class="cm-declarar__pie">
      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
      </svg>
      Sus datos se usan solo para administrar los productos que contrate.
      No se comparten con nadie más.
    </p>
  </form>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var f = document.getElementById('form-declarar');

  if (!f) { return; }

  /* ---- El bloque de la empresa, solo para quien es empleado ---- */

  var empleo  = document.getElementById('bloque-empleo');
  var empresa = document.getElementById('empresa');
  var cargo   = document.getElementById('cargo');

  function fuenteElegida() {
    var m = f.querySelector('input[name="fuente_ingreso"]:checked');
    return m ? m.value : '';
  }

  function ajustar() {
    var esEmpleado = fuenteElegida() === 'empleo';

    empleo.hidden = !esEmpleado;
    empresa.required = esEmpleado;

    /* Un campo escondido con valor dentro se manda igual. Si cambió de
       respuesta, lo que escribió antes ya no corresponde a nada. */
    if (!esEmpleado) { empresa.value = ''; cargo.value = ''; }
  }

  f.querySelectorAll('input[name="fuente_ingreso"]').forEach(function (r) {
    r.addEventListener('change', ajustar);
  });

  ajustar();

  /* ---- La antigüedad, dicha en años ----
     «84» no se lee; «7 años» sí. Se dice al lado mientras escribe,
     que es cuando sirve para darse cuenta de que puso un cero de
     más. */
  var meses = document.getElementById('antiguedad_meses');
  var dicho = document.getElementById('antiguedad-dicho');

  meses.addEventListener('input', function () {
    var n = parseInt(meses.value, 10);

    if (!n || n < 12) { dicho.innerHTML = '&nbsp;'; return; }

    var anios = Math.floor(n / 12);
    var resto = n % 12;

    dicho.textContent = 'Son ' + anios + (anios === 1 ? ' año' : ' años')
      + (resto > 0 ? ' y ' + resto + (resto === 1 ? ' mes' : ' meses') : '') + '.';
  });

  /* ---- El envío ---- */

  f.addEventListener('submit', function (ev) {
    ev.preventDefault();

    if (fuenteElegida() === '') {
      window.avisar('Falta un dato', 'Elija de dónde vienen sus ingresos.', 'alerta');
      return;
    }

    var btn = document.getElementById('btn-declarar');

    btn.disabled = true;

    CMProceso.enviar(f.action, { body: new FormData(f) }, 'generico', {
      titulo: 'Guardando sus datos',
      nota: 'Un momento, por favor.'
    })
      .then(function (j) {
        window.location = (j.datos && j.datos.redirigir) || <?= P::js($rutas->url('panel')) ?>;
      })
      .catch(function () {
        btn.disabled = false;
      });
  });
})();
</script>
