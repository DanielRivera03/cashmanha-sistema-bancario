/* =====================================================================
   La pantalla del segundo factor, ejecutada de verdad
   ---------------------------------------------------------------------
   Comprobar que un guion «analiza sin errores» no dice nada de si
   funciona: el fallo que dejó esta pantalla muerta era una función que
   se llamaba a sí misma, sintácticamente impecable. El navegador
   reventaba al primer aviso y en pantalla no ocurría nada.

   Esto ejecuta el guion tal como sale servido, con un DOM y un `fetch`
   de mentira, y comprueba lo que de verdad importa:

     · que pulsar «Activar» llame al servidor
     · que el token que manda sea el de ESTA pantalla
     · que el token nuevo de cada respuesta se guarde para la siguiente
     · que al confirmar se mande el código tecleado
     · que un error del servidor se MUESTRE en vez de callarse

   Se ejecuta con:  node tests/PantallaTotpTest.js
   ===================================================================== */
'use strict';

const fs = require('fs');
const path = require('path');

let bien = 0;
let mal  = 0;

function ok(condicion, texto) {
  if (condicion) { console.log('    ok    ' + texto); bien++; }
  else           { console.log('    MAL   ' + texto); mal++; }
}

// =====================================================================
// Un DOM mínimo: solo lo que la pantalla toca
// =====================================================================
function nodo(id) {
  return {
    id: id,
    value: '',
    textContent: '',
    innerHTML: '',
    hidden: false,
    disabled: false,
    classList: { add() {}, remove() {}, contains() { return false; } },
    style: {},
    focus() {},
    click() { if (this.onclick) { this.onclick(); } },
    parentNode: null,
    scrollIntoView() {},
    onclick: null,
    _click: null,
    addEventListener(ev, fn) { if (ev === 'click') { this._click = fn; } },
    querySelectorAll() { return []; },
    querySelector() { return null; },
    appendChild() {},
    removeAttribute() {},
    setAttribute() {}
  };
}

const nodos = {};

const document_ = {
  getElementById(id) {
    if (!nodos[id]) { nodos[id] = nodo(id); }
    return nodos[id];
  },
  createElement: (t) => (t === 'a' ? anclaQueDescarga() : nodo('creado')),
  addEventListener() {},
  querySelectorAll: () => [],
  querySelector: () => null,
  body: nodo('body')
};

// --- El servidor de mentira ------------------------------------------
const enviados = [];
let respuesta = null;

function fetchFalso(url, opciones) {
  const datos = {};

  // El FormData de mentira guarda lo que le meten.
  (opciones.body._pares || []).forEach(([k, v]) => { datos[k] = v; });

  enviados.push({ url: url, datos: datos });

  return Promise.resolve({
    status: 200,
    text: () => Promise.resolve(JSON.stringify(respuesta))
  });
}

class FormDataFalso {
  constructor() { this._pares = []; }
  append(k, v) { this._pares.push([k, String(v)]); }
}

// --- El navegador falso, del lado de las descargas --------------------
const descargas = [];
const blobs = new Map();

class BlobFalso {
  constructor(partes) { this.texto = partes.join(''); }
}

const URLFalso = {
  createObjectURL(b) {
    const u = 'blob:falso/' + blobs.size;
    blobs.set(u, b);
    return u;
  },
  revokeObjectURL(u) { blobs.delete(u); }
};

/* El `a` que se crea para descargar: al pulsarlo se anota qué archivo
   habría bajado y con qué contenido. */
function anclaQueDescarga() {
  const a = nodo('a-descarga');

  a.click = function () {
    const b = blobs.get(a.href);
    descargas.push({ nombre: a.download, texto: b ? b.texto : '' });
  };

  return a;
}

// --- Lo que la pantalla espera encontrar ------------------------------

const avisos = [];

const window_ = {
  /* La pantalla registra manejadores globales de error para que
     ninguna excepcion se pierda; el navegador falso tambien los
     necesita o el guion no llega ni a arrancar. */
  addEventListener() {},
  CMQr: { svg: () => '<svg/>' },
  avisar: (titulo, texto) => { avisos.push({ titulo, texto }); },
  CMAviso: { confirmar: (o) => { if (o && o.alAceptar) { o.alAceptar(); } } },
  console: console,
  alert: (t) => { avisos.push({ titulo: '(alert)', texto: t }); },
  setInterval: () => 0,
  clearInterval: () => {},
  setTimeout: (fn) => { fn(); return 0; },
  location: { reload() {}, href: '' }
};

// =====================================================================
// Se extrae el guion de la vista, con su PHP ya resuelto a mano
// =====================================================================
const vista = fs.readFileSync(
  path.join(__dirname, '..', 'src', 'Vistas', 'seguridad', 'totp.php'),
  'utf8'
);

const bloques = vista.match(/<script[^>]*nonce[^>]*>([\s\S]*?)<\/script>/g) || [];

const bloque = bloques
  .map((b) => b.replace(/<script[^>]*>|<\/script>/g, ''))
  .find((b) => b.includes('btn-iniciar'));

if (!bloque) {
  console.error('  !! no encontré el guion del segundo factor en la vista');
  process.exit(1);
}

/* Las etiquetas de PHP se sustituyen por valores de prueba: lo que se
   quiere ejecutar es el JavaScript, no el PHP. */
const js = bloque
  .replace(/<\?= P::js\(\$csrf\) \?>/g, '"TOKEN-DE-LA-PANTALLA"')
  /* Cualquier otra etiqueta PHP —`<?=` o `<?php`— pasa a ser una cadena
     vacía: lo que se quiere ejecutar aquí es el JavaScript. El patrón
     tiene que admitir el `>` de `$rutas->url(...)` dentro. */
  .replace(/<\?[\s\S]*?\?>/g, '""')
  /* La etiqueta de apertura lleva PHP dentro —`<script <?= nonce ?>>`—
     así que al recortarla queda su `>` final suelto al principio. */
  .replace(/^\s*>/, '');

console.log('\n  PANTALLA DEL SEGUNDO FACTOR — ejecutada\n');

try {
  new Function('window', 'document', 'fetch', 'FormData', 'Blob', 'URL', js)(
    window_, document_, fetchFalso, FormDataFalso, BlobFalso, URLFalso
  );
} catch (e) {
  console.log('    MAL   el guion ni siquiera arranca: ' + e.message);
  process.exit(1);
}

ok(nodos['btn-iniciar'] && nodos['btn-iniciar']._click !== null,
   'el botón de activar queda enganchado');

// --- 1. Pulsar «Activar» ---------------------------------------------
respuesta = {
  ok: true,
  datos: { secreto: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', legible: 'ABCD EFGH',
           uri: 'otpauth://totp/x?secret=ABCDEFGHIJKLMNOPQRSTUVWXYZ234567' },
  csrf: 'TOKEN-NUEVO-1'
};

nodos['btn-iniciar']._click();

setTimeout(() => {
  ok(enviados.length === 1 && enviados[0].url.endsWith('iniciar'),
     'pulsar Activar llama al servidor');

  ok(enviados[0] && enviados[0].datos._csrf === 'TOKEN-DE-LA-PANTALLA',
     'y manda el token de esta pantalla, no el de otra');

  ok(enviados[0] && enviados[0].datos._csrf_proposito === 'seguridad',
     'con su propósito');

  // --- 2. Confirmar, con el token renovado ---------------------------
  nodos['codigo-confirmar'].value = '123456';

  /* Tres: numero impar, para que la ultima fila de la hoja quede con
     una sola columna y se vea si eso rompe algo. */
  const CODIGOS = ['AAAAA-11111', 'BBBBB-22222', 'CCCCC-33333'];

  respuesta = { ok: true, mensaje: 'Activado', datos: { codigos_respaldo: CODIGOS } };

  nodos['btn-confirmar']._click();

  setTimeout(() => {
    const conf = enviados[1];

    ok(conf && conf.url.endsWith('confirmar'), 'confirmar llama al servidor');

    ok(conf && conf.datos.codigo === '123456', 'y manda el código tecleado');

    ok(conf && conf.datos._csrf === 'TOKEN-NUEVO-1',
       'con el token NUEVO que devolvió la llamada anterior');

    // --- 3. La hoja de respaldo se descarga -------------------------
    nodos['btn-descargar'].onclick();

    ok(descargas.length === 1, 'el botón Descargar produce un archivo');

    const hoja = descargas[0] || { nombre: '', texto: '' };

    ok(/^cashmanha-codigos-respaldo-.+-\d{8}\.txt$/.test(hoja.nombre),
       'con un nombre que lo identifica sin abrirlo: ' + hoja.nombre);

    ok(CODIGOS.every((c) => hoja.texto.includes(c)),
       'y dentro están LOS ' + CODIGOS.length + ' códigos, ninguno perdido');

    ok(hoja.texto.includes('UNA SOLA VEZ') && hoja.texto.includes('Regenerar'),
       'con las instrucciones de uso, que es cuando se leen');

    ok(hoja.texto.includes('Titular') && hoja.texto.includes('Generados'),
       'y de quién es y cuándo se generó');

    ok((hoja.texto.match(/\[ \]/g) || []).length === CODIGOS.length,
       'una casilla por código para tachar el usado');

    // --- 4. Un error del servidor se ve -----------------------------
    const antes = avisos.length;

    respuesta = { ok: false, mensaje: 'El token de seguridad venció.' };

    nodos['btn-confirmar']._click();

    setTimeout(() => {
      ok(avisos.length > antes, 'un error del servidor se muestra, no se calla');

      ok(avisos.some((a) => String(a.texto).includes('venció')),
         'y se muestra el mensaje que mandó el servidor');

      console.log('\n  ' + '-'.repeat(58));
      console.log('  ' + (bien + mal) + ' comprobaciones: ' + bien + ' bien, ' + mal + ' mal\n');

      process.exit(mal === 0 ? 0 : 1);
    }, 0);
  }, 0);
}, 0);
