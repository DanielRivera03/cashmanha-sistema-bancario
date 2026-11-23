<?php

declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\AlertaInvalida;
use CashMan\Aplicacion\Servicio\ServicioCumplimiento;
use CashMan\Dominio\Cumplimiento\UmbralesUIF;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * El panel del encargado de cumplimiento.
 *
 * Aquí se mira lo que la vigilancia levantó y se decide qué hacer con
 * ello. Nada de lo que pasa por aquí se le comunica al cliente: un
 * reporte de operación sospechosa es confidencial por ley, y el propio
 * hecho de estar siendo analizado también.
 */
final class ControladorCumplimiento extends ControladorBase
{
    public function panel(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('cumplimiento.ver');

        $servicio = new ServicioCumplimiento();

        $this->render('cumplimiento/panel', [
            'titulo'     => 'Cumplimiento · CashMan H.A.',
            'encabezado' => 'Prevención de lavado de dinero',
            'panorama'   => $servicio->panorama(),
            'alertas'    => $servicio->alertasAbiertas(200),
            'umbrales'   => [
                'efectivo'     => UmbralesUIF::EFECTIVO_CENT,
                'otros_medios' => UmbralesUIF::OTROS_MEDIOS_CENT,
                'analisis'     => UmbralesUIF::DIAS_HABILES_ANALISIS,
                'reporte'      => UmbralesUIF::DIAS_HABILES_REPORTE,
            ],
            'puedeAnalizar' => Autorizacion::puede('cumplimiento.analizar'),
            'puedeReportar' => Autorizacion::puede('cumplimiento.reportar'),
            'csrf'       => Csrf::token('cumplimiento'),
        ]);
    }

    public function reportes(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('cumplimiento.ver');

        $this->render('cumplimiento/reportes', [
            'titulo'     => 'Reportes a la UIF · CashMan H.A.',
            'encabezado' => 'Reportes a la UIF',
            'reportes'   => BaseDatos::todas(
                'SELECT r.*, CONCAT(u.nombres, " ", u.apellidos) AS cliente,
                        a.folio AS alerta_folio,
                        DATEDIFF(r.vence_en, CURDATE()) AS dias_restantes
                   FROM reportes_uif r
                   JOIN usuarios u ON u.id = r.usuario_id
                   LEFT JOIN alertas_cumplimiento a ON a.id = r.alerta_id
                  ORDER BY FIELD(r.estado, "borrador", "listo", "enviado", "anulado"),
                           r.vence_en, r.creado_en DESC
                  LIMIT 300'
            ),
            'puedeReportar' => Autorizacion::puede('cumplimiento.reportar'),
            'csrf'       => Csrf::token('cumplimiento'),
        ]);
    }

    // =================================================================
    // Acciones sobre una alerta
    // =================================================================

    public function descartar(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('cumplimiento.analizar');

        try {
            (new ServicioCumplimiento())->descartar(
                (string) ($parametros['folio'] ?? ''),
                $actorId,
                (string) $this->post('razon')
            );
        } catch (AlertaInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito($this->estadoPanel(), 'Alerta descartada, con su motivo registrado.');
    }

    public function escalar(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('cumplimiento.analizar');

        try {
            $r = (new ServicioCumplimiento())->escalar(
                (string) ($parametros['folio'] ?? ''),
                $actorId,
                (string) $this->post('relato'),
                (string) $this->post('tipo', 'ros')
            );
        } catch (AlertaInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(
            $r + $this->estadoPanel(),
            'Se creó el reporte ' . $r['folio'] . ' en BORRADOR. '
            . 'El plazo legal de remisión vence el '
            . date('d/m/Y', (int) strtotime((string) $r['vence_en'])) . '. '
            . 'El sistema no lo envía: eso se hace por los canales de la Fiscalía.'
        );
    }

    public function prorrogar(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('cumplimiento.analizar');

        try {
            $nueva = (new ServicioCumplimiento())->prorrogar(
                (string) ($parametros['folio'] ?? ''),
                $actorId
            );
        } catch (AlertaInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(
            ['vence' => $nueva] + $this->estadoPanel(),
            'Plazo prorrogado hasta el ' . date('d/m/Y', (int) strtotime($nueva))
            . '. La ley permite una sola prórroga, así que ésta ya se agotó.'
        );
    }

    public function marcarEnviado(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('cumplimiento.reportar');

        try {
            (new ServicioCumplimiento())->marcarEnviado(
                (string) ($parametros['folio'] ?? ''),
                $actorId,
                (string) $this->post('acuse')
            );
        } catch (AlertaInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito([], 'Reporte registrado como remitido, con su acuse.');
    }

    /** Lo que el panel necesita para repintarse sin recargar. */
    private function estadoPanel(): array
    {
        $servicio = new ServicioCumplimiento();

        return [
            'panorama' => $servicio->panorama(),
            'alertas'  => $servicio->alertasAbiertas(200),
        ];
    }
}
