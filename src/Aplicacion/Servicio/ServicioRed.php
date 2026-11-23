<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Tarjeta\Pan;
use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Cifrado;

/**
 * La red: lo que pasa cuando alguien teclea el número de una tarjeta.
 *
 * Hasta aquí, una tarjeta emitida por CashManHA solo servía eligiéndola
 * de una lista dentro de la banca del propio titular. El número que se
 * le entregaba al cliente —con su dígito de Luhn, su marca y su CVV— no
 * servía para nada: no había una sola consulta en todo el sistema que
 * buscara una tarjeta por su número, aunque `pan_hash` estuviera ahí,
 * con índice único, esperando exactamente eso.
 *
 * Esto es el lado adquirente. Recibe lo que recibe una caja —número,
 * vencimiento, CVV e importe—, decide si la operación pasa y deja el
 * cargo hecho. El cobro en sí no se repite aquí: se delega en
 * `ServicioTarjeta::cobrar()`, que es donde viven las reglas de cupo,
 * sobregiro y fondos, para que la tienda y la banca autoricen con el
 * mismo criterio y los dos movimientos entren igual en el corte.
 *
 * CÓDIGOS DE RESPUESTA
 * --------------------
 * Se devuelven los de ISO 8583, que son los que usa una red de verdad
 * y los que el cliente ha visto alguna vez en un comprobante:
 *
 *   00  aprobada
 *   05  no honrar (tarjeta bloqueada o cancelada)
 *   14  número inválido o inexistente
 *   51  fondos o cupo insuficientes
 *   54  tarjeta vencida
 *   62  tarjeta restringida (congelada, o canal no habilitado)
 *   82  CVV incorrecto
 *
 * LO QUE NO SE LE DICE A LA CAJA
 * ------------------------------
 * El comercio se entera de si pasó o no, y poco más. Un mensaje que
 * distinga «esa tarjeta no existe» de «ese CVV no es» convierte el
 * formulario en un oráculo: con él se comprueban números robados uno a
 * uno hasta dar con los que viven. Por eso el detalle solo viaja a la
 * auditoría y al titular; al comercio le llega un motivo escueto.
 *
 * ENTORNO DE PRUEBAS
 * ------------------
 * Estas tarjetas valen en las tiendas de este sistema. En la pasarela
 * externa no, y no es algo que se pueda arreglar desde aquí: Stripe en
 * modo de pruebas solo acepta los números que él publica, y rechaza
 * cualquier otro antes de que llegue a ningún servidor nuestro.
 */
final class ServicioRed
{
    /** El intento se guarda aunque sea rechazado: un rechazo también es un dato. */
    private const CANALES = ['tienda', 'internet', 'presencial', 'recurrente'];

    public function __construct(
        private readonly ServicioTarjeta $tarjetas = new ServicioTarjeta()
    ) {
    }

    /**
     * Autoriza y cobra una compra con una tarjeta tecleada.
     *
     * @param string $pan      El número, con o sin espacios o guiones.
     * @param string $cvv      Los 3 dígitos del dorso (4 en American Express).
     * @param int    $mes      Mes de vencimiento, 1-12.
     * @param int    $anio     Año de vencimiento, de 4 cifras.
     * @param Monto  $monto    El importe de la compra.
     * @param string $comercio Lo que el cliente verá en su estado de cuenta.
     *
     * @return array{aprobada:bool, codigo:string, motivo:string,
     *               referencia:?string, ultimos4:?string, marca:?string}
     */
    public function autorizar(
        string $pan,
        string $cvv,
        int $mes,
        int $anio,
        Monto $monto,
        string $comercio,
        string $categoria = 'varios',
        string $canal = 'tienda'
    ): array {
        if (!in_array($canal, self::CANALES, true)) {
            $canal = 'tienda';
        }

        $t = null;

        try {
            $t = $this->identificar($pan, $cvv, $mes, $anio, $monto, $comercio, $canal);
            $r = $this->tarjetas->cobrar((int) $t['id'], $monto, $comercio, $categoria);
        } catch (RedRechazada $e) {
            return $this->comoRespuesta($e);
        } catch (FondosInsuficientes $e) {
            return $this->comoRespuesta(
                $this->rechazo('51', $e->getMessage(), $t, $comercio, $monto, $canal)
            );
        } catch (\RuntimeException $e) {
            /* Cupo insuficiente llega como RuntimeException desde el
               camino de crédito. Se distingue por el texto porque es lo
               que hay; lo demás se responde como «no honrar», que es lo
               que dice una red cuando no quiere explicarse. */
            $esCupo = str_contains($e->getMessage(), 'Cupo insuficiente');

            return $this->comoRespuesta($this->rechazo(
                $esCupo ? '51' : '05',
                $esCupo ? $e->getMessage() : 'La tarjeta no admite operaciones.',
                $t, $comercio, $monto, $canal
            ));
        }

        Auditoria::registrar(
            'red.autorizada', Auditoria::EXITO, null,
            'tarjeta', (string) $t['id'],
            [
                'codigo'     => '00',
                'comercio'   => $comercio,
                'canal'      => $canal,
                'importe'    => $monto->aDecimal(),
                'referencia' => $r['referencia'] ?? null,
            ]
        );
        return [
            'aprobada'   => true,
            'codigo'     => '00',
            'motivo'     => 'Aprobada',
            'referencia' => $r['referencia'] ?? null,
            'ultimos4'   => (string) $t['ultimos4'],
            'marca'      => (string) $t['marca'],
        ];
    }

    /**
     * Comprueba que los datos tecleados son de una tarjeta de este banco
     * y que puede operar. No cobra nada.
     *
     * Lo usa la tienda, que necesita saber a qué tarjeta cargar la
     * compra antes de armar el pedido, y lo usa `autorizar()`, que
     * después cobra. Si no pasa, lanza `RedRechazada` con su código.
     *
     * @return array<string,mixed> La fila de la tarjeta.
     */
    public function identificar(
        string $pan,
        string $cvv,
        int $mes,
        int $anio,
        Monto $monto,
        string $comercio,
        string $canal = 'tienda'
    ): array {
        // Solo dígitos: la caja manda lo que el cliente teclea, con
        // espacios, guiones o lo que se le haya pegado al copiar.
        $numero = preg_replace('/\D+/', '', $pan) ?? '';
        $cvv    = preg_replace('/\D+/', '', $cvv) ?? '';

        /* Luhn primero, y sin tocar la base de datos.
         *
         * Un número mal tecleado no tiene por qué costar una consulta,
         * y así el error más común —un dígito cambiado— se responde al
         * instante y no parece un rechazo del banco. */
        if (strlen($numero) < 13 || strlen($numero) > 19 || !Pan::luhnValido($numero)) {
            throw $this->rechazo('14', 'Número de tarjeta inválido.', null, $comercio, $monto, $canal);
        }

        $t = BaseDatos::unaFila(
            'SELECT * FROM tarjetas WHERE pan_hash = ?',
            [Cifrado::hashBusqueda($numero, Config::claveBinaria('TARJETA_PEPPER'))]
        );

        /* Una tarjeta que no es nuestra se responde igual que un número
           mal formado, y con el mismo texto: si no, este formulario
           dice qué números existen en el banco. */
        if ($t === null) {
            throw $this->rechazo('14', 'Número de tarjeta inválido.', null, $comercio, $monto, $canal);
        }

                // --- Vencimiento --------------------------------------------
        if ($mes < 1 || $mes > 12 || $anio < 2000 || $anio > 2100) {
            throw $this->rechazo('54', 'Tarjeta vencida o fecha inválida.', $t, $comercio, $monto, $canal);
        }

        if ((int) $t['expira_mes'] !== $mes || (int) $t['expira_anio'] !== $anio) {
            throw $this->rechazo('54', 'Tarjeta vencida o fecha inválida.', $t, $comercio, $monto, $canal);
        }

        /* Vencida de verdad: una tarjeta vale hasta el último día de su
           mes de vencimiento, no hasta el primero. */
        $ultimoDia = new \DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes));
        $ultimoDia = $ultimoDia->modify('last day of this month')->setTime(23, 59, 59);

        if ($ultimoDia < new \DateTimeImmutable()) {
            throw $this->rechazo('54', 'Tarjeta vencida o fecha inválida.', $t, $comercio, $monto, $canal);
        }

        // --- CVV ------------------------------------------------------
        $esperado = Pan::cvvDe(
            (string) $t['token'],
            (int) $t['expira_mes'],
            (int) $t['expira_anio'],
            Config::claveBinaria('TARJETA_PEPPER'),
            (string) $t['marca']
        );

        // `hash_equals` y no `===`: comparar dígito a dígito filtra por
        // el tiempo de respuesta cuántos acertó quien lo está probando.
        if (!hash_equals($esperado, $cvv)) {
            throw $this->rechazo('82', 'Los datos de la tarjeta no coinciden.', $t, $comercio, $monto, $canal);
        }

        // --- Estado ---------------------------------------------------
        if ((string) $t['estado'] !== 'activa') {
            throw $this->rechazo('05', 'La tarjeta no admite operaciones.', $t, $comercio, $monto, $canal);
        }

        if ($t['congelada_hasta'] !== null
            && new \DateTimeImmutable((string) $t['congelada_hasta']) > new \DateTimeImmutable()) {
            throw $this->rechazo('62', 'La tarjeta está restringida.', $t, $comercio, $monto, $canal);
        }

        /* Los interruptores del titular, que hasta ahora solo existían
           en su pantalla de ajustes. Si alguien apagó las compras por
           internet, apagarlas tiene que significar que no pasan. */
        if (in_array($canal, ['tienda', 'internet', 'recurrente'], true)
            && (int) $t['compras_internet'] !== 1) {
            throw $this->rechazo(
                '62',
                'La tarjeta tiene deshabilitadas las compras por internet.',
                $t, $comercio, $monto, $canal
            );
        }

        return $t;
    }

    /** La forma en que un rechazo se le cuenta a la caja. */
    private function comoRespuesta(RedRechazada $e): array
    {
        return [
            'aprobada'   => false,
            'codigo'     => $e->codigo,
            'motivo'     => $e->getMessage(),
            'referencia' => null,
            'ultimos4'   => $e->ultimos4,
            'marca'      => $e->marca,
        ];
    }

    /**
     * Un rechazo, anotado.
     *
     * Se audita siempre, incluso cuando la tarjeta no es de este banco:
     * una ráfaga de códigos 14 desde la misma dirección es alguien
     * probando números, y sin dejar rastro no habría forma de verlo.
     *
     * @param array<string,mixed>|null $t
     * @return array{aprobada:bool, codigo:string, motivo:string,
     *               referencia:?string, ultimos4:?string, marca:?string}
     */
    private function rechazo(
        string $codigo,
        string $motivo,
        ?array $t,
        string $comercio,
        Monto $monto,
        string $canal
    ): RedRechazada {
        Auditoria::registrar(
            'red.rechazada', Auditoria::FALLO, null,
            'tarjeta', $t !== null ? (string) $t['id'] : 'desconocida',
            [
                'codigo'   => $codigo,
                'comercio' => $comercio,
                'canal'    => $canal,
                'importe'  => $monto->aDecimal(),
            ]
        );

        return new RedRechazada(
            $codigo,
            $motivo,
            $t !== null ? (string) $t['ultimos4'] : null,
            $t !== null ? (string) $t['marca'] : null
        );
    }
}
