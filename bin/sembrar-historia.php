#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Siembra una cartera de clientes con historia de todo el año.
 *
 * POR QUÉ HACE FALTA
 *
 * Un sistema bancario sin cartera no se puede mirar. Los tableros
 * salen en blanco, la morosidad no tiene tramos, la cobranza no tiene
 * casos y no hay forma de saber si una pantalla aguanta treinta filas
 * o solo tres. Esto crea esa cartera.
 *
 * QUÉ HISTORIA CUENTA
 *
 * La de un banco real, con su mezcla:
 *
 *   · Dos clientes patrimoniales y tres preferentes: saldos altos,
 *     créditos al día, tarjetas de gama alta.
 *   · La mayoría, estándar y cumplida.
 *   · Unos cuantos con tropiezos: una o dos cuotas atrasadas, de las
 *     que se arreglan solas.
 *   · Un grupo en cobranza, repartido por las etapas reales:
 *     administrativa, extrajudicial, demandados en juzgado, con
 *     sentencia, y uno castigado.
 *
 * Porque una cartera donde todos pagan no sirve para probar nada, y
 * una donde todos fallan tampoco se parece a un banco.
 *
 * CÓMO RESPETA LA CONTABILIDAD
 *
 * Todo movimiento pasa por `LibroMayor::registrar()`: partida doble,
 * suma cero, cadena de hashes. Nada se inserta a mano en
 * `movimientos`.
 *
 * Las fechas se corrigen DESPUÉS de registrar, porque el libro mayor
 * sella con la hora actual y aquí hace falta historia. Se puede hacer
 * sin romper nada porque la fecha no entra en el hash de la cadena
 * —se comprobó antes de escribir esto—; lo que sella son el asiento
 * anterior, el tipo, la referencia y los movimientos.
 *
 * Uso:
 *     php bin/sembrar-historia.php                  siembra
 *     php bin/sembrar-historia.php --limpiar        borra lo sembrado antes
 *     php bin/sembrar-historia.php --clientes=30    cuántos
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\Amortizacion;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\ServicioPassword;

/* Ni un correo. Esto crea decenas de clientes y mueve su dinero; cada
   servicio que avisa dispararía su aviso, y con la copia oculta
   configurada todos acabarían en una bandeja real. */
Correo::silenciar();

$opciones = getopt('', ['limpiar', 'clientes::', 'clave::']);

$cuantos = max(1, min(80, (int) ($opciones['clientes'] ?? 30)));
$clave   = (string) ($opciones['clave'] ?? 'CashMan2026#Banca');

/** Marca de lo sembrado, para poder deshacerlo sin tocar lo demás. */
const MARCA = 'sem2026';

/**
 * Marca de ESTA corrida, para las claves de idempotencia.
 *
 * El libro mayor rechaza un asiento cuya `clave_idempotencia` ya
 * exista, y hace bien: es lo que impide cobrar dos veces. Pero al
 * resembrar, las claves de la siembra anterior siguen en la tabla
 * —`--limpiar` borraba cuentas y movimientos, no los asientos—, así
 * que el libro los daba por repetidos y NO los registraba... mientras
 * el contador de este guion sí sumaba. El saldo local se adelantaba
 * al real y el primer retiro dejaba la cuenta en negativo, que es lo
 * que cantaba el CHECK de la base.
 *
 * Con una marca distinta por corrida, cada siembra tiene sus propias
 * claves y no choca con la de ayer.
 */
$corrida = date('ymdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 4);

echo "\n  SIEMBRA DE HISTORIA\n";
echo '  ' . str_repeat('=', 68) . "\n\n";

// =====================================================================
// Limpiar lo de antes
// =====================================================================

if (isset($opciones['limpiar'])) {
    $ids = array_map(
        static fn(array $r): int => (int) $r['id'],
        BaseDatos::todas('SELECT id FROM usuarios WHERE usuario LIKE ?', [MARCA . '.%'])
    );

    if ($ids === []) {
        echo "  No había nada sembrado.\n\n";
    } else {
        $en = implode(',', $ids);

        /* En orden: lo que cuelga primero. Las llaves foráneas en
           cascada harían casi todo, pero no todas las tablas la
           tienen y un borrado a medias deja huérfanos peores que el
           dato original. */
        BaseDatos::ejecutar("DELETE FROM cobranza_gestiones WHERE caso_id IN (SELECT id FROM cobranza_casos WHERE usuario_id IN ({$en}))");
        BaseDatos::ejecutar("DELETE FROM cobranza_casos WHERE usuario_id IN ({$en})");
        BaseDatos::ejecutar("DELETE FROM cuotas WHERE credito_id IN (SELECT id FROM creditos WHERE usuario_id IN ({$en}))");
        BaseDatos::ejecutar("DELETE FROM creditos WHERE usuario_id IN ({$en})");
        BaseDatos::ejecutar("DELETE FROM tarjeta_movimientos WHERE tarjeta_id IN (SELECT id FROM tarjetas WHERE usuario_id IN ({$en}))");
        BaseDatos::ejecutar("DELETE FROM tarjetas WHERE usuario_id IN ({$en})");
        /* Los movimientos se borran POR ASIENTO, no por cuenta.
         *
         * Un asiento de partida doble tiene dos patas: la del cliente
         * y la de la cuenta técnica. Borrar «los movimientos de las
         * cuentas de estos clientes» se llevaba una pata y dejaba la
         * otra, y el asiento quedaba con suma distinta de cero. La
         * primera resiembra dejó así noventa y dos asientos, y el
         * verificador de cuadre los cantó uno a uno.
         *
         * Se localizan los asientos tocados y se borran ENTEROS. La
         * contabilidad no admite medias tintas: o se va el asiento
         * completo o no se va ninguno. */
        $asientos = array_map(
            static fn(array $r): int => (int) $r['id'],
            BaseDatos::todas(
                "SELECT DISTINCT m.asiento_id AS id
                   FROM movimientos m
                  WHERE m.cuenta_id IN (SELECT id FROM cuentas WHERE usuario_id IN ({$en}))"
            )
        );

        if ($asientos !== []) {
            $ea = implode(',', $asientos);

            BaseDatos::ejecutar("DELETE FROM movimientos WHERE asiento_id IN ({$ea})");
            BaseDatos::ejecutar("DELETE FROM asientos WHERE id IN ({$ea})");
        }

        /* La cuenta técnica perdió sus patas de esos asientos, así que
           su saldo ya no cuadra con sus movimientos. Se rehace desde
           ellos, que es la fuente que sigue siendo verdad. */
        foreach (BaseDatos::todas('SELECT id FROM cuentas') as $cta) {
            $suma = (int) BaseDatos::unValor(
                'SELECT COALESCE(SUM(importe_cent), 0) FROM movimientos WHERE cuenta_id = ?',
                [(int) $cta['id']]
            );

            BaseDatos::ejecutar(
                'UPDATE cuentas SET saldo_cent = ? WHERE id = ? AND saldo_cent <> ?',
                [$suma, (int) $cta['id'], $suma]
            );
        }
        BaseDatos::ejecutar("DELETE FROM cuentas WHERE usuario_id IN ({$en})");
        BaseDatos::ejecutar("DELETE FROM detalle_usuarios WHERE usuario_id IN ({$en})");
        BaseDatos::ejecutar("DELETE FROM password_historial WHERE usuario_id IN ({$en})");
        BaseDatos::ejecutar("DELETE FROM notificaciones WHERE usuario_id IN ({$en})");
        BaseDatos::ejecutar("DELETE FROM usuarios WHERE id IN ({$en})");

        /* Y los asientos que quedaron sin movimientos.
         *
         * Borrar cuentas y movimientos deja los asientos huérfanos: no
         * mueven nada, pero sus `clave_idempotencia` siguen ocupando
         * sitio y el libro mayor rechazaría una siembra nueva con las
         * mismas. Se van solo los de la siembra —`clave` empieza por
         * `sem:`— y solo si ya no les cuelga ningún movimiento. */
        $n = BaseDatos::ejecutar(
            'DELETE FROM asientos
              WHERE clave_idempotencia LIKE "sem:%"
                AND NOT EXISTS (SELECT 1 FROM movimientos m WHERE m.asiento_id = asientos.id)'
        );

        printf("  Y %d asiento(s) de la siembra anterior, ya sin movimientos.\n", $n);

        printf("  Borrados %d cliente(s) sembrados y todo lo suyo.\n\n", count($ids));
    }

    if (!isset($opciones['clientes'])) { exit(0); }
}

// =====================================================================
// Los nombres
// =====================================================================

$nombres = [
    ['Ana Beatriz', 'Alvarado Mejía'], ['Carlos Alberto', 'Benítez Flores'],
    ['Diana Carolina', 'Cáceres Rivas'], ['Eduardo José', 'Delgado Morán'],
    ['Fátima Esperanza', 'Escobar Villalta'], ['Gerardo Antonio', 'Fuentes Marín'],
    ['Heidy Marisol', 'Guevara Ponce'], ['Iván Ernesto', 'Hernández Lazo'],
    ['Jacqueline', 'Interiano Ávila'], ['Kevin Alexander', 'Jiménez Durán'],
    ['Lorena Patricia', 'Lemus Carranza'], ['Mauricio Enrique', 'Martínez Sola'],
    ['Nancy Yamileth', 'Navarro Quintanilla'], ['Óscar Armando', 'Orellana Pérez'],
    ['Patricia Elena', 'Peña Zelaya'], ['Rodrigo Ernesto', 'Quintana Serrano'],
    ['Sandra Lisseth', 'Ramírez Bonilla'], ['Tomás Alfredo', 'Solórzano Aguilar'],
    ['Úrsula María', 'Trejo Campos'], ['Víctor Manuel', 'Umaña Rodríguez'],
    ['Wendy Carolina', 'Valladares Cruz'], ['Xiomara Beatriz', 'Zepeda Molina'],
    ['Yolanda Esther', 'Acosta Linares'], ['Zoila Margarita', 'Barrera Pineda'],
    ['Alfonso Javier', 'Castaneda Rojas'], ['Brenda Lissette', 'Dubón Salazar'],
    ['César Augusto', 'Elías Paniagua'], ['Daniela Sofía', 'Figueroa Nolasco'],
    ['Ernesto Rafael', 'Galdámez Rivera'], ['Fernanda Lucía', 'Henríquez Portillo'],
    ['Gustavo Adolfo', 'Iraheta Menjívar'], ['Hilda Concepción', 'Jacobo Larín'],
    ['Israel Antonio', 'Kuri Mendoza'], ['Julia Antonieta', 'López Arévalo'],
    ['Kenneth David', 'Monterrosa Grande'], ['Lucía Fernanda', 'Nieto Barahona'],
    ['Manuel Alejandro', 'Oliva Recinos'], ['Noemí Guadalupe', 'Palacios Sandoval'],
    ['Pablo Esteban', 'Quijano Mancía'], ['Raquel Vanessa', 'Rosales Erazo'],
];

$ocupaciones = [
    ['empleo', 'Contadora', 'Inversiones del Pacífico, S.A.', 'Analista contable'],
    ['empleo', 'Ingeniero de sistemas', 'Tecnología Integral', 'Desarrollador'],
    ['empleo', 'Enfermera', 'Hospital Nacional', 'Jefa de turno'],
    ['empleo', 'Docente', 'Colegio San Rafael', 'Profesora'],
    ['empleo', 'Mecánico automotriz', 'Taller Central', 'Jefe de taller'],
    ['negocio_propio', 'Comerciante', '', ''],
    ['negocio_propio', 'Dueña de panadería', '', ''],
    ['negocio_propio', 'Transportista', '', ''],
    ['honorarios', 'Abogado', '', ''],
    ['honorarios', 'Diseñadora gráfica', '', ''],
    ['remesas', 'Ama de casa', '', ''],
    ['pension', 'Pensionado', '', ''],
    ['rentas', 'Arrendador de locales', '', ''],
];

// =====================================================================
// El reparto: quién es quién
// =====================================================================

/**
 * El perfil de cada cliente.
 *
 * Los porcentajes no son al azar: se parecen a los de una cartera
 * real. La mayoría paga, una minoría se atrasa y una minoría muy
 * pequeña acaba en el juzgado. Si estuviera repartido en partes
 * iguales, las pantallas de cobranza saldrían llenas y las de cartera
 * sana vacías, y ninguna de las dos se parecería a lo que un
 * empleado ve cada día.
 */
function perfilDe(int $i, int $total): array
{
    $p = $i / max(1, $total - 1);

    return match (true) {
        $i === 0 => ['segmento' => 'patrimonial', 'salud' => 'excelente', 'ingreso' => 1200000],
        $i === 1 => ['segmento' => 'patrimonial', 'salud' => 'excelente', 'ingreso' => 950000],
        $i <= 4  => ['segmento' => 'preferente',  'salud' => 'excelente', 'ingreso' => 500000],
        $p <= 0.60 => ['segmento' => 'estandar',  'salud' => 'sana',      'ingreso' => random_int(60000, 180000)],
        $p <= 0.75 => ['segmento' => 'estandar',  'salud' => 'tropiezo',  'ingreso' => random_int(45000, 110000)],
        $p <= 0.85 => ['segmento' => 'estandar',  'salud' => 'mora',      'ingreso' => random_int(40000, 90000)],
        $p <= 0.93 => ['segmento' => 'estandar',  'salud' => 'judicial',  'ingreso' => random_int(35000, 80000)],
        default    => ['segmento' => 'estandar',  'salud' => 'castigado', 'ingreso' => random_int(30000, 70000)],
    };
}

// =====================================================================
// Ayudantes
// =====================================================================

$sp      = new ServicioPassword();
$hashBase = $sp->hashear($clave);

$rolCliente = (int) BaseDatos::unValor('SELECT id FROM roles WHERE clave = "cliente"');
$tecnica    = (int) BaseDatos::unValor('SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1');

if ($rolCliente === 0 || $tecnica === 0) {
    exit("  Falta el rol de cliente o la cuenta técnica.\n");
}

$prodAhorro = (int) BaseDatos::unValor('SELECT id FROM productos WHERE codigo = "AHO-PER"');

$prodCredito = BaseDatos::todas(
    'SELECT id, codigo, nombre, tasa_interes_bp FROM productos WHERE tipo = "credito"'
);

$prodTarjeta = BaseDatos::todas(
    'SELECT id, codigo, nombre FROM productos WHERE tipo = "tarjeta" AND codigo <> "TAR-DEB"'
);

/** Fecha de un asiento, corregida después de registrarlo. */
function fechar(int $asientoId, string $cuando): void
{
    BaseDatos::ejecutar('UPDATE asientos SET creado_en = ? WHERE id = ?', [$cuando, $asientoId]);
    BaseDatos::ejecutar('UPDATE movimientos SET creado_en = ? WHERE asiento_id = ?', [$cuando, $asientoId]);
}

/** Un número de cuenta que no esté usado ni retirado. */
function numeroLibre(): string
{
    do {
        $n = (string) random_int(100000000, 999999999);

        $existe = BaseDatos::unValor(
            'SELECT 1 FROM cuentas WHERE numero_cuenta = ?
              UNION ALL
             SELECT 1 FROM numeros_cuenta_retirados WHERE numero_cuenta = ?
              LIMIT 1',
            [$n, $n]
        );
    } while ($existe !== null);

    return $n;
}

/**
 * Le pone un crédito al cliente, con su tabla y su historia de pagos.
 *
 * LA SALUD DECIDE LA HISTORIA
 *
 *   excelente / sana   al día; lo vencido está pagado
 *   tropiezo           una o dos cuotas atrasadas: mora leve
 *   mora               tres a cinco sin pagar: mora avanzada
 *   judicial           medio año sin pagar, expediente en el juzgado
 *   castigado          todo vencido, dado contablemente por perdido
 *
 * La tabla se arma con `Amortizacion`, la MISMA clase que usa el
 * sistema al desembolsar de verdad. Replicar aquí la fórmula daría una
 * cartera que no coincide con lo que el propio sistema calcula, y el
 * verificador de cuadre lo cantaría cuota por cuota.
 *
 * La mora se calcula con `Mora::calcular`, por el mismo motivo: lleva
 * dentro los días de gracia y el tope por cuota, y escribir «días por
 * 5.99» a mano se saltaría los dos.
 *
 * @return array{id:int,folio:string,saldo:int,impagas:int,dias:int}
 */
function sembrarCredito(
    int $uid,
    int $cuentaId,
    array $perfil,
    array $producto,
    DateTimeImmutable $hoy
): array {
    /* El monto va con el bolsillo. Prestarle cien mil a quien gana
       cuatrocientos al mes no es una cartera realista: es una cartera
       imposible, y la morosidad que saliera de ahí no diría nada. */
    $monto = match ($perfil['segmento']) {
        'patrimonial' => random_int(5000000, 15000000),
        'preferente'  => random_int(1500000, 5000000),
        default       => random_int(100000, 1200000),
    };

    $plazo = [12, 18, 24, 36, 48][random_int(0, 4)];
    $tasa  = (int) $producto['tasa_interes_bp'];

    /* Hace cuánto se desembolsó. Los sanos, hace poco; los judiciales,
       hace mucho: una demanda no se presenta al segundo impago, lleva
       meses de gestión antes. */
    $mesesAtras = match ($perfil['salud']) {
        'excelente', 'sana' => random_int(3, 9),
        'tropiezo'          => random_int(5, 11),
        'mora'              => random_int(7, 13),
        'judicial'          => random_int(12, 18),
        default             => random_int(16, 22),
    };

    $desembolso = $hoy->modify("-{$mesesAtras} months");

    $am    = new Amortizacion(Monto::deCentavos($monto), $tasa, $plazo);
    $tabla = $am->tabla($desembolso->modify('+1 month'));
    $cuota = $am->cuota();

    $vencidas = 0;

    foreach ($tabla as $t) {
        if ($t['vencimiento'] < $hoy->format('Y-m-d')) { $vencidas++; }
    }

    $impagas = match ($perfil['salud']) {
        'excelente', 'sana' => 0,
        'tropiezo'          => min($vencidas, random_int(1, 2)),
        'mora'              => min($vencidas, random_int(3, 5)),
        'judicial'          => min($vencidas, random_int(6, 9)),
        default             => $vencidas,
    };

    $pagadas = max(0, $vencidas - $impagas);

    $estado = $perfil['salud'] === 'castigado' ? 'castigado' : 'vigente';

    $clasificacion = match ($perfil['salud']) {
        'excelente', 'sana' => 'A',
        'tropiezo'          => 'B',
        'mora'              => 'C',
        'judicial'          => 'D',
        default             => 'E',
    };

    $destinos = [
        'Consolidación de deudas', 'Mejoras a la vivienda', 'Capital de trabajo',
        'Compra de vehículo', 'Gastos médicos', 'Estudios universitarios',
        'Compra de mobiliario', 'Gastos personales',
    ];

    $folio = 'CR-' . $hoy->format('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));

    BaseDatos::ejecutar(
        'INSERT INTO creditos
           (uuid, folio, usuario_id, producto_id, cuenta_desembolso_id, monto_cent,
            saldo_capital_cent, total_pagado_cent, tasa_bp, plazo_meses, cuota_cent,
            estado, clasificacion, aprobado_en, desembolsado_en, creado_en,
            cuotas_pagadas, proxima_cuota, destino)
         VALUES (UUID(),?,?,?,?,?,?,0,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $folio, $uid, (int) $producto['id'], $cuentaId, $monto,
            $monto, $tasa, $plazo, $cuota->centavos,
            $estado, $clasificacion,
            $desembolso->format('Y-m-d H:i:s'),
            $desembolso->format('Y-m-d H:i:s'),
            $desembolso->format('Y-m-d H:i:s'),
            $pagadas,
            $tabla[min($pagadas, count($tabla) - 1)]['vencimiento'],
            $destinos[random_int(0, count($destinos) - 1)],
        ]
    );

    $creditoId = (int) BaseDatos::ultimoId();

    $capitalPagado = 0;
    $totalPagado   = 0;
    $diasPeor      = 0;

    foreach ($tabla as $i => $t) {
        $num = $i + 1;

        /* Los seguros se calculan sobre el saldo, como en el sistema, y
           `total_cent` los incluye: la comprobación 1 del verificador
           exige que el total sea exactamente la suma de sus partes. */
        $vida   = (int) round($t['saldo']->centavos * 0.0005);
        $empleo = (int) round($t['saldo']->centavos * 0.0006);
        $total  = $t['cuota']->centavos + $vida + $empleo;

        $yaVencio = $t['vencimiento'] < $hoy->format('Y-m-d');
        $estaPaga = $yaVencio && $num <= $pagadas;

        if ($estaPaga) {
            $capitalPagado += $t['capital']->centavos;
            $totalPagado   += $total;
        }

        $dias = $yaVencio && !$estaPaga
            ? (int) (new DateTimeImmutable($t['vencimiento']))->diff($hoy)->days
            : 0;

        if ($dias > $diasPeor) { $diasPeor = $dias; }

        $mora = $dias > 0
            ? \CashMan\Dominio\Credito\Mora::calcular(Monto::deCentavos($total), $dias)->centavos
            : 0;

        BaseDatos::ejecutar(
            'INSERT INTO cuotas
               (credito_id, numero, capital_cent, interes_cent, seguro_vida_cent,
                seguro_empleo_cent, mora_cent, total_cent, saldo_posterior_cent,
                pagado_cent, fecha_vencimiento, estado, dias_mora, pagada_en,
                pagada_tarde)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)',
            [
                $creditoId, $num,
                $t['capital']->centavos, $t['interes']->centavos,
                $vida, $empleo, $mora, $total,
                $t['saldo']->centavos,
                $estaPaga ? $total : 0,
                $t['vencimiento'],
                $estaPaga ? 'pagada' : ($yaVencio ? 'vencida' : 'pendiente'),
                $dias,
                $estaPaga
                    ? (new DateTimeImmutable($t['vencimiento']))->format('Y-m-d H:i:s')
                    : null,
            ]
        );
    }

    /* El saldo del crédito es el capital que queda por cobrar, ni más
       ni menos: la comprobación 2 compara una cosa con la otra. */
    BaseDatos::ejecutar(
        'UPDATE creditos SET saldo_capital_cent = ?, total_pagado_cent = ? WHERE id = ?',
        [max(0, $monto - $capitalPagado), $totalPagado, $creditoId]
    );

    return [
        'id'      => $creditoId,
        'folio'   => $folio,
        'saldo'   => max(0, $monto - $capitalPagado),
        'impagas' => $impagas,
        'dias'    => $diasPeor,
    ];
}

/**
 * Abre el caso de cobranza que corresponde a la salud del cliente.
 *
 * La etapa no es decorativa: dice lo que el banco YA hizo. Con tres
 * meses de atraso se llama por teléfono; con seis el expediente va al
 * abogado; con un año hay demanda admitida y embargo pedido. Poner a
 * todos en «judicial» desde el primer impago daría una cartera que no
 * se parece a la de ningún banco, y las pantallas de cobranza no
 * servirían para ensayar el trabajo real.
 */
function sembrarCobranza(int $uid, array $credito, array $perfil, DateTimeImmutable $hoy): void
{
    $etapa = match ($perfil['salud']) {
        'tropiezo'  => 'preventiva',
        'mora'      => random_int(0, 1) === 0 ? 'administrativa' : 'extrajudicial',
        'judicial'  => random_int(0, 1) === 0 ? 'judicial' : 'sentencia',
        'castigado' => 'castigado',
        default     => null,
    };

    if ($etapa === null) { return; }

    $dias    = max(1, $credito['dias']);
    $abierto = $hoy->modify('-' . max(1, (int) ($dias * 0.8)) . ' days');

    $juzgados = [
        'Juzgado Primero de lo Civil y Mercantil de San Salvador',
        'Juzgado Segundo de lo Civil y Mercantil de San Salvador',
        'Juzgado Tercero de lo Civil y Mercantil de Santa Tecla',
    ];

    $abogados = ['Lic. Mauricio Interiano', 'Licda. Rosa Elena Campos', 'Lic. Jorge Alvarenga'];

    $enJuzgado = in_array($etapa, ['judicial', 'sentencia'], true);

    $folio = 'COB-' . $hoy->format('y') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

    BaseDatos::ejecutar(
        'INSERT INTO cobranza_casos
           (uuid, folio, credito_id, usuario_id, etapa, deuda_cent, mora_cent,
            dias_mora, cuotas_vencidas, juzgado, referencia_judicial, abogado,
            demanda_en, emplazamiento_en, sentencia_en, sentencia_favorable,
            abierto_en, actualizado_en)
         VALUES (UUID(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $folio, $credito['id'], $uid, $etapa,
            $credito['saldo'],
            (int) BaseDatos::unValor(
                'SELECT COALESCE(SUM(mora_cent),0) FROM cuotas WHERE credito_id = ?',
                [$credito['id']]
            ),
            $dias,
            $credito['impagas'],
            $enJuzgado ? $juzgados[random_int(0, 2)] : null,
            $enJuzgado ? 'EXP-' . random_int(1000, 9999) . '-' . $hoy->format('Y') : null,
            $enJuzgado ? $abogados[random_int(0, 2)] : null,
            $enJuzgado ? $abierto->modify('+20 days')->format('Y-m-d') : null,
            $enJuzgado ? $abierto->modify('+50 days')->format('Y-m-d') : null,
            $etapa === 'sentencia' ? $abierto->modify('+110 days')->format('Y-m-d') : null,
            $etapa === 'sentencia' ? 1 : null,
            $abierto->format('Y-m-d H:i:s'),
            $hoy->format('Y-m-d H:i:s'),
        ]
    );

    $casoId = (int) BaseDatos::ultimoId();

    /* El guion, en el orden en que de verdad ocurre. Se recorre hasta
       la etapa del caso y ni un paso más: un expediente en gestión
       administrativa no puede tener anotada la audiencia. */
    $guion = [
        ['preventiva',     'llamada',       'Recordatorio de cuota próxima a vencer',       'contactado'],
        ['administrativa', 'llamada',       'Primera gestión de cobro telefónica',          'promesa_pago'],
        ['administrativa', 'correo',        'Estado de cuenta y requerimiento de pago',     'enviada'],
        ['administrativa', 'mensaje',       'Recordatorio por mensaje de texto',            'no_contesta'],
        ['extrajudicial',  'visita',        'Visita domiciliar de cobro',                   'contactado'],
        ['extrajudicial',  'requerimiento', 'Requerimiento extrajudicial de pago',          'negativa'],
        ['judicial',       'escrito',       'Expediente remitido al departamento jurídico', 'sin_resultado'],
        ['judicial',       'demanda',       'Demanda ejecutiva presentada y admitida',      'sin_resultado'],
        ['sentencia',      'audiencia',     'Audiencia celebrada; sentencia favorable',     'sin_resultado'],
        ['sentencia',      'escrito',       'Solicitud de embargo sobre bienes del deudor', 'sin_resultado'],
        ['castigado',      'nota',          'Crédito castigado contablemente',              'sin_resultado'],
    ];

    $orden = [
        'preventiva' => 1, 'administrativa' => 2, 'extrajudicial' => 3,
        'judicial' => 4, 'sentencia' => 5, 'castigado' => 6,
    ];

    $paso = 0;

    foreach ($guion as [$deEtapa, $tipo, $asunto, $resultado]) {
        if ($orden[$deEtapa] > $orden[$etapa]) { break; }

        $cuando = $abierto->modify('+' . ($paso * max(4, (int) ($dias / 12))) . ' days');

        if ($cuando > $hoy) { break; }

        BaseDatos::ejecutar(
            'INSERT INTO cobranza_gestiones
               (caso_id, tipo, automatica, etapa, asunto, detalle, resultado, creada_en)
             VALUES (?,?,0,?,?,?,?,?)',
            [
                $casoId, $tipo, $deEtapa, $asunto,
                'Gestión registrada por el departamento de cobranza.',
                $resultado,
                $cuando->format('Y-m-d H:i:s'),
            ]
        );

        $paso++;
    }
}

/**
 * Emite la tarjeta y le pone consumos, mora y —a algunas— sobregiro.
 *
 * EL SALDO NO SE INVENTA
 *
 * Se emite cada consumo, cada pago, cada interés y cada recargo, y el
 * saldo usado es su suma. La comprobación 10 del verificador compara
 * las dos cosas: una tarjeta con saldo pero sin movimientos que lo
 * expliquen es justo lo que busca.
 *
 * LA MORA DE TARJETA NO ES LA DEL CRÉDITO
 *
 * No hay cuotas: hay un corte que venció sin pagarse. Mora leve es un
 * corte vencido; mora avanzada son varios, con intereses y recargos
 * encima. Por eso el estado del último corte sigue a la salud del
 * cliente en vez de ser siempre «pagado».
 *
 * EL SOBREGIRO
 *
 * Aquí sobregirar es pasarse del límite de la línea dentro de la
 * tolerancia pactada, y lleva su comisión. Se le da a una minoría de
 * tarjetas que lo tengan permitido, que es como ocurre.
 *
 * @return array{sobregiro:bool,mora:bool}
 */
function sembrarTarjeta(
    int $uid,
    int $cuentaId,
    array $perfil,
    array $producto,
    string $titular,
    string $alta,
    DateTimeImmutable $hoy,
    string $corrida
): array {
    $limite = match ($perfil['segmento']) {
        'patrimonial' => random_int(1500000, 4000000),
        'preferente'  => random_int(400000, 1200000),
        default       => random_int(50000, 300000),
    };

    $sana = in_array($perfil['salud'], ['excelente', 'sana'], true);

    /* El dia del mes en que corta esta tarjeta. Se elige una vez y
       manda tanto en la ficha como en la fecha de los cortes. */
    $diaCorte = [5, 10, 15, 20, 25][random_int(0, 4)];

    /* El sobregiro se le da a quien tiene la línea apretada y la
       costumbre de rozarla, no a quien nunca pasa del 30%.
     *
     * Va por turno y no por sorteo: a cara o cruz, una corrida entera
     * podía quedarse sin un solo sobregiro que mirar. */
    static $turno = 0;

    $turno++;

    $conSobregiro = !$sana && $turno % 2 === 0;

    BaseDatos::ejecutar(
        'INSERT INTO tarjetas
           (uuid, usuario_id, cuenta_id, producto_id, token, pan_cifrado, pan_hash,
            ultimos4, bin6, marca, tipo, titular, expira_mes, expira_anio,
            limite_cent, limite_diario_cent, saldo_usado_cent, sobregiro_permitido,
            sobregiro_tolerancia_bp, estado, emitida_en, activada_en, dia_corte,
            dias_para_pago)
         VALUES (UUID(),?,?,?,?,?,?,?,?,?,"credito",?,?,?,?,?,0,?,?,"activa",?,?,?,20)',
        [
            $uid, $cuentaId, (int) $producto['id'],
            bin2hex(random_bytes(20)),
            random_bytes(48),
            hash('sha256', $corrida . ':' . $uid . ':' . random_int(1, PHP_INT_MAX)),
            sprintf('%04d', random_int(0, 9999)),
            (random_int(0, 1) === 0 ? '4' : '5') . random_int(10000, 99999),
            ['visa', 'mastercard'][random_int(0, 1)],
            mb_substr(mb_strtoupper($titular), 0, 120),
            random_int(1, 12),
            (int) $hoy->format('Y') + random_int(2, 5),
            $limite,
            max(20000, (int) round($limite / 4)),
            $conSobregiro ? 1 : 0,
            $conSobregiro ? 1000 : 0,
            $alta, $alta,
            $diaCorte,
        ]
    );

    $tarjetaId = (int) BaseDatos::ultimoId();

    $comercios = [
        ['Super Selectos',       'supermercado'],
        ['Farmacia San Nicolás', 'salud'],
        ['Gasolinera Puma',      'combustible'],
        ['Restaurante La Pampa', 'restaurante'],
        ['Almacenes Simán',      'tienda'],
        ['Claro El Salvador',    'telecomunicaciones'],
        ['Librería Cervantes',   'educacion'],
        ['Cinemark Metrocentro', 'entretenimiento'],
    ];

    $ciudades = ['San Salvador', 'Santa Tecla', 'Antiguo Cuscatlán', 'Soyapango', 'San Miguel'];

    $usado  = 0;
    $hechos = 0;
    $k      = 0;

    $apunta = static function (
        string $tipo,
        int $importe,
        string $comercio,
        string $cat,
        DateTimeImmutable $cuando
    ) use ($tarjetaId, $corrida, $ciudades, &$usado, &$hechos, &$k): void {
        $k++;

        BaseDatos::ejecutar(
            'INSERT INTO tarjeta_movimientos
               (tarjeta_id, tipo, importe_cent, comercio, categoria, referencia,
                estado, creado_en, ciudad, diferible)
             VALUES (?,?,?,?,?,?,"liquidada",?,?,?)',
            [
                $tarjetaId, $tipo, $importe, $comercio, $cat,
                strtoupper(substr(hash('sha256', $corrida . ':' . $tarjetaId . ':' . $k), 0, 20)),
                $cuando->format('Y-m-d H:i:s.u'),
                $ciudades[random_int(0, count($ciudades) - 1)],
                $tipo === 'consumo' ? 1 : 0,
            ]
        );

        $usado += in_array($tipo, ['pago', 'reverso'], true) ? -$importe : $importe;
        $hechos++;
    };

    /* El techo: quien no sobregira no pasa del límite, y quien sobregira
       se pasa pero dentro de la tolerancia pactada, no más allá. */
    $techo = $conSobregiro
        ? (int) round($limite * 1.07)
        : (int) round($limite * ($sana ? 0.55 : 0.85));

    foreach (range(1, random_int(8, 20)) as $ignorado) {
        $cuando = $hoy->modify('-' . random_int(2, 300) . ' days')
                      ->setTime(random_int(8, 21), random_int(0, 59), random_int(0, 59));

        /* Los sanos pagan seguido; los morosos casi no. */
        $pagaHoy = $usado > 0 && $hechos > 2
                && random_int(1, 100) <= ($sana ? 40 : 12);

        if ($pagaHoy) {
            $importe = (int) round($usado * (random_int(30, 100) / 100));

            if ($importe > 0) {
                $apunta('pago', $importe, 'Pago recibido', 'pago', $cuando);
            }

            continue;
        }

        $importe = random_int(1500, max(3000, (int) round($limite / 8)));

        if ($usado + $importe > $techo) { continue; }

        [$com, $cat] = $comercios[random_int(0, count($comercios) - 1)];

        $apunta('consumo', $importe, $com, $cat, $cuando);
    }

    /* Si la tarjeta tiene el sobregiro pactado pero el azar la dejó
       justo por debajo del límite, se le empuja el consumo que lo
       cruza. El bucle de arriba nunca lo haría: corta en cuanto el
       siguiente consumo pasaría del techo, así que la tarjeta se
       quedaba rozando el límite sin cruzarlo jamás y el sobregiro no
       llegaba a existir. */
    if ($conSobregiro && $usado <= $limite) {
        $empuje = $limite - $usado + random_int(1000, max(2000, (int) round($limite * 0.05)));

        [$com, $cat] = $comercios[random_int(0, count($comercios) - 1)];

        $apunta('consumo', $empuje, $com, $cat, $hoy->modify('-' . random_int(2, 25) . ' days'));
    }

    /* La comisión del sobregiro, si de verdad se pasó del límite. */
    if ($conSobregiro && $usado > $limite) {
        $apunta(
            'comision',
            max(0, \CashMan\Infraestructura\Parametros::entero('tarjeta.sobregiro_comision_cent', 500)),
            'Comisión por sobregiro',
            'sobregiro',
            $hoy->modify('-' . random_int(1, 20) . ' days')
        );
    }

    /* La mora de la tarjeta: interés sobre el saldo y recargo por cada
       corte que venció sin pagarse. Leve es uno; avanzada, varios. */
    $cortesVencidos = match ($perfil['salud']) {
        'excelente', 'sana' => 0,
        'tropiezo'          => 1,
        'mora'              => random_int(2, 3),
        default             => random_int(4, 6),
    };

    for ($m = $cortesVencidos; $m >= 1; $m--) {
        if ($usado <= 0) { break; }

        $cuando = $hoy->modify('-' . ($m * 30) . ' days');

        $apunta('interes', max(100, (int) round($usado * 0.0325)), 'Interés del periodo', 'interes', $cuando);
        $apunta('mora', \CashMan\Dominio\Credito\Mora::cargoDeUnDia() * random_int(5, 25), 'Recargo por mora', 'mora', $cuando);
    }

    /* El último corte, con el estado que le toca. Un corte que no se
       pagó no se queda en «cerrado»: vence, y es lo que leen las
       pantallas de mora de tarjeta. */
    /* Una tarjeta morosa tiene el corte ya vencido: si el cliente
       debe, el plazo ya pasó, ésa es la definición. Fechándolo a
       veinte días o menos, la fecha límite caía en el futuro y el
       corte se quedaba en «cerrado», invisible para las pantallas de
       mora. */
    /* El corte cae en el DIA DE CORTE de la tarjeta, no en un dia
     * cualquiera. Un estado de cuenta es mensual: va del dia
     * siguiente al corte anterior hasta el corte de este mes.
     *
     * Fechandolo al azar, una tarjeta que corta los dias 10 acababa
     * con un corte sembrado el 30 de septiembre, y el primer corte
     * de verdad salia del 1 al 10 de octubre: diez dias en un
     * documento que el cliente lee como el mes entero. El servicio
     * de corte hacia lo correcto —arranca donde termino el
     * anterior—; era el dato sembrado el que no respeta el dia de
     * corte de la tarjeta. */
    $mesesAtras = $cortesVencidos > 0 ? random_int(1, 2) : 0;

    $corte = $hoy->modify('-' . $mesesAtras . ' months');
    $corte = $corte->setDate(
        (int) $corte->format('Y'),
        (int) $corte->format('n'),
        min($diaCorte, (int) $corte->format('t'))
    );

    /* Si ese dia aun no ha llegado este mes, el ultimo corte fue el
       del mes pasado: no se puede cortar en el futuro. */
    if ($corte > $hoy) { $corte = $corte->modify('-1 month'); }

    $limitePago = $corte->modify('+20 days');

    $consumos = (int) BaseDatos::unValor(
        'SELECT COALESCE(SUM(importe_cent),0) FROM tarjeta_movimientos
          WHERE tarjeta_id = ? AND tipo IN ("consumo","interes","comision","mora")
            AND creado_en < ?',
        [$tarjetaId, $corte->format('Y-m-d H:i:s')]
    );

    $pagos = (int) BaseDatos::unValor(
        'SELECT COALESCE(SUM(importe_cent),0) FROM tarjeta_movimientos
          WHERE tarjeta_id = ? AND tipo = "pago" AND creado_en < ?',
        [$tarjetaId, $corte->format('Y-m-d H:i:s')]
    );

    $saldoCorte = max(0, $consumos - $pagos);

    BaseDatos::ejecutar(
        'INSERT INTO tarjeta_cortes
           (uuid, tarjeta_id, periodo_inicio, periodo_fin, fecha_corte,
            fecha_limite_pago, saldo_anterior_cent, consumos_cent, pagos_cent,
            saldo_final_cent, pago_minimo_cent, pago_contado_cent, movimientos,
            estado, generado_en)
         VALUES (UUID(),?,?,?,?,?,0,?,?,?,?,?,?,?,?)',
        [
            $tarjetaId,
            $corte->modify('-1 month')->format('Y-m-d'),
            $corte->format('Y-m-d'),
            $corte->format('Y-m-d'),
            $limitePago->format('Y-m-d'),
            $consumos, $pagos, $saldoCorte,
            (int) round($saldoCorte * 0.05),
            $saldoCorte,
            $hechos,
            $cortesVencidos > 0
                ? ($limitePago < $hoy ? 'vencido' : 'cerrado')
                : 'pagado',
            $corte->format('Y-m-d H:i:s'),
        ]
    );

    /* Los movimientos que entran en este corte quedan MARCADOS.
     *
     * `ServicioCorteTarjeta` factura lo que tiene `corte_id` nulo,
     * y asi es como evita cobrar dos veces lo mismo. Al sembrar un
     * corte sin marcar sus movimientos, el primer corte de verdad
     * tomaba el saldo final sembrado como saldo anterior Y volvia a
     * sumar todos los movimientos: el saldo anterior contado dos
     * veces. En una tarjeta la deuda paso de $11,801 a $23,541.
     *
     * El servicio hacia lo correcto; los datos sembrados mentian. */
    BaseDatos::ejecutar(
        'UPDATE tarjeta_movimientos SET corte_id = ?
          WHERE tarjeta_id = ? AND corte_id IS NULL AND creado_en < ?',
        [BaseDatos::ultimoId(), $tarjetaId, $corte->format('Y-m-d H:i:s')]
    );

    BaseDatos::ejecutar(
        'UPDATE tarjetas SET saldo_usado_cent = ?, ultimo_corte = ?, proximo_corte = ? WHERE id = ?',
        [
            max(0, $usado),
            $corte->format('Y-m-d'),
            $corte->modify('+1 month')->format('Y-m-d'),
            $tarjetaId,
        ]
    );

    return [
        'sobregiro' => $conSobregiro && $usado > $limite,
        'mora'      => $cortesVencidos > 0,
    ];
}

$anio      = (int) date('Y');
$hoy       = new DateTimeImmutable();
$creados   = 0;
$conCredito = 0;
$conTarjeta = 0;
$enCobranza = 0;
$conSobregiro = 0;
$tarjetaEnMora = 0;
$arranque  = microtime(true);

printf("  Sembrando %d cliente(s) con historia de %d…\n\n", $cuantos, $anio);

for ($i = 0; $i < $cuantos; $i++) {
    [$nom, $ape] = $nombres[$i % count($nombres)];

    if ($i >= count($nombres)) { $ape .= ' ' . (string) (int) ($i / count($nombres) + 1); }

    $perfil = perfilDe($i, $cuantos);
    $ocu    = $ocupaciones[$i % count($ocupaciones)];

    $usuario = MARCA . '.' . strtolower(
        preg_replace('/[^a-z]/i', '', explode(' ', $nom)[0]) . '.' .
        preg_replace('/[^a-z]/i', '', explode(' ', $ape)[0])
    ) . $i;

    /* Alta escalonada a lo largo del año: un banco no capta treinta
       clientes el mismo día, y las series por mes se ven planas si
       todos entran a la vez. */
    $altaMes  = 1 + (int) floor($i * 10 / max(1, $cuantos));
    $alta     = sprintf('%d-%02d-%02d 09:%02d:00', $anio, $altaMes, random_int(2, 26), random_int(0, 59));

    BaseDatos::enTransaccion(function () use (
        $usuario, $nom, $ape, $perfil, $ocu, $alta, $hashBase, $rolCliente,
        $prodAhorro, $prodCredito, $prodTarjeta, $tecnica, $anio, $hoy, $corrida,
        &$creados, &$conCredito, &$conTarjeta, &$enCobranza,
        &$conSobregiro, &$tarjetaEnMora
    ): void {
        // ---------- El cliente ----------
        //
        // El DUI y el teléfono se calculan aquí, antes de la consulta,
        // porque hacen falta DOS veces: en `usuarios` y en el
        // expediente. Generados dos veces salían distintos, y un
        // cliente con dos documentos no es un cliente.
        $dui      = sprintf('%08d-%d', random_int(0, 99999999), random_int(0, 9));
        $telefono = sprintf('%04d-%04d', random_int(2000, 7999), random_int(1000, 9999));

        BaseDatos::ejecutar(
            'INSERT INTO usuarios
               (uuid, usuario, correo, nombres, apellidos, password_hash,
                password_algoritmo, password_actualizado_en, requiere_cambio_password,
                rol_id, estado, telefono, documento_identidad, tipo_documento,
                perfil_completo, segmento, creado_en, actualizado_en)
             VALUES (UUID(),?,?,?,?,?, "argon2id", ?, 0, ?, "activo", ?,?, "dui", 1, ?, ?, ?)',
            [
                $usuario,
                $usuario . '@example.test',
                $nom, $ape,
                $hashBase,
                $alta,
                $rolCliente,
                $telefono,
                $dui,
                $perfil['segmento'],
                $alta, $alta,
            ]
        );

        $uid = BaseDatos::ultimoId();

        BaseDatos::ejecutar(
            /* El DUI, el NIT y el teléfono van AQUÍ, no solo en
               `usuarios`: la vista de clientes los lee del expediente,
               y sin ellos la tabla pintaba una raya en la columna del
               documento y la búsqueda por DUI no encontraba a nadie.

               El NIT va homologado con el DUI, como en el país desde
               2022 y como lo trata el resto del sistema. */
            'INSERT INTO detalle_usuarios
               (usuario_id, dui, nit, telefono, celular, fuente_ingreso, ocupacion,
                empresa, cargo, antiguedad_meses, ingreso_mensual_cent, direccion,
                perfil_declarado_en, perfil_completo, actualizado_en)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)',
            [
                $uid, $dui, str_replace('-', '', $dui), $telefono, $telefono,
                $ocu[0], $ocu[1], $ocu[2], $ocu[3],
                random_int(12, 180), $perfil['ingreso'],
                'Colonia ' . $ape . ', calle principal, casa ' . random_int(1, 99),
                $alta, $alta,
            ]
        );

        // ---------- Su cuenta ----------
        $numero = numeroLibre();

        BaseDatos::ejecutar(
            'INSERT INTO cuentas
               (uuid, numero_cuenta, usuario_id, producto_id, moneda, saldo_cent,
                estado, origen, apertura_en, compensacion_autorizada,
                compensacion_bp, compensacion_minimo_cent)
             VALUES (UUID(),?,?,?,"USD",0,"activa","ventanilla",?,1,10000,100)',
            [$numero, $uid, $prodAhorro, $alta]
        );

        $cuentaId = BaseDatos::ultimoId();

        // ---------- Su año de movimientos ----------
        $desde = (new DateTimeImmutable($alta))->modify('first day of next month');
        $saldo = 0;

        for ($m = (int) $desde->format('n'); $m <= (int) $hoy->format('n'); $m++) {
            $dia = min(random_int(3, 15), 28);
            $f   = sprintf('%d-%02d-%02d 10:%02d:00', $anio, $m, $dia, random_int(0, 59));

            if ($f > $hoy->format('Y-m-d H:i:s')) { break; }

            // El sueldo entra.
            $sueldo = (int) round($perfil['ingreso'] * (random_int(90, 110) / 100));

            LibroMayor::bloquearCuentas([$cuentaId, $tecnica]);

            $r = LibroMayor::registrar(
                'deposito',
                [
                    ['cuenta_id' => $cuentaId, 'importe' => Monto::deCentavos($sueldo),
                     'descripcion' => 'Abono de salario'],
                    ['cuenta_id' => $tecnica, 'importe' => Monto::deCentavos(-$sueldo),
                     'descripcion' => 'Efectivo recibido'],
                ],
                null,
                'sem:' . $corrida . ':' . $usuario . ':dep:' . $m,
                'Deposito de salario'
            );

            fechar((int) $r['asiento_id'], $f);
            $saldo += $sueldo;

            // Y se gasta casi todo: así se comporta una cuenta de sueldo.
            $gasto = (int) round($sueldo * (random_int(60, 92) / 100));

            if ($gasto > 100 && $saldo - $gasto >= 100) {
                $f2 = sprintf('%d-%02d-%02d 15:%02d:00', $anio, $m, min($dia + 5, 28), random_int(0, 59));

                if ($f2 <= $hoy->format('Y-m-d H:i:s')) {
                    $r2 = LibroMayor::registrar(
                        'retiro',
                        [
                            ['cuenta_id' => $cuentaId, 'importe' => Monto::deCentavos(-$gasto),
                             'descripcion' => 'Retiro en ventanilla'],
                            ['cuenta_id' => $tecnica, 'importe' => Monto::deCentavos($gasto),
                             'descripcion' => 'Efectivo entregado'],
                        ],
                        null,
                        'sem:' . $corrida . ':' . $usuario . ':ret:' . $m,
                        'Retiro de efectivo'
                    );

                    fechar((int) $r2['asiento_id'], $f2);
                    $saldo -= $gasto;
                }
            }
        }

        // ---------- Su crédito, si le toca ----------
        //
        // No todo cuentahabiente debe dinero: en un banco de verdad
        // menos de la mitad tiene crédito vivo. Pero los que están en
        // mora o en juzgado SÍ lo tienen sin excepción, porque sin
        // deuda no hay nada que cobrar y el cliente no aparecería en
        // ninguna de las pantallas que se quieren poder ensayar.
        $leToca = in_array($perfil['salud'], ['tropiezo', 'mora', 'judicial', 'castigado'], true)
               || random_int(1, 100) <= 45;

        if ($leToca) {
            $cr = sembrarCredito(
                $uid, $cuentaId, $perfil,
                $prodCredito[array_rand($prodCredito)],
                $hoy
            );

            $conCredito++;

            sembrarCobranza($uid, $cr, $perfil, $hoy);

            if (in_array($perfil['salud'], ['mora', 'judicial', 'castigado'], true)) {
                $enCobranza++;
            }
        }

        // ---------- Y su tarjeta ----------
        /* Los casos interesantes dejan de depender del azar. Quien
           está en mora lleva tarjeta sí o sí: es el único modo de que
           la mora de tarjeta y el sobregiro aparezcan en TODAS las
           corridas y no sólo en las afortunadas. */
        $tocaTarjeta = $prodTarjeta !== [] && (
            !in_array($perfil['salud'], ['excelente', 'sana'], true)
            || $perfil['segmento'] !== 'estandar'
            || random_int(1, 100) <= 45
        );

        if ($tocaTarjeta) {
            $tj = sembrarTarjeta(
                $uid, $cuentaId, $perfil,
                $prodTarjeta[array_rand($prodTarjeta)],
                $nom . ' ' . $ape, $alta, $hoy, $corrida
            );

            $conTarjeta++;

            if ($tj['sobregiro']) { $conSobregiro++; }
            if ($tj['mora'])      { $tarjetaEnMora++; }
        }

        $creados++;
    });

    if (($i + 1) % 5 === 0) {
        printf("    %d de %d clientes…\n", $i + 1, $cuantos);
    }
}

// =====================================================================
// Rehacer la secuencia de saldos
// =====================================================================
//
// `movimientos.saldo_posterior_cent` es la foto del saldo justo
// después de cada movimiento, y el libro mayor la escribe en el orden
// en que los inserta. Aquí se insertan en orden y DESPUÉS se les
// cambia la fecha hacia atrás, así que leídos por fecha las fotos
// quedan desordenadas: la última cronológicamente ya no es la última
// que se escribió.
//
// El saldo de la cuenta sigue bien —la suma de importes no depende
// del orden— pero la columna del extracto miente, y el verificador lo
// canta: «saldo $666.113 pero el último movimiento dejó $726.230».
//
// Se rehace la secuencia leyendo cada cuenta en orden cronológico y
// reconstruyendo la foto paso a paso. Es lo mismo que hace cualquier
// banco al migrar historia de otro sistema.

echo "  Rehaciendo la secuencia de saldos…\n";

$cuentas = BaseDatos::todas(
    'SELECT DISTINCT m.cuenta_id AS id FROM movimientos m'
);

$ajustadas = 0;

foreach ($cuentas as $c) {
    $id = (int) $c['id'];

    $movs = BaseDatos::todas(
        'SELECT id, importe_cent FROM movimientos
          WHERE cuenta_id = ? ORDER BY creado_en, id',
        [$id]
    );

    $corriente = 0;
    $cambios   = 0;

    foreach ($movs as $m) {
        $corriente += (int) $m['importe_cent'];

        BaseDatos::ejecutar(
            'UPDATE movimientos SET saldo_posterior_cent = ?
              WHERE id = ? AND saldo_posterior_cent <> ?',
            [$corriente, (int) $m['id'], $corriente]
        );

        $cambios++;
    }

    /* El saldo de la cuenta tiene que ser el final de la secuencia.
       Si no lo es, hay movimientos fuera de `movimientos` y eso sí
       sería un problema de verdad: se avisa y no se toca. */
    $saldo = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$id]);

    if ($saldo !== $corriente) {
        printf(
            "    !! cuenta %d: saldo %s pero sus movimientos suman %s\n",
            $id,
            number_format($saldo / 100, 2),
            number_format($corriente / 100, 2)
        );
    }

    if ($cambios > 0) { $ajustadas++; }
}

printf("  %d cuenta(s) con su extracto en orden.\n", $ajustadas);

printf("\n  %d cliente(s) con su cuenta y su año de movimientos.\n", $creados);
printf("  %d con crédito · %d con tarjeta · %d en cobranza.\n",
    $conCredito, $conTarjeta, $enCobranza);
printf("  %d tarjeta(s) en mora · %d con sobregiro.\n",
    $tarjetaEnMora, $conSobregiro);
printf("  Hecho en %.1f s.\n\n", microtime(true) - $arranque);
