/* =====================================================================
   CashMan H.A. — avisos y confirmaciones
   ---------------------------------------------------------------------
   Sustituye a toastr y a SweetAlert por un sistema propio, por tres
   razones concretas:

   1. Coherencia. Los dos traían su propia tipografía, sus colores y sus
      radios de esquina, y ninguno coincidía con el resto del sistema.

   2. Peso. Entre los dos sumaban unos 60 KB de JavaScript y CSS para
      algo que aquí ocupa unas pocas decenas de líneas.

   3. Control. Un sistema bancario necesita que un error de saldo y un
      "guardado correctamente" se vean distintos de un vistazo y que el
      texto no se corte nunca. Eso no se ajusta bien desde fuera.

   API:
     CMAviso.exito(titulo, mensaje)      aparece y se va solo
     CMAviso.error(titulo, mensaje)      se queda hasta que se cierre
     CMAviso.alerta(titulo, mensaje)
     CMAviso.info(titulo, mensaje)
     CMAviso.cargando(texto)   -> devuelve un objeto con .cerrar()
     CMAviso.confirmar(opciones) -> Promise<boolean>

   Se mantiene `window.avisar(titulo, mensaje, severidad)` porque es lo
   que ya llaman las vistas y el canal de tiempo real.
   ===================================================================== */
(function (global, documento) {
  'use strict';

  /* Cuánto se queda cada aviso en pantalla.
   *
   * Ninguno se queda para siempre. Los críticos sí lo hacían —`0`, sin
   * temporizador—, y la pantalla acababa con una pila de recuadros
   * rojos de hace diez minutos que no decían nada de lo que estaba
   * pasando ahora; al final se leen todos como ruido y no se lee
   * ninguno. Duran más que el resto, porque importan más, pero se van.
   *
   * El que necesite volver a leerlo lo tiene en el historial de
   * notificaciones, y el que lo esté leyendo cuando toque irse solo
   * tiene que dejar el ratón encima: la cuenta se detiene.
   *
   * Estos son el mínimo; `vidaDe()` lo alarga según lo que haya que
   * leer. */
  var DURACION = { exito: 4000, info: 5000, alerta: 7000, critico: 9000 };

  /* El tope, para que ni el mensaje más largo se quede clavado. */
  var VIDA_MAXIMA = 15000;

  /**
   * El tiempo que hay que dejar un aviso en pantalla.
   *
   * Un «Guardado» y un rechazo de tarjeta con el motivo y el código no
   * se leen en el mismo tiempo, y darles los mismos segundos deja uno
   * eterno y el otro a medio leer. Se parte del mínimo del tipo y se
   * suma el tiempo de lectura: unas 200 palabras por minuto, que es
   * lectura tranquila, más un momento para darse cuenta de que salió.
   */
  function vidaDe(tipo, titulo, mensaje) {
    var letras  = String(titulo || '').length + String(mensaje || '').length;
    var leyendo = 900 + (letras / 5) * (60000 / 200);

    return Math.min(VIDA_MAXIMA, Math.max(DURACION[tipo] || 5000, Math.round(leyendo)));
  }
  var MAXIMO   = 5;

  var ICONOS = {
    exito:  '<path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/>',
    critico:'<circle cx="12" cy="12" r="10"/><path d="M12 7v6M12 16.5h.01" stroke-linecap="round"/>',
    alerta: '<path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z" stroke-linejoin="round"/><path d="M12 9v4M12 17h.01" stroke-linecap="round"/>',
    info:   '<circle cx="12" cy="12" r="10"/><path d="M12 16v-5M12 8h.01" stroke-linecap="round"/>',
    carga:  '<path d="M21 12a9 9 0 11-6.2-8.6" stroke-linecap="round"/>'
  };

  var pila = null;

  /* Deduplicado.
     Varias capas pueden avisar de lo mismo: el interceptor global y,
     además, la vista que hizo la petición. En vez de obligar a cada
     vista a callarse, se ignora el mensaje repetido si llega enseguida
     detrás del anterior. El usuario ve uno, que es lo que quiere. */
  var recientes = Object.create(null);
  var VENTANA_DUPLICADO = 2500;

  function esDuplicado(severidad, mensaje) {
    if (!mensaje) { return false; }

    var clave  = severidad + '\u0000' + mensaje;
    var ahora  = Date.now();
    var previo = recientes[clave];

    recientes[clave] = ahora;

    // Limpieza para que el objeto no crezca sin fin.
    if (Object.keys(recientes).length > 40) {
      Object.keys(recientes).forEach(function (k) {
        if (ahora - recientes[k] > VENTANA_DUPLICADO) { delete recientes[k]; }
      });
    }

    return previo !== undefined && (ahora - previo) < VENTANA_DUPLICADO;
  }

  function contenedor() {
    if (pila && documento.body.contains(pila)) { return pila; }
    pila = documento.createElement('div');
    pila.className = 'cm-avisos';
    pila.setAttribute('role', 'region');
    pila.setAttribute('aria-live', 'polite');
    pila.setAttribute('aria-label', 'Notificaciones');
    documento.body.appendChild(pila);
    return pila;
  }

  function svg(clave, tamano) {
    return '<svg viewBox="0 0 24 24" width="' + (tamano || 18) + '" height="' + (tamano || 18) +
           '" fill="none" stroke="currentColor" stroke-width="2.3" aria-hidden="true">' +
           (ICONOS[clave] || ICONOS.info) + '</svg>';
  }

  /**
   * Muestra un aviso.
   *
   * El texto se inserta con textContent, nunca como HTML: estos
   * mensajes vienen del servidor y pueden llevar datos que escribió un
   * usuario (el nombre de un cliente, el concepto de una transferencia).
   */
  function mostrar(severidad, titulo, mensaje, opciones) {
    opciones = opciones || {};

    if (esDuplicado(severidad, mensaje || titulo)) {
      return { cerrar: function () {}, elemento: null };
    }

    var caja = contenedor();

    // Nunca más de unos pocos a la vez: si se acumulan, no se lee ninguno.
    while (caja.children.length >= MAXIMO) {
      quitar(caja.firstElementChild, true);
    }

    var tipo = ICONOS[severidad] ? severidad : 'info';
    var vida = opciones.duracion !== undefined
      ? opciones.duracion
      : vidaDe(tipo, titulo, mensaje);

    var el = documento.createElement('div');
    el.className = 'cm-aviso-toast cm-aviso-toast--' + tipo;
    el.setAttribute('role', tipo === 'critico' ? 'alert' : 'status');

    var icono = documento.createElement('span');
    icono.className = 'cm-aviso-toast__icono';
    icono.innerHTML = svg(opciones.icono || tipo);

    var cuerpo = documento.createElement('div');
    cuerpo.className = 'cm-aviso-toast__cuerpo';

    if (titulo) {
      var t = documento.createElement('strong');
      t.className = 'cm-aviso-toast__titulo';
      t.textContent = titulo;
      cuerpo.appendChild(t);
    }

    if (mensaje) {
      var m = documento.createElement('span');
      m.className = 'cm-aviso-toast__texto';
      m.textContent = mensaje;
      cuerpo.appendChild(m);
    }

    el.appendChild(icono);
    el.appendChild(cuerpo);

    if (!opciones.sinCerrar) {
      var cerrar = documento.createElement('button');
      cerrar.type = 'button';
      cerrar.className = 'cm-aviso-toast__cerrar';
      cerrar.setAttribute('aria-label', 'Cerrar');
      cerrar.innerHTML =
        '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" ' +
        'stroke-width="2.4"><path d="M18 6L6 18M6 6l12 12" stroke-linecap="round"/></svg>';
      cerrar.addEventListener('click', function () { quitar(el); });
      el.appendChild(cerrar);
    }

    if (vida > 0) {
      var barra = documento.createElement('i');
      barra.className = 'cm-aviso-toast__barra';
      barra.style.animationDuration = vida + 'ms';
      el.appendChild(barra);
    }

    caja.appendChild(el);

    // Fuerza el cálculo de estilos para que la transición de entrada corra.
    void el.offsetWidth;
    el.classList.add('cm-aviso-toast--visible');

    var temporizador = null;

    function programar() {
      if (vida > 0) { temporizador = setTimeout(function () { quitar(el); }, vida); }
    }

    // Al pasar el ratón se detiene la cuenta: un mensaje largo necesita
    // tiempo para leerse, y el que lee suele apuntar con el ratón.
    el.addEventListener('mouseenter', function () {
      clearTimeout(temporizador);
      el.classList.add('cm-aviso-toast--pausa');
    });
    el.addEventListener('mouseleave', function () {
      el.classList.remove('cm-aviso-toast--pausa');
      programar();
    });

    programar();

    return {
      cerrar: function () { quitar(el); },
      elemento: el
    };
  }

  function quitar(el, inmediato) {
    if (!el || el.dataset.saliendo === '1') { return; }
    el.dataset.saliendo = '1';

    if (inmediato) { el.remove(); return; }

    el.classList.remove('cm-aviso-toast--visible');
    el.classList.add('cm-aviso-toast--saliendo');
    setTimeout(function () { el.remove(); }, 260);
  }

  /**
   * Aviso de operación en curso.
   *
   * Lo cierra quien lo abrió, en cuanto la operación termina. Pero si
   * eso no llega a pasar —la petición se quedó colgada, la respuesta
   * vino rota y nadie llamó a `.cerrar()`— se va solo al cabo de un
   * minuto: no tiene botón de cerrar, así que un «Procesando…» sin
   * temporizador se queda ahí hasta que se recargue la página,
   * diciendo que algo está pasando cuando ya no pasa nada.
   *
   * El velo de pantalla completa tiene la misma red de seguridad, con
   * medio minuto: ese sí bloquea, y conviene quitarlo antes.
   */
  function cargando(texto, tope) {
    var aviso = mostrar('info', texto || 'Procesando…', '', {
      duracion: 0, sinCerrar: true, icono: 'carga'
    });

    var limite = typeof tope === 'number' ? tope : 60000;
    var solo   = limite > 0 ? setTimeout(function () { aviso.cerrar(); }, limite) : null;

    return {
      elemento: aviso.elemento,
      cerrar: function () {
        if (solo) { clearTimeout(solo); solo = null; }
        aviso.cerrar();
      }
    };
  }

  /* -------------------------------------------------------------------
     Confirmación
     ------------------------------------------------------------------- */

  /**
   * Diálogo de confirmación. Devuelve una promesa que resuelve a
   * true o false.
   *
   * Para acciones destructivas (`peligro: true`) el botón de confirmar
   * va en rojo y el foco arranca en Cancelar, para que un Enter de
   * inercia no borre nada.
   */
  function confirmar(opciones) {
    opciones = opciones || {};

    return new Promise(function (resolver) {
      var fondo = documento.createElement('div');
      fondo.className = 'cm-modal';
      fondo.setAttribute('role', 'dialog');
      fondo.setAttribute('aria-modal', 'true');

      var caja = documento.createElement('div');
      caja.className = 'cm-modal__caja';

      var peligro = opciones.peligro === true;

      var icono = documento.createElement('div');
      icono.className = 'cm-modal__icono cm-modal__icono--' + (peligro ? 'peligro' : 'aviso');
      icono.innerHTML = svg(peligro ? 'critico' : 'alerta', 26);

      var titulo = documento.createElement('h5');
      titulo.className = 'cm-modal__titulo';
      titulo.textContent = opciones.titulo || '¿Confirmas esta acción?';

      var texto = documento.createElement('p');
      texto.className = 'cm-modal__texto';
      texto.textContent = opciones.mensaje || '';

      var pie = documento.createElement('div');
      pie.className = 'cm-modal__pie';

      var btnNo = documento.createElement('button');
      btnNo.type = 'button';
      btnNo.className = 'btn btn-outline-secondary';
      btnNo.textContent = opciones.cancelar || 'Cancelar';

      var btnSi = documento.createElement('button');
      btnSi.type = 'button';
      btnSi.className = 'btn ' + (peligro ? 'btn-danger' : 'btn-primary');
      btnSi.textContent = opciones.confirmar || 'Sí, continuar';

      pie.appendChild(btnNo);
      pie.appendChild(btnSi);

      /* Un campo de texto cuando hace falta un motivo y no un sí o un
         no. Reutiliza este mismo diálogo en vez de abrir un `prompt()`
         del navegador, que no se puede estilar, no se puede validar y
         en una pantalla de anulaciones parece un fallo del sistema.

         Con campo, la promesa resuelve con el TEXTO —o `null` si se
         canceló—; sin campo sigue resolviendo `true`/`false`, que es
         lo que esperan las llamadas de siempre. */
      var campo = null;

      if (opciones.campo) {
        campo = documento.createElement(opciones.campo.largo ? 'textarea' : 'input');
        campo.className = 'form-control cm-modal__campo';
        campo.setAttribute('aria-label', opciones.campo.etiqueta || 'Motivo');

        if (opciones.campo.largo)  { campo.rows = 3; }
        if (opciones.campo.pista)  { campo.placeholder = opciones.campo.pista; }
        if (opciones.campo.minimo) { campo.minLength = opciones.campo.minimo; }
      }

      caja.appendChild(icono);
      caja.appendChild(titulo);
      if (opciones.mensaje) { caja.appendChild(texto); }
      if (campo) { caja.appendChild(campo); }
      caja.appendChild(pie);
      fondo.appendChild(caja);
      documento.body.appendChild(fondo);

      var antes = documento.activeElement;
      documento.body.style.overflow = 'hidden';

      void fondo.offsetWidth;
      fondo.classList.add('cm-modal--visible');

      // Con campo, el foco va ahí: es lo que hay que rellenar.
      if (campo) { campo.focus(); } else { (peligro ? btnNo : btnSi).focus(); }

      function cerrar(valor) {
        documento.removeEventListener('keydown', teclado);
        documento.body.style.overflow = '';
        fondo.classList.remove('cm-modal--visible');
        setTimeout(function () {
          fondo.remove();
          if (antes && antes.focus) { antes.focus(); }
        }, 200);
        resolver(valor);
      }

      function teclado(ev) {
        if (ev.key === 'Escape') { ev.preventDefault(); cerrar(campo ? null : false); return; }

        // El foco no debe salir del diálogo mientras está abierto.
        if (ev.key === 'Tab' && !campo) {
          ev.preventDefault();
          (documento.activeElement === btnSi ? btnNo : btnSi).focus();
        }
      }

      btnNo.addEventListener('click', function () { cerrar(campo ? null : false); });

      btnSi.addEventListener('click', function () {
        if (!campo) { cerrar(true); return; }

        var v = campo.value.trim();
        var min = opciones.campo.minimo || 1;

        /* Un motivo vacío no sirve de motivo: lo va a leer quien
           audite la decisión dentro de seis meses. Se marca el campo
           en vez de cerrar el diálogo y perder lo escrito. */
        if (v.length < min) {
          campo.classList.add('cm-invalido');
          campo.focus();
          return;
        }

        cerrar(v);
      });

      if (campo) {
        campo.addEventListener('input', function () {
          campo.classList.remove('cm-invalido');
        });
      }
      fondo.addEventListener('click', function (ev) {
        if (ev.target === fondo) { cerrar(false); }
      });
      documento.addEventListener('keydown', teclado);
    });
  }

  /* -------------------------------------------------------------------
     Indicador de espera a pantalla completa
     -------------------------------------------------------------------

     Para lo que tarda y no se ve: generar un PDF, hablar con la
     procesadora, aplicar un cobro. Sin esto la pantalla se queda igual
     que estaba, el usuario cree que no pulsó y vuelve a pulsar. En una
     pasarela eso son dos cobros preparados.

     Tapa la pantalla a propósito: mientras hay una operación con
     dinero a medias no debería poder tocarse nada más.
  */

  var ICONOS_CARGA = {
    documento: '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>'
             + '<path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
    tarjeta:   '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
    reloj:     '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2" stroke-linecap="round"/>'
  };

  var capaCarga  = null;
  var cerrarseEn = null;

  /**
   * Abre el indicador. Devuelve una función que lo cierra, para que
   * quien lo abrió pueda cerrarlo sin saber nada del resto.
   */
  function abrirCarga(opciones) {
    opciones = opciones || {};

    // Uno solo: dos superpuestos dejan el de abajo encendido para
    // siempre cuando el de arriba se cierra.
    cerrarCarga(true);

    var fondo = documento.createElement('div');
    fondo.className = 'cm-carga';
    fondo.setAttribute('role', 'status');
    fondo.setAttribute('aria-live', 'polite');

    var icono = ICONOS_CARGA[opciones.icono] || ICONOS_CARGA.reloj;

    var caja = documento.createElement('div');
    caja.className = 'cm-carga__caja';
    caja.innerHTML =
        '<div class="cm-carga__anillo">'
      +   '<div class="cm-carga__icono">'
      +     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">'
      +       icono + '</svg>'
      +   '</div>'
      + '</div>'
      + '<div class="cm-carga__titulo"></div>'
      + '<div class="cm-carga__texto"></div>'
      + '<div class="cm-carga__barra"></div>'
      + '<div class="cm-carga__pie"></div>';

    /* Por `textContent`, nunca por `innerHTML`: estos textos vienen a
       veces del servidor y no tienen por qué ser seguros como HTML. */
    caja.querySelector('.cm-carga__titulo').textContent =
        opciones.titulo || 'Un momento';
    caja.querySelector('.cm-carga__texto').textContent =
        opciones.mensaje || 'Estamos preparando lo que pidió.';
    caja.querySelector('.cm-carga__pie').textContent = opciones.pie || '';

    fondo.appendChild(caja);
    documento.body.appendChild(fondo);

    // Un fotograma de margen para que la transición arranque.
    requestAnimationFrame(function () { fondo.classList.add('cm-carga--visible'); });

    capaCarga = fondo;

    /* Red de seguridad. Si lo que se espera no contesta nunca —el
       servidor se cayó, la pestaña se cerró—, un velo que no se quita
       deja la aplicación inutilizable. Antes de eso se quita solo. */
    var tope = typeof opciones.tope === 'number' ? opciones.tope : 30000;

    if (tope > 0) {
      cerrarseEn = setTimeout(function () { cerrarCarga(); }, tope);
    }

    return function () { cerrarCarga(); };
  }

  function cerrarCarga(inmediato) {
    if (cerrarseEn) { clearTimeout(cerrarseEn); cerrarseEn = null; }

    var fondo = capaCarga;
    capaCarga = null;

    if (!fondo) { return; }

    if (inmediato) {
      if (fondo.parentNode) { fondo.parentNode.removeChild(fondo); }
      return;
    }

    fondo.classList.remove('cm-carga--visible');

    setTimeout(function () {
      if (fondo.parentNode) { fondo.parentNode.removeChild(fondo); }
    }, 240);
  }

  /* -------------------------------------------------------------------
     API pública
     ------------------------------------------------------------------- */

  var CMAviso = {
    mostrar:   mostrar,
    exito:     function (t, m, o) { return mostrar('exito',   t, m, o); },
    error:     function (t, m, o) { return mostrar('critico', t, m, o); },
    alerta:    function (t, m, o) { return mostrar('alerta',  t, m, o); },
    info:      function (t, m, o) { return mostrar('info',    t, m, o); },
    cargando:  cargando,
    confirmar: confirmar,

    /* Pedir un texto, en lugar del `prompt()` del navegador.
       Resuelve con lo escrito, o con `null` si se canceló. */
    pedirTexto: function (titulo, opciones) {
      opciones = opciones || {};

      return confirmar({
        titulo:    titulo,
        mensaje:   opciones.mensaje || '',
        confirmar: opciones.boton || 'Continuar',
        peligro:   opciones.peligro === true,
        campo: {
          etiqueta: opciones.etiqueta || titulo,
          pista:    opciones.pista || '',
          largo:    opciones.largo !== false,
          minimo:   opciones.minimo || 5
        }
      });
    },
    // El velo de pantalla completa, para lo que tarda de verdad.
    esperar:   abrirCarga,
    listo:     function () { cerrarCarga(); },
    limpiar:   function () {
      if (pila) {
        Array.prototype.slice.call(pila.children).forEach(function (e) { quitar(e); });
      }
    }
  };

  global.CMAviso = CMAviso;

  // Compatibilidad: es la firma que ya usan las vistas y el canal de
  // tiempo real, así que se conserva tal cual.
  global.avisar = function (titulo, mensaje, severidad) {
    return mostrar(severidad || 'info', titulo, mensaje);
  };

})(window, document);

/* =====================================================================
   RESPALDO DE `CMProceso`
   =====================================================================

   `proceso.js` dibuja el panel de pasos de una operación. Es adorno:
   ayuda a entender qué está pasando, pero la operación funciona sin
   él.

   El problema es que las vistas lo llaman para MANDAR el formulario,
   no solo para dibujar. Y eso convirtió un adorno en una pieza
   crítica: el día que el panel se quedó sin cargar `proceso.js` —un
   parche que abortó a medias y se llevó el `<script>` con él—,
   `CMProceso` no existía, el manejador reventaba justo después de
   deshabilitar el botón, y la pantalla se quedaba quieta. Sin aviso,
   sin error visible y sin forma de volver a intentarlo: exactamente
   lo que se siente como «le di guardar y no pasó nada».

   Esto es el suelo. Si `proceso.js` está, no hace nada: el `if` de
   abajo ve que `CMProceso` ya existe y se aparta. Si no está, deja
   una versión sin panel que hace la petición igual y avisa con un
   toast. Más feo, pero el formulario se manda.

   Vive en `avisos.js` porque este sí se carga en los tres marcos.
   ===================================================================== */
(function (global) {
  'use strict';

  if (global.CMProceso) { return; }

  function nada() { return api; }

  var api = {
    paso: nada, avanzar: nada, nota: nada, detalle: nada,
    listo: function (t) {
      try {
        if (t && global.CMAviso) { global.CMAviso.exito('Listo', t); }
      } catch (e) {}
      return api;
    },
    fallo: function (t) {
      try {
        if (global.CMAviso) {
          global.CMAviso.critico('No se pudo', t || 'La operación no se completó.');
        }
      } catch (e) {}
      return api;
    },
    cerrar: nada,
    elemento: null
  };

  global.CMProceso = {
    abrir: function () { return api; },

    enviar: function (url, opciones) {
      opciones = opciones || {};
      opciones.method = opciones.method || 'POST';
      opciones.credentials = opciones.credentials || 'same-origin';
      opciones.headers = opciones.headers || {};

      if (!opciones.headers['X-Requested-With']) {
        opciones.headers['X-Requested-With'] = 'XMLHttpRequest';
      }

      /* El aviso de «procesando» va dentro de un `try`.
       *
       * Todo este respaldo existe para que el formulario se mande
       * pase lo que pase. Sería absurdo que se quedara sin mandar
       * porque falló el toast que solo sirve para hacer la espera
       * más llevadera: el adorno no puede volver a tumbar la
       * operación, que es justo el fallo que vino a arreglar. */
      var carga = null;

      try {
        if (global.CMAviso) { carga = global.CMAviso.cargando('Procesando…'); }
      } catch (e) { /* sin aviso, pero la petición sale igual */ }

      function quitarCarga() {
        try { if (carga) { carga.cerrar(); } } catch (e) {}
      }

      return fetch(url, opciones)
        .then(function (r) {
          return r.json()
            .then(function (j) { return { ok: r.ok, estado: r.status, j: j }; })
            .catch(function () { return { ok: false, estado: r.status, j: null }; });
        })
        .then(function (res) {
          quitarCarga();

          var j = res.j;
          var bien = res.ok && j && (j.exito === true || j.ok === true);

          if (!bien) {
            var m = (j && (j.mensaje || j.error))
              || 'No se pudo completar la operación. Intente de nuevo.';

            api.fallo(m);

            var e = new Error(m);
            e.respuesta = j;
            e.estado = res.estado;
            e.mostrado = true;
            throw e;
          }

          return j;
        })
        .catch(function (e) {
          quitarCarga();

          if (!e.mostrado) {
            api.fallo('No se pudo conectar con el servidor. Revise su conexión.');
            e.mostrado = true;
          }

          throw e;
        });
    },

    catalogo: {},
    actual: function () { return null; }
  };

  if (global.console && global.console.warn) {
    global.console.warn('[cm] proceso.js no está cargado: se usa el respaldo sin panel.');
  }
})(window);
