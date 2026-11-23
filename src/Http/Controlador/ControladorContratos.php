<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Infraestructura\Parametros;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Documento\ServicioDocumentos;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Contratos y finiquitos.
 *
 * Cubre tres pantallas del sistema anterior:
 * `contrato-modelo-clientes-solicitudes-nuevas-crediticias` (generar),
 * `mostrar-copia-contrato-creditos-clientes` (consultar la copia) y
 * `finiquito-cancelacion-creditos-clientes` (acreditar la cancelación).
 *
 * La diferencia con el original está en la integridad: allí el contrato
 * era un PDF que se volvía a generar cada vez, sin forma de demostrar
 * que el firmado y el mostrado eran el mismo. Aquí se guarda el
 * SHA-256 del documento en el momento de firmarlo, y al abrirlo se
 * vuelve a calcular: si no coincide, se avisa en lugar de servirlo
 * como si nada.
 */
final class ControladorContratos extends ControladorBase
{
    private const TIPOS = [
        'credito'          => 'Contrato de crédito',
        'cuenta'           => 'Contrato de cuenta',
        'tarjeta'          => 'Contrato de tarjeta',
        'reestructuracion' => 'Convenio de reestructuración',
        'finiquito'        => 'Finiquito de cancelación',
    ];

    /** Documentos que el cliente firma. El finiquito lo firma la casa. */
    private const FIRMABLES = ['credito', 'cuenta', 'tarjeta', 'reestructuracion'];

    public function listar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $todos     = Autorizacion::puede('contrato.generar');

        $condiciones = [];
        $args        = [];

        if (!$todos) {
            $condiciones[] = 'k.usuario_id = ?';
            $args[]        = $usuarioId;
        }

        $vista = $this->get('v', 'todos');
        if ($vista === 'sin_firmar')  { $condiciones[] = 'k.firmado_en IS NULL'; }
        elseif ($vista === 'firmados') { $condiciones[] = 'k.firmado_en IS NOT NULL'; }
        elseif (isset(self::TIPOS[$vista])) {
            $condiciones[] = 'k.tipo = ?';
            $args[]        = $vista;
        }

        $buscar = $todos ? $this->get('q') : '';
        if ($buscar !== '') {
            $condiciones[] = '(cr.folio LIKE ? OR CONCAT(u.nombres," ",u.apellidos) LIKE ?
                               OR cu.numero_cuenta LIKE ?)';
            $like = '%' . $buscar . '%';
            $args[] = $like; $args[] = $like; $args[] = $like;
        }

        $donde = $condiciones === [] ? '1=1' : implode(' AND ', $condiciones);

        $contratos = BaseDatos::todas(
            "SELECT k.*, cr.folio, cu.numero_cuenta,
                    CONCAT(u.nombres,' ',u.apellidos) AS cliente, u.usuario AS cliente_usuario
               FROM contratos k
               JOIN usuarios  u  ON u.id  = k.usuario_id
               LEFT JOIN creditos cr ON cr.id = k.credito_id
               LEFT JOIN cuentas  cu ON cu.id = k.cuenta_id
              WHERE {$donde}
              ORDER BY k.creado_en DESC
              LIMIT 200",
            $args
        );

        foreach ($contratos as &$k) {
            $k['codigo']      = Hashid::codificar((int) $k['id'], 'contrato');
            $k['tipo_nombre'] = self::TIPOS[$k['tipo']] ?? $k['tipo'];
            $k['firmado']     = $k['firmado_en'] !== null;

            /* Un finiquito no se firma: lo extiende la institución para
               acreditar que la deuda se extinguió. Ofrecer la firma al
               cliente invierte quién declara qué a quién. */
            $k['firmable']    = in_array((string) $k['tipo'], self::FIRMABLES, true);
            $k['es_mio']      = (int) $k['usuario_id'] === $usuarioId;
            $k['ip_firma_texto'] = $k['ip_firma'] !== null
                ? ClienteIp::desdeBinaria((string) $k['ip_firma'])
                : '';

            // Si el archivo desapareció del disco hay que decirlo: un
            // enlace que da 404 es peor que un aviso claro.
            $k['existe'] = $k['ruta_pdf'] !== null && is_readable((string) $k['ruta_pdf']);

            unset($k['id'], $k['usuario_id'], $k['credito_id'], $k['cuenta_id'],
                  $k['ruta_pdf'], $k['ip_firma']);
        }
        unset($k);

        // Créditos que todavía no tienen contrato: es lo que hay que
        // generar, y sin esta lista habría que buscarlo a mano.
        $sinContrato = [];
        if (Autorizacion::puede('contrato.generar')) {
            $sinContrato = BaseDatos::todas(
                "SELECT c.id, c.folio, c.monto_cent, c.estado,
                        p.nombre AS producto,
                        CONCAT(u.nombres,' ',u.apellidos) AS cliente
                   FROM creditos c
                   JOIN productos p ON p.id = c.producto_id
                   JOIN usuarios  u ON u.id = c.usuario_id
                  WHERE c.estado IN ('aprobado','desembolsado','vigente')
                    AND NOT EXISTS (
                        SELECT 1 FROM contratos k
                         WHERE k.credito_id = c.id AND k.tipo = 'credito'
                    )
                  ORDER BY c.id DESC
                  LIMIT 50"
            );

            foreach ($sinContrato as &$sc) {
                $sc['codigo'] = Hashid::codificar((int) $sc['id'], 'credito');
                unset($sc['id']);
            }
            unset($sc);
        }

        $conteos = BaseDatos::unaFila(
            $todos
                ? 'SELECT COUNT(*) todos,
                          SUM(firmado_en IS NULL) sin_firmar,
                          SUM(firmado_en IS NOT NULL) firmados
                     FROM contratos'
                : 'SELECT COUNT(*) todos,
                          SUM(firmado_en IS NULL) sin_firmar,
                          SUM(firmado_en IS NOT NULL) firmados
                     FROM contratos WHERE usuario_id = ?',
            $todos ? [] : [$usuarioId]
        ) ?? [];

        $this->render('contratos/listar', [
            'titulo'       => 'Contratos · CashMan H.A.',
            'encabezado'   => $todos ? 'Contratos y finiquitos' : 'Mis contratos',
            'contratos'    => $contratos,
            'sinContrato'  => $sinContrato,
            'conteos'      => $conteos,
            'tipos'        => self::TIPOS,
            'vista'        => $vista,
            'filtros'      => ['q' => $buscar],
            'vistaTodos'   => $todos,
            'puedeGenerar' => Autorizacion::puede('contrato.generar'),
            'puedeFirmar'  => Autorizacion::puede('contrato.firmar'),
            'csrf'         => Csrf::token('contrato'),
        ]);
    }

    /** Genera el contrato de un crédito y lo deja archivado. */
    public function generar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        Autorizacion::exigir('contrato.generar');

        $creditoId = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'credito');
        if ($creditoId === 0) {
            throw new RecursoNoEncontrado('Crédito no encontrado.');
        }

        $credito = BaseDatos::unaFila(
            'SELECT id, folio, usuario_id, estado FROM creditos WHERE id = ?',
            [$creditoId]
        );

        if ($credito === null) {
            throw new RecursoNoEncontrado('Crédito no encontrado.');
        }

        // Un crédito rechazado o cancelado no tiene contrato que emitir.
        if (!in_array($credito['estado'], ['aprobado', 'desembolsado', 'vigente'], true)) {
            Respuesta::error(
                'Solo se emite contrato de un crédito aprobado, desembolsado o vigente. '
                . 'Este está ' . $credito['estado'] . '.',
                422
            );
        }

        $existente = BaseDatos::unValor(
            'SELECT id FROM contratos WHERE credito_id = ? AND tipo = "credito"',
            [$creditoId]
        );

        if ($existente !== null) {
            Respuesta::error(
                'Este crédito ya tiene contrato. Si necesita otro, anula el anterior primero.',
                422
            );
        }

        try {
            $doc = (new ServicioDocumentos())->contratoCredito($creditoId);
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        BaseDatos::ejecutar(
            'INSERT INTO contratos
               (uuid, credito_id, usuario_id, tipo, ruta_pdf, hash_documento)
             VALUES (UUID(),?,?,"credito",?,?)',
            [$creditoId, (int) $credito['usuario_id'], $doc['ruta'], $doc['hash']]
        );
        $id = BaseDatos::ultimoId();

        Auditoria::registrar('contrato.generado', Auditoria::EXITO, $usuarioId,
            'contrato', (string) $id,
            ['credito' => $credito['folio'], 'hash' => $doc['hash']]);

        /* El documento recien generado se abre: un contrato que se
           guarda en el servidor y no aparece por ningun lado es lo
           mismo que no haberlo generado. */
        Respuesta::exito(
            [
                'codigo' => Hashid::codificar($id, 'contrato'),
                'abrir'  => $this->url('contratos/' . Hashid::codificar($id, 'contrato') . '/pdf'),
            ],
            'Contrato generado para el crédito ' . $credito['folio'] . '.'
        );
    }

    /** Genera el finiquito de un crédito cancelado. */
    public function finiquito(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        Autorizacion::exigir('contrato.generar');

        $creditoId = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'credito');
        if ($creditoId === 0) {
            throw new RecursoNoEncontrado('Crédito no encontrado.');
        }

        $credito = BaseDatos::unaFila(
            'SELECT c.folio, c.usuario_id, c.monto_cent,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente, u.correo
               FROM creditos c
               JOIN usuarios u ON u.id = c.usuario_id
              WHERE c.id = ?',
            [$creditoId]
        );
        if ($credito === null) {
            throw new RecursoNoEncontrado('Crédito no encontrado.');
        }

        try {
            // El servicio comprueba que de verdad no quede nada
            // pendiente antes de emitirlo.
            $doc = (new ServicioDocumentos())->finiquitoCredito($creditoId);
        } catch (\RuntimeException $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        BaseDatos::ejecutar(
            'INSERT INTO contratos
               (uuid, credito_id, usuario_id, tipo, ruta_pdf, hash_documento)
             VALUES (UUID(),?,?,"finiquito",?,?)',
            [$creditoId, (int) $credito['usuario_id'], $doc['ruta'], $doc['hash']]
        );
        $id = BaseDatos::ultimoId();

        Auditoria::registrar('finiquito.generado', Auditoria::EXITO, $usuarioId,
            'contrato', (string) $id,
            ['credito' => $credito['folio'], 'hash' => $doc['hash']]);

        $enviado = $this->enviarFiniquito($credito, $doc);

        /* `abrir` hace que el PDF salga en una pestaña nueva sin sacar a
           nadie de la pantalla donde estaba. Emitir un documento y que
           no aparezca por ningún lado era la queja: el archivo existía,
           pero solo en el servidor. */
        Respuesta::exito(
            [
                'codigo' => Hashid::codificar($id, 'contrato'),
                'abrir'  => $this->url('contratos/' . Hashid::codificar($id, 'contrato') . '/pdf'),
            ],
            'Finiquito emitido para el crédito ' . $credito['folio'] . '.'
            . ($enviado
                ? ' Se envió por correo a ' . $credito['correo'] . '.'
                : ' No se pudo enviar por correo; queda disponible para descarga.')
        );
    }

    /**
     * El finiquito, al correo del titular.
     *
     * Va adjunto, no como enlace: el cliente tiene que poder guardarlo
     * y presentarlo sin depender de que esta institución siga teniendo
     * el archivo o de que su sesión siga viva.
     *
     * Un fallo de correo no invalida la emisión —el documento ya está
     * firmado y registrado—, así que se informa y se sigue.
     *
     * @param array<string,mixed> $credito
     * @param array{ruta:string,hash:string,nombre:string} $doc
     */
    private function enviarFiniquito(array $credito, array $doc): bool
    {
        $correo = trim((string) ($credito['correo'] ?? ''));

        if ($correo === '' || !is_file((string) $doc['ruta'])) {
            return false;
        }

        $nombre     = trim((string) ($credito['cliente'] ?? ''));
        $folio      = (string) $credito['folio'];
        $intitucion = Parametros::obtener('institucion.nombre', 'CashMan H.A. S.A. de C.V.');

        $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $cuerpo =
            '<p>' . $e($intitucion) . ' extiende a su favor el <strong>finiquito</strong> '
            . 'del contrato de crédito ' . $e($folio) . ', que se acompaña al presente '
            . 'correo en formato PDF.</p>'

            . '<p>Dicho documento hace constar que la obligación quedó '
            . '<strong>extinguida en su totalidad</strong> y que esta institución no '
            . 'conserva acción alguna de cobro en su contra por capital, intereses, '
            . 'intereses moratorios, seguros ni gastos derivados de ese contrato.</p>'

            . '<p style="font-size:13px;color:#667085">Le recomendamos conservarlo. Es el '
            . 'documento con el que acredita ante terceros que la deuda fue saldada, y '
            . 'el que respalda la actualización de su historial crediticio.</p>';

        try {
            return (new Correo())->enviar(
                $correo,
                'Finiquito de cancelación · ' . $folio,
                PlantillaCorreo::base(
                    'Finiquito de cancelación',
                    'Señor(a) ' . ($nombre !== '' ? $nombre : 'cliente'),
                    $cuerpo,
                    [
                        ['etiqueta' => 'Credito',   'valor' => $folio],
                        ['etiqueta' => 'Documento', 'valor' => 'Finiquito de cancelacion'],
                        ['etiqueta' => 'Emitido',   'valor' => date('d/m/Y H:i')],
                        ['etiqueta' => 'Huella SHA-256',
                         'valor' => substr((string) $doc['hash'], 0, 32) . '…'],
                    ],
                    null,
                    null,
                    null,
                    'La huella SHA-256 permite comprobar que el archivo adjunto es '
                    . 'exactamente el que esta institucion emitio y registro.'
                ),
                "FINIQUITO DE CANCELACION\n\n"
                . "Senor(a) {$nombre}:\n\n"
                . "{$intitucion} extiende a su favor el finiquito del contrato de\n"
                . "credito {$folio}, que se acompana en formato PDF.\n\n"
                . "La obligacion queda extinguida en su totalidad. Esta institucion\n"
                . "no conserva accion alguna de cobro por capital, intereses,\n"
                . "intereses moratorios, seguros ni gastos de ese contrato.\n\n"
                . 'Emitido: ' . date('d/m/Y H:i') . "\n"
                . 'Huella SHA-256: ' . $doc['hash'] . "\n\n"
                . "Conserve este documento.\n",
                $nombre,
                $this->copiasDeSeguimiento(),
                Parametros::obtener('cobranza.remitente', 'CashMan H.A. · Créditos'),
                [[
                    'ruta'   => (string) $doc['ruta'],
                    'nombre' => 'finiquito-' . $folio . '.pdf',
                    'tipo'   => 'application/pdf',
                ]]
            );
        } catch (\Throwable $ex) {
            error_log('[Contratos] finiquito no enviado: ' . $ex->getMessage());
            return false;
        }
    }

    /** @return list<string> */
    private function copiasDeSeguimiento(): array
    {
        $crudo = trim(Parametros::obtener('correo.copia_creditos', ''));

        if ($crudo === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $crudo)),
            static fn(string $c): bool => $c !== '',
        ));
    }

    /**
     * Entrega el PDF.
     *
     * Antes de servirlo se recalcula el hash y se compara con el que
     * quedó guardado. Un documento alterado se sirve igual —el titular
     * tiene derecho a verlo— pero queda registrado como incidencia.
     */
    public function ver(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'contrato');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Contrato no encontrado.');
        }

        $k = BaseDatos::unaFila('SELECT * FROM contratos WHERE id = ?', [$id]);
        if ($k === null) {
            throw new RecursoNoEncontrado('Contrato no encontrado.');
        }

        if ((int) $k['usuario_id'] !== $usuarioId && !Autorizacion::puede('contrato.generar')) {
            Auditoria::registrar('contrato.acceso_denegado', Auditoria::DENEGADO,
                $usuarioId, 'contrato', (string) $id);
            throw new RecursoNoEncontrado('Contrato no encontrado.');
        }

        $ruta = (string) $k['ruta_pdf'];

        if ($ruta === '' || !is_readable($ruta)) {
            throw new RecursoNoEncontrado('El archivo del contrato ya no está disponible.');
        }

        $hashAhora = (string) hash_file('sha256', $ruta);

        if (!hash_equals((string) $k['hash_documento'], $hashAhora)) {
            Auditoria::registrar('contrato.hash_no_coincide', Auditoria::FALLO,
                $usuarioId, 'contrato', (string) $id, [
                    'esperado' => $k['hash_documento'],
                    'actual'   => $hashAhora,
                ]);
        }

        Auditoria::registrar('contrato.consultado', Auditoria::EXITO,
            $usuarioId, 'contrato', (string) $id);

        Respuesta::ver($ruta, 'contrato-' . $id . '.pdf');
    }

    /**
     * Firma del cliente.
     *
     * No es una firma criptográfica: es la constancia de que el titular
     * aceptó, con su nombre escrito a mano, la fecha, la IP desde la
     * que lo hizo y el hash del documento en ese momento. Eso es lo que
     * permite sostener después que firmó *ese* contrato y no otro.
     */
    public function firmar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        Autorizacion::exigir('contrato.firmar');

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'contrato');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Contrato no encontrado.');
        }

        $k = BaseDatos::unaFila('SELECT * FROM contratos WHERE id = ?', [$id]);
        if ($k === null) {
            throw new RecursoNoEncontrado('Contrato no encontrado.');
        }

        // Solo firma el titular. Ni siquiera un administrador firma por
        // otro: eso vaciaría de sentido la firma.
        if ((int) $k['usuario_id'] !== $usuarioId) {
            Respuesta::error('Solo el titular del contrato puede firmarlo.', 403);
        }

        if ($k['firmado_en'] !== null) {
            Respuesta::error('Este contrato ya está firmado.', 422);
        }

        $firma = trim($this->post('firma'));

        $esperado = (string) BaseDatos::unValor(
            'SELECT CONCAT(nombres," ",apellidos) FROM usuarios WHERE id = ?',
            [$usuarioId]
        );

        // El nombre tecleado debe coincidir con el del titular: es la
        // forma de que firmar sea un acto consciente y no un clic.
        if ($this->normalizar($firma) !== $this->normalizar($esperado)) {
            Respuesta::error(
                'Escriba su nombre completo tal como aparece en el contrato: ' . $esperado,
                422
            );
        }

        $ruta = (string) $k['ruta_pdf'];
        if ($ruta === '' || !is_readable($ruta)) {
            Respuesta::error('El archivo del contrato no está disponible: no se puede firmar.', 422);
        }

        $hashAhora = (string) hash_file('sha256', $ruta);

        if (!hash_equals((string) $k['hash_documento'], $hashAhora)) {
            Auditoria::registrar('contrato.firma_bloqueada', Auditoria::DENEGADO,
                $usuarioId, 'contrato', (string) $id,
                ['motivo' => 'el documento cambió desde que se generó']);

            Respuesta::error(
                'El documento no coincide con el que se archivó. No se puede firmar: '
                . 'avisa a soporte para que lo revisen.',
                409
            );
        }

        BaseDatos::ejecutar(
            'UPDATE contratos
                SET firma_cliente = ?, firmado_en = NOW(), ip_firma = ?
              WHERE id = ? AND firmado_en IS NULL',
            [mb_substr($firma, 0, 255), ClienteIp::binaria(), $id]
        );

        Auditoria::registrar('contrato.firmado', Auditoria::EXITO, $usuarioId,
            'contrato', (string) $id, ['hash' => $hashAhora]);

        Respuesta::exito(null, 'Contrato firmado. Ya puede descargar tu copia.');
    }

    /** Compara nombres sin acentos, mayúsculas ni espacios de más. */
    private function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);
        return (string) preg_replace('/\s+/', ' ', $s);
    }
}
