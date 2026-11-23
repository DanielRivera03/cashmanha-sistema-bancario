<?php
/** @var list<array{valor:string,etiqueta:string,nota:string,tipo:string}> $destinatarios */
/** @var bool $es_cliente */
/** @var array|null $responde */
use CashMan\Http\Plantilla as P;
?>
<div class="row justify-content-center">
  <div class="col-lg-9 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title">Redactar mensaje</h4></div>
      <div class="card-body">
        <form data-cm-ajax method="post" action="<?= P::attr($rutas->url('mensajes/nuevo')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="mensaje">
          <?php if ($responde !== null): ?>
          <input type="hidden" name="responde_a" value="<?= P::attr($_GET['responde'] ?? '') ?>">
          <?php endif; ?>

          <?php /* ---------------------------------------------------------
               Al cliente se le ofrecen DOS cosas como mucho: su
               banquero, si lo tiene, y Atención a Clientes. No la
               plantilla entera, que es lo que había.

               Cuando solo hay una opción no se le pide que elija de un
               desplegable de un elemento: se le dice a dónde va y se
               manda. Pedir que escoja lo único que hay es una pregunta
               cuya respuesta ya se conoce.
               --------------------------------------------------------- */ ?>
          <?php if ($es_cliente && count($destinatarios) === 1): ?>
            <input type="hidden" name="destinatario"
                   value="<?= P::attr($destinatarios[0]['valor']) ?>">
            <div class="cm-destino-fijo">
              <span class="cm-destino-fijo__marca" aria-hidden="true"></span>
              <span>
                <strong><?= P::e($destinatarios[0]['etiqueta']) ?></strong>
                <small><?= P::e($destinatarios[0]['nota']) ?></small>
              </span>
            </div>
          <?php else: ?>
          <div class="form-group">
            <label for="destinatario">
              <?= $es_cliente ? '¿A quién dirige su mensaje?' : 'Destinatario' ?>
            </label>
            <select class="form-control" id="destinatario" name="destinatario" required>
              <option value="">— Seleccione —</option>
              <?php foreach ($destinatarios as $d): ?>
              <option value="<?= P::attr($d['valor']) ?>"
                <?= ($responde !== null && ($responde['codigo_destino'] ?? '') === $d['valor']) ? 'selected' : '' ?>>
                <?= P::e($d['etiqueta']) ?><?= $d['nota'] !== '' && !$es_cliente ? ' — ' . P::e($d['nota']) : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
            <?php if ($es_cliente): ?>
            <small class="form-text text-muted">
              Su mensaje llega al equipo que corresponde y le responde quien
              lo atienda. Queda registrado en su bandeja de enviados.
            </small>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <div class="form-group">
            <label for="asunto">Asunto</label>
            <input type="text" class="form-control" id="asunto" name="asunto" maxlength="160" required
              value="<?= P::attr($responde !== null ? 'Re: ' . $responde['asunto'] : '') ?>">
          </div>

          <div class="form-group">
            <label for="cuerpo">Mensaje</label>
            <textarea class="form-control" id="cuerpo" name="cuerpo" rows="8" maxlength="5000" required
                      placeholder="Escriba su mensaje..."></textarea>
            <small class="form-text text-muted">Máximo 5.000 caracteres.</small>
          </div>

          <button type="submit" class="btn btn-primary">Enviar mensaje</button>
          <a href="<?= P::attr($rutas->url('mensajes')) ?>" class="btn btn-outline-secondary">Cancelar</a>
        </form>
      </div>
    </div>
  </div>
</div>
