/* =====================================================================
   TIENDA · CARRUSEL DE COMERCIOS
   =====================================================================

   La tira de comercios se recorría con una barra de desplazamiento
   debajo de las fichas, y eso en una tienda se lee como un desperfecto:
   una raya gris bajo el contenido que nadie sabe si forma parte del
   diseño o es la página que se rompió.

   Un carrusel dice lo mismo sin ruido: dos flechas a los lados que
   aparecen solo cuando de verdad hay más que ver y se apagan al llegar
   a cada extremo.

   El desplazamiento sigue funcionando con el dedo y con la rueda —se
   oculta la barra, no el gesto—, así que en un teléfono se recorre como
   siempre. Y si este archivo no cargara, la tira seguiría siendo
   utilizable: las flechas nacen ocultas y solo este guion las enciende.
   ===================================================================== */
(function () {
  'use strict';

  function preparar(carrusel) {
    var pista = carrusel.querySelector('[data-tda-pista]');

    if (!pista) { return; }

    var izq = carrusel.querySelector('[data-tda-ir="-1"]');
    var der = carrusel.querySelector('[data-tda-ir="1"]');

    /** Cuánto avanza cada pulsación: casi una pantalla, con solape. */
    function salto() {
      return Math.max(160, pista.clientWidth * 0.8);
    }

    function actualizar() {
      // 2px de margen: los navegadores redondean el desplazamiento y sin
      // esa holgura la flecha de la derecha nunca llega a apagarse.
      var resto = pista.scrollWidth - pista.clientWidth - pista.scrollLeft;
      var hayMas = pista.scrollWidth > pista.clientWidth + 2;

      if (izq) {
        izq.hidden = !hayMas;
        izq.disabled = pista.scrollLeft <= 2;
      }

      if (der) {
        der.hidden = !hayMas;
        der.disabled = resto <= 2;
      }

      carrusel.classList.toggle('tda-carrusel--izq', hayMas && pista.scrollLeft > 2);
      carrusel.classList.toggle('tda-carrusel--der', hayMas && resto > 2);
    }

    [izq, der].forEach(function (b) {
      if (!b) { return; }

      b.addEventListener('click', function () {
        pista.scrollBy({
          left: salto() * Number(b.getAttribute('data-tda-ir')),
          behavior: 'smooth'
        });
      });
    });

    pista.addEventListener('scroll', actualizar, { passive: true });
    window.addEventListener('resize', actualizar);

    /* Que el comercio elegido se vea al entrar.
       Si alguien filtra por el último de la tira y al volver la página
       lo deja fuera de cuadro, parece que el filtro no se aplicó. */
    var activa = pista.querySelector('.tda-tienda--activa');

    if (activa && activa.offsetLeft > pista.clientWidth - activa.offsetWidth) {
      pista.scrollLeft = activa.offsetLeft - 16;
    }

    actualizar();
  }

  function iniciar() {
    document.querySelectorAll('[data-tda-carrusel]').forEach(preparar);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar);
  } else {
    iniciar();
  }
})();
