/* =====================================================================
   MENÚ ENCOGIDO: DESPLEGABLES QUE SÍ SE PUEDEN PULSAR
   =====================================================================

   Con el menú encogido, la plantilla enseña el submenú con un
   `li:hover > ul` colocado en `position: absolute` dentro de la propia
   barra. Eso falla por dos motivos a la vez:

     · La barra y su contenedor de desplazamiento recortan lo que se
       sale (`overflow`), así que el panel aparecía cortado o detrás del
       contenido, y las opciones quedaban fuera de alcance del ratón.

     · Al depender solo de `:hover`, en cuanto el puntero salía del
       icono el panel desaparecía. No había forma de llegar a las
       opciones: se cerraba a medio camino.

   El resultado era el que se veía: un panel bonito que no se podía
   pulsar, y un enlace padre que apunta a `javascript:void(0)` y por
   tanto tampoco lleva a ninguna parte.

   Aquí el panel se construye una sola vez, se cuelga del `<body>` —así
   no hay `overflow` de nadie que lo recorte— y se coloca con
   coordenadas calculadas del icono. Se abre al pasar el ratón y también
   al pulsar, se queda abierto mientras el puntero esté sobre el icono o
   sobre el panel, y se cierra con Escape, al pulsar fuera o al salir.

   Funciona igual con teclado: el enlace padre recibe foco, Enter abre y
   Escape cierra.
   ===================================================================== */
(function () {
  'use strict';

  var wrapper = document.getElementById('main-wrapper');
  var menu    = document.getElementById('menu');

  if (!wrapper || !menu) { return; }

  var panel   = null;
  var abiertoEn = null;   // el <li> cuyo submenú está abierto
  var cierreId  = null;

  /** ¿Está el menú encogido ahora mismo? */
  function encogido() {
    return wrapper.classList.contains('menu-toggle')
        && window.innerWidth > 767;
  }

  function crearPanel() {
    if (panel) { return panel; }

    panel = document.createElement('div');
    panel.className = 'cm-flotante';
    panel.setAttribute('role', 'menu');
    panel.hidden = true;

    // Mientras el puntero esté encima, no se cierra: es lo que permite
    // ir del icono a la opción sin que el panel se evapore.
    panel.addEventListener('mouseenter', cancelarCierre);
    panel.addEventListener('mouseleave', programarCierre);

    document.body.appendChild(panel);
    return panel;
  }

  function cancelarCierre() {
    if (cierreId !== null) {
      window.clearTimeout(cierreId);
      cierreId = null;
    }
  }

  function programarCierre() {
    cancelarCierre();
    // Un respiro corto: el puntero cruza unos píxeles de hueco entre el
    // icono y el panel, y cerrar al instante lo haría inservible.
    cierreId = window.setTimeout(cerrar, 180);
  }

  function cerrar() {
    cancelarCierre();

    if (panel) { panel.hidden = true; }

    if (abiertoEn) {
      abiertoEn.classList.remove('cm-flotante-activo');
      var a = abiertoEn.querySelector(':scope > a');
      if (a) { a.setAttribute('aria-expanded', 'false'); }
    }

    abiertoEn = null;
  }

  /**
   * Abre el submenú de un <li> junto a su icono.
   *
   * El contenido se clona: así el menú original sigue intacto para
   * cuando la barra vuelva a su tamaño normal, y el panel puede vivir
   * colgado del <body>.
   */
  function abrir(li) {
    var sub = li.querySelector(':scope > ul');
    if (!sub) { return; }

    cancelarCierre();

    if (abiertoEn === li && panel && !panel.hidden) { return; }
    if (abiertoEn !== li) { cerrar(); }

    var p = crearPanel();
    p.innerHTML = '';

    // Encabezado: el nombre de la sección. Con la barra encogida el
    // texto del icono no se ve, así que sin esto el panel no dice de
    // qué sección es.
    var rotulo = li.querySelector(':scope > a .nav-text');

    if (rotulo) {
      var cab = document.createElement('div');
      cab.className = 'cm-flotante__titulo';
      cab.textContent = rotulo.textContent.trim();
      p.appendChild(cab);
    }

    var lista = document.createElement('ul');
    lista.className = 'cm-flotante__lista';

    sub.querySelectorAll(':scope > li > a').forEach(function (a) {
      var item = document.createElement('li');
      var copia = a.cloneNode(true);

      copia.classList.remove('mm-active');
      if (a.classList.contains('mm-active') || a.classList.contains('active')) {
        copia.classList.add('cm-flotante__actual');
      }

      item.appendChild(copia);
      lista.appendChild(item);
    });

    p.appendChild(lista);
    p.hidden = false;

    // Colocación: pegada al icono, y si no cabe hacia abajo se sube lo
    // justo para que quepa entera en la ventana.
    var r = li.getBoundingClientRect();

    p.style.left = (r.right + 6) + 'px';
    p.style.top  = r.top + 'px';

    var alto = p.offsetHeight;
    var sobra = window.innerHeight - (r.top + alto) - 12;

    if (sobra < 0) {
      p.style.top = Math.max(12, r.top + sobra) + 'px';
    }

    abiertoEn = li;
    li.classList.add('cm-flotante-activo');

    var padre = li.querySelector(':scope > a');
    if (padre) { padre.setAttribute('aria-expanded', 'true'); }
  }

  // --- Enganches -----------------------------------------------------

  menu.querySelectorAll(':scope > li').forEach(function (li) {
    if (!li.querySelector(':scope > ul')) { return; }

    li.addEventListener('mouseenter', function () {
      if (encogido()) { abrir(li); }
    });

    li.addEventListener('mouseleave', function () {
      if (encogido()) { programarCierre(); }
    });

    var a = li.querySelector(':scope > a');
    if (!a) { return; }

    /* Pulsar el icono abre el panel.
     *
     * El enlace padre apunta a `javascript:void(0)`, que no lleva a
     * ninguna parte: cuando la barra está encogida, pulsarlo no hacía
     * absolutamente nada. Ahora abre su submenú, que es lo que el
     * usuario esperaba al pulsar. */
    a.addEventListener('click', function (ev) {
      if (!encogido()) { return; }

      ev.preventDefault();

      if (abiertoEn === li && panel && !panel.hidden) {
        cerrar();
      } else {
        abrir(li);
      }
    });
  });

  // Pulsar fuera cierra.
  document.addEventListener('click', function (ev) {
    if (!abiertoEn || !panel || panel.hidden) { return; }
    if (panel.contains(ev.target) || abiertoEn.contains(ev.target)) { return; }
    cerrar();
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { cerrar(); }
  });

  // Si cambia el tamaño o se despliega la barra, el panel ya no
  // corresponde a ninguna posición: se cierra en vez de quedar flotando.
  window.addEventListener('resize', cerrar);
  window.addEventListener('scroll', cerrar, true);

  var botones = document.querySelectorAll('.nav-control, [data-cm-menu]');
  botones.forEach(function (b) { b.addEventListener('click', cerrar); });
})();
