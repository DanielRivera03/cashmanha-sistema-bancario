<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Cifrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Remesas familiares.
 *
 * LO QUE HAY QUE TENER PRESENTE TODO EL TIEMPO
 *
 * Para mucha gente esto no es «un ingreso»: es EL ingreso del mes. Con
 * esto se paga el alquiler y la escuela. Una remesa que no se puede
 * cobrar porque el sistema se puso exigente no es un fallo técnico: es
 * una familia sin comer esa semana.
 *
 * De ahí dos decisiones que parecen laxas y no lo son:
 *
 *   · Se puede cobrar SIN tener cuenta en el banco. La mayoría cobra
 *     en efectivo en ventanilla, y exigir cuenta dejaría fuera
 *     justamente a quien más usa el servicio.
 *
 *   · El beneficiario se identifica por su DOCUMENTO, no por un
 *     usuario del sistema. La remesa puede llegar antes de que esa
 *     persona haya pisado el banco.
 *
 * Y una que parece dura y tampoco lo es: el código de control se
 * guarda cifrado y nunca se muestra entero. Quien tiene el código y un
 * documento cobra; un listado con códigos completos sería un listado
 * de llaves.
 *
 * DE DÓNDE SALE EL DINERO
 *
 * De la remesadora, no del banco. Mientras no se entrega, la
 * remesadora se lo debe al banco. Por eso la contrapartida es una
 * cuenta técnica, igual que la cámara interbancaria.
 */
final class ServicioRemesas
{
    private const CUENTA_REMESAS = '0000000008';

    /**
     * Cuánto tiempo queda disponible antes de que la remesadora la
     * reclame de vuelta. Treinta días es lo habitual.
     */
    public const DIAS_VIGENCIA = 30;

    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    private function cifra(): Cifrado
    {
        return new Cifrado(Config::claveBinaria('APP_CLAVE'));
    }

    // =================================================================
    // Llegada
    // =================================================================

    /**
     * Registra una remesa que llegó de la remesadora.
     *
     * No mueve dinero: lo deja disponible. El asiento se hace cuando
     * se cobra, porque hasta entonces el dinero sigue siendo de la
     * remesadora.
     *
     * @param array{
     *   remesadora:string, codigo:string, beneficiario_nombre:string,
     *   beneficiario_documento:string, monto:mixed,
     *   remitente_nombre?:string, remitente_pais?:string,
     *   moneda_origen?:string, monto_origen?:string
     * } $datos
     * @return array<string,mixed>
     */
    public function registrarLlegada(array $datos): array
    {
        $remesadora = $this->remesadora((string) $datos['remesadora']);

        $codigo = $this->normalizarCodigo((string) $datos['codigo']);

        if (!$this->codigoBienFormado($remesadora, $codigo)) {
            throw new RemesaInvalida(sprintf(
                'El %s de %s debe tener entre %d y %d caracteres.',
                $remesadora['etiqueta_codigo'],
                $remesadora['nombre_corto'],
                (int) $remesadora['codigo_largo_min'],
                (int) $remesadora['codigo_largo_max']
            ));
        }

        $monto = Monto::deEntradaUsuario($datos['monto']);

        $documento = $this->normalizarDocumento((string) $datos['beneficiario_documento']);

        if ($documento === '') {
            throw new RemesaInvalida('Hace falta el documento del beneficiario.');
        }

        $hash = Cifrado::hashBusqueda($codigo, (string) $remesadora['codigo']);

        $yaEsta = BaseDatos::unaFila(
            'SELECT folio, estado FROM remesas WHERE remesadora_id = ? AND codigo_hash = ?',
            [(int) $remesadora['id'], $hash]
        );

        if ($yaEsta !== null) {
            /* Pagar dos veces la misma remesa es un agujero que no se
               detecta hasta que la remesadora reclama. */
            throw new RemesaInvalida(
                'Ese código ya está registrado (folio ' . $yaEsta['folio']
                . ', ' . $yaEsta['estado'] . ').'
            );
        }

        /* Si el beneficiario ya es cliente, se le ata: así la ve en su
           banca en línea sin tener que ir a preguntar.
         *
         * La comparación va NORMALIZADA en los dos lados. En El
         * Salvador el DUI se escribe tanto «01234567-8» como
         * «012345678», y el expediente de un cliente puede tenerlo de
         * una forma mientras la remesadora lo manda de la otra.
         *
         * Comparando en crudo, la remesa de un cliente de toda la vida
         * no se le ataba y no la veía en su banca: tenía que ir a
         * preguntar a una agencia si le había llegado. */
        $usuarioId = BaseDatos::unValor(
            'SELECT id FROM usuarios
              WHERE REPLACE(REPLACE(documento_identidad, "-", ""), " ", "") = ?
                AND estado = "activo"
              LIMIT 1',
            [$documento]
        );

        $folio = Folio::unico('REM', 'remesas', 'folio', 'ymd', 5);
        $uuid  = LibroMayor::uuid4();

        BaseDatos::ejecutar(
            'INSERT INTO remesas
               (uuid, folio, remesadora_id, codigo_cifrado, codigo_hash, codigo_cola,
                beneficiario_nombre, beneficiario_documento, usuario_id,
                remitente_nombre, remitente_pais, monto_cent,
                moneda_origen, monto_origen, tipo_cambio, estado, expira_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "disponible",
                     DATE_ADD(CURDATE(), INTERVAL ? DAY))',
            [
                $uuid,
                $folio,
                (int) $remesadora['id'],
                $this->cifra()->cifrar($codigo, 'remesa'),
                $hash,
                substr($codigo, -4),
                mb_substr(trim((string) $datos['beneficiario_nombre']), 0, 140),
                $documento,
                $usuarioId !== null ? (int) $usuarioId : null,
                mb_substr(trim((string) ($datos['remitente_nombre'] ?? '')), 0, 140),
                strtoupper(substr((string) ($datos['remitente_pais'] ?? 'US'), 0, 2)),
                $monto->centavos,
                strtoupper(substr((string) ($datos['moneda_origen'] ?? 'USD'), 0, 3)),
                (float) ($datos['monto_origen'] ?? $monto->aDecimal()),
                1,
                self::DIAS_VIGENCIA,
            ]
        );

        Auditoria::registrar(
            'remesa.registrada',
            Auditoria::EXITO,
            Sesion::usuarioId(),
            'remesa',
            $folio,
            [
                'remesadora' => $remesadora['codigo'],
                'monto'      => $monto->aDecimal(),
                'codigo'     => '****' . substr($codigo, -4),
            ]
        );

        /* Si es cliente, se entera en el acto: ya no tiene que llamar
           para preguntar si llegó. */
        if ($usuarioId !== null) {
            $this->notificaciones->crear(
                (int) $usuarioId,
                'remesa.disponible',
                'Le llegó una remesa',
                'Tiene ' . $monto->formateado() . ' disponibles de '
                . $remesadora['nombre_corto']
                . (($datos['remitente_nombre'] ?? '') !== ''
                    ? ', enviados por ' . $datos['remitente_nombre'] : '')
                . '. Puede cobrarla en ventanilla o acreditarla a su cuenta.',
                ['folio' => $folio],
                'exito'
            );
        }

        return [
            'folio'        => $folio,
            'uuid'         => $uuid,
            'estado'       => 'disponible',
            'monto_cent'   => $monto->centavos,
            'beneficiario' => $datos['beneficiario_nombre'],
            'es_cliente'   => $usuarioId !== null,
        ];
    }

    // =================================================================
    // Búsqueda
    // =================================================================

    /**
     * Busca una remesa por su código de control.
     *
     * Se busca por el HASH: nunca hay que descifrar la tabla entera
     * para encontrar una, y el código en claro no toca la base.
     *
     * @return array<string,mixed>|null
     */
    public function porCodigo(string $remesadoraCodigo, string $codigo): ?array
    {
        $remesadora = $this->remesadora($remesadoraCodigo);

        $hash = Cifrado::hashBusqueda(
            $this->normalizarCodigo($codigo),
            (string) $remesadora['codigo']
        );

        return BaseDatos::unaFila(
            'SELECT r.*, rm.nombre_corto AS remesadora, rm.etiqueta_codigo
               FROM remesas r
               JOIN remesadoras rm ON rm.id = r.remesadora_id
              WHERE r.remesadora_id = ? AND r.codigo_hash = ?',
            [(int) $remesadora['id'], $hash]
        );
    }

    /**
     * Las remesas dirigidas a un documento.
     *
     * Es como llega la gente a ventanilla: con su DUI, sin recordar el
     * código. Negarle el servicio por eso sería absurdo.
     *
     * @return array<int,array<string,mixed>>
     */
    public function porDocumento(string $documento): array
    {
        return BaseDatos::todas(
            'SELECT r.*, rm.nombre_corto AS remesadora, rm.etiqueta_codigo
               FROM remesas r
               JOIN remesadoras rm ON rm.id = r.remesadora_id
              WHERE r.beneficiario_documento = ?
              ORDER BY FIELD(r.estado, "disponible", "retenida", "pagada", "devuelta"),
                       r.disponible_en DESC',
            [$this->normalizarDocumento($documento)]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function deUsuario(int $usuarioId): array
    {
        return BaseDatos::todas(
            'SELECT r.*, rm.nombre_corto AS remesadora, rm.etiqueta_codigo
               FROM remesas r
               JOIN remesadoras rm ON rm.id = r.remesadora_id
              WHERE r.usuario_id = ?
              ORDER BY FIELD(r.estado, "disponible", "retenida", "pagada", "devuelta"),
                       r.disponible_en DESC
              LIMIT 200',
            [$usuarioId]
        );
    }

    // =================================================================
    // Cobro
    // =================================================================

    /**
     * Acredita la remesa a una cuenta del banco.
     *
     * @return array<string,mixed>
     */
    public function acreditar(string $folio, int $cuentaId, ?int $actorId = null): array
    {
        return $this->cobrar($folio, 'cuenta', $cuentaId, null, $actorId);
    }

    /**
     * Paga la remesa en efectivo por ventanilla.
     *
     * @return array<string,mixed>
     */
    public function pagarEnEfectivo(string $folio, int $cajaSesionId, ?int $actorId = null): array
    {
        return $this->cobrar($folio, 'efectivo', null, $cajaSesionId, $actorId);
    }

    /**
     * @return array<string,mixed>
     */
    private function cobrar(
        string $folio,
        string $forma,
        ?int $cuentaId,
        ?int $cajaSesionId,
        ?int $actorId
    ): array {
        $r = $this->exigirRemesa($folio);

        if ($r['estado'] !== 'disponible') {
            throw new RemesaInvalida(
                'Esa remesa está ' . $r['estado'] . ': no se puede cobrar.'
            );
        }

        if ($r['expira_en'] !== null && $r['expira_en'] < date('Y-m-d')) {
            throw new RemesaInvalida(
                'Esa remesa venció el ' . date('d/m/Y', (int) strtotime((string) $r['expira_en']))
                . '. La remesadora ya la reclamó: el remitente tiene que reenviarla.'
            );
        }

        $monto   = Monto::deCentavos((int) $r['monto_cent']);
        $tecnica = $this->cuentaTecnica();

        return BaseDatos::enTransaccion(function () use (
            $r, $forma, $cuentaId, $cajaSesionId, $actorId, $monto, $tecnica, $folio
        ): array {
            $tecnicaId = (int) $tecnica['id'];

            /* --- A cuenta ------------------------------------------
             * El dinero sale de la remesadora (cuenta técnica) y entra
             * a la del cliente. */
            if ($forma === 'cuenta') {
                if ($cuentaId === null) {
                    throw new RemesaInvalida('Falta la cuenta a la que acreditar.');
                }

                $bloqueadas = LibroMayor::bloquearCuentas([$cuentaId, $tecnicaId]);
                $destino    = $bloqueadas[$cuentaId] ?? null;

                if ($destino === null || $destino['estado'] !== 'activa'
                    || (int) $destino['es_tecnica'] === 1) {
                    throw new RemesaInvalida('Esa cuenta no puede recibir fondos.');
                }

                $asiento = LibroMayor::registrar(
                    'remesa',
                    [
                        ['cuenta_id' => $tecnicaId, 'importe' => $monto->negado(),
                         'descripcion' => 'Remesa ' . $folio . ' · ' . $r['remesadora']],
                        ['cuenta_id' => $cuentaId, 'importe' => $monto,
                         'descripcion' => 'Remesa de ' . ($r['remitente_nombre'] !== ''
                             ? $r['remitente_nombre'] : $r['remesadora'])],
                    ],
                    $actorId,
                    'remesa-' . $folio,
                    'Remesa familiar ' . $folio
                );

                $usuarioDestino = (int) $destino['usuario_id'];
            } else {
                /* --- En efectivo ---------------------------------
                 * El dinero sale de la remesadora y entra a la caja.
                 * No toca ninguna cuenta de cliente: nunca estuvo en
                 * una. Por eso la contrapartida es la caja. */
                if ($cajaSesionId === null) {
                    throw new RemesaInvalida('Falta la sesión de caja que paga.');
                }

                $cuentaCaja = $this->cuentaDeCaja($cajaSesionId);

                $bloqueadas = LibroMayor::bloquearCuentas([$cuentaCaja, $tecnicaId]);

                $asiento = LibroMayor::registrar(
                    'remesa',
                    [
                        ['cuenta_id' => $tecnicaId, 'importe' => $monto->negado(),
                         'descripcion' => 'Remesa ' . $folio . ' · ' . $r['remesadora']],
                        ['cuenta_id' => $cuentaCaja, 'importe' => $monto,
                         'descripcion' => 'Remesa pagada en efectivo · ' . $folio],
                    ],
                    $actorId,
                    'remesa-' . $folio,
                    'Remesa familiar ' . $folio . ' pagada en efectivo'
                );

                /* Y el movimiento de caja, para que el arqueo cuadre:
                   de la caja salió efectivo. */
                BaseDatos::ejecutar(
                    'INSERT INTO caja_movimientos
                       (sesion_id, tipo, medio, importe_cent, cliente_id,
                        referencia, concepto, asiento_id)
                     VALUES (?, "remesa_salida", "efectivo", ?, ?, ?, ?, ?)',
                    [
                        $cajaSesionId,
                        -$monto->centavos,
                        $r['usuario_id'] !== null ? (int) $r['usuario_id'] : null,
                        $folio,
                        'Remesa ' . $r['remesadora'] . ' a ' . $r['beneficiario_nombre'],
                        (int) $asiento['asiento_id'],
                    ]
                );

                $usuarioDestino = $r['usuario_id'] !== null ? (int) $r['usuario_id'] : null;
            }

            BaseDatos::ejecutar(
                'UPDATE remesas
                    SET estado = "pagada", forma_pago = ?, cuenta_id = ?,
                        caja_sesion_id = ?, pagada_por = ?, pagada_en = NOW(),
                        asiento_id = ?
                  WHERE id = ?',
                [
                    $forma, $cuentaId, $cajaSesionId, $actorId,
                    (int) $asiento['asiento_id'], (int) $r['id'],
                ]
            );

            Auditoria::registrar(
                'remesa.pagada',
                Auditoria::EXITO,
                $actorId,
                'remesa',
                $folio,
                [
                    'forma'        => $forma,
                    'monto'        => $monto->aDecimal(),
                    'beneficiario' => $r['beneficiario_nombre'],
                ]
            );

            if ($usuarioDestino !== null) {
                $this->notificaciones->crear(
                    $usuarioDestino,
                    'remesa.pagada',
                    'Remesa cobrada',
                    'Se entregaron ' . $monto->formateado() . ' de la remesa ' . $folio
                    . ($forma === 'cuenta' ? ', acreditados a su cuenta.' : ', en efectivo.'),
                    ['folio' => $folio],
                    'exito'
                );
            }

            return [
                'folio'      => $folio,
                'estado'     => 'pagada',
                'forma'      => $forma,
                'monto_cent' => $monto->centavos,
                'referencia' => $asiento['referencia'],
            ];
        });
    }

    /**
     * Devuelve a la remesadora una remesa que nadie cobró.
     *
     * No mueve dinero: nunca salió de la remesadora.
     */
    public function devolver(string $folio, string $motivo): void
    {
        $r = $this->exigirRemesa($folio);

        if ($r['estado'] !== 'disponible') {
            throw new RemesaInvalida('Solo se devuelve una remesa disponible.');
        }

        BaseDatos::ejecutar(
            'UPDATE remesas SET estado = "devuelta", motivo = ?, pagada_en = NOW() WHERE id = ?',
            [mb_substr($motivo, 0, 160), (int) $r['id']]
        );

        Auditoria::registrar(
            'remesa.devuelta',
            Auditoria::EXITO,
            Sesion::usuarioId(),
            'remesa',
            $folio,
            ['motivo' => $motivo]
        );
    }

    /**
     * Marca como vencidas las que nadie cobró a tiempo.
     *
     * No es un castigo: la remesadora las reclama pasado su plazo, y
     * dejarlas como disponibles haría que alguien intentara cobrar
     * algo que ya no está.
     */
    public function vencerCaducadas(): int
    {
        $caducadas = BaseDatos::todas(
            'SELECT id, folio, usuario_id, monto_cent FROM remesas
              WHERE estado = "disponible" AND expira_en IS NOT NULL AND expira_en < CURDATE()'
        );

        foreach ($caducadas as $c) {
            BaseDatos::ejecutar(
                'UPDATE remesas SET estado = "devuelta",
                        motivo = "Venció el plazo y la remesadora la reclamó."
                  WHERE id = ?',
                [(int) $c['id']]
            );

            if ($c['usuario_id'] !== null) {
                $this->notificaciones->crear(
                    (int) $c['usuario_id'],
                    'remesa.vencida',
                    'Una remesa venció sin cobrarse',
                    'La remesa ' . $c['folio'] . ' por '
                    . Monto::deCentavos((int) $c['monto_cent'])->formateado()
                    . ' venció sin cobrarse y volvió a la remesadora. '
                    . 'Quien la envió tendrá que reenviarla.',
                    ['folio' => $c['folio']],
                    'alerta'
                );
            }
        }

        return count($caducadas);
    }

    // =================================================================
    // Interno
    // =================================================================

    /** @return array<string,mixed> */
    private function exigirRemesa(string $folio): array
    {
        $r = BaseDatos::unaFila(
            'SELECT r.*, rm.nombre_corto AS remesadora
               FROM remesas r
               JOIN remesadoras rm ON rm.id = r.remesadora_id
              WHERE r.folio = ?',
            [$folio]
        );

        if ($r === null) {
            throw new RemesaInvalida('No existe la remesa ' . $folio . '.');
        }

        return $r;
    }

    /** @return array<string,mixed> */
    private function remesadora(string $codigo): array
    {
        $r = BaseDatos::unaFila(
            'SELECT * FROM remesadoras WHERE codigo = ? AND activa = 1',
            [strtoupper(trim($codigo))]
        );

        if ($r === null) {
            throw new RemesaInvalida('Esa remesadora no está disponible.');
        }

        return $r;
    }

    /** @return array<string,mixed> */
    private function cuentaTecnica(): array
    {
        $c = BaseDatos::unaFila(
            'SELECT * FROM cuentas WHERE numero_cuenta = ? AND es_tecnica = 1',
            [self::CUENTA_REMESAS]
        );

        if ($c === null) {
            throw new \RuntimeException(
                'Falta la cuenta técnica de remesas (' . self::CUENTA_REMESAS . ').'
            );
        }

        return $c;
    }

    /**
     * La contrapartida del efectivo que sale por ventanilla.
     *
     * Es la MISMA cuenta técnica general que usa la caja para
     * depósitos y retiros (`ServicioCaja::cuentaTecnica`). No se
     * inventa una por caja: el efectivo del banco es uno solo, y el
     * cuadre por caja lo lleva el arqueo con `caja_movimientos`, que
     * es su sitio.
     *
     * Partirlo en una cuenta por caja habría obligado a conciliar dos
     * verdades distintas sobre el mismo efectivo.
     */
    private function cuentaDeCaja(int $sesionId): int
    {
        $existe = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM caja_sesiones WHERE id = ? AND cerrada_en IS NULL',
            [$sesionId]
        );

        if ($existe === 0) {
            throw new RemesaInvalida(
                'Esa sesión de caja no está abierta: no se puede pagar efectivo desde ella.'
            );
        }

        $id = BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
        );

        if ($id === null) {
            throw new \RuntimeException('No existe la cuenta técnica de contrapartida.');
        }

        return (int) $id;
    }

    public function normalizarCodigo(string $codigo): string
    {
        return strtoupper(preg_replace('/[\s\-]/', '', trim($codigo)) ?? '');
    }

    public function normalizarDocumento(string $documento): string
    {
        return preg_replace('/[^0-9A-Za-z]/', '', trim($documento)) ?? '';
    }

    /** @param array<string,mixed> $remesadora */
    private function codigoBienFormado(array $remesadora, string $codigo): bool
    {
        $largo = strlen($codigo);

        return $largo >= (int) $remesadora['codigo_largo_min']
            && $largo <= (int) $remesadora['codigo_largo_max']
            && preg_match('/^[0-9A-Z]+$/', $codigo) === 1;
    }
}
