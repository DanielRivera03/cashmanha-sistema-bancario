/* =====================================================================
   Navegación en vivo: el sistema deja de recargarse
   ---------------------------------------------------------------------
   QUÉ HACE

   Cambiar de pantalla, pasar de página, filtrar por un mes o registrar
   un depósito deja de recargar el navegador. Se pide la pantalla
   nueva, se sustituye SOLO el contenido, y el menú, la cabecera, el
   reloj y los avisos se quedan donde están.

   POR QUÉ IMPORTA, Y NO ES UN CAPRICHO

   Una recarga completa tira abajo cosas que costaba recuperar: la
   posición en la que se estaba leyendo, el campo donde estaba el
   cursor, la pestaña abierta dentro de la ficha, el menú desplegado.
   En una ventanilla, donde se repite la misma operación cincuenta
   veces al día, eso es medio segundo de parpadeo y tres clics para
   volver a donde se estaba, cada vez.

   QUÉ SE CONSERVA AL CAMBIAR

     · la posición de la página (y se sube arriba solo si se cambió de
       pantalla de verdad)
     · la pestaña activa dentro de la ficha
     · el menú lateral, sin cerrarse ni parpadear
     · el historial del navegador: atrás y adelante funcionan

   LO QUE NO SE INTERCEPTA, Y POR QUÉ

     · lo que abre en otra pestaña — lo pidió quien pulsó
     · las descargas — no son una pantalla
     · salir de la sesión — ahí sí conviene empezar de cero
     · lo que sale del propio sistema
     · ctrl+clic, el botón central, clic con mayúsculas — son gestos
       del navegador y no se le quitan a nadie

   EL NONCE, QUE ES LA PARTE DELICADA

   La política de seguridad de la página que está abierta autoriza los
   guiones por su `nonce`, y ese nonce es el de ESA respuesta. La
   pantalla que se trae después viene con el suyo, distinto, y si se
   inyectara tal cual el navegador se negaría a ejecutarlo —con razón—.
   Por eso a cada guion que se reinyecta se le pone el nonce de la
   página en curso, que es el único que esta política reconoce.
   ===================================================================== */
(function (window, document) {
  'use strict';

  var CAJA   = 'cm-vista';
  var TITULO = 'cm-encabezado';

  var raiz = document.getElementById(CAJA);

  /* Sin el contenedor con nombre no hay nada que sustituir: pasa en
     el sitio público y en la pantalla de acceso, que no llevan este
     marco. Ahí la navegación de siempre está bien. */
  if (!raiz) { return; }

  var base  = (window.CM && window.CM.base) || '/';
  var salir = (window.CM && window.CM.salir) || '';
  var nonce = (window.CM && window.CM.nonce) || '';

  var cargando = false;

  // ------------------------------------------------------------------
  // Un modal abierto no debe sobrevivir a un cambio de pantalla
  // ------------------------------------------------------------------
  //
  // Bootstrap cuelga el fondo oscuro (.modal-backdrop) y el bloqueo del
  // scroll (body.modal-open, overflow:hidden) del <body>, FUERA de
  // #cm-vista. Al sustituir la vista, el modal que estaba DENTRO
  // desaparece, pero ese fondo y ese bloqueo se quedan: la pantalla
  // entera queda oscura y sin responder, con la pantalla nueva cargada
  // debajo —justo lo que pasaba al pulsar «Pagar con tarjeta» desde el
  // modal de cobro de una cuota—. Se cierra y se limpia a mano antes de
  // traer la pantalla nueva.
  function cerrarModales() {
    var $ = window.jQuery;

    if ($ && $.fn && $.fn.modal) {
      try { $('.modal.show').modal('hide'); } catch (e) {}
    }

    Array.prototype.forEach.call(
      document.querySelectorAll('.modal-backdrop'),
      function (fondo) { if (fondo.parentNode) { fondo.parentNode.removeChild(fondo); } }
    );

    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
  }

  // ------------------------------------------------------------------
  // La barra de progreso de arriba
  // ------------------------------------------------------------------
  //
  // Sin ella, una pantalla que tarda medio segundo parece que ignoró
  // el clic, y quien la usa vuelve a pulsar. Es la única señal que
  // queda cuando se quita el parpadeo de la recarga.
  var barra = null;

  function progreso(encendida) {
    if (encendida) {
      if (!barra) {
        barra = document.createElement('div');
        barra.className = 'cm-vivo-barra';
        document.body.appendChild(barra);
      }

      barra.classList.remove('is-fin');
      // Forzar el reflujo para que la animación arranque de cero.
      void barra.offsetWidth;
      barra.classList.add('is-va');
    } else if (barra) {
      barra.classList.remove('is-va');
      barra.classList.add('is-fin');
    }
  }

  // ------------------------------------------------------------------
  // ¿Esta dirección es nuestra?
  // ------------------------------------------------------------------
  function interna(url) {
    try {
      var u = new URL(url, location.href);

      if (u.origin !== location.origin) { return false; }
      if (salir && u.pathname === new URL(salir, location.href).pathname) { return false; }

      // Fuera del sistema (o de sus assets) no se navega en vivo.
      return u.pathname.indexOf(new URL(base, location.href).pathname) === 0
          && !/\.(pdf|csv|xlsx?|docx?|zip|png|jpe?g|svg|ico|js|css)$/i.test(u.pathname);
    } catch (e) {
      return false;
    }
  }

  // ------------------------------------------------------------------
  // Traer una pantalla y ponerla
  // ------------------------------------------------------------------
  function traer(url, opciones) {
    opciones = opciones || {};

    if (cargando) { return; }

    // Que no quede ningún modal (con su fondo oscuro) tapando la
    // pantalla nueva. Ver cerrarModales().
    cerrarModales();

    cargando = true;
    progreso(true);

    var mismaPantalla = quitaAncla(url) === quitaAncla(location.href);
    var alturaAntes   = window.pageYOffset;

    return fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-CashMan-Vista': '1' },
      redirect: 'follow'
    })
      .then(function (r) {
        /* Una respuesta que no es HTML no se puede poner en la
           pantalla. Lo más probable es que la sesión caducara y el
           servidor esté mandando a la puerta: ahí se deja que el
           navegador vaya, de verdad y entero. */
        var tipo = r.headers.get('content-type') || '';

        if (!r.ok && r.status >= 500) {
          throw new Error('El servidor respondió ' + r.status);
        }

        if (tipo.indexOf('text/html') === -1) {
          location.assign(url);
          return null;
        }

        /* Si el servidor nos llevó a otra parte —a la pantalla de
           acceso, típicamente— la dirección final es la que vale. */
        if (r.url && quitaAncla(r.url) !== quitaAncla(url)) {
          url = r.url;
        }

        return r.text();
      })
      .then(function (html) {
        if (html === null) { return; }

        var doc = new DOMParser().parseFromString(html, 'text/html');
        var nueva = doc.getElementById(CAJA);

        /* Sin contenedor en lo que llegó, lo que llegó no es una
           pantalla del panel: puede ser la de acceso. Se va de
           verdad. */
        if (!nueva) { location.assign(url); return; }

        poner(nueva, doc, url, opciones, mismaPantalla, alturaAntes);
      })
      .catch(function (err) {
        if (window.console && console.error) {
          console.error('[vista] no se pudo traer ' + url, err);
        }

        /* Si falla, la navegación de siempre: es mejor una recarga
           que quedarse en una pantalla que ya no corresponde. */
        location.assign(url);
      })
      .then(function () {
        cargando = false;
        progreso(false);
      });
  }

  function quitaAncla(u) {
    try {
      var x = new URL(u, location.href);
      return x.origin + x.pathname + x.search;
    } catch (e) { return u; }
  }

  // ------------------------------------------------------------------
  // Poner la pantalla nueva en su sitio
  // ------------------------------------------------------------------
  function poner(nueva, doc, url, opciones, mismaPantalla, alturaAntes) {
    var vieja = document.getElementById(CAJA);

    /* Qué pestaña estaba abierta, para volver a ella. Al filtrar los
       movimientos de una tarjeta, aterrizar en la primera pestaña en
       vez de en la que se estaba mirando es exactamente el tipo de
       salto que esto viene a quitar. */
    var pestana = null;
    var activa  = vieja.querySelector('.nav-tabs .nav-link.active[href^="#"]');

    if (activa) { pestana = activa.getAttribute('href'); }

    /* Fuera lo de antes, con sus gráficos y sus tablas: dejarlos
       colgando come memoria y los oyentes siguen vivos sobre nodos
       que ya no están en ninguna parte. */
    desmontar(vieja);

    vieja.innerHTML = nueva.innerHTML;

    // El título de la ventana y el de la cabecera.
    if (doc.title) { document.title = doc.title; }

    var enc = document.getElementById(TITULO);
    var encNuevo = doc.getElementById(TITULO);

    if (enc && encNuevo) { enc.textContent = encNuevo.textContent; }

    // El menú: qué opción queda marcada.
    marcarMenu(doc);

    // El historial, salvo cuando venimos justo de él.
    if (!opciones.delHistorial) {
      if (opciones.reemplazar || quitaAncla(url) === quitaAncla(location.href)) {
        history.replaceState({ cm: true }, '', url);
      } else {
        history.pushState({ cm: true }, '', url);
      }
    }

    reinyectarGuiones(vieja, nueva);
    montar(vieja);

    // Volver a la pestaña que estaba abierta.
    if (pestana) {
      var enlace = vieja.querySelector('.nav-tabs .nav-link[href="' + pestana + '"]');

      if (enlace && window.jQuery && window.jQuery(enlace).tab) {
        try { window.jQuery(enlace).tab('show'); } catch (e) {}
      }
    }

    /* Dónde dejar la vista. Si se cambió de pantalla, arriba, como
       haría el navegador. Si es la misma —se paginó, se filtró— se
       respeta dónde estaba: moverla sería perder el sitio. */
    var ancla = (function () {
      try { return new URL(url, location.href).hash; } catch (e) { return ''; }
    })();

    if (ancla) {
      var destino = document.querySelector(ancla);

      if (destino && destino.scrollIntoView) {
        destino.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } else if (!mismaPantalla) {
        window.scrollTo(0, 0);
      }
    } else if (mismaPantalla) {
      window.scrollTo(0, alturaAntes);
    } else {
      window.scrollTo(0, 0);
    }

    document.dispatchEvent(new CustomEvent('cm:vista', { detail: { url: url } }));
  }

  // ------------------------------------------------------------------
  // Quitar y volver a poner los enganches
  // ------------------------------------------------------------------
  function desmontar(caja) {
    var $ = window.jQuery;

    if ($ && $.fn && $.fn.DataTable) {
      caja.querySelectorAll('table').forEach(function (t) {
        try {
          if ($.fn.DataTable.isDataTable(t)) { $(t).DataTable().destroy(); }
        } catch (e) {}
      });
    }

    /* Los gráficos de Chart.js guardan su contexto: destruirlos evita
       que sigan respondiendo al ratón sobre lienzos ya retirados. */
    if (window.Chart && window.Chart.instances) {
      Object.keys(window.Chart.instances).forEach(function (k) {
        var g = window.Chart.instances[k];

        try {
          if (g && g.canvas && caja.contains(g.canvas)) { g.destroy(); }
        } catch (e) {}
      });
    }
  }

  function montar(caja) {
    /* Los enlaces de las filas, otra vez en pestaña nueva. */
    if (window.cmEnlacesDeTabla) { window.cmEnlacesDeTabla(caja); }

    /* Las tablas: `cm.js` las engancha al cargar la página, y esto no
       es una carga de página. Se repite aquí lo mismo. */
    var $ = window.jQuery;

    if ($ && $.fn && $.fn.DataTable && window.cmTablas) {
      window.cmTablas(caja);
    }

    /* Lo que cada guion suelto quiera rehacer: se avisa y punto, en
       vez de que esto tenga que conocerlos a todos. */
    document.dispatchEvent(new CustomEvent('cm:montar', { detail: { caja: caja } }));
  }

  // ------------------------------------------------------------------
  // Los guiones que venían dentro de la pantalla
  // ------------------------------------------------------------------
  //
  // `innerHTML` inserta los `<script>` pero el navegador NO los
  // ejecuta: es una regla vieja del lenguaje. Hay que recrearlos.
  //
  // Y hay que ponerles el nonce de la página en curso: el que traen
  // es el de su propia respuesta, y la política que está en vigor es
  // la de la página que se abrió, que solo reconoce el suyo.
  function reinyectarGuiones(destino, origen) {
    var guiones = origen.querySelectorAll('script');

    Array.prototype.forEach.call(guiones, function (viejo) {
      var nuevo = document.createElement('script');

      if (viejo.type) { nuevo.type = viejo.type; }

      /* Las islas de datos (`application/json`) no se ejecutan: ya
         están puestas por `innerHTML` y recrearlas las duplicaría. */
      if (viejo.type && viejo.type.indexOf('json') !== -1) { return; }

      if (nonce) { nuevo.nonce = nonce; nuevo.setAttribute('nonce', nonce); }

      if (viejo.src) {
        nuevo.src = viejo.src;
        nuevo.async = false;
      } else {
        nuevo.textContent = viejo.textContent;
      }

      destino.appendChild(nuevo);
    });
  }

  // ------------------------------------------------------------------
  // El menú, marcado donde toca
  // ------------------------------------------------------------------
  /* -------------------------------------------------------------------
     EL MENÚ, MARCADO DONDE TOCA

     Esto es lo que hacía que, navegando por el sistema, el menú dejara
     de decir qué pantalla se está viendo.

     La versión anterior copiaba la marca `mm-active` de un `<li>` a
     otro, emparejándolos por su posición en la lista. Dos fallos a la
     vez:

       · El servidor marca las opciones de dentro de un grupo en la
         ETIQUETA `<a>`, no en el `<li>` —así es como las estiliza la
         plantilla—. Esa marca no se copiaba nunca. De ahí el síntoma:
         el grupo se abría y resaltaba, y dentro no había nada
         encendido.

       · Tampoco se copiaba qué grupo queda DESPLEGADO (`mm-show` y
         `aria-expanded`), de modo que el submenú abierto seguía siendo
         el de la pantalla anterior.

     Ahora se copia el estado entero, y emparejando por DIRECCIÓN en
     vez de por posición: si un permiso cambiara a mitad de sesión y el
     menú tuviera otra forma, emparejar por posición marcaría la opción
     equivocada, que es peor que no marcar ninguna.
     ------------------------------------------------------------------- */
  function marcarMenu(doc) {
    var ahora = document.querySelector('.metismenu');
    var luego = doc.querySelector('.metismenu');

    if (!ahora || !luego) { return; }

    /* Cuál es LA opción encendida en la página nueva, por dirección.
       De dos formas, porque el servidor marca cada clase de opción
       donde la plantilla la estiliza:

         · las de dentro de un grupo, en el `<a>`;
         · las sueltas de primer nivel («Inicio», «Mi perfil»…), en su
           `<li>`.

       Los `<li>` de grupo también llevan la marca, pero su enlace es
       `javascript:void(0)` —el mismo en todos—, así que no sirve de
       llave y se descarta: con él se encenderían todos los grupos a la
       vez. El grupo que toca se abre solo, subiendo desde la opción. */
    var encendida = null;

    var marcadaNueva = luego.querySelector('a.mm-active');

    if (marcadaNueva === null) {
      var sueltas = luego.querySelectorAll('li.mm-active > a[href]');

      for (var k = 0; k < sueltas.length; k++) {
        var h = sueltas[k].getAttribute('href') || '';

        if (h !== '' && h.indexOf('javascript:') !== 0) {
          marcadaNueva = sueltas[k];
          break;
        }
      }
    }

    if (marcadaNueva !== null) {
      encendida = marcadaNueva.getAttribute('href') || '';

      if (encendida === '' || encendida.indexOf('javascript:') === 0) {
        encendida = null;
      }
    }

    /* Se apaga todo y se enciende lo que toca. Apagar primero evita
       que quede encendida la opción de la pantalla anterior cuando la
       nueva no tiene ninguna. */
    Array.prototype.forEach.call(ahora.querySelectorAll('a'), function (a) {
      a.classList.remove('mm-active');
      a.classList.remove('active');
    });

    Array.prototype.forEach.call(ahora.querySelectorAll('li'), function (li) {
      li.classList.remove('mm-active');
    });

    Array.prototype.forEach.call(ahora.querySelectorAll('ul ul'), function (ul) {
      ul.classList.remove('mm-show');
      ul.setAttribute('aria-expanded', 'false');
    });

    if (encendida === null) { return; }

    var marcado = ahora.querySelector('a[href="' + encendida.replace(/"/g, '\\"') + '"]');

    if (marcado === null) { return; }

    marcado.classList.add('mm-active');

    /* Y hacia arriba: el grupo que la contiene se marca y se despliega,
       porque una opción encendida dentro de un submenú cerrado no la ve
       nadie. */
    var nodo = marcado.parentNode;

    while (nodo && nodo !== ahora.parentNode) {
      if (nodo.tagName === 'LI') {
        nodo.classList.add('mm-active');
      }

      if (nodo.tagName === 'UL' && nodo !== ahora) {
        nodo.classList.add('mm-show');
        nodo.setAttribute('aria-expanded', 'true');

        var padre = nodo.previousElementSibling;

        if (padre && padre.tagName === 'A') {
          padre.setAttribute('aria-expanded', 'true');
        }
      }

      nodo = nodo.parentNode;
    }
  }

  // ------------------------------------------------------------------
  // Los clics
  // ------------------------------------------------------------------
  document.addEventListener('click', function (ev) {
    // Gestos del navegador: abrir en otra pestaña, en otra ventana…
    if (ev.defaultPrevented || ev.button !== 0) { return; }
    if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) { return; }

    var a = ev.target.closest ? ev.target.closest('a[href]') : null;

    if (!a) { return; }
    if (a.target && a.target !== '_self') { return; }
    if (a.hasAttribute('download')) { return; }
    if (a.hasAttribute('data-cm-entero')) { return; }

    var href = a.getAttribute('href');

    if (!href || href.charAt(0) === '#') { return; }
    if (/^(javascript:|mailto:|tel:)/i.test(href)) { return; }
    if (!interna(a.href)) { return; }

    ev.preventDefault();
    traer(a.href, {});
  });

  // ------------------------------------------------------------------
  // Los formularios de consulta
  // ------------------------------------------------------------------
  //
  // Los de GET —buscar, filtrar— se resuelven igual: se arma la
  // dirección con lo que hay puesto y se trae la pantalla. Los de POST
  // no se tocan: de ésos se encarga `cm.js`, que ya los manda sin
  // recargar y sabe leer la respuesta.
  document.addEventListener('submit', function (ev) {
    var form = ev.target;

    if (ev.defaultPrevented) { return; }
    if ((form.method || 'get').toLowerCase() !== 'get') { return; }
    if (form.hasAttribute('data-cm-entero')) { return; }

    var accion = form.getAttribute('action') || location.pathname;

    if (!interna(new URL(accion, location.href).href)) { return; }

    var datos = new URLSearchParams(new FormData(form));

    /* Los campos vacíos no se llevan a la dirección: «?anio=&mes=»
       ensucia el enlace que luego se comparte o se guarda. */
    Array.prototype.slice.call(datos.keys()).forEach(function (k) {
      if (datos.get(k) === '') { datos.delete(k); }
    });

    var cola = datos.toString();
    var url  = new URL(accion, location.href);

    url.search = cola;

    ev.preventDefault();
    traer(url.href, {});
  });

  /* Un desplegable marcado con `data-cm-enviar` consulta al elegir,
     sin tener que pulsar nada: es lo que se espera de un filtro. */
  document.addEventListener('change', function (ev) {
    var campo = ev.target;

    if (!campo.matches || !campo.matches('[data-cm-enviar]')) { return; }

    var form = campo.form;

    if (!form) { return; }

    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    }
  });

  // ------------------------------------------------------------------
  // Atrás y adelante
  // ------------------------------------------------------------------
  window.addEventListener('popstate', function () {
    traer(location.href, { delHistorial: true });
  });

  history.replaceState({ cm: true }, '', location.href);

  // ------------------------------------------------------------------
  // Para que otros guiones puedan pedirlo
  // ------------------------------------------------------------------
  window.CMVista = {
    /** Trae otra pantalla sin recargar. */
    ir: function (url) { return traer(url, {}); },

    /** Vuelve a pedir la de ahora: lo que antes era `location.reload()`. */
    refrescar: function () {
      return traer(location.href, { delHistorial: true, reemplazar: true });
    }
  };
})(window, document);
