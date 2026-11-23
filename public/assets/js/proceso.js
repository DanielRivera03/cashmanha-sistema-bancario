/* =====================================================================
   LOS PROCESOS SE VEN MIENTRAS PASAN
   =====================================================================

   Hasta aquí, mandar una compra, una transferencia o un depósito se
   veía así: el botón se ponía gris y no pasaba nada más. Entre que se
   aprieta y que contesta el servidor puede haber varios segundos —la
   red de tarjetas tarda lo suyo—, y en esos segundos quien está
   delante no sabe si se mandó, si se colgó, o si conviene apretar otra
   vez. Apretar otra vez, en una pantalla de pago, es exactamente lo
   que no debe pasar.

   Esto abre un panel con los pasos del proceso, cuál va, cuánto lleva
   y en cuál falló si falla.

   SOBRE LA HONESTIDAD DEL PROGRESO

   Un proceso de UNA sola petición no tiene progreso que enseñar: el
   navegador no sabe por dónde va el servidor. Inventar una barra que
   sube al 70 % es mentirle al que mira, y cuando la cosa falla al
   final, la mentira además fue inútil.

   Aquí se hace así:

     - Los pasos que el navegador SÍ controla —mandar, recibir, llevar
       a la pantalla siguiente— se marcan cuando de verdad ocurren.

     - El tramo que corre en el servidor se enseña como lo que es: un
       paso en curso, con animación de indeterminado y un contador de
       segundos. Sin porcentaje.

     - Los procesos de VARIAS peticiones —el cobro por pasarela son
       tres; una compra de dos tiendas son dos cobros— sí llevan barra
       de verdad, porque ahí el navegador cuenta pasos reales.

   USO

     var p = CMProceso.abrir('compra');          // del catálogo
     var p = CMProceso.abrir({ titulo:…, pasos:[…] });

     p.paso('autorizando');       // marca hecho lo anterior
     p.avanzar();                 // el siguiente, sin nombrarlo
     p.nota('Autorizando con Visa…');
     p.listo('Compra confirmada');
     p.fallo('La tarjeta fue rechazada', 'autorizando');

   O, lo más corto, que es lo que usan las vistas:

     CMProceso.enviar(URL, { body: fd }, 'compra')
       .then(function (j) { … })        // ya cerró el panel
       .catch(function (e) { … });      // ya lo marcó en rojo
   ===================================================================== */
(function (global, documento) {
  'use strict';

  /* -------------------------------------------------------------------
     EL CATÁLOGO
     -------------------------------------------------------------------
     Cada proceso del sistema con sus pasos, en el orden en que de
     verdad ocurren. Está aquí y no en cada vista para que digan lo
     mismo en toda la aplicación: el que hace una transferencia en el
     panel y el que paga en la tienda tienen que ver el mismo lenguaje.

     `servidor: true` marca el paso que corre del otro lado. Ese es el
     que se queda en indeterminado con el contador.
     ------------------------------------------------------------------- */
  var CATALOGO = {
    compra: {
      titulo: 'Procesando su compra',
      nota: 'No cierre ni recargue esta pantalla.',
      pasos: [
        { clave: 'enviando',    texto: 'Enviando su pedido' },
        { clave: 'autorizando', texto: 'Autorizando con la red de tarjetas', servidor: true },
        { clave: 'registrando', texto: 'Registrando el pedido y apartando el inventario' },
        { clave: 'listo',       texto: 'Compra confirmada' }
      ]
    },

    /* El pago por pasarela son DOS momentos con el cliente tecleando
       su tarjeta en medio, así que son dos paneles y no uno: dejar uno
       abierto mientras se escriben dieciséis dígitos es tapar la
       pantalla justo cuando hay que leerla. */
    pasarela_apartar: {
      titulo: 'Preparando su pago',
      nota: 'Apartamos sus artículos mientras completa el pago.',
      pasos: [
        { clave: 'enviando',   texto: 'Enviando sus datos de entrega' },
        { clave: 'apartando',  texto: 'Apartando sus artículos', servidor: true },
        { clave: 'preparando', texto: 'Preparando el cobro con la procesadora', servidor: true },
        { clave: 'listo',      texto: 'Listo para pagar' }
      ]
    },

    pasarela_cobrar: {
      titulo: 'Cobrando a su tarjeta',
      nota: 'No cierre ni recargue esta pantalla.',
      pasos: [
        { clave: 'cobrando',    texto: 'Cobrando a su tarjeta', servidor: true },
        { clave: 'confirmando', texto: 'Confirmando su pedido', servidor: true },
        { clave: 'listo',       texto: 'Pago aplicado' }
      ]
    },

    transferencia: {
      titulo: 'Enviando su transferencia',
      nota: 'No cierre esta pantalla hasta que termine.',
      pasos: [
        { clave: 'enviando',  texto: 'Enviando la orden' },
        { clave: 'validando', texto: 'Revisando saldo y límites', servidor: true },
        { clave: 'asentando', texto: 'Registrando el movimiento en el libro mayor' },
        { clave: 'listo',     texto: 'Transferencia aplicada' }
      ]
    },

    /* Un envío a otro banco tiene un paso más que uno interno, y es el
       que de verdad tarda: el viaje por la red del BCR. Mostrarlo no
       es decoración. Quien manda dinero fuera quiere saber en qué
       punto va, y el paso de la cámara explica por qué el saldo ya
       bajó aunque el otro banco todavía no haya respondido. */
    interbancaria: {
      titulo: 'Enviando a otro banco',
      nota: 'No cierre esta pantalla hasta que termine.',
      pasos: [
        { clave: 'enviando',  texto: 'Enviando la orden' },
        { clave: 'validando', texto: 'Revisando saldo, límites y destino', servidor: true },
        { clave: 'camara',    texto: 'Asentando en la cámara interbancaria' },
        { clave: 'red',       texto: 'Entregando al sistema de pagos', servidor: true },
        { clave: 'listo',     texto: 'Transferencia en camino' }
      ]
    },

    deposito: {
      titulo: 'Registrando el depósito',
      pasos: [
        { clave: 'enviando',  texto: 'Enviando la operación' },
        { clave: 'validando', texto: 'Revisando la cuenta y el efectivo del turno', servidor: true },
        { clave: 'asentando', texto: 'Asentando en el libro mayor' },
        { clave: 'listo',     texto: 'Depósito aplicado' }
      ]
    },

    retiro: {
      titulo: 'Registrando el retiro',
      pasos: [
        { clave: 'enviando',  texto: 'Enviando la operación' },
        { clave: 'validando', texto: 'Revisando saldo disponible y límites', servidor: true },
        { clave: 'asentando', texto: 'Asentando en el libro mayor' },
        { clave: 'listo',     texto: 'Retiro aplicado' }
      ]
    },

    pago: {
      titulo: 'Aplicando su pago',
      pasos: [
        { clave: 'enviando',  texto: 'Enviando el pago' },
        { clave: 'validando', texto: 'Calculando capital, interés y mora', servidor: true },
        { clave: 'asentando', texto: 'Aplicando a su crédito' },
        { clave: 'listo',     texto: 'Pago aplicado' }
      ]
    },

    apertura: {
      titulo: 'Abriendo su cuenta',
      nota: 'Esto toma unos segundos. No cierre la pantalla.',
      pasos: [
        { clave: 'enviando',  texto: 'Enviando sus datos' },
        { clave: 'creando',   texto: 'Creando la cuenta', servidor: true },
        { clave: 'tarjeta',   texto: 'Emitiendo su tarjeta de débito virtual' },
        { clave: 'contrato',  texto: 'Generando y enviando su contrato' },
        { clave: 'listo',     texto: 'Cuenta abierta' }
      ]
    },

    registro: {
      titulo: 'Creando su usuario',
      pasos: [
        { clave: 'enviando', texto: 'Enviando sus datos' },
        { clave: 'creando',  texto: 'Creando su usuario', servidor: true },
        { clave: 'listo',    texto: 'Usuario creado' }
      ]
    },

    tarjeta: {
      titulo: 'Procesando la operación de su tarjeta',
      pasos: [
        { clave: 'enviando',  texto: 'Enviando la solicitud' },
        { clave: 'validando', texto: 'Revisando cupo y estado de la tarjeta', servidor: true },
        { clave: 'asentando', texto: 'Registrando el movimiento' },
        { clave: 'listo',     texto: 'Listo' }
      ]
    },

    documento: {
      titulo: 'Generando el documento',
      pasos: [
        { clave: 'enviando',  texto: 'Pidiendo el documento' },
        { clave: 'armando',   texto: 'Armando el PDF', servidor: true },
        { clave: 'listo',     texto: 'Documento listo' }
      ]
    },

    /* El de siempre, para lo que no tiene pasos propios. Mejor esto
       que no enseñar nada. */
    generico: {
      titulo: 'Procesando',
      pasos: [
        { clave: 'enviando', texto: 'Enviando' },
        { clave: 'esperando', texto: 'Procesando', servidor: true },
        { clave: 'listo',    texto: 'Listo' }
      ]
    }
  };

  var ICONOS = {
    hecho: '<path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/>',
    fallo: '<path d="M18 6L6 18M6 6l12 12" stroke-linecap="round"/>'
  };

  var abierto = null;

  function svg(d, tam) {
    return '<svg viewBox="0 0 24 24" width="' + (tam || 14) + '" height="' + (tam || 14)
         + '" fill="none" stroke="currentColor" stroke-width="3">' + d + '</svg>';
  }

  /* -------------------------------------------------------------------
     El panel
     ------------------------------------------------------------------- */

  function abrir(cual, extra) {
    /* Uno a la vez. Dos procesos encima del otro es una pantalla que
       no se entiende y, peor, dos operaciones de dinero a la vez que
       nadie pidió. */
    if (abierto) { abierto.cerrar(true); }

    var def = typeof cual === 'string'
      ? (CATALOGO[cual] || CATALOGO.generico)
      : (cual || CATALOGO.generico);

    extra = extra || {};

    var pasos = (extra.pasos || def.pasos).map(function (p) {
      return { clave: p.clave, texto: p.texto, servidor: p.servidor === true };
    });

    var fondo = documento.createElement('div');
    fondo.className = 'cm-proceso';
    fondo.setAttribute('role', 'dialog');
    fondo.setAttribute('aria-modal', 'true');
    fondo.setAttribute('aria-live', 'polite');

    var caja = documento.createElement('div');
    caja.className = 'cm-proceso__caja';

    var h = documento.createElement('h5');
    h.className = 'cm-proceso__titulo';
    h.textContent = extra.titulo || def.titulo;
    caja.appendChild(h);

    /* La barra. Con pasos reales marca cuántos van; mientras el
       servidor trabaja se pone en indeterminado, que es la verdad:
       no se sabe por dónde va. */
    var barra = documento.createElement('div');
    barra.className = 'cm-proceso__barra';

    var relleno = documento.createElement('i');
    barra.appendChild(relleno);
    caja.appendChild(barra);

    var lista = documento.createElement('ol');
    lista.className = 'cm-proceso__pasos';

    var filas = pasos.map(function (p) {
      var li = documento.createElement('li');
      li.className = 'cm-proceso__paso';

      var marca = documento.createElement('span');
      marca.className = 'cm-proceso__marca';

      var txt = documento.createElement('span');
      txt.className = 'cm-proceso__texto';
      txt.textContent = p.texto;

      li.appendChild(marca);
      li.appendChild(txt);
      lista.appendChild(li);

      return { li: li, marca: marca, texto: txt };
    });

    caja.appendChild(lista);

    var pie = documento.createElement('p');
    pie.className = 'cm-proceso__pie';
    pie.textContent = extra.nota || def.nota || 'Esto puede tardar unos segundos.';
    caja.appendChild(pie);

    var reloj = documento.createElement('span');
    reloj.className = 'cm-proceso__reloj';
    caja.appendChild(reloj);

    fondo.appendChild(caja);
    documento.body.appendChild(fondo);

    requestAnimationFrame(function () { fondo.classList.add('cm-proceso--visible'); });

    // ---------------------------------------------------------------

    var actual = -1;
    var muerto = false;
    var arranque = Date.now();

    var tic = setInterval(function () {
      var s = Math.round((Date.now() - arranque) / 1000);

      /* El contador aparece a los tres segundos. Antes de eso estorba:
         todo lo que tarda dos segundos se siente instantáneo, y poner
         un cronómetro encima lo hace sentir lento. */
      reloj.textContent = s >= 3 ? s + ' s' : '';
    }, 500);

    /* Red de seguridad. Si la petición no contesta nunca, un panel que
       tapa la pantalla la deja inservible. */
    var tope = setTimeout(function () {
      fallo('No hubo respuesta. Revise su conexión y vuelva a intentarlo.');
    }, typeof extra.tope === 'number' ? extra.tope : 90000);

    function pintar() {
      filas.forEach(function (f, i) {
        f.li.className = 'cm-proceso__paso';
        f.marca.innerHTML = '';

        if (i < actual) {
          f.li.classList.add('cm-proceso__paso--hecho');
          f.marca.innerHTML = svg(ICONOS.hecho);
        } else if (i === actual) {
          f.li.classList.add('cm-proceso__paso--curso');
          if (pasos[i].servidor) { f.li.classList.add('cm-proceso__paso--servidor'); }
        }
      });

      /* La barra solo cuenta pasos cerrados. Dentro de uno no se
         inventa nada: si es del servidor, se pone a rayas. */
      var hechos = Math.max(0, actual);
      relleno.style.width = Math.round((hechos / pasos.length) * 100) + '%';

      barra.classList.toggle(
        'cm-proceso__barra--sin-saber',
        actual >= 0 && pasos[actual] && pasos[actual].servidor
      );
    }

    function indiceDe(clave) {
      for (var i = 0; i < pasos.length; i++) {
        if (pasos[i].clave === clave) { return i; }
      }
      return -1;
    }

    function paso(clave) {
      if (muerto) { return api; }

      var i = typeof clave === 'number' ? clave : indiceDe(clave);

      if (i < 0) { return api; }

      actual = i;
      pintar();
      return api;
    }

    function avanzar() { return paso(actual + 1); }

    function nota(texto) {
      if (!muerto) { pie.textContent = texto; }
      return api;
    }

    /** Cambia el texto de un paso: «Autorizando con Visa ····4242». */
    function detalle(clave, texto) {
      var i = typeof clave === 'number' ? clave : indiceDe(clave);
      if (i >= 0 && filas[i]) { filas[i].texto.textContent = texto; }
      return api;
    }

    function listo(texto) {
      if (muerto) { return api; }

      actual = pasos.length;
      pintar();
      barra.classList.remove('cm-proceso__barra--sin-saber');
      relleno.style.width = '100%';
      caja.classList.add('cm-proceso__caja--listo');

      if (texto) { pie.textContent = texto; }

      /* Se queda un momento para que se vea que terminó bien, y se va.
         Lo que sigue —la redirección, el recibo— lo decide quien
         llamó. */
      setTimeout(function () { cerrar(); }, 900);
      return api;
    }

    function fallo(texto, clave) {
      if (muerto) { return api; }

      clearTimeout(tope);

      var i = clave !== undefined ? indiceDe(clave) : actual;

      if (i >= 0 && filas[i]) {
        filas[i].li.className = 'cm-proceso__paso cm-proceso__paso--fallo';
        filas[i].marca.innerHTML = svg(ICONOS.fallo);
      }

      barra.classList.remove('cm-proceso__barra--sin-saber');
      barra.classList.add('cm-proceso__barra--fallo');
      caja.classList.add('cm-proceso__caja--fallo');

      pie.className = 'cm-proceso__pie cm-proceso__pie--fallo';
      pie.textContent = texto || 'No se pudo completar la operación.';

      /* Con un botón, y no cerrándose solo: lo que hay que leer aquí
         es por qué falló, y eso no se lee en dos segundos. */
      if (!caja.querySelector('.cm-proceso__cerrar')) {
        var b = documento.createElement('button');
        b.type = 'button';
        b.className = 'cm-proceso__cerrar';
        b.textContent = 'Entendido';
        b.addEventListener('click', function () { cerrar(); });
        caja.appendChild(b);
        b.focus();
      }

      return api;
    }

    function cerrar(inmediato) {
      if (muerto) { return; }

      muerto = true;
      clearInterval(tic);
      clearTimeout(tope);

      if (abierto === api) { abierto = null; }

      if (inmediato) {
        if (fondo.parentNode) { fondo.parentNode.removeChild(fondo); }
        return;
      }

      fondo.classList.remove('cm-proceso--visible');
      setTimeout(function () {
        if (fondo.parentNode) { fondo.parentNode.removeChild(fondo); }
      }, 220);
    }

    var api = {
      paso: paso,
      avanzar: avanzar,
      nota: nota,
      detalle: detalle,
      listo: listo,
      fallo: fallo,
      cerrar: cerrar,
      elemento: fondo
    };

    paso(0);
    abierto = api;

    return api;
  }

  /* -------------------------------------------------------------------
     El atajo: una petición con su panel
     -------------------------------------------------------------------
     Hace el `fetch`, mueve los pasos con lo que de verdad va pasando y
     devuelve una promesa con el JSON. El panel se cierra solo: en
     verde si salió, y en rojo con el motivo si no.
     ------------------------------------------------------------------- */

  function enviar(url, opciones, cual, extra) {
    var p = abrir(cual || 'generico', extra);

    opciones = opciones || {};
    opciones.method = opciones.method || 'POST';
    opciones.credentials = opciones.credentials || 'same-origin';
    opciones.headers = opciones.headers || {};

    if (!opciones.headers['X-Requested-With']) {
      opciones.headers['X-Requested-With'] = 'XMLHttpRequest';
    }

    /* El primer paso queda hecho en cuanto la petición sale de aquí:
       eso sí lo sabemos. Lo siguiente lo está haciendo el servidor. */
    p.avanzar();

    return fetch(url, opciones)
      .then(function (r) {
        return r.json()
          .then(function (j) { return { ok: r.ok, estado: r.status, j: j }; })
          .catch(function () { return { ok: false, estado: r.status, j: null }; });
      })
      .then(function (res) {
        var j = res.j;
        var bien = res.ok && j && (j.exito === true || j.ok === true);

        if (!bien) {
          var m = (j && (j.mensaje || j.error))
            || 'No se pudo completar la operación. Intente de nuevo.';

          p.fallo(m);

          var e = new Error(m);
          e.respuesta = j;
          e.estado = res.estado;
          e.mostrado = true;        // el panel ya lo dijo: no avisar dos veces
          throw e;
        }

        /* Respondió bien: los pasos que quedaban entre el servidor y
           el final ya ocurrieron, por definición. */
        p.listo(j.mensaje || '');

        return j;
      })
      .catch(function (e) {
        if (!e.mostrado) {
          p.fallo('No se pudo conectar con el servidor. Revise su conexión.');
          e.mostrado = true;
        }
        throw e;
      });
  }

  global.CMProceso = {
    abrir: abrir,
    enviar: enviar,
    catalogo: CATALOGO,
    /** El que esté abierto, para los procesos de varias peticiones. */
    actual: function () { return abierto; }
  };
})(window, document);
