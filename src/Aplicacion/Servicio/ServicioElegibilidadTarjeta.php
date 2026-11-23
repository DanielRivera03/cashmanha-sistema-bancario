<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\CapacidadPago;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * Si un cliente puede pedir una línea de tarjeta, y qué le falta.
 *
 * La pantalla de banca preferente ofrecía la Obsidiana con un botón
 * grande y entero; el cliente lo pulsaba y recibía un aviso rojo
 * diciéndole que hacían falta $25,000 de ingreso mensual. Esa
 * información existía ANTES de que pulsara: el requisito está en el
 * producto y el ingreso en el expediente. Hacerle pulsar para
 * descubrirlo no es una validación, es una puerta pintada en la pared.
 *
 * Esta clase evalúa cada condición por separado y devuelve todas, las
 * que se cumplen y las que no. Importan las dos: saber que faltan
 * $7,000 de ingreso y que lo demás está en orden es una respuesta
 * útil; «no se pudo completar» no lo es.
 *
 * Las reglas son LAS MISMAS que aplica
 * {@see ServicioSolicitudTarjeta::solicitar()} al recibir la solicitud.
 * Esto no sustituye a aquella comprobación —la pantalla no decide
 * nada, y entre que se pinta y se envía pueden cambiar las cosas—,
 * pero sí tiene que coincidir con ella: una pantalla que da luz verde
 * a algo que el servidor va a rechazar es peor que no decir nada.
 */
final class ServicioElegibilidadTarjeta
{
    /**
     * Evalúa una línea para un cliente.
     *
     * @param  array<string,mixed> $producto Fila de `productos`, con al
     *         menos `codigo`, `nombre`, `ingreso_minimo_cent`,
     *         `linea_desde_cent` y `publico`.
     * @return array{
     *     puede: bool,
     *     bloqueo: string|null,
     *     requisitos: list<array{clave:string, etiqueta:string, cumple:bool, detalle:string}>,
     *     faltan: int
     * }
     */
    public function evaluar(int $usuarioId, array $producto): array
    {
        $req = [];

        /* El orden importa: primero lo que depende de la situación del
           cliente y puede arreglar hoy, y al final lo que depende de su
           historial, que no se arregla esta tarde. Leer una lista que
           empieza por «su clasificación no alcanza» desanima aunque lo
           que de verdad falte sea ponerse al día en una cuota. */

        // --- 1. Sin otra solicitud en trámite ---------------------------
        $pendiente = BaseDatos::unValor(
            "SELECT folio FROM solicitudes_tarjeta
              WHERE usuario_id = ? AND etapa NOT IN ('aprobada','rechazada','emitida')
              LIMIT 1",
            [$usuarioId]
        );

        $req[] = [
            'clave'    => 'tramite',
            'etiqueta' => 'Sin otra solicitud en trámite',
            'cumple'   => $pendiente === null,
            'detalle'  => $pendiente === null
                ? 'No tiene solicitudes pendientes de resolver.'
                : 'Tiene la solicitud ' . $pendiente . ' en trámite. Le avisamos en '
                  . 'cuanto se resuelva y entonces podrá pedir otra.',
        ];

        // --- 2. Al día en lo que ya tiene -------------------------------
        $vencidas = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM cuotas q JOIN creditos c ON c.id = q.credito_id
              WHERE c.usuario_id = ? AND q.estado IN ("vencida","parcial")
                AND q.fecha_vencimiento < CURDATE()',
            [$usuarioId]
        );

        $req[] = [
            'clave'    => 'mora',
            'etiqueta' => 'Al día en sus compromisos',
            'cumple'   => $vencidas === 0,
            'detalle'  => $vencidas === 0
                ? 'No tiene cuotas vencidas.'
                : 'Tiene ' . $vencidas . ' cuota(s) en mora. Al ponerse al día, '
                  . 'esta condición se cumple de inmediato.',
        ];

        // --- 3. La línea está abierta a su segmento ---------------------
        $exclusiva = (int) ($producto['publico'] ?? 1) !== 1;

        if ($exclusiva) {
            $suyas = (array) ((new ServicioSegmento())
                ->de($usuarioId)['beneficios']['lineas_exclusivas'] ?? []);

            $incluida = in_array((string) $producto['codigo'], $suyas, true);

            $req[] = [
                'clave'    => 'segmento',
                'etiqueta' => 'Línea disponible para su segmento',
                'cumple'   => $incluida,
                'detalle'  => $incluida
                    ? 'Su segmento incluye esta línea.'
                    : 'Esta línea se ofrece por invitación de la institución.',
            ];
        }

        // --- 4. El ingreso que pide el producto -------------------------
        $ingreso = $this->ingreso($usuarioId);
        $minimo  = (int) ($producto['ingreso_minimo_cent'] ?? 0);

        $faltan = $minimo > 0 ? max(0, $minimo - $ingreso->centavos) : 0;

        if ($minimo > 0) {
            $req[] = [
                'clave'    => 'ingreso',
                'etiqueta' => 'Ingreso mensual desde ' . $this->pesos($minimo),
                'cumple'   => $ingreso->centavos >= $minimo,
                'detalle'  => match (true) {
                    $ingreso->centavos <= 0 => 'No tenemos su ingreso registrado. '
                        . 'Indíquelo en su perfil y volvemos a evaluarlo.',
                    $ingreso->centavos >= $minimo => 'Su ingreso registrado es de '
                        . $ingreso->formateado() . '.',
                    default => 'Su ingreso registrado es de ' . $ingreso->formateado()
                        . '. Faltan ' . $this->pesos($faltan) . ' para esta línea.',
                },
            ];
        }

        // --- 5. Margen de pago ------------------------------------------
        $capacidad = CapacidadPago::limiteTarjeta(
            $ingreso,
            $this->compromisos($usuarioId),
            null,
            (new ServicioSegmento())->vecesIngreso($usuarioId)
        );

        $req[] = [
            'clave'    => 'capacidad',
            'etiqueta' => 'Margen suficiente de pago',
            'cumple'   => $capacidad->esPositivo(),
            'detalle'  => $capacidad->esPositivo()
                ? 'Sus compromisos actuales admiten una línea de hasta '
                  . $capacidad->formateado() . '.'
                : 'Sus compromisos actuales no dejan margen para una línea nueva.',
        ];

        /* --- 6. La clasificación -----------------------------------------

           Se evalúa sobre el pago mínimo de la línea de entrada del
           producto, que es el compromiso mensual real que esta tarjeta
           le añadiría. Evaluarlo sobre el límite entero daría un «no»
           a casi todo el mundo: nadie paga su línea completa cada mes.

           Solo se consulta si lo demás está en orden. El motor de
           riesgo hace varias consultas y deja constancia de lo que
           evalúa; no tiene sentido gastarlas ni ensuciar el historial
           para decirle a alguien que además le falta ingreso. */
        $hastaAqui = array_reduce(
            $req,
            static fn(bool $t, array $r): bool => $t && $r['cumple'],
            true
        );

        if ($hastaAqui) {
            $pct   = max(1, Parametros::entero('tarjeta.pago_minimo_pct', 500));
            $linea = (int) ($producto['linea_desde_cent'] ?? 0);

            $decision = $this->riesgo(
                $usuarioId,
                (int) round($linea * $pct / 10000)
            );

            $req[] = [
                'clave'    => 'riesgo',
                'etiqueta' => 'Clasificación de crédito',
                'cumple'   => (bool) $decision['aprueba'],
                'detalle'  => (bool) $decision['aprueba']
                    ? 'Su clasificación es ' . ($decision['banda'] ?? '—')
                      . ' y admite esta línea.'
                    : (string) ($decision['narrativa'] ?? 'Su clasificación actual no '
                      . 'admite una línea nueva en este momento.'),
            ];
        }

        $puede = array_reduce(
            $req,
            static fn(bool $t, array $r): bool => $t && $r['cumple'],
            true
        );

        // El primero que falla es el que se enseña cuando solo cabe uno.
        $bloqueo = null;

        foreach ($req as $r) {
            if (!$r['cumple']) { $bloqueo = $r['detalle']; break; }
        }

        return [
            'puede'      => $puede,
            'bloqueo'    => $bloqueo,
            'requisitos' => $req,
            'faltan'     => $faltan,
        ];
    }

    /**
     * Evalúa varias líneas de una vez.
     *
     * @param  list<array<string,mixed>> $productos
     * @return list<array<string,mixed>> los mismos, con la clave
     *         `elegibilidad` añadida a cada uno.
     */
    public function evaluarVarias(int $usuarioId, array $productos): array
    {
        foreach ($productos as &$p) {
            $p['elegibilidad'] = $this->evaluar($usuarioId, $p);
        }

        return $productos;
    }

    /**
     * La decisión de riesgo, recordada dentro de la misma petición.
     *
     * El catálogo evalúa seis o siete líneas de una sentada y el motor
     * hace varias consultas por evaluación. Sin esto, abrir el catálogo
     * dispararía cuarenta consultas para contestar casi siempre lo
     * mismo. Solo vive lo que dura la petición: nada se guarda entre
     * una carga de pantalla y la siguiente, porque la situación del
     * cliente puede cambiar en medio.
     *
     * @var array<string,array<string,mixed>>
     */
    private array $memoRiesgo = [];

    /** @return array<string,mixed> */
    private function riesgo(int $usuarioId, int $cuotaCent): array
    {
        $llave = $usuarioId . ':' . $cuotaCent;

        return $this->memoRiesgo[$llave] ??= (new ServicioRiesgo())
            ->evaluar($usuarioId, Monto::deCentavos($cuotaCent));
    }

    /** El ingreso que consta en el expediente. */
    private function ingreso(int $usuarioId): Monto
    {
        return Monto::deCentavos((int) BaseDatos::unValor(
            'SELECT COALESCE(ingreso_mensual_cent, 0) FROM detalle_usuarios WHERE usuario_id = ?',
            [$usuarioId]
        ));
    }

    /** Lo que ya paga cada mes: cuotas de crédito y mínimos de tarjeta. */
    private function compromisos(int $usuarioId): Monto
    {
        $creditos = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(cuota_cent),0) FROM creditos
              WHERE usuario_id = ? AND estado = "vigente"',
            [$usuarioId]
        );

        $pct = max(1, Parametros::entero('tarjeta.pago_minimo_pct', 500));

        $saldoTarjetas = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(saldo_usado_cent),0) FROM tarjetas
              WHERE usuario_id = ? AND tipo = "credito" AND estado = "activa"',
            [$usuarioId]
        );

        return Monto::deCentavos($creditos + (int) round($saldoTarjetas * $pct / 10000));
    }

    private function pesos(int $centavos): string
    {
        return '$' . number_format($centavos / 100, 2);
    }
}
