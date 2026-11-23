<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Dominio\Comun\Monto;

/**
 * La bienvenida a una tarjeta recién emitida.
 *
 * Hasta ahora, cuando a un cliente se le emitía una tarjeta se
 * enteraba porque aparecía una fila más en su listado. Es información
 * correcta y es, también, la misma forma en que el sistema le comunica
 * que se guardó un cambio de teléfono.
 *
 * Esta clase arma lo que hace falta para decírselo de otra manera:
 * qué tarjeta es, cómo se ve, qué límite trae y qué incluye. Los
 * beneficios salen del producto, no de una lista escrita en la vista,
 * porque si mañana se cambia lo que ofrece la Platinum hay que
 * cambiarlo en un sitio y no en dos.
 *
 * Se da UNA vez por tarjeta. La marca vive en la propia tarjeta
 * (`bienvenida_vista_en`), así que alguien con tres tarjetas recibe
 * tres bienvenidas y no una: cada una es un hecho distinto.
 *
 * @see \CashMan\Aplicacion\Servicio\ServicioSegmento para los
 *      beneficios que dependen del cliente y no del producto.
 */
final class ServicioBienvenidaTarjeta
{
    /**
     * Lo que incluye una tarjeta de débito.
     *
     * No sale de `productos` como las de crédito: una tarjeta de débito
     * nace con la cuenta de ahorro y no pasa por el catálogo, así que no
     * hay una fila de la que leerlo.
     *
     * @var list<string>
     */
    private const BENEFICIOS_DEBITO = [
        'Compras en comercios y en línea con cargo directo a su cuenta',
        'Retiros en cajeros de la red sin comisión',
        'Pago sin contacto',
        'Alertas en cada movimiento',
        'Bloqueo inmediato desde la banca en línea si la pierde',
    ];

    /**
     * Lo que trae cualquier tarjeta de crédito de la casa.
     *
     * Solo se usa cuando la tarjeta no tiene línea de catálogo detrás.
     * Lo normal es que la tenga y que los beneficios salgan de ella:
     * si mañana cambia lo que ofrece la Platinum, se cambia en
     * `productos` y no aquí.
     *
     * @var list<string>
     */
    private const BENEFICIOS_CREDITO = [
        'Compras a cuotas en comercios afiliados',
        'Alertas en cada movimiento',
        'Bloqueo inmediato desde la banca en línea si la pierde',
        'Estado de cuenta mensual con el detalle de sus consumos',
    ];

    /**
     * La bienvenida pendiente del cliente, si la hay.
     *
     * Devuelve `null` cuando no hay nada que celebrar, que es el caso
     * de casi todas las cargas de pantalla; por eso la consulta va
     * primero y lo demás solo se arma si hay algo.
     *
     * @param  bool $marcar Si se da por entregada. En falso sirve para
     *                      mirar sin consumirla.
     * @return array<string,mixed>|null
     */
    public function pendiente(int $usuarioId, bool $marcar = true): ?array
    {
        if ($usuarioId <= 0) {
            return null;
        }

        /* Una tarjeta cancelada o vencida no se celebra aunque nunca se
           hubiera saludado: habría que explicarle al cliente que le
           damos la enhorabuena por algo que ya no tiene.

           El producto se toma de `t.producto_id`, que es donde lo
           guarda el emisor, y no de la solicitud. Por la solicitud
           también se llega, pero solo cuando la hubo: una tarjeta de
           débito nace con la cuenta y las sembradas al poblar la base
           tampoco la tienen, y por ese camino se quedaban sin línea y
           sin beneficios. El JOIN sigue siendo LEFT porque el débito
           no tiene producto de catálogo. */
        $t = BaseDatos::unaFila(
            "SELECT t.id, t.marca, t.tipo, t.ultimos4, t.titular,
                    t.expira_mes, t.expira_anio, t.estado,
                    t.limite_cent, t.dia_corte, t.dias_para_pago,
                    p.codigo     AS producto_codigo,
                    p.nivel      AS producto_nivel,
                    p.nombre     AS producto_nombre,
                    p.beneficios AS producto_beneficios,
                    c.numero_cuenta AS cuenta_numero
               FROM tarjetas t
               LEFT JOIN productos p ON p.id = t.producto_id
               LEFT JOIN cuentas   c ON c.id = t.cuenta_id
              WHERE t.usuario_id = ?
                AND t.bienvenida_vista_en IS NULL
                AND t.estado IN ('activa', 'pendiente_activacion')
              ORDER BY t.emitida_en ASC, t.id ASC
              LIMIT 1",
            [$usuarioId]
        );

        if ($t === null) {
            return null;
        }

        $id = (int) $t['id'];

        if ($marcar) {
            /* Se marca ANTES de devolverla. Si algo falla al pintarla,
               el cliente se pierde la celebración; si se marcara
               después y fallara la marca, se la encontraría en cada
               carga de pantalla hasta hartarse. De los dos fallos
               posibles, este es el leve. */
            BaseDatos::ejecutar(
                "UPDATE tarjetas SET bienvenida_vista_en = NOW() WHERE id = ?",
                [$id]
            );
        }

        return $this->armar($t);
    }

    /**
     * @param  array<string,mixed> $t
     * @return array<string,mixed>
     */
    private function armar(array $t): array
    {
        $debito = (string) $t['tipo'] === 'debito';

        $nombre = (string) ($t['producto_nombre'] ?? '');

        if ($nombre === '') {
            $nombre = $debito ? 'Tarjeta de débito' : 'Tarjeta de crédito';
        }

        // Los datos del plástico, con el mismo formato que usan las
        // vistas: así lo dibuja la misma función y no una copia.
        $plastico = [
            'codigo'         => Hashid::codificar((int) $t['id'], 'tarjeta'),
            'marca'          => (string) $t['marca'],
            'tipo'           => (string) $t['tipo'],
            'ultimos4'       => (string) $t['ultimos4'],
            'titular'        => (string) $t['titular'],
            'expira_mes'     => (int) $t['expira_mes'],
            'expira_anio'    => (int) $t['expira_anio'],
            // En la bienvenida la tarjeta se enseña entera y encendida
            // aunque esté por activar: es el retrato del producto, no
            // su estado operativo.
            'estado'         => 'activa',
            'producto_codigo' => (string) ($t['producto_codigo'] ?? ''),
            'producto_nivel'  => (int) ($t['producto_nivel'] ?? 1),
        ];

        $datos = [
            ['et' => 'Marca',      'val' => $this->marcaLegible((string) $t['marca'])],
            ['et' => 'Termina en', 'val' => '•••• ' . (string) $t['ultimos4']],
        ];

        if ($debito) {
            if (($t['cuenta_numero'] ?? null) !== null) {
                $datos[] = ['et' => 'Cuenta', 'val' => (string) $t['cuenta_numero']];
            }

            $beneficios = self::BENEFICIOS_DEBITO;

            $pie = 'Ya puede usarla. Si todavía no tiene su PIN, puede asignarlo '
                 . 'desde el detalle de la tarjeta.';
        } else {
            $limite = Monto::deCentavos((int) $t['limite_cent']);

            // El límite va primero: es el dato por el que se pregunta.
            array_unshift($datos, ['et' => 'Límite', 'val' => $limite->formateado()]);

            $datos[] = [
                'et'  => 'Corte',
                'val' => 'Día ' . (int) $t['dia_corte'],
            ];

            $beneficios = $this->beneficiosDe((string) ($t['producto_beneficios'] ?? ''));

            /* Si la tarjeta no tiene línea de catálogo detrás —las
               sembradas al poblar la base no la tienen—, la
               felicitación no puede salir sin contenido. Esto es lo
               que trae cualquier tarjeta de crédito de la casa. */
            if ($beneficios === []) {
                $beneficios = self::BENEFICIOS_CREDITO;
            }

            $pie = 'Su tarjeta física llega a la sucursal donde abrió su cuenta en los '
                 . 'próximos días hábiles. Mientras tanto ya puede usarla para compras '
                 . 'en línea desde el detalle de la tarjeta.';
        }

        return [
            'titulo'     => 'Su ' . $nombre . ' ya es suya',
            'subtitulo'  => $debito
                ? 'Quedó lista y asociada a su cuenta.'
                : 'Quedó emitida a su nombre y activa desde hoy.',
            'plastico'   => $plastico,
            'datos'      => $datos,
            'beneficios' => $beneficios,
            'pie'        => $pie,
            'boton'      => 'Ver mi tarjeta',
            'url'        => 'tarjetas/' . $plastico['codigo'],
        ];
    }

    /**
     * Los beneficios del producto, uno por línea.
     *
     * @return list<string>
     */
    private function beneficiosDe(string $texto): array
    {
        if (trim($texto) === '') {
            return [];
        }

        /* En la base están separados por saltos de línea, pero algunas
           filas los guardaron con la barra ene escrita como dos
           caracteres en vez de como un salto. Se admiten las dos. */
        $texto = str_replace('\n', "\n", $texto);

        $lineas = array_values(array_filter(
            array_map('trim', explode("\n", $texto)),
            static fn(string $l): bool => $l !== ''
        ));

        // Más de seis no se leen: se celebra, no se recita el contrato.
        return array_slice($lineas, 0, 6);
    }

    private function marcaLegible(string $marca): string
    {
        return match ($marca) {
            'visa'       => 'Visa',
            'mastercard' => 'Mastercard',
            'amex'       => 'American Express',
            'discover'   => 'Discover',
            default      => 'Tarjeta',
        };
    }
}
