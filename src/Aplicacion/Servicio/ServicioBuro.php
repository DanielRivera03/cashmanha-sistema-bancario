<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Buro\BuroError;
use CashMan\Infraestructura\Buro\BuroSimulado;
use CashMan\Infraestructura\Buro\ProveedorBuro;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Consulta al buró de crédito.
 *
 * El orden de las comprobaciones no es casual:
 *
 *   1. Permiso del empleado. Consultar el historial de alguien no es
 *      una operación de rutina.
 *   2. Autorización del cliente, vigente y para ESE buró. Es requisito
 *      legal, no una formalidad: sin ella, la consulta no sale de aquí.
 *   3. Consulta vigente reciente. Los burós cobran por consulta y el
 *      historial no cambia de un día para otro; repetirla sin motivo es
 *      gastar dinero y dejar una huella de más en el expediente del
 *      cliente.
 *   4. Solo entonces se pregunta.
 *
 * Todo queda registrado: quién consultó, cuándo, con qué autorización y
 * qué devolvió. El cliente tiene derecho a saberlo.
 */
final class ServicioBuro
{
    /** Registra la autorización firmada por el cliente. */
    public function autorizar(
        int $usuarioId,
        string $buro,
        ?string $venceEn = null,
        string $documento = ''
    ): int {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('buro.autorizar');

        $buro = trim($buro);
        if ($buro === '') {
            throw new \InvalidArgumentException(
                'Indique qué buró autorizó el cliente: la autorización debe nombrarlo.'
            );
        }

        BaseDatos::ejecutar(
            'INSERT INTO buro_autorizaciones
               (usuario_id, buro, vence_en, documento, ip, registrada_por)
             VALUES (?,?,?,?,?,?)',
            [
                $usuarioId, $buro,
                preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $venceEn) === 1 ? $venceEn : null,
                mb_substr(trim($documento), 0, 255) ?: null,
                ClienteIp::binaria(),
                $actorId,
            ]
        );

        $id = BaseDatos::ultimoId();

        Auditoria::registrar('buro.autorizacion_registrada', Auditoria::EXITO, $actorId,
            'usuario', (string) $usuarioId, ['buro' => $buro, 'autorizacion' => $id]);

        return $id;
    }

    /**
     * Consulta el historial de un cliente.
     *
     * @param bool $forzar Repite la consulta aunque haya una vigente.
     * @return array<string,mixed>
     */
    public function consultar(int $usuarioId, ?int $solicitudId = null, bool $forzar = false): array
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('buro.consultar');

        $proveedor = $this->proveedor();
        $buro      = $proveedor->nombre();

        $cliente = BaseDatos::unaFila(
            'SELECT id, dui, CONCAT(nombres," ",apellidos) AS nombre FROM usuarios WHERE id = ?',
            [$usuarioId]
        );

        if ($cliente === null) {
            throw new \RuntimeException('Cliente no encontrado.');
        }

        // --- 2. Autorización vigente para este buró ---------------------
        $aut = BaseDatos::unaFila(
            'SELECT id FROM buro_autorizaciones
              WHERE usuario_id = ? AND buro = ? AND revocada_en IS NULL
                AND (vence_en IS NULL OR vence_en >= CURDATE())
              ORDER BY id DESC LIMIT 1',
            [$usuarioId, $buro]
        );

        if ($aut === null) {
            $this->registrar($usuarioId, $solicitudId, $buro, null, null,
                'sin_autorizacion',
                'El cliente no tiene autorización vigente para consultar ' . $buro . '.',
                $actorId);

            throw new BuroError(
                'No hay autorización vigente del cliente para consultar ' . $buro . '. '
                . 'La ley exige su consentimiento por escrito, nombrando al buró.'
            );
        }

        // --- 3. ¿Ya hay una consulta vigente? ---------------------------
        $vigencia = max(0, Parametros::entero('buro.vigencia_dias', 30));

        if (!$forzar && $vigencia > 0) {
            $previa = BaseDatos::unaFila(
                "SELECT * FROM buro_consultas
                  WHERE usuario_id = ? AND resultado IN ('exito','sin_historial')
                    AND consultada_en >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  ORDER BY id DESC LIMIT 1",
                [$usuarioId, $vigencia]
            );

            if ($previa !== null) {
                return $this->comoResultado($previa, true);
            }
        }

        // --- 4. Consulta -----------------------------------------------
        try {
            $r = $proveedor->consultar((string) $cliente['dui'], (string) $cliente['nombre']);
        } catch (BuroError $e) {
            $this->registrar($usuarioId, $solicitudId, $buro, (int) $aut['id'], null,
                'error', $e->getMessage(), $actorId);

            throw $e;
        }

        $id = $this->registrar(
            $usuarioId, $solicitudId, $buro, (int) $aut['id'], $r,
            $r['sin_historial'] ? 'sin_historial' : 'exito', null, $actorId
        );

        $fila = BaseDatos::unaFila('SELECT * FROM buro_consultas WHERE id = ?', [$id]);

        return $this->comoResultado($fila ?? [], false);
    }

    /**
     * Última consulta vigente, sin consultar de nuevo.
     *
     * Para las pantallas que solo necesitan enseñar lo que ya se sabe.
     *
     * @return array<string,mixed>|null
     */
    public function ultima(int $usuarioId): ?array
    {
        if (!Autorizacion::puede('buro.ver')) {
            return null;
        }

        $vigencia = max(1, Parametros::entero('buro.vigencia_dias', 30));

        $fila = BaseDatos::unaFila(
            "SELECT * FROM buro_consultas
              WHERE usuario_id = ? AND resultado IN ('exito','sin_historial')
                AND consultada_en >= DATE_SUB(NOW(), INTERVAL ? DAY)
              ORDER BY id DESC LIMIT 1",
            [$usuarioId, $vigencia]
        );

        return $fila === null ? null : $this->comoResultado($fila, true);
    }

    /** ¿El cliente tiene autorización vigente para el buró configurado? */
    public function tieneAutorizacion(int $usuarioId): bool
    {
        $buro = $this->proveedor()->nombre();

        return (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM buro_autorizaciones
              WHERE usuario_id = ? AND buro = ? AND revocada_en IS NULL
                AND (vence_en IS NULL OR vence_en >= CURDATE())',
            [$usuarioId, $buro]
        ) > 0;
    }

    // -----------------------------------------------------------------

    /**
     * El proveedor configurado.
     *
     * Solo existe el simulado. Los burós reales de El Salvador exigen
     * contrato y domiciliación en el país, así que su implementación se
     * escribirá cuando ese contrato exista; configurar su nombre sin
     * tenerla daría una falsa sensación de estar consultando algo.
     */
    private function proveedor(): ProveedorBuro
    {
        $elegido = Parametros::obtener('buro.proveedor', 'simulado');

        return match ($elegido) {
            'simulado' => new BuroSimulado(),
            default    => throw new BuroError(
                'El proveedor de buró "' . $elegido . '" no está implementado. '
                . 'Requiere contrato con el buró; mientras tanto, use "simulado".'
            ),
        };
    }

    /**
     * @param array<string,mixed>|null $r
     */
    private function registrar(
        int $usuarioId,
        ?int $solicitudId,
        string $buro,
        ?int $autorizacionId,
        ?array $r,
        string $resultado,
        ?string $error,
        int $actorId
    ): int {
        BaseDatos::ejecutar(
            'INSERT INTO buro_consultas
               (usuario_id, solicitud_id, buro, autorizacion_id, score, categoria,
                deuda_total_cent, cuota_mensual_cent, creditos_activos,
                moras_12m, peor_mora_dias, respuesta_json, resultado,
                detalle_error, consultada_por, ip)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $usuarioId, $solicitudId, $buro, $autorizacionId,
                $r['score'] ?? null,
                $r['categoria'] ?? null,
                $r['deuda_total_cent'] ?? null,
                $r['cuota_mensual_cent'] ?? null,
                $r['creditos_activos'] ?? null,
                $r['moras_12m'] ?? null,
                $r['peor_mora_dias'] ?? null,
                $r !== null ? json_encode($r['crudo'] ?? [], JSON_UNESCAPED_UNICODE) : null,
                $resultado,
                $error === null ? null : mb_substr($error, 0, 400),
                $actorId,
                ClienteIp::binaria(),
            ]
        );

        $id = BaseDatos::ultimoId();

        Auditoria::registrar('buro.consultado',
            $resultado === 'exito' || $resultado === 'sin_historial'
                ? Auditoria::EXITO : Auditoria::DENEGADO,
            $actorId, 'usuario', (string) $usuarioId,
            ['buro' => $buro, 'resultado' => $resultado, 'consulta' => $id]);

        return $id;
    }

    /**
     * Da forma a lo que las pantallas consumen.
     *
     * @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    private function comoResultado(array $f, bool $deCache): array
    {
        $score  = (int) ($f['score'] ?? 0);
        $minimo = Parametros::entero('buro.score_minimo', 550);

        return [
            'consulta_id'        => (int) ($f['id'] ?? 0),
            'buro'               => (string) ($f['buro'] ?? ''),
            'score'              => $score,
            'categoria'          => (string) ($f['categoria'] ?? ''),
            'deuda_total_cent'   => (int) ($f['deuda_total_cent'] ?? 0),
            'cuota_mensual_cent' => (int) ($f['cuota_mensual_cent'] ?? 0),
            'creditos_activos'   => (int) ($f['creditos_activos'] ?? 0),
            'moras_12m'          => (int) ($f['moras_12m'] ?? 0),
            'peor_mora_dias'     => (int) ($f['peor_mora_dias'] ?? 0),
            'sin_historial'      => (string) ($f['resultado'] ?? '') === 'sin_historial',
            'consultada_en'      => (string) ($f['consultada_en'] ?? ''),
            'de_consulta_previa' => $deCache,
            // Un puntaje bajo no rechaza por sí solo: marca para que lo
            // mire una persona. Automatizar el rechazo sería decidir sin
            // contexto sobre el crédito de alguien.
            'requiere_revision'  => $score > 0 && $score < $minimo,
            'score_minimo'       => $minimo,
            'simulado'           => (string) ($f['buro'] ?? '') === 'simulado',
        ];
    }
}
