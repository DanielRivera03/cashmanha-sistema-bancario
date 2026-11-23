/* =====================================================================
   EL TELÉFONO SE ESCRIBE 0000-0000
   =====================================================================

   El teléfono salvadoreño son ocho dígitos y se escribe con un guion
   en medio. En el sistema se guardaba como viniera: con guion, sin
   guion, con el +503 delante, con espacios. El resultado es el mismo
   problema del documento de identidad —dos clientes que en realidad
   son uno, porque nadie los encuentra buscando— y además queda feo en
   un comprobante.

   Esto pone el guion solo mientras se escribe, en TODOS los campos de
   teléfono del sistema, sin tocar ninguna vista: se engancha a
   `input[type="tel"]`, a `[name="telefono"]` y a cualquier campo con
   `data-cm-telefono`.

   EL +503 Y LOS NÚMEROS DE FUERA

   Si alguien escribe un número con código de país, no se le pelea: se
   deja el `+` y los dígitos tal cual, sin máscara. Un cliente con
   número de Estados Unidos existe, y forzarle el formato de ocho
   dígitos sería impedirle dar su teléfono. La máscara es para lo
   normal, no una regla contra lo demás.
   ===================================================================== */
(function (global, documento) {
  'use strict';

  var LARGO = 8;      // los dígitos de un teléfono salvadoreño
  var CORTE = 4;      // dónde va el guion

  /** Pone el guion donde va. Devuelve el texto ya formateado. */
  function enmascarar(valor) {
    var texto = String(valor || '');

    /* Con `+` delante es un número internacional: se limpia de
       espacios y de símbolos sueltos, pero no se le mete el guion de
       ocho dígitos, que no le corresponde. */
    if (texto.trim().charAt(0) === '+') {
      return '+' + texto.replace(/[^0-9]/g, '').slice(0, 15);
    }

    var d = texto.replace(/[^0-9]/g, '').slice(0, LARGO);

    if (d.length <= CORTE) { return d; }

    return d.slice(0, CORTE) + '-' + d.slice(CORTE);
  }

  /** ¿Está completo? Ocho dígitos, o un internacional razonable. */
  function completo(valor) {
    var texto = String(valor || '').trim();

    if (texto === '') { return true; }     // vacío es asunto del `required`

    if (texto.charAt(0) === '+') {
      return texto.replace(/[^0-9]/g, '').length >= 8;
    }

    return texto.replace(/[^0-9]/g, '').length === LARGO;
  }

  function montar(campo) {
    if (!campo || campo.dataset.cmTelefonoListo === '1') { return; }

    campo.dataset.cmTelefonoListo = '1';

    if (!campo.getAttribute('placeholder')) {
      campo.setAttribute('placeholder', '0000-0000');
    }

    campo.setAttribute('inputmode', 'tel');

    /* El tope cuenta el guion. Sin subirlo, el campo se llenaba a los
       ocho caracteres y el octavo dígito no cabía. */
    var tope = parseInt(campo.getAttribute('maxlength'), 10) || 0;

    if (tope > 0 && tope < LARGO + 1) {
      campo.setAttribute('maxlength', String(LARGO + 1));
    }

    function revisar() {
      if (completo(campo.value)) {
        campo.setCustomValidity('');
        campo.classList.remove('cm-invalido');
      } else {
        campo.setCustomValidity('El teléfono son ocho dígitos: 0000-0000.');
      }
    }

    campo.addEventListener('input', function () {
      var antes = campo.value;
      var alFinal = campo.selectionStart === antes.length;
      var nuevo = enmascarar(antes);

      if (nuevo !== antes) {
        campo.value = nuevo;

        /* Solo se recoloca el cursor si estaba al final. Si alguien
           está corrigiendo el tercer dígito, moverlo al final le
           arruina la corrección. */
        if (alFinal) {
          try { campo.setSelectionRange(nuevo.length, nuevo.length); } catch (e) {}
        }
      }

      /* Mientras se escribe no se regaña: la marca roja solo se quita
         en cuanto el dato queda bien. El aviso se deja para cuando el
         campo pierde el foco. */
      if (completo(campo.value)) { revisar(); }
    });

    campo.addEventListener('blur', revisar);

    // Lo que ya viniera escrito desde el servidor, también.
    if (campo.value !== '') { campo.value = enmascarar(campo.value); }
  }

  function montarTodo(raiz) {
    var donde = raiz || documento;

    donde.querySelectorAll(
      'input[type="tel"], input[name="telefono"], input[name*="telefono"], [data-cm-telefono]'
    ).forEach(montar);
  }

  if (documento.readyState === 'loading') {
    documento.addEventListener('DOMContentLoaded', function () { montarTodo(); });
  } else {
    montarTodo();
  }

  global.CMTelefono = {
    montar: montar,
    montarTodo: montarTodo,
    enmascarar: enmascarar,
    completo: completo
  };
})(window, document);
