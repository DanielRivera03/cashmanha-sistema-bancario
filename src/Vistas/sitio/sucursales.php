<?php
/**
 * Sucursales, cajeros y canales de atención.
 *
 * Las agencias salen ya de la tabla `sucursales`. Antes estaban
 * escritas en este archivo, con una nota explicando que era así porque
 * el sistema no tenía dónde guardarlas; ahora lo tiene, y de paso cada
 * agencia dice cuántas ventanillas atienden en ella.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var list<array<string,mixed>> $agencias
 */
use CashMan\Http\Plantilla as P;

require __DIR__ . '/_graficos.php';

$agencias = $agencias ?? [];
$conAtm   = 0;

foreach ($agencias as $a) { if ((int) $a['tiene_atm'] === 1) { $conAtm++; } }

/** El número en palabras: «Tenemos 10 agencias» se lee a tropezones. */
$enLetra = static function (int $n): string {
    return [
        1 => 'una', 2 => 'dos', 3 => 'tres', 4 => 'cuatro', 5 => 'cinco',
        6 => 'seis', 7 => 'siete', 8 => 'ocho', 9 => 'nueve', 10 => 'diez',
        11 => 'once', 12 => 'doce',
    ][$n] ?? (string) $n;
};
?>

<section class="sitio-hero">
  <?= P::crudo($escenario('cafetal')) ?>

  <div class="sitio-ancho">
    <span class="sitio-etiqueta"><?= P::crudo($ico($D['mapa'], 13)) ?> Visítenos cuando guste</span>
    <h1>Lo esperamos<br>en cualquiera</h1>
    <p class="sitio-entrada">
      Tenemos <?= P::e($enLetra(count($agencias))) ?> agencias<?= $conAtm > 0 ? ', ' . P::e($enLetra($conAtm)) . ' de ellas con cajero automático' : '' ?>
      esperándolo. Y si prefiere no salir de casa, casi todo lo puede
      resolver desde su banca en línea.
    </p>
    <div class="sitio-acciones">
      <a class="sitio-btn sitio-btn--primario" href="<?= P::attr($rutas->url('entrar')) ?>"
         target="_blank" rel="noopener">
        Banca en línea
      </a>
      <a class="sitio-btn sitio-btn--linea" href="tel:22220000">Llamar al 2222-0000</a>
    </div>
  </div>
</section>

<section class="sitio-seccion">
  <div class="sitio-ancho">
    <div class="sitio-titulo">
      <h2>Nuestras agencias</h2>
      <p>Con mucho gusto lo atendemos en la que mejor le quede. Tome en cuenta que el horario puede variar en días festivos.</p>
    </div>

    <div class="sitio-rejilla">
      <?php foreach ($agencias as $a): ?>
      <article class="sitio-tarjeta">
        <div class="sitio-tarjeta__icono"><?= P::crudo($ico($D['mapa'], 22)) ?></div>
        <h3>
          <?= P::e((string) $a['nombre']) ?>
          <?php if ((int) $a['es_central'] === 1): ?>
            <span class="sitio-pildora">Casa matriz</span>
          <?php endif; ?>
        </h3>
        <p>
          <?= P::e((string) $a['direccion']) ?><br>
          <?= P::e((string) $a['departamento']) ?>
        </p>

        <ul class="sitio-lista" style="margin-bottom:1rem">
          <li><?= P::crudo($ico($D['reloj'], 16)) ?><span><?= P::e((string) $a['horario']) ?></span></li>
          <?php if ((int) $a['ventanillas'] > 0): ?>
          <li><?= P::crudo($ico($D['escudo'], 16)) ?><span>
            <?= (int) $a['ventanillas'] ?> ventanilla<?= (int) $a['ventanillas'] === 1 ? '' : 's' ?> de atención
          </span></li>
          <?php endif; ?>
          <?php if ((int) $a['tiene_atm'] === 1): ?>
          <li><?= P::crudo($ico($D['tarjeta'], 16)) ?><span>Cajero automático 24 horas</span></li>
          <?php endif; ?>
          <?php if (!empty($a['telefono'])): ?>
          <li><?= P::crudo($ico($D['telefono'], 16)) ?><span>
            <a href="tel:<?= P::attr(str_replace('-', '', (string) $a['telefono'])) ?>">
              <?= P::e((string) $a['telefono']) ?></a>
          </span></li>
          <?php endif; ?>
        </ul>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sitio-seccion sitio-seccion--tenue">
  <div class="sitio-ancho">
    <div class="sitio-titulo">
      <h2>Otras formas de atenderle</h2>
    </div>

    <div class="sitio-rejilla">
      <article class="sitio-tarjeta">
        <div class="sitio-tarjeta__icono"><?= P::crudo($ico($D['telefono'], 22)) ?></div>
        <h3>Atención telefónica</h3>
        <p><b style="color:var(--tinta);font-size:1.1rem">2222-0000</b><br>
           Lunes a viernes de 8:00 a 17:00 y sábados de 8:00 a 12:00. Será un gusto atenderle.</p>
      </article>

      <article class="sitio-tarjeta">
        <div class="sitio-tarjeta__icono"><?= P::crudo($ico($D['escudo'], 22)) ?></div>
        <h3>Tarjeta extraviada o robada</h3>
        <p><b style="color:var(--tinta);font-size:1.1rem">2222-0001</b><br>
           Las 24 horas, todos los días del año. Llámenos sin pensarlo: siempre se puede reactivar.</p>
      </article>

      <article class="sitio-tarjeta">
        <div class="sitio-tarjeta__icono"><?= P::crudo($ico($D['movil'], 22)) ?></div>
        <h3>Banca en línea</h3>
        <p>Consulte saldos, transfiera, pague sus cuotas y descargue sus estados de cuenta a la hora que prefiera.</p>
        <div class="sitio-tarjeta__pie">
          <a href="<?= P::attr($rutas->url('entrar')) ?>">Entrar →</a>
        </div>
      </article>

      <article class="sitio-tarjeta">
        <div class="sitio-tarjeta__icono"><?= P::crudo($ico($D['correo'], 22)) ?></div>
        <h3>Por escrito</h3>
        <p>Si su consulta necesita más detalle, escríbanos con confianza y le respondemos por correo.</p>
        <div class="sitio-tarjeta__pie">
          <a href="<?= P::attr($rutas->url('contacto')) ?>">Formulario de contacto →</a>
        </div>
      </article>
    </div>
  </div>
</section>
