<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\Sesion;

final class ControladorPanel extends ControladorBase
{
    public function inicio(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $usuario   = $this->usuarioActual();

        $esCliente = ($usuario['rol_clave'] ?? '') === 'cliente';

        $datos = [
            'titulo'   => 'Panel · CashMan H.A.',
            'cuentas'  => $this->cuentasDe($usuarioId),
            'tarjetas' => $this->tarjetasDe($usuarioId),
            'ultimos'  => $this->ultimosMovimientos($usuarioId),
        ];

        // Los roles operativos ven además indicadores de la institución.
        if (!$esCliente && Autorizacion::puede('reporte.ver')) {
            $datos['indicadores'] = BaseDatos::unaFila('SELECT * FROM v_indicadores_institucion');
        }

        if (Autorizacion::puede('cartera.ver_morosos')) {
            $datos['morosos_resumen'] = $this->resumenMorosos();
        }

        // Situación crediticia propia (lo que ve un cliente).
        $datos['creditos_activos'] = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM creditos WHERE usuario_id = ? AND estado = "vigente"',
            [$usuarioId]
        );
        $datos['deuda_creditos'] = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(saldo_capital_cent),0) FROM creditos
              WHERE usuario_id = ? AND estado = "vigente"',
            [$usuarioId]
        );
        $datos['cuotas_vencidas'] = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM cuotas q JOIN creditos c ON c.id = q.credito_id
              WHERE c.usuario_id = ? AND q.estado IN ("pendiente","parcial","vencida")
                AND q.fecha_vencimiento < CURDATE()',
            [$usuarioId]
        );

        $this->render('panel/inicio', $datos);
    }

    /** @return array<int,array<string,mixed>> */
    private function cuentasDe(int $usuarioId): array
    {
        $filas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, c.saldo_cent, c.saldo_retenido_cent,
                    c.moneda, c.estado, p.nombre AS producto, p.tipo
               FROM cuentas c
               JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.es_tecnica = 0
              ORDER BY c.id',
            [$usuarioId]
        );

        foreach ($filas as &$f) {
            $f['codigo'] = Hashid::codificar((int) $f['id'], 'cuenta');
            unset($f['id']);
        }

        return $filas;
    }

    /** @return array<int,array<string,mixed>> */
    private function tarjetasDe(int $usuarioId): array
    {
        $filas = BaseDatos::todas(
            'SELECT t.id, t.ultimos4, t.marca, t.tipo, t.estado, t.limite_cent,
                    t.saldo_usado_cent, t.expira_mes, t.expira_anio, t.dia_corte,
                    t.titular, t.contactless, t.congelada_hasta,
                    pr.nivel AS producto_nivel, pr.codigo AS producto_codigo,
                    u.nombres, u.apellidos,
                    (SELECT COALESCE(SUM(pl.total_cent - pl.pagado_cent),0)
                       FROM tarjeta_planes pl
                      WHERE pl.tarjeta_id = t.id AND pl.estado = "vigente") AS diferido_cent
               FROM tarjetas t
               LEFT JOIN productos pr ON pr.id = t.producto_id
               JOIN usuarios u ON u.id = t.usuario_id
              WHERE t.usuario_id = ? AND t.estado <> "cancelada"
              ORDER BY t.id',
            [$usuarioId]
        );

        foreach ($filas as &$f) {
            $f['codigo']         = Hashid::codificar((int) $f['id'], 'tarjeta');
            $f['disponible_cent'] = \CashMan\Aplicacion\Servicio\ServicioTarjeta::cupoDisponible(
                (int) $f['limite_cent'], (int) $f['saldo_usado_cent']
            );
            unset($f['id']);
        }

        return $filas;
    }

    /** @return array<int,array<string,mixed>> */
    private function ultimosMovimientos(int $usuarioId, int $limite = 12): array
    {
        return BaseDatos::todas(
            "SELECT m.importe_cent, m.descripcion, m.saldo_posterior_cent, m.creado_en,
                    a.referencia, a.tipo, c.numero_cuenta
               FROM movimientos m
               JOIN asientos a ON a.id = m.asiento_id
               JOIN cuentas  c ON c.id = m.cuenta_id
              WHERE c.usuario_id = ? AND c.es_tecnica = 0
              ORDER BY m.id DESC
              LIMIT {$limite}",
            [$usuarioId]
        );
    }

    /** @return array<string,mixed> */
    private function metricasInstitucionales(): array
    {
        $f = BaseDatos::unaFila(
            'SELECT
               (SELECT COUNT(*) FROM usuarios WHERE rol_id = 5 AND estado = "activo") AS clientes_activos,
               (SELECT COUNT(*) FROM cuentas  WHERE estado = "activa" AND es_tecnica = 0) AS cuentas_activas,
               (SELECT COALESCE(SUM(saldo_cent),0) FROM cuentas WHERE es_tecnica = 0) AS depositos_cent,
               (SELECT COUNT(*) FROM tarjetas WHERE estado = "activa") AS tarjetas_activas,
               (SELECT COALESCE(SUM(saldo_capital_cent),0) FROM creditos WHERE estado = "vigente") AS cartera_cent,
               (SELECT COUNT(*) FROM asientos WHERE DATE(creado_en) = CURDATE()) AS operaciones_hoy'
        );

        return $f ?? [];
    }

    /** @return array<string,mixed> */
    private function resumenMorosos(): array
    {
        $f = BaseDatos::unaFila(
            'SELECT COUNT(DISTINCT usuario_id) AS clientes,
                    COALESCE(SUM(deuda_vencida_cent), 0) AS deuda_cent,
                    COALESCE(MAX(dias_mora_max), 0) AS peor_atraso
               FROM v_cartera_morosa'
        );

        return $f ?? ['clientes' => 0, 'deuda_cent' => 0, 'peor_atraso' => 0];
    }
}
