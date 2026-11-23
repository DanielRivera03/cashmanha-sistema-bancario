/* =====================================================================
   CashMan — Interfaz
   Conecta el motor de tiempo real con lo que se ve en pantalla.
   ===================================================================== */
(function (global) {
  'use strict';

  var CM = global.CM || {};

  // ------------------------------------------------------------------
  // Utilidades
  // ------------------------------------------------------------------
  function money(centavos) {
    var signo = centavos < 0 ? '-' : '';
    var abs   = Math.abs(centavos);
    var ent   = Math.floor(abs / 100).toLocaleString('en-US');
    var dec   = String(abs % 100).padStart(2, '0');
    return signo + '$' + ent + '.' + dec;
  }

  function escapar(s) {
    var d = document.createElement('div');
    d.textContent = String(s == null ? '' : s);
    return d.innerHTML;
  }

  /** Actualiza un valor solo si cambió, y lo destella. */
  function fijarValor(el, texto) {
    if (!el || el.textContent === texto) return false;
    el.textContent = texto;
    el.classList.remove('destello');
    void el.offsetWidth;          // reinicia la animación
    el.classList.add('destello');
    return true;
  }

  // ------------------------------------------------------------------
  // Brindis (notificaciones emergentes)
  // ------------------------------------------------------------------
  var ICONOS = {
    exito:   '<path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/>',
    alerta:  '<path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0zM12 9v4M12 17h.01" stroke-linecap="round" stroke-linejoin="round"/>',
    critico: '<circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01" stroke-linecap="round"/>',
    info:    '<circle cx="12" cy="12" r="10"/><path d="M12 16v-5M12 8h.01" stroke-linecap="round"/>'
  };

  function brindis(titulo, mensaje, severidad, duracion) {
    var zona = document.getElementById('brindis-zona');
    if (!zona) return;

    severidad = severidad || 'info';

    var el = document.createElement('div');
    el.className = 'brindis brindis--' + severidad;
    el.setAttribute('role', severidad === 'critico' ? 'alert' : 'status');
    el.innerHTML =
      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
      'stroke-width="2.2" style="flex-shrink:0;margin-top:1px">' + (ICONOS[severidad] || ICONOS.info) + '</svg>' +
      '<div style="flex:1;min-width:0">' +
        '<div class="brindis__titulo">' + escapar(titulo) + '</div>' +
        '<div class="brindis__mensaje">' + escapar(mensaje) + '</div>' +
      '</div>' +
      '<button class="brindis__cerrar" aria-label="Cerrar">&times;</button>';

    el.querySelector('.brindis__cerrar').addEventListener('click', function () { quitar(el); });

    zona.appendChild(el);

    var ms = duracion || (severidad === 'critico' ? 9000 : 5500);
    setTimeout(function () { quitar(el); }, ms);

    // No dejar que se acumulen indefinidamente.
    while (zona.children.length > 5) zona.removeChild(zona.firstChild);
  }

  function quitar(el) {
    if (!el || !el.parentNode) return;
    el.classList.add('saliendo');
    setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 200);
  }

  global.brindis = brindis;

  // ------------------------------------------------------------------
  // Reacción a los eventos en vivo
  // ------------------------------------------------------------------
  if (global.Vivo) {

    // --- Notificación nueva: emerge sin recargar -------------------
    global.Vivo.on('notificacion', function (n) {
      /* Solo se avisa de lo reciente.

         Da igual por qué llegue una notificación vieja —una reconexión
         con un cursor atrasado, un canal que se reabre, un historial sin
         leer—: si se creó hace rato, no es noticia. Sumar en la
         campanita sí; interrumpir con un globo, no.

         Sin esta comprobación, una pestaña abierta con 104 avisos sin
         leer los iba soltando de veinte en veinte cada vez que el canal
         reconectaba, y la pantalla no paraba de mostrar alertas. */
      var RECIENTE_MS = 120000;
      var esReciente = true;

      if (n.creada_en) {
        // La fecha viene como "2026-11-09 13:28:26.822314": se
        // normaliza porque Safari no acepta ese formato con espacio.
        var t = Date.parse(String(n.creada_en).replace(' ', 'T').split('.')[0]);
        if (!isNaN(t)) { esReciente = (Date.now() - t) < RECIENTE_MS; }
      }
      if (esReciente) { brindis(n.titulo, n.mensaje, n.severidad); }
      incrementarContador();

      // En desarrollo, el código OTP llega en la notificación y se
      // escribe solo en el campo: ahorra copiar y pegar al probar.
      if (n.datos && n.datos.codigo_desarrollo) {
        var campo = document.getElementById('codigo-otp');
        if (campo) {
          campo.value = n.datos.codigo_desarrollo;
          campo.dispatchEvent(new Event('input', { bubbles: true }));
        }
      }
    });

    // --- Pulso: saldos y contadores --------------------------------
    global.Vivo.on('pulso', function (p) {
      if (!p) return;

      // Saldo total
      var total = document.querySelector('[data-vivo="saldo-total"]');
      if (total && typeof p.saldo_total === 'number') {
        fijarValor(total, money(p.saldo_total));
      }

      // Saldo por cuenta
      (p.cuentas || []).forEach(function (c) {
        var el = document.querySelector('[data-vivo="saldo"][data-cuenta="' + c.codigo + '"]');
        if (el) fijarValor(el, money(c.saldo));
      });

      // Contador de notificaciones
      if (typeof p.sin_leer === 'number') fijarContador(p.sin_leer);
    });

    global.Vivo.on('conectado', function () {
      // Primera conexión establecida.
    });
  }

  function fijarContador(n) {
    var c = document.getElementById('contador-notif');
    if (!c) return;
    if (n > 0) {
      c.textContent = n > 99 ? '99+' : String(n);
      c.classList.remove('oculto');
    } else {
      c.classList.add('oculto');
    }
  }

  function incrementarContador() {
    var c = document.getElementById('contador-notif');
    if (!c) return;
    var n = parseInt(c.textContent, 10) || 0;
    fijarContador(n + 1);
  }

  // ------------------------------------------------------------------
  // Panel de notificaciones
  // ------------------------------------------------------------------
  var btnNotif   = document.getElementById('btn-notif');
  var panelNotif = document.getElementById('panel-notif');

  if (btnNotif && panelNotif) {
    btnNotif.addEventListener('click', function (e) {
      e.stopPropagation();
      var abierto = !panelNotif.classList.contains('oculto');
      if (abierto) {
        panelNotif.classList.add('oculto');
        btnNotif.setAttribute('aria-expanded', 'false');
      } else {
        cargarNotificaciones();
        panelNotif.classList.remove('oculto');
        btnNotif.setAttribute('aria-expanded', 'true');
      }
    });

    document.addEventListener('click', function (e) {
      if (!panelNotif.contains(e.target) && e.target !== btnNotif) {
        panelNotif.classList.add('oculto');
        btnNotif.setAttribute('aria-expanded', 'false');
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        panelNotif.classList.add('oculto');
        btnNotif.setAttribute('aria-expanded', 'false');
      }
    });
  }

  function cargarNotificaciones() {
    if (!CM.notif) return;

    panelNotif.innerHTML = '<div class="vacio sm">Cargando…</div>';

    fetch(CM.notif, {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var lista = (j.datos && j.datos.notificaciones) || [];

        if (!lista.length) {
          panelNotif.innerHTML =
            '<div class="vacio">' +
            '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">' +
            '<path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
            '<div class="sm">Sin notificaciones</div></div>';
          return;
        }

        var html = '<div style="padding:.7rem .95rem;border-bottom:1px solid var(--borde);' +
                   'display:flex;justify-content:space-between;align-items:center">' +
                   '<strong class="sm">Notificaciones</strong>' +
                   '<button class="btn btn--fantasma btn--sm" id="marcar-todas">Marcar leídas</button></div>';

        lista.forEach(function (n) {
          html += '<div class="panel-notif__item' + (n.leida ? '' : ' no-leida') + '">' +
                  '<div style="flex:1;min-width:0">' +
                  '<div class="sm negrita">' + escapar(n.titulo) + '</div>' +
                  '<div class="xs txt-2">' + escapar(n.mensaje) + '</div>' +
                  '</div></div>';
        });

        panelNotif.innerHTML = html;

        var btn = document.getElementById('marcar-todas');
        if (btn) btn.addEventListener('click', marcarTodas);
      })
      .catch(function () {
        panelNotif.innerHTML = '<div class="vacio sm">No se pudieron cargar.</div>';
      });
  }

  function marcarTodas() {
    fetch(CM.base + 'api/notificaciones/leer', {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        /* El token vigente, no la copia del layout: son de un solo uso
           y esa copia se gasta en la primera llamada. Y su propósito,
           o el servidor lo comprueba contra «global» y nunca cuadra. */
        'X-CSRF-Token': (window.CMToken ? window.CMToken.de('api') : (CM.csrf || '')),
        'X-CSRF-Proposito': 'api',
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: 'id=0',
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function () {
        fijarContador(0);
        cargarNotificaciones();
      });
  }

  // ------------------------------------------------------------------
  // Tema claro / oscuro
  // ------------------------------------------------------------------
  var btnTema = document.getElementById('btn-tema');
  if (btnTema) {
    var guardado = null;
    try { guardado = localStorage.getItem('cm-tema'); } catch (e) {}
    if (guardado) document.documentElement.setAttribute('data-tema', guardado);

    btnTema.addEventListener('click', function () {
      var actual = document.documentElement.getAttribute('data-tema');
      var nuevo;
      if (actual === 'oscuro') nuevo = 'claro';
      else if (actual === 'claro') nuevo = 'oscuro';
      else nuevo = global.matchMedia('(prefers-color-scheme: dark)').matches ? 'claro' : 'oscuro';

      document.documentElement.setAttribute('data-tema', nuevo);
      try { localStorage.setItem('cm-tema', nuevo); } catch (e) {}
    });
  }

  // ------------------------------------------------------------------
  // Barra lateral en móvil
  // ------------------------------------------------------------------
  var alternar = document.getElementById('alternar-barra');
  var barra    = document.getElementById('barra');

  function ajustarBarra() {
    if (!alternar) return;
    alternar.style.display = global.innerWidth <= 1024 ? 'inline-flex' : 'none';
  }

  if (alternar && barra) {
    alternar.addEventListener('click', function (e) {
      e.stopPropagation();
      var abierta = barra.classList.toggle('abierta');
      alternar.setAttribute('aria-expanded', abierta ? 'true' : 'false');
    });

    document.addEventListener('click', function (e) {
      if (global.innerWidth <= 1024 && barra.classList.contains('abierta') &&
          !barra.contains(e.target) && e.target !== alternar) {
        barra.classList.remove('abierta');
        alternar.setAttribute('aria-expanded', 'false');
      }
    });

    global.addEventListener('resize', ajustarBarra);
    ajustarBarra();
  }

  global.CMUtil = { money: money, escapar: escapar, fijarValor: fijarValor };

})(window);
