<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Buro;

/**
 * Lo que este sistema necesita de un buró de crédito.
 *
 * La interfaz existe para que el día que haya contrato con Equifax,
 * TransUnion o INFORED solo haya que escribir su implementación y
 * cambiar el parámetro `buro.proveedor`. Nada del resto del sistema
 * conoce a un buró concreto.
 *
 * Se declara deliberadamente pequeña: solo los datos que de verdad se
 * usan para decidir un crédito. Pedir más historial del necesario es
 * guardar datos personales sin razón, y aquí el que menos se guarde,
 * mejor.
 */
interface ProveedorBuro
{
    /** Nombre con el que se identifica en los registros y en la autorización. */
    public function nombre(): string;

    /**
     * Consulta el historial de una persona.
     *
     * Quien llame debe haber comprobado ya que existe autorización
     * vigente del cliente: eso no es cosa del proveedor, es un requisito
     * legal que se verifica antes de salir de este sistema.
     *
     * @param string $documento Documento único de identidad
     * @param string $nombre    Nombre completo, para el cotejo del buró
     *
     * @return array{
     *   score:int, categoria:string, deuda_total_cent:int,
     *   cuota_mensual_cent:int, creditos_activos:int,
     *   moras_12m:int, peor_mora_dias:int, sin_historial:bool,
     *   crudo:array<string,mixed>
     * }
     *
     * @throws BuroError si la consulta no se puede completar
     */
    public function consultar(string $documento, string $nombre): array;
}
