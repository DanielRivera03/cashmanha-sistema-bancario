<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $estado
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$activo = (bool) $estado['activo'];
?>
<div class="row justify-content-center">
  <div class="col-lg-8">

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Verificación en dos pasos</h4>
        <span class="badge badge-<?= $activo ? 'success' : 'secondary' ?>" id="estado-2fa">
          <?= $activo ? 'Activa' : 'Inactiva' ?>
        </span>
      </div>

      <div class="card-body">
        <?php if (!$activo): ?>

        <p class="text-muted" style="font-size:.92rem">
          Con la verificación en dos pasos, entrar a su cuenta requiere su contraseña
          <strong>y</strong> un código que genera su teléfono. Aunque alguien
          averigüe su contraseña, no podrá entrar.
        </p>
        <p class="text-muted" style="font-size:.88rem">
          Necesitas una app autenticadora: Google Authenticator, Microsoft
          Authenticator, Authy o 1Password, entre otras.
        </p>

        <!-- Paso 1 -->
        <div id="paso-1">
          <button class="btn btn-primary" id="btn-iniciar">
            <?= cm_icono('shield', 16, 'mr-1') ?> Activar verificación en dos pasos
          </button>
        </div>

        <!-- Paso 2: escanear -->
        <div id="paso-2" class="d-none">
          <hr>
          <div class="row">
            <div class="col-md-5 text-center">
              <div id="qr" style="min-height:200px;display:flex;align-items:center;justify-content:center"></div>
              <small class="text-muted d-block mt-2">Escanea con su aplicación</small>
            </div>
            <div class="col-md-7">
              <p style="font-size:.9rem" class="mb-2">
                <strong>1.</strong> Escanea el código con su aplicación autenticadora.
              </p>
              <p style="font-size:.88rem" class="text-muted mb-2">
                ¿Sin cámara? Escriba esta clave a mano:
              </p>
              <div class="alert alert-light border cm-mono text-center mb-3"
                   id="secreto-texto" style="letter-spacing:.08em;font-size:.95rem"></div>

              <p style="font-size:.9rem" class="mb-2">
                <strong>2.</strong> Escriba el código de 6 dígitos que aparece:
              </p>
              <div id="aviso-2fa" class="alert d-none" role="status"
                   style="font-size:.9rem"></div>

              <div class="form-group">
                <input type="text" class="form-control text-center" id="codigo-confirmar"
                       maxlength="6" inputmode="numeric" placeholder="000000"
                       style="font-size:1.5rem;letter-spacing:.5em;font-family:Consolas,monospace">
                <small class="form-text text-muted">
                  El código cambia cada 30 segundos
                  (<span id="contador">--</span> s para el siguiente).
                </small>
              </div>
              <button class="btn btn-success btn-block" id="btn-confirmar">
                Confirmar y activar
              </button>
            </div>
          </div>
        </div>

        <?php else: ?>

        <div class="alert alert-success d-flex align-items-center">
          <?= cm_icono('check', 22, 'mr-3') ?>
          <div>
            <strong>Su cuenta está protegida.</strong><br>
            <small>
              Activada el <?= P::e(P::fecha((string) $estado['confirmado_en'], 'd/m/Y')) ?>.
              Al entrar se te pedirá el código de su aplicación.
            </small>
          </div>
        </div>

        <div class="d-flex justify-content-between align-items-center p-3 border rounded mb-3">
          <div>
            <strong style="font-size:.92rem">Códigos de respaldo</strong><br>
            <small class="text-muted">
              Te quedan <strong><?= (int) $estado['respaldo_disponibles'] ?></strong> sin usar.
              Sirven para entrar si pierdes el teléfono.
            </small>
          </div>
          <div class="d-flex align-items-center" style="gap:.4rem">
            <input type="text" class="form-control form-control-sm text-center"
                   id="codigo-regenerar" maxlength="6" inputmode="numeric"
                   placeholder="000000" style="width:6.5rem;letter-spacing:.14em"
                   aria-label="Código de su aplicación">
            <button class="btn btn-sm btn-outline-primary" id="btn-regenerar">Regenerar</button>
          </div>
        </div>

        <?php if ((int) $estado['respaldo_disponibles'] <= 2): ?>
        <div class="alert alert-warning" style="font-size:.88rem">
          Te quedan pocos códigos de respaldo. Conviene regenerarlos.
        </div>
        <?php endif; ?>

        <hr>
        <h6 class="text-muted" style="font-size:.78rem;letter-spacing:.05em">DESACTIVAR</h6>
        <p class="text-muted" style="font-size:.86rem">
          Su cuenta quedará protegida solo por la contraseña.
        </p>
        <div class="form-row align-items-end">
          <div class="form-group col-md-5">
            <label class="small">Código actual de su aplicación</label>
            <input type="text" class="form-control form-control-sm text-center" id="codigo-baja"
                   maxlength="6" inputmode="numeric" placeholder="000000">
          </div>
          <div class="form-group col-md-4">
            <button class="btn btn-sm btn-outline-danger btn-block" id="btn-desactivar">
              Desactivar
            </button>
          </div>
        </div>

        <?php endif; ?>
      </div>
    </div>

    <!-- Códigos de respaldo -->
    <div class="card d-none" id="caja-respaldo">
      <div class="card-header"><h4 class="card-title mb-0">Códigos de respaldo</h4></div>
      <div class="card-body">
        <div class="alert alert-warning">
          <strong>Guárdalos ahora.</strong> No se volverán a mostrar.
          Cada uno sirve una sola vez para entrar si pierdes el teléfono.
        </div>
        <div class="row" id="lista-respaldo"></div>
        <div class="mt-3 d-flex flex-wrap" style="gap:.5rem">
          <button class="btn btn-sm btn-primary" id="btn-descargar">
            <?= cm_icono('download', 15, 'mr-1') ?> Descargar
          </button>
          <button class="btn btn-sm btn-outline-secondary" id="btn-copiar">
            Copiar al portapapeles
          </button>
          <button class="btn btn-sm btn-outline-secondary" id="btn-imprimir">
            Imprimir
          </button>
        </div>
        <p class="text-muted mt-2 mb-0" style="font-size:.82rem">
          El archivo lleva su nombre, la fecha y las instrucciones de uso.
          Guárdelo fuera del teléfono que genera los códigos.
        </p>
      </div>
    </div>

  </div>
</div>

<?php /* Con su version en la direccion, como el resto de los
         guiones del sistema. Sin esto el navegador sirve el que
         tenga guardado, y una correccion en el generador del QR no
         llega a quien ya habia abierto la pantalla. */ ?>
<?php $qrv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/qr.js') ?: time(); ?>
<script src="<?= P::attr($rutas->url('assets/js/qr.js') . '?v=' . $qrv) ?>"></script>
<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';
  /* El token de seguridad se lee vivo, no congelado.

     Aquí había una copia del token tomada al
     dibujar la página. Como el token es de un solo uso, la primera
     acción lo gastaba y la segunda fallaba siempre con «el token de
     seguridad venció»: pedir el código llegaba, pulsar «Ver datos» no.

     El servidor manda el reemplazo en cada respuesta y `cm.js` lo
     guarda en `window.CM_CSRF`. Leer de ahí vale para toda la vida de
     la pantalla, por muchas acciones que se encadenen. */
  /* EL TOKEN DE ESTA PANTALLA, aparte del global.
   *
   * Antes se usaba `window.CM_CSRF = window.CM_CSRF || <el nuestro>`.
   * Ese `||` conserva el que ya hubiera, y con la navegacion en vivo
   * —que no recarga la pagina— lo que hay suele ser el token de otra
   * pantalla, emitido para OTRO proposito. El servidor valida token y
   * proposito juntos, asi que lo rechazaba y la activacion no pasaba
   * de la primera pulsacion.
   *
   * Con una variable propia, esta pantalla usa siempre el suyo y no
   * le pisa el token a nadie. */
  /* Ninguna excepcion se pierde.
   *
   * Un error de JavaScript corta el manejador por la mitad y deja
   * la pantalla muda: el usuario pulsa y no pasa nada, sin pista de
   * por que. Aqui se recoge y se ensena, que es lo minimo que se le
   * debe a quien esta esperando. */
  window.addEventListener('error', function (ev) {
    var caja = document.getElementById('aviso-2fa');

    if (!caja) { return; }

    caja.className = 'alert alert-danger';
    caja.textContent = 'Fallo en la pantalla: ' + (ev.message || ev.type)
      + (ev.lineno ? ' (linea ' + ev.lineno + ')' : '');
  });

  window.addEventListener('unhandledrejection', function (ev) {
    var caja = document.getElementById('aviso-2fa');

    if (!caja) { return; }

    caja.className = 'alert alert-danger';
    caja.textContent = 'La peticion fallo: '
      + ((ev.reason && ev.reason.message) || String(ev.reason));
  });

  var CSRF = <?= P::js($csrf) ?>;

  /* Para la hoja de respaldo: de quien es, con nombre y usuario. Una
     lista de codigos sin dueno no se puede archivar ni auditar. */
  var TITULAR = <?= P::js(trim(($usuario['nombres'] ?? '') . ' ' . ($usuario['apellidos'] ?? ''))) ?>;
  var USUARIO = <?= P::js($usuario['usuario'] ?? '') ?>;
  var BASE = <?= P::js($rutas->url('seguridad/totp/')) ?>;

  /** Para que un fallo se vea, aunque `avisos.js` no esté cargado. */
  /* Escapar, sin depender de nadie.
   *
   * Aqui se usaba `window.CMUtil.escapar`, que vive en `app.js`, y
   * `app.js` NO se carga en el panel. La llamada reventaba con
   * «Cannot read properties of undefined» justo despues de activar:
   * el segundo factor quedaba activo en el servidor pero la pantalla
   * se quedaba a medias, sin los codigos de respaldo y sin cambiar
   * el estado. Desde fuera parecia que no habia pasado nada. */
  function escapar(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function decir(titulo, texto, tono) {
    /* SIEMPRE en la tarjeta, ademas del aviso flotante.
     *
     * El aviso flotante vive fuera de esta tarjeta y puede no
     * aparecer: si `avisos.js` no cargo, si otro elemento lo tapa,
     * si se cierra solo antes de que nadie lo lea. Entonces el
     * usuario pulsa un boton y no ve absolutamente nada, que es lo
     * peor que puede hacer una pantalla. Aqui queda escrito, en el
     * sitio donde la persona esta mirando. */
    var caja = document.getElementById('aviso-2fa');

    if (caja) {
      caja.className = 'alert alert-' + (
        tono === 'exito' ? 'success'
        : tono === 'critico' ? 'danger'
        : tono === 'alerta' ? 'warning' : 'info'
      );
      caja.textContent = texto;
    }

    if (typeof window.avisar === 'function') {
      /* `window.avisar`, no `decir`: llamarse a si misma colgaba el
         navegador con «Maximum call stack size exceeded» al primer
         aviso, y como la excepcion mataba el manejador, pulsar el
         boton no producia absolutamente nada. */
      window.avisar(titulo, texto, tono);
      return;
    }

    /* Sin el sistema de avisos no se puede callar: el usuario está
       esperando a que pase algo. */
    if (window.console && console.error) { console.error('[2FA] ' + texto); }

    window.alert(texto);
  }

  function post(accion, datos) {
    var fd = new FormData();
    fd.append('_csrf', CSRF);
    fd.append('_csrf_proposito', 'seguridad');
    Object.keys(datos || {}).forEach(function (k) { fd.append(k, datos[k]); });

    return fetch(BASE + accion, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) {
        return r.text().then(function (texto) {
          /* Una respuesta que no es JSON no puede pasar en silencio.
           *
           * Aquí se hacía `r.json()` a secas. Si el servidor devolvía
           * otra cosa —su página de error, por ejemplo— la promesa se
           * rompía, nadie la recogía, y en la pantalla NO OCURRÍA
           * ABSOLUTAMENTE NADA: el botón volvía a su sitio y la
           * verificación seguía inactiva sin una palabra de por qué. */
          try {
            return JSON.parse(texto);
          } catch (e) {
            if (window.console && console.error) {
              console.error('[2FA] respuesta no JSON de ' + accion
                + ' (HTTP ' + r.status + '):', texto.slice(0, 800));
            }

            return {
              ok: false,
              mensaje: 'El servidor respondió algo que no se pudo interpretar '
                + '(HTTP ' + r.status + '). Vuelva a intentarlo; si sigue, '
                + 'recargue la página.'
            };
          }
        });
      })
      .then(function (j) {
        /* EL TOKEN NUEVO SE GUARDA.
         *
         * El token de seguridad es de un solo uso y el servidor manda
         * uno nuevo en cada respuesta. Esta pantalla encadena dos
         * acciones —«Activar» y luego «Confirmar»— y no lo estaba
         * guardando: la segunda salía con el token que la primera ya
         * había gastado, el servidor la rechazaba con «el token de
         * seguridad venció» y, como el error tampoco se mostraba, el
         * botón de confirmar no hacía nada y el segundo factor se
         * quedaba inactivo para siempre.
         *
         * El comentario de arriba decía que de esto se encarga
         * `cm.js`, y es cierto para los formularios que pasan por él.
         * Estas llamadas van por su cuenta, así que les toca a ellas. */
        if (j && j.csrf) { CSRF = j.csrf; }

        return j;
      });
  }

  // ---------- Activar ----------
  var btnIni = document.getElementById('btn-iniciar');
  if (btnIni) {
    btnIni.addEventListener('click', function () {
      btnIni.disabled = true;
      post('iniciar', {}).then(function (j) {
        btnIni.disabled = false;
        if (!j.ok) { decir('Error', j.mensaje, 'critico'); return; }

        document.getElementById('secreto-texto').textContent = j.datos.legible;

        // El QR se dibuja en el navegador: el secreto nunca sale
        // hacia un servicio externo de generación de imágenes.
        if (window.CMQr) {
          document.getElementById('qr').innerHTML = window.CMQr.svg(j.datos.uri, 196);
        } else {
          document.getElementById('qr').innerHTML =
            '<small class="text-muted">Usa la clave de texto</small>';
        }

        document.getElementById('paso-1').classList.add('d-none');
        document.getElementById('paso-2').classList.remove('d-none');
        document.getElementById('codigo-confirmar').focus();
        iniciarContador();
      })
        .catch(function (e) {
          if (window.console && console.error) { console.error("[2FA]", e); }
          decir("Sin respuesta", "No se pudo contactar al servidor. "
            + "Revise su conexion y vuelva a intentarlo.", "critico");
        });
    });
  }

  var btnConf = document.getElementById('btn-confirmar');
  if (btnConf) {
    btnConf.addEventListener('click', function () {
      var c = document.getElementById('codigo-confirmar').value.trim();
      if (c.length !== 6) { decir('Atención', 'Indique los 6 dígitos.', 'alerta'); return; }

      btnConf.disabled = true;
      post('confirmar', { codigo: c }).then(function (j) {
        btnConf.disabled = false;
        if (!j.ok) { decir('Error', j.mensaje, 'critico'); return; }
        decir('Activado', j.mensaje, 'exito');

        /* El estado, al dia sin recargar: era lo que el usuario
           miraba para saber si habia funcionado. */
        var insignia = document.getElementById('estado-2fa');

        if (insignia) {
          insignia.textContent = 'Activa';
          insignia.className = 'badge badge-success';
        }
        mostrarRespaldo(j.datos.codigos_respaldo);
        document.getElementById('paso-2').classList.add('d-none');
      })
        .catch(function (e) {
          if (window.console && console.error) { console.error("[2FA]", e); }
          decir("Sin respuesta", "No se pudo contactar al servidor. "
            + "Revise su conexion y vuelva a intentarlo.", "critico");
        });
    });
  }

  // ---------- Desactivar ----------
  var btnOff = document.getElementById('btn-desactivar');
  if (btnOff) {
    btnOff.addEventListener('click', function () {
      var c = document.getElementById('codigo-baja').value.trim();
      if (!c) { decir('Atención', 'Ingrese el código de su aplicación.', 'alerta'); return; }

      window.CMAviso.confirmar({
        titulo:  'Desactivar la verificación en dos pasos',
        mensaje: 'Su cuenta quedará protegida solo por la contraseña. '
               + 'Cualquiera que la averigüe podrá entrar.',
        confirmar: 'Sí, desactivar',
        peligro: true
      }).then(function (ok) {
        if (!ok) { return; }

        var espera = window.CMAviso.cargando('Desactivando…');

        post('desactivar', { codigo: c }).then(function (j) {
          espera.cerrar();
          decir(j.ok ? 'Desactivado' : 'No se pudo desactivar',
                        j.mensaje, j.ok ? 'alerta' : 'critico');
          if (j.ok) setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1200);
        }).catch(function () {
          espera.cerrar();
          decir('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
        })
          .catch(function (e) {
            if (window.console && console.error) { console.error("[2FA]", e); }
            decir("Sin respuesta", "No se pudo contactar al servidor. "
              + "Revise su conexion y vuelva a intentarlo.", "critico");
          });
      });
    });
  }

  // ---------- Regenerar respaldo ----------
  var btnReg = document.getElementById('btn-regenerar');
  if (btnReg) {
    btnReg.addEventListener('click', function () {
      var c = document.getElementById('codigo-regenerar').value.trim();

      if (c.length !== 6) {
        decir('Falta el código',
          'Escriba el código de 6 dígitos de su aplicación para regenerar los de respaldo.', 'alerta');
        document.getElementById('codigo-regenerar').focus();
        return;
      }

      window.CMAviso.confirmar({
        titulo:  'Regenerar los códigos de respaldo',
        mensaje: 'Los códigos que tengas guardados dejarán de funcionar y se '
               + 'sustituyen por otros nuevos.',
        confirmar: 'Regenerar'
      }).then(function (ok) {
        if (!ok) { return; }

        var espera = window.CMAviso.cargando('Generando códigos…');

        post('respaldo', { codigo: c }).then(function (j) {
          espera.cerrar();
          if (!j.ok) { decir('No se pudo regenerar', j.mensaje, 'critico'); return; }
          decir('Códigos regenerados', j.mensaje, 'exito');
          mostrarRespaldo(j.datos.codigos_respaldo);
        }).catch(function () {
          espera.cerrar();
          decir('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
        })
          .catch(function (e) {
            if (window.console && console.error) { console.error("[2FA]", e); }
            decir("Sin respuesta", "No se pudo contactar al servidor. "
              + "Revise su conexion y vuelva a intentarlo.", "critico");
          });
      });
    });
  }

  function mostrarRespaldo(codigos) {
    var caja = document.getElementById('caja-respaldo');
    var lista = document.getElementById('lista-respaldo');
    lista.innerHTML = '';

    codigos.forEach(function (c) {
      var col = document.createElement('div');
      col.className = 'col-md-3 col-6 mb-2';
      col.innerHTML = '<div class="alert alert-light border mb-0 text-center cm-mono py-2">' +
                      escapar(c) + '</div>';
      lista.appendChild(col);
    });

    caja.classList.remove('d-none');
    caja.scrollIntoView({ behavior: 'smooth', block: 'center' });

    var btnCopiar = document.getElementById('btn-copiar');
    btnCopiar.onclick = function () {
      /* El portapapeles falla en silencio cuando el navegador no lo
         concede: hay que decirlo, o el usuario cree que copio. */
      if (!navigator.clipboard) {
        decir('No se pudo copiar',
              'Su navegador no permite copiar desde aqui. Use Descargar.', 'alerta');
        return;
      }

      navigator.clipboard.writeText(codigos.join('\n')).then(function () {
        decir('Copiado', 'Códigos copiados al portapapeles.', 'exito');
      }).catch(function () {
        decir('No se pudo copiar',
              'El navegador bloqueo el portapapeles. Use Descargar.', 'alerta');
      });
    };

    document.getElementById('btn-descargar').onclick = function () {
      descargarRespaldo(codigos);
    };

    document.getElementById('btn-imprimir').onclick = function () {
      imprimirRespaldo(codigos);
    };
  }

  /* ------------------------------------------------------------------
     La hoja de respaldo
     ------------------------------------------------------------------
     Una lista de codigos sueltos no se entiende dentro de dos anos. El
     archivo lleva de quien es, cuando se genero, como se usan y que
     hacer al quedarse sin ellos, porque es justo entonces —perdido el
     telefono, con prisa— cuando se lee.

     Sin acentos a proposito: esta hoja se imprime, se teclea a mano y
     puede acabar en un Bloc de notas con otra codificacion.
     ------------------------------------------------------------------ */
  function hojaDeRespaldo(codigos) {
    var ahora = new Date();

    function dos(n) { return (n < 10 ? '0' : '') + n; }

    var fecha = dos(ahora.getDate()) + '/' + dos(ahora.getMonth() + 1) + '/'
              + ahora.getFullYear() + '  ' + dos(ahora.getHours())
              + ':' + dos(ahora.getMinutes());

    var raya = new Array(69).join('=');
    var fina = new Array(69).join('-');

    var l = [];

    l.push('CASHMAN H.A.');
    l.push('CODIGOS DE RESPALDO - VERIFICACION EN DOS PASOS');
    l.push(raya);
    l.push('');
    l.push('  Titular      ' + (TITULAR || '(sin nombre)'));
    l.push('  Usuario      ' + (USUARIO || '(sin usuario)'));
    l.push('  Generados    ' + fecha);
    l.push('  Cantidad     ' + codigos.length + ' codigos, de un solo uso cada uno');
    l.push('');
    l.push(fina);
    l.push('');

    /* De dos en dos, con una casilla delante para tachar el usado. */
    for (var i = 0; i < codigos.length; i += 2) {
      var izq = codigos[i];
      var der = codigos[i + 1];

      var linea = '      [ ]  ' + izq;

      if (der) {
        linea += new Array(Math.max(2, 22 - izq.length)).join(' ') + '[ ]  ' + der;
      }

      l.push(linea);
    }

    l.push('');
    l.push(fina);
    l.push('');
    l.push('COMO SE USAN');
    l.push('');
    l.push('  Cada codigo sirve UNA SOLA VEZ. Si pierde el telefono, o la');
    l.push('  aplicacion autenticadora deja de darle codigos, escriba uno de');
    l.push('  estos donde se le pida el codigo de seis digitos al entrar.');
    l.push('');
    l.push('  Al usarlo queda gastado para siempre. Marque su casilla.');
    l.push('');
    l.push('DONDE GUARDARLOS');
    l.push('');
    l.push('  Impresos y bajo llave, o en un gestor de contrasenas. No los');
    l.push('  deje en el mismo telefono que genera los codigos: si lo pierde,');
    l.push('  pierde las dos cosas a la vez y se queda fuera de su cuenta.');
    l.push('');
    l.push('  Quien tenga esta hoja y su contrasena puede entrar a su cuenta.');
    l.push('  Tratela como trataria una llave.');
    l.push('');
    l.push('SI SE QUEDA SIN CODIGOS');
    l.push('');
    l.push('  Entre a Seguridad, Verificacion en dos pasos, y pulse Regenerar.');
    l.push('  Los de esta hoja dejaran de servir en ese mismo momento, asi que');
    l.push('  destruyala cuando genere unos nuevos.');
    l.push('');
    l.push(raya);
    l.push('CashMan H.A. no guarda copia de estos codigos ni puede recuperarlos.');
    l.push('');

    return l.join('\r\n');
  }

  /* El nombre del archivo: identificable sin abrirlo, y ordenable por
     fecha si llega a haber varios. */
  function nombreDeHoja() {
    var a = new Date();

    function dos(n) { return (n < 10 ? '0' : '') + n; }

    var limpio = String(USUARIO || 'usuario').replace(/[^a-zA-Z0-9._-]/g, '-');

    return 'cashmanha-codigos-respaldo-' + limpio + '-'
         + a.getFullYear() + dos(a.getMonth() + 1) + dos(a.getDate()) + '.txt';
  }

  function descargarRespaldo(codigos) {
    try {
      /* El BOM para que el Bloc de notas de Windows no destroce nada si
         alguna vez entra un caracter fuera del ASCII. */
      var blob = new Blob(['\uFEFF' + hojaDeRespaldo(codigos)],
                          { type: 'text/plain;charset=utf-8' });

      var url = URL.createObjectURL(blob);
      var a   = document.createElement('a');

      a.href = url;
      a.download = nombreDeHoja();
      a.style.display = 'none';

      document.body.appendChild(a);
      a.click();

      /* Sin esto el blob se queda en memoria hasta cerrar la pestana. */
      setTimeout(function () {
        URL.revokeObjectURL(url);
        if (a.parentNode) { a.parentNode.removeChild(a); }
      }, 1000);

      decir('Descargado',
            'Guarde el archivo fuera del telefono que genera los codigos.',
            'exito');
    } catch (e) {
      decir('No se pudo descargar',
            'Copie los codigos al portapapeles y guardelos a mano.', 'critico');
    }
  }

  /* Imprimir es lo que de verdad conviene: papel bajo llave. Se abre
     una ventana con la hoja ya compuesta, en vez de imprimir el panel
     entero con su menu y su barra. */
  function imprimirRespaldo(codigos) {
    var v = window.open('', '_blank', 'width=720,height=860');

    if (!v) {
      decir('El navegador bloqueo la ventana',
            'Permita las ventanas emergentes de este sitio, o use Descargar.',
            'alerta');
      return;
    }

    var doc = v.document;

    doc.open();
    doc.write('<!doctype html><html lang="es"><head><meta charset="utf-8">'
            + '<title>Codigos de respaldo</title></head>'
            + '<body style="margin:2.5cm 2cm"></body></html>');
    doc.close();

    /* Con createTextNode en vez de innerHTML: el contenido se trata
       como texto y no como marcado, pase lo que pase. */
    var pre = doc.createElement('pre');

    pre.style.font = '13px/1.45 ui-monospace, Consolas, monospace';
    pre.style.whiteSpace = 'pre-wrap';
    pre.appendChild(doc.createTextNode(hojaDeRespaldo(codigos)));

    doc.body.appendChild(pre);

    v.focus();
    v.print();
  }

  // ---------- Contador del periodo ----------
  function iniciarContador() {
    var el = document.getElementById('contador');
    if (!el) { return; }
    setInterval(function () {
      el.textContent = 30 - (Math.floor(Date.now() / 1000) % 30);
    }, 1000);
  }
})();
</script>
