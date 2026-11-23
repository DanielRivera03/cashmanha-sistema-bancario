<?php

/**
 * El recorrido de una solicitud de tarjeta.
 *
 * Una barra de progreso a medias dice en qué punto va, y nada más. No
 * dice cuándo entró en ese punto, ni cuánto lleva ahí, ni qué pasó
 * antes. Para quien espera un trámite eso es justo lo que importa: no
 * tanto el paso como que SE ESTÁ MOVIENDO. Una solicitud parada cuatro
 * días y una que avanzó ayer se veían exactamente igual.
 *
 * Aquí se enseñan los cinco pasos, con el recorrido real debajo: lo que
 * ya ocurrió con su fecha, lo que está ocurriendo ahora, y lo que falta
 * en gris. Los pasos futuros se enseñan también —a propósito— porque
 * saber cuántos faltan es la mitad de la paciencia.
 *
 * Se usa desde el catálogo y desde «Mis solicitudes», así que vive
 * aparte: dos copias del mismo recorrido acabarían diciendo cosas
 * distintas.
 *
 * @var array                    $sol       La solicitud.
 * @var list<array<string,mixed>> $historial Los pasos ya dados.
 */

use CashMan\Http\Plantilla as P;

/* Los pasos del trámite normal, en orden. El rechazo no está aquí:
   no es un paso del camino, es que el camino se acabó. */
$PASOS = [
    'capturada'         => ['Recibida',    'Su solicitud entró en cola.'],
    'revision_atencion' => ['En revisión', 'Un ejecutivo revisa su expediente.'],
    'revision_gerencia' => ['En gerencia', 'Se aprueba el límite.'],
    'aprobada'          => ['Aprobada',    'Se emite su tarjeta.'],
    'emitida'           => ['Emitida',     'Ya es suya.'],
];

$etapa    = (string) ($sol['etapa'] ?? 'capturada');
$rechazada = $etapa === 'rechazada';

$orden    = array_keys($PASOS);
$actual   = array_search($etapa, $orden, true);
$actual   = $actual === false ? 0 : (int) $actual;

/* Cuándo pasó cada etapa, del historial. Si una etapa no está, es que
   no hay constancia de cuándo ocurrió, y no se inventa una fecha. */
$cuando = [];

foreach (($historial ?? []) as $h) {
    $cuando[(string) $h['etapa_nueva']] ??= (string) $h['creado_en'];
}
?>

<div class="cm-recorrido">
  <ol class="cm-recorrido__pasos">
    <?php foreach ($PASOS as $clave => [$titulo, $detalle]): ?>
    <?php
      $i = (int) array_search($clave, $orden, true);

      $estado = match (true) {
          $rechazada && $i > $actual => 'futuro',
          $i <  $actual              => 'hecho',
          $i === $actual             => ($etapa === 'emitida' ? 'hecho' : 'ahora'),
          default                    => 'futuro',
      };
    ?>
    <li class="cm-recorrido__paso is-<?= $estado ?>">
      <span class="cm-recorrido__punto" aria-hidden="true"></span>
      <span class="cm-recorrido__texto">
        <strong><?= P::e($titulo) ?></strong>
        <?php if (isset($cuando[$clave])): ?>
          <small><?= P::e(P::fecha($cuando[$clave], 'd/m/Y H:i')) ?></small>
        <?php elseif ($estado === 'futuro'): ?>
          <small><?= P::e($detalle) ?></small>
        <?php endif; ?>
      </span>
    </li>
    <?php endforeach; ?>
  </ol>

  <?php if ($rechazada): ?>
  <div class="cm-recorrido__alto">
    <strong>La solicitud no procedió.</strong>
    <?php if (!empty($sol['motivo_rechazo'])): ?>
      <span><?= P::e((string) $sol['motivo_rechazo']) ?></span>
    <?php endif; ?>
    <span>Puede volver a solicitarla cuando cambie su situación.</span>
  </div>
  <?php endif; ?>

  <?php /* El detalle, para quien quiera verlo. Plegado porque la
           mayoría de las veces basta con los cinco puntos de arriba. */ ?>
  <?php if (($historial ?? []) !== []): ?>
  <button type="button" class="cm-recorrido__ver" data-cm-desplegar>
    Ver el detalle del trámite
    <span class="cm-requisitos__flecha" aria-hidden="true"></span>
  </button>

  <ul class="cm-recorrido__detalle" hidden>
    <?php foreach (array_reverse($historial) as $h): ?>
    <li>
      <small><?= P::e(P::fecha((string) $h['creado_en'], 'd/m/Y H:i')) ?></small>
      <span><?= P::e((string) ($h['nota'] ?? '')) ?></span>
      <?php if (!empty($h['actor'])): ?>
        <i><?= P::e((string) $h['actor']) ?></i>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
