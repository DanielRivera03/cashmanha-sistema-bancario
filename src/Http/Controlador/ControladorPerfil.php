<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\ClienteIp;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Perfil propio y gestión de dispositivos.
 */
final class ControladorPerfil extends ControladorBase
{
    public function ver(array $parametros = []): void
    {
        $id = Sesion::exigirAutenticado();

        $u = BaseDatos::unaFila(
            'SELECT u.*, r.nombre AS rol, r.descripcion AS rol_desc
               FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?',
            [$id]
        );
        if ($u === null) {
            throw new \RuntimeException('Perfil no disponible.');
        }

        // Nunca enviar material sensible a la vista.
        unset($u['password_hash'], $u['totp_secreto_cifrado'], $u['id']);

        $u['ultimo_acceso_ip_txt'] = $u['ultimo_acceso_ip'] !== null
            ? ClienteIp::desdeBinaria((string) $u['ultimo_acceso_ip']) : '—';
        unset($u['ultimo_acceso_ip']);

        $detalle = BaseDatos::unaFila('SELECT * FROM detalle_usuarios WHERE usuario_id = ?', [$id]);

        $accesos = BaseDatos::todas(
            'SELECT ip, navegador, sistema, dispositivo, exitoso, creado_en
               FROM accesos WHERE usuario_id = ? ORDER BY id DESC LIMIT 15',
            [$id]
        );
        foreach ($accesos as &$a) {
            $a['ip_txt'] = ClienteIp::desdeBinaria((string) $a['ip']);
            unset($a['ip']);
        }

        $this->render('perfil/ver', [
            'titulo'     => 'Mi perfil · CashMan H.A.',
            'encabezado' => 'Mi perfil',
            'perfil'     => $u,
            'detalle'    => $detalle,
            'accesos'    => $accesos,
            'csrf'       => Csrf::token('perfil'),
        ]);
    }

    public function actualizar(array $parametros = []): void
    {
        $id = Sesion::exigirAutenticado();

        $correo = mb_strtolower(trim($this->post('correo')));
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            Respuesta::error('Correo electrónico inválido.', 422);
        }

        $ocupado = BaseDatos::unValor(
            'SELECT 1 FROM usuarios WHERE correo = ? AND id <> ?', [$correo, $id]
        );
        if ($ocupado !== null) {
            Respuesta::error('Ese correo ya está en uso por otra cuenta.', 422);
        }

        $correoActual = (string) BaseDatos::unValor('SELECT correo FROM usuarios WHERE id = ?', [$id]);

        BaseDatos::ejecutar(
            'UPDATE usuarios SET correo = ?, telefono = ? WHERE id = ?',
            [$correo, $this->post('telefono') ?: null, $id]
        );

        // El cambio de correo es relevante para la seguridad de la
        // cuenta: queda en la bitácora con el valor anterior.
        if ($correo !== $correoActual) {
            Auditoria::registrar('perfil.correo_cambiado', Auditoria::EXITO, $id,
                'usuario', (string) $id, ['anterior' => $correoActual, 'nuevo' => $correo]);
        } else {
            Auditoria::registrar('perfil.actualizado', Auditoria::EXITO, $id, 'usuario', (string) $id);
        }

        Respuesta::exito(null, 'Perfil actualizado.');
    }

    /** Sesiones abiertas del usuario, con opción de cerrarlas. */
    public function sesiones(array $parametros = []): void
    {
        $id = Sesion::exigirAutenticado();

        $sesiones = BaseDatos::todas(
            'SELECT id, ip, user_agent, creada_en, ultima_actividad, expira_en,
                    revocada_en, motivo_revocacion
               FROM sesiones WHERE usuario_id = ?
              ORDER BY ultima_actividad DESC LIMIT 40',
            [$id]
        );

        $actual = hash('sha256', (string) session_id());

        foreach ($sesiones as &$s) {
            $s['ip_txt']  = ClienteIp::desdeBinaria((string) $s['ip']);
            $s['activa']  = $s['revocada_en'] === null
                && new \DateTimeImmutable((string) $s['expira_en']) > new \DateTimeImmutable();
            $s['es_esta'] = hash_equals((string) $s['id'], $actual);
            unset($s['ip'], $s['id']);
        }

        $this->render('perfil/sesiones', [
            'titulo'     => 'Mis dispositivos · CashMan H.A.',
            'encabezado' => 'Mis dispositivos y sesiones',
            'sesiones'   => $sesiones,

            /* Los dispositivos son otra cosa que las sesiones: una
               sesión dura horas, un dispositivo es el aparato desde el
               que se entra una y otra vez. Cuando alguien sospecha, lo
               que quiere mirar es la lista de aparatos. */
            'dispositivos' => (new \CashMan\Aplicacion\Servicio\ServicioFactorOperacion())
                ->dispositivosDe($id),

            'csrf'       => Csrf::token('perfil'),
        ]);
    }

    /**
     * «Éste no fui yo».
     *
     * No cierra sesiones —para eso está el otro botón— pero hace que
     * ese dispositivo deje de contar como conocido: volverá a pedir
     * factor en cada operación y a avisar en cada entrada.
     */
    public function desconocerDispositivo(array $parametros = []): void
    {
        $id = Sesion::exigirAutenticado();

        $servicio = new \CashMan\Aplicacion\Servicio\ServicioFactorOperacion();

        try {
            $servicio->desconocer((int) ($parametros['id'] ?? 0), $id);
        } catch (\RuntimeException $e) {
            \CashMan\Http\Respuesta::error($e->getMessage(), 404);
            return;
        }

        \CashMan\Http\Respuesta::exito(
            ['dispositivos' => $servicio->dispositivosDe($id)],
            'Marcado como ajeno. Desde ahora pedirá código en cada operación y le '
            . 'avisaremos cada vez que entre desde ahí. Si no reconoce ese acceso, '
            . 'cambie su contraseña.'
        );
    }

    /** Cierra todas las demás sesiones, dejando viva solo la actual. */
    public function cerrarOtras(array $parametros = []): void
    {
        $id  = Sesion::exigirAutenticado();
        $sid = hash('sha256', (string) session_id());

        $n = BaseDatos::ejecutar(
            'UPDATE sesiones SET revocada_en = NOW(), motivo_revocacion = "cierre_por_usuario"
              WHERE usuario_id = ? AND id <> ? AND revocada_en IS NULL',
            [$id, $sid]
        );

        Auditoria::registrar('perfil.sesiones_cerradas', Auditoria::EXITO, $id,
            'usuario', (string) $id, ['cerradas' => $n]);

        Respuesta::exito(['cerradas' => $n],
            $n > 0 ? "Se cerraron {$n} sesión(es) en otros dispositivos."
                   : 'No había otras sesiones abiertas.');
    }

    /**
     * Guarda el tema que eligió, para que le acompañe.
     *
     * El navegador ya lo tiene aplicado y guardado en local cuando esto
     * llega: lo que se gana aquí es que al abrir desde otro dispositivo
     * la banca salga como la dejó, sin volver a elegir.
     *
     * No devuelve aviso de éxito a propósito. El usuario acaba de ver
     * la pantalla entera cambiar de color: esa ES la confirmación, y un
     * mensaje encima sobra.
     */
    public function tema(array $parametros = []): void
    {
        $id   = Sesion::exigirAutenticado();
        $tema = $this->post('tema');

        if (!in_array($tema, ['dia', 'noche', 'auto'], true)) {
            Respuesta::error('Tema no válido.', 422);
            return;
        }

        BaseDatos::ejecutar('UPDATE usuarios SET tema = ? WHERE id = ?', [$tema, $id]);

        Respuesta::exito(['tema' => $tema], '');
    }
}
