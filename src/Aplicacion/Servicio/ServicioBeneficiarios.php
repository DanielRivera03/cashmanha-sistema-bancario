<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Interbancario\RedDePagos;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * La agenda de a quién se le puede mandar dinero.
 *
 * POR QUÉ ESTO EXISTE, Y NO ES COMODIDAD
 *
 * Separar «registrar un destino» de «mandarle dinero» es el control
 * antifraude más barato y más efectivo que tiene una banca en línea.
 *
 * Sin agenda, quien entra con credenciales robadas vacía la cuenta en
 * un solo movimiento hacia donde quiera. Con agenda, primero tiene que
 * dar de alta un destino; ese alta AVISA al titular por todos sus
 * canales; y el destino no sirve hasta que pasa un período de
 * enfriamiento. Son horas en las que el dueño legítimo puede ver el
 * aviso y frenarlo.
 *
 * De ahí que las dos cosas que podrían parecer molestias —el aviso y
 * la espera— sean justamente la razón de ser de esta clase. Quitarlas
 * para «mejorar la experiencia» sería quitar la protección entera.
 *
 * EL CASO DE LAS CUENTAS PROPIAS
 *
 * A una cuenta del mismo titular, en este mismo banco, no se le aplica
 * enfriamiento: no hay a quién robarle moviendo dinero del bolsillo
 * izquierdo al derecho. La excepción se concede por TITULARIDAD
 * comprobada en la base, nunca porque el cliente lo diga.
 */
final class ServicioBeneficiarios
{
    /**
     * Cuánto tarda un destino nuevo en poder recibir dinero.
     *
     * Doce horas es el término medio de lo que usan los bancos del
     * país: bastante para que el aviso llegue y se lea —incluso si se
     * dio de alta de madrugada— y poco para no estorbar a quien
     * registra a su casero el día que le toca pagar.
     */
    public const ENFRIAMIENTO_HORAS = 12;

    public function __construct(
        private ServicioDesafio $desafios = new ServicioDesafio(),
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    // =================================================================
    // Alta
    // =================================================================

    /**
     * Da de alta un destino.
     *
     * @param array{
     *   alias:string, banco_id:int, numero_cuenta?:string,
     *   tipo_cuenta?:string, titular_nombre:string,
     *   titular_documento?:string, celular?:string, codigo_otp?:string
     * } $datos
     * @return array<string,mixed>
     */
    public function registrar(array $datos): array
    {
        $actorId = Sesion::exigirAutenticado();

        $alias = trim((string) ($datos['alias'] ?? ''));

        if ($alias === '') {
            throw new BeneficiarioInvalido('Póngale un nombre al destino: así lo reconocerá después.');
        }

        $banco = $this->banco((int) ($datos['banco_id'] ?? 0));

        $titular = trim((string) ($datos['titular_nombre'] ?? ''));

        if (mb_strlen($titular) < 5) {
            throw new BeneficiarioInvalido('Escriba el nombre completo del titular de la cuenta destino.');
        }

        $celular = RedDePagos::normalizarCelular((string) ($datos['celular'] ?? ''));
        $cuenta  = RedDePagos::normalizarCuenta((string) ($datos['numero_cuenta'] ?? ''));

        /* O cuenta, o celular. Un destino sin ninguno de los dos no se
           puede alcanzar, y uno con ambos obliga a elegir al enviar. */
        if ($cuenta === '' && $celular === '') {
            throw new BeneficiarioInvalido(
                'Indique el número de cuenta, o el celular si va a usar Transfer365 Móvil.'
            );
        }

        if ($cuenta !== '' && !RedDePagos::cuentaValida($cuenta)) {
            throw new BeneficiarioInvalido(
                'El número de cuenta debe tener entre 8 y 20 dígitos, sin letras.'
            );
        }

        if ($celular !== '' && !RedDePagos::celularValido($celular)) {
            throw new BeneficiarioInvalido(
                'El celular debe ser un número móvil salvadoreño de ocho dígitos.'
            );
        }

        /* Un destino sin cuenta se guarda con el celular en su lugar,
           para que el UNIQUE de la tabla siga distinguiendo destinos. */
        $claveCuenta = $cuenta !== '' ? $cuenta : 'CEL:' . $celular;

        $yaEsta = BaseDatos::unaFila(
            'SELECT uuid, alias FROM beneficiarios
              WHERE usuario_id = ? AND banco_id = ? AND numero_cuenta = ?',
            [$actorId, $banco['id'], $claveCuenta]
        );

        if ($yaEsta !== null) {
            throw new BeneficiarioInvalido(
                'Ya tiene ese destino en su agenda, guardado como «' . $yaEsta['alias'] . '».'
            );
        }

        // --- El segundo factor, antes de tocar la agenda -------------
        // Dar de alta un destino es la operación que un atacante
        // necesita: pide código igual que una transferencia.
        $this->desafios->consumir(
            $actorId,
            'beneficiario',
            (string) ($datos['codigo_otp'] ?? ''),
            $this->contexto($banco['id'], $claveCuenta)
        );

        // --- ¿Es una cuenta propia en este mismo banco? ---------------
        $propia = $this->esCuentaPropia($actorId, $banco, $cuenta);

        $horas = $propia ? 0 : self::ENFRIAMIENTO_HORAS;

        $uuid = BaseDatos::enTransaccion(function () use (
            $actorId, $alias, $banco, $claveCuenta, $cuenta, $celular, $titular, $datos, $horas, $propia
        ): string {
            $uuid = \CashMan\Dominio\Comun\LibroMayor::uuid4();

            BaseDatos::ejecutar(
                'INSERT INTO beneficiarios
                   (uuid, usuario_id, alias, banco_id, numero_cuenta, tipo_cuenta,
                    titular_nombre, titular_documento, celular, estado,
                    enfriamiento_hasta, verificado_en, creado_desde_ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                         DATE_ADD(NOW(), INTERVAL ? HOUR), ?, ?)',
                [
                    $uuid,
                    $actorId,
                    $alias,
                    $banco['id'],
                    $claveCuenta,
                    in_array($datos['tipo_cuenta'] ?? '', ['ahorro', 'corriente'], true)
                        ? $datos['tipo_cuenta'] : 'ahorro',
                    $titular,
                    mb_substr(trim((string) ($datos['titular_documento'] ?? '')), 0, 20),
                    $celular,
                    $propia ? 'activo' : 'en_enfriamiento',
                    $horas,
                    $propia ? date('Y-m-d H:i:s') : null,
                    ClienteIp::binaria(),
                ]
            );

            return $uuid;
        });

        Auditoria::registrar(
            'beneficiario.registrado',
            Auditoria::EXITO,
            $actorId,
            'beneficiario',
            $uuid,
            [
                'banco'   => $banco['nombre_corto'],
                'destino' => $this->enmascarar($claveCuenta),
                'propia'  => $propia,
            ]
        );

        // --- El aviso: es la mitad de la protección -------------------
        $this->notificaciones->crear(
            $actorId,
            'beneficiario.registrado',
            'Se agregó un destino a su agenda',
            'Se registró «' . $alias . '» (' . $banco['nombre_corto'] . ' '
            . $this->enmascarar($claveCuenta) . '). '
            . ($propia
                ? 'Por ser una cuenta suya, ya puede usarla.'
                : 'Podrá enviarle dinero dentro de ' . self::ENFRIAMIENTO_HORAS
                  . ' horas. Si no fue usted, bloquéelo ahora desde su agenda.'),
            ['uuid' => $uuid],
            $propia ? 'info' : 'alerta'
        );

        return [
            'uuid'               => $uuid,
            'alias'              => $alias,
            'estado'             => $propia ? 'activo' : 'en_enfriamiento',
            'enfriamiento_horas' => $horas,
            'disponible_desde'   => $propia
                ? null
                : date('c', strtotime('+' . self::ENFRIAMIENTO_HORAS . ' hours')),
        ];
    }

    // =================================================================
    // Consulta
    // =================================================================

    /**
     * La agenda del cliente, con el estado real ya resuelto.
     *
     * `usable` se calcula en SQL y no se lee de la columna: la columna
     * dice lo que se guardó, pero quien manda es el reloj. Un registro
     * cuyo enfriamiento venció ya es utilizable aunque nadie haya
     * pasado todavía a cambiarle la etiqueta.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listar(int $usuarioId): array
    {
        return BaseDatos::todas(
            'SELECT b.*, bp.nombre_corto AS banco, bp.nombre AS banco_nombre,
                    bp.es_propio, bp.transfer365, bp.transfer365_movil, bp.uni,
                    (b.estado <> "bloqueado" AND b.enfriamiento_hasta <= NOW()) AS usable,
                    GREATEST(0, TIMESTAMPDIFF(MINUTE, NOW(), b.enfriamiento_hasta)) AS faltan_min
               FROM beneficiarios b
               JOIN bancos_participantes bp ON bp.id = b.banco_id
              WHERE b.usuario_id = ?
              ORDER BY usable DESC, b.ultima_uso_en IS NULL, b.ultima_uso_en DESC, b.alias',
            [$usuarioId]
        );
    }

    /**
     * Un beneficiario concreto, comprobando que sea de quien dice.
     *
     * @return array<string,mixed>
     */
    public function exigirDe(string $uuid, int $usuarioId): array
    {
        $b = BaseDatos::unaFila(
            'SELECT b.*, bp.nombre_corto AS banco, bp.nombre AS banco_nombre,
                    bp.es_propio, bp.transfer365, bp.transfer365_movil, bp.uni,
                    (b.estado <> "bloqueado" AND b.enfriamiento_hasta <= NOW()) AS usable
               FROM beneficiarios b
               JOIN bancos_participantes bp ON bp.id = b.banco_id
              WHERE b.uuid = ? AND b.usuario_id = ?',
            [$uuid, $usuarioId]
        );

        if ($b === null) {
            throw new BeneficiarioInvalido('Ese destino no está en su agenda.');
        }

        return $b;
    }

    // =================================================================
    // Bloqueo y baja
    // =================================================================

    /**
     * Bloquea un destino.
     *
     * Es el botón que tiene que pulsar quien recibe el aviso de un alta
     * que no hizo, y por eso NO pide segundo factor: en ese momento el
     * atacante puede tener el control de la sesión, pero frenar nunca
     * puede ser más difícil que hacer. Bloquear no mueve dinero: solo
     * cierra una puerta.
     */
    public function bloquear(string $uuid, int $usuarioId): void
    {
        $b = $this->exigirDe($uuid, $usuarioId);

        BaseDatos::ejecutar(
            'UPDATE beneficiarios SET estado = "bloqueado" WHERE id = ?',
            [$b['id']]
        );

        Auditoria::registrar(
            'beneficiario.bloqueado',
            Auditoria::EXITO,
            $usuarioId,
            'beneficiario',
            $uuid,
            ['alias' => $b['alias']]
        );

        $this->notificaciones->crear(
            $usuarioId,
            'beneficiario.bloqueado',
            'Destino bloqueado',
            'Ya no se puede enviar dinero a «' . $b['alias'] . '».',
            ['uuid' => $uuid],
            'exito'
        );
    }

    /**
     * Elimina un destino de la agenda.
     *
     * Solo la fila de la agenda. Las transferencias que se le hicieron
     * NO se tocan: son hechos ocurridos y el historial del cliente
     * tiene que seguir cuadrando. Por eso la clave foránea queda en
     * NULL en lugar de arrastrar el borrado.
     */
    public function eliminar(string $uuid, int $usuarioId): void
    {
        $b = $this->exigirDe($uuid, $usuarioId);

        BaseDatos::enTransaccion(static function () use ($b): void {
            BaseDatos::ejecutar(
                'UPDATE transferencias_interbancarias SET beneficiario_id = NULL
                  WHERE beneficiario_id = ?',
                [$b['id']]
            );

            BaseDatos::ejecutar('DELETE FROM beneficiarios WHERE id = ?', [$b['id']]);
        });

        Auditoria::registrar(
            'beneficiario.eliminado',
            Auditoria::EXITO,
            $usuarioId,
            'beneficiario',
            $uuid,
            ['alias' => $b['alias']]
        );
    }

    /**
     * Pone al día las etiquetas de los enfriamientos vencidos.
     *
     * No cambia quién puede recibir dinero —eso lo decide el reloj en
     * cada consulta— solo hace que la pantalla diga «activo» cuando ya
     * lo está. La llama el latido del sistema.
     */
    public function madurarEnfriamientos(): int
    {
        return BaseDatos::ejecutar(
            'UPDATE beneficiarios SET estado = "activo"
              WHERE estado = "en_enfriamiento" AND enfriamiento_hasta <= NOW()'
        );
    }

    // =================================================================
    // Interno
    // =================================================================

    /** @return array<string,mixed> */
    private function banco(int $id): array
    {
        $b = BaseDatos::unaFila(
            'SELECT * FROM bancos_participantes WHERE id = ? AND activo = 1',
            [$id]
        );

        if ($b === null) {
            throw new BeneficiarioInvalido('Esa institución no está disponible.');
        }

        return $b;
    }

    /**
     * ¿La cuenta destino es del propio cliente, en este mismo banco?
     *
     * Se comprueba contra la base, no contra lo que diga el formulario.
     */
    private function esCuentaPropia(int $actorId, array $banco, string $cuenta): bool
    {
        if ((int) $banco['es_propio'] !== 1 || $cuenta === '') {
            return false;
        }

        return (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM cuentas
              WHERE numero_cuenta = ? AND usuario_id = ? AND es_tecnica = 0',
            [$cuenta, $actorId]
        ) > 0;
    }

    /** Para avisos y bitácora: nunca el número completo. */
    private function enmascarar(string $destino): string
    {
        if (str_starts_with($destino, 'CEL:')) {
            return 'celular ****' . substr($destino, -4);
        }

        return '****' . substr($destino, -4);
    }

    private function contexto(int $bancoId, string $cuenta): string
    {
        return hash('sha256', "beneficiario|{$bancoId}|{$cuenta}");
    }
}
