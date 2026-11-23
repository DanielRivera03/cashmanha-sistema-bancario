<?php
/**
 * Cómo llegó el banco hasta aquí.
 *
 * El tablero responde «cómo estamos hoy». Esta pantalla responde la
 * otra mitad de la pregunta de un consejo: «y veníamos de dónde».
 *
 * EL GRANO LO ELIGE QUIEN MIRA
 *
 * Día, semana, mes y año no son cuatro adornos del mismo gráfico: son
 * cuatro preguntas. Por día se ve el pico del viernes de pago; por
 * mes, si la cartera crece o se estanca; por año, si el banco de hoy
 * se parece al de hace cinco. Por eso el selector está arriba del
 * todo y se aplica a TODA la pantalla a la vez: comparar dos gráficos
 * con granos distintos es la manera más fácil de sacar una conclusión
 * falsa.
 *
 * LO QUE NO SE HACE AQUÍ
 *
 * No hay dos escalas en un mismo gráfico. Captaciones y cartera son
 * los dos dinero, pero de tamaño muy distinto, y superponerlas con un
 * eje a cada lado deja que la forma de las curvas dependa de dónde se
 * ponga el cero. Van separadas, cada una con su eje desde cero.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array<string,mixed> $series
 * @var array<string,string> $periodos
 * @var string $periodo
 */
use CashMan\Http\Plantilla as P;

/* Lo que el gráfico necesita, y nada más: los rótulos y los valores.
   Mandar el arreglo entero metería en el HTML cosas que el navegador
   no usa. */
$paraElGuion = [
    'captaciones'  => $series['captaciones'],
    'cartera'      => $series['cartera'],
    'operaciones'  => $series['operaciones'],
    'desembolsos'  => $series['desembolsos'],
    'recuperacion' => $series['recuperacion'],
    'clientes'     => $series['clientes'],
];

/** Lo último de una serie, para el número grande de la tarjeta. */
$ultimo = static function (array $serie, string $campo = 'valor'): int {
    if ($serie === []) { return 0; }

    $f = end($serie);

    return (int) ($f[$campo] ?? 0);
};

/** Cuánto cambió respecto al tramo anterior, en por ciento. */
$variacion = static function (array $serie, string $campo = 'valor'): ?float {
    if (count($serie) < 2) { return null; }

    $f    = array_slice($serie, -2);
    $ante = (int) ($f[0][$campo] ?? 0);
    $hoy  = (int) ($f[1][$campo] ?? 0);

    if ($ante === 0) { return null; }

    return round((($hoy - $ante) / abs($ante)) * 100, 1);
};
?>

<div class="jd">

  <!-- ============== EL GRANO ============== -->
  <div class="jd-barra">
    <div>
      <h5 class="jd-barra__titulo">Evolución del banco</h5>
      <p class="jd-barra__nota mb-0">
        Las mismas cifras del tablero, pero a lo largo del tiempo.
        El grano se aplica a todos los gráficos a la vez.
      </p>
    </div>

    <nav class="jd-grano" aria-label="Agrupación de las series">
      <?php foreach ($periodos as $clave => $etiqueta): ?>
      <a href="<?= P::attr($rutas->url('junta/evolucion') . '?p=' . $clave) ?>"
         class="jd-grano__op <?= $clave === $periodo ? 'is-activo' : '' ?>"
         <?= $clave === $periodo ? 'aria-current="page"' : '' ?>><?= P::e($etiqueta) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>

  <?php
  /* Las cuatro cifras de cabecera. Son el último tramo de cada serie:
     el gráfico de abajo dice cómo se llegó, éstas dicen dónde se está
     ahora mismo y si el último tramo subió o bajó. */
  $tarjetas = [
      ['Captaciones',  $ultimo($series['captaciones']),          true,  $variacion($series['captaciones'])],
      ['Cartera',      $ultimo($series['cartera']),              true,  $variacion($series['cartera'])],
      ['Desembolsado', $ultimo($series['desembolsos'], 'monto'), true,  $variacion($series['desembolsos'], 'monto')],
      ['Clientes nuevos', $ultimo($series['clientes']),          false, $variacion($series['clientes'])],
  ];
  ?>

  <div class="row">
    <?php foreach ($tarjetas as [$et, $valor, $esDinero, $var]): ?>
    <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
      <div class="cm-kpi">
        <div class="cm-kpi__et"><?= P::e($et) ?></div>
        <div class="cm-kpi__val">
          <?= $esDinero ? P::e(P::money($valor)) : number_format($valor) ?>
        </div>
        <?php if ($var !== null): ?>
        <div class="jd-var jd-var--<?= $var >= 0 ? 'sube' : 'baja' ?>">
          <?= $var >= 0 ? '▲' : '▼' ?> <?= P::e(number_format(abs($var), 1)) ?>&nbsp;%
          <span class="jd-var__nota">frente al <?= P::e(mb_strtolower($series['etiqueta'])) ?> anterior</span>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ============== LOS DOS SALDOS ============== -->
  <div class="row">
    <div class="col-xl-6 mb-4">
      <div class="card h-100">
        <div class="card-header">
          <div>
            <h4 class="card-title mb-0">Captaciones</h4>
            <p class="jd-grafico__nota mb-0">Saldo en cuentas de clientes al cierre de cada
              <?= P::e(mb_strtolower($series['etiqueta'])) ?>.</p>
          </div>
        </div>
        <div class="card-body">
          <div class="jd-lienzo"><canvas id="jd-captaciones" height="260"></canvas></div>
        </div>
      </div>
    </div>

    <div class="col-xl-6 mb-4">
      <div class="card h-100">
        <div class="card-header">
          <div>
            <h4 class="card-title mb-0">Cartera de créditos</h4>
            <p class="jd-grafico__nota mb-0">Capital prestado pendiente de cobro al cierre de cada
              <?= P::e(mb_strtolower($series['etiqueta'])) ?>.</p>
          </div>
        </div>
        <div class="card-body">
          <div class="jd-lienzo"><canvas id="jd-cartera" height="260"></canvas></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ============== LO QUE SALE Y LO QUE VUELVE ============== -->
  <div class="row">
    <div class="col-12 mb-4">
      <div class="card">
        <div class="card-header">
          <div>
            <h4 class="card-title mb-0">Desembolsos y recuperación</h4>
            <p class="jd-grafico__nota mb-0">
              Lo que salió en préstamos frente a lo que volvió en pagos. Las dos barras
              son dinero y comparten el mismo eje, de modo que su altura se puede
              comparar directamente.
            </p>
          </div>
        </div>
        <div class="card-body">
          <div class="jd-lienzo jd-lienzo--ancho"><canvas id="jd-flujo" height="280"></canvas></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ============== ACTIVIDAD ============== -->
  <div class="row">
    <div class="col-xl-6 mb-4">
      <div class="card h-100">
        <div class="card-header">
          <div>
            <h4 class="card-title mb-0">Operaciones</h4>
            <p class="jd-grafico__nota mb-0">Movimientos registrados en el periodo.</p>
          </div>
        </div>
        <div class="card-body">
          <div class="jd-lienzo"><canvas id="jd-operaciones" height="250"></canvas></div>
        </div>
      </div>
    </div>

    <div class="col-xl-6 mb-4">
      <div class="card h-100">
        <div class="card-header">
          <div>
            <h4 class="card-title mb-0">Clientes nuevos</h4>
            <p class="jd-grafico__nota mb-0">Altas de cuentahabientes en el periodo.</p>
          </div>
        </div>
        <div class="card-body">
          <div class="jd-lienzo"><canvas id="jd-clientes" height="250"></canvas></div>
        </div>
      </div>
    </div>
  </div>

  <?php
  /* La misma tabla que los gráficos, en números.
   *
   * No es un extra: un gráfico es una lectura, y quien tiene que
   * firmar un acta necesita la cifra exacta. Además es lo que hace la
   * pantalla utilizable con un lector de pantalla, para el que un
   * `<canvas>` no es nada. */
  ?>
  <div class="card">
    <div class="card-header">
      <h4 class="card-title mb-0">Los mismos datos, en cifras</h4>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0" data-cm-tabla>
          <thead>
            <tr>
              <th><?= P::e($series['etiqueta']) ?></th>
              <th class="cm-num">Captaciones</th>
              <th class="cm-num">Cartera</th>
              <th class="cm-num">Desembolsado</th>
              <th class="cm-num">Recuperado</th>
              <th class="cm-num">Operaciones</th>
              <th class="cm-num">Clientes</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($series['captaciones'] as $n => $fila): ?>
            <tr>
              <td class="cm-mono"><?= P::e($fila['rotulo']) ?></td>
              <td class="cm-num" data-order="<?= (int) $fila['valor'] ?>">
                <?= P::e(P::money((int) $fila['valor'])) ?></td>
              <td class="cm-num" data-order="<?= (int) ($series['cartera'][$n]['valor'] ?? 0) ?>">
                <?= P::e(P::money((int) ($series['cartera'][$n]['valor'] ?? 0))) ?></td>
              <td class="cm-num" data-order="<?= (int) ($series['desembolsos'][$n]['monto'] ?? 0) ?>">
                <?= P::e(P::money((int) ($series['desembolsos'][$n]['monto'] ?? 0))) ?></td>
              <td class="cm-num" data-order="<?= (int) ($series['recuperacion'][$n]['monto'] ?? 0) ?>">
                <?= P::e(P::money((int) ($series['recuperacion'][$n]['monto'] ?? 0))) ?></td>
              <td class="cm-num"><?= number_format((int) ($series['operaciones'][$n]['valor'] ?? 0)) ?></td>
              <td class="cm-num"><?= number_format((int) ($series['clientes'][$n]['valor'] ?? 0)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php /* Los datos, en una isla. El guion los lee de aquí: así no hay
         JavaScript en línea que la política de seguridad tenga que
         permitir, y el marcado se queda legible. */ ?>
<script type="application/json" id="jd-series"><?= P::crudo(json_encode(
    $paraElGuion,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
)) ?></script>

<?php $jsv = @filemtime(dirname(__DIR__, 3) . '/public/assets/js/junta-series.js') ?: time(); ?>
<script src="<?= P::attr($rutas->url('assets/js/junta-series.js') . '?v=' . $jsv) ?>"></script>
