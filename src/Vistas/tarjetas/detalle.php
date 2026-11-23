<?php
/**
 * Detalle de tarjeta con todas las acciones.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $tarjeta
 * @var array $movimientos
 * @var array $diferibles
 * @var array $planes
 * @var array $extras
 * @var array $otras
 * @var array $cuentasTitular
 * @var int   $sobregiroComision
 * @var int   $sobregiroTecho
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

/* El plástico lo dibuja `cm_plastico()`, no esta vista.
   Antes cada pantalla lo armaba a su manera —aquí un degradado según la
   marca— y la misma tarjeta se veía distinta en el panel, en el listado
   y aquí. El acabado sale de la LÍNEA contratada; la marca va en su
   sello, abajo, como en una tarjeta de verdad. */
$logosTarjeta = $rutas->url('assets/plantilla/images');

$uso      = (float) $tarjeta['uso_pct'];
$esCredito = $tarjeta['tipo'] === 'credito';
$esMia    = (bool) $tarjeta['esMia'];

/* Las ACCIONES se abren con esto, no con `esMia`: el
   administrador opera sobre cualquier tarjeta. Los textos
   siguen con `esMia`, porque a quien mira la tarjeta de otro
   no se le habla de «su» saldo. */
$puedeOperar = (bool) ($tarjeta['puedeOperar'] ?? $esMia);
$ruta     = $rutas->url('tarjetas/' . $tarjeta['codigo']);

/* El efectivo tomado contra la linea. Se suma aparte porque ocupa
   cupo igual que un consumo, pero no es uno: no tiene comercio ni
   aparece entre los movimientos del periodo, y sin esta cuenta el
   cliente no entiende por que su disponible bajo. */
$extras     = $extras ?? [];
$extraSaldo = 0;
$extraCuota = 0;

foreach ($extras as $x) {
    $extraSaldo += (int) $x['pendiente_cent'];
    $extraCuota += (int) $x['cuota_cent'];
}
?>

<div class="row">
  <!-- ============ PLÁSTICO + ACCIONES RÁPIDAS ============ -->
  <div class="col-xl-4 col-lg-5 col-md-12 mb-4">

    <div class="mb-3">
      <div style="position:relative">
        <?= P::crudo(cm_plastico($tarjeta, [
              'id'        => 'plastico',
              'assets'    => $logosTarjeta,
              'idNumero'  => 'pan-mostrado',
              'idVence'   => 'vence-mostrado',
              'idCvv'     => 'cvv-mostrado',
              'volteable' => true,
            ])) ?>

      </div>
    </div>

<?php
/* --- Cómo va el pago del mes ------------------------------------------
   El mínimo es del PERIODO: lo que decide si hay mora es cuánto se
   abonó desde el corte. Enseñar el mínimo entero a quien ya pagó parte
   es no decirle lo único que necesita saber: cuánto le falta. */
$pm = $pagoMes ?? ['hay_corte' => false];
?>
<?php if (!empty($pm['hay_corte'])): ?>
<?php
  $pmClase = $pm['cubierto'] ? 'bien' : ($pm['vencido'] ? 'grave' : 'ojo');
  $pmPct   = (int) $pm['minimo_cent'] > 0
      ? min(100, (int) round((int) $pm['abonado_cent'] * 100 / (int) $pm['minimo_cent']))
      : 100;
?>
<div class="cm-pagomes cm-pagomes--<?= $pmClase ?>">
  <div class="cm-pagomes__alto">
    <strong>
      <?php if ($pm['cubierto']): ?>
        Pago mínimo cubierto
      <?php elseif ($pm['vencido']): ?>
        El pago mínimo venció
      <?php else: ?>
        Le falta para el pago mínimo
      <?php endif; ?>
    </strong>
    <span class="cm-pagomes__cifra">
      <?= P::e(P::money((int) $pm['abonado_cent'])) ?>
      de <?= P::e(P::money((int) $pm['minimo_cent'])) ?>
    </span>
  </div>

  <div class="cm-pagomes__barra">
    <span style="width:<?= $pmPct ?>%"></span>
  </div>

  <p class="cm-pagomes__texto">
    <?php if ($pm['cubierto']): ?>
      Ya abonó el mínimo de este corte. Si además paga
      <strong><?= P::e(P::money((int) $pm['contado_cent'])) ?></strong> antes del
      <?= P::e(P::fecha((string) $pm['fecha_limite'], 'd/m/Y')) ?>, los consumos del
      periodo no generan ningún interés.
    <?php elseif ($pm['vencido']): ?>
      Faltaron <strong><?= P::e(P::money((int) $pm['faltante_cent'])) ?></strong> y la
      fecha límite (<?= P::e(P::fecha((string) $pm['fecha_limite'], 'd/m/Y')) ?>) ya pasó.
      El saldo vencido genera intereses moratorios y el atraso queda en su historial
      crediticio. Abone cuanto antes para detenerlo.
    <?php else: ?>
      Ha abonado <strong><?= P::e(P::money((int) $pm['abonado_cent'])) ?></strong>, pero el
      mínimo de este corte es <strong><?= P::e(P::money((int) $pm['minimo_cent'])) ?></strong>:
      le faltan <strong><?= P::e(P::money((int) $pm['faltante_cent'])) ?></strong>.
      Tiene hasta el <strong><?= P::e(P::fecha((string) $pm['fecha_limite'], 'd/m/Y')) ?></strong><?php
        if ((int) $pm['dias'] >= 0): ?> &mdash; <?= (int) $pm['dias'] ?> día(s)<?php endif; ?>.
      Si no lo completa, la tarjeta entra en mora: intereses moratorios sobre lo vencido
      y el atraso en su historial crediticio.
    <?php endif; ?>
  </p>
</div>
<?php endif; ?>

    <?php if ((string) $tarjeta['tipo'] === 'debito' && !empty($tarjeta['cuenta_numero'])): ?>
    <?php /* Lo disponible, arriba y en grande.
             Una de débito no tiene cupo ni saldo propio: lo que puede
             gastar es lo que haya en su cuenta, y ese es el dato que
             se viene a consultar. Sin él, la ficha de una débito no
             contestaba la única pregunta que se le hace. */ ?>
    <div class="cm-debito-saldo">
      <div>
        <small>Disponible en su cuenta</small>
        <strong><?= P::e(P::money((int) ($tarjeta['cuenta_saldo_cent'] ?? 0))) ?></strong>
      </div>
      <a class="btn btn-sm btn-outline-primary"
         href="<?= P::attr($rutas->url('cuentas/' . ($tarjeta['cuenta_codigo'] ?? ''))) ?>">
        Ver la cuenta
      </a>
    </div>

    <p class="text-muted mb-3" style="font-size:.82rem">
      Ligada a la cuenta <strong class="cm-mono"><?= P::e($tarjeta['cuenta_numero']) ?></strong>.
      Paga con su propio dinero: lo que gaste sale de ahí en el momento, sin línea de
      crédito, sin intereses y sin fecha de corte.
    </p>
    <?php endif; ?>

    <?php if ($puedeOperar): ?>
    <div class="d-flex flex-wrap" style="gap:.4rem">
      <?php if ((string) $tarjeta['tipo'] === 'credito'): ?>
      <a href="<?= P::attr($rutas->url('tarjetas/' . $tarjeta['codigo'] . '/pago')) ?>"
         class="btn btn-sm btn-primary flex-fill">
        <?= cm_icono('wallet', 15, 'mr-1') ?> Pagar
      </a>
      <?php endif; ?>

      <?php /* El giro, fuera del plastico: dentro tapaba el sello de la
               marca, que es justo una de las cosas que hay que ver. */ ?>
      <button class="btn btn-sm btn-outline-secondary flex-fill" id="btn-voltear"
              aria-label="Ver el reverso de la tarjeta">
        <?= cm_icono('swap', 15, 'mr-1') ?> Reverso
      </button>

      <button class="btn btn-sm btn-outline-primary flex-fill" id="btn-ver-datos">
        <?= cm_icono('search', 15, 'mr-1') ?> Ver datos
      </button>

      <?php if ($tarjeta['congelada']): ?>
      <button class="btn btn-sm btn-success flex-fill" id="btn-descongelar">
        <?= cm_icono('check', 15, 'mr-1') ?> Reactivar
      </button>
      <?php else: ?>
      <button class="btn btn-sm btn-outline-warning flex-fill" id="btn-congelar">
        <?= cm_icono('lock', 15, 'mr-1') ?> Congelar
      </button>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="alert alert-light border mt-3 mb-0" style="font-size:.8rem">
      <strong>Protección de datos.</strong> El número y el CVV van cifrados con
      AES-256-GCM y no se pueden leer de la base de datos. Para verlos se pide un
      código y se emite un token de un solo uso que vence en 90 segundos.
    </div>
  </div>

  <!-- ============ INDICADORES + PESTAÑAS ============ -->
  <div class="col-xl-8 col-lg-7 col-md-12 mb-4">

    <?php if ($esCredito): ?>
    <div class="card mb-3">
      <div class="card-body">

        <?php if (($tarjeta['estado'] ?? '') === 'inhabilitada'):
            $excedenteCent = max(0, (int) $tarjeta['saldo_usado_cent'] - (int) $tarjeta['limite_cent']); ?>
        <div class="alert alert-danger" role="alert">
          <strong><?= cm_icono('alert-triangle', 16, 'mr-1') ?>Tarjeta inhabilitada por sobregiro.</strong>
          Su saldo superó el límite aprobado<?php if ($excedenteCent > 0): ?> en
          <strong><?= P::e(P::money($excedenteCent)) ?></strong><?php endif; ?> y venció la fecha
          límite de pago sin cubrirlo. Por eso no puede usarse para compras ni adelantos.
          <hr class="my-2">
          <span><strong>Para reactivarla:</strong> abone al menos
          <strong><?= P::e(P::money($excedenteCent)) ?></strong> (el excedente) a esta tarjeta
          desde el botón «Pagar» o en caja. En cuanto el saldo vuelva dentro de su límite,
          se reactiva sola.</span>
        </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-2">
          <div>
            <small class="text-muted d-block">Saldo utilizado</small>
            <h3 class="mb-0 cm-neg"><?= P::e(P::money((int) $tarjeta['saldo_usado_cent'])) ?></h3>
          </div>
          <div class="text-right">
            <small class="text-muted d-block">Cupo disponible</small>
            <h3 class="mb-0 cm-pos"><?= P::e(P::money((int) $tarjeta['disponible_cent'])) ?></h3>
          </div>
        </div>

        <div class="progress" style="height:10px">
          <div class="progress-bar bg-<?= $uso > 80 ? 'danger' : ($uso > 50 ? 'warning' : 'success') ?>"
               style="width:<?= $uso ?>%" role="progressbar"
               aria-valuenow="<?= $uso ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <div class="d-flex justify-content-between mt-1">
          <small class="text-muted"><?= $uso ?>% usado</small>
          <small class="text-muted">Límite <?= P::e(P::money((int) $tarjeta['limite_cent'])) ?></small>
        </div>

        <?php
          /* Las dos fechas que de verdad importan.
           *
           * Antes solo salía el corte, y el corte por sí solo no le
           * sirve a nadie: lo que el cliente necesita saber es hasta
           * cuándo tiene para pagar sin intereses. La fecha límite es
           * el corte más los días de gracia del producto, y se calcula
           * aquí en lugar de guardarla porque así nunca queda vieja. */
          $corteFecha = (string) ($tarjeta['proximo_corte'] ?? '');
          $diasPago   = (int) ($tarjeta['dias_para_pago'] ?? 20);
          $limiteFecha = '';
          $diasRestan  = null;

          if ($corteFecha !== '') {
              try {
                  $c0 = new DateTimeImmutable($corteFecha);
                  $lim = $c0->modify('+' . $diasPago . ' days');
                  $limiteFecha = $lim->format('Y-m-d');
                  $diasRestan = (int) (new DateTimeImmutable('today'))->diff($lim)->format('%r%a');
              } catch (Throwable) {
                  $limiteFecha = '';
              }
          }
        ?>
        <div class="row mt-3 pt-3 border-top">
          <div class="col-6 col-md-3 mb-2">
            <small class="text-muted d-block">Próximo corte</small>
            <strong><?= $corteFecha !== '' ? P::e(P::fecha($corteFecha, 'd/m/Y')) : '—' ?></strong>
          </div>
          <div class="col-6 col-md-3 mb-2">
            <small class="text-muted d-block">Fecha límite de pago</small>
            <strong><?= $limiteFecha !== '' ? P::e(P::fecha($limiteFecha, 'd/m/Y')) : '—' ?></strong>
            <?php if ($diasRestan !== null): ?>
            <small class="d-block <?= $diasRestan < 0 ? 'text-danger' : ($diasRestan <= 5 ? 'text-warning' : 'text-muted') ?>"
                   style="font-size:.7rem">
              <?= $diasRestan < 0
                    ? 'venció hace ' . abs($diasRestan) . ' día(s)'
                    : ($diasRestan === 0 ? 'vence hoy' : 'faltan ' . $diasRestan . ' día(s)') ?>
            </small>
            <?php endif; ?>
          </div>
          <div class="col-6 col-md-3 mb-2">
            <small class="text-muted d-block">Saldo diferido</small>
            <strong><?= P::e(P::money((int) $tarjeta['saldo_diferido_cent'])) ?></strong>
          </div>
          <div class="col-6 col-md-3 mb-2">
            <small class="text-muted d-block">Planes activos</small>
            <strong><?= (int) $tarjeta['planes_vigentes'] ?></strong>
          </div>
        </div>

        <?php if ($extraSaldo > 0): ?>
        <div class="row mt-2 pt-3 border-top">
          <div class="col-6 col-md-3 mb-2">
            <small class="text-muted d-block">Extrafinanciamiento</small>
            <strong class="cm-neg"><?= P::e(P::money($extraSaldo)) ?></strong>
          </div>
          <div class="col-6 col-md-3 mb-2">
            <small class="text-muted d-block">Cuota en cada corte</small>
            <strong><?= P::e(P::money($extraCuota)) ?></strong>
          </div>
          <div class="col-12 col-md-6 mb-2">
            <small class="text-muted d-block" style="font-size:.74rem;line-height:1.4">
              Es efectivo que se le entregó contra esta línea: ocupa cupo
              mientras se liquida y su cuota se carga en cada estado de cuenta.
            </small>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card mb-0">
      <div class="card-body pb-0">
        <ul class="nav nav-tabs" role="tablist">
          <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab-movs">Movimientos</a></li>
          <?php if ($esCredito && $puedeOperar): ?>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-cuotas">
            Compras a cuotas
            <?php if ($diferibles !== []): ?>
            <span class="badge badge-primary ml-1"><?= count($diferibles) ?></span>
            <?php endif; ?>
          </a></li>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-planes">
            Planes <span class="badge badge-light ml-1"><?= count($planes) ?></span>
          </a></li>
          <?php if ($extras !== []): ?>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-extra">
            Extrafinanciamiento
            <span class="badge badge-light ml-1"><?= count($extras) ?></span>
          </a></li>
          <?php endif; ?>
          <?php if ($otras !== []): ?>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-adelanto">Adelanto de efectivo</a></li>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-traslado">Trasladar saldo</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-estados">
            Estados de cuenta
            <?php if ($cortes !== []): ?>
            <span class="badge badge-light ml-1"><?= count($cortes) ?></span>
            <?php endif; ?>
          </a></li>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-config">Controles</a></li>
          <?php endif; ?>
        </ul>
      </div>

      <div class="tab-content">

        <!-- ---------- MOVIMIENTOS ---------- -->
        <div class="tab-pane fade show active" id="tab-movs">
          <?php /* «Sin movimientos» solo si la tarjeta no tiene
                   ninguno. Con un filtro puesto la lista puede
                   salir vacia sin que eso sea cierto, y entonces
                   hay que seguir enseniando el filtro para poder
                   quitarlo: si se oculta, el cliente se queda
                   encerrado en un periodo vacio. */ ?>
          <?php if ($movimientos === [] && (int) $filtroMov["anio"] === 0 && (int) $filtroMov["mes"] === 0): ?>
            <div class="cm-vacio"><p class="mb-0">Sin movimientos todavía.</p></div>
          <?php else: ?>

          <?php
          /* Los periodos salen de la tabla ENTERA, no de la página que
             se esté mirando: un desplegable armado con lo que hay en
             pantalla cambiaría de opciones al pasar de página, y el
             mes que se busca es justo el que no aparece.

             Tampoco se ofrecen los doce meses: solo los que tienen
             movimientos. Once puertas a una lista vacía no ayudan. */
          $aniosMov = [];
          $mesesMov = [];

          foreach ($periodosMov as $pm) {
              $aniosMov[(int) $pm['anio']] = true;
              $mesesMov[(int) $pm['mes']]  = true;
          }

          krsort($aniosMov);
          ksort($mesesMov);

          $MESM = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                   'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

          $anioSel = (int) ($filtroMov['anio'] ?? 0);
          $mesSel  = (int) ($filtroMov['mes'] ?? 0);

          /* Las URL del filtro y de la paginación, siempre con lo que
             ya estaba puesto: cambiar de página no puede perder el mes
             elegido, ni cambiar de mes la cantidad por página. */
          $enlaceMov = static function (array $cambios) use ($rutas, $tarjeta, $anioSel, $mesSel, $paginacionMov): string {
              $q = array_filter([
                  'anio' => $anioSel > 0 ? (string) $anioSel : '',
                  'mes'  => $mesSel  > 0 ? (string) $mesSel  : '',
                  'pp'   => (int) $paginacionMov['porPagina'] === 25
                            ? '' : (string) $paginacionMov['porPagina'],
              ] + $cambios, static fn(string $v): bool => $v !== '');

              foreach ($cambios as $k => $v) {
                  if ($v === '') { unset($q[$k]); } else { $q[$k] = $v; }
              }

              $url = $rutas->url('tarjetas/' . $tarjeta['codigo']);

              return $q === [] ? $url . '#tab-movs' : $url . '?' . http_build_query($q) . '#tab-movs';
          };
          ?>

          <div class="card-body pb-2 pt-3">
            <?php /* Un formulario GET de verdad: lo que se elige se
                     consulta, no se esconde. Sin JavaScript también
                     funciona, y con la capa en vivo no recarga. */ ?>
            <form method="get" class="form-row align-items-end"
                  action="<?= P::attr($rutas->url('tarjetas/' . $tarjeta['codigo'])) ?>">
              <div class="form-group col-md-3 col-6 mb-2">
                <label class="small mb-1" for="m-anio">Año</label>
                <select class="form-control form-control-sm" id="m-anio" name="anio" data-cm-enviar>
                  <option value="">Todos</option>
                  <?php foreach (array_keys($aniosMov) as $a): ?>
                  <option value="<?= (int) $a ?>"<?= $anioSel === (int) $a ? ' selected' : '' ?>>
                    <?= (int) $a ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-md-4 col-6 mb-2">
                <label class="small mb-1" for="m-mes">Mes</label>
                <select class="form-control form-control-sm" id="m-mes" name="mes" data-cm-enviar>
                  <option value="">Todos</option>
                  <?php foreach (array_keys($mesesMov) as $mm): ?>
                  <option value="<?= (int) $mm ?>"<?= $mesSel === (int) $mm ? ' selected' : '' ?>>
                    <?= P::e($MESM[(int) $mm]) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <?php if ((int) $paginacionMov['porPagina'] !== 25): ?>
              <input type="hidden" name="pp" value="<?= (int) $paginacionMov['porPagina'] ?>">
              <?php endif; ?>

              <div class="form-group col-md-2 col-5 mb-2">
                <?php if ($anioSel > 0 || $mesSel > 0): ?>
                <a href="<?= P::attr($enlaceMov(['anio' => '', 'mes' => '', 'p' => ''])) ?>"
                   class="btn btn-outline-secondary btn-sm btn-block">Ver todo</a>
                <?php else: ?>
                <button type="submit" class="btn btn-outline-primary btn-sm btn-block">Filtrar</button>
                <?php endif; ?>
              </div>

              <div class="form-group col-md-3 col-7 mb-2 text-md-right">
                <small class="text-muted" style="font-size:.78rem">
                  <?= number_format((float) $paginacionMov['total']) ?>
                  movimiento<?= (int) $paginacionMov['total'] === 1 ? '' : 's' ?>
                  <?= $anioSel > 0 || $mesSel > 0 ? 'en el periodo' : 'en total' ?>
                </small>
              </div>
            </form>
          </div>

          <div class="table-responsive">
            <table class="table table-hover mb-0" id="tabla-movs">
              <thead><tr><th>Tipo</th><th>Comercio</th><th>Fecha</th><th>Estado</th><th class="cm-num">Importe</th></tr></thead>
              <tbody>
              <?php foreach ($movimientos as $m):
                $neg = in_array($m['tipo'], ['consumo','interes','comision','mora'], true);
                $tm  = strtotime((string) $m['creado_en']);
              ?>
                <tr>
                  <td>
                    <?= P::e(ucfirst((string) $m['tipo'])) ?>
                    <?php if ($m['plan_id'] !== null): ?>
                      <span class="badge badge-info ml-1">diferido</span>
                    <?php endif; ?>
                  </td>
                  <td><?= P::e($m['comercio'] ?: '—') ?></td>
                  <td class="text-muted"><?= P::e(P::fecha((string) $m['creado_en'])) ?></td>
                  <td><span class="badge badge-light"><?= P::e($m['estado']) ?></span></td>
                  <td class="cm-num <?= $neg ? 'cm-neg' : 'cm-pos' ?>">
                    <?= $neg ? '-' : '+' ?><?= P::e(P::money((int) $m['importe_cent'])) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>

            <?php if ($movimientos === []): ?>
            <div class="cm-vacio py-4">
              <p class="mb-0" style="font-size:.88rem">
                No hubo movimientos en ese periodo.
              </p>
            </div>
            <?php endif; ?>
          </div>

          <?php
          /* La misma barra que el resto del sistema. */
          $pag          = $paginacionMov;
          $enlace       = static fn(int $p): string => $enlaceMov(['p' => (string) $p]);
          $enlaceTamano = static fn(int $t): string => $enlaceMov(['pp' => (string) $t, 'p' => '']);

          include __DIR__ . '/../layout/paginacion.php';
          ?>
          <?php endif; ?>

          <?php if ($puedeOperar && $tarjeta['estado'] === 'activa'): ?>
          <div class="card-footer">
            <form data-cm-ajax data-cm-recargar method="post"
                  action="<?= P::attr($ruta . '/consumo') ?>" class="form-row align-items-end">
              <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
              <input type="hidden" name="_csrf_proposito" value="tarjeta">
              <div class="form-group col-md-4 mb-0">
                <label class="small text-muted">Comercio (simulación)</label>
                <input type="text" class="form-control form-control-sm" name="comercio"
                       placeholder="Supermercado" required>
              </div>
              <div class="form-group col-md-3 mb-0">
                <label class="small text-muted">Monto</label>
                <input type="text" class="form-control form-control-sm" name="monto"
                       placeholder="0.00" inputmode="decimal" required>
              </div>
              <div class="form-group col-md-3 mb-0">
                <label class="small text-muted">Categoría</label>
                <select class="form-control form-control-sm" name="categoria">
                  <option value="supermercado">Supermercado</option>
                  <option value="restaurante">Restaurante</option>
                  <option value="combustible">Combustible</option>
                  <option value="tecnologia">Tecnología</option>
                  <option value="viajes">Viajes</option>
                  <option value="varios">Varios</option>
                </select>
              </div>
              <div class="form-group col-md-2 mb-0">
                <button class="btn btn-sm btn-outline-secondary btn-block">Simular consumo</button>
              </div>
            </form>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($esCredito && $puedeOperar): ?>
        <!-- ---------- COMPRAS A CUOTAS ---------- -->
        <div class="tab-pane fade" id="tab-cuotas">
          <div class="card-body">
            <?php
            /* El tope, dicho siempre y por delante.
               Rechazar la compra a cuotas cuando el cliente ya eligió el
               plazo le hace perder el paso por un dato que se sabía de
               antemano; y en la tarjeta que ya difirió todo —donde el
               dato más importa— la pestaña no decía absolutamente nada. */
            $tope   = (int) ($topePlanes ?? 5);
            $huecos = (int) ($huecosPlan ?? 0);
            $vivos  = max(0, $tope - $huecos);
            ?>
            <div class="alert <?= $huecos > 0 ? 'alert-light' : 'alert-warning' ?> py-2"
                 style="font-size:.84rem">
              <?php if ($huecos > 0): ?>
                Puede mantener hasta <strong><?= $tope ?> compras diferidas a la vez</strong>
                en esta tarjeta. Lleva <strong><?= $vivos ?></strong> vigente(s), así que
                puede diferir <strong><?= $huecos ?></strong> más.
              <?php else: ?>
                Ya tiene <strong><?= $vivos ?> compras diferidas vigentes</strong>, que es el
                máximo. Al terminar de pagar alguna se libera el cupo y podrá diferir otra.
              <?php endif; ?>
              Las compras a cuotas de la <a href="<?= P::attr($rutas->url('tienda')) ?>">tienda
              CashMan</a> se difieren solas, con el plazo que elija al pagar.
            </div>

            <?php if ($diferibles === []): ?>
              <div class="cm-vacio"><p class="mb-0">No hay consumos pendientes de diferir.</p></div>
            <?php else: ?>
            <p class="text-muted" style="font-size:.9rem">
              Elija el consumo, la modalidad y el plazo. En <strong>tasa cero</strong> paga
              exactamente lo que consumió, repartido en cuotas; <strong>con interés</strong>
              la cuota es menor en los plazos largos pero el total sube.
            </p>


            <form id="form-diferir" method="post" action="<?= P::attr($ruta . '/diferir') ?>">
              <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
              <input type="hidden" name="_csrf_proposito" value="tarjeta">

              <div class="form-group">
                <label>Consumo a diferir</label>
                <select class="form-control" name="movimiento" id="sel-movimiento" required>
                  <option value="">— Seleccione un consumo —</option>
                  <?php foreach ($diferibles as $d): ?>
                  <option value="<?= (int) $d['id'] ?>" data-monto="<?= (int) $d['importe_cent'] ?>">
                    <?= P::e(P::fecha((string) $d['creado_en'], 'd/m/Y')) ?> &middot;
                    <?= P::e($d['comercio'] ?: 'Consumo') ?> &middot;
                    <?= P::e(P::money((int) $d['importe_cent'])) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div id="opciones-diferido" class="d-none">
                <?php /* La modalidad primero. Antes se enseñaban las dos
                         a la vez en la misma tabla y el formulario
                         mandaba siempre tasa cero: dos columnas con
                         cifras que no iban a aplicarse. */ ?>
                <label class="d-block">Modalidad</label>
                <div class="btn-group btn-group-sm mb-2" role="group" id="grp-modalidad">
                  <button type="button" class="btn btn-success active"
                          data-modalidad="tasa_cero">Tasa cero</button>
                  <button type="button" class="btn btn-outline-secondary"
                          data-modalidad="con_interes" id="btn-mod-interes">Con interés</button>
                </div>
                <p class="text-muted mb-3" style="font-size:.82rem" id="nota-modalidad"></p>

                <label class="d-block">Plazo</label>
                <div class="table-responsive mb-3">
                  <table class="table table-sm table-hover mb-0" id="tabla-simulacion">
                    <thead>
                      <tr><th></th><th>Plazo</th>
                          <th class="cm-num">Cuota mensual</th>
                          <th class="cm-num col-interes">Intereses</th>
                          <th class="cm-num">Total a pagar</th></tr>
                    </thead>
                    <tbody></tbody>
                  </table>
                </div>
                <button type="submit" class="btn btn-primary" id="btn-diferir"
                        <?= $huecos > 0 ? 'disabled' : 'disabled title="Sin cupo de planes disponible"' ?>>
                  Diferir compra
                </button>
              </div>
            </form>
            <?php endif; ?>
          </div>
        </div>

        <!-- ---------- PLANES ---------- -->
        <div class="tab-pane fade" id="tab-planes">
          <?php if ($planes === []): ?>
            <div class="cm-vacio"><p class="mb-0">No tiene compras diferidas.</p></div>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead><tr><th>Folio</th><th>Comercio</th><th>Modalidad</th><th>Avance</th>
                         <th class="cm-num">Cuota</th><th class="cm-num">Pendiente</th>
                         <th>Próxima</th><th>Estado</th></tr></thead>
              <tbody>
              <?php foreach ($planes as $p):
                $avance = (int) $p['cuotas'] > 0
                    ? (int) round(((int) $p['cuotas_pagadas'] / (int) $p['cuotas']) * 100) : 0;
              ?>
                <tr>
                  <td class="cm-mono"><?= P::e($p['folio']) ?></td>
                  <td><?= P::e($p['comercio'] ?: '—') ?></td>
                  <td>
                    <?php if ($p['tipo'] === 'tasa_cero'): ?>
                      <span class="badge badge-success">Tasa cero</span>
                    <?php elseif ($p['tipo'] === 'traslado'): ?>
                      <span class="badge badge-info">Traslado</span>
                    <?php else: ?>
                      <span class="badge badge-warning"><?= number_format((int) $p['tasa_bp'] / 100, 2) ?>%</span>
                    <?php endif; ?>
                  </td>
                  <td style="min-width:110px">
                    <div class="progress" style="height:6px">
                      <div class="progress-bar bg-success" style="width:<?= $avance ?>%"></div>
                    </div>
                    <small class="text-muted"><?= (int) $p['cuotas_pagadas'] ?>/<?= (int) $p['cuotas'] ?></small>
                  </td>
                  <td class="cm-num"><?= P::e(P::money((int) $p['cuota_cent'])) ?></td>
                  <td class="cm-num cm-neg"><?= P::e(P::money((int) $p['pendiente_cent'])) ?></td>
                  <td class="text-muted"><?= P::e(P::fecha((string) ($p['proxima_cuota'] ?? ''), 'd/m/Y')) ?></td>
                  <td><span class="badge badge-<?= $p['estado'] === 'vigente' ? 'primary' : 'light' ?>">
                    <?= P::e(ucfirst((string) $p['estado'])) ?></span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>

        <!-- ---------- EXTRAFINANCIAMIENTO ---------- -->
        <?php if ($extras !== []): ?>
        <div class="tab-pane fade" id="tab-extra">
          <div class="card-body pb-0">
            <p class="text-muted mb-3" style="font-size:.88rem;max-width:46rem">
              Efectivo que se le entregó en su cuenta de ahorro contra el cupo de
              esta tarjeta. No es un crédito aparte: la cuota se carga en el estado
              de cuenta de la tarjeta en cada corte, y el saldo pendiente mantiene
              ocupada esa parte de su línea hasta liquidarlo.
            </p>
          </div>

          <?php foreach ($extras as $x):
            $pagadas = (int) $x['cuotas_pagadas'];
            $plazo   = max(1, (int) $x['plazo_meses']);
            $avance  = (int) round(($pagadas / $plazo) * 100);
          ?>
          <div class="border-top">
            <div class="card-body pb-2">
              <div class="d-flex justify-content-between align-items-start flex-wrap"
                   style="gap:1rem">
                <div style="min-width:0">
                  <div class="cm-mono text-muted" style="font-size:.76rem">
                    <?= P::e($x['folio']) ?>
                  </div>
                  <h5 class="mb-1"><?= P::e(P::money((int) $x['monto_cent'])) ?>
                    <small class="text-muted" style="font-size:.78rem">
                      a <?= $plazo ?> meses &middot;
                      <?= number_format((int) $x['tasa_bp'] / 100, 2) ?>% anual
                    </small>
                  </h5>
                  <div class="text-muted" style="font-size:.8rem">
                    Otorgado el <?= P::e(P::fecha((string) $x['otorgado_en'], 'd/m/Y')) ?>
                  </div>
                </div>

                <div class="text-right cm-nowrap">
                  <small class="text-muted d-block">Pendiente</small>
                  <strong class="cm-neg" style="font-size:1.05rem">
                    <?= P::e(P::money((int) $x['pendiente_cent'])) ?>
                  </strong>
                  <small class="text-muted d-block" style="font-size:.74rem">
                    de <?= P::e(P::money((int) $x['total_cent'])) ?> en total
                  </small>
                </div>
              </div>

              <div class="row mt-3">
                <div class="col-6 col-md-3 mb-2">
                  <small class="text-muted d-block">Cuota mensual</small>
                  <strong><?= P::e(P::money((int) $x['cuota_cent'])) ?></strong>
                </div>
                <div class="col-6 col-md-3 mb-2">
                  <small class="text-muted d-block">Próximo cargo</small>
                  <strong><?= $x['proxima_cuota'] !== null
                        ? P::e(P::fecha((string) $x['proxima_cuota'], 'd/m/Y')) : '—' ?></strong>
                </div>
                <div class="col-6 col-md-3 mb-2">
                  <small class="text-muted d-block">Intereses del plazo</small>
                  <strong><?= P::e(P::money((int) $x['interes_total_cent'])) ?></strong>
                </div>
                <div class="col-6 col-md-3 mb-2">
                  <small class="text-muted d-block">Avance</small>
                  <div class="progress mt-1" style="height:6px">
                    <div class="progress-bar bg-success" style="width:<?= $avance ?>%"></div>
                  </div>
                  <small class="text-muted"><?= $pagadas ?>/<?= $plazo ?> cuotas</small>
                </div>
              </div>

              <a class="btn btn-sm btn-outline-secondary" data-toggle="collapse"
                 href="#<?= P::attr($x['ancla']) ?>" role="button"
                 aria-expanded="false" aria-controls="<?= P::attr($x['ancla']) ?>">
                Ver el calendario de <?= $plazo ?> cuotas
              </a>
            </div>

            <div class="collapse" id="<?= P::attr($x['ancla']) ?>">
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead>
                    <tr><th>#</th><th>Se carga en el corte</th>
                        <th class="cm-num">Capital</th><th class="cm-num">Intereses</th>
                        <th class="cm-num">Cuota</th><th class="cm-num">Saldo después</th>
                        <th>Estado</th></tr>
                  </thead>
                  <tbody>
                  <?php foreach ($x['cuotas'] as $q): ?>
                    <tr<?= $q['cobrada_en'] !== null ? ' class="text-muted"' : '' ?>>
                      <td><?= (int) $q['numero'] ?></td>
                      <td class="cm-nowrap"><?= P::e(P::fecha((string) $q['fecha_corte'], 'd/m/Y')) ?></td>
                      <td class="cm-num"><?= P::e(P::money((int) $q['capital_cent'])) ?></td>
                      <td class="cm-num"><?= P::e(P::money((int) $q['interes_cent'])) ?></td>
                      <td class="cm-num font-weight-bold"><?= P::e(P::money((int) $q['total_cent'])) ?></td>
                      <td class="cm-num"><?= P::e(P::money((int) $q['saldo_posterior_cent'])) ?></td>
                      <td>
                        <?php if ($q['cobrada_en'] !== null): ?>
                          <span class="badge badge-success">Cobrada</span>
                        <?php else: ?>
                          <span class="badge badge-light">Pendiente</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ---------- TRASLADO DE SALDO ---------- -->
        <?php if ($otras !== []): ?>
        <!-- ---------- ADELANTO DE EFECTIVO ---------- -->
        <?php
        /* Disponer de la línea en dinero, a una cuenta propia.
           Lo que se enseña arriba del todo es lo que se le va a COBRAR,
           no lo que se lleva: son cifras distintas y confundirlas es lo
           que acaba en una reclamación en la ventanilla. */
        $tarAdel = $adelanto ?? ['comision_bp' => 500, 'minima_cent' => 500, 'gasto_cent' => 300];
        ?>
        <div class="tab-pane fade" id="tab-adelanto">
          <div class="card-body">
            <p class="text-muted" style="font-size:.9rem">
              Pase parte de su línea de crédito a una de sus cuentas, hasta el
              100 % de lo que tenga disponible. El dinero le queda acreditado
              en el acto.
            </p>

            <div class="alert alert-warning" style="font-size:.86rem">
              <strong>Esto no es una compra y se cobra distinto.</strong>
              Se le cargan
              <strong><?= P::e(number_format($tarAdel['comision_bp'] / 100, 2)) ?>%</strong>
              de comisión sobre lo que disponga, con un mínimo de
              <strong><?= P::e(P::money((int) $tarAdel['minima_cent'])) ?></strong>,
              más <strong><?= P::e(P::money((int) $tarAdel['gasto_cent'])) ?></strong>
              de gasto administrativo. Los dos cargos aparecen en su estado de
              cuenta y ocupan cupo, igual que el adelanto.
            </div>

            <?php if ($cuentasTitular === []): ?>
            <div class="alert alert-light border">
              Para recibir un adelanto necesita una cuenta activa a su nombre.
            </div>
            <?php else: ?>
            <form id="form-adelanto">
              <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
              <input type="hidden" name="_csrf_proposito" value="tarjeta">
              <input type="hidden" name="tarjeta" value="<?= P::attr($tarjeta['codigo']) ?>">

              <div class="form-row">
                <div class="form-group col-md-7">
                  <label>¿A cuál de sus cuentas?</label>
                  <select class="form-control" name="cuenta" id="adel-cuenta" required>
                    <?php foreach ($cuentasTitular as $c): ?>
                    <option value="<?= P::attr($c['codigo']) ?>">
                      <?= P::e($c['numero_cuenta'] . ' · ' . $c['producto']) ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group col-md-5">
                  <label>¿Cuánto desea recibir?</label>
                  <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                    <input type="text" class="form-control" name="monto" id="adel-monto"
                           inputmode="decimal" placeholder="0.00" required autocomplete="off">
                  </div>
                  <small class="text-muted" id="adel-maximo">&nbsp;</small>
                </div>
              </div>

              <!-- El desglose, antes de confirmar y no en el estado de cuenta. -->
              <div class="card bg-light d-none mb-3" id="adel-resumen">
                <div class="card-body py-2">
                  <div class="row text-center">
                    <div class="col-4">
                      <small class="text-muted d-block">Recibe en su cuenta</small>
                      <strong class="cm-pos" id="adel-recibe">—</strong>
                    </div>
                    <div class="col-4">
                      <small class="text-muted d-block">Comisión y gasto</small>
                      <strong id="adel-cargos">—</strong>
                    </div>
                    <div class="col-4">
                      <small class="text-muted d-block">Se le carga a la tarjeta</small>
                      <strong class="cm-neg" id="adel-total">—</strong>
                    </div>
                  </div>
                </div>
              </div>

              <div id="adel-aviso" class="d-none"></div>

              <button type="submit" class="btn btn-primary" id="btn-adelanto" disabled>
                Confirmar adelanto
              </button>
            </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="tab-pane fade" id="tab-traslado">
          <div class="card-body">
            <p class="text-muted" style="font-size:.9rem">
              Traslada parte de su saldo a otra de sus tarjetas y páguelo
              <strong>en cuotas sin intereses</strong>. Útil para consolidar deuda
              en la tarjeta de menor tasa.
            </p>

            <div id="aviso-traslado" class="d-none"></div>

            <form id="form-traslado">
              <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
              <input type="hidden" name="_csrf_proposito" value="tarjeta">
              <input type="hidden" name="origen" value="<?= P::attr($tarjeta['codigo']) ?>">

              <div class="form-row">
                <div class="form-group col-md-6">
                  <label>Trasladar a</label>
                  <select class="form-control" name="destino" required>
                    <?php foreach ($otras as $o): ?>
                    <option value="<?= P::attr($o['codigo']) ?>">
                      <?= P::e(strtoupper((string) $o['marca'])) ?> &bull;&bull;&bull;&bull;<?= P::e($o['ultimos4']) ?>
                      &middot; cupo <?= P::e(P::money((int) $o['disponible_cent'])) ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group col-md-3">
                  <label>Monto</label>
                  <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                    <input type="text" class="form-control" name="monto" inputmode="decimal"
                           placeholder="0.00" required>
                  </div>
                  <small class="text-muted">
                    Máx. <?= P::e(P::money((int) $tarjeta['saldo_usado_cent'])) ?>
                  </small>
                </div>
                <div class="form-group col-md-3">
                  <label>Cuotas</label>
                  <select class="form-control" name="cuotas">
                    <?php foreach ([3,6,9,12,18,24] as $n): ?>
                    <option value="<?= $n ?>"<?= $n === 12 ? ' selected' : '' ?>><?= $n ?> meses</option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div id="otp-traslado" class="form-group d-none">
                <label>Código de verificación</label>
                <input type="text" class="form-control text-center" name="codigo" id="codigo-otp"
                       maxlength="6" inputmode="numeric" placeholder="000000"
                       style="font-size:1.4rem;letter-spacing:.5em;font-family:Consolas,monospace">
              </div>

              <button type="button" class="btn btn-outline-primary" id="btn-cod-traslado">
                Solicitar código
              </button>
              <button type="submit" class="btn btn-primary d-none" id="btn-traslado">
                Confirmar traslado
              </button>
            </form>
          </div>
        </div>
        <?php endif; ?>

        <!-- ---------- CONTROLES ---------- -->
<!-- ---------- ESTADOS DE CUENTA ---------- -->
        <div class="tab-pane fade" id="tab-estados">
          <div class="card-body">
            <?php if ($cortes === []): ?>
              <div class="cm-vacio">
                <p class="mb-1">Todavía no hay estados de cuenta.</p>
                <p class="mb-0 text-muted" style="font-size:.86rem">
                  El primero se emite en la fecha de corte de la tarjeta.
                </p>
              </div>
            <?php else: ?>

            <?php
            /* Los años y meses que de verdad tienen corte.
               Un desplegable con los doce meses ofrece once puertas a
               pantallas vacías; estos son los periodos que existen. */
            $anios = [];
            $meses = [];

            foreach ($cortes as $k) {
                $tc = strtotime((string) $k['fecha_corte']);
                $anios[date('Y', $tc)] = true;
                $meses[(int) date('n', $tc)] = true;
            }

            krsort($anios);
            ksort($meses);

            $nombreMes = [
                1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
            ];
            ?>

            <div class="form-row align-items-end mb-3">
              <div class="form-group col-md-3">
                <label class="small mb-1">Año</label>
                <select class="form-control form-control-sm" id="f-anio">
                  <option value="">Todos</option>
                  <?php foreach (array_keys($anios) as $a): ?>
                  <option value="<?= (int) $a ?>"><?= (int) $a ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-md-3">
                <label class="small mb-1">Mes</label>
                <select class="form-control form-control-sm" id="f-mes">
                  <option value="">Todos</option>
                  <?php foreach (array_keys($meses) as $mm): ?>
                  <option value="<?= (int) $mm ?>"><?= P::e($nombreMes[(int) $mm]) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-md-3">
                <button type="button" class="btn btn-outline-secondary btn-sm btn-block"
                        id="f-limpiar">Quitar filtro</button>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table table-hover mb-0" id="tabla-estados">
                <thead>
                  <tr>
                    <th>Periodo</th><th>Corte</th><th>Límite de pago</th>
                    <th class="cm-num">Movs.</th>
                    <th class="cm-num">Saldo</th><th class="cm-num">Pago mínimo</th>
                    <th class="cm-acciones">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($cortes as $k): $tc = strtotime((string) $k['fecha_corte']); ?>
                  <tr data-anio="<?= date('Y', $tc) ?>" data-mes="<?= (int) date('n', $tc) ?>">
                    <td class="text-muted cm-nowrap" style="font-size:.82rem">
                      <?= P::e(P::fecha((string) $k['periodo_inicio'], 'd/m/Y')) ?>
                      &ndash; <?= P::e(P::fecha((string) $k['periodo_fin'], 'd/m/Y')) ?>
                    </td>
                    <td class="cm-nowrap"><?= P::e(P::fecha((string) $k['fecha_corte'], 'd/m/Y')) ?></td>
                    <td class="cm-nowrap"><?= P::e(P::fecha((string) $k['fecha_limite_pago'], 'd/m/Y')) ?></td>
                    <td class="cm-num text-muted"><?= (int) $k['movimientos'] ?></td>
                    <td class="cm-num font-weight-bold"><?= P::e(P::money((int) $k['saldo_final_cent'])) ?></td>
                    <td class="cm-num"><?= P::e(P::money((int) $k['pago_minimo_cent'])) ?></td>
                    <td class="text-right">
                      <?= P::crudo(cm_acciones([
                            ['Descargar en PDF',
                             $rutas->url('tarjetas/' . $tarjeta['codigo'] . '/estado/' . (int) $k['id']),
                             'download', 'nueva' => true,
                             'documento' => 'Preparando el estado de cuenta',
                             'titulo' => 'Estado de cuenta del corte '
                                       . P::fecha((string) $k['fecha_corte'], 'd/m/Y')],
                          ])) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <p class="text-muted mt-3 mb-0" style="font-size:.82rem">
              Cada estado de cuenta lleva el detalle del periodo —consumo por consumo— y el
              cuadre completo: saldo anterior, lo del mes, intereses, seguros y el saldo que
              queda. Es el documento con el que se comprueba un cargo.
            </p>
            <?php endif; ?>
          </div>
        </div>
        <div class="tab-pane fade" id="tab-config">
          <div class="card-body">
            <form data-cm-ajax method="post" action="<?= P::attr($ruta . '/configurar') ?>">
              <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
              <input type="hidden" name="_csrf_proposito" value="tarjeta">

              <div class="form-row">
                <div class="form-group col-md-6">
                  <label>Alias de la tarjeta</label>
                  <input type="text" class="form-control" name="alias" maxlength="40"
                         value="<?= P::attr($tarjeta['alias'] ?? '') ?>"
                         placeholder="Ej. Tarjeta de gastos del hogar">
                </div>
                <div class="form-group col-md-6">
                  <label>Avisarme cuando una compra supere</label>
                  <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                    <input type="text" class="form-control" name="alerta_consumo" inputmode="decimal"
                           value="<?= P::attr(number_format((int) ($tarjeta['alerta_consumo_cent'] ?? 0) / 100, 2, '.', '')) ?>">
                  </div>
                  <small class="form-text text-muted">
                    Le llega un aviso al instante. En <strong>0.00</strong> no se avisa de nada.
                  </small>
                </div>
              </div>

              <?php if ($esCredito): ?>
              <!-- ---------- SOBREGIRO DE CUPO ----------
                   Aquí estaba el «límite diario de consumo», que no
                   protegía de nada que el cupo no protegiera ya. Lo que
                   de verdad hace falta decidir es qué pasa cuando el
                   consumo toca el cupo. -->
              <div class="cm-bloque-config mb-3">
                <label class="d-block font-weight-bold mb-1">Sobregiro de cupo</label>
                <p class="text-muted mb-2" style="font-size:.82rem">
                  Si lo activa, sus compras pueden pasar del cupo hasta el margen que
                  elija, con una comisión de
                  <strong><?= P::e(P::money($sobregiroComision)) ?></strong> por cada
                  consumo que lo use. Es para que una compra no se le caiga por unos
                  pocos dólares; usted decide de antemano.
                </p>

                <div class="custom-control custom-switch mb-2">
                  <input type="hidden" name="sobregiro_permitido" value="0">
                  <input type="checkbox" class="custom-control-input" id="sw-sobregiro"
                         name="sobregiro_permitido" value="1"
                         <?= (int) ($tarjeta['sobregiro_permitido'] ?? 0) === 1 ? 'checked' : '' ?>>
                  <label class="custom-control-label" for="sw-sobregiro">
                    Permitir pasarme del cupo
                  </label>
                </div>

                <div class="form-group mb-0" style="max-width:22rem">
                  <label style="font-size:.84rem">Margen autorizado</label>
                  <select class="form-control" name="sobregiro_tolerancia_bp">
                    <?php
                      $actual = (int) ($tarjeta['sobregiro_tolerancia_bp'] ?? 0);
                      $limite = (int) $tarjeta['limite_cent'];
                      foreach ([0, 500, 1000, 1500] as $bp):
                        if ($bp > $sobregiroTecho) continue;
                        $equivale = (int) round($limite * $bp / 10000);
                    ?>
                    <option value="<?= $bp ?>" <?= $actual === $bp ? 'selected' : '' ?>>
                      <?= $bp === 0
                            ? 'Sin margen: el cupo es el tope'
                            : rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.')
                              . '% del cupo (hasta ' . P::money($equivale) . ' de más)' ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <!-- ---------- PAGO AUTOMÁTICO ----------
                   Evitar la mora vale más que cobrarla. -->
              <div class="cm-bloque-config mb-3">
                <label class="d-block font-weight-bold mb-1">Pago automático al vencimiento</label>
                <p class="text-muted mb-2" style="font-size:.82rem">
                  Nosotros nos encargamos de pagar su tarjeta el día del vencimiento,
                  debitando de la cuenta que usted nos indique. Así no depende de
                  acordarse, y se ahorra la mora.
                </p>

                <div class="form-row">
                  <div class="form-group col-md-6 mb-0">
                    <label style="font-size:.84rem">¿Qué pagamos?</label>
                    <select class="form-control" name="pago_automatico">
                      <?php
                        $modo = (string) ($tarjeta['pago_automatico'] ?? 'no');
                        $modos = [
                          'no'     => 'Nada: yo pago cuando quiera',
                          'minimo' => 'El pago mínimo',
                          'total'  => 'El saldo completo',
                        ];
                        foreach ($modos as $v => $txt):
                      ?>
                      <option value="<?= P::attr($v) ?>" <?= $modo === $v ? 'selected' : '' ?>>
                        <?= P::e($txt) ?>
                      </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-group col-md-6 mb-0">
                    <label style="font-size:.84rem">¿De cuál de sus cuentas?</label>
                    <select class="form-control" name="pago_automatico_cuenta_id">
                      <option value="0">— Elija una cuenta —</option>
                      <?php foreach ($cuentasTitular as $c): ?>
                      <option value="<?= (int) $c['id'] ?>"
                        <?= (int) ($tarjeta['pago_automatico_cuenta_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= P::e($c['numero_cuenta'] . ' · ' . $c['producto']) ?>
                      </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
              </div>
              <?php endif; ?>

              <div class="form-group">
                <label class="d-block">Controles de uso</label>
                <?php
                  $controles = [
                    'compras_internet' => 'Compras por internet',
                    'compras_exterior' => 'Compras en el exterior',
                    'contactless'      => 'Pago sin contacto',
                  ];
                  foreach ($controles as $campo => $etiqueta):
                ?>
                <div class="custom-control custom-switch mb-2">
                  <input type="hidden" name="<?= P::attr($campo) ?>" value="0">
                  <input type="checkbox" class="custom-control-input"
                         id="sw-<?= P::attr($campo) ?>" name="<?= P::attr($campo) ?>" value="1"
                         <?= (int) $tarjeta[$campo] === 1 ? 'checked' : '' ?>>
                  <label class="custom-control-label" for="sw-<?= P::attr($campo) ?>">
                    <?= P::e($etiqueta) ?>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>

              <button type="submit" class="btn btn-primary">Guardar controles</button>
            </form>

            <hr>

            <form data-cm-ajax data-cm-recargar method="post"
                  action="<?= P::attr($ruta . '/bloquear') ?>" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
              <input type="hidden" name="_csrf_proposito" value="tarjeta">
              <button type="submit"
                      class="btn btn-<?= $tarjeta['estado'] === 'bloqueada' ? 'success' : 'danger' ?>"
                      data-cm-confirmar="<?= $tarjeta['estado'] === 'bloqueada'
                          ? 'La tarjeta volverá a estar operativa.'
                          : 'Bloqueo permanente: la tarjeta dejará de funcionar.' ?>">
                <?= $tarjeta['estado'] === 'bloqueada' ? 'Reactivar tarjeta' : 'Bloquear definitivamente' ?>
              </button>
            </form>
            <small class="text-muted d-block mt-2">
              Para un bloqueo temporal use <strong>Congelar</strong>: usted mismo puede revertirlo cuando quiera.
            </small>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<!-- ============ MODAL: VER DATOS ============ -->
<div class="modal fade" id="modal-datos" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Datos de la tarjeta</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" id="cuerpo-datos">
        <p class="text-muted">
          Por seguridad generamos un código de verificación. Lo encontrará en sus
          notificaciones (la campana, arriba a la derecha); si el correo está
          activo, también le llegará ahí. Use siempre el más reciente. Los datos
          se mostrarán una sola vez durante 90 segundos.
        </p>
        <div class="form-group d-none" id="campo-codigo">
          <label>Código de verificación</label>
          <input type="text" class="form-control text-center" id="codigo-datos" maxlength="6"
                 inputmode="numeric" placeholder="000000"
                 style="font-size:1.4rem;letter-spacing:.5em;font-family:Consolas,monospace">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cerrar</button>
        <button type="button" class="btn btn-primary" id="btn-pedir-codigo">Enviar código</button>
        <button type="button" class="btn btn-primary d-none" id="btn-confirmar-datos">Ver datos</button>
      </div>
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';
  var RUTA = <?= P::js($ruta) ?>;
  /* El token de seguridad se lee vivo, no congelado.

     Aquí había una copia del token tomada al
     dibujar la página. Como el token es de un solo uso, la primera
     acción lo gastaba y la segunda fallaba siempre con «el token de
     seguridad venció»: pedir el código llegaba, pulsar «Ver datos» no.

     El servidor manda el reemplazo en cada respuesta y `cm.js` lo
     guarda en `window.CM_CSRF`. Leer de ahí vale para toda la vida de
     la pantalla, por muchas acciones que se encadenen. */
  window.CM_CSRF = window.CM_CSRF || <?= P::js($csrf) ?>;

  function post(url, datos) {
    var fd = new FormData();
    fd.append('_csrf', window.CM_CSRF);
    fd.append('_csrf_proposito', 'tarjeta');
    Object.keys(datos || {}).forEach(function (k) { fd.append(k, datos[k]); });
    return fetch(url, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); });
  }

  // ---------- Ver datos ----------
  var btnVer = document.getElementById('btn-ver-datos');
  if (btnVer) {
    btnVer.addEventListener('click', function () { $('#modal-datos').modal('show'); });
  }

  var btnPedir = document.getElementById('btn-pedir-codigo');
  if (btnPedir) {
    btnPedir.addEventListener('click', function () {
      btnPedir.disabled = true; btnPedir.textContent = 'Enviando…';
      post(RUTA + '/codigo-datos', {}).then(function (j) {
        btnPedir.disabled = false; btnPedir.textContent = 'Reenviar código';
        if (j.ok) {
          document.getElementById('campo-codigo').classList.remove('d-none');
          document.getElementById('btn-confirmar-datos').classList.remove('d-none');
          document.getElementById('codigo-datos').focus();
          window.avisar('Código enviado', j.mensaje, 'exito');
        } else { window.avisar('Error', j.mensaje, 'critico'); }
      });
    });
  }

  var btnConf = document.getElementById('btn-confirmar-datos');
  if (btnConf) {
    btnConf.addEventListener('click', function () {
      var cod = document.getElementById('codigo-datos').value.trim();
      if (!cod) { window.avisar('Atención', 'Ingrese el código.', 'alerta'); return; }

      btnConf.disabled = true;
      post(RUTA + '/token-datos', { codigo: cod }).then(function (j) {
        if (!j.ok) { btnConf.disabled = false; window.avisar('Error', j.mensaje, 'critico'); return; }
        return post(RUTA + '/datos', { token: j.datos.token }).then(function (d) {
          btnConf.disabled = false;
          if (!d.ok) { window.avisar('Error', d.mensaje, 'critico'); return; }
          mostrarDatos(d.datos);
        });
      });
    });
  }

  /* ---------- El giro ----------
     Una tarjeta tiene reverso, y ahí están la banda, el panel de firma y
     el CVV. El botón la voltea; al revelar los datos se voltea sola,
     porque enseñar el CVV sin girar sería pedir que se busque donde no
     está. */
  var plastico = document.getElementById('plastico');
  var btnVolt  = document.getElementById('btn-voltear');

  function voltear(haciaAtras) {
    if (!plastico) { return; }

    var atras = haciaAtras === undefined
      ? !plastico.classList.contains('cm-pl-giro--volteada')
      : haciaAtras;

    plastico.classList.toggle('cm-pl-giro--volteada', atras);

    if (btnVolt) {
      btnVolt.setAttribute('aria-label',
        atras ? 'Ver el frente de la tarjeta' : 'Ver el reverso de la tarjeta');
      // El rotulo dice a donde lleva, no donde se esta.
      var et = btnVolt.querySelector('span');
      btnVolt.innerHTML = btnVolt.innerHTML.replace(
        atras ? 'Reverso' : 'Frente', atras ? 'Frente' : 'Reverso');
    }
  }

  if (btnVolt) {
    btnVolt.addEventListener('click', function () { voltear(); });
  }

  function mostrarDatos(d) {
    $('#modal-datos').modal('hide');

    document.getElementById('pan-mostrado').textContent = d.pan;
    document.getElementById('vence-mostrado').textContent = d.vence;
    document.getElementById('cvv-mostrado').textContent = d.cvv || '•••';

    // El CVV vive detrás: se le da la vuelta para que se vea.
    voltear(true);

    var seg = d.expira_en || 90;
    window.avisar('Datos visibles', 'Se ocultarán en ' + seg + ' segundos.', 'alerta');

    // Ocultar solos: el PAN no debe quedarse en pantalla.
    setTimeout(function () {
      document.getElementById('pan-mostrado').innerHTML =
        '&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; ' +
        <?= P::js($tarjeta['ultimos4']) ?>;
      document.getElementById('cvv-mostrado').textContent = '•••';
      voltear(false);
      document.getElementById('codigo-datos').value = '';
      document.getElementById('campo-codigo').classList.add('d-none');
      document.getElementById('btn-confirmar-datos').classList.add('d-none');
    }, seg * 1000);
  }


  /* ---------- Filtro de estados de cuenta ----------
     En el navegador: son doce al año, así que traerlos todos y esconder
     los que no tocan es instantáneo y ahorra una ida y vuelta por cada
     cambio de mes. */
  (function () {
    var selAnio = document.getElementById('f-anio');
    var selMes  = document.getElementById('f-mes');
    var limpiar = document.getElementById('f-limpiar');
    var tabla   = document.getElementById('tabla-estados');

    if (!tabla) { return; }

    function aplicar() {
      var a = selAnio ? selAnio.value : '';
      var m = selMes ? selMes.value : '';
      var visibles = 0;

      tabla.querySelectorAll('tbody tr').forEach(function (tr) {
        if (tr.classList.contains('cm-sin-resultados')) { return; }

        var ok = (a === '' || tr.dataset.anio === a)
              && (m === '' || tr.dataset.mes === m);

        tr.style.display = ok ? '' : 'none';
        if (ok) { visibles++; }
      });

      // Decir que no hay nada es mejor que una tabla vacia sin explicar.
      var aviso = tabla.querySelector('tr.cm-sin-resultados');

      if (visibles === 0) {
        if (!aviso) {
          aviso = document.createElement('tr');
          aviso.className = 'cm-sin-resultados';
          aviso.innerHTML = '<td colspan="7" class="text-center text-muted py-3">'
            + 'No hay estados de cuenta en ese periodo.</td>';
          tabla.querySelector('tbody').appendChild(aviso);
        }
        aviso.style.display = '';
      } else if (aviso) {
        aviso.style.display = 'none';
      }
    }

    if (selAnio) { selAnio.addEventListener('change', aplicar); }
    if (selMes)  { selMes.addEventListener('change', aplicar); }

    if (limpiar) {
      limpiar.addEventListener('click', function () {
        if (selAnio) { selAnio.value = ''; }
        if (selMes)  { selMes.value = ''; }
        aplicar();
      });
    }
  })();
  // ---------- Congelar ----------
  ['btn-congelar', 'btn-descongelar'].forEach(function (id) {
    var b = document.getElementById(id);
    if (!b) return;
    b.addEventListener('click', function () {
      var desc = id === 'btn-descongelar';
      post(RUTA + '/congelar', desc ? { accion: 'descongelar' } : { horas: 24 })
        .then(function (j) {
          window.avisar(j.ok ? 'Listo' : 'Error', j.mensaje, j.ok ? 'exito' : 'critico');
          if (j.ok) setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 900);
        });
    });
  });

  // ---------- Simulador de cuotas ----------
  /* La modalidad es una decisión, no una columna.
     Antes la tabla enseñaba a la vez la cuota sin intereses y la cuota
     con intereses, pero el formulario mandaba siempre tasa cero: dos
     columnas de adorno y la palabra «interés» en una compra que no los
     llevaba. Ahora se elige, y la tabla enseña lo de esa modalidad. */
  var HUECOS_PLAN = <?= (int) ($huecosPlan ?? 0) ?>;
  var modalidad = 'tasa_cero';
  var opciones  = [];
  var tasaBp    = 0;

  function pintarOpciones() {
    var tb = document.querySelector('#tabla-simulacion tbody');
    if (!tb) { return; }

    var cero = modalidad === 'tasa_cero';
    tb.innerHTML = '';

    // En tasa cero no hay intereses: la columna sobra.
    document.querySelectorAll('#tabla-simulacion .col-interes').forEach(function (c) {
      c.classList.toggle('d-none', cero);
    });

    opciones.forEach(function (o) {
      var cuota = cero ? o.cuota_cero_cent : o.cuota_int_cent;
      var total = cero ? o.total_cero_cent : o.total_int_cent;

      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td><input type="radio" name="plazo_sel" value="' + o.cuotas + '"></td>' +
        '<td><strong>' + o.cuotas + '</strong> cuotas</td>' +
        '<td class="cm-num"><span class="badge badge-' + (cero ? 'success' : 'warning') +
          '">' + window.CMUtil.money(cuota) + '</span></td>' +
        '<td class="cm-num text-muted col-interes' + (cero ? ' d-none' : '') + '">' +
          window.CMUtil.money(o.interes_cent) + '</td>' +
        '<td class="cm-num font-weight-bold">' + window.CMUtil.money(total) + '</td>';
      tb.appendChild(tr);
    });

    var nota = document.getElementById('nota-modalidad');
    if (nota) {
      nota.innerHTML = cero
        ? 'Paga exactamente lo que consumió, repartido en cuotas. '
          + '<strong>Sin intereses ni recargos.</strong>'
        : 'El saldo diferido devenga el ' + (tasaBp / 100).toFixed(2)
          + '% anual. La columna <strong>Intereses</strong> es lo que pagará de más.';
    }

    var btn = document.getElementById('btn-diferir');
    if (btn) { btn.disabled = true; }

    // Sin cupo de planes no hay plazo que valga: el aviso ya lo explica
    // arriba y habilitar el botón solo llevaría a un error del servidor.
    if (HUECOS_PLAN < 1) { return; }

    document.querySelectorAll('#tabla-simulacion input[name="plazo_sel"]').forEach(function (rb) {
      rb.addEventListener('change', function () {
        document.getElementById('btn-diferir').disabled = false;
      });
    });
  }

  var grupoMod = document.getElementById('grp-modalidad');

  if (grupoMod) {
    grupoMod.addEventListener('click', function (ev) {
      var b = ev.target.closest('button[data-modalidad]');
      if (!b) { return; }

      modalidad = b.getAttribute('data-modalidad');

      grupoMod.querySelectorAll('button').forEach(function (x) {
        var suya = x === b;
        x.classList.toggle('active', suya);
        x.classList.toggle('btn-outline-secondary', !suya);
        x.classList.toggle(
          x.getAttribute('data-modalidad') === 'tasa_cero' ? 'btn-success' : 'btn-warning',
          suya
        );
      });

      pintarOpciones();
    });
  }

  var sel = document.getElementById('sel-movimiento');
  if (sel) {
    sel.addEventListener('change', function () {
      var mov = sel.value;
      var caja = document.getElementById('opciones-diferido');
      if (!mov) { caja.classList.add('d-none'); return; }

      post(RUTA + '/simular', { movimiento: mov }).then(function (j) {
        if (!j.ok) { window.avisar('Error', j.mensaje, 'critico'); return; }

        opciones = j.datos.opciones || [];
        tasaBp   = j.datos.tasa_bp || 0;

        var btnInt = document.getElementById('btn-mod-interes');
        if (btnInt) {
          btnInt.textContent = 'Con interés · ' + (tasaBp / 100).toFixed(2) + '%';
        }

        pintarOpciones();
        caja.classList.remove('d-none');
      });
    });
  }

  var formDif = document.getElementById('form-diferir');
  if (formDif) {
    formDif.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var sel2 = formDif.querySelector('input[name="plazo_sel"]:checked');
      if (!sel2) { window.avisar('Atención', 'Seleccione un plazo.', 'alerta'); return; }

      post(RUTA + '/diferir', {
        movimiento: document.getElementById('sel-movimiento').value,
        cuotas: sel2.value,
        modalidad: modalidad
      }).then(function (j) {
        window.avisar(j.ok ? 'Compra diferida' : 'Error', j.mensaje, j.ok ? 'exito' : 'critico');
        if (j.ok) setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1400);
      });
    });
  }

  // ---------- Adelanto de efectivo ----------
  var formAd = document.getElementById('form-adelanto');

  if (formAd) {
    var adMonto  = document.getElementById('adel-monto');
    var adRes    = document.getElementById('adel-resumen');
    var adAviso  = document.getElementById('adel-aviso');
    var btnAd    = document.getElementById('btn-adelanto');
    var adMaximo = document.getElementById('adel-maximo');
    var URL_SIM  = <?= P::js($rutas->url('adelanto/simular')) ?>;
    var URL_ADEL = <?= P::js($rutas->url('adelanto')) ?>;
    var esperaAd;

    function avisoAd(texto, clase) {
      if (!texto) { adAviso.className = 'd-none'; adAviso.textContent = ''; return; }
      adAviso.className = 'alert alert-' + clase;
      adAviso.textContent = texto;
    }

    /* Se recalcula mientras teclea.
       Enseñar la comisión solo al confirmar es enseñarla tarde: el
       cliente ya decidió y la descubre cuando le llega el cargo. */
    adMonto.addEventListener('input', function () {
      clearTimeout(esperaAd);
      btnAd.disabled = true;
      adRes.classList.add('d-none');
      avisoAd('', '');

      if (adMonto.value.trim() === '') { return; }

      esperaAd = setTimeout(function () {
        post(URL_SIM, {
          tarjeta: formAd.querySelector('[name=tarjeta]').value,
          monto: adMonto.value
        }).then(function (j) {
          if (!j.ok) { avisoAd(j.mensaje, 'warning'); return; }

          var d = j.datos;
          document.getElementById('adel-recibe').textContent = d.recibe.formateado;
          document.getElementById('adel-cargos').textContent =
            d.comision.formateado + ' + ' + d.gasto.formateado;
          document.getElementById('adel-total').textContent = d.se_le_debe.formateado;
          adRes.classList.remove('d-none');

          if (d.maximo) {
            adMaximo.textContent = 'Lo más que puede recibir hoy: ' + d.maximo.formateado;
          }

          if (d.alcanza) {
            btnAd.disabled = false;
          } else {
            avisoAd('Su cupo no alcanza para este adelanto y sus cargos. '
                  + 'Disponible: ' + d.disponible.formateado + '.', 'warning');
          }
        });
      }, 400);
    });

    formAd.addEventListener('submit', function (ev) {
      ev.preventDefault();
      btnAd.disabled = true;

      post(URL_ADEL, {
        tarjeta: formAd.querySelector('[name=tarjeta]').value,
        cuenta:  document.getElementById('adel-cuenta').value,
        monto:   adMonto.value
      }).then(function (j) {
        window.avisar(j.ok ? 'Adelanto acreditado' : 'No se pudo', j.mensaje,
                      j.ok ? 'exito' : 'critico');
        if (j.ok) { setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1400); }
        else { btnAd.disabled = false; }
      });
    });

    // El máximo, nada más abrir: antes de teclear ya sabe su techo.
    post(URL_SIM, {
      tarjeta: formAd.querySelector('[name=tarjeta]').value, monto: '1.00'
    }).then(function (j) {
      if (j.ok && j.datos.maximo) {
        adMaximo.textContent = 'Lo más que puede recibir hoy: ' + j.datos.maximo.formateado;
      }
    });
  }

  // ---------- Traslado de saldo ----------
  var btnCodTr = document.getElementById('btn-cod-traslado');
  var formTr   = document.getElementById('form-traslado');

  if (btnCodTr && formTr) {
    btnCodTr.addEventListener('click', function () {
      var fd = new FormData(formTr);
      btnCodTr.disabled = true; btnCodTr.textContent = 'Enviando…';

      post(<?= P::js($rutas->url('traslado/codigo')) ?>, {
        origen: fd.get('origen'), destino: fd.get('destino'), monto: fd.get('monto')
      }).then(function (j) {
        btnCodTr.disabled = false; btnCodTr.textContent = 'Reenviar código';
        if (j.ok) {
          document.getElementById('otp-traslado').classList.remove('d-none');
          document.getElementById('btn-traslado').classList.remove('d-none');
          window.avisar('Código enviado', j.mensaje, 'exito');
        } else { window.avisar('Error', j.mensaje, 'critico'); }
      });
    });

    formTr.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var fd = new FormData(formTr);
      post(<?= P::js($rutas->url('traslado')) ?>, {
        origen: fd.get('origen'), destino: fd.get('destino'),
        monto: fd.get('monto'), cuotas: fd.get('cuotas'), codigo: fd.get('codigo')
      }).then(function (j) {
        window.avisar(j.ok ? 'Traslado realizado' : 'Error', j.mensaje, j.ok ? 'exito' : 'critico');
        if (j.ok) setTimeout(function () { (window.CMVista ? window.CMVista.refrescar() : location.reload()); }, 1600);
      });
    });
  }
})();
</script>

<?php /* El filtro de los movimientos lo resuelve ahora el
         servidor: aqui habia un guion que escondia filas de
         las sesenta ya traidas, y por eso filtrar por un mes
         que hubiera quedado fuera respondia que no hubo
         movimientos. Lo sustituye la consulta, que si los
         encuentra. */ ?>

