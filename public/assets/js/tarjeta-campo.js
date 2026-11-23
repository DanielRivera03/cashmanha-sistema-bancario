/* =====================================================================
   EL NÚMERO DE LA TARJETA NO SE ENSEÑA MIENTRAS SE ESCRIBE
   =====================================================================

   Un campo de texto corriente deja el número completo a la vista
   durante todo el rato que dura llenar el formulario, y luego en el
   historial de autocompletado del navegador. En una pantalla de pago
   —que se usa en una oficina, en un cajero, con gente detrás— eso es
   el número entero legible por cualquiera que pase.

   Aquí se ve lo mismo que en un comprobante: puntos y los últimos
   cuatro dígitos. El número de verdad va en un campo oculto que
   acompaña al formulario, se manda y no se guarda en ninguna parte.

   El CVV no se enseña en ningún momento, ni siquiera los últimos
   dígitos: es el dato que convierte un número de tarjeta en un cobro,
   y no hay ninguna razón para que esté en pantalla.

   Además el campo sabe de qué marca es la tarjeta por los primeros
   dígitos, y con eso:

     - agrupa el número como lo trae impreso el plástico (American
       Express va 4-6-5, no de cuatro en cuatro);
     - pide tres dígitos de CVV, o cuatro si es American Express, en
       lugar de dejar un «hasta cuatro» que acepta un CVV a medias y
       lo manda al banco para que lo rechace él.

   Uso:

     CMTarjeta.proteger({
       numero: '#num-numero',     // el campo que se ve
       cvv:    '#num-cvv',
       marca:  '#num-marca'       // opcional, donde se pinta la marca
     });

   Los campos visibles NO llevan `name`: el valor sale por los ocultos
   que crea este archivo.
   ===================================================================== */
(function (global, documento) {
  'use strict';

  var PUNTO = '•';

  /* Las marcas, por los primeros dígitos.
   *
   * Es la misma tabla que usa la red para encaminar el cobro. El orden
   * importa: `prueba` se mira de arriba abajo y gana la primera. */
  var MARCAS = [
    {
      id: 'amex',
      nombre: 'American Express',
      prueba: /^3[47]/,
      largos: [15],
      grupos: [4, 6, 5],
      cvv: 4,
      cvvTexto: 'Los cuatro dígitos impresos al frente, sobre el número.'
    },
    {
      id: 'visa',
      nombre: 'Visa',
      prueba: /^4/,
      largos: [13, 16, 19],
      grupos: [4, 4, 4, 4, 3],
      cvv: 3,
      cvvTexto: 'Los tres dígitos del dorso, junto a la firma.'
    },
    {
      id: 'mastercard',
      nombre: 'Mastercard',
      prueba: /^(5[1-5]|2[2-7])/,
      largos: [16],
      grupos: [4, 4, 4, 4],
      cvv: 3,
      cvvTexto: 'Los tres dígitos del dorso, junto a la firma.'
    },
    {
      id: 'discover',
      nombre: 'Discover',
      prueba: /^(6011|64[4-9]|65)/,
      largos: [16, 19],
      grupos: [4, 4, 4, 4, 3],
      cvv: 3,
      cvvTexto: 'Los tres dígitos del dorso.'
    }
  ];

  var GENERICA = {
    id: '',
    nombre: '',
    prueba: null,
    largos: [13, 14, 15, 16, 17, 18, 19],
    grupos: [4, 4, 4, 4, 3],
    cvv: 3,
    cvvTexto: 'Los tres dígitos del dorso. Cuatro si es American Express.'
  };

  function marcaDe(digitos) {
    for (var i = 0; i < MARCAS.length; i++) {
      if (MARCAS[i].prueba.test(digitos)) { return MARCAS[i]; }
    }
    return GENERICA;
  }

  /** El dígito verificador, que es lo que descarta un número mal tecleado. */
  function luhn(d) {
    var suma = 0;
    var alterna = false;

    for (var i = d.length - 1; i >= 0; i--) {
      var n = d.charCodeAt(i) - 48;

      if (n < 0 || n > 9) { return false; }

      if (alterna) {
        n *= 2;
        if (n > 9) { n -= 9; }
      }

      suma += n;
      alterna = !alterna;
    }

    return d.length > 0 && suma % 10 === 0;
  }

  /** Puntos para todo menos los últimos cuatro. */
  function tapar(digitos) {
    if (digitos.length <= 4) { return digitos; }

    return new Array(digitos.length - 4 + 1).join(PUNTO) + digitos.slice(-4);
  }

  /** Agrupa como viene impreso en el plástico. */
  function agrupar(texto, grupos) {
    var fuera = [];
    var i = 0;

    for (var g = 0; g < grupos.length && i < texto.length; g++) {
      fuera.push(texto.substr(i, grupos[g]));
      i += grupos[g];
    }

    if (i < texto.length) { fuera.push(texto.slice(i)); }

    return fuera.join(' ');
  }

  /** El campo oculto que de verdad viaja en el formulario. */
  function oculto(visible, nombre) {
    var yaEsta = visible.form
      ? visible.form.querySelector('input[type="hidden"][name="' + nombre + '"]')
      : null;

    if (yaEsta) { return yaEsta; }

    var h = documento.createElement('input');
    h.type = 'hidden';
    h.name = nombre;
    (visible.form || visible.parentNode).appendChild(h);

    return h;
  }

  function alFinal(campo) {
    try { campo.setSelectionRange(campo.value.length, campo.value.length); }
    catch (e) { /* un input que no admite selección: da igual */ }
  }

  /* -------------------------------------------------------------------
     El campo del número
     ------------------------------------------------------------------- */

  function protegerNumero(vista, alCambiarMarca) {
    var nombre = vista.getAttribute('name') || 'numero';
    var real   = oculto(vista, nombre);

    /* El campo visible deja de mandarse: lo que viaja es el oculto.
       Y deja de autocompletarse, que es la otra forma de que el número
       acabe guardado donde no toca. */
    vista.removeAttribute('name');
    vista.setAttribute('autocomplete', 'off');
    vista.setAttribute('spellcheck', 'false');
    vista.setAttribute('aria-describedby', (vista.getAttribute('aria-describedby') || '') + ' num-marca');

    var digitos = '';
    var marca   = GENERICA;

    function pintar() {
      var nueva = marcaDe(digitos);

      if (nueva.id !== marca.id) {
        marca = nueva;
        if (alCambiarMarca) { alCambiarMarca(marca); }
      }

      var tope = Math.max.apply(null, marca.largos);

      if (digitos.length > tope) { digitos = digitos.slice(0, tope); }

      vista.value = agrupar(tapar(digitos), marca.grupos);
      real.value  = digitos;

      /* Se marca solo cuando el número ya está completo: avisar del
         dígito verificador a medio teclear sería decir «está mal»
         mientras todavía se escribe. */
      var completo = marca.largos.indexOf(digitos.length) !== -1;

      if (completo && !luhn(digitos)) {
        vista.classList.add('cm-invalido');
        vista.setCustomValidity('Ese número de tarjeta no es válido. Revise los dígitos.');
      } else {
        vista.classList.remove('cm-invalido');
        vista.setCustomValidity('');
      }

      alFinal(vista);
    }

    vista.addEventListener('keydown', function (ev) {
      // Copiar, pegar y seleccionar siguen funcionando.
      if (ev.ctrlKey || ev.metaKey || ev.altKey) { return; }

      if (ev.key === 'Tab' || ev.key === 'Enter' || ev.key === 'Escape') { return; }

      ev.preventDefault();

      if (ev.key === 'Backspace') {
        digitos = digitos.slice(0, -1);
        pintar();
        return;
      }

      if (/^[0-9]$/.test(ev.key)) {
        digitos += ev.key;
        pintar();
      }
    });

    /* Los teclados de móvil no siempre mandan la tecla en `keydown`
       —llega como «Unidentified»—, así que el valor se reconstruye
       también desde lo que quedó escrito. Los puntos de delante son
       dígitos que ya estaban; lo que se ve como número es la cola más
       lo que se acaba de teclear. */
    vista.addEventListener('input', function () {
      var v       = vista.value.replace(/\s+/g, '');
      var tapados = (v.match(/•/g) || []).length;
      var visibles = v.replace(/[^0-9]/g, '');

      digitos = digitos.slice(0, tapados) + visibles;
      pintar();
    });

    vista.addEventListener('paste', function (ev) {
      ev.preventDefault();

      var texto = (ev.clipboardData || global.clipboardData).getData('text') || '';

      digitos = (digitos + texto.replace(/[^0-9]/g, '')).slice(0, 19);
      pintar();
    });

    vista.addEventListener('drop', function (ev) { ev.preventDefault(); });
    vista.addEventListener('click', function () { alFinal(vista); });
    vista.addEventListener('focus', function () { setTimeout(function () { alFinal(vista); }, 0); });

    /* Si el formulario se manda y se vuelve, nada queda escrito. */
    if (vista.form) {
      vista.form.addEventListener('reset', function () {
        setTimeout(function () { digitos = ''; pintar(); }, 0);
      });
    }

    return {
      limpiar: function () { digitos = ''; pintar(); },
      marca: function () { return marca; }
    };
  }

  /* -------------------------------------------------------------------
     El CVV
     ------------------------------------------------------------------- */

  function protegerCvv(vista) {
    /* `password` y no un enmascarado propio: el navegador ya sabe
       taparlo, no lo copia al historial y lo respeta el lector de
       pantalla. Aquí no hace falta ver ningún dígito. */
    vista.type = 'password';
    vista.setAttribute('inputmode', 'numeric');
    vista.setAttribute('autocomplete', 'off');
    vista.setAttribute('spellcheck', 'false');

    vista.addEventListener('input', function () {
      var tope = parseInt(vista.getAttribute('maxlength'), 10) || 4;
      var d = vista.value.replace(/[^0-9]/g, '').slice(0, tope);

      if (d !== vista.value) { vista.value = d; }
    });

    vista.addEventListener('paste', function (ev) { ev.preventDefault(); });
  }

  /* -------------------------------------------------------------------
     Montaje
     ------------------------------------------------------------------- */

  function proteger(opciones) {
    opciones = opciones || {};

    var vistaNum = typeof opciones.numero === 'string'
      ? documento.querySelector(opciones.numero)
      : opciones.numero;

    if (!vistaNum) { return null; }

    var vistaCvv = typeof opciones.cvv === 'string'
      ? documento.querySelector(opciones.cvv)
      : opciones.cvv;

    var sello = typeof opciones.marca === 'string'
      ? documento.querySelector(opciones.marca)
      : opciones.marca;

    var pista = typeof opciones.pistaCvv === 'string'
      ? documento.querySelector(opciones.pistaCvv)
      : opciones.pistaCvv;

    if (vistaCvv) { protegerCvv(vistaCvv); }

    var campo = protegerNumero(vistaNum, function (marca) {
      if (sello) {
        sello.textContent = marca.nombre;
        sello.className = 'tda-marca' + (marca.id ? ' tda-marca--' + marca.id : '');
        sello.hidden = marca.id === '';
      }

      if (vistaCvv) {
        /* El tope cambia con la marca: tres dígitos, o cuatro en
           American Express. Lo tecleado de más se recorta en el acto,
           que es mejor que mandarlo y que lo rechace el banco. */
        vistaCvv.setAttribute('maxlength', String(marca.cvv));
        vistaCvv.setAttribute('placeholder', new Array(marca.cvv + 1).join('0'));
        vistaCvv.value = vistaCvv.value.slice(0, marca.cvv);

        var etiqueta = marca.cvv === 4 ? 'CID' : 'CVV';
        var rotulo = vistaCvv.form
          ? vistaCvv.form.querySelector('label[for="' + vistaCvv.id + '"]')
          : null;

        if (rotulo) { rotulo.textContent = etiqueta; }
        vistaCvv.setAttribute('aria-label', etiqueta + ' de la tarjeta');
      }

      if (pista) { pista.textContent = marca.cvvTexto; }
    });

    return campo;
  }

  global.CMTarjeta = {
    proteger: proteger,
    marcaDe: marcaDe,
    luhn: luhn
  };
})(window, document);
