<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

/**
 * Los límites que el cliente administra por sí mismo.
 *
 * DOS TECHOS, NO UNO
 *
 * `limites_operacion` son los del BANCO, por rol: el techo
 * institucional, que el cliente no ve y no toca.
 *
 * Éstos son los del CLIENTE, por canal. La regla es simple y no tiene
 * excepciones: puede BAJARLOS cuanto quiera, nunca subirlos por encima
 * del techo del banco.
 *
 * POR QUÉ ESTO IMPORTA DE VERDAD
 *
 * Un límite bajo es la diferencia entre un susto y una ruina. Quien
 * casi nunca transfiere puede dejarlo en cien dólares: aunque le roben
 * la contraseña y el teléfono, el daño tiene techo. Y es el propio
 * cliente quien mejor sabe cuánto necesita mover, no el banco.
 *
 * Por eso bajar un límite es inmediato y no pide nada, mientras que
 * SUBIRLO pide segundo factor: bajarlo solo puede protegerte, subirlo
 * es lo que querría hacer un atacante antes de vaciar la cuenta.
 */
final class ServicioLimitesCliente
{
    /** Los canales que el cliente puede administrar. */
    public const CANALES = [
        'interna'           => 'Transferencias entre cuentas de CashMan H.A.',
        'transfer365'       => 'Transfer365 a otros bancos',
        'transfer365_movil' => 'Transfer365 Móvil (por celular)',
        'uni'               => 'UNI',
        'servicios'         => 'Pago de servicios y colecturías',
    ];

    /**
     * Lo que rige para un cliente en un canal.
     *
     * Si nunca tocó sus límites, rige el techo del banco entero: no se
     * le estorba a quien no pidió que se le estorbara.
     *
     * @return array{max_operacion_cent:int, max_diario_cent:int,
     *               max_ops_diarias:int, propio:bool, techo_operacion_cent:int,
     *               techo_diario_cent:int, techo_ops_diarias:int}
     */
    public function vigente(int $usuarioId, string $canal): array
    {
        $techo = $this->techoInstitucional($canal);

        $propio = BaseDatos::unaFila(
            'SELECT * FROM limites_canal_cliente WHERE usuario_id = ? AND canal = ?',
            [$usuarioId, $canal]
        );

        if ($propio === null) {
            return [
                'max_operacion_cent'   => $techo['max_operacion_cent'],
                'max_diario_cent'      => $techo['max_diario_cent'],
                'max_ops_diarias'      => $techo['max_ops_diarias'],
                'propio'               => false,
                'techo_operacion_cent' => $techo['max_operacion_cent'],
                'techo_diario_cent'    => $techo['max_diario_cent'],
                'techo_ops_diarias'    => $techo['max_ops_diarias'],
            ];
        }

        /* El mínimo entre lo que pidió el cliente y lo que permite el
           banco. Si el banco BAJÓ su techo después, el del cliente no
           puede quedar por encima por haberse guardado antes. */
        return [
            'max_operacion_cent'   => min((int) $propio['max_operacion_cent'], $techo['max_operacion_cent']),
            'max_diario_cent'      => min((int) $propio['max_diario_cent'], $techo['max_diario_cent']),
            'max_ops_diarias'      => min((int) $propio['max_ops_diarias'], $techo['max_ops_diarias']),
            'propio'               => true,
            'techo_operacion_cent' => $techo['max_operacion_cent'],
            'techo_diario_cent'    => $techo['max_diario_cent'],
            'techo_ops_diarias'    => $techo['max_ops_diarias'],
        ];
    }

    /**
     * Comprueba el límite del cliente antes de mover dinero.
     *
     * El institucional se comprueba aparte, en cada servicio: son dos
     * techos y los dos tienen que cumplirse.
     */
    public function verificar(int $usuarioId, string $canal, Monto $monto): void
    {
        $l = $this->vigente($usuarioId, $canal);

        if ($monto->centavos > $l['max_operacion_cent']) {
            throw new LimiteExcedidoTransferencia(
                'El monto supera su límite por operación en este canal ('
                . Monto::deCentavos($l['max_operacion_cent'])->formateado()
                . '). Puede cambiarlo desde Límites.'
            );
        }

        $hoy = $this->usadoHoy($usuarioId, $canal);

        if ($hoy['monto_cent'] + $monto->centavos > $l['max_diario_cent']) {
            throw new LimiteExcedidoTransferencia(
                'Supera su límite diario en este canal. Le queda hoy: '
                . Monto::deCentavos(max(0, $l['max_diario_cent'] - $hoy['monto_cent']))->formateado() . '.'
            );
        }

        if ($hoy['operaciones'] >= $l['max_ops_diarias']) {
            throw new LimiteExcedidoTransferencia(
                'Alcanzó su máximo de operaciones del día en este canal ('
                . $l['max_ops_diarias'] . ').'
            );
        }
    }

    /**
     * Lo gastado hoy en un canal.
     *
     * @return array{monto_cent:int, operaciones:int}
     */
    public function usadoHoy(int $usuarioId, string $canal): array
    {
        if ($canal === 'interna') {
            $f = BaseDatos::unaFila(
                'SELECT COALESCE(SUM(-m.importe_cent), 0) AS monto_cent, COUNT(*) AS operaciones
                   FROM movimientos m
                   JOIN asientos a ON a.id = m.asiento_id
                   JOIN cuentas c  ON c.id = m.cuenta_id
                  WHERE c.usuario_id = ? AND m.importe_cent < 0
                    AND a.tipo = "transferencia" AND DATE(a.creado_en) = CURDATE()',
                [$usuarioId]
            );
        } else {
            $f = BaseDatos::unaFila(
                'SELECT COALESCE(SUM(monto_cent), 0) AS monto_cent, COUNT(*) AS operaciones
                   FROM transferencias_interbancarias
                  WHERE usuario_id = ? AND canal = ? AND sentido = "enviada"
                    AND estado <> "rechazada" AND DATE(creada_en) = CURDATE()',
                [$usuarioId, $canal]
            );
        }

        return [
            'monto_cent'  => (int) ($f['monto_cent'] ?? 0),
            'operaciones' => (int) ($f['operaciones'] ?? 0),
        ];
    }

    /**
     * El cliente fija sus límites.
     *
     * Devuelve si la operación requirió segundo factor, para que la
     * pantalla sepa qué pedir. Subir pide código; bajar, no.
     *
     * @return array<string,mixed>
     */
    public function fijar(
        int $usuarioId,
        string $canal,
        int $maxOperacionCent,
        int $maxDiarioCent,
        int $maxOpsDiarias,
        ?int $actorId = null
    ): array {
        if (!isset(self::CANALES[$canal])) {
            throw new LimiteExcedidoTransferencia('Canal no reconocido.');
        }

        $techo = $this->techoInstitucional($canal);

        if ($maxOperacionCent < 100) {
            throw new LimiteExcedidoTransferencia('El límite por operación no puede ser menor a $1.00.');
        }

        if ($maxDiarioCent < $maxOperacionCent) {
            throw new LimiteExcedidoTransferencia(
                'El límite diario no puede ser menor que el límite por operación.'
            );
        }

        if ($maxOpsDiarias < 1) {
            throw new LimiteExcedidoTransferencia('Debe permitir al menos una operación por día.');
        }

        /* El techo del banco se aplica recortando, no rechazando: si el
           cliente pide más de lo permitido, se le concede lo permitido
           y se le dice. Rechazar el formulario entero por pasarse en un
           campo es castigar al cliente por no saber un número que no le
           hemos dicho. */
        $recortado = false;

        if ($maxOperacionCent > $techo['max_operacion_cent']) {
            $maxOperacionCent = $techo['max_operacion_cent'];
            $recortado = true;
        }

        if ($maxDiarioCent > $techo['max_diario_cent']) {
            $maxDiarioCent = $techo['max_diario_cent'];
            $recortado = true;
        }

        if ($maxOpsDiarias > $techo['max_ops_diarias']) {
            $maxOpsDiarias = $techo['max_ops_diarias'];
            $recortado = true;
        }

        $antes = $this->vigente($usuarioId, $canal);

        BaseDatos::ejecutar(
            'INSERT INTO limites_canal_cliente
               (usuario_id, canal, max_operacion_cent, max_diario_cent,
                max_ops_diarias, actualizado_por)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               max_operacion_cent = VALUES(max_operacion_cent),
               max_diario_cent    = VALUES(max_diario_cent),
               max_ops_diarias    = VALUES(max_ops_diarias),
               actualizado_por    = VALUES(actualizado_por)',
            [$usuarioId, $canal, $maxOperacionCent, $maxDiarioCent, $maxOpsDiarias, $actorId]
        );

        Auditoria::registrar(
            'limites.fijados',
            Auditoria::EXITO,
            $actorId,
            'usuario',
            (string) $usuarioId,
            [
                'canal'  => $canal,
                'antes'  => Monto::deCentavos($antes['max_diario_cent'])->aDecimal(),
                'ahora'  => Monto::deCentavos($maxDiarioCent)->aDecimal(),
                'subida' => $maxDiarioCent > $antes['max_diario_cent'],
            ]
        );

        return [
            'canal'              => $canal,
            'max_operacion_cent' => $maxOperacionCent,
            'max_diario_cent'    => $maxDiarioCent,
            'max_ops_diarias'    => $maxOpsDiarias,
            'recortado'          => $recortado,
            'mensaje'            => $recortado
                ? 'Se guardó, ajustado al máximo que permite el banco en este canal.'
                : 'Límites actualizados.',
        ];
    }

    /**
     * ¿Este cambio es una SUBIDA?
     *
     * Lo pregunta la pantalla antes de guardar, para saber si tiene que
     * pedir el segundo factor. Subir un límite es lo que haría un
     * atacante antes de vaciar la cuenta; bajarlo no le sirve de nada.
     */
    public function esSubida(
        int $usuarioId,
        string $canal,
        int $maxOperacionCent,
        int $maxDiarioCent,
        int $maxOpsDiarias
    ): bool {
        $a = $this->vigente($usuarioId, $canal);

        return $maxOperacionCent > $a['max_operacion_cent']
            || $maxDiarioCent    > $a['max_diario_cent']
            || $maxOpsDiarias    > $a['max_ops_diarias'];
    }

    /** Todos los canales con su estado, para la pantalla de límites.
     *
     * @return array<int,array<string,mixed>>
     */
    public function panorama(int $usuarioId): array
    {
        $filas = [];

        foreach (self::CANALES as $canal => $descripcion) {
            $l   = $this->vigente($usuarioId, $canal);
            $hoy = $this->usadoHoy($usuarioId, $canal);

            $filas[] = $l + [
                'canal'       => $canal,
                'descripcion' => $descripcion,
                'usado_hoy_cent' => $hoy['monto_cent'],
                'ops_hoy'        => $hoy['operaciones'],
                'restante_cent'  => max(0, $l['max_diario_cent'] - $hoy['monto_cent']),
            ];
        }

        return $filas;
    }

    /**
     * El techo del banco para un canal.
     *
     * @return array{max_operacion_cent:int, max_diario_cent:int, max_ops_diarias:int}
     */
    private function techoInstitucional(string $canal): array
    {
        $tipo = match ($canal) {
            'interna'   => 'transferencia',
            'uni'       => 'transfer365',
            'servicios' => 'servicios',
            default     => $canal,
        };

        $l = BaseDatos::unaFila(
            'SELECT max_por_operacion_cent, max_diario_cent, max_ops_diarias
               FROM limites_operacion
              WHERE tipo_operacion = ? AND activo = 1 AND rol_id IS NULL
              LIMIT 1',
            [$tipo]
        );

        if ($l === null) {
            /* Sin techo configurado no se concede nada. Fallar cerrado
               también aquí: un límite «infinito» por descuido de
               configuración es exactamente el agujero que esta tabla
               viene a cerrar. */
            return ['max_operacion_cent' => 0, 'max_diario_cent' => 0, 'max_ops_diarias' => 0];
        }

        return [
            'max_operacion_cent' => (int) $l['max_por_operacion_cent'],
            'max_diario_cent'    => (int) $l['max_diario_cent'],
            'max_ops_diarias'    => (int) $l['max_ops_diarias'],
        ];
    }
}
