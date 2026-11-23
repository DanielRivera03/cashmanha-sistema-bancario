/* =====================================================================
   La bienvenida a un producto nuevo
   =====================================================================

   Cuando a alguien le aprueban una tarjeta, el sistema le decía
   «solicitud registrada» en un aviso de cuatro segundos y se acabó.
   Para la institución es una fila más en una tabla; para el cliente es
   el día que le dieron su primera tarjeta de crédito, y eso se celebra.

   Lo que se enseña no es solo confeti: es el plástico que va a
   recibir, sus beneficios y qué pasa después. Una celebración que no
   informa es ruido, y a los dos minutos el cliente sigue sin saber
   cuándo le llega la tarjeta.

   El confeti se dibuja en un lienzo y se apaga solo a los pocos
   segundos. Respeta `prefers-reduced-motion`: a quien pidió menos
   movimiento se le enseña la tarjeta sin nada volando.
   ===================================================================== */
(function (global, documento) {
  'use strict';

  var COLORES = ['#6418c3', '#a479f5', '#c99a2e', '#e0b65c', '#12b76a', '#2e90fa'];

  function menosMovimiento() {
    try {
      return global.matchMedia
          && global.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) {
      return false;
    }
  }

  /**
   * El confeti.
   *
   * Sobre un lienzo y no con elementos del DOM: doscientos divs
   * animados hacen sudar a un teléfono, y esto tiene que verse bien
   * justo en el momento en que el cliente está contento.
   */
  function confeti(lienzo, segundos) {
    var ctx = lienzo.getContext('2d');
    if (!ctx) { return function () {}; }

    var piezas = [];
    var vivo   = true;

    function medir() {
      var r = global.devicePixelRatio || 1;
      lienzo.width  = lienzo.offsetWidth * r;
      lienzo.height = lienzo.offsetHeight * r;
      ctx.setTransform(r, 0, 0, r, 0, 0);
    }

    medir();
    global.addEventListener('resize', medir);

    for (var i = 0; i < 160; i++) {
      piezas.push({
        x: Math.random() * lienzo.offsetWidth,
        // Arrancan por encima del borde para que entren cayendo y no
        // aparezcan de golpe a media pantalla.
        y: -20 - Math.random() * lienzo.offsetHeight,
        ancho: 6 + Math.random() * 6,
        alto: 9 + Math.random() * 7,
        color: COLORES[(Math.random() * COLORES.length) | 0],
        vy: 1.6 + Math.random() * 2.6,
        vx: -0.9 + Math.random() * 1.8,
        giro: Math.random() * Math.PI,
        vgiro: -0.09 + Math.random() * 0.18
      });
    }

    var desde = Date.now();

    function cuadro() {
      if (!vivo) { return; }

      ctx.clearRect(0, 0, lienzo.offsetWidth, lienzo.offsetHeight);

      var edad = (Date.now() - desde) / 1000;

      // Se desvanece al final en vez de desaparecer de golpe.
      ctx.globalAlpha = edad > segundos - 1
        ? Math.max(0, segundos - edad)
        : 1;

      piezas.forEach(function (p) {
        p.y += p.vy;
        p.x += p.vx;
        p.giro += p.vgiro;

        // Lo que sale por abajo vuelve por arriba mientras dure.
        if (p.y > lienzo.offsetHeight + 20 && edad < segundos - 1.4) {
          p.y = -20;
          p.x = Math.random() * lienzo.offsetWidth;
        }

        ctx.save();
        ctx.translate(p.x, p.y);
        ctx.rotate(p.giro);
        ctx.fillStyle = p.color;
        ctx.fillRect(-p.ancho / 2, -p.alto / 2, p.ancho, p.alto);
        ctx.restore();
      });

      if (edad < segundos) {
        global.requestAnimationFrame(cuadro);
      } else {
        ctx.clearRect(0, 0, lienzo.offsetWidth, lienzo.offsetHeight);
      }
    }

    global.requestAnimationFrame(cuadro);

    return function () {
      vivo = false;
      global.removeEventListener('resize', medir);
    };
  }

  /**
   * Abre la bienvenida.
   *
   * @param {{titulo:string, subtitulo:string, plastico:string,
   *          beneficios:Array<string>, datos:Array<{et:string,val:string}>,
   *          pie:string, boton:string, url:string}} o
   */
  function abrir(o) {
    o = o || {};

    var e = function (s) {
      var d = documento.createElement('div');
      d.textContent = String(s == null ? '' : s);
      return d.innerHTML;
    };

    var fondo = documento.createElement('div');
    fondo.className = 'cm-fiesta';
    fondo.setAttribute('role', 'dialog');
    fondo.setAttribute('aria-modal', 'true');
    fondo.setAttribute('aria-label', o.titulo || 'Bienvenida');

    var beneficios = (o.beneficios || []).map(function (b) {
      return '<li>' + e(b) + '</li>';
    }).join('');

    var datos = (o.datos || []).map(function (d) {
      return '<div class="cm-fiesta__dato">'
        + '<small>' + e(d.et) + '</small>'
        + '<strong>' + e(d.val) + '</strong>'
        + '</div>';
    }).join('');

    fondo.innerHTML =
        '<canvas class="cm-fiesta__confeti" aria-hidden="true"></canvas>'
      + '<div class="cm-fiesta__caja" role="document">'
      +   '<button type="button" class="cm-fiesta__cerrar" aria-label="Cerrar">&times;</button>'
      +   '<div class="cm-fiesta__cinta">¡Felicidades!</div>'
      +   '<h3 class="cm-fiesta__titulo">' + e(o.titulo || '') + '</h3>'
      +   '<p class="cm-fiesta__sub">' + e(o.subtitulo || '') + '</p>'
      +   (o.plastico ? '<div class="cm-fiesta__plastico">' + o.plastico + '</div>' : '')
      +   (datos ? '<div class="cm-fiesta__datos">' + datos + '</div>' : '')
      +   (beneficios
            ? '<div class="cm-fiesta__beneficios">'
              + '<strong>Lo que incluye</strong><ul>' + beneficios + '</ul></div>'
            : '')
      +   (o.pie ? '<p class="cm-fiesta__pie">' + e(o.pie) + '</p>' : '')
      +   '<div class="cm-fiesta__botones">'
      +     '<button type="button" class="btn btn-primary cm-fiesta__ok">'
      +       e(o.boton || 'Entendido') + '</button>'
      +   '</div>'
      + '</div>';

    documento.body.appendChild(fondo);
    documento.body.style.overflow = 'hidden';

    var parar = function () {};

    if (!menosMovimiento()) {
      parar = confeti(fondo.querySelector('.cm-fiesta__confeti'), 6);
    }

    requestAnimationFrame(function () { fondo.classList.add('cm-fiesta--visible'); });

    function cerrar() {
      parar();
      fondo.classList.remove('cm-fiesta--visible');
      documento.body.style.overflow = '';

      setTimeout(function () {
        if (fondo.parentNode) { fondo.parentNode.removeChild(fondo); }
        if (o.url) { global.location.assign(o.url); }
      }, 260);
    }

    fondo.querySelector('.cm-fiesta__cerrar').addEventListener('click', cerrar);
    fondo.querySelector('.cm-fiesta__ok').addEventListener('click', cerrar);

    fondo.addEventListener('click', function (ev) {
      if (ev.target === fondo) { cerrar(); }
    });

    documento.addEventListener('keydown', function esc(ev) {
      if (ev.key === 'Escape') {
        documento.removeEventListener('keydown', esc);
        cerrar();
      }
    });

    // El foco al botón: quien navega con teclado no debería tener que
    // buscar por dónde se sale.
    fondo.querySelector('.cm-fiesta__ok').focus();

    return cerrar;
  }

  global.CMFiesta = { abrir: abrir };
})(window, document);
