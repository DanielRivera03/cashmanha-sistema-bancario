/* =====================================================================
   CashMan — Motor de tiempo real
   ---------------------------------------------------------------------
   Dos canales que se complementan:

     SSE      → el servidor EMPUJA notificaciones y cambios de saldo.
                Latencia de ~2 segundos, sin que el navegador pregunte.

     Polling  → respaldo con ETag. Si el navegador no soporta SSE, o la
                conexión se cae repetidamente, se cambia a consultas
                periódicas. Cuando nada cambió el servidor responde 304
                y no se transfiere ni un byte de cuerpo.

   La página nunca se recarga. Los valores se actualizan en su sitio y
   destellan brevemente para que el usuario note qué cambió.
   ===================================================================== */
(function (global) {
  'use strict';

  var CM = global.CM || {};

  var fuente        = null;   // EventSource
  var temporizador  = null;   // polling
  var etagPulso     = null;
  var ultimoEventId = 0;
  var reintentos    = 0;
  var modo          = 'sse';
  var activo        = true;

  var MAX_REINTENTOS   = 4;
  var INTERVALO_POLLING = 5000;

  var oyentes = {};

  // ------------------------------------------------------------------
  // Bus de eventos interno
  // ------------------------------------------------------------------
  function on(evento, fn) {
    (oyentes[evento] = oyentes[evento] || []).push(fn);
  }

  function emitir(evento, datos) {
    (oyentes[evento] || []).forEach(function (fn) {
      try { fn(datos); } catch (e) { console.error('[vivo]', evento, e); }
    });
  }

  // ------------------------------------------------------------------
  // Indicador de conexión
  // ------------------------------------------------------------------
  function estado(clase, texto) {
    var el = document.getElementById('estado-vivo');
    if (!el) return;
    el.className = 'en-vivo ' + clase;
    var t = el.querySelector('.en-vivo__texto');
    if (t) t.textContent = texto;
  }

  // ------------------------------------------------------------------
  // Canal SSE
  // ------------------------------------------------------------------
  function abrirSSE() {
    if (!global.EventSource || !CM.sse) {
      iniciarPolling();
      return;
    }

    try {
      fuente = new EventSource(CM.sse + '?desde=' + ultimoEventId, { withCredentials: true });
    } catch (e) {
      iniciarPolling();
      return;
    }

    fuente.addEventListener('open', function () {
      reintentos = 0;
      modo = 'sse';
      estado('conectado', 'en vivo');
    });

    fuente.addEventListener('conectado', function (ev) {
      reintentos = 0;
      estado('conectado', 'en vivo');
      emitir('conectado', parse(ev.data));
    });

    fuente.addEventListener('notificacion', function (ev) {
      var n = parse(ev.data);
      if (!n) return;
      if (ev.lastEventId) ultimoEventId = parseInt(ev.lastEventId, 10) || ultimoEventId;
      emitir('notificacion', n);
    });

    fuente.addEventListener('pulso', function (ev) {
      var p = parse(ev.data);
      if (p) emitir('pulso', p);
    });

    // El servidor corta a los ~45 s para no acaparar un worker de
    // Apache. Esa desconexión es normal: se reabre de inmediato.
    fuente.addEventListener('reconectar', function () {
      cerrarSSE();
      if (activo) setTimeout(abrirSSE, 400);
    });

    fuente.addEventListener('error', function () {
      cerrarSSE();
      if (!activo) return;

      reintentos++;
      if (reintentos > MAX_REINTENTOS) {
        // SSE no funciona en este entorno: pasar a polling.
        iniciarPolling();
        return;
      }

      estado('reconectando', 'reconectando…');
      // Retroceso exponencial con tope.
      var espera = Math.min(1000 * Math.pow(2, reintentos - 1), 15000);
      setTimeout(function () { if (activo) abrirSSE(); }, espera);
    });
  }

  function cerrarSSE() {
    if (fuente) {
      try { fuente.close(); } catch (e) {}
      fuente = null;
    }
  }

  // ------------------------------------------------------------------
  // Polling con ETag
  // ------------------------------------------------------------------
  function iniciarPolling() {
    if (temporizador) return;
    modo = 'polling';
    estado('conectado', 'actualizando');
    consultarPulso();
    temporizador = setInterval(consultarPulso, INTERVALO_POLLING);
  }

  function detenerPolling() {
    if (temporizador) { clearInterval(temporizador); temporizador = null; }
  }

  function consultarPulso() {
    if (!activo || !CM.pulso) return;

    // Esta cabecera le dice al servidor que la petición es del
    // sondeo y no de una acción del usuario, para que no cuente
    // como actividad y la sesión pueda caducar por inactividad.
    var cab = { 'Accept': 'application/json', 'X-CM-Sondeo': '1' };
    if (etagPulso) cab['If-None-Match'] = etagPulso;

    fetch(CM.pulso, { headers: cab, credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 304) return null;  // nada cambió
        if (r.status === 401) { global.location.assign(CM.base + 'entrar'); return null; }

        var et = r.headers.get('ETag');
        if (et) etagPulso = et;
        return r.json();
      })
      .then(function (p) {
        if (p) emitir('pulso', p);
        estado('conectado', 'actualizando');
      })
      .catch(function () {
        estado('reconectando', 'sin conexión');
      });
  }

  function parse(txt) {
    try { return JSON.parse(txt); } catch (e) { return null; }
  }

  // ------------------------------------------------------------------
  // Ahorro de recursos: pausar cuando la pestaña no se ve
  // ------------------------------------------------------------------
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      activo = false;
      cerrarSSE();
      detenerPolling();
      estado('', 'en pausa');
    } else {
      activo = true;
      reintentos = 0;
      if (modo === 'sse') abrirSSE(); else iniciarPolling();
    }
  });

  global.addEventListener('beforeunload', function () {
    activo = false;
    cerrarSSE();
    detenerPolling();
  });

  // ------------------------------------------------------------------
  // API pública
  // ------------------------------------------------------------------
  global.Vivo = {
    iniciar: function () {
      activo = true;
      abrirSSE();
    },
    on: on,
    modo: function () { return modo; },
    refrescar: consultarPulso
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', global.Vivo.iniciar);
  } else {
    global.Vivo.iniciar();
  }

})(window);
