/* =====================================================================
   El código QR, leído de vuelta
   ---------------------------------------------------------------------
   Un generador de QR no se puede dar por bueno mirándolo: sale un
   cuadrado con sus tres esquinas tanto si los datos están bien como si
   están corridos. Eso fue exactamente lo que pasó —el código se veía
   perfecto y ninguna aplicación autenticadora lo reconocía— y nadie lo
   detectó hasta que alguien intentó escanearlo.

   Esta prueba cierra el círculo: genera el código, vuelve a leer la
   matriz siguiendo el estándar por su cuenta, y comprueba que sale el
   mismo texto que entró. El lector está escrito aparte a propósito: si
   reutilizara las funciones del generador, los dos compartirían el
   mismo error y la prueba pasaría con el código roto.

   Se ejecuta con:  node tests/QrTest.js
   ===================================================================== */
'use strict';

const fs = require('fs');
const path = require('path');

// --- Se carga el generador tal cual lo usa el navegador --------------
const ruta = path.join(__dirname, '..', 'public', 'assets', 'js', 'qr.js');
const global_ = { };

new Function('window', fs.readFileSync(ruta, 'utf8'))(global_);

const CMQr = global_.CMQr;

if (!CMQr || typeof CMQr.svg !== 'function') {
  console.error('  !! qr.js no expuso CMQr.svg');
  process.exit(1);
}

// =====================================================================
// El lector, escrito desde el estándar y sin mirar al generador
// =====================================================================

/** Saca la matriz de módulos del SVG que produce el generador. */
function matrizDesdeSvg(svg) {
  const vb = svg.match(/viewBox="0 0 (\d+) \1"/);

  if (!vb) { throw new Error('el SVG no trae viewBox cuadrado'); }

  const total  = parseInt(vb[1], 10);
  const margen = 4;
  const n      = total - margen * 2;

  const m = Array.from({ length: n }, () => new Array(n).fill(0));

  const re = /<rect x="(\d+)" y="(\d+)" width="1" height="1"/g;
  let r;

  while ((r = re.exec(svg)) !== null) {
    const x = parseInt(r[1], 10) - margen;
    const y = parseInt(r[2], 10) - margen;

    if (x >= 0 && y >= 0 && x < n && y < n) { m[y][x] = 1; }
  }

  return m;
}

/** Qué celdas son de función y no llevan datos. */
function mapaFunciones(n, version) {
  const f = Array.from({ length: n }, () => new Array(n).fill(false));

  const marcar = (r0, c0, alto, ancho) => {
    for (let r = r0; r < r0 + alto; r++) {
      for (let c = c0; c < c0 + ancho; c++) {
        if (r >= 0 && c >= 0 && r < n && c < n) { f[r][c] = true; }
      }
    }
  };

  // Buscadores con su separador.
  marcar(0, 0, 9, 9);
  marcar(0, n - 8, 9, 8);
  marcar(n - 8, 0, 8, 9);

  // Sincronización.
  for (let i = 0; i < n; i++) { f[6][i] = true; f[i][6] = true; }

  // Alineación.
  const ALIN = {
    1: [], 2: [6, 18], 3: [6, 22],
    4: [6, 26], 5: [6, 30], 6: [6, 34], 7: [6, 22, 38],
    8: [6, 24, 42], 9: [6, 26, 46], 10: [6, 28, 50]
  }[version] || [];

  ALIN.forEach((r) => ALIN.forEach((c) => {
    const esquina = (r === 6 && c === 6)
                 || (r === 6 && c === ALIN[ALIN.length - 1])
                 || (r === ALIN[ALIN.length - 1] && c === 6);

    if (!esquina) { marcar(r - 2, c - 2, 5, 5); }
  }));

  /* La informacion de version: dos bloques de 6x3, solo de la
     version 7 en adelante. Son 36 modulos que no llevan datos. */
  if (version >= 7) {
    for (let b = 0; b < 18; b++) {
      const fila = Math.floor(b / 3);
      const des  = b % 3;

      f[fila][n - 11 + des] = true;
      f[n - 11 + des][fila] = true;
    }
  }

  return f;
}

/** Lee los bits de datos recorriendo en zigzag y quitando la máscara 0. */
function leerBits(m, version) {
  const n = m.length;
  const f = mapaFunciones(n, version);
  const bits = [];

  let arriba = true;

  for (let col = n - 1; col > 0; col -= 2) {
    if (col === 6) { col--; }

    for (let i = 0; i < n; i++) {
      const fila = arriba ? (n - 1 - i) : i;

      for (let c = 0; c < 2; c++) {
        const x = col - c;

        if (f[fila][x]) { continue; }

        let bit = m[fila][x];

        if ((fila + x) % 2 === 0) { bit ^= 1; }   // máscara 0

        bits.push(bit);
      }
    }

    arriba = !arriba;
  }

  return bits;
}

/** Deshace el intercalado de bloques y devuelve las palabras de datos. */
function desintercalar(palabras, version) {
  const V = {
    1:  [21,  10, 1, 16,  0, 0],
    2:  [25,  16, 1, 28,  0, 0],
    3:  [29,  26, 1, 44,  0, 0],
    4:  [33,  18, 2, 32,  0, 0],
    5:  [37,  24, 2, 43,  0, 0],
    6:  [41,  16, 4, 27,  0, 0],
    7:  [45,  18, 4, 31,  0, 0],
    8:  [49,  22, 2, 38,  2, 39],
    9:  [53,  22, 3, 36,  2, 37],
    10: [57,  26, 4, 43,  1, 44]
  }[version];

  const bloques = [];

  for (let b = 0; b < V[2]; b++) { bloques.push(new Array(V[3])); }
  for (let b = 0; b < V[4]; b++) { bloques.push(new Array(V[5])); }

  const maximo = Math.max(V[3], V[5] || 0);
  let idx = 0;

  for (let i = 0; i < maximo; i++) {
    for (let b = 0; b < bloques.length; b++) {
      if (i < bloques[b].length) { bloques[b][i] = palabras[idx++]; }
    }
  }

  return [].concat(...bloques);
}

/** El texto que lleva dentro, en modo byte. */
function decodificar(svg) {
  const m = matrizDesdeSvg(svg);
  const n = m.length;
  const version = (n - 17) / 4;

  const bits = leerBits(m, version);

  const palabras = [];

  for (let i = 0; i + 7 < bits.length; i += 8) {
    let v = 0;
    for (let k = 0; k < 8; k++) { v = (v << 1) | bits[i + k]; }
    palabras.push(v);
  }

  const datos = desintercalar(palabras, version);

  // Modo (4 bits) + longitud (8 bits en estas versiones) + bytes.
  let b = '';
  datos.forEach((p) => { b += p.toString(2).padStart(8, '0'); });

  const modo = parseInt(b.slice(0, 4), 2);

  if (modo !== 4) { throw new Error('el modo leído no es byte: ' + modo); }

  const largo = parseInt(b.slice(4, 12), 2);
  const bytes = [];

  for (let i = 0; i < largo; i++) {
    bytes.push(parseInt(b.slice(12 + i * 8, 20 + i * 8), 2));
  }

  return Buffer.from(bytes).toString('utf8');
}

// =====================================================================
// Las pruebas
// =====================================================================

const casos = [
  'HOLA',
  'otpauth://totp/CashMan%20H.A.:javier.pineda'
    + '?secret=O5Z3OBNLQ2NV65EAXCZHUZVBCNO2MZAG'
    + '&issuer=CashMan%20H.A.&algorithm=SHA1&digits=6&period=30',
  'otpauth://totp/CashMan%20H.A.:un.usuario.con.nombre.bastante.largo'
    + '?secret=ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
    + '&issuer=CashMan%20H.A.&algorithm=SHA1&digits=6&period=30',
];

let bien = 0;
let mal  = 0;

console.log('\n  CÓDIGO QR — generar y volver a leer\n');

casos.forEach((texto) => {
  const corto = texto.length > 54 ? texto.slice(0, 51) + '…' : texto;

  try {
    const leido = decodificar(CMQr.svg(texto, 240));

    if (leido === texto) {
      console.log('    ok    ' + corto);
      bien++;
    } else {
      console.log('    MAL   ' + corto);
      console.log('          se leyó: ' + leido.slice(0, 70));
      mal++;
    }
  } catch (e) {
    console.log('    MAL   ' + corto);
    console.log('          ' + e.message);
    mal++;
  }
});

console.log('\n  ' + '-'.repeat(58));
console.log('  ' + (bien + mal) + ' comprobaciones: ' + bien + ' bien, ' + mal + ' mal\n');

process.exit(mal === 0 ? 0 : 1);
