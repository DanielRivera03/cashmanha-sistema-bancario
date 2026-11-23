<?php
/**
 * Quiénes somos.
 *
 * Los datos de la institución —razón social, domicilio, marco legal—
 * son los que constan en el contrato que se entrega al cliente.
 *
 * @var \CashMan\Http\Enrutador $rutas
 */
use CashMan\Http\Plantilla as P;

require __DIR__ . '/_graficos.php';
?>

<section class="sitio-hero">
  <?= P::crudo($escenario('salvador-mundo')) ?>

  <div class="sitio-ancho">
    <span class="sitio-etiqueta"><?= P::crudo($ico($D['escudo'], 13)) ?> Mucho gusto</span>
    <h1>Permítanos<br>presentarnos</h1>
    <p class="sitio-entrada">
      Somos CashMan H.A. El Salvador, Sociedad Anónima de Capital
      Variable, una institución bancaria con domicilio en San Salvador.
      Y, sobre todo, somos la gente que lo atiende cada día.
    </p>
  </div>
</section>

<section class="sitio-seccion">
  <div class="sitio-ancho sitio-hero__rejilla">
    <div>
      <h2>A qué nos dedicamos</h2>
      <p style="color:var(--suave)">
        Cuidamos los ahorros de quienes nos los confían y, con ellos,
        apoyamos a quienes necesitan comprar su casa, cambiar de vehículo
        o sacar adelante su negocio. Dicho de otro modo: lo que una
        familia guarda hoy ayuda a que otra dé un paso mañana.
      </p>
      <p style="color:var(--suave)">
        Trabajamos con cuatro líneas: cuentas de ahorro, cuenta corriente,
        créditos personales e hipotecarios, y tarjetas de crédito. Todas
        con sus condiciones publicadas y su contrato por escrito, porque
        creemos que usted tiene derecho a saber exactamente a qué se
        compromete.
      </p>

      <h3 style="margin-top:1.8rem">Cómo decidimos un crédito</h3>
      <p style="color:var(--suave)">
        Ninguna solicitud la aprueba una sola persona. Pasa por atención
        al cliente, después por gerencia y finalmente por presidencia, y
        en cada paso queda constancia de quién la autorizó. Toma un poco
        más de tiempo que un clic, y es a propósito: así protegemos
        tanto su dinero como el de quienes ahorran con nosotros.
      </p>
    </div>

    <div>
      <div class="sitio-tabla-caja">
        <table class="sitio-tabla">
          <thead><tr><th colspan="2">Nuestros datos</th></tr></thead>
          <tbody>
            <tr><td>Razón social</td>
                <td class="sitio-tabla__dato">CashMan H.A. El Salvador, S.A. de C.V.</td></tr>
            <tr><td>También nos conocen como</td>
                <td class="sitio-tabla__dato">CashMan H.A. S.A. de C.V.</td></tr>
            <tr><td>Qué somos</td>
                <td class="sitio-tabla__dato">Institución bancaria</td></tr>
            <tr><td>Dónde estamos</td>
                <td class="sitio-tabla__dato">San Salvador, El Salvador</td></tr>
            <tr><td>Nos rige</td>
                <td class="sitio-tabla__dato">La Ley de Bancos</td></tr>
            <tr><td>Nos supervisa</td>
                <td class="sitio-tabla__dato">Superintendencia del Sistema Financiero</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<section class="sitio-seccion sitio-seccion--tenue">
  <div class="sitio-ancho">
    <div class="sitio-titulo sitio-titulo--centro">
      <h2>En qué creemos</h2>
      <p>Tres compromisos que no negociamos con nadie.</p>
    </div>

    <div class="sitio-rejilla">
      <article class="sitio-tarjeta">
        <div class="sitio-tarjeta__icono"><?= P::crudo($ico($D['libro'], 22)) ?></div>
        <h3>Todo queda por escrito</h3>
        <p>Cada movimiento de su cuenta queda registrado con su fecha y
           su hora, de forma permanente. Si alguna vez algo no le cuadra,
           podemos reconstruir con usted lo que pasó, paso por paso.</p>
      </article>

      <article class="sitio-tarjeta">
        <div class="sitio-tarjeta__icono"><?= P::crudo($ico($D['usuarios'], 22)) ?></div>
        <h3>Nadie decide solo</h3>
        <p>Las decisiones importantes pasan por varias manos, y quien
           atiende no es quien autoriza. Es nuestra forma de cuidar que
           las cosas se hagan bien.</p>
      </article>

      <article class="sitio-tarjeta">
        <div class="sitio-tarjeta__icono"><?= P::crudo($ico($D['candado'], 22)) ?></div>
        <h3>Su información es suya</h3>
        <p>Su contraseña no la puede ver nadie, tampoco nosotros. Y si
           quiere una capa más de protección, puede activar la
           verificación desde su teléfono cuando guste.</p>
      </article>
    </div>
  </div>
</section>

<section class="sitio-seccion">
  <div class="sitio-ancho">
    <div class="sitio-titulo">
      <h2>Lo que queremos que sepa antes de firmar</h2>
      <p>
        Preferimos explicárselo ahora, con calma, y no que se entere
        después. Esto es lo esencial de un contrato de crédito con
        nosotros.
      </p>
    </div>

    <ul class="sitio-lista" style="max-width:72ch">
      <li><?= P::crudo($ico($D['check'], 18)) ?>
        <span><b>Los intereses se calculan sobre lo que debe.</b> Conforme
        va pagando, va debiendo menos intereses. Nunca le cobraremos
        intereses que todavía no se hayan generado.</span></li>

      <li><?= P::crudo($ico($D['check'], 18)) ?>
        <span><b>Su crédito lleva dos seguros.</b> El seguro de vida
        cancela lo que quede pendiente si algo llegara a pasarle, de modo
        que la deuda no recae en su familia. El seguro de desempleo cubre
        sus cuotas si pierde el trabajo sin buscarlo. Los dos se
        detallan en su cuota, sin letra pequeña.</span></li>

      <li><?= P::crudo($ico($D['check'], 18)) ?>
        <span><b>Si se atrasa, hay un recargo.</b> Se calcula por cada día
        de atraso mientras dure. Si llega a pasarle, le pedimos que nos
        avise cuanto antes: casi siempre hay una salida, y es mejor
        buscarla juntos y a tiempo.</span></li>

      <li><?= P::crudo($ico($D['check'], 18)) ?>
        <span><b>Cómo se aplica lo que abona.</b> Primero al recargo si lo
        hubiera, luego a los intereses y por último al capital.</span></li>

      <li><?= P::crudo($ico($D['check'], 18)) ?>
        <span><b>La tasa puede variar.</b> Si cambia, se lo comunicamos por
        los medios que establece la Ley de Bancos. No nos gustan las
        sorpresas tanto como a usted.</span></li>

      <li><?= P::crudo($ico($D['check'], 18)) ?>
        <span><b>Puede adelantar capital.</b> Y si lo hace, no le
        cobraremos intereses que no se hayan generado.</span></li>
    </ul>
  </div>
</section>

<section class="sitio-llamada">
  <div class="sitio-ancho">
    <div>
      <h2>¿Le quedó alguna duda?</h2>
      <p>
        Pregúntenos con toda confianza lo que necesite saber. Preferimos
        explicarlo las veces que haga falta.
      </p>
    </div>
    <a class="sitio-btn sitio-btn--claro" href="<?= P::attr($rutas->url('contacto')) ?>">
      Escribirnos
    </a>
  </div>
</section>
