<?php
/** @var \CashMan\Http\Enrutador $rutas */
/** @var array|null $usuario */
/** @var string $contenido */
use CashMan\Http\Plantilla as P;
use CashMan\Infraestructura\Seguridad\Autorizacion;

$permisos = $usuario['permisos'] ?? [];
$tiene = static fn(string $p): bool => in_array($p, $permisos, true);

$actual = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$esActivo = static function (string $ruta) use ($actual, $rutas): string {
    $destino = rtrim($rutas->url($ruta), '/');
    return rtrim($actual, '/') === $destino ? ' activo' : '';
};
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= P::e($titulo ?? 'CashMan H.A.') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= P::attr($rutas->url('assets/css/app.css')) ?>">
</head>
<body>

<div class="app">

  <!-- ============ BARRA LATERAL ============ -->
  <nav class="barra" id="barra" aria-label="Navegación principal">
    <div class="barra__cabecera">
      <svg width="26" height="26" viewBox="0 0 32 32" fill="none" aria-hidden="true">
        <rect width="32" height="32" rx="8" fill="#6418c3"/>
        <path d="M9 20V12m0 0h5.5a3 3 0 010 6H9m14-6v8m0-8h-4a3 3 0 00-3 3v5"
              stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      CashMan H.A.
    </div>

    <div class="barra__nav">

      <div class="barra__grupo">
        <div class="barra__grupo-titulo">Principal</div>

        <a href="<?= P::attr($rutas->url('panel')) ?>" class="barra__enlace<?= $esActivo('panel') ?>">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/>
            <rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>
          </svg>
          Panel
        </a>

        <a href="<?= P::attr($rutas->url('cuentas')) ?>" class="barra__enlace<?= $esActivo('cuentas') ?>">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20" stroke-linecap="round"/>
          </svg>
          Mis cuentas
        </a>

        <a href="<?= P::attr($rutas->url('movimientos')) ?>" class="barra__enlace<?= $esActivo('movimientos') ?>">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M3 7h13l-3-3M21 17H8l3 3" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          Movimientos
        </a>
      </div>

      <div class="barra__grupo">
        <div class="barra__grupo-titulo">Operaciones</div>

        <?php if ($tiene('transferencia.ejecutar') || $tiene('transferencia.ejecutar_terceros')): ?>
        <a href="<?= P::attr($rutas->url('transferir')) ?>" class="barra__enlace<?= $esActivo('transferir') ?>">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M12 19V5M5 12l7-7 7 7" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          Transferir
        </a>
        <?php endif; ?>

        <?php if ($tiene('tarjeta.ver_propia') || $tiene('tarjeta.ver_todas')): ?>
        <a href="<?= P::attr($rutas->url('tarjetas')) ?>" class="barra__enlace<?= $esActivo('tarjetas') ?>">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20" stroke-linecap="round"/>
            <path d="M6 15h3" stroke-linecap="round"/>
          </svg>
          Tarjetas
        </a>
        <?php endif; ?>

        <?php if ($tiene('estado_cuenta.ver_propio') || $tiene('estado_cuenta.ver_todos')): ?>
        <a href="<?= P::attr($rutas->url('estados')) ?>" class="barra__enlace<?= $esActivo('estados') ?>">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" stroke-linejoin="round"/>
            <path d="M14 2v6h6M8 13h8M8 17h5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          Estados de cuenta
        </a>
        <?php endif; ?>
      </div>

      <?php if ($tiene('cartera.ver_morosos') || $tiene('reporte.ver')): ?>
      <div class="barra__grupo">
        <div class="barra__grupo-titulo">Gestión</div>

        <?php if ($tiene('cartera.ver_morosos')): ?>
        <a href="<?= P::attr($rutas->url('cartera')) ?>" class="barra__enlace<?= $esActivo('cartera') ?>">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z" stroke-linejoin="round"/>
            <path d="M12 9v4M12 17h.01" stroke-linecap="round"/>
          </svg>
          Cartera morosa
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

    </div>

    <div class="barra__pie">
      <div class="barra__usuario">
        <span class="avatar"><?= P::e($usuario['iniciales'] ?? '?') ?></span>
        <div class="barra__usuario-datos">
          <div class="barra__usuario-nombre"><?= P::e($usuario['nombre_completo'] ?? '') ?></div>
          <div class="barra__usuario-rol"><?= P::e($usuario['rol_nombre'] ?? '') ?></div>
        </div>
        <a href="<?= P::attr($rutas->url('salir')) ?>" class="icono-btn" title="Cerrar sesión"
           style="color:var(--marca-300)">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"
                  stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </a>
      </div>
    </div>
  </nav>

  <!-- ============ CONTENIDO ============ -->
  <div class="principal">

    <header class="cabecera">
      <button class="icono-btn" id="alternar-barra" aria-label="Abrir menú"
              style="display:none" aria-expanded="false">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path d="M3 12h18M3 6h18M3 18h18" stroke-linecap="round"/>
        </svg>
      </button>

      <h1 class="cabecera__titulo"><?= P::e($encabezado ?? $titulo ?? 'Panel') ?></h1>

      <div class="cabecera__acciones">
        <span class="en-vivo" id="estado-vivo" title="Estado de la conexión en tiempo real">
          <span class="en-vivo__punto"></span>
          <span class="en-vivo__texto">conectando…</span>
        </span>

        <div class="capa">
          <button class="icono-btn" id="btn-notif" aria-label="Notificaciones" aria-expanded="false">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0"
                    stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <?php $sl = (int) ($usuario['sin_leer'] ?? 0); ?>
            <span class="icono-btn__punto<?= $sl === 0 ? ' oculto' : '' ?>" id="contador-notif">
              <?= $sl > 99 ? '99+' : $sl ?>
            </span>
          </button>
          <div class="panel-notif oculto" id="panel-notif" role="region" aria-label="Notificaciones"></div>
        </div>

        <button class="icono-btn" id="btn-tema" aria-label="Cambiar tema">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
      </div>
    </header>

    <main class="contenido">
      <div class="contenedor">
        <?= P::crudo($contenido) ?>
      </div>
    </main>
  </div>
</div>

<div class="brindis-zona" id="brindis-zona" aria-live="polite" aria-atomic="false"></div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
  window.CM = {
    base: <?= P::js($rutas->url('')) ?>,
    sse:  <?= P::js($rutas->url('eventos')) ?>,
    pulso:<?= P::js($rutas->url('api/pulso')) ?>,
    notif:<?= P::js($rutas->url('api/notificaciones')) ?>,
    csrf: <?= P::js(P::tokenCsrf('api')) ?>
  };
</script>
<script src="<?= P::attr($rutas->url('assets/js/vivo.js')) ?>"></script>
<script src="<?= P::attr($rutas->url('assets/js/app.js')) ?>"></script>
</body>
</html>
