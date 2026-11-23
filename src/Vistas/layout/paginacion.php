<?php
/**
 * Controles de paginación.
 *
 * Se incluye desde cualquier listado:
 *
 *     <?php $pag = $paginacion; $enlace = fn(int $p) => $rutas->url('cuotas') . '?p=' . $p;
 *           include __DIR__ . '/../layout/paginacion.php'; ?>
 *
 * Imprime el resumen a la izquierda ("Mostrando 26–50 de 290"), el
 * selector de tamaño y los botones a la derecha. Si el listado cabe
 * entero en una página no dibuja nada, para no dejar una barra vacía
 * debajo de una tabla de cuatro filas.
 *
 * @var array    $pag     Lo que devuelve Paginador::aArreglo()
 * @var callable $enlace  fn(int $pagina): string — URL de esa página
 * @var callable|null $enlaceTamano fn(int $porPagina): string
 */
use CashMan\Http\Plantilla as P;

if (!isset($pag) || !is_array($pag) || (int) ($pag['total'] ?? 0) === 0) {
    return;
}

/** @var callable $enlace */
$enlaceTamano = $enlaceTamano ?? null;
?>

<div class="cm-paginacion">
  <div class="cm-paginacion__resumen">
    Mostrando
    <strong><?= number_format((float) $pag['desde']) ?></strong>–<strong><?= number_format((float) $pag['hasta']) ?></strong>
    de <strong><?= number_format((float) $pag['total']) ?></strong>
    <?= (int) $pag['total'] === 1 ? 'registro' : 'registros' ?>

    <?php if ($enlaceTamano !== null && (int) $pag['total'] > $pag['tamanos'][0]): ?>
    <span class="cm-paginacion__tamano">
      <label class="mb-0" for="cm-pp">Por página</label>
      <!-- Sin `onchange=` en el marcado: la politica de seguridad no
           ejecuta manejadores en linea. Lo engancha cm.js por el id. -->
      <select id="cm-pp" class="form-control form-control-sm" data-cm-ir>
        <?php foreach ($pag['tamanos'] as $t): ?>
        <option value="<?= P::attr($enlaceTamano($t)) ?>"
                <?= (int) $pag['porPagina'] === (int) $t ? 'selected' : '' ?>><?= (int) $t ?></option>
        <?php endforeach; ?>
      </select>
    </span>
    <?php endif; ?>
  </div>

  <?php if ((int) $pag['paginas'] > 1): ?>
  <nav aria-label="Paginación del listado">
    <ul class="pagination pagination-sm mb-0 cm-paginas">
      <li class="page-item <?= $pag['anterior'] === null ? 'disabled' : '' ?>">
        <?php if ($pag['anterior'] === null): ?>
          <span class="page-link" aria-hidden="true">&lsaquo;</span>
        <?php else: ?>
          <a class="page-link" href="<?= P::attr($enlace(1)) ?>" title="Primera página">&laquo;</a>
        <?php endif; ?>
      </li>

      <?php if ($pag['anterior'] !== null): ?>
      <li class="page-item">
        <a class="page-link" href="<?= P::attr($enlace((int) $pag['anterior'])) ?>"
           rel="prev" title="Página anterior">&lsaquo;</a>
      </li>
      <?php endif; ?>

      <?php foreach ($pag['ventana'] as $n): ?>
        <?php if ($n === null): ?>
        <li class="page-item disabled"><span class="page-link cm-paginas__hueco">&hellip;</span></li>
        <?php elseif ((int) $n === (int) $pag['pagina']): ?>
        <li class="page-item active" aria-current="page">
          <span class="page-link"><?= (int) $n ?></span>
        </li>
        <?php else: ?>
        <li class="page-item">
          <a class="page-link" href="<?= P::attr($enlace((int) $n)) ?>"><?= (int) $n ?></a>
        </li>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php if ($pag['siguiente'] !== null): ?>
      <li class="page-item">
        <a class="page-link" href="<?= P::attr($enlace((int) $pag['siguiente'])) ?>"
           rel="next" title="Página siguiente">&rsaquo;</a>
      </li>
      <li class="page-item">
        <a class="page-link" href="<?= P::attr($enlace((int) $pag['paginas'])) ?>"
           title="Última página">&raquo;</a>
      </li>
      <?php else: ?>
      <li class="page-item disabled"><span class="page-link" aria-hidden="true">&rsaquo;</span></li>
      <?php endif; ?>
    </ul>
  </nav>
  <?php endif; ?>
</div>
