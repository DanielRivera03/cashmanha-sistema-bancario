/* =====================================================================
   EL TOKEN DE SEGURIDAD, EN UN SOLO SITIO
   =====================================================================

   EL PROBLEMA QUE CIERRA

   Los tokens CSRF de este sistema son de UN SOLO USO: en cuanto el
   servidor valida uno, lo descarta. Eso está bien y no se toca.

   Lo que estaba mal era todo lo demás:

     · Cada pantalla guardaba su propia copia del token en una variable
       («var CSRF = …»). Unas se acordaban de actualizarla con el
       reemplazo que devuelve el servidor y otras no. Las que no,
       fallaban a la SEGUNDA acción, siempre.

     · `CM.csrf`, el del layout, no se actualizaba nunca. Con navegación
       en vivo el layout no se vuelve a ejecutar, así que ese token
       quedaba gastado para el resto de la sesión, pantalla tras
       pantalla.

     · Cuando una petición fallaba por token vencido, el servidor NO
       devolvía uno nuevo. La pantalla quedaba muerta: cada reintento
       volvía a fallar y la única salida era recargar. De ahí el
       «recargue la página» del aviso, que es pedirle al cliente que
       arregle a mano un problema nuestro.

     · El recolector del sitio público buscaba el token dentro de
       `datos` y el servidor lo manda arriba, así que no lo encontraba
       casi nunca.

   LA SOLUCIÓN

   Un registro único, por propósito, que se mantiene al día solo
   mirando cada respuesta al pasar. Y, cuando aun así una petición se
   encuentra con un token vencido, se REINTENTA UNA VEZ con el que el
   servidor acaba de devolver.

   Ese reintento es lo que hace que el error deje de existir para quien
   usa el sistema. Es seguro: un sitio ajeno no puede leer nuestra
   respuesta, así que nunca llega a ver el token nuevo; el reintento
   ocurre dentro de nuestra propia página, con nuestro propio token, a
   raíz de algo que el usuario pidió. Y la comprobación de origen sigue
   en pie como segunda barrera.

   Este archivo se carga ANTES que cualquier otro que use fetch.
   ===================================================================== */
(function (global, documento) {
  'use strict';

  /* El token más fresco de cada propósito. */
  var registro = {};

  /* Para no reintentar en bucle si algo va mal de verdad. */
  var MARCA_REINTENTO = '__cmReintentoCsrf';

  // ===================================================================
  // El registro
  // ===================================================================

  /** El token vigente de un propósito, o el del formulario si no hay. */
  function de(proposito) {
    var p = proposito || 'global';

    if (registro[p]) { return registro[p]; }

    /* Si nadie lo ha registrado todavía, se lee de la pantalla: es
       donde lo dejó el servidor al pintarla. */
    var campo = documento.querySelector(
      'input[name="_csrf_proposito"][value="' + p.replace(/"/g, '') + '"]'
    );

    if (campo && campo.form) {
      var tok = campo.form.querySelector('input[name="_csrf"]');
      if (tok && tok.value) {
        registro[p] = tok.value;
        return tok.value;
      }
    }

    /* El del layout, para las peticiones de fondo que no tienen
       formulario del que leerlo. */
    if (p === 'api' && global.CM && global.CM.csrf) {
      registro.api = global.CM.csrf;
      return global.CM.csrf;
    }

    return global.CM_CSRF || '';
  }

  /**
   * Guarda un token nuevo y lo reparte por donde haga falta.
   *
   * Solo en los campos del MISMO propósito: un token de «tarjeta» no
   * vale en el formulario de «perfil» que haya en la misma pantalla, y
   * escribirlo ahí lo rompía en silencio.
   */
  function adoptar(token, proposito) {
    if (typeof token !== 'string' || token === '') { return; }

    var p = proposito || 'global';

    registro[p] = token;

    /* Compatibilidad con lo que ya existía. */
    global.CM_CSRF = token;

    /* El del layout. Sin esto, con navegación en vivo quedaba gastado
       para toda la sesión, que es el fallo que más se notaba. */
    if (global.CM && p === 'api') { global.CM.csrf = token; }

    var campos = documento.querySelectorAll('input[name="_csrf"]');

    for (var i = 0; i < campos.length; i++) {
      var form = campos[i].form;
      var pr = form ? form.querySelector('input[name="_csrf_proposito"]') : null;

      /* Sin propósito declarado se actualiza igual: no hay forma de
         saber si corresponde, y dejarlo gastado es el fallo que esto
         viene a evitar. */
      if (pr && pr.value && pr.value !== p) { continue; }

      campos[i].value = token;
    }
  }

  /**
   * Relee los tokens de la pantalla.
   *
   * Con navegación en vivo el contenido se sustituye sin recargar: los
   * tokens del HTML nuevo son buenos y los del registro, de la pantalla
   * anterior. Hay que quedarse con los nuevos.
   */
  function releer() {
    var campos = documento.querySelectorAll('input[name="_csrf"]');

    for (var i = 0; i < campos.length; i++) {
      var form = campos[i].form;
      var pr = form ? form.querySelector('input[name="_csrf_proposito"]') : null;

      if (pr && pr.value && campos[i].value) {
        registro[pr.value] = campos[i].value;
      }
    }

    /* El token del layout, que no vive en ningún formulario.
     *
     * Es el de propósito «api», el que usan las peticiones de fondo
     * —notificaciones, pulso— que mandan el token por cabecera. Sin
     * sembrarlo aquí, la PRIMERA de esas llamadas saldría sin token.
     *
     * Solo se toma si el registro todavía no tiene uno: el del layout
     * es el inicial, y cualquiera que haya llegado después en una
     * respuesta es más nuevo que él. */
    if (!registro.api && global.CM && global.CM.csrf) {
      registro.api = global.CM.csrf;
    }
  }

  documento.addEventListener('cm:montar', releer);
  documento.addEventListener('cm:vista', releer);

  // ===================================================================
  // El reintento
  // ===================================================================

  /**
   * Mete el token nuevo en una petición que va a repetirse.
   *
   * Devuelve false si no se puede —un cuerpo que ya se consumió, por
   * ejemplo— y entonces no se reintenta: mejor el aviso de siempre que
   * una petición a medias.
   */
  function reemplazarToken(opciones, token, proposito) {
    if (!opciones) { return false; }

    var cambiado = false;

    /* La cabecera, que es como la manda el layout. */
    if (opciones.headers) {
      var h = opciones.headers;

      if (typeof h.set === 'function') {
        if (h.get('X-CSRF-Token') !== null) { h.set('X-CSRF-Token', token); cambiado = true; }
      } else if (typeof h === 'object') {
        for (var k in h) {
          if (Object.prototype.hasOwnProperty.call(h, k)
              && k.toLowerCase() === 'x-csrf-token') {
            h[k] = token;
            cambiado = true;
          }
        }
      }
    }

    var cuerpo = opciones.body;

    if (cuerpo && typeof FormData !== 'undefined' && cuerpo instanceof FormData) {
      if (cuerpo.has('_csrf')) {
        /* Si declara propósito, solo se toca el que corresponde. */
        var suyo = cuerpo.get('_csrf_proposito');

        if (!suyo || suyo === proposito) {
          cuerpo.set('_csrf', token);
          cambiado = true;
        }
      }
      return cambiado;
    }

    if (typeof cuerpo === 'string' && cuerpo.indexOf('_csrf=') !== -1) {
      opciones.body = cuerpo.replace(
        /(^|&)_csrf=[^&]*/,
        '$1_csrf=' + encodeURIComponent(token)
      );
      return true;
    }

    return cambiado;
  }

  // ===================================================================
  // El envoltorio de fetch
  // ===================================================================
  var original = global.fetch;

  if (typeof original !== 'function') {
    global.CMToken = { de: de, adoptar: adoptar, releer: releer };
    return;
  }

  global.fetch = function (recurso, opciones) {
    var self = this;
    var args = arguments;

    return original.apply(self, args).then(function (respuesta) {
      var tipo = respuesta.headers.get('content-type') || '';

      if (tipo.indexOf('json') === -1) { return respuesta; }

      /* Se lee de un CLON: el cuerpo original solo se puede consumir
         una vez y es de quien hizo la llamada. */
      return respuesta.clone().json().then(function (j) {
        if (!j || typeof j !== 'object') { return respuesta; }

        if (j.csrf) { adoptar(j.csrf, j.csrf_proposito); }

        /* --- El reintento -------------------------------------------
           Solo cuando el servidor dice expresamente que lo que falló
           fue el token, y solo si nos dio uno nuevo con el que volver
           a intentarlo. */
        var vencio = respuesta.status === 403
                  && j.codigo === 'csrf_vencido'
                  && typeof j.csrf === 'string' && j.csrf !== '';

        if (!vencio) { return respuesta; }

        if (opciones && opciones[MARCA_REINTENTO]) { return respuesta; }

        if (!opciones) { return respuesta; }

        if (!reemplazarToken(opciones, j.csrf, j.csrf_proposito)) {
          return respuesta;
        }

        opciones[MARCA_REINTENTO] = true;

        return original.call(self, recurso, opciones);
      }).catch(function () {
        /* Que no se pueda leer el token nuevo no debe tumbar una
           petición que sí funcionó. */
        return respuesta;
      });
    });
  };

  // ===================================================================
  // Lo que ven las vistas
  // ===================================================================
  global.CMToken = {
    de: de,
    adoptar: adoptar,
    releer: releer
  };

  /* Compatibilidad: las vistas y guiones que ya existían leen esto. */
  global.cmAdoptarCsrf = adoptar;
  global.CM_CSRF = global.CM_CSRF || null;

  if (documento.readyState === 'loading') {
    documento.addEventListener('DOMContentLoaded', releer);
  } else {
    releer();
  }
})(window, document);
