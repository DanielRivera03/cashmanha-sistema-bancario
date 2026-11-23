/* =====================================================================
   ESTE ARCHIVO YA NO HACE NADA
   =====================================================================

   Lo que había aquí —recoger el token nuevo de cada respuesta— vive
   ahora en `token.js`, junto con el registro por propósito y el
   reintento automático.

   Había DOS implementaciones de lo mismo, ésta y otra dentro de
   `cm.js`, y ninguna de las dos cubría todos los casos: ésta buscaba
   el token dentro de `datos` cuando el servidor lo manda arriba, así
   que casi nunca lo encontraba; y la de `cm.js` no actualizaba el
   token del layout, que con navegación en vivo quedaba gastado para el
   resto de la sesión.

   El archivo se queda, vacío y sin envolver `fetch`, por una razón
   concreta: un navegador con la página anterior en caché seguiría
   pidiéndolo. Borrarlo daría un 404 y, peor, dos envoltorios de
   `fetch` encadenados si una copia vieja llegara a cargarse junto con
   la nueva.

   Si lo ve referenciado en algún sitio, cámbielo por `token.js`.
   ===================================================================== */
(function (global) {
  'use strict';

  /* Si por lo que sea `token.js` no se cargó, al menos que las vistas
     que llaman a `cmAdoptarCsrf` no revienten. */
  if (typeof global.cmAdoptarCsrf !== 'function') {
    global.cmAdoptarCsrf = function () {};
  }
})(window);
