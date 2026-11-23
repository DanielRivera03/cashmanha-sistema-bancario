<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Hashid;

/**
 * La cartera de un banquero.
 *
 * La pantalla de banca preferente le promete al cliente «una persona
 * asignada que lleva su relación con la institución». Esa persona ya
 * existe y tiene clientes asignados, pero no tenía dónde verlos: su
 * cartera estaba en una columna de la base y en ningún sitio más.
 *
 * Lo que un banquero necesita no es un listado de nombres. Necesita
 * saber a quién le toca atender HOY: quién abrió una gestión que no ha
 * contestado nadie, quién tiene una solicitud esperando, quién se
 * atrasó en una cuota, quién cambió de escalón. Un listado alfabético
 * de cuarenta clientes no dice nada de eso y obliga a entrar en cada
 * uno para descubrirlo.
 *
 * Por eso esto devuelve la cartera ORDENADA POR LO QUE RECLAMA
 * atención, no por apellido.
 */
final class ServicioCartera
{
    /**
     * Los clientes de este banquero, con lo que reclama su atención.
     *
     * @return array{
     *   clientes: list<array<string,mixed>>,
     *   resumen: array<string,int>
     * }
     */
    public function de(int $banqueroId): array
    {
        $filas = BaseDatos::todas(
            'SELECT u.id, u.usuario, u.segmento, u.banquero_desde,
                    CONCAT(u.nombres," ",u.apellidos) AS nombre,
                    u.telefono, u.correo,

                    /* Gestiones abiertas que todavía no ha contestado
                       nadie del equipo: son las que de verdad esperan. */
                    (SELECT COUNT(*) FROM tickets t
                      WHERE t.usuario_id = u.id
                        AND t.estado IN ("abierto","en_proceso")) AS gestiones,

                    (SELECT COUNT(*) FROM tickets t
                      WHERE t.usuario_id = u.id
                        AND t.estado = "abierto"
                        AND NOT EXISTS (SELECT 1 FROM ticket_respuestas r
                                         WHERE r.ticket_id = t.id AND r.interna = 0)
                     ) AS sin_contestar,

                    (SELECT COUNT(*) FROM solicitudes_tarjeta s
                      WHERE s.usuario_id = u.id
                        AND s.etapa NOT IN ("aprobada","rechazada","emitida")) AS solicitudes,

                    (SELECT COUNT(*) FROM cuotas q JOIN creditos cr ON cr.id = q.credito_id
                      WHERE cr.usuario_id = u.id AND q.estado = "vencida") AS cuotas_vencidas,

                    (SELECT COALESCE(SUM(q.total_cent - q.pagado_cent + q.mora_cent), 0)
                       FROM cuotas q JOIN creditos cr ON cr.id = q.credito_id
                      WHERE cr.usuario_id = u.id
                        AND q.estado IN ("vencida","parcial")) AS vencido_cent,

                    (SELECT COALESCE(SUM(c.saldo_cent), 0) FROM cuentas c
                      WHERE c.usuario_id = u.id AND c.es_tecnica = 0
                        AND c.estado = "activa") AS depositos_cent,

                    (SELECT MAX(m.creado_en) FROM mensajes m
                      WHERE m.remitente_id = u.id) AS ultimo_mensaje

               FROM usuarios u
              WHERE u.banquero_id = ? AND u.estado = "activo"',
            [$banqueroId]
        );

        $clientes = [];

        foreach ($filas as $f) {
            $urgencia = 0;

            /* Lo que decide el orden. Una gestión sin contestar pesa
               más que una mora: la mora la trabaja cobranza y lleva su
               propio proceso; una gestión sin respuesta es alguien
               esperando a que le contesten, y eso es exactamente lo que
               esta persona tiene asignado. */
            $urgencia += (int) $f['sin_contestar']    * 100;
            $urgencia += (int) $f['gestiones']        * 20;
            $urgencia += (int) $f['solicitudes']      * 15;
            $urgencia += (int) $f['cuotas_vencidas']  * 5;

            $clientes[] = [
                'codigo'          => Hashid::codificar((int) $f['id'], 'usuario'),
                'nombre'          => (string) $f['nombre'],
                'usuario'         => (string) $f['usuario'],
                'segmento'        => (string) $f['segmento'],
                'telefono'        => (string) ($f['telefono'] ?? ''),
                'desde'           => (string) ($f['banquero_desde'] ?? ''),
                'gestiones'       => (int) $f['gestiones'],
                'sin_contestar'   => (int) $f['sin_contestar'],
                'solicitudes'     => (int) $f['solicitudes'],
                'cuotas_vencidas' => (int) $f['cuotas_vencidas'],
                'vencido_cent'    => (int) $f['vencido_cent'],
                'depositos_cent'  => (int) $f['depositos_cent'],
                'ultimo_mensaje'  => (string) ($f['ultimo_mensaje'] ?? ''),
                'urgencia'        => $urgencia,
            ];
        }

        /* Por lo que reclama atención, y a igualdad, por depósitos.
           Un listado alfabético de cuarenta clientes no dice a quién
           atender primero y obliga a entrar en cada uno. */
        usort($clientes, static function (array $a, array $b): int {
            return [$b['urgencia'], $b['depositos_cent']]
               <=> [$a['urgencia'], $a['depositos_cent']];
        });

        $resumen = [
            'clientes'      => count($clientes),
            'sin_contestar' => array_sum(array_column($clientes, 'sin_contestar')),
            'gestiones'     => array_sum(array_column($clientes, 'gestiones')),
            'solicitudes'   => array_sum(array_column($clientes, 'solicitudes')),
            'con_mora'      => count(array_filter(
                $clientes,
                static fn(array $c): bool => $c['cuotas_vencidas'] > 0
            )),
            'depositos_cent' => array_sum(array_column($clientes, 'depositos_cent')),
        ];

        return ['clientes' => $clientes, 'resumen' => $resumen];
    }

    /**
     * ¿Este cliente es de este banquero?
     *
     * La restricción a «sus» clientes la aplica la aplicación, no el
     * rol: el permiso `cliente.ver_expediente` le deja ver expedientes,
     * y es aquí donde se decide CUÁLES. Sin esto, un banquero podría
     * abrir la ficha de cualquiera cambiando el código de la URL.
     */
    public function esSuyo(int $banqueroId, int $clienteId): bool
    {
        return (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM usuarios WHERE id = ? AND banquero_id = ?',
            [$clienteId, $banqueroId]
        ) > 0;
    }

    /**
     * Las gestiones de un cliente, las mismas que ve él.
     *
     * Es el punto del encargo: que cliente y empleado miren lo mismo.
     * Las notas internas quedan fuera —no todo lo que el personal
     * anota es para el titular— pero el hilo de la conversación es el
     * mismo objeto, no dos listas que se parecen.
     *
     * @return list<array<string,mixed>>
     */
    public function gestionesDe(int $clienteId): array
    {
        $filas = BaseDatos::todas(
            'SELECT t.id, t.folio, t.titulo, t.estado, t.prioridad, t.via,
                    t.creado_en, t.ultima_actividad,
                    CONCAT(a.nombres," ",a.apellidos) AS responsable,
                    (SELECT COUNT(*) FROM ticket_respuestas r
                      WHERE r.ticket_id = t.id AND r.interna = 0) AS respuestas,
                    (SELECT MAX(r.creado_en) FROM ticket_respuestas r
                      WHERE r.ticket_id = t.id AND r.interna = 0) AS ultima_respuesta
               FROM tickets t
          LEFT JOIN usuarios a ON a.id = t.asignado_a
              WHERE t.usuario_id = ?
              ORDER BY FIELD(t.estado, "abierto","en_proceso","resuelto","no_resuelto","cerrado"),
                       t.ultima_actividad DESC
              LIMIT 40',
            [$clienteId]
        );

        foreach ($filas as &$f) {
            $f['codigo'] = Hashid::codificar((int) $f['id'], 'ticket');

            /* Si el cliente escribió lo último, le toca al banquero.
               Es la señal que convierte una lista de gestiones en una
               lista de tareas. */
            $f['espera_respuesta'] = (int) $f['respuestas'] === 0
                && in_array((string) $f['estado'], ['abierto', 'en_proceso'], true);

            unset($f['id']);
        }
        unset($f);

        return $filas;
    }

    /** El banquero de un cliente, para enseñárselo a él. */
    public function banqueroDe(int $clienteId): ?array
    {
        $b = BaseDatos::unaFila(
            'SELECT b.id, CONCAT(b.nombres," ",b.apellidos) AS nombre,
                    b.correo, b.telefono, u.banquero_desde
               FROM usuarios u
               JOIN usuarios b ON b.id = u.banquero_id
              WHERE u.id = ? AND b.estado = "activo"',
            [$clienteId]
        );

        if ($b === null) {
            return null;
        }

        return [
            'codigo'   => Hashid::codificar((int) $b['id'], 'usuario'),
            'nombre'   => (string) $b['nombre'],
            'correo'   => (string) $b['correo'],
            'telefono' => (string) ($b['telefono'] ?? ''),
            'desde'    => (string) ($b['banquero_desde'] ?? ''),
        ];
    }

    /** Para el encabezado: lo que lleva en total. */
    public function totalDepositos(int $banqueroId): Monto
    {
        return Monto::deCentavos((int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(c.saldo_cent), 0)
               FROM cuentas c JOIN usuarios u ON u.id = c.usuario_id
              WHERE u.banquero_id = ? AND c.es_tecnica = 0 AND c.estado = "activa"',
            [$banqueroId]
        ));
    }
}
