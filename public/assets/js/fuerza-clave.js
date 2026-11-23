/* =====================================================================
   EL MEDIDOR DE FUERZA DE LA CONTRASEÑA
   =====================================================================

   Sin esto, la política de contraseñas solo se manifestaba al enviar
   el formulario: se escribía una clave, se apretaba el botón y
   volvían cinco renglones diciendo todo lo que le faltaba. Eso es
   adivinar a ciegas, y lo que la gente hace entonces es poner lo
   mínimo que el sistema deje pasar.

   Aquí se ve mientras se escribe: qué falta, qué ya cumple y cuánto
   aguantaría la contraseña si alguien la atacara.

   CÓMO SE MIDE, Y POR QUÉ ASÍ

   No se cuentan «tipos de carácter», que es la medida de toda la
   vida y es mala: dice que `P@ssw0rd!` es excelente —tiene de los
   cuatro tipos— y que `caballo correcto batería grapa` es pobre,
   cuando en la realidad la primera está en cualquier diccionario de
   ataque y la segunda no la adivina nadie.

   Se estima la ENTROPÍA: cuántos intentos habría que hacer para dar
   con ella por fuerza bruta. Y antes se castiga lo que un atacante
   prueba primero —palabras comunes, repeticiones, secuencias de
   teclado, fechas— porque una contraseña larga hecha de eso no vale
   lo que su largo sugiere.

   Esto es una ESTIMACIÓN y se dice como tal. La comprobación de
   verdad la hace el servidor, que es el que manda; esto solo ayuda a
   llegar con algo decente.

   Uso: se engancha solo a `input[type=password][autocomplete=new-password]`
   y a cualquier campo con `data-cm-fuerza`.
   ===================================================================== */
(function (global, documento) {
  'use strict';

  /* Lo que un atacante prueba antes que nada. No es un diccionario:
     es la lista corta de lo que aparece en todas las filtraciones. */
  var COMUNES = [
    'password', 'contrasena', 'contraseña', 'qwerty', 'asdf', 'zxcv',
    '123456', '1234567890', 'admin', 'administrador', 'usuario', 'cliente',
    'banco', 'cashman', 'cashmanha', 'bienvenido', 'iloveyou', 'elsalvador',
    'salvador', 'master', 'letmein', 'welcome', 'abc123', 'monkey', 'dragon'
  ];

  var NIVELES = [
    { hasta: 28,  nombre: 'Muy débil',  clase: 'muy-debil' },
    { hasta: 40,  nombre: 'Débil',      clase: 'debil' },
    { hasta: 56,  nombre: 'Aceptable',  clase: 'aceptable' },
    { hasta: 72,  nombre: 'Buena',      clase: 'buena' },
    { hasta: 1e9, nombre: 'Excelente',  clase: 'excelente' }
  ];

  /* Las reglas que el servidor exige. Se enseñan para que no haya que
     adivinarlas: son las mismas de `ServicioPassword::validarPolitica`. */
  var REGLAS = [
    { id: 'largo',     texto: 'Al menos 12 caracteres', prueba: function (c) { return c.length >= 12; } },
    { id: 'minuscula', texto: 'Una minúscula',          prueba: function (c) { return /[a-záéíóúñ]/.test(c); } },
    { id: 'mayuscula', texto: 'Una mayúscula',          prueba: function (c) { return /[A-ZÁÉÍÓÚÑ]/.test(c); } },
    { id: 'numero',    texto: 'Un número',              prueba: function (c) { return /[0-9]/.test(c); } },
    { id: 'simbolo',   texto: 'Un símbolo',             prueba: function (c) { return /[^A-Za-z0-9]/.test(c); } }
  ];

  /** El tamaño del alfabeto que habría que recorrer a ciegas. */
  function alfabeto(clave) {
    var n = 0;

    if (/[a-z]/.test(clave)) { n += 26; }
    if (/[A-Z]/.test(clave)) { n += 26; }
    if (/[0-9]/.test(clave)) { n += 10; }
    if (/[^A-Za-z0-9]/.test(clave)) { n += 33; }
    if (/[áéíóúñÁÉÍÓÚÑ]/.test(clave)) { n += 12; }

    return n || 1;
  }

  /**
   * Lo que hay que descontar por ser previsible.
   *
   * Devuelve la fracción de la contraseña que NO cuenta: una clave de
   * veinte caracteres que son «password» repetido vale lo que vale
   * «password», no lo que valen veinte caracteres.
   */
  /**
   * Deshace el disfraz de los números por letras.
   *
   * `P@ssw0rd!` no contiene «password» mirando letra por letra, pero
   * es la misma palabra y cualquier herramienta de ataque la prueba
   * igual: las reglas de sustitución son lo primero que aplican. Sin
   * esto, la contraseña más famosa del mundo salía «Buena».
   */
  function sinDisfraz(texto) {
    return texto
      .replace(/[@4]/g, 'a')
      .replace(/[0]/g, 'o')
      .replace(/[1!|]/g, 'i')
      .replace(/[3]/g, 'e')
      .replace(/[5$]/g, 's')
      .replace(/[7]/g, 't')
      .replace(/[8]/g, 'b')
      .replace(/[9]/g, 'g');
  }

  function castigo(clave) {
    var baja = clave.toLowerCase();
    var pelada = sinDisfraz(baja);
    var fuera = 0;

    // Palabras que están en toda lista de ataque, con disfraz o sin él.
    for (var i = 0; i < COMUNES.length; i++) {
      if (baja.indexOf(COMUNES[i]) !== -1 || pelada.indexOf(COMUNES[i]) !== -1) {
        fuera += COMUNES[i].length * 0.9;
      }
    }

    // Un carácter repetido: «aaaa» no son cuatro decisiones, es una.
    var rep = baja.match(/(.)\1{2,}/g) || [];
    rep.forEach(function (r) { fuera += r.length - 1; });

    /* Secuencias: del abecedario, de los números y de la fila del
       teclado. Son lo segundo que prueba cualquier ataque. */
    var seq = 'abcdefghijklmnopqrstuvwxyz0123456789qwertyuiopasdfghjklzxcvbnm';

    for (var j = 0; j + 3 <= baja.length; j++) {
      var trozo = baja.substr(j, 3);
      if (seq.indexOf(trozo) !== -1) { fuera += 2; }
    }

    // Un año de cuatro cifras, que casi siempre es una fecha personal.
    if (/(19|20)\d{2}/.test(baja)) { fuera += 3; }

    return Math.min(fuera, clave.length * 0.85);
  }

  /** Bits de entropía, por lo bajo. */
  function bits(clave) {
    if (!clave) { return 0; }

    var util = Math.max(1, clave.length - castigo(clave));

    return util * (Math.log(alfabeto(clave)) / Math.log(2));
  }

  function nivelDe(b) {
    for (var i = 0; i < NIVELES.length; i++) {
      if (b < NIVELES[i].hasta) { return NIVELES[i]; }
    }
    return NIVELES[NIVELES.length - 1];
  }

  /**
   * Cuánto aguantaría, dicho en tiempo.
   *
   * Se supone un atacante que roba la base y prueba fuera de línea.
   * Argon2id está calibrado aquí para que cada intento cueste, lo que
   * hoy pondría a un equipo dedicado en el orden de diez mil intentos
   * por segundo.
   *
   * Aquí se cuenta con cien mil, diez veces más. No es un error: una
   * contraseña vive meses, el equipo del atacante mejora en ese
   * tiempo, y lo barato es quedarse corto en la promesa. Decirle a
   * alguien que su clave «aguanta siglos» cuando apenas pasa el
   * mínimo es lo que hace que se quede con ella.
   */
  function aguanta(b) {
    if (b <= 0) { return ''; }

    var segundos = Math.pow(2, b - 1) / 100000;

    if (segundos < 60)      { return 'se adivina en segundos'; }
    if (segundos < 3600)    { return 'aguanta minutos'; }
    if (segundos < 86400)   { return 'aguanta horas'; }
    if (segundos < 2592000) { return 'aguanta días'; }
    if (segundos < 31536000){ return 'aguanta meses'; }

    var anios = segundos / 31536000;

    if (anios < 100)   { return 'aguanta años'; }
    if (anios < 1e6)   { return 'aguanta siglos'; }

    return 'no se adivina por fuerza bruta';
  }

  /* ------------------------------------------------------------------
     El dibujo
     ------------------------------------------------------------------ */

  function montar(campo) {
    if (!campo || campo.dataset.cmFuerzaListo === '1') { return; }

    campo.dataset.cmFuerzaListo = '1';

    var caja = documento.createElement('div');
    caja.className = 'cm-fuerza';

    caja.innerHTML =
      '<div class="cm-fuerza__barra"><i></i></div>'
      + '<div class="cm-fuerza__linea">'
      +   '<span class="cm-fuerza__nivel"></span>'
      +   '<span class="cm-fuerza__tiempo"></span>'
      + '</div>'
      + '<ul class="cm-fuerza__reglas"></ul>';

    var barra  = caja.querySelector('.cm-fuerza__barra > i');
    var nivel  = caja.querySelector('.cm-fuerza__nivel');
    var tiempo = caja.querySelector('.cm-fuerza__tiempo');
    var lista  = caja.querySelector('.cm-fuerza__reglas');

    REGLAS.forEach(function (r) {
      var li = documento.createElement('li');
      li.dataset.regla = r.id;
      li.innerHTML = '<span class="cm-fuerza__punto"></span>' + r.texto;
      lista.appendChild(li);
    });

    /* Debajo del campo, dentro de su grupo: así en una rejilla de dos
       columnas se queda en la suya en vez de cruzar a la de al lado. */
    var donde = campo.closest('.form-group') || campo.parentNode;
    donde.appendChild(caja);

    function pintar() {
      var clave = campo.value;

      caja.classList.toggle('cm-fuerza--activa', clave !== '');

      var b = bits(clave);
      var n = nivelDe(b);

      // El ancho satura en 80 bits: más allá, la diferencia no se nota.
      barra.style.width = Math.min(100, Math.round((b / 80) * 100)) + '%';

      caja.className = 'cm-fuerza'
        + (clave !== '' ? ' cm-fuerza--activa cm-fuerza--' + n.clase : '');

      nivel.textContent  = clave === '' ? '' : n.nombre;
      tiempo.textContent = clave === '' ? '' : aguanta(b);

      REGLAS.forEach(function (r) {
        var li = lista.querySelector('[data-regla="' + r.id + '"]');
        li.classList.toggle('cm-fuerza__ok', r.prueba(clave));
      });
    }

    campo.addEventListener('input', pintar);
    campo.addEventListener('focus', pintar);

    pintar();
  }

  function montarTodo(raiz) {
    (raiz || documento)
      .querySelectorAll('input[type="password"][autocomplete="new-password"], [data-cm-fuerza]')
      .forEach(montar);
  }

  if (documento.readyState === 'loading') {
    documento.addEventListener('DOMContentLoaded', function () { montarTodo(); });
  } else {
    montarTodo();
  }

  global.CMFuerza = {
    montar: montar,
    montarTodo: montarTodo,
    bits: bits,
    nivelDe: nivelDe,
    aguanta: aguanta
  };
})(window, document);
