<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Notas de abono y de cargo.
 *
 * Es la forma correcta de corregir o ajustar algo sin tocar lo ya
 * registrado. Un asiento contable no se edita ni se borra: si hay que
 * abonar o cargar una cuenta —por un error de digitación, por una
 * comisión mal cobrada, por un ajuste autorizado—, se emite otro
 * asiento que lo dice, con su folio, su motivo y su firma.
 *
 * Lo importante es quién firma qué:
 *
 *   · El cajero EMITE la nota. Describe qué pasó y por cuánto.
 *   · Alguien por encima la AUTORIZA. Hasta entonces no mueve ni un
 *     centavo: queda en «pendiente».
 *
 * Esa separación no es trámite. Sin ella, quien recibe el dinero en
 * ventanilla podría abonarse a sí mismo —o a quien quisiera— cualquier
 * cantidad y el libro mayor seguiría cuadrando perfectamente. Cuadrar no
 * es lo mismo que estar bien.
 */
final class ServicioNotaContable
{
    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    /**
     * Emite una nota. Nace pendiente de autorización.
     *
     * @param array{tipo:string, cuenta_id:int, monto:mixed, concepto:string, motivo:string} $datos
     * @return array<string,mixed>
     */
    public function emitir(array $datos): array
    {
        Autorizacion::exigir('nota.emitir');
        $actorId = Sesion::exigirAutenticado();

        $tipo = (string) ($datos['tipo'] ?? '');

        if (!in_array($tipo, ['abono', 'cargo'], true)) {
            throw new \InvalidArgumentException('La nota tiene que ser de abono o de cargo.');
        }

        $monto = Monto::deEntradaUsuario($datos['monto'] ?? 0);

        if (!$monto->esPositivo()) {
            throw new \InvalidArgumentException('El importe de la nota debe ser mayor que cero.');
        }

        $motivo = trim((string) ($datos['motivo'] ?? ''));

        /* El motivo es obligatorio y se exige con holgura.
         *
         * Una nota sin motivo es un movimiento de dinero que nadie puede
         * explicar seis meses después, que es justo cuando se pregunta.
         * «Ajuste» tampoco explica nada, de ahí el mínimo. */
        if (mb_strlen($motivo) < 10) {
            throw new \InvalidArgumentException(
                'Explique el motivo de la nota con algo de detalle: es lo que verá '
                . 'quien la autorice y quien la audite.'
            );
        }

        $cuentaId = (int) ($datos['cuenta_id'] ?? 0);

        $cuenta = BaseDatos::unaFila(
            'SELECT c.id, c.numero_cuenta, c.usuario_id, c.saldo_cent, c.estado,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente
               FROM cuentas c JOIN usuarios u ON u.id = c.usuario_id
              WHERE c.id = ? AND c.es_tecnica = 0',
            [$cuentaId]
        );

        if ($cuenta === null) {
            throw new \InvalidArgumentException('Esa cuenta no existe.');
        }

        if ((string) $cuenta['estado'] !== 'activa') {
            throw new \RuntimeException(
                'La cuenta está ' . $cuenta['estado'] . ': no admite movimientos.'
            );
        }

        $concepto = mb_substr(
            trim((string) ($datos['concepto'] ?? '')) ?: ('Nota de ' . $tipo),
            0,
            190
        );

        // El turno de caja, si quien la emite está en ventanilla: así la
        // nota también cuadra contra el efectivo del día.
        $sesionId = BaseDatos::unValor(
            'SELECT id FROM caja_sesiones WHERE usuario_id = ? AND estado = "abierta" LIMIT 1',
            [$actorId]
        );

        $folio = Folio::unico(
            'N' . strtoupper(substr($tipo, 0, 1)),
            'notas_contables', 'folio', 'ymd'
        );

        BaseDatos::ejecutar(
            'INSERT INTO notas_contables
               (uuid, folio, tipo, cuenta_id, usuario_id, importe_cent,
                concepto, motivo, sesion_id, emitida_por)
             VALUES (UUID(),?,?,?,?,?,?,?,?,?)',
            [
                $folio, $tipo, $cuentaId, (int) $cuenta['usuario_id'],
                $monto->centavos, $concepto, mb_substr($motivo, 0, 500),
                $sesionId === null ? null : (int) $sesionId, $actorId,
            ]
        );

        $id = BaseDatos::ultimoId();

        Auditoria::registrar('nota.emitida', Auditoria::EXITO, $actorId,
            'nota_contable', (string) $id,
            ['folio' => $folio, 'tipo' => $tipo, 'monto' => $monto->aDecimal(),
             'cuenta' => $cuenta['numero_cuenta']]);

        // Quien autoriza se entera en el momento: una nota pendiente
        // detiene al cliente en la ventanilla.
        foreach ($this->autorizadores() as $uid) {
            $this->notificaciones->crear(
                $uid,
                'nota.pendiente',
                'Nota de ' . $tipo . ' pendiente de autorización',
                'La nota ' . $folio . ' por ' . $monto->formateado()
                . ' sobre la cuenta ' . $cuenta['numero_cuenta'] . ' de '
                . $cuenta['cliente'] . ' espera autorización. Motivo: '
                . mb_substr($motivo, 0, 180),
                ['folio' => $folio],
                'alerta'
            );
        }

        return [
            'id'      => $id,
            'folio'   => $folio,
            'tipo'    => $tipo,
            'monto'   => $monto->jsonSerialize(),
            'cuenta'  => $cuenta['numero_cuenta'],
            'cliente' => $cuenta['cliente'],
            'estado'  => 'pendiente',
        ];
    }

    /**
     * Autoriza la nota y la aplica: aquí es donde se mueve el dinero.
     *
     * @return array<string,mixed>
     */
    public function autorizar(int $notaId): array
    {
        Autorizacion::exigir('nota.autorizar');
        $actorId = Sesion::exigirAutenticado();

        return BaseDatos::enTransaccion(function () use ($notaId, $actorId) {
            $n = BaseDatos::unaFila(
                'SELECT * FROM notas_contables WHERE id = ? FOR UPDATE',
                [$notaId]
            );

            if ($n === null) {
                throw new \RuntimeException('Nota no encontrada.');
            }

            if ((string) $n['estado'] !== 'pendiente') {
                throw new \RuntimeException('Esta nota ya está ' . $n['estado'] . '.');
            }

            // Los cuatro ojos: quien la emitió no la autoriza.
            if ((int) $n['emitida_por'] === $actorId) {
                throw new \RuntimeException(
                    'Una nota la tiene que autorizar una persona distinta de quien la emitió.'
                );
            }

            $monto    = Monto::deCentavos((int) $n['importe_cent']);
            $cuentaId = (int) $n['cuenta_id'];
            $esAbono  = (string) $n['tipo'] === 'abono';

            $tecnica = (int) BaseDatos::unValor(
                'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
            );

            if ($tecnica === 0) {
                throw new \RuntimeException('No hay cuenta técnica configurada.');
            }

            $bloq   = LibroMayor::bloquearCuentas([$cuentaId, $tecnica]);
            $cuenta = $bloq[$cuentaId] ?? null;

            if ($cuenta === null) {
                throw new \RuntimeException('La cuenta de la nota ya no existe.');
            }

            /* Un cargo no puede dejar la cuenta en descubierto.
             *
             * Es la diferencia entre corregir un error y crear otro: si
             * el cargo no cabe, hay que resolverlo con el cliente, no
             * dejarle el saldo en negativo y que se entere después. */
            if (!$esAbono) {
                $disponible = (int) $cuenta['saldo_cent']
                    - (int) $cuenta['saldo_retenido_cent'];

                if ($monto->centavos > $disponible) {
                    throw new \RuntimeException(
                        'El cargo de ' . $monto->formateado() . ' deja la cuenta en '
                        . 'descubierto: solo hay ' . Monto::deCentavos(max(0, $disponible))->formateado()
                        . ' disponible.'
                    );
                }
            }

            $importe = $esAbono ? $monto : $monto->negado();

            $r = LibroMayor::registrar(
                $esAbono ? 'nota_abono' : 'nota_cargo',
                [
                    ['cuenta_id' => $cuentaId, 'importe' => $importe,
                     'descripcion' => (string) $n['concepto']],
                    ['cuenta_id' => $tecnica, 'importe' => $importe->negado(),
                     'descripcion' => 'Contrapartida de ' . $n['folio']],
                ],
                $actorId,
                'nota-' . $n['uuid'],
                (string) $n['concepto']
            );

            BaseDatos::ejecutar(
                'UPDATE notas_contables
                    SET estado = "aplicada", autorizada_por = ?, autorizada_en = NOW(),
                        asiento_id = ?
                  WHERE id = ?',
                [$actorId, (int) $r['asiento_id'], $notaId]
            );

            // Si la emitió un cajero con turno abierto, el efectivo
            // también se mueve en su caja.
            if ($n['sesion_id'] !== null) {
                $abierta = BaseDatos::unValor(
                    'SELECT 1 FROM caja_sesiones WHERE id = ? AND estado = "abierta"',
                    [(int) $n['sesion_id']]
                );

                if ($abierta !== null) {
                    BaseDatos::ejecutar(
                        'INSERT INTO caja_movimientos
                           (sesion_id, tipo, importe_cent, cuenta_id, cliente_id,
                            asiento_id, referencia, concepto)
                         VALUES (?,?,?,?,?,?,?,?)',
                        [
                            (int) $n['sesion_id'],
                            $esAbono ? 'nota_abono' : 'nota_cargo',
                            // Un abono al cliente sale de la caja; un
                            // cargo entra. Desde el cajón se ve al revés
                            // que desde la cuenta del cliente.
                            $esAbono ? -$monto->centavos : $monto->centavos,
                            $cuentaId,
                            (int) $n['usuario_id'],
                            (int) $r['asiento_id'],
                            (string) $n['folio'],
                            (string) $n['concepto'],
                        ]
                    );
                }
            }

            Auditoria::registrar('nota.autorizada', Auditoria::EXITO, $actorId,
                'nota_contable', (string) $notaId,
                ['folio' => $n['folio'], 'asiento' => $r['asiento_id']]);

            $this->notificaciones->crear(
                (int) $n['usuario_id'],
                'nota.aplicada',
                $esAbono ? 'Abono aplicado a su cuenta' : 'Cargo aplicado a su cuenta',
                ($esAbono
                    ? 'Le informamos que abonamos ' . $monto->formateado() . ' a su cuenta'
                    : 'Le informamos que aplicamos un cargo de ' . $monto->formateado()
                      . ' a su cuenta')
                . '. Concepto: ' . $n['concepto']
                . '. Si tiene cualquier duda, con gusto se la aclaramos.',
                [
                    'folio'      => (string) $n['folio'],
                    'monto'      => $monto->formateado(),
                    'referencia' => (string) $r['referencia'],
                ],
                $esAbono ? 'exito' : 'alerta'
            );

            return [
                'folio'      => (string) $n['folio'],
                'estado'     => 'aplicada',
                'referencia' => (string) $r['referencia'],
                'monto'      => $monto->jsonSerialize(),
            ];
        });
    }

    /** Rechaza una nota sin aplicarla. */
    public function rechazar(int $notaId, string $motivo): array
    {
        Autorizacion::exigir('nota.autorizar');
        $actorId = Sesion::exigirAutenticado();

        if (mb_strlen(trim($motivo)) < 5) {
            throw new \InvalidArgumentException(
                'Indique por qué no se autoriza: quien la emitió necesita saberlo.'
            );
        }

        $n = BaseDatos::unaFila('SELECT * FROM notas_contables WHERE id = ?', [$notaId]);

        if ($n === null) {
            throw new \RuntimeException('Nota no encontrada.');
        }

        if ((string) $n['estado'] !== 'pendiente') {
            throw new \RuntimeException('Esta nota ya está ' . $n['estado'] . '.');
        }

        BaseDatos::ejecutar(
            'UPDATE notas_contables
                SET estado = "rechazada", autorizada_por = ?, autorizada_en = NOW(),
                    motivo_anulacion = ?
              WHERE id = ?',
            [$actorId, mb_substr(trim($motivo), 0, 300), $notaId]
        );

        Auditoria::registrar('nota.rechazada', Auditoria::EXITO, $actorId,
            'nota_contable', (string) $notaId,
            ['folio' => $n['folio'], 'motivo' => mb_substr($motivo, 0, 200)]);

        $this->notificaciones->crear(
            (int) $n['emitida_por'],
            'nota.rechazada',
            'No se autorizó la nota ' . $n['folio'],
            'La nota ' . $n['folio'] . ' no fue autorizada. Motivo: '
            . mb_substr(trim($motivo), 0, 250),
            ['folio' => (string) $n['folio']],
            'alerta'
        );

        return ['folio' => (string) $n['folio'], 'estado' => 'rechazada'];
    }

    /**
     * Anula una nota ya aplicada, con su reverso contable.
     *
     * No se borra ni se edita: se emite el asiento contrario. El error
     * queda a la vista, que es lo correcto —un libro donde los errores
     * desaparecen no sirve para auditar nada—.
     */
    public function anular(int $notaId, string $motivo): array
    {
        Autorizacion::exigir('nota.anular');
        $actorId = Sesion::exigirAutenticado();

        if (mb_strlen(trim($motivo)) < 10) {
            throw new \InvalidArgumentException(
                'Explique el motivo de la anulación con detalle.'
            );
        }

        return BaseDatos::enTransaccion(function () use ($notaId, $motivo, $actorId) {
            $n = BaseDatos::unaFila(
                'SELECT * FROM notas_contables WHERE id = ? FOR UPDATE',
                [$notaId]
            );

            if ($n === null) {
                throw new \RuntimeException('Nota no encontrada.');
            }

            if ((string) $n['estado'] !== 'aplicada') {
                throw new \RuntimeException(
                    'Solo se anula una nota aplicada. Esta está ' . $n['estado'] . '.'
                );
            }

            $monto    = Monto::deCentavos((int) $n['importe_cent']);
            $cuentaId = (int) $n['cuenta_id'];
            $esAbono  = (string) $n['tipo'] === 'abono';

            $tecnica = (int) BaseDatos::unValor(
                'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
            );

            LibroMayor::bloquearCuentas([$cuentaId, $tecnica]);

            // El reverso: exactamente al revés que la nota original.
            $importe = $esAbono ? $monto->negado() : $monto;

            $r = LibroMayor::registrar(
                'reverso',
                [
                    ['cuenta_id' => $cuentaId, 'importe' => $importe,
                     'descripcion' => 'Anulación de ' . $n['folio']],
                    ['cuenta_id' => $tecnica, 'importe' => $importe->negado(),
                     'descripcion' => 'Contrapartida de anulación ' . $n['folio']],
                ],
                $actorId,
                'anula-' . $n['uuid'],
                'Anulación de la nota ' . $n['folio']
            );

            BaseDatos::ejecutar(
                'UPDATE notas_contables
                    SET estado = "anulada", anulada_por = ?, anulada_en = NOW(),
                        motivo_anulacion = ?
                  WHERE id = ?',
                [$actorId, mb_substr(trim($motivo), 0, 300), $notaId]
            );

            Auditoria::registrar('nota.anulada', Auditoria::EXITO, $actorId,
                'nota_contable', (string) $notaId,
                ['folio' => $n['folio'], 'reverso' => $r['asiento_id'],
                 'motivo' => mb_substr($motivo, 0, 200)]);

            $this->notificaciones->crear(
                (int) $n['usuario_id'],
                'nota.anulada',
                'Se revirtió un movimiento de su cuenta',
                'Dejamos sin efecto la nota ' . $n['folio'] . ' por '
                . $monto->formateado() . ', de modo que su saldo vuelve a como estaba. '
                . 'Motivo: ' . mb_substr(trim($motivo), 0, 200)
                . '. Lamentamos la molestia y quedamos a sus órdenes.',
                [
                    'folio'      => (string) $n['folio'],
                    'monto'      => $monto->formateado(),
                    'referencia' => (string) $r['referencia'],
                ],
                'info'
            );

            return [
                'folio'      => (string) $n['folio'],
                'estado'     => 'anulada',
                'referencia' => (string) $r['referencia'],
            ];
        });
    }

    // -----------------------------------------------------------------

    /** @return list<int> */
    private function autorizadores(): array
    {
        $filas = BaseDatos::todas(
            'SELECT DISTINCT u.id
               FROM usuarios u
               JOIN roles r        ON r.id = u.rol_id
               JOIN rol_permiso rp ON rp.rol_id = r.id
               JOIN permisos p     ON p.id = rp.permiso_id
              WHERE p.clave = "nota.autorizar" AND u.estado = "activo"'
        );

        return array_map(static fn(array $f): int => (int) $f['id'], $filas);
    }
}
