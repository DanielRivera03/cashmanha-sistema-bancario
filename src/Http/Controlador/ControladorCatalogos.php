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
 * Catálogos: productos y límites operativos.
 *
 * El menú enlazaba a /productos y /limites desde el principio, pero las
 * rutas no existían: ambos enlaces daban 404. Esto los implementa,
 * replicando lo que el sistema anterior repartía entre cuatro vistas
 * (`consulta-general-productos`, `consulta-especifica-productos`,
 * `registro-nuevos-productos` y `modificar-productos`) y unificándolas
 * en una sola pantalla con alta y edición en línea.
 *
 * Los límites no existían en el sistema anterior: allí el tope de
 * $2,000 por transferencia estaba escrito en el JavaScript, de modo que
 * bastaba con editar el formulario para saltárselo. Aquí viven en la
 * base, por rol y tipo de operación, y el servidor los aplica.
 */
final class ControladorCatalogos extends ControladorBase
{
    private const TIPOS = [
        'ahorro'    => 'Cuenta de ahorro',
        'corriente' => 'Cuenta corriente',
        'credito'   => 'Producto crediticio',
        'tarjeta'   => 'Tarjeta',
    ];

    /* =================================================================
       PRODUCTOS
       ================================================================= */

    public function productos(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('sistema.configurar');

        $tipo   = $this->get('tipo');
        $buscar = $this->get('q');

        $donde = ['1=1'];
        $args  = [];

        if (isset(self::TIPOS[$tipo])) {
            $donde[] = 'p.tipo = ?';
            $args[]  = $tipo;
        }
        if ($buscar !== '') {
            $donde[] = '(p.nombre LIKE ? OR p.codigo LIKE ? OR p.descripcion LIKE ?)';
            $like = '%' . $buscar . '%';
            $args[] = $like; $args[] = $like; $args[] = $like;
        }

        $productos = BaseDatos::todas(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM cuentas  c WHERE c.producto_id = p.id) AS cuentas,
                    (SELECT COUNT(*) FROM creditos r WHERE r.producto_id = p.id) AS creditos,
                    (SELECT COALESCE(SUM(c.saldo_cent),0) FROM cuentas c
                      WHERE c.producto_id = p.id AND c.es_tecnica = 0) AS saldo_cent
               FROM productos p
              WHERE ' . implode(' AND ', $donde) . '
              ORDER BY p.tipo, p.nombre',
            $args
        );

        $totales = ['productos' => count($productos), 'activos' => 0, 'contratados' => 0, 'saldo' => 0];

        foreach ($productos as &$p) {
            $p['codigo_hash'] = Hashid::codificar((int) $p['id'], 'producto');
            $p['tipo_nombre'] = self::TIPOS[$p['tipo']] ?? $p['tipo'];
            $p['contratados'] = (int) $p['cuentas'] + (int) $p['creditos'];
            $p['en_uso']      = $p['contratados'] > 0;

            if ((int) $p['activo'] === 1) { $totales['activos']++; }
            $totales['contratados'] += $p['contratados'];
            $totales['saldo']       += (int) $p['saldo_cent'];

            unset($p['id']);
        }
        unset($p);

        $this->render('catalogos/productos', [
            'titulo'     => 'Productos · CashMan H.A.',
            'encabezado' => 'Catálogo de productos',
            'productos'  => $productos,
            'totales'    => $totales,
            'tipos'      => self::TIPOS,
            'filtros'    => ['tipo' => $tipo, 'q' => $buscar],
            'csrf'       => Csrf::token('catalogo'),
        ]);
    }

    public function guardarProducto(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        Autorizacion::exigir('sistema.configurar');

        $codigoHash = $this->post('producto');
        $id = $codigoHash !== '' ? $this->idDesde($codigoHash, 'producto') : 0;

        $nombre = mb_substr($this->post('nombre'), 0, 120);
        if (mb_strlen($nombre) < 3) {
            Respuesta::error('El nombre debe tener al menos 3 caracteres.', 422);
        }

        $tipo = $this->post('tipo');
        if (!isset(self::TIPOS[$tipo])) {
            Respuesta::error('Tipo de producto no válido.', 422);
        }

        // La tasa se escribe en porcentaje y se guarda en puntos base,
        // que es un entero: así no hay errores de coma flotante.
        $tasa = (float) str_replace(',', '.', $this->post('tasa', '0'));
        if ($tasa < 0 || $tasa > 200) {
            Respuesta::error('La tasa debe estar entre 0% y 200%.', 422);
        }
        $tasaBp = (int) round($tasa * 100);

        $minimo = (float) str_replace([',', '$'], '', $this->post('minimo', '0'));
        if ($minimo < 0) {
            Respuesta::error('El saldo mínimo no puede ser negativo.', 422);
        }
        $minimoCent = (int) round($minimo * 100);

        $descripcion = mb_substr($this->post('descripcion'), 0, 500);
        $activo      = $this->post('activo') === '1' ? 1 : 0;

        if ($id > 0) {
            $antes = BaseDatos::unaFila('SELECT * FROM productos WHERE id = ?', [$id]);
            if ($antes === null) {
                throw new RecursoNoEncontrado('Producto no encontrado.');
            }

            // Cambiar el tipo de un producto ya contratado descuadraría
            // los contratos existentes: una cuenta de ahorro no puede
            // convertirse en un crédito a posteriori.
            $enUso = (int) BaseDatos::unValor(
                'SELECT (SELECT COUNT(*) FROM cuentas WHERE producto_id = ?)
                      + (SELECT COUNT(*) FROM creditos WHERE producto_id = ?)',
                [$id, $id]
            );

            if ($enUso > 0 && $antes['tipo'] !== $tipo) {
                Respuesta::error(
                    'No se puede cambiar el tipo: el producto ya tiene '
                    . $enUso . ' contrato(s) asociado(s).',
                    422
                );
            }

            BaseDatos::ejecutar(
                'UPDATE productos
                    SET nombre = ?, tipo = ?, descripcion = ?,
                        tasa_interes_bp = ?, saldo_minimo_cent = ?, activo = ?
                  WHERE id = ?',
                [$nombre, $tipo, $descripcion, $tasaBp, $minimoCent, $activo, $id]
            );

            Auditoria::registrar('producto.modificado', Auditoria::EXITO, $usuarioId,
                'producto', (string) $id, [
                    'antes'   => ['nombre' => $antes['nombre'], 'tasa_bp' => $antes['tasa_interes_bp'],
                                  'minimo' => $antes['saldo_minimo_cent'], 'activo' => $antes['activo']],
                    'despues' => ['nombre' => $nombre, 'tasa_bp' => $tasaBp,
                                  'minimo' => $minimoCent, 'activo' => $activo],
                ]);

            Respuesta::exito(null, 'Producto actualizado.');
        }

        // --- Alta ---
        $codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $this->post('codigo')) ?? '');
        if (strlen($codigo) < 3 || strlen($codigo) > 40) {
            Respuesta::error('El código debe tener entre 3 y 40 caracteres alfanuméricos.', 422);
        }

        if (BaseDatos::unValor('SELECT id FROM productos WHERE codigo = ?', [$codigo]) !== null) {
            Respuesta::error('Ya existe un producto con ese código.', 422);
        }

        BaseDatos::ejecutar(
            'INSERT INTO productos
               (codigo, nombre, tipo, descripcion, tasa_interes_bp, saldo_minimo_cent, activo)
             VALUES (?,?,?,?,?,?,?)',
            [$codigo, $nombre, $tipo, $descripcion, $tasaBp, $minimoCent, $activo]
        );
        $nuevoId = BaseDatos::ultimoId();

        Auditoria::registrar('producto.creado', Auditoria::EXITO, $usuarioId,
            'producto', (string) $nuevoId, ['codigo' => $codigo, 'nombre' => $nombre, 'tipo' => $tipo]);

        Respuesta::exito(
            ['codigo' => Hashid::codificar($nuevoId, 'producto')],
            'Producto creado.'
        );
    }

    /**
     * Activa o desactiva un producto.
     *
     * No se borra nunca: un producto con contratos vivos tiene que
     * seguir existiendo para que esos contratos sigan teniendo sentido.
     * Desactivarlo solo impide contratarlo de nuevo.
     */
    public function alternarProducto(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        Autorizacion::exigir('sistema.configurar');

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'producto');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Producto no encontrado.');
        }

        $p = BaseDatos::unaFila('SELECT nombre, activo FROM productos WHERE id = ?', [$id]);
        if ($p === null) {
            throw new RecursoNoEncontrado('Producto no encontrado.');
        }

        $nuevo = (int) $p['activo'] === 1 ? 0 : 1;
        BaseDatos::ejecutar('UPDATE productos SET activo = ? WHERE id = ?', [$nuevo, $id]);

        Auditoria::registrar(
            $nuevo === 1 ? 'producto.activado' : 'producto.desactivado',
            Auditoria::EXITO, $usuarioId, 'producto', (string) $id, ['nombre' => $p['nombre']]
        );

        Respuesta::exito(
            ['activo' => $nuevo === 1],
            $nuevo === 1
                ? 'Producto activado: ya puede contratarse.'
                : 'Producto desactivado: los contratos vigentes siguen su curso.'
        );
    }

    /* =================================================================
       LÍMITES OPERATIVOS
       ================================================================= */

    public function limites(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('sistema.configurar');

        $limites = BaseDatos::todas(
            'SELECT l.*, r.nombre AS rol
               FROM limites_operacion l
               JOIN roles r ON r.id = l.rol_id
              ORDER BY l.tipo_operacion, r.id'
        );

        foreach ($limites as &$l) {
            $l['codigo'] = Hashid::codificar((int) $l['id'], 'limite');
            unset($l['id']);
        }
        unset($l);

        // Agrupadas por operación: así se comparan los roles entre sí,
        // que es la lectura útil.
        $porOperacion = [];
        foreach ($limites as $l) {
            $porOperacion[$l['tipo_operacion']][] = $l;
        }

        $this->render('catalogos/limites', [
            'titulo'       => 'Límites operativos · CashMan H.A.',
            'encabezado'   => 'Límites operativos',
            'porOperacion' => $porOperacion,
            'csrf'         => Csrf::token('catalogo'),
        ]);
    }

    public function guardarLimite(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();
        Autorizacion::exigir('sistema.configurar');

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'limite');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Límite no encontrado.');
        }

        $antes = BaseDatos::unaFila(
            'SELECT l.*, r.nombre AS rol FROM limites_operacion l
               JOIN roles r ON r.id = l.rol_id WHERE l.id = ?',
            [$id]
        );
        if ($antes === null) {
            throw new RecursoNoEncontrado('Límite no encontrado.');
        }

        /** Lee un importe del formulario y lo pasa a centavos. */
        $centavos = function (string $campo) use (&$antes): int {
            $v = (float) str_replace([',', '$', ' '], '', $this->post($campo, '0'));
            return max(0, (int) round($v * 100));
        };

        $porOperacion = $centavos('max_por_operacion');
        $diario       = $centavos('max_diario');
        $mensual      = $centavos('max_mensual');
        $umbral       = $centavos('umbral_doble_control');
        $ops          = max(0, (int) $this->post('max_ops_diarias', '0'));
        $otp          = $this->post('requiere_otp') === '1' ? 1 : 0;
        $activo       = $this->post('activo') === '1' ? 1 : 0;

        // Coherencia: un tope diario menor que el de una sola operación
        // deja el límite por operación sin efecto y confunde al operador.
        if ($diario > 0 && $porOperacion > $diario) {
            Respuesta::error(
                'El máximo por operación no puede superar el máximo diario.',
                422
            );
        }
        if ($mensual > 0 && $diario > $mensual) {
            Respuesta::error('El máximo diario no puede superar el mensual.', 422);
        }

        BaseDatos::ejecutar(
            'UPDATE limites_operacion
                SET max_por_operacion_cent = ?, max_diario_cent = ?, max_mensual_cent = ?,
                    max_ops_diarias = ?, requiere_otp = ?,
                    umbral_doble_control_cent = ?, activo = ?
              WHERE id = ?',
            [$porOperacion, $diario, $mensual, $ops, $otp, $umbral, $activo, $id]
        );

        // Un cambio de límite es una decisión de riesgo: queda con el
        // antes y el después para que se pueda revisar más tarde.
        Auditoria::registrar('limite.modificado', Auditoria::EXITO, $usuarioId,
            'limite', (string) $id, [
                'rol'       => $antes['rol'],
                'operacion' => $antes['tipo_operacion'],
                'antes'     => [
                    'por_operacion' => (int) $antes['max_por_operacion_cent'],
                    'diario'        => (int) $antes['max_diario_cent'],
                    'mensual'       => (int) $antes['max_mensual_cent'],
                    'otp'           => (int) $antes['requiere_otp'],
                ],
                'despues'   => [
                    'por_operacion' => $porOperacion,
                    'diario'        => $diario,
                    'mensual'       => $mensual,
                    'otp'           => $otp,
                ],
            ]);

        Respuesta::exito(null, 'Límite actualizado para ' . $antes['rol'] . '.');
    }
}
