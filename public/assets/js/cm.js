/* =====================================================================
   CashMan v2 — interfaz sobre Chrev
   Conecta el motor de tiempo real con la plantilla.
   ===================================================================== */
(function (global, $) {
  'use strict';

  var CM = global.CM || {};

  // ------------------------------------------------------------------
  // Utilidades
  // ------------------------------------------------------------------
  function money(c) {
    var s = c < 0 ? '-' : '', a = Math.abs(c);
    return s + '$' + Math.floor(a / 100).toLocaleString('en-US') +
           '.' + String(a % 100).padStart(2, '0');
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = String(s == null ? '' : s);
    return d.innerHTML;
  }

  function fijar(el, txt) {
    if (!el || el.textContent === txt) return false;
    el.textContent = txt;
    el.classList.remove('cm-destello');
    void el.offsetWidth;
    el.classList.add('cm-destello');
    return true;
  }

  global.CMUtil = { money: money, escapar: esc, fijarValor: fijar };

  // ------------------------------------------------------------------
  // Avisos — los define avisos.js, que se carga antes que este archivo.
  // Aquí solo se toma la referencia local que usa el resto del módulo.
  // ------------------------------------------------------------------
  function avisar(titulo, mensaje, severidad) {
    if (global.CMAviso) {
      global.CMAviso.mostrar(severidad || 'info', titulo, mensaje);
    }
  }

  // ------------------------------------------------------------------
  // Tiempo real
  // ------------------------------------------------------------------
  if (global.Vivo) {

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
      if (esReciente) { avisar(n.titulo, n.mensaje, n.severidad); }
      subirContador();

      // En desarrollo el OTP llega en la notificación: se escribe solo.
      if (n.datos && n.datos.codigo_desarrollo) {
        var campo = document.getElementById('codigo-otp');
        if (campo) {
          campo.value = n.datos.codigo_desarrollo;
          campo.dispatchEvent(new Event('input', { bubbles: true }));
        }
      }
    });

    global.Vivo.on('pulso', function (p) {
      if (!p) return;

      var total = document.querySelector('[data-vivo="saldo-total"]');
      if (total && typeof p.saldo_total === 'number') fijar(total, money(p.saldo_total));

      /* Detalle de cada cuenta, no solo el saldo.

         Antes solo se refrescaba `[data-vivo="saldo"]`. Para que una
         pantalla pueda enseñar la cuenta completa —saldo, retenido,
         mínimo y disponible— y que todo eso se mueva solo cuando entra o
         sale dinero, cada dato tiene su propio gancho.

         `data-cuenta="*"` sirve para las pantallas que siguen a una sola
         cuenta y no quieren repetir su código en cada elemento. */
      (p.cuentas || []).forEach(function (c) {
        ['saldo', 'retenido', 'minimo', 'disponible'].forEach(function (campo) {
          var sel = '[data-vivo="' + campo + '"][data-cuenta="' + c.codigo + '"]';
          document.querySelectorAll(sel).forEach(function (el) {
            if (typeof c[campo] === 'number') { fijar(el, money(c[campo])); }
          });
        });

        // Estado y producto: texto, no dinero.
        document.querySelectorAll('[data-vivo="estado"][data-cuenta="' + c.codigo + '"]')
          .forEach(function (el) { fijar(el, c.estado); });

        document.querySelectorAll('[data-vivo="producto"][data-cuenta="' + c.codigo + '"]')
          .forEach(function (el) { fijar(el, c.producto); });
      });

      /* Último movimiento: lo que acaba de pasar en la cuenta.
         Es lo que hace que el panel se sienta vivo de verdad; un saldo
         que cambia sin decir por qué deja al cliente adivinando. */
      if (p.ultimo_mov) {
        var m = p.ultimo_mov;

        var cn = document.querySelector('[data-vivo="ultimo-concepto"]');
        if (cn) { fijar(cn, m.concepto || m.tipo || ''); }

        var im = document.querySelector('[data-vivo="ultimo-importe"]');
        if (im && typeof m.importe === 'number') {
          fijar(im, (m.importe < 0 ? '' : '+') + money(m.importe));
          im.classList.toggle('cm-neg', m.importe < 0);
          im.classList.toggle('cm-pos', m.importe >= 0);
        }

        var rf = document.querySelector('[data-vivo="ultimo-referencia"]');
        if (rf) { fijar(rf, m.referencia || ''); }
      }

      if (typeof p.sin_leer === 'number') ponerContador(p.sin_leer);
    });
  }

  function ponerContador(n) {
    var c = document.getElementById('contador-notif');
    if (!c) return;
    if (n > 0) { c.textContent = n > 99 ? '99+' : String(n); c.classList.remove('d-none'); }
    else { c.classList.add('d-none'); }
  }

  function subirContador() {
    var c = document.getElementById('contador-notif');
    if (!c) return;
    ponerContador((parseInt(c.textContent, 10) || 0) + 1);
  }

  // ------------------------------------------------------------------
  // Panel de notificaciones
  // ------------------------------------------------------------------
  var btnNotif = document.getElementById('btn-notif');
  if (btnNotif) {
    btnNotif.addEventListener('click', cargarNotificaciones);
  }

  /**
   * Carga el panel de la campana.
   *
   * Se llamaba solo al pulsar, de modo que el desplegable se abría con
   * "Cargando…" y el usuario veía un recuadro vacío mientras llegaba la
   * respuesta. Ahora se carga al abrir la página y se refresca al
   * pulsar, así que el contenido ya está cuando se despliega.
   */
  function cargarNotificaciones() {
    var cont = document.getElementById('panel-notif');
    if (!cont || !CM.notif) { return; }

    fetch(CM.notif, {
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        // Consultar la campana no es actividad del usuario.
        'X-CM-Sondeo': '1'
      },
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var lista = (j.datos && j.datos.notificaciones) || [];

        if (!lista.length) {
          cont.innerHTML =
            '<div class="cm-panel-notif__vacio">' +
              '<svg viewBox="0 0 24 24" width="30" height="30" fill="none" ' +
              'stroke="currentColor" stroke-width="1.6">' +
              '<path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>' +
              '<path d="M13.7 21a2 2 0 01-3.4 0"/></svg>' +
              '<p>No tiene notificaciones</p>' +
            '</div>';
          return;
        }

        var html = '';

        lista.forEach(function (n) {
          var nueva = n.leida === false;
          var sev = ['exito', 'alerta', 'critico', 'info'].indexOf(n.severidad) >= 0
            ? n.severidad : 'info';

          html +=
            '<a class="cm-noti-mini' + (nueva ? ' cm-noti-mini--nueva' : '') + '"' +
               ' href="' + esc(n.url_destino || (CM.base + 'notificaciones')) + '">' +
              '<span class="cm-noti-mini__punto cm-noti-mini__punto--' + sev + '"></span>' +
              '<span class="cm-noti-mini__cuerpo">' +
                '<strong>' + esc(n.titulo) + '</strong>' +
                '<span>' + esc(n.mensaje) + '</span>' +
                '<time>' + esc(n.hace || '') + '</time>' +
              '</span>' +
            '</a>';
        });

        cont.innerHTML = html;
      })
      .catch(function () {
        cont.innerHTML =
          '<div class="cm-panel-notif__vacio cm-panel-notif__vacio--error">' +
            '<p>No se pudieron cargar las notificaciones.</p>' +
          '</div>';
      });
  }

  // Se cargan al entrar, no al pulsar: así el desplegable nunca se abre
  // con el recuadro en blanco.
  if (document.getElementById('panel-notif')) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', cargarNotificaciones);
    } else {
      cargarNotificaciones();
    }
  }
  var marcarTodas = document.getElementById('marcar-todas');
  if (marcarTodas) {
    marcarTodas.addEventListener('click', function (e) {
      e.preventDefault();
      fetch(CM.base + 'api/notificaciones/leer', {
        method: 'POST',
        headers: {
          'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
          /* El token y SU PROPÓSITO. Sin el propósito, el servidor lo
             comprueba contra «global», no coincide con el que emitió
             el layout, y la petición falla siempre. */
          'X-CSRF-Token': (window.CMToken ? window.CMToken.de('api') : (CM.csrf || '')),
          'X-CSRF-Proposito': 'api',
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'id=0', credentials: 'same-origin'
      })
        .then(function (r) { return r.json(); })
        .then(function () { ponerContador(0); cargarNotificaciones(); });
    });
  }

  // ------------------------------------------------------------------
  // Envío asíncrono de formularios: data-cm-ajax
  // ------------------------------------------------------------------
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form.matches('[data-cm-ajax]')) return;

    ev.preventDefault();

    var btn = form.querySelector('[type="submit"]');
    var txt = btn ? btn.innerHTML : '';
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>Procesando…';
    }

    fetch(form.action || location.href, {
      method: (form.method || 'POST').toUpperCase(),
      body: new FormData(form),
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      credentials: 'same-origin'
    })
      .then(function (r) {
        /* Una respuesta que no es JSON no es «no hay conexión».

           Antes se hacía `r.json()` a secas: si el servidor devolvía su
           página de error en HTML —porque una excepción se escapó sin
           atrapar— el parseo fallaba, caía en el `.catch()` de abajo y
           el cliente leía «Sin conexión. No se pudo contactar al
           servidor». El servidor estaba perfectamente y el mensaje
           mandaba a revisar la red, que es el sitio equivocado.

           Aquí se distingue: si llegó una respuesta pero no se entiende,
           se dice eso, y el detalle queda en la consola para quien
           tenga que arreglarlo. */
        return r.text().then(function (texto) {
          var j;

          try {
            j = JSON.parse(texto);
          } catch (err) {
            if (global.console && console.error) {
              console.error('[CashMan] Respuesta no JSON de ' + (form.action || location.href)
                + ' (HTTP ' + r.status + '):', texto.slice(0, 2000));
            }

            return {
              s: r.status,
              j: {
                ok: false,
                mensaje: 'El servidor respondió algo que no se pudo interpretar '
                  + '(HTTP ' + r.status + '). La operación puede no haberse aplicado: '
                  + 'revise antes de repetirla.'
              }
            };
          }

          return { s: r.status, j: j };
        });
      })
      .then(function (res) {
        if (btn) { btn.disabled = false; btn.innerHTML = txt; }

        if (res.j.ok) {
          avisar('Listo', res.j.mensaje || 'Operación completada.', 'exito');
          var destino = res.j.datos && res.j.datos.redirigir;
          var abrir   = res.j.datos && res.j.datos.abrir;

          /* Un documento recien emitido tiene que aparecer. Se abre
             aparte para no sacar a nadie de la pantalla en la que
             estaba trabajando. */
          if (abrir) { window.open(abrir, "_blank", "noopener"); }

          var recarga = form.getAttribute('data-cm-recargar');

          /* Ni recargar ni saltar: traer.
           *
           * Antes, al terminar una operación, esto hacía
           * `location.reload()` y el navegador volvía a montar la
           * pantalla entera: medio segundo en blanco, el menú cerrado,
           * la pestaña de la ficha perdida y la página de vuelta
           * arriba del todo. En una ventanilla que repite la misma
           * operación cincuenta veces al día, eso se nota.
           *
           * `CMVista` pide la misma pantalla y sustituye solo el
           * contenido, así que el cambio se ve donde tiene que verse y
           * el resto se queda quieto. Cuando no está disponible —el
           * sitio público, la pantalla de acceso— se hace lo de
           * siempre, que sigue siendo correcto. */
          var vista = global.CMVista;

          if (destino) {
            setTimeout(function () {
              if (vista) { vista.ir(destino); } else { location.assign(destino); }
            }, 700);
          } else if (recarga !== null) {
            setTimeout(function () {
              if (vista) { vista.refrescar(); } else { location.reload(); }
            }, 700);
          } else {
            form.reset();
            if (global.Vivo) { global.Vivo.refrescar(); }
          }

          form.dispatchEvent(new CustomEvent('cm:exito', { detail: res.j }));
        } else {
          var m = res.j.mensaje || 'No se pudo completar.';
          if (res.j.fallos && res.j.fallos.length) m = res.j.fallos.join(' ');
          avisar('Atención', m, res.s === 429 ? 'alerta' : 'critico');
          form.dispatchEvent(new CustomEvent('cm:error', { detail: res.j }));
        }
      })
      .catch(function (err) {
        if (btn) { btn.disabled = false; btn.innerHTML = txt; }

        // A este punto solo se llega si de verdad no hubo respuesta: la
        // petición ni salió o se cortó a medio camino.
        if (global.console && console.error) {
          console.error('[CashMan] La petición no llegó a completarse:', err);
        }

        avisar(
          'Sin conexión',
          'No se pudo contactar al servidor. Revise su conexión e inténtelo de nuevo.',
          'critico'
        );
      });
  });

  // ------------------------------------------------------------------
  // Confirmación antes de acciones delicadas: data-cm-confirmar
  // ------------------------------------------------------------------
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-cm-confirmar]');
    if (!el || el.dataset.cmOk === '1') return;

    ev.preventDefault();
    var msg = el.getAttribute('data-cm-confirmar') || '¿Confirmas esta acción?';

    // El atributo data-cm-peligro marca las acciones que destruyen algo:
    // el diálogo sale en rojo y con el foco en Cancelar.
    var peligro = el.hasAttribute('data-cm-peligro') ||
                  /elimin|borr|anul|cancel|revoc|bloque/i.test(msg);

    if (global.CMAviso) {
      global.CMAviso.confirmar({
        titulo:  el.getAttribute('data-cm-titulo') || '¿Confirmas esta acción?',
        mensaje: msg,
        confirmar: el.getAttribute('data-cm-boton') || (peligro ? 'Sí, continuar' : 'Confirmar'),
        peligro: peligro
      }).then(function (ok) {
        if (ok) { el.dataset.cmOk = '1'; el.click(); }
      });
    } else {
      /* Sin `confirm()` de respaldo.
       *
       * Ese recuadro del navegador rompe la pantalla en seco y, en una
       * confirmación de dinero, se parece más a un error del sistema
       * que a una pregunta. `avisos.js` se carga en todos los marcos
       * —panel, sitio público y acceso—, así que `CMAviso` existe
       * siempre.
       *
       * Si algún día no cargara, lo correcto es NO ejecutar la acción:
       * una confirmación que no se puede mostrar es una confirmación
       * que no se dio, y de estos botones cuelgan anulaciones y
       * cierres de turno. */
      console.error('[cm] no se pudo pedir confirmación: avisos.js no está cargado');
    }
  });

  // ------------------------------------------------------------------
  // DataTables en tablas marcadas
  // ------------------------------------------------------------------
  if ($ && $.fn && $.fn.DataTable) {
    /* Con nombre y no suelto dentro del arranque.
     *
     * La navegación en vivo sustituye el contenido sin recargar la
     * página, así que este enganche —que antes corría una sola vez, al
     * cargar— tiene que poder repetirse sobre lo que acaba de llegar.
     * Si no, la primera tabla del sistema pagina y todas las
     * siguientes salen en crudo, con sus trescientas filas. */
    global.cmTablas = function (caja) {
      $(caja || document).find('table[data-cm-tabla]').each(function () {
        var $t = $(this);

        // Ya enganchada: repetirlo la rompería.
        if ($.fn.DataTable.isDataTable(this)) { return; }

        $t.DataTable({
          pageLength: 25,
          lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Todos']],
          order: [],

          /* Numeradas, con primera y última. Es lo que dibuja el
             paginador del servidor —« ‹ 1 2 3 … 10 › »— y lo que
             permite llegar al final de una tabla larga sin pulsar
             «siguiente» treinta veces. */
          pagingType: 'full_numbers',

          /* La estructura del pie, con LAS MISMAS CLASES que
             `layout/paginacion.php`. Es lo que iguala las dos
             paginaciones sin duplicar una sola regla de estilo:

               arriba   el buscador
               tabla
               abajo    .cm-paginacion
                          .cm-paginacion__resumen  (recuento + tamaño)
                          los botones

             Las letras son las de DataTables: f buscador, r aviso de
             proceso, t tabla, i recuento, l tamaño, p botones. */
          dom: '<"cm-dt-cabecera"f>rt'
             + '<"cm-paginacion cm-paginacion--dt"'
             +   '<"cm-paginacion__resumen"il>p>',

          /* Una sola pagina no necesita botones, ni una tabla de
             ocho filas necesita un selector de tamano. El
             paginador del servidor ya se comporta asi —no dibuja
             nada cuando todo cabe— y aqui se iguala, que era
             justo la diferencia que quedaba entre los dos. */
          drawCallback: function () {
            var info = this.api().page.info();
            var caja = this.api().table().container();

            var botones = caja.querySelector('.dataTables_paginate');
            var tamano  = caja.querySelector('.dataTables_length');

            if (botones) { botones.style.display = info.pages > 1 ? '' : 'none'; }
            if (tamano)  { tamano.style.display  = info.recordsTotal > 10 ? '' : 'none'; }
          },

          language: {
            search: 'Buscar:',
            lengthMenu: 'Por página _MENU_',
            /* Las mismas palabras y las mismas negritas que el
               paginador del servidor. DataTables inserta esto como
               HTML, así que las etiquetas valen. */
            info: 'Mostrando <strong>_START_</strong>–<strong>_END_</strong> '
                + 'de <strong>_TOTAL_</strong> registros',
            infoEmpty: 'Sin registros',
            infoFiltered: '(filtrados de <strong>_MAX_</strong>)',
            zeroRecords: 'Sin coincidencias',
            paginate: { first: '«', last: '»', next: '›', previous: '‹' }
          }
        });

        /* DataTables envuelve la tabla donde estaba, o sea DENTRO de
           `.table-responsive`. Esa caja lleva `overflow-x: auto`, y el
           CSS convierte entonces el eje vertical en `auto` también: no
           se puede desbordar en horizontal y recortar en vertical. Con
           el buscador y el paginador metidos ahí, asomaban por abajo y
           salía la barra vertical —y el paginador acababa montado sobre
           las filas—.

           Se invierten los dos contenedores: en la caja que desplaza
           queda solo la tabla, y los controles pasan fuera, a lo ancho
           de la tarjeta. */
        var $envoltorio = $t.closest('.dataTables_wrapper');
        var $caja       = $envoltorio.closest('.table-responsive');

        if ($caja.length && $envoltorio.length) {
          $caja.after($envoltorio);
          $caja.remove();
          $t.wrap('<div class="table-responsive"></div>');
          $envoltorio.addClass('cm-tabla-dt');
        }
      });
    };

    // Y la primera vez, al cargar.
    $(function () { global.cmTablas(document); });
  }

})(window, window.jQuery);

/* =====================================================================
   Reloj del header — se actualiza cada segundo, en hora local.
   ===================================================================== */
(function () {
  'use strict';
  var el = document.getElementById('cm-hora');
  if (!el) return;

  function tic() {
    var d = new Date();
    el.textContent =
      String(d.getHours()).padStart(2, '0') + ':' +
      String(d.getMinutes()).padStart(2, '0') + ':' +
      String(d.getSeconds()).padStart(2, '0');
  }
  tic();
  setInterval(tic, 1000);
})();

/* =====================================================================
   Aviso automático de toda acción
   ---------------------------------------------------------------------
   Se mira cada respuesta JSON al pasar y se avisa cuando una petición
   cambió algo. Antes cada vista decidía si avisaba, y unas lo hacían y
   otras no: la misma acción confirmaba en una pantalla y era muda en
   otra.

   LO QUE YA NO ESTÁ AQUÍ

   La renovación del token CSRF vivía en este mismo bloque, y había
   OTRA copia en `csrf.js`. Dos implementaciones de lo mismo, ninguna
   completa: ésta no actualizaba `CM.csrf` —el del layout— que con
   navegación en vivo quedaba gastado para el resto de la sesión.

   Ahora todo eso está en `token.js`, que se carga antes que este
   archivo y envuelve `fetch` primero. Aquí solo quedan los avisos.
   ===================================================================== */
(function (global) {
  'use strict';

  var original = global.fetch;
  if (typeof original !== 'function') { return; }

  global.fetch = function (recurso, opciones) {
    // Método de la petición: solo las que cambian algo merecen aviso.
    var metodo = 'GET';
    if (opciones && opciones.method) { metodo = String(opciones.method).toUpperCase(); }
    else if (recurso && recurso.method) { metodo = String(recurso.method).toUpperCase(); }

    var muta = metodo !== 'GET' && metodo !== 'HEAD';

    return original.apply(this, arguments).then(function (respuesta) {
      var tipo = respuesta.headers.get('content-type') || '';
      if (tipo.indexOf('application/json') < 0) { return respuesta; }

      // Se lee de un clon: el cuerpo original queda intacto para quien
      // llamó, que es quien lo va a consumir de verdad.
      return respuesta.clone().json()
        .then(function (j) {
          if (!j || typeof j !== 'object') { return respuesta; }

          /* El token lo recoge `token.js`, que envuelve `fetch` antes
             que este archivo. Hacerlo aquí también sería repetirlo. */

          /* Aviso automático de toda acción.
             Antes cada vista decidía si avisaba o no, y unas lo hacían
             y otras no. Aquí se avisa siempre que una petición cambie
             algo y el servidor responda con `ok`. Las vistas que ya
             avisan por su cuenta no molestan: el mensaje repetido se
             descarta por el deduplicado de avisos.js.

             `silencio: true` en la respuesta permite que un endpoint
             concreto pida no avisar —el sondeo de notificaciones, por
             ejemplo, que avisa por su propio canal. */
          if (muta && global.CMAviso && j.silencio !== true &&
              Object.prototype.hasOwnProperty.call(j, 'ok')) {

            if (j.ok) {
              if (j.mensaje) { global.CMAviso.exito('Listo', j.mensaje); }
            } else {
              var texto = j.mensaje || 'No se pudo completar la operación.';

              if (j.datos && j.datos.fallos && j.datos.fallos.length) {
                texto = j.datos.fallos.join(' ');
              } else if (j.fallos && j.fallos.length) {
                texto = j.fallos.join(' ');
              }

              // 429 es un límite alcanzado, no un error del usuario.
              var sev = respuesta.status === 429 ? 'alerta' : 'critico';
              global.CMAviso.mostrar(sev,
                sev === 'alerta' ? 'Demasiados intentos' : 'No se pudo completar', texto);
            }
          }

          return respuesta;
        })
        .catch(function () { return respuesta; });
    }).catch(function (error) {
      // Un fallo de red en una acción tampoco puede pasar en silencio.
      if (muta && global.CMAviso) {
        global.CMAviso.error('Sin conexión',
          'No se pudo contactar al servidor. Revise su conexión e inténtalo de nuevo.');
      }
      throw error;
    });
  };
})(window);

/* =====================================================================
   Menú lateral: desplazamiento razonable
   ---------------------------------------------------------------------
   Al cargar la página, el navegador desplaza el menú para enseñar el
   elemento activo. Si ese elemento está a media altura, el encabezado
   de la sección queda arriba fuera de vista y da la impresión de que la
   pantalla cortó el contenido.

   Aquí se corrige: si el elemento activo ya se ve entero, no se toca
   nada; si no, se desplaza lo justo para que quede visible, dejando el
   encabezado de su grupo por encima.
   ===================================================================== */
(function () {
  'use strict';

  function ajustar() {
    var caja = document.querySelector('.deznav .deznav-scroll');
    if (!caja) { return; }

    var activo = caja.querySelector('.metismenu > li.mm-active > a') ||
                 caja.querySelector('.metismenu a.mm-active');
    if (!activo) { caja.scrollTop = 0; return; }

    var cajaRect   = caja.getBoundingClientRect();
    var activoRect = activo.getBoundingClientRect();

    /* Si el elemento activo está en la parte de arriba del menú no se
       toca nada. Antes se desplazaba igual, y eso dejaba las primeras
       secciones —Principal, Inicio, Cuentas— fuera de la vista sin que
       hubiera ningún motivo. */
    if (activoRect.top - cajaRect.top < caja.clientHeight * 0.5) {
      caja.scrollTop = 0;
      return;
    }

    // Margen por arriba, para que se vea la etiqueta del grupo.
    var margen = 56;

    if (activoRect.top >= cajaRect.top + margen &&
        activoRect.bottom <= cajaRect.bottom) {
      return;   // ya se ve: no hace falta mover nada
    }

    caja.scrollTop += (activoRect.top - cajaRect.top) - margen;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ajustar);
  } else {
    ajustar();
  }

  // metisMenu reposiciona al abrir un submenú; se reajusta después.
  window.addEventListener('load', function () { setTimeout(ajustar, 120); });
})();


/* =====================================================================
   El menú de acciones, fuera de la caja de la tabla
   ---------------------------------------------------------------------
   El menú nace dentro de una celda, y la caja de la tabla recorta lo
   que se sale de ella: al abrirse hacia abajo quedaba cortado por el
   borde. Dentro de esa caja no hay postura buena —si no se recorta,
   empuja y saca barra de desplazamiento—, así que se le saca del flujo.

   `position: fixed` no lo recorta ningún `overflow`. Las coordenadas
   salen del rectángulo real del botón, se abre hacia arriba cuando
   abajo no cabe, y se recoloca si la página se mueve con el menú
   abierto.
   ===================================================================== */
(function (global, $) {
  'use strict';
  if (!$) { return; }

  var abierto = null;

  function medir(menu) {
    // El menú todavía no está visible: se muestra en falso para poder
    // medirlo, porque un elemento oculto no tiene tamaño.
    var previo = menu.style.cssText;
    menu.style.display = 'block';
    menu.style.visibility = 'hidden';
    var tam = { ancho: menu.offsetWidth, alto: menu.offsetHeight };
    menu.style.cssText = previo;
    return tam;
  }

  function colocar(caja, menu) {
    var btn = caja.querySelector('.cm-acciones__btn');
    if (!btn) { return; }

    var tam = menu.classList.contains('show')
      ? { ancho: menu.offsetWidth, alto: menu.offsetHeight }
      : medir(menu);

    var r = btn.getBoundingClientRect();
    var margen = 8;

    // Alineado a la derecha del botón, como estaba.
    var izq = r.right - tam.ancho;
    if (izq < margen) { izq = margen; }
    if (izq + tam.ancho > global.innerWidth - margen) {
      izq = Math.max(margen, global.innerWidth - margen - tam.ancho);
    }

    // Debajo salvo que no quepa: entonces encima, que es mejor que
    // quedarse a medias fuera de la pantalla.
    var arriba = r.bottom + 4;
    if (arriba + tam.alto > global.innerHeight - margen) {
      arriba = (r.top - tam.alto - 4 >= margen)
        ? r.top - tam.alto - 4
        : Math.max(margen, global.innerHeight - margen - tam.alto);
    }

    menu.style.top = arriba + 'px';
    menu.style.left = izq + 'px';
  }

  $(document).on('show.bs.dropdown', '.cm-acciones', function () {
    var menu = this.querySelector('.cm-acciones__menu');
    if (!menu) { return; }

    menu.classList.add('cm-menu-flotante');
    colocar(this, menu);
    abierto = { caja: this, menu: menu };
  });

  $(document).on('hidden.bs.dropdown', '.cm-acciones', function () {
    var menu = this.querySelector('.cm-acciones__menu');
    if (menu) {
      menu.classList.remove('cm-menu-flotante');
      menu.style.top = '';
      menu.style.left = '';
    }
    abierto = null;
  });

  // Si la página se mueve con el menú abierto, el menú va con ella: un
  // menú fijo que se queda donde estaba apunta a otra fila.
  function seguir() {
    if (abierto) { colocar(abierto.caja, abierto.menu); }
  }

  global.addEventListener('scroll', seguir, true);
  global.addEventListener('resize', seguir);
})(window, window.jQuery);
/* =====================================================================
   Documentos: en su pestaña, y con señal de que están saliendo
   =====================================================================

   Un informe o un comprobante se consultan antes de guardarse, así que
   se abren en una pestaña en vez de caer en la carpeta de descargas
   sin que nadie los vea. Y tardan: el servidor compone el PDF al
   vuelo. Mientras tanto la pantalla no daba ninguna señal, el usuario
   creía que no había pulsado y volvía a pulsar.

   El problema de avisar es que la pestaña del PDF no puede hablar con
   la que la abrió —es otro documento, y encima un PDF, sin JavaScript
   que ejecutar—. Así que el aviso va por donde sí pasa: el servidor
   deja una galleta al entregar el archivo y esta pestaña la vigila.
   ===================================================================== */
(function (global, documento) {
  'use strict';

  function ficha() {
    // Sin valor de seguridad: solo distingue un documento de otro
    // cuando se piden dos a la vez.
    return 'd' + Math.random().toString(36).slice(2, 10);
  }

  function tieneGalleta(nombre) {
    return documento.cookie.split('; ').some(function (c) {
      return c.indexOf(nombre + '=') === 0;
    });
  }

  function borrarGalleta(nombre) {
    documento.cookie = nombre + '=; Max-Age=0; path=/';
  }

  documento.addEventListener('click', function (ev) {
    var enlace = ev.target.closest ? ev.target.closest('a[data-cm-doc]') : null;

    if (!enlace || ev.defaultPrevented) { return; }

    // Clic con modificador o con el botón central: el navegador ya
    // sabe lo que hace y no hay que estorbarle.
    if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey || ev.button !== 0) { return; }

    if (!global.CMAviso || !global.CMAviso.esperar) { return; }

    ev.preventDefault();

    var marca = ficha();
    var url   = enlace.getAttribute('href');

    if (!url || url === '#') { return; }

    url += (url.indexOf('?') === -1 ? '?' : '&') + 'listo=' + marca;

    /* La pestaña se abre AHORA, dentro del propio clic. Abrirla luego,
       cuando el documento estuviera listo, es justo lo que los
       navegadores bloquean por considerarlo una ventana emergente.
     *
     * SIN «noopener» en la cadena de opciones. Con ella, `open`
     * devuelve null POR ESPECIFICACIÓN aunque la pestaña se haya
     * abierto perfectamente —no hay referencia que devolver—, y este
     * código lo tomaba por bloqueo: abría la pestaña nueva Y ADEMÁS
     * navegaba la actual al PDF. Es justo lo que se veía. La
     * desvinculación se hace después, soltando `opener`. */
    var pestana = global.open(url, '_blank');

    if (!pestana) {
      // Ahora sí la bloquearon: se va en esta misma pestaña antes que
      // dejar al usuario sin su documento.
      global.location.assign(url);
      return;
    }

    // Que la pestaña nueva no pueda tocar esta.
    try { pestana.opener = null; } catch (e) { /* defensa extra, no crítica */ }

    var cerrar = global.CMAviso.esperar({
      titulo:  enlace.getAttribute('data-cm-doc-titulo') || 'Preparando el documento',
      mensaje: enlace.getAttribute('data-cm-doc-texto')
               || 'Se está generando y se abrirá en una pestaña nueva.',
      icono:   'documento',
      pie:     'Si su navegador bloqueó la pestaña, permítala y vuelva a intentarlo.',
      tope:    25000
    });

    var galleta = 'cm_doc_' + marca;
    var hasta   = Date.now() + 25000;

    var vigilar = setInterval(function () {
      if (tieneGalleta(galleta)) {
        borrarGalleta(galleta);
        clearInterval(vigilar);
        cerrar();
        return;
      }

      if (Date.now() > hasta) { clearInterval(vigilar); cerrar(); }
    }, 180);
  }, false);
})(window, document);

/* =====================================================================
   Desplegar y plegar
   =====================================================================

   Un detalle que se enseña solo si se pide. Se usa en el indicador de
   requisitos: la cabecera dice lo que falta en una línea y, quien
   quiera ver las seis condiciones una por una, las abre.

   Se hace con `hidden` y no con una clase porque `hidden` es lo que
   entienden los lectores de pantalla: una lista plegada no debe
   leerse como si estuviera ahí.
   ===================================================================== */
(function (documento) {
  'use strict';

  documento.addEventListener('click', function (ev) {
    var cab = ev.target.closest ? ev.target.closest('[data-cm-desplegar]') : null;

    if (!cab) { return; }

    ev.preventDefault();

    // El panel es el hermano siguiente, o lo que diga el atributo.
    var sel    = cab.getAttribute('data-cm-desplegar');
    var panel  = sel ? documento.querySelector(sel) : cab.nextElementSibling;

    if (!panel) { return; }

    var abierto = !panel.hidden;

    panel.hidden = abierto;
    cab.setAttribute('aria-expanded', abierto ? 'false' : 'true');
    cab.classList.toggle('is-abierto', !abierto);
  });

  // El estado inicial, para que el lector de pantalla no se invente uno.
  documento.addEventListener('DOMContentLoaded', function () {
    documento.querySelectorAll('[data-cm-desplegar]').forEach(function (cab) {
      var sel   = cab.getAttribute('data-cm-desplegar');
      var panel = sel ? documento.querySelector(sel) : cab.nextElementSibling;

      if (panel) { cab.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true'); }
    });
  });
})(document);


/* =====================================================================
   Lo que se abre desde una fila, se abre en otra pestaña
   ---------------------------------------------------------------------
   El menú de acciones ya lo hace desde PHP. Esto es para los enlaces
   que cada vista escribe a mano dentro de las celdas: el nombre del
   cliente, el folio del crédito, el número de cuenta.

   Se hace aquí y no vista por vista porque son decenas repartidas por
   todo el sistema, y porque la próxima tabla que alguien escriba se
   beneficia sin tener que acordarse.

   NO se marca:
     · lo que no navega  (#, javascript:, sin href)
     · correo y teléfono (los abre otra aplicación, no una pestaña)
     · la paginación y el orden de las columnas
     · lo que ya trae su propio `target`
     · las descargas, que no abren página ninguna
   ===================================================================== */
(function () {
  'use strict';

  var NO = '.cm-paginacion, .pagination, .dataTables_paginate, .cm-acciones__menu';

  function cmEnlacesDeTabla(raiz) {
    var enlaces = (raiz || document).querySelectorAll('table tbody td a[href]');

    Array.prototype.forEach.call(enlaces, function (a) {
      if (a.target) { return; }
      if (a.hasAttribute('download')) { return; }
      if (a.closest(NO)) { return; }

      var href = a.getAttribute('href') || '';

      if (href === '' || href.charAt(0) === '#') { return; }
      if (/^(javascript:|mailto:|tel:)/i.test(href)) { return; }

      a.target = '_blank';
      a.rel = (a.rel ? a.rel + ' ' : '') + 'noopener';
    });
  }

  window.cmEnlacesDeTabla = cmEnlacesDeTabla;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { cmEnlacesDeTabla(); });
  } else {
    cmEnlacesDeTabla();
  }

  /* DataTables vuelve a dibujar el cuerpo al paginar, ordenar o
     buscar, y las filas nuevas llegan sin marcar. Se repasa en cada
     dibujado; si DataTables no está, no pasa nada. */
  var $ = window.jQuery;

  if ($ && $.fn && $.fn.DataTable) {
    $(document).on('draw.dt', function (ev) {
      cmEnlacesDeTabla(ev.target);
    });
  }
})();


/* =====================================================================
   Imágenes que se esconden si no cargan
   ---------------------------------------------------------------------
   Antes esto era un `onerror="this.style.display=none"` escrito en el
   marcado. La política de seguridad no ejecuta manejadores en línea
   —ni con nonce ni con hash: la norma los excluye— así que no corría
   nunca: un icono que no cargaba se quedaba como imagen rota, y la
   consola acumulaba un aviso de violación de la política que despista
   al buscar otros problemas.
   ===================================================================== */
(function (document) {
  'use strict';

  function enganchar(raiz) {
    var imagenes = (raiz || document).querySelectorAll('img[data-cm-ocultar-si-falla]');

    Array.prototype.forEach.call(imagenes, function (img) {
      if (img.dataset.cmEnganchada) { return; }

      img.dataset.cmEnganchada = '1';

      img.addEventListener('error', function () { img.style.display = 'none'; });

      /* Si ya falló antes de que esto corriera, el evento no vuelve a
         dispararse: hay que mirar el estado. */
      if (img.complete && img.naturalWidth === 0) { img.style.display = 'none'; }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { enganchar(); });
  } else {
    enganchar();
  }

  document.addEventListener('cm:montar', function (ev) {
    enganchar(ev.detail && ev.detail.caja);
  });
})(document);