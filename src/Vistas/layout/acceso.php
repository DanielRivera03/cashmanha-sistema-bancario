<?php
/**
 * Marco de las pantallas públicas: ingresar, recuperar, restablecer y
 * el cambio obligatorio de contraseña.
 *
 * Reproduce la estructura de acceso del sistema base
 * (`authincation` > `authincation-content` > `auth-form`, tarjeta
 * centrada en `col-md-6`, logo arriba y título en `h4`), con los
 * mismos recursos de la plantilla.
 *
 * Los iconos van en SVG en línea: el sistema base los traía de un CDN
 * de FontAwesome y la política de contenido de este los bloquea.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var string $contenido
 * @var string $titulo
 * @var string|null $ilustracion  archivo de images/ sobre la tarjeta
 * @var string|null $nota         HTML del aviso que va encima
 */
use CashMan\Http\Plantilla as P;

$P = $rutas->url('assets/plantilla/');
?>
<!doctype html>
<html lang="es" class="h-100">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="same-origin">
  <title><?= P::e($titulo ?? 'CashMan H.A. | Portal Financiero') ?></title>

  <link rel="apple-touch-icon" sizes="180x180" href="<?= P::attr($P) ?>images/apple-icon-180x180.png">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= P::attr($P) ?>images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="<?= P::attr($P) ?>images/favicon-16x16.png">
  <meta name="theme-color" content="#6418c3">

  <link href="<?= P::attr($P) ?>css/style.css" rel="stylesheet">
<?php /* El panel que enseña los pasos de una operación mientras corre.
         Archivo propio porque lo comparten el panel, el sitio público
         y el acceso. */ ?>
<?php $prc = @filemtime(dirname(__DIR__, 3) . '/public/assets/css/proceso.css') ?: time(); ?>
<?php /* El medidor de fuerza de la contraseña. */ ?>
<?php $fzc = @filemtime(dirname(__DIR__, 3) . '/public/assets/css/fuerza.css') ?: time(); ?>
<link rel="stylesheet" href="<?= P::attr($rutas->url('assets/css/fuerza.css') . '?v=' . $fzc) ?>">
<link href="<?= P::attr($rutas->url('assets/css/proceso.css') . '?v=' . $prc) ?>" rel="stylesheet">
  <?php $v = @filemtime(dirname(__DIR__, 3) . "/public/assets/css/acceso.css") ?: time(); ?>
  <link href="<?= P::attr($rutas->url('assets/css/acceso.css') . '?v=' . $v) ?>" rel="stylesheet">
  <?php /* Los avisos y los mensajes de campo. Van en su propio archivo
           porque los comparten el sitio público y el acceso; antes su
           CSS solo estaba en el del panel y aquí salían como texto
           suelto. */ ?>
  <?php $avc = @filemtime(dirname(__DIR__, 3) . '/public/assets/css/avisos.css') ?: time(); ?>
  <link href="<?= P::attr($rutas->url('assets/css/avisos.css') . '?v=' . $avc) ?>" rel="stylesheet">
</head>

<body class="h-100 cm-acceso-body">

<?php
/* El mismo telón que el sitio público.
 *
 * Estas pantallas vivían en su propio mundo —fondo gris claro con dos
 * manchas de color— mientras la cara pública tenía degradado animado y
 * paisaje. Quien venía de la portada a entrar notaba el salto.
 *
 * La biblioteca gráfica es la del sitio: un solo sitio donde están
 * dibujadas las escenas, y aquí se toma la que toque. */
require dirname(__DIR__) . '/sitio/_graficos.php';
?>

<div class="cm-acceso-fondo" aria-hidden="true">
  <?= P::crudo($escenario($escena ?? 'volcanes')) ?>
</div>

<div class="authincation h-100">
  <div class="container h-100">
    <div class="row justify-content-center h-100 align-items-center">

      <?php if (!empty($ilustracion)): ?>
      <img class="cm-ilustracion d-none d-md-block"
           src="<?= P::attr($P . 'images/' . $ilustracion) ?>" alt="" aria-hidden="true">
      <?php endif; ?>

      <?php /* La mayoría de estas pantallas son tres campos y caben de
               sobra en una columna estrecha. El registro son nueve, y
               en esa misma columna salía una tira larguísima que había
               que recorrer entera para llegar al botón.

               Con `$ancho` la pantalla pide una caja más grande y se
               reparte en dos columnas. No se le da a todas porque una
               caja de entrar de ochocientos píxeles con dos campos
               dentro se ve vacía. */ ?>
      <div class="<?= !empty($ancho)
          ? 'col-xl-8 col-lg-10 col-md-11'
          : 'col-xl-5 col-lg-6 col-md-8' ?>">

        <?= P::crudo($nota ?? '') ?>

        <div class="authincation-content">
          <div class="row no-gutters">
            <div class="col-xl-12">
              <div class="auth-form">

                <div class="text-center">
                  <img class="logo-abbr logo-formulario"
                       src="<?= P::attr($P) ?>images/logo.png" alt="CashMan H.A.">
                </div>

                <?= P::crudo($contenido) ?>

              </div>
            </div>
          </div>
        </div>

        <p class="text-center cm-pie-acceso mt-3 mb-0">
          &copy; <?= date('Y') ?> CashMan H.A. S.A. de C.V.
        </p>
      </div>
    </div>
  </div>
</div>

<script src="<?= P::attr($P) ?>vendor/global/global.min.js"></script>

<?php /* El calendario del sistema, para que la fecha de nacimiento no
         la pida el selector del navegador —distinto en cada uno y
         parecido al nuestro en ninguno—. Se engancha solo a todo
         `input[type=date]` que encuentre; si no cargara, el campo
         sigue siendo utilizable con el selector de siempre. */ ?>
<?php $vc = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/calendario.js') ?: time(); ?>
<script src="<?= P::attr($rutas->url('assets/js/calendario.js') . '?v=' . $vc) ?>" defer></script>
<?php /* Avisos y validación propios.
         Sin estos dos, el código cae al `alert()` del navegador —ese
         recuadro que dice «localhost:90 dice»— y los campos sacan el
         globo amarillo en el idioma del navegador, no en el de la
         página. Los dos archivos ya existían; faltaba cargarlos fuera
         del panel. */ ?>
<?php /* El token de seguridad es de un solo uso y el servidor devuelve
         uno nuevo en cada respuesta. Esto lo recoge y lo escribe en el
         formulario; sin ello, el segundo intento moría con «el token
         venció», que es justo lo que pasa cuando el primero falló por
         un dato que faltaba. */ ?>
<?php $csv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/token.js') ?: time(); ?>
<script src="<?= P::attr($rutas->url('assets/js/token.js') . '?v=' . $csv) ?>"></script>
<?php $avv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/avisos.js') ?: time(); ?>
<?php $prj = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/proceso.js') ?: time(); ?>
<script src="<?= P::attr($rutas->url('assets/js/proceso.js') . '?v=' . $prj) ?>"></script>
<?php $vav = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/validacion.js') ?: time(); ?>
<?php $fzj = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/fuerza-clave.js') ?: time(); ?>
<script src="<?= P::attr($rutas->url('assets/js/fuerza-clave.js') . '?v=' . $fzj) ?>"></script>
<?php /* El documento de identidad: el tipo manda la máscara. Va en los
         tres marcos porque el documento se pide en el registro, en la
         tienda y en el alta de clientes del panel. */ ?>
<?php $dcv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/documento.js') ?: time(); ?>
<?php /* El teléfono, 0000-0000 en todo el sistema. Se engancha solo a
         los campos de teléfono, sin tocar las vistas. */ ?>
<?php $tlv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/telefono.js') ?: time(); ?>
<script src="<?= P::attr($rutas->url('assets/js/telefono.js') . '?v=' . $tlv) ?>"></script>
<script src="<?= P::attr($rutas->url('assets/js/documento.js') . '?v=' . $dcv) ?>"></script>
<script src="<?= P::attr($rutas->url('assets/js/avisos.js') . '?v=' . $avv) ?>"></script>
<script src="<?= P::attr($rutas->url('assets/js/validacion.js') . '?v=' . $vav) ?>"></script>
</body>
</html>
