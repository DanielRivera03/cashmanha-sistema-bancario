<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Documento\ServicioDocumentos;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Abrir una cuenta desde la banca en línea, uno mismo.
 *
 * Tres cosas pasan a la vez y o pasan todas o no pasa ninguna:
 *
 *   1. se abre la cuenta,
 *   2. se emite su tarjeta de débito, y
 *   3. se genera el contrato, que se archiva y se manda por correo.
 *
 * Que vayan en una sola transacción no es un capricho. Una cuenta sin
 * tarjeta obliga al cliente a pedirla por otro lado y a nosotros a
 * explicarle por qué no vino; y un contrato que se genera «después»
 * es un contrato que alguien se olvida de generar. Lo que se promete
 * en la pantalla de apertura tiene que estar entero cuando esa
 * pantalla termina.
 *
 * EL CORREO SE MANDA FUERA DE LA TRANSACCIÓN
 * ------------------------------------------
 * Un servidor de correo lento mantendría abierta una transacción con
 * cuentas bloqueadas, y uno caído desharía una apertura que ya estaba
 * bien hecha. El correo se intenta al final: si falla, la cuenta
 * existe igual y el contrato está en «Mis documentos», que es donde
 * el cliente va a buscarlo de todas formas.
 */
final class ServicioAperturaEnLinea
{
    public function __construct(
        private readonly EmisorTarjeta $emisor = new EmisorTarjeta(),
        private readonly ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    /**
     * El producto que se abre en línea, con sus condiciones.
     *
     * @return array<string,mixed>|null
     */
    public static function productoDigital(): ?array
    {
        $codigo = Parametros::obtener('cuenta_digital.producto', 'AHO-DIG');

        return BaseDatos::unaFila(
            'SELECT * FROM productos WHERE codigo = ? AND activo = 1 AND tipo IN ("ahorro","corriente")',
            [$codigo]
        );
    }

    /** Las marcas entre las que puede elegir quien abre la cuenta. */
    public static function marcas(): array
    {
        return ['visa' => 'Visa', 'mastercard' => 'Mastercard'];
    }

    /**
     * ¿Ya tiene una cuenta digital? Solo se permite una.
     *
     * Varias cuentas digitales del mismo titular serían varias veces
     * el tope de saldo, que es justo lo que el tope existe para
     * impedir. Para tener más, hay que completar el expediente.
     */
    public static function yaLaTiene(int $usuarioId): bool
    {
        $producto = self::productoDigital();

        if ($producto === null) {
            return false;
        }

        return (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM cuentas
              WHERE usuario_id = ? AND producto_id = ? AND estado <> "cerrada"',
            [$usuarioId, (int) $producto['id']]
        ) > 0;
    }

    /**
     * Abre la cuenta digital del usuario en sesión.
     *
     * @param string $marca La marca de tarjeta que eligió.
     * @return array<string,mixed>
     */
    public function abrir(string $marca = ''): array
    {
        Autorizacion::exigir('cuenta.abrir_propia');
        $usuarioId = Sesion::exigirAutenticado();

        $producto = self::productoDigital();

        if ($producto === null) {
            throw new \RuntimeException(
                'La apertura en línea no está disponible en este momento.'
            );
        }

        if (self::yaLaTiene($usuarioId)) {
            throw new \RuntimeException(
                'Ya tiene una Cuenta Digital. Para abrir otra cuenta, acérquese a '
                . 'cualquier sucursal con su documento.'
            );
        }

        $titular = BaseDatos::unaFila(
            'SELECT id, nombres, apellidos, correo, documento_identidad
               FROM usuarios WHERE id = ? AND estado = "activo"',
            [$usuarioId]
        );

        if ($titular === null) {
            throw new \RuntimeException('Su usuario no está activo.');
        }

        /* Sin documento no hay cuenta. Es el único dato que un banco no
           puede dejar en blanco: sin él no se puede identificar a quien
           tiene el dinero, ni cumplir lo que la ley pide. */
        if (trim((string) ($titular['documento_identidad'] ?? '')) === '') {
            throw new \RuntimeException(
                'Antes de abrir su cuenta necesitamos su documento de identidad. '
                . 'Complételo en «Mi perfil».'
            );
        }

        $marcas = self::marcas();
        $marca  = isset($marcas[$marca])
            ? $marca
            : Parametros::obtener('cuenta_digital.marca_tarjeta', 'visa');

        $hecho = BaseDatos::enTransaccion(function () use ($usuarioId, $producto, $marca) {
            // --- La cuenta ------------------------------------------
            do {
                $numero = (string) random_int(100000000, 999999999);

                /* No basta con que no exista hoy: tampoco puede ser uno
                 * de los retirados.
                 *
                 * Un número de cuenta vive fuera del sistema —en
                 * recibos, en ordenes de pago, en la libreta de un
                 * proveedor— y esa vida no termina cuando la cuenta se
                 * cierra. Reasignarlo es lo que hace que un deposito
                 * dirigido a la cuenta vieja de alguien aterrice en la
                 * cuenta nueva de otro. */
                $existe = BaseDatos::unValor(
                    'SELECT 1 FROM cuentas WHERE numero_cuenta = ?
                      UNION ALL
                     SELECT 1 FROM numeros_cuenta_retirados WHERE numero_cuenta = ?
                      LIMIT 1',
                    [$numero, $numero]
                );
            } while ($existe !== null);

            BaseDatos::ejecutar(
                /* La compensación se escribe aquí, a mano.
                 *
                 * La columna tiene DEFAULT 1, así que sin estas líneas
                 * la cuenta salía igualmente compensable — pero por un
                 * valor por omisión que no se ve al leer el código, y
                 * que cualquiera podría cambiar en la base sin que
                 * nadie relacionara el cambio con esta pantalla.
                 *
                 * Dicho aquí queda a la vista de quien lea la apertura,
                 * y cuadra con lo que el titular acepta: el contrato de
                 * la Cuenta Digital pacta expresamente la compensación
                 * con estos mismos límites. */
                'INSERT INTO cuentas
                   (uuid, numero_cuenta, usuario_id, producto_id, moneda,
                    saldo_cent, estado, origen, apertura_en,
                    compensacion_autorizada, compensacion_bp, compensacion_minimo_cent)
                 VALUES (UUID(), ?,?,?, "USD", 0, "activa", "en_linea", NOW(), 1, ?, ?)',
                [
                    $numero,
                    $usuarioId,
                    (int) $producto['id'],
                    Parametros::entero('compensacion.bp_por_omision', 10000),
                    Parametros::entero('compensacion.minimo_cuenta_cent', 2500),
                ]
            );

            $cuentaId = BaseDatos::ultimoId();

            // --- Su tarjeta de débito, sin pedirla aparte ------------
            $tarjeta = $this->emisor->emitir(
                $usuarioId,
                $marca,
                'debito',
                Monto::cero(),
                null,
                $usuarioId,
                ['cuenta_id' => $cuentaId]
            );

            return [
                'cuenta_id' => $cuentaId,
                'numero'    => $numero,
                'tarjeta'   => $tarjeta,
            ];
        });

        // --- El contrato, archivado y a su nombre --------------------
        $contrato = $this->archivarContrato(
            (int) $hecho['cuenta_id'],
            $usuarioId,
            (string) $hecho['numero']
        );

        Auditoria::registrar(
            'cuenta.abierta_en_linea', Auditoria::EXITO, $usuarioId,
            'cuenta', (string) $hecho['cuenta_id'],
            [
                'numero'   => $hecho['numero'],
                'producto' => $producto['codigo'],
                'marca'    => $marca,
                'contrato' => $contrato['id'] ?? null,
            ]
        );

        $ultimos4 = (string) ($hecho['tarjeta']['ultimos4'] ?? '');

        $this->notificaciones->crear(
            $usuarioId, 'cuenta.abierta', 'Su Cuenta Digital está lista',
            'Abrimos su cuenta ' . $hecho['numero'] . ' y le emitimos su tarjeta de débito '
            . 'virtual ••••' . $ultimos4 . ', lista para comprar por internet. '
            . 'Su contrato quedó en «Mis documentos».',
            ['cuenta' => $hecho['numero']],
            'exito'
        );

        $this->enviarContrato($titular, (string) $hecho['numero'], $ultimos4, $contrato, $producto);

        return [
            'cuenta_id' => $hecho['cuenta_id'],
            'numero'    => $hecho['numero'],
            'ultimos4'  => $ultimos4,
            'marca'     => $marca,
            'contrato'  => $contrato['codigo'] ?? null,
        ];
    }

    // -----------------------------------------------------------------

    /**
     * Genera el contrato y lo deja archivado.
     *
     * Si fallara, la cuenta NO se deshace: ya existe, ya tiene tarjeta
     * y el cliente ya puede usarla. Un contrato que no se pudo imprimir
     * se vuelve a generar; una cuenta deshecha después de decirle al
     * cliente que estaba abierta, no se arregla con nada.
     *
     * @return array<string,mixed>
     */
    private function archivarContrato(int $cuentaId, int $usuarioId, string $numero): array
    {
        try {
            $doc = (new ServicioDocumentos())->contratoCuenta($cuentaId);

            BaseDatos::ejecutar(
                'INSERT INTO contratos
                   (uuid, cuenta_id, usuario_id, tipo, ruta_pdf, hash_documento)
                 VALUES (UUID(),?,?, "cuenta", ?, ?)',
                [$cuentaId, $usuarioId, $doc['ruta'], $doc['hash']]
            );

            $id = BaseDatos::ultimoId();

            return [
                'id'     => $id,
                'ruta'   => $doc['ruta'],
                'nombre' => $doc['nombre'],
                'hash'   => $doc['hash'],
            ];
        } catch (\Throwable $e) {
            error_log('[AperturaEnLinea] contrato no generado para ' . $numero . ': ' . $e->getMessage());

            Auditoria::registrar(
                'cuenta.contrato_pendiente', Auditoria::FALLO, $usuarioId,
                'cuenta', (string) $cuentaId,
                ['numero' => $numero, 'motivo' => $e->getMessage()]
            );

            return [];
        }
    }

    /**
     * @param array<string,mixed> $titular
     * @param array<string,mixed> $contrato
     * @param array<string,mixed> $producto
     */
    private function enviarContrato(
        array $titular,
        string $numero,
        string $ultimos4,
        array $contrato,
        array $producto
    ): void {
        $correo = (string) ($titular['correo'] ?? '');

        if ($correo === '') {
            return;
        }

        $url = rtrim((string) Config::obtener('APP_URL', ''), '/');

        $datos = [
            ['etiqueta' => 'Número de cuenta', 'valor' => $numero],
            ['etiqueta' => 'Producto',         'valor' => (string) $producto['nombre']],
            ['etiqueta' => 'Tarjeta de débito', 'valor' => 'Virtual ••••' . $ultimos4],
            ['etiqueta' => 'Tope de saldo',    'valor' => Monto::deCentavos(
                Parametros::entero('cuenta_digital.saldo_maximo_cent', 300000)
            )->formateado()],
            ['etiqueta' => 'Tope diario',      'valor' => Monto::deCentavos(
                Parametros::entero('cuenta_digital.movimiento_diario_cent', 100000)
            )->formateado()],
            ['etiqueta' => 'Abierta el',       'valor' => date('d/m/Y H:i')],
        ];

        /* El contrato va ADJUNTO, no enlazado.
           Un enlace que exige iniciar sesión para leer lo que uno acaba
           de aceptar no es entregar el contrato: es guardárselo. */
        $adjuntos = [];

        if (($contrato['ruta'] ?? '') !== '' && is_file((string) $contrato['ruta'])) {
            $adjuntos[] = [
                'ruta'   => (string) $contrato['ruta'],
                'nombre' => 'contrato-cuenta-' . $numero . '.pdf',
                'tipo'   => 'application/pdf',
            ];
        }

        try {
            (new Correo())->enviar(
                $correo,
                'Su Cuenta Digital ' . $numero . ' está abierta',
                PlantillaCorreo::comprobante(
                    (string) $titular['nombres'],
                    'Su Cuenta Digital está lista',
                    'Abrimos su cuenta y le emitimos su tarjeta de débito virtual. '
                    . 'Adjuntamos el contrato con todas las condiciones.',
                    $datos,
                    $url . '/cuentas',
                    [],
                    $adjuntos === [] ? [] : ['contrato-cuenta-' . $numero . '.pdf'],
                    $adjuntos === []
                        ? 'Su contrato quedará disponible en «Mis documentos» en unos minutos.'
                        : 'Guarde este correo: el contrato adjunto es su copia.',
                    'exito'
                ),
                '',
                (string) $titular['nombres'],
                // Sin copias y sin remitente propio: los adjuntos van
                // en el último parámetro, no en el sexto.
                [],
                '',
                $adjuntos
            );
        } catch (\Throwable $e) {
            error_log('[AperturaEnLinea] contrato no enviado: ' . $e->getMessage());
        }
    }
}
