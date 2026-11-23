<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioCorteTarjeta;
use CashMan\Aplicacion\Servicio\ServicioMora;
use CashMan\Dominio\Credito\Mora;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Procesos periódicos.
 *
 * El devengo de mora y el corte de tarjetas se ejecutan desde consola y
 * conviene que sigan así —programados, una vez al día—, pero quien
 * administra el sistema no tiene por qué entrar al servidor para saber
 * si corrieron ni para lanzarlos un día que fallara la tarea.
 *
 * Esta pantalla hace dos cosas: enseña qué harían hoy (en simulación,
 * sin escribir) y permite aplicarlos.
 */
final class ControladorProcesos extends ControladorBase
{
    public function panel(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        if (!Autorizacion::puedeAlguno(['mora.devengar', 'tarjeta.cortar', 'parametro.editar'])) {
            throw new \CashMan\Infraestructura\Seguridad\AccesoDenegado(
                'No tiene permiso para ver los procesos periódicos.'
            );
        }

        $puedeMora  = Autorizacion::puede('mora.devengar');
        $puedeCorte = Autorizacion::puede('tarjeta.cortar');

        // Simulación: así la pantalla dice qué pasaría antes de que
        // nadie pulse nada.
        $mora  = $puedeMora  ? (new ServicioMora())->devengar(null, true) : null;
        $corte = $puedeCorte ? (new ServicioCorteTarjeta())->cortar(null, true) : null;

        $this->render('seguridad/procesos', [
            'titulo'      => 'Procesos periódicos · CashMan H.A.',
            'encabezado'  => 'Procesos periódicos',
            'mora'        => $mora,
            'corte'       => $corte,
            'puedeMora'   => $puedeMora,
            'puedeCorte'  => $puedeCorte,
            'parametros'  => $this->parametrosVisibles(),
            'ultimas'     => $this->ultimasEjecuciones(),
            'cargoDiario' => Mora::cargoDeUnDia(),
            'csrf'        => Csrf::token('procesos'),
        ]);
    }

    /** Ejecuta el devengo de mora. */
    public function devengarMora(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('mora.devengar');

        $r = (new ServicioMora())->devengar();

        $this->responderSegunCliente(
            true,
            $r['cuotas'] === 0
                ? 'No había mora pendiente de devengar.'
                : sprintf(
                    'Se devengó la mora de %d cuota(s): $%s en %d día(s).',
                    $r['cuotas'], number_format($r['cargo_cent'] / 100, 2), $r['dias']
                ),
            $this->enrutador->url('procesos'),
            ['cuotas' => $r['cuotas'], 'cargo_cent' => $r['cargo_cent']]
        );
    }

    /** Ejecuta el corte de tarjetas. */
    public function cortarTarjetas(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('tarjeta.cortar');

        $r = (new ServicioCorteTarjeta())->cortar();

        $this->responderSegunCliente(
            true,
            $r['cortes'] === 0
                ? 'Ninguna tarjeta tenía corte pendiente.'
                : sprintf(
                    'Se generaron %d corte(s) por $%s.',
                    $r['cortes'], number_format($r['saldo_cent'] / 100, 2)
                ),
            $this->enrutador->url('procesos'),
            ['cortes' => $r['cortes']]
        );
    }

    /**
     * Guarda un parámetro.
     *
     * Solo se admiten las claves que esta pantalla muestra: la clave
     * llega del formulario, y sin lista blanca se podría escribir
     * cualquier parámetro del sistema desde aquí.
     */
    public function guardarParametro(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('parametro.editar');

        $clave = trim((string) $this->post('clave'));
        $valor = trim((string) $this->post('valor'));

        if (!array_key_exists($clave, self::EDITABLES)) {
            $this->responderSegunCliente(false, 'Ese parámetro no se edita desde aquí.',
                $this->enrutador->url('procesos'), null, [], 422);
            return;
        }

        [, , $min, $max] = self::EDITABLES[$clave];

        if ($min !== null) {
            if (!is_numeric($valor)) {
                $this->responderSegunCliente(false, 'El valor debe ser un número.',
                    $this->enrutador->url('procesos'), null, [], 422);
                return;
            }
            $n = (int) $valor;
            if ($n < $min || $n > $max) {
                $this->responderSegunCliente(false,
                    sprintf('El valor debe estar entre %d y %d.', $min, $max),
                    $this->enrutador->url('procesos'), null, [], 422);
                return;
            }
            $valor = (string) $n;
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            $this->responderSegunCliente(false, 'La fecha debe tener el formato AAAA-MM-DD.',
                $this->enrutador->url('procesos'), null, [], 422);
            return;
        }

        Parametros::guardar($clave, $valor);

        \CashMan\Infraestructura\Seguridad\Auditoria::registrar(
            'parametro.modificado',
            \CashMan\Infraestructura\Seguridad\Auditoria::EXITO,
            Sesion::usuarioId(),
            'parametro',
            $clave,
            ['valor' => $valor]
        );

        $this->responderSegunCliente(true, 'Parámetro actualizado.',
            $this->enrutador->url('procesos'));
    }

    // -----------------------------------------------------------------

    /**
     * Parámetros editables: clave => [etiqueta, ayuda, mínimo, máximo].
     *
     * Con mínimo null el valor es una fecha. Los topes no son adorno: el
     * cargo diario multiplica por días, y un cero o un número desmedido
     * ahí se traduce en dinero mal cobrado a clientes reales.
     */
    private const EDITABLES = [
        'mora.cargo_diario_cent' => [
            'Recargo diario por mora (centavos)',
            'La cláusula penal del contrato fija hasta $5.99 diarios por cuota atrasada.',
            0, 10000,
        ],
        'mora.dias_gracia' => [
            'Días de gracia antes del recargo',
            'El contrato concede diez días; el sistema anterior cobraba desde el primero.',
            0, 60,
        ],
        'mora.tope_por_cuota_cent' => [
            'Tope de recargo por cuota (centavos)',
            'Cero significa sin tope propio: el recargo igual nunca pasa del saldo de la cuota.',
            0, 100000000,
        ],
        'mora.devengar_desde' => [
            'Devengar la mora desde',
            'Protege de cobrar de golpe atrasos anteriores a la puesta en marcha.',
            null, null,
        ],
        'seguro.vida_por_mil_cent' => [
            'Seguro de vida por cada $1,000 (centavos)',
            'Prima mensual sobre el saldo deudor de un crédito.',
            0, 10000,
        ],
        'seguro.empleo_por_mil_cent' => [
            'Seguro de desempleo por cada $1,000 (centavos)',
            'Prima mensual sobre el saldo deudor de un crédito.',
            0, 10000,
        ],
        'tarjeta.pago_minimo_pct' => [
            'Pago mínimo de tarjeta (puntos base)',
            '500 equivale al 5 % del saldo al corte.',
            0, 10000,
        ],
        'tarjeta.dias_para_pago' => [
            'Días entre corte y fecha límite',
            'Plazo que tiene el cliente para pagar después del corte.',
            1, 60,
        ],
    ];

    /** @return array<int,array<string,mixed>> */
    private function parametrosVisibles(): array
    {
        $salida = [];

        foreach (self::EDITABLES as $clave => [$etiqueta, $ayuda, $min, $max]) {
            $salida[] = [
                'clave'    => $clave,
                'etiqueta' => $etiqueta,
                'ayuda'    => $ayuda,
                'valor'    => Parametros::obtener($clave, ''),
                'esFecha'  => $min === null,
            ];
        }

        return $salida;
    }

    /** @return array<int,array<string,mixed>> */
    private function ultimasEjecuciones(): array
    {
        return BaseDatos::todas(
            "SELECT accion, resultado, detalle_json, creado_en
               FROM auditoria
              WHERE accion IN ('mora.devengada', 'tarjeta.corte_mensual', 'parametro.modificado')
              ORDER BY id DESC LIMIT 15"
        );
    }
}
