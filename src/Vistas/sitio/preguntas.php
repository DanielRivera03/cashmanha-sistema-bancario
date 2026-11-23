<?php
/**
 * Preguntas frecuentes.
 *
 * Las respuestas describen cómo se comporta el sistema de verdad —los
 * plazos de sesión, el flujo de aprobación, la mora— y no una versión
 * idealizada. Si el comportamiento cambia, esto se corrige.
 *
 * @var \CashMan\Http\Enrutador $rutas
 */
use CashMan\Http\Plantilla as P;

require __DIR__ . '/_graficos.php';

$grupos = [
    'Su cuenta en línea' => [
        ['¿Cómo entro por primera vez?',
         '<p>Con el usuario y la contraseña que le entregamos en agencia. La '
         . 'primera vez le pediremos que la cambie por una que solamente usted '
         . 'conozca; la que le dimos sirve únicamente para ese primer ingreso.</p>'
         . '<p>Al cambiarla cerramos las demás sesiones que estuvieran abiertas, '
         . 'por su seguridad. Si tiene cualquier dificultad, con mucho gusto le '
         . 'ayudamos al <b>2222-0000</b>.</p>'],

        ['Olvidé mi contraseña, ¿qué hago?',
         '<p>No se preocupe, le pasa a cualquiera. En la pantalla de ingreso elija '
         . '<em>¿Olvidó su contraseña?</em> y le enviaremos un enlace al correo que '
         . 'tenemos registrado. Ese enlace sirve una sola vez y caduca pronto, '
         . 'también por su seguridad.</p>'
         . '<p>Si ya no tiene acceso a ese correo, acérquese a cualquier agencia con '
         . 'su documento de identidad y lo resolvemos ahí mismo.</p>'],

        ['¿Por qué se cerró sola mi sesión?',
         '<p>La cerramos nosotros cuando pasa un rato sin actividad. Es una '
         . 'protección pensada sobre todo para cuando entra desde el teléfono o '
         . 'desde una computadora compartida.</p>'
         . '<p>Antes de cerrarla le mostramos un aviso con una cuenta regresiva, '
         . 'para que pueda continuar si sigue ahí.</p>'],

        ['¿Qué es la verificación en dos pasos?',
         '<p>Es una segunda llave para su cuenta: además de su contraseña, le '
         . 'pediremos un código de seis dígitos que cambia cada 30 segundos y que '
         . 'se genera en su propio teléfono. Así, aunque alguien llegara a conocer '
         . 'su contraseña, no podría entrar.</p>'
         . '<p>Puede activarla cuando guste desde <em>Seguridad y 2FA</em> dentro de '
         . 'su cuenta. Se la recomendamos de corazón.</p>'],
    ],

    'Cuentas y movimientos' => [
        ['¿Cómo abro una cuenta?',
         '<p>Lo esperamos en cualquiera de nuestras agencias con su documento de '
         . 'identidad y su NIT. El monto para abrirla depende del producto que '
         . 'elija y lo puede consultar en la sección de productos.</p>'
         . '<p>Si prefiere que le expliquemos las opciones antes de ir, escríbanos y '
         . 'con gusto lo orientamos.</p>'],

        ['¿Puedo transferir a otro banco?',
         '<p>Entre cuentas de CashMan H.A. la transferencia es inmediata. Para otras '
         . 'instituciones, consúltenos: depende del convenio vigente y con gusto le '
         . 'indicamos las opciones disponibles.</p>'],

        ['¿Dónde encuentro mi estado de cuenta?',
         '<p>En <em>Estados de cuenta</em>, dentro de su banca en línea. Los '
         . 'generamos cada mes, por cuenta y por producto, y puede descargarlos '
         . 'cuando los necesite.</p>'
         . '<p>Cada documento lleva un código de verificación impreso, de modo que '
         . 'siempre se puede comprobar que es el original que le entregamos.</p>'],
    ],

    'Créditos' => [
        ['¿Cuánto tarda la aprobación?',
         '<p>Su solicitud recorre tres revisiones: la captura y revisa atención al '
         . 'cliente, la evalúa gerencia y la autoriza presidencia. Usted puede ir '
         . 'viendo en qué etapa se encuentra desde su cuenta, sin tener que '
         . 'llamarnos a preguntar.</p>'
         . '<p>Toma algo más de tiempo que una aprobación automática, y es a '
         . 'propósito: así cuidamos que cada crédito se otorgue bien.</p>'],

        ['¿Qué seguros lleva mi crédito?',
         '<p>Dos, y los dos son para protegerlo a usted y a su familia:</p>'
         . '<p><b>Seguro de vida sobre saldo deudor.</b> Si algo llegara a pasarle, '
         . 'la aseguradora cancela lo que quede pendiente y la deuda no recae en los '
         . 'suyos.</p>'
         . '<p><b>Seguro de desempleo.</b> Cubre sus cuotas durante un tiempo si '
         . 'pierde su trabajo sin haberlo buscado.</p>'
         . '<p>Los dos se calculan sobre lo que debe, así que van bajando conforme '
         . 'usted avanza, y aparecen detallados en su cuota. Nada de letra '
         . 'pequeña.</p>'],

        ['¿Qué pasa si me atraso en una cuota?',
         '<p>Se aplica un recargo por cada día de atraso, conforme a la cláusula '
         . 'penal de su contrato, mientras dure el atraso. Ese recargo se cubre '
         . 'primero: lo que abone se aplica a la mora, luego a los intereses y por '
         . 'último al capital.</p>'
         . '<p>Si prevé que va a tener dificultades, le pedimos de favor que nos '
         . 'avise antes. Casi siempre hay una salida, y es mucho más fácil '
         . 'encontrarla a tiempo que después.</p>'],

        ['¿Puedo pagar mi cuota con la tarjeta de otro banco?',
         '<p>Sí, y con toda comodidad. Desde su banca en línea puede pagar con '
         . 'tarjeta de crédito o débito de otra institución, aunque en ese momento '
         . 'no tenga fondos con nosotros.</p>'
         . '<p>El abono se refleja en cuanto la procesadora confirma el cobro.</p>'],

        ['¿Puedo adelantar capital?',
         '<p>Claro que sí, y nos parece una excelente decisión. No le cobraremos '
         . 'intereses que todavía no se hayan generado.</p>'
         . '<p>Eso sí: aunque adelante capital, sus intereses siguen pagándose en la '
         . 'fecha acordada. Si quiere, le ayudamos a calcular cuánto le conviene '
         . 'abonar.</p>'],
    ],

    'Tarjetas' => [
        ['Perdí mi tarjeta, ¿qué hago?',
         '<p>Llámenos de inmediato al <b>2222-0001</b>. Contestamos las 24 horas, '
         . 'todos los días del año.</p>'
         . '<p>También puede bloquearla usted mismo en cuestión de segundos desde '
         . '<em>Tarjetas</em> en su banca en línea. Hágalo sin pensarlo: siempre se '
         . 'puede reactivar si aparece.</p>'],

        ['¿Cómo sé cuánto debo?',
         '<p>En <em>Tarjetas</em> verá el saldo que lleva usado, su límite y cuánto '
         . 'tiene disponible, actualizado al momento.</p>'],

        ['¿Mi tarjeta también lleva seguros?',
         '<p>Sí. Igual que en los créditos, su tarjeta incluye el seguro de vida '
         . 'sobre saldo deudor y el seguro de desempleo, calculados sobre el saldo '
         . 'que tenga usado.</p>'],
    ],
];
?>

<section class="sitio-hero">
  <?= P::crudo($escenario('volcanes')) ?>

  <div class="sitio-ancho">
    <span class="sitio-etiqueta"><?= P::crudo($ico($D['escudo'], 13)) ?> Estamos para servirle</span>
    <h1>¿En qué le<br>podemos ayudar?</h1>
    <p class="sitio-entrada">
      Aquí respondemos lo que más nos consultan. Si no encuentra su
      pregunta, escríbanos con toda confianza: será un gusto atenderle.
    </p>
  </div>
</section>

<?php $i = 0; foreach ($grupos as $grupo => $preguntas): $i++; ?>
<section class="sitio-seccion <?= $i % 2 === 0 ? 'sitio-seccion--tenue' : '' ?>">
  <div class="sitio-ancho" style="max-width:860px">
    <div class="sitio-titulo"><h2><?= P::e($grupo) ?></h2></div>

    <?php foreach ($preguntas as [$pregunta, $respuesta]): ?>
    <details class="sitio-pregunta">
      <summary><?= P::e($pregunta) ?></summary>
      <div class="sitio-pregunta__cuerpo"><?= P::crudo($respuesta) ?></div>
    </details>
    <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>

<section class="sitio-llamada">
  <div class="sitio-ancho">
    <div>
      <h2>¿No encontró su pregunta?</h2>
      <p>
        Escríbanos y le respondemos por correo, o llámenos al 2222-0000.
        Con mucho gusto le atendemos.
      </p>
    </div>
    <a class="sitio-btn sitio-btn--claro" href="<?= P::attr($rutas->url('contacto')) ?>">
      Preguntar
    </a>
  </div>
</section>
