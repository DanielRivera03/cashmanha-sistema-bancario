<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioReestructuracion;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

final class ControladorReestructuraciones extends ControladorBase
{
    public function listar(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        if (!Autorizacion::puedeAlguno(['reestructuracion.solicitar', 'reestructuracion.aprobar'])) {
            throw new \CashMan\Infraestructura\Seguridad\AccesoDenegado(
                'No tiene permiso para ver reestructuraciones.'
            );
        }

        $filas = BaseDatos::todas(
            'SELECT r.*, c.folio AS credito_folio, c.estado AS credito_estado,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente,
                    CONCAT(s.nombres," ",s.apellidos) AS solicitante,
                    CONCAT(a.nombres," ",a.apellidos) AS aprobador
               FROM reestructuraciones r
               JOIN creditos c ON c.id = r.credito_id
               JOIN usuarios u ON u.id = c.usuario_id
               JOIN usuarios s ON s.id = r.solicitada_por
          LEFT JOIN usuarios a ON a.id = r.aprobada_por
              ORDER BY FIELD(r.estado,"solicitada","aplicada","rechazada"), r.id DESC
              LIMIT 200'
        );

        $miId = Sesion::usuarioId();
        foreach ($filas as &$f) {
            $f['codigo'] = Hashid::codificar((int) $f['id'], 'reestructura');
            // Quien solicitó no puede aprobar su propia solicitud.
            $f['puede_aprobar'] = $f['estado'] === 'solicitada'
                && Autorizacion::puede('reestructuracion.aprobar')
                && (int) $f['solicitada_por'] !== $miId;
            $f['es_mia'] = (int) $f['solicitada_por'] === $miId;
            unset($f['id']);
        }

        // Créditos candidatos: vigentes y con saldo.
        $candidatos = [];
        if (Autorizacion::puede('reestructuracion.solicitar')) {
            $candidatos = BaseDatos::todas(
                'SELECT c.id, c.folio, c.saldo_capital_cent, c.cuota_cent, c.tasa_bp,
                        c.plazo_meses, c.cuotas_pagadas,
                        CONCAT(u.nombres," ",u.apellidos) AS cliente,
                        (SELECT COUNT(*) FROM cuotas q WHERE q.credito_id = c.id
                          AND q.estado NOT IN ("pagada","condonada") AND q.fecha_vencimiento < CURDATE()) AS vencidas
                   FROM creditos c JOIN usuarios u ON u.id = c.usuario_id
                  WHERE c.estado = "vigente" AND c.saldo_capital_cent > 0
                    AND NOT EXISTS (SELECT 1 FROM reestructuraciones x
                                     WHERE x.credito_id = c.id AND x.estado = "solicitada")
                  ORDER BY vencidas DESC, c.id DESC LIMIT 100'
            );
            foreach ($candidatos as &$cd) {
                $cd['codigo'] = Hashid::codificar((int) $cd['id'], 'credito');
                unset($cd['id']);
            }
        }

        $this->render('reestructuraciones/listar', [
            'titulo'      => 'Reestructuraciones · CashMan H.A.',
            'encabezado'  => 'Reestructuraciones de crédito',
            'filas'       => $filas,
            'candidatos'  => $candidatos,
            'puedeSolicitar' => Autorizacion::puede('reestructuracion.solicitar'),
            'csrf'        => Csrf::token('reestructura'),
        ]);
    }

    public function simular(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('reestructuracion.solicitar');

        $creditoId = $this->idDesde($this->post('credito'), 'credito');
        if ($creditoId === 0) {
            Respuesta::error('Seleccione un crédito.', 422);
        }

        $tasa = $this->post('tasa');

        try {
            $r = (new ServicioReestructuracion())->simular(
                $creditoId,
                (int) $this->post('plazo', '24'),
                $tasa !== '' ? (int) round((float) $tasa * 100) : null
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito($r);
    }

    public function solicitar(array $parametros = []): void
    {
        $creditoId = $this->idDesde($this->post('credito'), 'credito');
        if ($creditoId === 0) {
            Respuesta::error('Seleccione un crédito.', 422);
        }

        $tasa = $this->post('tasa');

        try {
            $r = (new ServicioReestructuracion())->solicitar(
                $creditoId,
                (int) $this->post('plazo', '24'),
                $tasa !== '' ? (int) round((float) $tasa * 100) : null,
                $this->post('motivo')
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito($r, 'Reestructuración solicitada para ' . $r['folio']
            . '. Queda pendiente de aprobación.');
    }

    public function aprobar(array $parametros = []): void
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'reestructura');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Reestructuración no encontrada.');
        }

        try {
            $r = (new ServicioReestructuracion())->aprobar($id);
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito($r, 'Reestructuración aplicada: ' . $r['cuotas_nuevas']
            . ' cuotas de ' . $r['cuota']['formateado'] . '.');
    }

    public function rechazar(array $parametros = []): void
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'reestructura');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Reestructuración no encontrada.');
        }

        try {
            (new ServicioReestructuracion())->rechazar($id, $this->post('motivo'));
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito(null, 'Reestructuración rechazada.');
    }
}
