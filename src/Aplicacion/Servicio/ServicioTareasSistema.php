<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;

/**
 * Las tareas periódicas, dentro del propio sistema.
 *
 * Antes dependían del Programador de tareas de Windows. Funciona, pero
 * ata el sistema a una máquina y a que alguien se acuerde de
 * registrarlas: al moverlo a otro servidor, restaurar una copia o
 * desplegarlo en otro sitio, las tareas se quedan atrás sin que nada
 * avise. Y lo que se queda atrás no es menor: sin `revisar-segmentos`
 * los clientes dejan de recibir los beneficios que su pantalla les
 * promete, y nadie se entera hasta que uno reclama.
 *
 * **Cómo corre.** Cada petición web pregunta si hay algo vencido —una
 * consulta con índice, que casi siempre dice que no— y, si lo hay, lo
 * ejecuta DESPUÉS de haber respondido al usuario. El mismo mecanismo
 * que ya usa el envío de correo: `register_shutdown_function` más
 * `fastcgi_finish_request` donde se puede.
 *
 * **La contrapartida, sin adornos.** Esto corre cuando alguien usa el
 * sistema. Un domingo sin visitas no corre nada y el lunes se pone al
 * día. Para una banca con uso diario alcanza, y cada tarea está
 * escrita para tolerar el retraso: el abono de intereses se niega a
 * pagar un periodo abierto, el corte solo toca las tarjetas cuyo día
 * es hoy, el devengo cuenta días reales. Si algún día hace falta
 * puntualidad de reloj, la tarea de Windows sigue valiendo y las dos
 * conviven: el bloqueo impide que se pisen.
 *
 * **Una a la vez.** Se ejecuta UNA tarea por petición aunque haya
 * varias vencidas. Correrlas todas de golpe en la petición de un
 * usuario cualquiera convertiría su visita en el proceso nocturno del
 * banco; la siguiente visita coge la siguiente.
 */
final class ServicioTareasSistema
{
    /** Qué corre cada clave. El guion vive en `bin/`. */
    private const GUIONES = [
        'revisar-segmentos' => ['revisar-segmentos.php', []],
        'devengar-mora'     => ['devengar-mora.php',     ['--aplicar']],
        'cortar-tarjetas'   => ['cortar-tarjetas.php',   ['--aplicar']],

        /* Tras el corte: inhabilita las tarjetas que vencieron su fecha
           límite de pago todavía por encima del límite, y reactiva las
           que un pago devolvió a su línea. El excedente ya era exigible
           en el mínimo del corte; esto cierra la puerta a seguir
           gastando mientras no se solvente. */
        'inhabilitar-tarjetas-sobregiro' => ['inhabilitar-tarjetas-sobregiro.php', ['--aplicar']],

        /* Recordatorio de pago (credito y tarjeta) y aviso de sobregiro.
           Ambos de-duplican con `avisos_enviados`, asi que correrlos a
           diario no spamea: cada aviso sale una vez por cuota/corte. */
        'recordar-pagos'    => ['recordar-pagos.php',    ['--aplicar']],
        'avisar-sobregiro'  => ['avisar-sobregiro.php',  ['--aplicar']],

        /* Reporte INTERNO (no a clientes) de lo recuperado por
           compensacion. Mensual; va a la casilla reporte.correo_interno. */
        'reporte-compensacion' => ['reporte-compensacion.php', ['--aplicar']],

        'abonar-intereses'  => ['abonar-intereses.php',  ['--abonar']],
        'verificar-cuadre'  => ['verificar-cuadre.php',  []],

        /* La compensación se aplica sola en cuanto entra dinero a una
           cuenta. Este barrido recoge lo que ese disparo no alcanza:
           saldo que entró cuando todavía no había cuota vencida, y
           abonos que entraron por una vía que no pasa por los
           servicios. Sin él, ese dinero se queda quieto mientras al
           cliente le sigue corriendo el recargo. */
        'compensar'         => ['compensar.php',         []],

        /* Cuentas en cero y sin movimiento: primero avisa, y treinta
           días después cierra. Son dos pasadas de la misma tarea, así
           que con correrla a diario basta. */
        'cerrar-inactivas'  => ['cerrar-inactivas.php',  []],

        /* Cobros de pasarela que quedaron a medias. Esta va más
           seguido que las demás —cada quince minutos— porque un pedido
           pagado y sin confirmar tiene al cliente esperando. */
        'conciliar-pasarela' => ['conciliar-pasarela.php', []],

        /* Transferencias a otros bancos. Cada diez minutos, porque la
           ventana de Transfer365 abre a las 00:00:01 y lo que entró de
           noche tiene que salir entonces, no cuando alguien se asome
           al sistema por la mañana. */
        'despachar-interbancarias' => ['despachar-interbancarias.php', ['--aplicar']],

        /* La vigilancia de lavado de dinero. Corre de madrugada y
           SOBRE LO YA OCURRIDO, nunca dentro de la transacción que
           mueve el dinero: un fallo del monitoreo no puede llevarse
           por delante una operación legítima del cliente. */
        'vigilar-cumplimiento' => ['vigilar-cumplimiento.php', ['--aplicar']],

        /* Lo recaudado por pago de servicios es una deuda del banco
           frente a cada empresa hasta que se liquida. Sin esta tarea,
           el saldo de la colecturía crece sin parar y figura como un
           pasivo que nadie mira. */
        'liquidar-colecturia' => ['liquidar-colecturia.php', ['--aplicar']],

        /* Remesas que nadie cobró. No mueve dinero —nunca salió de la
           remesadora— pero deja de decir que están disponibles y avisa
           al beneficiario, que es quien tiene que llamar a quien se la
           envió. */
        'vencer-remesas' => ['vencer-remesas.php', ['--aplicar']],

        /* Depósitos a plazo que vencen. El cliente ya cumplió su
           parte dejando el dinero el tiempo pactado; retenérselo un
           día más sería quedarse con lo que no es nuestro. */
        'vencer-plazos' => ['vencer-plazos.php', ['--aplicar']],

        /* Transferencias programadas y debito automatico. Dos veces
           al dia: a primera hora lo del dia, y a media tarde lo que
           no pudo. Un reintento al dia siguiente llega tarde cuando
           la fecha limite es hoy. */
        'ejecutar-programadas' => ['ejecutar-programadas.php', ['--aplicar']],

        /* Plazos de reclamos. Un reclamo que vence sin que nadie lo
           note no es un retraso interno: es un incumplimiento, y el
           cliente puede llevarlo a la Defensoria con esa fecha. */
        'vigilar-reclamos' => ['vigilar-reclamos.php', ['--aplicar']],

        /* Solicitudes de constancia que nadie pagó en caja. Una
           constancia de saldo certifica el saldo del día en que se
           emite; cobrar tres semanas después una solicitud de hace
           tres semanas le entregaría al cliente un papel que dice algo
           distinto de lo que fue a pedir. */
        'vencer-solicitudes-constancia' => ['vencer-solicitudes-constancia.php', ['--aplicar']],
    ];

    /**
     * Un bloqueo que lleva más de esto se da por muerto.
     *
     * Si el proceso se cae a mitad, la marca de «corriendo» se queda
     * puesta y la tarea no volvería a ejecutarse nunca. Media hora es
     * de sobra para la más lenta y poco para que importe el retraso.
     */
    private const MINUTOS_BLOQUEO_MUERTO = 30;

    /**
     * Engancha la revisión al final de la petición.
     *
     * Se llama una vez por petición, desde el arranque. No consulta
     * nada aquí: registrar el cierre es barato y la consulta se hace
     * cuando el usuario ya tiene su respuesta.
     */
    public static function engancharAlCierre(): void
    {
        static $puesto = false;

        if ($puesto) {
            return;
        }

        $puesto = true;

        /* DESDE LA CONSOLA TAMBIÉN
         *
         * Antes esto se iba de largo en CLI, y ahí estaba el agujero:
         * si el latido moría —un reinicio de la máquina, un cierre de
         * XAMPP— solo volvía con una VISITA WEB. En una instalación sin
         * tráfico podía pasar días muerto, y mientras tanto la mora no
         * se devengaba y los plazos no vencían.
         *
         * Ahora cualquier contacto con el sistema lo revive: una
         * visita, un `php bin/loquesea.php`, una prueba. Lo único que
         * no puede revivirlo es nada, porque no hay nada que no pase
         * por aquí.
         *
         * En consola NO se corre ninguna tarea: solo se comprueba el
         * latido. Correrlas aquí le robaría trabajo al latido y
         * retrasaría un guion que el usuario está esperando. */
        $enConsola = PHP_SAPI === 'cli';

        /* Salvo cuando el guion ES el latido: entonces arrancar otro
           sería tener dos, y dos devengando a la vez cobrarían el
           recargo dos veces. */
        if ($enConsola && self::esElPropioLatido()) {
            return;
        }

        register_shutdown_function(static function () use ($enConsola): void {
            /* ¿Pudimos SOLTAR la respuesta al cliente antes de ponernos a
               trabajar? Solo con fastcgi_finish_request (PHP-FPM). Bajo
               Apache/mod_php —que es lo normal en XAMPP— esa función NO
               existe: la conexión con el navegador sigue abierta mientras
               el shutdown corre, y todo lo que tarde aquí lo espera el
               usuario con la pantalla en blanco o con el velo de carga
               puesto. */
            $desacoplado = false;

            if (!$enConsola && function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
                $desacoplado = true;
            }

            try {
                /* Primero, que haya latido.
                 *
                 * Mientras lata, las tareas corren solas en segundo plano
                 * y esta vía casi nunca hace falta. Es justo lo que se
                 * quiere: que el sistema no dependa de que alguien entre. */
                self::asegurarLatido();

                if ($enConsola) {
                    return;
                }

                /* Correr una tarea AQUÍ bloquea hasta que termine (es un
                   `exec` que espera su salida). Solo es seguro cuando ya
                   soltamos la respuesta al cliente; si no, un guion de
                   minutos —mandar avisos, recalcular— deja al usuario
                   esperando con la pantalla bloqueada, y su petición
                   (incluido el «Pagar con tarjeta») se cuelga.
                   Bajo mod_php, por tanto, NO se corre en línea: el latido
                   de fondo, que acabamos de asegurar, las corre sin colgar
                   a nadie. */
                if ($desacoplado) {
                    (new self())->correrUnaPendiente();
                }
            } catch (\Throwable $e) {
                // Nunca puede tumbar una petición que ya se respondió.
                error_log('[Tareas] ' . $e->getMessage());
            }
        });
    }

    /**
     * ¿El guion que corre ahora mismo es el propio latido?
     *
     * Se mira el archivo que PHP está ejecutando, no un argumento: un
     * argumento se puede falsificar y lo que hay que saber es qué
     * guion es éste de verdad.
     */
    private static function esElPropioLatido(): bool
    {
        $guion = $_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['argv'][0] ?? '');

        return basename((string) $guion) === 'latido.php';
    }

    /**
     * Levanta el latido si no hay ninguno vivo.
     *
     * POR QUÉ DESDE AQUÍ
     *
     * Porque es el único sitio por el que el sistema pasa siempre y
     * que no depende de nada de fuera. Una tarea del Programador de
     * Windows habría que registrarla a mano en cada máquina, y el día
     * que el sistema se mueva o se restaure una copia, nadie se
     * acordaría: las tareas dejarían de correr en silencio.
     *
     * CÓMO NO ESTORBA
     *
     * Esto corre DESPUÉS de haber respondido al usuario, y lo único
     * que hace en el caso normal es leer un archivo pequeño y ver que
     * la fecha es reciente. Arrancar de verdad pasa una vez cada
     * varios días.
     *
     * EL PROCESO QUEDA SUELTO
     *
     * Se lanza de forma que no cuelgue de esta petición: si colgara,
     * moriría con ella y no habría latido ninguno. En Windows eso es
     * `start /B`; en los demás, un `&` y la salida al vacío.
     */
    /**
     * Arranca un latido SIN mirar si hace falta.
     *
     * Lo usa el propio latido al morirse, para dejar a su relevo. En
     * ese momento sabe perfectamente que hace falta —se está yendo él—
     * y los dos frenos de `asegurarLatido()` estorbarían:
     *
     *   · la marca del pid todavía existe o acaba de borrarse, así
     *     que la comprobación de «visto hace poco» podría decir que
     *     hay latido cuando el que hay es el que se está muriendo;
     *
     *   · el cortafuegos de cinco minutos entre intentos dejaría al
     *     sistema sin latido justo ese rato.
     */
    public static function forzarArranque(): void
    {
        self::lanzarLatido();
    }

    public static function asegurarLatido(): void
    {
        $dir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage'
             . DIRECTORY_SEPARATOR . 'latido';

        $marca = $dir . DIRECTORY_SEPARATOR . 'latido.pid';

        if (is_file($marca)) {
            $d = json_decode((string) @file_get_contents($marca), true);

            /* Visto hace poco: hay latido y no hay nada que hacer. */
            if (is_array($d) && time() - (int) ($d['visto'] ?? 0) <= 180) {
                return;
            }
        }

        /* Un cortafuegos para no intentarlo en cada petición si el
           arranque falla —por ejemplo si `exec` está deshabilitado—.
           Sin esto, una instalación donde no se pueda lanzar procesos
           intentaría arrancarlo en todas las visitas. */
        $espera = $dir . DIRECTORY_SEPARATOR . 'ultimo-intento';

        if (is_file($espera) && time() - (int) @file_get_contents($espera) < 300) {
            return;
        }

        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

        @file_put_contents($espera, (string) time());

        self::lanzarLatido();
    }

    /**
     * El lanzamiento en sí.
     *
     * Separado de `asegurarLatido()` porque hay dos clientes con
     * necesidades distintas: el que comprueba antes de lanzar, y el
     * latido moribundo que ya sabe que hace falta.
     */
    private static function lanzarLatido(): void
    {
        if (!function_exists('exec')) {
            error_log('[Latido] no se puede arrancar: exec() no está disponible');
            return;
        }

        $php   = self::binarioDeConsola();
        $guion = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin'
               . DIRECTORY_SEPARATOR . 'latido.php';

        if (!is_file($guion)) { return; }

        try {
            if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
                /* `start /B` deja el proceso corriendo sin ventana y
                   sin atarlo a esta petición. El título vacío entre
                   comillas es obligatorio: sin él, `start` toma la
                   ruta del ejecutable como título y no arranca nada. */
                $cmd = 'start /B "" ' . escapeshellarg($php) . ' '
                     . escapeshellarg($guion) . ' --fondo';

                pclose(popen('cmd /c ' . $cmd, 'r'));
            } else {
                exec(
                    escapeshellarg($php) . ' ' . escapeshellarg($guion)
                    . ' --fondo > /dev/null 2>&1 &'
                );
            }

            error_log('[Latido] arrancado (' . PHP_SAPI . ')');
        } catch (\Throwable $e) {
            error_log('[Latido] no se pudo arrancar: ' . $e->getMessage());
        }
    }

    /**
     * Corre la primera tarea vencida, si la hay.
     *
     * @return string|null La clave de lo que se corrió, o null.
     */
    public function correrUnaPendiente(): ?string
    {
        $t = BaseDatos::unaFila(
            'SELECT id, clave FROM tareas_sistema
              WHERE activa = 1
                AND (proxima_corrida IS NULL OR proxima_corrida <= NOW())
                AND (corriendo_desde IS NULL
                     OR corriendo_desde < DATE_SUB(NOW(), INTERVAL ? MINUTE))
              ORDER BY proxima_corrida IS NOT NULL, proxima_corrida
              LIMIT 1',
            [self::MINUTOS_BLOQUEO_MUERTO]
        );

        if ($t === null) {
            return null;
        }

        return $this->correr((string) $t['clave']) ? (string) $t['clave'] : null;
    }

    /**
     * Corre una tarea concreta.
     *
     * Devuelve false si no pudo tomar el bloqueo, que es el caso normal
     * cuando dos peticiones coinciden: una la corre y la otra sigue.
     */
    public function correr(string $clave): bool
    {
        if (!isset(self::GUIONES[$clave])) {
            return false;
        }

        /* El bloqueo, con un UPDATE condicional. Es atómico: de dos
           peticiones simultáneas, solo una ve `afectadas = 1`. Hacerlo
           con SELECT y luego UPDATE dejaría el hueco por el que las dos
           creerían que les toca. */
        $tomado = BaseDatos::ejecutar(
            'UPDATE tareas_sistema
                SET corriendo_desde = NOW()
              WHERE clave = ? AND activa = 1
                AND (corriendo_desde IS NULL
                     OR corriendo_desde < DATE_SUB(NOW(), INTERVAL ? MINUTE))',
            [$clave, self::MINUTOS_BLOQUEO_MUERTO]
        );

        if ($tomado === 0) {
            return false;
        }

        [$guion, $args] = self::GUIONES[$clave];

        $inicio = microtime(true);
        $estado = 'ok';
        $salida = '';

        try {
            $salida = $this->ejecutar($guion, $args);
        } catch (\Throwable $e) {
            $estado = 'fallo';
            $salida = $e->getMessage();
        }

        $segundos = round(microtime(true) - $inicio, 1);

        BaseDatos::ejecutar(
            'UPDATE tareas_sistema
                SET corriendo_desde = NULL,
                    ultima_corrida  = NOW(),
                    proxima_corrida = ' . $this->expresionProxima() . ',
                    ultimo_estado   = ?,
                    ultimo_resultado = ?,
                    corridas = corridas + 1,
                    fallos   = fallos + ?
              WHERE clave = ?',
            [
                $estado,
                mb_substr(trim($salida), 0, 500) . ' (' . $segundos . 's)',
                $estado === 'fallo' ? 1 : 0,
                $clave,
            ]
        );

        if ($estado === 'fallo') {
            Auditoria::registrar(
                'tarea.fallida', Auditoria::FALLO, 0, 'tarea', $clave,
                ['error' => mb_substr($salida, 0, 190)]
            );
        }

        return true;
    }

    /**
     * Cuándo toca la siguiente.
     *
     * Con hora preferida se busca esa hora del día siguiente; sin ella,
     * se suman los minutos. Se calcula en SQL para que no dependa del
     * reloj de PHP, que puede tener otra zona horaria que la base.
     */
    private function expresionProxima(): string
    {
        return "CASE
                  WHEN hora_preferida IS NOT NULL
                  THEN TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), hora_preferida)
                  ELSE DATE_ADD(NOW(), INTERVAL cada_minutos MINUTE)
                END";
    }

    /**
     * Ejecuta el guion en un proceso aparte.
     *
     * Aparte y no incluido aquí: estos guiones están escritos para
     * consola —hacen `exit`, imprimen, miran `$argv`— e incluirlos
     * dentro de una petición web cortaría la petición en seco al
     * primer `exit`. Además, si uno se cae, se lleva su proceso y no
     * el del usuario.
     */
    private function ejecutar(string $guion, array $args): string
    {
        $php  = self::binarioDeConsola();
        $ruta = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin'
              . DIRECTORY_SEPARATOR . $guion;

        if (!is_file($ruta)) {
            throw new \RuntimeException('No existe ' . $guion);
        }

        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($ruta);

        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg((string) $a);
        }

        $salida = [];
        $codigo = 0;

        exec($cmd . ' 2>&1', $salida, $codigo);

        $texto = trim(implode("\n", $salida));

        /* `verificar-cuadre` devuelve 1 cuando encuentra algo, y eso no
           es que la tarea fallara: es que hizo su trabajo. Se distingue
           por la clave, no por el código. */
        if ($codigo !== 0 && !str_contains($guion, 'verificar-cuadre')) {
            throw new \RuntimeException(
                'salió con código ' . $codigo . ': ' . mb_substr($texto, 0, 300)
            );
        }

        /* De la salida se guarda el final, que es donde los guiones
           ponen el resumen. El principio es la cabecera. */
        $lineas = array_values(array_filter(
            array_map('trim', explode("\n", $texto)),
            static fn(string $l): bool => $l !== ''
        ));

        return implode(' · ', array_slice($lineas, -3));
    }

    /**
     * El binario de PHP de CONSOLA.
     *
     * `PHP_BINARY` no sirve: bajo Apache con mod_php vale `httpd.exe`,
     * y lanzar «httpd.exe guion.php» arranca procesos hijos del
     * servidor web en cascada. Pasó: los cinco guiones «corrieron» a la
     * vez y cada hijo volvía a enganchar la revisión.
     *
     * Se busca el de consola, que es otro programa. Primero lo que diga
     * el parámetro —hay instalaciones donde está en un sitio raro—,
     * luego `PHP_BINDIR`, que apunta al directorio de la instalación
     * aunque corra como módulo, y por último `PHP_BINARY` si de verdad
     * es php.
     */
    private static function binarioDeConsola(): string
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $candidatos = [];

        $puesto = \CashMan\Infraestructura\Parametros::obtener('sistema.php_cli', '');

        if ($puesto !== '') {
            $candidatos[] = $puesto;
        }

        $exe = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';

        if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
            $candidatos[] = PHP_BINDIR . DIRECTORY_SEPARATOR . $exe;
        }

        // Solo si PHP_BINARY es de verdad php y no el servidor web.
        if (PHP_BINARY !== '' && str_contains(strtolower(basename(PHP_BINARY)), 'php')) {
            $candidatos[] = PHP_BINARY;
        }

        foreach ($candidatos as $c) {
            if (is_file($c) && is_executable($c)) {
                return $cache = $c;
            }
        }

        throw new \RuntimeException(
            'No encuentro el PHP de consola. Pongalo en el parametro '
            . '«sistema.php_cli» (por ejemplo C:\xampp\php\php.exe).'
        );
    }
    /**
     * El estado de todas, para enseñarlo.
     *
     * @return list<array<string,mixed>>
     */
    public function estado(): array
    {
        return BaseDatos::todas(
            'SELECT clave, descripcion, cada_minutos, hora_preferida, activa,
                    corriendo_desde, ultima_corrida, proxima_corrida,
                    ultimo_estado, ultimo_resultado, corridas, fallos,
                    (proxima_corrida IS NOT NULL AND proxima_corrida <= NOW()) AS vencida
               FROM tareas_sistema
              ORDER BY hora_preferida, clave'
        );
    }
}
