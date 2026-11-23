<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Dominio\Comun\Folio;
use CashMan\Dominio\Comun\Monto;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Almacenamiento\ArchivoInvalido;
use CashMan\Infraestructura\Almacenamiento\ServicioArchivos;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * El panel de la tienda: comercios, catálogo, precios y fotos.
 *
 * Una tienda cuyo catálogo solo se puede cambiar editando migraciones no
 * es una tienda, es una maqueta. Aquí se da de alta un comercio, se
 * publica un artículo, se corrige un precio, se ajustan existencias y se
 * sube la foto, sin tocar la base de datos a mano.
 *
 * Sobre las fotos: los archivos subidos NO se guardan en el directorio
 * público. Van al almacén privado y se sirven por `imagen()`, que
 * comprueba el tipo real antes de mandarlos. Un archivo dentro del
 * webroot es un archivo que el servidor podría llegar a ejecutar, y una
 * tienda donde cualquiera con acceso al panel puede subir ficheros es
 * justo donde eso no se puede permitir.
 */
final class ControladorTiendaAdmin extends ControladorBase
{
    /** Subcarpeta del almacén privado. */
    private const CATEGORIA = 'tienda';

    public function panel(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('tienda.gestionar');

        $comercioSel = $this->get('c');
        $cid = $comercioSel !== '' ? Hashid::decodificar($comercioSel, 'comercio') : null;

        $comercios = BaseDatos::todas(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM articulos a WHERE a.comercio_id = c.id) AS articulos,
                    (SELECT COUNT(*) FROM articulos a
                      WHERE a.comercio_id = c.id AND a.activo = 1 AND a.existencias > 0) AS disponibles,
                    (SELECT COALESCE(SUM(a.existencias), 0) FROM articulos a
                      WHERE a.comercio_id = c.id) AS unidades
               FROM comercios c ORDER BY c.orden, c.nombre'
        );

        foreach ($comercios as &$c) {
            $c['codigo'] = Hashid::codificar((int) $c['id'], 'comercio');
        }
        unset($c);

        $donde = $cid !== null ? 'a.comercio_id = ?' : '1=1';
        $args  = $cid !== null ? [$cid] : [];

        $articulos = BaseDatos::todas(
            "SELECT a.*, c.nombre AS comercio, c.iniciales, c.color,
                    c.id AS comercio_id
               FROM articulos a JOIN comercios c ON c.id = a.comercio_id
              WHERE {$donde}
              ORDER BY c.orden, a.nombre
              LIMIT 400",
            $args
        );

        foreach ($articulos as &$a) {
            $a['codigo'] = Hashid::codificar((int) $a['id'], 'articulo');
            $a['comercio_codigo'] = Hashid::codificar((int) $a['comercio_id'], 'comercio');
        }
        unset($a);

        // Lo que importa de un vistazo: qué se está agotando.
        $alertas = BaseDatos::todas(
            'SELECT a.nombre, a.sku, a.existencias, c.nombre AS comercio
               FROM articulos a JOIN comercios c ON c.id = a.comercio_id
              WHERE a.activo = 1 AND a.existencias <= 5
              ORDER BY a.existencias, a.nombre LIMIT 20'
        );

        $totales = BaseDatos::unaFila(
            'SELECT COUNT(*) AS articulos,
                    SUM(activo = 1) AS activos,
                    SUM(existencias = 0) AS agotados,
                    COALESCE(SUM(existencias), 0) AS unidades,
                    COALESCE(SUM(precio_cent * existencias), 0) AS inventario_cent
               FROM articulos'
        ) ?? [];

        $this->render('tienda/admin', [
            'titulo'      => 'Panel de la tienda · CashMan H.A.',
            'encabezado'  => 'Panel de la tienda',
            'comercios'   => $comercios,
            'articulos'   => $articulos,
            'alertas'     => $alertas,
            'totales'     => $totales,
            'comercioSel' => $comercioSel,
            'csrf'        => Csrf::token('tienda_admin'),
        ]);
    }

    // =================================================================
    // COMERCIOS
    // =================================================================

    public function guardarComercio(array $parametros = []): void
    {
        Autorizacion::exigir('tienda.gestionar');
        $actorId = Sesion::exigirAutenticado();

        $destino = $this->enrutador->url('tienda/admin');

        $nombre = trim($this->post('nombre'));

        if (mb_strlen($nombre) < 3) {
            $this->responderSegunCliente(false, 'El nombre del comercio es muy corto.',
                $destino, null, [], 422);
            return;
        }

        $codigo = $this->post('codigo');
        $id     = $codigo !== '' ? Hashid::decodificar($codigo, 'comercio') : null;

        // Dos letras y un color: con eso se dibuja el monograma, sin
        // depender de que alguien suba un logo.
        $iniciales = mb_strtoupper(mb_substr(
            preg_replace('/[^A-Za-zÁÉÍÓÚÑáéíóúñ]/u', '', $this->post('iniciales')) ?: $nombre,
            0,
            2
        ));

        $color = $this->post('color');

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            $color = '#6418c3';
        }

        $datos = [
            $nombre,
            mb_substr(trim($this->post('rubro')), 0, 60) ?: 'Comercio',
            mb_substr(trim($this->post('categoria')), 0, 40) ?: 'varios',
            mb_substr(trim($this->post('descripcion')), 0, 400) ?: null,
            $iniciales !== '' ? $iniciales : 'CM',
            $color,
            max(0, min(36, $this->postEntero('cuotas'))),
            Monto::deEntradaUsuario($this->post('envio', '0'))->centavos,
            $this->post('activo') === '1' ? 1 : 0,
            max(0, min(999, $this->postEntero('orden'))),
        ];

        try {
            if ($id !== null && $id > 0) {
                BaseDatos::ejecutar(
                    'UPDATE comercios
                        SET nombre = ?, rubro = ?, categoria = ?, descripcion = ?,
                            iniciales = ?, color = ?, cuotas_sin_intereses = ?,
                            envio_cent = ?, activo = ?, orden = ?
                      WHERE id = ?',
                    [...$datos, $id]
                );

                $accion = 'tienda.comercio_editado';
            } else {
                $clave = mb_strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $nombre) ?: 'COM');

                BaseDatos::ejecutar(
                    'INSERT INTO comercios
                       (codigo, nombre, rubro, categoria, descripcion, iniciales, color,
                        cuotas_sin_intereses, envio_cent, activo, orden)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [mb_substr($clave, 0, 30), ...$datos]
                );

                $id = BaseDatos::ultimoId();
                $accion = 'tienda.comercio_creado';
            }
        } catch (\PDOException $e) {
            $this->responderSegunCliente(
                false,
                'Ya existe un comercio con ese nombre. Elija otro, por favor.',
                $destino,
                null,
                [],
                422
            );
            return;
        }

        Auditoria::registrar($accion, Auditoria::EXITO, $actorId,
            'comercio', (string) $id, ['nombre' => $nombre]);

        $this->responderSegunCliente(true, 'Comercio «' . $nombre . '» guardado.', $destino);
    }

    // =================================================================
    // ARTÍCULOS
    // =================================================================

    public function guardarArticulo(array $parametros = []): void
    {
        Autorizacion::exigir('tienda.gestionar');
        $actorId = Sesion::exigirAutenticado();

        $destino = $this->enrutador->url('tienda/admin');

        $comercioId = Hashid::decodificar($this->post('comercio'), 'comercio');

        if ($comercioId === null || $comercioId <= 0) {
            $this->responderSegunCliente(false, 'Elija a qué comercio pertenece.',
                $destino, null, [], 422);
            return;
        }

        $nombre = trim($this->post('nombre'));

        if (mb_strlen($nombre) < 3) {
            $this->responderSegunCliente(false, 'El nombre del artículo es muy corto.',
                $destino, null, [], 422);
            return;
        }

        try {
            $precio = Monto::deEntradaUsuario($this->post('precio'));
            $antes  = Monto::deEntradaUsuario($this->post('antes', '0'));
        } catch (\Throwable $e) {
            $this->responderSegunCliente(false, 'El precio no es válido.',
                $destino, null, [], 422);
            return;
        }

        if (!$precio->esPositivo()) {
            $this->responderSegunCliente(false, 'El precio debe ser mayor que cero.',
                $destino, null, [], 422);
            return;
        }

        /* El precio «antes» tiene que ser mayor que el actual.
         *
         * Si no, la tarjeta del artículo anunciaría un descuento
         * negativo: «-0%» o, peor, «-15%» cuando en realidad subió. Es
         * el tipo de detalle que destruye la confianza en una tienda. */
        if ($antes->centavos > 0 && $antes->centavos <= $precio->centavos) {
            $this->responderSegunCliente(
                false,
                'El precio anterior debe ser mayor que el precio actual, '
                . 'o déjelo en cero si no es una oferta.',
                $destino,
                null,
                [],
                422
            );
            return;
        }

        $codigo = $this->post('articulo');
        $id     = $codigo !== '' ? Hashid::decodificar($codigo, 'articulo') : null;

        $datos = [
            $comercioId,
            $nombre,
            mb_substr(trim($this->post('descripcion')), 0, 500) ?: null,
            mb_substr(trim($this->post('marca')), 0, 60) ?: null,
            mb_substr(trim($this->post('categoria')), 0, 60) ?: 'general',
            $precio->centavos,
            $antes->centavos,
            max(0, min(99999, $this->postEntero('existencias'))),
            $this->post('activo') === '1' ? 1 : 0,
        ];

        try {
            if ($id !== null && $id > 0) {
                BaseDatos::ejecutar(
                    'UPDATE articulos
                        SET comercio_id = ?, nombre = ?, descripcion = ?, marca = ?,
                            categoria = ?, precio_cent = ?, antes_cent = ?,
                            existencias = ?, activo = ?
                      WHERE id = ?',
                    [...$datos, $id]
                );

                $accion = 'tienda.articulo_editado';
            } else {
                // El SKU se arma del comercio y un correlativo: un código
                // tecleado a mano acaba repetido tarde o temprano.
                $prefijo = mb_strtoupper(mb_substr(
                    preg_replace('/[^A-Za-z]/', '', (string) BaseDatos::unValor(
                        'SELECT nombre FROM comercios WHERE id = ?', [$comercioId]
                    )) ?: 'ART',
                    0,
                    3
                ));

                // `articulos.sku` lleva indice unico: el choque no es
                // teorico, aborta el alta del articulo.
                $sku = Folio::unico($prefijo, 'articulos', 'sku', '', 3);

                BaseDatos::ejecutar(
                    'INSERT INTO articulos
                       (comercio_id, sku, nombre, descripcion, marca, categoria,
                        precio_cent, antes_cent, existencias, activo)
                     VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$comercioId, $sku, ...array_slice($datos, 1)]
                );

                $id = BaseDatos::ultimoId();
                $accion = 'tienda.articulo_creado';
            }
        } catch (\PDOException $e) {
            $this->responderSegunCliente(false, 'No se pudo guardar el artículo.',
                $destino, null, [], 500);
            return;
        }

        Auditoria::registrar($accion, Auditoria::EXITO, $actorId,
            'articulo', (string) $id,
            ['nombre' => $nombre, 'precio' => $precio->aDecimal(),
             'existencias' => $datos[7]]);

        $this->responderSegunCliente(
            true,
            'Artículo «' . $nombre . '» guardado.',
            $destino,
            ['codigo' => Hashid::codificar($id, 'articulo')]
        );
    }

    /** Sube o reemplaza la foto de un artículo. */
    public function subirFoto(array $parametros = []): void
    {
        Autorizacion::exigir('tienda.gestionar');
        $actorId = Sesion::exigirAutenticado();

        $destino = $this->enrutador->url('tienda/admin');

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'articulo');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Artículo no encontrado.');
        }

        $a = BaseDatos::unaFila('SELECT id, nombre, imagen FROM articulos WHERE id = ?', [$id]);

        if ($a === null) {
            throw new RecursoNoEncontrado('Artículo no encontrado.');
        }

        $archivos = new ServicioArchivos();

        try {
            $guardado = $archivos->guardar($_FILES['foto'] ?? [], self::CATEGORIA);
        } catch (ArchivoInvalido $e) {
            $this->responderSegunCliente(false, $e->getMessage(), $destino, null, [], 422);
            return;
        }

        /* Si no es una imagen, fuera.
         *
         * El servicio ya acepta solo tipos conocidos, pero entre ellos
         * está el PDF, que vale para un expediente y no para la foto de
         * un producto: la etiqueta <img> no lo dibujaría y el artículo
         * quedaría con un hueco. */
        if (!str_starts_with($guardado['tipo'], 'image/')) {
            $archivos->eliminar($guardado['nombre'], self::CATEGORIA);

            $this->responderSegunCliente(
                false,
                'La foto tiene que ser una imagen (JPG, PNG o WebP).',
                $destino,
                null,
                [],
                422
            );
            return;
        }

        // La anterior se borra: si no, cada reemplazo dejaría un archivo
        // huérfano que nadie va a limpiar nunca.
        $anterior = (string) ($a['imagen'] ?? '');

        BaseDatos::ejecutar(
            'UPDATE articulos SET imagen = ?, imagen_alt = ? WHERE id = ?',
            [
                $guardado['nombre'],
                mb_substr(trim($this->post('alt')) ?: (string) $a['nombre'], 0, 190),
                $id,
            ]
        );

        if ($anterior !== '' && !str_starts_with($anterior, 'assets/')) {
            $archivos->eliminar($anterior, self::CATEGORIA);
        }

        Auditoria::registrar('tienda.foto_subida', Auditoria::EXITO, $actorId,
            'articulo', (string) $id,
            ['nombre' => $a['nombre'], 'archivo' => $guardado['nombre'],
             'tamano' => $guardado['tamano']]);

        $this->responderSegunCliente(
            true,
            'Foto actualizada para «' . $a['nombre'] . '».',
            $destino
        );
    }

    /**
     * Sirve la foto de un artículo.
     *
     * Los archivos subidos viven fuera del directorio público: un archivo
     * dentro del webroot es un archivo que el servidor podría llegar a
     * ejecutar, y en una tienda donde el panel admite subidas eso no se
     * puede permitir. Se sirven desde aquí, comprobando el tipo real.
     */
    public function imagen(array $parametros = []): void
    {
        $nombre = (string) ($parametros['nombre'] ?? '');

        /* Solo lo que el almacén nombra: ni rutas, ni subidas de nivel.
         *
         * El `..` se rechaza aparte aunque la ruta no admita barras:
         * una defensa que depende de que el enrutador siga siendo como
         * es hoy no es una defensa, es una coincidencia. */
        if (preg_match('/^[A-Za-z0-9._-]{1,190}$/', $nombre) !== 1
            || str_contains($nombre, '..')) {
            throw new RecursoNoEncontrado('Imagen no encontrada.');
        }

        $archivos = new ServicioArchivos();
        $ruta = $archivos->rutaDe($nombre, self::CATEGORIA);

        if ($ruta === null || !is_readable($ruta)) {
            throw new RecursoNoEncontrado('Imagen no encontrada.');
        }

        $mime = $archivos->tipoMime($ruta);

        if (!str_starts_with($mime, 'image/')) {
            throw new RecursoNoEncontrado('Imagen no encontrada.');
        }

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) filesize($ruta));
            // La foto de un producto cambia poco y la piden todos los
            // visitantes: una semana de caché ahorra mucho tráfico.
            header('Cache-Control: public, max-age=604800, immutable');
            header('X-Content-Type-Options: nosniff');
        }

        readfile($ruta);
        exit;
    }

    public function darBaja(array $parametros = []): void
    {
        Autorizacion::exigir('tienda.gestionar');
        $actorId = Sesion::exigirAutenticado();

        $destino = $this->enrutador->url('tienda/admin');

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'articulo');

        if ($id === 0) {
            throw new RecursoNoEncontrado('Artículo no encontrado.');
        }

        $a = BaseDatos::unaFila('SELECT nombre, activo FROM articulos WHERE id = ?', [$id]);

        if ($a === null) {
            throw new RecursoNoEncontrado('Artículo no encontrado.');
        }

        /* No se borra: se despublica.
         *
         * Hay pedidos que lo referencian, y borrarlo dejaría un historial
         * de compras apuntando a la nada. Despublicado desaparece del
         * catálogo y los pedidos antiguos siguen diciendo qué se vendió. */
        $nuevo = (int) $a['activo'] === 1 ? 0 : 1;

        BaseDatos::ejecutar('UPDATE articulos SET activo = ? WHERE id = ?', [$nuevo, $id]);

        Auditoria::registrar('tienda.articulo_publicacion', Auditoria::EXITO, $actorId,
            'articulo', (string) $id,
            ['nombre' => $a['nombre'], 'activo' => $nuevo]);

        $this->responderSegunCliente(
            true,
            '«' . $a['nombre'] . '» ' . ($nuevo === 1 ? 'volvió al catálogo.' : 'salió del catálogo.'),
            $destino
        );
    }
}
