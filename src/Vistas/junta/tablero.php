<?php
/**
 * El tablero de la Junta Directiva.
 *
 * Lo primero que se ve es si el libro mayor cuadra, y no es un
 * capricho de orden: si no cuadra, ninguna de las demás cifras de
 * esta pantalla se puede creer del todo, y conviene saberlo ANTES de
 * leerlas y no al final.
 *
 * Después, de lo más grande a lo más fino: cuánto dinero custodia el
 * banco, cuánto tiene prestado, cuánto de eso no está pagando, y en
 * manos de quién está concentrado.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array<string,mixed> $indicadores
 */
use CashMan\Http\Plantilla as P;

$i = $indicadores;

/* El índice de mora decide el color de media pantalla, así que se
   resuelve una vez aquí en lugar de repetir el umbral abajo. Los
   cortes son los de uso común en cartera: hasta 3 % se considera
   sano, hasta 10 % vigilancia, por encima es un problema. */
$indice = $i['morosidad']['indice'];

$tono = $indice === null ? 'neutro'
      : ($indice <= 3 ? 'bien' : ($indice <= 10 ? 'ojo' : 'mal'));
?>

<div class="jd">

  <!-- ============== ¿SE PUEDE CREER LO DE ABAJO? ============== -->
  <div class="jd-libro jd-libro--<?= $i['libro']['cuadra'] ? 'ok' : 'mal' ?>">
    <span class="jd-libro__icono" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
           stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
        <?php if ($i['libro']['cuadra']): ?>
        <path d="M20 6L9 17l-5-5"/>
        <?php else: ?>
        <circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16h.01"/>
        <?php endif; ?>
      </svg>
    </span>

    <div>
      <strong>
        <?= $i['libro']['cuadra']
            ? 'El libro mayor cuadra'
            : 'El libro mayor NO cuadra' ?>
      </strong>
      <span>
        <?= (int) $i['libro']['asientos'] ?> asientos en partida doble.
        <?php if ($i['libro']['cuadra']): ?>
          La suma de todos los movimientos da cero, que es lo que debe dar.
        <?php else: ?>
          Hay <?= (int) $i['libro']['descuadrados'] ?> asiento(s) descuadrado(s)
          por <?= P::e($i['libro']['descuadre']->formateado()) ?>.
          <strong>Las cifras de abajo no se pueden dar por buenas hasta resolverlo.</strong>
        <?php endif; ?>
      </span>
    </div>

    <small>al <?= P::e($i['generado_en']) ?></small>
  </div>

  <!-- ===================== LO GRANDE ===================== -->
  <div class="jd-cifras">

    <div class="jd-cifra">
      <small>Captaciones del público</small>
      <b><?= P::e($i['captaciones']['saldo']->formateado()) ?></b>
      <span>
        <?= (int) $i['captaciones']['cuentas'] ?> cuentas ·
        <?= (int) $i['captaciones']['activas'] ?> activas
      </span>
    </div>

    <div class="jd-cifra">
      <small>Cartera de créditos</small>
      <b><?= P::e($i['cartera']['capital']->formateado()) ?></b>
      <span>
        <?= (int) $i['cartera']['creditos'] ?> créditos ·
        otorgado <?= P::e($i['cartera']['otorgado']->formateado()) ?>
      </span>
    </div>

    <div class="jd-cifra jd-cifra--<?= P::attr($tono) ?>">
      <small>Índice de mora</small>
      <b><?= $indice === null ? 'n/d' : P::e(number_format($indice, 2)) . ' %' ?></b>
      <span>
        <?= P::e($i['morosidad']['capital_en_mora']->formateado()) ?> en
        <?= (int) $i['morosidad']['creditos'] ?> crédito(s)
      </span>
    </div>

    <div class="jd-cifra">
      <small>Clientes</small>
      <b><?= (int) $i['clientes']['total'] ?></b>
      <span>
        <?= (int) $i['clientes']['activos'] ?> activos ·
        <?= (int) $i['clientes']['nuevos'] ?> nuevos en 30 días
      </span>
    </div>
  </div>

  <?php if ($indice !== null && $indice > 10): ?>
  <?php /* Un índice así no se deja pasar como un número más entre
           otros: es la conclusión principal de la pantalla. */ ?>
  <div class="jd-alerta">
    <strong>La mora está por encima de lo tolerable.</strong>
    <?= P::e(number_format($indice, 2)) ?> % de la cartera corresponde a créditos
    con alguna cuota vencida. El atraso mayor va por
    <?= (int) $i['morosidad']['peor_atraso'] ?> días.
  </div>
  <?php endif; ?>

  <div class="jd-rejilla">

    <!-- ===================== LA MORA, POR TRAMOS ===================== -->
    <section class="jd-caja">
      <h5>Mora por antigüedad</h5>
      <p class="jd-nota">
        No es lo mismo treinta días que trescientos: lo primero suele ser un
        descuido y lo segundo, una pérdida. Estos son los cortes que usa la
        clasificación de cartera.
      </p>

      <?php if ($i['morosidad']['tramos'] === []): ?>
      <p class="jd-vacio">No hay cuotas vencidas.</p>
      <?php else: ?>
      <table class="jd-tabla">
        <thead>
          <tr><th>Antigüedad</th><th class="n">Cuotas</th><th class="n">Pendiente</th></tr>
        </thead>
        <tbody>
          <?php foreach ($i['morosidad']['tramos'] as $t): ?>
          <tr>
            <td><?= P::e($t['tramo']) ?></td>
            <td class="n"><?= (int) $t['cuotas'] ?></td>
            <td class="n"><?= P::e($t['pendiente']->formateado()) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td>Recargo acumulado</td>
            <td class="n">—</td>
            <td class="n"><?= P::e($i['morosidad']['recargo']->formateado()) ?></td>
          </tr>
        </tfoot>
      </table>
      <?php endif; ?>
    </section>

    <!-- ===================== CONCENTRACIÓN ===================== -->
    <section class="jd-caja">
      <h5>Mayores deudores</h5>
      <p class="jd-nota">
        La concentración en pocas manos es lo que hunde a una institución, y eso
        no se ve en un total. Es el único punto de esta pantalla que baja al
        nombre propio.
      </p>

      <?php if ($i['mayores'] === []): ?>
      <p class="jd-vacio">No hay créditos vigentes.</p>
      <?php else: ?>
      <table class="jd-tabla">
        <thead>
          <tr><th>Cliente</th><th class="n">Capital</th><th class="n">Peso</th></tr>
        </thead>
        <tbody>
          <?php foreach ($i['mayores'] as $m): ?>
          <tr>
            <td>
              <?= P::e($m['cliente']) ?>
              <?php if ($m['en_mora']): ?>
              <span class="jd-marca">en mora</span>
              <?php endif; ?>
            </td>
            <td class="n"><?= P::e($m['capital']->formateado()) ?></td>
            <td class="n"><?= P::e(number_format((float) $m['peso'], 2)) ?> %</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </section>

    <!-- ===================== CAPTACIONES ===================== -->
    <section class="jd-caja">
      <h5>Captaciones por producto</h5>

      <table class="jd-tabla">
        <thead>
          <tr><th>Producto</th><th class="n">Cuentas</th><th class="n">Saldo</th></tr>
        </thead>
        <tbody>
          <?php foreach ($i['captaciones']['por_producto'] as $p): ?>
          <tr>
            <td><?= P::e($p['nombre']) ?></td>
            <td class="n"><?= (int) $p['cuentas'] ?></td>
            <td class="n"><?= P::e($p['saldo']->formateado()) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <!-- ===================== CARTERA ===================== -->
    <section class="jd-caja">
      <h5>Cartera por producto</h5>

      <table class="jd-tabla">
        <thead>
          <tr><th>Producto</th><th class="n">Créditos</th><th class="n">Capital</th></tr>
        </thead>
        <tbody>
          <?php foreach ($i['cartera']['por_producto'] as $p): ?>
          <tr>
            <td><?= P::e($p['nombre']) ?></td>
            <td class="n"><?= (int) $p['creditos'] ?></td>
            <td class="n"><?= P::e($p['capital']->formateado()) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <!-- ===================== TARJETAS ===================== -->
    <section class="jd-caja">
      <h5>Tarjetas de crédito</h5>
      <p class="jd-nota">
        El uso de la línea dice si el riesgo está tomado o solo ofrecido: una
        línea otorgada y sin consumir no es exposición todavía.
      </p>

      <table class="jd-tabla">
        <tbody>
          <tr>
            <td>Línea otorgada</td>
            <td class="n"><?= P::e($i['tarjetas']['linea']->formateado()) ?></td>
          </tr>
          <tr>
            <td>Consumido</td>
            <td class="n"><?= P::e($i['tarjetas']['usado']->formateado()) ?></td>
          </tr>
          <tr>
            <td>Uso de la línea</td>
            <td class="n">
              <?= $i['tarjetas']['uso'] === null
                  ? 'n/d'
                  : P::e(number_format((float) $i['tarjetas']['uso'], 2)) . ' %' ?>
            </td>
          </tr>
          <tr>
            <td>Emitidas / activas</td>
            <td class="n">
              <?= (int) $i['tarjetas']['emitidas'] ?> / <?= (int) $i['tarjetas']['activas'] ?>
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- ===================== EL MES ===================== -->
    <section class="jd-caja">
      <h5>Movimiento del mes</h5>
      <p class="jd-nota">
        Tomado del libro mayor, que es la única fuente que no se puede tocar sin
        dejar rastro.
      </p>

      <?php if ($i['mes'] === []): ?>
      <p class="jd-vacio">Sin movimientos este mes.</p>
      <?php else: ?>
      <table class="jd-tabla">
        <thead>
          <tr><th>Concepto</th><th class="n">Operaciones</th><th class="n">Monto</th></tr>
        </thead>
        <tbody>
          <?php foreach ($i['mes'] as $m): ?>
          <tr>
            <td><?= P::e(ucfirst(str_replace('_', ' ', (string) $m['tipo']))) ?></td>
            <td class="n"><?= (int) $m['operaciones'] ?></td>
            <td class="n"><?= P::e($m['monto']->formateado()) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </section>
  </div>

  <p class="jd-pie">
    Vista de consulta. Los datos se leen de las mismas tablas que usa la
    operación, no de un resumen aparte, de modo que lo que ve aquí es lo que el
    sistema tiene en este momento.
  </p>
</div>
