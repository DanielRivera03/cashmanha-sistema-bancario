<?php
/**
 * Solicitud de recuperación.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;
?>

<!-- ============ PASO 1: pedir el correo ============ -->
<div id="paso-pedir" class="cm-paso">

  <h4 class="text-center mb-2">Recuperar Tu Contraseña</h4>
  <p class="cm-sub">
    Escriba el correo con el que te registraste. Te enviaremos un enlace y un
    código de un solo uso para definir una contraseña nueva.
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

  <form id="form-rec" class="form-valide" method="post"
        action="<?= P::attr($rutas->url('recuperar')) ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">

    <div class="form-group">
      <label class="text-label" for="correo">Correo</label>
      <div class="input-group">
        <div class="input-group-prepend">
          <span class="input-group-text">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.1" aria-hidden="true">
              <rect x="2" y="4" width="20" height="16" rx="2"/>
              <path d="M22 6l-10 7L2 6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </span>
        </div>
        <input type="email" class="form-control" id="correo" name="correo"
               placeholder="Escriba su correo..." required autocomplete="email"
               autofocus spellcheck="false" autocapitalize="none">
      </div>
    </div>

    <div class="text-center mt-4">
      <button type="submit" class="btn btn-primary btn-block" id="btn-enviar">
        Enviar Instrucciones
      </button>
    </div>
  </form>

  <p class="text-center mt-3 mb-0" style="font-size:.83rem">
    <a href="<?= P::attr($rutas->url('entrar')) ?>">Volver a iniciar sesión</a>
  </p>
</div>

<!-- ============ PASO 2: confirmación ============ -->
<div id="paso-enviado" class="cm-paso d-none text-center">

  <div class="cm-icono-exito mx-auto mb-3">
    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.2" aria-hidden="true">
      <rect x="2" y="4" width="20" height="16" rx="2"/>
      <path d="M22 6l-10 7L2 6" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
  </div>

  <h4 class="mb-2">Revise Su Correo</h4>
  <p class="cm-sub">
    Si <strong id="eco-correo"></strong> está registrado, en unos minutos
    recibirá un mensaje con el enlace y el código de verificación.
  </p>

  <div class="cm-aviso cm-aviso--alerta text-left">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
      <path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z" stroke-linejoin="round"/>
      <path d="M12 9v4M12 17h.01" stroke-linecap="round"/>
    </svg>
    <span>
      El enlace vence en 30 minutos y sirve una sola vez. Si no llega,
      revisa la carpeta de correo no deseado.
    </span>
  </div>

  <a href="<?= P::attr($rutas->url('entrar')) ?>" class="btn btn-primary btn-block">
    Volver a Iniciar Sesión
  </a>

  <p class="mt-3 mb-0" style="font-size:.83rem">
    <a href="#" id="otra-vez">Usar otro correo</a>
  </p>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var form  = document.getElementById('form-rec');
  var btn   = document.getElementById('btn-enviar');
  var aviso = document.getElementById('aviso');

  var SVG_ERROR = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01" stroke-linecap="round"/></svg>';

  function error(texto) {
    aviso.className = 'cm-aviso cm-aviso--error';
    aviso.innerHTML = SVG_ERROR + '<span></span>';
    aviso.querySelector('span').textContent = texto;
    aviso.classList.remove('d-none');
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    aviso.classList.add('d-none');

    var correo = document.getElementById('correo').value.trim();

    if (correo.indexOf('@') < 1 || correo.indexOf('.') < 0) {
      error('Escriba un correo electrónico válido.');
      return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>Enviando...';

    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      btn.disabled = false;
      btn.textContent = 'Enviar Instrucciones';

      // Los tokens son de un solo uso: el servidor manda el siguiente.
      if (j.csrf) { form.querySelector('[name="_csrf"]').value = j.csrf; }

      if (!j.ok) { error(j.mensaje || 'No se pudo procesar la solicitud.'); return; }

      document.getElementById('eco-correo').textContent = correo;
      document.getElementById('paso-pedir').classList.add('d-none');
      document.getElementById('paso-enviado').classList.remove('d-none');
    })
    .catch(function () {
      btn.disabled = false;
      btn.textContent = 'Enviar Instrucciones';
      error('No se pudo contactar al servidor. Revise su conexión.');
    });
  });

  document.getElementById('otra-vez').addEventListener('click', function (ev) {
    ev.preventDefault();
    document.getElementById('paso-enviado').classList.add('d-none');
    document.getElementById('paso-pedir').classList.remove('d-none');
    document.getElementById('correo').focus();
  });
})();
</script>
