<?php
/**
 * Contacto.
 *
 * El formulario funciona sin JavaScript: envía por POST normal y el
 * controlador responde con una redirección que trae el aviso en la URL.
 * Con JavaScript disponible se envía por fetch y no se recarga.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var string $csrf
 * @var bool   $correoVivo  si el servicio de correo está configurado
 */
use CashMan\Http\Plantilla as P;

require __DIR__ . '/_graficos.php';

// El controlador redirige con ?ok=... o ?e=... según el resultado.
$exito = (string) ($_GET['ok'] ?? '');
$error = (string) ($_GET['e']  ?? '');
?>

<section class="sitio-hero">
  <?= P::crudo($escenario('costa')) ?>

  <div class="sitio-ancho">
    <span class="sitio-etiqueta"><?= P::crudo($ico($D['correo'], 13)) ?> Contacto</span>
    <h1>Con gusto<br>le escuchamos</h1>
    <p class="sitio-entrada">
      Escríbanos y le respondemos por correo lo antes posible. Si su
      asunto es urgente, el teléfono siempre será más rápido.
    </p>
  </div>
</section>

<section class="sitio-seccion">
  <div class="sitio-ancho sitio-hero__rejilla">

    <div>
      <h2>Cuéntenos en qué le ayudamos</h2>

      <?php if ($exito !== ''): ?>
      <div class="sitio-aviso sitio-aviso--ok" role="status">
        <?= P::crudo($ico($D['check'], 18)) ?>
        <span><?= P::e($exito) ?></span>
      </div>
      <?php endif; ?>

      <?php if ($error !== ''): ?>
      <div class="sitio-aviso sitio-aviso--mal" role="alert">
        <?= P::crudo($ico($D['escudo'], 18)) ?>
        <span><?= P::e($error) ?></span>
      </div>
      <?php endif; ?>

      <?php if (!$correoVivo): ?>
      <div class="sitio-aviso sitio-aviso--info">
        <?= P::crudo($ico($D['correo'], 18)) ?>
        <span>En este momento no podemos recibir mensajes por este medio.
              Le pedimos disculpas: llámenos al <b>2222-0000</b> y con
              mucho gusto lo atendemos.</span>
      </div>
      <?php endif; ?>

      <form class="sitio-formulario" method="post"
            action="<?= P::attr($rutas->url('contacto')) ?>" novalidate>
        <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
        <!-- El token se emitió para este propósito; el middleware lo compara. -->
        <input type="hidden" name="_csrf_proposito" value="contacto">

        <!-- Señuelo para robots: una persona no lo ve ni lo rellena. -->
        <div class="sitio-trampa" aria-hidden="true">
          <label for="sitio_web">No rellene este campo</label>
          <input type="text" id="sitio_web" name="sitio_web" tabindex="-1" autocomplete="off">
        </div>

        <div class="sitio-campo">
          <label for="nombre">Su nombre</label>
          <input type="text" id="nombre" name="nombre" required minlength="3"
                 autocomplete="name" placeholder="Nombre y apellido">
        </div>

        <div class="sitio-campo">
          <label for="correo">Correo electrónico</label>
          <input type="email" id="correo" name="correo" required
                 autocomplete="email" placeholder="nombre@dominio.com">
        </div>

        <div class="sitio-campo">
          <label for="asunto">Asunto <span style="font-weight:400;color:var(--suave)">(opcional)</span></label>
          <input type="text" id="asunto" name="asunto" maxlength="120"
                 placeholder="Sobre qué nos escribe">
        </div>

        <div class="sitio-campo">
          <label for="mensaje">Mensaje</label>
          <textarea id="mensaje" name="mensaje" required minlength="15"
                    placeholder="Escríbanos con toda confianza"></textarea>
        </div>

        <button class="sitio-btn sitio-btn--primario" type="submit">
          Enviar mensaje
        </button>

        <p style="font-size:.78rem;color:var(--suave);margin:0">
          No pida datos de su cuenta ni envíe contraseñas por este medio.
          Nunca se los solicitaremos por correo.
        </p>
      </form>
    </div>

    <div>
      <h2>Otras formas de contactarnos</h2>

      <div class="sitio-rejilla" style="grid-template-columns:1fr">
        <article class="sitio-tarjeta">
          <div class="sitio-tarjeta__icono"><?= P::crudo($ico($D['telefono'], 22)) ?></div>
          <h3>Atención telefónica</h3>
          <p><b style="color:var(--tinta);font-size:1.1rem">2222-0000</b><br>
             Lunes a viernes 8:00–17:00 · Sábados 8:00–12:00</p>
        </article>

        <article class="sitio-tarjeta">
          <div class="sitio-tarjeta__icono"><?= P::crudo($ico($D['escudo'], 22)) ?></div>
          <h3>Tarjeta extraviada</h3>
          <p><b style="color:var(--tinta);font-size:1.1rem">2222-0001</b><br>
             Las 24 horas, todos los días del año.</p>
        </article>

        <article class="sitio-tarjeta">
          <div class="sitio-tarjeta__icono"><?= P::crudo($ico($D['mapa'], 22)) ?></div>
          <h3>En agencia</h3>
          <p>Tenemos seis agencias en el país. Con gusto le indicamos la más cercana.</p>
          <div class="sitio-tarjeta__pie">
            <a href="<?= P::attr($rutas->url('sucursales')) ?>">Ver sucursales →</a>
          </div>
        </article>

        <article class="sitio-tarjeta">
          <div class="sitio-tarjeta__icono"><?= P::crudo($ico($D['candado'], 22)) ?></div>
          <h3>¿Ya es cliente?</h3>
          <p>Desde su banca en línea puede abrir una solicitud de soporte y
             seguir su avance paso a paso.</p>
          <div class="sitio-tarjeta__pie">
            <a href="<?= P::attr($rutas->url('entrar')) ?>">Entrar →</a>
          </div>
        </article>
      </div>
    </div>

  </div>
</section>
