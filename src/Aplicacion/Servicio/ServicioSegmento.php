<?php
declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Infraestructura\Config;

use CashMan\Infraestructura\Seguridad\Auditoria;

use CashMan\Infraestructura\Notificacion\Correo;

use CashMan\Infraestructura\Notificacion\PlantillaCorreo;

use CashMan\Dominio\Cliente\Segmento;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * Reúne lo que hace falta para segmentar a un cliente y lo guarda.
 *
 * El segmento se recalcula al consultarlo, no se deja envejecer en una
 * columna: un cliente que retiró su dinero ayer no puede seguir siendo
 * patrimonial hoy. Lo que se guarda es el resultado y la fecha, para
 * poder responder «desde cuándo es preferente» y para que una pantalla
 * que lista mil clientes no tenga que recalcular mil veces.
 */
final class ServicioSegmento
{
    private ServicioRiesgo $riesgo;

    public function __construct(?ServicioRiesgo $riesgo = null)
    {
        $this->riesgo = $riesgo ?? new ServicioRiesgo();
    }

    /**
     * El segmento de hoy, con el porqué y lo que concede.
     *
     * @return array<string,mixed>
     */
    public function de(int $usuarioId): array
    {
        $hechos = $this->patrimonio($usuarioId);
        $banda  = $this->riesgo->evaluar($usuarioId);

        $hechos['banda'] = (string) $banda['banda'];

        $r = Segmento::clasificar($hechos);

        $r['beneficios'] = Segmento::beneficios($r['segmento']);
        $r['visibles']   = Segmento::beneficiosVisibles($r['segmento']);
        $r['banda']      = (string) $banda['banda'];
        $r['puntaje']    = (int) $banda['puntaje'];

        $r['depositos_cent'] = $hechos['depositos']->centavos;
        $r['linea_cent']     = $hechos['linea']->centavos;
        $r['ingreso_cent']   = $hechos['ingreso']->centavos;
        $r['meses_cliente']  = (int) $hechos['meses_cliente'];

        $this->guardar($usuarioId, $r);

        return $r;
    }

    /**
     * Lo que el segmento concede, para quien solo necesita el número.
     *
     * Lo usan la emisión de tarjetas, el diferido y el motor de riesgo:
     * cada uno pide el suyo sin tener que saber de segmentos.
     */
    public function beneficio(int $usuarioId, string $cual): mixed
    {
        $b = Segmento::beneficios($this->cacheado($usuarioId));

        return $b[$cual] ?? null;
    }

    /**
     * Cuántas veces su ingreso disponible puede llegar a ser su línea.
     *
     * Es el beneficio `linea_veces_ingreso` del segmento: 3 para el
     * estándar, 4 para el preferente, 5 para el patrimonial. Sale de
     * aquí y no del parámetro general porque el parámetro general no
     * sabe de segmentos, y usarlo era lo que dejaba a un cliente
     * patrimonial con el mismo techo que a uno recién llegado.
     *
     * Se devuelve al menos 1: un múltiplo de cero dejaría a todo el
     * mundo sin línea si alguien se equivocara al editar la tabla.
     */
    public function vecesIngreso(int $usuarioId): int
    {
        return max(1, (int) $this->beneficio($usuarioId, 'linea_veces_ingreso'));
    }

    /**
     * El segmento guardado, sin recalcular.
     *
     * Para los sitios donde recalcular sería caro y basta con lo del
     * último cierre: un listado de cartera, un correo masivo.
     *
     * OJO: esto es lo que lee `beneficio()`, y por tanto lo que
     * aplican la tarjeta, el diferido y el sobregiro. Si nadie
     * recalcula, un cliente que acaba de cumplir las condiciones sigue
     * sin sus beneficios hasta que algo llame a `de()`. Para eso está
     * `revisarCartera()`, que corre una vez al día desde
     * `bin/revisar-segmentos.php`.
     */
    public function cacheado(int $usuarioId): string
    {
        $s = (string) (BaseDatos::unValor(
            'SELECT segmento FROM usuarios WHERE id = ?', [$usuarioId]
        ) ?? '');

        return $s !== '' ? $s : Segmento::ESTANDAR;
    }

    /**
     * Recalcula a toda la cartera.
     *
     * Pensado para correr una vez al día: los ascensos y descensos de
     * segmento no deberían depender de que el cliente entre a mirar.
     * Y hasta que esto corra, `cacheado()` —y con él la tasa de la
     * tarjeta, el sobregiro y el diferido— sigue aplicando el segmento
     * anterior, así que no es un adorno: es lo que mantiene vivo todo
     * lo demás.
     *
     * @param  bool $avisar Si se le comunica al cliente. En falso se
     *         recalcula igual y se devuelve lo que se habría enviado,
     *         que es como se prueba esto sin escribirle a nadie.
     * @return array{revisados:int, cambios:list<array<string,mixed>>}
     */
    public function revisarCartera(bool $avisar = true): array
    {
        $clientes = BaseDatos::todas(
            'SELECT u.id, u.usuario, u.correo, u.nombres, u.apellidos,
                    COALESCE(u.segmento, "estandar") AS antes
               FROM usuarios u
               JOIN roles r ON r.id = u.rol_id
              WHERE r.nombre = "Cliente" AND u.estado = "activo"'
        );

        $cambios = [];

        foreach ($clientes as $c) {
            $r = $this->de((int) $c['id']);

            if ($r['segmento'] === $c['antes']) { continue; }

            $cambio = [
                'usuario_id' => (int) $c['id'],
                'usuario'    => (string) $c['usuario'],
                'nombre'     => trim($c['nombres'] . ' ' . $c['apellidos']),
                'correo'     => (string) $c['correo'],
                'antes'      => (string) $c['antes'],
                'ahora'      => (string) $r['segmento'],
                'asciende'   => Segmento::rango($r['segmento']) > Segmento::rango((string) $c['antes']),
                'avisado'    => false,
            ];

            if ($avisar) {
                $cambio['avisado'] = $this->avisarCambio($cambio, $r);
            }

            $cambios[] = $cambio;
        }

        return ['revisados' => count($clientes), 'cambios' => $cambios];
    }

    /**
     * Le cuenta al cliente que su categoría cambió.
     *
     * Un ascenso cambia la tasa de sus créditos, el interés de sus
     * tarjetas en cada corte, el techo de su línea, cuántas compras
     * puede diferir y si tiene una persona asignada. Nada de eso se
     * comunicaba: se anotaba en su ficha y el cliente se enteraba si
     * entraba a la pantalla y se fijaba en que el sello era otro.
     *
     * El descenso se avisa igual. Callarlo sería peor: el cliente
     * descubriría por su cuenta que una condición que tenía ya no
     * está, sin saber por qué ni qué hacer para recuperarla.
     *
     * Falla en silencio a propósito. Que el correo no salga no puede
     * deshacer un cambio de segmento que ya está aplicado y que afecta
     * a lo que el cliente paga; se deja constancia y la revisión
     * sigue con el resto de la cartera.
     *
     * @param array<string,mixed> $cambio
     * @param array<string,mixed> $estado Lo que devolvió `de()`.
     */
    private function avisarCambio(array $cambio, array $estado): bool
    {
        if (!filter_var($cambio['correo'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $html = PlantillaCorreo::cambioSegmento(
                (string) $cambio['nombre'],
                Segmento::etiqueta((string) $cambio['ahora']),
                Segmento::etiqueta((string) $cambio['antes']),
                // La MISMA lista que lee su pantalla y que aplican los
                // servicios. Escribir una segunda aquí es la forma más
                // segura de prometer por correo algo que no se hace.
                Segmento::beneficiosVisibles((string) $cambio['ahora']),
                $this->motivosDe($estado),
                rtrim((string) Config::obtener('APP_URL', ''), '/') . '/preferente',
                (bool) $cambio['asciende']
            );

            (new Correo())->enviar(
                (string) $cambio['correo'],
                $cambio['asciende']
                    ? 'Bienvenido a Banca ' . Segmento::etiqueta((string) $cambio['ahora'])
                    : 'Actualización de su categoría',
                $html
            );

            return true;
        } catch (\Throwable $e) {
            Auditoria::registrar(
                'segmento.aviso_fallido',
                Auditoria::FALLO,
                (int) $cambio['usuario_id'],
                'usuario',
                (string) $cambio['usuario_id'],
                ['error' => mb_substr($e->getMessage(), 0, 190)]
            );

            return false;
        }
    }

    /**
     * Por qué le corresponde este escalón.
     *
     * Un cambio de categoría sin motivo parece arbitrario, y si el
     * cliente no sabe qué lo produjo tampoco sabe qué hacer para
     * conservarlo. Son los mismos hechos que midió la clasificación.
     *
     * @param  array<string,mixed> $e
     * @return list<string>
     */
    private function motivosDe(array $e): array
    {
        $m = [];

        if (($e['depositos_cent'] ?? 0) > 0) {
            $m[] = 'Saldo en depósitos de '
                 . Monto::deCentavos((int) $e['depositos_cent'])->formateado() . '.';
        }

        if (($e['linea_cent'] ?? 0) > 0) {
            $m[] = 'Línea de tarjeta vigente de '
                 . Monto::deCentavos((int) $e['linea_cent'])->formateado() . '.';
        }

        if (($e['ingreso_cent'] ?? 0) > 0) {
            $m[] = 'Ingreso mensual registrado de '
                 . Monto::deCentavos((int) $e['ingreso_cent'])->formateado() . '.';
        }

        if (($e['meses_cliente'] ?? 0) > 0) {
            $m[] = (int) $e['meses_cliente'] . ' meses de relación con la institución.';
        }

        if (($e['banda'] ?? '') !== '') {
            $m[] = 'Clasificación de crédito ' . $e['banda'] . '.';
        }

        return $m;
    }

    // =================================================================

    /**
     * Lo que el cliente tiene con la institución.
     *
     * @return array<string,mixed>
     */
    private function patrimonio(int $usuarioId): array
    {
        $depositos = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(saldo_cent),0) FROM cuentas
              WHERE usuario_id = ? AND es_tecnica = 0 AND estado = "activa"',
            [$usuarioId]
        );

        $linea = (int) BaseDatos::unValor(
            'SELECT COALESCE(SUM(limite_cent),0) FROM tarjetas
              WHERE usuario_id = ? AND tipo = "credito" AND estado = "activa"',
            [$usuarioId]
        );

        $ingreso = (int) (BaseDatos::unValor(
            'SELECT COALESCE(ingreso_mensual_cent,0) FROM detalle_usuarios WHERE usuario_id = ?',
            [$usuarioId]
        ) ?? 0);

        /* Antigüedad: desde su PRIMER producto, no desde la fila del
         * usuario.
         *
         * La fila se crea cuando se carga el sistema o cuando alguien
         * captura el expediente, y eso puede ser años después de que
         * la persona sea cliente. Medir desde ahí deja en «0 meses» a
         * un cliente con ocho cuotas pagadas, que es un disparate. La
         * relación empieza cuando se le abre el primer producto. */
        $meses = (int) (BaseDatos::unValor(
            'SELECT TIMESTAMPDIFF(MONTH, LEAST(
                      u.creado_en,
                      COALESCE((SELECT MIN(c.apertura_en) FROM cuentas c
                                 WHERE c.usuario_id = u.id AND c.es_tecnica = 0), u.creado_en),
                      COALESCE((SELECT MIN(t.emitida_en) FROM tarjetas t
                                 WHERE t.usuario_id = u.id), u.creado_en),
                      COALESCE((SELECT MIN(cr.desembolsado_en) FROM creditos cr
                                 WHERE cr.usuario_id = u.id), u.creado_en)
                    ), NOW())
               FROM usuarios u WHERE u.id = ?',
            [$usuarioId]
        ) ?? 0);

        return [
            'depositos'     => Monto::deCentavos($depositos),
            'linea'         => Monto::deCentavos($linea),
            'ingreso'       => Monto::deCentavos($ingreso),
            'meses_cliente' => $meses,
        ];
    }

    /** @param array<string,mixed> $r */
    private function guardar(int $usuarioId, array $r): void
    {
        $actual = (string) (BaseDatos::unValor(
            'SELECT COALESCE(segmento,"") FROM usuarios WHERE id = ?', [$usuarioId]
        ) ?? '');

        if ($actual === (string) $r['segmento']) {
            return;   // sin cambio: no se toca la fecha de ascenso
        }

        BaseDatos::ejecutar(
            'UPDATE usuarios SET segmento = ?, segmento_desde = NOW() WHERE id = ?',
            [(string) $r['segmento'], $usuarioId]
        );
    }
}
