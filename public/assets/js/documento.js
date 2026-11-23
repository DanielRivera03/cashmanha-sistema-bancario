/* =====================================================================
   EL DOCUMENTO DE IDENTIDAD, CON SU TIPO Y SU MÁSCARA
   =====================================================================

   Antes el formulario pedía «documento de identidad» y dejaba un campo
   de texto libre. Ahí entraba un DUI con guion, el mismo DUI sin guion
   y un NIT, los tres indistinguibles: la búsqueda de ventanilla fallaba
   según cómo lo hubiera tecleado quien registró al cliente, y el mismo
   cliente podía quedar dos veces.

   Aquí se pregunta primero QUÉ documento es, y con eso:

     DUI        01234567-8          el guion se pone solo
     NIT        0614-280598-102-5   los tres guiones se ponen solos
     Residente  sin máscara: el carné del extranjero no tiene un
                formato fijo, cambia con el país y con el año

   Y se dice en voz alta lo que mucha gente no sabe: desde 2022 el NIT
   de la persona natural ESTÁ HOMOLOGADO con su DUI. No hay que
   tramitar un NIT aparte, el número de DUI hace de NIT. Lo decimos
   como nota, no como regla: la persona jurídica sí tiene NIT propio,
   y hay clientes que todavía arrastran el suyo de antes.

   Uso en la vista:

     <select name="tipo_documento" id="tipo-doc"> … </select>
     <input  name="documento"      id="doc">
     <small  id="doc-nota"></small>

     CMDocumento.montar({
       tipo: '#tipo-doc', valor: '#doc', nota: '#doc-nota'
     });

   O, sin tocar nada, poniendo `data-cm-documento` en el contenedor.
   ===================================================================== */
(function (global, documento) {
  'use strict';

  var TIPOS = {
    dui: {
      nombre: 'DUI',
      /* Ocho dígitos y el verificador. */
      grupos: [8, 1],
      digitos: 9,
      pista: '01234567-8',
      nota: 'Ocho dígitos y el verificador. Si es persona natural, este mismo '
          + 'número es su NIT: están homologados desde 2022.'
    },
    nit: {
      nombre: 'NIT',
      grupos: [4, 6, 3, 1],
      digitos: 14,
      pista: '0614-280598-102-5',
      nota: 'Catorce dígitos. Las personas naturales ya no necesitan NIT aparte: '
          + 'desde 2022 su DUI hace de NIT. Use NIT si es una empresa, o si tiene '
          + 'uno de los de antes.'
    },
    residente: {
      nombre: 'Carné de residente',
      grupos: null,          // sin formato fijo: no se le pone máscara
      digitos: 20,
      pista: 'Como aparece en el carné',
      nota: 'Escríbalo como aparece en el carné, sin espacios.'
    }
  };

  /** Pone los guiones donde van, según el tipo. */
  function enmascarar(tipo, valor) {
    var t = TIPOS[tipo];

    if (!t) { return valor; }

    if (t.grupos === null) {
      /* Sin máscara, pero sin espacios ni símbolos: lo que se guarda
         tiene que poderse comparar con lo que escriba el siguiente. */
      return valor.replace(/[^A-Za-z0-9-]/g, '').toUpperCase().slice(0, t.digitos);
    }

    var d = valor.replace(/\D+/g, '').slice(0, t.digitos);
    var fuera = [];
    var i = 0;

    for (var g = 0; g < t.grupos.length && i < d.length; g++) {
      fuera.push(d.substr(i, t.grupos[g]));
      i += t.grupos[g];
    }

    return fuera.join('-');
  }

  /** ¿Está completo? Sirve para avisar sin esperar al envío. */
  function completo(tipo, valor) {
    var t = TIPOS[tipo];

    if (!t) { return false; }

    if (t.grupos === null) {
      return valor.replace(/[^A-Za-z0-9]/g, '').length >= 5;
    }

    return valor.replace(/\D+/g, '').length === t.digitos;
  }

  function montar(opciones) {
    opciones = opciones || {};

    var sel = typeof opciones.tipo === 'string'
      ? documento.querySelector(opciones.tipo) : opciones.tipo;

    var campo = typeof opciones.valor === 'string'
      ? documento.querySelector(opciones.valor) : opciones.valor;

    if (!campo) { return null; }

    var nota = typeof opciones.nota === 'string'
      ? documento.querySelector(opciones.nota) : opciones.nota;

    function tipoActual() {
      return sel && TIPOS[sel.value] ? sel.value : 'dui';
    }

    function alTipo() {
      var t = TIPOS[tipoActual()];

      campo.setAttribute('placeholder', t.pista);
      campo.setAttribute('inputmode', t.grupos === null ? 'text' : 'numeric');
      campo.setAttribute('aria-label', 'Número de ' + t.nombre);

      /* El tope cuenta los guiones que pone la máscara. */
      var guiones = t.grupos === null ? 0 : t.grupos.length - 1;
      campo.setAttribute('maxlength', String(t.digitos + guiones));

      if (nota) { nota.textContent = t.nota; }

      // Lo que ya estuviera escrito se vuelve a formatear con el tipo nuevo.
      if (campo.value !== '') {
        campo.value = enmascarar(tipoActual(), campo.value);
      }

      revisar();
    }

    function revisar() {
      if (campo.value === '') {
        campo.setCustomValidity('');
        campo.classList.remove('cm-invalido');
        return;
      }

      if (completo(tipoActual(), campo.value)) {
        campo.setCustomValidity('');
        campo.classList.remove('cm-invalido');
      } else {
        campo.setCustomValidity(
          'El ' + TIPOS[tipoActual()].nombre + ' va así: ' + TIPOS[tipoActual()].pista + '.'
        );
      }
    }

    campo.addEventListener('input', function () {
      var antes = campo.value;
      var alFinal = campo.selectionStart === antes.length;
      var nuevo = enmascarar(tipoActual(), antes);

      if (nuevo !== antes) {
        campo.value = nuevo;
        if (alFinal) {
          try { campo.setSelectionRange(nuevo.length, nuevo.length); } catch (e) {}
        }
      }

      /* Mientras se escribe no se regaña: solo se quita la marca roja
         en cuanto el dato queda bien. El aviso de «va así» se deja para
         cuando el campo pierde el foco o se manda el formulario. */
      if (completo(tipoActual(), campo.value)) { revisar(); }
    });

    campo.addEventListener('blur', revisar);

    if (sel) {
      sel.addEventListener('change', alTipo);
    }

    alTipo();

    return { revisar: revisar, tipo: tipoActual };
  }

  /**
   * Monta solo todos los campos marcados.
   *
   * Se busca por el campo del NÚMERO y desde ahí se sube: el selector
   * y la nota pueden estar en el mismo contenedor —si lo hay— o
   * sueltos en el formulario, que es lo que pasa en una rejilla de
   * columnas, donde cada campo va en su propia celda y no se pueden
   * envolver los dos sin romper la fila.
   */
  function montarTodo(raiz) {
    (raiz || documento).querySelectorAll('[data-cm-documento-valor]').forEach(function (campo) {
      var ambito = (campo.closest && campo.closest('[data-cm-documento]'))
                || campo.form
                || documento;

      montar({
        tipo:  ambito.querySelector('[data-cm-documento-tipo]'),
        valor: campo,
        nota:  ambito.querySelector('[data-cm-documento-nota]')
      });
    });
  }

  if (documento.readyState === 'loading') {
    documento.addEventListener('DOMContentLoaded', function () { montarTodo(); });
  } else {
    montarTodo();
  }

  global.CMDocumento = {
    montar: montar,
    montarTodo: montarTodo,
    enmascarar: enmascarar,
    completo: completo,
    tipos: TIPOS
  };
})(window, document);
