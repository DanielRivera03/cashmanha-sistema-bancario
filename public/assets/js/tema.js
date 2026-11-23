/* =====================================================================
   El tema: día, noche o el del sistema
   =====================================================================

   Vive en su propio archivo y se carga en el <head> porque tiene que
   aplicarse ANTES del primer pintado. Si se aplicara al final, la
   página entera aparecería en blanco y pasaría a negro medio segundo
   después: ese destello es molesto de día y doloroso de noche.

   La preferencia se guarda en el navegador y, si el cliente está
   dentro, también en su cuenta: quien elige el modo oscuro en su
   teléfono no quiere volver a elegirlo al abrir en la computadora.
   ===================================================================== */
(function (global, documento) {
  'use strict';

  var LLAVE = 'cm-tema';

  var ICONOS = {
    dia: '<circle cx="12" cy="12" r="4.2"/>'
       + '<path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4'
       + 'M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" stroke-linecap="round"/>',
    noche: '<path d="M20.5 14.3A8.5 8.5 0 019.7 3.5a8.5 8.5 0 1010.8 10.8z"/>',
    auto: '<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 000 18z" fill="currentColor"/>'
  };

  /** Lo que el sistema operativo pide, cuando se le deja mandar. */
  function delSistema() {
    try {
      return global.matchMedia
          && global.matchMedia('(prefers-color-scheme: dark)').matches
        ? 'noche' : 'dia';
    } catch (e) {
      return 'dia';
    }
  }

  function guardada() {
    try {
      return global.localStorage.getItem(LLAVE);
    } catch (e) {
      // Navegación privada, almacenamiento bloqueado: no es un error,
      // simplemente no hay preferencia guardada.
      return null;
    }
  }

  /**
   * Pinta el tema. `modo` es lo que el usuario eligió —dia, noche o
   * auto—; lo que se aplica puede ser otra cosa cuando elige auto.
   */
  function aplicar(modo) {
    var efectivo = modo === 'auto' ? delSistema() : modo;

    documento.documentElement.setAttribute('data-tema', efectivo);
    documento.documentElement.setAttribute('data-tema-modo', modo);

    /* La plantilla trae SU PROPIO modo oscuro: mil y pico de reglas
     * que ya cubren el menú, la cabecera, las tablas, los formularios
     * y todo lo que trajo de fábrica.
     *
     * Reescribir eso a mano sería volver a hacer lo que ya está hecho,
     * y cada componente que se olvidara quedaría en blanco sobre negro.
     * Se enciende el suyo y encima va el nuestro, que solo se ocupa de
     * las piezas propias de CashMan y de afinar el contraste.
     *
     * El atributo va en el <body> porque la mitad de sus reglas lo
     * combinan con `data-layout` y `data-sidebar-style`, que viven
     * ahí: puesto en el <html> esas combinaciones no casarían. */
    var cuerpo = documento.body;

    if (cuerpo) {
      if (efectivo === 'noche') {
        cuerpo.setAttribute('data-theme-version', 'dark');
      } else {
        cuerpo.removeAttribute('data-theme-version');
      }
    }

    logotipo(efectivo);
  }

  /**
   * El logotipo, que es una imagen y no texto.
   *
   * El logotipo de la casa es azul marino sobre fondo transparente:
   * sobre papel blanco se lee perfecto y sobre el panel azul noche no
   * está. Durante un tiempo esto se intentó con `color` en el CSS, que
   * no podía funcionar —a una imagen no la tiñe el color del texto— y
   * después con dos imágenes y `display`, que salió peor: la plantilla
   * las dimensiona con reglas propias y al forzarlo el logotipo salió
   * a tamaño natural encima de toda la pantalla.
   *
   * Lo único que se puede cambiar sin alterar cómo se mide una imagen
   * es su `src`. Cada una lleva sus dos rutas y aquí se elige.
   */
  function logotipo(efectivo) {
    var imgs = documento.querySelectorAll('img[data-logo-dia]');

    for (var i = 0; i < imgs.length; i++) {
      var ruta = imgs[i].getAttribute(
        efectivo === 'noche' ? 'data-logo-noche' : 'data-logo-dia'
      );

      // Comparar antes de asignar: escribir el mismo `src` dispara
      // otra petición en algunos navegadores.
      if (ruta && imgs[i].getAttribute('src') !== ruta) {
        imgs[i].setAttribute('src', ruta);
      }
    }
  }

  /* El tema inicial, cuanto antes.
     El <html> ya existe aunque el <body> no, así que esto puede correr
     en el <head> y evitar el destello. */
  var inicial = guardada()
    || documento.documentElement.getAttribute('data-tema-inicial')
    || 'auto';

  aplicar(inicial);

  /* Si está en automático, seguir al sistema cuando cambie: hay
     teléfonos que pasan a oscuro al anochecer. */
  try {
    if (global.matchMedia) {
      global.matchMedia('(prefers-color-scheme: dark)')
        .addEventListener('change', function () {
          if ((guardada() || inicial) === 'auto') { aplicar('auto'); }
        });
    }
  } catch (e) { /* navegadores viejos: se queda con lo que haya */ }

  // -------------------------------------------------------------------
  // El botón flotante
  // -------------------------------------------------------------------
  function svg(nombre, tam) {
    return '<svg viewBox="0 0 24 24" width="' + tam + '" height="' + tam + '"'
      + ' fill="none" stroke="currentColor" stroke-width="1.8">'
      + ICONOS[nombre] + '</svg>';
  }

  function montar() {
    if (documento.getElementById('cm-tema-boton')) { return; }

    var modo = guardada() || inicial;

    var boton = documento.createElement('button');
    boton.type = 'button';
    boton.id = 'cm-tema-boton';
    boton.className = 'cm-tema-boton';
    boton.setAttribute('aria-haspopup', 'true');
    boton.setAttribute('aria-expanded', 'false');
    boton.setAttribute('aria-label', 'Personalizar la apariencia');
    boton.setAttribute('title', 'Personalizar la apariencia');
    boton.innerHTML = svg(modo === 'auto' ? 'auto' : modo, 21);

    var panel = documento.createElement('div');
    panel.className = 'cm-tema-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Apariencia');

    panel.innerHTML =
        '<div class="cm-tema-panel__titulo">Apariencia</div>'
      + opcion('dia',   'Modo día',   'Fondo claro, como hasta ahora.')
      + opcion('noche', 'Modo noche', 'Fondo oscuro, más descansado de noche.')
      + opcion('auto',  'Automático', 'Sigue lo que use su dispositivo.')
      + '<div class="cm-tema-panel__nota">Su elección se guarda y le acompaña '
      + 'la próxima vez que entre.</div>';

    documento.body.appendChild(panel);
    documento.body.appendChild(boton);

    function opcion(valor, titulo, detalle) {
      return '<button type="button" class="cm-tema-opcion" data-tema-opcion="' + valor + '">'
        + svg(valor, 17)
        + '<span><strong style="display:block;font-size:.84rem">' + titulo + '</strong>'
        + '<small style="font-size:.73rem;opacity:.8">' + detalle + '</small></span>'
        + '</button>';
    }

    function marcar() {
      var actual = guardada() || inicial;

      panel.querySelectorAll('[data-tema-opcion]').forEach(function (b) {
        b.classList.toggle('cm-tema-opcion--activa',
          b.getAttribute('data-tema-opcion') === actual);
      });

      boton.innerHTML = svg(actual === 'auto' ? 'auto' : actual, 21);
    }

    function abrir(si) {
      panel.classList.toggle('cm-tema-panel--abierto', si);
      boton.setAttribute('aria-expanded', si ? 'true' : 'false');
    }

    marcar();

    boton.addEventListener('click', function (ev) {
      ev.stopPropagation();
      abrir(!panel.classList.contains('cm-tema-panel--abierto'));
    });

    panel.addEventListener('click', function (ev) {
      var b = ev.target.closest ? ev.target.closest('[data-tema-opcion]') : null;
      if (!b) { return; }

      var elegido = b.getAttribute('data-tema-opcion');

      try { global.localStorage.setItem(LLAVE, elegido); } catch (e) { /* da igual */ }

      inicial = elegido;
      aplicar(elegido);
      marcar();
      abrir(false);

      guardarEnLaCuenta(elegido);
    });

    // Fuera del panel se cierra; con Escape también.
    documento.addEventListener('click', function () { abrir(false); });

    documento.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { abrir(false); }
    });
  }

  /**
   * Guarda la elección en la cuenta, si hay sesión.
   *
   * Falla en silencio a propósito: el tema ya está aplicado y guardado
   * en el navegador. Que el servidor no pueda anotarlo es un fastidio
   * —habrá que volver a elegirlo en otro dispositivo—, no un motivo
   * para molestar al usuario con un error.
   */
  function guardarEnLaCuenta(modo) {
    var meta = documento.querySelector('meta[name="cm-tema-url"]');
    var tok  = documento.querySelector('meta[name="cm-tema-csrf"]');

    if (!meta || !tok || !global.fetch) { return; }

    var datos = new FormData();
    datos.append('_csrf', tok.getAttribute('content') || '');
    datos.append('_csrf_proposito', 'tema');
    datos.append('tema', modo);

    global.fetch(meta.getAttribute('content'), {
      method: 'POST',
      body: datos,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        // El token es de un solo uso: se recoge el relevo para poder
        // volver a cambiar de tema sin recargar.
        if (j && j.csrf) { tok.setAttribute('content', j.csrf); }
      })
      .catch(function () { /* el tema ya quedó aplicado */ });
  }

  /* El <body> no existe todavía cuando este guion corre en el <head>,
     así que el atributo de la plantilla se pone en cuanto exista. El
     del <html> ya quedó puesto arriba y es el que evita el destello. */
  function alCargar() {
    aplicar(guardada() || inicial);
    montar();
  }

  if (documento.readyState === 'loading') {
    documento.addEventListener('DOMContentLoaded', alCargar);
  } else {
    alCargar();
  }
})(window, document);
