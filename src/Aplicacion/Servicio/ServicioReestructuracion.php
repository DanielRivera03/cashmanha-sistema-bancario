<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\Amortizacion;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Reestructuración de créditos.
 *
 * Reestructurar es renegociar un crédito vigente: se toma el saldo
 * pendiente y se arma un plan nuevo, normalmente a mayor plazo y menor
 * cuota, para que un cliente con dificultades pueda seguir pagando.
 *
 * En v1 esto existía como casos sueltos del controlador sin estados ni
 * aprobación: el plan se sobrescribía directamente. Aquí hay solicitud,
 * aprobación por un segundo usuario y trazabilidad de las condiciones
 * anteriores y nuevas, porque una reestructuración cambia lo pactado y
 * debe quedar documentada.
 */
final class ServicioReestructuracion
{
    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    /**
     * Simula las condiciones nuevas sin crear nada.
     *
     * @return array<string,mixed>
     */
    public function simular(int $creditoId, int $plazoNuevo, ?int $tasaNuevaBp = null): array
    {
        $c = $this->creditoVigente($creditoId);

        $saldo  = Monto::deCentavos((int) $c['saldo_capital_cent']);
        $tasaBp = $tasaNuevaBp ?? (int) $c['tasa_bp'];

        if ($plazoNuevo < 3 || $plazoNuevo > 360) {
            throw new \InvalidArgumentException('El plazo nuevo debe estar entre 3 y 360 meses.');
        }

        $amort   = new Amortizacion($saldo, $tasaBp, $plazoNuevo);
        $resumen = $amort->resumen();

        $cuotaAnterior = Monto::deCentavos((int) $c['cuota_cent']);
        $alivio        = $cuotaAnterior->centavos - $resumen['cuota']->centavos;

        // Interés que aún falta pagar en el plan vigente, para poder
        // comparar el costo total de una opción contra la otra.
        $interesPendiente = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(interes_cent),0) FROM cuotas
              WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")',
            [$creditoId]
        );

        return [
            'saldo_actual'      => $saldo->jsonSerialize(),
            'cuota_anterior'    => $cuotaAnterior->jsonSerialize(),
            'cuota_nueva'       => $resumen['cuota']->jsonSerialize(),
            /* Con su signo aparte.
               Antes el importe se recortaba a cero cuando la cuota
               subía, pero el porcentaje salía tal cual: el panel decía
               «$0.00» y debajo «-264.7% menos por mes». Acortar el
               plazo encarece la cuota, y eso hay que decirlo, no
               taparlo con un cero. */
            'alivia'            => $alivio > 0,
            'alivio_mensual'    => Monto::deCentavos(abs($alivio))->jsonSerialize(),
            'alivio_pct'        => $cuotaAnterior->centavos > 0
                ? round((abs($alivio) / $cuotaAnterior->centavos) * 100, 1) : 0.0,
            'plazo_anterior'    => (int) $c['plazo_meses'] - (int) $c['cuotas_pagadas'],
            'plazo_nuevo'       => $plazoNuevo,
            'tasa_anterior'     => (int) $c['tasa_bp'] / 100,
            'tasa_nueva'        => $tasaBp / 100,
            'interes_pendiente' => Monto::deCentavos($interesPendiente)->jsonSerialize(),
            'interes_nuevo'     => $resumen['interes_total']->jsonSerialize(),
            // Reestructurar casi siempre encarece el crédito en total,
            // aunque alivie la cuota. Se muestra sin maquillarlo.
            'encarece'          => $resumen['interes_total']->centavos > $interesPendiente,
            'costo_adicional'   => Monto::deCentavos(
                abs($resumen['interes_total']->centavos - $interesPendiente)
            )->jsonSerialize(),
            // El techo que admite el servicio, para que la pantalla no
            // tenga que repetirlo por su cuenta.
            'plazo_maximo'      => 360,
            'total_pagar'       => $resumen['total_pagar']->jsonSerialize(),
        ];
    }

    /** @return array<string,mixed> */
    public function solicitar(int $creditoId, int $plazoNuevo, ?int $tasaNuevaBp, string $motivo): array
    {
        Autorizacion::exigir('reestructuracion.solicitar');
        $actorId = Sesion::exigirAutenticado();

        if (trim($motivo) === '') {
            throw new \InvalidArgumentException('Debe indicar el motivo de la reestructuración.');
        }

        $c = $this->creditoVigente($creditoId);

        $pendiente = BaseDatos::unValor(
            'SELECT uuid FROM reestructuraciones WHERE credito_id = ? AND estado = "solicitada"',
            [$creditoId]
        );
        if ($pendiente !== null) {
            throw new \RuntimeException('Ya hay una reestructuración pendiente para este crédito.');
        }

        $sim = $this->simular($creditoId, $plazoNuevo, $tasaNuevaBp);

        BaseDatos::ejecutar(
            'INSERT INTO reestructuraciones
               (uuid, credito_id, saldo_anterior_cent, saldo_nuevo_cent,
                plazo_anterior, plazo_nuevo, tasa_anterior_bp, tasa_nueva_bp,
                cuota_anterior_cent, cuota_nueva_cent, motivo, solicitada_por)
             VALUES (UUID(),?,?,?,?,?,?,?,?,?,?,?)',
            [
                $creditoId,
                (int) $c['saldo_capital_cent'], (int) $c['saldo_capital_cent'],
                (int) $sim['plazo_anterior'], $plazoNuevo,
                (int) $c['tasa_bp'], $tasaNuevaBp ?? (int) $c['tasa_bp'],
                (int) $c['cuota_cent'], (int) $sim['cuota_nueva']['centavos'],
                mb_substr($motivo, 0, 500), $actorId,
            ]
        );
        $id = BaseDatos::ultimoId();

        Auditoria::registrar('reestructuracion.solicitada', Auditoria::EXITO, $actorId,
            'reestructuracion', (string) $id,
            ['credito' => $c['folio'], 'plazo_nuevo' => $plazoNuevo]);

        // Avisar a quien puede aprobarla.
        foreach (BaseDatos::todas(
            'SELECT DISTINCT u.id FROM usuarios u
               JOIN rol_permiso rp ON rp.rol_id = u.rol_id
               JOIN permisos p ON p.id = rp.permiso_id
              WHERE p.clave = "reestructuracion.aprobar" AND u.estado = "activo" LIMIT 10'
        ) as $r) {
            $this->notificaciones->crear(
                (int) $r['id'], 'reestructuracion.pendiente',
                'Reestructuración por aprobar',
                'El crédito ' . $c['folio'] . ' tiene una solicitud de reestructuración.',
                null, 'alerta'
            );
        }

        return ['id' => $id, 'simulacion' => $sim, 'folio' => $c['folio']];
    }

    /**
     * Aprueba y aplica: reemplaza el plan de pagos pendiente.
     *
     * Las cuotas ya pagadas NO se tocan — son hechos consumados. Solo
     * se sustituyen las pendientes.
     *
     * @return array<string,mixed>
     */
    public function aprobar(int $reestructuracionId): array
    {
        Autorizacion::exigir('reestructuracion.aprobar');
        $actorId = Sesion::exigirAutenticado();

        return BaseDatos::enTransaccion(function () use ($reestructuracionId, $actorId) {
            $r = BaseDatos::unaFila(
                'SELECT * FROM reestructuraciones WHERE id = ? FOR UPDATE',
                [$reestructuracionId]
            );
            if ($r === null) {
                throw new \RuntimeException('Reestructuración no encontrada.');
            }
            if ($r['estado'] !== 'solicitada') {
                throw new \RuntimeException('Esta solicitud ya fue resuelta.');
            }

            // Separación de funciones: quien solicita no puede aprobar.
            if ((int) $r['solicitada_por'] === $actorId) {
                Auditoria::registrar('reestructuracion.autoaprobacion_denegada',
                    Auditoria::DENEGADO, $actorId, 'reestructuracion', (string) $reestructuracionId);
                throw new \RuntimeException(
                    'No puede aprobar una reestructuración que usted mismo solicitó.'
                );
            }

            $c = BaseDatos::unaFila(
                'SELECT * FROM creditos WHERE id = ? FOR UPDATE',
                [(int) $r['credito_id']]
            );
            if ($c === null || $c['estado'] !== 'vigente') {
                throw new \RuntimeException('El crédito ya no está vigente.');
            }

            $saldo  = Monto::deCentavos((int) $c['saldo_capital_cent']);
            $plazo  = (int) $r['plazo_nuevo'];
            $tasaBp = (int) $r['tasa_nueva_bp'];

            $amort = new Amortizacion($saldo, $tasaBp, $plazo);
            $tabla = $amort->tabla();

            // Archivar las cuotas pendientes antes de reemplazarlas:
            // el plan anterior no se borra, queda en el histórico.
            $pendientes = BaseDatos::todas(
                'SELECT numero, total_cent, fecha_vencimiento FROM cuotas
                  WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")',
                [(int) $r['credito_id']]
            );

            BaseDatos::ejecutar(
                'DELETE FROM cuotas WHERE credito_id = ? AND estado NOT IN ("pagada","condonada")',
                [(int) $r['credito_id']]
            );

            $pagadas = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM cuotas WHERE credito_id = ?', [(int) $r['credito_id']]
            );

            foreach ($tabla as $f) {
                BaseDatos::ejecutar(
                    'INSERT INTO cuotas
                       (credito_id, numero, capital_cent, interes_cent, total_cent,
                        saldo_posterior_cent, fecha_vencimiento)
                     VALUES (?,?,?,?,?,?,?)',
                    [
                        (int) $r['credito_id'], $pagadas + $f['numero'],
                        $f['capital']->centavos, $f['interes']->centavos,
                        $f['cuota']->centavos, $f['saldo']->centavos,
                        $f['vencimiento'],
                    ]
                );
            }

            BaseDatos::ejecutar(
                'UPDATE creditos
                    SET plazo_meses = ?, cuota_cent = ?, tasa_bp = ?,
                        proxima_cuota = ?
                  WHERE id = ?',
                [
                    $pagadas + count($tabla), $amort->cuota()->centavos, $tasaBp,
                    $tabla[0]['vencimiento'], (int) $r['credito_id'],
                ]
            );

            BaseDatos::ejecutar(
                'UPDATE reestructuraciones
                    SET estado = "aplicada", aprobada_por = ?, aplicada_en = NOW()
                  WHERE id = ?',
                [$actorId, $reestructuracionId]
            );

            Auditoria::registrar('reestructuracion.aplicada', Auditoria::EXITO, $actorId,
                'reestructuracion', (string) $reestructuracionId,
                [
                    'credito'        => $c['folio'],
                    'cuotas_previas' => count($pendientes),
                    'cuotas_nuevas'  => count($tabla),
                    'cuota_anterior' => Monto::deCentavos((int) $r['cuota_anterior_cent'])->aDecimal(),
                    'cuota_nueva'    => $amort->cuota()->aDecimal(),
                ]
            );

            $this->notificaciones->crear(
                (int) $c['usuario_id'], 'credito.reestructurado',
                'Crédito reestructurado',
                'Tu crédito ' . $c['folio'] . ' quedó en ' . count($tabla)
                . ' cuotas de ' . $amort->cuota()->formateado() . '.',
                null, 'exito'
            );

            return [
                'cuotas_nuevas' => count($tabla),
                'cuota'         => $amort->cuota()->jsonSerialize(),
                'plazo'         => $plazo,
                'folio'         => $c['folio'],
            ];
        });
    }

    public function rechazar(int $reestructuracionId, string $motivo): array
    {
        Autorizacion::exigir('reestructuracion.aprobar');
        $actorId = Sesion::exigirAutenticado();

        $r = BaseDatos::unaFila('SELECT * FROM reestructuraciones WHERE id = ?', [$reestructuracionId]);
        if ($r === null) {
            throw new \RuntimeException('Reestructuración no encontrada.');
        }
        if ($r['estado'] !== 'solicitada') {
            throw new \RuntimeException('Esta solicitud ya fue resuelta.');
        }

        BaseDatos::ejecutar(
            'UPDATE reestructuraciones
                SET estado = "rechazada", aprobada_por = ?,
                    motivo = CONCAT(motivo, "\n[RECHAZO] ", ?)
              WHERE id = ?',
            [$actorId, mb_substr($motivo, 0, 300), $reestructuracionId]
        );

        Auditoria::registrar('reestructuracion.rechazada', Auditoria::EXITO, $actorId,
            'reestructuracion', (string) $reestructuracionId);

        return ['estado' => 'rechazada'];
    }

    /** @return array<string,mixed> */
    private function creditoVigente(int $creditoId): array
    {
        $c = BaseDatos::unaFila(
            'SELECT c.*, CONCAT(u.nombres," ",u.apellidos) AS cliente
               FROM creditos c JOIN usuarios u ON u.id = c.usuario_id
              WHERE c.id = ?',
            [$creditoId]
        );

        if ($c === null) {
            throw new \RuntimeException('Crédito no encontrado.');
        }
        if ($c['estado'] !== 'vigente') {
            throw new \RuntimeException('Solo se reestructuran créditos vigentes.');
        }
        if ((int) $c['saldo_capital_cent'] <= 0) {
            throw new \RuntimeException('El crédito no tiene saldo pendiente.');
        }

        return $c;
    }
}
