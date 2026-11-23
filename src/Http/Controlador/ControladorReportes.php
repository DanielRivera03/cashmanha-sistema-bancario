<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Reportes e indicadores de la institución.
 */
final class ControladorReportes extends ControladorBase
{
    public function indicadores(array $parametros = []): void
    {
        Autorizacion::exigir('reporte.ver');

        $ind = BaseDatos::unaFila('SELECT * FROM v_indicadores_institucion');

        // Operaciones por día en el último mes.
        $serie = BaseDatos::todas(
            'SELECT DATE(creado_en) dia, COUNT(*) operaciones,
                    SUM(tipo = "transferencia") transferencias,
                    SUM(tipo = "deposito")      depositos,
                    SUM(tipo = "retiro")        retiros
               FROM asientos
              WHERE creado_en > DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              GROUP BY DATE(creado_en) ORDER BY dia'
        );

        $porTipo = BaseDatos::todas(
            'SELECT a.tipo, COUNT(*) n,
                    COALESCE(SUM(CASE WHEN m.importe_cent > 0 THEN m.importe_cent ELSE 0 END),0) monto
               FROM asientos a JOIN movimientos m ON m.asiento_id = a.id
              GROUP BY a.tipo ORDER BY n DESC'
        );

        $cartera = BaseDatos::todas(
            'SELECT clasificacion_sugerida clase, COUNT(*) casos,
                    COALESCE(SUM(deuda_vencida_cent),0) deuda
               FROM v_cartera_morosa GROUP BY clasificacion_sugerida ORDER BY clase'
        );

        $productos = BaseDatos::todas(
            'SELECT p.nombre, p.tipo,
                    (SELECT COUNT(*) FROM cuentas  WHERE producto_id = p.id AND es_tecnica = 0) cuentas,
                    (SELECT COUNT(*) FROM creditos WHERE producto_id = p.id) creditos,
                    (SELECT COALESCE(SUM(saldo_cent),0) FROM cuentas WHERE producto_id = p.id AND es_tecnica = 0) saldo
               FROM productos p WHERE p.activo = 1 ORDER BY p.tipo, p.nombre'
        );

        // Estado contable: siempre visible, no escondido en un script.
        $contable = BaseDatos::unaFila(
            'SELECT (SELECT COUNT(*) FROM v_asientos_descuadrados) descuadrados,
                    (SELECT COUNT(*) FROM v_conciliacion_saldos WHERE diferencia_cent <> 0) sin_conciliar,
                    (SELECT descuadre_cent FROM v_balance_general) balance'
        );

        $this->render('reportes/indicadores', [
            'titulo'     => 'Indicadores · CashMan H.A.',
            'encabezado' => 'Indicadores de la institución',
            'ind'        => $ind ?? [],
            'serie'      => $serie,
            'porTipo'    => $porTipo,
            'cartera'    => $cartera,
            'productos'  => $productos,
            'contable'   => $contable ?? [],
        ]);
    }

    public function transacciones(array $parametros = []): void
    {
        Autorizacion::exigir('reporte.ver');

        $desde = $this->get('desde') ?: date('Y-m-01');
        $hasta = $this->get('hasta') ?: date('Y-m-d');

        foreach ([$desde, $hasta] as $f) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
                Respuesta::error('Rango de fechas inválido.', 422);
            }
        }

        $tipo  = $this->get('t');
        $where = 'a.creado_en >= ? AND a.creado_en < DATE_ADD(?, INTERVAL 1 DAY)';
        $args  = [$desde, $hasta];

        if ($tipo !== '') {
            $where .= ' AND a.tipo = ?';
            $args[] = $tipo;
        }

        $movs = BaseDatos::todas(
            "SELECT a.id AS asiento_id,
                    a.referencia, a.tipo, a.descripcion, a.creado_en,
                    m.importe_cent, c.numero_cuenta,
                    CONCAT(u.nombres,' ',u.apellidos) AS titular,
                    COALESCE(CONCAT(o.nombres,' ',o.apellidos), 'Sistema') AS operador
               FROM asientos a
               JOIN movimientos m ON m.asiento_id = a.id
               JOIN cuentas  c ON c.id = m.cuenta_id AND c.es_tecnica = 0
               JOIN usuarios u ON u.id = c.usuario_id
               LEFT JOIN usuarios o ON o.id = a.creado_por
              WHERE {$where}
              ORDER BY a.id DESC LIMIT 1000",
            $args
        );

        /* El código del comprobante es un hashid, no la referencia.
         *
         * La referencia es lo que el cliente lee en su listado; el
         * hashid es lo que la ruta sabe descodificar. Poner una donde
         * va el otro daba un 404 que parecía un problema de permisos
         * y no lo era. Los demás listados del sistema ya lo hacen
         * así; este se había quedado atrás. */
        foreach ($movs as &$m) {
            $m['comprobante'] = Hashid::codificar((int) $m['asiento_id'], 'asiento');
        }

        unset($m);

        $resumen = BaseDatos::unaFila(
            "SELECT COUNT(DISTINCT a.id) operaciones,
                    COALESCE(SUM(CASE WHEN m.importe_cent > 0 THEN m.importe_cent ELSE 0 END),0) entradas,
                    COALESCE(SUM(CASE WHEN m.importe_cent < 0 THEN -m.importe_cent ELSE 0 END),0) salidas
               FROM asientos a
               JOIN movimientos m ON m.asiento_id = a.id
               JOIN cuentas c ON c.id = m.cuenta_id AND c.es_tecnica = 0
              WHERE {$where}",
            $args
        );

        if ($this->get('csv') === '1') {
            $this->exportarCsv($movs, 'transacciones-' . $desde . '-a-' . $hasta);
        }

        $this->render('reportes/transacciones', [
            'titulo'     => 'Reporte de transacciones · CashMan H.A.',
            'encabezado' => 'Reporte de transacciones',
            'movs'       => $movs,
            'resumen'    => $resumen ?? [],
            'desde'      => $desde,
            'hasta'      => $hasta,
            'tipo'       => $tipo,
        ]);
    }

    public function cartera(array $parametros = []): void
    {
        Autorizacion::exigir('reporte.ver');

        $creditos = BaseDatos::todas(
            'SELECT c.folio, c.monto_cent, c.saldo_capital_cent, c.cuota_cent,
                    c.cuotas_pagadas, c.plazo_meses, c.estado, c.clasificacion,
                    c.tasa_bp, p.nombre AS producto,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente,
                    (SELECT COUNT(*) FROM cuotas q
                      WHERE q.credito_id = c.id AND q.estado NOT IN ("pagada","condonada")
                        AND q.fecha_vencimiento < CURDATE()) AS vencidas
               FROM creditos c
               JOIN productos p ON p.id = c.producto_id
               JOIN usuarios  u ON u.id = c.usuario_id
              WHERE c.estado IN ("vigente","aprobado")
              ORDER BY vencidas DESC, c.saldo_capital_cent DESC'
        );

        $resumen = BaseDatos::unaFila(
            'SELECT COUNT(*) creditos,
                    COALESCE(SUM(monto_cent),0) otorgado,
                    COALESCE(SUM(saldo_capital_cent),0) saldo,
                    COALESCE(SUM(total_pagado_cent),0) recuperado
               FROM creditos WHERE estado IN ("vigente","aprobado")'
        );

        $this->render('reportes/cartera', [
            'titulo'     => 'Reporte de cartera · CashMan H.A.',
            'encabezado' => 'Reporte de cartera',
            'creditos'   => $creditos,
            'resumen'    => $resumen ?? [],
        ]);
    }

    /** @param array<int,array<string,mixed>> $filas */
    private function exportarCsv(array $filas, string $nombre): void
    {
        Auditoria::registrar('reporte.exportado', Auditoria::EXITO, Sesion::usuarioId(),
            'reporte', $nombre, ['registros' => count($filas)]);

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $nombre . '.csv"');
            header('Cache-Control: no-store, private');
            header('X-Content-Type-Options: nosniff');
        }

        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");

        if ($filas !== []) {
            fputcsv($out, array_keys($filas[0]));
            foreach ($filas as $f) {
                // Neutralizar fórmulas: un valor que empiece por = lo
                // ejecuta Excel al abrir el archivo.
                fputcsv($out, array_map(static function ($v): string {
                    $v = (string) $v;
                    return $v !== '' && in_array($v[0], ['=','+','-','@',"\t","\r"], true) ? "'" . $v : $v;
                }, $f));
            }
        }

        fclose($out);
        exit;
    }
}
