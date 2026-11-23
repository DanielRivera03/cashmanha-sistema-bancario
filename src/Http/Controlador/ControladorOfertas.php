<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioOfertaPreaprobada;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Ofertas preaprobadas.
 *
 * Dos pantallas con públicos distintos:
 *
 *   MIS OFERTAS   Lo que el cliente ve: la propuesta, por qué se le
 *                 hizo, hasta cuándo, y dos botones. Aceptar ejecuta la
 *                 operación completa —crédito desembolsado o tarjeta
 *                 emitida— sin pasar por solicitud ni revisión, porque
 *                 la decisión ya estaba tomada.
 *
 *   LA CAMPAÑA    Lo que ve quien dirige la cartera: a quién se ofreció,
 *                 con qué criterios, qué se aceptó y qué caducó. Y el
 *                 botón de evaluar, que vuelve a mirar la cartera.
 */
final class ControladorOfertas extends ControladorBase
{
    /** Lo que el cliente tiene sobre la mesa. */
    public function mias(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $svc = new ServicioOfertaPreaprobada();
        $vigentes = $svc->vigentesDe($usuarioId);

        foreach ($vigentes as &$o) {
            $o['criterios'] = json_decode((string) $o['criterios_json'], true) ?: [];
            $o['dias']      = max(0, (int) ceil(
                (strtotime((string) $o['vence_el']) - strtotime(date('Y-m-d'))) / 86400
            ));

            /* Los plazos entre los que puede elegir, ya calculados.
             *
             * El plazo es lo que cada quien decide según su bolsillo:
             * a doce meses la cuota aprieta y se pagan menos intereses;
             * a sesenta, al revés. Enseñar las cifras de cada opción
             * es lo que convierte la elección en una decisión, y no en
             * una apuesta. */
            $o['plazos'] = [];

            if ((string) $o['tipo'] === 'credito') {
                foreach (ServicioOfertaPreaprobada::plazosPosibles() as $p) {
                    try {
                        $o['plazos'][] = $svc->simularPlazo((int) $o['id'], $p);
                    } catch (\Throwable $e) {
                        // Un plazo que no se pudo calcular no se ofrece.
                    }
                }
            }

            $o['codigo'] = Hashid::codificar((int) $o['id'], 'oferta');
            unset($o['id']);
        }
        unset($o);

        // El historial importa: una oferta aceptada es la prueba de que
        // esto no es publicidad, y una caducada explica por qué ya no
        // está. Esconderlas dejaría la pantalla vacía sin decir nada.
        $historial = BaseDatos::todas(
            'SELECT o.folio, o.tipo, o.monto_cent, o.estado, o.vence_el,
                    o.aceptada_en, o.cerrada_motivo, c.folio AS credito_folio,
                    t.ultimos4
               FROM ofertas_preaprobadas o
               LEFT JOIN creditos c ON c.id = o.credito_id
               LEFT JOIN tarjetas t ON t.id = o.tarjeta_id
              WHERE o.usuario_id = ? AND o.estado <> "vigente"
              ORDER BY o.id DESC LIMIT 20',
            [$usuarioId]
        );

        $this->render('ofertas/mias', [
            'titulo'     => 'Ofertas preaprobadas · CashMan H.A.',
            'encabezado' => 'Ofertas preaprobadas para usted',
            'ofertas'    => $vigentes,
            'historial'  => $historial,
            'csrf'       => Csrf::token('oferta'),
        ]);
    }

    /** La campaña, para quien dirige la cartera. */
    public function cartera(array $parametros = []): void
    {
        Autorizacion::exigir('oferta.ver');
        Sesion::exigirAutenticado();

        (new ServicioOfertaPreaprobada())->caducarVencidas();

        $estado = trim($this->get('e', ''));
        $donde  = '1=1';
        $args   = [];

        if (in_array($estado, ['vigente', 'aceptada', 'rechazada', 'caducada', 'anulada'], true)) {
            $donde = 'o.estado = ?';
            $args[] = $estado;
        }

        $ofertas = BaseDatos::todas(
            'SELECT o.*, CONCAT(u.nombres," ",u.apellidos) AS cliente, u.usuario,
                    u.correo, p.nombre AS producto,
                    c.folio AS credito_folio, t.ultimos4
               FROM ofertas_preaprobadas o
               JOIN usuarios u  ON u.id = o.usuario_id
               LEFT JOIN productos p ON p.id = o.producto_id
               LEFT JOIN creditos  c ON c.id = o.credito_id
               LEFT JOIN tarjetas  t ON t.id = o.tarjeta_id
              WHERE ' . $donde . '
              ORDER BY o.id DESC LIMIT 300',
            $args
        );

        foreach ($ofertas as &$o) {
            $o['codigo']    = Hashid::codificar((int) $o['id'], 'oferta');
            $o['criterios'] = json_decode((string) $o['criterios_json'], true) ?: [];
            unset($o['id']);
        }
        unset($o);

        $porEstado = BaseDatos::unaFila(
            'SELECT SUM(estado = "vigente")   AS vigente,
                    SUM(estado = "aceptada")  AS aceptada,
                    SUM(estado = "rechazada") AS rechazada,
                    SUM(estado = "caducada")  AS caducada,
                    SUM(estado = "anulada")   AS anulada,
                    COALESCE(SUM(CASE WHEN estado = "vigente" THEN monto_cent END),0) AS ofrecido,
                    COALESCE(SUM(CASE WHEN estado = "aceptada" THEN monto_cent END),0) AS colocado
               FROM ofertas_preaprobadas'
        ) ?? [];

        $this->render('ofertas/cartera', [
            'titulo'     => 'Ofertas preaprobadas · CashMan H.A.',
            'encabezado' => 'Campaña de ofertas preaprobadas',
            'ofertas'    => $ofertas,
            'porEstado'  => $porEstado,
            'filtro'     => $estado,
            'criterios'  => [
                'min_cuotas' => Parametros::entero('oferta.min_cuotas_pagadas', 6),
                'max_mora'   => Parametros::entero('oferta.max_dias_mora', 5),
                'vigencia'   => Parametros::entero('oferta.vigencia_dias', 30),
                'margen'     => Parametros::entero('oferta.margen_bp', 8000) / 100,
                'plazo'      => Parametros::entero('oferta.plazo_credito', 36),
            ],
            'puedeGenerar' => Autorizacion::puede('oferta.generar'),
            'puedeAnular'  => Autorizacion::puede('oferta.anular'),
            'csrf'       => Csrf::token('oferta'),
        ]);
    }

    /** Vuelve a mirar la cartera y genera lo que proceda. */
    public function generar(array $parametros = []): void
    {
        Autorizacion::exigir('oferta.generar');
        Sesion::exigirAutenticado();

        try {
            $r = (new ServicioOfertaPreaprobada())->evaluar(null, true);
        } catch (\Throwable $e) {
            $this->responderSegunCliente(
                false, $e->getMessage(), $this->url('ofertas/cartera'), null, [], 422
            );
            return;
        }

        $this->responderSegunCliente(
            true,
            $r['ofertas'] === 0
                ? 'Se evaluaron ' . $r['evaluados'] . ' cliente(s) y ninguno reúne hoy '
                  . 'las condiciones para una oferta nueva.'
                : 'Se evaluaron ' . $r['evaluados'] . ' cliente(s) y se generaron '
                  . $r['ofertas'] . ' oferta(s), ya enviadas por correo.',
            $this->url('ofertas/cartera'),
            $r
        );
    }

    /** El cliente acepta: se le otorga en el acto. */
    public function aceptar(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'oferta');

        if ($id === 0) {
            $this->responderSegunCliente(
                false, 'Oferta no encontrada.', $this->url('ofertas'), null, [], 404
            );
            return;
        }

        /* El plazo que eligió el cliente, si eligió uno.
           El servicio lo valida contra su propia lista: aquí solo se
           traslada lo que llegó, que viene del navegador. */
        $plazo = $this->postEntero('plazo');

        try {
            $r = (new ServicioOfertaPreaprobada())->aceptar($id, $plazo > 0 ? $plazo : null);
        } catch (\Throwable $e) {
            $this->responderSegunCliente(
                false, $e->getMessage(), $this->url('ofertas'), null, [], 422
            );
            return;
        }

        $acreditado = Monto::deCentavos((int) ($r['monto']['centavos'] ?? 0))->formateado();

        /* Cada producto deja algo distinto, y el acuse tiene que decir
           exactamente lo que quedo: al extrafinanciamiento no se le
           emite ninguna tarjeta —se le ocupa la que ya tenia— y el
           dinero entra en la cuenta de ahorro. */
        $mensaje = match ($r['tipo']) {
            'credito' =>
                'Listo. Su crédito ' . ($r['folio_credito'] ?? '')
                . ' quedó formalizado y se acreditaron ' . $acreditado
                . ' en su cuenta ' . ($r['cuenta'] ?? '') . '.',

            'extrafinanciamiento' =>
                'Listo. Se acreditaron ' . $acreditado . ' en su cuenta '
                . ($r['cuenta'] ?? '') . '. La cuota de '
                . Monto::deCentavos((int) ($r['cuota_cent'] ?? 0))->formateado()
                . ' se cargará cada mes en el estado de cuenta de su tarjeta '
                . 'durante ' . (int) ($r['plazo'] ?? 0) . ' meses.',

            default => 'Listo. Su tarjeta de crédito quedó emitida.',
        };

        $this->responderSegunCliente(
            true, $mensaje . ' Le enviamos el detalle por correo.',
            $this->url(match ($r['tipo']) {
                'credito'             => 'creditos',
                'extrafinanciamiento' => 'cuentas',
                default               => 'tarjetas',
            }),
            $r
        );
    }

    /** El cliente la descarta. */
    public function rechazar(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'oferta');

        if ($id === 0) {
            $this->responderSegunCliente(
                false, 'Oferta no encontrada.', $this->url('ofertas'), null, [], 404
            );
            return;
        }

        try {
            (new ServicioOfertaPreaprobada())->rechazar($id, $this->post('motivo'));
        } catch (\Throwable $e) {
            $this->responderSegunCliente(
                false, $e->getMessage(), $this->url('ofertas'), null, [], 422
            );
            return;
        }

        $this->responderSegunCliente(
            true,
            'Oferta descartada. No afecta sus productos actuales ni su historial.',
            $this->url('ofertas')
        );
    }

    /** La institución la retira. */
    public function anular(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'oferta');

        if ($id === 0) {
            $this->responderSegunCliente(
                false, 'Oferta no encontrada.', $this->url('ofertas/cartera'), null, [], 404
            );
            return;
        }

        try {
            (new ServicioOfertaPreaprobada())->anular($id, $this->post('motivo'));
        } catch (\Throwable $e) {
            $this->responderSegunCliente(
                false, $e->getMessage(), $this->url('ofertas/cartera'), null, [], 422
            );
            return;
        }

        $this->responderSegunCliente(
            true, 'Oferta anulada.', $this->url('ofertas/cartera')
        );
    }
}
