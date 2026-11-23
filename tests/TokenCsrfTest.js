/* =====================================================================
   EL TOKEN DE SEGURIDAD, EJECUTADO DE VERDAD
   ---------------------------------------------------------------------
   Lo que se comprueba es lo único que importa aquí: que el error «el
   token de seguridad venció» NO llegue a la pantalla.

   Para eso tienen que cumplirse cuatro cosas, y las cuatro se prueban
   ejecutando `token.js` con un navegador de mentira:

     · que el token nuevo de cada respuesta se guarde por propósito
     · que se escriba en los campos de ESE propósito y en ningún otro
     · que una petición rechazada por token vencido se REINTENTE una
       vez, sola, con el token nuevo
     · que no se reintente en bucle, ni cuando el fallo fue de otra cosa

   POR QUÉ ASÍ Y NO MIRANDO EL CÓDIGO

   Porque el fallo que esto viene a cerrar llevaba meses en el sistema
   con DOS implementaciones distintas que parecían correctas leyéndolas.
   Ninguna cubría el token del layout, y con navegación en vivo ése
   quedaba gastado para el resto de la sesión.

   Se ejecuta con:  node tests/TokenCsrfTest.js
   ===================================================================== */
'use strict';

const fs = require('fs');
const path = require('path');

let bien = 0;
let mal = 0;

function ok(condicion, texto) {
  if (condicion) { console.log('    ok    ' + texto); bien++; }
  else { console.log('    MAL   ' + texto); mal++; }
}

// =====================================================================
// Un navegador de mentira
// =====================================================================
function campo(nombre, valor, form) {
  return {
    name: nombre, value: valor, form: form || null,
    getAttribute() { return null; }
  };
}

/** Un formulario con su token y su propósito. */
function formulario(proposito, token) {
  const f = { _campos: {} };

  f.querySelector = (sel) => {
    if (sel.includes('_csrf_proposito')) { return f._campos.proposito; }
    if (sel.includes('_csrf')) { return f._campos.token; }
    return null;
  };

  f._campos.token = campo('_csrf', token, f);
  f._campos.proposito = campo('_csrf_proposito', proposito, f);

  return f;
}

const formA = formulario('interbancario', 'TOKEN-A-VIEJO');
const formB = formulario('cumplimiento', 'TOKEN-B-VIEJO');

const documento = {
  readyState: 'complete',
  addEventListener() {},
  querySelectorAll(sel) {
    if (sel.includes('_csrf"]')) {
      return [formA._campos.token, formB._campos.token];
    }
    return [];
  },
  querySelector(sel) {
    if (sel.includes('value="api"')) { return null; }
    if (sel.includes('value="interbancario"')) { return formA._campos.proposito; }
    if (sel.includes('value="cumplimiento"')) { return formB._campos.proposito; }
    return null;
  }
};

// --- El servidor de mentira ------------------------------------------
const llamadas = [];
let guion = [];

function respuestaFalsa(estado, cuerpo) {
  return {
    status: estado,
    headers: { get: () => 'application/json; charset=UTF-8' },
    clone() { return respuestaFalsa(estado, cuerpo); },
    json() { return Promise.resolve(cuerpo); }
  };
}

function fetchNativo(recurso, opciones) {
  llamadas.push({
    url: recurso,
    token: opciones && opciones.body && opciones.body.get
      ? opciones.body.get('_csrf')
      : (opciones && opciones.headers ? opciones.headers['X-CSRF-Token'] : null)
  });

  const siguiente = guion.shift();

  return Promise.resolve(siguiente || respuestaFalsa(200, { ok: true }));
}

class FormDataFalso {
  constructor() { this._p = {}; }
  append(k, v) { this._p[k] = String(v); }
  set(k, v) { this._p[k] = String(v); }
  get(k) { return Object.prototype.hasOwnProperty.call(this._p, k) ? this._p[k] : null; }
  has(k) { return Object.prototype.hasOwnProperty.call(this._p, k); }
}

const ventana = {
  fetch: fetchNativo,
  CM: { csrf: 'TOKEN-API-DEL-LAYOUT' },
  CM_CSRF: null
};

// =====================================================================
// Se ejecuta token.js tal cual se sirve
// =====================================================================
const fuente = fs.readFileSync(
  path.join(__dirname, '..', 'public', 'assets', 'js', 'token.js'),
  'utf8'
);

console.log('\n  EL TOKEN DE SEGURIDAD — ejecutado\n');

try {
  new Function('window', 'document', 'FormData', fuente)(
    ventana, documento, FormDataFalso
  );
} catch (e) {
  console.log('    MAL   token.js ni siquiera arranca: ' + e.message);
  process.exit(1);
}

ok(typeof ventana.CMToken === 'object', 'token.js publica CMToken');

ok(ventana.CMToken.de('interbancario') === 'TOKEN-A-VIEJO',
   'lee el token de la pantalla cuando todavía no hay ninguno registrado');

ok(ventana.CMToken.de('api') === 'TOKEN-API-DEL-LAYOUT',
   'y el del layout para las peticiones de fondo, que no tienen formulario');

// ---------------------------------------------------------------------
// 1. Adoptar el token nuevo, solo donde toca
// ---------------------------------------------------------------------
ventana.CMToken.adoptar('TOKEN-A-NUEVO', 'interbancario');

ok(formA._campos.token.value === 'TOKEN-A-NUEVO',
   'el token nuevo se escribe en el formulario de su propósito');

ok(formB._campos.token.value === 'TOKEN-B-VIEJO',
   'y NO pisa el de otro propósito que esté en la misma pantalla');

ventana.CMToken.adoptar('TOKEN-API-NUEVO', 'api');

ok(ventana.CM.csrf === 'TOKEN-API-NUEVO',
   'el token del layout también se actualiza: con navegación en vivo nadie más lo haría');

// ---------------------------------------------------------------------
// 2. El reintento
// ---------------------------------------------------------------------
function cuerpoCon(token) {
  const fd = new FormDataFalso();
  fd.append('_csrf', token);
  fd.append('_csrf_proposito', 'interbancario');
  fd.append('monto', '50.00');
  return fd;
}

llamadas.length = 0;

guion = [
  respuestaFalsa(403, {
    ok: false,
    codigo: 'csrf_vencido',
    mensaje: 'El token de seguridad venció. Vuelva a intentarlo.',
    csrf: 'TOKEN-DEL-RESCATE',
    csrf_proposito: 'interbancario'
  }),
  respuestaFalsa(200, { ok: true, mensaje: 'Transferencia enviada.' })
];

ventana.fetch('/otros-bancos/enviar', {
  method: 'POST',
  body: cuerpoCon('TOKEN-YA-GASTADO')
}).then(function (r) {
  ok(r.status === 200,
     'una petición con el token gastado termina BIEN: el cliente no ve el error');

  ok(llamadas.length === 2,
     'porque se reintentó una vez, no más');

  ok(llamadas[0].token === 'TOKEN-YA-GASTADO',
     'el primer intento salió con el token gastado');

  ok(llamadas[1].token === 'TOKEN-DEL-RESCATE',
     'y el segundo con el que devolvió el servidor');

  // -------------------------------------------------------------------
  // 3. No se reintenta en bucle
  // -------------------------------------------------------------------
  llamadas.length = 0;

  guion = [
    respuestaFalsa(403, {
      ok: false, codigo: 'csrf_vencido', mensaje: 'venció',
      csrf: 'OTRO-1', csrf_proposito: 'interbancario'
    }),
    respuestaFalsa(403, {
      ok: false, codigo: 'csrf_vencido', mensaje: 'venció otra vez',
      csrf: 'OTRO-2', csrf_proposito: 'interbancario'
    }),
    respuestaFalsa(200, { ok: true })
  ];

  return ventana.fetch('/otros-bancos/enviar', {
    method: 'POST',
    body: cuerpoCon('GASTADO')
  });
}).then(function (r) {
  ok(llamadas.length === 2 && r.status === 403,
     'si el segundo intento también falla, se rinde: no entra en bucle');

  // -------------------------------------------------------------------
  // 4. Un 403 de OTRA cosa no se reintenta
  // -------------------------------------------------------------------
  llamadas.length = 0;

  guion = [
    respuestaFalsa(403, {
      ok: false,
      mensaje: 'No tiene permiso para esta operación.'
    }),
    respuestaFalsa(200, { ok: true })
  ];

  return ventana.fetch('/otros-bancos/enviar', {
    method: 'POST',
    body: cuerpoCon('BUENO')
  });
}).then(function (r) {
  ok(llamadas.length === 1 && r.status === 403,
     'un 403 por falta de permiso NO se reintenta: no era el token');

  // -------------------------------------------------------------------
  // 5. Una respuesta normal sigue llegando intacta
  // -------------------------------------------------------------------
  llamadas.length = 0;

  guion = [respuestaFalsa(200, { ok: true, mensaje: 'Listo', datos: { folio: 'ABC' } })];

  return ventana.fetch('/algo', { method: 'POST', body: cuerpoCon('BUENO') });
}).then(function (r) {
  return r.json().then(function (j) {
    ok(j.ok === true && j.datos.folio === 'ABC',
       'y una respuesta normal llega entera a quien la pidió');

    console.log('\n  ' + '-'.repeat(58));
    console.log('  ' + (bien + mal) + ' comprobaciones: ' + bien + ' bien, ' + mal + ' mal\n');

    process.exit(mal === 0 ? 0 : 1);
  });
}).catch(function (e) {
  console.log('    MAL   la cadena de pruebas reventó: ' + e.message);
  process.exit(1);
});
