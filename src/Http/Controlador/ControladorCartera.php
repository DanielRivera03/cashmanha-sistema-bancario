<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Cartera morosa.
 *
 * La clasificación de riesgo (A a E) sigue la lógica estándar de
 * provisiones bancarias por días de atraso.
 */
final class ControladorCartera extends ControladorBase
{
    public function morosos(array $parametros = []): void
    {
        Autorizacion::exigir('cartera.ver_morosos');

        $clasificacion = $this->get('c');
        $parametrosSql = [];
        $filtro        = '';

        if ($clasificacion !== '' && in_array($clasificacion, ['A','B','C','D','E'], true)) {
            $filtro = ' WHERE clasificacion_sugerida = ?';
            $parametrosSql[] = $clasificacion;
        }

        $morosos = BaseDatos::todas(
            'SELECT * FROM v_cartera_morosa' . $filtro
            . ' ORDER BY dias_mora_max DESC, deuda_vencida_cent DESC LIMIT 300',
            $parametrosSql
        );

        /* El expediente de cobro de cada caso, si ya está abierto.
         *
         * Es el enlace que le faltaba a esta pantalla: la lista dice
         * cuánto se debe, y para trabajar el caso hace falta ver a quién
         * se llamó, qué contestó y en qué punto va. Se resuelve en una
         * sola consulta en vez de una por fila. */
        $expedientes = [];

        if ($morosos !== []) {
            $ids   = array_map(static fn(array $m): int => (int) $m['credito_id'], $morosos);
            $hueco = implode(',', array_fill(0, count($ids), '?'));

            foreach (BaseDatos::todas(
                "SELECT id, credito_id FROM cobranza_casos WHERE credito_id IN ({$hueco})",
                $ids
            ) as $k) {
                $expedientes[(int) $k['credito_id']] = (int) $k['id'];
            }
        }

        foreach ($morosos as &$m) {
            $creditoId = (int) $m['credito_id'];

            $m['codigo_cliente']  = Hashid::codificar((int) $m['usuario_id'], 'usuario');
            $m['codigo_credito']  = Hashid::codificar($creditoId, 'credito');
            $m['codigo_cobranza'] = isset($expedientes[$creditoId])
                ? Hashid::codificar($expedientes[$creditoId], 'cobranza')
                : null;

            unset($m['usuario_id'], $m['credito_id']);
        }
        unset($m);

        $resumen = BaseDatos::todas(
            'SELECT clasificacion_sugerida AS clase,
                    COUNT(*) AS casos,
                    COALESCE(SUM(deuda_vencida_cent), 0) AS deuda_cent
               FROM v_cartera_morosa
              GROUP BY clasificacion_sugerida
              ORDER BY clasificacion_sugerida'
        );

        $totales = BaseDatos::unaFila(
            'SELECT COUNT(DISTINCT usuario_id) AS clientes,
                    COALESCE(SUM(deuda_vencida_cent), 0) AS deuda_cent,
                    COALESCE(SUM(mora_acumulada_cent), 0) AS mora_cent,
                    COALESCE(MAX(dias_mora_max), 0) AS peor
               FROM v_cartera_morosa'
        ) ?? ['clientes' => 0, 'deuda_cent' => 0, 'mora_cent' => 0, 'peor' => 0];

        if ($this->esperaJson()) {
            Respuesta::jsonConEtag([
                'morosos' => $morosos,
                'resumen' => $resumen,
                'totales' => $totales,
            ]);
        }

        $this->render('cartera/morosos', [
            'titulo'        => 'Cartera morosa · CashMan H.A.',
            'encabezado'    => 'Cartera morosa',
            'morosos'       => $morosos,
            'resumen'       => $resumen,
            'totales'       => $totales,
            'clasificacion' => $clasificacion,
        ]);
    }

    public function exportar(array $parametros = []): void
    {
        Autorizacion::exigir('cartera.gestionar');

        $filas = BaseDatos::todas(
            'SELECT cliente, correo, telefono, clasificacion_sugerida,
                    cuotas_vencidas, deuda_vencida_cent, mora_acumulada_cent,
                    vencimiento_mas_antiguo, dias_mora_max
               FROM v_cartera_morosa
              ORDER BY dias_mora_max DESC'
        );

        Auditoria::registrar(
            'cartera.exportada',
            Auditoria::EXITO,
            Sesion::usuarioId(),
            'cartera',
            '',
            ['registros' => count($filas)]
        );

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="cartera-morosa-' . date('Ymd') . '.csv"');
            header('Cache-Control: no-store, private');
            header('X-Content-Type-Options: nosniff');
        }

        $salida = fopen('php://output', 'wb');
        fwrite($salida, "\xEF\xBB\xBF"); // BOM para que Excel lea los acentos

        fputcsv($salida, [
            'Cliente', 'Correo', 'Teléfono', 'Clasificación', 'Cuotas vencidas',
            'Deuda vencida', 'Mora acumulada', 'Vencimiento más antiguo', 'Días de atraso',
        ]);

        foreach ($filas as $f) {
            fputcsv($salida, [
                $this->neutralizarFormula((string) $f['cliente']),
                $this->neutralizarFormula((string) $f['correo']),
                $this->neutralizarFormula((string) ($f['telefono'] ?? '')),
                $f['clasificacion_sugerida'],
                $f['cuotas_vencidas'],
                number_format(((int) $f['deuda_vencida_cent']) / 100, 2, '.', ''),
                number_format(((int) $f['mora_acumulada_cent']) / 100, 2, '.', ''),
                $f['vencimiento_mas_antiguo'],
                $f['dias_mora_max'],
            ]);
        }

        fclose($salida);
        exit;
    }

    /**
     * Evita la inyección de fórmulas en CSV.
     *
     * Un nombre que empiece por =, +, - o @ lo ejecuta Excel al abrir
     * el archivo. Es un vector real de ejecución de código en el equipo
     * de quien abre el reporte.
     */
    private function neutralizarFormula(string $valor): string
    {
        if ($valor !== '' && in_array($valor[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $valor;
        }
        return $valor;
    }
}
