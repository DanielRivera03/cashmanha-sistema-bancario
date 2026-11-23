<?php
/** @var \CashMan\Http\Enrutador $rutas */
use CashMan\Http\Plantilla as P;
?>
<div class="row justify-content-center">
  <div class="col-lg-8 col-md-12">
    <div class="card">
      <div class="card-header"><h4 class="card-title">Reportar un problema</h4></div>
      <div class="card-body">
        <p class="text-muted" style="font-size:.9rem">
          Describe con el mayor detalle posible lo que ocurrió. Si el problema
          involucra una operación, incluye la referencia.
        </p>

        <form data-cm-ajax method="post" action="<?= P::attr($rutas->url('tickets/nuevo')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="ticket">

          <div class="form-group">
            <label for="titulo">Asunto</label>
            <input type="text" class="form-control" id="titulo" name="titulo" maxlength="160" required
                   placeholder="Resume el problema en una línea">
          </div>

          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="categoria">Categoría</label>
              <select class="form-control" id="categoria" name="categoria">
                <option value="acceso">Acceso y contraseñas</option>
                <option value="transaccion">Transacciones</option>
                <option value="tarjeta">Tarjetas</option>
                <option value="credito">Créditos</option>
                <option value="sistema">Sistema</option>
                <option value="otro" selected>Otro</option>
              </select>
            </div>
            <div class="form-group col-md-6">
              <label for="prioridad">Prioridad</label>
              <select class="form-control" id="prioridad" name="prioridad">
                <option value="baja">Baja</option>
                <option value="media" selected>Media</option>
                <option value="alta">Alta</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label for="descripcion">Descripción</label>
            <textarea class="form-control" id="descripcion" name="descripcion" rows="7"
                      maxlength="4000" required
                      placeholder="¿Qué pasó? ¿Qué esperabas que pasara? ¿Puede reproducirlo?"></textarea>
            <small class="form-text text-muted">Máximo 4.000 caracteres.</small>
          </div>

          <button type="submit" class="btn btn-primary">Enviar reporte</button>
          <a href="<?= P::attr($rutas->url('tickets')) ?>" class="btn btn-outline-secondary">Cancelar</a>
        </form>
      </div>
    </div>
  </div>
</div>
