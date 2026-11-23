<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * La constancia que se paga en efectivo, en ventanilla.
 *
 * POR QUÉ HACE FALTA UN PASO INTERMEDIO
 *
 * El cliente llega a la agencia y pide una constancia de saldo. Quien
 * lo atiende en el módulo de atención al cliente puede prepararla
 * entera… menos cobrarla: atención no tiene gaveta ni arqueo. El
 * efectivo lo recibe la caja, y solo la caja.
 *
 * Así que lo que atención genera es una SOLICITUD con su folio. El
 * cliente pasa a caja con ese folio, paga, y el cajero cobra y emite.
 * Es lo mismo que ya pasa en el banco con cualquier trámite que se
 * paga en ventanilla.
 *
 * POR QUÉ NO SE EMITE ANTES DE COBRAR
 *
 * Porque la constancia ES el producto. Emitirla primero dejaría un
 * documento auténtico y verificable circulando con el cobro pendiente,
 * y anularlo después —con el cliente ya enseñándolo en una embajada—
 * no es una opción.
 *
 * POR QUÉ CADUCA
 *
 * Una constancia de saldo certifica el saldo del día en que se emite.
 * Si la solicitud se pagara tres semanas después, el documento diría
 * el saldo de hoy bajo un trámite de hace tres semanas, y el cliente
 * pensaría que le certificaron otra cosa. El plazo vive en
 * `constancia_config.horas_vigencia_solicitud`.
 */
final class ServicioSolicitudConstancia
{
    public function __construct(
        private ServicioConstancias $constancias = new ServicioConstancias(),
        private ServicioSesionCaja $caja = new ServicioSesionCaja(),
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    // =================================================================
    // Configuración
    // =================================================================

    private function config(string $clave, string $porOmision): string
    {
        $v = BaseDatos::unValor(
            'SELECT valor FROM constancia_config WHERE clave = ?',
            [$clave]
        );

        return $v === null ? $porOmision : (string) $v;
    }

    // =================================================================
    // Atención al cliente genera la solicitud
    // =================================================================

    /**
     * @param array{
     *   tipo:string, usuario_id:int, objeto_id?:int,
     *   dirigida_a?:string, proposito?:string
     * } $datos
     * @return array<string,mixed>
     */
    public function generar(array $datos): array
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('constancia.emitir_terceros');

        $tipo   = (string) ($datos['tipo'] ?? '');
        $paraId = (int) ($datos['usuario_id'] ?? 0);

        $arancel = BaseDatos::unaFila(
            'SELECT * FROM constancia_aranceles WHERE tipo = ? AND activa = 1',
            [$tipo]
        );

        if ($arancel === null) {
            throw new ConstanciaInvalida('Ese tipo de constancia no está disponible.');
        }

        $cliente = BaseDatos::unaFila(
            'SELECT id, CONCAT(nombres," ",apellidos) AS nombre, documento_identidad
               FROM usuarios WHERE id = ? AND estado = "activo"',
            [$paraId]
        );

        if ($cliente === null) {
            throw new ConstanciaInvalida('Ese cliente no existe o no está activo.');
        }

        $comision = (int) $arancel['comision_cent'];

        /* Una constancia gratis no tiene nada que cobrar, así que
           mandarla a caja sería mandar al cliente a hacer una cola para
           pagar cero. Se emite en el acto desde atención. */
        if ($comision === 0) {
            $r = $this->constancias->emitir([
                'tipo'       => $tipo,
                'usuario_id' => $paraId,
                'objeto_id'  => $datos['objeto_id'] ?? null,
                'dirigida_a' => $datos['dirigida_a'] ?? '',
                'proposito'  => $datos['proposito'] ?? '',
                'forma_pago' => 'exenta',
            ]);

            return $r + ['directa' => true];
        }

        $horas = max(1, (int) $this->config('horas_vigencia_solicitud', '24'));
        $folio = Folio::unico('SCT', 'constancia_solicitudes', 'folio', 'ymd', 5);

        /* El objeto se valida AQUÍ y no al cobrar. Si la cuenta no es
           del cliente, el cajero se enteraría con el dinero ya en la
           mano y el cliente haciendo cola por nada. */
        $objetoTipo = 'global';
        $objetoId   = null;

        if (in_array($tipo, ['saldo', 'referencia_bancaria'], true)
            && (int) ($datos['objeto_id'] ?? 0) > 0) {
            $objetoId = (int) $datos['objeto_id'];
            $objetoTipo = 'cuenta';

            $suya = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM cuentas WHERE id = ? AND usuario_id = ? AND es_tecnica = 0',
                [$objetoId, $paraId]
            );

            if ($suya === 0) {
                throw new ConstanciaInvalida('Esa cuenta no es del cliente.');
            }
        }

        if ($tipo === 'solvencia' && (int) ($datos['objeto_id'] ?? 0) > 0) {
            $objetoId   = (int) $datos['objeto_id'];
            $objetoTipo = 'credito';

            $suyo = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM creditos WHERE id = ? AND usuario_id = ?',
                [$objetoId, $paraId]
            );

            if ($suyo === 0) {
                throw new ConstanciaInvalida('Ese crédito no es del cliente.');
            }
        }

        BaseDatos::ejecutar(
            'INSERT INTO constancia_solicitudes
               (folio, usuario_id, tipo, objeto_tipo, objeto_id,
                dirigida_a, proposito, comision_cent, generada_por, vence_en)
             VALUES (?,?,?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? HOUR))',
            [
                $folio,
                $paraId,
                $tipo,
                $objetoTipo,
                $objetoId,
                mb_substr(trim((string) ($datos['dirigida_a'] ?? '')), 0, 160),
                mb_substr(trim((string) ($datos['proposito'] ?? '')), 0, 255),
                $comision,
                $actorId,
                $horas,
            ]
        );

        $id = BaseDatos::ultimoId();

        Auditoria::registrar(
            'constancia.solicitada',
            Auditoria::EXITO,
            $actorId,
            'constancia_solicitud',
            $folio,
            ['tipo' => $tipo, 'para' => $paraId,
             'arancel' => Monto::deCentavos($comision)->aDecimal()]
        );

        $this->notificaciones->crear(
            $paraId,
            'constancia.solicitada',
            'Su ' . mb_strtolower((string) $arancel['nombre']) . ' le espera en caja',
            'Pase a cualquier caja con el folio ' . $folio . ' y pague '
            . Monto::deCentavos($comision)->formateado() . '. Se la entregamos ahí '
            . 'mismo. Tiene ' . $horas . ' horas: pasado ese plazo habría que '
            . 'volver a pedirla, porque certifica los datos del día en que se emite.',
            ['folio' => $folio],
            'info'
        );

        return [
            'id'            => $id,
            'folio'         => $folio,
            'tipo'          => $tipo,
            'nombre'        => $arancel['nombre'],
            'cliente'       => $cliente['nombre'],
            'comision_cent' => $comision,
            'horas'         => $horas,
            'directa'       => false,
        ];
    }

    // =================================================================
    // La caja la cobra y la emite
    // =================================================================

    /**
     * El cajero recibe el efectivo y la constancia nace.
     *
     * @return array<string,mixed>
     */
    public function cobrar(string $folio, string $medio = 'efectivo'): array
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('constancia.cobrar');

        /* Sin turno abierto no hay dónde anotar el efectivo, y un cobro
           que no entra en el arqueo le sale al cajero como sobrante al
           cerrar. Se para antes de tocar nada. */
        $turno = $this->caja->turnoActual($actorId);

        if ($turno === null) {
            throw new ConstanciaInvalida(
                'Abra su caja antes de cobrar: el efectivo tiene que entrar en su arqueo.'
            );
        }

        if (!in_array($medio, ['efectivo', 'cheque', 'tarjeta'], true)) {
            $medio = 'efectivo';
        }

        /* La caducidad se marca AQUÍ, fuera de la transacción, y no
           dentro.

           Dentro, el `UPDATE` que la marca y el `throw` que avisa al
           cajero viven en la misma transacción: al lanzar la excepción
           se deshace también el `UPDATE`, así que la solicitud seguía
           figurando como pendiente de pago después de que el sistema
           acabara de decirle al cajero que había caducado. Volvía a
           salir en la lista, y el siguiente cajero volvía a intentarlo.

           Fuera, la marca queda. Dentro se sigue comprobando —otra
           petición pudo cambiarla entre esta lectura y el bloqueo—,
           pero allí solo se avisa; marcar es cosa de aquí. */
        $previa = BaseDatos::unaFila(
            'SELECT id, estado, vence_en FROM constancia_solicitudes WHERE folio = ?',
            [$folio]
        );

        if ($previa !== null
            && $previa['estado'] === 'pendiente_pago'
            && strtotime((string) $previa['vence_en']) < time()) {
            BaseDatos::ejecutar(
                'UPDATE constancia_solicitudes SET estado = "vencida"
                  WHERE id = ? AND estado = "pendiente_pago"',
                [(int) $previa['id']]
            );

            throw new ConstanciaInvalida(
                'Esa solicitud caducó el '
                . date('d/m/Y H:i', (int) strtotime((string) $previa['vence_en']))
                . '. Certificaría datos de otro día; pídala de nuevo, sin costo '
                . 'adicional de trámite.'
            );
        }

        return BaseDatos::enTransaccion(function () use ($folio, $actorId, $turno, $medio): array {
            $s = BaseDatos::unaFila(
                'SELECT * FROM constancia_solicitudes WHERE folio = ? FOR UPDATE',
                [$folio]
            );

            if ($s === null) {
                throw new ConstanciaInvalida('No existe una solicitud con ese folio.');
            }

            /* Cobrar dos veces la misma solicitud es la forma más fácil
               de quedarse con el dinero de un cliente sin darse cuenta:
               el folio se dicta a mano y se repite. */
            if ($s['estado'] !== 'pendiente_pago') {
                throw new ConstanciaInvalida(match ($s['estado']) {
                    'emitida', 'pagada' => 'Esa solicitud ya se cobró y se entregó.',
                    'anulada'           => 'Esa solicitud fue anulada.',
                    'vencida'           => 'Esa solicitud caducó. El cliente debe pedirla de nuevo.',
                    default             => 'Esa solicitud ya no está pendiente de pago.',
                });
            }

            /* El plazo, otra vez y ya con la fila bloqueada: entre la
               comprobación de arriba y este bloqueo cabe una petición
               de otro cajero. Aquí solo se avisa; marcarla se hizo
               fuera, donde la marca sobrevive al aviso. */
            if (strtotime((string) $s['vence_en']) < time()) {
                throw new ConstanciaInvalida(
                    'Esa solicitud caducó el ' . date('d/m/Y H:i', (int) strtotime((string) $s['vence_en']))
                    . '. Certificaría datos de otro día; pídala de nuevo, sin costo adicional '
                    . 'de trámite.'
                );
            }

            $comision = (int) $s['comision_cent'];

            /* El efectivo entra en la gaveta. Esto es lo que hace que
               el cobro aparezca en el corte del cajero: sin esta fila,
               el dinero estaría en el cajón y el arqueo lo daría por
               sobrante. */
            $movId = $this->caja->anotarSiHayTurno(
                'constancia',
                $comision,
                'Arancel de constancia · ' . $folio,
                [
                    'medio'      => $medio,
                    'cliente_id' => (int) $s['usuario_id'],
                ]
            );

            if ($movId === null) {
                throw new ConstanciaInvalida(
                    'No se pudo anotar el cobro en su caja. Revise que su turno siga abierto.'
                );
            }

            $referencia = (string) (BaseDatos::unValor(
                'SELECT referencia FROM caja_movimientos WHERE id = ?',
                [$movId]
            ) ?? '');

            /* Y ahora sí: la constancia. Va dentro de la misma
               transacción que el cobro, así que o están las dos cosas o
               no está ninguna. */
            $r = $this->constancias->emitir([
                'tipo'            => (string) $s['tipo'],
                'usuario_id'      => (int) $s['usuario_id'],
                'objeto_id'       => $s['objeto_id'] === null ? null : (int) $s['objeto_id'],
                'dirigida_a'      => (string) $s['dirigida_a'],
                'proposito'       => (string) $s['proposito'],
                'forma_pago'      => 'efectivo',
                'solicitud_id'    => (int) $s['id'],
                'pago_referencia' => $referencia,
            ]);

            $constanciaId = (int) BaseDatos::unValor(
                'SELECT id FROM constancias WHERE folio = ?',
                [$r['folio']]
            );

            BaseDatos::ejecutar(
                'UPDATE constancia_solicitudes
                    SET estado = "emitida", pagada_en = NOW(), cobrada_por = ?,
                        caja_sesion_id = ?, caja_movimiento_id = ?, constancia_id = ?
                  WHERE id = ?',
                [$actorId, (int) $turno['id'], $movId, $constanciaId, (int) $s['id']]
            );

            Auditoria::registrar(
                'constancia.cobrada',
                Auditoria::EXITO,
                $actorId,
                'constancia_solicitud',
                $folio,
                ['constancia' => $r['folio'],
                 'importe' => Monto::deCentavos($comision)->aDecimal(),
                 'medio' => $medio]
            );

            return $r + [
                'solicitud'      => $folio,
                'cobrado_cent'   => $comision,
                'caja_movimiento' => $movId,
            ];
        });
    }

    // =================================================================
    // Consultas
    // =================================================================

    /**
     * Lo que la caja tiene por cobrar.
     *
     * @return array<int,array<string,mixed>>
     */
    public function pendientes(int $limite = 100): array
    {
        Autorizacion::exigir('constancia.cobrar');

        return BaseDatos::todas(
            'SELECT s.*, a.nombre AS tipo_nombre,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente,
                    u.documento_identidad,
                    CONCAT(g.nombres," ",g.apellidos) AS generada_por_nombre,
                    TIMESTAMPDIFF(MINUTE, NOW(), s.vence_en) AS minutos_restantes
               FROM constancia_solicitudes s
               JOIN usuarios u ON u.id = s.usuario_id
               JOIN usuarios g ON g.id = s.generada_por
               LEFT JOIN constancia_aranceles a ON a.tipo = s.tipo
              WHERE s.estado = "pendiente_pago"
              ORDER BY s.vence_en
              LIMIT ' . max(1, min(500, $limite))
        );
    }

    /** Una sola, por folio, para la pantalla de cobro. */
    public function porFolio(string $folio): ?array
    {
        return BaseDatos::unaFila(
            'SELECT s.*, a.nombre AS tipo_nombre,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente,
                    u.documento_identidad,
                    TIMESTAMPDIFF(MINUTE, NOW(), s.vence_en) AS minutos_restantes
               FROM constancia_solicitudes s
               JOIN usuarios u ON u.id = s.usuario_id
               LEFT JOIN constancia_aranceles a ON a.tipo = s.tipo
              WHERE s.folio = ?',
            [$folio]
        );
    }

    /**
     * Las del propio cliente, para que vea en su banca lo que le espera
     * en caja.
     *
     * @return array<int,array<string,mixed>>
     */
    public function deUsuario(int $usuarioId, int $limite = 20): array
    {
        return BaseDatos::todas(
            'SELECT s.folio, s.tipo, s.estado, s.comision_cent, s.generada_en,
                    s.vence_en, a.nombre AS tipo_nombre,
                    TIMESTAMPDIFF(MINUTE, NOW(), s.vence_en) AS minutos_restantes
               FROM constancia_solicitudes s
               LEFT JOIN constancia_aranceles a ON a.tipo = s.tipo
              WHERE s.usuario_id = ?
              ORDER BY s.generada_en DESC
              LIMIT ' . max(1, min(100, $limite)),
            [$usuarioId]
        );
    }

    // =================================================================
    // Anular y caducar
    // =================================================================

    public function anular(string $folio, string $motivo): void
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('constancia.emitir_terceros');

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new ConstanciaInvalida('Diga por qué se anula.');
        }

        $n = BaseDatos::ejecutar(
            'UPDATE constancia_solicitudes
                SET estado = "anulada", motivo_anulacion = ?
              WHERE folio = ? AND estado = "pendiente_pago"',
            [mb_substr($motivo, 0, 255), $folio]
        );

        if ($n === 0) {
            throw new ConstanciaInvalida(
                'Esa solicitud no existe o ya no está pendiente de pago.'
            );
        }

        Auditoria::registrar(
            'constancia.solicitud_anulada',
            Auditoria::EXITO,
            $actorId,
            'constancia_solicitud',
            $folio,
            ['motivo' => $motivo]
        );
    }

    /**
     * Caduca las que nadie pagó. La corre la tarea periódica.
     *
     * Devuelve cuántas caducaron.
     */
    public function vencer(): int
    {
        $viejas = BaseDatos::todas(
            'SELECT id, folio, usuario_id, comision_cent
               FROM constancia_solicitudes
              WHERE estado = "pendiente_pago" AND vence_en < NOW()'
        );

        if ($viejas === []) {
            return 0;
        }

        foreach ($viejas as $v) {
            BaseDatos::ejecutar(
                'UPDATE constancia_solicitudes SET estado = "vencida" WHERE id = ?',
                [(int) $v['id']]
            );

            /* Se le avisa al cliente. Que su trámite caduque en
               silencio y lo descubra haciendo cola en la caja es
               exactamente lo que no debe pasar. */
            $this->notificaciones->crear(
                (int) $v['usuario_id'],
                'constancia.vencida',
                'Su solicitud de constancia caducó',
                'La solicitud ' . $v['folio'] . ' venció sin pagarse, así que ya no '
                . 'se puede cobrar en caja: certificaría datos de otro día. No se le '
                . 'cobró nada. Puede volver a pedirla cuando guste.',
                ['folio' => $v['folio']],
                'info'
            );
        }

        return count($viejas);
    }
}
