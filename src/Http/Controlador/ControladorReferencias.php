<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Referencias personales y laborales.
 *
 * Replica el `gestor-referencias-personales-clientes` del sistema
 * anterior, con una diferencia de fondo: las referencias pertenecen al
 * **expediente del cliente**, no al trámite.
 *
 * Antes colgaban del crédito o de la solicitud con borrado en cascada,
 * así que al cancelar un trámite se perdían y había que volver a
 * pedirlas y a verificarlas por teléfono. Alguien que responde por un
 * cliente lo sigue haciendo en la solicitud siguiente: el trabajo de
 * conseguirlas y comprobarlas no se tira.
 *
 * De ahí el reparto de competencias:
 *
 *   * Se registran, modifican y eliminan desde el expediente.
 *   * Una solicitud las consulta y puede dejar constancia de cuáles
 *     usó, pero no las borra.
 */
final class ControladorReferencias extends ControladorBase
{
    private const TIPOS = [
        'personal'  => 'Personal',
        'laboral'   => 'Laboral',
        'familiar'  => 'Familiar',
        'comercial' => 'Comercial',
    ];

    /** Mínimo que pide la política de crédito antes de evaluar. */
    private const MINIMO_EXIGIDO = 2;

    /* =================================================================
       EXPEDIENTE — aquí se gestionan
       ================================================================= */

    public function expediente(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $titular = $this->titularDe($parametros, $usuarioId);

        $referencias = BaseDatos::todas(
            'SELECT * FROM v_referencias_expediente
              WHERE usuario_id = ?
              ORDER BY verificada DESC, tipo, apellidos',
            [$titular['id']]
        );

        $verificadas = 0;
        foreach ($referencias as &$r) {
            $r['codigo']      = Hashid::codificar((int) $r['id'], 'referencia');
            $r['tipo_nombre'] = self::TIPOS[$r['tipo']] ?? $r['tipo'];
            if ((int) $r['verificada'] === 1) { $verificadas++; }
            unset($r['id'], $r['usuario_id'], $r['solicitud_id'], $r['credito_id']);
        }
        unset($r);

        $esMio = (int) $titular['id'] === $usuarioId;

        $this->render('referencias/expediente', [
            'titulo'      => 'Referencias · ' . $titular['nombre'],
            'encabezado'  => $esMio ? 'Mis referencias' : 'Referencias de ' . $titular['nombre'],
            'titular'     => $titular,
            'referencias' => $referencias,
            'verificadas' => $verificadas,
            'minimo'      => self::MINIMO_EXIGIDO,
            'tipos'       => self::TIPOS,
            'esMio'       => $esMio,
            'puedeRegistrar' => Autorizacion::puede('referencia.registrar')
                              && ($esMio || Autorizacion::puede('cliente.editar_expediente')),
            'puedeVerificar' => Autorizacion::puede('referencia.verificar'),
            'csrf'        => Csrf::token('referencia'),
        ]);
    }

    public function registrar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        Autorizacion::exigir('referencia.registrar');

        $titular = $this->titularDe($parametros, $usuarioId);
        $id      = (int) $titular['id'];

        if ($id !== $usuarioId && !Autorizacion::puede('cliente.editar_expediente')) {
            Respuesta::error('No puede tocar el expediente de otro cliente.', 403);
        }

        $datos = $this->validar($id, 0);

        BaseDatos::ejecutar(
            'INSERT INTO referencias
               (usuario_id, tipo, nombres, apellidos, empresa, profesion,
                telefono, celular, correo, direccion, parentesco, anios_conocer)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $id, $datos['tipo'], $datos['nombres'], $datos['apellidos'],
                $datos['empresa'], $datos['profesion'], $datos['telefono'],
                $datos['celular'], $datos['correo'], $datos['direccion'],
                $datos['parentesco'], $datos['anios'],
            ]
        );
        $refId = BaseDatos::ultimoId();

        Auditoria::registrar('referencia.registrada', Auditoria::EXITO, $usuarioId,
            'usuario', (string) $id,
            ['tipo' => $datos['tipo'], 'referencia' => $datos['nombres'] . ' ' . $datos['apellidos']]);

        $total = (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM referencias WHERE usuario_id = ?', [$id]
        );

        Respuesta::exito(
            ['codigo' => Hashid::codificar($refId, 'referencia'), 'total' => $total],
            $total < self::MINIMO_EXIGIDO
                ? 'Referencia registrada en el expediente. Falta al menos '
                  . (self::MINIMO_EXIGIDO - $total) . ' para poder evaluar una solicitud.'
                : 'Referencia registrada en el expediente.'
        );
    }

    /** Modifica una referencia existente. */
    public function modificar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        Autorizacion::exigir('referencia.registrar');

        $refId = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'referencia');
        if ($refId === 0) {
            throw new RecursoNoEncontrado('Referencia no encontrada.');
        }

        $antes = BaseDatos::unaFila('SELECT * FROM referencias WHERE id = ?', [$refId]);
        if ($antes === null) {
            throw new RecursoNoEncontrado('Referencia no encontrada.');
        }

        $dueno = (int) $antes['usuario_id'];

        if ($dueno !== $usuarioId && !Autorizacion::puede('cliente.editar_expediente')) {
            Respuesta::error('No puede modificar una referencia de otro expediente.', 403);
        }

        $datos = $this->validar($dueno, $refId);

        BaseDatos::ejecutar(
            'UPDATE referencias
                SET tipo = ?, nombres = ?, apellidos = ?, empresa = ?, profesion = ?,
                    telefono = ?, celular = ?, correo = ?, direccion = ?,
                    parentesco = ?, anios_conocer = ?
              WHERE id = ?',
            [
                $datos['tipo'], $datos['nombres'], $datos['apellidos'],
                $datos['empresa'], $datos['profesion'], $datos['telefono'],
                $datos['celular'], $datos['correo'], $datos['direccion'],
                $datos['parentesco'], $datos['anios'], $refId,
            ]
        );

        // Cambiar los datos de contacto invalida la verificación: lo que
        // se comprobó fue a esa persona en ese teléfono.
        $contactoCambio = $datos['telefono'] !== (string) $antes['telefono']
                       || $datos['celular']  !== (string) ($antes['celular'] ?? '')
                       || $datos['nombres']  !== (string) $antes['nombres']
                       || $datos['apellidos'] !== (string) $antes['apellidos'];

        if ($contactoCambio && (int) $antes['verificada'] === 1) {
            BaseDatos::ejecutar(
                'UPDATE referencias
                    SET verificada = 0, verificada_por = NULL, verificada_en = NULL,
                        notas = CONCAT(COALESCE(notas,""), " [verificación anulada: cambiaron los datos de contacto]")
                  WHERE id = ?',
                [$refId]
            );
        }

        Auditoria::registrar('referencia.modificada', Auditoria::EXITO, $usuarioId,
            'referencia', (string) $refId, [
                'antes'   => ['nombre' => $antes['nombres'] . ' ' . $antes['apellidos'],
                              'telefono' => $antes['telefono']],
                'despues' => ['nombre' => $datos['nombres'] . ' ' . $datos['apellidos'],
                              'telefono' => $datos['telefono']],
                'verificacion_anulada' => $contactoCambio && (int) $antes['verificada'] === 1,
            ]);

        Respuesta::exito(
            null,
            $contactoCambio && (int) $antes['verificada'] === 1
                ? 'Referencia actualizada. Al cambiar los datos de contacto se anuló '
                  . 'su verificación: hay que volver a comprobarla.'
                : 'Referencia actualizada.'
        );
    }

    public function verificar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        Autorizacion::exigir('referencia.verificar');

        $refId = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'referencia');
        if ($refId === 0) {
            throw new RecursoNoEncontrado('Referencia no encontrada.');
        }

        $r = BaseDatos::unaFila(
            'SELECT id, usuario_id, nombres, apellidos, verificada FROM referencias WHERE id = ?',
            [$refId]
        );
        if ($r === null) {
            throw new RecursoNoEncontrado('Referencia no encontrada.');
        }

        // Nadie verifica sus propias referencias: perdería el sentido.
        if ((int) $r['usuario_id'] === $usuarioId) {
            Respuesta::error(
                'No puede verificar sus propias referencias: eso lo hace el personal '
                . 'contactándolas.',
                403
            );
        }

        $nuevo = (int) $r['verificada'] === 1 ? 0 : 1;
        $notas = mb_substr($this->post('notas'), 0, 500);

        if ($nuevo === 1 && mb_strlen(trim($notas)) < 10) {
            Respuesta::error(
                'Anota qué confirmó la referencia al contactarla (mínimo 10 caracteres).',
                422
            );
        }

        BaseDatos::ejecutar(
            'UPDATE referencias
                SET verificada = ?, verificada_por = ?,
                    verificada_en = IF(? = 1, NOW(), NULL), notas = ?
              WHERE id = ?',
            [$nuevo, $nuevo === 1 ? $usuarioId : null, $nuevo,
             $nuevo === 1 ? $notas : null, $refId]
        );

        Auditoria::registrar(
            $nuevo === 1 ? 'referencia.verificada' : 'referencia.verificacion_retirada',
            Auditoria::EXITO, $usuarioId, 'referencia', (string) $refId,
            ['referencia' => $r['nombres'] . ' ' . $r['apellidos']]
        );

        Respuesta::exito(
            ['verificada' => $nuevo === 1],
            $nuevo === 1 ? 'Referencia verificada.' : 'Verificación retirada.'
        );
    }

    /**
     * Elimina una referencia del expediente.
     *
     * Solo desde el expediente, nunca al borrar un trámite: ese es el
     * cambio respecto a cómo estaba antes.
     */
    public function eliminar(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        Autorizacion::exigir('referencia.registrar');

        $refId = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'referencia');
        if ($refId === 0) {
            throw new RecursoNoEncontrado('Referencia no encontrada.');
        }

        $r = BaseDatos::unaFila('SELECT * FROM referencias WHERE id = ?', [$refId]);
        if ($r === null) {
            throw new RecursoNoEncontrado('Referencia no encontrada.');
        }

        $dueno = (int) $r['usuario_id'];

        if ($dueno !== $usuarioId && !Autorizacion::puede('cliente.editar_expediente')) {
            Respuesta::error('No puede eliminar una referencia de otro expediente.', 403);
        }

        // Si respaldó un crédito vigente, forma parte del expediente que
        // justificó esa operación y no se borra.
        if ($r['credito_id'] !== null) {
            $vigente = BaseDatos::unValor(
                'SELECT folio FROM creditos WHERE id = ? AND estado IN ("vigente","aprobado","desembolsado")',
                [(int) $r['credito_id']]
            );

            if ($vigente !== null) {
                Respuesta::error(
                    'Esta referencia respalda el crédito ' . $vigente
                    . ', que sigue vigente. No se elimina mientras exista esa operación.',
                    422
                );
            }
        }

        if ((int) $r['verificada'] === 1) {
            Respuesta::error(
                'Esta referencia está verificada. Retira la verificación antes de eliminarla.',
                422
            );
        }

        BaseDatos::ejecutar('DELETE FROM referencias WHERE id = ?', [$refId]);

        Auditoria::registrar('referencia.eliminada', Auditoria::EXITO, $usuarioId,
            'usuario', (string) $dueno,
            ['referencia' => $r['nombres'] . ' ' . $r['apellidos']]);

        Respuesta::exito(null, 'Referencia eliminada del expediente.');
    }

    /* =================================================================
       SOLICITUD — aquí solo se consultan
       ================================================================= */

    /**
     * Referencias disponibles para una solicitud.
     *
     * No las duplica: muestra las del expediente del solicitante y deja
     * marcar cuáles respaldan este trámite.
     */
    public function deSolicitud(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $solicitudId = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'solicitud');
        if ($solicitudId === 0) {
            throw new RecursoNoEncontrado('Solicitud no encontrada.');
        }

        $s = BaseDatos::unaFila(
            'SELECT s.*, p.nombre AS producto,
                    CONCAT(u.nombres," ",u.apellidos) AS cliente, u.usuario AS cliente_usuario
               FROM solicitudes_credito s
               JOIN productos p ON p.id = s.producto_id
               JOIN usuarios  u ON u.id = s.usuario_id
              WHERE s.id = ?',
            [$solicitudId]
        );

        if ($s === null) {
            throw new RecursoNoEncontrado('Solicitud no encontrada.');
        }

        $esMia = (int) $s['usuario_id'] === $usuarioId;
        if (!$esMia && !Autorizacion::puede('credito.ver_todos')) {
            throw new RecursoNoEncontrado('Solicitud no encontrada.');
        }

        $referencias = BaseDatos::todas(
            'SELECT * FROM v_referencias_expediente WHERE usuario_id = ?
              ORDER BY verificada DESC, tipo, apellidos',
            [(int) $s['usuario_id']]
        );

        $verificadas = 0;
        foreach ($referencias as &$r) {
            $r['codigo']      = Hashid::codificar((int) $r['id'], 'referencia');
            $r['tipo_nombre'] = self::TIPOS[$r['tipo']] ?? $r['tipo'];
            $r['usada_aqui']  = (int) ($r['solicitud_id'] ?? 0) === $solicitudId;
            if ((int) $r['verificada'] === 1) { $verificadas++; }
            unset($r['id'], $r['usuario_id'], $r['solicitud_id'], $r['credito_id']);
        }
        unset($r);

        $codigoTitular = Hashid::codificar((int) $s['usuario_id'], 'usuario');

        $s['codigo'] = Hashid::codificar($solicitudId, 'solicitud');
        unset($s['id'], $s['usuario_id'], $s['producto_id'], $s['credito_id']);

        $this->render('solicitudes/referencias', [
            'titulo'      => 'Referencias · ' . $s['folio'],
            'encabezado'  => 'Referencias de la solicitud ' . $s['folio'],
            'solicitud'   => $s,
            'referencias' => $referencias,
            'verificadas' => $verificadas,
            'minimo'      => self::MINIMO_EXIGIDO,
            'codigoTitular' => $codigoTitular,
            'puedeExpediente' => Autorizacion::puede('referencia.registrar')
                               && ($esMia || Autorizacion::puede('cliente.editar_expediente')),
            'puedeVerificar' => Autorizacion::puede('referencia.verificar'),
            'csrf'        => Csrf::token('referencia'),
        ]);
    }

    /* ================================================================= */

    /**
     * Resuelve de qué expediente se trata.
     *
     * @return array{id:int, nombre:string, usuario:string}
     */
    private function titularDe(array $parametros, int $usuarioId): array
    {
        $codigo = (string) ($parametros['codigo'] ?? '');

        // Sin código en la URL, es el expediente propio.
        $id = $codigo !== '' ? $this->idDesde($codigo, 'usuario') : $usuarioId;

        if ($id === 0) {
            throw new RecursoNoEncontrado('Cliente no encontrado.');
        }

        if ($id !== $usuarioId && !Autorizacion::puede('cliente.ver_expediente')) {
            throw new RecursoNoEncontrado('Cliente no encontrado.');
        }

        $u = BaseDatos::unaFila(
            'SELECT CONCAT(nombres," ",apellidos) AS nombre, usuario FROM usuarios WHERE id = ?',
            [$id]
        );

        if ($u === null) {
            throw new RecursoNoEncontrado('Cliente no encontrado.');
        }

        return [
            'id'      => $id,
            'nombre'  => (string) $u['nombre'],
            'usuario' => (string) $u['usuario'],
            'codigo'  => Hashid::codificar($id, 'usuario'),
        ];
    }

    /**
     * Valida y normaliza los datos del formulario.
     *
     * @return array<string,mixed>
     */
    private function validar(int $dueno, int $excluirId): array
    {
        $tipo = $this->post('tipo');
        if (!isset(self::TIPOS[$tipo])) {
            Respuesta::error('Tipo de referencia no válido.', 422);
        }

        $nombres   = mb_substr(trim($this->post('nombres')), 0, 120);
        $apellidos = mb_substr(trim($this->post('apellidos')), 0, 120);

        if (mb_strlen($nombres) < 2 || mb_strlen($apellidos) < 2) {
            Respuesta::error('Nombres y apellidos de la referencia son obligatorios.', 422);
        }

        $telefono = preg_replace('/[^0-9+\-() ]/', '', $this->post('telefono')) ?? '';
        $celular  = preg_replace('/[^0-9+\-() ]/', '', $this->post('celular')) ?? '';

        if (strlen(preg_replace('/\D/', '', $telefono . $celular) ?? '') < 8) {
            Respuesta::error(
                'Hace falta al menos un teléfono o celular con 8 dígitos: '
                . 'la referencia se verifica llamando.',
                422
            );
        }

        $correo = mb_strtolower(trim($this->post('correo')));
        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            Respuesta::error('El correo de la referencia no es válido.', 422);
        }

        // El titular no puede ser su propia referencia.
        $titular = BaseDatos::unaFila(
            'SELECT nombres, apellidos FROM usuarios WHERE id = ?', [$dueno]
        );

        if ($titular !== null
            && $this->normalizar($nombres . ' ' . $apellidos)
               === $this->normalizar((string) $titular['nombres'] . ' ' . (string) $titular['apellidos'])) {
            Respuesta::error('El titular no puede figurar como su propia referencia.', 422);
        }

        // Ni repetirse dentro del mismo expediente. La base también lo
        // impide con una clave única; esto da el mensaje legible.
        $repetida = BaseDatos::unValor(
            'SELECT id FROM referencias
              WHERE usuario_id = ? AND nombres = ? AND apellidos = ? AND id <> ?',
            [$dueno, $nombres, $apellidos, $excluirId]
        );

        if ($repetida !== null) {
            Respuesta::error('Esa persona ya figura en este expediente.', 422);
        }

        $anios = (int) $this->post('anios_conocer', '0');
        if ($anios < 0 || $anios > 100) {
            Respuesta::error('Los años de conocerse deben estar entre 0 y 100.', 422);
        }

        return [
            'tipo'       => $tipo,
            'nombres'    => $nombres,
            'apellidos'  => $apellidos,
            'empresa'    => mb_substr($this->post('empresa'), 0, 160),
            'profesion'  => mb_substr($this->post('profesion'), 0, 120),
            'telefono'   => $telefono,
            'celular'    => $celular,
            'correo'     => $correo !== '' ? $correo : null,
            'direccion'  => mb_substr($this->post('direccion'), 0, 255) ?: null,
            'parentesco' => mb_substr($this->post('parentesco'), 0, 60),
            'anios'      => $anios > 0 ? $anios : null,
        ];
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
