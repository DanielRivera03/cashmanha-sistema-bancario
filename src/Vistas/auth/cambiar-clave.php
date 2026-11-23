<?php
/**
 * Cambio obligatorio de contraseña: lo pide el sistema tras un alta o
 * un restablecimiento hecho por un administrador.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;
?>

<div id="paso-form" class="cm-paso">

  <h4 class="text-center mb-2">Actualiza Tu Contraseña</h4>
  <p class="cm-sub">
    <?= P::e(($motivo ?? '') !== ''
        ? $motivo
        : 'Por seguridad necesita definir una contraseña propia antes de continuar.') ?>
  </p>

  <?php if (($aviso ?? '') !== ''): ?>
  <div class="cm-aviso cm-aviso--<?= P::attr($avisoTipo ?: 'info') ?>" role="alert">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
      <circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01" stroke-linecap="round"/>
    </svg>
    <span><?= P::e($aviso) ?></span>
  </div>
  <?php endif; ?>
  <div id="aviso" class="d-none" role="alert"></div>

  <form id="form-clave" method="post" action="<?= P::attr($rutas->url('clave')) ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">

    <div class="form-group">
      <label for="actual">Contraseña actual</label>
      <div class="input-group">
        <div class="input-group-prepend">
          <span class="input-group-text">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.1" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4" stroke-linecap="round"/></svg>
          </span>
        </div>
        <input type="password" class="form-control" id="actual" name="actual"
               autocomplete="current-password" required autofocus
               placeholder="La que usaste para entrar">
      </div>
    </div>

    <hr class="my-4">

    <div class="form-group">
      <label for="nueva">Contraseña nueva</label>
      <div class="input-group transparent-append">
        <div class="input-group-prepend">
          <span class="input-group-text">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.1" aria-hidden="true">
              <rect x="3" y="11" width="18" height="11" rx="2"/>
              <path d="M7 11V7a5 5 0 0110 0v4" stroke-linecap="round"/>
            </svg>
          </span>
        </div>
        <input type="password" class="form-control" id="nueva" name="nueva"
               autocomplete="new-password" required placeholder="Mínimo 12 caracteres">
        <div class="input-group-append show-pass">
          <button class="btn" type="button" id="ver-clave"
                  aria-label="Mostrar contraseña" tabindex="-1">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" aria-hidden="true" id="icono-ojo">
              <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M1 1l22 22" stroke-linecap="round"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="cm-fuerza mt-2"><i id="barra"></i></div>
      <small class="form-text" id="etiqueta-fuerza" style="font-size:.76rem">&nbsp;</small>

      <ul class="cm-reglas" id="reglas">
        <li data-regla="largo">Al menos 12 caracteres</li>
        <li data-regla="mayus">Una letra mayúscula</li>
        <li data-regla="minus">Una letra minúscula</li>
        <li data-regla="numero">Un número</li>
        <li data-regla="simbolo">Un símbolo (!, #, $, %...)</li>
      </ul>
    </div>

    <div class="form-group">
      <label for="confirmacion">Repite la contraseña nueva</label>
      <div class="input-group">
        <div class="input-group-prepend">
          <span class="input-group-text">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.1" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4" stroke-linecap="round"/><path d="M9.5 16.5l1.8 1.8 3.2-3.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </span>
        </div>
        <input type="password" class="form-control" id="confirmacion" name="confirmacion"
               autocomplete="new-password" required placeholder="Debe coincidir">
      </div>
      <small class="form-text" id="coincide" style="font-size:.76rem">&nbsp;</small>
    </div>

    <button type="submit" class="btn btn-primary btn-block" id="btn-guardar">
      Guardar y continuar
    </button>
  </form>

  <div class="cm-aviso cm-aviso--info mt-4 mb-0">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
      <circle cx="12" cy="12" r="10"/><path d="M12 16v-5M12 8h.01" stroke-linecap="round"/>
    </svg>
    <span>
      Al cambiarla se cerrarán las demás sesiones abiertas de su cuenta y
      tendrá que volver a ingresar con la contraseña nueva.
    </span>
  </div>

  <p class="text-center mt-3 mb-0" style="font-size:.83rem">
    <a href="<?= P::attr($rutas->url('salir')) ?>">Cerrar sesión</a>
  </p>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var form  = document.getElementById('form-clave');
  var btn   = document.getElementById('btn-guardar');
  var aviso = document.getElementById('aviso');
  var nueva = document.getElementById('nueva');
  var conf  = document.getElementById('confirmacion');

  var SVG_ERROR = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01" stroke-linecap="round"/></svg>';
  var SVG_OK    = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  function avisar(texto, tipo, lista) {
    aviso.className = 'cm-aviso cm-aviso--' + tipo;
    aviso.innerHTML = (tipo === 'exito' ? SVG_OK : SVG_ERROR) + '<div></div>';
    var caja = aviso.querySelector('div');

    var p = document.createElement('div');
    p.textContent = texto;
    caja.appendChild(p);

    if (lista && lista.length) {
      var ul = document.createElement('ul');
      ul.style.margin = '.35rem 0 0';
      ul.style.paddingLeft = '1.1rem';
      lista.forEach(function (f) {
        var li = document.createElement('li');
        li.textContent = f;
        ul.appendChild(li);
      });
      caja.appendChild(ul);
    }
    aviso.classList.remove('d-none');
  }

  // ---------- Ver / ocultar ----------
  var OJO_ABIERTO = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3"/>';
  var OJO_TACHADO = '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24" stroke-linecap="round" stroke-linejoin="round"/><path d="M1 1l22 22" stroke-linecap="round"/>';

  document.getElementById('ver-clave').addEventListener('click', function () {
    var oculta = nueva.type === 'password';
    nueva.type = conf.type = oculta ? 'text' : 'password';
    document.getElementById('icono-ojo').innerHTML = oculta ? OJO_TACHADO : OJO_ABIERTO;
    nueva.focus();
  });

  // ---------- Medidor ----------
  // Las mismas reglas que aplica el servidor; esto solo las muestra.
  var REGLAS = {
    largo:   function (v) { return v.length >= 12; },
    mayus:   function (v) { return /[A-ZÁÉÍÓÚÑ]/.test(v); },
    minus:   function (v) { return /[a-záéíóúñ]/.test(v); },
    numero:  function (v) { return /[0-9]/.test(v); },
    simbolo: function (v) { return /[^A-Za-z0-9ÁÉÍÓÚÑáéíóúñ]/.test(v); }
  };

  var COLORES = ['#b42318', '#b45309', '#c99a2e', '#8b4ae0', '#6418c3'];
  var TEXTOS  = ['Muy débil', 'Débil', 'Aceptable', 'Buena', 'Excelente'];

  var barra    = document.getElementById('barra');
  var etiqueta = document.getElementById('etiqueta-fuerza');

  function evaluar() {
    var v = nueva.value;
    var cumplidas = 0;

    Object.keys(REGLAS).forEach(function (k) {
      var ok = REGLAS[k](v);
      if (ok) { cumplidas++; }
      var li = document.querySelector('[data-regla="' + k + '"]');
      if (li) { li.classList.toggle('ok', ok); }
    });

    var nivel = cumplidas;
    if (cumplidas === 5 && v.length >= 16) { nivel = 5; }

    var pct = v === '' ? 0 : Math.max(12, (nivel / 5) * 100);
    var idx = Math.max(0, Math.min(4, nivel - 1));

    barra.style.width = pct + '%';
    barra.style.background = COLORES[idx];
    etiqueta.textContent = v === '' ? ' ' : TEXTOS[idx];
    etiqueta.style.color = v === '' ? '' : COLORES[idx];

    revisarCoincidencia();
  }

  function revisarCoincidencia() {
    var el = document.getElementById('coincide');
    if (conf.value === '') { el.textContent = ' '; el.style.color = ''; return; }

    var igual = conf.value === nueva.value;
    el.textContent = igual ? 'Las contraseñas coinciden.' : 'Las contraseñas no coinciden.';
    el.style.color = igual ? '#6418c3' : '#b42318';
  }

  nueva.addEventListener('input', evaluar);
  nueva.addEventListener('change', evaluar);
  conf.addEventListener('input', revisarCoincidencia);
  conf.addEventListener('change', revisarCoincidencia);

  /* El medidor se calculaba solo al escribir. Si el navegador rellena
     el campo por su cuenta —gestor de contraseñas, o al volver atrás y
     restaurar el formulario— no se dispara ningún `input`, así que la
     contraseña aparecía puesta y el medidor en cero.

     Se evalúa al arrancar y se vigila el campo durante tres segundos,
     que es el margen en que actúa el autocompletado. */
  evaluar();

  var intentos = 0;
  var ultimoVisto = nueva.value;
  var vigilante = setInterval(function () {
    // Se vigila el valor, no solo si dejó de estar vacío: hay
    // gestores de contraseñas que rellenan tarde y otros que lo
    // hacen en dos pasos, y en ambos casos no llega ningún evento.
    if (nueva.value !== ultimoVisto) { ultimoVisto = nueva.value; evaluar(); }
    if (++intentos > 30) { clearInterval(vigilante); }
  }, 100);

  /* Chrome marca los campos autocompletados con una animación propia;
     escucharla es la forma fiable de enterarse. */
  nueva.addEventListener('animationstart', function (ev) {
    if (ev.animationName === 'onAutoFillStart') { evaluar(); }
  });

  // ---------- Envío ----------
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    aviso.classList.add('d-none');

    if (conf.value !== nueva.value) { avisar('Las contraseñas no coinciden.', 'error'); return; }

    var faltan = Object.keys(REGLAS).filter(function (k) { return !REGLAS[k](nueva.value); });
    if (faltan.length) {
      avisar('La contraseña todavía no cumple los requisitos marcados abajo.', 'error');
      return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>Guardando...';

    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      btn.disabled = false;
      btn.textContent = 'Guardar y continuar';

      // Los tokens son de un solo uso: el servidor manda el siguiente.
      if (j.csrf) { form.querySelector('[name="_csrf"]').value = j.csrf; }

      if (!j.ok) {
        avisar(j.mensaje || 'No se pudo guardar.', 'error', j.datos && j.datos.fallos);
        return;
      }

      avisar(j.mensaje || 'Contraseña actualizada.', 'exito');
      setTimeout(function () {
        window.location.href = (j.datos && j.datos.redirigir) || form.action;
      }, 1600);
    })
    .catch(function () {
      btn.disabled = false;
      btn.textContent = 'Guardar y continuar';
      avisar('No se pudo contactar al servidor. Revise su conexión.', 'error');
    });
  });
})();
</script>
