<?php
/**
 * La verificación pública de una constancia.
 *
 * Esta es la ÚNICA pantalla del sistema que no pide sesión: la usa
 * quien recibe el documento —una embajada, un patrono, otro banco— y
 * ésos no tienen cuenta aquí ni tienen por qué tenerla.
 *
 * @var \CashMan\Http\Enrutador $rutas
 * @var array|null $resultado
 * @var string $codigo
 * @var string $csrf
 */

use CashMan\Http\Plantilla as P;
?>
<section class="py-5">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-8">

        <div class="text-center mb-4">
          <h1 style="font-size:1.8rem">Verificar una constancia</h1>
          <p class="text-muted">
            Si tiene en la mano un documento emitido por CashMan H.A., teclee aquí su
            código de verificación. Verá el mismo texto que dice el papel y si sigue
            vigente.
          </p>
        </div>

        <div class="card">
          <div class="card-body">
            <form method="post" action="<?= P::attr($rutas->url('verificar-constancia')) ?>">
              <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
              <input type="hidden" name="_csrf_proposito" value="verificar">

              <label for="codigo">Código de verificación</label>
              <div class="input-group input-group-lg">
                <input type="text" class="form-control cm-mono text-center"
                       id="codigo" name="codigo" maxlength="20"
                       style="letter-spacing:.2em; text-transform:uppercase"
                       value="<?= P::attr($codigo) ?>"
                       autocomplete="off" spellcheck="false" autofocus required>
                <div class="input-group-append">
                  <button class="btn btn-primary" type="submit">Verificar</button>
                </div>
              </div>
              <small class="form-text text-muted">
                Doce caracteres, impresos al pie del documento. Puede escribirlos con o
                sin espacios.
              </small>
            </form>
          </div>
        </div>

        <?php if ($codigo !== ''): ?>

          <?php if ($resultado === null): ?>
          <!-- ----------------------------------------------------------
               Que no exista es un resultado, y hay que decirlo con
               claridad: quien verifica necesita saber si le dieron un
               documento falso.
               ---------------------------------------------------------- -->
          <div class="card border-danger mt-4">
            <div class="card-body text-center">
              <h3 class="text-danger" style="font-size:1.3rem">
                No encontramos ninguna constancia con ese código
              </h3>
              <p class="mb-0 text-muted">
                Revise que lo haya tecleado completo y sin errores. Si el código está
                bien copiado y aun así no aparece,
                <strong>este documento no fue emitido por CashMan H.A.</strong>
              </p>
            </div>
          </div>

          <?php else: ?>

          <div class="card mt-4 <?= $resultado['anulada'] ? 'border-danger'
                                   : ($resultado['vencida'] ? 'border-warning' : 'border-success') ?>">
            <div class="card-body">

              <?php if ($resultado['anulada']): ?>
                <div class="alert alert-danger">
                  <strong>Esta constancia fue ANULADA.</strong>
                  <?= P::e($resultado['motivo_anulacion']) ?>
                  <br>No debe aceptarse como válida.
                </div>
              <?php elseif ($resultado['vencida']): ?>
                <div class="alert alert-warning">
                  <strong>Documento auténtico, pero VENCIDO.</strong>
                  Su vigencia terminó el
                  <?= P::e(date('d/m/Y', strtotime((string) $resultado['vigente_hasta']))) ?>.
                  Los datos que certifica son de esa fecha y pueden haber cambiado.
                </div>
              <?php else: ?>
                <div class="alert alert-success">
                  <strong>Documento auténtico y vigente</strong>, emitido por CashMan H.A.
                </div>
              <?php endif; ?>

              <div class="row">
                <div class="col-md-6">
                  <small class="text-muted d-block">Tipo de documento</small>
                  <strong><?= P::e($resultado['tipo']) ?></strong>
                </div>
                <div class="col-md-6">
                  <small class="text-muted d-block">Folio</small>
                  <strong class="cm-mono"><?= P::e($resultado['folio']) ?></strong>
                </div>
              </div>

              <div class="row mt-3">
                <div class="col-md-6">
                  <small class="text-muted d-block">A nombre de</small>
                  <strong><?= P::e($resultado['cliente']) ?></strong>
                  <br><small class="text-muted cm-mono"><?= P::e($resultado['documento']) ?></small>
                </div>
                <div class="col-md-6">
                  <small class="text-muted d-block">Emitida el</small>
                  <strong>
                    <?= P::e(date('d/m/Y H:i', strtotime((string) $resultado['emitida_en']))) ?>
                  </strong>
                  <br><small class="text-muted">
                    vigente hasta el
                    <?= P::e(date('d/m/Y', strtotime((string) $resultado['vigente_hasta']))) ?>
                  </small>
                </div>
              </div>

              <?php if ($resultado['dirigida_a'] !== ''): ?>
              <div class="mt-3">
                <small class="text-muted d-block">Dirigida a</small>
                <strong><?= P::e($resultado['dirigida_a']) ?></strong>
              </div>
              <?php endif; ?>

              <hr>

              <small class="text-muted d-block mb-2">Texto del documento</small>
              <div class="p-3 bg-light rounded" style="white-space:pre-wrap; font-size:.92rem">
<?= P::e($resultado['texto']) ?>
              </div>

              <p class="text-muted mt-3 mb-0" style="font-size:.82rem">
                Esta constancia se ha verificado
                <?= (int) $resultado['veces_verificada'] ?> vez(ces).
                Si el texto de arriba no coincide con el del papel que tiene en la mano,
                el papel fue alterado.
              </p>

            </div>
          </div>

          <?php endif; ?>
        <?php endif; ?>

        <p class="text-center text-muted mt-4" style="font-size:.84rem">
          Esta página solo confirma documentos a partir de su código. No permite
          consultar clientes ni saldos.
        </p>

      </div>
    </div>
  </div>
</section>
