<?php
/**
 * @var \CashMan\Http\Enrutador $rutas
 * @var array $sesiones
 * @var array $dispositivos
 * @var string $csrf
 */
use CashMan\Http\Plantilla as P;

$activas = 0;
foreach ($sesiones as $s) {
    if ($s['activa']) { $activas++; }
}

$dispositivos = $dispositivos ?? [];
?>
<div class="row justify-content-center">
  <div class="col-lg-9">

    <!-- ----------------------------------------------------------------
         LOS DISPOSITIVOS

         Una sesión dura horas; un dispositivo es el aparato desde el
         que se entra una y otra vez. Cuando alguien sospecha que le
         entraron a la cuenta, lo que quiere mirar es esta lista, no la
         de sesiones: «¿desde qué aparatos se ha entrado a lo mío?».
         ---------------------------------------------------------------- -->
    <div class="card">
      <div class="card-header">
        <h4 class="card-title mb-0">Dispositivos desde los que ha entrado</h4>
      </div>
      <div class="card-body">

        <p class="text-muted" style="font-size:.88rem">
          Le avisamos cada vez que alguien entra a su cuenta desde un dispositivo que
          no habíamos visto antes. Si ve aquí uno que no reconoce, márquelo:
          <strong>le pedirá código en cada operación</strong> y le avisaremos cada vez
          que entre desde ahí.
        </p>

        <?php if ($dispositivos === []): ?>
          <p class="text-muted mb-0">Todavía no hay dispositivos registrados.</p>
        <?php else: ?>

        <div id="lista-dispositivos">
          <?php foreach ($dispositivos as $d): ?>
          <div class="border rounded p-3 mb-2 d-flex justify-content-between align-items-center flex-wrap"
               data-dispositivo="<?= (int) $d['id'] ?>">
            <div>
              <strong><?= P::e($d['descripcion']) ?></strong>
              <?php if ($d['es_este']): ?>
                <span class="badge badge-info">este mismo</span>
              <?php endif; ?>
              <?php if ((int) $d['bloqueado'] === 1): ?>
                <span class="badge badge-danger">marcado como ajeno</span>
              <?php endif; ?>
              <br>
              <small class="text-muted">
                <?= (int) $d['veces'] ?> entrada(s) ·
                última el <?= P::e(date('d/m/Y H:i', strtotime((string) $d['ultima_vez_en']))) ?>
                <?php if ($d['ip_texto'] !== ''): ?>
                  · desde <?= P::e($d['ip_texto']) ?>
                <?php endif; ?>
              </small>
            </div>

            <?php if ((int) $d['bloqueado'] === 0 && !$d['es_este']): ?>
            <button class="btn btn-sm btn-outline-danger" data-desconocer>
              Éste no fui yo
            </button>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">
          Dispositivos y sesiones
          <span class="badge badge-success ml-1"><?= $activas ?> activa(s)</span>
        </h4>
        <?php if ($activas > 1): ?>
        <form data-cm-ajax data-cm-recargar method="post"
              action="<?= P::attr($rutas->url('perfil/cerrar-otras')) ?>">
          <input type="hidden" name="_csrf" value="<?= P::attr($csrf) ?>">
          <input type="hidden" name="_csrf_proposito" value="perfil">
          <button class="btn btn-sm btn-outline-danger"
                  data-cm-confirmar="Se cerrarán todas las sesiones excepto esta.">
            Cerrar las demás sesiones
          </button>
        </form>
        <?php endif; ?>
      </div>

      <div class="card-body p-0">
        <?php if ($sesiones === []): ?>
          <div class="cm-vacio"><p class="mb-0">Sin sesiones registradas.</p></div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr><th>Dispositivo</th><th>IP</th><th>Inicio</th>
                  <th>Última actividad</th><th>Estado</th></tr>
            </thead>
            <tbody>
            <?php foreach ($sesiones as $s): ?>
              <tr<?= $s['es_esta'] ? ' class="table-info"' : '' ?>>
                <td style="max-width:280px">
                  <small class="text-muted" style="word-break:break-word">
                    <?= P::e(mb_substr((string) $s['user_agent'], 0, 80)) ?>
                  </small>
                  <?php if ($s['es_esta']): ?>
                    <span class="badge badge-primary ml-1">este dispositivo</span>
                  <?php endif; ?>
                </td>
                <td class="cm-mono"><?= P::e($s['ip_txt']) ?></td>
                <td class="text-muted"><?= P::e(P::fecha((string) $s['creada_en'])) ?></td>
                <td class="text-muted"><?= P::e(P::hace((string) $s['ultima_actividad'])) ?></td>
                <td>
                  <?php if ($s['activa']): ?>
                    <span class="badge badge-success">activa</span>
                  <?php else: ?>
                    <span class="badge badge-secondary">
                      <?= P::e($s['motivo_revocacion'] ?: 'expirada') ?>
                    </span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="alert alert-light border" style="font-size:.86rem">
      Si no reconoce alguna sesión, ciérrelas todas y cambie su contraseña de inmediato.
    </div>
  </div>
</div>

<script <?= P::crudo('nonce="' . P::nonce() . '"') ?>>
(function () {
  'use strict';

  var caja = document.getElementById('lista-dispositivos');
  if (!caja) { return; }

  var BASE = <?= P::js($rutas->url('perfil/dispositivo/')) ?>;

  var CSRF_SEMILLA = <?= P::js($csrf) ?>;

  function tokenVigente() {
    return (window.CMToken && window.CMToken.de('perfil')) || CSRF_SEMILLA;
  }

  caja.addEventListener('click', function (ev) {
    if (!ev.target.hasAttribute || !ev.target.hasAttribute('data-desconocer')) { return; }

    var fila  = ev.target.closest('[data-dispositivo]');
    var id    = fila.getAttribute('data-dispositivo');
    var boton = ev.target;

    window.CMAviso.confirmar({
      titulo: '¿No reconoce este dispositivo?',
      texto: 'A partir de ahora le pediremos código en cada operación desde ahí y le '
           + 'avisaremos cada vez que entre. Si de verdad no fue usted, cambie también '
           + 'su contraseña y cierre las demás sesiones.',
      alAceptar: function () {
        boton.disabled = true;

        var fd = new FormData();
        fd.append('_csrf', tokenVigente());
        fd.append('_csrf_proposito', 'perfil');

        fetch(BASE + id + '/desconocer', {
          method: 'POST', body: fd, credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            boton.disabled = false;

            if (!j.ok) { window.avisar('No se pudo', j.mensaje, 'critico'); return; }

            window.avisar('Dispositivo marcado', j.mensaje, 'exito');

            if (window.CMVista) { window.CMVista.refrescar(); }
            else { location.reload(); }
          })
          .catch(function () {
            boton.disabled = false;
            window.avisar('Sin conexión', 'No se pudo contactar al servidor.', 'critico');
          });
      }
    });
  });
})();
</script>
