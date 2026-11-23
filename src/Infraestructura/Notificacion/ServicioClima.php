<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Notificacion;

use CashMan\Infraestructura\Config;

/**
 * Widget del clima (OpenWeatherMap), como en el sistema original.
 *
 * Correcciones respecto a v1:
 *
 *   1. La API key estaba escrita en el código fuente y versionada en
 *      el repositorio. Ahora vive en .env.
 *   2. v1 usaba CURLOPT_SSL_VERIFYPEER = false, lo que anula la
 *      validación del certificado y permite interceptar la conexión.
 *      Aquí la verificación está activa.
 *   3. v1 llamaba a la API en CADA carga del panel: con varios
 *      usuarios se agota la cuota gratuita en horas y cada carga
 *      arrastra la latencia de la petición externa. Ahora hay caché
 *      en disco de 15 minutos.
 *   4. Si la API falla o tarda, v1 rompía la página. Aquí se devuelve
 *      null y el panel simplemente no muestra el widget.
 */
final class ServicioClima
{
    private const CACHE_SEG = 900;      // 15 minutos
    private const TIMEOUT   = 4;        // nunca bloquear el panel

    /**
     * @return array<string,mixed>|null null si no hay datos disponibles
     */
    public function obtener(): ?array
    {
        $clave = Config::obtener('CLIMA_API_KEY', '');
        if ($clave === '' || !Config::booleano('CLIMA_HABILITADO', true)) {
            return null;
        }

        $ciudad = Config::obtener('CLIMA_CIUDAD_ID', '3583361'); // San Salvador

        $cache = $this->leerCache($ciudad);
        if ($cache !== null) {
            return $cache;
        }

        $datos = $this->consultarApi($clave, $ciudad);
        if ($datos !== null) {
            $this->guardarCache($ciudad, $datos);
        }

        return $datos;
    }

    /** @return array<string,mixed>|null */
    private function consultarApi(string $clave, string $ciudad): ?array
    {
        $url = 'https://api.openweathermap.org/data/2.5/weather'
             . '?id=' . rawurlencode($ciudad)
             . '&lang=es&units=metric&appid=' . rawurlencode($clave);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            // v1 ponía esto en false: cualquiera en la red podía
            // suplantar la respuesta.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'CashMan/2.0',
        ]);

        $respuesta = curl_exec($ch);
        $codigo    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false || $codigo !== 200) {
            error_log('[Clima] Sin datos (HTTP ' . $codigo . ') ' . $error);
            return null;
        }

        $d = json_decode((string) $respuesta, true);
        if (!is_array($d) || !isset($d['main']['temp'])) {
            return null;
        }

        $icono     = (string) ($d['weather'][0]['icon'] ?? '01d');
        $amanecer  = (int) ($d['sys']['sunrise'] ?? 0);
        $atardecer = (int) ($d['sys']['sunset'] ?? 0);
        $ahora     = time();

        return [
            'ciudad'       => (string) ($d['name'] ?? 'San Salvador'),
            'pais'         => (string) ($d['sys']['country'] ?? 'SV'),
            'temperatura'  => (int) round((float) $d['main']['temp']),
            'sensacion'    => (int) round((float) ($d['main']['feels_like'] ?? $d['main']['temp'])),
            'minima'       => (int) round((float) ($d['main']['temp_min'] ?? 0)),
            'maxima'       => (int) round((float) ($d['main']['temp_max'] ?? 0)),
            'humedad'      => (int) ($d['main']['humidity'] ?? 0),
            'presion'      => (int) ($d['main']['pressure'] ?? 0),
            'viento'       => round(((float) ($d['wind']['speed'] ?? 0)) * 3.6, 1), // m/s → km/h
            'descripcion'  => ucfirst((string) ($d['weather'][0]['description'] ?? '')),
            'icono_api'    => $icono,
            'icono'        => $this->mapearIcono($icono),
            'es_noche'     => str_ends_with($icono, 'n'),
            'amanecer'     => $amanecer > 0 ? date('H:i', $amanecer) : null,
            'atardecer'    => $atardecer > 0 ? date('H:i', $atardecer) : null,
            'actualizado'  => date('H:i', $ahora),
        ];
    }

    /**
     * Traduce el código de OpenWeatherMap al nombre del SVG que ya
     * trae la plantilla en images/icon-weather/.
     *
     * Los nombres estaban inventados: `rain-day`, `rain-night` y
     * `cloudy-night` no existen en ese set, así que cualquier día de
     * lluvia —que en San Salvador son muchos— el icono salía roto, con
     * el texto alternativo partido a media palabra dentro del recuadro.
     * Ahora cada nombre está comprobado contra los archivos que de
     * verdad hay, y lo que no existe cae en el más parecido que sí.
     */
    private function mapearIcono(string $codigo): string
    {
        $noche = str_ends_with($codigo, 'n');
        $base  = substr($codigo, 0, 2);

        $nombre = match ($base) {
            '01'    => $noche ? 'clear-night' : 'clear-day',
            '02'    => $noche ? 'partly-cloudy-night' : 'partly-cloudy-day',
            '03'    => 'cloudy',
            '04'    => $noche ? 'overcast-night' : 'overcast-day',
            '09'    => $noche ? 'overcast-night-drizzle' : 'overcast-day-drizzle',
            '10'    => $noche ? 'overcast-night-rain' : 'overcast-day-rain',
            '11'    => $noche ? 'thunderstorms-night' : 'thunderstorms-day',
            '13'    => $noche ? 'overcast-night-snow' : 'overcast-day-snow',
            '50'    => $noche ? 'fog-night' : 'fog-day',
            default => $noche ? 'clear-night' : 'clear-day',
        };

        /* Última red: si el archivo no está, se usa uno que sí.
         *
         * Un icono roto es peor que un icono genérico, y mantener esta
         * tabla sincronizada a mano con 244 archivos es justo la clase
         * de cosa que vuelve a romperse. Comprobarlo cuesta una llamada
         * a `is_file` sobre un valor cacheado quince minutos. */
        $carpeta = dirname(__DIR__, 3) . '/public/assets/plantilla/images/icon-weather/';

        if (!is_file($carpeta . $nombre . '.svg')) {
            $nombre = $noche ? 'clear-night' : 'clear-day';
        }

        return $nombre;
    }

    /** Saludo según la hora, como en la bienvenida original. */
    public static function saludo(): string
    {
        $h = (int) date('G');
        return match (true) {
            $h < 12 => 'Buenos días',
            $h < 19 => 'Buenas tardes',
            default => 'Buenas noches',
        };
    }

    /** "miércoles, 6 de noviembre de 2026" */
    public static function fechaLarga(): string
    {
        $dias  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
        $meses = ['enero','febrero','marzo','abril','mayo','junio','julio',
                  'agosto','septiembre','octubre','noviembre','diciembre'];

        return $dias[(int) date('w')] . ', ' . (int) date('j')
             . ' de ' . $meses[(int) date('n') - 1] . ' de ' . date('Y');
    }

    // -----------------------------------------------------------------

    private function rutaCache(string $ciudad): string
    {
        return CM_STORAGE . DIRECTORY_SEPARATOR . 'cache'
             . DIRECTORY_SEPARATOR . 'clima-' . preg_replace('/\W/', '', $ciudad) . '.json';
    }

    /** @return array<string,mixed>|null */
    private function leerCache(string $ciudad): ?array
    {
        $ruta = $this->rutaCache($ciudad);

        if (!is_readable($ruta) || (time() - (int) filemtime($ruta)) > self::CACHE_SEG) {
            return null;
        }

        $json = @file_get_contents($ruta);
        if ($json === false) {
            return null;
        }

        $d = json_decode($json, true);
        return is_array($d) ? $d : null;
    }

    /** @param array<string,mixed> $datos */
    private function guardarCache(string $ciudad, array $datos): void
    {
        @file_put_contents(
            $this->rutaCache($ciudad),
            json_encode($datos, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}
