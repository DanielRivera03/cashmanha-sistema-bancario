<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Seguridad;

use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * Limitador de tasa con ventana fija y bloqueo progresivo.
 *
 * El sistema anterior no tenía ninguno: con contraseñas DES truncadas
 * a 8 caracteres, un atacante podía probar sin límite ni demora.
 *
 * Se aplica por IP y por identificador a la vez. Solo por IP, un
 * atacante rota direcciones; solo por usuario, puede bloquear a
 * cualquiera a voluntad (denegación de servicio sobre la víctima).
 * Las dos juntas cierran ambos huecos.
 */
final class LimitadorTasa
{
    public function __construct(
        private int $maxIntentos = 5,
        private int $ventanaSeg  = 900,
        private int $bloqueoSeg  = 900
    ) {
    }

    /**
     * Registra un intento y determina si se permite.
     *
     * @return array{permitido:bool, restantes:int, reintentar_en:int}
     */
    public function intentar(string $clave, ?int $maxIntentos = null): array
    {
        $max    = $maxIntentos ?? $this->maxIntentos;
        $clave  = $this->normalizar($clave);
        $ahora  = new \DateTimeImmutable();

        return BaseDatos::enTransaccion(function () use ($clave, $max, $ahora) {
            // FOR UPDATE: sin esto, dos peticiones simultáneas leen el mismo
            // contador y ambas pasan — el clásico hueco de carrera que deja
            // colar el doble de intentos.
            $fila = BaseDatos::unaFila(
                'SELECT contador, ventana_inicio, bloqueado_hasta
                   FROM limites_tasa WHERE clave = ? FOR UPDATE',
                [$clave]
            );

            $ahoraSql = $ahora->format('Y-m-d H:i:s');

            if ($fila === null) {
                BaseDatos::ejecutar(
                    'INSERT INTO limites_tasa (clave, contador, ventana_inicio) VALUES (?, 1, ?)',
                    [$clave, $ahoraSql]
                );
                return ['permitido' => true, 'restantes' => $max - 1, 'reintentar_en' => 0];
            }

            // ¿Sigue bloqueado?
            if (!empty($fila['bloqueado_hasta'])) {
                $hasta = new \DateTimeImmutable((string) $fila['bloqueado_hasta']);
                if ($hasta > $ahora) {
                    return [
                        'permitido'     => false,
                        'restantes'     => 0,
                        'reintentar_en' => $hasta->getTimestamp() - $ahora->getTimestamp(),
                    ];
                }
                // Bloqueo cumplido: ventana nueva.
                BaseDatos::ejecutar(
                    'UPDATE limites_tasa SET contador = 1, ventana_inicio = ?, bloqueado_hasta = NULL
                      WHERE clave = ?',
                    [$ahoraSql, $clave]
                );
                return ['permitido' => true, 'restantes' => $max - 1, 'reintentar_en' => 0];
            }

            // ¿Ventana expirada?
            $inicio = new \DateTimeImmutable((string) $fila['ventana_inicio']);
            if ($ahora->getTimestamp() - $inicio->getTimestamp() > $this->ventanaSeg) {
                BaseDatos::ejecutar(
                    'UPDATE limites_tasa SET contador = 1, ventana_inicio = ? WHERE clave = ?',
                    [$ahoraSql, $clave]
                );
                return ['permitido' => true, 'restantes' => $max - 1, 'reintentar_en' => 0];
            }

            $contador = (int) $fila['contador'] + 1;

            if ($contador > $max) {
                $hasta = $ahora->modify("+{$this->bloqueoSeg} seconds");
                BaseDatos::ejecutar(
                    'UPDATE limites_tasa SET contador = ?, bloqueado_hasta = ? WHERE clave = ?',
                    [$contador, $hasta->format('Y-m-d H:i:s'), $clave]
                );
                return ['permitido' => false, 'restantes' => 0, 'reintentar_en' => $this->bloqueoSeg];
            }

            BaseDatos::ejecutar(
                'UPDATE limites_tasa SET contador = ? WHERE clave = ?',
                [$contador, $clave]
            );

            return ['permitido' => true, 'restantes' => $max - $contador, 'reintentar_en' => 0];
        });
    }

    /** Variante que lanza excepción en lugar de devolver el estado. */
    public function exigir(string $clave, ?int $maxIntentos = null): void
    {
        $r = $this->intentar($clave, $maxIntentos);
        if (!$r['permitido']) {
            throw new LimiteExcedido(
                'Demasiados intentos. Por favor, inténtelo de nuevo en ' . $r['reintentar_en'] . ' segundos.',
                $r['reintentar_en']
            );
        }
    }

    /** Limpia el contador tras una operación exitosa. */
    public function limpiar(string $clave): void
    {
        BaseDatos::ejecutar('DELETE FROM limites_tasa WHERE clave = ?', [$this->normalizar($clave)]);
    }

    /** Mantenimiento: elimina ventanas caducadas. */
    public static function purgar(): int
    {
        return BaseDatos::ejecutar(
            'DELETE FROM limites_tasa
              WHERE (bloqueado_hasta IS NULL OR bloqueado_hasta < NOW())
                AND ventana_inicio < DATE_SUB(NOW(), INTERVAL 1 DAY)'
        );
    }

    /** Hash de la clave: evita guardar correos o IPs en claro en esta tabla. */
    private function normalizar(string $clave): string
    {
        return substr(hash('sha256', $clave), 0, 64);
    }
}
