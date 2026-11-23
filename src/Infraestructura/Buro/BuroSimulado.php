<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Buro;

/**
 * Buró simulado, para desarrollo y pruebas.
 *
 * Ninguno de los burós que operan en El Salvador —Equifax, TransUnion,
 * INFORED— publica un entorno de pruebas abierto: hay que contratar, y
 * la ley exige además que el buró esté domiciliado en el país. Mientras
 * no exista ese contrato, este proveedor permite ejercitar el flujo
 * completo sin inventar una integración que no se puede probar.
 *
 * Lo importante de cómo está hecho: el resultado se deriva del propio
 * documento de identidad mediante un hash. Eso le da dos propiedades que
 * un `rand()` no tendría:
 *
 *   · El mismo cliente devuelve siempre lo mismo. Se puede escribir una
 *     prueba que espere un valor concreto, y una demostración no cambia
 *     de resultado a media reunión.
 *
 *   · Clientes distintos devuelven perfiles distintos y repartidos, de
 *     modo que se ven todos los caminos: aprobado holgado, al límite,
 *     con mora y sin historial.
 *
 * Deja claro que es simulado en todo lo que devuelve. Nadie debería
 * poder confundir esto con un informe real.
 */
final class BuroSimulado implements ProveedorBuro
{
    /** Documentos reservados para forzar un escenario concreto en pruebas. */
    private const GUION = [
        // documento        => [score, moras12m, peorMora, sinHistorial]
        '00000000-0' => [820,  0,   0, false],  // excelente
        '11111111-1' => [690,  1,  15, false],  // bueno
        '22222222-2' => [560,  3,  45, false],  // al límite
        '33333333-3' => [430,  6, 120, false],  // malo
        '44444444-4' => [  0,  0,   0, true],   // sin historial
    ];

    public function nombre(): string
    {
        return 'simulado';
    }

    public function consultar(string $documento, string $nombre): array
    {
        $doc = trim($documento);

        if ($doc === '') {
            throw new BuroError('Falta el documento de identidad para consultar el buró.');
        }

        [$score, $moras, $peorMora, $sinHistorial] = self::GUION[$doc] ?? $this->derivar($doc);

        if ($sinHistorial) {
            return [
                'score'              => 0,
                'categoria'          => 'sin historial',
                'deuda_total_cent'   => 0,
                'cuota_mensual_cent' => 0,
                'creditos_activos'   => 0,
                'moras_12m'          => 0,
                'peor_mora_dias'     => 0,
                'sin_historial'      => true,
                'crudo'              => [
                    'proveedor' => 'simulado',
                    'aviso'     => 'DATO SIMULADO: no proviene de un buró real.',
                    'documento' => $this->enmascarar($doc),
                ],
            ];
        }

        // La deuda y los compromisos se derivan del mismo hash, para que
        // acompañen al puntaje: a mejor historial, deuda más manejable.
        $semilla = $this->semilla($doc);

        $creditos = 1 + ($semilla % 4);
        $deuda    = (($semilla >> 3) % 2000000) + 50000;      // $500 – $20,500
        $cuota    = (int) round($deuda / (12 + ($semilla % 48)));

        return [
            'score'              => $score,
            'categoria'          => $this->categoria($score),
            'deuda_total_cent'   => $deuda,
            'cuota_mensual_cent' => $cuota,
            'creditos_activos'   => $creditos,
            'moras_12m'          => $moras,
            'peor_mora_dias'     => $peorMora,
            'sin_historial'      => false,
            'crudo'              => [
                'proveedor'  => 'simulado',
                'aviso'      => 'DATO SIMULADO: no proviene de un buró real.',
                'documento'  => $this->enmascarar($doc),
                'nombre'     => $nombre,
                'generado'   => date('c'),
            ],
        ];
    }

    /**
     * Perfil derivado del documento.
     *
     * @return array{0:int,1:int,2:int,3:bool}
     */
    private function derivar(string $documento): array
    {
        $s = $this->semilla($documento);

        // Un 8 % sin historial: existe de verdad y el flujo tiene que
        // saber qué hacer con un cliente del que no hay nada.
        if ($s % 100 < 8) {
            return [0, 0, 0, true];
        }

        // Puntaje entre 400 y 850, cargado hacia la mitad alta, que es
        // como se reparte una cartera real.
        $score = 400 + (($s >> 7) % 451);
        if ($score < 600 && ($s % 3) !== 0) {
            $score += 120;
        }
        $score = min(850, $score);

        // Las moras aparecen cuando el puntaje es bajo, no al azar: si no
        // se correlacionaran, saldrían clientes de 820 con seis atrasos.
        $moras    = $score >= 720 ? 0 : ($score >= 620 ? ($s % 2) : 2 + ($s % 5));
        $peorMora = $moras === 0 ? 0 : 15 + (($s >> 11) % 150);

        return [$score, $moras, $peorMora, false];
    }

    private function semilla(string $documento): int
    {
        // Hash estable: el mismo documento da siempre el mismo perfil.
        return (int) hexdec(substr(hash('sha256', 'buro-sim|' . $documento), 0, 12));
    }

    private function categoria(int $score): string
    {
        return match (true) {
            $score >= 750 => 'A',
            $score >= 680 => 'B',
            $score >= 600 => 'C',
            $score >= 520 => 'D',
            default       => 'E',
        };
    }

    /** No se guarda el documento completo en la respuesta cruda. */
    private function enmascarar(string $doc): string
    {
        $n = mb_strlen($doc);

        return $n <= 4 ? str_repeat('*', $n) : str_repeat('*', $n - 4) . mb_substr($doc, -4);
    }
}
