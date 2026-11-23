<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

/**
 * Abonar a una tarjeta desde una cuenta.
 *
 * POR QUÉ EXISTE ESTA CLASE
 *
 * Esta lógica vivía dentro de `ControladorTarjetas::pagar()`, y hasta
 * ahí bien: era el único sitio que pagaba tarjetas.
 *
 * Al llegar el débito automático había DOS. Y dos implementaciones de
 * un movimiento de dinero no se quedan iguales: una se corrige, la
 * otra no, y un día el pago hecho a mano y el automático dejan la
 * tarjeta en estados distintos. Eso no se descubre hasta que un
 * cliente reclama.
 *
 * Así que la lógica vive aquí y los dos caminos la usan.
 *
 * LO QUE HACE, EN ORDEN
 *
 *   1. Bloquea la cuenta y comprueba fondos de verdad.
 *   2. Asienta en el libro mayor.
 *   3. Rebaja el saldo de la tarjeta.
 *   4. Deja el movimiento, que es lo que hace que el pago aparezca en
 *      el estado de cuenta y cuente para el mínimo del mes.
 *
 * LO QUE NO HACE
 *
 * Comprobar permisos ni propiedad. Eso es del llamador, que es quien
 * sabe si está atendiendo a una persona o corriendo un barrido.
 */
final class ServicioAbonoTarjeta
{
    /**
     * @return array<string,mixed> El asiento, con su referencia.
     * @throws FondosInsuficientes
     */
    public function abonar(
        int $tarjetaId,
        int $cuentaId,
        Monto $monto,
        ?int $actorId,
        string $concepto = 'Pago desde cuenta',
        ?string $claveIdempotencia = null
    ): array {
        if ($monto->centavos <= 0) {
            throw new \InvalidArgumentException('El abono tiene que ser mayor que cero.');
        }

        $tarjeta = BaseDatos::unaFila(
            'SELECT id, ultimos4, usuario_id, saldo_usado_cent FROM tarjetas WHERE id = ?',
            [$tarjetaId]
        );

        if ($tarjeta === null) {
            throw new \RuntimeException('La tarjeta no existe.');
        }

        return BaseDatos::enTransaccion(function () use (
            $tarjetaId, $cuentaId, $monto, $actorId, $concepto, $claveIdempotencia, $tarjeta
        ): array {
            $bloq   = LibroMayor::bloquearCuentas([$cuentaId]);
            $origen = $bloq[$cuentaId] ?? null;

            if ($origen === null) {
                throw new \RuntimeException('No se pudo bloquear la cuenta.');
            }

            $disponible = Monto::deCentavos(
                (int) $origen['saldo_cent']
                - (int) $origen['saldo_retenido_cent']
                - (int) $origen['saldo_minimo_cent']
            );

            if ($disponible->menorQue($monto)) {
                throw new FondosInsuficientes(
                    'Fondos insuficientes. Disponible: ' . $disponible->formateado()
                );
            }

            $tecnica = (int) BaseDatos::unValor(
                'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
            );

            $asiento = LibroMayor::registrar(
                'pago_tarjeta',
                [
                    ['cuenta_id' => $cuentaId, 'importe' => $monto->negado(),
                     'descripcion' => 'Pago tarjeta ••••' . $tarjeta['ultimos4']],
                    ['cuenta_id' => $tecnica, 'importe' => $monto,
                     'descripcion' => 'Abono a tarjeta ••••' . $tarjeta['ultimos4']],
                ],
                $actorId,
                $claveIdempotencia
                    ?? ('pago-tarjeta:' . $tarjetaId . ':' . bin2hex(random_bytes(10))),
                'Pago de tarjeta'
            );

            /* Un reintento con la misma clave devuelve el asiento
               original. No se vuelve a rebajar la tarjeta: eso sería
               abonar dos veces lo que se cobró una. */
            if (($asiento['duplicado'] ?? false) === true) {
                return $asiento + ['duplicado' => true];
            }

            BaseDatos::ejecutar(
                'UPDATE tarjetas SET saldo_usado_cent = GREATEST(0, saldo_usado_cent - ?)
                  WHERE id = ?',
                [$monto->centavos, $tarjetaId]
            );

            /* Reactivación automática por sobregiro solventado.
               Si la tarjeta estaba INHABILITADA por haber superado el
               límite y este pago la devuelve dentro de su línea, vuelve
               a estar activa en el acto. Solo toca 'inhabilitada'
               —nunca 'bloqueada', que es decisión de una persona y se
               levanta a mano—, y la condición se evalúa con el saldo ya
               rebajado. */
            $reactivada = BaseDatos::ejecutar(
                'UPDATE tarjetas
                    SET estado = "activa"
                  WHERE id = ? AND estado = "inhabilitada"
                    AND saldo_usado_cent <= limite_cent',
                [$tarjetaId]
            );

            /* Si este pago la devolvio dentro del limite, se lo
               confirmamos en el acto: igual que el aviso de cuando se
               inhabilito, para que el cliente sepa que ya puede usarla. */
            if ($reactivada > 0) {
                (new ServicioNotificacion())->crear(
                    (int) $tarjeta['usuario_id'],
                    'tarjeta.reactivada',
                    'Tarjeta reactivada',
                    'Su tarjeta ••••' . $tarjeta['ultimos4'] . ' volvió dentro de su límite de '
                    . 'crédito y ya puede usarla de nuevo.',
                    null,
                    'info'
                );
            }

            BaseDatos::ejecutar(
                'INSERT INTO tarjeta_movimientos
                   (tarjeta_id, asiento_id, tipo, importe_cent, comercio, referencia, estado)
                 VALUES (?, ?, "pago", ?, ?, ?, "liquidada")',
                [
                    $tarjetaId,
                    $asiento['asiento_id'],
                    $monto->centavos,
                    mb_substr($concepto, 0, 120),
                    $asiento['referencia'],
                ]
            );

            Auditoria::registrar(
                'tarjeta.pago',
                Auditoria::EXITO,
                $actorId,
                'tarjeta',
                (string) $tarjetaId,
                ['monto' => $monto->aDecimal(), 'concepto' => $concepto]
            );

            return $asiento;
        });
    }

    /**
     * Lo que se debe pagar de una tarjeta, según el modo.
     *
     * `total` es el saldo entero. `minimo` es el pago mínimo del
     * último corte, que NO es un porcentaje del saldo de hoy: es la
     * cifra que el corte fijó y la que evita la mora.
     *
     * Confundir los dos es el error clásico: pagar el 5% del saldo
     * actual puede quedarse corto respecto al mínimo del corte, y el
     * cliente creería estar al día sin estarlo.
     */
    public function montoSegunModo(int $tarjetaId, string $modo): int
    {
        $t = BaseDatos::unaFila(
            'SELECT saldo_usado_cent FROM tarjetas WHERE id = ?',
            [$tarjetaId]
        );

        if ($t === null) {
            return 0;
        }

        $saldo = (int) $t['saldo_usado_cent'];

        if ($modo === 'total') {
            return $saldo;
        }

        if ($modo !== 'minimo') {
            return 0;
        }

        $minimo = (int) (BaseDatos::unValor(
            'SELECT pago_minimo_cent FROM estados_cuenta
              WHERE tarjeta_id = ? ORDER BY fecha_corte DESC LIMIT 1',
            [$tarjetaId]
        ) ?? 0);

        /* Nunca más de lo que se debe: si el cliente abonó por otra vía
           entre el corte y hoy, el mínimo del corte puede superar el
           saldo, y cobrarlo entero dejaría la tarjeta a favor. */
        return min($minimo, $saldo);
    }
}
