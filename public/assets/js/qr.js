/* =====================================================================
   Generador de códigos QR — ISO/IEC 18004
   ---------------------------------------------------------------------
   Implementado aquí a propósito, en lugar de usar un servicio externo
   como api.qrserver.com o las Google Charts: el secreto TOTP iría en
   la URL de la petición, es decir, se lo estaríamos entregando a un
   tercero junto con el nombre de usuario.

   Soporta modo byte con corrección de errores nivel M, suficiente para
   una URI otpauth:// (hasta ~150 caracteres).
   ===================================================================== */
(function (global) {
  'use strict';

  // --- Aritmética en GF(256), para Reed-Solomon ----------------------
  var EXP = new Array(512);
  var LOG = new Array(256);

  (function () {
    var x = 1;
    for (var i = 0; i < 255; i++) {
      EXP[i] = x;
      LOG[x] = i;
      x <<= 1;
      if (x & 0x100) { x ^= 0x11D; }   // polinomio generador
    }
    for (var j = 255; j < 512; j++) { EXP[j] = EXP[j - 255]; }
  })();

  function mul(a, b) {
    if (a === 0 || b === 0) { return 0; }
    return EXP[LOG[a] + LOG[b]];
  }

  function polinomioGenerador(grado) {
    var p = [1];
    for (var i = 0; i < grado; i++) {
      var nuevo = new Array(p.length + 1).fill(0);
      for (var j = 0; j < p.length; j++) {
        nuevo[j] ^= mul(p[j], 1);
        nuevo[j + 1] ^= mul(p[j], EXP[i]);
      }
      p = nuevo;
    }
    return p;
  }

  function corregir(datos, cantidad) {
    var gen = polinomioGenerador(cantidad);
    var res = datos.concat(new Array(cantidad).fill(0));

    for (var i = 0; i < datos.length; i++) {
      var coef = res[i];
      if (coef === 0) { continue; }
      for (var j = 0; j < gen.length; j++) {
        res[i + j] ^= mul(gen[j], coef);
      }
    }
    return res.slice(datos.length);
  }

  /* Tabla de capacidad del ESTÁNDAR, nivel de corrección M.
   *
   *   versión → [módulos, EC por bloque, bloques G1, datos G1,
   *              bloques G2, datos G2]
   *
   * La que había aquí no era la del estándar: decía, por ejemplo, que
   * la versión 4 lleva un bloque de 50 palabras con 20 de corrección
   * —setenta en total—, cuando una versión 4 tiene cien palabras y el
   * nivel M las reparte en dos bloques de 32 con 18 de corrección cada
   * uno. Lo mismo en casi todas las demás.
   *
   * Con esos números el generador producía un cuadrado impecable que
   * ningún lector podía interpretar, porque los lectores sí siguen el
   * estándar. Era la causa de que las aplicaciones autenticadoras no
   * reconocieran el código.
   *
   * Cada fila cuadra: datos + corrección = palabras totales de esa
   * versión. v4: 2×32 + 2×18 = 100. v9: 3×36 + 2×37 + 5×22 = 292.
   */
  var VERSIONES = {
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
  };

  var ALINEACION = {
    1: [], 2: [6, 18], 3: [6, 22],
    4: [6, 26], 5: [6, 30], 6: [6, 34], 7: [6, 22, 38],
    8: [6, 24, 42], 9: [6, 26, 46], 10: [6, 28, 50]
  };

  // Información de formato para EC nivel M, ya calculada.
  var FORMATO_M = [
    0x5412, 0x5125, 0x5E7C, 0x5B4B, 0x45F9, 0x40CE, 0x4F97, 0x4AA0
  ];

  /* Información de VERSIÓN, obligatoria de la versión 7 en adelante.
   *
   * Son dieciocho bits —seis de número de versión y doce de corrección
   * BCH— repetidos en dos bloques de 6×3: uno sobre el buscador de
   * arriba a la derecha y otro a la izquierda del de abajo.
   *
   * Faltaban. Y no es un adorno: son 36 módulos que el generador daba
   * por libres y usaba para escribir datos, mientras cualquier lector
   * los salta por ser zona de función. A partir de ahí leía todo
   * desplazado y descartaba el código. Era la razón por la que el QR
   * se veía perfecto y ninguna aplicación autenticadora lo reconocía.
   *
   * Hasta la versión 6 no existen, y por eso los códigos cortos —un
   * «HOLA» de prueba— sí funcionaban: no llegan a versión 7.
   */
  var VERSION_INFO = {
    7:  0x07C94,
    8:  0x085BC,
    9:  0x09A99,
    10: 0x0A4D3
  };

  function capacidadDatos(v) {
    var c = VERSIONES[v];
    return c[2] * c[3] + c[4] * c[5];
  }

  function elegirVersion(longitud) {
    for (var v = 1; v <= 10; v++) {
      // 4 bits de modo + 8/16 de longitud + 8 por carácter + relleno
      var bitsCabecera = 4 + (v >= 10 ? 16 : 8);
      if (capacidadDatos(v) * 8 >= bitsCabecera + longitud * 8 + 4) {
        return v;
      }
    }
    return null;
  }

  function aBytes(texto) {
    var out = [];
    for (var i = 0; i < texto.length; i++) {
      var c = texto.charCodeAt(i);
      if (c < 0x80) { out.push(c); }
      else if (c < 0x800) { out.push(0xC0 | (c >> 6), 0x80 | (c & 63)); }
      else { out.push(0xE0 | (c >> 12), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63)); }
    }
    return out;
  }

  function construirDatos(bytes, version) {
    var bits = [];
    function push(valor, n) {
      for (var i = n - 1; i >= 0; i--) { bits.push((valor >> i) & 1); }
    }

    push(4, 4);                                    // modo byte
    push(bytes.length, version >= 10 ? 16 : 8);    // longitud
    bytes.forEach(function (b) { push(b, 8); });

    var capacidad = capacidadDatos(version) * 8;

    // Terminador
    for (var t = 0; t < 4 && bits.length < capacidad; t++) { bits.push(0); }
    // Alinear a byte
    while (bits.length % 8 !== 0) { bits.push(0); }

    var palabras = [];
    for (var i = 0; i < bits.length; i += 8) {
      var b = 0;
      for (var j = 0; j < 8; j++) { b = (b << 1) | bits[i + j]; }
      palabras.push(b);
    }

    // Relleno alterno que exige el estándar
    var relleno = [0xEC, 0x11];
    var k = 0;
    while (palabras.length < capacidadDatos(version)) {
      palabras.push(relleno[k++ % 2]);
    }

    return palabras;
  }

  function intercalar(palabras, version) {
    var c = VERSIONES[version];
    var ecPorBloque = c[1];
    var bloques = [];
    var pos = 0;

    for (var i = 0; i < c[2]; i++) { bloques.push(palabras.slice(pos, pos += c[3])); }
    for (var j = 0; j < c[4]; j++) { bloques.push(palabras.slice(pos, pos += c[5])); }

    var ec = bloques.map(function (b) { return corregir(b, ecPorBloque); });

    var salida = [];
    var maxDatos = Math.max.apply(null, bloques.map(function (b) { return b.length; }));

    for (var d = 0; d < maxDatos; d++) {
      bloques.forEach(function (b) { if (d < b.length) { salida.push(b[d]); } });
    }
    for (var e = 0; e < ecPorBloque; e++) {
      ec.forEach(function (b) { salida.push(b[e]); });
    }

    return salida;
  }

  function crearMatriz(version) {
    var n = VERSIONES[version][0];
    var m = [];
    for (var i = 0; i < n; i++) { m.push(new Array(n).fill(null)); }
    return m;
  }

  function patronBusqueda(m, fila, col) {
    for (var r = -1; r <= 7; r++) {
      for (var c = -1; c <= 7; c++) {
        var y = fila + r, x = col + c;
        if (y < 0 || y >= m.length || x < 0 || x >= m.length) { continue; }
        var borde = (r >= 0 && r <= 6 && (c === 0 || c === 6)) ||
                    (c >= 0 && c <= 6 && (r === 0 || r === 6));
        var centro = r >= 2 && r <= 4 && c >= 2 && c <= 4;
        m[y][x] = (borde || centro) ? 1 : 0;
      }
    }
  }

  function dibujarFunciones(m, version) {
    var n = m.length;

    patronBusqueda(m, 0, 0);
    patronBusqueda(m, 0, n - 7);
    patronBusqueda(m, n - 7, 0);

    // Patrones de sincronización
    for (var i = 8; i < n - 8; i++) {
      var v = (i % 2 === 0) ? 1 : 0;
      if (m[6][i] === null) { m[6][i] = v; }
      if (m[i][6] === null) { m[i][6] = v; }
    }

    /* Patrones de alineación.
     *
     * Se dibujan en TODOS los cruces de la retícula menos los tres que
     * caerían encima de un patrón de búsqueda. Antes la condición era
     * «si el centro ya está ocupado, no lo dibujes», y eso se saltaba
     * además los que cruzan la línea de sincronización —en una versión
     * 8, los de (6,24) y (24,6)—. El estándar sí los exige: la línea
     * de sincronización pasa por encima y ellos siguen ahí.
     *
     * Treinta y seis módulos quedaban libres de más, y los datos se
     * escribían dentro de ellos: cualquier lector los descartaba por
     * ser zona de función y leía el resto desplazado. */
    var pos = ALINEACION[version] || [];
    var ultimo = pos[pos.length - 1];

    pos.forEach(function (r) {
      pos.forEach(function (c) {
        var sobreBuscador = (r === 6 && c === 6)
                         || (r === 6 && c === ultimo)
                         || (r === ultimo && c === 6);

        if (sobreBuscador) { return; }

        for (var dr = -2; dr <= 2; dr++) {
          for (var dc = -2; dc <= 2; dc++) {
            m[r + dr][c + dc] = (Math.max(Math.abs(dr), Math.abs(dc)) !== 1) ? 1 : 0;
          }
        }
      });
    });

    /* La información de versión, de la 7 en adelante.
     *
     * Dos bloques de 6×3 con los mismos dieciocho bits: uno debajo del
     * buscador de arriba a la derecha y otro a la derecha del de abajo
     * a la izquierda. El bit 0 es el menos significativo.
     *
     * Son 36 módulos. Sin dibujarlos quedaban libres, el generador
     * escribía datos encima y el lector —que sabe que ahí va la
     * versión— los descartaba: a partir de ese punto leía basura. */
    var info = VERSION_INFO[version];

    if (info !== undefined) {
      for (var b = 0; b < 18; b++) {
        var bit  = (info >> b) & 1;
        var fila = Math.floor(b / 3);
        var des  = b % 3;

        m[fila][n - 11 + des] = bit;   // arriba a la derecha
        m[n - 11 + des][fila] = bit;   // abajo a la izquierda
      }
    }

    m[n - 8][8] = 1;   // módulo oscuro fijo

    /* LAS CELDAS DE LA INFORMACIÓN DE FORMATO SE RESERVAN AQUÍ.
     *
     * Sin esto, `colocarDatos` las veía vacías y escribía datos en
     * ellas; después `colocarFormato` las pisaba con los bits de
     * formato y esos datos se perdían. Todo el flujo posterior quedaba
     * corrido y el código resultaba ilegible: se veía perfecto
     * —cuadrado, con sus tres esquinas— y ninguna aplicación
     * autenticadora lo reconocía.
     *
     * Se marcan con 0 y `colocarFormato` escribe el valor real al
     * final. Lo que importa es que dejen de ser `null`, porque `null`
     * es lo que `colocarDatos` entiende por «celda libre». */
    for (var k = 0; k <= 8; k++) {
      // Copia de la esquina superior izquierda: fila 8 y columna 8,
      // de 0 a 8, con la esquina (8,8) incluida.
      if (m[8][k] === null) { m[8][k] = 0; }
      if (m[k][8] === null) { m[k][8] = 0; }

      /* Las otras dos copias ocupan OCHO celdas cada una, no nueve.
         Reservar una de más —la (8, n-9) y la (n-9, 8)— le quitaba al
         flujo de datos un módulo por lado: el generador no escribía
         ahí y el lector sí leía, y a partir de ese punto todo el
         contenido salía desplazado. */
      if (k <= 7) {
        if (m[8][n - 1 - k] === null) { m[8][n - 1 - k] = 0; }
        if (m[n - 1 - k][8] === null) { m[n - 1 - k][8] = 0; }
      }
    }
  }

  function colocarDatos(m, datos) {
    var n = m.length;
    var bits = [];
    datos.forEach(function (b) {
      for (var i = 7; i >= 0; i--) { bits.push((b >> i) & 1); }
    });

    var idx = 0, arriba = true;

    for (var col = n - 1; col > 0; col -= 2) {
      if (col === 6) { col--; }   // se salta la columna de sincronización

      for (var i = 0; i < n; i++) {
        var fila = arriba ? (n - 1 - i) : i;

        for (var c = 0; c < 2; c++) {
          var x = col - c;
          if (m[fila][x] !== null) { continue; }

          var bit = idx < bits.length ? bits[idx++] : 0;
          // Máscara 0: (fila + columna) par
          if ((fila + x) % 2 === 0) { bit ^= 1; }
          m[fila][x] = bit;
        }
      }
      arriba = !arriba;
    }
  }

  /**
   * Los quince bits de formato, en las posiciones del estándar.
   *
   * Estaban TRANSPUESTOS: la copia de arriba a la izquierda escribía
   * en la columna 8 lo que va en la fila 8 y al revés, y la segunda
   * copia hacía lo mismo. Un lector busca esos bits en sitios fijos;
   * encontrándolos cambiados no sabe ni el nivel de corrección ni la
   * máscara, y abandona antes de intentar leer los datos.
   *
   * El orden es el de ISO/IEC 18004 §8.9: el bit 0 es el más
   * significativo de los quince.
   *
   *   copia 1   bits 0-5  -> (8,0)…(8,5)      fila 8, por la izquierda
   *             bit  6    -> (8,7)            saltando la sincronización
   *             bit  7    -> (8,8)            la esquina
   *             bit  8    -> (7,8)
   *             bits 9-14 -> (5,8)…(0,8)      columna 8, hacia arriba
   *
   *   copia 2   bits 0-6  -> (n-1,8)…(n-7,8)  columna 8, desde abajo
   *             bits 7-14 -> (8,n-8)…(8,n-1)  fila 8, hacia la derecha
   */
  function colocarFormato(m) {
    var n = m.length;
    var bits = FORMATO_M[0];   // máscara 0, nivel M

    for (var i = 0; i < 15; i++) {
      var b = (bits >> (14 - i)) & 1;

      // --- Primera copia, junto al buscador superior izquierdo ---
      if (i < 6)        { m[8][i] = b; }
      else if (i === 6) { m[8][7] = b; }
      else if (i === 7) { m[8][8] = b; }
      else if (i === 8) { m[7][8] = b; }
      else              { m[14 - i][8] = b; }

      // --- Segunda copia, repartida en las otras dos esquinas ---
      if (i < 7) { m[n - 1 - i][8] = b; }
      else       { m[8][n - 15 + i] = b; }
    }

    // El módulo oscuro cae dentro de la segunda copia: se repone.
    m[n - 8][8] = 1;
  }

  /** Genera el QR como SVG. */
  function svg(texto, tamano) {
    tamano = tamano || 200;

    var bytes   = aBytes(texto);
    var version = elegirVersion(bytes.length);

    if (version === null) {
      return '<div style="padding:1rem;color:#b42318;font-size:.8rem">' +
             'El contenido es demasiado largo para el código QR.</div>';
    }

    var m = crearMatriz(version);
    dibujarFunciones(m, version);
    colocarDatos(m, intercalar(construirDatos(bytes, version), version));
    colocarFormato(m);

    var n      = m.length;
    var margen = 4;
    var total  = n + margen * 2;
    var escala = tamano / total;

    var partes = ['<svg xmlns="http://www.w3.org/2000/svg" width="' + tamano +
                  '" height="' + tamano + '" viewBox="0 0 ' + total + ' ' + total +
                  '" shape-rendering="crispEdges" role="img" aria-label="Código QR">',
                  '<rect width="' + total + '" height="' + total + '" fill="#ffffff"/>'];

    for (var y = 0; y < n; y++) {
      for (var x = 0; x < n; x++) {
        if (m[y][x] === 1) {
          partes.push('<rect x="' + (x + margen) + '" y="' + (y + margen) +
                      '" width="1" height="1" fill="#0a3d32"/>');
        }
      }
    }

    partes.push('</svg>');
    return partes.join('');
  }

  global.CMQr = { svg: svg };

})(window);
