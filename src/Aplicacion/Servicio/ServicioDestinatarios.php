<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Hashid;

/**
 * A quién puede escribir cada quien.
 *
 * El formulario de «Redactar mensaje» le ofrecía al cliente, como
 * destinatarios, a TODO el personal activo de la institución: nombre y
 * apellidos de administradores, presidencia, gerencia y cajeros, en un
 * desplegable. Con dos empleados de prueba pasaba desapercibido; al
 * poblar la red con diez agencias se convirtió en lo que siempre fue:
 * el directorio completo de la plantilla, a disposición de cualquiera
 * que tenga una cuenta.
 *
 * Un cliente no elige a qué empleado escribe. Se dirige a un
 * DEPARTAMENTO, y el departamento reparte. La única excepción es su
 * banquero, que sí es una persona concreta y a quien se le asigna
 * precisamente para que el cliente escriba a alguien y no a una cola.
 *
 * Para el personal no cambia nada: entre ellos se escriben por nombre,
 * porque trabajan juntos y saben quién es quién.
 */
final class ServicioDestinatarios
{
    /**
     * Los departamentos a los que un cliente puede dirigirse.
     *
     * La clave es la del rol; el reparto busca dentro de ese rol a
     * quien menos carga tenga.
     *
     * @var array<string, array{etiqueta:string, nota:string}>
     */
    private const DEPARTAMENTOS = [
        'atencionclientes' => [
            'etiqueta' => 'Atención a Clientes',
            'nota'     => 'Consultas sobre sus cuentas, tarjetas, créditos y operaciones.',
        ],
    ];

    /**
     * Lo que se le puede ofrecer a este usuario como destinatario.
     *
     * @return array{
     *   es_cliente: bool,
     *   opciones: list<array{valor:string, etiqueta:string, nota:string, tipo:string}>
     * }
     */
    public function para(int $usuarioId): array
    {
        $yo = BaseDatos::unaFila(
            'SELECT u.id, u.banquero_id, r.clave AS rol
               FROM usuarios u JOIN roles r ON r.id = u.rol_id
              WHERE u.id = ?',
            [$usuarioId]
        );

        if ($yo === null) {
            return ['es_cliente' => false, 'opciones' => []];
        }

        if ((string) $yo['rol'] !== 'cliente') {
            return ['es_cliente' => false, 'opciones' => $this->personal($usuarioId)];
        }

        $opciones = [];

        /* El banquero primero, cuando lo hay: es a quien el cliente de
           cartera quiere escribir, y enterrarlo debajo del departamento
           sería contradecir lo que le promete su propia pantalla. */
        if ($yo['banquero_id'] !== null) {
            $b = BaseDatos::unaFila(
                'SELECT id, CONCAT(nombres," ",apellidos) AS nombre
                   FROM usuarios WHERE id = ? AND estado = "activo"',
                [(int) $yo['banquero_id']]
            );

            if ($b !== null) {
                $opciones[] = [
                    'valor'    => 'u:' . Hashid::codificar((int) $b['id'], 'usuario'),
                    'etiqueta' => $b['nombre'] . ' · su banquero',
                    'nota'     => 'Le responde directamente, sin pasar por la cola general.',
                    'tipo'     => 'banquero',
                ];
            }
        }

        foreach (self::DEPARTAMENTOS as $clave => $d) {
            // No se ofrece un departamento que no tiene a nadie activo:
            // el mensaje se quedaría sin destinatario al resolverlo.
            $hay = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id
                  WHERE r.clave = ? AND u.estado = "activo"',
                [$clave]
            );

            if ($hay === 0) { continue; }

            $opciones[] = [
                'valor'    => 'dep:' . $clave,
                'etiqueta' => $d['etiqueta'],
                'nota'     => $d['nota'],
                'tipo'     => 'departamento',
            ];
        }

        return ['es_cliente' => true, 'opciones' => $opciones];
    }

    /**
     * Resuelve lo que eligió el usuario en una persona concreta.
     *
     * Devuelve el id del destinatario, o 0 si lo elegido no es algo
     * que esta persona pueda elegir. Se comprueba contra la lista que
     * se le habría ofrecido y no contra lo que llegó: si no, bastaría
     * con cambiar el valor del formulario para escribirle a quien sea.
     */
    public function resolver(int $remitenteId, string $elegido): int
    {
        $permitidos = [];

        foreach ($this->para($remitenteId)['opciones'] as $o) {
            $permitidos[$o['valor']] = $o['tipo'];
        }

        if (!isset($permitidos[$elegido])) {
            return 0;
        }

        if (str_starts_with($elegido, 'u:')) {
            return Hashid::decodificar(substr($elegido, 2), 'usuario') ?? 0;
        }

        if (str_starts_with($elegido, 'dep:')) {
            return $this->delDepartamento(substr($elegido, 4));
        }

        return 0;
    }

    /**
     * A quién del departamento le toca.
     *
     * Al que menos mensajes sin leer tenga. No es un reparto perfecto
     * —quien contesta rápido recibe más—, pero es mejor que el azar y
     * mucho mejor que mandárselos todos al primero de la lista.
     *
     * Entre dos con la misma carga decide el id, y no un `RAND()`: así
     * el reparto es reproducible y se puede explicar por qué un
     * mensaje concreto acabó donde acabó.
     */
    private function delDepartamento(string $claveRol): int
    {
        return (int) BaseDatos::unValor(
            'SELECT u.id
               FROM usuarios u
               JOIN roles r ON r.id = u.rol_id
              WHERE r.clave = ? AND u.estado = "activo"
              ORDER BY (SELECT COUNT(*) FROM mensajes m
                         WHERE m.destinatario_id = u.id AND m.leido_en IS NULL) ASC,
                       u.id ASC
              LIMIT 1',
            [$claveRol]
        );
    }

    /**
     * Para el personal: el resto del personal, por nombre.
     *
     * Entre empleados no hay nada que ocultar —trabajan juntos— y sí
     * hace falta poder escribirle a alguien concreto. Los CLIENTES
     * siguen fuera de esta lista: para escribirle a un cliente se le
     * responde a su mensaje o se le abre una gestión, que es lo que
     * deja constancia.
     *
     * @return list<array{valor:string, etiqueta:string, nota:string, tipo:string}>
     */
    private function personal(int $usuarioId): array
    {
        $filas = BaseDatos::todas(
            'SELECT u.id, CONCAT(u.nombres," ",u.apellidos) AS nombre, r.nombre AS rol
               FROM usuarios u JOIN roles r ON r.id = u.rol_id
              WHERE u.estado = "activo" AND r.clave <> "cliente" AND u.id <> ?
              ORDER BY r.nivel DESC, u.nombres',
            [$usuarioId]
        );

        $out = [];

        foreach ($filas as $f) {
            $out[] = [
                'valor'    => 'u:' . Hashid::codificar((int) $f['id'], 'usuario'),
                'etiqueta' => $f['nombre'],
                'nota'     => (string) $f['rol'],
                'tipo'     => 'persona',
            ];
        }

        return $out;
    }
}
