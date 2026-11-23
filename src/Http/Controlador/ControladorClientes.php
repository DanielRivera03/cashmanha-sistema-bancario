<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioRiesgo;
use CashMan\Dominio\Comun\Monto;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Expedientes de clientes (KYC).
 *
 * Los documentos de identidad del cliente se guardan FUERA del
 * webroot. En v1 las fotos del DUI, NIT y firma se subían a
 * vista/images/, servidas directamente por Apache: cualquiera con la
 * URL podía descargar la cédula de un cliente. Aquí se sirven por PHP
 * previa verificación de permiso.
 */
final class ControladorClientes extends ControladorBase
{
    public function listar(array $parametros = []): void
    {
        Autorizacion::exigir('cliente.ver_expediente');

        $q     = trim($this->get('q'));
        $where = '1=1';
        $args  = [];

        if ($q !== '') {
            $where .= ' AND (nombre_completo LIKE ? OR correo LIKE ? OR dui LIKE ? OR usuario LIKE ?)';
            $like = '%' . $q . '%';
            $args = [$like, $like, $like, $like];
        }

        $clientes = BaseDatos::todas(
            "SELECT *, CONCAT(nombres,' ',apellidos) AS nombre_completo
               FROM (SELECT * FROM v_expediente_cliente) t
              WHERE {$where}
              ORDER BY nombres, apellidos
              LIMIT 300",
            $args
        );

        foreach ($clientes as &$c) {
            $c['codigo'] = Hashid::codificar((int) $c['usuario_id'], 'usuario');
            unset($c['usuario_id']);
        }

        $resumen = BaseDatos::unaFila(
            'SELECT COUNT(*) total,
                    SUM(estado = "activo")   activos,
                    SUM(perfil_completo = 1) con_expediente,
                    COALESCE(SUM(saldo_total_cent),0) captaciones
               FROM v_expediente_cliente'
        );

        $this->render('clientes/listar', [
            'titulo'     => 'Clientes · CashMan H.A.',
            'encabezado' => 'Clientes',
            'clientes'   => $clientes,
            'resumen'    => $resumen ?? [],
            'busqueda'   => $q,
            // Qué acciones se ofrecen en la tabla depende del rol.
            'puedeEditar'      => Autorizacion::puede('cliente.editar_expediente'),
            'puedeReferencias' => Autorizacion::puede('referencia.registrar'),
            'puedeCuenta'      => Autorizacion::puede('cuenta.abrir'),
            'puedeTarjeta'     => Autorizacion::puede('tarjeta.emitir'),
            'puedeCredito'     => Autorizacion::puede('solicitud.capturar'),
            'puedeUsuario'     => Autorizacion::puede('usuario.ver_todos'),
        ]);
    }

    public function ver(array $parametros = []): void
    {
        Autorizacion::exigir('cliente.ver_expediente');

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'usuario');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Cliente no encontrado.');
        }

        $c = BaseDatos::unaFila('SELECT * FROM v_expediente_cliente WHERE usuario_id = ?', [$id]);
        if ($c === null) {
            throw new RecursoNoEncontrado('Cliente no encontrado.');
        }

        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, c.saldo_cent, c.estado, p.nombre AS producto
               FROM cuentas c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.es_tecnica = 0 ORDER BY c.id',
            [$id]
        );
        foreach ($cuentas as &$cu) {
            $cu['codigo'] = Hashid::codificar((int) $cu['id'], 'cuenta');
            unset($cu['id']);
        }

        $tarjetas = BaseDatos::todas(
            'SELECT id, ultimos4, marca, tipo, estado, limite_cent, saldo_usado_cent
               FROM tarjetas WHERE usuario_id = ? AND estado <> "cancelada"',
            [$id]
        );
        foreach ($tarjetas as &$t) {
            $t['codigo'] = Hashid::codificar((int) $t['id'], 'tarjeta');
            unset($t['id']);
        }

        $creditos = BaseDatos::todas(
            'SELECT c.id, c.folio, c.monto_cent, c.saldo_capital_cent, c.estado,
                    c.cuotas_pagadas, c.plazo_meses, c.clasificacion, p.nombre AS producto
               FROM creditos c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? ORDER BY c.id DESC',
            [$id]
        );
        foreach ($creditos as &$cr) {
            $cr['codigo'] = Hashid::codificar((int) $cr['id'], 'credito');
            unset($cr['id']);
        }

        $accesos = BaseDatos::todas(
            'SELECT ip, navegador, sistema, dispositivo, exitoso, creado_en
               FROM accesos WHERE usuario_id = ? ORDER BY id DESC LIMIT 20',
            [$id]
        );
        foreach ($accesos as &$a) {
            $a['ip_txt'] = \CashMan\Infraestructura\Seguridad\ClienteIp::desdeBinaria((string) $a['ip']);
            unset($a['ip']);
        }

        $c['codigo'] = Hashid::codificar($id, 'usuario');
        unset($c['usuario_id']);

        Auditoria::registrar('cliente.expediente_visto', Auditoria::EXITO,
            Sesion::usuarioId(), 'cliente', (string) $id);

        $this->render('clientes/ver', [
            'titulo'      => 'Expediente · ' . $c['nombres'] . ' ' . $c['apellidos'],
            'encabezado'  => 'Expediente de ' . $c['nombres'] . ' ' . $c['apellidos'],
            'cliente'     => $c,
            'cuentas'     => $cuentas,
            'tarjetas'    => $tarjetas,
            'creditos'    => $creditos,
            'accesos'     => $accesos,
            /* La clasificación de riesgo, con el detalle de por qué.
             *
             * Se calcula al abrir el expediente en vez de leer la última
             * guardada: quien atiende necesita saber cómo está HOY, no
             * cómo estaba cuando pidió lo último. Las guardadas sirven
             * para auditar una decisión concreta, no para informar de
             * la situación actual. */
            'riesgo'      => (new ServicioRiesgo())->evaluar($id),
            'puedeEditar' => Autorizacion::puede('cliente.editar_expediente'),
            'csrf'        => Csrf::token('cliente'),
        ]);
    }

    public function actualizar(array $parametros = []): void
    {
        Autorizacion::exigir('cliente.editar_expediente');
        $actorId = Sesion::exigirAutenticado();

        $id = $this->idDesde((string) ($parametros['codigo'] ?? ''), 'usuario');
        if ($id === 0) {
            throw new RecursoNoEncontrado('Cliente no encontrado.');
        }

        /* Quién tiene expediente de cliente.
         *
         * Antes era `rol_id = 5`, el identificador del rol «cliente»
         * escrito a número en el código. Dos problemas:
         *
         *   · La ficha SÍ se abría —`v_expediente_cliente` no mira el
         *     rol—, así que el expediente de un empleado con cuenta
         *     propia se podía leer y editar en pantalla, y al guardar
         *     respondía «Cliente no encontrado». El empleado veía que
         *     sus datos no se guardaban y no sabía por qué.
         *
         *   · Y el rol no es lo que define a un cliente: lo define
         *     tener dinero o deuda en el banco. Misma regla que en la
         *     ventanilla. */
        $existe = BaseDatos::unValor(
            'SELECT 1 FROM usuarios u
              WHERE u.id = ?
                AND (
                      EXISTS (SELECT 1 FROM cuentas  c WHERE c.usuario_id = u.id AND c.es_tecnica = 0)
                   OR EXISTS (SELECT 1 FROM tarjetas t WHERE t.usuario_id = u.id)
                   OR EXISTS (SELECT 1 FROM creditos k WHERE k.usuario_id = u.id)
                )',
            [$id]
        );

        if ($existe === null) {
            throw new RecursoNoEncontrado('Cliente no encontrado.');
        }

        // Validación de documentos salvadoreños.
        $dui = preg_replace('/\D/', '', $this->post('dui'));
        if ($dui !== '' && strlen($dui) !== 9) {
            Respuesta::error('El DUI debe tener 9 dígitos.', 422);
        }

        $nit = preg_replace('/\D/', '', $this->post('nit'));
        if ($nit !== '' && strlen($nit) !== 14) {
            Respuesta::error('El NIT debe tener 14 dígitos.', 422);
        }

        $ingreso = 0;
        if ($this->post('ingreso') !== '') {
            try {
                $ingreso = Monto::deEntradaUsuario($this->post('ingreso'))->centavos;
            } catch (\Throwable $e) {
                Respuesta::error('Ingreso mensual inválido.', 422);
            }
        }

        $nac = $this->post('fecha_nacimiento');
        if ($nac !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nac)) {
            Respuesta::error('Fecha de nacimiento inválida.', 422);
        }
        // Mayoría de edad.
        if ($nac !== '' && (new \DateTimeImmutable($nac))->diff(new \DateTimeImmutable())->y < 18) {
            Respuesta::error('El cliente debe ser mayor de edad.', 422);
        }

        BaseDatos::ejecutar(
            'INSERT INTO detalle_usuarios
               (usuario_id, dui, nit, telefono, celular, telefono_trabajo, direccion,
                empresa, cargo, direccion_trabajo, ingreso_mensual_cent,
                fecha_nacimiento, genero, estado_civil, perfil_completo)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
             ON DUPLICATE KEY UPDATE
                dui=VALUES(dui), nit=VALUES(nit), telefono=VALUES(telefono),
                celular=VALUES(celular), telefono_trabajo=VALUES(telefono_trabajo),
                direccion=VALUES(direccion), empresa=VALUES(empresa), cargo=VALUES(cargo),
                direccion_trabajo=VALUES(direccion_trabajo),
                ingreso_mensual_cent=VALUES(ingreso_mensual_cent),
                fecha_nacimiento=VALUES(fecha_nacimiento), genero=VALUES(genero),
                estado_civil=VALUES(estado_civil), perfil_completo=1',
            [
                $id, $dui ?: null, $nit ?: null,
                $this->post('telefono') ?: null,
                $this->post('celular') ?: null,
                $this->post('telefono_trabajo') ?: null,
                mb_substr($this->post('direccion'), 0, 255),
                mb_substr($this->post('empresa'), 0, 255),
                mb_substr($this->post('cargo'), 0, 255),
                mb_substr($this->post('direccion_trabajo'), 0, 255),
                $ingreso,
                $nac ?: null,
                in_array($this->post('genero'), ['M','F','O'], true) ? $this->post('genero') : null,
                mb_substr($this->post('estado_civil'), 0, 30) ?: null,
            ]
        );

        BaseDatos::ejecutar('UPDATE usuarios SET perfil_completo = 1 WHERE id = ?', [$id]);

        Auditoria::registrar('cliente.expediente_actualizado', Auditoria::EXITO,
            $actorId, 'cliente', (string) $id);

        Respuesta::exito(null, 'Expediente actualizado.');
    }
}
