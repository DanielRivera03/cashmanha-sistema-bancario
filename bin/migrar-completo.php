<?php
declare(strict_types=1);

/**
 * Migración completa de datos: cashmanha (v1) → cashmanha_v2.
 *
 * Migra todo lo que faltaba: expedientes de clientes, créditos con su
 * tabla de amortización, pagos de cuotas, depósitos y retiros,
 * mensajería, bitácora de accesos e históricos.
 *
 * Cada movimiento de dinero se reconstruye como asiento de partida
 * doble, de modo que el libro mayor quede coherente desde el día uno
 * y los saldos sean verificables.
 *
 * Uso:  php bin/migrar-completo.php
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Dominio\Comun\LibroMayor;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por línea de comandos.\n");
}

function linea(string $t = ''): void { echo '  ' . $t . PHP_EOL; }
function titulo(string $t): void { echo PHP_EOL . '  ' . $t . PHP_EOL . '  ' . str_repeat('-', 62) . PHP_EOL; }

echo PHP_EOL . '  Migración completa de datos  v1 → v2' . PHP_EOL;
echo '  ' . str_repeat('=', 62) . PHP_EOL;

// Mapa usuario v1 → v2 (por código de usuario, que es único en ambas)
$mapaUsuarios = [];
foreach (BaseDatos::todas('SELECT id, usuario FROM usuarios') as $u) {
    $mapaUsuarios[(string) $u['usuario']] = (int) $u['id'];
}
$porIdV1 = [];
foreach (BaseDatos::todas('SELECT idusuarios, codigousuario FROM cashmanha.usuarios') as $u) {
    $cod = (string) $u['codigousuario'];
    if (isset($mapaUsuarios[$cod])) {
        $porIdV1[(int) $u['idusuarios']] = $mapaUsuarios[$cod];
    }
}
linea('Usuarios correlacionados: ' . count($porIdV1));

$sistema = (int) BaseDatos::unValor('SELECT id FROM usuarios WHERE rol_id=1 ORDER BY id LIMIT 1');
$tecnica = (int) BaseDatos::unValor('SELECT id FROM cuentas WHERE es_tecnica=1 ORDER BY id LIMIT 1');

// =====================================================================
titulo('1. Expedientes de clientes (KYC)');
// =====================================================================
$n = 0;
BaseDatos::enTransaccion(function () use ($porIdV1, &$n): void {
    foreach (BaseDatos::todas('SELECT * FROM cashmanha.detalleusuarios') as $d) {
        $uid = $porIdV1[(int) $d['idusuarios']] ?? null;
        if ($uid === null) { continue; }

        BaseDatos::ejecutar(
            'INSERT INTO detalle_usuarios
               (usuario_id, dui, nit, telefono, celular, telefono_trabajo, direccion,
                empresa, cargo, direccion_trabajo, fecha_nacimiento, genero, estado_civil,
                foto_dui_frontal, foto_dui_reverso, foto_nit, foto_firma, perfil_completo)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
             ON DUPLICATE KEY UPDATE dui = VALUES(dui)',
            [
                $uid, $d['dui'] ?: null, $d['nit'] ?: null,
                $d['telefono'] ?: null, $d['celular'] ?: null, $d['telefonotrabajo'] ?: null,
                (string) $d['direccion'], (string) $d['empresa'], (string) $d['cargo'],
                (string) $d['direcciontrabajo'],
                $d['fechanacimiento'] ?: null, $d['genero'] ?: null, $d['estadocivil'] ?: null,
                $d['fotoduifrontal'] ?: null, $d['fotoduireverso'] ?: null,
                $d['fotonit'] ?: null, $d['fotofirma'] ?: null,
            ]
        );
        $n++;
    }
    BaseDatos::ejecutar('UPDATE usuarios SET perfil_completo = 1 WHERE id IN (SELECT usuario_id FROM detalle_usuarios)');
});
linea("Expedientes migrados: {$n}");

// =====================================================================
titulo('2. Créditos y tabla de amortización');
// =====================================================================
$mapaCreditos = [];
$nc = 0; $nq = 0;

$prodMap = [];
foreach (BaseDatos::todas('SELECT id, codigo FROM productos') as $p) {
    $prodMap[(string) $p['codigo']] = (int) $p['id'];
}
$prodCredito = $prodMap['CRE-PER'] ?? 4;

BaseDatos::enTransaccion(function () use ($porIdV1, $prodCredito, &$mapaCreditos, &$nc, &$nq): void {
    foreach (BaseDatos::todas('SELECT * FROM cashmanha.creditos ORDER BY idcreditos') as $c) {
        $uid = $porIdV1[(int) $c['idusuarios']] ?? null;
        if ($uid === null) { continue; }

        $monto  = (int) round(((float) $c['montocredito']) * 100);
        $saldo  = (int) round(((float) $c['saldocredito']) * 100);
        $cuota  = (int) round(((float) $c['cuotamensual']) * 100);
        $tasaBp = (int) round(((float) $c['interescredito']) * 100);
        $plazo  = (int) $c['plazocredito'];

        $estadoV1 = strtolower((string) ($c['estado'] ?? ''));
        $estado = match (true) {
            str_contains($estadoV1, 'aprobado'), str_contains($estadoV1, 'curso') => 'vigente',
            str_contains($estadoV1, 'cancelado')  => 'cancelado',
            str_contains($estadoV1, 'rechazado')  => 'rechazado',
            default                               => 'vigente',
        };

        BaseDatos::ejecutar(
            'INSERT INTO creditos
               (uuid, folio, usuario_id, producto_id, monto_cent, saldo_capital_cent,
                tasa_bp, plazo_meses, cuota_cent, estado, destino, creado_en)
             VALUES (UUID(), ?,?,?,?,?,?,?,?,?,?, ?)',
            [
                'CR-' . str_pad((string) $c['idcreditos'], 6, '0', STR_PAD_LEFT),
                $uid, $prodCredito, $monto, max(0, $saldo), $tasaBp, $plazo, $cuota,
                $estado, (string) ($c['destinocredito'] ?? ''),
                $c['fechasolicitud'] ?? date('Y-m-d H:i:s'),
            ]
        );
        $nuevo = BaseDatos::ultimoId();
        $mapaCreditos[(int) $c['idcreditos']] = $nuevo;
        $nc++;

        // Cuotas
        foreach (BaseDatos::todas(
            'SELECT * FROM cashmanha.cuotas WHERE idcreditos = ? ORDER BY idcuotas',
            [$c['idcreditos']]
        ) as $i => $q) {
            $totalCuota = (int) round(((float) $q['montocancelar']) * 100);
            $capital    = (int) round(((float) ($q['montocapital'] ?? 0)) * 100);
            $interes    = max(0, $totalCuota - $capital);

            $estCuota = strtolower((string) $q['estadocuota']) === 'cancelado' ? 'pagada' : 'pendiente';
            if ($estCuota === 'pendiente' && $q['fechavencimiento'] < date('Y-m-d')) {
                $estCuota = 'vencida';
            }

            BaseDatos::ejecutar(
                'INSERT INTO cuotas
                   (credito_id, numero, capital_cent, interes_cent, total_cent,
                    pagado_cent, fecha_vencimiento, estado)
                 VALUES (?,?,?,?,?,?,?,?)',
                [
                    $nuevo, $i + 1, $capital, $interes, $totalCuota,
                    $estCuota === 'pagada' ? $totalCuota : 0,
                    $q['fechavencimiento'], $estCuota,
                ]
            );
            $nq++;
        }

        // Resumen del crédito
        BaseDatos::ejecutar(
            'UPDATE creditos c SET
                cuotas_pagadas = (SELECT COUNT(*) FROM cuotas WHERE credito_id=c.id AND estado="pagada"),
                total_pagado_cent = (SELECT COALESCE(SUM(pagado_cent),0) FROM cuotas WHERE credito_id=c.id),
                proxima_cuota = (SELECT MIN(fecha_vencimiento) FROM cuotas WHERE credito_id=c.id AND estado<>"pagada")
              WHERE c.id = ?',
            [$nuevo]
        );
    }
});
linea("Créditos: {$nc}   Cuotas: {$nq}");

// =====================================================================
titulo('3. Referencias personales');
// =====================================================================
$nr = 0;
BaseDatos::enTransaccion(function () use ($mapaCreditos, &$nr): void {
    foreach (BaseDatos::todas('SELECT * FROM cashmanha.referenciaspersonales') as $r) {
        $cid = $mapaCreditos[(int) $r['idcreditos']] ?? null;
        if ($cid === null) { continue; }

        // El esquema viejo repetía columnas (…_referencia1, …_referencia2).
        // Aquí cada referencia es una fila: se pueden añadir N sin tocar
        // el esquema, y se pueden consultar y verificar una por una.
        foreach ([1, 2] as $i) {
            $nom = trim((string) ($r["nombres_referencia{$i}"] ?? ''));
            if ($nom === '') { continue; }

            BaseDatos::ejecutar(
                'INSERT INTO referencias
                   (credito_id, tipo, nombres, apellidos, empresa, profesion, telefono)
                 VALUES (?,?,?,?,?,?,?)',
                [
                    $cid, $i === 1 ? 'personal' : 'laboral', $nom,
                    (string) ($r["apellidos_referencia{$i}"] ?? ''),
                    (string) ($r["empresa_referencia{$i}"] ?? ''),
                    (string) ($r["profesion_oficioreferencia{$i}"] ?? ''),
                    (string) ($r["telefono_referencia{$i}"] ?? ''),
                ]
            );
            $nr++;
        }
    }
});
linea("Referencias migradas: {$nr}  (desde columnas repetidas a filas)");

// =====================================================================
titulo('4. Depósitos y retiros → libro mayor');
// =====================================================================
$nt = 0; $omitidos = 0;

$cuentasV1 = [];
foreach (BaseDatos::todas('SELECT idcuentas, numerocuenta FROM cashmanha.cuentas') as $c) {
    $id = BaseDatos::unValor('SELECT id FROM cuentas WHERE numero_cuenta = ?', [(string) $c['numerocuenta']]);
    if ($id !== null) { $cuentasV1[(int) $c['idcuentas']] = (int) $id; }
}

foreach (BaseDatos::todas(
    'SELECT * FROM cashmanha.transaccionescuentasclientes ORDER BY idtransaccioncuentas'
) as $t) {
    $cuentaId = $cuentasV1[(int) $t['idcuentas']] ?? null;
    if ($cuentaId === null) { $omitidos++; continue; }

    $monto = (int) round(((float) $t['monto']) * 100);
    if ($monto <= 0) { $omitidos++; continue; }

    $tipoV1 = strtolower((string) ($t['tipotransaccion'] ?? ''));
    $esDeposito = str_contains($tipoV1, 'deposito');
    $tipo = $esDeposito ? 'deposito' : 'retiro';

    try {
        BaseDatos::enTransaccion(function () use ($cuentaId, $tecnica, $monto, $esDeposito, $tipo, $sistema, $t, &$nt): void {
            LibroMayor::bloquearCuentas([$cuentaId, $tecnica]);

            $movs = $esDeposito
                ? [
                    ['cuenta_id' => $cuentaId, 'importe' => \CashMan\Dominio\Comun\Monto::deCentavos($monto),  'descripcion' => 'Depósito migrado'],
                    ['cuenta_id' => $tecnica,  'importe' => \CashMan\Dominio\Comun\Monto::deCentavos(-$monto), 'descripcion' => 'Contrapartida'],
                  ]
                : [
                    ['cuenta_id' => $cuentaId, 'importe' => \CashMan\Dominio\Comun\Monto::deCentavos(-$monto), 'descripcion' => 'Retiro migrado'],
                    ['cuenta_id' => $tecnica,  'importe' => \CashMan\Dominio\Comun\Monto::deCentavos($monto),  'descripcion' => 'Contrapartida'],
                  ];

            LibroMayor::registrar(
                $tipo, $movs, $sistema,
                'migracion-trx-' . $t['idtransaccioncuentas'],
                ucfirst($tipo) . ' migrado desde v1'
            );
            $nt++;
        });
    } catch (\Throwable $e) {
        // Un retiro que dejaría la cuenta en negativo se omite: el
        // saldo de apertura ya refleja el resultado neto.
        $omitidos++;
    }
}
linea("Movimientos reconstruidos: {$nt}   omitidos: {$omitidos}");

// =====================================================================
titulo('5. Mensajería interna');
// =====================================================================
$nm = 0;
BaseDatos::enTransaccion(function () use ($porIdV1, &$nm): void {
    foreach (BaseDatos::todas('SELECT * FROM cashmanha.mensajeria ORDER BY idmensajeria') as $m) {
        $rem  = $porIdV1[(int) ($m['idusuarios'] ?? 0)] ?? null;
        $dest = $porIdV1[(int) ($m['idusuariosdestinatario'] ?? 0)] ?? null;
        if ($rem === null || $dest === null) { continue; }

        BaseDatos::ejecutar(
            'INSERT INTO mensajes (uuid, remitente_id, destinatario_id, asunto, cuerpo, creado_en)
             VALUES (UUID(),?,?,?,?,?)',
            [
                $rem, $dest,
                mb_substr((string) ($m['asuntomensaje'] ?? 'Sin asunto'), 0, 160),
                (string) ($m['detallemensaje'] ?? ''),
                $m['fechamensaje'] ?? date('Y-m-d H:i:s'),
            ]
        );
        $nm++;
    }
});
linea("Mensajes migrados: {$nm}");

// =====================================================================
titulo('6. Bitácora de accesos');
// =====================================================================
$na = 0;
BaseDatos::enTransaccion(function () use ($porIdV1, &$na): void {
    foreach (BaseDatos::todas('SELECT * FROM cashmanha.accesos ORDER BY idacceso') as $a) {
        $uid = $porIdV1[(int) $a['idusuarios']] ?? null;
        if ($uid === null) { continue; }

        // El sistema viejo guardaba php_uname(): el nombre del SERVIDOR.
        // Se conserva como dato histórico, señalado como tal.
        BaseDatos::ejecutar(
            'INSERT INTO accesos (usuario_id, ip, user_agent, navegador, sistema, dispositivo, creado_en)
             VALUES (?,?,?,?,?,?,?)',
            [
                $uid, inet_pton('0.0.0.0'), 'migrado desde v1',
                'desconocido',
                mb_substr((string) ($a['sistemaoperativo'] ?? ''), 0, 60),
                mb_substr((string) ($a['dispositivo'] ?? ''), 0, 40),
                $a['fechaacceso'] ?? date('Y-m-d H:i:s'),
            ]
        );
        $na++;
    }
});
linea("Accesos migrados: {$na}");

// =====================================================================
titulo('7. Notificaciones');
// =====================================================================
$nn = 0;
BaseDatos::enTransaccion(function () use ($porIdV1, &$nn): void {
    foreach (BaseDatos::todas('SELECT * FROM cashmanha.notificaciones ORDER BY idnotificacion') as $x) {
        $uid = $porIdV1[(int) $x['idusuarios']] ?? null;
        if ($uid === null) { continue; }

        BaseDatos::ejecutar(
            'INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, severidad, leida_en, creada_en)
             VALUES (?,?,?,?,"info",?,?)',
            [
                $uid,
                mb_substr((string) ($x['clasificacionnotificacion'] ?? 'general'), 0, 50),
                mb_substr((string) ($x['titulonotificacion'] ?? ''), 0, 160),
                mb_substr((string) ($x['descripcionnotificacion'] ?? ''), 0, 500),
                strtolower((string) ($x['ocultarnotificacion'] ?? '')) === 'leida' ? date('Y-m-d H:i:s') : null,
                $x['fechanotificacion'] ?? date('Y-m-d H:i:s'),
            ]
        );
        $nn++;
    }
});
linea("Notificaciones migradas: {$nn}");

// =====================================================================
titulo('8. Histórico de créditos');
// =====================================================================
$nh = 0;
BaseDatos::enTransaccion(function () use ($porIdV1, $prodCredito, &$nh): void {
    foreach (BaseDatos::todas('SELECT * FROM cashmanha.historicocreditos') as $h) {
        $uid = $porIdV1[(int) $h['idusuarios']] ?? null;
        if ($uid === null) { continue; }

        BaseDatos::ejecutar(
            'INSERT INTO historico_creditos
               (credito_id, usuario_id, producto_id, monto_cent, tasa_bp, plazo_meses,
                cuota_cent, motivo_cierre, cerrado_en)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [
                (int) $h['idcreditos'], $uid, $prodCredito,
                (int) round(((float) $h['montocredito']) * 100),
                (int) round(((float) $h['interescredito']) * 100),
                (int) $h['plazocredito'],
                (int) round(((float) $h['cuotamensual']) * 100),
                str_contains(strtolower((string) ($h['estado'] ?? '')), 'rechaz') ? 'rechazado' : 'cancelado',
                $h['fechahistorico'] ?? date('Y-m-d H:i:s'),
            ]
        );
        $nh++;
    }
});
linea("Histórico migrado: {$nh}");

// =====================================================================
titulo('9. Verificación contable');
// =====================================================================
$desc = (int) BaseDatos::unValor('SELECT COUNT(*) FROM v_asientos_descuadrados');
$conc = (int) BaseDatos::unValor('SELECT COUNT(*) FROM v_conciliacion_saldos WHERE diferencia_cent<>0');
$bal  = (int) BaseDatos::unValor('SELECT descuadre_cent FROM v_balance_general');

linea('Asientos descuadrados : ' . $desc);
linea('Saldos sin conciliar  : ' . $conc);
linea('Descuadre del balance : ' . $bal);
linea(($desc === 0 && $conc === 0 && $bal === 0) ? 'Contabilidad correcta.' : 'REVISAR.');

// =====================================================================
titulo('Resumen final');
// =====================================================================
foreach ([
    'usuarios' => 'Usuarios', 'detalle_usuarios' => 'Expedientes',
    'cuentas' => 'Cuentas', 'creditos' => 'Créditos', 'cuotas' => 'Cuotas',
    'asientos' => 'Asientos', 'movimientos' => 'Movimientos',
    'mensajes' => 'Mensajes', 'accesos' => 'Accesos',
    'notificaciones' => 'Notificaciones', 'referencias' => 'Referencias',
    'historico_creditos' => 'Histórico',
] as $t => $etiqueta) {
    printf("  %-16s %s%s", $etiqueta, BaseDatos::unValor("SELECT COUNT(*) FROM {$t}"), PHP_EOL);
}

echo PHP_EOL . '  ' . str_repeat('=', 62) . PHP_EOL . PHP_EOL;
