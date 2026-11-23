<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Cliente\Documento;
use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Notificacion\Correo;
use CashMan\Infraestructura\Notificacion\PlantillaCorreo;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\ServicioPassword;

/**
 * Registro público: alguien que no es cliente se da de alta solo.
 *
 * El sistema anterior no lo permitía. Para existir en el banco hacía
 * falta que un empleado te creara el usuario, lo que significa que
 * nadie podía ni mirar los productos desde dentro, ni pedir una cuenta
 * fuera del horario de una sucursal. Un banco que solo se puede abrir
 * en persona pierde a todo el que llega de noche.
 *
 * QUÉ ES Y QUÉ NO ES REGISTRARSE
 * ------------------------------
 * Registrarse crea un USUARIO, no un sujeto de crédito. Quien se
 * registra entra, ve el catálogo y puede abrirse una cuenta digital,
 * que es el producto sin riesgo: su propio dinero, sin línea que
 * nadie le preste. Para un crédito o una tarjeta de crédito hace falta
 * completar el expediente —ingresos, documentos— y eso lo revisa una
 * persona. Por eso nace con `perfil_completo = 0`.
 *
 * LO QUE NO SE LE DICE A QUIEN SE REGISTRA
 * ----------------------------------------
 * Si el correo o el documento ya existen, el mensaje es el mismo que
 * si todo hubiera ido bien. Un formulario que responde «ese correo ya
 * está registrado» es una forma cómoda de averiguar quién es cliente
 * de este banco: se prueban mil correos y los que den ese error son
 * clientes. El aviso de que ya existía va al correo, que es donde solo
 * lo lee su dueño.
 */
final class ServicioRegistro
{
    /** Nombres de usuario que nadie puede pedir para sí. */
    private const RESERVADOS = [
        'admin', 'administrador', 'root', 'sistema', 'soporte', 'cashman',
        'banco', 'cajero', 'gerencia', 'test', 'prueba', 'api', 'null',
    ];

    public function __construct(
        private readonly ServicioPassword $password = new ServicioPassword(),
        private readonly ServicioNotificacion $notificaciones = new ServicioNotificacion()
    ) {
    }

    /** ¿Está abierto el registro ahora mismo? */
    public static function abierto(): bool
    {
        return Parametros::entero('registro.abierto', 1) === 1;
    }

    /**
     * Da de alta a quien se registra.
     *
     * @param array{nombres:string, apellidos:string, correo:string,
     *              documento:string, telefono?:string, nacimiento?:string,
     *              password:string, confirmacion:string} $datos
     *
     * @return array{usuario:string, creado:bool}
     *         `creado` es falso cuando el correo o el documento ya
     *         existían. Quien llama NO debe distinguirlo en pantalla.
     */
    public function registrar(array $datos): array
    {
        if (!self::abierto()) {
            throw new \RuntimeException(
                'El registro en línea está cerrado en este momento. '
                . 'Puede abrir su cuenta en cualquiera de nuestras sucursales.'
            );
        }

        $nombres   = $this->limpiarNombre($datos['nombres'] ?? '');
        $apellidos = $this->limpiarNombre($datos['apellidos'] ?? '');
        $correo    = mb_strtolower(trim((string) ($datos['correo'] ?? '')));
        /* El documento, con su tipo.
         *
         * Antes se guardaba el número a secas, con los no-dígitos
         * quitados. Eso convertía un DUI, un NIT y un carné de
         * residente en la misma cadena de números sin manera de
         * distinguirlos, y el mismo DUI tecleado con guion y sin guion
         * en dos clientes distintos para cualquier búsqueda. */
        $tipoDoc = strtolower(trim((string) ($datos['tipo_documento'] ?? 'dui')));
        $docVo   = null;

        try {
            $docVo = Documento::de($tipoDoc, (string) ($datos['documento'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            $errorDoc = $e->getMessage();
        }

        $documento = $docVo !== null ? $docVo->crudo() : '';
        $telefono  = preg_replace('/[^0-9+\- ]/', '', (string) ($datos['telefono'] ?? '')) ?? '';
        $clave     = (string) ($datos['password'] ?? '');

        // --- Lo que se puede comprobar sin tocar la base --------------
        $fallos = [];

        if (mb_strlen($nombres) < 2)   { $fallos[] = 'Escriba sus nombres.'; }
        if (mb_strlen($apellidos) < 2) { $fallos[] = 'Escriba sus apellidos.'; }

        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $fallos[] = 'El correo no parece válido.';
        }

        /* El formato lo decide `Documento`, que es quien sabe de cada
           tipo. El dígito verificador del DUI NO se comprueba: el RNPN
           no publica el algoritmo y las versiones que circulan no
           coinciden, así que rechazar con una fórmula sin confirmar
           dejaría fuera a clientes de verdad. */
        if (isset($errorDoc)) {
            $fallos[] = $errorDoc;
        }

        if ($clave !== (string) ($datos['confirmacion'] ?? '')) {
            $fallos[] = 'La confirmación no coincide con la contraseña.';
        }

        foreach ($this->password->validarPolitica($clave) as $f) {
            $fallos[] = $f;
        }

        // --- La edad, si la declara -----------------------------------
        $nacimiento = trim((string) ($datos['nacimiento'] ?? ''));

        if ($nacimiento !== '') {
            $edadMinima = max(0, Parametros::entero('registro.edad_minima', 18));

            try {
                $fecha = new \DateTimeImmutable($nacimiento);
                $edad  = (int) $fecha->diff(new \DateTimeImmutable())->y;

                if ($edad < $edadMinima) {
                    $fallos[] = 'Debe tener al menos ' . $edadMinima . ' años cumplidos.';
                }

                if ($edad > 120) {
                    $fallos[] = 'Revise su fecha de nacimiento.';
                }
            } catch (\Throwable) {
                $fallos[] = 'La fecha de nacimiento no es válida.';
            }
        }

        if ($fallos !== []) {
            throw new PoliticaPassword($fallos);
        }

        // --- ¿Ya existe? ----------------------------------------------
        $yaEsta = BaseDatos::unaFila(
            'SELECT id, nombres, correo FROM usuarios
              WHERE correo = ? OR (documento_identidad IS NOT NULL AND documento_identidad = ?)
              LIMIT 1',
            [$correo, $documento]
        );

        if ($yaEsta !== null) {
            /* Ni una palabra distinta en pantalla. El aviso va al
               correo del titular, que es quien tiene derecho a saber
               que alguien intentó registrarse con sus datos —y a quien
               le conviene enterarse si no fue él—. */
            Auditoria::registrar(
                'registro.duplicado', Auditoria::DENEGADO, null,
                'usuario', (string) $yaEsta['id'],
                ['ip' => ClienteIp::obtener()]
            );

            $this->avisarIntentoDuplicado((string) $yaEsta['correo'], (string) $yaEsta['nombres']);

            return ['usuario' => '', 'creado' => false];
        }

        // --- El alta ---------------------------------------------------
        $rolCliente = (int) BaseDatos::unValor(
            'SELECT id FROM roles WHERE nombre = "Cliente" LIMIT 1'
        );

        if ($rolCliente === 0) {
            throw new \RuntimeException('No hay un rol de Cliente configurado.');
        }

        $usuario = $this->nombreDeUsuarioLibre($nombres, $apellidos);

        /* El cifrado se hace UNA vez, fuera de la transacción.
         *
         * Argon2id tarda a propósito —está calibrado para que probar
         * claves a la fuerza salga caro—, y ese rato con la
         * transacción abierta es un rato de filas bloqueadas sin
         * ninguna necesidad. */
        $hashClave = $this->password->hashear($clave);

        /* ¿Se le pide cambiarla al entrar?
         *
         * Conviene decirlo claro: esta contraseña la eligió el propio
         * cliente hace un momento y no la conoce nadie más, así que
         * pedirle cambiarla no gana seguridad por sí mismo. Está
         * porque la política de la casa lo pide para TODO usuario
         * nuevo, sin excepciones que haya que explicar después, y
         * porque deja el hábito instalado desde el primer día. Se
         * apaga con `registro.exigir_cambio_primer_ingreso`. */
        $exigirCambio = Parametros::booleano('registro.exigir_cambio_primer_ingreso', true) ? 1 : 0;

        $nuevoId = BaseDatos::enTransaccion(function () use (
            $usuario, $correo, $nombres, $apellidos, $hashClave, $exigirCambio,
            $rolCliente, $telefono, $documento, $tipoDoc
        ): int {
            BaseDatos::ejecutar(
                'INSERT INTO usuarios
                   (uuid, usuario, correo, nombres, apellidos, password_hash,
                    password_algoritmo, password_actualizado_en, requiere_cambio_password,
                    rol_id, estado, telefono, documento_identidad, tipo_documento,
                    perfil_completo, segmento, creado_en, actualizado_en)
                 VALUES (UUID(),?,?,?,?,?, "argon2id", NOW(), ?, ?, "activo", ?,?,?,
                         0, "estandar", NOW(), NOW())',
                [
                    $usuario, $correo, $nombres, $apellidos,
                    $hashClave,
                    $exigirCambio,
                    $rolCliente,
                    $telefono !== '' ? $telefono : null,
                    $documento,
                    $tipoDoc,
                ]
            );

            $id = BaseDatos::ultimoId();

            /* La clave de alta cuenta como usada desde el primer día:
               sin esto, el primer cambio podría volver a ponerla. */
            (new ServicioPoliticaPassword($this->password))->recordar($id, $hashClave);

            return $id;
        });

        Auditoria::registrar(
            'registro.completado', Auditoria::EXITO, $nuevoId,
            'usuario', (string) $nuevoId,
            ['usuario' => $usuario, 'ip' => ClienteIp::obtener()]
        );

        $this->notificaciones->crear(
            $nuevoId, 'registro.bienvenida', 'Bienvenido a CashMan H.A.',
            'Su usuario es ' . $usuario . '. Ya puede abrir su Cuenta Digital en un par '
            . 'de minutos, sin depósito de apertura y con tarjeta de débito virtual '
            . 'desde el primer día.',
            null, 'exito'
        );

        $this->darBienvenida($correo, $nombres, $usuario);

        return ['usuario' => $usuario, 'creado' => true];
    }

    // -----------------------------------------------------------------

    /** Quita lo que no es un nombre y respeta acentos y apóstrofos. */
    private function limpiarNombre(string $v): string
    {
        $v = preg_replace("/[^\p{L}\p{M}\s'’\-]/u", '', trim($v)) ?? '';
        $v = preg_replace('/\s+/u', ' ', $v) ?? '';

        return mb_substr($v, 0, 80);
    }

    /**
     * Un nombre de usuario libre, a partir del nombre de la persona.
     *
     * La carrera entre comprobar y escribir la resuelve la clave única
     * de la tabla: si dos personas se registran a la vez con el mismo
     * nombre, una de las dos inserciones falla y se reintenta. Aquí
     * solo se busca un candidato razonable.
     */
    private function nombreDeUsuarioLibre(string $nombres, string $apellidos): string
    {
        $trozo = static function (string $s): string {
            $sinAcentos = iconv('UTF-8', 'ASCII//TRANSLIT', $s);
            $primero    = explode(' ', trim($sinAcentos !== false ? $sinAcentos : $s))[0];

            return preg_replace('/[^a-z0-9]/', '', mb_strtolower($primero)) ?? '';
        };

        $base = mb_substr(($trozo($nombres) ?: 'cliente') . '.' . $trozo($apellidos), 0, 40);

        if (in_array($base, self::RESERVADOS, true)) {
            $base .= '.cl';
        }

        $candidato = $base;
        $n = 1;

        while (BaseDatos::unValor('SELECT 1 FROM usuarios WHERE usuario = ?', [$candidato]) !== null) {
            $candidato = $base . (++$n);

            if ($n > 500) {
                $candidato = $base . '.' . bin2hex(random_bytes(3));
                break;
            }
        }

        return $candidato;
    }

    private function darBienvenida(string $correo, string $nombres, string $usuario): void
    {
        try {
            (new Correo())->enviar(
                $correo,
                'Bienvenido a CashMan H.A.',
                PlantillaCorreo::bienvenida(
                    $nombres,
                    $usuario,
                    rtrim((string) Config::obtener('APP_URL', ''), '/') . '/entrar'
                ),
                '',
                $nombres
            );
        } catch (\Throwable $e) {
            /* Que no llegue el correo no puede deshacer un alta que ya
               está hecha: la persona puede entrar igual con su usuario.
               Se deja constancia y se sigue. */
            error_log('[Registro] no se pudo enviar la bienvenida: ' . $e->getMessage());
        }
    }

    private function avisarIntentoDuplicado(string $correo, string $nombres): void
    {
        try {
            (new Correo())->enviar(
                $correo,
                'Alguien intentó registrarse con sus datos',
                PlantillaCorreo::comprobante(
                    $nombres,
                    'Ya tiene una cuenta con nosotros',
                    'Se intentó crear un usuario nuevo con su correo o su documento. '
                    . 'No hicimos nada: su cuenta de siempre sigue igual.',
                    [
                        ['etiqueta' => 'Qué pasó', 'valor' => 'Un registro con datos ya existentes'],
                        ['etiqueta' => 'Fecha',    'valor' => date('d/m/Y H:i')],
                    ],
                    rtrim((string) Config::obtener('APP_URL', ''), '/') . '/recuperar',
                    [],
                    [],
                    'Si fue usted y no recuerda su contraseña, use «olvidé mi contraseña». '
                    . 'Si no fue usted, no tiene que hacer nada, pero avísenos.',
                    'aviso'
                ),
                '',
                $nombres
            );
        } catch (\Throwable $e) {
            error_log('[Registro] aviso de duplicado no enviado: ' . $e->getMessage());
        }
    }
}
