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
 * Adelanto de efectivo: pasar cupo de la tarjeta a la cuenta de ahorro.
 *
 * Es de las cosas que una tarjeta de crédito hace desde siempre y que
 * aquí faltaba: el cliente puede disponer de su línea en dinero, hasta
 * el 100 % de lo que tenga libre, y le entra a su cuenta.
 *
 * NO ES UNA COMPRA, Y SE COBRA DISTINTO
 * -------------------------------------
 * En una compra el comercio paga al banco; en un adelanto el banco
 * entrega dinero y no cobra a nadie más, así que lo cobra al cliente:
 * una comisión sobre lo dispuesto, con un mínimo, y un gasto
 * administrativo fijo. Los dos van al estado de cuenta como cargos
 * aparte, con su propia línea, porque es lo que el cliente va a querer
 * mirar cuando le llegue. Las tarifas viven en `CargosDeTarjeta`.
 *
 * LO QUE SE LE DEBE AL BANCO ES MÁS QUE LO QUE RECIBE
 * ---------------------------------------------------
 * Si pide $500, recibe $500 en su cuenta y su tarjeta queda debiendo
 * $528.00: los $500 más $25.00 de comisión y $3.00 de gestión. Por eso
 * el cupo tiene que alcanzar para TODO, no solo para lo que se lleva;
 * si no, el adelanto dejaría la tarjeta por encima de su límite en el
 * mismo instante de concederlo.
 *
 * Lo que se le dice antes de confirmar es el total, no el monto: una
 * pantalla que dice «$500» y un estado de cuenta que dice «$528» es
 * una reclamación en la ventanilla.
 *
 * DOS LIBROS QUE TIENEN QUE CUADRAR
 * ---------------------------------
 * El dinero que entra a la cuenta es dinero de verdad y lleva su
 * asiento de partida doble. La deuda de la tarjeta vive en
 * `saldo_usado_cent` y en sus movimientos, y entra al corte como todo
 * lo demás. Los cargos se anotan como movimientos de tipo `comision`
 * para que el corte los recoja y el estado de cuenta los imprima.
 */
final class ServicioAdelantoEfectivo
{
    public function __construct(
        private readonly ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    /**
     * Lo que costaría un adelanto, sin hacerlo.
     *
     * La pantalla lo pide mientras el cliente teclea el monto, para que
     * vea la comisión antes de confirmar y no después.
     *
     * @return array<string,mixed>
     */
    public function simular(int $tarjetaId, mixed $monto): array
    {
        $usuarioId = Sesion::exigirAutenticado();
        $t = Autorizacion::exigirTarjeta($tarjetaId, $usuarioId);

        $montoVo = Monto::deEntradaUsuario($monto);
        $cargos  = CargosDeTarjeta::porAdelanto($montoVo, (int) $t['usuario_id']);

        $disponible = ServicioTarjeta::cupoDisponible(
            (int) $t['limite_cent'],
            (int) $t['saldo_usado_cent']
        );

        $total = $montoVo->centavos + $cargos->total()->centavos;

        return [
            'recibe'     => $montoVo->jsonSerialize(),
            'comision'   => $cargos->comision->jsonSerialize(),
            'gasto'      => $cargos->gasto->jsonSerialize(),
            'se_le_debe' => Monto::deCentavos($total)->jsonSerialize(),
            'disponible' => Monto::deCentavos($disponible)->jsonSerialize(),
            'alcanza'    => $total <= $disponible,
            'comision_bp' => $cargos->comisionBp,
        ];
    }

    /**
     * El máximo que puede adelantar: el 100 % de su cupo, cargos incluidos.
     *
     * No es el cupo disponible a secas. Si tiene $500 libres y la
     * comisión es del 5 % más $3.00, lo más que puede llevarse son
     * $473.33, porque los cargos también ocupan cupo. Decirle $500 y
     * rechazárselo al confirmar sería hacerle perder el viaje.
     */
    public function maximoDisponible(int $tarjetaId): Monto
    {
        $usuarioId = Sesion::exigirAutenticado();
        $t = Autorizacion::exigirTarjeta($tarjetaId, $usuarioId);

        $disponible = ServicioTarjeta::cupoDisponible(
            (int) $t['limite_cent'],
            (int) $t['saldo_usado_cent']
        );

        if ($disponible <= 0) {
            return Monto::deCentavos(0);
        }

        /* Se busca a la baja en vez de despejar la fórmula: la comisión
           tiene un mínimo y un redondeo, así que no es una recta y
           despejarla daría un centavo de más justo en los importes
           pequeños, que es donde el mínimo manda. Treinta pasos bastan
           para cualquier límite que exista. */
        $bajo = 0;
        $alto = $disponible;

        for ($i = 0; $i < 30 && $bajo < $alto; $i++) {
            $medio  = intdiv($bajo + $alto + 1, 2);
            $cargos = CargosDeTarjeta::porAdelanto(Monto::deCentavos($medio), (int) $t['usuario_id']);

            if ($medio + $cargos->total()->centavos <= $disponible) {
                $bajo = $medio;
            } else {
                $alto = $medio - 1;
            }
        }

        return Monto::deCentavos($bajo);
    }

    /**
     * Hace el adelanto: carga la tarjeta y abona la cuenta.
     *
     * @return array<string,mixed>
     */
    public function adelantar(int $tarjetaId, int $cuentaId, mixed $monto): array
    {
        Autorizacion::exigir('tarjeta.adelanto');
        $usuarioId = Sesion::exigirAutenticado();

        $t = Autorizacion::exigirTarjeta($tarjetaId, $usuarioId);

        if ((int) $t['usuario_id'] !== $usuarioId) {
            throw new \RuntimeException('Esa tarjeta no es suya.');
        }

        if ((string) $t['tipo'] !== 'credito') {
            throw new \RuntimeException(
                'El adelanto de efectivo es de las tarjetas de crédito. '
                . 'Con una de débito el dinero ya está en su cuenta.'
            );
        }

        $cuenta = Autorizacion::exigirCuenta($cuentaId, $usuarioId, 'cuenta.ver_todas');
        Autorizacion::exigirCuentaOperable($cuenta);

        if ((int) $cuenta['usuario_id'] !== $usuarioId) {
            throw new \RuntimeException('Esa cuenta no es suya.');
        }

        $montoVo = Monto::deEntradaUsuario($monto);

        if ($montoVo->centavos <= 0) {
            throw new \InvalidArgumentException('Indique cuánto desea adelantar.');
        }

        $cargos = CargosDeTarjeta::porAdelanto($montoVo, $usuarioId);
        $total  = $montoVo->centavos + $cargos->total()->centavos;

        $resultado = BaseDatos::enTransaccion(
            function () use ($tarjetaId, $cuentaId, $montoVo, $cargos, $total, $usuarioId, $cuenta) {
                $tt = BaseDatos::unaFila(
                    'SELECT * FROM tarjetas WHERE id = ? FOR UPDATE',
                    [$tarjetaId]
                );

                if ((string) $tt['estado'] !== 'activa') {
                    throw new \RuntimeException('La tarjeta no está activa.');
                }

                if ($tt['congelada_hasta'] !== null
                    && new \DateTimeImmutable((string) $tt['congelada_hasta']) > new \DateTimeImmutable()) {
                    throw new \RuntimeException('La tarjeta está congelada.');
                }

                /* El cupo tiene que alcanzar para el adelanto Y sus
                   cargos. Mirar solo el monto dejaría la tarjeta por
                   encima del límite en el acto, y sin sobregiro que lo
                   autorizara: un adelanto no es una compra en la caja
                   del supermercado, se decide con calma y no hay por
                   qué tolerar nada. */
                $disponible = ServicioTarjeta::cupoDisponible(
                    (int) $tt['limite_cent'],
                    (int) $tt['saldo_usado_cent']
                );

                if ($total > $disponible) {
                    throw new \RuntimeException(
                        'Su cupo no alcanza. Con la comisión y el gasto administrativo, '
                        . 'este adelanto suma ' . Monto::deCentavos($total)->formateado()
                        . ' y tiene ' . Monto::deCentavos($disponible)->formateado()
                        . ' disponibles.'
                    );
                }

                $ref = Folio::unico('ADEL', 'tarjeta_movimientos', 'referencia', 'ymd');

                // --- La deuda en la tarjeta ---------------------------
                $lineas = [
                    ['consumo',  $montoVo->centavos,          'Adelanto de efectivo',
                     'adelanto', ''],
                    ['comision', $cargos->comision->centavos, 'Comisión por adelanto de efectivo',
                     'comision', '-COM'],
                    ['comision', $cargos->gasto->centavos,    'Gasto administrativo',
                     'comision', '-ADM'],
                ];

                $movPrincipal = 0;

                foreach ($lineas as [$tipo, $importe, $concepto, $categoria, $sufijo]) {
                    if ($importe <= 0) {
                        continue;
                    }

                    BaseDatos::ejecutar(
                        'INSERT INTO tarjeta_movimientos
                           (tarjeta_id, tipo, importe_cent, comercio, categoria,
                            referencia, estado, diferible)
                         VALUES (?,?,?,?,?,?, "liquidada", 0)',
                        [$tarjetaId, $tipo, $importe, $concepto, $categoria, $ref . $sufijo]
                    );

                    if ($sufijo === '') {
                        $movPrincipal = BaseDatos::ultimoId();
                    }
                }

                BaseDatos::ejecutar(
                    'UPDATE tarjetas SET saldo_usado_cent = saldo_usado_cent + ? WHERE id = ?',
                    [$total, $tarjetaId]
                );

                // --- El dinero en la cuenta ---------------------------
                /* Esto sí es dinero y lleva su asiento. Sin él, la
                   cuenta subiría sola y el libro mayor no lo sabría. */
                $tecnica = (int) BaseDatos::unValor(
                    'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
                );

                LibroMayor::bloquearCuentas([$cuentaId, $tecnica]);

                $asiento = LibroMayor::registrar(
                    'deposito',
                    [
                        ['cuenta_id' => $cuentaId, 'importe' => $montoVo,
                         'descripcion' => 'Adelanto de efectivo de su tarjeta ••••' . $tt['ultimos4']],
                        ['cuenta_id' => $tecnica, 'importe' => $montoVo->negado(),
                         'descripcion' => 'Contrapartida de adelanto de efectivo'],
                    ],
                    $usuarioId,
                    'adelanto-' . $ref,
                    'Adelanto de efectivo ' . $ref
                );

                BaseDatos::ejecutar(
                    'UPDATE tarjeta_movimientos SET asiento_id = ? WHERE id = ?',
                    [$asiento['asiento_id'] ?? null, $movPrincipal]
                );

                return [
                    'referencia'  => $ref,
                    'asiento_id'  => $asiento['asiento_id'] ?? null,
                    'ultimos4'    => (string) $tt['ultimos4'],
                    'saldo_final' => (int) $tt['saldo_usado_cent'] + $total,
                ];
            }
        );

        Auditoria::registrar(
            'tarjeta.adelanto_efectivo', Auditoria::EXITO, $usuarioId,
            'tarjeta', (string) $tarjetaId,
            [
                'referencia' => $resultado['referencia'],
                'recibe'     => $montoVo->aDecimal(),
                'comision'   => $cargos->comision->aDecimal(),
                'gasto'      => $cargos->gasto->aDecimal(),
                'se_le_debe' => Monto::deCentavos($total)->aDecimal(),
                'cuenta'     => $cuenta['numero_cuenta'],
            ]
        );

        /* El aviso dice las dos cifras, no una.
           Lo que recibe y lo que debe no coinciden, y el cliente tiene
           que saberlo antes de que le llegue el estado de cuenta. */
        $this->notificaciones->crear(
            $usuarioId, 'tarjeta.adelanto', 'Adelanto de efectivo acreditado',
            'Se acreditaron ' . $montoVo->formateado() . ' a su cuenta '
            . $cuenta['numero_cuenta'] . ' con cargo a su tarjeta ••••'
            . $resultado['ultimos4'] . '. A su tarjeta se le cargaron '
            . Monto::deCentavos($total)->formateado() . ': el adelanto, '
            . $cargos->comision->formateado() . ' de comisión y '
            . $cargos->gasto->formateado() . ' de gasto administrativo. '
            . 'Los tres cargos aparecerán en su próximo estado de cuenta.',
            [
                'referencia'  => $resultado['referencia'],
                'asiento_id'  => $resultado['asiento_id'],
                'monto'       => $montoVo->formateado(),
                'comision'    => $cargos->comision->formateado(),
                'gasto'       => $cargos->gasto->formateado(),
                'total'       => Monto::deCentavos($total)->formateado(),
                'cuenta'      => $cuenta['numero_cuenta'],
            ],
            'alerta'
        );

        return [
            'referencia'  => $resultado['referencia'],
            'recibe'      => $montoVo->jsonSerialize(),
            'comision'    => $cargos->comision->jsonSerialize(),
            'gasto'       => $cargos->gasto->jsonSerialize(),
            'se_le_debe'  => Monto::deCentavos($total)->jsonSerialize(),
            'cuenta'      => $cuenta['numero_cuenta'],
            'ultimos4'    => $resultado['ultimos4'],
        ];
    }
}
