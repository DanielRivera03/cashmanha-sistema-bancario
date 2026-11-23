<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Tarjeta\Pan;
use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Cifrado;
use CashMan\Infraestructura\Seguridad\Hashid;

/**
 * Emisión de tarjetas: el único sitio donde nace un plástico.
 *
 * Antes esto vivía dentro del controlador del formulario «Emitir
 * tarjeta», y por eso una solicitud aprobada se quedaba en el aire: el
 * expediente decía «aprobada con $4,000», pero en «Tarjetas emitidas»
 * no aparecía nada y alguien tenía que volver a capturar los mismos
 * datos a mano. Dos caminos para lo mismo son dos oportunidades de que
 * el límite aprobado y el límite emitido no coincidan.
 *
 * Ahora el nacimiento de una tarjeta pasa siempre por aquí: el
 * formulario y la aprobación llaman al mismo método, con las mismas
 * reglas y el mismo rastro.
 *
 * Lo que NO hace esta clase, a propósito:
 *
 *   · No comprueba permisos. Quien emite ya pasó por su control:
 *     `tarjeta.emitir` en el formulario, `tarjeta.revisar_gerencia` en
 *     la aprobación. Repetirlo aquí rompería la aprobación, porque
 *     quien aprueba no siempre tiene el permiso de emitir.
 *
 *   · No valida la capacidad de pago. En la solicitud eso ya se
 *     resolvió al aprobar, y volver a calcularlo daría un número
 *     distinto —los compromisos cambian de un día para otro— y el
 *     cliente recibiría una tarjeta con un límite que nadie firmó.
 */
final class EmisorTarjeta
{
    /**
     * Emite una tarjeta y devuelve sus datos.
     *
     * El número completo se devuelve UNA sola vez, aquí: después queda
     * cifrado y ni el propio sistema lo lee sin pasar por la revelación
     * con código de un solo uso.
     *
     * @param  array<string,mixed> $opciones  origen, solicitud, cuenta_id
     * @return array<string,mixed>
     */
    public function emitir(
        int $titularId,
        string $marca,
        string $tipo,
        Monto $limite,
        ?int $productoId = null,
        ?int $actorId = null,
        array $opciones = []
    ): array {
        $titular = BaseDatos::unaFila(
            'SELECT id, nombres, apellidos FROM usuarios
              WHERE id = ? AND estado = "activo"',
            [$titularId]
        );

        if ($titular === null) {
            throw new \RuntimeException('El titular no existe o su cuenta no está activa.');
        }

        /* Las marcas que emite la casa, del parámetro y no de una lista
           escrita aquí: añadir una no debería ser tocar código. Amex
           llevaba tiempo soportada en el generador de números y en la
           base, y se caía justo aquí. */
        $disponibles = array_values(array_filter(array_map(
            'trim',
            explode(',', Parametros::obtener('tarjeta.marcas_disponibles', 'visa,mastercard,amex'))
        )));

        if ($disponibles === []) {
            $disponibles = ['visa'];
        }

        $marca = in_array($marca, $disponibles, true) ? $marca : $disponibles[0];
        $tipo  = in_array($tipo, ['credito', 'debito'], true) ? $tipo : 'credito';

        /* La cuenta del débito.
           Sin ella la tarjeta nace sin fondos de donde tirar, y el
           camino de pago no tendría a qué cargarle: mejor no emitirla
           que entregar un plástico que falla en la primera caja. */
        $cuentaId = isset($opciones['cuenta_id']) ? (int) $opciones['cuenta_id'] : 0;

        if ($tipo === 'debito') {
            if ($cuentaId <= 0) {
                throw new \RuntimeException(
                    'Una tarjeta de débito necesita una cuenta de ahorro a la cual '
                    . 'quedar ligada.'
                );
            }

            $cuenta = BaseDatos::unaFila(
                'SELECT id, numero_cuenta FROM cuentas
                  WHERE id = ? AND usuario_id = ? AND es_tecnica = 0 AND estado = "activa"',
                [$cuentaId, $titularId]
            );

            if ($cuenta === null) {
                throw new \RuntimeException(
                    'La cuenta indicada no es del titular o no está activa.'
                );
            }

            $tope = max(1, Parametros::entero('tarjeta.debito_max_por_cuenta', 2));

            $vivas = (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM tarjetas
                  WHERE cuenta_id = ? AND tipo = "debito" AND estado = "activa"',
                [$cuentaId]
            );

            if ($vivas >= $tope) {
                throw new \RuntimeException(
                    'La cuenta ' . $cuenta['numero_cuenta'] . ' ya tiene ' . $vivas
                    . ' tarjeta(s) de débito activa(s), que es el máximo.'
                );
            }
        }

        // Una tarjeta de débito no lleva línea: el dinero es del cliente.
        if ($tipo !== 'credito') {
            $limite = Monto::cero();
        }

        /* Condiciones heredadas del producto.
         *
         * La tasa del diferido sale del producto solicitado, no de un
         * número fijo: una Signature no cobra lo mismo que una Clásica,
         * y si la tarjeta no guardara su propia tasa, cambiar el
         * catálogo mañana reescribiría las condiciones de las tarjetas
         * ya entregadas. */
        $tasaBp = Parametros::entero('tarjeta.tasa_revolvente_bp', 4400);

        if ($productoId !== null && $productoId > 0) {
            $p = BaseDatos::unaFila(
                'SELECT tasa_interes_bp FROM productos WHERE id = ? AND tipo = "tarjeta"',
                [$productoId]
            );

            if ($p === null) {
                $productoId = null;
            } elseif ((int) $p['tasa_interes_bp'] > 0) {
                $tasaBp = (int) $p['tasa_interes_bp'];
            }
        }

        $pan    = Pan::generar($marca);
        $cifra  = new Cifrado(Config::claveBinaria('TARJETA_CLAVE'));
        $pepper = Config::claveBinaria('TARJETA_PEPPER');


        $token = bin2hex(random_bytes(20));
        $anio  = (int) date('Y') + 4;
        $mes   = (int) date('n');

        $nombreTitular = mb_strtoupper(trim($titular['nombres'] . ' ' . $titular['apellidos']));

        // Día de corte y primera fecha de corte: sin esto la tarjeta
        // nace sin calendario y el primer estado de cuenta no sale.
        $diaCorte     = min(28, max(1, Parametros::entero('tarjeta.dia_corte', 25)));
        $diasPago     = max(1, Parametros::entero('tarjeta.dias_para_pago', 20));
        $proximoCorte = $this->primerCorte($diaCorte);

        try {
            BaseDatos::ejecutar(
                'INSERT INTO tarjetas
                   (uuid, usuario_id, cuenta_id, producto_id, token, pan_cifrado, pan_hash,
                    ultimos4, bin6, marca, tipo, titular, expira_mes, expira_anio,
                    limite_cent, dia_corte, dias_para_pago, tasa_diferido_bp,
                    proximo_corte, estado, pasarela)
                 VALUES (UUID(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, "activa", ?)',
                [
                    $titularId,
                    $cuentaId > 0 ? $cuentaId : null,
                    $productoId,
                    $token,
                    // El id de la tarjeta aún no existe, así que se ata
                    // el cifrado al token, que ya es único.
                    $cifra->cifrar($pan->numero, $token),
                    Cifrado::hashBusqueda($pan->numero, $pepper),
                    $pan->ultimos4,
                    $pan->bin6,
                    $pan->marca,
                    $tipo,
                    $nombreTitular,
                    $mes,
                    $anio,
                    $limite->centavos,
                    $diaCorte,
                    $diasPago,
                    $tasaBp,
                    $proximoCorte,
                    Config::obtener('PASARELA', 'simulada'),
                ]
            );
        } catch (\PDOException $e) {
            // pan_hash es UNIQUE: colisión improbable pero posible.
            throw new \RuntimeException('No se pudo emitir la tarjeta. Intente de nuevo.');
        }

        $nuevoId = BaseDatos::ultimoId();

        Auditoria::registrar(
            'tarjeta.emitida',
            Auditoria::EXITO,
            $actorId,
            'tarjeta',
            (string) $nuevoId,
            [
                'titular_id' => $titularId,
                'marca'      => $pan->marca,
                'ultimos4'   => $pan->ultimos4,
                'limite'     => $limite->aDecimal(),
                'origen'     => (string) ($opciones['origen'] ?? 'manual'),
                'solicitud'  => $opciones['solicitud'] ?? null,
            ]
        );

        return [
            'id'       => $nuevoId,
            'codigo'   => Hashid::codificar($nuevoId, 'tarjeta'),
            'numero'   => $pan->formateado(),
            'ultimos4' => $pan->ultimos4,
            'marca'    => $pan->marca,
            'tipo'     => $tipo,
            'titular'  => $nombreTitular,
            'vence'    => str_pad((string) $mes, 2, '0', STR_PAD_LEFT) . '/' . substr((string) $anio, -2),
            'limite'   => $limite->jsonSerialize(),
            'corte'    => $proximoCorte,
            'aviso'    => 'Guarde este número: no se volverá a mostrar.',
        ];
    }

    /**
     * Primera fecha de corte a partir de hoy.
     *
     * Si el día de corte de este mes ya pasó, el primer corte es el del
     * mes siguiente: una tarjeta emitida el 26 no puede cortar el 25.
     */
    private function primerCorte(int $dia): string
    {
        $hoy = new \DateTimeImmutable('today');

        $candidato = $hoy->setDate(
            (int) $hoy->format('Y'),
            (int) $hoy->format('n'),
            $dia
        );

        if ($candidato <= $hoy) {
            $siguiente = $hoy->modify('first day of next month');
            $candidato = $siguiente->setDate(
                (int) $siguiente->format('Y'),
                (int) $siguiente->format('n'),
                $dia
            );
        }

        return $candidato->format('Y-m-d');
    }
}
