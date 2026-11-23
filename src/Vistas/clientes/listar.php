<?php
/**
 * Listado de clientes.
 *
 * ONCE COLUMNAS NO CABEN EN UNA PANTALLA
 *
 * La versión anterior ponía una columna por dato —cliente, usuario,
 * DUI, contacto, cuentas, saldo, créditos, deuda, expediente, estado y
 * acciones— y el resultado era una tabla más ancha que cualquier
 * monitor: había que desplazarse a la derecha para ver la deuda, y al
 * volver ya no se sabía de quién era. El nombre, encajado en una
 * columna estrecha, se partía en cuatro líneas.
 *
 * Aquí los once datos siguen estando, pero agrupados por lo que se
 * mira junto:
 *
 *   QUIÉN      nombre, usuario y documento, uno debajo de otro
 *   CONTACTO   correo y teléfono
 *   PRODUCTOS  cuántas cuentas y cuántos créditos
 *   SALDO      lo que tiene con el banco
 *   DEUDA      lo que le debe al banco
 *   SITUACIÓN  estado de la cuenta y del expediente
 *
 * Seis columnas y las acciones. Entra en pantalla sin desplazamiento
 * lateral, y cada fila se lee de un vistazo en vez de a trozos.
 *
 * ORDENAR POR DINERO, NO POR TEXTO
 *
 * Las celdas de importe llevan `data-order` con el valor en centavos.
 * Sin eso DataTables ordena «$1,000.00» antes que «$9.00», porque
 * compara las cadenas carácter a carácter.
 *
 * @var array $clientes
 * @var array $resumen
 * @var string $busqueda
 * @var bool $puedeEditar
 * @var bool $puedeReferencias
 * @var bool $puedeCuenta
 * @var bool $puedeTarjeta
 * @var bool $puedeCredito
 * @var bool $puedeUsuario
 */
use CashMan\Http\Plantilla as P;
?>
<div class="row">
  <?php foreach ([
    ['Clientes',        (int)($resumen['total'] ?? 0),        'dark'],
    ['Activos',         (int)($resumen['activos'] ?? 0),      'success'],
    ['Con expediente',  (int)($resumen['con_expediente'] ?? 0),'info'],
  ] as [$et,$v,$c]): ?>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et"><?= P::e($et) ?></div>
      <div class="cm-kpi__val text-<?= P::attr($c) ?>"><?= number_format($v) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="col-xl-3 col-lg-6 col-sm-6 mb-4">
    <div class="cm-kpi">
      <div class="cm-kpi__et">Captaciones</div>
      <div class="cm-kpi__val"><?= P::e(P::money((int)($resumen['captaciones'] ?? 0))) ?></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
    <h4 class="card-title mb-0">Clientes</h4>
    <form method="get" class="form-inline">
      <input type="text" class="form-control form-control-sm mr-2" name="q"
             value="<?= P::attr($busqueda) ?>" placeholder="Nombre, correo o DUI">
      <button class="btn btn-sm btn-outline-primary">Buscar</button>
    </form>
  </div>
  <div class="card-body p-0">
    <?php if ($clientes === []): ?>
      <div class="cm-vacio"><p class="mb-0">Sin clientes<?= $busqueda!==''?' que coincidan':'' ?>.</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0 cm-tabla-clientes" data-cm-tabla>
        <thead>
          <tr>
            <th>Cliente</th>
            <th>Contacto</th>
            <th class="cm-num">Productos</th>
            <th class="cm-num">Saldo</th>
            <th class="cm-num">Deuda</th>
            <th>Situación</th>
            <th class="cm-acciones">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($clientes as $c):
          $nombre  = trim($c['nombres'] . ' ' . $c['apellidos']);
          $ficha   = $rutas->url('clientes/' . $c['codigo']);
          $saldo   = (int) $c['saldo_total_cent'];
          $deuda   = (int) $c['deuda_cent'];
          $cuentas = (int) $c['cuentas'];
          $creds   = (int) $c['creditos_vigentes'];
        ?>
          <tr>
            <?php /* Quién es: el nombre manda, y debajo lo que sirve
                     para identificarlo sin abrir la ficha. */ ?>
            <td class="cm-cli">
              <a href="<?= P::attr($ficha) ?>" class="cm-cli__nombre"><?= P::e($nombre) ?></a>
              <div class="cm-cli__meta">
                <span class="cm-mono"><?= P::e($c['usuario']) ?></span>
                <?php if (!empty($c['dui'])): ?>
                  <span class="cm-cli__sep">·</span>
                  <span class="cm-mono">DUI <?= P::e($c['dui']) ?></span>
                <?php endif; ?>
              </div>
            </td>

            <td class="cm-cli__contacto">
              <span class="cm-cli__correo" title="<?= P::attr($c['correo']) ?>"><?= P::e($c['correo']) ?></span>
              <?php if (!empty($c['telefono'])): ?>
                <div class="cm-mono text-muted"><?= P::e($c['telefono']) ?></div>
              <?php endif; ?>
            </td>

            <?php /* Cuentas y créditos juntos: son la misma pregunta
                     —qué tiene contratado— y por separado gastaban dos
                     columnas para dos cifras de un dígito. */ ?>
            <td class="cm-num cm-cli__prod" data-order="<?= $cuentas + $creds ?>">
              <span title="Cuentas"><?= $cuentas ?> cta<?= $cuentas === 1 ? '' : 's' ?></span>
              <?php if ($creds > 0): ?>
                <span class="cm-cli__sep">·</span>
                <span title="Créditos vigentes"><?= $creds ?> créd<?= $creds === 1 ? '' : 's' ?></span>
              <?php endif; ?>
            </td>

            <td class="cm-num" data-order="<?= $saldo ?>"><?= P::e(P::money($saldo)) ?></td>

            <td class="cm-num <?= $deuda > 0 ? 'cm-neg' : 'text-muted' ?>" data-order="<?= $deuda ?>">
              <?= $deuda > 0 ? P::e(P::money($deuda)) : '—' ?>
            </td>

            <td class="cm-cli__sit">
              <span class="badge badge-<?= $c['estado']==='activo'?'success':'secondary' ?>">
                <?= P::e($c['estado']) ?></span>
              <?php if ((int) $c['perfil_completo'] !== 1): ?>
                <span class="badge badge-warning" title="Falta la declaración de perfil">expediente</span>
              <?php endif; ?>
            </td>

            <td class="cm-acciones">
              <?php /* Consultar primero, modificar después y dar de alta
                       al final: el orden en que se usan, separado por lo
                       que cambian. */ ?>
              <?= P::crudo(cm_acciones([
                    ['Ver expediente', $ficha, 'search',
                     'titulo' => 'Ficha completa del cliente'],

                    ['Editar expediente', $ficha . '#expediente',
                     'files', 'si' => $puedeEditar],

                    ['Referencias', $rutas->url('clientes/' . $c['codigo'] . '/referencias'),
                     'users', 'si' => $puedeReferencias],

                    ['Datos de acceso', $rutas->url('usuarios/' . $c['codigo']),
                     'lock', 'si' => $puedeUsuario],

                    ['separador' => true,
                     'si' => $puedeCuenta || $puedeTarjeta || $puedeCredito],

                    ['Abrir cuenta', $rutas->url('cuentas/abrir'), 'wallet',
                     'si' => $puedeCuenta],

                    ['Emitir tarjeta', $rutas->url('tarjetas'), 'card',
                     'si' => $puedeTarjeta],

                    ['Capturar solicitud', $rutas->url('solicitudes/nueva'), 'plus',
                     'si' => $puedeCredito],
                  ])) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
