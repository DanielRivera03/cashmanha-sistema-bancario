<?php
/**
 * Ingreso. Misma forma que el sistema base (input-group con icono,
 * "Recordar Usuario", enlace de contraseña olvidada y botón de bloque),
 * más el segundo paso de verificación que el sistema base no tenía.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var string $csrf
 * @var string $error
 */
use CashMan\Http\Plantilla as P;
?>

<!-- ================= PASO 1: credenciales ================= -->
<div id="paso-credenciales" class="cm-paso">

  <h4 class="text-center mb-2">CashMan H.A. | Iniciar Sesión</h4>
  <p class="cm-sub">Ingrese con sus credenciales para continuar.</p>

  <?php if (($error ?? '') !== ''): ?>
  <div class="cm-aviso cm-aviso--error" role="alert">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
      <circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01" stroke-linecap="round"/>
    </svg>
    <span><?= P::e($error) ?></span>
  </div>
  <?php endif; ?>

  <div id="aviso" class="d-none" role="alert"></div>

  <form id="form-acceso" class="form-valide-with-icon" method="post"
        action="<?= P::attr($rutas->url('entrar')) ?>" autocomplete="off" novalidate>
    <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
    <input type="hidden" name="totp" id="totp-oculto" value="">

    <div class="form-group">
      <label class="text-label" for="usuario">Usuario o correo</label>
      <div class="input-group">
        <div class="input-group-prepend">
          <span class="input-group-text">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.1" aria-hidden="true">
              <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2" stroke-linecap="round" stroke-linejoin="round"/>
              <circle cx="12" cy="7" r="4"/>
            </svg>
          </span>
        </div>
        <input type="text" class="form-control" id="usuario" name="usuario"
               placeholder="Usuario" autocomplete="username" required autofocus
               spellcheck="false" autocapitalize="none">
      </div>
    </div>

    <div class="form-group">
      <label class="text-label" for="password">Contraseña</label>
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
        <input type="password" class="form-control" id="password" name="password"
               placeholder="Contraseña" autocomplete="current-password" required>
        <div class="input-group-append show-pass">
          <button class="btn" type="button" id="ver-clave" aria-label="Mostrar contraseña" tabindex="-1">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" aria-hidden="true" id="icono-ojo">
              <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M1 1l22 22" stroke-linecap="round"/>
            </svg>
          </button>
        </div>
      </div>
    </div>

    <div class="form-row d-flex justify-content-between align-items-center mt-4 mb-3">
      <div class="custom-control custom-checkbox ml-1">
        <input type="checkbox" name="recordar" class="custom-control-input" id="recordar" value="1">
        <label class="custom-control-label" for="recordar">Recordar usuario</label>
      </div>
      <a href="<?= P::attr($rutas->url('recuperar')) ?>" style="font-size:.83rem">
        ¿Olvidé mi contraseña?
      </a>
    </div>

    <div class="text-center">
      <button type="submit" class="btn btn-primary btn-block" id="btn-entrar">
        Iniciar Sesión
      </button>
    </div>
  </form>

  <?php /* La puerta para quien todavía no es cliente.
           Sin este enlace el registro existe y no lo encuentra nadie:
           a una pantalla a la que no se llega es como si no estuviera. */ ?>
  <p class="text-center mt-3 mb-0" style="font-size:.86rem">
    ¿Todavía no tiene cuenta?
    <a href="<?= P::attr($rutas->url('registro')) ?>">Créela en un par de minutos</a>
  </p>
</div>

<!-- ================= PASO 2: verificación en dos pasos ================= -->
<div id="paso-totp" class="cm-paso d-none">

  <h4 class="text-center mb-2">Código de Seguridad</h4>
  <p class="cm-sub">
    Abre su aplicación autenticadora y escribe el código de 6 dígitos que muestra
    para <strong id="eco-usuario"></strong>.
  </p>

  <div id="aviso-totp" class="d-none" role="alert"></div>

  <div class="form-group">
    <label class="text-label" for="codigo-totp">Ingresar código aquí</label>
    <div class="input-group">
      <input type="text" class="form-control cm-codigo" id="codigo-totp"
             maxlength="6" inputmode="numeric" autocomplete="one-time-code"
             placeholder="000000">
    </div>
    <small class="form-text text-muted text-center">
      Cambia cada 30 segundos &mdash; quedan <span id="contador">30</span> s.
    </small>
  </div>

  <div class="text-center">
    <button type="button" class="btn btn-primary btn-block" id="btn-verificar">
      Verificar e Ingresar
    </button>
  </div>

  <div class="form-row d-flex justify-content-between align-items-center mt-3">
    <a href="#" id="volver" style="font-size:.82rem">&larr; Volver</a>
    <a href="#" id="usar-respaldo" style="font-size:.82rem">Usar código de respaldo</a>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var form     = document.getElementById('form-acceso');
  var btn      = document.getElementById('btn-entrar');
  var aviso    = document.getElementById('aviso');
  var avisoT   = document.getElementById('aviso-totp');
  var oculto   = document.getElementById('totp-oculto');
  var pasoCred = document.getElementById('paso-credenciales');
  var pasoTotp = document.getElementById('paso-totp');

  var SVG_ERROR = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01" stroke-linecap="round"/></svg>';
  var SVG_OK    = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  function mostrar(caja, texto, tipo) {
    caja.className = 'cm-aviso cm-aviso--' + tipo;
    caja.innerHTML = (tipo === 'exito' ? SVG_OK : SVG_ERROR) + '<span></span>';
    caja.querySelector('span').textContent = texto;
    caja.classList.remove('d-none');
  }

  function ocupado(b, si, textoOcupado) {
    b.disabled = si;
    if (si) {
      b.dataset.texto = b.textContent.trim();
      b.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>' +
                    (textoOcupado || 'Verificando...');
    } else if (b.dataset.texto) {
      b.textContent = b.dataset.texto;
    }
  }

  // ---------- Ver / ocultar contraseña ----------
  var campo = document.getElementById('password');

  var OJO_ABIERTO = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3"/>';
  var OJO_TACHADO = '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24" stroke-linecap="round" stroke-linejoin="round"/><path d="M1 1l22 22" stroke-linecap="round"/>';

  document.getElementById('ver-clave').addEventListener('click', function () {
    var oculta = campo.type === 'password';
    campo.type = oculta ? 'text' : 'password';
    this.setAttribute('aria-label', oculta ? 'Ocultar contraseña' : 'Mostrar contraseña');
    document.getElementById('icono-ojo').innerHTML = oculta ? OJO_ABIERTO : OJO_TACHADO;
    campo.focus();
  });

  // ---------- Envío ----------
  function enviar(btnActivo, cajaAviso) {
    return fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      // Los tokens son de un solo uso: el servidor manda el siguiente.
      if (j.csrf) { form.querySelector('[name="_csrf"]').value = j.csrf; }

      // La contraseña era correcta pero falta el segundo factor.
      if (j.requiere_totp) {
        ocupado(btnActivo, false);
        irAPasoTotp();
        return;
      }

      if (!j.ok) {
        ocupado(btnActivo, false);
        mostrar(cajaAviso, j.mensaje || 'No se pudo iniciar sesión.', 'error');
        return;
      }

      mostrar(cajaAviso, j.mensaje || 'Sesión iniciada.', 'exito');
      window.location.href = (j.datos && j.datos.redirigir) || form.action;
    })
    .catch(function () {
      ocupado(btnActivo, false);
      mostrar(cajaAviso, 'No se pudo contactar al servidor. Revise su conexión.', 'error');
    });
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    aviso.classList.add('d-none');

    if (!document.getElementById('usuario').value.trim() || !campo.value) {
      mostrar(aviso, 'Usuario y contraseña son obligatorios.', 'error');
      return;
    }

    ocupado(btn, true, 'Ingresando...');
    enviar(btn, aviso);
  });

  // ---------- Segundo factor ----------
  var temporizador = null;

  function irAPasoTotp() {
    document.getElementById('eco-usuario').textContent =
      document.getElementById('usuario').value.trim();

    pasoCred.classList.add('d-none');
    pasoTotp.classList.remove('d-none');
    document.getElementById('codigo-totp').focus();

    if (temporizador === null) {
      var c = document.getElementById('contador');
      temporizador = setInterval(function () {
        c.textContent = 30 - (Math.floor(Date.now() / 1000) % 30);
      }, 1000);
    }
  }

  document.getElementById('volver').addEventListener('click', function (ev) {
    ev.preventDefault();
    pasoTotp.classList.add('d-none');
    pasoCred.classList.remove('d-none');
    oculto.value = '';
    avisoT.classList.add('d-none');
    campo.value = '';
    campo.focus();
  });

  var btnVer    = document.getElementById('btn-verificar');
  var campoTotp = document.getElementById('codigo-totp');

  function verificar() {
    var c = campoTotp.value.trim();
    if (c.length < 6) {
      mostrar(avisoT, 'Escriba el código completo.', 'error');
      return;
    }
    avisoT.classList.add('d-none');
    oculto.value = c;
    ocupado(btnVer, true, 'Verificando...');
    enviar(btnVer, avisoT).then(function () { campoTotp.select(); });
  }

  btnVer.addEventListener('click', verificar);

  campoTotp.addEventListener('input', function () {
    // Los códigos de respaldo llevan guion; el del autenticador es solo dígitos.
    var esRespaldo = campoTotp.maxLength > 6;

    campoTotp.value = esRespaldo
      ? campoTotp.value.toUpperCase().replace(/[^A-Z0-9-]/g, '')
      : campoTotp.value.replace(/[^0-9]/g, '');

    if (!esRespaldo && campoTotp.value.length === 6) { verificar(); }
  });

  campoTotp.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter') { ev.preventDefault(); verificar(); }
  });

  document.getElementById('usar-respaldo').addEventListener('click', function (ev) {
    ev.preventDefault();
    campoTotp.maxLength = 14;
    campoTotp.placeholder = 'XXXXX-XXXXX';
    campoTotp.classList.remove('cm-codigo');
    campoTotp.classList.add('text-center');
    campoTotp.value = '';
    campoTotp.focus();
    this.outerHTML = '<span class="text-muted" style="font-size:.8rem">' +
                     'Escriba uno de los códigos que guardaste.</span>';
  });
})();
</script>
