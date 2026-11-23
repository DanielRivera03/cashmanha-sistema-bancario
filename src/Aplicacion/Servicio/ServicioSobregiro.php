<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * La comisión por sobregiro, y a quién se le perdona.
 *
 * `Segmento::BENEFICIOS` promete «sobregiros sin comisión»: uno al año
 * al cliente preferente y tres al patrimonial. La pantalla de banca
 * preferente lo enumera con su explicación —«si una compra rebasa su
 * línea, no se le cobra la comisión por sobregiro las primeras veces
 * del año»— y no lo leía nadie: los dos sitios que cobran la comisión
 * tomaban el parámetro general y cobraban a todo el mundo por igual.
 *
 * Aquí se decide, y aquí queda constancia.
 */
final class ServicioSobregiro
{
    /**
     * La categoría con la que se marcan estos movimientos.
     *
     * Van como `comision` y no como un tipo propio a propósito: el
     * corte mensual suma por TIPO, y un tipo nuevo habría que añadirlo
     * a mano a cada consulta del corte, del estado de cuenta y de los
     * informes. Olvidar una sola dejaría movimientos fuera de un
     * estado de cuenta, que es exactamente lo que no puede pasar.
     *
     * La categoría distingue estos de las demás comisiones sin tocar
     * nada de lo que ya cuadra.
     */
    private const CATEGORIA = 'sobregiro';

    public function __construct(private ?ServicioSegmento $segmento = null)
    {
        $this->segmento ??= new ServicioSegmento();
    }

    /**
     * Cuánto se cobra por este sobregiro.
     *
     * El cargo es un PORCENTAJE de lo que se excedió de la línea, con
     * un piso: así escala con el tamaño del sobregiro, como la banca
     * real, en vez de cobrar lo mismo por rebasar un dólar que por
     * rebasar mil. El `$excedenteCent` es cuánto se pasó del cupo.
     *
     * @return array{comision_cent:int, perdonada:bool, usadas:int, derecho:int}
     */
    public function comision(int $usuarioId, int $excedenteCent = 0): array
    {
        $base    = $this->cargoBase($excedenteCent);
        $derecho = max(0, (int) $this->segmento->beneficio($usuarioId, 'sobregiros_sin_cargo'));

        if ($derecho === 0) {
            return ['comision_cent' => $base, 'perdonada' => false, 'usadas' => 0, 'derecho' => 0];
        }

        $usadas    = $this->sobregirosDelAnio($usuarioId);
        $perdonada = $usadas < $derecho;

        return [
            'comision_cent' => $perdonada ? 0 : $base,
            'perdonada'     => $perdonada,
            'usadas'        => $usadas,
            'derecho'       => $derecho,
        ];
    }

    /**
     * El cargo por un sobregiro, antes de la cortesía.
     *
     * Un porcentaje de lo excedido (`tarjeta.sobregiro_comision_pct`,
     * en puntos básicos) con un piso (`tarjeta.sobregiro_comision_cent`)
     * para que un excedente pequeño no salga casi gratis. Si no se
     * conoce el excedente, se cobra el piso.
     */
    private function cargoBase(int $excedenteCent): int
    {
        $piso = max(0, Parametros::entero('tarjeta.sobregiro_comision_cent', 500));
        $pct  = max(0, Parametros::entero('tarjeta.sobregiro_comision_pct', 500));

        if ($excedenteCent <= 0) {
            return $piso;
        }

        return max($piso, (int) round($excedenteCent * $pct / 10000));
    }

    /**
     * Cuántos sobregiros lleva este año.
     *
     * Se cuentan los sobregiros, cobrados Y perdonados. Contar solo
     * las comisiones cobradas dejaría la cortesía sin consumir: al
     * perdonar la primera no se cobraría nada, el contador seguiría en
     * cero, y la segunda también saldría gratis, y la tercera, y
     * todas.
     *
     * El año es el natural, que es como se enuncia el beneficio («al
     * año»), y no doce meses desde el último: eso haría que la fecha
     * de renovación dependiera de cuándo se sobregiró, y no hay manera
     * de explicarle eso a un cliente.
     */
    private function sobregirosDelAnio(int $usuarioId): int
    {
        return (int) BaseDatos::unValor(
            'SELECT COUNT(*)
               FROM tarjeta_movimientos m
               JOIN tarjetas t ON t.id = m.tarjeta_id
              WHERE t.usuario_id = ?
                AND m.categoria = ?
                AND m.estado <> "reversada"
                AND YEAR(m.creado_en) = YEAR(CURDATE())',
            [$usuarioId, self::CATEGORIA]
        );
    }

    /**
     * Deja constancia, se haya cobrado o no.
     *
     * Sin esta fila una cortesía concedida no deja rastro y el
     * beneficio sería infinito. Y para el cliente es la diferencia
     * entre leer en su estado de cuenta «sobregiro — sin comisión por
     * su categoría (1 de 3 del año)» y no ver nada.
     *
     * Cuando se perdona, el importe es CERO: la fila existe para
     * contar y para explicar, no para cobrar. El corte la suma como
     * cualquier comisión y suma cero, así que no mueve nada.
     */
    public function registrar(
        int $tarjetaId,
        array $decision,
        string $referencia
    ): void {
        $nota = $decision['perdonada']
            ? sprintf(
                'Sobregiro de cupo — sin comisión por su categoría (%d de %d del año)',
                $decision['usadas'] + 1,
                $decision['derecho']
            )
            : 'Comisión por sobregiro de cupo';

        BaseDatos::ejecutar(
            'INSERT INTO tarjeta_movimientos
               (tarjeta_id, tipo, importe_cent, comercio, categoria, referencia, estado)
             VALUES (?, "comision", ?, ?, ?, ?, "liquidada")',
            [
                $tarjetaId,
                max(0, (int) $decision['comision_cent']),
                mb_substr($nota, 0, 120),
                self::CATEGORIA,
                mb_substr($referencia, 0, 64),
            ]
        );
    }
}
