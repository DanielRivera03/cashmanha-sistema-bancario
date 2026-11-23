<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Cumplimiento\UmbralesUIF;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Reclamos formales.
 *
 * QUÉ LOS DISTINGUE DE UN TICKET DE SOPORTE
 *
 * Un ticket es «no me carga la pantalla». Un reclamo es «me cobraron
 * algo que no debía» o «esa operación no la hice yo»: un acto con
 * consecuencias jurídicas, con plazo legal de respuesta, con derecho
 * de escalamiento, y cuya resolución puede devolver dinero.
 *
 * Mezclarlos habría tenido un costo concreto: un reclamo con plazo
 * legal perdido entre cincuenta preguntas de «cómo cambio mi clave»,
 * y nadie capaz de contestar «¿cuántos reclamos tenemos fuera de
 * plazo?».
 *
 * EL PLAZO SE CUENTA EN DÍAS HÁBILES
 *
 * Lo dice la Ley de Protección al Consumidor para sus términos.
 * Contarlo corrido daría una fecha límite ANTERIOR a la real, que en
 * un plazo legal es el error que conviene: avisa antes, nunca después.
 *
 * LO QUE EL SISTEMA NO PUEDE INSINUAR
 *
 * Que el cliente tenga que esperar al banco antes de ir a la
 * Defensoría del Consumidor. Puede acudir sin agotar el proceso con
 * el proveedor, y la pantalla lo dice.
 */
final class ServicioReclamos
{
    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    public function parametro(string $clave, string $porDefecto = '0'): string
    {
        $v = BaseDatos::unValor(
            'SELECT valor FROM reclamo_parametros WHERE clave = ?',
            [$clave]
        );

        return $v !== null ? (string) $v : $porDefecto;
    }

    // =================================================================
    // Presentar
    // =================================================================

    /**
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    public function presentar(array $datos): array
    {
        $actorId = Sesion::exigirAutenticado();

        Autorizacion::exigir('reclamo.presentar');

        $paraId = (int) ($datos['usuario_id'] ?? $actorId);

        if ($paraId !== $actorId) {
            Autorizacion::exigir('reclamo.atender');
        }

        $asunto = trim((string) ($datos['asunto'] ?? ''));
        $relato = trim((string) ($datos['relato'] ?? ''));

        if (mb_strlen($asunto) < 5) {
            throw new ReclamoInvalido('Resuma en una línea de qué se trata.');
        }

        /* Un relato corto no sostiene un reclamo, y pedirlo después
           alarga el plazo para el propio cliente. */
        if (mb_strlen($relato) < 30) {
            throw new ReclamoInvalido(
                'Cuente qué pasó con detalle: cuándo, por cuánto y qué espera que hagamos. '
                . 'Cuanto más completo, más rápido se resuelve.'
            );
        }

        $categorias = ['cargo_indebido', 'operacion_no_reconocida', 'pago_mal_aplicado',
                       'cobro_de_intereses', 'comisiones', 'tarjeta', 'credito',
                       'cuenta', 'atencion', 'informacion_crediticia', 'otro'];

        $categoria = (string) ($datos['categoria'] ?? 'otro');

        if (!in_array($categoria, $categorias, true)) {
            $categoria = 'otro';
        }

        $monto = 0;

        if (($datos['monto'] ?? '') !== '' && (float) $datos['monto'] > 0) {
            $monto = Monto::deEntradaUsuario($datos['monto'])->centavos;
        }

        $dias = max(1, (int) $this->parametro('dias_habiles_respuesta', '30'));

        $vence = UmbralesUIF::sumarHabiles(new \DateTimeImmutable(), $dias);

        $folio = Folio::unico('REC', 'reclamos', 'folio', 'ymd', 5);

        $id = BaseDatos::enTransaccion(function () use (
            $folio, $paraId, $categoria, $asunto, $relato, $monto, $datos, $vence, $actorId
        ): int {
            BaseDatos::ejecutar(
                'INSERT INTO reclamos
                   (uuid, folio, usuario_id, canal, categoria, asunto, relato,
                    monto_cent, objeto_tipo, objeto_id, referencia, estado, vence_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "recibido", ?)',
                [
                    LibroMayor::uuid4(), $folio, $paraId,
                    in_array($datos['canal'] ?? '', ['banca_en_linea', 'agencia', 'telefono',
                                                      'correo', 'defensoria'], true)
                        ? $datos['canal'] : 'banca_en_linea',
                    $categoria, $asunto, $relato, $monto,
                    in_array($datos['objeto_tipo'] ?? '', ['cuenta', 'tarjeta', 'credito'], true)
                        ? $datos['objeto_tipo'] : 'ninguno',
                    $datos['objeto_id'] ?? null,
                    mb_substr(trim((string) ($datos['referencia'] ?? '')), 0, 64),
                    $vence->format('Y-m-d'),
                ]
            );

            $id = BaseDatos::ultimoId();

            /* El relato queda también como primer mensaje: así la
               conversación se lee de corrido desde el principio. */
            BaseDatos::ejecutar(
                'INSERT INTO reclamo_mensajes (reclamo_id, de, autor_id, texto)
                 VALUES (?, "cliente", ?, ?)',
                [$id, $actorId, $relato]
            );

            return $id;
        });

        Auditoria::registrar(
            'reclamo.presentado',
            Auditoria::EXITO,
            $actorId,
            'reclamo',
            $folio,
            ['categoria' => $categoria, 'monto' => Monto::deCentavos($monto)->aDecimal()]
        );

        $this->notificaciones->crear(
            $paraId,
            'reclamo.recibido',
            'Recibimos su reclamo',
            'Su reclamo ' . $folio . ' quedó registrado. Le responderemos a más tardar el '
            . $vence->format('d/m/Y') . '. '
            . 'Puede seguirlo desde Reclamos, y si prefiere no esperarnos, tiene derecho '
            . 'a acudir directamente a la Defensoría del Consumidor.',
            ['folio' => $folio],
            'info'
        );

        return [
            'id'       => $id,
            'folio'    => $folio,
            'vence_en' => $vence->format('Y-m-d'),
            'dias'     => $dias,
        ];
    }

    // =================================================================
    // Atención
    // =================================================================

    public function responder(string $folio, string $texto, bool $interno = false): void
    {
        $actorId = Sesion::exigirAutenticado();

        Autorizacion::exigir('reclamo.atender');

        $r = $this->exigir($folio);

        $texto = trim($texto);

        if (mb_strlen($texto) < 10) {
            throw new ReclamoInvalido('Escriba una respuesta de verdad.');
        }

        BaseDatos::ejecutar(
            'INSERT INTO reclamo_mensajes (reclamo_id, de, autor_id, texto, interno)
             VALUES (?, "banco", ?, ?, ?)',
            [(int) $r['id'], $actorId, $texto, $interno ? 1 : 0]
        );

        if ($r['estado'] === 'recibido') {
            BaseDatos::ejecutar(
                'UPDATE reclamos SET estado = "en_analisis", asignado_a = COALESCE(asignado_a, ?)
                  WHERE id = ?',
                [$actorId, (int) $r['id']]
            );
        }

        if (!$interno) {
            $this->notificaciones->crear(
                (int) $r['usuario_id'],
                'reclamo.respuesta',
                'Tiene respuesta en su reclamo',
                'Respondimos a su reclamo ' . $folio . '. Entre a verlo.',
                ['folio' => $folio],
                'info'
            );
        }
    }

    /**
     * Pide información al cliente y SUSPENDE el plazo.
     *
     * El tiempo que el cliente tarda en contestar no cuenta contra el
     * banco. Pero se registra: si no, pedir información sería una
     * excusa para no responder nunca.
     */
    public function pedirInformacion(string $folio, string $queSeNecesita): void
    {
        $actorId = Sesion::exigirAutenticado();

        Autorizacion::exigir('reclamo.atender');

        $r = $this->exigir($folio);

        if ($r['estado'] === 'espera_cliente') {
            throw new ReclamoInvalido('Ya se le pidió información y seguimos esperándola.');
        }

        BaseDatos::enTransaccion(static function () use ($r, $queSeNecesita, $actorId): void {
            BaseDatos::ejecutar(
                'UPDATE reclamos SET estado = "espera_cliente", suspendido_desde = CURDATE()
                  WHERE id = ?',
                [(int) $r['id']]
            );

            BaseDatos::ejecutar(
                'INSERT INTO reclamo_mensajes (reclamo_id, de, autor_id, texto)
                 VALUES (?, "banco", ?, ?)',
                [(int) $r['id'], $actorId, $queSeNecesita]
            );
        });

        $espera = (int) $this->parametro('dias_espera_cliente', '10');

        $this->notificaciones->crear(
            (int) $r['usuario_id'],
            'reclamo.informacion',
            'Necesitamos algo para seguir con su reclamo',
            'Para resolver el reclamo ' . $folio . ' necesitamos que nos envíe algo. '
            . 'Mientras tanto el plazo queda en pausa. Si no recibimos respuesta en '
            . $espera . ' días hábiles, resolveremos con lo que tengamos.',
            ['folio' => $folio],
            'alerta'
        );

        Auditoria::registrar('reclamo.informacion_pedida', Auditoria::EXITO,
            $actorId, 'reclamo', $folio);
    }

    /**
     * El cliente contesta: se reanuda el plazo y se descuenta la espera.
     */
    public function aportar(string $folio, string $texto, int $usuarioId): void
    {
        $r = $this->exigir($folio);

        if ((int) $r['usuario_id'] !== $usuarioId) {
            throw new \CashMan\Infraestructura\Seguridad\AccesoDenegado('Ese reclamo no es suyo.');
        }

        if (in_array($r['estado'], ['resuelto_favorable', 'resuelto_desfavorable', 'desistido'], true)) {
            throw new ReclamoInvalido('Ese reclamo ya está resuelto.');
        }

        BaseDatos::enTransaccion(static function () use ($r, $texto, $usuarioId): void {
            BaseDatos::ejecutar(
                'INSERT INTO reclamo_mensajes (reclamo_id, de, autor_id, texto)
                 VALUES (?, "cliente", ?, ?)',
                [(int) $r['id'], $usuarioId, trim($texto)]
            );

            if ($r['estado'] !== 'espera_cliente') {
                return;
            }

            /* Los días que estuvo esperando se suman a los suspendidos
               y la fecha límite se corre otro tanto: el reloj no
               corrió mientras la pelota estaba del otro lado. */
            $desde = $r['suspendido_desde'] !== null
                ? new \DateTimeImmutable((string) $r['suspendido_desde'])
                : new \DateTimeImmutable();

            $dias = 0;
            $cursor = $desde;
            $hoy = new \DateTimeImmutable(date('Y-m-d'));

            while ($cursor < $hoy) {
                $cursor = $cursor->modify('+1 day');

                if ((int) $cursor->format('N') <= 5) {
                    $dias++;
                }
            }

            $nuevoVence = UmbralesUIF::sumarHabiles(
                new \DateTimeImmutable((string) $r['vence_en']),
                $dias
            );

            BaseDatos::ejecutar(
                'UPDATE reclamos
                    SET estado = "en_analisis", suspendido_desde = NULL,
                        dias_suspendidos = dias_suspendidos + ?, vence_en = ?
                  WHERE id = ?',
                [$dias, $nuevoVence->format('Y-m-d'), (int) $r['id']]
            );
        });
    }

    /**
     * Prorroga el plazo. Una sola vez, y con motivo.
     */
    public function prorrogar(string $folio, string $motivo): string
    {
        $actorId = Sesion::exigirAutenticado();

        Autorizacion::exigir('reclamo.atender');

        $r = $this->exigir($folio);

        if ((int) $r['prorrogado'] === 1) {
            throw new ReclamoInvalido('Ese reclamo ya se prorrogó una vez.');
        }

        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 15) {
            throw new ReclamoInvalido(
                'Escriba por qué hace falta más tiempo: el cliente lo va a leer.'
            );
        }

        $dias = max(1, (int) $this->parametro('dias_habiles_prorroga', '15'));

        $nuevo = UmbralesUIF::sumarHabiles(
            new \DateTimeImmutable((string) $r['vence_en']),
            $dias
        );

        BaseDatos::ejecutar(
            'UPDATE reclamos SET prorrogado = 1, prorroga_motivo = ?, vence_en = ? WHERE id = ?',
            [mb_substr($motivo, 0, 255), $nuevo->format('Y-m-d'), (int) $r['id']]
        );

        $this->notificaciones->crear(
            (int) $r['usuario_id'],
            'reclamo.prorroga',
            'Su reclamo necesita más tiempo',
            'El reclamo ' . $folio . ' se prorrogó hasta el ' . $nuevo->format('d/m/Y')
            . '. Motivo: ' . $motivo,
            ['folio' => $folio],
            'alerta'
        );

        Auditoria::registrar('reclamo.prorrogado', Auditoria::EXITO,
            $actorId, 'reclamo', $folio, ['hasta' => $nuevo->format('Y-m-d')]);

        return $nuevo->format('Y-m-d');
    }

    // =================================================================
    // Resolución
    // =================================================================

    /**
     * Resuelve el reclamo, con o sin devolución de dinero.
     *
     * @return array<string,mixed>
     */
    public function resolver(
        string $folio,
        bool $aFavor,
        string $resolucion,
        int $compensacionCent = 0,
        ?int $cuentaAbonoId = null
    ): array {
        $actorId = Sesion::exigirAutenticado();

        Autorizacion::exigir('reclamo.resolver');

        $r = $this->exigir($folio);

        $resolucion = trim($resolucion);

        /* La resolución la va a leer el cliente, y si no le convence
           irá a la Defensoría con ella en la mano. Una línea suelta no
           sostiene nada. */
        if (mb_strlen($resolucion) < 40) {
            throw new ReclamoInvalido(
                'Explique la resolución con detalle: qué se revisó, qué se encontró y '
                . 'por qué se decide así. El cliente la va a leer, y puede llevarla a '
                . 'la Defensoría del Consumidor.'
            );
        }

        if (!$aFavor && $compensacionCent > 0) {
            throw new ReclamoInvalido(
                'No se puede resolver en contra y devolver dinero a la vez: '
                . 'si se le devuelve algo, el reclamo procedió.'
            );
        }

        return BaseDatos::enTransaccion(function () use (
            $r, $aFavor, $resolucion, $compensacionCent, $cuentaAbonoId, $folio, $actorId
        ): array {
            $asientoId = null;

            if ($compensacionCent > 0) {
                $cuentaId = $cuentaAbonoId ?? (int) (BaseDatos::unValor(
                    'SELECT id FROM cuentas
                      WHERE usuario_id = ? AND estado = "activa" AND es_tecnica = 0
                      ORDER BY id LIMIT 1',
                    [(int) $r['usuario_id']]
                ) ?? 0);

                if ($cuentaId === 0) {
                    throw new ReclamoInvalido(
                        'El cliente no tiene una cuenta activa donde abonarle la devolución.'
                    );
                }

                $general = (int) BaseDatos::unValor(
                    'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
                );

                LibroMayor::bloquearCuentas([$cuentaId, $general]);

                $m = Monto::deCentavos($compensacionCent);

                $asiento = LibroMayor::registrar(
                    'ajuste',
                    [
                        ['cuenta_id' => $general, 'importe' => $m->negado(),
                         'descripcion' => 'Devolución por reclamo ' . $folio],
                        ['cuenta_id' => $cuentaId, 'importe' => $m,
                         'descripcion' => 'Devolución por su reclamo ' . $folio],
                    ],
                    $actorId,
                    'reclamo-' . $folio,
                    'Resolución de reclamo ' . $folio
                );

                $asientoId = (int) $asiento['asiento_id'];
            }

            BaseDatos::ejecutar(
                'UPDATE reclamos
                    SET estado = ?, resolucion = ?, resuelto_en = NOW(), resuelto_por = ?,
                        compensacion_cent = ?, asiento_id = ?
                  WHERE id = ?',
                [
                    $aFavor ? 'resuelto_favorable' : 'resuelto_desfavorable',
                    $resolucion, $actorId, $compensacionCent, $asientoId, (int) $r['id'],
                ]
            );

            BaseDatos::ejecutar(
                'INSERT INTO reclamo_mensajes (reclamo_id, de, autor_id, texto)
                 VALUES (?, "banco", ?, ?)',
                [(int) $r['id'], $actorId, $resolucion]
            );

            Auditoria::registrar(
                $aFavor ? 'reclamo.resuelto_favorable' : 'reclamo.resuelto_desfavorable',
                Auditoria::EXITO,
                $actorId,
                'reclamo',
                $folio,
                ['compensacion' => Monto::deCentavos($compensacionCent)->aDecimal()]
            );

            /* El aviso recuerda el derecho a escalar, SIEMPRE: también
               cuando se resuelve a favor. Que el banco haya dado la
               razón no quita el derecho a no estar de acuerdo con el
               monto o con el plazo. */
            $this->notificaciones->crear(
                (int) $r['usuario_id'],
                'reclamo.resuelto',
                $aFavor ? 'Su reclamo procedió' : 'Resolvimos su reclamo',
                'El reclamo ' . $folio . ' quedó resuelto'
                . ($compensacionCent > 0
                    ? ' y se le abonaron '
                      . Monto::deCentavos($compensacionCent)->formateado() . ' a su cuenta.'
                    : '.')
                . ' Entre a leer la resolución. Si no está de acuerdo, puede acudir a la '
                . 'Defensoría del Consumidor.',
                ['folio' => $folio],
                $aFavor ? 'exito' : 'info'
            );

            return [
                'folio'  => $folio,
                'estado' => $aFavor ? 'resuelto_favorable' : 'resuelto_desfavorable',
                'compensacion_cent' => $compensacionCent,
                'asiento_id' => $asientoId,
            ];
        });
    }

    /** El cliente lo llevó a la Defensoría. */
    public function marcarEscalado(string $folio, string $referencia): void
    {
        $actorId = Sesion::exigirAutenticado();

        Autorizacion::exigir('reclamo.atender');

        $r = $this->exigir($folio);

        BaseDatos::ejecutar(
            'UPDATE reclamos
                SET estado = "escalado", escalado_en = NOW(), referencia_externa = ?
              WHERE id = ?',
            [mb_substr(trim($referencia), 0, 60), (int) $r['id']]
        );

        Auditoria::registrar('reclamo.escalado', Auditoria::EXITO,
            $actorId, 'reclamo', $folio, ['referencia' => $referencia]);
    }

    // =================================================================
    // Vigilancia de plazos
    // =================================================================

    /**
     * @return array{por_vencer:array<int,array<string,mixed>>,
     *               vencidos:array<int,array<string,mixed>>,
     *               esperando:array<int,array<string,mixed>>}
     */
    public function panorama(): array
    {
        $aviso = max(1, (int) $this->parametro('dias_aviso_previo', '5'));
        $espera = max(1, (int) $this->parametro('dias_espera_cliente', '10'));

        /* Calificado con `r.`: `usuarios` también tiene una columna
           `estado`, y sin el prefijo MySQL rechaza la consulta por
           ambigua. */
        $abiertos = "r.estado IN ('recibido','en_analisis','espera_cliente')";

        return [
            'por_vencer' => BaseDatos::todas(
                "SELECT r.*, CONCAT(u.nombres,' ',u.apellidos) AS cliente,
                        DATEDIFF(r.vence_en, CURDATE()) AS dias
                   FROM reclamos r JOIN usuarios u ON u.id = r.usuario_id
                  WHERE {$abiertos} AND r.vence_en BETWEEN CURDATE()
                        AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                  ORDER BY r.vence_en",
                [$aviso]
            ),

            'vencidos' => BaseDatos::todas(
                "SELECT r.*, CONCAT(u.nombres,' ',u.apellidos) AS cliente,
                        DATEDIFF(CURDATE(), r.vence_en) AS dias
                   FROM reclamos r JOIN usuarios u ON u.id = r.usuario_id
                  WHERE {$abiertos} AND r.vence_en < CURDATE()
                  ORDER BY r.vence_en",
                []
            ),

            'esperando' => BaseDatos::todas(
                "SELECT r.*, CONCAT(u.nombres,' ',u.apellidos) AS cliente,
                        DATEDIFF(CURDATE(), r.suspendido_desde) AS dias
                   FROM reclamos r JOIN usuarios u ON u.id = r.usuario_id
                  WHERE r.estado = 'espera_cliente'
                    AND r.suspendido_desde <= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  ORDER BY r.suspendido_desde",
                [$espera]
            ),
        ];
    }

    // =================================================================
    // Consulta
    // =================================================================

    /** @return array<int,array<string,mixed>> */
    public function deUsuario(int $usuarioId): array
    {
        $filas = BaseDatos::todas(
            'SELECT * FROM reclamos WHERE usuario_id = ? ORDER BY recibido_en DESC',
            [$usuarioId]
        );

        foreach ($filas as &$f) {
            $f['mensajes'] = $this->mensajes((int) $f['id'], false);
        }

        return $filas;
    }

    /** @return array<int,array<string,mixed>> */
    public function abiertos(int $limite = 200): array
    {
        return BaseDatos::todas(
            "SELECT r.*, CONCAT(u.nombres,' ',u.apellidos) AS cliente,
                    u.documento_identidad,
                    DATEDIFF(r.vence_en, CURDATE()) AS dias_restantes
               FROM reclamos r JOIN usuarios u ON u.id = r.usuario_id
              WHERE r.estado IN ('recibido','en_analisis','espera_cliente','escalado')
              ORDER BY r.vence_en, r.recibido_en
              LIMIT " . max(1, min(500, $limite))
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function mensajes(int $reclamoId, bool $incluirInternos): array
    {
        return BaseDatos::todas(
            'SELECT m.de, m.texto, m.interno, m.creado_en,
                    CONCAT(u.nombres, " ", u.apellidos) AS autor
               FROM reclamo_mensajes m
               LEFT JOIN usuarios u ON u.id = m.autor_id
              WHERE m.reclamo_id = ?' . ($incluirInternos ? '' : ' AND m.interno = 0') . '
              ORDER BY m.creado_en, m.id',
            [$reclamoId]
        );
    }

    /** @return array<string,mixed> */
    public function porFolio(string $folio): array
    {
        return $this->exigir($folio);
    }

    /** @return array<string,mixed> */
    private function exigir(string $folio): array
    {
        $r = BaseDatos::unaFila('SELECT * FROM reclamos WHERE folio = ?', [$folio]);

        if ($r === null) {
            throw new ReclamoInvalido('No existe el reclamo ' . $folio . '.');
        }

        return $r;
    }
}
