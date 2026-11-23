/* =====================================================================
   CashMan H.A. — validación de formularios
   ---------------------------------------------------------------------
   Sustituye los globos nativos del navegador ("Rellena este campo") por
   mensajes propios, por tres razones:

   1. Los nativos no se pueden estilar: aparecen con la tipografía y el
      color del navegador, distintos en cada uno, y en un sistema
      bancario desentonan con todo lo demás.

   2. Dicen poco. "Rellena este campo" no explica qué se espera; aquí el
      mensaje depende de la restricción concreta y del tipo de dato.

   3. Solo señalan el primer campo y desaparecen al hacer clic. Esto
      marca todos los que fallan a la vez y los deja marcados hasta que
      se corrigen.

   Funciona sobre cualquier formulario sin tocarlo: se le pone
   `novalidate` para callar al navegador y se valida aquí con la misma
   información (required, type, min, max, minlength, pattern), de modo
   que no hay que repetir las reglas en dos sitios.

   Esto NO sustituye la validación del servidor, que es la que cuenta.
   Es solo para avisar antes de enviar.
   ===================================================================== */
(function (global, documento) {
  'use strict';

  /* ------------------------------------------------------------------
     Mensajes
     ------------------------------------------------------------------ */

  var TIPOS = {
    email:  'Escriba un correo electrónico válido, como nombre@dominio.com.',
    url:    'Escriba una dirección web completa, empezando por https://',
    tel:    'Escriba un número de teléfono válido.',
    number: 'Escriba un número.',
    date:   'Escriba una fecha válida.'
  };

  /** Nombre legible del campo, para que el mensaje diga de cuál habla. */
  function etiquetaDe(campo) {
    var id = campo.id;

    if (id) {
      var lab = documento.querySelector('label[for="' + CSS.escape(id) + '"]');
      if (lab) { return lab.textContent.trim().replace(/[:*]\s*$/, ''); }
    }

    var padre = campo.closest('.form-group, .form-row > div');
    if (padre) {
      var l = padre.querySelector('label');
      if (l) { return l.textContent.trim().replace(/[:*]\s*$/, ''); }
    }

    return campo.getAttribute('aria-label') || campo.placeholder || 'Este campo';
  }

  /** Qué está mal con este campo, o null si está bien. */
  function problema(campo) {
    var v = campo.validity;
    if (v.valid) { return null; }

    var nombre = etiquetaDe(campo);
    var esSelect = campo.tagName === 'SELECT';

    if (v.valueMissing) {
      if (campo.type === 'checkbox') { return 'Marca esta casilla para continuar.'; }
      if (esSelect) { return 'Elija una opción de ' + nombre.toLowerCase() + '.'; }
      return 'Falta ' + nombre.toLowerCase() + '.';
    }

    if (v.typeMismatch) {
      return TIPOS[campo.type] || 'El formato de ' + nombre.toLowerCase() + ' no es válido.';
    }

    if (v.tooShort) {
      var faltan = campo.minLength - campo.value.length;
      return nombre + ' necesita al menos ' + campo.minLength + ' caracteres'
           + ' (faltan ' + faltan + ').';
    }

    if (v.tooLong) {
      return nombre + ' no puede pasar de ' + campo.maxLength + ' caracteres.';
    }

    if (v.rangeUnderflow) { return nombre + ' no puede ser menor que ' + campo.min + '.'; }
    if (v.rangeOverflow)  { return nombre + ' no puede ser mayor que ' + campo.max + '.'; }

    if (v.stepMismatch) {
      return nombre + ' debe ir en incrementos de ' + (campo.step || 1) + '.';
    }

    if (v.patternMismatch) {
      // Si el campo trae su propia explicación, se usa: sabe mejor que
      // nosotros qué formato espera.
      return campo.getAttribute('data-cm-formato')
          || campo.title
          || 'El formato de ' + nombre.toLowerCase() + ' no es el esperado.';
    }

    return 'Revise ' + nombre.toLowerCase() + '.';
  }

  /* ------------------------------------------------------------------
     Pintado
     ------------------------------------------------------------------ */

  function contenedorDe(campo) {
    return campo.closest('.form-group')
        || campo.closest('.input-group')
        || campo.parentNode;
  }

  function marcar(campo, mensaje) {
    campo.classList.add('cm-invalido');
    campo.setAttribute('aria-invalid', 'true');

    var caja = contenedorDe(campo);
    var previo = caja.querySelector(':scope > .cm-error');

    if (!previo) {
      previo = documento.createElement('span');
      previo.className = 'cm-error';
      caja.appendChild(previo);
    }

    previo.textContent = mensaje;

    if (!campo.id) { return; }
    previo.id = 'err-' + campo.id;
    campo.setAttribute('aria-describedby', previo.id);
  }

  function limpiar(campo) {
    campo.classList.remove('cm-invalido');
    campo.removeAttribute('aria-invalid');

    var caja = contenedorDe(campo);
    var err = caja.querySelector(':scope > .cm-error');
    if (err) { err.remove(); }
  }

  function limpiarTodo(form) {
    form.querySelectorAll('.cm-invalido').forEach(limpiar);
  }

  /* ------------------------------------------------------------------
     Validación
     ------------------------------------------------------------------ */

  /** Campos que de verdad hay que mirar. */
  function camposDe(form) {
    return Array.prototype.filter.call(
      form.querySelectorAll('input, select, textarea'),
      function (c) {
        return !c.disabled
            && c.type !== 'hidden'
            && c.type !== 'submit'
            && c.type !== 'button'
            // Un campo oculto por la interfaz no se valida: por ejemplo
            // el límite de crédito cuando la tarjeta es de débito.
            && c.offsetParent !== null;
      }
    );
  }

  function validar(form) {
    limpiarTodo(form);

    var fallos = [];

    camposDe(form).forEach(function (campo) {
      var p = problema(campo);
      if (p !== null) {
        marcar(campo, p);
        fallos.push(campo);
      }
    });

    return fallos;
  }

  /* ------------------------------------------------------------------
     Enganche
     ------------------------------------------------------------------ */

  function preparar(form) {
    if (form.dataset.cmValidado === '1') { return; }
    form.dataset.cmValidado = '1';

    // Con novalidate el navegador se calla y nos deja a nosotros.
    form.setAttribute('novalidate', 'novalidate');

    // En fase de captura, para correr antes que cualquier otro
    // manejador de submit que la vista haya puesto.
    form.addEventListener('submit', function (ev) {
      var fallos = validar(form);
      if (fallos.length === 0) { return; }

      ev.preventDefault();
      ev.stopImmediatePropagation();

      var primero = fallos[0];
      primero.focus({ preventScroll: true });
      primero.scrollIntoView({ behavior: 'smooth', block: 'center' });

      if (global.CMAviso) {
        global.CMAviso.alerta(
          fallos.length === 1 ? 'Falta un dato' : 'Faltan ' + fallos.length + ' datos',
          fallos.length === 1
            ? problema(primero) || 'Revise el campo marcado.'
            : 'Revise los campos marcados en rojo antes de continuar.'
        );
      }
    }, true);

    // Al corregir, el aviso desaparece sin esperar a reenviar.
    form.addEventListener('input', function (ev) {
      var c = ev.target;
      if (c.classList && c.classList.contains('cm-invalido') && c.validity.valid) {
        limpiar(c);
      }
    });

    // Al salir de un campo ya marcado se revalida, para que el mensaje
    // se actualice si el error cambió de tipo.
    form.addEventListener('blur', function (ev) {
      var c = ev.target;
      if (!c.classList || !c.classList.contains('cm-invalido')) { return; }
      var p = problema(c);
      if (p === null) { limpiar(c); } else { marcar(c, p); }
    }, true);
  }

  function prepararTodos(raiz) {
    (raiz || documento).querySelectorAll('form').forEach(preparar);
  }

  if (documento.readyState === 'loading') {
    documento.addEventListener('DOMContentLoaded', function () { prepararTodos(); });
  } else {
    prepararTodos();
  }

  /* Los formularios que se añaden después —un modal, una fila nueva—
     también quedan cubiertos. */
  if (global.MutationObserver) {
    new MutationObserver(function (cambios) {
      cambios.forEach(function (c) {
        c.addedNodes.forEach(function (n) {
          if (n.nodeType !== 1) { return; }
          if (n.tagName === 'FORM') { preparar(n); }
          else if (n.querySelectorAll) { prepararTodos(n); }
        });
      });
    }).observe(documento.body, { childList: true, subtree: true });
  }

  global.CMValidacion = {
    validar: validar,
    limpiar: limpiarTodo,
    preparar: preparar
  };

})(window, document);
