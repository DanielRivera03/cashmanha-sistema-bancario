<?php
/** @var \CashMan\Http\Enrutador $rutas */
/** @var int $codigo */
/** @var string $encabezado */
/** @var string $descripcion */
use CashMan\Http\Plantilla as P;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= P::e($titulo ?? 'Error') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= P::attr($rutas->url('assets/css/app.css')) ?>">
</head>
<body>
<div style="min-height:100vh;display:grid;place-items:center;padding:1.5rem">
  <div style="text-align:center;max-width:430px">

    <div style="font-size:clamp(3.5rem,11vw,5.5rem);font-weight:800;letter-spacing:-.045em;
                line-height:1;color:var(--marca-600);margin-bottom:.35rem">
      <?= (int) $codigo ?>
    </div>

    <h1 style="margin-bottom:.6rem"><?= P::e($encabezado) ?></h1>
    <p class="txt-2" style="margin-bottom:1.6rem"><?= P::e($descripcion) ?></p>

    <div class="flex gap" style="justify-content:center;flex-wrap:wrap">
      <a href="<?= P::attr($rutas->url(($autenticado ?? false) ? 'panel' : 'entrar')) ?>"
         class="btn btn--primario">
        <?= ($autenticado ?? false) ? 'Volver al panel' : 'Ir a ingresar' ?>
      </a>
      <button data-cm-atras class="btn btn--secundario">Regresar</button>
    </div>

  </div>
</div>
</body>
</html>
