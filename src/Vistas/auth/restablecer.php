<?php
/**
 * Pantalla a la que lleva el enlace del correo.
 *
 * El enlace por sí solo no cambia nada: además hay que escribir el
 * código de seis dígitos del mensaje. Si el enlace se filtra por el
 * historial, el Referer o un proxy, sigue siendo inútil sin el código.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var string $csrf
 * @var string $token
 * @var bool   $valido
 * @var string $nombre
 */
use CashMan\Http\Plantilla as P;
?>

<?php if (!$valido): ?>

<!-- ============ Enlace vencido o ya usado ============ -->
<div class="text-center cm-paso">
  <div class="cm-icono-alerta mx-auto mb-3">
    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.2" aria-hidden="true">
      <circle cx="12" cy="12" r="10"/>
      <path d="M12 7v6M12 16.5h.01" stroke-linecap="round"/>
    </svg>
  </div>

  <h4 class="mb-2">Este Enlace Ya No Sirve</h4>
  <p class="cm-sub">
    Los enlaces de restablecimiento vencen a los 30 minutos y funcionan una
    sola vez. También se anulan si pediste otro después de este.
  </p>

  <a href="<?= P::attr($rutas->url('recuperar')) ?>" class="btn btn-primary btn-block">
    Solicitar un enlace nuevo
  </a>
  <p class="mt-3 mb-0" style="font-size:.83rem">
    <a href="<?= P::attr($rutas->url('entrar')) ?>">Volver a ingresar</a>
  </p>
</div>

<?php else: ?>

<!-- ============ Formulario ============ -->
<div id="paso-form" class="cm-paso">

  <h4 class="text-center mb-2">Define Tu Nueva Contraseña</h4>
  <p class="cm-sub">
    Hola <strong><?= P::e($nombre) ?></strong>. Escriba el código que te
    enviamos por correo y elige la contraseña con la que entrarás a partir de ahora.
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

  <form id="form-rest" method="post"
        action="<?= P::attr($rutas->url('restablecer/' . $token)) ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">

    <div class="form-group">
      <label for="codigo">Código del correo</label>
      <input type="text" class="form-control cm-codigo" id="codigo" name="codigo"
             maxlength="6" inputmode="numeric" autocomplete="one-time-code"
             placeholder="000000" required autofocus>
      <small class="form-text text-muted">
        Son los 6 dígitos que aparecen destacados en el mensaje.
      </small>
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
      <label for="confirmar">Repite la contraseña nueva</label>
      <div class="input-group">
        <div class="input-group-prepend">
          <span class="input-group-text">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.1" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4" stroke-linecap="round"/><path d="M9.5 16.5l1.8 1.8 3.2-3.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </span>
        </div>
        <input type="password" class="form-control" id="confirmar"
               autocomplete="new-password" required placeholder="Debe coincidir">
      </div>
      <small class="form-text" id="coincide" style="font-size:.76rem">&nbsp;</small>
    </div>

    <button type="submit" class="btn btn-primary btn-block" id="btn-guardar">
      Guardar contraseña
    </button>
  </form>

  <div class="cm-aviso cm-aviso--info mt-4 mb-0">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
      <circle cx="12" cy="12" r="10"/><path d="M12 16v-5M12 8h.01" stroke-linecap="round"/>
    </svg>
    <span>
      Al guardar se cerrarán todas las sesiones abiertas de su cuenta, estés
      donde estés. Es la forma de echar fuera a quien no debería estar dentro.
    </span>
  </div>
</div>

<!-- ============ Listo ============ -->
<div id="paso-listo" class="cm-paso d-none text-center">
  <div class="cm-icono-exito mx-auto mb-3">
    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.6" aria-hidden="true">
      <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
  </div>
  <h4 class="mb-2">Contraseña Actualizada</h4>
  <p class="cm-sub">Ya puede entrar con su contraseña nueva.</p>
  <a href="<?= P::attr($rutas->url('entrar')) ?>" class="btn btn-primary btn-block">
    Ir a ingresar
  </a>
</div>


<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var form  = document.getElementById('form-rest');
  var btn   = document.getElementById('btn-guardar');
  var aviso = document.getElementById('aviso');
  var nueva = document.getElementById('nueva');
  var conf  = document.getElementById('confirmar');

  var SVG_ERROR = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01" stroke-linecap="round"/></svg>';

  function error(texto, lista) {
    aviso.className = 'cm-aviso cm-aviso--error';
    aviso.innerHTML = SVG_ERROR + '<div></div>';
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
  // Replica las reglas que el servidor aplica de verdad. Esto es solo
  // para que la persona vea en qué va; la validación que cuenta es la
  // de PHP, que corre igualmente aunque desactiven el JavaScript.
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

    // Una contraseña larga vale más que una corta con muchos tipos.
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

  // ---------- Código: solo dígitos ----------
  var codigo = document.getElementById('codigo');
  codigo.addEventListener('input', function () {
    codigo.value = codigo.value.replace(/[^0-9]/g, '');
  });

  // ---------- Envío ----------
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    aviso.classList.add('d-none');

    if (codigo.value.length !== 6) { error('Escriba los 6 dígitos del código.'); return; }
    if (conf.value !== nueva.value) { error('Las contraseñas no coinciden.'); return; }

    var faltan = Object.keys(REGLAS).filter(function (k) { return !REGLAS[k](nueva.value); });
    if (faltan.length) {
      error('La contraseña todavía no cumple los requisitos marcados abajo.');
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
      btn.textContent = 'Guardar contraseña';

      // Los tokens son de un solo uso: el servidor manda el siguiente.
      if (j.csrf) { form.querySelector('[name="_csrf"]').value = j.csrf; }

      if (!j.ok) {
        error(j.mensaje || 'No se pudo guardar.', j.datos && j.datos.fallos);
        return;
      }

      document.getElementById('paso-form').classList.add('d-none');
      document.getElementById('paso-listo').classList.remove('d-none');
      setTimeout(function () {
        window.location.href = (j.datos && j.datos.redirigir) || form.action;
      }, 2600);
    })
    .catch(function () {
      btn.disabled = false;
      btn.textContent = 'Guardar contraseña';
      error('No se pudo contactar al servidor. Revise su conexión.');
    });
  });
})();
</script>

<?php endif; ?>
