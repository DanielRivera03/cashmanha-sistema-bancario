<?php
/**
 * Banca de empresas.
 *
 * Muestra solo los productos cuyo mercado es empresa (o ambos), con su
 * detalle real del catálogo. Antes se adivinaba por el nombre y se
 * perdían el crédito y la tarjeta de empresa; ahora el controlador ya
 * filtra por `mercado` y aquí solo se recorre lo que llega.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $productos
 */
use CashMan\Http\Plantilla as P;

require __DIR__ . '/_graficos.php';

$familias = [
    'ahorro'    => ['Cuentas para su empresa', 'ahorro',
                    'Para operar el día a día y para guardar lo que va dejando el negocio.', 'Cuenta'],
    'corriente' => ['Cuentas corrientes', 'corriente',
                    'El movimiento diario de su negocio, con chequera y todas las facilidades.', 'Cuenta'],
    'credito'   => ['Financiamiento', 'credito',
                    'Capital para crecer, con una cuota clara desde el primer día.', 'Crédito'],
    'tarjeta'   => ['Tarjetas', 'tarjeta',
                    'Para los gastos del negocio, con control y estado de cuenta ordenado.', 'Tarjeta'],
];

$dinero = static fn(int $cent): string => '$' . number_format($cent / 100, 2);

$spec = static function (string $k, string $v): string {
    return '<div style="display:flex;justify-content:space-between;gap:12px;padding:7px 0;'
        . 'border-bottom:1px solid var(--borde,#e8ecf2)">'
        . '<span style="color:var(--suave,#667085);font-size:.85rem">' . P::e($k) . '</span>'
        . '<span style="font-weight:600;font-size:.9rem;text-align:right">' . P::e($v) . '</span></div>';
};

$pill = static function (string $txt, string $bg = '#f0ecfb', string $fg = '#6418c3'): string {
    return '<span style="display:inline-block;padding:3px 10px;border-radius:999px;'
        . 'background:' . $bg . ';color:' . $fg . ';font-size:.72rem;font-weight:700;'
        . 'letter-spacing:.02em;text-transform:uppercase">' . P::e($txt) . '</span>';
};

$hayProductos = array_sum(array_map('count', $productos)) > 0;
?>

<section class="sitio-hero">
  <?= P::crudo($escenario('ciudad')) ?>

  <div class="sitio-ancho">
    <span class="sitio-etiqueta"><?= P::crudo($ico($D['maletin'], 13)) ?> Para su negocio</span>
    <h1>Su empresa merece<br>un banco que la entienda</h1>
    <p class="sitio-entrada">
      Cuentas para operar el día a día, financiamiento cuando llega el
      momento de crecer y un registro ordenado de cada movimiento, para
      que su contabilidad siempre cuadre. Será un gusto conocer su
      proyecto.
    </p>
    <div class="sitio-acciones">
      <a class="sitio-btn sitio-btn--primario" href="<?= P::attr($rutas->url('contacto')) ?>">
        Quiero hablar con un ejecutivo
      </a>
      <a class="sitio-btn sitio-btn--linea" href="<?= P::attr($rutas->url('entrar')) ?>">
        Entrar a mi cuenta
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
                   : 'Su ejecutivo le explicará las condiciones con todo detalle.') ?>
        </p>

        <?php
          $tasaTxt = $p['tasa_rango'] !== '' ? $p['tasa_rango'] : ($p['tasa'] ?? '');
          $esFinanciero = in_array($tipo, ['credito', 'tarjeta'], true);
        ?>
        <?php if ((int) $p['tasa_interes_bp'] > 0 || $p['tasa_rango'] !== ''): ?>
        <div class="sitio-tasa">
          <b><?= P::e($tasaTxt) ?></b>
          <span><?= $esFinanciero ? 'tasa anual' : 'rendimiento anual' ?></span>
        </div>
        <?php endif; ?>

        <div style="margin:.6rem 0 1rem">
        <?php if ($tipo === 'tarjeta'): ?>
            <?php if ((int) $p['linea_desde_cent'] > 0): ?>
              <?= P::crudo($spec('Línea de crédito desde', $dinero((int) $p['linea_desde_cent']))) ?>
            <?php endif; ?>
            <?php if ((int) $p['ingreso_minimo_cent'] > 0): ?>
              <?= P::crudo($spec('Ingreso / facturación mínima', $dinero((int) $p['ingreso_minimo_cent']))) ?>
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

        <?php if ($tipo === 'tarjeta' && !empty($p['beneficios_lista'])): ?>
        <ul class="sitio-lista" style="margin-bottom:1rem">
          <?php foreach ($p['beneficios_lista'] as $b): ?>
          <li><?= P::crudo($ico($D['check'], 16)) ?> <span><?= P::e($b) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <?php elseif ($tipo !== 'tarjeta'): ?>
        <ul class="sitio-lista" style="margin-bottom:1rem">
          <li><?= P::crudo($ico($D['check'], 16)) ?> <span>Movimientos sin límite</span></li>
          <li><?= P::crudo($ico($D['check'], 16)) ?> <span>Su comprobante en cada operación</span></li>
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

<?php if (!$hayProductos): ?>
<section class="sitio-seccion">
  <div class="sitio-ancho">
    <div class="sitio-rejilla">
      <article class="sitio-tarjeta">
        <h3>Conversemos</h3>
        <p>Cada negocio es distinto, así que preferimos escucharlo antes
           de proponerle nada. Cuéntenos a qué se dedica y le preparamos
           una propuesta hecha a su medida.</p>
        <div class="sitio-tarjeta__pie">
          <a href="<?= P::attr($rutas->url('contacto')) ?>">Pedir una propuesta →</a>
        </div>
      </article>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="sitio-seccion sitio-seccion--tenue">
  <div class="sitio-ancho">
    <div class="sitio-titulo">
      <h2>Lo que su contador nos va a agradecer</h2>
      <p>Porque sabemos que al final del mes alguien tiene que cuadrar todo.</p>
    </div>

    <div class="sitio-rejilla">
      <article class="sitio-tarjeta">
        <div class="sitio-tarjeta__icono"><?= P::crudo($ico($D['libro'], 22)) ?></div>
        <h3>Sus cuentas siempre cuadran</h3>
        <p>Cada movimiento tiene su contrapartida y todo cierra al
           centavo. Sin ajustes raros ni diferencias que nadie sabe
           explicar.</p>
      </article>
      <article class="sitio-tarjeta">
        <div class="sitio-tarjeta__icono"><?= P::crudo($ico($D['grafica'], 22)) ?></div>
        <h3>Estados de cuenta cuando los pida</h3>
        <p>Por mes, por cuenta y por tipo de producto, listos para
           descargar e incorporar a su contabilidad.</p>
      </article>
      <article class="sitio-tarjeta">
        <div class="sitio-tarjeta__icono"><?= P::crudo($ico($D['escudo'], 22)) ?></div>
        <h3>Sabemos quién hizo qué</h3>
        <p>Queda registrado quién realizó cada operación y cuándo. Útil
           cuando varias personas de su equipo manejan la cuenta.</p>
      </article>
      <article class="sitio-tarjeta">
        <div class="sitio-tarjeta__icono"><?= P::crudo($ico($D['rayo'], 22)) ?></div>
        <h3>Que le paguen con tarjeta</h3>
        <p>Sus clientes pueden pagarle con la tarjeta de cualquier banco,
           y usted recibe el abono en su cuenta.</p>
      </article>
    </div>
  </div>
</section>

<section class="sitio-llamada">
  <div class="sitio-ancho">
    <div>
      <h2>Cuéntenos de su negocio</h2>
      <p>
        Un ejecutivo revisa su caso con detenimiento y le propone la
        estructura que de verdad le sirve. Sin compromiso.
      </p>
    </div>
    <a class="sitio-btn sitio-btn--claro" href="<?= P::attr($rutas->url('contacto')) ?>">
      Contactarnos
    </a>
  </div>
</section>
