<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Cifrado;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\Sesion;
use CashMan\Infraestructura\Seguridad\Totp;

/**
 * El segundo factor, decidido por la OPERACIÓN y no por la sesión.
 *
 * LA IDEA, QUE ES LO QUE IMPORTA
 *
 * Entrar con contraseña y después poder vaciar la cuenta sin volver a
 * demostrar nada es el escenario de la sesión robada: el atacante no
 * necesita saber nada más, solo llegar a una sesión abierta.
 *
 * La norma de canales digitales pide un factor de categoría 2 —la
 * contraseña con la que entró— MÁS un factor adicional que depende de
 * la TRANSACCIÓN. Eso es lo que hace esta clase.
 *
 * POR QUÉ NO SE PIDE SIEMPRE
 *
 * Porque pedir un código para transferir cinco dólares entre cuentas
 * propias enseña al cliente a teclear códigos sin leer para qué, y ese
 * hábito es justo el que explota la ingeniería social: quien llama
 * diciendo «le voy a mandar un código, démelo» se aprovecha de alguien
 * acostumbrado a dictar códigos sin mirar.
 *
 * El factor tiene que doler donde importa y desaparecer donde no.
 *
 * EL DISPOSITIVO SUBE LA EXIGENCIA, NUNCA LA BAJA
 *
 * Un dispositivo conocido no exime de nada: solo evita subir. La
 * huella se puede falsificar, así que sirve para notar que algo es
 * nuevo —y avisar— pero jamás como factor de autenticación.
 */
final class ServicioFactorOperacion
{
    public function __construct(
        private ServicioDesafio $desafios = new ServicioDesafio(),
        private ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    // =================================================================
    // La política
    // =================================================================

    /**
     * Qué factor exige esta operación por este monto.
     *
     * @return array{factor:string, tramo_desde:int, descripcion:string,
     *               dispositivo_conocido:bool}
     */
    public function politica(string $operacion, int $montoCent = 0, ?int $usuarioId = null): array
    {
        $usuarioId ??= Sesion::usuarioId() ?? 0;

        $conocido = $this->dispositivoConocido($usuarioId);

        $p = BaseDatos::unaFila(
            'SELECT factor, factor_desconocido, desde_cent, descripcion
               FROM politicas_factor
              WHERE operacion = ? AND activa = 1 AND desde_cent <= ?
              ORDER BY desde_cent DESC
              LIMIT 1',
            [$operacion, max(0, $montoCent)]
        );

        /* Sin política definida se exige el factor fuerte. Fallar
           CERRADO: una operación nueva que nadie clasificó no puede
           quedar sin protección por el olvido de añadir una fila. */
        if ($p === null) {
            return [
                'factor'      => 'fuerte',
                'tramo_desde' => 0,
                'descripcion' => 'Operación sin política definida: se exige el factor más fuerte.',
                'dispositivo_conocido' => $conocido,
            ];
        }

        $factor = (string) $p['factor'];

        if (!$conocido && $p['factor_desconocido'] !== null) {
            /* Solo sube. Si la política del dispositivo desconocido
               fuera más laxa —un error de configuración— se ignora. */
            $factor = $this->masFuerte($factor, (string) $p['factor_desconocido']);
        }

        return [
            'factor'      => $factor,
            'tramo_desde' => (int) $p['desde_cent'],
            'descripcion' => (string) $p['descripcion'],
            'dispositivo_conocido' => $conocido,
        ];
    }

    /** @var array<string,int> */
    private const FUERZA = ['ninguno' => 0, 'otp' => 1, 'totp' => 2, 'fuerte' => 3];

    private function masFuerte(string $a, string $b): string
    {
        return (self::FUERZA[$b] ?? 0) > (self::FUERZA[$a] ?? 0) ? $b : $a;
    }

    /**
     * Resuelve «fuerte» a lo que el cliente de verdad tiene.
     *
     * Si tiene el segundo factor activo, se le pide ése; si no, el
     * código al correo. Pedirle la app autenticadora a quien no la
     * activó sería bloquearle la cuenta.
     */
    public function factorEfectivo(string $factor, int $usuarioId): string
    {
        if ($factor !== 'fuerte') {
            return $factor;
        }

        $tieneTotp = (int) BaseDatos::unValor(
            'SELECT totp_activo FROM usuarios WHERE id = ?',
            [$usuarioId]
        ) === 1;

        return $tieneTotp ? 'totp' : 'otp';
    }

    // =================================================================
    // La exigencia
    // =================================================================

    /**
     * Comprueba el factor que corresponde a esta operación.
     *
     * Lanza si no se cumple. Si la política dice «ninguno», no pide
     * nada y deja constancia de que se evaluó.
     *
     * @param string $codigo El que tecleó el cliente, si lo hubo.
     * @param string|null $contexto Hash que ata el código a ESTA
     *        operación: un código emitido para otra no sirve.
     */
    public function exigir(
        string $operacion,
        int $montoCent = 0,
        string $codigo = '',
        ?string $contexto = null
    ): void {
        $usuarioId = Sesion::exigirAutenticado();

        $p = $this->politica($operacion, $montoCent, $usuarioId);

        $factor = $this->factorEfectivo((string) $p['factor'], $usuarioId);

        if ($factor === 'ninguno') {
            $this->anotar($usuarioId, $operacion, $montoCent, 'ninguno',
                (bool) $p['dispositivo_conocido'], 'exento',
                (string) $p['descripcion']);

            return;
        }

        $codigo = trim($codigo);

        if ($codigo === '') {
            $this->anotar($usuarioId, $operacion, $montoCent, $factor,
                (bool) $p['dispositivo_conocido'], 'fallido', 'No se presentó código.');

            throw new FactorRequerido(
                $factor === 'totp'
                    ? 'Escriba el código de seis dígitos de su aplicación autenticadora.'
                    : 'Escriba el código de verificación; lo encontrará en sus notificaciones (la campana).',
                $factor,
                $operacion
            );
        }

        try {
            if ($factor === 'totp') {
                $this->verificarTotp($usuarioId, $codigo);
            } else {
                $this->desafios->consumir($usuarioId, 'reforzada', $codigo, $contexto);
            }
        } catch (\RuntimeException $e) {
            $this->anotar($usuarioId, $operacion, $montoCent, $factor,
                (bool) $p['dispositivo_conocido'], 'fallido', $e->getMessage());

            throw $e;
        }

        $this->anotar($usuarioId, $operacion, $montoCent, $factor,
            (bool) $p['dispositivo_conocido'], 'cumplido', (string) $p['descripcion']);
    }

    /**
     * Pide el código que corresponda, si corresponde alguno.
     *
     * @return array<string,mixed> Lo que la pantalla necesita saber.
     */
    public function solicitar(string $operacion, int $montoCent, ?string $contexto, string $descripcion): array
    {
        $usuarioId = Sesion::exigirAutenticado();

        $p = $this->politica($operacion, $montoCent, $usuarioId);

        $factor = $this->factorEfectivo((string) $p['factor'], $usuarioId);

        if ($factor === 'ninguno') {
            return [
                'factor'  => 'ninguno',
                'mensaje' => 'Esta operación no necesita código.',
                'dispositivo_conocido' => $p['dispositivo_conocido'],
            ];
        }

        if ($factor === 'totp') {
            return [
                'factor'  => 'totp',
                'mensaje' => 'Escriba el código de su aplicación autenticadora.',
                'dispositivo_conocido' => $p['dispositivo_conocido'],
            ];
        }

        $r = $this->desafios->emitir($usuarioId, 'reforzada', $contexto, $descripcion);

        return [
            'factor'    => 'otp',
            'mensaje'   => $r['mensaje'] ?? 'Su código está en sus notificaciones (la campana).',
            'enviado_a' => $r['enviado_a'] ?? '',
            'expira_en' => $r['expira_en'] ?? '',
            'dispositivo_conocido' => $p['dispositivo_conocido'],
        ];
    }

    private function verificarTotp(int $usuarioId, string $codigo): void
    {
        $u = BaseDatos::unaFila(
            'SELECT totp_secreto_cifrado, totp_ultimo_contador, totp_desfase_seg
               FROM usuarios WHERE id = ? AND totp_activo = 1',
            [$usuarioId]
        );

        if ($u === null || $u['totp_secreto_cifrado'] === null) {
            throw new DesafioInvalido('No tiene activa la verificación en dos pasos.');
        }

        $cifra = new Cifrado(\CashMan\Infraestructura\Config::claveBinaria('APP_CLAVE'));

        $secreto = $cifra->descifrar((string) $u['totp_secreto_cifrado'], 'totp');

        $contador = Totp::verificar(
            $secreto,
            $codigo,
            $u['totp_ultimo_contador'] !== null ? (int) $u['totp_ultimo_contador'] : null,
            (int) ($u['totp_desfase_seg'] ?? 0)
        );

        if ($contador === null) {
            throw new DesafioInvalido(
                'El código no es válido o ya se usó. Espere al siguiente y vuelva a intentarlo.'
            );
        }

        /* Anti-reutilización: el mismo código no sirve dos veces, ni
           siquiera dentro de su ventana de treinta segundos. */
        BaseDatos::ejecutar(
            'UPDATE usuarios SET totp_ultimo_contador = ? WHERE id = ?',
            [$contador, $usuarioId]
        );
    }

    // =================================================================
    // Dispositivos
    // =================================================================

    /**
     * ¿El banco ha visto antes este dispositivo para este cliente?
     */
    public function dispositivoConocido(int $usuarioId): bool
    {
        if ($usuarioId === 0) {
            return false;
        }

        $huella = $this->huellaActual();

        if ($huella === '') {
            return false;
        }

        return (int) BaseDatos::unValor(
            'SELECT COUNT(*) FROM dispositivos_conocidos
              WHERE usuario_id = ? AND huella = ? AND bloqueado = 0',
            [$usuarioId, $huella]
        ) > 0;
    }

    /**
     * Registra el dispositivo y avisa si es nuevo.
     *
     * Se llama al iniciar sesión. El aviso es la mitad de la
     * protección: si alguien entra con credenciales robadas, el dueño
     * se entera el mismo minuto.
     *
     * @return bool true si era nuevo
     */
    public function registrarAcceso(int $usuarioId): bool
    {
        $huella = $this->huellaActual();

        if ($huella === '' || $usuarioId === 0) {
            return false;
        }

        $ya = BaseDatos::unaFila(
            'SELECT id, bloqueado FROM dispositivos_conocidos
              WHERE usuario_id = ? AND huella = ?',
            [$usuarioId, $huella]
        );

        if ($ya !== null) {
            BaseDatos::ejecutar(
                'UPDATE dispositivos_conocidos
                    SET ultima_vez_en = NOW(), veces = veces + 1, ip_ultima = ?
                  WHERE id = ?',
                [ClienteIp::binaria(), (int) $ya['id']]
            );

            /* Un dispositivo que el cliente marcó como ajeno vuelve a
               avisar cada vez: si sigue entrando, hay que saberlo. */
            return (int) $ya['bloqueado'] === 1;
        }

        $descripcion = $this->describirDispositivo();

        BaseDatos::ejecutar(
            'INSERT INTO dispositivos_conocidos
               (usuario_id, huella, descripcion, ip_primera, ip_ultima)
             VALUES (?, ?, ?, ?, ?)',
            [$usuarioId, $huella, $descripcion, ClienteIp::binaria(), ClienteIp::binaria()]
        );

        Auditoria::registrar(
            'dispositivo.nuevo',
            Auditoria::EXITO,
            $usuarioId,
            'dispositivo',
            substr($huella, 0, 12),
            ['descripcion' => $descripcion]
        );

        $this->notificaciones->crear(
            $usuarioId,
            'acceso.dispositivo_nuevo',
            'Entró desde un dispositivo nuevo',
            'Se inició sesión en su cuenta desde ' . $descripcion
            . ' (IP ' . ClienteIp::obtener() . ') el '
            . date('d/m/Y \a \l\a\s H:i') . '. '
            . 'Si no fue usted, cambie su contraseña ahora y avísenos.',
            ['huella' => substr($huella, 0, 12)],
            'alerta'
        );

        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public function dispositivosDe(int $usuarioId): array
    {
        $filas = BaseDatos::todas(
            'SELECT id, descripcion, primera_vez_en, ultima_vez_en, veces,
                    bloqueado, ip_ultima, huella
               FROM dispositivos_conocidos
              WHERE usuario_id = ?
              ORDER BY ultima_vez_en DESC',
            [$usuarioId]
        );

        $actual = $this->huellaActual();

        foreach ($filas as &$f) {
            $f['es_este']  = hash_equals((string) $f['huella'], $actual);
            $f['ip_texto'] = $f['ip_ultima'] !== null
                ? ClienteIp::desdeBinaria((string) $f['ip_ultima'])
                : '';

            /* La huella NO sale a la pantalla: es lo que identifica al
               dispositivo y publicarla ayudaría a suplantarlo. */
            unset($f['huella'], $f['ip_ultima']);
        }

        return $filas;
    }

    /**
     * «Éste no fui yo».
     *
     * No cierra la sesión por sí solo —de eso se encarga el cliente
     * desde «Mis dispositivos»— pero hace que ese dispositivo deje de
     * contar como conocido: volverá a pedir factor cada vez y a avisar
     * en cada entrada.
     */
    public function desconocer(int $dispositivoId, int $usuarioId): void
    {
        $d = BaseDatos::unaFila(
            'SELECT id, descripcion FROM dispositivos_conocidos
              WHERE id = ? AND usuario_id = ?',
            [$dispositivoId, $usuarioId]
        );

        if ($d === null) {
            throw new \RuntimeException('Ese dispositivo no está en su lista.');
        }

        BaseDatos::ejecutar(
            'UPDATE dispositivos_conocidos SET bloqueado = 1, bloqueado_en = NOW() WHERE id = ?',
            [$dispositivoId]
        );

        Auditoria::registrar(
            'dispositivo.desconocido',
            Auditoria::EXITO,
            $usuarioId,
            'dispositivo',
            (string) $dispositivoId,
            ['descripcion' => $d['descripcion']]
        );
    }

    // =================================================================
    // Interno
    // =================================================================

    private function huellaActual(): string
    {
        try {
            $m = new \ReflectionMethod(Sesion::class, 'huellaCliente');
            $m->setAccessible(true);

            return (string) $m->invoke(null);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Un nombre que el cliente reconozca al verlo.
     *
     * No se guarda el user agent entero: es largo, cambia con cada
     * actualización del navegador y no le dice nada a nadie. Se
     * extrae lo que una persona sí reconoce.
     */
    private function describirDispositivo(): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        if ($ua === '') {
            return 'un dispositivo desconocido';
        }

        $sistema = match (true) {
            str_contains($ua, 'Android')             => 'Android',
            str_contains($ua, 'iPhone')              => 'iPhone',
            str_contains($ua, 'iPad')                => 'iPad',
            str_contains($ua, 'Windows')             => 'Windows',
            str_contains($ua, 'Mac OS X')            => 'Mac',
            str_contains($ua, 'Linux')               => 'Linux',
            default                                  => 'un sistema desconocido',
        };

        $navegador = match (true) {
            str_contains($ua, 'Edg/')     => 'Edge',
            str_contains($ua, 'OPR/')     => 'Opera',
            str_contains($ua, 'Chrome/')  => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/')  => 'Safari',
            default                       => 'un navegador',
        };

        return $navegador . ' en ' . $sistema;
    }

    private function anotar(
        int $usuarioId,
        string $operacion,
        int $montoCent,
        string $factor,
        bool $conocido,
        string $resultado,
        string $detalle
    ): void {
        BaseDatos::ejecutar(
            'INSERT INTO factor_exigido
               (usuario_id, operacion, monto_cent, factor_pedido,
                dispositivo_conocido, resultado, detalle, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $usuarioId, $operacion, $montoCent, $factor,
                $conocido ? 1 : 0, $resultado,
                mb_substr($detalle, 0, 160), ClienteIp::binaria(),
            ]
        );
    }

    /**
     * La política entera, para enseñarla.
     *
     * Un examinador pregunta «¿qué operaciones piden factor y desde
     * qué monto?». Esto lo responde sin abrir el código.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function tabla(): array
    {
        $filas = BaseDatos::todas(
            'SELECT * FROM politicas_factor WHERE activa = 1
              ORDER BY operacion, desde_cent'
        );

        $por = [];

        foreach ($filas as $f) {
            $por[(string) $f['operacion']][] = $f;
        }

        return $por;
    }
}
