<?php
/**
 * La banca del cliente preferente.
 *
 * Al que ya está dentro se le enseña lo que tiene; al que no, lo que
 * le falta. Esconderle el escalón siguiente a un cliente estándar no
 * lo protege de nada: lo deja sin saber que existe, y con él la razón
 * para concentrar su relación aquí.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array  $seg
 * @var array  $cliente
 * @var array  $exclusivas
 * @var array  $resumen
 * @var array  $escalones
 * @var string $csrf
 */
use CashMan\Dominio\Cliente\Segmento;
use CashMan\Http\Plantilla as P;

/* El ingreso registrado del cliente, para contrastarlo con lo que
   pide cada línea. Si no viene, se trata como cero: no saberlo no
   es lo mismo que tenerlo, y la pantalla lo dice con esas palabras. */
$ingresoCliente = (int) ($ingreso_cliente ?? 0);

/* Su banquero, cuando su categoria lo incluye y ya se le asigno uno. */
$banquero = $banquero ?? null;

$esEstandar = $seg['segmento'] === Segmento::ESTANDAR;
$b = $seg['beneficios'];
?>

<?php /* La cabecera. Dorada para el patrimonial, morada para el
         preferente y sobria para el estándar: el color dice en qué
         escalón está antes de leer nada. */ ?>
<div class="cm-pref cm-pref--<?= P::attr((string) $seg['segmento']) ?>">
  <div class="cm-pref__cuerpo">
    <div class="cm-pref__sello"><?= P::e(mb_substr((string) $seg['etiqueta'], 0, 1)) ?></div>

    <div style="min-width:0;flex:1">
      <div class="cm-pref__et">
        <?= $esEstandar ? 'Su segmento actual' : 'Usted es cliente' ?>
      </div>
      <?php /* El color va en línea porque la hoja de la plantilla fija
               el de los encabezados y el de los párrafos por etiqueta,
               y eso gana a heredar del contenedor: el título salía en
               gris oscuro sobre el morado, casi ilegible. */ ?>
      <h3 class="cm-pref__titulo" style="color:inherit"><?= P::e((string) $seg['etiqueta']) ?></h3>
      <p class="cm-pref__motivo" style="color:inherit"><?= P::e((string) $seg['motivo']) ?></p>

      <?php if (!$esEstandar && !empty($cliente['segmento_desde'])): ?>
      <small class="cm-pref__desde">
        Desde el <?= P::e(P::fecha((string) $cliente['segmento_desde'], 'd/m/Y')) ?>
      </small>
      <?php endif; ?>
    </div>

    <div class="cm-pref__cifras">
      <div>
        <small>Con la institución</small>
        <strong><?= (int) $seg['meses_cliente'] ?> <span>mes(es)</span></strong>
      </div>
      <div>
        <small>Clasificación</small>
        <strong><?= P::e((string) $seg['banda']) ?> <span><?= (int) $seg['puntaje'] ?> pts</span></strong>
      </div>
      <div>
        <small>Productos</small>
        <strong>
          <?= (int) $resumen['cuentas'] + (int) $resumen['tarjetas'] + (int) $resumen['creditos'] ?>
          <span>activos</span>
        </strong>
      </div>
    </div>
  </div>
</div>

<?php if ($esEstandar): ?>

<?php /* ---------- El que todavía no está ---------- */ ?>
<div class="card mb-4">
  <div class="card-body">
    <h5 class="mb-2">Cómo se entra</h5>
    <p class="text-muted mb-3" style="font-size:.9rem;max-width:48rem">
      El segmento no lo asigna nadie a mano: sale de lo que usted tiene con nosotros,
      de su clasificación de riesgo y de su antigüedad. Se revisa solo, y en cuanto
      reúne las condiciones los beneficios se aplican sin que tenga que pedirlos.
    </p>

    <?php if (!empty($seg['falta'])): ?>
    <div class="cm-pref__falta">
      <strong>Le falta</strong>
      <ul>
        <?php foreach ($seg['falta'] as $f): ?>
        <li><?= P::e(ucfirst((string) $f)) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="row">
  <?php foreach ($escalones as $clave => $lista): ?>
  <div class="col-lg-6 mb-4">
    <div class="card h-100 mb-0 cm-pref-escalon cm-pref-escalon--<?= P::attr((string) $clave) ?>">
      <div class="card-header">
        <h5 class="card-title mb-0"><?= P::e(Segmento::etiqueta((string) $clave)) ?></h5>
      </div>
      <div class="card-body">
        <ul class="cm-pref__lista">
          <?php foreach ($lista as $x): ?>
          <li>
            <strong><?= P::e($x['titulo']) ?></strong>
            <span><?= P::e($x['detalle']) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php else: ?>

<?php /* ---------- El que ya está dentro ---------- */ ?>
<div class="row">
  <div class="col-lg-7 mb-4">
    <div class="card h-100 mb-0">
      <div class="card-header">
        <h4 class="card-title mb-0">Lo que le corresponde</h4>
        <small class="text-muted d-block mt-1" style="font-size:.78rem">
          Aplicado, no prometido: son los números con los que el sistema resuelve
          sus solicitudes.
        </small>
      </div>
      <div class="card-body">
        <ul class="cm-pref__lista">
          <?php foreach ($seg['visibles'] as $x): ?>
          <li>
            <?= cm_icono('check', 15) ?>
            <strong><?= P::e($x['titulo']) ?></strong>
            <span><?= P::e($x['detalle']) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>

  <div class="col-lg-5 mb-4">
    <?php /* Las acciones que solo tiene este segmento. */ ?>
    <div class="card mb-4">
      <div class="card-header">
        <h4 class="card-title mb-0">Sus gestiones</h4>
      </div>
      <div class="card-body">
        <div class="d-flex flex-column" style="gap:.5rem">
          <?php /* Las rutas son las que existen de verdad.
                   Aquí había «soporte/nuevo» y «mensajeria», que no son
                   rutas de este sistema: los botones llevaban a un 404.
                   Las de verdad son «tickets/nuevo» y «mensajes/nuevo». */ ?>
          <?php if (!empty($b['atencion_prioritaria'])): ?>
          <a class="btn btn-outline-primary btn-block text-left"
             href="<?= P::attr($rutas->url('tickets/nuevo') . '?prioridad=alta') ?>">
            <?= cm_icono('support', 15, 'mr-2') ?>
            Abrir una gestión con atención prioritaria
          </a>
          <?php endif; ?>

          <a class="btn btn-outline-primary btn-block text-left"
             href="<?= P::attr($rutas->url('solicitudes/nueva')) ?>">
            <?= cm_icono('invest', 15, 'mr-2') ?>
            Solicitar un crédito con su tasa preferente
          </a>

          <a class="btn btn-outline-primary btn-block text-left"
             href="<?= P::attr($rutas->url('tarjetas/catalogo')) ?>">
            <?= cm_icono('card', 15, 'mr-2') ?>
            Ver el catálogo de tarjetas
          </a>
        </div>

        <?php if (!empty($b['banquero_asignado'])): ?>
        <div class="cm-pref__banquero">
          <?php if (!empty($banquero)): ?>
          <?php /* Con nombre. «Tiene una persona asignada» sin decir
                   quién es lo mismo que no tener a nadie: la gracia del
                   puesto es que el cliente sepa a quién escribe. */ ?>
          <strong>Su banquero</strong>
          <div class="cm-banquero">
            <span class="cm-avatar">
              <?= P::e(mb_strtoupper(mb_substr((string) $banquero['nombre'], 0, 2))) ?>
            </span>
            <div>
              <b><?= P::e((string) $banquero['nombre']) ?></b>
              <?php if (!empty($banquero['desde'])): ?>
              <small>lleva su relación desde
                <?= P::e(P::fecha((string) $banquero['desde'], 'm/Y')) ?></small>
              <?php endif; ?>
            </div>
          </div>
          <p>
            Le responde directamente, sin pasar por la cola general. Lo que le
            escriba y lo que él le conteste quedan en el mismo hilo, y los dos
            ven lo mismo.
          </p>
          <a class="btn btn-sm btn-primary"
             href="<?= P::attr($rutas->url('mensajes/nuevo')) ?>">Escribirle</a>
          <a class="btn btn-sm btn-outline-primary"
             href="<?= P::attr($rutas->url('tickets/nuevo')) ?>">Abrir una gestión</a>
          <?php else: ?>
          <strong>Su banquero</strong>
          <p>
            Su categoría incluye una persona asignada. Estamos terminando de
            asignarle la suya; en cuanto esté, la verá aquí con su nombre.
          </p>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php /* El escalón siguiente, si lo hay. */ ?>
    <?php if ($seg['segmento'] === Segmento::PREFERENTE): ?>
    <div class="card mb-0">
      <div class="card-body">
        <h6 class="mb-2">El escalón siguiente</h6>
        <p class="text-muted mb-0" style="font-size:.85rem">
          <strong>Patrimonial</strong> añade banquero asignado, membresía sin costo,
          hasta 12 compras a cuotas a la vez y dos puntos y medio de descuento en la
          tasa. Se entra con $50,000 en cuentas, $30,000 de línea o $8,000 de ingreso
          mensual, manteniendo su clasificación.
        </p>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php /* ---------- Las líneas reservadas ---------- */ ?>
<?php if ($exclusivas !== []): ?>
<div class="card">
  <div class="card-header">
    <h4 class="card-title mb-0">Líneas reservadas para usted</h4>
    <small class="text-muted d-block mt-1" style="font-size:.78rem">
      No aparecen en el catálogo general. Puede solicitarlas desde aquí.
    </small>
  </div>
  <div class="card-body">
    <div class="row">
      <?php foreach ($exclusivas as $p): ?>
      <div class="col-lg-6 mb-4">
        <div class="cm-ficha">
          <?= P::crudo(cm_plastico([
                'ultimos4' => '••••',
                'marca' => (string) ($p['marca_emision'] ?? 'visa'),
                'tipo' => 'credito',
                'estado' => 'activa',
                'titular' => mb_strtoupper((string) ($cliente['nombre'] ?? 'TITULAR')),
                'expira_mes' => (int) date('n'),
                'expira_anio' => (int) date('Y') + 4,
                'contactless' => 1,
                'producto_codigo' => (string) $p['codigo'],
                'producto_nivel'  => (int) $p['nivel'],
              ], [
                'assets' => $rutas->url('assets/plantilla/images'),
                'numero' => '•••• •••• •••• ••••',
              ])) ?>
        </div>

        <h5 class="mt-3 mb-1"><?= P::e($p['nombre']) ?></h5>
        <p class="text-muted" style="font-size:.86rem"><?= P::e($p['descripcion']) ?></p>

        <ul class="cm-pref__beneficios">
          <?php foreach (array_filter(explode("\n", (string) $p['beneficios'])) as $linea): ?>
          <li><?= P::e(trim($linea)) ?></li>
          <?php endforeach; ?>
        </ul>

        <div class="d-flex justify-content-between align-items-end mb-3"
             style="font-size:.84rem">
          <span class="text-muted">
            Línea desde <strong><?= P::e(P::money((int) $p['linea_desde_cent'])) ?></strong>
          </span>
          <span class="text-muted">
            <?php if (!empty($b['membresia_descuento']) && $b['membresia_descuento'] >= 1.0): ?>
              Membresía <strong class="text-success">sin costo</strong>
            <?php else: ?>
              Membresía
              <strong><?= P::e(P::money((int) round(
                  (int) $p['membresia_anual_cent'] * (1 - (float) ($b['membresia_descuento'] ?? 0))
              ))) ?></strong> al año
            <?php endif; ?>
          </span>
        </div>

        <?php /* ---------------------------------------------------------
             Pedirla, o lo que falta para poder pedirla.

             Antes aquí había un botón entero para las tres líneas, y
             quien no cumplía se enteraba al pulsarlo, con un aviso
             rojo. El dato ya estaba: el requisito lo pone el producto y
             el ingreso consta en el expediente. Ahora el estado se ve
             ANTES, y cuando falta algo se dice qué y cuánto.
             --------------------------------------------------------- */ ?>
        <?php $el = $p['elegibilidad']; ?>

        <?php /* El requisito de ingreso, en TODAS: también en las que
                 sí puede pedir. Saber que una línea pide $10,000 y que
                 uno tiene $12,000 explica por qué está disponible; ver
                 solo un botón no explica nada, y cuando la de al lado
                 no lo tiene, tampoco explica la diferencia. */ ?>
        <?php if ((int) $p['ingreso_minimo_cent'] > 0): ?>
        <?php $cumpleIngreso = $ingresoCliente >= (int) $p['ingreso_minimo_cent']; ?>
        <div class="cm-ingreso <?= $cumpleIngreso ? 'cm-ingreso--ok' : 'cm-ingreso--falta' ?>">
          <span class="cm-ingreso__marca" aria-hidden="true"></span>
          <span>
            Requiere un ingreso mensual desde
            <strong><?= P::e(P::money((int) $p['ingreso_minimo_cent'])) ?></strong>.
            <?php if ($ingresoCliente > 0): ?>
              El suyo registrado es de <strong><?= P::e(P::money($ingresoCliente)) ?></strong><?php
                if (!$cumpleIngreso): ?>; faltan
                <strong><?= P::e(P::money((int) $p['ingreso_minimo_cent'] - $ingresoCliente)) ?></strong><?php
                endif; ?>.
            <?php else: ?>
              No tenemos su ingreso registrado:
              <a href="<?= P::attr($rutas->url('perfil')) ?>">indíquelo en su perfil</a>
              y volvemos a evaluarlo.
            <?php endif; ?>
          </span>
        </div>
        <?php endif; ?>

        <?php if ($el['puede']): ?>
        <form method="post" data-cm-ajax
              action="<?= P::attr($rutas->url('tarjetas/solicitar')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="solicitud_tarjeta">
          <input type="hidden" name="producto" value="<?= P::attr($p['codigo_h']) ?>">
          <input type="hidden" name="limite"
                 value="<?= number_format((int) $p['linea_desde_cent'] / 100, 2, '.', '') ?>">
          <button type="submit" class="btn btn-primary btn-block"
                  data-cm-confirmar="Se evaluará su solicitud con su clasificación actual. ¿Continuar?">
            Solicitar la <?= P::e(str_replace('Tarjeta ', '', (string) $p['nombre'])) ?>
          </button>
        </form>
        <?php else: ?>
        <div class="cm-requisitos">
          <button type="button" class="cm-requisitos__cab" data-cm-desplegar>
            <span class="cm-requisitos__sello">
              <?= cm_icono('lock', 14) ?>
            </span>
            <span class="cm-requisitos__texto">
              <strong>Aún no disponible para usted</strong>
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
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<p class="text-muted mt-3" style="font-size:.78rem;line-height:1.55;max-width:50rem">
  El segmento se revisa de forma automática con una regla escrita y versionada
  (v<?= P::e((string) $seg['version']) ?>): nadie lo asigna ni lo retira a mano. Si su
  situación cambia, el segmento cambia con ella y los beneficios se aplican o dejan de
  aplicarse desde ese momento.
</p>
