<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Riesgo\MotorDecision;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * Reúne el expediente de un cliente y lo pasa por el motor de decisión.
 *
 * El motor no sabe de base de datos ni de tablas: recibe hechos y
 * devuelve una decisión. Esa separación es deliberada —permite probar
 * la regla con casos escritos a mano y recalibrarla sin tocar SQL—, y
 * esta clase es la que hace de puente.
 *
 * Cada evaluación se guarda entera: los hechos de entrada, el puntaje,
 * la banda y los códigos de razón. Una decisión de crédito tiene que
 * poder reconstruirse años después con los datos de entonces, no con
 * los de hoy.
 */
final class ServicioRiesgo
{
    private MotorDecision $motor;

    public function __construct(?MotorDecision $motor = null)
    {
        $this->motor = $motor ?? new MotorDecision();
    }

    /**
     * Evalúa a un cliente para una operación concreta.
     *
     * @param Monto|null $cuota La cuota que tendría que pagar. Sin ella
     *        la capacidad se evalúa a medias, que es lo correcto cuando
     *        todavía no hay una propuesta sobre la mesa.
     *
     * @return array<string,mixed> Lo que devuelve el motor, más los
     *         hechos con los que se decidió.
     */
    public function evaluar(int $usuarioId, ?Monto $cuota = null): array
    {
        $hechos   = $this->expediente($usuarioId, $cuota);
        $decision = $this->motor->evaluar($hechos);

        $decision['hechos'] = $this->paraGuardar($hechos);

        return $decision;
    }

    /**
     * Deja constancia de la decisión.
     *
     * Se guarda SIEMPRE, se apruebe o no. Un expediente sin la
     * evaluación que lo rechazó no permite responder a la pregunta que
     * siempre acaba llegando: «¿por qué a mí me dijeron que no?».
     *
     * @param array<string,mixed> $decision
     */
    public function registrar(
        int $usuarioId,
        array $decision,
        string $producto,
        ?int $solicitudId = null
    ): int {
        BaseDatos::ejecutar(
            'INSERT INTO evaluaciones_riesgo
               (uuid, usuario_id, solicitud_id, producto, version_modelo,
                puntaje, banda, aprueba, razones_json, vetos_json, hechos_json,
                narrativa)
             VALUES (UUID(),?,?,?,?,?,?,?,?,?,?,?)',
            [
                $usuarioId,
                $solicitudId,
                mb_substr($producto, 0, 30),
                (string) $decision['version'],
                (int) $decision['puntaje'],
                (string) $decision['banda'],
                !empty($decision['aprueba']) ? 1 : 0,
                json_encode($decision['razones'], JSON_UNESCAPED_UNICODE),
                json_encode($decision['vetos'], JSON_UNESCAPED_UNICODE),
                json_encode($decision['hechos'] ?? [], JSON_UNESCAPED_UNICODE),
                mb_substr((string) $decision['narrativa'], 0, 1500),
            ]
        );

        return BaseDatos::ultimoId();
    }

    /**
     * Lo que puede ofrecérsele de verdad, a partir de lo que pidió.
     *
     * @param array<string,mixed> $decision
     * @return array{monto:Monto, plazo:int, tasa_bp:int, ajustado:bool}
     */
    public function condicionar(
        array $decision,
        Monto $montoPedido,
        int $plazoPedido,
        int $tasaBp
    ): array {
        return $this->motor->condicionarOferta($decision, $montoPedido, $plazoPedido, $tasaBp);
    }

    /** La última evaluación de un cliente, si la hay. */
    public function ultima(int $usuarioId): ?array
    {
        $r = BaseDatos::unaFila(
            'SELECT * FROM evaluaciones_riesgo
              WHERE usuario_id = ? ORDER BY id DESC LIMIT 1',
            [$usuarioId]
        );

        if ($r === null) {
            return null;
        }

        foreach (['razones', 'vetos', 'hechos'] as $k) {
            $r[$k] = json_decode((string) ($r[$k . '_json'] ?? '[]'), true) ?: [];
            unset($r[$k . '_json']);
        }

        return $r;
    }

    // =================================================================
    // EL EXPEDIENTE
    // =================================================================

    /**
     * Todo lo que se sabe del cliente, reunido.
     *
     * @return array<string,mixed>
     */
    private function expediente(int $usuarioId, ?Monto $cuota): array
    {
        $h = ['cuota' => $cuota];

        /* Lo que dicen las centrales de riesgo.
         *
         * Vale tanto la consulta con historial como la que volvió sin
         * él: «sin historial» es un resultado, no un fallo, y el motor
         * lo trata distinto de «no se pudo consultar». Lo que no sirve
         * es una consulta que terminó en error o sin autorización. */
        $buro = BaseDatos::unaFila(
            'SELECT score, categoria, moras_12m, peor_mora_dias, resultado
               FROM buro_consultas
              WHERE usuario_id = ? AND resultado IN ("exito", "sin_historial")
              ORDER BY id DESC LIMIT 1',
            [$usuarioId]
        );

        if ($buro === null) {
            $h['buro_score'] = 0;
            $h['buro_sin_historial'] = false;
        } else {
            $h['buro_score']     = (int) ($buro['score'] ?? 0);
            $h['buro_categoria'] = (string) ($buro['categoria'] ?? '');
            $h['buro_moras_12m'] = (int) ($buro['moras_12m'] ?? 0);
            $h['buro_peor_mora'] = (int) ($buro['peor_mora_dias'] ?? 0);
            $h['buro_sin_historial'] =
                (string) ($buro['resultado'] ?? '') === 'sin_historial'
                || (string) ($buro['categoria'] ?? '') === 'sin historial';
        }

        // --- Cómo ha pagado AQUÍ ----------------------------------------
        $interno = BaseDatos::unaFila(
            'SELECT
                COUNT(CASE WHEN q.estado = "pagada" THEN 1 END) AS pagadas,
                COALESCE(MAX(q.dias_mora), 0) AS atraso_maximo,
                COUNT(CASE WHEN q.estado NOT IN ("pagada","condonada")
                            AND q.fecha_vencimiento < CURDATE() THEN 1 END) AS vencidas
               FROM cuotas q
               JOIN creditos c ON c.id = q.credito_id
              WHERE c.usuario_id = ?',
            [$usuarioId]
        ) ?? [];

        $h['cuotas_pagadas']     = (int) ($interno['pagadas'] ?? 0);
        $h['atraso_maximo_dias'] = (int) ($interno['atraso_maximo'] ?? 0);
        $h['cuotas_vencidas']    = (int) ($interno['vencidas'] ?? 0);

        $h['creditos_cerrados'] = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM creditos WHERE usuario_id = ? AND estado = "cancelado"',
            [$usuarioId]
        );

        $h['tiene_cobranza'] = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM cobranza_casos cb
               JOIN creditos c ON c.id = cb.credito_id
              WHERE c.usuario_id = ? AND cb.cerrado_en IS NULL
                AND cb.etapa NOT IN ("recuperado","castigado")',
            [$usuarioId]
        ) > 0;

        $h['tiene_castigado'] = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM creditos
              WHERE usuario_id = ? AND estado = "castigado"',
            [$usuarioId]
        ) > 0;

        // --- Capacidad --------------------------------------------------
        $h['ingreso'] = Monto::deCentavos((int) (BaseDatos::unValor(
            'SELECT COALESCE(ingreso_mensual_cent,0) FROM detalle_usuarios WHERE usuario_id = ?',
            [$usuarioId]
        ) ?? 0));

        $h['compromisos'] = $this->compromisos($usuarioId);

        // --- Vinculación -------------------------------------------------
        $h['meses_cliente'] = (int) (BaseDatos::unValor(
            'SELECT TIMESTAMPDIFF(MONTH, creado_en, NOW()) FROM usuarios WHERE id = ?',
            [$usuarioId]
        ) ?? 0);

        $h['productos_activos'] =
            (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM cuentas WHERE usuario_id = ? AND estado = "activa"
                   AND es_tecnica = 0', [$usuarioId]
            )
            + (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM tarjetas WHERE usuario_id = ? AND estado = "activa"',
                [$usuarioId]
            )
            + (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM creditos WHERE usuario_id = ? AND estado = "vigente"',
                [$usuarioId]
            );

        // --- Uso de las líneas -------------------------------------------
        $lineas = BaseDatos::unaFila(
            'SELECT COALESCE(SUM(limite_cent),0) AS limite,
                    COALESCE(SUM(saldo_usado_cent),0) AS usado
               FROM tarjetas
              WHERE usuario_id = ? AND tipo = "credito" AND estado = "activa"',
            [$usuarioId]
        ) ?? [];

        $limite = (int) ($lineas['limite'] ?? 0);

        $h['uso_linea_pct'] = $limite > 0
            ? min(100.0, ((int) $lineas['usado'] / $limite) * 100)
            : 0.0;

        return $h;
    }

    /**
     * Lo que ya tiene comprometido cada mes.
     *
     * Créditos vigentes más el mínimo de sus tarjetas. Es el mismo
     * criterio del simulador y del otorgamiento: tener dos cuentas
     * distintas de lo mismo es como se acaba aprobando lo que otra
     * pantalla negaba.
     */
    private function compromisos(int $usuarioId): Monto
    {
        $cuotas = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(q.total_cent),0)
               FROM cuotas q
               JOIN creditos c ON c.id = q.credito_id
              WHERE c.usuario_id = ? AND c.estado = "vigente"
                AND q.estado NOT IN ("pagada","condonada")
                AND q.fecha_vencimiento >= CURDATE()
                AND q.fecha_vencimiento < DATE_ADD(CURDATE(), INTERVAL 1 MONTH)',
            [$usuarioId]
        );

        $tarjetas = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(GREATEST(saldo_usado_cent * 0.05, 0)),0)
               FROM tarjetas
              WHERE usuario_id = ? AND tipo = "credito" AND estado = "activa"
                AND saldo_usado_cent > 0',
            [$usuarioId]
        );

        return Monto::deCentavos($cuotas + $tarjetas);
    }

    /**
     * Los hechos en forma guardable.
     *
     * Los `Monto` se vuelven centavos: un objeto serializado en una
     * columna deja de poder consultarse, y lo que se guarda aquí tiene
     * que poder leerse dentro de cinco años sin cargar esta clase.
     *
     * @param array<string,mixed> $h
     * @return array<string,mixed>
     */
    private function paraGuardar(array $h): array
    {
        foreach ($h as $k => $v) {
            if ($v instanceof Monto) {
                $h[$k] = $v->centavos;
            }
        }

        return $h;
    }
}
