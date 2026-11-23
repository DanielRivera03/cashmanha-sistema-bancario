<?php
/**
 * Marco del sitio público.
 *
 * Nada de lo que se dibuja aquí depende de que haya sesión: es la cara
 * que ve cualquiera que llegue al dominio. Por eso no carga el CSS del
 * panel —que arrastra el menú lateral, la cabecera fija y el tema
 * completo de la plantilla— sino una hoja propia y pequeña.
 *
 * El acceso a la banca en línea está siempre visible, en la cabecera y
 * en el pie, porque es a lo que viene la mayoría de quien ya es cliente.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var string      $contenido
 * @var string      $titulo
 * @var string      $seccion      clave de la sección activa
 * @var string|null $descripcion  para el buscador
 */
use CashMan\Http\Plantilla as P;

$P = $rutas->url('assets/plantilla/');

$navegacion = [
    'personas'   => ['Personas',   'personas'],
    'empresas'   => ['Empresas',   'empresas'],
    // La tienda es parte de la cara pública: cualquiera entra, ve el
    // catálogo y compra, sin necesidad de ser cliente.
    'tienda'     => ['Tienda',     'tienda'],
    'nosotros'   => ['Nosotros',   'nosotros'],
    'sucursales' => ['Sucursales', 'sucursales'],
    'preguntas'  => ['Ayuda',      'preguntas'],
    'contacto'   => ['Contacto',   'contacto'],
];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= P::e($titulo ?? 'CashMan H.A.') ?></title>
  <meta name="description" content="<?= P::attr($descripcion ?? 'Banca personal y de empresas en El Salvador.') ?>">
  <meta name="theme-color" content="#6418c3">

  <link rel="apple-touch-icon" sizes="180x180" href="<?= P::attr($P) ?>images/apple-icon-180x180.png">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= P::attr($P) ?>images/favicon-32x32.png">

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <?php $v = @filemtime(dirname(__DIR__, 3) . '/public/assets/css/sitio.css') ?: time(); ?>
  <link href="<?= P::attr($rutas->url('assets/css/sitio.css') . '?v=' . $v) ?>" rel="stylesheet">
<?php /* El panel que enseña los pasos de una operación mientras corre.
         Archivo propio porque lo comparten el panel, el sitio público
         y el acceso. */ ?>
<?php $prc = @filemtime(dirname(__DIR__, 3) . '/public/assets/css/proceso.css') ?: time(); ?>
<?php /* El medidor de fuerza de la contraseña. */ ?>
<?php $fzc = @filemtime(dirname(__DIR__, 3) . '/public/assets/css/fuerza.css') ?: time(); ?>
<link rel="stylesheet" href="<?= P::attr($rutas->url('assets/css/fuerza.css') . '?v=' . $fzc) ?>">
<link href="<?= P::attr($rutas->url('assets/css/proceso.css') . '?v=' . $prc) ?>" rel="stylesheet">
  <?php /* Los avisos y los mensajes de campo. Van en su propio archivo
           porque los comparten el sitio público y el acceso; antes su
           CSS solo estaba en el del panel y aquí salían como texto
           suelto. */ ?>
  <?php $avc = @filemtime(dirname(__DIR__, 3) . '/public/assets/css/avisos.css') ?: time(); ?>
  <link href="<?= P::attr($rutas->url('assets/css/avisos.css') . '?v=' . $avc) ?>" rel="stylesheet">
</head>
<body>

<a class="sitio-saltar" href="#contenido">Saltar al contenido</a>

<header class="sitio-cabecera">
  <div class="sitio-ancho sitio-cabecera__fila">
    <a class="sitio-marca" href="<?= P::attr($rutas->url('/')) ?>">
      <img src="<?= P::attr($P) ?>images/logo.png" alt="" aria-hidden="true">
      <span>CashMan <b>H.A.</b></span>
    </a>

    <input type="checkbox" id="sitio-menu" class="sitio-menu__estado" hidden>
    <label for="sitio-menu" class="sitio-menu__boton" aria-label="Abrir el menú">
      <span></span><span></span><span></span>
    </label>

    <nav class="sitio-nav" aria-label="Secciones">
      <?php foreach ($navegacion as $clave => [$etiqueta, $ruta]): ?>
      <a href="<?= P::attr($rutas->url($ruta)) ?>"
         class="<?= ($seccion ?? '') === $clave ? 'activo' : '' ?>"><?= P::e($etiqueta) ?></a>
      <?php endforeach; ?>

      <a class="sitio-btn sitio-btn--primario sitio-nav__acceso"
         href="<?= P::attr($rutas->url('entrar')) ?>"
         target="_blank" rel="noopener">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.3" aria-hidden="true">
          <rect x="3" y="11" width="18" height="11" rx="2.5"/>
          <path d="M7 11V7a5 5 0 0110 0v4" stroke-linecap="round"/>
        </svg>
        Banca en línea
      </a>
    </nav>
  </div>
</header>

<main id="contenido">
<?= P::crudo($contenido) ?>
</main>

<footer class="sitio-pie">
  <div class="sitio-ancho">
    <div class="sitio-pie__rejilla">

      <div>
        <div class="sitio-marca sitio-marca--pie">
          <!-- El pie va sobre fondo oscuro: aquí el logotipo original,
               que es azul marino, quedaba tinta oscura sobre oscuro. -->
          <img src="<?= P::attr($P) ?>images/logo-reverso.png" alt="" aria-hidden="true">
          <span>CashMan <b>H.A.</b></span>
        </div>
        <p class="sitio-pie__nota">
          CashMan H.A. El Salvador, S.A. de C.V.<br>
          San Salvador, El Salvador.
        </p>
        <a class="sitio-btn sitio-btn--claro" href="<?= P::attr($rutas->url('entrar')) ?>">
          Entrar a mi cuenta
        </a>
      </div>

      <div>
        <h3>Productos</h3>
        <a href="<?= P::attr($rutas->url('personas')) ?>">Cuentas de ahorro</a>
        <a href="<?= P::attr($rutas->url('personas')) ?>">Cuenta corriente</a>
        <a href="<?= P::attr($rutas->url('personas')) ?>">Créditos</a>
        <a href="<?= P::attr($rutas->url('personas')) ?>">Tarjetas de crédito</a>
        <a href="<?= P::attr($rutas->url('empresas')) ?>">Banca de empresas</a>
      </div>

      <div>
        <h3>Institución</h3>
        <a href="<?= P::attr($rutas->url('nosotros')) ?>">Quiénes somos</a>
        <a href="<?= P::attr($rutas->url('sucursales')) ?>">Sucursales y cajeros</a>
        <?php /* Quien compró como invitado y cerró el correo no tenía
                 ninguna puerta para entrar a ver su pedido. Esta es. */ ?>
        <a href="<?= P::attr($rutas->url('tienda/seguimiento')) ?>">Seguimiento de pedidos</a>
        <a href="<?= P::attr($rutas->url('preguntas')) ?>">Preguntas frecuentes</a>
        <a href="<?= P::attr($rutas->url('contacto')) ?>">Contacto</a>
      </div>

      <div>
        <h3>Estamos para servirle</h3>
        <p class="sitio-pie__nota">
          <b>2222-0000</b><br>
          Lunes a viernes, 8:00 a 17:00<br>
          Sábados, 8:00 a 12:00
        </p>
        <p class="sitio-pie__nota">
          Tarjeta extraviada, las 24 horas<br>
          <b>2222-0001</b>
        </p>
      </div>

    </div>

    <div class="sitio-pie__legal">
      <p>
        Entidad sujeta a la Ley de Bancos y a la supervisión de la
        Superintendencia del Sistema Financiero. Las tasas publicadas son
        de referencia y están sujetas a evaluación crediticia; la tasa
        aplicable a cada operación consta en su contrato.
      </p>
      <p>&copy; <?= date('Y') ?> CashMan H.A. S.A. de C.V. Todos los derechos reservados.</p>
    </div>
  </div>
</footer>

<?php if (($seccion ?? '') === 'tienda'): ?>
<!-- Solo en la tienda: el resto del sitio no necesita este guion y no
     tiene por qué descargarlo. -->
<?php $tdv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/tienda.js') ?: time(); ?>
<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>
        src="<?= P::attr($rutas->url('assets/js/tienda.js') . '?v=' . $tdv) ?>"></script>
<?php endif; ?>
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
