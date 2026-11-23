<?php
/**
 * Crear una cuenta de usuario.
 *
 * El sistema anterior no tenía esta pantalla: al banco solo se entraba
 * si un empleado te daba de alta. Es el primer paso de un camino que
 * sigue en «abrir mi Cuenta Digital».
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var string $csrf
 * @var bool   $abierto
 * @var int    $edad
 * @var array|null $producto
 * @var list<string> $ventajas
 */
use CashMan\Http\Plantilla as P;
?>

<h4 class="text-center mb-1">CashMan H.A. | Crear mi cuenta</h4>
<p class="cm-sub mb-3">Unos datos y ya puede abrir su cuenta en línea.</p>

<?php if (!$abierto): ?>
<div class="cm-aviso cm-aviso--error" role="alert">
  <span>
    El registro en línea está cerrado en este momento. Puede abrir su cuenta
    en cualquiera de nuestras sucursales.
  </span>
</div>
<?php else: ?>

<div class="cm-registro">

  <?php /* El costado. Lo que se lleva, antes de pedirle nada: un
           formulario de nueve campos sin decir para qué es, se
           abandona. Antes iba encima y empujaba el formulario fuera de
           la pantalla; al lado dice lo mismo y no estorba. */ ?>
  <aside class="cm-registro__lado">
    <?php if ($ventajas !== []): ?>
    <h5 class="cm-registro__titulo">Su Cuenta Digital incluye</h5>

    <ul class="cm-ventajas">
      <?php foreach (array_slice($ventajas, 0, 4) as $v): ?>
      <li><?= P::e($v) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <p class="cm-registro__pie">
      Sus datos viajan cifrados y solo se usan para administrar los
      productos que contrate.
    </p>

    <p class="cm-registro__ya">
      ¿Ya es cliente?<br>
      <a href="<?= P::attr($rutas->url('entrar')) ?>">Entrar a mi banca</a>
    </p>
  </aside>

  <div class="cm-registro__form">

<div id="aviso" class="d-none" role="alert"></div>

<form id="form-registro" method="post"
      action="<?= P::attr($rutas->url('registro')) ?>" autocomplete="off" novalidate>
  <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
  <input type="hidden" name="_csrf_proposito" value="registro">

  <div class="form-row">
    <div class="form-group col-md-6">
      <label class="text-label" for="nombres">Nombres</label>
      <input type="text" class="form-control" id="nombres" name="nombres"
             maxlength="80" required autocomplete="given-name" autofocus>
    </div>
    <div class="form-group col-md-6">
      <label class="text-label" for="apellidos">Apellidos</label>
      <input type="text" class="form-control" id="apellidos" name="apellidos"
             maxlength="80" required autocomplete="family-name">
    </div>
  </div>

  <div class="form-group">
    <label class="text-label" for="correo">Correo electrónico</label>
    <input type="email" class="form-control" id="correo" name="correo"
           maxlength="190" required autocomplete="email">
    <small class="form-text text-muted">
      Aquí le llegarán su contrato y los avisos de sus movimientos.
    </small>
  </div>

  <?php /* Primero QUÉ documento es, y después el número con su máscara.
           Un campo de texto suelto admitía el mismo DUI con guion y sin
           guion, y un NIT indistinguible de los dos: la búsqueda de
           ventanilla fallaba según cómo lo hubiera tecleado quien
           registró al cliente. */ ?>
  <div class="form-row" data-cm-documento>
    <div class="form-group col-md-5">
      <label class="text-label" for="tipo_documento">Documento</label>
      <select class="form-control" id="tipo_documento" name="tipo_documento"
              data-cm-documento-tipo required>
        <option value="dui">DUI</option>
        <option value="nit">NIT</option>
        <option value="residente">Carné de residente</option>
      </select>
    </div>
    <div class="form-group col-md-7">
      <label class="text-label" for="documento">Número</label>
      <input type="text" class="form-control" id="documento" name="documento"
             inputmode="numeric" maxlength="10" required placeholder="01234567-8"
             data-cm-documento-valor autocomplete="off">
      <small class="form-text text-muted" data-cm-documento-nota></small>
    </div>
  </div>

  <?php /* Los dos en la misma fila: son cortos y juntos ahorran una
           franja entera de pantalla. */ ?>
  <div class="form-row">
    <div class="form-group col-md-5">
      <label class="text-label" for="telefono">Teléfono</label>
      <input type="tel" class="form-control" id="telefono" name="telefono"
             maxlength="20" autocomplete="tel" placeholder="0000-0000">
    </div>

    <div class="form-group col-md-7">
    <label class="text-label" for="nacimiento">Fecha de nacimiento</label>
    <?php /* Los dos topes se recalculan en cada carga, así que el límite
             se mueve solo: quien cumple los 18 hoy encuentra hoy su
             propia fecha disponible, sin que nadie toque nada.

             El `min` no está por desconfianza, está para que la lista de
             años del calendario tenga principio: sin él iba de hace
             noventa años a dentro de quince, y la mitad de esas opciones
             el formulario las iba a rechazar. */ ?>
    <input type="date" class="form-control" id="nacimiento" name="nacimiento"
           min="<?= P::attr(date('Y-m-d', strtotime('-110 years'))) ?>"
           max="<?= P::attr(date('Y-m-d', strtotime('-' . $edad . ' years'))) ?>">
    <small class="form-text text-muted">
      Al menos <?= (int) $edad ?> años cumplidos.
    </small>
    </div>
  </div>

  <div class="form-row">
    <div class="form-group col-md-6">
      <label class="text-label" for="password">Contraseña</label>
      <input type="password" class="form-control" id="password" name="password"
             required autocomplete="new-password">
    </div>
    <div class="form-group col-md-6">
      <label class="text-label" for="confirmacion">Repítala</label>
      <input type="password" class="form-control" id="confirmacion" name="confirmacion"
             required autocomplete="new-password">
    </div>
  </div>

  <div id="fallos" class="cm-aviso cm-aviso--error d-none" role="alert"></div>

  <button type="submit" class="btn btn-primary btn-block" id="btn-registro">
    Crear mi cuenta
  </button>

  <p class="cm-sub text-center mt-2 mb-0" style="font-size:.76rem">
    Al crear su cuenta acepta nuestras condiciones de uso.
  </p>
</form>

  </div><?php /* .cm-registro__form */ ?>
</div><?php /* .cm-registro */ ?>
<?php endif; ?>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';
  var f = document.getElementById('form-registro');
  if (!f) { return; }

  var caja   = document.getElementById('fallos');
  var btn    = document.getElementById('btn-registro');
  /* El documento lo lleva `documento.js`: pone los guiones del DUI o
     del NIT según el tipo elegido, y deja el carné de residente sin
     máscara porque no tiene un formato fijo. */

  f.addEventListener('submit', function (ev) {
    ev.preventDefault();
    caja.className = 'cm-aviso cm-aviso--error d-none';
    caja.textContent = '';
    btn.disabled = true;

    /* Los pasos. Crear el usuario pasa por el cifrado de la
       contraseña, que a propósito tarda: Argon2id está calibrado para
       que probar claves a la fuerza salga caro. Eso aquí se nota, y
       sin decir nada parece que se colgó. */
    var pr = CMProceso.abrir('registro');
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
          /* Se va a la pantalla de entrar con el mensaje. No se inicia
             sesión sola: que teclee su contraseña una vez de verdad
             confirma que la recuerda, y es la única oportunidad de
             descubrir ahora que la escribió mal. */
          pr.listo('Su usuario quedó creado.');

          window.location = j.datos && j.datos.redirigir
            ? j.datos.redirigir
            : <?= P::js($rutas->url('entrar')) ?>;
          return;
        }

        /* Los datos que faltan se enseñan en el formulario, junto a
           cada campo: ahí es donde hay que corregirlos. El panel solo
           dice que no se creó. */
        pr.fallo(j.mensaje || 'Revise los datos marcados.', 'creando');

        btn.disabled = false;

        var lista = (j.datos && j.datos.fallos) || [];

        if (lista.length > 0) {
          caja.innerHTML = '<ul class="mb-0 pl-3"></ul>';
          var ul = caja.querySelector('ul');

          lista.forEach(function (t) {
            var li = document.createElement('li');
            li.textContent = t;
            ul.appendChild(li);
          });
        } else {
          caja.textContent = j.mensaje || 'No se pudo crear la cuenta.';
        }

        caja.className = 'cm-aviso cm-aviso--error';
      })
      .catch(function () {
        btn.disabled = false;
        caja.textContent = 'No pudimos comunicarnos con el servidor.';
        caja.className = 'cm-aviso cm-aviso--error';
      });
  });
})();
</script>
