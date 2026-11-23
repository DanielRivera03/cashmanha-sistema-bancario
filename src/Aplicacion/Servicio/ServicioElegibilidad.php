<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Credito\PeriodoDeCastigo;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Si un cliente puede optar hoy a un producto nuevo.
 *
 * LA PREGUNTA QUE RESPONDE
 *
 * Alguien que estuvo atrasado no vuelve a ser sujeto de crédito al
 * día siguiente de ponerse al corriente. La ley de historial de
 * crédito pone el techo —el dato negativo pesa hasta tres años, menos
 * si canceló la deuda entera— y `PeriodoDeCastigo` traduce la
 * gravedad del atraso en meses. Esto lo aplica sobre el historial de
 * una persona concreta.
 *
 * POR QUÉ SE CALCULA Y NO SE GUARDA
 *
 * Porque una columna con la fecha de habilitación habría que
 * actualizarla en cada pago, y el día que ese recálculo fallara, la
 * columna diría una cosa y los hechos otra. En este sistema ya pasó
 * con el cierre de expedientes: falló durante meses sin que nadie lo
 * notara. El historial, en cambio, no se desincroniza de sí mismo.
 *
 * DÓNDE SE USA
 *
 * En todas las puertas por las que entra un producto nuevo: la oferta
 * preaprobada, la solicitud de crédito y la emisión de tarjeta. Una
 * regla que solo se aplica en una de las tres puertas no es una
 * regla, es un adorno.
 *
 * LA EXCEPCIÓN
 *
 * Se puede autorizar que un inhabilitado opte igual. Lo decide una
 * persona con permiso, deja su nombre y su motivo, y caduca sola. Que
 * exista la excepción es lo que hace que la regla pueda ser estricta:
 * sin ella, la tentación sería aflojar la regla para todos.
 */
final class ServicioElegibilidad
{
    /**
     * Cómo está este cliente hoy.
     *
     * @return array{
     *     puede: bool,
     *     inhabilitado: bool,
     *     hasta: ?string,
     *     dias_restantes: int,
     *     meses: int,
     *     peor_atraso_dias: int,
     *     etapa: ?string,
     *     castigado: bool,
     *     al_dia_desde: ?string,
     *     motivo: string,
     *     excepcion: ?array<string,mixed>
     * }
     */
    public function de(int $usuarioId, string $producto = 'todo'): array
    {
        $h = $this->historial($usuarioId);

        $limpio = [
            'puede'            => true,
            'inhabilitado'     => false,
            'hasta'            => null,
            'dias_restantes'   => 0,
            'meses'            => 0,
            'peor_atraso_dias' => (int) $h['peor_atraso'],
            'etapa'            => $h['etapa'],
            'castigado'        => (bool) $h['castigado'],
            'al_dia_desde'     => $h['al_dia_desde'],
            'motivo'           => 'Sin atrasos que pesen sobre su elegibilidad.',
            'excepcion'        => null,
        ];

        /* Lo vencido de HOY pesa más que cualquier historial: a quien
           debe una cuota no se le ofrece otra cosa, y punto. */
        if ((int) $h['vencidas_hoy'] > 0) {
            return array_merge($limpio, [
                'puede'        => false,
                'inhabilitado' => true,
                'motivo'       => 'Tiene ' . (int) $h['vencidas_hoy']
                    . ' cuota(s) vencida(s) sin pagar. Primero hay que ponerse al día.',
            ]);
        }

        if ((int) $h['cobros_abiertos'] > 0) {
            return array_merge($limpio, [
                'puede'        => false,
                'inhabilitado' => true,
                'motivo'       => 'Tiene un expediente de cobro abierto.',
            ]);
        }

        $meses = PeriodoDeCastigo::meses(
            (int) $h['peor_atraso'],
            $h['etapa'],
            (bool) $h['castigado'],
            (bool) $h['deuda_cancelada'],
            (int) $h['cancelado_cent']
        );

        if ($meses === 0 || $h['al_dia_desde'] === null) {
            return $limpio;
        }

        $hasta = PeriodoDeCastigo::hasta(
            new \DateTimeImmutable((string) $h['al_dia_desde']),
            (int) $h['peor_atraso'],
            $h['etapa'],
            (bool) $h['castigado'],
            (bool) $h['deuda_cancelada'],
            (int) $h['cancelado_cent']
        );

        $hoy = new \DateTimeImmutable('today');

        if ($hasta === null || $hasta <= $hoy) {
            return array_merge($limpio, [
                'meses'  => $meses,
                'motivo' => 'Tuvo atrasos, pero el plazo que pesaban ya se cumplió.',
            ]);
        }

        $estado = array_merge($limpio, [
            'puede'          => false,
            'inhabilitado'   => true,
            'hasta'          => $hasta->format('Y-m-d'),
            'dias_restantes' => (int) $hoy->diff($hasta)->days,
            'meses'          => $meses,
            'motivo'         => PeriodoDeCastigo::explicacion(
                (int) $h['peor_atraso'], $h['etapa'], (bool) $h['castigado']
            ),
        ]);

        /* ¿Hay una excepción viva que le abra la puerta igual? */
        $exc = $this->excepcionVigente($usuarioId, $producto);

        if ($exc !== null) {
            $estado['puede']     = true;
            $estado['excepcion'] = $exc;
        }

        return $estado;
    }

    /**
     * Lo mismo, pero cortando el paso.
     *
     * @throws \RuntimeException si está inhabilitado
     */
    public function exigir(int $usuarioId, string $producto = 'todo'): array
    {
        $e = $this->de($usuarioId, $producto);

        if ($e['puede']) { return $e; }

        $hasta = $e['hasta'] !== null
            ? ' Podrá optar a productos nuevos a partir del '
              . (new \DateTimeImmutable($e['hasta']))->format('d/m/Y') . '.'
            : '';

        throw new \RuntimeException($e['motivo'] . $hasta);
    }

    /**
     * El historial que importa, en una sola consulta por pieza.
     *
     * @return array<string,mixed>
     */
    private function historial(int $usuarioId): array
    {
        /* El peor atraso alcanzado: tanto el de las cuotas que se
           pagaron tarde como el de las que siguen vencidas. Mirar solo
           las pagadas dejaría fuera justo a quien peor está. */
        $peor = (int) BaseDatos::unValor(
            'SELECT COALESCE(MAX(atraso), 0) FROM (
                 SELECT GREATEST(0, DATEDIFF(q.pagada_en, q.fecha_vencimiento)) AS atraso
                   FROM cuotas q JOIN creditos c ON c.id = q.credito_id
                  WHERE c.usuario_id = ? AND q.pagada_en IS NOT NULL
                 UNION ALL
                 SELECT GREATEST(0, q.dias_mora)
                   FROM cuotas q JOIN creditos c ON c.id = q.credito_id
                  WHERE c.usuario_id = ?
             ) t',
            [$usuarioId, $usuarioId]
        );

        /* Cuándo quedó al día: el último pago que dejó su cartera sin
           nada vencido. Se toma la fecha del pago más reciente, que es
           cuando de verdad se resolvió. */
        $alDia = BaseDatos::unValor(
            'SELECT MAX(q.pagada_en)
               FROM cuotas q JOIN creditos c ON c.id = q.credito_id
              WHERE c.usuario_id = ? AND q.pagada_en IS NOT NULL',
            [$usuarioId]
        );

        /* La etapa de cobranza más grave a la que llegó. El orden lo
           da la propia columna, así que se traduce a número aquí. */
        $etapa = BaseDatos::unValor(
            'SELECT k.etapa FROM cobranza_casos k
              WHERE k.usuario_id = ?
              ORDER BY FIELD(k.etapa, "preventiva","administrativa","extrajudicial",
                                      "judicial","sentencia","castigado") DESC
              LIMIT 1',
            [$usuarioId]
        );

        return [
            'peor_atraso'      => $peor,
            'al_dia_desde'     => $alDia !== null ? (string) $alDia : null,
            'etapa'            => $etapa !== null ? (string) $etapa : null,

            'castigado' => (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM creditos WHERE usuario_id = ? AND estado = "castigado"',
                [$usuarioId]
            ) > 0,

            'vencidas_hoy' => (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM cuotas q JOIN creditos c ON c.id = q.credito_id
                  WHERE c.usuario_id = ? AND q.estado NOT IN ("pagada","condonada")
                    AND q.fecha_vencimiento < CURDATE()',
                [$usuarioId]
            ),

            'cobros_abiertos' => (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM cobranza_casos
                  WHERE usuario_id = ? AND cerrado_en IS NULL',
                [$usuarioId]
            ),

            /* ¿Liquidó del todo? La ley acorta el castigo a un año
               cuando la deuda se canceló entera, y a seis meses si
               además era pequeña. */
            'deuda_cancelada' => (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM creditos
                  WHERE usuario_id = ? AND estado IN ("vigente","desembolsado")',
                [$usuarioId]
            ) === 0,

            'cancelado_cent' => (int) BaseDatos::unValor(
                'SELECT COALESCE(MAX(monto_cent), 0) FROM creditos
                  WHERE usuario_id = ? AND estado = "cancelado"',
                [$usuarioId]
            ),
        ];
    }

    /** @return array<string,mixed>|null */
    private function excepcionVigente(int $usuarioId, string $producto): ?array
    {
        $e = BaseDatos::unaFila(
            'SELECT id, uuid, alcance, motivo, autorizada_por, vigente_hasta,
                    (SELECT usuario FROM usuarios WHERE id = autorizada_por) AS autoriza
               FROM elegibilidad_excepciones
              WHERE usuario_id = ?
                AND revocada_en IS NULL
                AND vigente_desde <= CURDATE()
                AND vigente_hasta >= CURDATE()
                AND (alcance = "todo" OR alcance = ?)
              ORDER BY vigente_hasta DESC
              LIMIT 1',
            [$usuarioId, $producto]
        );

        return $e ?: null;
    }

    /**
     * Autorizar que un inhabilitado opte igual.
     *
     * Lleva motivo obligatorio, caduca sola y queda en la auditoría.
     * Una excepción sin esas tres cosas deja de ser una excepción y
     * pasa a ser una regla distinta para una persona, que es
     * justamente lo que no puede haber en un banco.
     */
    public function excepcionar(
        int $usuarioId,
        string $motivo,
        int $meses = 3,
        string $alcance = 'todo'
    ): array {
        Autorizacion::exigir('elegibilidad.excepcionar');

        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 20) {
            throw new \InvalidArgumentException(
                'El motivo tiene que explicar la decisión, no nombrarla. '
                . 'Escriba al menos una frase.'
            );
        }

        $meses = max(1, min(12, $meses));

        $actor = Sesion::exigirAutenticado();
        $hasta = (new \DateTimeImmutable('today'))->modify('+' . $meses . ' months');

        BaseDatos::ejecutar(
            'INSERT INTO elegibilidad_excepciones
               (uuid, usuario_id, alcance, motivo, autorizada_por,
                vigente_desde, vigente_hasta)
             VALUES (UUID(), ?, ?, ?, ?, CURDATE(), ?)',
            [$usuarioId, $alcance, $motivo, $actor, $hasta->format('Y-m-d')]
        );

        $id = BaseDatos::ultimoId();

        Auditoria::registrar(
            'elegibilidad.excepcion',
            Auditoria::EXITO,
            $actor,
            'usuario',
            (string) $usuarioId,
            ['alcance' => $alcance, 'meses' => $meses, 'motivo' => $motivo]
        );

        return [
            'id'      => $id,
            'hasta'   => $hasta->format('Y-m-d'),
            'alcance' => $alcance,
        ];
    }
}
