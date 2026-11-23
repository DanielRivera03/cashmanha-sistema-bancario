<?php
declare(strict_types=1);

namespace CashMan\Http;

/**
 * Enrutador con rutas limpias.
 *
 * Antes:  /CashManHA/controlador/cGestionesCashman.php?cashmanhagestion=inicioadministradores
 * Ahora:  /panel
 *
 * Antes:  /CashManHA/controlador/cGestionesCashman.php?cashmanhagestion=...&idcuentas=5
 * Ahora:  /cuentas/k7Nq2mP9
 *
 * La estructura interna (controlador, modelo, vista) deja de ser visible:
 * no es solo estética, también deja de regalar información sobre cómo
 * está construido el sistema y qué archivos existen.
 */
final class Enrutador
{
    /** @var array<string, array<int, array{patron:string, parametros:array<int,string>, manejador:callable|array, middleware:array<int,string>}>> */
    private array $rutas = [];

    /** @var array<int,string> */
    private array $middlewareGlobal = [];

    private string $prefijoBase = '';

    public function __construct(string $prefijoBase = '')
    {
        $this->prefijoBase = rtrim($prefijoBase, '/');
    }

    /** @param array<int,string> $middleware */
    public function get(string $ruta, callable|array $manejador, array $middleware = []): self
    {
        return $this->agregar('GET', $ruta, $manejador, $middleware);
    }

    /** @param array<int,string> $middleware */
    public function post(string $ruta, callable|array $manejador, array $middleware = []): self
    {
        return $this->agregar('POST', $ruta, $manejador, $middleware);
    }

    /** @param array<int,string> $middleware */
    public function put(string $ruta, callable|array $manejador, array $middleware = []): self
    {
        return $this->agregar('PUT', $ruta, $manejador, $middleware);
    }

    /** @param array<int,string> $middleware */
    public function delete(string $ruta, callable|array $manejador, array $middleware = []): self
    {
        return $this->agregar('DELETE', $ruta, $manejador, $middleware);
    }

    public function middlewareGlobal(string $middleware): self
    {
        $this->middlewareGlobal[] = $middleware;
        return $this;
    }

    /** @param array<int,string> $middleware */
    private function agregar(string $metodo, string $ruta, callable|array $manejador, array $middleware): self
    {
        $parametros = [];

        // Convierte "/cuentas/{codigo}" en una expresión regular y
        // recoge los nombres de los parámetros.
        $patron = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            function (array $m) use (&$parametros): string {
                $parametros[] = $m[1];
                // Por defecto solo caracteres seguros para una URL.
                $regex = $m[2] ?? '[A-Za-z0-9_-]+';
                return '(' . $regex . ')';
            },
            '/' . trim($ruta, '/')
        );

        $this->rutas[$metodo][] = [
            'patron'     => '#^' . rtrim($patron, '/') . '/?$#',
            'parametros' => $parametros,
            'manejador'  => $manejador,
            'middleware' => $middleware,
        ];

        return $this;
    }

    /**
     * Resuelve la petición actual.
     *
     * @return array{manejador:callable|array, parametros:array<string,string>, middleware:array<int,string>}|null
     */
    public function resolver(string $metodo, string $uri): ?array
    {
        $ruta = $this->normalizar($uri);

        // Permite que formularios HTML envíen PUT/DELETE vía _method.
        if ($metodo === 'POST' && isset($_POST['_method'])) {
            $sobrescrito = strtoupper((string) $_POST['_method']);
            if (in_array($sobrescrito, ['PUT', 'DELETE', 'PATCH'], true)) {
                $metodo = $sobrescrito;
            }
        }

        // Las rutas SIN parámetros se evalúan primero.
        //
        // Sin esto, el orden de registro decide: si /cuentas/{codigo}
        // se declara antes que /cuentas/abrir, la primera captura
        // "abrir" como si fuera un identificador y la segunda nunca
        // se alcanza. Ordenar aquí hace que el orden de registro deje
        // de importar.
        $definiciones = $this->rutas[$metodo] ?? [];
        usort($definiciones, static fn(array $a, array $b): int =>
            count($a['parametros']) <=> count($b['parametros'])
        );

        foreach ($definiciones as $definicion) {
            if (!preg_match($definicion['patron'], $ruta, $coincidencias)) {
                continue;
            }

            array_shift($coincidencias);
            $parametros = [];
            foreach ($definicion['parametros'] as $i => $nombre) {
                $parametros[$nombre] = $coincidencias[$i] ?? '';
            }

            return [
                'manejador'  => $definicion['manejador'],
                'parametros' => $parametros,
                'middleware' => array_merge($this->middlewareGlobal, $definicion['middleware']),
            ];
        }

        return null;
    }

    /** ¿Existe la ruta con otro método? Permite responder 405 en lugar de 404. */
    public function metodosPermitidos(string $uri): array
    {
        $ruta      = $this->normalizar($uri);
        $permitidos = [];

        foreach ($this->rutas as $metodo => $definiciones) {
            foreach ($definiciones as $d) {
                if (preg_match($d['patron'], $ruta)) {
                    $permitidos[] = $metodo;
                    break;
                }
            }
        }

        return $permitidos;
    }

    private function normalizar(string $uri): string
    {
        // Quitar query string.
        $ruta = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Quitar el prefijo de instalación (p. ej. /cashmanha-v2/public).
        if ($this->prefijoBase !== '' && str_starts_with($ruta, $this->prefijoBase)) {
            $ruta = substr($ruta, strlen($this->prefijoBase));
        }

        $ruta = '/' . trim($ruta, '/');

        // Normalizar barras repetidas: //panel///x → /panel/x
        $ruta = (string) preg_replace('#/+#', '/', $ruta);

        return $ruta === '/' ? '/' : rtrim($ruta, '/');
    }

    /** Construye una URL absoluta a partir de una ruta interna. */
    public function url(string $ruta = '/'): string
    {
        return $this->prefijoBase . '/' . ltrim($ruta, '/');
    }
}
