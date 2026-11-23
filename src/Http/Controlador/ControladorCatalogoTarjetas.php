<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioSegmento;

use CashMan\Aplicacion\Servicio\ServicioBienvenidaTarjeta;
use CashMan\Aplicacion\Servicio\ServicioElegibilidadTarjeta;
use CashMan\Aplicacion\Servicio\ServicioSolicitudTarjeta;
use CashMan\Dominio\Comun\Monto;
use CashMan\Http\Respuesta;
use CashMan\Dominio\Credito\CapacidadPago;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Catálogo de tarjetas y sus solicitudes.
 *
 * El cliente no tenía forma de pedir una tarjeta: la pantalla le decía
 * que escribiera a soporte, y quien la emitía era el personal desde una
 * pantalla interna, sin solicitud ni constancia de por qué se le dio ese
 * límite a esa persona.
 *
 * Aquí el cliente ve las tarjetas, qué pide cada una y qué incluye, y
 * puede solicitarla y seguir en qué va. El personal ve lo mismo más la
 * bandeja de solicitudes.
 */
final class ControladorCatalogoTarjetas extends ControladorBase
{
    /** El catálogo, con lo que el cliente puede optar según su ingreso. */
    public function catalogo(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $tarjetas = BaseDatos::todas(
            'SELECT id, codigo, nombre, descripcion, tasa_interes_bp,
                    ingreso_minimo_cent, linea_desde_cent, membresia_anual_cent,
                    beneficios, nivel, marca_emision
               FROM productos
              WHERE tipo = "tarjeta" AND activo = 1 AND publico = 1
                /* La de debito no va aqui: se pide desde la cuenta a la
                   que queda ligada, y este catalogo lleva al flujo de
                   solicitud de credito. Mostrarla seria ofrecer una
                   puerta que no abre. */
                AND codigo <> "TAR-DEB"
              ORDER BY nivel, ingreso_minimo_cent'
        );

        // Ingreso y compromisos del cliente: con eso se le dice de una vez
        // a cuál puede optar, en lugar de dejarle solicitar una que el
        // sistema va a rechazar después.
        $ingreso = Monto::deCentavos((int) BaseDatos::unValor(
            'SELECT COALESCE(ingreso_mensual_cent, 0) FROM detalle_usuarios WHERE usuario_id = ?',
            [$usuarioId]
        ));

        $compromisos = $this->compromisos($usuarioId);
        $capacidad   = $ingreso->esPositivo()
            ? CapacidadPago::limiteTarjeta(
                  $ingreso,
                  $compromisos,
                  null,
                  (new ServicioSegmento())->vecesIngreso($usuarioId)
              )
            : Monto::cero();

        $elegible = new ServicioElegibilidadTarjeta();

        foreach ($tarjetas as &$t) {
            $t['codigo_h']   = Hashid::codificar((int) $t['id'], 'producto');
            $t['tasa']       = number_format((int) $t['tasa_interes_bp'] / 100, 2) . ' %';
            $t['lista']      = array_values(array_filter(
                array_map('trim', explode("\n", (string) $t['beneficios']))
            ));
            /* `alcanza` miraba solo el ingreso, que es una de las seis
               condiciones. Un cliente con ingreso de sobra pero una
               cuota en mora veía el botón entero y se topaba con el
               rechazo al pulsarlo. Ahora se evalúan todas y la pantalla
               dice lo mismo que va a decidir el servidor. */
            $t['elegibilidad'] = $elegible->evaluar($usuarioId, $t);
            $t['alcanza']      = $t['elegibilidad']['puede'];
            unset($t['id'], $t['beneficios']);
        }
        unset($t);

        // Solicitud en trámite, si la hay: es lo primero que quiere saber
        // quien ya pidió una.
        $enTramite = BaseDatos::unaFila(
            "SELECT s.id, s.folio, s.etapa, s.limite_solicitado_cent,
                    s.limite_aprobado_cent, s.creado_en, p.nombre AS producto
               FROM solicitudes_tarjeta s
               JOIN productos p ON p.id = s.producto_id
              WHERE s.usuario_id = ?
              ORDER BY s.id DESC LIMIT 1",
            [$usuarioId]
        );

        /* El recorrido de la que está en trámite, para enseñar los
           pasos y sus fechas en lugar de una barra a medias. */
        $historialTramite = $enTramite === null ? [] : BaseDatos::todas(
            'SELECT h.etapa_nueva, h.nota, h.creado_en
               FROM solicitud_tarjeta_historial h
               JOIN solicitudes_tarjeta s ON s.id = h.solicitud_id
              WHERE s.folio = ? AND s.usuario_id = ?
              ORDER BY h.id ASC',
            [(string) $enTramite['folio'], $usuarioId]
        );

        $this->render('tarjetas/catalogo', [
            'historial_tramite' => $historialTramite,
            'titulo'      => 'Catálogo de tarjetas · CashMan H.A.',
            'encabezado'  => 'Nuestras tarjetas de crédito',
            'tarjetas'    => $tarjetas,
            'ingreso'     => $ingreso->centavos,
            'compromisos' => $compromisos->centavos,
            'capacidad'   => $capacidad->centavos,
            'enTramite'   => $enTramite,
            'csrf'        => Csrf::token('solicitud_tarjeta'),
        ]);
    }

    /** Recibe la solicitud. */
    public function solicitar(array $parametros = []): void
    {
        $this->exigirPerfilParaProductos();

        $destino = $this->enrutador->url('tarjetas/catalogo');

        try {
            $r = (new ServicioSolicitudTarjeta())->solicitar([
                'usuario_id'  => $this->idDesde($this->post('cliente'), 'usuario'),
                'producto_id' => $this->idDesde($this->post('producto'), 'producto'),
                'marca'       => $this->post('marca'),
                'limite'      => $this->post('limite'),
                'ingreso'     => $this->post('ingreso'),
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $mensaje = 'Recibimos su solicitud ' . $r['folio'] . ' para la ' . $r['producto'] . '.';

        if ($r['se_ajustara']) {
            $mensaje .= ' Tenga en cuenta que el límite se ajustará a '
                      . $r['capacidad']['formateado'] . ', que es lo que admite su capacidad de pago.';
        }

        $this->responderSegunCliente(true, $mensaje, $destino, $r);
    }

    /**
     * La bienvenida pendiente del cliente, si la hay.
     *
     * La pide la pantalla al cargar. Devuelve `{hay:false}` casi
     * siempre —lo normal es que no haya nada que celebrar— y por eso
     * es una consulta barata con un índice detrás.
     *
     * Solo sirve al titular: el identificador de la tarjeta no viaja
     * en la petición, se saca de la sesión. Así no hay forma de pedir
     * la bienvenida de otro.
     */
    public function bienvenida(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $b = (new ServicioBienvenidaTarjeta())->pendiente($usuarioId);

        if ($b === null) {
            Respuesta::json(['hay' => false]);
            return;
        }

        /* El plástico lo dibuja la misma función que las vistas. Tener
           una segunda versión del dibujo para este modal sería dos
           sitios donde arreglar el mismo acabado. */
        require_once dirname(__DIR__, 2) . '/Vistas/layout/plastico.php';

        $b['plastico'] = cm_plastico(
            $b['plastico'],
            ['assets' => $this->enrutador->url('assets/plantilla/images')]
        );

        $b['url'] = $this->enrutador->url($b['url']);

        Respuesta::json(['hay' => true, 'bienvenida' => $b]);
    }
    /**
     * Las solicitudes del propio cliente, con su recorrido.
     *
     * El cliente solo veía la que tenía en trámite, y solo de paso, en
     * el catálogo. Las resueltas desaparecían de su vista: para saber
     * qué le dijeron al negarle una línea hace tres meses tenía que
     * llamar y que alguien se lo leyera.
     *
     * No hay parámetro de cliente. El titular sale de la sesión y
     * punto: esta pantalla es la suya.
     */
    public function mias(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $filas = BaseDatos::todas(
            'SELECT s.id, s.folio, s.etapa, s.creado_en, s.motivo_rechazo,
                    s.limite_solicitado_cent, s.limite_aprobado_cent,
                    p.nombre AS producto,
                    t.id AS tarjeta_id, t.ultimos4 AS tarjeta_ultimos4
               FROM solicitudes_tarjeta s
               JOIN productos p ON p.id = s.producto_id
          LEFT JOIN tarjetas  t ON t.id = s.tarjeta_id
              WHERE s.usuario_id = ?
              ORDER BY s.id DESC
              LIMIT 50',
            [$usuarioId]
        );

        /* Cómo se llama cada etapa para el titular. «revision_gerencia»
           es el nombre interno del paso, no lo que él necesita leer. */
        $ESTADOS = [
            'capturada'         => ['Recibida',        'light'],
            'revision_atencion' => ['En revisión',     'info'],
            'revision_gerencia' => ['En gerencia',     'primary'],
            'aprobada'          => ['Aprobada',        'success'],
            'emitida'           => ['Tarjeta emitida', 'success'],
            'rechazada'         => ['No aprobada',     'danger'],
        ];

        foreach ($filas as &$f) {
            $id = (int) $f['id'];

            [$f['estado'], $f['color']] =
                $ESTADOS[(string) $f['etapa']] ?? ['En proceso', 'light'];

            /* El recorrido. Solo lo que es para el titular: el nombre
               de quien movió el expediente no se le enseña —no le
               sirve de nada y es dato del personal—, pero la fecha de
               cada paso sí, que es lo que contesta «¿se está
               moviendo?». */
            $f['historial'] = BaseDatos::todas(
                'SELECT etapa_nueva, nota, creado_en
                   FROM solicitud_tarjeta_historial
                  WHERE solicitud_id = ?
                  ORDER BY id ASC',
                [$id]
            );

            $f['tarjeta_codigo'] = $f['tarjeta_id'] === null
                ? null
                : Hashid::codificar((int) $f['tarjeta_id'], 'tarjeta');

            unset($f['id'], $f['tarjeta_id']);
        }
        unset($f);

        $this->render('tarjetas/mis_solicitudes', [
            'titulo'       => 'Mis solicitudes · CashMan H.A.',
            'encabezado'   => 'Mis solicitudes de tarjeta',
            'solicitudes'  => $filas,
        ]);
    }
    /** Bandeja de solicitudes, para el personal. */
    public function listar(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('tarjeta.ver_solicitudes');

        $etapa = $this->get('etapa');

        $donde = '1=1';
        $args  = [];

        if (in_array($etapa, ['capturada', 'revision_atencion', 'revision_gerencia',
                              'aprobada', 'rechazada', 'emitida'], true)) {
            $donde = 's.etapa = ?';
            $args[] = $etapa;
        }

        $solicitudes = BaseDatos::todas(
            "SELECT s.id, s.folio, s.etapa, s.marca, s.limite_solicitado_cent,
                    s.limite_aprobado_cent, s.capacidad_maxima_cent, s.ingreso_cent,
                    s.motivo_rechazo, s.creado_en, s.producto_id,
                    p.nombre AS producto, p.ingreso_minimo_cent,
                    CONCAT(u.nombres,' ',u.apellidos) AS cliente, u.usuario,
                    t.id AS tarjeta_id, t.ultimos4 AS tarjeta_ultimos4
               FROM solicitudes_tarjeta s
               JOIN productos p ON p.id = s.producto_id
               JOIN usuarios  u ON u.id = s.usuario_id
               LEFT JOIN tarjetas t ON t.id = s.tarjeta_id
              WHERE {$donde}
              ORDER BY s.id DESC
              LIMIT 200",
            $args
        );

        foreach ($solicitudes as &$s) {
            $s['codigo'] = Hashid::codificar((int) $s['id'], 'solicitud_tarjeta');

            // La tarjeta que salió de la solicitud, para poder ir a verla.
            $s['tarjeta_codigo'] = $s['tarjeta_id'] === null
                ? null
                : Hashid::codificar((int) $s['tarjeta_id'], 'tarjeta');

            unset($s['id'], $s['tarjeta_id']);
        }
        unset($s);

        $conteos = BaseDatos::unaFila(
            "SELECT
               SUM(etapa = 'capturada')         AS capturada,
               SUM(etapa = 'revision_atencion') AS revision_atencion,
               SUM(etapa = 'revision_gerencia') AS revision_gerencia,
               SUM(etapa = 'aprobada')          AS aprobada,
               SUM(etapa = 'emitida')           AS emitida,
               SUM(etapa = 'rechazada')         AS rechazada,
               COUNT(*)                         AS todas
             FROM solicitudes_tarjeta"
        ) ?? [];

        $this->render('tarjetas/solicitudes', [
            'titulo'       => 'Solicitudes de tarjeta · CashMan H.A.',
            'encabezado'   => 'Solicitudes de tarjeta',
            'solicitudes'  => $solicitudes,
            'conteos'      => $conteos,
            'etapa'        => $etapa,
            'puedeAtencion'=> Autorizacion::puede('tarjeta.revisar_atencion'),
            'puedeGerencia'=> Autorizacion::puede('tarjeta.revisar_gerencia'),
            'puedeEmitir'  => Autorizacion::puede('tarjeta.emitir'),
            // El catalogo, para poder corregir el producto de una
            // solicitud sin tener que rechazarla y recapturarla.
            'productos'    => $this->catalogoTarjetas(),
            'csrf'         => Csrf::token('solicitud_tarjeta'),
        ]);
    }

    /** Avanza una solicitud. */
    public function avanzar(array $parametros = []): void
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'solicitud_tarjeta');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Solicitud no encontrada.');
        }

        $destino = $this->enrutador->url('tarjetas/solicitudes');

        try {
            $r = (new ServicioSolicitudTarjeta())->avanzar($id, $this->post('observacion'));
        } catch (\RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        /* El número completo de la tarjeta no sale de aquí.
         *
         * El emisor lo devuelve una sola vez para la pantalla de
         * entrega, pero esta respuesta va a una tabla de solicitudes que
         * cualquiera del personal puede tener abierta: lo que se informa
         * es que la tarjeta ya existe y cómo reconocerla. */
        $t = $r['tarjeta'] ?? null;

        if ($t !== null) {
            unset($t['numero']);
            $r['tarjeta'] = $t;
        }

        $this->responderSegunCliente(
            true,
            $t !== null
                ? 'Solicitud aprobada con un límite de $'
                  . number_format(($r['limite_aprobado_cent'] ?? 0) / 100, 2)
                  . '. La tarjeta ' . strtoupper((string) $t['marca'])
                  . ' terminada en ' . $t['ultimos4']
                  . ' ya quedó emitida a nombre del cliente.'
                : 'La solicitud pasó a ' . str_replace('_', ' ', $r['etapa']) . '.',
            $destino,
            $r
        );
    }

    /** Rechaza una solicitud. */
    public function rechazar(array $parametros = []): void
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'solicitud_tarjeta');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Solicitud no encontrada.');
        }

        $destino = $this->enrutador->url('tarjetas/solicitudes');

        try {
            (new ServicioSolicitudTarjeta())->rechazar($id, $this->post('motivo'));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $this->responderSegunCliente(true, 'Solicitud rechazada y notificada al cliente.', $destino);
    }

    /** Corrige los datos de una solicitud en trámite. */
    public function editar(array $parametros = []): void
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'solicitud_tarjeta');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Solicitud no encontrada.');
        }

        $destino = $this->enrutador->url('tarjetas/solicitudes');

        try {
            $r = (new ServicioSolicitudTarjeta())->editar($id, [
                // El mismo espacio de codigos que usa el catalogo: los
                // identificadores no viajan en claro en ninguna pantalla.
                'producto_id' => $this->idDesde($this->post('producto'), 'producto'),
                'marca'       => $this->post('marca'),
                'limite'      => $this->post('limite'),
                'ingreso'     => $this->post('ingreso'),
                'observacion' => $this->post('observacion'),
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        $this->responderSegunCliente(
            true,
            $r['cambios'] === []
                ? 'Observación agregada a la solicitud ' . $r['folio'] . '.'
                : 'Solicitud ' . $r['folio'] . ' corregida.',
            $destino,
            $r
        );
    }

    // -----------------------------------------------------------------

    /**
     * El catalogo de tarjetas publicadas, con su codigo ofuscado.
     *
     * @return list<array<string,mixed>>
     */
    private function catalogoTarjetas(): array
    {
        $filas = BaseDatos::todas(
            'SELECT id, nombre, ingreso_minimo_cent FROM productos
              WHERE tipo = "tarjeta" AND activo = 1 AND publico = 1
              ORDER BY nivel, nombre'
        );

        foreach ($filas as &$f) {
            $f['codigo'] = Hashid::codificar((int) $f['id'], 'producto');
        }
        unset($f);

        return $filas;
    }

    private function compromisos(int $usuarioId): Monto
    {
        $creditos = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(cuota_cent),0) FROM creditos
              WHERE usuario_id = ? AND estado = "vigente"',
            [$usuarioId]
        );

        $pct = max(1, Parametros::entero('tarjeta.pago_minimo_pct', 500));

        $saldo = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(saldo_usado_cent),0) FROM tarjetas
              WHERE usuario_id = ? AND tipo = "credito" AND estado = "activa"',
            [$usuarioId]
        );

        return Monto::deCentavos($creditos + (int) round($saldo * $pct / 10000));
    }
}
