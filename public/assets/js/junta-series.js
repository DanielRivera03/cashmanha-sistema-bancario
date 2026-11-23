/* =====================================================================
   Los gráficos de la Junta Directiva
   ---------------------------------------------------------------------
   La vista entrega los datos en una isla JSON y este guion los dibuja.
   No se arma el gráfico en el HTML por dos razones: la política de
   seguridad no ejecuta guiones en línea sin su nonce, y mezclar
   quinientas cifras con el marcado haría la vista ilegible.

   DECISIONES DE DIBUJO, Y POR QUÉ

   · UN SOLO EJE. Nunca dos escalas en el mismo gráfico. Dos medidas
     de tamaño distinto van en dos gráficos, no en uno con el eje
     derecho inventado: con dos ejes cualquiera puede hacer que dos
     curvas se crucen donde le convenga, y eso en un consejo de
     administración no es un gráfico, es un argumento.

   · UNA SERIE NO LLEVA LEYENDA. El título ya dice qué es. Dos o más,
     siempre leyenda.

   · LÍNEAS FINAS Y SIN PUNTOS. Los puntos aparecen al pasar por
     encima. Treinta círculos sobre una línea esconden la línea.

   · LOS COLORES SON LOS VALIDADOS. Azul y naranja, en su paso para
     fondo claro y en el suyo para fondo oscuro. Se comprobaron con el
     validador —banda de luminosidad, croma, separación para daltonismo
     y contraste contra el fondo— y pasan las cinco en los dos modos.
     No se cambian a ojo.

   · EL DINERO VA EN CENTAVOS Y SE FORMATEA AL PINTAR. Dividir antes
     arrastraría el error de redondeo a la escala.
   ===================================================================== */
(function (window, document) {
  'use strict';

  if (!window.Chart) { return; }

  var isla = document.getElementById('jd-series');

  if (!isla) { return; }

  var datos;

  try {
    datos = JSON.parse(isla.textContent || '{}');
  } catch (e) {
    console.error('[junta] no pude leer las series', e);
    return;
  }

  // ------------------------------------------------------------------
  // La paleta, por modo
  // ------------------------------------------------------------------
  var PALETA = {
    dia:   { uno: '#2a78d6', dos: '#eb6834', reja: '#eceff4', texto: '#7b8494' },
    noche: { uno: '#3987e5', dos: '#d95926', reja: '#2a3150', texto: '#a0a7b8' }
  };

  function modo() {
    return document.documentElement.getAttribute('data-tema') === 'noche' ? 'noche' : 'dia';
  }

  function tinta() { return PALETA[modo()]; }

  // ------------------------------------------------------------------
  // Formato de cifras
  // ------------------------------------------------------------------
  function dolares(centavos) {
    return (centavos / 100).toLocaleString('es-SV', {
      style: 'currency', currency: 'USD', maximumFractionDigits: 0
    });
  }

  /* En el eje no cabe «$1,234,567»: se abrevia. En el globo sí cabe
     entero, y ahí va entero, porque es donde se lee la cifra. */
  function corto(centavos) {
    var v = centavos / 100;

    if (Math.abs(v) >= 1000000) { return '$' + (v / 1000000).toFixed(1) + ' M'; }
    if (Math.abs(v) >= 1000)    { return '$' + Math.round(v / 1000) + ' k'; }

    return '$' + Math.round(v);
  }

  function entero(n) { return Number(n).toLocaleString('es-SV'); }

  // ------------------------------------------------------------------
  // Las piezas comunes de todos los gráficos
  // ------------------------------------------------------------------
  function ejes(formato) {
    var t = tinta();

    return {
      xAxes: [{
        gridLines: { display: false, drawBorder: false },
        ticks: { fontColor: t.texto, fontSize: 11, maxRotation: 0, autoSkipPadding: 14 }
      }],
      yAxes: [{
        gridLines: { color: t.reja, drawBorder: false, zeroLineColor: t.reja },
        ticks: {
          fontColor: t.texto,
          fontSize: 11,
          padding: 8,
          beginAtZero: true,
          callback: formato
        }
      }]
    };
  }

  function globo(formato) {
    return {
      mode: 'index',
      intersect: false,
      backgroundColor: 'rgba(24,31,57,.94)',
      titleFontSize: 12,
      bodyFontSize: 12,
      bodySpacing: 5,
      xPadding: 11,
      yPadding: 9,
      cornerRadius: 7,
      displayColors: true,
      callbacks: {
        label: function (item, data) {
          var serie = data.datasets[item.datasetIndex].label || '';

          return (serie ? serie + ': ' : '') + formato(item.yLabel);
        }
      }
    };
  }

  function base(formatoEje, formatoGlobo, conLeyenda) {
    return {
      responsive: true,
      maintainAspectRatio: false,
      layout: { padding: { top: 6, right: 6 } },
      legend: conLeyenda
        ? {
            display: true,
            position: 'top',
            align: 'end',
            labels: {
              boxWidth: 10, usePointStyle: true,
              fontColor: tinta().texto, fontSize: 11, padding: 14
            }
          }
        : { display: false },
      tooltips: globo(formatoGlobo),
      hover: { mode: 'index', intersect: false },
      scales: ejes(formatoEje)
    };
  }

  // ------------------------------------------------------------------
  // Los tipos de gráfico
  // ------------------------------------------------------------------
  function linea(lienzo, serie, color, esDinero) {
    var ctx = lienzo.getContext('2d');

    /* El degradado bajo la línea da el volumen del saldo sin pedirle
       al ojo que compare áreas: la línea sigue siendo la que manda. */
    var relleno = ctx.createLinearGradient(0, 0, 0, lienzo.height || 260);

    relleno.addColorStop(0, color + '38');
    relleno.addColorStop(1, color + '04');

    return new window.Chart(ctx, {
      type: 'line',
      data: {
        labels: serie.map(function (f) { return f.rotulo; }),
        datasets: [{
          data: serie.map(function (f) { return f.valor; }),
          borderColor: color,
          backgroundColor: relleno,
          borderWidth: 2,
          pointRadius: 0,
          pointHoverRadius: 5,
          pointHoverBackgroundColor: color,
          pointHoverBorderColor: '#fff',
          pointHoverBorderWidth: 2,
          fill: true,
          lineTension: 0.28
        }]
      },
      options: base(
        esDinero ? corto : entero,
        esDinero ? dolares : entero,
        false
      )
    });
  }

  function barras(lienzo, serie, color, esDinero) {
    return new window.Chart(lienzo.getContext('2d'), {
      type: 'bar',
      data: {
        labels: serie.map(function (f) { return f.rotulo; }),
        datasets: [{
          data: serie.map(function (f) { return f.valor; }),
          backgroundColor: color,
          hoverBackgroundColor: color,
          borderWidth: 0,
          barPercentage: 0.58,
          categoryPercentage: 0.82
        }]
      },
      options: base(
        esDinero ? corto : entero,
        esDinero ? dolares : entero,
        false
      )
    });
  }

  /* Dos series del MISMO tipo de medida —los dos son dinero— así que
     comparten eje con toda legitimidad. Con leyenda, porque son dos. */
  function dos(lienzo, a, b, etiquetaA, etiquetaB) {
    var t = tinta();

    return new window.Chart(lienzo.getContext('2d'), {
      type: 'bar',
      data: {
        labels: a.map(function (f) { return f.rotulo; }),
        datasets: [
          {
            label: etiquetaA,
            data: a.map(function (f) { return f.monto; }),
            backgroundColor: t.uno,
            borderWidth: 0,
            barPercentage: 0.46,
            categoryPercentage: 0.86
          },
          {
            label: etiquetaB,
            data: b.map(function (f) { return f.monto; }),
            backgroundColor: t.dos,
            borderWidth: 0,
            barPercentage: 0.46,
            categoryPercentage: 0.86
          }
        ]
      },
      options: base(corto, dolares, true)
    });
  }

  // ------------------------------------------------------------------
  // A dibujar
  // ------------------------------------------------------------------
  var vivos = [];

  function dibujar() {
    /* Al cambiar de tema se redibuja todo: los colores de la reja y
       del texto se fijan al construir el gráfico, no se heredan del
       CSS, así que no basta con repintar. */
    vivos.forEach(function (g) { g.destroy(); });
    vivos = [];

    var t = tinta();

    var $ = function (id) { return document.getElementById(id); };

    if ($('jd-captaciones') && datos.captaciones) {
      vivos.push(linea($('jd-captaciones'), datos.captaciones, t.uno, true));
    }

    if ($('jd-cartera') && datos.cartera) {
      vivos.push(linea($('jd-cartera'), datos.cartera, t.dos, true));
    }

    if ($('jd-flujo') && datos.desembolsos && datos.recuperacion) {
      vivos.push(dos($('jd-flujo'), datos.desembolsos, datos.recuperacion,
        'Desembolsado', 'Recuperado'));
    }

    if ($('jd-operaciones') && datos.operaciones) {
      vivos.push(barras($('jd-operaciones'), datos.operaciones, t.uno, false));
    }

    if ($('jd-clientes') && datos.clientes) {
      vivos.push(barras($('jd-clientes'), datos.clientes, t.uno, false));
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', dibujar);
  } else {
    dibujar();
  }

  /* El tema se cambia sin recargar, así que hay que enterarse. No hay
     evento propio: se vigila el atributo del documento, que es lo que
     `tema.js` cambia. */
  if (window.MutationObserver) {
    new window.MutationObserver(function (cambios) {
      for (var i = 0; i < cambios.length; i++) {
        if (cambios[i].attributeName === 'data-tema') { dibujar(); return; }
      }
    }).observe(document.documentElement, { attributes: true });
  }
})(window, document);
