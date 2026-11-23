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
 * Constancias, solvencias y referencias bancarias.
 *
 * LO QUE HACE QUE UNA CONSTANCIA VALGA ALGO
 *
 * No es el papel ni el sello: es que quien la recibe —una embajada,
 * otro banco, un patrono— pueda COMPROBAR que es auténtica. Un PDF se
 * falsifica en diez minutos.
 *
 * Por eso lo que esta clase emite de verdad es un REGISTRO con un
 * código de verificación. El PDF es su copia impresa.
 *
 * LA FOTO DE LOS DATOS
 *
 * Una constancia de saldo dice lo que el saldo era ESE día. Si al
 * verificarla se recalculara, diría otra cosa, y quien la recibió
 * pensaría que el cliente le mintió.
 *
 * Por eso los datos se congelan al emitir y no se vuelven a tocar.
 * Incluso el texto impreso se guarda, para que la página de
 * verificación enseñe exactamente lo mismo que hay en el papel.
 */
final class ServicioConstancias
{
    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    // =================================================================
    // Catálogo
    // =================================================================

    /** @return array<int,array<string,mixed>> */
    public function tiposDisponibles(bool $soloAutoservicio = true): array
    {
        return BaseDatos::todas(
            'SELECT * FROM constancia_aranceles
              WHERE activa = 1' . ($soloAutoservicio ? ' AND autoservicio = 1' : '') . '
              ORDER BY comision_cent, nombre'
        );
    }

    /** @return array<string,mixed> */
    private function arancel(string $tipo): array
    {
        $a = BaseDatos::unaFila(
            'SELECT * FROM constancia_aranceles WHERE tipo = ? AND activa = 1',
            [$tipo]
        );

        if ($a === null) {
            throw new ConstanciaInvalida('Ese tipo de constancia no está disponible.');
        }

        return $a;
    }

    // =================================================================
    // Emisión
    // =================================================================

    /**
     * @param array{
     *   tipo:string, usuario_id?:int, objeto_codigo?:string,
     *   dirigida_a?:string, proposito?:string, cuenta_cargo?:int
     * } $datos
     * @return array<string,mixed>
     */
    public function emitir(array $datos): array
    {
        $actorId = Sesion::exigirAutenticado();

        $tipo    = (string) ($datos['tipo'] ?? '');
        $arancel = $this->arancel($tipo);

        /* ¿Para quién? El propio cliente, o un tercero si quien actúa
           tiene permiso. Sin esta comprobación, cambiar un id en la
           petición emitiría una constancia con los saldos de otro. */
        $paraId = (int) ($datos['usuario_id'] ?? $actorId);

        if ($paraId !== $actorId) {
            /* El cajero que cobra una solicitud no está emitiendo por su
               cuenta: ejecuta un trámite que atención al cliente ya
               autorizó y que el cliente acaba de pagar en su ventanilla.
               Pedirle «emitir a terceros» sería darle el permiso de
               emitir cualquier constancia de cualquier cliente cuando se
               le antoje, mucho más de lo que su trabajo necesita. */
            if (isset($datos['solicitud_id']) && Autorizacion::puede('constancia.cobrar')) {
                Autorizacion::exigir('constancia.cobrar');
            } else {
                Autorizacion::exigir('constancia.emitir_terceros');
            }
        } else {
            Autorizacion::exigir('constancia.emitir_propia');
        }

        if ((int) $arancel['autoservicio'] !== 1 && $paraId === $actorId) {
            throw new ConstanciaInvalida(
                'La ' . mb_strtolower((string) $arancel['nombre'])
                . ' la emite un oficial en agencia: requiere revisión.'
            );
        }

        $cliente = BaseDatos::unaFila(
            'SELECT id, nombres, apellidos, documento_identidad, tipo_documento,
                    correo, creado_en
               FROM usuarios WHERE id = ? AND estado = "activo"',
            [$paraId]
        );

        if ($cliente === null) {
            throw new ConstanciaInvalida('Ese cliente no existe o no está activo.');
        }

        // --- Los datos que va a certificar ---------------------------
        $contenido = match ($tipo) {
            'saldo'               => $this->datosSaldo($paraId, $datos),
            'referencia_bancaria' => $this->datosReferencia($paraId, $cliente),
            'solvencia'           => $this->datosSolvencia($paraId, $datos),
            'no_adeudo'           => $this->datosNoAdeudo($paraId),
            'ingresos'            => $this->datosIngresos($paraId),
            default               => throw new ConstanciaInvalida('Tipo no reconocido.'),
        };

        $comision = (int) $arancel['comision_cent'];

        /* -------------------------------------------------------------
           CÓMO SE PAGA

           Hasta aquí solo existía un camino: debitar la cuenta. Eso
           dejaba fuera a quien llega a la agencia con efectivo —y a
           quien no quiere que el cargo le toque la cuenta—, y obligaba
           a tener cuenta corriente para pedir un papel.

           El efectivo NO se cobra aquí. Atención al cliente no tiene
           gaveta: lo que hace es generar una solicitud y mandarla a
           caja, y la constancia nace cuando el cajero cobra. Ese camino
           vive en `ServicioSolicitudConstancia`, y es él quien vuelve a
           entrar por aquí con la solicitud ya pagada.
           ------------------------------------------------------------- */
        $formaPago = (string) ($datos['forma_pago'] ?? 'cuenta');

        if ($comision === 0) {
            $formaPago = 'exenta';
        }

        if ($formaPago === 'efectivo' && !isset($datos['solicitud_id'])) {
            throw new ConstanciaInvalida(
                'El efectivo se cobra en caja. Genere la solicitud y el cliente '
                . 'la paga en ventanilla; la constancia se emite al cobrarla.'
            );
        }

        if (!in_array($formaPago, ['cuenta', 'tarjeta', 'efectivo', 'exenta'], true)) {
            throw new ConstanciaInvalida('Esa forma de pago no existe.');
        }

        $folio  = Folio::unico('CST', 'constancias', 'folio', 'ymd', 5);
        $codigo = $this->codigoVerificacion();

        $texto = $this->redactar($tipo, $cliente, $contenido, $datos);

        $resultado = BaseDatos::enTransaccion(function () use (
            $cliente, $tipo, $contenido, $comision, $folio, $codigo, $texto,
            $datos, $arancel, $actorId, $paraId, $formaPago
        ): array {
            /* El arancel, por donde el cliente eligió pagarlo. */
            $pago = $this->cobrarArancel(
                $formaPago, $comision, $datos, $paraId, $actorId, $folio, $arancel
            );

            $asientoId = $pago['asiento_id'];

            BaseDatos::ejecutar(
                'INSERT INTO constancias
                   (uuid, folio, codigo_verificacion, usuario_id, tipo,
                    objeto_tipo, objeto_id, dirigida_a, proposito,
                    datos_json, texto, comision_cent, forma_pago,
                    solicitud_id, tarjeta_id, pago_referencia, asiento_id,
                    vigente_hasta, emitida_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                         DATE_ADD(CURDATE(), INTERVAL ? DAY), ?)',
                [
                    LibroMayor::uuid4(),
                    $folio,
                    $codigo,
                    $paraId,
                    $tipo,
                    (string) ($contenido['objeto_tipo'] ?? 'global'),
                    $contenido['objeto_id'] ?? null,
                    mb_substr(trim((string) ($datos['dirigida_a'] ?? '')), 0, 160),
                    mb_substr(trim((string) ($datos['proposito'] ?? '')), 0, 255),
                    json_encode($contenido, JSON_UNESCAPED_UNICODE),
                    $texto,
                    $comision,
                    $pago['forma_pago'],
                    isset($datos['solicitud_id']) ? (int) $datos['solicitud_id'] : null,
                    $pago['tarjeta_id'],
                    $pago['pago_referencia'],
                    $asientoId,
                    (int) $arancel['dias_vigencia'],
                    $actorId !== $paraId ? $actorId : null,
                ]
            );

            return [
                'folio'           => $folio,
                'codigo'          => $codigo,
                'asiento_id'      => $asientoId,
                'forma_pago'      => $pago['forma_pago'],
                'pago_referencia' => $pago['pago_referencia'],
            ];
        });

        Auditoria::registrar(
            'constancia.emitida',
            Auditoria::EXITO,
            $actorId,
            'constancia',
            $folio,
            ['tipo' => $tipo, 'para' => $paraId, 'arancel' => Monto::deCentavos($comision)->aDecimal()]
        );

        $this->notificaciones->crear(
            $paraId,
            'constancia.emitida',
            $arancel['nombre'] . ' emitida',
            'Su ' . mb_strtolower((string) $arancel['nombre']) . ' está lista (folio '
            . $folio . '). Quien la reciba puede comprobar que es auténtica con el '
            . 'código ' . $codigo . '.'
            . ($comision > 0
                ? ' Se cobró un arancel de ' . Monto::deCentavos($comision)->formateado()
                  . match ($resultado['forma_pago'] ?? 'cuenta') {
                      'tarjeta'  => ' a su tarjeta CashMan H.A.',
                      'efectivo' => ' en efectivo, en ventanilla.',
                      default    => ' de su cuenta.',
                  }
                : ''),
            ['folio' => $folio],
            'exito'
        );

        return $resultado + [
            'tipo'          => $tipo,
            'nombre'        => $arancel['nombre'],
            'comision_cent' => $comision,
            'texto'         => $texto,
            'datos'         => $contenido,
            'vigente_hasta' => date('Y-m-d', strtotime('+' . (int) $arancel['dias_vigencia'] . ' days')),
        ];
    }

    // =================================================================
    // El arancel
    // =================================================================

    /**
     * Cobra el arancel por donde el cliente eligió pagarlo.
     *
     * Se corre DENTRO de la transacción de la constancia: si el cobro
     * falla, la constancia no nace; y si la constancia falla después,
     * el cobro se deshace. Un arancel cobrado sin documento es dinero
     * del cliente que el banco se quedó.
     *
     * @param array<string,mixed> $datos
     * @param array<string,mixed> $arancel
     * @return array{asiento_id:?int, forma_pago:string, tarjeta_id:?int,
     *               pago_referencia:string}
     */
    private function cobrarArancel(
        string $forma,
        int $comision,
        array $datos,
        int $paraId,
        int $actorId,
        string $folio,
        array $arancel
    ): array {
        /* Gratis es gratis: la de no adeudo no cobra, y cobrar cero
           dejaría un asiento de cero en el libro mayor. */
        if ($comision <= 0) {
            return ['asiento_id' => null, 'forma_pago' => 'exenta',
                    'tarjeta_id' => null, 'pago_referencia' => ''];
        }

        /* El efectivo ya se cobró en caja antes de llegar aquí: quien
           entra por ese camino trae la solicitud pagada y su referencia
           de ventanilla. Aquí no hay nada que cobrar. */
        if ($forma === 'efectivo') {
            return [
                'asiento_id'      => isset($datos['asiento_id']) ? (int) $datos['asiento_id'] : null,
                'forma_pago'      => 'efectivo',
                'tarjeta_id'      => null,
                'pago_referencia' => mb_substr((string) ($datos['pago_referencia'] ?? ''), 0, 40),
            ];
        }

        if ($forma === 'tarjeta') {
            return $this->cobrarConTarjeta(
                (int) ($datos['tarjeta_id'] ?? 0), $comision, $paraId, $arancel
            );
        }

        return $this->cobrarDeCuenta($datos, $comision, $paraId, $actorId, $folio, $arancel);
    }

    /**
     * Debita la cuenta del cliente. El camino de siempre.
     *
     * @param array<string,mixed> $datos
     * @param array<string,mixed> $arancel
     * @return array{asiento_id:?int, forma_pago:string, tarjeta_id:?int,
     *               pago_referencia:string}
     */
    private function cobrarDeCuenta(
        array $datos,
        int $comision,
        int $paraId,
        int $actorId,
        string $folio,
        array $arancel
    ): array {
        $cuentaId = (int) ($datos['cuenta_cargo'] ?? 0);

        if ($cuentaId === 0) {
            $cuentaId = (int) (BaseDatos::unValor(
                'SELECT id FROM cuentas
                  WHERE usuario_id = ? AND estado = "activa" AND es_tecnica = 0
                  ORDER BY id LIMIT 1',
                [$paraId]
            ) ?? 0);
        }

        if ($cuentaId === 0) {
            throw new ConstanciaInvalida(
                'Hace falta una cuenta activa para cobrar el arancel de '
                . Monto::deCentavos($comision)->formateado()
                . '. También puede pagarlo con su tarjeta CashMan H.A., o en '
                . 'efectivo pidiéndola en agencia.'
            );
        }

        $general = (int) BaseDatos::unValor(
            'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
        );

        $bloqueadas = LibroMayor::bloquearCuentas([$cuentaId, $general]);
        $origen     = $bloqueadas[$cuentaId] ?? null;

        if ($origen === null || (int) $origen['usuario_id'] !== $paraId) {
            throw new ConstanciaInvalida('Esa cuenta no es del cliente.');
        }

        $disponible = (int) $origen['saldo_cent']
                    - (int) $origen['saldo_retenido_cent']
                    - (int) $origen['saldo_minimo_cent'];

        if ($disponible < $comision) {
            throw new FondosInsuficientes(
                'No hay fondos para el arancel de '
                . Monto::deCentavos($comision)->formateado()
                . '. Disponible: ' . Monto::deCentavos(max(0, $disponible))->formateado() . '.'
            );
        }

        $m = Monto::deCentavos($comision);

        $asiento = LibroMayor::registrar(
            'comision',
            [
                ['cuenta_id' => $cuentaId, 'importe' => $m->negado(),
                 'descripcion' => $arancel['nombre'] . ' · ' . $folio],
                ['cuenta_id' => $general, 'importe' => $m,
                 'descripcion' => 'Arancel de constancia ' . $folio],
            ],
            $actorId,
            'constancia-' . $folio,
            'Arancel de ' . $arancel['nombre']
        );

        return [
            'asiento_id'      => (int) $asiento['asiento_id'],
            'forma_pago'      => 'cuenta',
            'tarjeta_id'      => null,
            'pago_referencia' => (string) $asiento['referencia'],
        ];
    }

    /**
     * Carga el arancel a una tarjeta CashMan H.A.
     *
     * SOLO TARJETAS PROPIAS, Y NO ES UN CAPRICHO TÉCNICO
     *
     * Aceptar la tarjeta de otro banco significaría pasar el cobro por
     * una red de adquirencia y pagarle a ese banco una comisión —entre
     * el 2% y el 3%— por cobrar un arancel propio de $5.00. En una
     * constancia de saldo eso se come el arancel entero.
     *
     * Por eso la tarjeta tiene que estar en la tabla `tarjetas`, que es
     * la de las emitidas por esta institución. Una tarjeta que no esté
     * ahí no es nuestra, por muy válida que sea.
     *
     * El cobro lo hace `ServicioTarjeta::cobrar()`, que es donde viven
     * una sola vez las reglas de la tarjeta: estado, congelación, cupo,
     * fondos de la cuenta ligada si es de débito. Duplicarlas aquí
     * acabaría autorizando lo que allí se rechaza.
     *
     * @param array<string,mixed> $arancel
     * @return array{asiento_id:?int, forma_pago:string, tarjeta_id:?int,
     *               pago_referencia:string}
     */
    private function cobrarConTarjeta(
        int $tarjetaId,
        int $comision,
        int $paraId,
        array $arancel
    ): array {
        if ($tarjetaId <= 0) {
            throw new ConstanciaInvalida('Elija con cuál de sus tarjetas desea pagar.');
        }

        $t = BaseDatos::unaFila(
            'SELECT id, usuario_id, estado, ultimos4, tipo FROM tarjetas WHERE id = ?',
            [$tarjetaId]
        );

        if ($t === null) {
            throw new ConstanciaInvalida(
                'El arancel solo se puede pagar con una tarjeta CashMan H.A. '
                . 'Las tarjetas de otros bancos no se aceptan para este cobro.'
            );
        }

        if ((int) $t['usuario_id'] !== $paraId) {
            throw new ConstanciaInvalida('Esa tarjeta no es del titular de la constancia.');
        }

        if ($t['estado'] !== 'activa') {
            throw new ConstanciaInvalida(
                'Esa tarjeta no está activa ahora mismo. Elija otra o pague con su cuenta.'
            );
        }

        $cobro = (new ServicioTarjeta())->cobrar(
            $tarjetaId,
            Monto::deCentavos($comision),
            'CashMan H.A. · ' . (string) $arancel['nombre'],
            'servicios'
        );

        /* La referencia DEFINITIVA se lee de la fila, no de lo que
           devuelve el cobro: en débito se reescribe con la del asiento
           al liquidarse, y guardar la otra dejaría al cliente sin poder
           atar el cargo de su estado de cuenta a esta constancia. */
        $ref = (string) (BaseDatos::unValor(
            'SELECT referencia FROM tarjeta_movimientos WHERE id = ?',
            [(int) $cobro['movimiento_id']]
        ) ?? $cobro['referencia']);

        /* En débito el dinero salió de una cuenta y hay asiento. En
           crédito no: la línea es del banco hasta que el cliente la
           pague, y el ingreso entra cuando abona la tarjeta. */
        $asientoId = BaseDatos::unValor(
            'SELECT id FROM asientos WHERE referencia = ? LIMIT 1',
            [$ref]
        );

        return [
            'asiento_id'      => $asientoId === null ? null : (int) $asientoId,
            'forma_pago'      => 'tarjeta',
            'tarjeta_id'      => $tarjetaId,
            'pago_referencia' => mb_substr($ref, 0, 40),
        ];
    }

    /**
     * Las tarjetas CashMan con las que un cliente puede pagar un arancel.
     *
     * Es lo que la pantalla ofrece. Si sale vacía, no se le enseña la
     * opción: dejarla visible y rechazarla después es peor que no
     * ofrecerla.
     *
     * @return array<int,array<string,mixed>>
     */
    public function tarjetasParaPagar(int $usuarioId): array
    {
        return BaseDatos::todas(
            'SELECT t.id, t.ultimos4, t.marca, t.tipo, t.alias,
                    p.nombre AS producto
               FROM tarjetas t
               LEFT JOIN productos p ON p.id = t.producto_id
              WHERE t.usuario_id = ?
                AND t.estado = "activa"
                AND (t.congelada_hasta IS NULL OR t.congelada_hasta <= NOW())
              ORDER BY t.tipo, t.id',
            [$usuarioId]
        );
    }

    // =================================================================
    // Qué certifica cada una
    // =================================================================

    /**
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    private function datosSaldo(int $usuarioId, array $datos): array
    {
        $cuentaId = (int) ($datos['objeto_id'] ?? 0);

        $cuenta = $cuentaId > 0
            ? BaseDatos::unaFila(
                'SELECT c.*, p.nombre AS producto FROM cuentas c
                   JOIN productos p ON p.id = c.producto_id
                  WHERE c.id = ? AND c.usuario_id = ? AND c.es_tecnica = 0',
                [$cuentaId, $usuarioId]
            )
            : BaseDatos::unaFila(
                'SELECT c.*, p.nombre AS producto FROM cuentas c
                   JOIN productos p ON p.id = c.producto_id
                  WHERE c.usuario_id = ? AND c.estado = "activa" AND c.es_tecnica = 0
                  ORDER BY c.saldo_cent DESC LIMIT 1',
                [$usuarioId]
            );

        if ($cuenta === null) {
            throw new ConstanciaInvalida('El cliente no tiene cuentas sobre las que certificar saldo.');
        }

        return [
            'objeto_tipo'   => 'cuenta',
            'objeto_id'     => (int) $cuenta['id'],
            'numero_cuenta' => $cuenta['numero_cuenta'],
            'producto'      => $cuenta['producto'],
            'moneda'        => $cuenta['moneda'],
            'saldo_cent'    => (int) $cuenta['saldo_cent'],
            'estado'        => $cuenta['estado'],
            'apertura_en'   => $cuenta['apertura_en'],
            'al_corte'      => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string,mixed> $cliente
     * @return array<string,mixed>
     */
    private function datosReferencia(int $usuarioId, array $cliente): array
    {
        $cuentas = BaseDatos::todas(
            'SELECT c.numero_cuenta, c.apertura_en, c.estado, p.nombre AS producto
               FROM cuentas c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.es_tecnica = 0
              ORDER BY c.apertura_en',
            [$usuarioId]
        );

        $creditos = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM creditos WHERE usuario_id = ?',
            [$usuarioId]
        );

        /* El comportamiento, dicho sin adjetivos de más: cuántos días
           de atraso ha tenido como máximo. Es lo que de verdad quiere
           saber quien pide una referencia, y es comprobable. */
        $atrasoMaximo = (int) (BaseDatos::unValor(
            'SELECT COALESCE(MAX(DATEDIFF(COALESCE(cu.pagada_en, CURDATE()), cu.fecha_vencimiento)), 0)
               FROM cuotas cu
               JOIN creditos cr ON cr.id = cu.credito_id
              WHERE cr.usuario_id = ?',
            [$usuarioId]
        ) ?? 0);

        return [
            'objeto_tipo'    => 'global',
            'cliente_desde'  => $cliente['creado_en'],
            'cuentas'        => $cuentas,
            'cuentas_total'  => count($cuentas),
            'creditos_total' => $creditos,
            'atraso_maximo_dias' => max(0, $atrasoMaximo),
            'al_corte'       => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    private function datosSolvencia(int $usuarioId, array $datos): array
    {
        $creditoId = (int) ($datos['objeto_id'] ?? 0);

        $credito = $creditoId > 0
            ? BaseDatos::unaFila(
                'SELECT c.*, p.nombre AS producto FROM creditos c
                   JOIN productos p ON p.id = c.producto_id
                  WHERE c.id = ? AND c.usuario_id = ?',
                [$creditoId, $usuarioId]
            )
            : BaseDatos::unaFila(
                'SELECT c.*, p.nombre AS producto FROM creditos c
                   JOIN productos p ON p.id = c.producto_id
                  WHERE c.usuario_id = ?
                  ORDER BY c.id DESC LIMIT 1',
                [$usuarioId]
            );

        if ($credito === null) {
            throw new ConstanciaInvalida('El cliente no tiene créditos sobre los que certificar solvencia.');
        }

        $vencidas = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM cuotas
              WHERE credito_id = ? AND pagada_en IS NULL AND fecha_vencimiento < CURDATE()',
            [(int) $credito['id']]
        );

        return [
            'objeto_tipo'  => 'credito',
            'objeto_id'    => (int) $credito['id'],
            'numero'       => $credito['folio'],
            'producto'     => $credito['producto'],
            'saldo_cent'   => (int) $credito['saldo_capital_cent'],
            'estado'       => $credito['estado'],
            'cuotas_vencidas' => $vencidas,
            'al_dia'       => $vencidas === 0,
            'cancelado'    => (int) $credito['saldo_capital_cent'] === 0,
            'al_corte'     => date('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string,mixed> */
    private function datosNoAdeudo(int $usuarioId): array
    {
        $creditos = (int) (BaseDatos::unValor(
            'SELECT COALESCE(SUM(saldo_capital_cent), 0) FROM creditos
              WHERE usuario_id = ? AND estado IN ("desembolsado", "vigente")',
            [$usuarioId]
        ) ?? 0);

        $tarjetas = (int) (BaseDatos::unValor(
            'SELECT COALESCE(SUM(saldo_usado_cent), 0) FROM tarjetas
              WHERE usuario_id = ? AND estado <> "cancelada"',
            [$usuarioId]
        ) ?? 0);

        return [
            'objeto_tipo'      => 'global',
            'deuda_creditos_cent' => $creditos,
            'deuda_tarjetas_cent' => $tarjetas,
            'deuda_total_cent' => $creditos + $tarjetas,
            'sin_deuda'        => ($creditos + $tarjetas) === 0,
            'al_corte'         => date('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string,mixed> */
    private function datosIngresos(int $usuarioId): array
    {
        $filas = BaseDatos::todas(
            'SELECT DATE_FORMAT(a.creado_en, "%Y-%m") AS mes,
                    SUM(m.importe_cent) AS total
               FROM movimientos m
               JOIN asientos a ON a.id = m.asiento_id
               JOIN cuentas  c ON c.id = m.cuenta_id
              WHERE c.usuario_id = ? AND c.es_tecnica = 0 AND m.importe_cent > 0
                AND a.creado_en >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
              GROUP BY mes ORDER BY mes',
            [$usuarioId]
        );

        $total = array_sum(array_map(static fn(array $f): int => (int) $f['total'], $filas));

        return [
            'objeto_tipo' => 'global',
            'meses'       => $filas,
            'total_cent'  => $total,
            'promedio_cent' => $filas === [] ? 0 : intdiv($total, count($filas)),
            'al_corte'    => date('Y-m-d H:i:s'),
        ];
    }

    // =================================================================
    // Redacción
    // =================================================================

    /**
     * El texto que sale impreso y que verá quien verifique.
     *
     * Se guarda tal cual: la página de verificación tiene que enseñar
     * LO MISMO que hay en el papel, no una reconstrucción parecida.
     *
     * @param array<string,mixed> $cliente
     * @param array<string,mixed> $c
     * @param array<string,mixed> $datos
     */
    private function redactar(string $tipo, array $cliente, array $c, array $datos): string
    {
        $nombre = trim($cliente['nombres'] . ' ' . $cliente['apellidos']);
        $doc    = strtoupper((string) $cliente['tipo_documento']) . ' ' . $cliente['documento_identidad'];

        $a = trim((string) ($datos['dirigida_a'] ?? ''));
        $encabezado = $a !== '' ? 'A ' . $a . ':' : 'A quien interese:';

        $cuerpo = match ($tipo) {
            'saldo' => sprintf(
                'Hacemos constar que %s, con %s, mantiene en esta institución la cuenta '
                . '%s (%s), la cual a la fecha de esta constancia registra un saldo de %s. '
                . 'La cuenta fue aperturada el %s y se encuentra %s.',
                $nombre,
                $doc,
                $c['numero_cuenta'],
                $c['producto'],
                Monto::deCentavos((int) $c['saldo_cent'])->formateado(),
                date('d/m/Y', (int) strtotime((string) $c['apertura_en'])),
                $c['estado'] === 'activa' ? 'activa' : (string) $c['estado']
            ),

            'referencia_bancaria' => sprintf(
                'Hacemos constar que %s, con %s, es cliente de esta institución desde el %s. '
                . 'A la fecha mantiene %d producto(s) de depósito y ha tenido %d operación(es) '
                . 'de crédito con nosotros. %s',
                $nombre,
                $doc,
                date('d/m/Y', (int) strtotime((string) $c['cliente_desde'])),
                (int) $c['cuentas_total'],
                (int) $c['creditos_total'],
                (int) $c['atraso_maximo_dias'] === 0
                    ? 'Su comportamiento de pago ha sido puntual, sin atrasos registrados.'
                    : 'El atraso máximo registrado en sus obligaciones ha sido de '
                      . (int) $c['atraso_maximo_dias'] . ' día(s).'
            ),

            'solvencia' => sprintf(
                'Hacemos constar que %s, con %s, mantiene con esta institución el crédito '
                . 'número %s (%s), el cual %s. %s',
                $nombre,
                $doc,
                $c['numero'],
                $c['producto'],
                ((bool) $c['cancelado'])
                    ? 'se encuentra TOTALMENTE CANCELADO'
                    : 'registra un saldo de ' . Monto::deCentavos((int) $c['saldo_cent'])->formateado(),
                ((bool) $c['al_dia'])
                    ? 'El crédito se encuentra AL DÍA en sus pagos.'
                    : 'El crédito registra ' . (int) $c['cuotas_vencidas'] . ' cuota(s) vencida(s).'
            ),

            'no_adeudo' => sprintf(
                'Hacemos constar que %s, con %s, %s',
                $nombre,
                $doc,
                ((bool) $c['sin_deuda'])
                    ? 'NO REGISTRA obligación alguna pendiente de pago con esta institución '
                      . 'a la fecha de la presente constancia.'
                    : 'registra obligaciones pendientes por un total de '
                      . Monto::deCentavos((int) $c['deuda_total_cent'])->formateado() . '.'
            ),

            'ingresos' => sprintf(
                'Hacemos constar que %s, con %s, ha recibido abonos en sus cuentas de esta '
                . 'institución por un total de %s durante los últimos %d mes(es), '
                . 'con un promedio mensual de %s.',
                $nombre,
                $doc,
                Monto::deCentavos((int) $c['total_cent'])->formateado(),
                count((array) $c['meses']),
                Monto::deCentavos((int) $c['promedio_cent'])->formateado()
            ),

            default => '',
        };

        $proposito = trim((string) ($datos['proposito'] ?? ''));

        $cierre = 'Y para los usos que el interesado estime convenientes'
            . ($proposito !== '' ? ', específicamente para ' . $proposito : '')
            . ', se extiende la presente en la ciudad de San Salvador, '
            . 'el ' . $this->fechaEnLetras() . '.';

        return $encabezado . "\n\n" . $cuerpo . "\n\n" . $cierre;
    }

    private function fechaEnLetras(): string
    {
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                  'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return sprintf(
            '%s de %s de %s',
            date('j'),
            $meses[(int) date('n') - 1],
            date('Y')
        );
    }

    // =================================================================
    // Verificación pública
    // =================================================================

    /**
     * Lo que ve quien teclea un código en la página pública.
     *
     * Devuelve SOLO lo que hace falta para confirmar que el documento
     * es auténtico y qué decía. No es un buscador de clientes: sin el
     * código exacto no se llega a nada, y ese código solo lo tiene
     * quien recibió el documento de manos del cliente.
     *
     * @return array<string,mixed>|null
     */
    public function verificar(string $codigo): ?array
    {
        $codigo = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($codigo)) ?? '');

        if (strlen($codigo) !== 12) {
            return null;
        }

        $c = BaseDatos::unaFila(
            'SELECT c.*, CONCAT(u.nombres, " ", u.apellidos) AS cliente,
                    u.documento_identidad, a.nombre AS tipo_nombre
               FROM constancias c
               JOIN usuarios u ON u.id = c.usuario_id
               JOIN constancia_aranceles a ON a.tipo = c.tipo
              WHERE c.codigo_verificacion = ?',
            [$codigo]
        );

        if ($c === null) {
            return null;
        }

        BaseDatos::ejecutar(
            'UPDATE constancias
                SET veces_verificada = veces_verificada + 1, ultima_verificacion = NOW()
              WHERE id = ?',
            [(int) $c['id']]
        );

        Auditoria::registrar(
            'constancia.verificada',
            Auditoria::EXITO,
            null,
            'constancia',
            (string) $c['folio'],
            ['veces' => (int) $c['veces_verificada'] + 1]
        );

        $vencida = $c['vigente_hasta'] < date('Y-m-d');

        return [
            'autentica'     => true,
            'folio'         => $c['folio'],
            'tipo'          => $c['tipo_nombre'],
            'cliente'       => $c['cliente'],
            /* El documento se enmascara: quien verifica ya sabe de quién
               es —tiene el papel delante— y no hace falta publicarlo
               entero a quien pruebe códigos. */
            'documento'     => $this->enmascararDocumento((string) $c['documento_identidad']),
            'texto'         => $c['texto'],
            'dirigida_a'    => $c['dirigida_a'],
            'emitida_en'    => $c['emitida_en'],
            'vigente_hasta' => $c['vigente_hasta'],
            'vencida'       => $vencida,
            'anulada'       => $c['estado'] === 'anulada',
            'motivo_anulacion' => $c['motivo_anulacion'],
            'veces_verificada' => (int) $c['veces_verificada'] + 1,
        ];
    }

    public function anular(string $folio, string $motivo, int $actorId): void
    {
        Autorizacion::exigir('constancia.anular');

        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 10) {
            throw new ConstanciaInvalida(
                'Escriba por qué se anula: quien verifique el documento va a leerlo.'
            );
        }

        $c = BaseDatos::unaFila('SELECT id, usuario_id FROM constancias WHERE folio = ?', [$folio]);

        if ($c === null) {
            throw new ConstanciaInvalida('No existe esa constancia.');
        }

        BaseDatos::ejecutar(
            'UPDATE constancias SET estado = "anulada", motivo_anulacion = ? WHERE id = ?',
            [mb_substr($motivo, 0, 255), (int) $c['id']]
        );

        Auditoria::registrar(
            'constancia.anulada',
            Auditoria::EXITO,
            $actorId,
            'constancia',
            $folio,
            ['motivo' => $motivo]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function deUsuario(int $usuarioId, int $limite = 100): array
    {
        return BaseDatos::todas(
            'SELECT c.*, a.nombre AS tipo_nombre
               FROM constancias c
               JOIN constancia_aranceles a ON a.tipo = c.tipo
              WHERE c.usuario_id = ?
              ORDER BY c.emitida_en DESC
              LIMIT ' . max(1, min(500, $limite)),
            [$usuarioId]
        );
    }

    /** @return array<string,mixed>|null */
    public function porFolio(string $folio): ?array
    {
        return BaseDatos::unaFila(
            'SELECT c.*, a.nombre AS tipo_nombre,
                    CONCAT(u.nombres, " ", u.apellidos) AS cliente,
                    u.documento_identidad, u.tipo_documento
               FROM constancias c
               JOIN constancia_aranceles a ON a.tipo = c.tipo
               JOIN usuarios u ON u.id = c.usuario_id
              WHERE c.folio = ?',
            [$folio]
        );
    }

    // =================================================================
    // Interno
    // =================================================================

    /**
     * El código que se teclea para verificar.
     *
     * Doce caracteres de un alfabeto SIN los que se confunden al
     * dictarlos por teléfono: nada de O frente a 0, ni I frente a 1.
     * Quien recibe una constancia suele leer el código en voz alta.
     */
    private function codigoVerificacion(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        for ($intento = 0; $intento < 12; $intento++) {
            $codigo = '';

            for ($i = 0; $i < 12; $i++) {
                $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }

            $existe = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM constancias WHERE codigo_verificacion = ?',
                [$codigo]
            );

            if ($existe === 0) {
                return $codigo;
            }
        }

        throw new \RuntimeException('No se pudo generar un código de verificación único.');
    }

    private function enmascararDocumento(string $doc): string
    {
        $limpio = preg_replace('/[^0-9A-Za-z]/', '', $doc) ?? '';

        if (strlen($limpio) <= 4) {
            return '••••';
        }

        return str_repeat('•', strlen($limpio) - 4) . substr($limpio, -4);
    }
}
