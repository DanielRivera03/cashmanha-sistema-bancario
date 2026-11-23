/* =====================================================================
   CashMan v2 — utilidades mínimas, cargadas en el <head>
   ---------------------------------------------------------------------
   Esto existe por un problema de orden de carga que rompía páginas
   enteras sin dejar rastro visible.

   El marco imprime el contenido de la vista ANTES que las etiquetas
   <script> del pie. Las vistas llevan su propio script en línea, y ese
   script se ejecuta en cuanto el navegador lo encuentra: antes, por
   tanto, de que existan `CMUtil` o `avisar`.

   Mientras el script en línea solo usara esos helpers dentro de un
   manejador de clic no pasaba nada, porque para entonces ya estaban.
   Pero basta con que los llame al arrancar —por ejemplo, para pintar el
   saldo disponible de la cuenta de origen— para que lance

       TypeError: Cannot read properties of undefined (reading 'money')

   y con él se caiga el resto del script: no se registra el validador de
   la cuenta destino, ni el botón del código, ni el envío del
   formulario. Desde fuera no se ve ningún error; simplemente los
   botones "no hacen nada". Eso es lo que pasaba en /transferir, y la
   misma dependencia estaba en otras catorce vistas.

   La solución no es ir vista por vista moviendo código a un evento de
   carga: es que estas utilidades existan desde el primer momento. Son
   funciones puras y diminutas, así que cargarlas en el <head> no cuesta
   nada.

   `cm.js` y `app.js` vuelven a definir `CMUtil` más tarde con lo mismo;
   que lo sobrescriban es inofensivo.
   ===================================================================== */
(function (global, documento) {
    'use strict';

    /** Centavos enteros a dólares con separador de miles. */
    function money(c) {
        var n = parseInt(c, 10);
        if (isNaN(n)) { n = 0; }

        var signo = n < 0 ? '-' : '';
        var abs   = Math.abs(n);

        return signo + '$' + Math.floor(abs / 100).toLocaleString('en-US') +
               '.' + String(abs % 100).padStart(2, '0');
    }

    /** Escapa para insertar en HTML sin abrir la puerta a inyección. */
    function escapar(s) {
        var d = documento.createElement('div');
        d.textContent = String(s === null || s === undefined ? '' : s);
        return d.innerHTML;
    }

    /** Cambia un texto y lo destella, solo si de verdad cambió. */
    function fijarValor(el, txt) {
        if (!el || el.textContent === txt) { return false; }

        el.textContent = txt;
        el.classList.remove('cm-destello');
        void el.offsetWidth;
        el.classList.add('cm-destello');

        return true;
    }

    global.CMUtil = { money: money, escapar: escapar, fijarValor: fijarValor };

    /* ------------------------------------------------------------------
       Avisos antes de tiempo.

       `avisos.js` se carga al final. Si una vista llama a `avisar` antes
       —por ejemplo al validar algo nada más cargar— la llamada se guarda
       y se entrega cuando el sistema real esté listo, en vez de perderse
       o reventar.
       ------------------------------------------------------------------ */
    var pendientes = [];

    global.avisar = function (titulo, mensaje, severidad) {
        pendientes.push([titulo, mensaje, severidad]);
    };

    documento.addEventListener('DOMContentLoaded', function () {
        // Si avisos.js ya sustituyó esta función, no hay nada que hacer.
        if (pendientes.length === 0) { return; }

        var real = global.CMAviso;
        if (!real) { pendientes = []; return; }

        pendientes.forEach(function (a) {
            real.mostrar(a[2] || 'info', a[0], a[1]);
        });

        pendientes = [];
    });

})(window, document);

/* =====================================================================
   Manejadores que antes iban en el marcado
   ---------------------------------------------------------------------
   `onclick=`, `onchange=` y compañía son código en línea, y la política
   de seguridad no los ejecuta. Se enganchan aquí por atributo de datos,
   que es lo mismo pero sin violar la política.
   ===================================================================== */
(function (documento) {
    'use strict';

    documento.addEventListener('change', function (ev) {
        var s = ev.target.closest('[data-cm-ir]');

        if (s && s.value) {
            /* El selector de «por pagina» de la barra de paginacion
               entra por aqui. Con la navegacion en vivo puesta,
               cambiar de 25 a 50 filas no tiene por que recargar la
               pantalla entera: se trae y se sustituye el contenido,
               igual que al pasar de pagina. */
            if (window.CMVista) { window.CMVista.ir(s.value); }
            else { window.location.href = s.value; }
        }
    });

    documento.addEventListener('click', function (ev) {
        if (ev.target.closest('[data-cm-imprimir]')) {
            ev.preventDefault();
            window.print();
            return;
        }

        if (ev.target.closest('[data-cm-atras]')) {
            ev.preventDefault();
            window.history.back();
        }
    });

})(document);
