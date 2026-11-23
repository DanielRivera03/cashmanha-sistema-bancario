<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * El turno de caja: quién responde por el efectivo.
 *
 * El libro mayor siempre cuadró, pero eso no dice nada del dinero
 * físico. Un depósito de $500 en ventanilla deja un asiento perfecto y
 * quinientos dólares en billetes que alguien recibió, guardó en un cajón
 * y tiene que entregar al final del día. Hasta ahora no había forma de
 * saber quién, en qué caja, ni si al cerrar faltaba algo.
 *
 * Un turno es sencillo y es exactamente lo que se audita:
 *
 *   1. Se ABRE con un fondo inicial —el sencillo para dar cambio—.
 *   2. Se OPERA: cada entrada o salida de efectivo queda anotada y
 *      atada al asiento contable que la respalda.
 *   3. Se CIERRA contando el efectivo de verdad, denominación por
 *      denominación. La diferencia entre lo contado y lo que el sistema
 *      dice que debería haber es el sobrante o el faltante.
 *   4. Alguien distinto del cajero lo ARQUEA y lo da por bueno.
 *
 * El paso 4 no es burocracia: quien cuenta su propio dinero y además
 * firma que está bien no está siendo controlado por nadie.
 */
final class ServicioSesionCaja
{
    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    /**
     * Abre un turno de caja.
     *
     * @return array<string,mixed>
     */
    public function abrir(int $cajaId, mixed $fondoInicial): array
    {
        Autorizacion::exigir('caja.abrir');
        $usuarioId = Sesion::exigirAutenticado();

        $fondo = Monto::deEntradaUsuario($fondoInicial);

        if ($fondo->centavos < 0) {
            throw new \InvalidArgumentException('El fondo inicial no puede ser negativo.');
        }

        return BaseDatos::enTransaccion(function () use ($cajaId, $fondo, $usuarioId) {
            $caja = BaseDatos::unaFila(
                'SELECT * FROM cajas WHERE id = ? AND activa = 1 FOR UPDATE',
                [$cajaId]
            );

            if ($caja === null) {
                throw new \RuntimeException('Esa caja no existe o está fuera de servicio.');
            }

            /* Una caja, un turno abierto.
             *
             * Dos turnos simultáneos en la misma ventanilla hacen
             * imposible saber de quién es el faltante: los dos
             * operarían sobre el mismo cajón. */
            $ocupada = BaseDatos::unValor(
                'SELECT folio FROM caja_sesiones
                  WHERE caja_id = ? AND estado = "abierta" LIMIT 1',
                [$cajaId]
            );

            if ($ocupada !== null) {
                throw new \RuntimeException(
                    'La ' . $caja['nombre'] . ' ya tiene el turno ' . $ocupada
                    . ' abierto. Hay que cerrarlo antes de abrir otro.'
                );
            }

            // Y un cajero, un turno: si no, no se sabe dónde recibió el
            // dinero que tiene en la mano.
            $suyo = BaseDatos::unValor(
                'SELECT folio FROM caja_sesiones
                  WHERE usuario_id = ? AND estado = "abierta" LIMIT 1',
                [$usuarioId]
            );

            if ($suyo !== null) {
                throw new \RuntimeException(
                    'Usted ya tiene el turno ' . $suyo . ' abierto. Ciérrelo antes de abrir otro.'
                );
            }

            $folio = Folio::unico('CJ', 'caja_sesiones', 'folio', 'ymd');

            BaseDatos::ejecutar(
                'INSERT INTO caja_sesiones
                   (uuid, folio, caja_id, usuario_id, fondo_inicial_cent, esperado_cent)
                 VALUES (UUID(), ?, ?, ?, ?, ?)',
                [$folio, $cajaId, $usuarioId, $fondo->centavos, $fondo->centavos]
            );

            $sesionId = BaseDatos::ultimoId();

            // El fondo inicial es el primer movimiento del turno: si no
            // se anota, el arqueo no cuadra nunca.
            $this->anotar(
                $sesionId,
                'fondo_inicial',
                $fondo->centavos,
                'Fondo inicial del turno',
                []
            );

            Auditoria::registrar('caja.abierta', Auditoria::EXITO, $usuarioId,
                'caja_sesion', (string) $sesionId,
                ['folio' => $folio, 'caja' => $caja['codigo'], 'fondo' => $fondo->aDecimal()]);

            return [
                'sesion_id' => $sesionId,
                'folio'     => $folio,
                'caja'      => $caja['nombre'],
                'fondo'     => $fondo->jsonSerialize(),
            ];
        });
    }

    /**
     * Anota un movimiento de efectivo en el turno abierto del cajero.
     *
     * Devuelve null cuando no hay turno abierto: así las operaciones que
     * NO pasan por ventanilla —una transferencia del cliente desde su
     * casa, un cobro automático de madrugada— no fallan por no tener
     * caja. Solo se anota el efectivo que de verdad pasó por un cajón.
     *
     * @param array<string,mixed> $extra
     */
    public function anotarSiHayTurno(
        string $tipo,
        int $importeCent,
        string $concepto,
        array $extra = []
    ): ?int {
        $usuarioId = Sesion::usuarioId();

        if ($usuarioId === null) {
            return null;
        }

        $sesionId = (int) BaseDatos::unValor(
            'SELECT id FROM caja_sesiones WHERE usuario_id = ? AND estado = "abierta" LIMIT 1',
            [$usuarioId]
        );

        if ($sesionId === 0) {
            return null;
        }

        return $this->anotar($sesionId, $tipo, $importeCent, $concepto, $extra);
    }

    /** El turno abierto del cajero, con su cuadre al momento. */
    public function turnoActual(?int $usuarioId = null): ?array
    {
        $usuarioId ??= Sesion::usuarioId();

        if ($usuarioId === null) {
            return null;
        }

        $s = BaseDatos::unaFila(
            'SELECT s.*, c.codigo AS caja_codigo, c.nombre AS caja_nombre,
                    c.tope_efectivo_cent
               FROM caja_sesiones s JOIN cajas c ON c.id = s.caja_id
              WHERE s.usuario_id = ? AND s.estado = "abierta"
              LIMIT 1',
            [$usuarioId]
        );

        if ($s === null) {
            return null;
        }

        /* El resumen manda sobre la fila, no al revés.
         *
         * `caja_sesiones.esperado_cent` solo se escribe AL CERRAR, así
         * que mientras el turno está abierto guarda lo que había al
         * abrirlo. Con la unión al revés —`$s + resumen()`— ganaba la
         * fila, y la pantalla le enseñaba al cajero el fondo inicial
         * como si fuera el efectivo que tiene: $200.00 en una gaveta
         * con $26,028.37 dentro. Quien se fiara de ese número no
         * remesaría a bóveda cuando debe, y al arquear se llevaría el
         * susto.
         *
         * Sumar el detalle siempre dice la verdad, que es justo lo que
         * `resumen()` explica de sí mismo. */
        return $this->resumen((int) $s['id']) + $s;
    }

    /**
     * Cierra el turno contando el efectivo.
     *
     * @param array<int,int> $denominaciones  centavos => cantidad
     * @return array<string,mixed>
     */
    public function cerrar(int $sesionId, array $denominaciones, string $observaciones = ''): array
    {
        Autorizacion::exigir('caja.cerrar');
        $usuarioId = Sesion::exigirAutenticado();

        return BaseDatos::enTransaccion(function () use ($sesionId, $denominaciones, $observaciones, $usuarioId) {
            $s = BaseDatos::unaFila(
                'SELECT * FROM caja_sesiones WHERE id = ? FOR UPDATE',
                [$sesionId]
            );

            if ($s === null) {
                throw new \RuntimeException('Turno no encontrado.');
            }

            if ((string) $s['estado'] !== 'abierta') {
                throw new \RuntimeException('Este turno ya está cerrado.');
            }

            // Cada quien cierra el suyo; quien supervisa, cualquiera.
            if ((int) $s['usuario_id'] !== $usuarioId && !Autorizacion::puede('caja.ver_todas')) {
                throw new \RuntimeException('Este turno es de otro cajero.');
            }

            /* El conteo, denominación por denominación.
             *
             * Contar «$1,250 en total» no sirve para encontrar un error;
             * contar «12 de $100, 4 de $10, 2 de $5» sí: con el desglose
             * se ve enseguida si lo que falta es un billete de veinte o
             * si el cuadre se fue en monedas. */
            $contado = 0;

            BaseDatos::ejecutar('DELETE FROM caja_arqueo WHERE sesion_id = ?', [$sesionId]);

            foreach ($denominaciones as $centavos => $cantidad) {
                $centavos = (int) $centavos;
                $cantidad = (int) $cantidad;

                if ($centavos <= 0 || $cantidad <= 0) {
                    continue;
                }

                $contado += $centavos * $cantidad;

                BaseDatos::ejecutar(
                    'INSERT INTO caja_arqueo (sesion_id, denominacion_cent, cantidad)
                     VALUES (?, ?, ?)',
                    [$sesionId, $centavos, $cantidad]
                );
            }

            $resumen  = $this->resumen($sesionId);
            $esperado = (int) $resumen['esperado_cent'];
            $dif      = $contado - $esperado;

            $tolerancia = max(0, Parametros::entero('caja.diferencia_tolerada_cent', 100));
            $estado     = abs($dif) <= $tolerancia ? 'cuadrada' : 'descuadrada';

            BaseDatos::ejecutar(
                'UPDATE caja_sesiones
                    SET estado = ?, cerrada_en = NOW(), esperado_cent = ?,
                        contado_cent = ?, diferencia_cent = ?,
                        observaciones = NULLIF(TRIM(?), "")
                  WHERE id = ?',
                [$estado, $esperado, $contado, $dif, $observaciones, $sesionId]
            );

            Auditoria::registrar(
                'caja.cerrada',
                $estado === 'cuadrada' ? Auditoria::EXITO : Auditoria::FALLO,
                $usuarioId,
                'caja_sesion',
                (string) $sesionId,
                [
                    'folio'       => $s['folio'],
                    'esperado'    => Monto::deCentavos($esperado)->aDecimal(),
                    'contado'     => Monto::deCentavos($contado)->aDecimal(),
                    'diferencia'  => Monto::deCentavos($dif)->aDecimal(),
                    'estado'      => $estado,
                ]
            );

            /* Un descuadre se avisa a quien supervisa, no se deja en un
             * informe que nadie abre. Es la clase de cosa que cuanto más
             * tarde se mira, menos se puede explicar. */
            if ($estado === 'descuadrada') {
                $supervisores = BaseDatos::todas(
                    'SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id
                      WHERE r.clave IN ("administrador","gerencia","presidencia")
                        AND u.estado = "activo"'
                );

                foreach ($supervisores as $sup) {
                    $this->notificaciones->crear(
                        (int) $sup['id'],
                        'caja.descuadre',
                        'Caja cerrada con diferencia',
                        'El turno ' . $s['folio'] . ' cerró con una diferencia de '
                        . Monto::deCentavos($dif)->formateado()
                        . ' (' . ($dif > 0 ? 'sobrante' : 'faltante') . '). '
                        . 'Conviene arquearlo antes de que termine el día.',
                        ['folio' => $s['folio'], 'sesion' => $sesionId],
                        'alerta'
                    );
                }
            }

            return [
                'folio'      => $s['folio'],
                'esperado'   => Monto::deCentavos($esperado)->jsonSerialize(),
                'contado'    => Monto::deCentavos($contado)->jsonSerialize(),
                'diferencia' => Monto::deCentavos($dif)->jsonSerialize(),
                'estado'     => $estado,
                'tolerancia' => Monto::deCentavos($tolerancia)->jsonSerialize(),
            ];
        });
    }

    /**
     * Arquea un turno cerrado: otra persona lo revisa y lo da por bueno.
     *
     * @return array<string,mixed>
     */
    public function arquear(int $sesionId, string $observaciones = ''): array
    {
        Autorizacion::exigir('caja.arquear');
        $usuarioId = Sesion::exigirAutenticado();

        $s = BaseDatos::unaFila('SELECT * FROM caja_sesiones WHERE id = ?', [$sesionId]);

        if ($s === null) {
            throw new \RuntimeException('Turno no encontrado.');
        }

        if ((string) $s['estado'] === 'abierta') {
            throw new \RuntimeException('El turno todavía está abierto.');
        }

        // Los cuatro ojos: el cajero no se arquea a sí mismo.
        if ((int) $s['usuario_id'] === $usuarioId) {
            throw new \RuntimeException(
                'Su propio turno lo tiene que arquear otra persona.'
            );
        }

        BaseDatos::ejecutar(
            'UPDATE caja_sesiones
                SET arqueada_por = ?, arqueada_en = NOW(),
                    observaciones = TRIM(CONCAT(COALESCE(observaciones,""), ?))
              WHERE id = ?',
            [
                $usuarioId,
                $observaciones !== ''
                    ? "\n[arqueo " . date('d/m/Y H:i') . '] ' . $observaciones
                    : '',
                $sesionId,
            ]
        );

        Auditoria::registrar('caja.arqueada', Auditoria::EXITO, $usuarioId,
            'caja_sesion', (string) $sesionId, ['folio' => $s['folio']]);

        $this->notificaciones->crear(
            (int) $s['usuario_id'],
            'caja.arqueo',
            'Su turno de caja quedó arqueado',
            'Revisamos el turno ' . $s['folio'] . ' y quedó arqueado. '
            . ((int) $s['diferencia_cent'] === 0
                ? 'Cuadró exacto, muchas gracias por el cuidado.'
                : 'La diferencia registrada fue de '
                  . Monto::deCentavos((int) $s['diferencia_cent'])->formateado() . '.'),
            ['folio' => $s['folio']],
            'info'
        );

        return ['arqueada' => true, 'folio' => $s['folio']];
    }

    // -----------------------------------------------------------------

    /**
     * Cuánto efectivo debería haber en la caja ahora mismo.
     *
     * Se calcula sumando los movimientos, no guardando un contador: un
     * saldo guardado se puede desincronizar y entonces el arqueo mide
     * contra un número equivocado. Sumar el detalle siempre dice la
     * verdad.
     *
     * @return array<string,mixed>
     */
    public function resumen(int $sesionId): array
    {
        /* Lo que debe haber en la gaveta es SOLO el efectivo.
         *
         * Un cheque entra por la ventanilla y se abona a la cuenta del
         * cliente, pero no deja un billete más en la caja: se remesa al
         * banco. Contándolo como efectivo, el arqueo le reclamaba al
         * cajero un dinero que nunca tuvo —un depósito de $500 en
         * cheque le dejaba $500 de faltante al cerrar— y el descuadre
         * parecía suyo.
         *
         * Las entradas y las salidas siguen contándolo todo: son el
         * movimiento de la ventanilla, no el contenido de la gaveta. */
        $r = BaseDatos::unaFila(
            'SELECT
                COALESCE(SUM(CASE WHEN medio = "efectivo" THEN importe_cent END), 0) AS esperado_cent,
                COALESCE(SUM(CASE WHEN medio = "cheque"   THEN importe_cent END), 0) AS cheques_cent,
                COALESCE(SUM(CASE WHEN medio NOT IN ("efectivo","cheque")
                                  THEN importe_cent END), 0)                    AS otros_cent,
                COALESCE(SUM(CASE WHEN importe_cent > 0 THEN importe_cent END), 0)  AS entradas_cent,
                COALESCE(SUM(CASE WHEN importe_cent < 0 THEN -importe_cent END), 0) AS salidas_cent,
                COUNT(*)                                                      AS operaciones
               FROM caja_movimientos WHERE sesion_id = ?',
            [$sesionId]
        ) ?? [];

        $porTipo = BaseDatos::todas(
            'SELECT tipo, COUNT(*) AS n, COALESCE(SUM(importe_cent),0) AS total_cent
               FROM caja_movimientos WHERE sesion_id = ?
              GROUP BY tipo ORDER BY tipo',
            [$sesionId]
        );

        /* Por medio de pago: es lo que el cajero entrega al cerrar.
           El efectivo se cuenta, los cheques se entregan en fajo con
           su remesa, y cada cosa se cuadra por su lado. */
        $porMedio = BaseDatos::todas(
            'SELECT medio, COUNT(*) AS n, COALESCE(SUM(importe_cent),0) AS total_cent
               FROM caja_movimientos WHERE sesion_id = ?
              GROUP BY medio ORDER BY medio',
            [$sesionId]
        );

        return [
            'esperado_cent' => (int) ($r['esperado_cent'] ?? 0),
            // Lo que se remesa al banco, no lo que se cuenta a mano.
            'cheques_cent'  => (int) ($r['cheques_cent'] ?? 0),
            'otros_cent'    => (int) ($r['otros_cent'] ?? 0),
            'entradas_cent' => (int) ($r['entradas_cent'] ?? 0),
            'salidas_cent'  => (int) ($r['salidas_cent'] ?? 0),
            'operaciones'   => (int) ($r['operaciones'] ?? 0),
            'por_tipo'      => $porTipo,
            'por_medio'     => $porMedio,
        ];
    }

    /** @param array<string,mixed> $extra */
    private function anotar(
        int $sesionId,
        string $tipo,
        int $importeCent,
        string $concepto,
        array $extra
    ): int {
        $referencia = Folio::unico('MC', 'caja_movimientos', 'referencia', 'ymdHis');

        /* Con qué se pagó. Por omisión, efectivo: es lo que pasa en una
           ventanilla la mayor parte del tiempo, y así ninguna de las
           llamadas que ya existían cambia de comportamiento. */
        $medio = (string) ($extra['medio'] ?? 'efectivo');

        if (!in_array($medio, ['efectivo', 'cheque', 'transferencia', 'tarjeta'], true)) {
            $medio = 'efectivo';
        }

        BaseDatos::ejecutar(
            'INSERT INTO caja_movimientos
               (sesion_id, tipo, medio, cheque_banco, cheque_numero, importe_cent,
                cuenta_id, cliente_id, credito_id,
                tarjeta_id, asiento_id, referencia, concepto)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $sesionId,
                $tipo,
                $medio,
                $medio === 'cheque'
                    ? (mb_substr(trim((string) ($extra['cheque_banco'] ?? '')), 0, 80) ?: null)
                    : null,
                $medio === 'cheque'
                    ? (mb_substr(trim((string) ($extra['cheque_numero'] ?? '')), 0, 30) ?: null)
                    : null,
                $importeCent,
                isset($extra['cuenta_id'])  ? (int) $extra['cuenta_id']  : null,
                isset($extra['cliente_id']) ? (int) $extra['cliente_id'] : null,
                isset($extra['credito_id']) ? (int) $extra['credito_id'] : null,
                isset($extra['tarjeta_id']) ? (int) $extra['tarjeta_id'] : null,
                isset($extra['asiento_id']) ? (int) $extra['asiento_id'] : null,
                $referencia,
                mb_substr($concepto, 0, 190),
            ]
        );

        return BaseDatos::ultimoId();
    }
}
