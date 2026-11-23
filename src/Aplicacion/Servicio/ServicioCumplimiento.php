<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Cumplimiento\UmbralesUIF;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

/**
 * La vigilancia de lavado de dinero.
 *
 * DÓNDE CORRE, Y POR QUÉ AHÍ
 *
 * Fuera de la transacción que mueve el dinero. Siempre.
 *
 * Es tentador enganchar la vigilancia dentro del servicio que ejecuta
 * la operación —se ve más inmediato— pero sería un error grave: un
 * fallo en el monitoreo tumbaría una operación legítima del cliente, y
 * el monitoreo es, por naturaleza, la parte más cambiante del sistema.
 * Nada que cambie tanto debe poder revertir un asiento.
 *
 * Por eso la vigilancia LEE lo ya ocurrido y levanta alertas aparte. Es
 * como funcionan los sistemas de monitoreo de verdad, y tiene además
 * la ventaja de que detecta patrones que ninguna operación aislada
 * puede mostrar: el fraccionamiento solo se ve mirando varios días
 * juntos.
 *
 * LO QUE PRODUCE
 *
 * Alertas, no reportes. Una alerta es una señal que alguien tiene que
 * mirar; la mayoría se descartan con una explicación razonable. El
 * reporte a la UIF es un acto del encargado de cumplimiento, y esa
 * distinción es la diferencia entre vigilar y acusar.
 */
final class ServicioCumplimiento
{
    // =================================================================
    // El barrido
    // =================================================================

    /**
     * Revisa el día indicado y levanta lo que haya.
     *
     * @return array<string,int> cuántas alertas de cada tipo
     */
    public function vigilar(?string $fecha = null, bool $aplicar = true): array
    {
        $dia = $fecha ?? date('Y-m-d');

        $conteo = [];

        foreach (
            [
                'umbral_efectivo_diario'  => fn(): array => $this->efectivoDiario($dia),
                'umbral_efectivo_mensual' => fn(): array => $this->efectivoMensual($dia),
                'umbral_otros_medios'     => fn(): array => $this->otrosMedios($dia),
                'fraccionamiento'         => fn(): array => $this->fraccionamiento($dia),
                'fuera_de_perfil'         => fn(): array => $this->fueraDePerfil($dia),
                'pep_movimiento_alto'     => fn(): array => $this->pepMovimientoAlto($dia),
                'cuenta_nueva_activa'     => fn(): array => $this->cuentaNuevaActiva($dia),
                'lista_restrictiva'       => fn(): array => $this->listaRestrictiva(),
            ] as $tipo => $detector
        ) {
            $hallazgos = $detector();

            $conteo[$tipo] = 0;

            foreach ($hallazgos as $h) {
                if (!$aplicar) {
                    $conteo[$tipo]++;
                    continue;
                }

                if ($this->levantar($tipo, $h)) {
                    $conteo[$tipo]++;
                }
            }
        }

        return $conteo;
    }

    // =================================================================
    // Los detectores
    // =================================================================

    /**
     * Efectivo sobre US$10,000 en un día.
     *
     * Se suman TODAS las operaciones en efectivo del cliente en el día,
     * entradas y salidas por separado: quien deposita $6,000 y retira
     * $6,000 no movió cero, movió doce mil, y la ley habla de
     * operaciones, no de saldo neto.
     *
     * @return array<int,array<string,mixed>>
     */
    private function efectivoDiario(string $dia): array
    {
        $filas = BaseDatos::todas(
            'SELECT cm.cliente_id AS usuario_id,
                    SUM(ABS(cm.importe_cent)) AS monto_cent,
                    COUNT(*) AS operaciones,
                    GROUP_CONCAT(cm.referencia ORDER BY cm.creado_en SEPARATOR ",") AS refs
               FROM caja_movimientos cm
              WHERE cm.medio = "efectivo"
                AND cm.cliente_id IS NOT NULL
                AND DATE(cm.creado_en) = ?
                AND cm.tipo <> "fondo_inicial"
              GROUP BY cm.cliente_id
             HAVING SUM(ABS(cm.importe_cent)) > ?',
            [$dia, UmbralesUIF::EFECTIVO_CENT]
        );

        $salida = [];

        foreach ($filas as $f) {
            $salida[] = [
                'usuario_id'    => (int) $f['usuario_id'],
                'periodo_desde' => $dia,
                'periodo_hasta' => $dia,
                'monto_cent'    => (int) $f['monto_cent'],
                'operaciones'   => (int) $f['operaciones'],
                'severidad'     => 'alta',
                'detalle'       => sprintf(
                    'Movió %s en efectivo en %d operación(es) durante el día. '
                    . 'El artículo 9 de la LCLDA obliga a informar a la UIF las operaciones '
                    . 'en efectivo de un mismo cliente que superen %s en un día.',
                    Monto::deCentavos((int) $f['monto_cent'])->formateado(),
                    (int) $f['operaciones'],
                    Monto::deCentavos(UmbralesUIF::EFECTIVO_CENT)->formateado()
                ),
                'datos'         => ['referencias' => explode(',', (string) $f['refs'])],
            ];
        }

        return $salida;
    }

    /**
     * Efectivo sobre US$10,000 acumulado en el mes calendario.
     *
     * Solo se evalúa al cerrar el mes: antes, la acumulación todavía
     * puede crecer y levantar la alerta a mitad de mes la dejaría con
     * un monto que no es el final. La ley cuenta el mes entero.
     *
     * @return array<int,array<string,mixed>>
     */
    private function efectivoMensual(string $dia): array
    {
        $hoy = new \DateTimeImmutable($dia);

        /* El barrido corre de madrugada, así que el día 1 revisa el mes
           que acaba de cerrar. */
        if ((int) $hoy->format('j') !== 1) {
            return [];
        }

        $mes = $hoy->modify('-1 month');

        $desde = $mes->modify('first day of this month')->format('Y-m-d');
        $hasta = $mes->modify('last day of this month')->format('Y-m-d');

        $filas = BaseDatos::todas(
            'SELECT cm.cliente_id AS usuario_id,
                    SUM(ABS(cm.importe_cent)) AS monto_cent,
                    COUNT(*) AS operaciones
               FROM caja_movimientos cm
              WHERE cm.medio = "efectivo"
                AND cm.cliente_id IS NOT NULL
                AND DATE(cm.creado_en) BETWEEN ? AND ?
                AND cm.tipo <> "fondo_inicial"
              GROUP BY cm.cliente_id
             HAVING SUM(ABS(cm.importe_cent)) > ?',
            [$desde, $hasta, UmbralesUIF::EFECTIVO_CENT]
        );

        $salida = [];

        foreach ($filas as $f) {
            $salida[] = [
                'usuario_id'    => (int) $f['usuario_id'],
                'periodo_desde' => $desde,
                'periodo_hasta' => $hasta,
                'monto_cent'    => (int) $f['monto_cent'],
                'operaciones'   => (int) $f['operaciones'],
                'severidad'     => 'alta',
                'detalle'       => sprintf(
                    'Acumuló %s en efectivo en %d operación(es) durante el mes de %s. '
                    . 'La LCLDA obliga a informar la acumulación mensual que supere %s. '
                    . 'El plazo vence el %s.',
                    Monto::deCentavos((int) $f['monto_cent'])->formateado(),
                    (int) $f['operaciones'],
                    $mes->format('m/Y'),
                    Monto::deCentavos(UmbralesUIF::EFECTIVO_CENT)->formateado(),
                    UmbralesUIF::venceReporteMensual(
                        (int) $mes->format('Y'),
                        (int) $mes->format('n')
                    )->format('d/m/Y')
                ),
                'datos'         => ['mes' => $mes->format('Y-m')],
            ];
        }

        return $salida;
    }

    /**
     * Sobre US$25,000 por cualquier otro medio.
     *
     * Aquí entran las transferencias —internas e interbancarias— y lo
     * que pasa por caja sin ser efectivo.
     *
     * @return array<int,array<string,mixed>>
     */
    private function otrosMedios(string $dia): array
    {
        $filas = BaseDatos::todas(
            'SELECT c.usuario_id,
                    SUM(ABS(m.importe_cent)) AS monto_cent,
                    COUNT(*) AS operaciones
               FROM movimientos m
               JOIN asientos a ON a.id = m.asiento_id
               JOIN cuentas  c ON c.id = m.cuenta_id
              WHERE c.es_tecnica = 0
                AND a.tipo IN ("transferencia", "interbancaria")
                AND DATE(a.creado_en) = ?
              GROUP BY c.usuario_id
             HAVING SUM(ABS(m.importe_cent)) > ?',
            [$dia, UmbralesUIF::OTROS_MEDIOS_CENT]
        );

        $salida = [];

        foreach ($filas as $f) {
            $salida[] = [
                'usuario_id'    => (int) $f['usuario_id'],
                'periodo_desde' => $dia,
                'periodo_hasta' => $dia,
                'monto_cent'    => (int) $f['monto_cent'],
                'operaciones'   => (int) $f['operaciones'],
                'severidad'     => 'media',
                'detalle'       => sprintf(
                    'Movió %s en %d transferencia(s) durante el día, por encima del umbral '
                    . 'de %s que la LCLDA fija para operaciones por medios distintos del efectivo.',
                    Monto::deCentavos((int) $f['monto_cent'])->formateado(),
                    (int) $f['operaciones'],
                    Monto::deCentavos(UmbralesUIF::OTROS_MEDIOS_CENT)->formateado()
                ),
                'datos'         => [],
            ];
        }

        return $salida;
    }

    /**
     * Fraccionamiento: varias operaciones justo por debajo del umbral.
     *
     * Es la señal más clara que existe, porque no ocurre por
     * casualidad. Nadie deposita $9,400 dos veces en la misma semana
     * sin un motivo, y el motivo suele ser el umbral.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fraccionamiento(string $dia): array
    {
        $desde = (new \DateTimeImmutable($dia))
            ->modify('-' . (UmbralesUIF::FRACCIONAMIENTO_DIAS - 1) . ' days')
            ->format('Y-m-d');

        $filas = BaseDatos::todas(
            'SELECT cm.cliente_id AS usuario_id,
                    COUNT(*) AS operaciones,
                    SUM(ABS(cm.importe_cent)) AS monto_cent,
                    MIN(ABS(cm.importe_cent)) AS minimo,
                    MAX(ABS(cm.importe_cent)) AS maximo
               FROM caja_movimientos cm
              WHERE cm.medio = "efectivo"
                AND cm.cliente_id IS NOT NULL
                AND DATE(cm.creado_en) BETWEEN ? AND ?
                AND ABS(cm.importe_cent) >= ?
                AND ABS(cm.importe_cent) <= ?
              GROUP BY cm.cliente_id
             HAVING COUNT(*) >= ?',
            [
                $desde,
                $dia,
                UmbralesUIF::FRACCIONAMIENTO_DESDE_CENT,
                UmbralesUIF::EFECTIVO_CENT,
                UmbralesUIF::FRACCIONAMIENTO_OPS,
            ]
        );

        $salida = [];

        foreach ($filas as $f) {
            $salida[] = [
                'usuario_id'    => (int) $f['usuario_id'],
                'periodo_desde' => $desde,
                'periodo_hasta' => $dia,
                'monto_cent'    => (int) $f['monto_cent'],
                'operaciones'   => (int) $f['operaciones'],
                'severidad'     => 'alta',
                'detalle'       => sprintf(
                    '%d operaciones en efectivo de entre %s y %s en %d días, que suman %s. '
                    . 'Ninguna supera por sí sola el umbral de %s, pero el patrón es compatible '
                    . 'con un fraccionamiento deliberado para evitar el reporte.',
                    (int) $f['operaciones'],
                    Monto::deCentavos((int) $f['minimo'])->formateado(),
                    Monto::deCentavos((int) $f['maximo'])->formateado(),
                    UmbralesUIF::FRACCIONAMIENTO_DIAS,
                    Monto::deCentavos((int) $f['monto_cent'])->formateado(),
                    Monto::deCentavos(UmbralesUIF::EFECTIVO_CENT)->formateado()
                ),
                'datos'         => [],
            ];
        }

        return $salida;
    }

    /**
     * El cliente mueve mucho más de lo que declaró.
     *
     * Sin perfil declarado no hay alerta posible: «inusual» es lo que
     * se sale de lo esperado, y lo esperado hay que haberlo preguntado.
     * Los clientes sin perfil salen en el panel como pendientes de
     * debida diligencia, que es el problema de fondo.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fueraDePerfil(string $dia): array
    {
        $hoy = new \DateTimeImmutable($dia);

        $desde = $hoy->modify('first day of this month')->format('Y-m-d');

        $filas = BaseDatos::todas(
            'SELECT c.usuario_id,
                    SUM(ABS(m.importe_cent)) AS monto_cent,
                    COUNT(*) AS operaciones,
                    p.monto_mensual_esperado_cent AS esperado,
                    p.ingreso_mensual_cent
               FROM movimientos m
               JOIN asientos a ON a.id = m.asiento_id
               JOIN cuentas  c ON c.id = m.cuenta_id
               JOIN perfil_transaccional p ON p.usuario_id = c.usuario_id
              WHERE c.es_tecnica = 0
                AND DATE(a.creado_en) BETWEEN ? AND ?
                AND p.monto_mensual_esperado_cent > 0
              GROUP BY c.usuario_id, p.monto_mensual_esperado_cent, p.ingreso_mensual_cent
             HAVING SUM(ABS(m.importe_cent)) > GREATEST(?, p.monto_mensual_esperado_cent * ?)',
            [$desde, $dia, UmbralesUIF::PERFIL_PISO_CENT, UmbralesUIF::PERFIL_FACTOR]
        );

        $salida = [];

        foreach ($filas as $f) {
            $salida[] = [
                'usuario_id'    => (int) $f['usuario_id'],
                'periodo_desde' => $desde,
                'periodo_hasta' => $dia,
                'monto_cent'    => (int) $f['monto_cent'],
                'operaciones'   => (int) $f['operaciones'],
                'severidad'     => 'media',
                'detalle'       => sprintf(
                    'Movió %s en lo que va del mes, cuando declaró esperar %s mensuales '
                    . 'e ingresos de %s. Es %.1f veces lo declarado.',
                    Monto::deCentavos((int) $f['monto_cent'])->formateado(),
                    Monto::deCentavos((int) $f['esperado'])->formateado(),
                    Monto::deCentavos((int) $f['ingreso_mensual_cent'])->formateado(),
                    (int) $f['esperado'] > 0
                        ? (int) $f['monto_cent'] / (int) $f['esperado']
                        : 0
                ),
                'datos'         => ['esperado_cent' => (int) $f['esperado']],
            ];
        }

        return $salida;
    }

    /**
     * Una persona expuesta políticamente con movimiento alto.
     *
     * La ley exige debida diligencia intensificada sobre las PEP. Eso
     * no significa sospechar de ellas: significa mirar con más
     * atención, y un umbral más bajo es la forma concreta de hacerlo.
     *
     * @return array<int,array<string,mixed>>
     */
    private function pepMovimientoAlto(string $dia): array
    {
        $filas = BaseDatos::todas(
            'SELECT c.usuario_id,
                    SUM(ABS(m.importe_cent)) AS monto_cent,
                    COUNT(*) AS operaciones,
                    cc.pep, cc.pep_relacionado, cc.pep_cargo
               FROM movimientos m
               JOIN asientos a ON a.id = m.asiento_id
               JOIN cuentas  c ON c.id = m.cuenta_id
               JOIN cumplimiento_cliente cc ON cc.usuario_id = c.usuario_id
              WHERE c.es_tecnica = 0
                AND DATE(a.creado_en) = ?
                AND (cc.pep = 1 OR cc.pep_relacionado = 1)
              GROUP BY c.usuario_id, cc.pep, cc.pep_relacionado, cc.pep_cargo
             HAVING SUM(ABS(m.importe_cent)) > ?',
            [$dia, UmbralesUIF::PEP_MOVIMIENTO_CENT]
        );

        $salida = [];

        foreach ($filas as $f) {
            $salida[] = [
                'usuario_id'    => (int) $f['usuario_id'],
                'periodo_desde' => $dia,
                'periodo_hasta' => $dia,
                'monto_cent'    => (int) $f['monto_cent'],
                'operaciones'   => (int) $f['operaciones'],
                'severidad'     => 'media',
                'detalle'       => sprintf(
                    '%s con movimiento de %s en el día%s. La ley exige debida diligencia '
                    . 'intensificada sobre las personas expuestas políticamente.',
                    (int) $f['pep'] === 1
                        ? 'Persona expuesta políticamente'
                        : 'Relacionado con persona expuesta políticamente',
                    Monto::deCentavos((int) $f['monto_cent'])->formateado(),
                    $f['pep_cargo'] !== '' ? ' (' . $f['pep_cargo'] . ')' : ''
                ),
                'datos'         => [],
            ];
        }

        return $salida;
    }

    /**
     * Cuenta recién abierta con movimiento fuerte.
     *
     * @return array<int,array<string,mixed>>
     */
    private function cuentaNuevaActiva(string $dia): array
    {
        $filas = BaseDatos::todas(
            'SELECT c.usuario_id,
                    SUM(ABS(m.importe_cent)) AS monto_cent,
                    COUNT(*) AS operaciones,
                    MIN(c.apertura_en) AS abierta_en
               FROM movimientos m
               JOIN asientos a ON a.id = m.asiento_id
               JOIN cuentas  c ON c.id = m.cuenta_id
              WHERE c.es_tecnica = 0
                AND DATE(a.creado_en) = ?
                AND c.apertura_en >= DATE_SUB(?, INTERVAL ? DAY)
              GROUP BY c.usuario_id
             HAVING SUM(ABS(m.importe_cent)) > ?',
            [$dia, $dia, UmbralesUIF::CUENTA_NUEVA_DIAS, UmbralesUIF::CUENTA_NUEVA_CENT]
        );

        $salida = [];

        foreach ($filas as $f) {
            $dias = (int) ((new \DateTimeImmutable($dia))
                ->diff(new \DateTimeImmutable((string) $f['abierta_en']))->days);

            $salida[] = [
                'usuario_id'    => (int) $f['usuario_id'],
                'periodo_desde' => $dia,
                'periodo_hasta' => $dia,
                'monto_cent'    => (int) $f['monto_cent'],
                'operaciones'   => (int) $f['operaciones'],
                'severidad'     => 'baja',
                'detalle'       => sprintf(
                    'Cuenta abierta hace %d día(s) con movimiento de %s en el día. '
                    . 'El banco todavía no conoce el comportamiento habitual de este cliente.',
                    $dias,
                    Monto::deCentavos((int) $f['monto_cent'])->formateado()
                ),
                'datos'         => ['dias_desde_apertura' => $dias],
            ];
        }

        return $salida;
    }

    /**
     * Clientes que coinciden con una lista restrictiva.
     *
     * El cotejo se hace en PHP y no en SQL a propósito: comparar
     * nombres exige normalizar tildes y aceptar el orden cambiado, y
     * eso en SQL sale ilegible y dependiente del cotejamiento de la
     * base.
     *
     * Una coincidencia NO es una acusación: los nombres se repiten y un
     * homónimo no es un criminal. Por eso produce una alerta para que
     * una persona decida, y nunca un bloqueo automático.
     *
     * @return array<int,array<string,mixed>>
     */
    private function listaRestrictiva(): array
    {
        $lista = BaseDatos::todas(
            'SELECT id, lista, nombre, nombre_normalizado, documento, programa
               FROM listas_restrictivas WHERE vigente = 1'
        );

        if ($lista === []) {
            return [];
        }

        /* Solo los que no se han resuelto ya: un homónimo descartado no
           tiene que volver a salir cada noche. */
        $clientes = BaseDatos::todas(
            'SELECT u.id, CONCAT(u.nombres, " ", u.apellidos) AS nombre,
                    u.documento_identidad
               FROM usuarios u
               LEFT JOIN cumplimiento_cliente cc ON cc.usuario_id = u.id
              WHERE u.estado = "activo"
                AND (cc.lista_resuelta_en IS NULL)'
        );

        $salida = [];

        foreach ($clientes as $c) {
            foreach ($lista as $l) {
                $porDocumento = $c['documento_identidad'] !== ''
                    && $l['documento'] !== ''
                    && $c['documento_identidad'] === $l['documento'];

                $porNombre = UmbralesUIF::nombresCoinciden(
                    (string) $c['nombre'],
                    (string) $l['nombre']
                );

                if (!$porDocumento && !$porNombre) {
                    continue;
                }

                $salida[] = [
                    'usuario_id'    => (int) $c['id'],
                    'periodo_desde' => date('Y-m-d'),
                    'periodo_hasta' => date('Y-m-d'),
                    'monto_cent'    => 0,
                    'operaciones'   => 0,
                    'severidad'     => 'alta',
                    'detalle'       => sprintf(
                        'Coincide con «%s» de la lista %s%s. Coincidencia por %s. '
                        . 'Verifique si es la misma persona antes de tomar cualquier medida: '
                        . 'los nombres se repiten.',
                        $l['nombre'],
                        strtoupper((string) $l['lista']),
                        $l['programa'] !== '' ? ' (' . $l['programa'] . ')' : '',
                        $porDocumento ? 'documento de identidad' : 'nombre'
                    ),
                    'datos'         => [
                        'lista_id' => (int) $l['id'],
                        'criterio' => $porDocumento ? 'documento' : 'nombre',
                    ],
                ];

                break; // Una alerta por cliente basta.
            }
        }

        return $salida;
    }

    // =================================================================
    // Levantar y resolver
    // =================================================================

    /**
     * Guarda una alerta, si no estaba ya.
     *
     * @param array<string,mixed> $h
     */
    private function levantar(string $tipo, array $h): bool
    {
        /* El UNIQUE de la tabla es quien de verdad impide el duplicado;
           esta consulta solo evita gastar un folio en vano. */
        $yaEsta = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM alertas_cumplimiento
              WHERE usuario_id = ? AND tipo = ? AND periodo_desde = ? AND periodo_hasta = ?',
            [$h['usuario_id'], $tipo, $h['periodo_desde'], $h['periodo_hasta']]
        );

        if ($yaEsta > 0) {
            return false;
        }

        $folio = Folio::unico('ALC', 'alertas_cumplimiento', 'folio', 'ymd', 5);

        BaseDatos::ejecutar(
            'INSERT INTO alertas_cumplimiento
               (folio, usuario_id, tipo, severidad, periodo_desde, periodo_hasta,
                monto_cent, operaciones, detalle, datos_json, analisis_vence)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $folio,
                (int) $h['usuario_id'],
                $tipo,
                (string) $h['severidad'],
                (string) $h['periodo_desde'],
                (string) $h['periodo_hasta'],
                (int) $h['monto_cent'],
                (int) $h['operaciones'],
                (string) $h['detalle'],
                json_encode($h['datos'] ?? [], JSON_UNESCAPED_UNICODE),
                UmbralesUIF::venceAnalisis()->format('Y-m-d'),
            ]
        );

        /* La bitácora, sin el actor: esto lo levanta el sistema, no una
           persona. Poner ahí a un administrador diría que lo decidió. */
        Auditoria::registrar(
            'cumplimiento.alerta',
            Auditoria::EXITO,
            null,
            'alerta',
            $folio,
            ['tipo' => $tipo, 'severidad' => $h['severidad']]
        );

        return true;
    }

    /**
     * El analista descarta una alerta.
     *
     * Exige una razón escrita, y no por burocracia: una alerta
     * descartada sin motivo es indistinguible de una alerta que nadie
     * miró, y la diferencia entre las dos cosas es justamente lo que un
     * examen de la Superintendencia viene a comprobar.
     */
    public function descartar(string $folio, int $analistaId, string $razon): void
    {
        $razon = trim($razon);

        if (mb_strlen($razon) < 15) {
            throw new AlertaInvalida(
                'Escriba por qué se descarta. Una alerta cerrada sin explicación '
                . 'no se distingue de una que nadie miró.'
            );
        }

        $a = $this->exigirAlerta($folio);

        BaseDatos::ejecutar(
            'UPDATE alertas_cumplimiento
                SET estado = "descartada", analista_id = ?, resolucion = ?, resuelta_en = NOW()
              WHERE id = ?',
            [$analistaId, $razon, (int) $a['id']]
        );

        Auditoria::registrar(
            'cumplimiento.descartada',
            Auditoria::EXITO,
            $analistaId,
            'alerta',
            $folio,
            ['tipo' => $a['tipo']]
        );
    }

    /**
     * El analista escala la alerta a reporte.
     *
     * Crea el reporte en BORRADOR, nunca enviado: el envío es un acto
     * fuera de este sistema y decir que se envió sin haberlo hecho
     * sería dar por cumplida una obligación que sigue pendiente.
     *
     * @return array<string,mixed>
     */
    public function escalar(string $folio, int $analistaId, string $relato, string $tipo = 'ros'): array
    {
        if (!in_array($tipo, ['rte', 'ros', 'rtos'], true)) {
            throw new AlertaInvalida('Tipo de reporte no reconocido.');
        }

        $relato = trim($relato);

        if (mb_strlen($relato) < 40) {
            throw new AlertaInvalida(
                'El relato de los hechos tiene que describir qué se observó. '
                . 'Es lo que leerá la UIF, y es lo que sostiene el reporte.'
            );
        }

        $a = $this->exigirAlerta($folio);

        return BaseDatos::enTransaccion(function () use ($a, $analistaId, $relato, $tipo, $folio): array {
            $folioRep = Folio::unico(strtoupper($tipo), 'reportes_uif', 'folio', 'ymd', 5);

            /* El plazo: cinco días hábiles desde hoy, que es cuando se
               concluye que la operación es irregular. */
            $vence = UmbralesUIF::venceReporte(new \DateTimeImmutable())->format('Y-m-d');

            BaseDatos::ejecutar(
                'INSERT INTO reportes_uif
                   (folio, tipo, usuario_id, alerta_id, periodo_desde, periodo_hasta,
                    monto_cent, operaciones, relato, datos_json, vence_en,
                    estado, confidencial, creado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "borrador", ?, ?)',
                [
                    $folioRep,
                    $tipo,
                    (int) $a['usuario_id'],
                    (int) $a['id'],
                    (string) $a['periodo_desde'],
                    (string) $a['periodo_hasta'],
                    (int) $a['monto_cent'],
                    (int) $a['operaciones'],
                    $relato,
                    (string) ($a['datos_json'] ?? '[]'),
                    $vence,
                    /* Un ROS no se le comunica al cliente: la ley lo
                       prohíbe expresamente. Un RTE no tiene ese
                       carácter, porque no implica sospecha de nada. */
                    $tipo === 'rte' ? 0 : 1,
                    $analistaId,
                ]
            );

            BaseDatos::ejecutar(
                'UPDATE alertas_cumplimiento
                    SET estado = "escalada", analista_id = ?, resuelta_en = NOW()
                  WHERE id = ?',
                [$analistaId, (int) $a['id']]
            );

            Auditoria::registrar(
                'cumplimiento.escalada',
                Auditoria::EXITO,
                $analistaId,
                'reporte_uif',
                $folioRep,
                ['alerta' => $folio, 'tipo' => $tipo]
            );

            return ['folio' => $folioRep, 'tipo' => $tipo, 'vence_en' => $vence];
        });
    }

    /**
     * Prorroga el análisis.
     *
     * El artículo 9-A lo permite UNA sola vez, por otros quince días
     * hábiles, previa solicitud a la UIF. El límite está en la base y
     * aquí: una prórroga que se pudiera repetir sería un plazo sin
     * plazo.
     */
    public function prorrogar(string $folio, int $analistaId): string
    {
        $a = $this->exigirAlerta($folio);

        if ((int) $a['prorrogada'] === 1) {
            throw new AlertaInvalida(
                'Esta alerta ya se prorrogó una vez. La ley permite una sola prórroga.'
            );
        }

        $nueva = UmbralesUIF::venceAnalisis(
            new \DateTimeImmutable((string) $a['analisis_vence'])
        )->format('Y-m-d');

        BaseDatos::ejecutar(
            'UPDATE alertas_cumplimiento
                SET analisis_vence = ?, prorrogada = 1, analista_id = ?, estado = "en_analisis"
              WHERE id = ?',
            [$nueva, $analistaId, (int) $a['id']]
        );

        Auditoria::registrar(
            'cumplimiento.prorrogada',
            Auditoria::EXITO,
            $analistaId,
            'alerta',
            $folio,
            ['vence' => $nueva]
        );

        return $nueva;
    }

    /** Deja constancia de que el reporte salió de verdad. */
    public function marcarEnviado(string $folioReporte, int $usuarioId, string $acuse): void
    {
        $acuse = trim($acuse);

        if ($acuse === '') {
            throw new AlertaInvalida(
                'Anote el acuse que devolvió la UIF: es la prueba de que el reporte se presentó.'
            );
        }

        $r = BaseDatos::unaFila('SELECT * FROM reportes_uif WHERE folio = ?', [$folioReporte]);

        if ($r === null) {
            throw new AlertaInvalida('No existe ese reporte.');
        }

        BaseDatos::ejecutar(
            'UPDATE reportes_uif
                SET estado = "enviado", enviado_en = NOW(), enviado_por = ?, acuse = ?
              WHERE id = ?',
            [$usuarioId, mb_substr($acuse, 0, 80), (int) $r['id']]
        );

        BaseDatos::ejecutar(
            'UPDATE alertas_cumplimiento SET estado = "reportada" WHERE id = ?',
            [(int) $r['alerta_id']]
        );

        Auditoria::registrar(
            'cumplimiento.reportado',
            Auditoria::EXITO,
            $usuarioId,
            'reporte_uif',
            $folioReporte,
            ['acuse' => $acuse]
        );
    }

    // =================================================================
    // Consulta
    // =================================================================

    /** @return array<int,array<string,mixed>> */
    public function alertasAbiertas(int $limite = 200): array
    {
        return BaseDatos::todas(
            'SELECT a.*, CONCAT(u.nombres, " ", u.apellidos) AS cliente,
                    u.documento_identidad,
                    cc.nivel_riesgo, cc.pep,
                    DATEDIFF(a.analisis_vence, CURDATE()) AS dias_restantes
               FROM alertas_cumplimiento a
               JOIN usuarios u ON u.id = a.usuario_id
               LEFT JOIN cumplimiento_cliente cc ON cc.usuario_id = a.usuario_id
              WHERE a.estado IN ("nueva", "en_analisis")
              ORDER BY FIELD(a.severidad, "alta", "media", "baja"),
                       a.analisis_vence, a.creada_en
              LIMIT ' . max(1, min(500, $limite))
        );
    }

    /** @return array<string,mixed> */
    public function panorama(): array
    {
        $f = BaseDatos::unaFila(
            'SELECT
               SUM(estado IN ("nueva","en_analisis")) AS abiertas,
               SUM(estado IN ("nueva","en_analisis") AND severidad = "alta") AS altas,
               SUM(estado IN ("nueva","en_analisis") AND analisis_vence < CURDATE()) AS vencidas,
               SUM(estado = "descartada") AS descartadas,
               SUM(estado IN ("escalada","reportada")) AS escaladas
             FROM alertas_cumplimiento'
        ) ?? [];

        $r = BaseDatos::unaFila(
            'SELECT
               SUM(estado = "borrador") AS borradores,
               SUM(estado = "borrador" AND vence_en < CURDATE()) AS borradores_vencidos,
               SUM(estado = "enviado") AS enviados
             FROM reportes_uif'
        ) ?? [];

        $sinPerfil = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM usuarios u
              JOIN cuentas c ON c.usuario_id = u.id AND c.es_tecnica = 0
              LEFT JOIN perfil_transaccional p ON p.usuario_id = u.id
             WHERE u.estado = "activo" AND p.id IS NULL'
        );

        $dilVencida = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM cumplimiento_cliente
              WHERE diligencia_proxima IS NOT NULL AND diligencia_proxima < CURDATE()'
        );

        return [
            'abiertas'            => (int) ($f['abiertas'] ?? 0),
            'altas'               => (int) ($f['altas'] ?? 0),
            'vencidas'            => (int) ($f['vencidas'] ?? 0),
            'descartadas'         => (int) ($f['descartadas'] ?? 0),
            'escaladas'           => (int) ($f['escaladas'] ?? 0),
            'borradores'          => (int) ($r['borradores'] ?? 0),
            'borradores_vencidos' => (int) ($r['borradores_vencidos'] ?? 0),
            'enviados'            => (int) ($r['enviados'] ?? 0),
            'sin_perfil'          => $sinPerfil,
            'diligencia_vencida'  => $dilVencida,
        ];
    }

    /** @return array<string,mixed> */
    private function exigirAlerta(string $folio): array
    {
        $a = BaseDatos::unaFila(
            'SELECT * FROM alertas_cumplimiento WHERE folio = ?',
            [$folio]
        );

        if ($a === null) {
            throw new AlertaInvalida('No existe la alerta ' . $folio . '.');
        }

        if (in_array($a['estado'], ['descartada', 'escalada', 'reportada'], true)) {
            throw new AlertaInvalida('Esa alerta ya está resuelta (' . $a['estado'] . ').');
        }

        return $a;
    }
}
