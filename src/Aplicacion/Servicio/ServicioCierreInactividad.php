<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

/**
 * Cierre de cuentas de ahorro inactivas.
 *
 * Una cuenta en cero y sin movimiento durante meses no le sirve a
 * nadie: al titular no le da nada y a la institución le cuesta
 * mantenerla, auditarla y reportarla. Se cierra.
 *
 * PRIMERO SE AVISA, DESPUÉS SE CIERRA
 *
 * Son dos pasadas y no una. Cerrar sin avisar es lo que convierte un
 * trámite de limpieza en un reclamo: el cliente que vuelve a los ocho
 * meses se encuentra su cuenta cerrada, su número dado de baja y la
 * sensación de que el banco le hizo algo a sus espaldas. Con el aviso
 * tiene treinta días para mover un centavo y conservarla, que es todo
 * lo que hace falta.
 *
 * El aviso se manda una sola vez por cuenta: `aviso_inactividad_en`
 * guarda cuándo. Sin esa marca, cada pasada diaria le mandaría otro
 * correo diciendo lo mismo, que es la forma más segura de que deje de
 * leerlos.
 *
 * LO QUE NO SE CIERRA
 *
 *   - Las cuentas con saldo, ni un centavo. Cerrar una cuenta con
 *     dinero dentro es quedarse con dinero ajeno.
 *   - Las cuentas técnicas, que son del sistema.
 *   - Las de quien tenga un crédito vivo: su cuenta es por donde se
 *     cobra, y cerrarla deja al deudor sin forma de pagar.
 *   - Las que ya están cerradas o bloqueadas.
 *
 * EL NÚMERO NO VUELVE
 *
 * Al cerrar, el número pasa a `numeros_cuenta_retirados` y no se
 * asigna nunca más. Un número de cuenta vive fuera del sistema —en
 * recibos, en órdenes de pago, en la libreta de un proveedor— y esa
 * vida no termina cuando la cuenta se cierra. Reutilizarlo es lo que
 * hace que un depósito dirigido a la cuenta vieja de alguien aterrice
 * en la cuenta nueva de otro.
 */
final class ServicioCierreInactividad
{
    private ServicioNotificacion $notificaciones;

    public function __construct(?ServicioNotificacion $notificaciones = null)
    {
        $this->notificaciones = $notificaciones ?? new ServicioNotificacion();
    }

    public function activo(): bool
    {
        return Parametros::booleano('cuenta.cierre_automatico', true);
    }

    public function mesesDeInactividad(): int
    {
        return max(1, Parametros::entero('cuenta.inactividad_meses', 6));
    }

    public function diasDeAviso(): int
    {
        return max(1, Parametros::entero('cuenta.inactividad_aviso_dias', 30));
    }

    /**
     * Las que cumplen el plazo y todavía no han sido avisadas.
     *
     * @return list<array<string,mixed>>
     */
    public function porAvisar(): array
    {
        return BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, c.usuario_id, c.apertura_en,
                    p.nombre AS producto,
                    u.nombres, u.apellidos, u.correo,
                    (SELECT MAX(m.creado_en) FROM movimientos m WHERE m.cuenta_id = c.id)
                        AS ultimo_movimiento
               FROM cuentas c
               JOIN productos p ON p.id = c.producto_id
               JOIN usuarios  u ON u.id = c.usuario_id
              WHERE c.es_tecnica = 0
                AND c.estado = "activa"
                AND c.saldo_cent = 0
                AND c.aviso_inactividad_en IS NULL
                AND ' . $this->condicionSinMovimiento() . '
                AND NOT EXISTS (
                      SELECT 1 FROM creditos cr
                       WHERE cr.usuario_id = c.usuario_id
                         AND cr.estado IN ("vigente","desembolsado","mora")
                    )
              ORDER BY c.id'
        );
    }

    /**
     * Las avisadas a las que ya se les pasó el plazo de gracia.
     *
     * @return list<array<string,mixed>>
     */
    public function porCerrar(): array
    {
        return BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, c.usuario_id, c.aviso_inactividad_en,
                    p.nombre AS producto,
                    u.nombres, u.apellidos, u.correo
               FROM cuentas c
               JOIN productos p ON p.id = c.producto_id
               JOIN usuarios  u ON u.id = c.usuario_id
              WHERE c.es_tecnica = 0
                AND c.estado = "activa"
                AND c.saldo_cent = 0
                AND c.aviso_inactividad_en IS NOT NULL
                AND c.aviso_inactividad_en <= DATE_SUB(NOW(), INTERVAL ? DAY)
                AND ' . $this->condicionSinMovimiento() . '
                AND NOT EXISTS (
                      SELECT 1 FROM creditos cr
                       WHERE cr.usuario_id = c.usuario_id
                         AND cr.estado IN ("vigente","desembolsado","mora")
                    )
              ORDER BY c.id',
            [$this->diasDeAviso()]
        );
    }

    /**
     * «Sin movimiento» de verdad.
     *
     * Una cuenta recién abierta no tiene movimientos, y sin la segunda
     * mitad de esta condición entraría al cierre por no haberlos
     * tenido nunca: se cerraría sola al día siguiente de abrirse. Por
     * eso cuenta también desde la apertura.
     */
    private function condicionSinMovimiento(): string
    {
        $meses = $this->mesesDeInactividad();

        return '(
                  COALESCE(
                    (SELECT MAX(m.creado_en) FROM movimientos m WHERE m.cuenta_id = c.id),
                    c.apertura_en
                  ) <= DATE_SUB(NOW(), INTERVAL ' . $meses . ' MONTH)
                )';
    }

    /**
     * Avisa al titular de que su cuenta va a cerrarse.
     *
     * @return bool Si se avisó.
     */
    public function avisar(array $c): bool
    {
        $dias  = $this->diasDeAviso();
        $meses = $this->mesesDeInactividad();

        $mensaje = 'Su cuenta ' . $c['numero_cuenta'] . ' lleva más de ' . $meses
            . ' meses en cero y sin movimientos, por lo que será cerrada en '
            . $dias . ' días. Si quiere conservarla, basta con que haga un '
            . 'movimiento —un depósito, aunque sea pequeño— antes de esa fecha. '
            . 'Si no hace nada, la cuenta se cerrará y su número no volverá a '
            . 'asignarse.';

        BaseDatos::ejecutar(
            'UPDATE cuentas SET aviso_inactividad_en = NOW() WHERE id = ?',
            [(int) $c['id']]
        );

        $this->notificaciones->crear(
            (int) $c['usuario_id'],
            'cuenta.inactividad',
            'Su cuenta ' . $c['numero_cuenta'] . ' se cerrará en ' . $dias . ' días',
            $mensaje,
            ['cuenta' => (string) $c['numero_cuenta'], 'dias' => (string) $dias],
            'alerta'
        );

        $this->correo(
            $c,
            'Su cuenta ' . $c['numero_cuenta'] . ' se cerrará por inactividad',
            $mensaje,
            [
                ['etiqueta' => 'Cuenta',   'valor' => (string) $c['numero_cuenta']],
                ['etiqueta' => 'Producto', 'valor' => (string) $c['producto']],
                ['etiqueta' => 'Plazo',    'valor' => $dias . ' días'],
            ]
        );

        Auditoria::registrar(
            'cuenta.aviso_inactividad',
            Auditoria::EXITO,
            null,
            'cuenta',
            (string) $c['id'],
            ['numero' => $c['numero_cuenta'], 'dias' => $dias]
        );

        return true;
    }

    /**
     * Cierra la cuenta y retira su número para siempre.
     *
     * @return bool Si se cerró.
     */
    public function cerrar(array $c): bool
    {
        $meses = $this->mesesDeInactividad();

        $motivo = 'Cierre automatico por inactividad: ' . $meses
            . ' meses en cero y sin movimientos.';

        $hecho = BaseDatos::enTransaccion(function () use ($c, $motivo): bool {
            /* Se relee con bloqueo y se comprueba otra vez el saldo.
             *
             * Entre que la consulta eligió la cuenta y llegamos aquí
             * pudo entrar un depósito. Cerrar entonces sería cerrar una
             * cuenta con dinero dentro, que es exactamente lo que no
             * puede pasar nunca. */
            $hoy = BaseDatos::unaFila(
                'SELECT id, numero_cuenta, saldo_cent, estado
                   FROM cuentas WHERE id = ? FOR UPDATE',
                [(int) $c['id']]
            );

            if ($hoy === null
                || (string) $hoy['estado'] !== 'activa'
                || (int) $hoy['saldo_cent'] !== 0) {
                return false;
            }

            BaseDatos::ejecutar(
                'UPDATE cuentas
                    SET estado = "cerrada", cerrada_en = NOW(), cierre_motivo = ?
                  WHERE id = ?',
                [$motivo, (int) $c['id']]
            );

            /* El número se retira. `INSERT IGNORE` porque un número que
               ya estuviera retirado no es un error: es el estado al que
               se quería llegar. */
            BaseDatos::ejecutar(
                'INSERT IGNORE INTO numeros_cuenta_retirados
                   (numero_cuenta, cuenta_id, motivo, retirado_en)
                 VALUES (?,?,?,NOW())',
                [(string) $hoy['numero_cuenta'], (int) $c['id'], $motivo]
            );

            return true;
        });

        if (!$hecho) { return false; }

        $mensaje = 'Su cuenta ' . $c['numero_cuenta'] . ' fue cerrada por inactividad, '
            . 'como le avisamos. Su número no volverá a asignarse. Si necesita una '
            . 'cuenta nueva puede abrirla en la banca en línea o en cualquier agencia.';

        $this->notificaciones->crear(
            (int) $c['usuario_id'],
            'cuenta.cerrada',
            'Cuenta ' . $c['numero_cuenta'] . ' cerrada por inactividad',
            $mensaje,
            ['cuenta' => (string) $c['numero_cuenta']],
            'alerta'
        );

        $this->correo(
            $c,
            'Su cuenta ' . $c['numero_cuenta'] . ' fue cerrada',
            $mensaje,
            [
                ['etiqueta' => 'Cuenta',   'valor' => (string) $c['numero_cuenta']],
                ['etiqueta' => 'Producto', 'valor' => (string) $c['producto']],
                ['etiqueta' => 'Motivo',   'valor' => 'Inactividad'],
            ]
        );

        Auditoria::registrar(
            'cuenta.cerrada_inactividad',
            Auditoria::EXITO,
            null,
            'cuenta',
            (string) $c['id'],
            ['numero' => $c['numero_cuenta'], 'motivo' => $motivo]
        );

        return true;
    }

    /** @param list<array{etiqueta:string,valor:string}> $filas */
    private function correo(array $c, string $asunto, string $mensaje, array $filas): void
    {
        $correo = trim((string) ($c['correo'] ?? ''));

        if ($correo === '') { return; }

        try {
            $html = PlantillaCorreo::base(
                $asunto,
                'Estimado(a) ' . trim((string) $c['nombres'] . ' ' . (string) $c['apellidos']),
                '<p>' . htmlspecialchars($mensaje, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>',
                $filas,
                null,
                null,
                null,
                'Si no reconoce esta cuenta, comuníquese con nosotros.'
            );

            (new Correo())->enviar($correo, $asunto, $html, $mensaje);
        } catch (\Throwable $e) {
            /* El cierre ya está hecho y es correcto; que falle el correo
               no puede deshacerlo. Queda en el registro para poder
               reclamarlo después. */
            error_log('[CierreInactividad] sin avisar a ' . $correo . ': ' . $e->getMessage());
        }
    }

    /** ¿Este número ya se usó y no debe volver a asignarse? */
    public static function numeroRetirado(string $numero): bool
    {
        return BaseDatos::unValor(
            'SELECT 1 FROM numeros_cuenta_retirados WHERE numero_cuenta = ?',
            [$numero]
        ) !== null;
    }
}
