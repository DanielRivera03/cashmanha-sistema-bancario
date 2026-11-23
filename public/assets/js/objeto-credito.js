/* =====================================================================
   El formulario pregunta lo que corresponde a cada crédito
   ---------------------------------------------------------------------
   Un crédito de vehículo necesita marca, modelo, VIN y chasis; uno de
   vivienda, la matrícula del Centro Nacional de Registros; uno de
   estudios, la institución y la carrera. Enseñar los veintitantos
   campos a la vez sería ilegible, y enseñar siempre los mismos es lo
   que llevaba a que el contrato de un vehículo no dijera qué vehículo.

   Aquí se muestra solo el bloque de la modalidad del producto elegido
   y se repuebla la lista de destinos con los que esa modalidad admite.

   LOS CAMPOS OCULTOS SE VACÍAN
   ---------------------------------------------------------------------
   Si alguien escribe el VIN de un carro, cambia de idea y elige un
   crédito educativo, ese VIN seguiría viajando en el formulario y
   acabaría guardado en un crédito de estudios. Al ocultar un bloque
   se limpian sus campos: lo que no se ve no se manda.
   ===================================================================== */
(function (document) {
  'use strict';

  var selProducto = document.getElementById('producto');
  var selDestino  = document.getElementById('destino');

  if (!selProducto) { return; }

  var bloques = Array.prototype.slice.call(
    document.querySelectorAll('.cm-bien[data-modalidad]')
  );

  function limpiar(bloque) {
    bloque.querySelectorAll('input, select, textarea').forEach(function (campo) {
      campo.value = '';
    });
  }

  function pintar() {
    var opcion = selProducto.options[selProducto.selectedIndex];

    if (!opcion) { return; }

    var modalidad = opcion.getAttribute('data-modalidad') || 'personal';

    bloques.forEach(function (b) {
      var suya = b.getAttribute('data-modalidad') === modalidad;

      if (!suya && !b.hidden) { limpiar(b); }

      b.hidden = !suya;
    });

    // ---------- Los destinos de esa modalidad ----------
    if (!selDestino) { return; }

    var lista = (opcion.getAttribute('data-destinos') || '').split('|')
      .filter(function (x) { return x !== ''; });

    var elegido = selDestino.value;

    selDestino.innerHTML = '';

    lista.forEach(function (d) {
      var o = document.createElement('option');

      o.value = d;
      o.textContent = d;

      if (d === elegido) { o.selected = true; }

      selDestino.appendChild(o);
    });

    /* Y la puerta de atrás, para los destinos que ninguna lista
       prevé: «compra de equipo de ortodoncia» es un destino de
       crédito personal perfectamente razonable y no está en ninguna
       lista. Solo donde el contrato no va a describir un bien
       concreto. */
    if (modalidad === 'personal' || modalidad === 'negocio') {
      var otro = document.createElement('option');

      otro.value = '';
      otro.textContent = 'Otro (lo escribo)';
      selDestino.appendChild(otro);
    }
  }

  selProducto.addEventListener('change', pintar);

  /* Al elegir «Otro», aparece la caja para escribirlo. Se crea aquí y
     no en el HTML porque solo existe en ese caso. */
  if (selDestino) {
    selDestino.addEventListener('change', function () {
      var libre = document.getElementById('destino-libre');

      if (selDestino.value !== '') {
        if (libre) { libre.remove(); }
        selDestino.name = 'destino';
        return;
      }

      if (libre) { return; }

      /* El select cede el nombre del campo a la caja de texto: si los
         dos se llamaran «destino», el servidor recibiría dos valores
         y se quedaría con el último por accidente. */
      selDestino.name = '';

      var caja = document.createElement('input');

      caja.type = 'text';
      caja.className = 'form-control mt-2';
      caja.id = 'destino-libre';
      caja.name = 'destino';
      caja.maxLength = 255;
      caja.placeholder = 'Escriba el destino del crédito';

      selDestino.parentNode.insertBefore(caja, selDestino.nextSibling);
      caja.focus();
    });
  }

  pintar();

  /* Con la navegación en vivo, la pantalla puede llegar después de
     cargar el guion: se repinta cuando eso pase. */
  document.addEventListener('cm:montar', function () {
    selProducto = document.getElementById('producto');
    selDestino  = document.getElementById('destino');

    if (!selProducto) { return; }

    bloques = Array.prototype.slice.call(
      document.querySelectorAll('.cm-bien[data-modalidad]')
    );

    pintar();
  });
})(document);
