<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

/**
 * Lo que el cliente declara de sí mismo, una sola vez.
 *
 * POR QUÉ SE PIDE
 *
 * Un banco no puede dar productos a quien no ha dicho de qué vive. No
 * es burocracia: la cuota de un crédito se compara contra el ingreso
 * para saber si la persona va a poder pagarla, y prestarle a alguien
 * una cuota que no puede cubrir no es hacerle un favor.
 *
 * `detalle_usuarios` ya tenía dónde guardar esto, pero nada obligaba
 * a llenarlo y nada lo exigía antes de prestar: se podía pedir un
 * crédito con el expediente en blanco.
 *
 * POR QUÉ UNA SOLA VEZ
 *
 * Lo que se declara por internet no lo respalda nadie: no hay quien
 * compare lo dicho con una constancia de sueldo. Vale para empezar
 * —para abrir una cuenta y tener por dónde— pero no para que luego
 * alguien se suba el ingreso a sí mismo la noche antes de pedir un
 * préstamo. Por eso `perfil_declarado_en` cierra el formulario: a
 * partir de ahí, los cambios se hacen en agencia, con el cliente
 * presente y su documento sobre el mostrador.
 */
final class ServicioPerfilDeclarado
{
    /** De dónde puede venir un ingreso, y cómo se dice. */
    public const FUENTES = [
        'empleo'         => 'Soy empleado de una empresa',
        'negocio_propio' => 'Tengo un negocio propio',
        'honorarios'     => 'Trabajo por mi cuenta, por honorarios',
        'remesas'        => 'Recibo remesas del exterior',
        'pension'        => 'Soy pensionado o jubilado',
        'rentas'         => 'Vivo de rentas o alquileres',
        'otro'           => 'Otra fuente',
    ];

    /** ¿Este cliente ya declaró? */
    public static function yaDeclaro(int $usuarioId): bool
    {
        return BaseDatos::unValor(
            'SELECT perfil_declarado_en FROM detalle_usuarios WHERE usuario_id = ?',
            [$usuarioId]
        ) !== null;
    }

    /**
     * ¿Puede pedir productos?
     *
     * Mientras no haya declarado un ingreso, no. Es la regla que pedía
     * el negocio y la que evita el crédito con expediente en blanco.
     */
    public static function puedePedirProductos(int $usuarioId): bool
    {
        if (!Parametros::booleano('registro.exigir_perfil', true)) {
            return true;
        }

        $d = BaseDatos::unaFila(
            'SELECT perfil_declarado_en, ingreso_mensual_cent
               FROM detalle_usuarios WHERE usuario_id = ?',
            [$usuarioId]
        );

        return $d !== null
            && $d['perfil_declarado_en'] !== null
            && (int) $d['ingreso_mensual_cent'] > 0;
    }

    /** El texto que se le enseña a quien todavía no puede. */
    public static function porQueNoPuede(): string
    {
        return 'Para solicitar productos necesitamos saber de qué vive: su ocupación '
             . 'y su ingreso mensual. Se declara una sola vez y toma un minuto.';
    }

    /**
     * Guarda lo declarado y cierra el formulario.
     *
     * @param array{fuente_ingreso?:string, ocupacion?:string, empresa?:string,
     *              cargo?:string, antiguedad_meses?:int|string,
     *              ingreso?:string, direccion?:string} $datos
     * @return array<string,mixed>
     *
     * @throws \RuntimeException si ya había declarado.
     */
    public function declarar(int $usuarioId, array $datos): array
    {
        if (self::yaDeclaro($usuarioId)) {
            throw new \RuntimeException(
                'Sus datos ya están declarados. Para cambiarlos, acérquese a '
                . 'cualquier agencia con su documento de identidad.'
            );
        }

        $fuente = (string) ($datos['fuente_ingreso'] ?? '');

        if (!isset(self::FUENTES[$fuente])) {
            throw new \InvalidArgumentException('Indique de dónde vienen sus ingresos.');
        }

        $ocupacion = trim((string) ($datos['ocupacion'] ?? ''));

        if (mb_strlen($ocupacion) < 3) {
            throw new \InvalidArgumentException(
                'Escriba a qué se dedica, aunque sea en pocas palabras.'
            );
        }

        /* El ingreso. `Monto::deEntradaUsuario` rechaza el cero a
           propósito, y aquí ese rechazo es el correcto: un ingreso de
           cero no es un dato, es un formulario sin llenar. */
        try {
            $ingreso = Monto::deEntradaUsuario((string) ($datos['ingreso'] ?? ''));
        } catch (\Throwable) {
            throw new \InvalidArgumentException(
                'Escriba su ingreso mensual aproximado. Si varía, ponga un promedio.'
            );
        }

        $antiguedad = max(0, min(840, (int) ($datos['antiguedad_meses'] ?? 0)));

        $empresa   = mb_substr(trim((string) ($datos['empresa'] ?? '')), 0, 255);
        $cargo     = mb_substr(trim((string) ($datos['cargo'] ?? '')), 0, 255);
        $direccion = mb_substr(trim((string) ($datos['direccion'] ?? '')), 0, 255);

        if (mb_strlen($direccion) < 10) {
            throw new \InvalidArgumentException(
                'Necesitamos su dirección de residencia con algo de detalle.'
            );
        }

        /* Un empleado sin empresa es un dato a medias: si dijo que
           trabaja para alguien, ese alguien tiene nombre. */
        if ($fuente === 'empleo' && $empresa === '') {
            throw new \InvalidArgumentException(
                'Escriba el nombre de la empresa donde trabaja.'
            );
        }

        BaseDatos::enTransaccion(function () use (
            $usuarioId, $fuente, $ocupacion, $ingreso, $antiguedad,
            $empresa, $cargo, $direccion
        ): void {
            BaseDatos::ejecutar(
                'INSERT INTO detalle_usuarios
                   (usuario_id, fuente_ingreso, ocupacion, antiguedad_meses,
                    ingreso_mensual_cent, empresa, cargo, direccion,
                    perfil_declarado_en, actualizado_en)
                 VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())
                 ON DUPLICATE KEY UPDATE
                    fuente_ingreso = VALUES(fuente_ingreso),
                    ocupacion = VALUES(ocupacion),
                    antiguedad_meses = VALUES(antiguedad_meses),
                    ingreso_mensual_cent = VALUES(ingreso_mensual_cent),
                    empresa = VALUES(empresa),
                    cargo = VALUES(cargo),
                    direccion = VALUES(direccion),
                    perfil_declarado_en = NOW(),
                    actualizado_en = NOW()',
                [
                    $usuarioId, $fuente, $ocupacion, $antiguedad,
                    $ingreso->centavos,
                    /* Cadena vacía, no `null`.
                     *
                     * `empresa` y `cargo` son NOT NULL con cadena vacía
                     * por omisión. Mandar `null` cuando el campo venía
                     * en blanco —el cargo es opcional, y quien no es
                     * empleado tampoco tiene empresa— reventaba el alta
                     * entera con un «Column 'cargo' cannot be null» que
                     * además salía en pantalla. Vacío es exactamente lo
                     * que esas columnas esperan de un dato que no hay. */
                    $empresa,
                    $cargo,
                    $direccion,
                ]
            );
        });

        /* Lo declarado NO marca el expediente como completo.
         *
         * `perfil_completo` significa que alguien del banco revisó los
         * documentos del cliente. Esto es lo que el cliente dice de sí
         * mismo por internet, sin respaldo. Confundir las dos cosas
         * daría por verificado un expediente que nadie miró. */
        Auditoria::registrar(
            'perfil.declarado',
            Auditoria::EXITO,
            $usuarioId,
            'usuario',
            (string) $usuarioId,
            [
                'fuente'    => $fuente,
                'ocupacion' => $ocupacion,
                'ingreso'   => $ingreso->aDecimal(),
            ]
        );

        return [
            'ingreso'   => $ingreso->formateado(),
            'fuente'    => self::FUENTES[$fuente],
            'ocupacion' => $ocupacion,
        ];
    }
}
