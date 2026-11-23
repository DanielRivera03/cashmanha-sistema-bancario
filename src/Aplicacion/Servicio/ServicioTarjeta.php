<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Folio;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Dominio\Comun\LibroMayor;
use CashMan\Dominio\Comun\Monto;
use CashMan\Dominio\Tarjeta\Pan;
use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Cifrado;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Operaciones de tarjeta de crédito.
 *
 *   · Revelación temporal de datos con token de un solo uso
 *   · Compras diferidas a cuotas, con modalidad tasa cero
 *   · Traslado de saldo entre tarjetas
 *   · Controles de uso (internet, exterior, contactless, congelar)
 *   · Corte mensual
 */
final class ServicioTarjeta
{
    /** Vida del token de revelación: lo justo para leer y copiar. */
    private const REVELACION_SEG = 90;

    public function __construct(
        private ServicioNotificacion $notificaciones = new ServicioNotificacion(),
        private ServicioDesafio $desafios = new ServicioDesafio()
    ) {
    }

    // =================================================================
    // REVELACIÓN DE DATOS
    // =================================================================

    /**
     * Emite un token para ver los datos completos UNA vez.
     *
     * Exige el código de verificación: mostrar un PAN es una operación
     * sensible, no una consulta cualquiera.
     *
     * @return array{token:string, expira_en:string}
     */
    public function solicitarRevelacion(int $tarjetaId, string $codigoOtp): array
    {
        Autorizacion::exigir('tarjeta.ver_datos');
        $usuarioId = Sesion::exigirAutenticado();

        $tarjeta = Autorizacion::exigirTarjeta($tarjetaId, $usuarioId);

        // Solo el titular ve el número completo. Ni el personal con
        // permiso sobre tarjetas de terceros puede hacerlo: no hay
        // ninguna razón operativa legítima para ello.
        if ((int) $tarjeta['usuario_id'] !== $usuarioId) {
            Auditoria::registrar(
                'tarjeta.revelacion_denegada', Auditoria::DENEGADO,
                $usuarioId, 'tarjeta', (string) $tarjetaId
            );
            throw new \RuntimeException('Solo el titular puede ver los datos completos de la tarjeta.');
        }

        if ($tarjeta['estado'] === 'cancelada') {
            throw new \RuntimeException('La tarjeta está cancelada.');
        }

        $this->desafios->consumir(
            $usuarioId, 'alta_tarjeta', $codigoOtp,
            hash('sha256', "revelar|{$tarjetaId}")
        );

        $token  = bin2hex(random_bytes(32));
        $expira = (new \DateTimeImmutable())->modify('+' . self::REVELACION_SEG . ' seconds');
        $sid    = session_id();

        BaseDatos::ejecutar(
            'INSERT INTO tarjeta_revelaciones
               (tarjeta_id, usuario_id, token_hash, sesion_hash, ip, campos, expira_en)
             VALUES (?,?,?,?,?, "pan,cvv,titular", ?)',
            [
                $tarjetaId, $usuarioId,
                hash('sha256', $token),
                hash('sha256', is_string($sid) ? $sid : ''),
                ClienteIp::binaria(),
                $expira->format('Y-m-d H:i:s'),
            ]
        );

        Auditoria::registrar(
            'tarjeta.revelacion_solicitada', Auditoria::EXITO,
            $usuarioId, 'tarjeta', (string) $tarjetaId
        );

        return ['token' => $token, 'expira_en' => $expira->format('c')];
    }

    /**
     * Canjea el token y devuelve los datos. Un solo uso.
     *
     * @return array<string,mixed>
     */
    public function revelar(int $tarjetaId, string $token): array
    {
        $usuarioId = Sesion::exigirAutenticado();
        $sid       = session_id();

        return BaseDatos::enTransaccion(function () use ($tarjetaId, $token, $usuarioId, $sid) {
            $rev = BaseDatos::unaFila(
                'SELECT * FROM tarjeta_revelaciones
                  WHERE token_hash = ? AND tarjeta_id = ? AND usuario_id = ?
                  FOR UPDATE',
                [hash('sha256', $token), $tarjetaId, $usuarioId]
            );

            if ($rev === null) {
                Auditoria::registrar('tarjeta.token_invalido', Auditoria::DENEGADO,
                    $usuarioId, 'tarjeta', (string) $tarjetaId);
                throw new \RuntimeException('Token inválido.');
            }
            if ($rev['usado_en'] !== null) {
                throw new \RuntimeException('Este token ya fue utilizado.');
            }
            if (new \DateTimeImmutable((string) $rev['expira_en']) < new \DateTimeImmutable()) {
                throw new \RuntimeException('El token expiró. Solicítalo de nuevo.');
            }
            // Atado a la sesión: un token robado no sirve desde otro navegador.
            if (!hash_equals((string) $rev['sesion_hash'], hash('sha256', is_string($sid) ? $sid : ''))) {
                Auditoria::registrar('tarjeta.token_otra_sesion', Auditoria::DENEGADO,
                    $usuarioId, 'tarjeta', (string) $tarjetaId);
                throw new \RuntimeException('Token no válido para esta sesión.');
            }

            BaseDatos::ejecutar(
                'UPDATE tarjeta_revelaciones SET usado_en = NOW() WHERE id = ?',
                [$rev['id']]
            );

            $t = BaseDatos::unaFila('SELECT * FROM tarjetas WHERE id = ?', [$tarjetaId]);
            if ($t === null) {
                throw new \RuntimeException('Tarjeta no encontrada.');
            }

            $cifra = new Cifrado(Config::claveBinaria('TARJETA_CLAVE'));
            $pan   = $cifra->descifrar((string) $t['pan_cifrado'], (string) $t['token']);

            Auditoria::registrar('tarjeta.datos_revelados', Auditoria::EXITO,
                $usuarioId, 'tarjeta', (string) $tarjetaId);

            /* El CVV de la tarjeta, el mismo de toda su vida.
             *
             * Antes salía de la hora y cambiaba cada sesenta minutos.
             * Se deriva, no se guarda: ver `Pan::cvvDe()`, donde está
             * explicado por qué un emisor lo hace así. Vale igual para
             * las tarjetas ya emitidas que para las que vengan, porque
             * solo depende de datos que todas tienen. */
            $cvv = Pan::cvvDe(
                (string) $t['token'],
                (int) $t['expira_mes'],
                (int) $t['expira_anio'],
                Config::claveBinaria('TARJETA_PEPPER'),
                (string) $t['marca']
            );

            return [
                'pan'       => trim(chunk_split($pan, 4, ' ')),
                'titular'   => $t['titular'],
                'vence'     => str_pad((string) $t['expira_mes'], 2, '0', STR_PAD_LEFT)
                               . '/' . substr((string) $t['expira_anio'], -2),
                'cvv'       => $cvv,
                'expira_en' => self::REVELACION_SEG,
            ];
        });
    }

    // =================================================================
    // COMPRAS A CUOTAS
    // =================================================================

    /**
     * Difiere un consumo a cuotas.
     *
     * Tasa cero: el cliente paga exactamente lo que consumió, repartido.
     * Con interés: se aplica la tasa de la tarjeta sobre saldos
     * decrecientes, y la tasa queda congelada en el plan aunque
     * después cambie la de la tarjeta.
     *
     * @return array<string,mixed>
     */
    /**
     * Cuántas compras diferidas puede tener vivas una tarjeta.
     *
     * Sin tope, un titular podía diferir cada consumo y acabar con
     * veinte planes corriendo a la vez, cada uno con su calendario.
     * Eso no lo sigue nadie —ni él al leer su estado de cuenta ni quien
     * le llame a cobrar— y deja la línea comprometida a años vista sin
     * que nadie lo haya decidido.
     *
     * Vive en un parámetro para poder moverlo sin tocar el código, y el
     * segmento del cliente lo amplía: un preferente llega a ocho y un
     * patrimonial a doce. Sin cliente se devuelve el tope general, que
     * es lo que corresponde cuando se pregunta en abstracto.
     */
    public static function topePlanes(?int $usuarioId = null): int
    {
        $general = max(1, Parametros::entero('tarjeta.planes_maximos', 5));

        if ($usuarioId === null) {
            return $general;
        }

        $delSegmento = (new ServicioSegmento())->beneficio($usuarioId, 'planes_maximos');

        // El segmento solo puede ampliar, nunca recortar: si mañana se
        // sube el tope general, nadie se queda con menos del que tenía.
        return max($general, (int) ($delSegmento ?? 0));
    }

    /** Planes vigentes de la tarjeta: los que ocupan hueco. */
    public static function planesVigentes(int $tarjetaId): int
    {
        return (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM tarjeta_planes
              WHERE tarjeta_id = ? AND estado = "vigente"',
            [$tarjetaId]
        );
    }

    /**
     * Huecos libres. Se cuentan los vigentes, no los históricos: al
     * liquidar un plan se libera su sitio, que es lo que convierte el
     * tope en un límite y no en una cuota de por vida.
     */
    public static function huecosDePlan(int $tarjetaId): int
    {
        // El tope es el del TITULAR de esta tarjeta: su segmento lo
        // amplía, y preguntarlo en abstracto le quitaría ese beneficio
        // justo donde se nota.
        $titular = (int) (BaseDatos::unValor(
            'SELECT usuario_id FROM tarjetas WHERE id = ?', [$tarjetaId]
        ) ?? 0);

        return max(0, self::topePlanes($titular ?: null) - self::planesVigentes($tarjetaId));
    }

    /**
     * Avisa al titular del plan recién creado, con todo el detalle.
     *
     * Un plan compromete su línea durante meses. El correo lleva el
     * folio, la modalidad, el número y el importe de las cuotas, las
     * fechas de cargo, cómo le queda el cupo y cuántas compras más
     * puede diferir. Antes solo se le decía «queda a 12 cuotas sin
     * intereses», que no permite comprobar nada ni reclamar nada.
     *
     * Nunca lanza: el plan ya está creado y un fallo de correo no puede
     * deshacer una compra.
     */
    public static function avisarPlan(int $planId, string $motivo = ''): bool
    {
        try {
            $p = BaseDatos::unaFila(
                'SELECT pl.*, t.ultimos4, t.marca, t.limite_cent, t.saldo_usado_cent,
                        t.usuario_id, u.correo,
                        CONCAT(u.nombres," ",u.apellidos) AS cliente
                   FROM tarjeta_planes pl
                   JOIN tarjetas t ON t.id = pl.tarjeta_id
                   JOIN usuarios u ON u.id = t.usuario_id
                  WHERE pl.id = ?',
                [$planId]
            );

            if ($p === null || trim((string) $p['correo']) === '') {
                return false;
            }

            $cuotas = BaseDatos::todas(
                'SELECT numero, total_cent, fecha_corte
                   FROM tarjeta_plan_cuotas
                  WHERE plan_id = ? ORDER BY numero',
                [$planId]
            );

            $cero  = $p['tipo'] === 'tasa_cero';
            $tid   = (int) $p['tarjeta_id'];
            $vivos = self::planesVigentes($tid);
            $tope  = self::topePlanes();
            $libres = max(0, $tope - $vivos);

            $dinero = static fn(int $c): string => Monto::deCentavos($c)->formateado();
            $e = static fn(string $x): string => htmlspecialchars($x, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // El calendario completo: es lo que permite comprobar un
            // cargo cuando llegue, y lo que se reclama si no cuadra.
            $tabla = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
                . ' style="margin:18px 0;border-collapse:collapse;font-size:13px">'
                . '<tr><th align="left" style="padding:7px 0;border-bottom:2px solid #e4e7ec;'
                . 'color:#667085;font-weight:600">Cuota</th>'
                . '<th align="left" style="padding:7px 0;border-bottom:2px solid #e4e7ec;'
                . 'color:#667085;font-weight:600">Se carga el</th>'
                . '<th align="right" style="padding:7px 0;border-bottom:2px solid #e4e7ec;'
                . 'color:#667085;font-weight:600">Importe</th></tr>';

            foreach ($cuotas as $c) {
                $tabla .= '<tr>'
                    . '<td style="padding:7px 0;border-bottom:1px solid #f2f4f7">'
                    . (int) $c['numero'] . ' de ' . (int) $p['cuotas'] . '</td>'
                    . '<td style="padding:7px 0;border-bottom:1px solid #f2f4f7">'
                    . $e(date('d/m/Y', strtotime((string) $c['fecha_corte']))) . '</td>'
                    . '<td align="right" style="padding:7px 0;border-bottom:1px solid #f2f4f7;'
                    . 'font-weight:600">' . $e($dinero((int) $c['total_cent'])) . '</td>'
                    . '</tr>';
            }

            $tabla .= '</table>';

            $cuerpo =
                '<p>Su compra en <strong>' . $e((string) ($p['comercio'] ?: 'el comercio'))
                . '</strong> quedó diferida a <strong>' . (int) $p['cuotas']
                . ' cuotas</strong> con cargo a su tarjeta '
                . $e((string) $p['marca']) . ' ••••' . $e((string) $p['ultimos4']) . '.</p>'

                . ($motivo !== '' ? '<p>' . $e($motivo) . '</p>' : '')

                . ($cero
                    ? '<p><strong>Tasa cero.</strong> Pagará exactamente el importe de la '
                      . 'compra, ' . $e($dinero((int) $p['monto_cent'])) . ', repartido en '
                      . (int) $p['cuotas'] . ' cuotas. Este saldo <strong>no devenga '
                      . 'intereses corrientes ni primas de seguro</strong>: queda apartado '
                      . 'de esas dos bases en cada corte, de modo que el total a pagar no '
                      . 'cambia por dejarlo a plazos.</p>'
                    : '<p><strong>Con interés.</strong> El saldo diferido devenga el '
                      . number_format((int) $p['tasa_bp'] / 100, 2) . '% anual. Sobre el '
                      . 'importe de la compra, ' . $e($dinero((int) $p['monto_cent']))
                      . ', se pactaron intereses por ' . $e($dinero((int) $p['interes_total_cent']))
                      . ', de modo que pagará ' . $e($dinero((int) $p['total_cent']))
                      . ' en total.</p>')

                . '<p style="margin-top:22px"><strong>Calendario de cargos</strong></p>'
                . $tabla

                . '<p style="font-size:13px;color:#667085">Cada cuota se carga a su tarjeta '
                . 'en la fecha de corte indicada y aparecerá en el estado de cuenta de ese '
                . 'periodo. Puede cancelar el plan anticipadamente pagando el saldo '
                . 'pendiente; en tasa cero no hay penalización por hacerlo.</p>'

                . '<p style="font-size:13px;color:#667085"><strong>Compras diferidas.</strong> '
                . 'Puede mantener hasta <strong>' . $tope . ' compras diferidas a la vez</strong> '
                . 'en esta tarjeta. Con esta lleva ' . $vivos . ' de ' . $tope . ', así que '
                . ($libres > 0
                    ? 'puede diferir ' . $libres . ' compra(s) más.'
                    : 'no podrá diferir otra hasta que termine de pagar alguna de las '
                      . 'vigentes.')
                . ' Al liquidar un plan se libera su cupo de inmediato.</p>';

            $disponible = self::cupoDisponible(
                (int) $p['limite_cent'], (int) $p['saldo_usado_cent']
            );

            $filas = [
                ['etiqueta' => 'Plan',        'valor' => (string) $p['folio']],
                ['etiqueta' => 'Comercio',    'valor' => (string) ($p['comercio'] ?: '—')],
                ['etiqueta' => 'Tarjeta',     'valor' => $p['marca'] . ' ****' . $p['ultimos4']],
                ['etiqueta' => 'Modalidad',   'valor' => $cero
                    ? 'Tasa cero (sin intereses)'
                    : number_format((int) $p['tasa_bp'] / 100, 2) . '% anual'],
                ['etiqueta' => 'Importe de la compra', 'valor' => $dinero((int) $p['monto_cent'])],
                ['etiqueta' => 'Intereses',   'valor' => $dinero((int) $p['interes_total_cent'])],
                ['etiqueta' => 'TOTAL A PAGAR', 'valor' => $dinero((int) $p['total_cent'])],
                ['etiqueta' => 'Cuotas',      'valor' => (int) $p['cuotas'] . ' de '
                    . $dinero((int) $p['cuota_cent'])],
                ['etiqueta' => 'Primera cuota', 'valor' => $cuotas === [] ? '—'
                    : date('d/m/Y', strtotime((string) $cuotas[0]['fecha_corte']))],
                ['etiqueta' => 'Última cuota', 'valor' => $cuotas === [] ? '—'
                    : date('d/m/Y', strtotime((string) end($cuotas)['fecha_corte']))],
                ['etiqueta' => 'Límite de la tarjeta', 'valor' => $dinero((int) $p['limite_cent'])],
                ['etiqueta' => 'Cupo disponible', 'valor' => $dinero($disponible)],
                ['etiqueta' => 'Compras diferidas', 'valor' => $vivos . ' de ' . $tope],
            ];

            $texto = "COMPRA DIFERIDA A CUOTAS\n\n"
                . 'Senor(a) ' . $p['cliente'] . ":\n\n"
                . 'Su compra en ' . $p['comercio'] . ' quedo diferida a '
                . (int) $p['cuotas'] . " cuotas.\n\n"
                . 'Plan: ' . $p['folio'] . "\n"
                . 'Tarjeta: ' . $p['marca'] . ' ****' . $p['ultimos4'] . "\n"
                . 'Modalidad: ' . ($cero ? 'tasa cero, sin intereses' :
                    number_format((int) $p['tasa_bp'] / 100, 2) . '% anual') . "\n"
                . 'Importe de la compra: ' . $dinero((int) $p['monto_cent']) . "\n"
                . 'Intereses: ' . $dinero((int) $p['interes_total_cent']) . "\n"
                . 'Total a pagar: ' . $dinero((int) $p['total_cent']) . "\n"
                . 'Cuotas: ' . (int) $p['cuotas'] . ' de ' . $dinero((int) $p['cuota_cent']) . "\n\n"
                . "CALENDARIO\n";

            foreach ($cuotas as $c) {
                $texto .= '  ' . str_pad((string) (int) $c['numero'], 2, ' ', STR_PAD_LEFT)
                    . '/' . (int) $p['cuotas'] . '  '
                    . date('d/m/Y', strtotime((string) $c['fecha_corte'])) . '  '
                    . $dinero((int) $c['total_cent']) . "\n";
            }

            $texto .= "\nPuede mantener hasta {$tope} compras diferidas a la vez en esta\n"
                . "tarjeta. Con esta lleva {$vivos} de {$tope}. Al liquidar un plan se\n"
                . "libera su cupo de inmediato.\n\n"
                . 'Cupo disponible: ' . $dinero($disponible) . "\n";

            return (new Correo())->enviar(
                (string) $p['correo'],
                'Compra diferida a ' . (int) $p['cuotas'] . ' cuotas · ' . $p['folio'],
                PlantillaCorreo::base(
                    'Compra diferida a cuotas',
                    'Señor(a) ' . $p['cliente'],
                    $cuerpo,
                    $filas,
                    null,
                    null,
                    null,
                    'Si no reconoce esta compra, comuniquese con nosotros de inmediato '
                    . 'indicando el folio del plan.'
                ),
                $texto,
                (string) $p['cliente']
            );
        } catch (\Throwable $ex) {
            error_log('[Tarjeta] aviso de plan no enviado: ' . $ex->getMessage());
            return false;
        }
    }
    public function diferirCompra(int $movimientoId, int $cuotas, bool $tasaCero = true): array
    {
        Autorizacion::exigir('tarjeta.diferir');
        $usuarioId = Sesion::exigirAutenticado();

        if ($cuotas < 2 || $cuotas > 48) {
            throw new \InvalidArgumentException('El plazo debe estar entre 2 y 48 cuotas.');
        }

        return BaseDatos::enTransaccion(function () use ($movimientoId, $cuotas, $tasaCero, $usuarioId) {
            $mov = BaseDatos::unaFila(
                'SELECT m.*, t.usuario_id, t.id AS tid, t.ultimos4, t.tasa_diferido_bp,
                        t.dia_corte, t.limite_cent, t.saldo_usado_cent
                   FROM tarjeta_movimientos m
                   JOIN tarjetas t ON t.id = m.tarjeta_id
                  WHERE m.id = ? FOR UPDATE',
                [$movimientoId]
            );

            if ($mov === null || (int) $mov['usuario_id'] !== $usuarioId) {
                throw new \RuntimeException('Movimiento no encontrado.');
            }
            if ($mov['tipo'] !== 'consumo') {
                throw new \RuntimeException('Solo se pueden diferir consumos.');
            }
            if ($mov['plan_id'] !== null) {
                throw new \RuntimeException('Este consumo ya está diferido.');
            }
            if ((int) $mov['diferible'] !== 1) {
                throw new \RuntimeException('Este consumo no admite diferimiento.');
            }

            /* El tope. Se comprueba dentro de la transaccion y con la
               fila de la tarjeta ya bloqueada por el FOR UPDATE de
               arriba: comprobarlo fuera permitiria que dos peticiones
               a la vez pasaran las dos. */
            if (self::huecosDePlan((int) $mov['tid']) < 1) {
                throw new \RuntimeException(
                    'Ya tiene ' . self::topePlanes() . ' compras diferidas vigentes en esta '
                    . 'tarjeta, que es el maximo. Al terminar de pagar alguna se libera el '
                    . 'cupo y podra diferir otra.'
                );
            }

            $monto = Monto::deCentavos((int) $mov['importe_cent']);

            // Mínimo razonable por cuota: evita planes de centavos.
            if ($monto->centavos < $cuotas * 500) {
                throw new \RuntimeException(
                    'El monto es muy bajo para ' . $cuotas . ' cuotas (mínimo $5.00 por cuota).'
                );
            }

            // --- Cálculo del plan ------------------------------------
            $tasaBp = $tasaCero ? 0 : (int) $mov['tasa_diferido_bp'];

            if ($tasaCero) {
                $totalCent   = $monto->centavos;
                $interesCent = 0;
            } else {
                // Interés simple sobre saldos decrecientes.
                $tasaMensual = $tasaBp / 10000 / 12;
                $interesCent = 0;
                $saldo       = $monto->centavos;
                $capitalBase = intdiv($monto->centavos, $cuotas);

                for ($i = 0; $i < $cuotas; $i++) {
                    $interesCent += (int) round($saldo * $tasaMensual, 0, PHP_ROUND_HALF_EVEN);
                    $saldo -= $capitalBase;
                }
                $totalCent = $monto->centavos + $interesCent;
            }

            // repartir() garantiza que la suma de las cuotas sea
            // EXACTAMENTE el total: sin centavos perdidos.
            $capitales = Monto::deCentavos($monto->centavos)->repartir($cuotas);
            $intereses = Monto::deCentavos($interesCent)->repartir($cuotas);

            $folio = Folio::unico('PL', 'tarjeta_planes');

            BaseDatos::ejecutar(
                'INSERT INTO tarjeta_planes
                   (uuid, folio, tarjeta_id, movimiento_id, tipo, monto_cent,
                    interes_total_cent, total_cent, cuotas, cuota_cent, tasa_bp, comercio)
                 VALUES (UUID(),?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $folio, (int) $mov['tid'], $movimientoId,
                    $tasaCero ? 'tasa_cero' : 'con_interes',
                    $monto->centavos, $interesCent, $totalCent, $cuotas,
                    intdiv($totalCent, $cuotas), $tasaBp,
                    (string) $mov['comercio'],
                ]
            );
            $planId = BaseDatos::ultimoId();

            // Calendario de cuotas, una por corte mensual.
            $diaCorte = (int) $mov['dia_corte'];
            for ($i = 0; $i < $cuotas; $i++) {
                $base  = (new \DateTimeImmutable('first day of this month'))
                            ->modify('+' . ($i + 1) . ' months');
                $ultimo = (int) $base->format('t');
                $corte  = $base->setDate(
                    (int) $base->format('Y'),
                    (int) $base->format('n'),
                    min($diaCorte, $ultimo)
                );

                BaseDatos::ejecutar(
                    'INSERT INTO tarjeta_plan_cuotas
                       (plan_id, numero, capital_cent, interes_cent, total_cent, fecha_corte)
                     VALUES (?,?,?,?,?,?)',
                    [
                        $planId, $i + 1,
                        $capitales[$i]->centavos,
                        $intereses[$i]->centavos,
                        $capitales[$i]->centavos + $intereses[$i]->centavos,
                        $corte->format('Y-m-d'),
                    ]
                );
            }

            BaseDatos::ejecutar(
                'UPDATE tarjeta_movimientos SET plan_id = ? WHERE id = ?',
                [$planId, $movimientoId]
            );

            // Con interés, el saldo de la tarjeta sube por el interés
            // que se acaba de pactar.
            if ($interesCent > 0) {
                BaseDatos::ejecutar(
                    'UPDATE tarjetas SET saldo_usado_cent = saldo_usado_cent + ? WHERE id = ?',
                    [$interesCent, (int) $mov['tid']]
                );
            }

            self::avisarPlan($planId);

            Auditoria::registrar('tarjeta.compra_diferida', Auditoria::EXITO,
                $usuarioId, 'plan', (string) $planId,
                ['cuotas' => $cuotas, 'tasa_cero' => $tasaCero, 'monto' => $monto->aDecimal()]);

            $this->notificaciones->crear(
                $usuarioId, 'tarjeta.diferido',
                $tasaCero ? 'Compra diferida sin intereses' : 'Compra diferida a cuotas',
                'Tu compra de ' . $monto->formateado() . ' quedó en ' . $cuotas
                . ' cuotas de ' . Monto::deCentavos($capitales[0]->centavos + $intereses[0]->centavos)->formateado(),
                ['folio' => $folio], 'exito'
            );

            return [
                'folio'         => $folio,
                'cuotas'        => $cuotas,
                'tasa_cero'     => $tasaCero,
                'monto'         => $monto->jsonSerialize(),
                'interes'       => Monto::deCentavos($interesCent)->jsonSerialize(),
                'total'         => Monto::deCentavos($totalCent)->jsonSerialize(),
                'primera_cuota' => Monto::deCentavos(
                    $capitales[0]->centavos + $intereses[0]->centavos
                )->jsonSerialize(),
            ];
        });
    }

    /**
     * Simula un plan sin crearlo, para que el cliente compare.
     *
     * @return array<int,array<string,mixed>>
     */
    public function simularDiferido(int $montoCent, int $tasaBp): array
    {
        $opciones = [];

        foreach ([3, 6, 9, 12, 18, 24] as $n) {
            if ($montoCent < $n * 500) {
                continue;
            }

            // Tasa cero
            $cuotaCero = intdiv($montoCent, $n);

            // Con interés
            $tasaMensual = $tasaBp / 10000 / 12;
            $interes     = 0;
            $saldo       = $montoCent;
            $capital     = intdiv($montoCent, $n);
            for ($i = 0; $i < $n; $i++) {
                $interes += (int) round($saldo * $tasaMensual, 0, PHP_ROUND_HALF_EVEN);
                $saldo   -= $capital;
            }

            $opciones[] = [
                'cuotas'            => $n,
                'cuota_cero_cent'   => $cuotaCero,
                'total_cero_cent'   => $montoCent,
                'cuota_int_cent'    => intdiv($montoCent + $interes, $n),
                'total_int_cent'    => $montoCent + $interes,
                'interes_cent'      => $interes,
            ];
        }

        return $opciones;
    }

    // =================================================================
    // TRASLADO DE SALDO
    // =================================================================

    /**
     * Traslada saldo de una tarjeta a otra del mismo titular.
     *
     * Sirve para consolidar deuda en la tarjeta de menor tasa. Se crea
     * un plan en la tarjeta destino y se reduce el saldo de la origen.
     *
     * @return array<string,mixed>
     */
    /**
     * Lo que queda pendiente en planes diferidos.
     *
     * OJO: esto NO se resta del cupo. `saldo_usado_cent` ya es la deuda
     * completa, planes incluidos —el corte lo confirma: aparta el saldo
     * de tasa cero de la base de intereses y de seguros, no del saldo—.
     * Restarlo otra vez descontaría la misma compra dos veces.
     *
     * Sirve para enseñarlo («Saldo diferido») y para saber cuánto de la
     * deuda no devenga intereses.
     */
    public static function diferidoPendiente(int $tarjetaId): int
    {
        return (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(total_cent - pagado_cent),0)
               FROM tarjeta_planes
              WHERE tarjeta_id = ? AND estado = "vigente"',
            [$tarjetaId]
        );
    }

    /**
     * El mismo cálculo, como subconsulta, para los listados.
     *
     * Evita una consulta por fila sin que cada listado tenga que
     * reescribir el criterio —que es como empiezan a divergir—.
     */
    public static function sqlDiferido(string $alias = 't'): string
    {
        return '(SELECT COALESCE(SUM(pl.total_cent - pl.pagado_cent),0)
                   FROM tarjeta_planes pl
                  WHERE pl.tarjeta_id = ' . $alias . '.id
                    AND pl.estado = "vigente")';
    }

    /**
     * Línea libre.
     *
     * Una sola resta, porque `saldo_usado_cent` ya incluye lo diferido.
     * Existe como método —y no suelta por ahí— para que el cupo se
     * calcule igual en las siete pantallas y en los tres sitios donde
     * se autoriza un cargo.
     */
    public static function cupoDisponible(int $limiteCent, int $usadoCent): int
    {
        return max(0, $limiteCent - $usadoCent);
    }
    public function trasladarSaldo(int $origenId, int $destinoId, mixed $monto, int $cuotas, string $codigoOtp): array
    {
        Autorizacion::exigir('tarjeta.trasladar');
        $usuarioId = Sesion::exigirAutenticado();

        if ($origenId === $destinoId) {
            throw new \InvalidArgumentException('Las tarjetas deben ser distintas.');
        }

        $montoVo = Monto::deEntradaUsuario($monto);

        $origen  = Autorizacion::exigirTarjeta($origenId, $usuarioId);
        $destino = Autorizacion::exigirTarjeta($destinoId, $usuarioId);

        // Ambas deben ser del mismo titular: trasladar deuda a la
        // tarjeta de otro sería una transferencia encubierta.
        if ((int) $origen['usuario_id'] !== $usuarioId || (int) $destino['usuario_id'] !== $usuarioId) {
            throw new \RuntimeException('Ambas tarjetas deben ser suyas.');
        }
        if ($origen['tipo'] !== 'credito' || $destino['tipo'] !== 'credito') {
            throw new \RuntimeException('El traslado solo aplica entre tarjetas de crédito.');
        }
        if ($destino['estado'] !== 'activa') {
            throw new \RuntimeException('La tarjeta destino no está activa.');
        }

        $this->desafios->consumir(
            $usuarioId, 'transferencia', $codigoOtp,
            hash('sha256', "traslado|{$origenId}|{$destinoId}|{$montoVo->centavos}")
        );

        return BaseDatos::enTransaccion(function () use (
            $origenId, $destinoId, $montoVo, $cuotas, $usuarioId, $origen, $destino
        ) {
            $o = BaseDatos::unaFila('SELECT * FROM tarjetas WHERE id = ? FOR UPDATE', [$origenId]);
            $d = BaseDatos::unaFila('SELECT * FROM tarjetas WHERE id = ? FOR UPDATE', [$destinoId]);

            if ($montoVo->centavos > (int) $o['saldo_usado_cent']) {
                throw new \RuntimeException(
                    'No puede trasladar más de lo que debe en la tarjeta origen ('
                    . Monto::deCentavos((int) $o['saldo_usado_cent'])->formateado() . ').'
                );
            }

            /* Los cargos del traslado.
             *
             * Trasladar saldo no es gratis en ningún banco: se cobra
             * una comisión sobre lo trasladado y un gasto de gestión,
             * aunque más bajos que los de un adelanto porque al banco
             * le interesa traer deuda de otra tarjeta. Hasta aquí esta
             * operación no cobraba nada, lo que la hacía mejor negocio
             * para el cliente que para el banco y no se parecía a lo
             * que firma nadie.
             *
             * Van a la tarjeta DESTINO, que es donde queda la deuda. */
            $cargos = CargosDeTarjeta::porTraslado($montoVo, (int) $d['usuario_id']);
            $conCargos = $montoVo->centavos + $cargos->total()->centavos;

            $disponibleDestino = self::cupoDisponible(
                (int) $d['limite_cent'],
                (int) $d['saldo_usado_cent']
            );

            /* El cupo tiene que alcanzar para el traslado Y sus cargos.
               Mirar solo el monto dejaría la tarjeta destino por encima
               de su límite en el mismo instante del traslado. */
            if ($conCargos > $disponibleDestino) {
                throw new \RuntimeException(
                    'La tarjeta destino no tiene cupo suficiente. Con la comisión y el '
                    . 'gasto administrativo este traslado suma '
                    . Monto::deCentavos($conCargos)->formateado()
                    . ' y hay ' . Monto::deCentavos(max(0, $disponibleDestino))->formateado()
                    . ' disponibles.'
                );
            }

            $folio = Folio::unico('TR', 'tarjeta_planes');
            $ref   = Folio::unico('TRASL', 'tarjeta_movimientos', 'referencia', '');

            // Origen: baja la deuda
            BaseDatos::ejecutar(
                'UPDATE tarjetas SET saldo_usado_cent = saldo_usado_cent - ? WHERE id = ?',
                [$montoVo->centavos, $origenId]
            );
            BaseDatos::ejecutar(
                'INSERT INTO tarjeta_movimientos
                   (tarjeta_id, tipo, importe_cent, comercio, referencia, estado, diferible)
                 VALUES (?, "pago", ?, "Traslado de saldo (salida)", ?, "liquidada", 0)',
                [$origenId, $montoVo->centavos, $ref . '-O']
            );

            // Destino: sube la deuda, con sus cargos
            BaseDatos::ejecutar(
                'UPDATE tarjetas SET saldo_usado_cent = saldo_usado_cent + ? WHERE id = ?',
                [$conCargos, $destinoId]
            );
            BaseDatos::ejecutar(
                'INSERT INTO tarjeta_movimientos
                   (tarjeta_id, tipo, importe_cent, comercio, referencia, estado, diferible)
                 VALUES (?, "consumo", ?, "Traslado de saldo (entrada)", ?, "liquidada", 0)',
                [$destinoId, $montoVo->centavos, $ref . '-D']
            );
            $movDestino = BaseDatos::ultimoId();

            /* La comisión y el gasto, cada uno en su línea.
               Juntos en una sola, el cliente no sabría cuál le están
               cobrando, y es lo primero que pregunta al verlos. */
            foreach ([
                ['Comisión por traslado de saldo', $cargos->comision->centavos, '-COM'],
                ['Gasto administrativo',           $cargos->gasto->centavos,    '-ADM'],
            ] as [$concepto, $importe, $sufijo]) {
                if ($importe <= 0) {
                    continue;
                }

                BaseDatos::ejecutar(
                    'INSERT INTO tarjeta_movimientos
                       (tarjeta_id, tipo, importe_cent, comercio, categoria,
                        referencia, estado, diferible)
                     VALUES (?, "comision", ?, ?, "comision", ?, "liquidada", 0)',
                    [$destinoId, $importe, $concepto, $ref . $sufijo]
                );
            }

            // Plan de pago en la tarjeta destino, a tasa cero: es el
            // incentivo habitual de un traslado de saldo.
            $cuotas    = max(2, min(48, $cuotas));
            $capitales = $montoVo->repartir($cuotas);

            BaseDatos::ejecutar(
                'INSERT INTO tarjeta_planes
                   (uuid, folio, tarjeta_id, movimiento_id, tipo, monto_cent,
                    interes_total_cent, total_cent, cuotas, cuota_cent, tasa_bp, comercio)
                 VALUES (UUID(),?,?,?, "traslado", ?, 0, ?, ?, ?, 0, ?)',
                [
                    $folio, $destinoId, $movDestino,
                    $montoVo->centavos, $montoVo->centavos,
                    $cuotas, $capitales[0]->centavos,
                    'Traslado desde ••••' . $o['ultimos4'],
                ]
            );
            $planId = BaseDatos::ultimoId();

            $diaCorte = (int) $d['dia_corte'];
            for ($i = 0; $i < $cuotas; $i++) {
                $base   = (new \DateTimeImmutable('first day of this month'))->modify('+' . ($i + 1) . ' months');
                $corte  = $base->setDate((int) $base->format('Y'), (int) $base->format('n'),
                                         min($diaCorte, (int) $base->format('t')));
                BaseDatos::ejecutar(
                    'INSERT INTO tarjeta_plan_cuotas
                       (plan_id, numero, capital_cent, interes_cent, total_cent, fecha_corte)
                     VALUES (?,?,?,0,?,?)',
                    [$planId, $i + 1, $capitales[$i]->centavos, $capitales[$i]->centavos, $corte->format('Y-m-d')]
                );
            }
            BaseDatos::ejecutar('UPDATE tarjeta_movimientos SET plan_id = ? WHERE id = ?',
                [$planId, $movDestino]);

            Auditoria::registrar('tarjeta.traslado_saldo', Auditoria::EXITO, $usuarioId,
                'tarjeta', (string) $destinoId,
                ['origen' => $origenId, 'monto' => $montoVo->aDecimal(),
                 'comision' => $cargos->comision->aDecimal(),
                 'gasto' => $cargos->gasto->aDecimal(), 'cuotas' => $cuotas]);

            /* El aviso dice los cargos.
               Antes decía solo «sin intereses», que era verdad del
               traslado y mentira de la operación: el cliente se
               encontraba la comisión en el estado de cuenta sin que
               nadie se la hubiera nombrado. */
            $this->notificaciones->crear(
                $usuarioId, 'tarjeta.traslado', 'Traslado de saldo realizado',
                'Trasladaste ' . $montoVo->formateado() . ' de ••••' . $o['ultimos4']
                . ' a ••••' . $d['ultimos4'] . ' en ' . $cuotas . ' cuotas sin intereses.'
                . ($cargos->hayAlguno()
                    ? ' A ••••' . $d['ultimos4'] . ' se le cargaron además '
                      . $cargos->comision->formateado() . ' de comisión y '
                      . $cargos->gasto->formateado() . ' de gasto administrativo, '
                      . 'que aparecerán en su próximo estado de cuenta.'
                    : ''),
                [
                    'folio'    => $folio,
                    'comision' => $cargos->comision->formateado(),
                    'gasto'    => $cargos->gasto->formateado(),
                ],
                'exito'
            );

            return [
                'folio'      => $folio,
                'referencia' => $ref,
                'monto'      => $montoVo->jsonSerialize(),
                'comision'   => $cargos->comision->jsonSerialize(),
                'gasto'      => $cargos->gasto->jsonSerialize(),
                'se_le_debe' => Monto::deCentavos($conCargos)->jsonSerialize(),
                'cuotas'     => $cuotas,
                'cuota'      => $capitales[0]->jsonSerialize(),
                'origen'     => '••••' . $o['ultimos4'],
                'destino'    => '••••' . $d['ultimos4'],
            ];
        });
    }

    // =================================================================
    // CONTROLES
    // =================================================================

    /**
     * @param array<string,mixed> $ajustes
     * @return array<string,mixed>
     */
    public function configurar(int $tarjetaId, array $ajustes): array
    {
        Autorizacion::exigir('tarjeta.configurar');
        $usuarioId = Sesion::exigirAutenticado();

        $t = Autorizacion::exigirTarjeta($tarjetaId, $usuarioId);

        $campos = [];
        $args   = [];

        if (isset($ajustes['alias'])) {
            $campos[] = 'alias = ?';
            $args[]   = mb_substr(trim((string) $ajustes['alias']), 0, 40) ?: null;
        }
        foreach (['compras_internet', 'compras_exterior', 'contactless'] as $c) {
            if (isset($ajustes[$c])) {
                $campos[] = "{$c} = ?";
                $args[]   = $ajustes[$c] ? 1 : 0;
            }
        }
        /* Sobregiro de cupo.
         *
         * Aquí estaba el «límite diario de consumo», y no servía de
         * nada: la tarjeta ya tiene un cupo que no se puede pasar, y un
         * tope por día no protege de nada que el cupo no proteja ya. Lo
         * que de verdad hay que poder decidir es qué pasa cuando el
         * consumo toca el cupo: que la compra se caiga por tres dólares,
         * o que pase con una tolerancia y su comisión.
         *
         * La tolerancia tiene techo por parámetro: sin él, «permitir
         * sobregiro» acabaría siendo una línea de crédito paralela que
         * nadie aprobó. */
        if (isset($ajustes['sobregiro_permitido'])) {
            $campos[] = 'sobregiro_permitido = ?';
            $args[]   = $ajustes['sobregiro_permitido'] ? 1 : 0;
        }

        if (isset($ajustes['sobregiro_tolerancia_bp'])) {
            $techo = max(0, Parametros::entero('tarjeta.sobregiro_tolerancia_max_bp', 1500));
            $bp    = (int) $ajustes['sobregiro_tolerancia_bp'];

            if ($bp < 0 || $bp > $techo) {
                throw new \InvalidArgumentException(sprintf(
                    'La tolerancia de sobregiro no puede pasar del %s%% del cupo.',
                    rtrim(rtrim(number_format($techo / 100, 2, '.', ''), '0'), '.')
                ));
            }

            $campos[] = 'sobregiro_tolerancia_bp = ?';
            $args[]   = $bp;
        }

        /* Pago automático al vencimiento.
         *
         * Evitar la mora vale más que cobrarla. La cuenta se comprueba
         * contra el titular de la tarjeta: domiciliar el pago a la
         * cuenta de otra persona sería debitarle a un tercero. */
        if (isset($ajustes['pago_automatico'])) {
            $modo = (string) $ajustes['pago_automatico'];

            if (!in_array($modo, ['no', 'minimo', 'total'], true)) {
                throw new \InvalidArgumentException('Opción de pago automático no válida.');
            }

            $cuentaId = null;

            if ($modo !== 'no') {
                $cuentaId = (int) ($ajustes['pago_automatico_cuenta_id'] ?? 0);

                $suya = $cuentaId > 0 && BaseDatos::unValor(
                    'SELECT 1 FROM cuentas
                      WHERE id = ? AND usuario_id = ? AND es_tecnica = 0 AND estado = "activa"',
                    [$cuentaId, (int) $t['usuario_id']]
                ) !== null;

                if (!$suya) {
                    throw new \InvalidArgumentException(
                        'Elija una de sus cuentas activas para domiciliar el pago.'
                    );
                }
            }

            $campos[] = 'pago_automatico = ?';
            $args[]   = $modo;
            $campos[] = 'pago_automatico_cuenta_id = ?';
            $args[]   = $cuentaId;
        }

        // Aviso por consumo: más útil que un tope, porque informa en vez
        // de estorbar. En cero no avisa.
        if (isset($ajustes['alerta_consumo'])) {
            $alerta = Monto::deEntradaUsuario($ajustes['alerta_consumo']);
            $campos[] = 'alerta_consumo_cent = ?';
            $args[]   = max(0, $alerta->centavos);
        }

        if ($campos === []) {
            throw new \InvalidArgumentException('No hay cambios que aplicar.');
        }

        $args[] = $tarjetaId;
        BaseDatos::ejecutar('UPDATE tarjetas SET ' . implode(', ', $campos) . ' WHERE id = ?', $args);

        Auditoria::registrar('tarjeta.configurada', Auditoria::EXITO, $usuarioId,
            'tarjeta', (string) $tarjetaId, ['cambios' => array_keys($ajustes)]);

        return ['actualizado' => true];
    }

    /**
     * Congela la tarjeta por un rato, sin bloquearla definitivamente.
     *
     * Útil cuando no encuentras la tarjeta: la congelas, y si aparece
     * la reactivas sin tener que pedir una nueva.
     *
     * @return array<string,mixed>
     */
    public function congelar(int $tarjetaId, int $horas): array
    {
        $usuarioId = Sesion::exigirAutenticado();
        $t = Autorizacion::exigirTarjeta($tarjetaId, $usuarioId);

        if ((int) $t['usuario_id'] !== $usuarioId) {
            Autorizacion::exigir('tarjeta.bloquear');
        }

        $horas = max(1, min(720, $horas));   // de 1 hora a 30 días
        $hasta = (new \DateTimeImmutable())->modify("+{$horas} hours");

        BaseDatos::ejecutar(
            'UPDATE tarjetas SET congelada_hasta = ? WHERE id = ?',
            [$hasta->format('Y-m-d H:i:s'), $tarjetaId]
        );

        Auditoria::registrar('tarjeta.congelada', Auditoria::EXITO, $usuarioId,
            'tarjeta', (string) $tarjetaId, ['horas' => $horas]);

        $this->notificaciones->crear(
            (int) $t['usuario_id'], 'tarjeta.congelada', 'Tarjeta congelada',
            'Su tarjeta ••••' . $t['ultimos4'] . ' quedó congelada hasta el '
            . $hasta->format('d/m/Y H:i') . '.',
            null, 'alerta'
        );

        return ['congelada_hasta' => $hasta->format('c'), 'horas' => $horas];
    }

    public function descongelar(int $tarjetaId): array
    {
        $usuarioId = Sesion::exigirAutenticado();
        $t = Autorizacion::exigirTarjeta($tarjetaId, $usuarioId);

        BaseDatos::ejecutar('UPDATE tarjetas SET congelada_hasta = NULL WHERE id = ?', [$tarjetaId]);

        Auditoria::registrar('tarjeta.descongelada', Auditoria::EXITO, $usuarioId,
            'tarjeta', (string) $tarjetaId);

        return ['congelada_hasta' => null];
    }

    /** Simula un consumo, para poder probar el ciclo completo. */
    public function simularConsumo(int $tarjetaId, mixed $monto, string $comercio, string $categoria = 'varios'): array
    {
        $usuarioId = Sesion::exigirAutenticado();
        Autorizacion::exigirTarjeta($tarjetaId, $usuarioId);

        return $this->cobrar($tarjetaId, Monto::deEntradaUsuario($monto), $comercio, $categoria);
    }

    /**
     * Carga un consumo a una tarjeta: el cobro, sin preguntar quién lo pide.
     *
     * Aquí vive UNA sola vez lo que decide si un consumo pasa —estado,
     * congelación, cupo, sobregiro con su comisión por segmento, fondos
     * de la cuenta ligada en débito— y lo que lo deja anotado: el
     * movimiento, el asiento cuando el dinero sale de una cuenta, el
     * aviso al titular y el aviso por monto.
     *
     * Se separó de `simularConsumo()` cuando la red de adquirencia
     * necesitó cobrar una tarjeta tecleada en una tienda, donde no hay
     * sesión ni titular conectado. La alternativa era un segundo juego
     * de reglas, y dos juegos de reglas sobre el mismo dinero acaban
     * discrepando: uno autoriza lo que el otro rechaza, o uno anota un
     * movimiento que el corte del otro no recoge.
     *
     * Quien llama decide si el que pide el cobro tiene derecho a
     * pedirlo. Este método ya no lo comprueba.
     */
    public function cobrar(
        int $tarjetaId,
        Monto $montoVo,
        string $comercio,
        string $categoria = 'varios'
    ): array {
        $t = BaseDatos::unaFila('SELECT * FROM tarjetas WHERE id = ?', [$tarjetaId]);

        if ($t === null) {
            throw new \RuntimeException('Tarjeta no encontrada.');
        }

        return BaseDatos::enTransaccion(function () use ($tarjetaId, $montoVo, $comercio, $categoria, $t) {
            $tt = BaseDatos::unaFila('SELECT * FROM tarjetas WHERE id = ? FOR UPDATE', [$tarjetaId]);

            if ($tt['estado'] === 'inhabilitada') {
                throw new \RuntimeException(
                    'La tarjeta está inhabilitada por superar el límite de crédito. '
                    . 'Para volver a usarla, cubra el excedente hasta quedar dentro de '
                    . 'su línea aprobada; se reactiva sola en cuanto haya cupo disponible.'
                );
            }
            if ($tt['estado'] !== 'activa') {
                throw new \RuntimeException('La tarjeta no está activa.');
            }
            if ($tt['congelada_hasta'] !== null
                && new \DateTimeImmutable((string) $tt['congelada_hasta']) > new \DateTimeImmutable()) {
                throw new \RuntimeException('La tarjeta está congelada.');
            }

            /* Cupo, con sobregiro si el titular lo pidió.
             *
             * Sin tolerancia, una compra se cae por tres dólares y el
             * cliente queda a pie en la caja del supermercado. Con
             * tolerancia, pasa y se cobra una comisión que se le avisa:
             * es su decisión, tomada de antemano y por escrito.
             *
             * El techo del sobregiro sale del cupo, nunca de la nada: la
             * tolerancia es un porcentaje de la línea aprobada, así que
             * ni configurándola al máximo se convierte en otra línea de
             * crédito. */
            $disponible = self::cupoDisponible(
                (int) $tt['limite_cent'],
                (int) $tt['saldo_usado_cent']
            );
            $sobregiro  = 0;
            $comision   = 0;
            $cuentaDeb  = null;

            /* Débito: el dinero es del cliente, no una línea.
               Antes este camino no comprobaba nada cuando la tarjeta no
               era de crédito —y sumaba igual al saldo usado—, de modo
               que habría autorizado cualquier importe sin mirar la
               cuenta ni mover un centavo de ella. */
            if ($tt['tipo'] === 'debito') {
                if ((int) $tt['cuenta_id'] <= 0) {
                    throw new \RuntimeException(
                        'Esta tarjeta de débito no tiene una cuenta ligada. '
                        . 'Comuníquese con nosotros antes de usarla.'
                    );
                }

                $bloq = LibroMayor::bloquearCuentas([(int) $tt['cuenta_id']]);
                $cuentaDeb = $bloq[(int) $tt['cuenta_id']] ?? null;

                if ($cuentaDeb === null || $cuentaDeb['estado'] !== 'activa') {
                    throw new \RuntimeException(
                        'La cuenta ligada a esta tarjeta no está activa.'
                    );
                }

                // Lo que de verdad puede gastar: ni lo retenido ni el
                // minimo de la cuenta son suyos para gastarlos hoy.
                $disponible = max(0,
                    (int) $cuentaDeb['saldo_cent']
                    - (int) $cuentaDeb['saldo_retenido_cent']
                    - (int) ($cuentaDeb['saldo_minimo_cent'] ?? 0)
                );

                if ($montoVo->centavos > $disponible) {
                    throw new \CashMan\Aplicacion\Servicio\FondosInsuficientes(
                        'Fondos insuficientes en la cuenta ligada. Disponible: '
                        . Monto::deCentavos($disponible)->formateado() . '.'
                    );
                }
            }

            if ($tt['tipo'] === 'credito') {
                $tolerancia = (int) $tt['sobregiro_permitido'] === 1
                    ? (int) round((int) $tt['limite_cent'] * (int) $tt['sobregiro_tolerancia_bp'] / 10000)
                    : 0;

                if ($montoVo->centavos > $disponible + $tolerancia) {
                    throw new \RuntimeException(
                        'Cupo insuficiente. Disponible: '
                        . Monto::deCentavos(max(0, $disponible))->formateado()
                        . ($tolerancia > 0
                            ? ' más ' . Monto::deCentavos($tolerancia)->formateado()
                              . ' de sobregiro autorizado.'
                            : '.')
                    );
                }

                if ($montoVo->centavos > $disponible) {
                    $sobregiro = $montoVo->centavos - max(0, $disponible);

                    /* La comisión depende del SEGMENTO del titular, no
                       del parámetro general. `Segmento::BENEFICIOS`
                       promete uno al año sin cargo al preferente y tres
                       al patrimonial, la pantalla de banca preferente
                       lo anuncia con esas palabras, y hasta ahora se
                       cobraba a todo el mundo por igual.

                       El titular es el de la TARJETA y no quien está en
                       sesión: el cargo es suyo aunque la operación la
                       haga otro. */
                    $decisionSob = (new ServicioSobregiro())
                        ->comision((int) $tt['usuario_id'], $sobregiro);

                    $comision = $decisionSob['comision_cent'];
                }
            }

            $ref = Folio::unico('CST', 'tarjeta_movimientos', 'referencia', 'ymd');

            /* Nace liquidado. El importe entra en el saldo de la tarjeta
               en este mismo instante, asi que el cargo esta hecho; y
               como el corte solo lee los liquidados, dejarlo en
               «autorizada» equivalia a que el consumo no apareciera
               nunca en ningun estado de cuenta. */
            BaseDatos::ejecutar(
                'INSERT INTO tarjeta_movimientos
                   (tarjeta_id, tipo, importe_cent, comercio, categoria, referencia, estado)
                 VALUES (?, "consumo", ?, ?, ?, ?, "liquidada")',
                [$tarjetaId, $montoVo->centavos, mb_substr($comercio, 0, 120), $categoria, $ref]
            );
            $movId = BaseDatos::ultimoId();

            /* El sobregiro deja constancia SIEMPRE, se haya cobrado o
               no.

               Antes solo se anotaba cuando había comisión. Con la
               cortesía por categoría eso dejaría al beneficio sin
               consumirse: al perdonar la primera no se anotaría nada,
               el contador del año seguiría en cero y la segunda
               también saldría gratis, y la tercera, y todas.

               Cuando se perdona, el importe es cero: la fila existe
               para contar y para que el cliente lo lea en su estado de
               cuenta, no para cobrar. */
            if ($sobregiro > 0) {
                (new ServicioSobregiro())->registrar(
                    $tarjetaId,
                    $decisionSob ?? ['comision_cent' => $comision, 'perdonada' => false,
                                     'usadas' => 0, 'derecho' => 0],
                    $ref . '-SOB'
                );
            }

            if ($cuentaDeb !== null) {
                /* El dinero sale de la cuenta, con su asiento.
                   Un consumo de débito que solo deja un movimiento de
                   tarjeta es un consumo que nadie pagó: el saldo del
                   cliente seguiría intacto y la contabilidad, descuadrada. */
                $tecnica = (int) BaseDatos::unValor(
                    'SELECT id FROM cuentas WHERE es_tecnica = 1 ORDER BY id LIMIT 1'
                );

                $asiento = LibroMayor::registrar(
                    // `consumo_tarjeta` ya existe en el ENUM de asientos y es
                    // exactamente lo que es: un consumo hecho con tarjeta.
                    'consumo_tarjeta',
                    [
                        ['cuenta_id' => (int) $tt['cuenta_id'],
                         'importe' => $montoVo->negado(),
                         'descripcion' => 'Compra con tarjeta ••••' . $tt['ultimos4']
                                        . ' en ' . mb_substr($comercio, 0, 60)],
                        ['cuenta_id' => $tecnica, 'importe' => $montoVo,
                         'descripcion' => 'Contrapartida de compra con débito'],
                    ],
                    (int) $t['usuario_id'],
                    'debito-' . $tarjetaId . '-' . $ref,
                    'Compra con débito ' . $ref
                );

                // Un consumo de débito se liquida en el acto: no espera
                // corte ni se factura después.
                BaseDatos::ejecutar(
                    'UPDATE tarjeta_movimientos
                        SET estado = "liquidada", diferible = 0, referencia = ?
                      WHERE id = ?',
                    [$asiento['referencia'], $movId]
                );
            } else {
                BaseDatos::ejecutar(
                    'UPDATE tarjetas SET saldo_usado_cent = saldo_usado_cent + ? WHERE id = ?',
                    [$montoVo->centavos + $comision, $tarjetaId]
                );
            }

            $this->notificaciones->crear(
                (int) $t['usuario_id'], 'tarjeta.consumo', 'Consumo con su tarjeta',
                $montoVo->formateado() . ' en ' . $comercio . ' (••••' . $t['ultimos4'] . ')'
                . ($sobregiro > 0
                    ? '. Este consumo usó ' . Monto::deCentavos($sobregiro)->formateado()
                      . ' del sobregiro que usted autorizó, con una comisión de '
                      . Monto::deCentavos($comision)->formateado() . '.'
                    : ''),
                ['referencia' => $ref], $sobregiro > 0 ? 'alerta' : 'info'
            );

            /* Aviso por monto: informa en vez de estorbar.
             *
             * Es lo que sustituye al tope diario. Un tope bloquea compras
             * legítimas sin preguntar; un aviso deja que el titular se
             * entere al instante y reaccione si no fue él. */
            $alerta = (int) ($tt['alerta_consumo_cent'] ?? 0);

            if ($alerta > 0 && $montoVo->centavos >= $alerta) {
                $this->notificaciones->crear(
                    (int) $t['usuario_id'], 'tarjeta.alerta',
                    'Consumo por encima de su monto de aviso',
                    'Se autorizó un consumo de ' . $montoVo->formateado() . ' en ' . $comercio
                    . ', por encima de los ' . Monto::deCentavos($alerta)->formateado()
                    . ' que usted fijó para recibir aviso. Si no lo reconoce, congele la '
                    . 'tarjeta desde la aplicación y con gusto le ayudamos.',
                    ['referencia' => $ref], 'alerta'
                );
            }

            return [
                'movimiento_id' => $movId,
                'referencia'    => $ref,
                'monto'         => $montoVo->jsonSerialize(),
                'comercio'      => $comercio,
                'sobregiro'     => Monto::deCentavos($sobregiro)->jsonSerialize(),
                'comision'      => Monto::deCentavos($comision)->jsonSerialize(),
            ];
        });
    }
}
