/* =====================================================================
   El control de archivo
   =====================================================================

   El `<input type="file">` del navegador pone un botón gris que dice
   «Elegir archivo» y, al lado, «No se eligió ningún archivo». Ese botón
   no se puede estilar: lo dibuja el sistema operativo, así que ignora
   el tema, ignora la tipografía y en modo noche aparece un rectángulo
   claro en medio de un panel oscuro. Tampoco dice el tamaño del
   archivo, que es justo lo que hace falta saber ANTES de subir cuando
   hay un límite de 5 MB.

   Aquí el input de verdad sigue existiendo —es el que envía el
   formulario, y el que entiende el servidor—, pero se esconde y por
   encima va una zona que sí se puede dibujar: acepta arrastrar y
   soltar, enseña el archivo elegido con su peso, avisa si se pasa del
   límite o no es del tipo admitido, y deja quitarlo.

   El control NO valida por seguridad. Eso lo hace el servidor, que es
   el único que puede: aquí se comprueba para no hacerle perder el
   tiempo a quien eligió un archivo de 40 MB, no para decidir si entra.

   Uso:
     <div class="cm-archivo" data-cm-archivo>
       <input type="file" name="archivo" accept="..." data-max-mb="5">
     </div>
   ===================================================================== */
(function (global, documento) {
  'use strict';

  /** El peso, como lo diría una persona. */
  function peso(bytes) {
    if (bytes < 1024) { return bytes + ' B'; }
    if (bytes < 1024 * 1024) { return (bytes / 1024).toFixed(0) + ' KB'; }
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  /** Un icono según lo que sea: un PDF y una foto no son lo mismo. */
  function icono(tipo, nombre) {
    var esImagen = /^image\//.test(tipo || '')
                || /\.(jpe?g|png|webp|gif|bmp)$/i.test(nombre || '');

    var trazos = esImagen
      ? '<rect x="3" y="3" width="18" height="18" rx="2"/>'
        + '<circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>'
      : '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>'
        + '<path d="M14 2v6h6"/>';

    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none"'
      + ' stroke="currentColor" stroke-width="1.7" stroke-linecap="round"'
      + ' stroke-linejoin="round">' + trazos + '</svg>';
  }

  function escapar(s) {
    var d = documento.createElement('div');
    d.textContent = String(s == null ? '' : s);
    return d.innerHTML;
  }

  /**
   * ¿El archivo encaja con lo que pide el `accept`?
   *
   * Se admiten las dos formas que acepta el atributo: tipos MIME
   * («image/png», «image/*») y extensiones («.pdf»).
   */
  function admitido(archivo, accept) {
    if (!accept) { return true; }

    var nombre = (archivo.name || '').toLowerCase();
    var tipo   = (archivo.type || '').toLowerCase();

    return accept.split(',').some(function (regla) {
      regla = regla.trim().toLowerCase();

      if (regla === '') { return false; }
      if (regla.charAt(0) === '.') { return nombre.slice(-regla.length) === regla; }

      if (regla.slice(-2) === '/*') {
        return tipo.indexOf(regla.slice(0, -1)) === 0;
      }

      return tipo === regla;
    });
  }

  function montar(caja) {
    if (caja.getAttribute('data-cm-archivo-listo') === '1') { return; }

    var input = caja.querySelector('input[type="file"]');
    if (!input) { return; }

    caja.setAttribute('data-cm-archivo-listo', '1');

    var maxMb  = parseFloat(input.getAttribute('data-max-mb') || '0');
    var accept = input.getAttribute('accept') || '';

    var pista = caja.getAttribute('data-pista')
      || (accept ? accept.replace(/application\/|image\//g, '').toUpperCase() : '');

    var zona = documento.createElement('div');
    zona.className = 'cm-archivo__zona';
    zona.setAttribute('role', 'button');
    zona.setAttribute('tabindex', '0');

    var vacia =
        '<div class="cm-archivo__icono">'
      +   '<svg viewBox="0 0 24 24" width="22" height="22" fill="none"'
      +   ' stroke="currentColor" stroke-width="1.7" stroke-linecap="round"'
      +   ' stroke-linejoin="round">'
      +   '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>'
      +   '<path d="M7 10l5-5 5 5"/><path d="M12 5v12"/></svg>'
      + '</div>'
      + '<div class="cm-archivo__texto">'
      +   '<strong>Arrastre un archivo o pulse para elegirlo</strong>'
      +   (pista || maxMb
          ? '<small>' + escapar(pista) + (pista && maxMb ? ' · ' : '')
            + (maxMb ? 'hasta ' + maxMb + ' MB' : '') + '</small>'
          : '')
      + '</div>';

    zona.innerHTML = vacia;
    caja.appendChild(zona);

    function vaciar() {
      zona.innerHTML = vacia;
      zona.classList.remove('cm-archivo__zona--lleno', 'cm-archivo__zona--mal');
      caja.classList.remove('cm-archivo--mal');
    }

    function fallo(texto) {
      zona.innerHTML =
          '<div class="cm-archivo__icono cm-archivo__icono--mal">'
        +   '<svg viewBox="0 0 24 24" width="22" height="22" fill="none"'
        +   ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round">'
        +   '<circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16.5v.01"/></svg>'
        + '</div>'
        + '<div class="cm-archivo__texto">'
        +   '<strong>' + escapar(texto) + '</strong>'
        +   '<small>Pulse para elegir otro.</small>'
        + '</div>';

      zona.classList.add('cm-archivo__zona--mal');
      zona.classList.remove('cm-archivo__zona--lleno');
      caja.classList.add('cm-archivo--mal');

      // El input se limpia: si no, el formulario enviaría el archivo
      // que acabamos de rechazar en pantalla.
      input.value = '';
    }

    function pintar(archivo) {
      if (!admitido(archivo, accept)) {
        fallo('Ese tipo de archivo no se admite aquí.');
        return;
      }

      if (maxMb > 0 && archivo.size > maxMb * 1024 * 1024) {
        fallo('El archivo pesa ' + peso(archivo.size) + ' y el máximo es ' + maxMb + ' MB.');
        return;
      }

      zona.innerHTML =
          '<div class="cm-archivo__icono cm-archivo__icono--ok">'
        +   icono(archivo.type, archivo.name)
        + '</div>'
        + '<div class="cm-archivo__texto">'
        +   '<strong class="cm-archivo__nombre">' + escapar(archivo.name) + '</strong>'
        +   '<small>' + peso(archivo.size) + ' · listo para subir</small>'
        + '</div>'
        + '<button type="button" class="cm-archivo__quitar" aria-label="Quitar el archivo">'
        +   '<svg viewBox="0 0 24 24" width="15" height="15" fill="none"'
        +   ' stroke="currentColor" stroke-width="2" stroke-linecap="round">'
        +   '<path d="M18 6L6 18M6 6l12 12"/></svg>'
        + '</button>';

      zona.classList.add('cm-archivo__zona--lleno');
      zona.classList.remove('cm-archivo__zona--mal');
      caja.classList.remove('cm-archivo--mal');
    }

    // --- Elegir ------------------------------------------------------

    zona.addEventListener('click', function (ev) {
      if (ev.target.closest('.cm-archivo__quitar')) {
        ev.stopPropagation();
        input.value = '';
        vaciar();
        return;
      }

      input.click();
    });

    zona.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' || ev.key === ' ') {
        ev.preventDefault();
        input.click();
      }
    });

    input.addEventListener('change', function () {
      if (input.files && input.files.length) { pintar(input.files[0]); }
      else { vaciar(); }
    });

    // --- Arrastrar y soltar ------------------------------------------

    /* Hay que cancelar los cuatro: si no se cancela `dragover`, el
       navegador no deja soltar, y si no se cancela `drop` en el
       documento, soltar fuera de la zona ABRE el archivo y se pierde
       lo que el usuario estuviera rellenando. */
    ['dragenter', 'dragover'].forEach(function (e) {
      zona.addEventListener(e, function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        zona.classList.add('cm-archivo__zona--encima');
      });
    });

    ['dragleave', 'drop'].forEach(function (e) {
      zona.addEventListener(e, function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        zona.classList.remove('cm-archivo__zona--encima');
      });
    });

    zona.addEventListener('drop', function (ev) {
      var fs = ev.dataTransfer && ev.dataTransfer.files;
      if (!fs || !fs.length) { return; }

      /* `DataTransfer` permite poner los archivos en el input, que es
         lo que hace que el formulario los envíe. Sin esto la zona
         enseñaría el archivo y se subiría nada. */
      try {
        var dt = new DataTransfer();
        dt.items.add(fs[0]);
        input.files = dt.files;
      } catch (e) {
        // Navegador que no lo admite: no se puede pasar el archivo al
        // input, así que mejor no fingir que sí.
        fallo('Su navegador no admite arrastrar aquí. Pulse para elegirlo.');
        return;
      }

      pintar(fs[0]);
    });
  }

  function barrer(raiz) {
    (raiz || documento).querySelectorAll('[data-cm-archivo]').forEach(montar);
  }

  if (documento.readyState === 'loading') {
    documento.addEventListener('DOMContentLoaded', function () { barrer(); });
  } else {
    barrer();
  }

  // Para lo que se pinte después (un modal, una respuesta por AJAX).
  global.CMArchivo = { montar: barrer };

  /* Soltar un archivo FUERA de una zona no debe navegar a él. Es el
     accidente más común al arrastrar y cuesta un formulario entero. */
  ['dragover', 'drop'].forEach(function (e) {
    documento.addEventListener(e, function (ev) {
      if (!ev.target.closest || !ev.target.closest('.cm-archivo')) {
        ev.preventDefault();
      }
    });
  });
})(window, document);
