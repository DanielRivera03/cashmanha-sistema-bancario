<?php
/**
 * Banca personal.
 *
 * Cada familia de producto se muestra por separado y con SU detalle,
 * leído del catálogo: una tarjeta enseña su marca, su nivel, su línea,
 * su ingreso mínimo, su membresía y sus beneficios; un crédito, su
 * rango de monto y de plazo. Nada se escribe a mano: todo sale de los
 * productos registrados (ControladorSitio::catalogo()).
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $productos
 */
use CashMan\Http\Plantilla as P;

require __DIR__ . '/_graficos.php';

$familias = [
    'ahorro'    => ['Cuentas de ahorro', 'ahorro',
                    'Su dinero disponible siempre que lo necesite y, mientras tanto, '
                    . 'generando un rendimiento para usted.', 'Cuenta'],
    'corriente' => ['Cuentas corrientes', 'ahorro',
                    'Para el movimiento diario de su dinero, con chequera y todas las '
                    . 'facilidades para pagar y cobrar.', 'Cuenta'],
    'credito'   => ['Créditos', 'credito',
                    'Una cuota fija todos los meses, sin sorpresas a mitad del camino. '
                    . 'Usted sabe desde el primer día cuánto va a pagar.', 'Crédito'],
    'tarjeta'   => ['Tarjetas de crédito', 'tarjeta',
                    'Para sus compras aquí y en el extranjero, con un límite acordado '
                    . 'con usted desde el inicio. Elija la que va con usted.', 'Tarjeta'],
];

$dinero = static fn(int $cent): string => '$' . number_format($cent / 100, 2);

/* Una fila de ficha: etiqueta a la izquierda, dato a la derecha. */
$spec = static function (string $k, string $v): string {
    return '<div style="display:flex;justify-content:space-between;gap:12px;padding:7px 0;'
        . 'border-bottom:1px solid var(--borde,#e8ecf2)">'
        . '<span style="color:var(--suave,#667085);font-size:.85rem">' . P::e($k) . '</span>'
        . '<span style="font-weight:600;font-size:.9rem;text-align:right">' . P::e($v) . '</span></div>';
};

/* Una etiqueta/pastilla pequeña. */
$pill = static function (string $txt, string $bg = '#f0ecfb', string $fg = '#6418c3'): string {
    return '<span style="display:inline-block;padding:3px 10px;border-radius:999px;'
        . 'background:' . $bg . ';color:' . $fg . ';font-size:.72rem;font-weight:700;'
        . 'letter-spacing:.02em;text-transform:uppercase">' . P::e($txt) . '</span>';
};
?>

<section class="sitio-hero">
  <?= P::crudo($escenario('costa')) ?>

  <div class="sitio-ancho">
    <span class="sitio-etiqueta"><?= P::crudo($ico($D['usuarios'], 13)) ?> Para usted y su familia</span>
    <h1>Lo que tenemos<br>para ofrecerle</h1>
    <p class="sitio-entrada">
      Una cuenta donde guardar su dinero con tranquilidad, un crédito
      cuando llega el momento de dar un paso y una tarjeta para lo
      demás. Cada una con sus condiciones claras, aquí mismo.
    </p>
    <div class="sitio-acciones">
      <a class="sitio-btn sitio-btn--primario" href="<?= P::attr($rutas->url('contacto')) ?>">
        Quiero abrir mi cuenta
      </a>
      <a class="sitio-btn sitio-btn--linea" href="<?= P::attr($rutas->url('entrar')) ?>">
        Ya soy cliente
      </a>
    </div>
  </div>
</section>

<?php $n = 0; foreach ($familias as $tipo => [$titulo, $icono, $texto, $etiqueta]):
  $items = $productos[$tipo] ?? [];
  if ($items === []) { continue; }
  $n++;
?>
<section class="sitio-seccion <?= $n % 2 === 0 ? 'sitio-seccion--tenue' : '' ?>" id="<?= P::attr($tipo) ?>">
  <div class="sitio-ancho">
    <div class="sitio-titulo">
      <h2><?= P::crudo($ico($D[$icono], 20)) ?> <?= P::e($titulo) ?></h2>
      <p><?= P::e($texto) ?></p>
    </div>

    <div class="sitio-rejilla">
      <?php foreach ($items as $p):
        $marca = trim((string) ($p['marca_emision'] ?? ''));
      ?>
      <article class="sitio-tarjeta">

        <!-- Cabecera: qué tipo es y, si es tarjeta, su marca y su nivel -->
        <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:.5rem">
          <?= P::crudo($pill($etiqueta)) ?>
          <?php if ($tipo === 'tarjeta' && $marca !== ''): ?>
            <?= P::crudo($pill($marca, '#eef2f7', '#344054')) ?>
          <?php endif; ?>
          <?php if ($tipo === 'tarjeta' && (int) $p['nivel'] > 0): ?>
            <?= P::crudo($pill('Nivel ' . (int) $p['nivel'], '#fff5e6', '#b5700c')) ?>
          <?php endif; ?>
        </div>

        <h3><?= P::e($p['nombre']) ?></h3>
        <p>
          <?= P::e($p['descripcion'] !== ''
                   ? $p['descripcion']
                   : 'Con gusto le detallamos las condiciones en cualquiera de nuestras agencias.') ?>
        </p>

        <?php
          // La tasa: su rango si lo tiene, si no la tasa única.
          $tasaTxt = $p['tasa_rango'] !== '' ? $p['tasa_rango'] : ($p['tasa'] ?? '');
          $esFinanciero = in_array($tipo, ['credito', 'tarjeta'], true);
        ?>
        <?php if ((int) $p['tasa_interes_bp'] > 0 || $p['tasa_rango'] !== ''): ?>
        <div class="sitio-tasa">
          <b><?= P::e($tasaTxt) ?></b>
          <span><?= $esFinanciero ? 'tasa anual' : 'rendimiento anual' ?></span>
        </div>
        <?php endif; ?>

        <!-- La ficha, distinta según el producto -->
        <div style="margin:.6rem 0 1rem">
        <?php if ($tipo === 'tarjeta'): ?>
            <?php if ((int) $p['linea_desde_cent'] > 0): ?>
              <?= P::crudo($spec('Línea de crédito desde', $dinero((int) $p['linea_desde_cent']))) ?>
            <?php endif; ?>
            <?php if ((int) $p['ingreso_minimo_cent'] > 0): ?>
              <?= P::crudo($spec('Ingreso mínimo', $dinero((int) $p['ingreso_minimo_cent']))) ?>
            <?php endif; ?>
            <?= P::crudo($spec('Membresía anual',
                  (int) $p['membresia_anual_cent'] > 0
                      ? $dinero((int) $p['membresia_anual_cent'])
                      : 'Sin membresía')) ?>

        <?php elseif ($tipo === 'credito'): ?>
            <?php if ((int) $p['monto_maximo_cent'] > 0): ?>
              <?= P::crudo($spec('Monto',
                    $dinero((int) $p['monto_minimo_cent']) . ' a ' . $dinero((int) $p['monto_maximo_cent']))) ?>
            <?php endif; ?>
            <?php if ((int) $p['plazo_maximo'] > 0): ?>
              <?= P::crudo($spec('Plazo',
                    (int) $p['plazo_minimo'] . ' a ' . (int) $p['plazo_maximo'] . ' meses')) ?>
            <?php endif; ?>

        <?php else: // ahorro / corriente ?>
            <?= P::crudo($spec('Para abrirla',
                  (int) $p['saldo_minimo_cent'] > 0
                      ? $dinero((int) $p['saldo_minimo_cent'])
                      : 'Sin monto mínimo')) ?>
        <?php endif; ?>
        </div>

        <!-- Beneficios registrados de la tarjeta -->
        <?php if ($tipo === 'tarjeta' && !empty($p['beneficios_lista'])): ?>
        <ul class="sitio-lista" style="margin-bottom:1rem">
          <?php foreach ($p['beneficios_lista'] as $b): ?>
          <li><?= P::crudo($ico($D['check'], 16)) ?> <span><?= P::e($b) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <?php elseif ($tipo !== 'tarjeta'): ?>
        <ul class="sitio-lista" style="margin-bottom:1rem">
          <li><?= P::crudo($ico($D['check'], 16)) ?> <span>Consúltelo cuando quiera desde su teléfono</span></li>
          <li><?= P::crudo($ico($D['check'], 16)) ?> <span>Su estado de cuenta cada mes, listo para descargar</span></li>
        </ul>
        <?php endif; ?>

        <div class="sitio-tarjeta__pie">
          <a href="<?= P::attr($rutas->url('contacto')) ?>">Me interesa, quiero más información →</a>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endforeach; ?>

<!-- ---------------- Compare todas las tarjetas ---------------- -->
<?php $tarjetas = $productos['tarjeta'] ?? []; if ($tarjetas !== []): ?>
<section class="sitio-seccion sitio-seccion--tenue">
  <div class="sitio-ancho">
    <div class="sitio-titulo">
      <h2>Compare nuestras tarjetas</h2>
      <p>Todas, una al lado de la otra, para que vea cuál encaja con usted.</p>
    </div>

    <div class="sitio-tabla-caja">
      <table class="sitio-tabla">
        <thead>
          <tr>
            <th>Tarjeta</th>
            <th>Marca</th>
            <th>Línea desde</th>
            <th>Ingreso mínimo</th>
            <th>Membresía anual</th>
            <th>Tasa anual</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tarjetas as $p): ?>
          <tr>
            <td>
              <?= P::e($p['nombre']) ?>
              <?php if ((int) $p['nivel'] > 0): ?>
                <small style="color:var(--suave,#667085)">· nivel <?= (int) $p['nivel'] ?></small>
              <?php endif; ?>
            </td>
            <td><?= P::e(trim((string) $p['marca_emision']) !== '' ? $p['marca_emision'] : '—') ?></td>
            <td><?= P::e((int) $p['linea_desde_cent'] > 0 ? $dinero((int) $p['linea_desde_cent']) : '—') ?></td>
            <td><?= P::e((int) $p['ingreso_minimo_cent'] > 0 ? $dinero((int) $p['ingreso_minimo_cent']) : '—') ?></td>
            <td><?= P::e((int) $p['membresia_anual_cent'] > 0 ? $dinero((int) $p['membresia_anual_cent']) : 'Sin membresía') ?></td>
            <td class="sitio-tabla__dato"><?= P::e($p['tasa_rango'] !== '' ? $p['tasa_rango'] : $p['tasa']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ---------------- Tasas de créditos y cuentas ---------------- -->
<?php
$financieros = array_merge(
    $productos['credito'] ?? [],
    $productos['ahorro'] ?? [],
    $productos['corriente'] ?? []
);
if ($financieros !== []):
?>
<section class="sitio-seccion">
  <div class="sitio-ancho">
    <div class="sitio-titulo">
      <h2>Créditos y cuentas, de un vistazo</h2>
      <p>
        Las tasas las compartimos abiertamente para que pueda comparar
        con calma. La que se aplique a su operación quedará por escrito
        en su contrato, y se la explicaremos antes de que firme.
      </p>
    </div>

    <div class="sitio-tabla-caja">
      <table class="sitio-tabla">
        <thead>
          <tr>
            <th>Producto</th>
            <th>Tipo</th>
            <th>Monto / para abrirla</th>
            <th>Plazo</th>
            <th>Tasa anual</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (['credito' => 'Crédito', 'ahorro' => 'Ahorro', 'corriente' => 'Corriente'] as $t => $et): ?>
            <?php foreach (($productos[$t] ?? []) as $p): ?>
            <tr>
              <td><?= P::e($p['nombre']) ?></td>
              <td><?= P::e($et) ?></td>
              <td>
                <?php if ($t === 'credito' && (int) $p['monto_maximo_cent'] > 0): ?>
                  <?= P::e($dinero((int) $p['monto_minimo_cent']) . ' – ' . $dinero((int) $p['monto_maximo_cent'])) ?>
                <?php else: ?>
                  <?= P::e((int) $p['saldo_minimo_cent'] > 0 ? $dinero((int) $p['saldo_minimo_cent']) : '—') ?>
                <?php endif; ?>
              </td>
              <td>
                <?= P::e($t === 'credito' && (int) $p['plazo_maximo'] > 0
                         ? (int) $p['plazo_minimo'] . '–' . (int) $p['plazo_maximo'] . ' meses'
                         : '—') ?>
              </td>
              <td class="sitio-tabla__dato"><?= P::e($p['tasa_rango'] !== '' ? $p['tasa_rango'] : ((int) $p['tasa_interes_bp'] > 0 ? $p['tasa'] : '—')) ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p style="font-size:.84rem;color:var(--suave);margin-top:1rem">
      Sus créditos y tarjetas incluyen el seguro de vida sobre saldo
      deudor y el seguro de desempleo. Son los que protegen a su familia
      si algo llegara a pasarle, y se los detallamos en su cuota.
    </p>
  </div>
</section>
<?php endif; ?>

<section class="sitio-llamada">
  <div class="sitio-ancho">
    <div>
      <h2>¿Le ayudamos a elegir?</h2>
      <p>
        Cuéntenos qué necesita y le decimos con franqueza cuál le
        conviene. Sin compromiso y sin prisas.
      </p>
    </div>
    <a class="sitio-btn sitio-btn--claro" href="<?= P::attr($rutas->url('contacto')) ?>">
      Escribirnos
    </a>
  </div>
</section>
