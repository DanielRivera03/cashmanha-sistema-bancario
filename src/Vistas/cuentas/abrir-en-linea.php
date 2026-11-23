<?php
/**
 * Abrir la Cuenta Digital desde la banca en línea.
 *
 * Una sola decisión para el cliente: con qué marca quiere su tarjeta.
 * Todo lo demás —el número de cuenta, la emisión de la tarjeta, el
 * contrato— pasa solo. Pedirle más datos aquí sería pedirle otra vez
 * lo que ya dio al registrarse.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var string $csrf
 * @var array|null $producto
 * @var bool $yaLaTiene
 * @var bool $faltaDocumento
 * @var array<string,string> $marcas
 * @var list<string> $ventajas
 * @var \CashMan\Dominio\Comun\Monto $topeSaldo
 * @var \CashMan\Dominio\Comun\Monto $topeDiario
 */
use CashMan\Http\Plantilla as P;
?>

<div class="cm-apertura">

    <?php if ($producto === null): ?>
    <div class="alert alert-warning">
      La apertura en línea no está disponible en este momento.
      Puede abrir su cuenta en cualquiera de nuestras sucursales.
    </div>

    <?php elseif ($yaLaTiene): ?>
    <div class="card">
      <div class="card-body text-center py-5">
        <h4>Ya tiene su Cuenta Digital</h4>
        <p class="text-muted mb-4">
          Solo se permite una por persona. Para abrir otra cuenta, acérquese a
          cualquier sucursal con su documento.
        </p>
        <a href="<?= P::attr($rutas->url('cuentas')) ?>" class="btn btn-primary">
          Ver mis cuentas
        </a>
      </div>
    </div>

    <?php else: ?>
    <?php /* La bienvenida, antes que el formulario.
             Abrir una cuenta es de las pocas cosas que alguien hace
             una vez en la vida con un banco, y merece que la pantalla
             lo trate como lo que es y no como un trámite más. */ ?>
    <header class="cm-apertura__cab">
      <div class="cm-apertura__brillo" aria-hidden="true"></div>

      <span class="cm-apertura__chip">Apertura en línea</span>

      <h4><?= P::e((string) $producto['nombre']) ?></h4>

      <p><?= P::e((string) $producto['descripcion']) ?></p>

      <?php /* La tarjeta, dibujada. Es lo que se lleva, y enseñarla
               explica mejor que un renglón que diga «incluye tarjeta
               de débito virtual». */ ?>
      <div class="cm-apertura__plastico" aria-hidden="true">
        <span class="cm-apertura__chipdorado"></span>
        <span class="cm-apertura__pan">•••• •••• •••• ••••</span>
        <span class="cm-apertura__pie">
          <small>SU NOMBRE AQUÍ</small>
          <b id="marca-plastico">VISA</b>
        </span>
      </div>
    </header>

    <div class="cm-apertura__cuerpo">

      <?php if ($ventajas !== []): ?>
      <ul class="cm-apertura__ventajas">
        <?php foreach ($ventajas as $i => $v): ?>
        <li style="--retraso: <?= (int) $i * 60 ?>ms">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
               stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M20 6L9 17l-5-5"/>
          </svg>
          <?= P::e($v) ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

        <?php /* Los topes, ANTES de abrirla y no en la letra pequeña del
                 contrato. Es lo único que esta cuenta tiene peor que una
                 de ahorro normal, y quien la abre tiene que saberlo para
                 no llevarse el chasco el día que le rebote un pago. */ ?>
        <?php /* Los topes, ANTES de abrirla y no en la letra pequeña
                 del contrato. Es lo único que esta cuenta tiene peor
                 que una de ahorro normal, y quien la abre tiene que
                 saberlo para no llevarse el chasco el día que le
                 rebote un pago. */ ?>
        <div class="cm-apertura__topes">
          <div class="cm-apertura__tope">
            <small>Tope de saldo</small>
            <b><?= P::e($topeSaldo->formateado()) ?></b>
          </div>
          <div class="cm-apertura__tope">
            <small>Tope de movimiento al día</small>
            <b><?= P::e($topeDiario->formateado()) ?></b>
          </div>
          <p>
            Por abrirse sin presencia física esta cuenta lleva esos dos topes.
            Si se le queda corta, en cualquier sucursal la convierten en una
            cuenta de ahorro sin ellos, conservando el mismo número.
          </p>
        </div>

        <?php if ($faltaDocumento): ?>
        <div class="alert alert-warning">
          <strong>Falta su documento de identidad.</strong>
          Es el único dato que no podemos dejar en blanco para abrirle una cuenta.
          <a href="<?= P::attr($rutas->url('perfil')) ?>" class="ml-1">Completarlo ahora</a>
        </div>
        <?php else: ?>

        <form id="form-apertura" method="post"
              action="<?= P::attr($rutas->url('abrir-cuenta')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="apertura">

          <fieldset class="cm-apertura__marcas">
            <legend>¿Con qué marca quiere su tarjeta?</legend>

            <div class="cm-marcas" role="radiogroup" aria-label="Marca de la tarjeta">
              <?php foreach ($marcas as $clave => $nombre): $i = 0; ?>
              <label class="cm-marca-op">
                <input type="radio" name="marca" value="<?= P::attr((string) $clave) ?>"
                       <?= $clave === array_key_first($marcas) ? 'checked' : '' ?>>
                <span class="cm-marca-op__caja">
                  <span class="cm-marca-op__nombre"><?= P::e($nombre) ?></span>
                  <span class="cm-marca-op__tic" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none"
                         stroke="currentColor" stroke-width="3.2" stroke-linecap="round">
                      <path d="M20 6L9 17l-5-5"/>
                    </svg>
                  </span>
                </span>
              </label>
              <?php endforeach; ?>
            </div>

            <small class="form-text text-muted">
              Se emite virtual y al instante: sirve para comprar por internet desde
              hoy mismo. No hay plástico que esperar.
            </small>
          </fieldset>

          <div id="aviso-apertura" class="d-none"></div>

          <button type="submit" class="btn btn-primary btn-lg btn-block" id="btn-abrir">
            Abrir mi cuenta
          </button>

          <small class="d-block text-muted text-center mt-3">
            Al abrirla acepta el contrato, que le enviaremos por correo y quedará
            en «Mis documentos».
          </small>
        </form>
        <?php endif; ?>

    </div>
    <?php endif; ?>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';
  var f = document.getElementById('form-apertura');
  if (!f) { return; }

  var btn   = document.getElementById('btn-abrir');
  var aviso = document.getElementById('aviso-apertura');

  /* El dibujo de la tarjeta sigue a la marca elegida. Es un detalle,
     pero es el que hace que la elección se sienta una elección y no
     un campo más del formulario. */
  var plastico = document.getElementById('marca-plastico');

  f.querySelectorAll('input[name="marca"]').forEach(function (r) {
    r.addEventListener('change', function () {
      if (!plastico) { return; }

      var etiqueta = r.parentNode.querySelector('.cm-marca-op__nombre');

      plastico.textContent = (etiqueta ? etiqueta.textContent : r.value).toUpperCase();
    });
  });

  f.addEventListener('submit', function (ev) {
    ev.preventDefault();

    /* Se desactiva al primer clic y no se vuelve a activar si salió
       bien: abrir dos cuentas por pulsar dos veces es el error que
       nadie perdona. */
    btn.disabled = true;
    btn.textContent = 'Abriendo su cuenta…';
    aviso.className = 'd-none';

    /* Abrir una cuenta son varias cosas encadenadas —la cuenta, la
       tarjeta virtual, el contrato— y tarda lo suyo. Los pasos lo
       dicen, para que nadie piense que se trabó y apriete otra vez. */
    var pr = CMProceso.abrir('apertura');
    pr.avanzar();

    fetch(f.action, {
      method: 'POST',
      body: new FormData(f),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.ok) {
          pr.listo(j.mensaje || 'Su cuenta quedó abierta.');

          setTimeout(function () {
            window.location = (j.datos && j.datos.redirigir) || <?= P::js($rutas->url('cuentas')) ?>;
          }, 1800);
          return;
        }

        pr.fallo(j.mensaje || 'No se pudo abrir la cuenta.', 'creando');

        btn.disabled = false;
        btn.textContent = 'Abrir mi cuenta';
        aviso.className = 'alert alert-warning';
        aviso.textContent = j.mensaje || 'No se pudo abrir la cuenta.';
      })
      .catch(function () {
        /* Que no llegue la respuesta NO quiere decir que no se haya
           abierto: la petición pudo entrar y perderse la vuelta. Por
           eso se manda a mirar antes de reintentar, no a reintentar. */
        pr.fallo('No pudimos comunicarnos con el servidor. Revise en «Mis cuentas» '
               + 'antes de volver a intentarlo.');

        btn.disabled = false;
        btn.textContent = 'Abrir mi cuenta';
        aviso.className = 'alert alert-danger';
        aviso.textContent = 'No pudimos comunicarnos con el servidor. '
          + 'Revise en «Mis cuentas» antes de volver a intentarlo.';
      });
  });
})();
</script>
