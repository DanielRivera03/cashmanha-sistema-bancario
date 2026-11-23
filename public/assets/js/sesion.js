/* =====================================================================
   CashMan H.A. — aviso de caducidad de sesión
   ---------------------------------------------------------------------
   Lo que hace cualquier banca en línea: en vez de tirar al usuario sin
   previo aviso en mitad de lo que estuviera haciendo, le dice que
   quedan unos segundos y le deja continuar.

   El tiempo no lo decide esta página: lo marca el rol, y el servidor lo
   dice en /api/sesion. Un cliente tiene menos margen que un cajero
   porque entra desde cualquier equipo y se va; el cajero pasa la
   jornada dentro del sistema en una máquina de la institución.

   Dos detalles que importan:

   1. La consulta del tiempo restante lleva la cabecera de sondeo, así
      que NO reinicia la cuenta. Si la reiniciara, el propio aviso
      mantendría la sesión viva eternamente y el temporizador no
      serviría de nada.

   2. "Continuar" sí hace una petición normal, sin esa cabecera: es una
      acción deliberada del usuario y por eso cuenta como actividad.
   ===================================================================== */
(function (global, documento) {
  'use strict';

  var CM = global.CM || {};
  if (!CM.sesion) { return; }   // la ruta la inyecta el layout

  /* Cada cuánto se pregunta. No hace falta más: el servidor devuelve
     los segundos exactos que quedan, así que entre consulta y consulta
     se descuenta en local. */
  var INTERVALO = 30000;

  var restan   = null;
  var aviso    = 60;
  var caja     = null;
  var reloj    = null;
  var sondeo   = null;
  var cerrando = false;

  /* ---------- Consulta al servidor ---------- */

  function consultar() {
    if (cerrando) { return; }

    fetch(CM.sesion, {
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        // Clave: preguntar no reinicia la cuenta.
        'X-CM-Sondeo': '1'
      }
    })
      .then(function (r) {
        // 401 o una redirección al login significa que ya caducó.
        if (r.status === 401 || r.redirected) { caducada(); return null; }
        return r.json();
      })
      .then(function (j) {
        if (!j || !j.ok) { return; }
        restan = parseInt(j.restan, 10);
        aviso  = parseInt(j.aviso, 10) || 60;
        pintar();
      })
      .catch(function () {
        /* Sin red no se concluye nada: puede ser un corte momentáneo y
           expulsar al usuario por eso sería peor que esperar. */
      });
  }

  /* ---------- Prolongar ---------- */

  function continuar() {
    cerrar();

    // Sin la cabecera de sondeo: esto SÍ es actividad del usuario.
    fetch(CM.sesion.replace('/api/sesion', '/api/pulso'), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(function () {
        consultar();
        if (global.CMAviso) {
          global.CMAviso.exito('Sesión renovada', 'Puede seguir trabajando.');
        }
      })
      .catch(function () { consultar(); });
  }

  /* ---------- Sesión ya caducada ---------- */

  function caducada() {
    if (cerrando) { return; }
    cerrando = true;

    cerrar();
    clearInterval(sondeo);

    var fondo = documento.createElement('div');
    fondo.className = 'cm-modal cm-modal--visible';
    fondo.innerHTML =
      '<div class="cm-modal__caja">' +
        '<div class="cm-modal__icono cm-modal__icono--aviso">' +
          '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" ' +
          'stroke-width="2.3"><circle cx="12" cy="12" r="10"/>' +
          '<path d="M12 6v6l4 2" stroke-linecap="round"/></svg>' +
        '</div>' +
        '<h5 class="cm-modal__titulo">Su sesión se cerró</h5>' +
        '<p class="cm-modal__texto">Por seguridad cerramos la sesión tras un rato ' +
          'sin actividad. Por favor, vuelva a entrar para continuar.</p>' +
        '<div class="cm-modal__pie">' +
          '<a href="' + CM.entrar + '" class="btn btn-primary" style="flex:1">Volver a entrar</a>' +
        '</div>' +
      '</div>';

    documento.body.appendChild(fondo);
    documento.body.style.overflow = 'hidden';
  }

  /* ---------- Aviso previo ---------- */

  function pintar() {
    if (restan === null || cerrando) { return; }

    if (restan <= 0) { caducada(); return; }

    if (restan > aviso) { cerrar(); return; }

    if (caja) { actualizarReloj(); return; }

    caja = documento.createElement('div');
    caja.className = 'cm-sesion';
    caja.setAttribute('role', 'alertdialog');
    caja.innerHTML =
      '<div class="cm-sesion__icono">' +
        '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" ' +
        'stroke-width="2.3"><circle cx="12" cy="12" r="10"/>' +
        '<path d="M12 6v6l4 2" stroke-linecap="round"/></svg>' +
      '</div>' +
      '<div class="cm-sesion__cuerpo">' +
        '<strong class="cm-sesion__titulo">Su sesión está por cerrarse</strong>' +
        '<span class="cm-sesion__texto">Se cerrará en ' +
          '<b class="cm-sesion__reloj">--</b> por inactividad.</span>' +
      '</div>' +
      '<div class="cm-sesion__acciones">' +
        '<button type="button" class="btn btn-sm btn-primary" data-cm="seguir">Continuar</button>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-cm="salir">Salir</button>' +
      '</div>';

    documento.body.appendChild(caja);
    void caja.offsetWidth;
    caja.classList.add('cm-sesion--visible');

    caja.querySelector('[data-cm="seguir"]').addEventListener('click', continuar);
    caja.querySelector('[data-cm="salir"]').addEventListener('click', function () {
      global.location.href = CM.salir || CM.entrar;
    });

    actualizarReloj();
  }

  function actualizarReloj() {
    if (!caja) { return; }

    var el = caja.querySelector('.cm-sesion__reloj');
    var m = Math.floor(restan / 60);
    var s = restan % 60;

    el.textContent = m > 0
      ? m + ' min ' + String(s).padStart(2, '0') + ' s'
      : s + ' segundo' + (s === 1 ? '' : 's');

    // Los últimos quince segundos, en rojo.
    caja.classList.toggle('cm-sesion--urgente', restan <= 15);
  }

  function cerrar() {
    if (!caja) { return; }
    caja.classList.remove('cm-sesion--visible');
    var ref = caja;
    caja = null;
    setTimeout(function () { ref.remove(); }, 240);
  }

  /* ---------- Marcha ---------- */

  // Descuento local entre consultas, para que el reloj corra suave.
  reloj = setInterval(function () {
    if (restan === null || cerrando) { return; }
    restan = Math.max(0, restan - 1);
    pintar();
  }, 1000);

  sondeo = setInterval(consultar, INTERVALO);
  consultar();

  /* Al volver a la pestaña se consulta enseguida: mientras estuvo en
     segundo plano el navegador frena los temporizadores, así que el
     descuento local puede haberse quedado corto. */
  documento.addEventListener('visibilitychange', function () {
    if (!documento.hidden) { consultar(); }
  });

  /* Cualquier acción real del usuario reinicia la cuenta en el
     servidor, pero aquí no nos enteramos hasta la siguiente consulta.
     Para que el aviso no salga justo después de que el usuario haya
     hecho algo, se consulta tras un clic o una tecla si el margen ya
     estaba cerca. */
  var ultimoRefresco = 0;

  ['click', 'keydown'].forEach(function (ev) {
    documento.addEventListener(ev, function () {
      if (cerrando || restan === null) { return; }
      if (restan > aviso * 2) { return; }

      var ahora = Date.now();
      if (ahora - ultimoRefresco < 5000) { return; }
      ultimoRefresco = ahora;

      consultar();
    }, { passive: true });
  });

})(window, document);
