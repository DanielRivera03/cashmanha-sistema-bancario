<?php
/**
 * Catálogo de tarjetas de crédito.
 *
 * Se le dice al cliente, de entrada, a cuáles puede optar con el ingreso
 * que tiene declarado. Dejarle solicitar una que el sistema va a
 * rechazar después es hacerle perder el tiempo y quedar mal.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array      $tarjetas
 * @var int        $ingreso
 * @var int        $compromisos
 * @var int        $capacidad
 * @var array|null $enTramite
 * @var string     $csrf
 */
use CashMan\Http\Plantilla as P;

$etapas = [
    'capturada'         => ['Recibida',            'light',   10],
    'revision_atencion' => ['En revisión',         'info',    35],
    'revision_gerencia' => ['En gerencia',         'primary', 65],
    'aprobada'          => ['Aprobada',            'success', 100],
    'rechazada'         => ['No aprobada',         'danger',  100],
    'emitida'           => ['Tarjeta emitida',     'success', 100],
];
?>

<!-- ---------------- Solicitud en trámite ---------------- -->
<?php if ($enTramite !== null):
  [$texto, $color, $avance] = $etapas[(string) $enTramite['etapa']] ?? ['En proceso', 'light', 50];
?>
<div class="card">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start flex-wrap" style="gap:1rem">
      <div>
        <h5 class="mb-1" style="font-size:1rem">
          Su solicitud <span class="cm-mono"><?= P::e($enTramite['folio']) ?></span>
        </h5>
        <p class="text-muted mb-2" style="font-size:.86rem">
          <?= P::e($enTramite['producto']) ?> ·
          solicitada el <?= P::e(P::fecha((string) $enTramite['creado_en'], 'd/m/Y')) ?>
        </p>
      </div>
      <div class="text-right">
        <span class="badge badge-<?= $color ?>" style="font-size:.8rem"><?= P::e($texto) ?></span>
        <a class="d-block mt-2" style="font-size:.78rem"
           href="<?= P::attr($rutas->url('tarjetas/mis-solicitudes')) ?>">
          Ver todas mis solicitudes
        </a>
      </div>
    </div>

    <?php /* El recorrido con sus pasos y fechas, no una barra.

             Una barra a medias dice en qué punto va y nada más: no
             cuándo entró, ni cuánto lleva ahí. Para quien espera un
             trámite eso es lo que importa —no tanto el paso como que
             se está moviendo—, y una solicitud parada cuatro días se
             veía igual que una que avanzó ayer. */ ?>
    <?php
      $sol       = $enTramite;
      $historial = $historial_tramite ?? [];
      require __DIR__ . '/_recorrido.php';
    ?>

    <?php if ((string) $enTramite['etapa'] === 'aprobada' && $enTramite['limite_aprobado_cent'] !== null): ?>
    <p class="mb-0 mt-3" style="font-size:.88rem">
      <strong>¡Felicidades!</strong> Le aprobamos un límite de
      <strong><?= P::e(P::money((int) $enTramite['limite_aprobado_cent'])) ?></strong>.
      Puede pasar por su tarjeta a cualquiera de nuestras agencias.
    </p>
    <?php elseif ((string) $enTramite['etapa'] === 'rechazada'): ?>
    <p class="mb-0 mt-3 text-muted" style="font-size:.86rem">
      Lamentamos no haber podido aprobarla en esta ocasión. Con mucho gusto
      revisamos su caso en agencia y le proponemos otras opciones.
    </p>
    <?php else: ?>
    <p class="mb-0 mt-3 text-muted" style="font-size:.86rem">
      Su solicitud está en revisión. Le avisaremos en cada paso; no necesita
      hacer nada más por ahora.
    </p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ---------------- Capacidad del cliente ---------------- -->
<?php if ($ingreso > 0): ?>
<div class="alert alert-light border" style="font-size:.86rem">
  <strong>Su capacidad.</strong> Con el ingreso de
  <strong><?= P::e(P::money($ingreso)) ?></strong> que tenemos registrado
  <?php if ($compromisos > 0): ?>
    y <?= P::e(P::money($compromisos)) ?> de compromisos mensuales,
  <?php endif; ?>
  podríamos aprobarle un límite de hasta
  <strong><?= P::e(P::money($capacidad)) ?></strong>.
  Si su ingreso cambió, avísenos y lo actualizamos.
</div>
<?php else: ?>
<div class="alert alert-light border" style="font-size:.86rem">
  <strong>Nos falta un dato.</strong> No tenemos registrado su ingreso mensual,
  y lo necesitamos para decirle a qué tarjeta puede optar. Puede indicarlo al
  solicitar, o actualizarlo en su perfil.
</div>
<?php endif; ?>

<!-- ---------------- El catálogo ---------------- -->
<div class="row">
  <?php foreach ($tarjetas as $t): ?>
  <div class="col-xl-4 col-lg-6">
    <div class="card cm-tdc <?= $t['alcanza'] ? '' : 'cm-tdc--lejos' ?>">
      <div class="card-body">

        <?php /* El diseno de la linea, a la vista.
                 Elegir tarjeta leyendo solo tasas y topes deja fuera la
                 mitad de la decision: el plastico es lo que el cliente
                 va a sacar en una caja. */ ?>
        <div class="mb-3">
          <?= P::crudo(cm_plastico([
                'ultimos4' => '••••',
                /* La marca con la que se emite ESTA línea, no un «visa»
                   fijo: la Signature sale en American Express y la
                   Platinum en Mastercard, y el plástico de muestra
                   tiene que ser el que el cliente va a recibir. */
                'marca' => (string) ($t['marca_emision'] ?? 'visa'),
                'tipo' => 'credito',
                'estado' => 'activa',
                'titular' => 'NOMBRE DEL TITULAR',
                'expira_mes' => (int) date('n'),
                'expira_anio' => (int) date('Y') + 4,
                'contactless' => 1,
                'producto_codigo' => $t['codigo'] ?? '',
                'producto_nivel'  => (int) ($t['nivel'] ?? 1),
              ], [
                'assets' => $rutas->url('assets/plantilla/images'),
                'numero' => '•••• •••• •••• ••••',
              ])) ?>
        </div>


        <div class="cm-tdc__cabecera">
          <div>
            <h4 class="card-title mb-0" style="font-size:1.05rem"><?= P::e($t['nombre']) ?></h4>
            <small class="text-muted"><?= P::e($t['tasa']) ?> anual</small>
          </div>
          <?php /* El sello decía «Puede optar» o no decía nada, y la
                   ausencia de sello se confunde con que no se haya
                   evaluado.

                   Y cuando decía «Requisitos pendientes» mentía a
                   medias: con una solicitud en trámite TODAS las
                   tarjetas salían así, aunque al cliente no le faltara
                   ningún requisito. Lo que le faltaba era esperar. */ ?>
          <?php
            $bloqueo = array_values(array_filter(
                $t['elegibilidad']['requisitos'],
                static fn(array $r): bool => !$r['cumple']
            ))[0]['clave'] ?? null;
          ?>
          <?php if ($t['elegibilidad']['puede']): ?>
          <span class="badge badge-success">Puede optar</span>
          <?php elseif ($bloqueo === 'tramite'): ?>
          <span class="badge badge-info">Solicitud en trámite</span>
          <?php elseif ($bloqueo === 'ingreso'): ?>
          <span class="badge badge-warning">Pide más ingreso</span>
          <?php elseif ($bloqueo === 'mora'): ?>
          <span class="badge badge-danger">Tiene cuotas en mora</span>
          <?php else: ?>
          <span class="badge badge-warning">Requisitos pendientes</span>
          <?php endif; ?>
        </div>

        <p class="text-muted" style="font-size:.86rem"><?= P::e($t['descripcion']) ?></p>

        <div class="cm-tdc__datos">
          <div>
            <small>Ingreso requerido</small>
            <b><?= (int) $t['ingreso_minimo_cent'] === 0
                 ? 'Sin requisito'
                 : P::e(P::money((int) $t['ingreso_minimo_cent'])) ?></b>
            <?php /* El suyo al lado: el requisito solo significa algo
                     comparado con lo que uno tiene. */ ?>
            <?php if ((int) $t['ingreso_minimo_cent'] > 0 && $ingreso > 0): ?>
            <i class="cm-tdc__suyo <?= $ingreso >= (int) $t['ingreso_minimo_cent'] ? 'is-ok' : 'is-falta' ?>">
              el suyo: <?= P::e(P::money((int) $ingreso)) ?>
            </i>
            <?php endif; ?>
          </div>
          <div>
            <small>Línea desde</small>
            <b><?= P::e(P::money((int) $t['linea_desde_cent'])) ?></b>
          </div>
          <div>
            <small>Membresía anual</small>
            <b><?= (int) $t['membresia_anual_cent'] === 0
                 ? 'Sin membresía'
                 : P::e(P::money((int) $t['membresia_anual_cent'])) ?></b>
          </div>
        </div>

        <ul class="cm-tdc__beneficios">
          <?php foreach ($t['lista'] as $b): ?>
          <li><?= cm_icono('check', 14) ?> <span><?= P::e($b) ?></span></li>
          <?php endforeach; ?>
        </ul>

        <?php /* ---------------------------------------------------------
             El motivo REAL, no uno supuesto.

             Antes, cualquier impedimento se anunciaba como «requiere
             mayor ingreso»: quien tenía ingreso de sobra y una cuota
             en mora iba a revisar su perfil y lo encontraba correcto.
             Y a quien no tenía ingreso registrado se le ofrecía el
             formulario entero, porque la condición pedía `$ingreso > 0`
             y al no cumplirse caía en la rama del «sí».

             `elegibilidad` evalúa las seis condiciones y dice cuál
             falla. Es la misma evaluación que hará el servidor al
             recibir la solicitud, así que la pantalla y la decisión no
             pueden discrepar.
             --------------------------------------------------------- */ ?>
        <?php $el = $t['elegibilidad']; ?>

        <?php if ($enTramite !== null
                  && !in_array((string) $enTramite['etapa'], ['aprobada','rechazada','emitida'], true)): ?>
          <button class="btn btn-outline-secondary btn-block" disabled>
            Ya tiene una solicitud en trámite
          </button>
        <?php elseif (!$el['puede']): ?>
          <div class="cm-requisitos">
            <button type="button" class="cm-requisitos__cab" data-cm-desplegar>
              <span class="cm-requisitos__sello"><?= cm_icono('lock', 14) ?></span>
              <span class="cm-requisitos__texto">
                <strong><?= $bloqueo === 'tramite'
                    ? 'Mientras se resuelve la que tiene en curso'
                    : 'Todavía no puede pedirla' ?></strong>
                <small><?= P::e((string) $el['bloqueo']) ?></small>
              </span>
              <span class="cm-requisitos__flecha" aria-hidden="true"></span>
            </button>

            <ul class="cm-requisitos__lista" hidden>
              <?php foreach ($el['requisitos'] as $r): ?>
              <li class="<?= $r['cumple'] ? 'is-ok' : 'is-falta' ?>">
                <span class="cm-requisitos__marca" aria-hidden="true"></span>
                <span>
                  <strong><?= P::e($r['etiqueta']) ?></strong>
                  <small><?= P::e($r['detalle']) ?></small>
                </span>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php else: ?>
          <form method="post" data-cm-ajax
                action="<?= P::attr($rutas->url('tarjetas/solicitar')) ?>">
            <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
            <input type="hidden" name="_csrf_proposito" value="solicitud_tarjeta">
            <input type="hidden" name="producto" value="<?= P::attr($t['codigo_h']) ?>">

            <div class="form-row">
              <?php /* La red no se elige cuando la línea la tiene
                       contratada. Ofrecer un desplegable que el sistema
                       va a ignorar es prometer una elección que no
                       existe; se dice cuál es y por qué. */ ?>
              <?php if (($t['marca_emision'] ?? null) === null): ?>
              <div class="form-group col-6">
                <label class="small mb-1">Marca</label>
                <select class="form-control form-control-sm" name="marca">
                  <option value="visa">Visa</option>
                  <option value="mastercard">Mastercard</option>
                  <option value="amex">American Express</option>
                </select>
              </div>
              <?php else: ?>
              <div class="form-group col-6">
                <label class="small mb-1">Marca</label>
                <div class="form-control form-control-sm bg-light d-flex align-items-center"
                     style="font-size:.84rem">
                  <?= P::e(match ((string) $t['marca_emision']) {
                        'mastercard' => 'Mastercard',
                        'amex'       => 'American Express',
                        default      => 'Visa',
                      }) ?>
                </div>
                <small class="text-muted" style="font-size:.7rem">
                  Esta línea se emite en esa red.
                </small>
              </div>
              <?php endif; ?>

              <div class="form-group col-6">
                <label class="small mb-1">Límite deseado</label>
                <div class="input-group input-group-sm">
                  <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                  <input type="text" class="form-control" name="limite" inputmode="decimal"
                         value="<?= number_format((int) $t['linea_desde_cent'] / 100, 2, '.', '') ?>">
                </div>
              </div>
            </div>

            <?php if ($ingreso === 0): ?>
            <div class="form-group">
              <label class="small mb-1">Su ingreso mensual</label>
              <div class="input-group input-group-sm">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" name="ingreso" inputmode="decimal"
                       placeholder="0.00" required>
              </div>
            </div>
            <?php endif; ?>

            <button class="btn btn-primary btn-block"
                    data-cm-confirmar="Enviaremos su solicitud de la <?= P::attr($t['nombre']) ?> a revisión. ¿Desea continuar?">
              Solicitar esta tarjeta
            </button>
          </form>
        <?php endif; ?>

      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="alert alert-light border" style="font-size:.82rem">
  <strong>Cómo sigue.</strong> Su solicitud pasa por atención al cliente y
  después por gerencia. El límite que se apruebe será el menor entre el que
  pidió y el que admite su capacidad de pago, para que la cuota le quede
  cómoda. Le avisamos en cada paso.
</div>
