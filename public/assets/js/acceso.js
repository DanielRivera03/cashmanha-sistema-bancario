/* =====================================================================
   Acceso — envío asíncrono del formulario de ingreso
   ===================================================================== */
(function () {
  'use strict';

  var form   = document.getElementById('form-acceso');
  var boton  = document.getElementById('btn-entrar');
  var aviso  = document.getElementById('aviso-js');
  var verBtn = document.getElementById('ver-clave');
  var clave  = document.getElementById('password');

  // --- Mostrar / ocultar contraseña ---------------------------------
  if (verBtn && clave) {
    verBtn.addEventListener('click', function () {
      var visible = clave.type === 'text';
      clave.type = visible ? 'password' : 'text';
      verBtn.setAttribute('aria-label', visible ? 'Mostrar contraseña' : 'Ocultar contraseña');
      clave.focus();
    });
  }

  function mostrarAviso(mensaje, tipo) {
    if (!aviso) return;
    aviso.className = 'aviso aviso--' + (tipo || 'error');
    aviso.textContent = mensaje;
    aviso.classList.remove('oculto');
  }

  function ocuparBoton(ocupado) {
    if (!boton) return;
    boton.disabled = ocupado;
    boton.innerHTML = ocupado
      ? '<span class="btn__hilandera"></span><span>Verificando…</span>'
      : '<span class="btn__texto">Ingresar</span>';
  }

  if (!form) return;

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();

    if (aviso) aviso.classList.add('oculto');

    var usuario = form.usuario.value.trim();
    var pass    = form.password.value;

    if (!usuario || !pass) {
      mostrarAviso('Ingrese su usuario y contraseña.', 'error');
      return;
    }

    ocuparBoton(true);

    // Argon2id tarda ~230 ms a propósito. El indicador de carga evita
    // que el usuario crea que no pasó nada y pulse dos veces.
    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      credentials: 'same-origin'
    })
      .then(function (r) {
        return r.json().then(function (j) { return { estado: r.status, cuerpo: j }; });
      })
      .then(function (res) {
        if (res.cuerpo && res.cuerpo.ok) {
          var destino = (res.cuerpo.datos && res.cuerpo.datos.redirigir) || '';
          if (destino) {
            window.location.assign(destino);
            return;
          }
        }
        ocuparBoton(false);
        mostrarAviso(
          (res.cuerpo && res.cuerpo.mensaje) || 'No se pudo iniciar sesión.',
          res.estado === 429 ? 'alerta' : 'error'
        );
        form.password.value = '';
        form.password.focus();
      })
      .catch(function () {
        ocuparBoton(false);
        mostrarAviso('No hay conexión con el servidor. Revise su red.', 'error');
      });
  });
})();
