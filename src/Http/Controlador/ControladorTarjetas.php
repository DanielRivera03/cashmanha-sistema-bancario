<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\EmisorTarjeta;
use CashMan\Aplicacion\Servicio\ServicioSegmento;

use CashMan\Aplicacion\Servicio\ServicioExtrafinanciamiento;
use CashMan\Aplicacion\Servicio\ServicioTarjeta;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Credito\CapacidadPago;
use CashMan\Infraestructura\Parametros;
use CashMan\Dominio\Tarjeta\Pan;
use CashMan\Http\Paginador;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Cifrado;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Tarjetas.
 *
 * Reglas que se respetan sin excepción (PCI-DSS):
 *   - El PAN se guarda cifrado con AES-256-GCM, nunca en claro.
 *   - Solo se muestran los últimos 4 dígitos.
 *   - El CVV NO se almacena jamás, ni cifrado.
 *   - El número completo se enseña UNA vez, al emitir, y no vuelve a
 *     ser recuperable desde la interfaz.
 *   - Por las URLs circula un token opaco, no el id ni el PAN.
 */
final class ControladorTarjetas extends ControladorBase
{
    /**
     * Listado de tarjetas.
     *
     * Quien tiene `tarjeta.ver_todas` entra directamente al parque de
     * tarjetas de la institución. Antes hacía falta añadir `?todas=1` a
     * mano, así que al personal la pantalla le salía con el formulario
     * de emisión y nada más.
     */
    public function listar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $todas     = Autorizacion::puede('tarjeta.ver_todas');

        // Un gestor puede querer ver solo las suyas: ?mias=1
        if ($todas && $this->get('mias') === '1') {
            $todas = false;
        }

        $condiciones = ['t.estado <> "cancelada"'];
        $args        = [];

        if (!$todas) {
            $condiciones[] = 't.usuario_id = ?';
            $args[]        = $usuarioId;
        }

        $buscar = $todas ? $this->get('q') : '';
        $marca  = $this->get('marca');
        $tipo   = $this->get('tipo');
        $estado = $this->get('estado');

        if ($buscar !== '') {
            $condiciones[] = '(t.ultimos4 LIKE ? OR CONCAT(u.nombres," ",u.apellidos) LIKE ?
                               OR u.usuario LIKE ?)';
            $like = '%' . $buscar . '%';
            $args[] = $like; $args[] = $like; $args[] = $like;
        }
        if (in_array($marca, ['visa', 'mastercard', 'amex', 'discover'], true)) {
            $condiciones[] = 't.marca = ?';
            $args[] = $marca;
        }
        if (in_array($tipo, ['credito', 'debito', 'prepago'], true)) {
            $condiciones[] = 't.tipo = ?';
            $args[] = $tipo;
        }
        if (in_array($estado, ['activa', 'bloqueada', 'vencida', 'pendiente_activacion'], true)) {
            $condiciones[] = 't.estado = ?';
            $args[] = $estado;
        }

        $donde = implode(' AND ', $condiciones);

        $tarjetas = BaseDatos::todas(
            "SELECT t.*, u.nombres, u.apellidos, u.usuario AS titular_usuario,
                    pr.nivel AS producto_nivel, pr.codigo AS producto_codigo,
                    (SELECT COALESCE(SUM(pl.total_cent - pl.pagado_cent),0)
                       FROM tarjeta_planes pl
                      WHERE pl.tarjeta_id = t.id AND pl.estado = 'vigente') AS diferido_cent,
                    /* La cuenta de la que tira una tarjeta de débito.
                       Una de débito no tiene línea ni cupo: lo que el
                       cliente quiere ver ahí es el saldo de su cuenta,
                       que es de donde sale el dinero al pagar. */
                    cu.numero_cuenta AS cuenta_numero,
                    cu.saldo_cent    AS cuenta_saldo_cent
               FROM tarjetas t
               JOIN usuarios u ON u.id = t.usuario_id
               LEFT JOIN productos pr ON pr.id = t.producto_id
               LEFT JOIN cuentas cu ON cu.id = t.cuenta_id
              WHERE {$donde}
              ORDER BY t.estado = 'activa' DESC, t.id DESC
              LIMIT 200",
            $args
        );

        $totales = [
            'tarjetas'   => count($tarjetas),
            'activas'    => 0,
            'credito'    => 0,
            'debito'     => 0,
            'limite'     => 0,
            'usado'      => 0,
            'disponible' => 0,
        ];

        foreach ($tarjetas as &$t) {
            $disponible = ServicioTarjeta::cupoDisponible(
                (int) $t['limite_cent'],
                (int) $t['saldo_usado_cent']
            );

            $t['codigo']          = Hashid::codificar((int) $t['id'], 'tarjeta');
            $t['disponible_cent'] = $disponible;
            $t['cliente']         = trim((string) $t['nombres'] . ' ' . (string) $t['apellidos']);
            $t['es_mia']          = (int) $t['usuario_id'] === $usuarioId;

            if ($t['estado'] === 'activa') { $totales['activas']++; }
            if ($t['tipo'] === 'credito') {
                $totales['credito']++;
                $totales['limite']     += (int) $t['limite_cent'];
                $totales['usado']      += (int) $t['saldo_usado_cent'];
                $totales['disponible'] += $disponible;
            } else {
                $totales['debito']++;
            }

            // Nunca enviar el PAN cifrado ni el hash al navegador.
            unset($t['id'], $t['usuario_id'], $t['pan_cifrado'], $t['pan_hash'],
                  $t['pin_hash'], $t['pasarela_token'], $t['nombres'], $t['apellidos']);
        }
        unset($t);

        $this->render('tarjetas/listar', [
            'titulo'      => $todas ? 'Tarjetas · CashMan H.A.' : 'Mis tarjetas · CashMan H.A.',
            'encabezado'  => $todas ? 'Tarjetas emitidas' : 'Mis tarjetas',
            'tarjetas'    => $tarjetas,
            'totales'     => $totales,
            'vistaTodas'  => $todas,
            'puedeVerTodas' => Autorizacion::puede('tarjeta.ver_todas'),
            'filtros'     => ['q' => $buscar, 'marca' => $marca, 'tipo' => $tipo, 'estado' => $estado],
            'puedeEmitir' => Autorizacion::puede('tarjeta.emitir'),
            // El controlador ya aceptaba un cliente destino, pero el
            // formulario no lo ofrecía: el personal solo podía emitirse
            // tarjetas a sí mismo.
            'clientes'    => Autorizacion::puede('tarjeta.emitir')
                ? BaseDatos::todas(
                    'SELECT u.id, CONCAT(u.nombres," ",u.apellidos) AS nombre, u.usuario
                       FROM usuarios u
                      WHERE u.rol_id = (SELECT id FROM roles WHERE nombre = "Cliente")
                        AND u.estado = "activo"
                      ORDER BY u.nombres'
                  )
                : [],
            'csrf'        => Csrf::token('tarjeta'),
        ]);
    }

    /**
     * Cómo va el pago del mes.
     *
     * El mínimo es del periodo y no del instante: lo que decide si hay
     * mora es cuánto se abonó DESDE el corte, no el saldo que haya hoy.
     * Antes la pantalla topaba el mínimo contra el saldo y no restaba lo
     * ya pagado, así que quien abonó $24 de un mínimo de $43.71 seguía
     * viendo los $43.71 enteros, sin saber si bastaba ni cuánto faltaba.
     *
     * @return array<string,mixed>
     */
    private function estadoPagoDelMes(int $tarjetaId): array
    {
        $corte = BaseDatos::unaFila(
            'SELECT fecha_corte, fecha_limite_pago, pago_minimo_cent,
                    saldo_final_cent, pago_contado_cent
               FROM tarjeta_cortes
              WHERE tarjeta_id = ?
              ORDER BY fecha_corte DESC LIMIT 1',
            [$tarjetaId]
        );

        if ($corte === null) {
            return [
                'hay_corte' => false,
                'minimo_cent' => 0, 'abonado_cent' => 0, 'faltante_cent' => 0,
                'cubierto' => true, 'vencido' => false, 'dias' => null,
            ];
        }

        // Lo abonado despues del corte: eso es lo que cuenta para el
        // minimo de ESTE periodo.
        $abonado = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(importe_cent),0)
               FROM tarjeta_movimientos
              WHERE tarjeta_id = ?
                AND tipo IN ("pago","abono")
                AND DATE(creado_en) > ?',
            [$tarjetaId, $corte['fecha_corte']]
        );

        $minimo    = (int) $corte['pago_minimo_cent'];
        $faltante  = max(0, $minimo - $abonado);
        $limite    = strtotime((string) $corte['fecha_limite_pago']);
        $dias      = (int) floor(($limite - strtotime(date('Y-m-d'))) / 86400);

        return [
            'hay_corte'     => true,
            'fecha_corte'   => (string) $corte['fecha_corte'],
            'fecha_limite'  => (string) $corte['fecha_limite_pago'],
            'minimo_cent'   => $minimo,
            'abonado_cent'  => $abonado,
            'faltante_cent' => $faltante,
            'saldo_corte_cent' => (int) $corte['saldo_final_cent'],
            'contado_cent'  => max(0, (int) $corte['pago_contado_cent'] - $abonado),
            'cubierto'      => $faltante === 0,
            'vencido'       => $faltante > 0 && $dias < 0,
            'dias'          => $dias,
        ];
    }
    /**
     * El efectivo vivo sobre la línea, con su calendario.
     *
     * @return list<array<string,mixed>>
     */
    private function extrasDe(int $tarjetaId): array
    {
        $extras = (new ServicioExtrafinanciamiento())->vigentesDe($tarjetaId);

        foreach ($extras as &$e) {
            $e['cuotas'] = BaseDatos::todas(
                'SELECT numero, capital_cent, interes_cent, total_cent,
                        saldo_posterior_cent, fecha_corte, cobrada_en
                   FROM tarjeta_extra_cuotas
                  WHERE extra_id = ?
                  ORDER BY numero',
                [(int) $e['id']]
            );

            // El ancla del acordeon: el id de base no sale a la plantilla.
            $e['ancla'] = 'exf-' . substr((string) $e['uuid'], 0, 8);
            unset($e['id'], $e['uuid'], $e['usuario_id'], $e['cuenta_id'],
                  $e['asiento_id'], $e['oferta_id'], $e['tarjeta_id']);
        }

        unset($e);

        return $extras;
    }

    public function detalle(array $parametros = []): void
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'tarjeta');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Tarjeta no encontrada.');
        }

        Autorizacion::exigirTarjeta($id);

        // La vista trae los campos calculados (disponible, uso, planes).
        $tarjeta = BaseDatos::unaFila('SELECT * FROM v_tarjetas_detalle WHERE id = ?', [$id]);
        if ($tarjeta === null) {
            throw new RecursoNoEncontrado('Tarjeta no encontrada.');
        }

        /* El extracto: filtrado, ordenado por fecha y paginado.
         *
         * Antes venían sesenta filas ordenadas por `id` —por orden de
         * inserción— y el filtro de año y mes escondía las que no
         * cuadraban. Eso daba dos errores a la vez: el extracto salía
         * revuelto en cuanto hubo movimientos fechados hacia atrás, y
         * filtrar por un mes que hubiera quedado fuera de las sesenta
         * respondía «no hubo movimientos», que es falso.
         *
         * Ahora manda la consulta: el periodo se filtra en SQL, el
         * orden es por fecha —de lo más reciente a lo más antiguo, que
         * es como se lee un extracto— y la página la cuenta el mismo
         * `Paginador` que el resto del sistema. */
        $anio = (int) $this->get('anio', '0');
        $mes  = (int) $this->get('mes', '0');

        $filtro = '';
        $args   = [$id];

        if ($anio >= 2000 && $anio <= 2100) {
            $filtro .= ' AND YEAR(creado_en) = ?';
            $args[]  = $anio;
        }

        if ($mes >= 1 && $mes <= 12) {
            $filtro .= ' AND MONTH(creado_en) = ?';
            $args[]  = $mes;
        }

        $cuantos = (int) BaseDatos::unValor(
            "SELECT COUNT(*) FROM tarjeta_movimientos WHERE tarjeta_id = ?{$filtro}",
            $args
        );

        $pagMov = new Paginador(
            $cuantos,
            $this->get('p', '1'),
            $this->get('pp', (string) Paginador::POR_DEFECTO)
        );

        $movimientos = BaseDatos::todas(
            "SELECT id, tipo, importe_cent, comercio, categoria, referencia, estado,
                    creado_en, plan_id, diferible
               FROM tarjeta_movimientos
              WHERE tarjeta_id = ?{$filtro}
              ORDER BY creado_en DESC, id DESC
              LIMIT {$pagMov->porPagina} OFFSET {$pagMov->desplazamiento}",
            $args
        );

        /* Los periodos que de verdad tienen movimientos, sacados de la
           tabla entera y no de la página que toque: un desplegable
           armado con lo que hay en pantalla cambiaría de opciones al
           pasar de página. */
        $periodosMov = BaseDatos::todas(
            'SELECT DISTINCT YEAR(creado_en) AS anio, MONTH(creado_en) AS mes
               FROM tarjeta_movimientos
              WHERE tarjeta_id = ?
              ORDER BY anio DESC, mes DESC',
            [$id]
        );

        /* Para diferir hace falta mirar TODOS los consumos, no los de
           esta página: si no, la lista de «compras a cuotas» dependería
           de en qué página estuviera el cliente. */
        $movimientosDeLaTarjeta = BaseDatos::todas(
            'SELECT id, tipo, importe_cent, comercio, creado_en, plan_id, diferible
               FROM tarjeta_movimientos
              WHERE tarjeta_id = ? AND tipo = "consumo" AND plan_id IS NULL AND diferible = 1
              ORDER BY creado_en DESC, id DESC',
            [$id]
        );

        // Consumos que todavía se pueden diferir: los de la tarjeta
        // entera, no los de la página que se esté mirando.
        $diferibles = $movimientosDeLaTarjeta;

        $planes = BaseDatos::todas(
            'SELECT * FROM v_planes_diferidos WHERE tarjeta_id = ? ORDER BY estado, id DESC',
            [$id]
        );

        // Otras tarjetas del titular, para el traslado de saldo.
        $otras = BaseDatos::todas(
            'SELECT id, ultimos4, marca, limite_cent, saldo_usado_cent
               FROM tarjetas
              WHERE usuario_id = ? AND id <> ? AND tipo = "credito" AND estado = "activa"',
            [(int) $tarjeta['usuario_id'], $id]
        );
        foreach ($otras as &$o) {
            $o['codigo'] = Hashid::codificar((int) $o['id'], 'tarjeta');
            $o['diferido_cent']   = ServicioTarjeta::diferidoPendiente((int) $o['id']);
            $o['disponible_cent'] = ServicioTarjeta::cupoDisponible(
                (int) $o['limite_cent'], (int) $o['saldo_usado_cent']
            );
            unset($o['id']);
        }

        $tarjeta['codigo']  = Hashid::codificar($id, 'tarjeta');

        /* La cuenta de la que tira una de débito, para poder abrirla
           desde su ficha: el saldo que se enseña ahí vive allí. */
        $tarjeta['cuenta_codigo'] = (int) ($tarjeta['cuenta_id'] ?? 0) > 0
            ? Hashid::codificar((int) $tarjeta['cuenta_id'], 'cuenta')
            : '';

        /* Dos preguntas distintas, y antes había una sola.
         *
         *   `esTitular`   ¿es su tarjeta? Manda en los TEXTOS:
         *                 «su saldo» no se le dice a quien mira
         *                 la tarjeta de otro.
         *
         *   `puedeOperar` ¿puede hacer cosas con ella? Manda en
         *                 las ACCIONES. El administrador puede,
         *                 aunque no sea suya; antes no, y por eso
         *                 veía la ficha a medias. */
        $tarjeta['esTitular']   = (int) $tarjeta['usuario_id'] === Sesion::usuarioId();
        $tarjeta['puedeOperar'] = $tarjeta['esTitular'] || Autorizacion::mandoTotal();
        $tarjeta['esMia']       = $tarjeta['esTitular'];
        $tarjeta['congelada'] = $tarjeta['congelada_hasta'] !== null
            && new \DateTimeImmutable((string) $tarjeta['congelada_hasta']) > new \DateTimeImmutable();
        unset($tarjeta['id']);

        // Cuentas del titular: a una de ellas se domicilia el pago
        // automático, y en el formulario se eligen por nombre, no por id.
        $cuentasTitular = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, p.nombre AS producto
               FROM cuentas c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.es_tecnica = 0 AND c.estado = "activa"
              ORDER BY c.id',
            [(int) $tarjeta['usuario_id']]
        );

        /* El código opaco de cada cuenta, para el adelanto de efectivo.
           El pago automático se queda con el id porque va dentro del
           propio formulario de ajustes de la tarjeta; el adelanto viaja
           por su propia ruta y por ahí no circula un id. */
        foreach ($cuentasTitular as &$ct) {
            $ct['codigo'] = Hashid::codificar((int) $ct['id'], 'cuenta');
        }
        unset($ct);

        $this->render('tarjetas/detalle', [
            'titulo'      => 'Tarjeta ••••' . $tarjeta['ultimos4'] . ' · CashMan H.A.',
            'encabezado'  => 'Tarjeta ••••' . $tarjeta['ultimos4'],
            'tarjeta'     => $tarjeta,
            'movimientos' => $movimientos,
            /* La barra de paginacion de siempre y los periodos que
               de verdad existen, para que el desplegable no ofrezca
               meses vacios. */
            'paginacionMov' => $pagMov->aArreglo(),
            'periodosMov'   => $periodosMov,
            'filtroMov'     => ['anio' => $anio, 'mes' => $mes],
            'diferibles'  => $diferibles,
            'planes'      => $planes,
            'otras'       => $otras,
            'cuentasTitular'     => $cuentasTitular,
            'adelanto'   => [
                'comision_bp' => Parametros::entero('tarjeta.adelanto_comision_bp', 500),
                'minima_cent' => Parametros::entero('tarjeta.adelanto_comision_minima_cent', 500),
                'gasto_cent'  => Parametros::entero('tarjeta.adelanto_gasto_admin_cent', 300),
            ],
            'sobregiroComision'  => Parametros::entero('tarjeta.sobregiro_comision_cent', 500),
            'sobregiroTecho'     => Parametros::entero('tarjeta.sobregiro_tolerancia_max_bp', 1500),
            /* Los estados de cuenta ya emitidos.
               Se listan todos y la pantalla filtra por mes y año: son
               doce al año, no hacen falta ni paginación ni consulta
               nueva por cada filtro. */
            'cortes'      => BaseDatos::todas(
                'SELECT id, periodo_inicio, periodo_fin, fecha_corte, fecha_limite_pago,
                        saldo_final_cent, pago_minimo_cent, movimientos
                   FROM tarjeta_cortes
                  WHERE tarjeta_id = ?
                  ORDER BY fecha_corte DESC',
                [$id]
            ),
            'pagoMes'     => $this->estadoPagoDelMes($id),
            /* El efectivo tomado contra la linea. Cada uno con su
               calendario: lo que el cliente quiere saber no es el
               total, es cuanto le queda y cuando se le cobra. */
            'extras'      => $this->extrasDe($id),
            'topePlanes'  => ServicioTarjeta::topePlanes(),
            'huecosPlan'  => ServicioTarjeta::huecosDePlan($id),
            'csrf'        => Csrf::token('tarjeta'),
        ]);
    }

    public function emitir(array $parametros = []): void
    {
        Autorizacion::exigir('tarjeta.emitir');
        $actorId = Sesion::exigirAutenticado();

        $titularId = $this->idDesde($this->post('cliente'), 'usuario');
        if ($titularId === 0) {
            $titularId = $actorId;
        }

        $titular = BaseDatos::unaFila(
            'SELECT id, nombres, apellidos FROM usuarios WHERE id = ? AND estado = "activo"',
            [$titularId]
        );
        if ($titular === null) {
            Respuesta::error('Cliente no válido.', 422);
        }

        $marca = in_array($this->post('marca'), ['visa', 'mastercard'], true)
            ? $this->post('marca') : 'visa';
        $tipo  = in_array($this->post('tipo'), ['credito', 'debito'], true)
            ? $this->post('tipo') : 'credito';

        try {
            $limite = $tipo === 'credito'
                ? Monto::deEntradaUsuario($this->post('limite', '100000'))
                : Monto::cero();
        } catch (\Throwable $e) {
            Respuesta::error('Límite inválido.', 422);
            return;
        }

        /* El límite se ajusta a la capacidad de pago del cliente.
           Antes se tomaba tal cual del formulario: se podía emitir una
           tarjeta de $5,000 a quien gana $400, y el sobregiro llegaba
           solo. Lo que manda es el pago mínimo que esa persona puede
           cubrir cada mes, descontando lo que ya debe. */
        $capacidadLimite = null;

        if ($tipo === 'credito' && $limite->esPositivo()) {
            $ingreso = Monto::deCentavos((int) BaseDatos::unValor(
                'SELECT COALESCE(ingreso_mensual_cent, 0) FROM detalle_usuarios
                  WHERE usuario_id = ?',
                [$titularId]
            ));

            if ($ingreso->esPositivo()) {
                $pct = max(1, Parametros::entero('tarjeta.pago_minimo_pct', 500));

                $compromisos = Monto::deCentavos(
                    (int) BaseDatos::unValor(
                        'SELECT COALESCE(SUM(cuota_cent),0) FROM creditos
                          WHERE usuario_id = ? AND estado = "vigente"',
                        [$titularId]
                    )
                    + (int) round(((int) BaseDatos::unValor(
                        'SELECT COALESCE(SUM(saldo_usado_cent),0) FROM tarjetas
                          WHERE usuario_id = ? AND tipo = "credito" AND estado = "activa"',
                        [$titularId]
                    )) * $pct / 10000)
                );

                $maximo = CapacidadPago::limiteTarjeta(
                    $ingreso,
                    $compromisos,
                    null,
                    (new ServicioSegmento())->vecesIngreso($usuarioId)
                );
                $capacidadLimite = $maximo->centavos;

                if (!$maximo->esPositivo()) {
                    Respuesta::error(
                        'Los compromisos actuales del cliente no dejan margen para una '
                        . 'tarjeta de crédito. Revise su ingreso declarado.',
                        422
                    );
                    return;
                }

                if ($limite->mayorQue($maximo)) {
                    // Se recorta en vez de rechazar: el cliente sí puede
                    // tener tarjeta, solo que con otro límite.
                    $limite = $maximo;
                }
            }
        }

        /* La emisión vive en un solo sitio.
         *
         * Antes este método generaba el PAN y hacía el INSERT por su
         * cuenta, y la aprobación de una solicitud no emitía nada. Dos
         * caminos distintos para crear una tarjeta eran dos juegos de
         * reglas que se iban separando: el formulario ponía día de corte
         * y tasa por defecto, y la solicitud —cuando se emitía a
         * mano— lo que tocara ese día. */
        try {
            $t = (new EmisorTarjeta())->emitir(
                $titularId,
                $marca,
                $tipo,
                $limite,
                null,
                $actorId,
                ['origen' => 'formulario']
            );
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 500);
            return;
        }

        Respuesta::exito([
            'codigo'   => $t['codigo'],
            // El número completo se devuelve UNA sola vez.
            'numero'   => $t['numero'],
            'marca'    => $t['marca'],
            'titular'  => $t['titular'],
            'vence'    => $t['vence'],
            'limite'   => $t['limite'],
            'aviso'    => $t['aviso'],
        ], 'Tarjeta emitida.');
    }

    public function bloquear(array $parametros = []): void
    {
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'tarjeta');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Tarjeta no encontrada.');
        }

        // El titular puede bloquear la suya; el personal necesita permiso.
        $tarjeta = Autorizacion::exigirTarjeta($id);
        $actorId = Sesion::exigirAutenticado();

        if ((int) $tarjeta['usuario_id'] !== $actorId) {
            Autorizacion::exigir('tarjeta.bloquear');
        }

        $nuevoEstado = $tarjeta['estado'] === 'bloqueada' ? 'activa' : 'bloqueada';

        // Reactivar una tarjeta sí exige permiso explícito siempre.
        if ($nuevoEstado === 'activa' && (int) $tarjeta['usuario_id'] !== $actorId) {
            Autorizacion::exigir('tarjeta.bloquear');
        }

        BaseDatos::ejecutar('UPDATE tarjetas SET estado = ? WHERE id = ?', [$nuevoEstado, $id]);

        Auditoria::registrar(
            'tarjeta.' . ($nuevoEstado === 'bloqueada' ? 'bloqueada' : 'reactivada'),
            Auditoria::EXITO,
            $actorId,
            'tarjeta',
            (string) $id
        );

        (new \CashMan\Aplicacion\Servicio\ServicioNotificacion())->crear(
            (int) $tarjeta['usuario_id'],
            'tarjeta.estado',
            $nuevoEstado === 'bloqueada' ? 'Tarjeta bloqueada' : 'Tarjeta reactivada',
            'Su tarjeta terminada en ' . $tarjeta['ultimos4'] . ' ahora está ' . $nuevoEstado . '.',
            null,
            $nuevoEstado === 'bloqueada' ? 'alerta' : 'exito'
        );

        Respuesta::exito(['estado' => $nuevoEstado], 'Estado actualizado.');
    }

    /**
     * Pantalla de pago de una tarjeta de crédito.
     *
     * Hasta ahora el pago estaba escondido dentro del detalle de la
     * tarjeta y solo admitía debitar de una cuenta. Faltaba lo que el
     * cliente de verdad necesita al recibir su estado de cuenta: ver su
     * pago mínimo, su saldo total y su fecha límite, y poder pagar desde
     * ahí, con su cuenta o con la tarjeta de otro banco.
     *
     * La misma pantalla sirve al cliente y a quien lo atiende: cambia a
     * quién pertenece la tarjeta, no lo que se puede hacer con ella.
     */
    public function formularioPago(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('tarjeta.pagar');

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'tarjeta');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Tarjeta no encontrada.');
        }

        $tarjeta = Autorizacion::exigirTarjeta($id);

        if ((string) $tarjeta['tipo'] !== 'credito') {
            throw new RecursoNoEncontrado(
                'Solo las tarjetas de crédito tienen saldo que pagar.'
            );
        }

        // Último corte: de ahí salen el mínimo y la fecha límite. Si la
        // tarjeta todavía no tiene corte, se paga contra el saldo usado.
        $corte = BaseDatos::unaFila(
            'SELECT fecha_corte, fecha_limite_pago, saldo_final_cent,
                    pago_minimo_cent, intereses_cent, seguro_vida_cent,
                    seguro_empleo_cent, consumos_cent, pagos_cent, estado
               FROM tarjeta_cortes
              WHERE tarjeta_id = ?
              ORDER BY fecha_corte DESC LIMIT 1',
            [$id]
        );

        $saldo = (int) $tarjeta['saldo_usado_cent'];

        // El mínimo nunca puede pasar del saldo: si el cliente ya abonó
        // algo después del corte, pedirle el mínimo completo sería
        // cobrarle de más.
        /* Lo que FALTA del minimo, no el minimo entero.
           Topar contra el saldo no basta: quien ya abono algo despues
           del corte seguia viendo el minimo completo y no sabia si con
           lo pagado bastaba. */
        $pagoMes = $this->estadoPagoDelMes($id);
        $minimo  = min((int) $pagoMes['faltante_cent'], $saldo);

        // Cuentas con las que puede pagar.
        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, c.saldo_cent, c.saldo_retenido_cent,
                    p.nombre AS producto, p.saldo_minimo_cent
               FROM cuentas c
               JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.es_tecnica = 0 AND c.estado = "activa"
              ORDER BY c.numero_cuenta',
            [(int) $tarjeta['usuario_id']]
        );

        foreach ($cuentas as &$c) {
            $c['disponible_cent'] = max(0,
                (int) $c['saldo_cent']
                - (int) $c['saldo_retenido_cent']
                - (int) $c['saldo_minimo_cent']
            );
            $c['codigo'] = Hashid::codificar((int) $c['id'], 'cuenta');
            unset($c['id']);
        }
        unset($c);

        // Movimientos desde el último corte: lo que aún no está facturado.
        $posteriores = BaseDatos::todas(
            'SELECT tipo, importe_cent, comercio, creado_en
               FROM tarjeta_movimientos
              WHERE tarjeta_id = ? AND corte_id IS NULL
              ORDER BY id DESC LIMIT 10',
            [$id]
        );

        $this->render('tarjetas/pago', [
            'titulo'      => 'Pagar tarjeta · CashMan H.A.',
            'encabezado'  => 'Pago de tarjeta de crédito',
            'tarjeta'     => [
                'codigo'   => Hashid::codificar($id, 'tarjeta'),
                'ultimos4' => $tarjeta['ultimos4'],
                'marca'    => $tarjeta['marca'],
                'titular'  => $tarjeta['titular'],
                'limite_cent' => (int) $tarjeta['limite_cent'],
                'saldo_cent'  => $saldo,
                'disponible_cent' => ServicioTarjeta::cupoDisponible(
                    (int) $tarjeta['limite_cent'], $saldo
                ),
                'es_propia'   => (int) $tarjeta['usuario_id'] === $actorId,
            ],
            'corte'       => $corte,
            'minimo_cent' => $minimo,
            'pagoMes'     => $pagoMes,
            'cuentas'     => $cuentas,
            'posteriores' => $posteriores,
            'pasarela'    => \CashMan\Infraestructura\Pasarela\Stripe::habilitado(),
            'csrf'        => Csrf::token('tarjeta'),
            'csrfPasarela'=> Csrf::token('pasarela'),
        ]);
    }

    public function pagar(array $parametros = []): void
    {
        Autorizacion::exigir('tarjeta.pagar');

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'tarjeta');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Tarjeta no encontrada.');
        }

        $tarjeta = Autorizacion::exigirTarjeta($id);
        $actorId = Sesion::exigirAutenticado();

        $cuentaId = $this->idDesde($this->post('cuenta'), 'cuenta');
        if ($cuentaId === 0) {
            Respuesta::error('Seleccione la cuenta de cargo.', 422);
        }

        $cuenta = Autorizacion::exigirCuenta($cuentaId, $actorId);
        Autorizacion::exigirCuentaOperable($cuenta);

        try {
            $monto = Monto::deEntradaUsuario($this->post('monto'));
        } catch (\Throwable $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        $deuda = Monto::deCentavos((int) $tarjeta['saldo_usado_cent']);
        if ($monto->mayorQue($deuda)) {
            Respuesta::error(
                'El monto supera la deuda de la tarjeta (' . $deuda->formateado() . ').',
                422
            );
        }

        /* Quedarse sin saldo no es un error del programa.
         *
         * Antes esta excepción salía sin atrapar: PHP respondía con su
         * página de error en HTML, el navegador intentaba leerla como
         * JSON, fallaba, y el cliente veía «Sin conexión — No se pudo
         * contactar al servidor». El servidor estaba perfectamente; lo
         * que pasaba es que no le alcanzaba el dinero, que es justo lo
         * que había que decirle.
         *
         * Se atrapa aquí fuera de la transacción porque dentro ya hizo
         * ROLLBACK: a estas alturas no se movió nada. */
        /* El abono lo hace `ServicioAbonoTarjeta`, no este controlador.
         *
         * Esta lógica vivía aquí dentro, y mientras éste era el único
         * camino que pagaba tarjetas, bien. Al llegar el débito
         * automático habría habido DOS, y dos implementaciones de un
         * movimiento de dinero no se quedan iguales: una se corrige, la
         * otra no, y un día el pago hecho a mano y el automático dejan
         * la tarjeta en estados distintos.
         *
         * La bitácora también se registra allí, por lo mismo. */
        try {
            $resultado = (new \CashMan\Aplicacion\Servicio\ServicioAbonoTarjeta())->abonar(
                $id,
                $cuentaId,
                $monto,
                $actorId,
                'Pago desde cuenta'
            );
        } catch (\CashMan\Aplicacion\Servicio\FondosInsuficientes $e) {
            Respuesta::error(
                $e->getMessage()
                . ' Puede abonar un monto menor, o pagarlo con la tarjeta de otro banco '
                . 'desde esta misma pantalla.',
                422
            );
            return;
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        /* La bitácora la deja el servicio: registrarla también aquí
           anotaría dos veces el mismo pago. */

        // El efectivo no pasó por ninguna caja —es un débito a cuenta—,
        // pero si lo está haciendo un cajero en ventanilla con su turno
        // abierto, el movimiento se anota igual para que su corte cuadre.
        (new \CashMan\Aplicacion\Servicio\ServicioSesionCaja())->anotarSiHayTurno(
            'pago_tarjeta',
            0,
            'Pago de tarjeta ••••' . $tarjeta['ultimos4'] . ' con débito a cuenta',
            [
                'cuenta_id'  => $cuentaId,
                'cliente_id' => (int) $tarjeta['usuario_id'],
                'tarjeta_id' => $id,
                'asiento_id' => $resultado['asiento_id'] ?? null,
            ]
        );

        (new \CashMan\Aplicacion\Servicio\ServicioNotificacion())->crear(
            (int) $tarjeta['usuario_id'],
            'tarjeta.pago',
            'Pago aplicado a su tarjeta',
            'Con mucho gusto le confirmamos que se aplicaron ' . $monto->formateado()
            . ' a su tarjeta ••••' . $tarjeta['ultimos4'] . '. Gracias por su pago.',
            [
                'referencia' => $resultado['referencia'],
                'monto'      => $monto->formateado(),
                'tarjeta'    => '••••' . $tarjeta['ultimos4'],
            ],
            'exito'
        );

        Respuesta::exito([
            'referencia' => $resultado['referencia'],
            'monto'      => $monto->jsonSerialize(),
        ], 'Pago aplicado correctamente.');
    }
}
