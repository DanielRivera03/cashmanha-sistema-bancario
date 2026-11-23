<?php
/**
 * Layout Chrev — la misma plantilla del sistema base.
 *
 * Conserva la estructura original (nav-header, header, deznav,
 * content-body, footer) y sus assets, pero con tres diferencias de
 * fondo respecto a las vistas de v1:
 *
 *   1. Todo dato que se imprime pasa por P::e(). Las vistas originales
 *      no tenían una sola llamada a htmlspecialchars en 251 archivos.
 *   2. El menú se arma según los PERMISOS del rol, no duplicando la
 *      vista una vez por cada rol (que era el motivo de que existieran
 *      cinco copias casi idénticas de cada pantalla).
 *   3. Las URLs son limpias y los identificadores van ofuscados.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array|null $usuario
 * @var string $contenido
 */
use CashMan\Http\Plantilla as P;
require_once __DIR__ . "/iconos.php";

$P        = $rutas->url('assets/plantilla/');
$permisos = $usuario['permisos'] ?? [];
$tiene    = static fn(string $p): bool => in_array($p, $permisos, true);
$alguno   = static function (array $ps) use ($permisos): bool {
    foreach ($ps as $p) { if (in_array($p, $permisos, true)) { return true; } }
    return false;
};

/* =====================================================================
   QUÉ PÁGINA SE ESTÁ VIENDO
   =====================================================================

   Antes esto era una comparación exacta: la opción se marcaba solo si
   la dirección coincidía letra por letra con la del enlace. Tres cosas
   se caían por ahí:

     · Las pantallas de detalle. En `/creditos/4821` ninguna opción se
       encendía, porque la del menú es `/creditos` a secas. El cliente
       veía el grupo abierto y nada marcado dentro.

     · Los enlaces con filtro —«Todas las tarjetas» es `tarjetas?todas=1`,
       «Vía judicial» es `cobranza?etapa=judicial`—, porque la consulta
       no entra en la ruta.

     · Y, sobre todo, una veintena de opciones que nunca llevaron la
       marca: «Abrir cuenta», «Roles y permisos», «Redactar», los tres
       informes, «Accesos al sistema»… Se añadieron después y nadie se
       acordó de marcarlas. Ese olvido es el que esta lista impide
       repetir: `MenuActivoTest` lee este archivo y falla si un enlace
       del menú no está aquí.

   AHORA SE ELIGE UNA SOLA, Y ES LA MÁS ESPECÍFICA

   De todas las opciones que describen esta página gana la más larga:
   en `/otros-bancos/agenda` se enciende «Mis destinos», no «A otros
   bancos». Con dos marcas a la vez el menú tampoco diría qué se está
   viendo.
   ===================================================================== */

/** Todas las direcciones que el menú ofrece, en el orden que sea. */
$rutasMenu = [
    'panel',
    'junta', 'junta/evolucion',

    'cuentas', 'abrir-cuenta', 'cuentas/abrir', 'movimientos', 'transferir',
    'otros-bancos', 'otros-bancos/agenda', 'otros-bancos/historial',
    'otros-bancos/limites',
    'servicios', 'servicios/historial',
    'remesas', 'remesas/ventanilla',
    'constancias', 'constancias/agencia',
    'plazos', 'chequeras', 'programadas', 'deposito', 'retiro',

    'tarjetas', 'tarjetas?todas=1', 'tarjetas/catalogo',
    'tarjetas/mis-solicitudes', 'tarjetas/solicitudes',

    'creditos', 'cuotas', 'contratos',
    'solicitudes', 'solicitudes/nueva', 'reestructuraciones',

    'ofertas', 'estados', 'documentos', 'preferente', 'ofertas/cartera',

    'tienda/pedidos', 'tienda/pedidos?todos=1', 'tienda/admin',

    'caja', 'caja/atencion', 'caja/notas', 'caja/constancias',
    'cartera', 'clientes',
    'cobranza', 'cobranza?etapa=extrajudicial', 'cobranza?etapa=judicial',

    'reportes', 'reportes/transacciones', 'reportes/cartera',

    'mensajes', 'mensajes/enviados', 'mensajes/nuevo',
    'notificaciones',
    'tickets', 'tickets/nuevo', 'reclamos', 'reclamos/bandeja',

    'usuarios', 'usuarios/nuevo', 'roles',
    'productos', 'limites',
    'auditoria', 'auditoria/integridad', 'accesos', 'cumplimiento', 'procesos',

    'perfil', 'perfil/sesiones', 'seguridad',
];

$actual = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/');

$consulta = [];
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY), $consulta);

/* La ganadora. Se calcula UNA vez, no en cada enlace. */
$cmMejor = '';
$cmPeso  = -1;

foreach ($rutasMenu as $r) {
    [$ruta, $filtro] = array_pad(explode('?', $r, 2), 2, '');
    $u = rtrim($rutas->url($ruta), '/');

    /* La barra del final importa: sin ella `/caja` se daría por buena
       en `/cajamarca`, y la opción equivocada quedaría encendida. */
    if ($actual !== $u && !str_starts_with($actual, $u . '/')) {
        continue;
    }

    /* Longitud por dos para dejar hueco al punto del filtro: así
       `tarjetas?todas=1` gana a `tarjetas` cuando el filtro está
       puesto, y lo pierde cuando no. */
    $peso = strlen($u) * 2;

    if ($filtro !== '') {
        $exige = [];
        parse_str($filtro, $exige);

        foreach ($exige as $clave => $valor) {
            if (!isset($consulta[$clave])
                || (string) $consulta[$clave] !== (string) $valor) {
                continue 2;
            }
        }

        $peso++;
    }

    if ($peso > $cmPeso) {
        $cmPeso  = $peso;
        $cmMejor = $r;
    }
}

/** La marca de la opción encendida: solo la ganadora la lleva. */
$act = static function (string $ruta) use ($cmMejor): string {
    return $ruta === $cmMejor ? ' mm-active' : '';
};

/** El grupo se abre cuando la ganadora cuelga de alguna de sus ramas. */
$abierto = static function (array $rs) use ($cmMejor): string {
    $mejor = explode('?', $cmMejor, 2)[0];

    foreach ($rs as $r) {
        $rama = explode('?', $r, 2)[0];

        if ($mejor !== '' && str_starts_with($mejor . '/', $rama . '/')) {
            return ' mm-active';
        }
    }

    return '';
};

$sinLeer = (int) ($usuario['sin_leer'] ?? 0);

/* Cuántas ofertas preaprobadas vivas tiene quien está mirando.
   Se calcula aquí y no dentro del <li>: el menú se pinta en todas las
   pantallas, así que una consulta de más aquí es una por página. El
   try/catch es para que el menú no se caiga en una instalación donde
   la tabla todavía no exista. */
$ofertasVivas = 0;

if ((int) ($usuario['id'] ?? 0) > 0) {
    try {
        $ofertasVivas = (int) \CashMan\Infraestructura\Persistencia\BaseDatos::unValor(
            'SELECT COUNT(*) FROM ofertas_preaprobadas
              WHERE usuario_id = ? AND estado = "vigente" AND vence_el >= CURDATE()',
            [(int) $usuario['id']]
        );
    } catch (\Throwable $e) {
        $ofertasVivas = 0;
    }
}
?>
<!doctype html>
<?php
/* El tema con el que arranca la página.
 *
 * Va en el <html> y no se espera a JavaScript para decidirlo: lo que
 * eligió el cliente vive en su cuenta, así que el servidor ya lo sabe
 * y puede pintarlo de una vez. El guion solo lo confirma y se encarga
 * de los cambios en caliente.
 *
 * Quien no ha elegido: noche si es cliente preferente o patrimonial
 * —la banca privada se presenta así en todo el sector— y automático
 * para el resto, que respeta lo que use su dispositivo. */
$temaElegido = (string) ($usuario['tema'] ?? '');

if (!in_array($temaElegido, ['dia', 'noche', 'auto'], true)) {
    $temaElegido = in_array((string) ($usuario['segmento'] ?? ''),
        ['preferente', 'patrimonial'], true) ? 'noche' : 'auto';
}
?>
<html lang="es" data-tema-inicial="<?= P::attr($temaElegido) ?>"
      data-tema="<?= P::attr($temaElegido === 'auto' ? 'dia' : $temaElegido) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="robots" content="noindex, nofollow">
  <title><?= P::e($titulo ?? 'CashMan H.A.') ?></title>

  <?php /* A dónde manda el guion la elección, para que acompañe al
           cliente a su otro dispositivo. */ ?>
  <meta name="cm-tema-url" content="<?= P::attr($rutas->url('perfil/tema')) ?>">
  <meta name="cm-tema-csrf" content="<?= P::attr(\CashMan\Infraestructura\Seguridad\Csrf::token('tema')) ?>">

  <link rel="shortcut icon" href="<?= P::attr($P) ?>images/favicon.png">
  <link rel="stylesheet" href="<?= P::attr($P) ?>vendor/datatables/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="<?= P::attr($P) ?>vendor/bootstrap-select/dist/css/bootstrap-select.min.css">
  <link rel="stylesheet" href="<?= P::attr($P) ?>css/style.css">
  <?php $cmv = @filemtime(dirname(__DIR__, 3) . '/public/assets/css/cm.css') ?: time(); ?>
  <link rel="stylesheet" href="<?= P::attr($rutas->url('assets/css/cm.css') . '?v=' . $cmv) ?>">
  <?php /* El panel de proceso: los pasos de una operación mientras
           corre. Compartido con el sitio público y el acceso. */ ?>
  <?php $prc = @filemtime(dirname(__DIR__, 3) . '/public/assets/css/proceso.css') ?: time(); ?>
<?php /* El medidor de fuerza de la contraseña. */ ?>
<?php $fzc = @filemtime(dirname(__DIR__, 3) . '/public/assets/css/fuerza.css') ?: time(); ?>
<link rel="stylesheet" href="<?= P::attr($rutas->url('assets/css/fuerza.css') . '?v=' . $fzc) ?>">
  <link rel="stylesheet" href="<?= P::attr($rutas->url('assets/css/proceso.css') . '?v=' . $prc) ?>">

  <?php /* El modo noche va DESPUÉS: son reemplazos de color sobre lo
           ya definido, y cargarlo antes haría que lo claro ganara. */ ?>
  <?php $nov = @filemtime(dirname(__DIR__, 3) . '/public/assets/css/cm-noche.css') ?: time(); ?>
  <link rel="stylesheet" href="<?= P::attr($rutas->url('assets/css/cm-noche.css') . '?v=' . $nov) ?>">

  <?php /* Y el guion del tema, aquí arriba y SIN defer: tiene que
           aplicarse antes del primer pintado. Si corriera al final, la
           página aparecería en blanco y pasaría a negro medio segundo
           después; ese destello es molesto de día y doloroso de noche. */ ?>
  <?php $tmv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/tema.js') ?: time(); ?>
  <script src="<?= P::attr($rutas->url('assets/js/tema.js') . '?v=' . $tmv) ?>"></script>

  <!-- Utilidades mínimas, en el <head> a propósito.
       El contenido de la vista se imprime antes que los scripts del
       pie, y los scripts en línea de las vistas se ejecutan al
       encontrarlos. Si usan CMUtil al arrancar —y quince vistas lo
       hacen— sin esto lanzan un TypeError que se lleva por delante el
       resto de su script: los botones quedan sin registrar y la
       página parece no responder. -->
  <?php $bsv = @filemtime(dirname(__DIR__, 3) . "/public/assets/js/base.js") ?: time(); ?>
  <script src="<?= P::attr($rutas->url('assets/js/base.js') . '?v=' . $bsv) ?>"></script>

  <!-- El token de seguridad, en un solo sitio.

       Va aquí, en la cabecera y antes que todo lo demás, porque
       envuelve `fetch`: si se cargara después de un guion que ya hizo
       una petición, esa petición quedaría fuera del registro y sin
       reintento. Es el único archivo del sistema con esa exigencia. -->
  <?php $tkv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/token.js') ?: time(); ?>
  <script src="<?= P::attr($rutas->url('assets/js/token.js') . '?v=' . $tkv) ?>"></script>
</head>

<body>

<div id="preloader"><div class="lds-ripple"><div></div><div></div></div></div>

<div id="main-wrapper">

  <!-- ===================== NAV HEADER ===================== -->
  <div class="nav-header">
    <a href="<?= P::attr($rutas->url('panel')) ?>" class="brand-logo">
      <?php /* El logotipo es azul marino sobre fondo transparente: en
               el panel oscuro del modo noche quedaba tinta oscura sobre
               oscuro. Cada imagen lleva sus dos rutas y `tema.js`
               cambia el `src`. No se duplican las imágenes ni se juega
               con `display`: la plantilla las dimensiona con reglas
               propias y forzarlo las saca de su tamaño. */ ?>
      <img class="logo-abbr" alt="CashMan"
           src="<?= P::attr($P) ?>images/logo.png"
           data-logo-dia="<?= P::attr($P) ?>images/logo.png"
           data-logo-noche="<?= P::attr($P) ?>images/logo-reverso.png">
      <img class="logo-compact" alt="CashMan"
           src="<?= P::attr($P) ?>images/logo-text.png"
           data-logo-dia="<?= P::attr($P) ?>images/logo-text.png"
           data-logo-noche="<?= P::attr($P) ?>images/logo-text-reverso.png">
      <img class="brand-title" alt="CashMan"
           src="<?= P::attr($P) ?>images/logo-text.png"
           data-logo-dia="<?= P::attr($P) ?>images/logo-text.png"
           data-logo-noche="<?= P::attr($P) ?>images/logo-text-reverso.png">
    </a>
    <div class="nav-control">
      <div class="hamburger"><span class="line"></span><span class="line"></span><span class="line"></span></div>
    </div>
  </div>

  <!-- ===================== HEADER ===================== -->
  <div class="header">
    <div class="header-content">
      <nav class="navbar navbar-expand">
        <div class="collapse navbar-collapse justify-content-between">

          <div class="header-left">
            <div class="dashboard_bar" id="cm-encabezado"><?= P::e($encabezado ?? 'Inicio') ?></div>
          </div>

          <ul class="navbar-nav header-right">

            <!-- Clima y hora, como en el sistema original -->
            <?php if (!empty($clima)): ?>
            <li class="nav-item d-none d-lg-flex align-items-center mr-3">
              <div class="cm-clima" title="<?= P::attr($clima['descripcion'] . ' · actualizado ' . $clima['actualizado']) ?>">
                <img class="cm-clima__icono"
                     src="<?= P::attr($P) ?>images/icon-weather/<?= P::attr($clima['icono']) ?>.svg"
                     alt="<?= P::attr($clima['descripcion']) ?>"
                     data-cm-ocultar-si-falla>
                <div class="cm-clima__datos">
                  <span class="cm-clima__temp"><?= (int) $clima['temperatura'] ?>&deg;C</span>
                  <span class="cm-clima__desc"><?= P::e($clima['ciudad']) ?> &middot; <?= P::e($clima['descripcion']) ?></span>
                </div>
              </div>
            </li>
            <?php endif; ?>

            <li class="nav-item d-none d-md-flex align-items-center mr-3">
              <div class="cm-reloj">
                <span class="cm-reloj__hora" id="cm-hora">--:--:--</span>
                <span class="cm-reloj__fecha" id="cm-fecha"><?= P::e(\CashMan\Infraestructura\Notificacion\ServicioClima::fechaLarga()) ?></span>
              </div>
            </li>


            <!-- Mensajes -->
            <?php if ($tiene('mensaje.ver')): ?>
            <li class="nav-item dropdown notification_dropdown">
              <a class="nav-link bell dz-theme-mode" href="<?= P::attr($rutas->url('mensajes')) ?>">
                <?= cm_icono("mail") ?>
                <?php if (($usuario['mensajes_sin_leer'] ?? 0) > 0): ?>
                  <span class="badge light text-white bg-success"><?= (int) $usuario['mensajes_sin_leer'] ?></span>
                <?php endif; ?>
              </a>
            </li>
            <?php endif; ?>

            <!-- Notificaciones -->
            <li class="nav-item dropdown notification_dropdown">
              <a class="nav-link ai-icon" href="#" role="button" data-toggle="dropdown" id="btn-notif">
                <?= cm_icono("bell") ?>
                <span class="badge light text-white bg-primary<?= $sinLeer === 0 ? ' d-none' : '' ?>"
                      id="contador-notif"><?= $sinLeer > 99 ? '99+' : $sinLeer ?></span>
              </a>
              <div class="dropdown-menu dropdown-menu-right cm-panel-notif">
                <div class="cm-panel-notif__cabecera">
                  <strong>Notificaciones</strong>
                  <a href="<?= P::attr($rutas->url('notificaciones')) ?>">Ver todas</a>
                </div>

                <div class="cm-panel-notif__lista" id="panel-notif">
                  <div class="cm-panel-notif__cargando">
                    <span class="spinner-border spinner-border-sm mr-2"></span> Cargando
                  </div>
                </div>

                <button type="button" class="cm-panel-notif__pie" id="marcar-todas">
                  Marcar todas como leídas
                </button>
              </div>
            </li>

            <!-- Perfil -->
            <li class="nav-item dropdown header-profile">
              <a class="nav-link" href="#" role="button" data-toggle="dropdown">
                <div class="header-info mr-3 text-right">
                  <span class="d-block font-w600"><?= P::e($usuario['nombre_completo'] ?? '') ?></span>
                  <small class="text-end font-w400"><?= P::e($usuario['rol_nombre'] ?? '') ?></small>
                </div>
                <div class="cm-avatar"><?= P::e($usuario['iniciales'] ?? '?') ?></div>
              </a>
              <div class="dropdown-menu dropdown-menu-right">
                <a href="<?= P::attr($rutas->url('perfil')) ?>" class="dropdown-item ai-icon">
                  <?= cm_icono("user", 18, "mr-2 text-primary") ?><span class="ml-2">Mi perfil</span>
                </a>
                <a href="<?= P::attr($rutas->url('clave')) ?>" class="dropdown-item ai-icon">
                  <?= cm_icono("lock", 18, "mr-2 text-warning") ?><span class="ml-2">Cambiar contraseña</span>
                </a>
                <a href="<?= P::attr($rutas->url('perfil/sesiones')) ?>" class="dropdown-item ai-icon">
                  <?= cm_icono("device", 18, "mr-2 text-info") ?><span class="ml-2">Mis dispositivos</span>
                </a>
                <a href="<?= P::attr($rutas->url('seguridad')) ?>" class="dropdown-item ai-icon">
                  <?= cm_icono("shield", 18, "mr-2 text-success") ?><span class="ml-2">Verificación en dos pasos</span>
                </a>
                <a href="<?= P::attr($rutas->url('documentos')) ?>" class="dropdown-item ai-icon">
                  <?= cm_icono("package", 18, "mr-2 text-secondary") ?><span class="ml-2">Mis documentos</span>
                </a>                <div class="dropdown-divider"></div>
                <a href="<?= P::attr($rutas->url('salir')) ?>" class="dropdown-item ai-icon">
                  <?= cm_icono("exit", 18, "mr-2 text-danger") ?><span class="ml-2">Cerrar sesión</span>
                </a>
              </div>
            </li>

          </ul>
        </div>
      </nav>
    </div>
  </div>

  <!-- ===================== MENÚ LATERAL ===================== -->
  <div class="deznav">
    <div class="deznav-scroll">
      <ul class="metismenu" id="menu">

        <li class="nav-label first">Principal</li>

        <li class="<?= $act('panel') ?>">
          <a href="<?= P::attr($rutas->url('panel')) ?>" aria-expanded="false">
            <?= cm_icono("home") ?><span class="nav-text">Inicio</span>
          </a>
        </li>

        <?php /* El tablero de la Junta Directiva, de primero.
                 Para quien solo tiene ese permiso es lo único que hay
                 en el menú, y ponerlo al final le obligaría a recorrer
                 una lista de opciones que no puede usar. */ ?>
        <?php /* Con dos pantallas ya es un apartado, no un enlace: el
                 tablero dice cómo está el banco hoy y la evolución de
                 dónde viene, y quien entra aquí mira las dos. */ ?>
        <?php if ($tiene('junta.ver')): ?>
        <li class="<?= $abierto(['junta']) ?>">
          <a class="has-arrow ai-icon" href="javascript:void(0)" aria-expanded="false">
            <?= cm_icono("chart") ?><span class="nav-text">Junta Directiva</span>
          </a>
          <ul aria-expanded="false">
            <li><a href="<?= P::attr($rutas->url('junta')) ?>"
                   class="<?= $act('junta') ?>">Tablero</a></li>
            <li><a href="<?= P::attr($rutas->url('junta/evolucion')) ?>"
                   class="<?= $act('junta/evolucion') ?>">Evolución</a></li>
          </ul>
        </li>
        <?php endif; ?>

        <!-- ---------- MIS PRODUCTOS ---------- -->
        <?php if ($alguno(['cuenta.ver_propia','cuenta.ver_todas'])): ?>
        <li class="<?= $abierto(['cuentas','abrir-cuenta','movimientos','transferir','otros-bancos','servicios','remesas','constancias','plazos','chequeras','programadas','deposito','retiro']) ?>">
          <a class="has-arrow ai-icon" href="javascript:void(0)" aria-expanded="false">
            <?= cm_icono("wallet") ?><span class="nav-text">Cuentas</span>
          </a>
          <ul aria-expanded="false">
            <li><a href="<?= P::attr($rutas->url('cuentas')) ?>" class="<?= $act('cuentas') ?>">Listado de cuentas</a></li>
            <?php /* Abrirse una cuenta uno mismo. Solo lo ve quien
                     puede hacerlo: al personal no le sirve de nada
                     porque las de sus clientes se abren en caja. */ ?>
            <?php if ($tiene('cuenta.abrir_propia')): ?>
            <li><a href="<?= P::attr($rutas->url('abrir-cuenta')) ?>" class="<?= $act('abrir-cuenta') ?>">Abrir una cuenta</a></li>
            <?php endif; ?>
            <li><a href="<?= P::attr($rutas->url('movimientos')) ?>" class="<?= $act('movimientos') ?>">Movimientos</a></li>
            <?php if ($alguno(['transferencia.ejecutar','transferencia.ejecutar_terceros'])): ?>
            <li><a href="<?= P::attr($rutas->url('transferir')) ?>" class="<?= $act('transferir') ?>">Entre cuentas CashMan</a></li>
            <?php endif; ?>
            <?php /* A otros bancos. Va junto a la transferencia interna
                     porque para el cliente es la misma pregunta —mandar
                     dinero— y solo cambia hacia dónde. */ ?>
            <?php if ($tiene('transferencia.ejecutar')): ?>
            <li><a href="<?= P::attr($rutas->url('otros-bancos')) ?>" class="<?= $act('otros-bancos') ?>">A otros bancos (Transfer365)</a></li>
            <li><a href="<?= P::attr($rutas->url('otros-bancos/agenda')) ?>" class="<?= $act('otros-bancos/agenda') ?>">Mis destinos</a></li>
            <li><a href="<?= P::attr($rutas->url('otros-bancos/historial')) ?>" class="<?= $act('otros-bancos/historial') ?>">Envíos a otros bancos</a></li>
            <li><a href="<?= P::attr($rutas->url('otros-bancos/limites')) ?>" class="<?= $act('otros-bancos/limites') ?>">Mis límites</a></li>
            <?php /* Pagar la luz, el agua, el colegio. Va en el mismo
                     bloque que las transferencias porque para el cliente
                     es la misma pregunta: sacar dinero de su cuenta. */ ?>
            <li><a href="<?= P::attr($rutas->url('servicios')) ?>" class="<?= $act('servicios') ?>">Pagar servicios</a></li>
            <li><a href="<?= P::attr($rutas->url('servicios/historial')) ?>" class="<?= $act('servicios/historial') ?>">Servicios pagados</a></li>
            <?php endif; /* cierra transferencia.ejecutar */ ?>
            <?php /* Remesas. Para mucha gente de este país no es un
                     ingreso más: es EL ingreso del mes. */ ?>
            <?php if ($alguno(['remesa.ver_propia','remesa.ver'])): ?>
            <li><a href="<?= P::attr($rutas->url('remesas')) ?>" class="<?= $act('remesas') ?>">Mis remesas</a></li>
            <?php endif; ?>
            <?php if ($tiene('remesa.ver')): ?>
            <li><a href="<?= P::attr($rutas->url('remesas/ventanilla')) ?>" class="<?= $act('remesas/ventanilla') ?>">Pago de remesas</a></li>
            <?php endif; ?>
            <?php /* Constancias y solvencias. Se piden constantemente para
                     tramites, y hoy habia que ir a una agencia. */ ?>
            <?php if ($tiene('constancia.emitir_propia')): ?>
            <li><a href="<?= P::attr($rutas->url('constancias')) ?>" class="<?= $act('constancias') ?>">Constancias y solvencias</a></li>
            <?php /* La que se prepara para un cliente que está en el
                     mostrador. Quien solo puede emitir las suyas no la
                     ve: no tiene a quién buscar. */ ?>
            <?php if ($tiene('constancia.emitir_terceros')): ?>
            <li><a href="<?= P::attr($rutas->url('constancias/agencia')) ?>" class="<?= $act('constancias/agencia') ?>">Constancias de un cliente</a></li>
            <?php endif; ?>
            <?php endif; ?>
            <?php /* Plazo fijo. El producto de captacion mas basico
                     que tiene cualquier banco. */ ?>
            <?php if ($tiene('plazo.abrir_propio')): ?>
            <li><a href="<?= P::attr($rutas->url('plazos')) ?>" class="<?= $act('plazos') ?>">Depósitos a plazo</a></li>
            <?php endif; ?>
            <?php if ($tiene('chequera.solicitar')): ?>
            <li><a href="<?= P::attr($rutas->url('chequeras')) ?>" class="<?= $act('chequeras') ?>">Chequeras</a></li>
            <?php endif; ?>
            <?php /* Lo que se paga solo. Va aqui porque para el cliente
                     es la misma idea: sacar dinero de su cuenta sin
                     tener que acordarse cada mes. */ ?>
            <?php if ($tiene('programada.administrar')): ?>
            <li><a href="<?= P::attr($rutas->url('programadas')) ?>" class="<?= $act('programadas') ?>">Pagos programados</a></li>
            <?php endif; ?>
            <?php if ($tiene('deposito.registrar')): ?>
            <li><a href="<?= P::attr($rutas->url('deposito')) ?>" class="<?= $act('deposito') ?>">Registrar depósito</a></li>
            <?php endif; ?>
            <?php if ($tiene('retiro.registrar')): ?>
            <li><a href="<?= P::attr($rutas->url('retiro')) ?>" class="<?= $act('retiro') ?>">Registrar retiro</a></li>
            <?php endif; ?>
            <?php if ($tiene('cuenta.abrir')): ?>
            <li><a href="<?= P::attr($rutas->url('cuentas/abrir')) ?>" class="<?= $act('cuentas/abrir') ?>">Abrir cuenta</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- ---------- TARJETAS ---------- -->
        <?php if ($alguno(['tarjeta.ver_propia','tarjeta.ver_todas'])): ?>
        <li class="<?= $abierto(['tarjetas']) ?>">
          <a class="has-arrow ai-icon" href="javascript:void(0)" aria-expanded="false">
            <?= cm_icono("card") ?><span class="nav-text">Tarjetas</span>
          </a>
          <ul aria-expanded="false">
            <li><a href="<?= P::attr($rutas->url('tarjetas')) ?>" class="<?= $act('tarjetas') ?>">Mis tarjetas</a></li>
            <?php if ($tiene('tarjeta.solicitar')): ?>
            <li><a href="<?= P::attr($rutas->url('tarjetas/catalogo')) ?>" class="<?= $act('tarjetas/catalogo') ?>">Solicitar una tarjeta</a></li>
            <?php /* El recorrido de lo que ya pidio. Antes solo se veia
                     la solicitud en tramite, y de paso, dentro del
                     catalogo: una vez resuelta desaparecia de su vista. */ ?>
            <li><a href="<?= P::attr($rutas->url('tarjetas/mis-solicitudes')) ?>" class="<?= $act('tarjetas/mis-solicitudes') ?>">Mis solicitudes</a></li>
            <?php endif; ?>
            <?php if ($tiene('tarjeta.ver_solicitudes')): ?>
            <li><a href="<?= P::attr($rutas->url('tarjetas/solicitudes')) ?>" class="<?= $act('tarjetas/solicitudes') ?>">Solicitudes de tarjeta</a></li>
            <?php endif; ?>
            <?php if ($tiene('tarjeta.emitir')): ?>
            <li><a href="<?= P::attr($rutas->url('tarjetas?todas=1')) ?>" class="<?= $act('tarjetas?todas=1') ?>">Todas las tarjetas</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- ---------- CRÉDITOS ---------- -->
        <?php if ($alguno(['credito.solicitar','credito.ver_todos','solicitud.capturar'])): ?>
        <li class="<?= $abierto(['creditos','solicitudes','cuotas','contratos','reestructuraciones']) ?>">
          <a class="has-arrow ai-icon" href="javascript:void(0)" aria-expanded="false">
            <?= cm_icono("invest") ?><span class="nav-text">Créditos</span>
          </a>
          <ul aria-expanded="false">
            <li><a href="<?= P::attr($rutas->url('creditos')) ?>" class="<?= $act('creditos') ?>">
              <?= $tiene('credito.ver_todos') ? 'Cartera de créditos' : 'Mis créditos' ?></a></li>
            <li><a href="<?= P::attr($rutas->url('cuotas')) ?>" class="<?= $act('cuotas') ?>">Cuotas por cobrar</a></li>
            <li><a href="<?= P::attr($rutas->url('contratos')) ?>" class="<?= $act('contratos') ?>">Contratos</a></li>
            <li><a href="<?= P::attr($rutas->url('solicitudes')) ?>" class="<?= $act('solicitudes') ?>">Solicitudes</a></li>
            <?php if ($tiene('credito.solicitar')): ?>
            <li><a href="<?= P::attr($rutas->url('solicitudes/nueva')) ?>" class="<?= $act('solicitudes/nueva') ?>">Solicitar crédito</a></li>
            <?php endif; ?>
            <?php if ($alguno(['reestructuracion.solicitar','reestructuracion.aprobar'])): ?>
            <li><a href="<?= P::attr($rutas->url('reestructuraciones')) ?>" class="<?= $act('reestructuraciones') ?>">Reestructuraciones</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- ---------- OFERTAS PREAPROBADAS ----------
             Sin entrada propia, una oferta que caduca en treinta días se
             queda sin aceptar por no encontrarla. Solo aparece cuando
             hay alguna: un menú con puertas a pantallas vacías enseña a
             no abrirlas. -->
        <?php if ($ofertasVivas > 0): ?>
        <li class="<?= $act('ofertas') ?>">
          <a href="<?= P::attr($rutas->url('ofertas')) ?>" aria-expanded="false">
            <?= cm_icono("invest") ?><span class="nav-text">Ofertas para usted</span>
            <span class="badge badge-success ml-2"><?= (int) $ofertasVivas ?></span>
          </a>
        </li>
        <?php endif; ?>

        <!-- ---------- ESTADOS DE CUENTA ---------- -->
        <?php if ($alguno(['estado_cuenta.ver_propio','estado_cuenta.ver_todos'])): ?>
        <li class="<?= $act('estados') ?>">
          <a href="<?= P::attr($rutas->url('estados')) ?>" aria-expanded="false">
            <?= cm_icono("files") ?><span class="nav-text">Estados de cuenta</span>
          </a>
        </li>
        <?php endif; ?>

        <!-- ---------- DOCUMENTOS ---------- -->
        <?php if ($alguno(['documento.ver_propio','documento.ver_todos'])): ?>
        <li class="<?= $act('documentos') ?>">
          <a href="<?= P::attr($rutas->url('documentos')) ?>" aria-expanded="false">
            <?= cm_icono("package") ?><span class="nav-text">Mis documentos</span>
          </a>
        </li>
        <?php endif; ?>
        <!-- ---------- BANCA PREFERENTE ----------
             Visible para todo cliente, no solo para el que ya está
             dentro: a quien no lo está se le enseña qué le falta, que
             es justo lo que lo mueve a concentrar su relación aquí.
             Una puerta que no se ve no atrae a nadie. -->
        <?php if ($alguno(['cuenta.ver_propia', 'tarjeta.ver_propia'])): ?>
        <li class="<?= $act('preferente') ?>">
          <a href="<?= P::attr($rutas->url('preferente')) ?>" aria-expanded="false">
            <?= cm_icono("shield") ?><span class="nav-text">Banca preferente</span>
          </a>
        </li>
        <?php endif; ?>

        <!-- ---------- CAMPAÑA DE OFERTAS ---------- -->
        <?php if ($alguno(['oferta.ver'])): ?>
        <li class="<?= $act('ofertas/cartera') ?>">
          <a href="<?= P::attr($rutas->url('ofertas/cartera')) ?>" aria-expanded="false">
            <?= cm_icono("invest") ?><span class="nav-text">Ofertas preaprobadas</span>
          </a>
        </li>
        <?php endif; ?>

        <!-- ---------- TIENDA ----------
             Va en «Principal» porque para un cliente es tan suyo como
             sus cuentas: es donde usa la tarjeta que le dimos. -->
        <?php if ($alguno(['tienda.comprar','tienda.ver_pedidos'])): ?>
        <li class="<?= $abierto(['tienda']) ?>">
          <a class="has-arrow ai-icon" href="javascript:void(0)" aria-expanded="false">
            <?= cm_icono("package") ?><span class="nav-text">Tienda</span>
          </a>
          <ul aria-expanded="false">
            <!-- La tienda es pública: se abre en otra pestaña porque es
                 el sitio, no el panel. -->
            <li>
              <a href="<?= P::attr($rutas->url('tienda')) ?>" target="_blank" rel="noopener">
                Ver la tienda
              </a>
            </li>
            <li><a href="<?= P::attr($rutas->url('tienda/pedidos')) ?>" class="<?= $act('tienda/pedidos') ?>">Mis pedidos</a></li>
            <?php if ($tiene('tienda.ver_pedidos')): ?>
            <li><a href="<?= P::attr($rutas->url('tienda/pedidos') . '?todos=1') ?>" class="<?= $act('tienda/pedidos?todos=1') ?>">Todos los pedidos</a></li>
            <?php endif; ?>
            <?php if ($tiene('tienda.gestionar')): ?>
            <li><a href="<?= P::attr($rutas->url('tienda/admin')) ?>" class="<?= $act('tienda/admin') ?>">Administrar catálogo</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- ---------- VENTANILLA ----------
             Va antes de «Gestión» a propósito: para un cajero esta es
             su pantalla de trabajo, no una consulta. Pasa el turno
             entero aquí. -->
        <?php /* La cartera, solo para quien de verdad lleva una.
                 El permiso `cliente.ver_expediente` lo tienen tambien
                 gerencia y atencion, asi que se comprueba la asignacion:
                 un menu que lleva a una pantalla vacia es ruido. */ ?>
        <?php if (!empty($usuario['lleva_cartera'])): ?>
        <li class="<?= $act('cartera') ?>">
          <a href="<?= P::attr($rutas->url('cartera')) ?>" aria-expanded="false">
            <?= cm_icono("users") ?><span class="nav-text">Mi cartera</span>
          </a>
        </li>
        <?php endif; ?>
        <?php if ($alguno(['caja.operar','caja.ver_todas','nota.emitir','nota.autorizar'])): ?>
        <li class="nav-label">Ventanilla</li>

        <li class="<?= $abierto(['caja']) ?>">
          <a class="has-arrow ai-icon" href="javascript:void(0)" aria-expanded="false">
            <?= cm_icono("wallet") ?><span class="nav-text">Caja</span>
          </a>
          <ul aria-expanded="false">
            <?php
            /* El menú de la ventanilla, ordenado como el día del cajero.
             *
             * Tenía cinco entradas al mismo nivel —atención, mi caja,
             * notas, recibir un depósito, entregar un retiro— y eso
             * obliga a decidir por dónde entrar antes de saber qué
             * quiere el cliente. Peor: un depósito y un retiro no son
             * sitios a los que se va, son cosas que se le hacen a una
             * persona, y empezaban pidiendo otra vez un número de
             * cuenta que el cajero ya tenía delante.
             *
             * Ahora son tres, y cada una responde a un momento:
             *
             *   · ATENDER      — todo el día, con el cliente delante.
             *                    De ahí salen el depósito, el retiro, el
             *                    cobro de una cuota y el pago de una
             *                    tarjeta, ya sabiendo de quién son.
             *   · MI CAJA      — una vez por la mañana para abrirla y
             *                    una por la tarde para cuadrarla.
             *   · NOTAS        — su propio circuito, porque llevan
             *                    autorización de otra persona.
             *
             * Las operaciones sueltas siguen existiendo y sus rutas no
             * cambian: se llega a ellas desde la ficha del cliente. */
            ?>
            <?php if ($alguno(['caja.operar','caja.ver_todas'])): ?>
            <li><a href="<?= P::attr($rutas->url('caja/atencion')) ?>" class="<?= $act('caja/atencion') ?>">Atender a un cliente</a></li>
            <li><a href="<?= P::attr($rutas->url('caja')) ?>" class="<?= $act('caja') ?>">Mi caja y cuadratura</a></li>
            <?php endif; ?>
            <?php if ($alguno(['nota.emitir','nota.autorizar'])): ?>
            <li><a href="<?= P::attr($rutas->url('caja/notas')) ?>" class="<?= $act('caja/notas') ?>">Notas de abono y cargo</a></li>
            <?php endif; ?>
            <?php /* Las constancias que atención al cliente mandó a cobrar.
                     El cajero necesita verlas sin que el cliente le dicte el
                     folio: a veces llega sin él. */ ?>
            <?php if ($tiene('constancia.cobrar')): ?>
            <li><a href="<?= P::attr($rutas->url('caja/constancias')) ?>" class="<?= $act('caja/constancias') ?>">Constancias por cobrar</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- ---------- GESTIÓN ---------- -->
        <?php if ($alguno(['cartera.ver_morosos','cliente.ver_expediente','usuario.ver_todos','reporte.ver'])): ?>
        <li class="nav-label">Gestión</li>

        <?php if ($tiene('cliente.ver_expediente')): ?>
        <li class="<?= $abierto(['clientes']) ?>">
          <a href="<?= P::attr($rutas->url('clientes')) ?>" aria-expanded="false">
            <?= cm_icono("users") ?><span class="nav-text">Clientes</span>
          </a>
        </li>
        <?php endif; ?>

        <?php if ($alguno(['cartera.ver_morosos','cobranza.ver'])): ?>
        <li class="<?= $abierto(['cartera','cobranza']) ?>">
          <a class="has-arrow ai-icon" href="javascript:void(0)" aria-expanded="false">
            <?= cm_icono("warning") ?><span class="nav-text">Mora y cobranza</span>
          </a>
          <ul aria-expanded="false">
            <?php if ($tiene('cartera.ver_morosos')): ?>
            <li><a href="<?= P::attr($rutas->url('cartera')) ?>" class="<?= $act('cartera') ?>">Cartera morosa</a></li>
            <?php endif; ?>
            <?php if ($tiene('cobranza.ver')): ?>
            <!-- La lista dice cuánto se debe; el expediente dice qué se
                 ha hecho para cobrarlo. Son dos pantallas distintas. -->
            <li><a href="<?= P::attr($rutas->url('cobranza')) ?>" class="<?= $act('cobranza') ?>">Expedientes de cobro</a></li>
            <li><a href="<?= P::attr($rutas->url('cobranza') . '?etapa=extrajudicial') ?>" class="<?= $act('cobranza?etapa=extrajudicial') ?>">Extrajudicial</a></li>
            <li><a href="<?= P::attr($rutas->url('cobranza') . '?etapa=judicial') ?>" class="<?= $act('cobranza?etapa=judicial') ?>">Vía judicial</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <?php if ($tiene('reporte.ver')): ?>
        <li class="<?= $abierto(['reportes']) ?>">
          <a class="has-arrow ai-icon" href="javascript:void(0)" aria-expanded="false">
            <?= cm_icono("chart") ?><span class="nav-text">Reportes</span>
          </a>
          <ul aria-expanded="false">
            <li><a href="<?= P::attr($rutas->url('reportes')) ?>" class="<?= $act('reportes') ?>">Indicadores</a></li>
            <li><a href="<?= P::attr($rutas->url('reportes/transacciones')) ?>" class="<?= $act('reportes/transacciones') ?>">Transacciones</a></li>
            <li><a href="<?= P::attr($rutas->url('reportes/cartera')) ?>" class="<?= $act('reportes/cartera') ?>">Cartera</a></li>
          </ul>
        </li>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ---------- COMUNICACIÓN ---------- -->
        <li class="nav-label">Comunicación</li>

        <?php if ($tiene('mensaje.ver')): ?>
        <li class="<?= $abierto(['mensajes']) ?>">
          <a class="has-arrow ai-icon" href="javascript:void(0)" aria-expanded="false">
            <?= cm_icono("mail") ?><span class="nav-text">Mensajería</span>
          </a>
          <ul aria-expanded="false">
            <li><a href="<?= P::attr($rutas->url('mensajes')) ?>" class="<?= $act('mensajes') ?>">Bandeja de entrada</a></li>
            <li><a href="<?= P::attr($rutas->url('mensajes/enviados')) ?>" class="<?= $act('mensajes/enviados') ?>">Enviados</a></li>
            <li><a href="<?= P::attr($rutas->url('mensajes/nuevo')) ?>" class="<?= $act('mensajes/nuevo') ?>">Redactar</a></li>
          </ul>
        </li>
        <?php endif; ?>

        <li class="<?= $act('notificaciones') ?>">
          <a href="<?= P::attr($rutas->url('notificaciones')) ?>" aria-expanded="false">
            <?= cm_icono("bell") ?><span class="nav-text">Notificaciones</span>
          </a>
        </li>

        <?php if ($alguno(['ticket.crear','ticket.gestionar'])): ?>
        <li class="<?= $abierto(['tickets','reclamos']) ?>">
          <a class="has-arrow ai-icon" href="javascript:void(0)" aria-expanded="false">
            <?= cm_icono("support") ?><span class="nav-text">Soporte</span>
          </a>
          <ul aria-expanded="false">
            <li><a href="<?= P::attr($rutas->url('tickets')) ?>" class="<?= $act('tickets') ?>">
              <?= $tiene('ticket.gestionar') ? 'Todos los tickets' : 'Mis reportes' ?></a></li>
            <li><a href="<?= P::attr($rutas->url('tickets/nuevo')) ?>" class="<?= $act('tickets/nuevo') ?>">Reportar problema</a></li>
            <?php /* Un reclamo NO es un ticket: tiene plazo legal de
                     respuesta y derecho de escalamiento a la Defensoria.
                     Va al lado porque es donde el cliente lo busca. */ ?>
            <?php if ($tiene('reclamo.presentar')): ?>
            <li><a href="<?= P::attr($rutas->url('reclamos')) ?>" class="<?= $act('reclamos') ?>">Reclamos formales</a></li>
            <?php endif; ?>
            <?php if ($tiene('reclamo.atender')): ?>
            <li><a href="<?= P::attr($rutas->url('reclamos/bandeja')) ?>" class="<?= $act('reclamos/bandeja') ?>">Atención de reclamos</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <!-- ---------- ADMINISTRACIÓN ---------- -->
        <?php if ($alguno(['usuario.crear','sistema.configurar','auditoria.ver','acceso.ver_bitacora'])): ?>
        <li class="nav-label">Administración</li>

        <?php if ($tiene('usuario.ver_todos')): ?>
        <li class="<?= $abierto(['usuarios','roles']) ?>">
          <a class="has-arrow ai-icon" href="javascript:void(0)" aria-expanded="false">
            <?= cm_icono("user") ?><span class="nav-text">Usuarios</span>
          </a>
          <ul aria-expanded="false">
            <li><a href="<?= P::attr($rutas->url('usuarios')) ?>" class="<?= $act('usuarios') ?>">Listado</a></li>
            <?php if ($tiene('usuario.crear')): ?>
            <li><a href="<?= P::attr($rutas->url('usuarios/nuevo')) ?>" class="<?= $act('usuarios/nuevo') ?>">Registrar usuario</a></li>
            <?php endif; ?>
            <?php if ($tiene('sistema.configurar')): ?>
            <li><a href="<?= P::attr($rutas->url('roles')) ?>" class="<?= $act('roles') ?>">Roles y permisos</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <?php if ($tiene('sistema.configurar')): ?>
        <li class="<?= $abierto(['productos','limites']) ?>">
          <a class="has-arrow ai-icon" href="javascript:void(0)" aria-expanded="false">
            <?= cm_icono("package") ?><span class="nav-text">Catálogos</span>
          </a>
          <ul aria-expanded="false">
            <li><a href="<?= P::attr($rutas->url('productos')) ?>" class="<?= $act('productos') ?>">Productos</a></li>
            <li><a href="<?= P::attr($rutas->url('limites')) ?>" class="<?= $act('limites') ?>">Límites operativos</a></li>
          </ul>
        </li>
        <?php endif; ?>

        <?php if ($alguno(['auditoria.ver','acceso.ver_bitacora'])): ?>
        <li class="<?= $abierto(['auditoria','accesos','cumplimiento','procesos']) ?>">
          <a class="has-arrow ai-icon" href="javascript:void(0)" aria-expanded="false">
            <?= cm_icono("shield") ?><span class="nav-text">Seguridad</span>
          </a>
          <ul aria-expanded="false">
            <?php if ($tiene('auditoria.ver')): ?>
            <li><a href="<?= P::attr($rutas->url('auditoria')) ?>" class="<?= $act('auditoria') ?>">Bitácora de auditoría</a></li>
            <?php /* Prevención de lavado. Va aquí, junto a la bitácora,
                     porque son el mismo oficio: mirar lo que pasó para
                     poder responder por ello. */ ?>
            <?php if ($tiene('cumplimiento.ver')): ?>
            <li><a href="<?= P::attr($rutas->url('cumplimiento')) ?>" class="<?= $act('cumplimiento') ?>">Prevención de lavado</a></li>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($tiene('acceso.ver_bitacora')): ?>
            <li><a href="<?= P::attr($rutas->url('accesos')) ?>" class="<?= $act('accesos') ?>">Accesos al sistema</a></li>
            <?php endif; ?>
            <?php if ($tiene('auditoria.ver')): ?>
            <li><a href="<?= P::attr($rutas->url('auditoria/integridad')) ?>" class="<?= $act('auditoria/integridad') ?>">Verificar integridad</a></li>
            <?php endif; ?>
            <?php if ($alguno(['mora.devengar', 'tarjeta.cortar', 'parametro.editar'])): ?>
            <li><a href="<?= P::attr($rutas->url('procesos')) ?>" class="<?= $act('procesos') ?>">Procesos periódicos</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ---------- MI CUENTA ---------- -->
        <li class="nav-label">Mi cuenta</li>

        <li class="<?= $act('perfil') ?>">
          <a href="<?= P::attr($rutas->url('perfil')) ?>" aria-expanded="false">
            <?= cm_icono("user") ?><span class="nav-text">Mi perfil</span>
          </a>
        </li>

        <li class="<?= $act('seguridad') ?>">
          <a href="<?= P::attr($rutas->url('seguridad')) ?>" aria-expanded="false">
            <?= cm_icono("shield") ?><span class="nav-text">Seguridad y 2FA</span>
          </a>
        </li>

        <li class="<?= $act('perfil/sesiones') ?>">
          <a href="<?= P::attr($rutas->url('perfil/sesiones')) ?>" aria-expanded="false">
            <?= cm_icono("device") ?><span class="nav-text">Mis dispositivos</span>
          </a>
        </li>

        <li>
          <a href="<?= P::attr($rutas->url('salir')) ?>" aria-expanded="false">
            <?= cm_icono("exit") ?><span class="nav-text">Cerrar sesión</span>
          </a>
        </li>
      </ul>

      <div class="copyright px-4 py-3">
        <p class="mb-1"><strong>CashMan H.A.</strong> &middot; v2</p>
        <p class="fs-12 mb-0">&copy; <?= date('Y') ?> Todos los derechos reservados</p>
      </div>
    </div>
  </div>

  <!-- ===================== CONTENIDO ===================== -->
  <div class="content-body">
    <?php /* `cm-vista` es lo que la navegacion en vivo sustituye al
             cambiar de pantalla: todo lo que esta fuera —menu,
             cabecera, reloj, avisos— se queda donde esta y no
             parpadea. */ ?>
    <div class="container-fluid" id="cm-vista">
      <?php /* El resultado de la accion anterior, cuando se llego
               aqui por redirect y no por AJAX. */ ?>
      <?= P::crudo($avisoUrl ?? '') ?>
      <?= P::crudo($contenido) ?>
    </div>
  </div>

  <!-- ===================== FOOTER ===================== -->
  <div class="footer">
    <div class="copyright">
      <p>CashMan H.A. S.A. de C.V. &mdash; Sistema financiero
         &copy; <?= date('Y') ?></p>
    </div>
  </div>

</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
  window.CM = {
    sesion: <?= P::js($rutas->url('api/sesion')) ?>,
    entrar: <?= P::js($rutas->url('entrar')) ?>,
    salir:  <?= P::js($rutas->url('salir')) ?>,
    base:  <?= P::js($rutas->url('')) ?>,
    sse:   <?= P::js($rutas->url('eventos')) ?>,
    pulso: <?= P::js($rutas->url('api/pulso')) ?>,
    notif: <?= P::js($rutas->url('api/notificaciones')) ?>,
    csrf:  <?= P::js(P::tokenCsrf('api')) ?>,
    nonce: <?= P::js(P::nonce()) ?>
  };
</script>

<script src="<?= P::attr($P) ?>vendor/global/global.min.js"></script>
<script src="<?= P::attr($P) ?>vendor/bootstrap-select/dist/js/bootstrap-select.min.js"></script>
<script src="<?= P::attr($P) ?>vendor/datatables/js/jquery.dataTables.min.js"></script>


<script src="<?= P::attr($P) ?>vendor/chart.js/Chart.bundle.min.js"></script>
<script src="<?= P::attr($P) ?>js/custom.min.js"></script>
<script src="<?= P::attr($P) ?>js/deznav-init.js"></script>
<script src="<?= P::attr($rutas->url('assets/js/vivo.js')) ?>"></script>
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
<?php $ssv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/sesion.js') ?: time(); ?>
<script src="<?= P::attr($rutas->url('assets/js/avisos.js') . '?v=' . $avv) ?>"></script>
<script src="<?= P::attr($rutas->url('assets/js/validacion.js') . '?v=' . $vav) ?>"></script>
<script src="<?= P::attr($rutas->url('assets/js/sesion.js') . '?v=' . $ssv) ?>"></script>
<?php $jsv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/cm.js') ?: time(); ?>
<script src="<?= P::attr($rutas->url('assets/js/cm.js') . '?v=' . $jsv) ?>"></script>
<?php /* La navegación en vivo. Va DESPUÉS de cm.js porque le
         pide prestado el enganche de las tablas, y antes de
         los guiones de pantalla, que ya la encuentran puesta. */ ?>
<?php $vvv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/vista-viva.js') ?: time(); ?>
<script src="<?= P::attr($rutas->url('assets/js/vista-viva.js') . '?v=' . $vvv) ?>"></script>
<?php $clv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/calendario.js') ?: time(); ?>
<script src="<?= P::attr($rutas->url('assets/js/calendario.js') . '?v=' . $clv) ?>"></script>
<?php $mcv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/menu-compacto.js') ?: time(); ?>
<script src="<?= P::attr($rutas->url('assets/js/menu-compacto.js') . '?v=' . $mcv) ?>"></script>
<?php $arv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/archivo.js') ?: time(); ?>
<script src="<?= P::attr($rutas->url('assets/js/archivo.js') . '?v=' . $arv) ?>"></script>

<?php /* ===================== LA BIENVENIDA =====================

   Cuando a un cliente se le emite una tarjeta, se enteraba porque
   aparecía una fila más en su listado, con el mismo aviso de cuatro
   segundos que usa el sistema para decir «guardado». Para el banco es
   una fila más; para quien recibe su primera tarjeta de crédito no lo
   es.

   Se pregunta solo si quien está dentro TIENE tarjetas. Al personal no
   se le emiten, y ahorrarse la consulta en todas sus pantallas es
   gratis. La respuesta es `{hay:false}` casi siempre; la marca de ya
   vista va en la propia tarjeta, así que no se repite. */ ?>
<?php if (in_array('tarjeta.ver_propia', $permisos, true)): ?>
<?php $fsv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/fiesta.js') ?: time(); ?>
<script src="<?= P::attr($rutas->url('assets/js/fiesta.js') . '?v=' . $fsv) ?>"></script>
<?php /* Con su nonce. Sin el, la politica de seguridad bloquea el
         guion entero y la bienvenida de una tarjeta recien emitida
         no aparece jamas: queda en la consola como «Executing inline
         script violates the following Content Security Policy» y
         nadie se entera. */ ?>
<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  if (!window.fetch || !window.CMFiesta) { return; }

  /* Se espera a que la pantalla esté quieta. Soltar el confeti encima
     de una página que todavía se está colocando queda mal y, sobre
     todo, tapa lo que el cliente vino a hacer. */
  var arrancar = function () {
    fetch('<?= P::attr($rutas->url('tarjetas/bienvenida')) ?>', {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || !j.hay || !j.bienvenida) { return; }
        window.CMFiesta.abrir(j.bienvenida);
      })
      .catch(function () { /* sin felicitación; la tarjeta está igual */ });
  };

  if (document.readyState === 'complete') {
    setTimeout(arrancar, 700);
  } else {
    window.addEventListener('load', function () { setTimeout(arrancar, 700); });
  }
})();
</script>
<?php endif; ?>

<?php if (!empty($scripts)): ?><?= P::crudo($scripts) ?><?php endif; ?>

</body>
</html>
