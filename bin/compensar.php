#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Barrido diario de compensación.
 *
 * La compensación se aplica sola en cuanto entra dinero a una cuenta.
 * Este barrido recoge lo que ese disparo no alcanza:
 *
 *   - saldo que entró cuando todavía no había cuota vencida, y ahora
 *     sí la hay;
 *   - cuentas donde el cliente firmó la compensación después de que el
 *     dinero ya estuviera ahí;
 *   - abonos que entraron por una vía que no pasa por los servicios
 *     —una corrección manual, una carga de datos—.
 *
 * Solo toca cuentas con la compensación autorizada, respeta el mínimo
 * intocable y el porcentaje pactado, y avisa al cliente de cada
 * aplicación. Pasarlo dos veces el mismo día no cobra dos veces: lo
 * que ya quedó pagado deja de aparecer.
 *
 * Uso:
 *     php bin/compensar.php            aplica
 *     php bin/compensar.php --ensayo   solo dice qué haría
 */

require_once __DIR__ . '/../src/bootstrap.php';

use CashMan\Aplicacion\Servicio\ServicioCompensacion;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;

$ensayo = in_array('--ensayo', $argv, true) || in_array('--dry-run', $argv, true);

$d = static fn(int $c): string => Monto::deCentavos($c)->formateado();

echo "\n  Compensacion automatica" . ($ensayo ? ' (ENSAYO, no aplica nada)' : '') . "\n";
echo '  ' . str_repeat('-', 62) . "\n";

$candidatas = BaseDatos::todas(
    'SELECT c.id, c.numero_cuenta, c.saldo_cent, c.compensacion_bp,
            c.compensacion_minimo_cent,
            CONCAT(u.nombres, " ", u.apellidos) AS cliente,
            COUNT(DISTINCT q.id) AS vencidas,
            COALESCE(SUM(DISTINCT q.total_cent - q.pagado_cent), 0) AS deuda_cent
       FROM cuentas  c
       JOIN usuarios u  ON u.id = c.usuario_id
       JOIN creditos cr ON cr.usuario_id = c.usuario_id
       JOIN cuotas   q  ON q.credito_id = cr.id
      WHERE c.es_tecnica = 0
        AND c.estado = "activa"
        AND c.compensacion_autorizada = 1
        AND c.saldo_cent > 0
        AND q.estado <> "pagada"
        AND q.fecha_vencimiento < CURDATE()
        AND cr.estado IN ("vigente","desembolsado","castigado")
      GROUP BY c.id
      ORDER BY c.id'
);

if ($candidatas === []) {
    echo "  No hay cuentas con compensacion autorizada y saldo que aplicar.\n\n";

    // Decirlo explicitamente evita la duda de si el proceso corrio.
    $sinFirmar = (int) BaseDatos::unValor(
        'SELECT COUNT(DISTINCT c.id)
           FROM cuentas c
           JOIN creditos cr ON cr.usuario_id = c.usuario_id
           JOIN cuotas q    ON q.credito_id = cr.id
          WHERE c.es_tecnica = 0 AND c.estado = "activa"
            AND c.compensacion_autorizada = 0
            AND c.saldo_cent > 0
            AND q.estado <> "pagada" AND q.fecha_vencimiento < CURDATE()'
    );

    if ($sinFirmar > 0) {
        printf("  (%d cuenta(s) tienen saldo y mora pero NO han autorizado la\n", $sinFirmar);
        echo "   compensacion, asi que no se tocan.)\n\n";
    }

    exit(0);
}

printf("  %-14s %-26s %12s %5s %12s\n",
    'CUENTA', 'CLIENTE', 'SALDO', 'MORA', 'DEUDA');

foreach ($candidatas as $c) {
    printf("  %-14s %-26s %12s %5d %12s\n",
        $c['numero_cuenta'],
        mb_substr((string) $c['cliente'], 0, 26),
        $d((int) $c['saldo_cent']),
        (int) $c['vencidas'],
        $d((int) $c['deuda_cent'])
    );
}

echo "\n";

if ($ensayo) {
    printf("  %d cuenta(s) entrarian al barrido. No se aplico nada.\n\n", count($candidatas));
    exit(0);
}

$r = (new ServicioCompensacion())->barrido();

echo '  ' . str_repeat('-', 62) . "\n";
printf("  cuentas revisadas ......... %d\n", $r['cuentas_revisadas']);
printf("  cuentas con aplicacion .... %d\n", $r['cuentas_aplicadas']);
printf("  cuotas cubiertas .......... %d\n", $r['cuotas']);
printf("  total aplicado ............ %s\n\n", $d((int) $r['total_cent']));
