/* =====================================================================
   CALENDARIO PROPIO
   =====================================================================

   Los campos de fecha usaban el selector del navegador, que en cada uno
   se ve distinto —y en ninguno se parece al resto del sistema—: otra
   tipografía, otros colores, los domingos en rojo y un «Borrar / Hoy» en
   azul de enlace. En una pantalla cuidada, ese recuadro desentona.

   Aquí el campo sigue siendo un `input[type=date]`, a propósito:

     · El valor viaja en ISO (aaaa-mm-dd), que es lo que el servidor
       espera. Cambiarlo a texto obligaría a convertir de ida y vuelta,
       y cada conversión es una ocasión de equivocarse con el formato.
     · La validación del navegador (`required`, `min`, `max`) sigue
       funcionando sola.
     · Si este archivo no cargara, el campo sigue siendo utilizable con
       el selector de siempre. No se rompe nada, solo se ve distinto.

   Lo único que se hace es ocultar el icono nativo, poner el nuestro y
   dibujar el calendario con los estilos del sistema.
   ===================================================================== */
(function () {
  'use strict';

  /* Los meses, en tres letras.
   *
   * El desplegable es estrecho —va al lado del año y de las dos
   * flechas, dentro de un panel que tiene que caber en un teléfono— y
   * «septiembre» o «noviembre» no entran: el navegador los cortaba a
   * media palabra y se leía «noviemb».
   *
   * Tres letras es lo que usa cualquier calendario para esto y no hay
   * ambigüedad posible: ningún par de meses comparte las tres
   * primeras. Se evita además tener que ensanchar el panel por una
   * palabra. */
  var MESES = [
    'ene', 'feb', 'mar', 'abr', 'may', 'jun',
    'jul', 'ago', 'sep', 'oct', 'nov', 'dic'
  ];

  // La semana empieza en lunes, como se lee un calendario aquí.
  var DIAS = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];

  var panel = null;
  var campoActivo = null;
  var mesVisible = null;   // Date al día 1 del mes que se está viendo

  // ------------------------------------------------------------------
  // Utilidades de fecha. Todo en hora local: una fecha de calendario no
  // tiene zona horaria, y usar UTC hace que el día 1 se dibuje como el
  // último del mes anterior en husos negativos, que es el nuestro.
  // ------------------------------------------------------------------

  function iso(d) {
    var m = String(d.getMonth() + 1).padStart(2, '0');
    var dia = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + m + '-' + dia;
  }

  function desdeIso(s) {
    if (!s) { return null; }
    var p = String(s).split('-');
    if (p.length !== 3) { return null; }
    var d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
    return isNaN(d.getTime()) ? null : d;
  }

  function mismoDia(a, b) {
    return a && b
      && a.getFullYear() === b.getFullYear()
      && a.getMonth() === b.getMonth()
      && a.getDate() === b.getDate();
  }

  /** Lunes = 0 … domingo = 6. */
  function columnaDe(d) {
    return (d.getDay() + 6) % 7;
  }

  // ------------------------------------------------------------------
  // El panel
  // ------------------------------------------------------------------

  function crearPanel() {
    if (panel) { return panel; }

    panel = document.createElement('div');
    panel.className = 'cm-cal';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Seleccionar fecha');
    panel.hidden = true;

    /* Pulsar dentro no cierra: el panel vive mientras se navega por él.

       El `preventDefault` está para que el campo no pierda el foco al
       pulsar un día. Pero sobre un <select> ese mismo `preventDefault`
       cancela la apertura de la lista nativa, y por eso los
       desplegables de mes y año no se dejaban abrir con el ratón: el
       navegador recibía la orden de no hacer lo suyo.

       Los controles de formulario quedan exentos. El foco se devuelve
       al campo cuando el panel se cierra, así que no se pierde nada. */
    panel.addEventListener('mousedown', function (ev) {
      var t = ev.target;

      if (t && t.closest && t.closest('select, option, input, textarea')) {
        return;
      }

      ev.preventDefault();
    });

    document.body.appendChild(panel);
    return panel;
  }

  function cerrar() {
    if (panel) { panel.hidden = true; }

    if (campoActivo) {
      campoActivo.removeAttribute('aria-expanded');
      campoActivo = null;
    }
  }

  function limite(campo, cual) {
    var v = campo.getAttribute(cual);
    return v ? desdeIso(v) : null;
  }

  function dibujar() {
    var p = crearPanel();
    var hoy = new Date();
    var elegida = desdeIso(campoActivo.value);
    var min = limite(campoActivo, 'min');
    var max = limite(campoActivo, 'max');

    var anio = mesVisible.getFullYear();
    var mes = mesVisible.getMonth();

    var primero = new Date(anio, mes, 1);
    var dias = new Date(anio, mes + 1, 0).getDate();
    var hueco = columnaDe(primero);

    var html = ''
      + '<div class="cm-cal__cab">'
      +   '<button type="button" class="cm-cal__nav" data-cal="-1" aria-label="Mes anterior">&lsaquo;</button>'
      +   '<div class="cm-cal__mes">'
      +     '<select class="cm-cal__sel" data-cal="mes" aria-label="Mes">';

    for (var i = 0; i < 12; i++) {
      html += '<option value="' + i + '"' + (i === mes ? ' selected' : '') + '>'
            + MESES[i] + '</option>';
    }

    html += '</select><select class="cm-cal__sel" data-cal="anio" aria-label="Año">';

    /* El rango de años sale de los topes del campo cuando los tiene.
     *
     * Antes iba siempre de hace noventa años a dentro de quince, sin
     * mirar el `min` ni el `max`: en una fecha de nacimiento ofrecía
     * quince años futuros que el formulario iba a rechazar, y en un
     * vencimiento ofrecía noventa años pasados que tampoco valían.
     * Una lista de ciento cinco años donde sobran la mitad es lo que
     * hace que elegir el año cueste. */
    var topeMax = campoActivo ? topeDe(campoActivo, 'max') : null;
    var topeMin = campoActivo ? topeDe(campoActivo, 'min') : null;

    var desdeAnio = topeMin ? topeMin.getFullYear() : hoy.getFullYear() - 90;
    var hastaAnio = topeMax ? topeMax.getFullYear() : hoy.getFullYear() + 15;

    // El año que se está viendo siempre tiene que estar en la lista,
    // aunque venga de un valor guardado fuera de los topes.
    desdeAnio = Math.min(desdeAnio, anio);
    hastaAnio = Math.max(hastaAnio, anio);

    for (var a = hastaAnio; a >= desdeAnio; a--) {
      html += '<option value="' + a + '"' + (a === anio ? ' selected' : '') + '>' + a + '</option>';
    }

    html += '</select></div>'
      +   '<button type="button" class="cm-cal__nav" data-cal="1" aria-label="Mes siguiente">&rsaquo;</button>'
      + '</div>'
      + '<div class="cm-cal__semana">';

    for (var s = 0; s < 7; s++) {
      html += '<span>' + DIAS[s] + '</span>';
    }

    html += '</div><div class="cm-cal__dias">';

    for (var h = 0; h < hueco; h++) {
      html += '<span class="cm-cal__vacio"></span>';
    }

    for (var d = 1; d <= dias; d++) {
      var fecha = new Date(anio, mes, d);
      var fuera = (min && fecha < min) || (max && fecha > max);

      var clases = 'cm-cal__dia';
      if (mismoDia(fecha, hoy)) { clases += ' cm-cal__dia--hoy'; }
      if (mismoDia(fecha, elegida)) { clases += ' cm-cal__dia--elegido'; }

      html += '<button type="button" class="' + clases + '" data-cal-dia="' + iso(fecha) + '"'
            + (fuera ? ' disabled' : '') + '>' + d + '</button>';
    }

    html += '</div><div class="cm-cal__pie">'
      +   '<button type="button" class="cm-cal__accion" data-cal="hoy">Hoy</button>'
      +   '<button type="button" class="cm-cal__accion cm-cal__accion--tenue" data-cal="limpiar">Quitar</button>'
      + '</div>';

    p.innerHTML = html;
    p.hidden = false;

    colocar();
  }

  function colocar() {
    var r = campoActivo.getBoundingClientRect();

    panel.style.left = Math.max(8, Math.min(
      r.left,
      window.innerWidth - panel.offsetWidth - 8
    )) + 'px';

    // Debajo del campo, salvo que no quepa: entonces encima.
    var abajo = r.bottom + 6;

    if (abajo + panel.offsetHeight > window.innerHeight - 8) {
      var arriba = r.top - panel.offsetHeight - 6;
      panel.style.top = (arriba > 8 ? arriba : 8) + 'px';
    } else {
      panel.style.top = abajo + 'px';
    }
  }

  /* Los topes del propio campo, que es donde ya están declarados.
     Leerlos de ahí evita tener que configurar el calendario aparte: el
     `max` del input manda, y si cambia, el calendario cambia con él. */
  function topeDe(campo, cual) {
    return desdeIso(campo.getAttribute(cual) || '');
  }

  function abrir(campo) {
    campoActivo = campo;

    var v = desdeIso(campo.value);

    if (v) {
      mesVisible = new Date(v.getFullYear(), v.getMonth(), 1);
    } else {
      /* Vacío: se abre donde el campo DEJA elegir, no en el mes de hoy.
       *
       * En una fecha de nacimiento con `max` a dieciocho años atrás,
       * abrir en el mes actual obligaba a retroceder dieciocho años a
       * mano —o a bajar dieciocho entradas de una lista de noventa—
       * para llegar al primer año que el formulario acepta.
       *
       * Abrir en el tope es además lo que más probablemente quiere
       * quien lo usa: el que acaba de cumplir la edad mínima. */
      var max = topeDe(campo, 'max');
      var min = topeDe(campo, 'min');
      var hoy = new Date();
      var arranque = hoy;

      if (max && max < hoy) { arranque = max; }
      else if (min && min > hoy) { arranque = min; }

      mesVisible = new Date(arranque.getFullYear(), arranque.getMonth(), 1);
    }

    campo.setAttribute('aria-expanded', 'true');
    dibujar();
  }

  function fijar(valor) {
    if (!campoActivo) { return; }

    campoActivo.value = valor;

    // `change` es lo que escuchan los formularios y los filtros; sin
    // dispararlo a mano, elegir una fecha no recalcularía nada.
    campoActivo.dispatchEvent(new Event('input', { bubbles: true }));
    campoActivo.dispatchEvent(new Event('change', { bubbles: true }));

    cerrar();
  }

  // ------------------------------------------------------------------
  // Enganches
  // ------------------------------------------------------------------

  document.addEventListener('click', function (ev) {
    var dia = ev.target.closest('[data-cal-dia]');

    if (dia && panel && !panel.hidden) {
      ev.preventDefault();
      fijar(dia.getAttribute('data-cal-dia'));
      return;
    }

    /* Los desplegables de mes y año también llevan `data-cal`, pero no
       son botones: su valor lo recoge el manejador de `change`. Si
       entran por aquí se les cancela el clic y dejan de desplegarse. */
    var accion = ev.target.closest('[data-cal]');

    if (accion && accion.tagName === 'SELECT') { accion = null; }

    if (accion && panel && panel.contains(accion)) {
      ev.preventDefault();
      var v = accion.getAttribute('data-cal');

      if (v === 'hoy') {
        var h = new Date();
        mesVisible = new Date(h.getFullYear(), h.getMonth(), 1);
        fijar(iso(h));
      } else if (v === 'limpiar') {
        fijar('');
      } else if (v === '1' || v === '-1') {
        mesVisible = new Date(
          mesVisible.getFullYear(),
          mesVisible.getMonth() + Number(v),
          1
        );
        dibujar();
      }

      return;
    }

    // Abrir: sobre el propio campo o sobre su botón.
    var campo = ev.target.closest('input[type="date"]');
    var boton = ev.target.closest('[data-cm-calendario]');

    if (boton) {
      campo = boton.previousElementSibling;
      while (campo && campo.tagName !== 'INPUT') {
        campo = campo.previousElementSibling;
      }
    }

    if (campo && campo.type === 'date' && !campo.disabled && !campo.readOnly) {
      ev.preventDefault();

      if (campoActivo === campo && panel && !panel.hidden) {
        cerrar();
      } else {
        abrir(campo);
      }

      return;
    }

    if (panel && !panel.hidden && !panel.contains(ev.target)) {
      cerrar();
    }
  });

  // Los desplegables de mes y año.
  document.addEventListener('change', function (ev) {
    if (!panel || panel.hidden || !panel.contains(ev.target)) { return; }

    var cual = ev.target.getAttribute('data-cal');

    if (cual === 'mes') {
      mesVisible = new Date(mesVisible.getFullYear(), Number(ev.target.value), 1);
      dibujar();
    } else if (cual === 'anio') {
      mesVisible = new Date(Number(ev.target.value), mesVisible.getMonth(), 1);
      dibujar();
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      cerrar();
      return;
    }

    // Con el foco en el campo, Enter o flecha abajo abren el calendario:
    // quien no usa ratón también tiene que poder elegir una fecha.
    var campo = document.activeElement;

    if (!campo || campo.type !== 'date') { return; }

    if ((ev.key === 'Enter' || ev.key === 'ArrowDown') && (!panel || panel.hidden)) {
      ev.preventDefault();
      abrir(campo);
    }
  });

  window.addEventListener('resize', cerrar);
  window.addEventListener('scroll', cerrar, true);

  /* El icono propio, al lado de cada campo.
   *
   * El del navegador se oculta por CSS; este lo sustituye y queda
   * dentro del mismo recuadro, con el color del sistema. */
  function marcar() {
    document.querySelectorAll('input[type="date"]:not([data-cm-cal-listo])').forEach(function (c) {
      c.setAttribute('data-cm-cal-listo', '1');
      c.classList.add('cm-cal-campo');

      var envoltorio = c.parentElement;

      if (!envoltorio || envoltorio.querySelector('[data-cm-calendario]')) { return; }

      if (getComputedStyle(envoltorio).position === 'static') {
        envoltorio.classList.add('cm-cal-contenedor');
      }

      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'cm-cal-boton';
      b.setAttribute('data-cm-calendario', '1');
      b.setAttribute('tabindex', '-1');
      b.setAttribute('aria-hidden', 'true');
      b.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" '
        + 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round">'
        + '<rect x="3" y="5" width="18" height="16" rx="2"/>'
        + '<path d="M8 3v4M16 3v4M3 10h18"/></svg>';

      c.insertAdjacentElement('afterend', b);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', marcar);
  } else {
    marcar();
  }

  // Las pantallas que añaden filas con fecha después de cargar.
  window.CMCalendario = { refrescar: marcar };
})();
