<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioCartera;
use CashMan\Aplicacion\Servicio\ServicioElegibilidadTarjeta;
use CashMan\Aplicacion\Servicio\ServicioSegmento;
use CashMan\Dominio\Cliente\Segmento;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * La banca del cliente preferente.
 *
 * No es una pantalla de adorno con un sello dorado. Enseña lo que su
 * segmento le concede de verdad —el múltiplo de línea que se le
 * aprueba, cuántas compras puede diferir, el descuento de tasa— y le
 * da acceso a lo que solo él puede pedir.
 *
 * Al cliente estándar NO se le esconde: se le enseña exactamente qué
 * le falta para entrar. Una puerta cerrada sin explicación es peor que
 * no tener puerta, y además el camino al escalón siguiente es
 * precisamente lo que mueve a un cliente a concentrar su relación aquí.
 */
final class ControladorPreferente extends ControladorBase
{
    public function ver(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        /* Quien atiende puede mirar el de un cliente concreto; el
           cliente solo el suyo. */
        $deOtro = $this->get('cliente');

        if ($deOtro !== '' && Autorizacion::puede('cliente.ver_expediente')) {
            $otro = $this->idDesde($deOtro, 'usuario');

            if ($otro > 0) {
                $usuarioId = $otro;
            }
        }

        $svc = new ServicioSegmento();
        $s   = $svc->de($usuarioId);

        $cliente = BaseDatos::unaFila(
            'SELECT CONCAT(nombres," ",apellidos) AS nombre, segmento_desde
               FROM usuarios WHERE id = ?',
            [$usuarioId]
        ) ?? [];

        /* Las líneas que solo este segmento puede pedir.
           Se consultan por código y no por nivel: la exclusividad es
           una decisión comercial de cada producto, no del escalón. */
        $exclusivas = [];

        $codigos = (array) ($s['beneficios']['lineas_exclusivas'] ?? []);

        if ($codigos !== []) {
            $marcas = implode(',', array_fill(0, count($codigos), '?'));

            $exclusivas = BaseDatos::todas(
                /* `ingreso_minimo_cent` y `publico` no son adorno: son
                   DOS de las condiciones que evalúa la elegibilidad.
                   Sin ellos, el evaluador leía cero en el requisito de
                   ingreso y daba por cumplido lo que no se cumplía. */
                'SELECT id, codigo, nombre, descripcion, beneficios,
                        linea_desde_cent, membresia_anual_cent, tasa_interes_bp,
                        marca_emision, nivel, ingreso_minimo_cent, publico
                   FROM productos
                  WHERE codigo IN (' . $marcas . ') AND tipo = "tarjeta" AND activo = 1
                  ORDER BY nivel',
                $codigos
            );

            /* Si el cliente PUEDE pedirla, y si no, qué le falta.

               Antes esta pantalla ofrecía las tres con el mismo botón
               entero, y el requisito aparecía al pulsarlo, en un aviso
               rojo. Pero el requisito ya se sabía: está en el producto
               y el ingreso está en el expediente. Hacerle pulsar para
               enterarse no es validar, es una puerta pintada. */
            $elegible = new ServicioElegibilidadTarjeta();

            foreach ($exclusivas as &$p) {
                $p['codigo_h']     = Hashid::codificar((int) $p['id'], 'producto');
                $p['elegibilidad'] = $elegible->evaluar($usuarioId, $p);
                unset($p['id']);
            }
            unset($p);
        }

        // Lo que ya tiene con la institución, para que el panel no sea
        // solo promesas.
        $resumen = [
            'cuentas'  => (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM cuentas WHERE usuario_id = ? AND es_tecnica = 0
                   AND estado = "activa"', [$usuarioId]),
            'tarjetas' => (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM tarjetas WHERE usuario_id = ? AND estado = "activa"',
                [$usuarioId]),
            'creditos' => (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM creditos WHERE usuario_id = ? AND estado = "vigente"',
                [$usuarioId]),
        ];

        /* Su ingreso registrado. Se enseña junto a cada requisito:
           «pide $25,000 · el suyo es $9,000» responde la pregunta de
           una vez, y además deja claro de dónde sale el dato, que es
           lo que permite corregirlo si está mal. */
        $ingresoCliente = (int) BaseDatos::unValor(
            'SELECT COALESCE(ingreso_mensual_cent, 0) FROM detalle_usuarios
              WHERE usuario_id = ?',
            [$usuarioId]
        );

        /* Quién es su banquero. La tarjeta decía «tiene una persona
           asignada» sin decir quién, y asignar a alguien sin nombrarlo
           es lo mismo que no asignarlo. */
        $banquero = (new ServicioCartera())->banqueroDe($usuarioId);

        $this->render('clientes/preferente', [
            'banquero'        => $banquero,
            'ingreso_cliente' => $ingresoCliente,
            'titulo'     => 'Banca Preferente · CashMan H.A.',
            'encabezado' => $s['segmento'] === Segmento::ESTANDAR
                ? 'Banca Preferente'
                : 'Banca ' . $s['etiqueta'],
            'seg'        => $s,
            'cliente'    => $cliente,
            'exclusivas' => $exclusivas,
            'resumen'    => $resumen,
            'escalones'  => [
                Segmento::PREFERENTE  => Segmento::beneficiosVisibles(Segmento::PREFERENTE),
                Segmento::PATRIMONIAL => Segmento::beneficiosVisibles(Segmento::PATRIMONIAL),
            ],
            'csrf'       => Csrf::token('solicitud_tarjeta'),
        ]);
    }
}
