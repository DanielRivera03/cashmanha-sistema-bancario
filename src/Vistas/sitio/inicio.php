<?php
/**
 * Portada del sitio público.
 *
 * Las tasas, los nombres de producto y el número de agencias salen de
 * las tablas, no de un texto escrito a mano: si Gerencia cambia una
 * tasa o abre una agencia, el sitio no se queda diciendo otra cosa al
 * día siguiente.
 *
 * Sobre el tono: esto lo lee un cliente, no un auditor de sistemas. Las
 * garantías se cuentan por lo que significan para él —«nadie puede ver
 * su contraseña»— y no por cómo están construidas por dentro. Y se le
 * habla de usted, con la cortesía con que se le atiende en ventanilla.
 *
 * Sobre lo visual: todo lo que se ve —el plástico, el teléfono, el
 * escudo, las formas del fondo— es SVG dibujado en `_graficos.php`. No
 * hay una sola fotografía ni un recurso traído de fuera, lo que deja la
 * política de seguridad de contenido cerrada y la página ligera.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $productos  catálogo por tipo
 * @var array $cifras
 */
use CashMan\Http\Plantilla as P;

require __DIR__ . '/_graficos.php';

$familias = [
    'ahorro'    => ['Cuentas de ahorro', 'ahorro',
                    'Guarde su dinero con nosotros y vea cómo va creciendo cada mes.'],
    'corriente' => ['Cuenta corriente', 'corriente',
                    'Para el movimiento diario de su casa o de su negocio, sin límites.'],
    'credito'   => ['Créditos', 'credito',
                    'Con una cuota fija todos los meses, para que siempre sepa cuánto pagar.'],
    'tarjeta'   => ['Tarjetas de crédito', 'tarjeta',
                    'Compre hoy y páguelo después, aquí y también cuando viaje.'],
];

/* A qué acabado corresponde cada producto de tarjeta.
   El código del producto manda; lo que no esté en la lista sale con el
   acabado clásico, que es el que no desentona con nada. */
$acabados = [
    'TAR-CLA' => ['clasica',   'visa'],
    'TAR-ORO' => ['oro',       'mastercard'],
    'TAR-PLA' => ['platinum',  'visa'],
    'TAR-SIG' => ['signature', 'mastercard'],
    'TAR-INF' => ['signature', 'visa'],
    'TAR-OBS' => ['obsidiana', 'mastercard'],
    'TAR-CAS' => ['cashback',  'visa'],
    'TAR-INI' => ['inicia',    'visa'],
    'TAR-DEB' => ['debito',    'visa'],
    'TAR-EMP' => ['empresa',   'mastercard'],
];
?>

<!-- ====================== PORTADA ====================== -->
<section class="sitio-hero">
  <?= P::crudo($escenario('volcanes')) ?>

  <div class="sitio-hero__fondo" aria-hidden="true">
    <?= P::crudo($ornamento('anillos')) ?>
    <?= P::crudo($ornamento('puntos')) ?>
    <?= P::crudo($ornamento('mancha')) ?>
  </div>

  <div class="sitio-ancho sitio-hero__rejilla">
    <div>
      <span class="sitio-etiqueta">
        <?= P::crudo($ico($D['escudo'], 13)) ?> Le damos la bienvenida
      </span>

      <h1>Con mucho gusto,<br>le acompañamos</h1>

      <p class="sitio-entrada">
        Abra su cuenta, solicite su crédito o pague sus cuotas desde donde
        esté y a la hora que mejor le convenga. Y cuando prefiera que lo
        atendamos en persona, será un placer recibirlo en cualquiera de
        nuestras agencias.
      </p>

      <div class="sitio-acciones">
        <a class="sitio-btn sitio-btn--primario" href="<?= P::attr($rutas->url('registro')) ?>">
          <?= P::crudo($ico($D['rayo'], 15)) ?> Abrir mi cuenta en línea
        </a>
        <a class="sitio-btn sitio-btn--linea" href="<?= P::attr($rutas->url('entrar')) ?>">
          <?= P::crudo($ico($D['candado'], 15)) ?> Ya soy cliente
        </a>
      </div>

      <ul class="sitio-ventajas sitio-ventajas--claro">
        <li>Sin depósito de apertura y sin saldo mínimo</li>
        <li>Tarjeta de débito virtual el mismo día</li>
      </ul>
    </div>

    <div class="sitio-dupla__figura">
      <?= P::crudo($ilustra('tarjeta')) ?>
    </div>
  </div>

  <?= P::crudo($ornamento('onda')) ?>
</section>

<!-- ====================== FAMILIAS ====================== -->
<section class="sitio-seccion">
  <div class="sitio-ancho">
    <div class="sitio-titulo">
      <h2>¿En qué podemos servirle?</h2>
      <p>
        Estas son nuestras tasas de hoy. Si tiene dudas sobre cuál de
        nuestros productos le conviene más, con mucho gusto se lo
        explicamos con calma y sin ningún compromiso.
      </p>
    </div>

    <div class="sitio-rejilla">
      <?php foreach ($familias as $tipo => [$titulo, $icono, $texto]):
        $items = $productos[$tipo] ?? [];
        if ($items === []) { continue; }

        // Se enseña la más conveniente para quien lee: la más baja si es
        // un préstamo, la más alta si es un depósito.
        $tasas = array_map(static fn($p) => (int) $p['tasa_interes_bp'], $items);
        $mejor = in_array($tipo, ['credito', 'tarjeta'], true) ? min($tasas) : max($tasas);
      ?>
      <article class="sitio-producto sitio-producto__<?= P::attr($tipo) ?>">
        <div class="sitio-icocaja"><?= P::crudo($icoDuo($icono, 30)) ?></div>

        <h3><?= P::e($titulo) ?></h3>
        <p><?= P::e($texto) ?></p>

        <?php if ($mejor > 0): ?>
        <div class="sitio-dato">
          <?= P::e(number_format($mejor / 100, 2)) ?>%
          <small><?= in_array($tipo, ['credito', 'tarjeta'], true) ? 'desde' : 'hasta' ?></small>
        </div>
        <?php endif; ?>

        <ul class="sitio-lista" style="margin:.9rem 0 0">
          <?php foreach (array_slice($items, 0, 3) as $p): ?>
          <li><?= P::crudo($ico($D['check'], 15)) ?> <span><?= P::e($p['nombre']) ?></span></li>
          <?php endforeach; ?>
        </ul>

        <a class="sitio-producto__pie"
           href="<?= P::attr($rutas->url($tipo === 'corriente' ? 'empresas' : 'personas')) ?>">
          Me interesa, cuéntenme más <?= P::crudo($ico($D['flecha'], 15)) ?>
        </a>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ====================== GALERÍA DE TARJETAS ====================== -->
<?php $tarjetas = $productos['tarjeta'] ?? []; ?>
<?php if ($tarjetas !== []): ?>
<section class="sitio-seccion sitio-seccion--tenue">
  <div class="sitio-ancho">
    <div class="sitio-titulo">
      <h2>Una tarjeta para cada momento</h2>
      <p>
        Desde la primera, para quien empieza a construir su historial, hasta
        las de viaje. Todas con compras a cuotas sin intereses en los
        comercios afiliados.
      </p>
    </div>

    <?php /* Se desliza con el dedo en el teléfono y se reparte en el
             escritorio. En una fila de tarjetas que no se distinguen
             entre sí nadie elige: por eso cada una lleva su acabado. */ ?>
    <div class="sitio-galeria">
      <?php foreach ($tarjetas as $t):
        $codigo = (string) ($t['codigo'] ?? '');
        [$acabado, $marca] = $acabados[$codigo] ?? ['clasica', 'visa'];
        $linea = (int) ($t['linea_desde_cent'] ?? 0);
      ?>
      <a class="sitio-plastico sitio-plastico--<?= P::attr($acabado) ?>"
         href="<?= P::attr($rutas->url('personas')) ?>">
        <?= P::crudo($arteTarjeta($acabado, $marca)) ?>

        <div class="sitio-plastico__texto">
          <h3><?= P::e((string) $t['nombre']) ?></h3>
          <p>
            <?php if ($linea > 0): ?>
              Línea desde $<?= P::e(number_format($linea / 100)) ?>
            <?php else: ?>
              Consulte sus condiciones
            <?php endif; ?>
          </p>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ====================== CUENTA DIGITAL ====================== -->
<section class="sitio-seccion">
  <div class="sitio-ancho">
    <div class="sitio-dupla">
      <div>
        <span class="sitio-etiqueta sitio-etiqueta--marca">
          <?= P::crudo($ico($D['rayo'], 13)) ?> Cuenta Digital
        </span>

        <h2>Ábrala usted mismo, en un par de minutos</h2>

        <p>
          Sin ir a una agencia, sin depósito de apertura y sin papeles. Al
          terminar le emitimos su tarjeta de débito virtual, con la que puede
          comprar por internet ese mismo día, y le mandamos su contrato por
          correo.
        </p>

        <ul class="sitio-ventajas">
          <li>Sin monto de apertura ni saldo mínimo que mantener</li>
          <li>Tarjeta de débito virtual inmediata</li>
          <li>Su contrato por correo, sin papeles que firmar</li>
          <li>Si se le queda corta, en cualquier agencia la convertimos</li>
        </ul>

        <div class="sitio-acciones">
          <a class="sitio-btn sitio-btn--oro" href="<?= P::attr($rutas->url('registro')) ?>">
            Abrir mi cuenta <?= P::crudo($ico($D['flecha'], 15)) ?>
          </a>
        </div>
      </div>

      <div class="sitio-dupla__figura">
        <?= P::crudo($ilustra('movil')) ?>
      </div>
    </div>

    <div class="sitio-pasos">
      <div class="sitio-paso">
        <h3>Cree su usuario</h3>
        <p>Sus nombres, su documento y un correo. Nada más.</p>
      </div>
      <div class="sitio-paso">
        <h3>Elija su tarjeta</h3>
        <p>Con qué marca la quiere. Lo demás lo hacemos nosotros.</p>
      </div>
      <div class="sitio-paso">
        <h3>Empiece a usarla</h3>
        <p>Cuenta abierta, tarjeta emitida y contrato en su correo.</p>
      </div>
    </div>
  </div>
</section>

<!-- ====================== SEGURIDAD ====================== -->
<section class="sitio-seccion sitio-seccion--tenue">
  <div class="sitio-ancho">
    <div class="sitio-dupla sitio-dupla--invertida">
      <div>
        <span class="sitio-etiqueta sitio-etiqueta--marca">
          <?= P::crudo($ico($D['escudo'], 13)) ?> Su dinero, protegido
        </span>

        <h2>Su dinero está en buenas manos</h2>

        <p>
          Usted no tiene por qué preocuparse de cómo funciona todo esto por
          dentro; de eso nos encargamos nosotros. Esto es lo que hacemos para
          que pueda estar tranquilo.
        </p>

        <ul class="sitio-lista">
          <li>
            <?= P::crudo($ico($D['candado'], 18)) ?>
            <span><b>Su contraseña es solamente suya.</b> Nadie más puede
            verla, ni siquiera quienes trabajamos aquí. Si alguna vez alguien
            se la pide por teléfono o por correo, le pedimos que no la
            comparta: no somos nosotros.</span>
          </li>
          <li>
            <?= P::crudo($ico($D['escudo'], 18)) ?>
            <span><b>Una segunda llave, si así lo prefiere.</b> Puede pedir
            que su cuenta le solicite además un código desde su teléfono.
            Así, aunque alguien llegara a conocer su contraseña, no podría
            entrar.</span>
          </li>
          <li>
            <?= P::crudo($ico($D['libro'], 18)) ?>
            <span><b>Cada movimiento queda registrado.</b> Toda operación
            deja constancia con su fecha y su hora. Si algo no le cuadra, con
            gusto lo revisamos junto con usted, al detalle y las veces que
            haga falta.</span>
          </li>
          <li>
            <?= P::crudo($ico($D['reloj'], 18)) ?>
            <span><b>Cerramos la sesión por usted.</b> Si deja su cuenta
            abierta y se distrae, la cerramos solos. Es una tranquilidad más
            cuando entra desde una computadora que no es la suya.</span>
          </li>
        </ul>
      </div>

      <div class="sitio-dupla__figura">
        <?= P::crudo($ilustra('seguridad')) ?>
      </div>
    </div>
  </div>
</section>

<!-- ====================== DESDE DONDE ESTÉ ====================== -->
<section class="sitio-seccion">
  <div class="sitio-ancho">
    <div class="sitio-titulo sitio-titulo--centro">
      <h2>Desde donde esté, a la hora que sea</h2>
      <p>Lo que antes era ir a una agencia, ahora cabe en su teléfono.</p>
    </div>

    <div class="sitio-rejilla">
      <article class="sitio-producto sitio-producto__tarjeta">
        <div class="sitio-icocaja"><?= P::crudo($icoDuo('movil', 30)) ?></div>
        <h3>Desde su teléfono</h3>
        <p>Consulte su saldo, transfiera y pague sus cuotas con toda
           comodidad. No necesita instalar nada.</p>
      </article>

      <article class="sitio-producto sitio-producto__ahorro">
        <div class="sitio-icocaja"><?= P::crudo($icoDuo('tarjeta', 30)) ?></div>
        <h3>Su tarjeta, bajo su control</h3>
        <p>Congélela si la extravió, fije su propio límite y vea cada
           compra en el momento en que ocurre.</p>
      </article>

      <article class="sitio-producto sitio-producto__credito">
        <div class="sitio-icocaja"><?= P::crudo($icoDuo('credito', 30)) ?></div>
        <h3>Su crédito, siempre claro</h3>
        <p>Su tabla de cuotas completa, lo que ya pagó y lo que le falta,
           sin tener que preguntar.</p>
      </article>

      <article class="sitio-producto sitio-producto__corriente">
        <div class="sitio-icocaja"><?= P::crudo($icoDuo('tienda', 30)) ?></div>
        <h3>Compre a cuotas</h3>
        <p>En los comercios afiliados, sin intereses, eligiendo el plazo
           desde su propia banca.</p>
      </article>
    </div>
  </div>
</section>

<!-- ====================== CIFRAS ====================== -->
<section class="sitio-seccion sitio-seccion--tenue">
  <div class="sitio-ancho">
    <div class="sitio-titulo sitio-titulo--centro">
      <h2>Gracias por su confianza</h2>
      <p>Es lo que nos ha traído hasta aquí, y lo cuidamos todos los días.</p>
    </div>

    <div class="sitio-cifras">
      <div class="sitio-cifra">
        <?= P::crudo($icoDuo('usuarios', 30)) ?>
        <b><?= P::e(number_format((float) $cifras['clientes'])) ?></b>
        <span>familias y negocios nos acompañan</span>
      </div>
      <div class="sitio-cifra">
        <?= P::crudo($icoDuo('ahorro', 30)) ?>
        <b><?= P::e(number_format((float) $cifras['cuentas'])) ?></b>
        <span>cuentas a su servicio</span>
      </div>
      <div class="sitio-cifra">
        <?= P::crudo($icoDuo('credito', 30)) ?>
        <b><?= P::e(number_format((float) $cifras['creditos'])) ?></b>
        <span>proyectos que ayudamos a financiar</span>
      </div>
      <div class="sitio-cifra">
        <?= P::crudo($icoDuo('mapa', 30)) ?>
        <b><?= P::e(number_format((float) ($cifras['sucursales'] ?? 0))) ?></b>
        <span>agencias listas para atenderle</span>
      </div>
    </div>
  </div>
</section>

<!-- ====================== CIERRE ====================== -->
<section class="sitio-seccion">
  <div class="sitio-ancho">
    <div class="sitio-franja">
      <?= P::crudo($ornamento('anillos')) ?>
      <?= P::crudo($ornamento('puntos')) ?>

      <h2>¿Empezamos?</h2>
      <p>
        Abra su cuenta en línea en un par de minutos, o escríbanos y con
        mucho gusto le ayudamos a elegir lo que mejor le convenga.
      </p>

      <div class="sitio-acciones sitio-acciones--centro">
        <a class="sitio-btn sitio-btn--primario" href="<?= P::attr($rutas->url('registro')) ?>">
          Abrir mi cuenta
        </a>
        <a class="sitio-btn sitio-btn--linea" href="<?= P::attr($rutas->url('contacto')) ?>">
          Hablar con alguien
        </a>
      </div>
    </div>
  </div>
</section>
