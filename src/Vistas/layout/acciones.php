<?php
/**
 * La columna de acciones de las tablas.
 *
 * Antes cada listado resolvía esto por su cuenta, y el resultado era
 * desigual: unas tablas traían botones, otras exigían adivinar que la
 * fila entera era un enlace, y en la cartera de créditos no había nada
 * en absoluto —había que saber que se hacía clic en el folio—. Peor
 * aún, cada vista decidía por su cuenta quién ve qué, de modo que una
 * tabla podía ofrecer un botón que el rol de quien mira no puede usar;
 * el servidor lo rechazaba después, con un 403 y sin explicación.
 *
 * Aquí se decide una sola vez. Cada acción declara el permiso que
 * necesita y `cm_acciones()` descarta las que esta persona no tiene
 * antes de dibujar nada. Lo que se ve es lo que se puede hacer.
 *
 * Uso:
 *
 *     <td class="text-right"><?= cm_acciones([
 *         ['Ver',      $rutas->url('creditos/' . $c['codigo']), 'search'],
 *         ['Cobranza', $url, 'warning', 'permiso' => 'cobranza.ver',
 *                                       'si' => $c['vencidas'] > 0],
 *     ]) ?></td>
 *
 * Cada acción es [etiqueta, url, icono] más opciones por clave:
 *
 *   permiso   Una clave, o varias: basta con tener una de ellas.
 *   si        Condición propia de la fila (estado, saldo, propiedad…).
 *   estilo    Color de Bootstrap. Por omisión 'secondary'.
 *   metodo    'post' dibuja un formulario en vez de un enlace.
 *   confirmar Texto de confirmación para las acciones de una sola vía.
 *   csrf      Token, obligatorio en las de método post.
 */

use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;

if (!function_exists('cm_csrf_accion')) {
    /**
     * El token de una acción, aunque la vista no lo pase.
     *
     * Las acciones POST necesitan token. Si la vista no lo tenía a mano
     * —la cartera de créditos, por ejemplo, nunca lo recibió del
     * controlador— los formularios salían con el campo vacío y TODOS
     * los botones de la tabla fallaban con «El token de seguridad
     * venció», que además es un mensaje que manda a recargar cuando
     * recargar no arregla nada.
     *
     * Pedirlo aquí, por propósito, hace imposible ese olvido: el
     * ayudante que dibuja la acción es el mismo que garantiza que
     * pueda ejecutarse.
     */
    function cm_csrf_accion(?string $dado, string $proposito): string
    {
        $dado = trim((string) $dado);

        return $dado !== '' ? $dado : Csrf::token($proposito);
    }
}

if (!function_exists('cm_puede_accion')) {
    /**
     * ¿Esta persona puede usar esta acción?
     *
     * Separada de `cm_acciones()` porque las cabeceras también la
     * necesitan: una columna «Acciones» cuyas filas salen todas vacías
     * es una columna de ancho desperdiciado, así que la vista pregunta
     * antes de dibujar el `<th>`.
     *
     * @param array<string,mixed>|list<mixed> $accion
     */
    function cm_puede_accion(array $accion): bool
    {
        if (array_key_exists('si', $accion) && !$accion['si']) {
            return false;
        }

        $permiso = $accion['permiso'] ?? null;

        if ($permiso === null || $permiso === '' || $permiso === []) {
            return true;
        }

        return is_array($permiso)
            ? Autorizacion::puedeAlguno($permiso)
            : Autorizacion::puede((string) $permiso);
    }
}

if (!function_exists('cm_marca_documento')) {
    /**
     * Marca una acción que entrega un documento.
     *
     * Lo que hay detrás no es una pantalla: es un PDF que el servidor
     * compone al vuelo y que tarda. El navegador lo abre en su
     * pestaña, y estos atributos le dicen al guion de la aplicación
     * que mientras tanto enseñe el indicador de espera —sin eso la
     * pantalla no se inmuta, el usuario cree que no pulsó y vuelve a
     * pulsar, y se generan dos documentos.
     *
     * @param array<string,mixed>|list<mixed> $a
     */
    function cm_marca_documento(array $a): string
    {
        if (empty($a['documento'])) {
            return '';
        }

        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $titulo = is_string($a['documento']) ? $a['documento'] : 'Preparando el documento';

        return ' data-cm-doc data-cm-doc-titulo="' . $e($titulo) . '"'
             . (!empty($a['documento_texto'])
                 ? ' data-cm-doc-texto="' . $e((string) $a['documento_texto']) . '"' : '');
    }
}

if (!function_exists('cm_acciones')) {
    /**
     * Dibuja las acciones que esta persona sí puede usar.
     *
     * Todas van dentro de un desplegable, y eso es deliberado:
     *
     *   OCUPA UN ANCHO FIJO. Con los botones en línea, la columna crecía
     *   según cuántas acciones tuviera cada fila y según el rol de quien
     *   mirase. La cartera de créditos acababa con tres botones por fila
     *   empujando el folio y el cliente fuera de la pantalla, y la tabla
     *   con barra horizontal.
     *
     *   SE VE IGUAL EN TODAS. Antes unas tablas traían botones sueltos,
     *   otras un menú y otras nada, según cuántas acciones hubiera. Ese
     *   «a veces menú, a veces no» obliga a mirar dos veces para saber
     *   dónde está lo que se busca.
     *
     * El precio es un clic de más para la acción más frecuente. Se
     * compensa poniéndola siempre la primera y dejando el menú abierto
     * con el teclado.
     *
     * @param list<array<string,mixed>|list<mixed>> $acciones
     */
    function cm_acciones(array $acciones, int $enLinea = 0): string
    {
        $vivas = array_values(array_filter($acciones, 'cm_puede_accion'));

        /* Un separador no es una acción. Si los permisos dejaron fuera
           todo lo demás, lo que queda es una raya suelta en un menú
           vacío. */
        $reales = array_filter(
            $vivas,
            static fn(array $a): bool => empty($a['separador'])
        );

        if ($reales === []) {
            return '<span class="text-muted">&mdash;</span>';
        }

        return cm_acciones_menu($vivas);
    }
}

if (!function_exists('cm_accion_boton')) {
    /**
     * Una acción suelta: enlace, o formulario si cambia algo.
     *
     * Las que modifican datos van por POST con su token. Un enlace que
     * borra o autoriza se dispara con que alguien lo prefetchee o lo
     * ponga en un `<img src>` ajeno.
     *
     * @param array<string,mixed>|list<mixed> $a
     */
    function cm_accion_boton(array $a): string
    {
        $etiqueta = (string) ($a[0] ?? $a['etiqueta'] ?? '');
        $url      = (string) ($a[1] ?? $a['url'] ?? '#');
        $icono    = (string) ($a[2] ?? $a['icono'] ?? 'info');
        $estilo   = (string) ($a['estilo'] ?? 'secondary');

        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        /* El `title` solo cuando añade algo.
         *
         * Un botón que dice «Ver crédito» con un `title` que dice «Ver
         * el crédito CR-000011» no informa: hace aparecer un recuadro
         * negro sobre la fila siguiente cada vez que el puntero pasa por
         * encima, y en el borde de la ventana sale cortado. El atributo
         * se reserva para lo que el botón no alcanza a decir —el nombre
         * completo detrás de un icono, por ejemplo—. */
        $titulo = (string) ($a['titulo'] ?? '');
        $attrTitulo = ($titulo !== '' && $titulo !== $etiqueta)
            ? ' title="' . $e($titulo) . '"'
            : '';

        $clase = 'btn btn-sm btn-outline-' . $e($estilo) . ' cm-accion';
        $dentro = cm_icono($icono, 14) . '<span>' . $e($etiqueta) . '</span>';

        if (strtolower((string) ($a['metodo'] ?? 'get')) !== 'post') {
            $extra = !empty($a['nueva']) || !empty($a['documento'])
                ? ' target="_blank" rel="noopener"' : '';

            $extra .= cm_marca_documento($a);

            return '<a class="' . $clase . '" href="' . $e($url) . '"'
                 . $attrTitulo . $extra
                 // El texto sí llega a quien usa lector de pantalla, aunque
                 // en pantalla estrecha el <span> se oculte por CSS.
                 . ' aria-label="' . $e($titulo !== '' ? $titulo : $etiqueta) . '">'
                 . $dentro . '</a>';
        }

        $confirmar = (string) ($a['confirmar'] ?? '');
        $campos = '';

        foreach ((array) ($a['campos'] ?? []) as $k => $v) {
            $campos .= '<input type="hidden" name="' . $e((string) $k)
                     . '" value="' . $e((string) $v) . '">';
        }

        /* Por AJAX, y recargando al terminar.
         *
         * Sin `data-cm-ajax` el navegador hace el POST a pelo y se queda
         * en la respuesta del servidor, que es JSON: la pantalla acababa
         * siendo un volcado de `{"ok":true,"mensaje":"Arreglo ARR-…
         * autorizado"}` sobre fondo negro. La operación había salido
         * bien, pero el usuario quedaba fuera del sistema y sin saber si
         * podía volver.
         *
         * Con esto se queda en la página, ve el aviso y la tabla se
         * actualiza. El servidor, además, redirige cuando la petición no
         * es de fondo, por si el JavaScript no llegó a cargar. */
        return '<form method="post" action="' . $e($url) . '" class="d-inline"'
             . ' data-cm-ajax data-cm-recargar>'
             . '<input type="hidden" name="_csrf" value="'
             . $e(cm_csrf_accion($a['csrf'] ?? null, (string) ($a['proposito'] ?? 'panel'))) . '">'
             . '<input type="hidden" name="_csrf_proposito" value="'
             . $e((string) ($a['proposito'] ?? 'panel')) . '">'
             . $campos
             . '<button type="submit" class="' . $clase . '"' . $attrTitulo
             . ' aria-label="' . $e($titulo !== '' ? $titulo : $etiqueta) . '"'
             . ($confirmar !== '' ? ' data-cm-confirmar="' . $e($confirmar) . '"' : '')
             . '>' . $dentro . '</button></form>';
    }
}

if (!function_exists('cm_acciones_menu')) {
    /**
     * Las acciones de una fila, en un desplegable.
     *
     * Cada entrada conserva su naturaleza: las de consulta son enlaces y
     * las que cambian algo siguen siendo formularios POST con su token.
     * Que se vean como líneas de un menú no las convierte en enlaces;
     * un enlace que anula un arreglo se dispara con que alguien lo
     * prefetchee.
     *
     * `data-boundary="window"` porque el menú vive dentro de una celda
     * y las tablas van en `.table-responsive`, que recorta lo que se
     * sale. Sin eso, el desplegable de las últimas filas aparecía
     * cortado por el borde de la tabla.
     *
     * @param list<array<string,mixed>|list<mixed>> $vivas
     */
    function cm_acciones_menu(array $vivas): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $id = 'acc' . bin2hex(random_bytes(5));

        $items = '';

        foreach ($vivas as $a) {
            // Un separador agrupa: «ver / editar» no es lo mismo que
            // «abrir cuenta / emitir tarjeta», y en una lista corrida
            // las dos cosas se leen igual.
            if (!empty($a['separador'])) {
                if ($items !== '' && !str_ends_with($items, '<div class="dropdown-divider"></div>')) {
                    $items .= '<div class="dropdown-divider"></div>';
                }
                continue;
            }

            $etiqueta = (string) ($a[0] ?? $a['etiqueta'] ?? '');
            $url      = (string) ($a[1] ?? $a['url'] ?? '#');
            $icono    = (string) ($a[2] ?? $a['icono'] ?? 'info');
            $estilo   = (string) ($a['estilo'] ?? '');
            $titulo   = (string) ($a['titulo'] ?? '');

            // Las destructivas se marcan en rojo: en una lista de texto
            // todas se parecen, y «Anular» no puede leerse igual que
            // «Ver» cuando están una debajo de otra.
            $clase = 'dropdown-item cm-accion-item'
                   . (in_array($estilo, ['danger', 'warning'], true)
                       ? ' cm-accion-item--ojo' : '')
                   . (($extraClase = trim((string) ($a['clase'] ?? ''))) !== ''
                       ? ' ' . $extraClase : '');

            /* Atributos `data-`. Algunas listas —usuarios, sobre todo—
               enganchan sus acciones desde un script de la propia vista
               por clase, y le pasan el código del registro por aquí. Sin
               esto no se podían mover al desplegable. */
            $datos = '';

            foreach ((array) ($a['datos'] ?? []) as $k => $v) {
                $datos .= ' data-' . $e((string) $k) . '="' . $e((string) $v) . '"';
            }

            if (strtolower((string) ($a['metodo'] ?? 'get')) !== 'post') {
                /* En pestaña nueva, por omisión.
                 *
                 * Quien está en una tabla ha buscado, ha filtrado y ha
                 * llegado hasta la fila que le interesa. Llevárselo de
                 * ahí para enseñarle una ficha le cuesta todo ese
                 * camino cuando vuelve. Antes había que pedirlo con
                 * `nueva` acción por acción, y casi ninguna lo pedía.
                 *
                 * Se excluyen las anclas de la misma página: abrir una
                 * pestaña para bajar un palmo no tiene sentido. Y una
                 * acción puede seguir diciendo `'nueva' => false` si de
                 * verdad quiere reemplazar la página. */
                $navega = $url !== '' && $url !== '#' && !str_starts_with($url, '#')
                       && !str_starts_with($url, 'javascript:');

                $enPestana = array_key_exists('nueva', $a)
                    ? !empty($a['nueva'])
                    : ($navega || !empty($a['documento']));

                $extra = $enPestana ? ' target="_blank" rel="noopener"' : '';

                $extra .= cm_marca_documento($a);

                $items .= '<a class="' . $clase . '" href="' . $e($url) . '"' . $extra . $datos
                        . ($titulo !== '' && $titulo !== $etiqueta
                            ? ' title="' . $e($titulo) . '"' : '')
                        . '>' . cm_icono($icono, 14, 'mr-2') . $e($etiqueta) . '</a>';
                continue;
            }

            $confirmar = (string) ($a['confirmar'] ?? '');
            $campos = '';

            foreach ((array) ($a['campos'] ?? []) as $k => $v) {
                $campos .= '<input type="hidden" name="' . $e((string) $k)
                         . '" value="' . $e((string) $v) . '">';
            }

            $items .= '<form method="post" action="' . $e($url) . '"'
                    . ' data-cm-ajax data-cm-recargar class="m-0">'
                    . '<input type="hidden" name="_csrf" value="'
                    . $e(cm_csrf_accion($a['csrf'] ?? null, (string) ($a['proposito'] ?? 'panel'))) . '">'
                    . '<input type="hidden" name="_csrf_proposito" value="'
                    . $e((string) ($a['proposito'] ?? 'panel')) . '">'
                    . $campos
                    . '<button type="submit" class="' . $clase . '"' . $datos
                    . ($titulo !== '' && $titulo !== $etiqueta
                        ? ' title="' . $e($titulo) . '"' : '')
                    . ($confirmar !== '' ? ' data-cm-confirmar="' . $e($confirmar) . '"' : '')
                    . '>' . cm_icono($icono, 14, 'mr-2') . $e($etiqueta) . '</button>'
                    . '</form>';
        }

        return '<div class="dropdown cm-acciones">'
             . '<button type="button" class="btn btn-sm btn-outline-secondary cm-acciones__btn"'
             . ' data-toggle="dropdown" data-boundary="window" data-display="static"'
             . ' id="' . $id . '"'
             . ' aria-haspopup="true" aria-expanded="false" aria-label="Acciones">'
             . cm_icono('more', 16)
             . '</button>'
             . '<div class="dropdown-menu dropdown-menu-right cm-acciones__menu"'
             . ' aria-labelledby="' . $id . '">'
             . $items . '</div></div>';
    }
}

if (!function_exists('cm_th_acciones')) {
    /**
     * La cabecera, solo si alguna fila va a traer algo.
     *
     * Se le pasan las acciones posibles de la tabla —sin las
     * condiciones de fila, que varían— y decide si la columna tiene
     * sentido para quien mira.
     *
     * @param list<array<string,mixed>|list<mixed>> $posibles
     */
    function cm_th_acciones(array $posibles, string $clase = 'text-right'): string
    {
        foreach ($posibles as $a) {
            unset($a['si']);

            if (cm_puede_accion($a)) {
                return '<th class="' . htmlspecialchars($clase, ENT_QUOTES, 'UTF-8')
                     . '" style="width:1%;white-space:nowrap">Acciones</th>';
            }
        }

        return '';
    }
}
