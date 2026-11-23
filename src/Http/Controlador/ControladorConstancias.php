<?php

declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ConstanciaInvalida;
use CashMan\Aplicacion\Servicio\FondosInsuficientes;
use CashMan\Aplicacion\Servicio\ServicioConstancias;
use CashMan\Aplicacion\Servicio\ServicioSolicitudConstancia;
use CashMan\Http\Respuesta;
use CashMan\Infraestructura\Documento\GeneradorPdf;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\AccesoDenegado;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\Hashid;
use CashMan\Infraestructura\Seguridad\LimitadorTasa;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Constancias y su verificación pública.
 *
 * La pantalla de verificación es la única del sistema que NO pide
 * sesión: la usa quien recibe el documento —una embajada, un patrono,
 * otro banco— y ésos no tienen cuenta aquí ni tienen por qué tenerla.
 */
final class ControladorConstancias extends ControladorBase
{
    public function mias(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        Autorizacion::exigir('constancia.emitir_propia');

        $servicio = new ServicioConstancias();

        $this->render('constancias/mias', [
            'titulo'      => 'Constancias · CashMan H.A.',
            'encabezado'  => 'Constancias y solvencias',
            'tipos'       => $servicio->tiposDisponibles(true),
            'constancias' => $servicio->deUsuario($usuarioId),
            'cuentas'     => $this->cuentasDe($usuarioId),
            'creditos'    => $this->creditosDe($usuarioId),
            'tarjetas'    => $this->tarjetasDe($usuarioId),
            'solicitudes' => (new ServicioSolicitudConstancia())->deUsuario($usuarioId),
            'csrf'        => Csrf::token('constancias'),
        ]);
    }

    public function emitir(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        /* Una constancia cuesta dinero y deja rastro: no se emiten
           veinte seguidas por un botón que se quedó pulsado. */
        (new LimitadorTasa(10, 600, 600))->exigir('constancia:' . $usuarioId);

        $objeto = (string) $this->post('objeto');

        /* Con qué paga. La banca en línea ofrece dos: debitar la cuenta
           o cargarlo a una tarjeta CashMan H.A.

           El efectivo no está aquí porque no se puede cobrar efectivo
           por internet: ése se pide en agencia y se paga en caja. */
        $forma = (string) $this->post('forma_pago', 'cuenta');

        if (!in_array($forma, ['cuenta', 'tarjeta'], true)) {
            $forma = 'cuenta';
        }

        try {
            $r = (new ServicioConstancias())->emitir([
                'tipo'         => (string) $this->post('tipo'),
                'objeto_id'    => $objeto !== ''
                    ? $this->idDesde($objeto, (string) $this->post('objeto_espacio', 'cuenta'))
                    : 0,
                'dirigida_a'   => (string) $this->post('dirigida_a'),
                'proposito'    => (string) $this->post('proposito'),
                'forma_pago'   => $forma,
                'cuenta_cargo' => $this->idDesde($this->post('cuenta_cargo'), 'cuenta'),
                'tarjeta_id'   => $this->idDesde((string) $this->post('tarjeta'), 'tarjeta'),
            ]);
        } catch (ConstanciaInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        } catch (FondosInsuficientes $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'fondos_insuficientes']);
            return;
        }

        $servicio = new ServicioConstancias();

        $r['constancias'] = $servicio->deUsuario($usuarioId);
        $r['cuentas']     = $this->cuentasDe($usuarioId);
        $r['tarjetas']    = $this->tarjetasDe($usuarioId);

        Respuesta::exito($r, 'Constancia emitida con folio ' . $r['folio'] . '.');
    }

    /**
     * El PDF.
     *
     * Es la COPIA IMPRESA del registro, no el documento. Lo que vale
     * es el código de verificación que lleva impreso.
     */
    public function pdf(array $parametros = []): void
    {
        $usuarioId = Sesion::exigirAutenticado();

        $c = (new ServicioConstancias())->porFolio((string) ($parametros['folio'] ?? ''));

        if ($c === null) {
            throw new \CashMan\Infraestructura\Seguridad\RecursoNoEncontrado('No existe esa constancia.');
        }

        /* El cajero que acaba de cobrarla tiene que poder imprimirla:
           es lo siguiente que hace, siempre, y el cliente está de pie
           esperando el papel.

           Pedirle «emitir a terceros» para eso le daría además el
           permiso de emitir constancias de cualquier cliente cuando
           quisiera. `constancia.cobrar` es lo que su trabajo necesita,
           ni más ni menos. */
        if ((int) $c['usuario_id'] !== $usuarioId
            && !Autorizacion::puede('constancia.emitir_terceros')
            && !Autorizacion::puede('constancia.cobrar')) {
            throw new AccesoDenegado('Esa constancia no es suya.');
        }

        $pdf = new GeneradorPdf();

        $pdf->configurar(
            mb_strtoupper((string) $c['tipo_nombre']),
            'Documento verificable en línea',
            (string) $c['folio']
        );

        $pdf->AddPage();

        $pdf->bloqueDatos([
            'Cliente'        => $c['cliente'],
            'Documento'      => strtoupper((string) $c['tipo_documento']) . ' '
                              . $c['documento_identidad'],
            'Emitida el'     => date('d/m/Y H:i', (int) strtotime((string) $c['emitida_en'])),
            'Vigente hasta'  => date('d/m/Y', (int) strtotime((string) $c['vigente_hasta'])),
        ], 2);

        $pdf->Ln(4);

        foreach (explode("\n", (string) $c['texto']) as $parrafo) {
            if (trim($parrafo) === '') {
                $pdf->Ln(3);
                continue;
            }

            $pdf->parrafo($parrafo, 9.5);
        }

        $pdf->Ln(6);

        /* El código, en grande y explicado. Es lo que hace que el papel
           valga algo: sin él, cualquiera lo imprime. */
        $pdf->cajaLegal('CÓMO COMPROBAR QUE ESTE DOCUMENTO ES AUTÉNTICO', [
            'Código de verificación:  ' . chunk_split((string) $c['codigo_verificacion'], 4, ' '),
            'Verifíquelo en la página de CashMan H.A., sección «Verificar constancia».',
            'Allí verá este mismo texto, quién lo emitió y si sigue vigente.',
            'Un documento impreso sin código verificable NO fue emitido por esta institución.',
        ]);

        if ($c['estado'] === 'anulada') {
            $pdf->aviso('ESTA CONSTANCIA FUE ANULADA: ' . $c['motivo_anulacion']);
        }

        $pdf->sello(hash('sha256', (string) $c['folio'] . '|' . (string) $c['texto']));

        $nombre = 'constancia-' . strtolower((string) $c['tipo']) . '-' . $c['folio'] . '.pdf';

        $pdf->Output('I', $nombre);
        exit;
    }

    // =================================================================
    // La página pública
    // =================================================================

    public function pantallaVerificar(array $parametros = []): void
    {
        $this->verPublica(null, '');
    }

    /**
     * La verificación va en el marco PÚBLICO, no en el del panel.
     *
     * Quien verifica una constancia no es cliente del banco —es una
     * embajada, un patrono, otro banco— y no tiene por qué ver un
     * menú de productos ni una invitación a iniciar sesión encima del
     * resultado que vino a consultar.
     *
     * @param array<string,mixed>|null $resultado
     */
    private function verPublica(?array $resultado, string $codigo): void
    {
        if (!headers_sent()) {
            /* Lleva token, así que no se cachea. */
            header('Cache-Control: no-store, no-cache, must-revalidate, private');
        }

        Respuesta::html(
            $this->vista->conLayout('constancias/verificar', 'layout/publico', [
                'titulo'     => 'Verificar una constancia · CashMan H.A.',
                'encabezado' => 'Verificar una constancia',
                'resultado'  => $resultado,
                'codigo'     => $codigo,
                'csrf'       => Csrf::token('verificar'),
            ])
        );
    }

    public function verificar(array $parametros = []): void
    {
        /* Sin sesión, así que el freno va por dirección: sin esto,
           alguien podría probar códigos hasta acertar uno. Con doce
           caracteres de un alfabeto de 32 eso es inviable de todas
           formas, pero el freno cuesta poco y cierra la puerta. */
        (new LimitadorTasa(15, 300, 900))->exigir('verificar-constancia');

        $codigo = (string) $this->post('codigo');

        $r = (new ServicioConstancias())->verificar($codigo);

        if (\CashMan\Http\Respuesta::esperaJson()) {
            Respuesta::exito(
                ['resultado' => $r],
                $r === null
                    ? 'No encontramos ninguna constancia con ese código.'
                    : 'Documento auténtico, emitido por CashMan H.A.',
                ['silencio' => true]
            );
            return;
        }

        $this->verPublica($r, $codigo);
    }

    // =================================================================
    // Interno
    // =================================================================

    /** @return array<int,array<string,mixed>> */
    // =================================================================
    // El camino de agencia: atención genera, caja cobra
    // =================================================================

    /**
     * Atención al cliente genera la solicitud y la manda a caja.
     *
     * No cobra nada: atención no tiene gaveta. Lo que sale de aquí es
     * un folio que el cliente lleva a la ventanilla.
     */
    public function solicitar(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();

        (new LimitadorTasa(20, 600, 600))->exigir('constancia-solicitud:' . $actorId);

        $objeto = (string) $this->post('objeto');

        try {
            $r = (new ServicioSolicitudConstancia())->generar([
                'tipo'       => (string) $this->post('tipo'),
                'usuario_id' => $this->idDesde((string) $this->post('cliente'), 'usuario'),
                'objeto_id'  => $objeto !== ''
                    ? $this->idDesde($objeto, (string) $this->post('objeto_espacio', 'cuenta'))
                    : 0,
                'dirigida_a' => (string) $this->post('dirigida_a'),
                'proposito'  => (string) $this->post('proposito'),
            ]);
        } catch (ConstanciaInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        } catch (FondosInsuficientes $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'fondos_insuficientes']);
            return;
        }

        Respuesta::exito(
            $r,
            ($r['directa'] ?? false) === true
                ? 'Constancia emitida con folio ' . $r['folio'] . '. No lleva arancel.'
                : 'Solicitud ' . $r['folio'] . ' generada. El cliente la paga en caja '
                  . 'y ahí mismo se le entrega.'
        );
    }

    /**
     * La pantalla de atención al cliente.
     *
     * Aquí se prepara la constancia de alguien que está de pie en el
     * mostrador. Lo que sale es un folio para la caja; el cobro no se
     * hace aquí porque atención no tiene gaveta.
     */
    public function agencia(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('constancia.emitir_terceros');

        $servicio = new ServicioConstancias();

        $this->render('constancias/agencia', [
            'titulo'      => 'Constancias en agencia · CashMan H.A.',
            'encabezado'  => 'Constancias para un cliente',
            /* Todos los tipos, no solo los de autoservicio: la de
               ingresos la revisa un oficial, y es precisamente aquí
               donde hay uno. */
            'tipos'       => $servicio->tiposDisponibles(false),
            'csrf'        => Csrf::token('constancias-agencia'),
        ]);
    }

    /**
     * Busca al cliente por documento o por número de cuenta.
     *
     * Devuelve lo justo para preparar la constancia —quién es, qué
     * cuentas y qué créditos tiene— y nada más. Una búsqueda que
     * devuelva saldos convierte esta pantalla en un mirador de
     * clientes para cualquiera que tenga el permiso de emitir.
     */
    public function buscarCliente(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();
        Autorizacion::exigir('constancia.emitir_terceros');

        (new LimitadorTasa(40, 60, 120))->exigir('constancia-busca:' . $actorId);

        $texto = trim((string) $this->post('texto'));

        if (mb_strlen($texto) < 4) {
            Respuesta::error('Escriba el documento completo o el número de cuenta.', 422);
            return;
        }

        /* El documento se guarda con guiones y se teclea sin ellos (o
           al revés). Comparar normalizando los dos lados es lo único
           que encuentra al cliente las dos veces. */
        $plano = preg_replace('/\D/', '', $texto) ?? '';

        $u = BaseDatos::unaFila(
            'SELECT u.id, CONCAT(u.nombres," ",u.apellidos) AS nombre,
                    u.documento_identidad, u.tipo_documento
               FROM usuarios u
              WHERE u.estado = "activo"
                /* Cliente del banco es quien tiene un producto aquí, no
                   quien tiene cierto rol: el empleado con cuenta propia
                   también pide constancias, y el empleado sin productos
                   no tiene nada que certificar. Misma regla que en la
                   ventanilla. */
                AND (
                      EXISTS (SELECT 1 FROM cuentas  cx WHERE cx.usuario_id = u.id AND cx.es_tecnica = 0)
                   OR EXISTS (SELECT 1 FROM tarjetas tx WHERE tx.usuario_id = u.id)
                   OR EXISTS (SELECT 1 FROM creditos kx WHERE kx.usuario_id = u.id)
                )
                AND (REPLACE(REPLACE(u.documento_identidad,"-","")," ","") = ?
                     OR EXISTS (SELECT 1 FROM cuentas c
                                 WHERE c.usuario_id = u.id AND c.es_tecnica = 0
                                   AND c.numero_cuenta = ?))
              LIMIT 1',
            [$plano, $plano]
        );

        if ($u === null) {
            Respuesta::error('No encontramos a ese cliente.', 404);
            return;
        }

        $id = (int) $u['id'];

        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, p.nombre AS producto
               FROM cuentas c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.estado = "activa" AND c.es_tecnica = 0
              ORDER BY c.id',
            [$id]
        );

        foreach ($cuentas as &$c) {
            $c['codigo'] = Hashid::codificar((int) $c['id'], 'cuenta');
            unset($c['id']);
        }

        unset($c);

        $creditos = BaseDatos::todas(
            'SELECT c.id, c.folio, p.nombre AS producto
               FROM creditos c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ?
              ORDER BY c.id DESC',
            [$id]
        );

        foreach ($creditos as &$cr) {
            $cr['codigo'] = Hashid::codificar((int) $cr['id'], 'credito');
            unset($cr['id']);
        }

        unset($cr);

        Respuesta::exito([
            'codigo'    => Hashid::codificar($id, 'usuario'),
            'nombre'    => $u['nombre'],
            'documento' => strtoupper((string) $u['tipo_documento']) . ' '
                         . $u['documento_identidad'],
            'cuentas'   => $cuentas,
            'creditos'  => $creditos,
            'pendientes' => (new ServicioSolicitudConstancia())->deUsuario($id, 5),
        ]);
    }

    /** Lo que la caja tiene pendiente de cobrar. */
    public function bandejaCaja(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('constancia.cobrar');

        $this->render('constancias/caja', [
            'titulo'     => 'Constancias por cobrar · CashMan H.A.',
            'encabezado' => 'Constancias por cobrar',
            'pendientes' => (new ServicioSolicitudConstancia())->pendientes(),
            'csrf'       => Csrf::token('constancias-caja'),
        ]);
    }

    /** El cajero recibe el dinero y la constancia nace. */
    public function cobrarSolicitud(array $parametros = []): void
    {
        $actorId = Sesion::exigirAutenticado();

        (new LimitadorTasa(40, 600, 600))->exigir('constancia-cobro:' . $actorId);

        try {
            $r = (new ServicioSolicitudConstancia())->cobrar(
                (string) $this->post('folio'),
                (string) $this->post('medio', 'efectivo')
            );
        } catch (ConstanciaInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        } catch (FondosInsuficientes $e) {
            Respuesta::error($e->getMessage(), 422, ['codigo' => 'fondos_insuficientes']);
            return;
        }

        $r['pendientes'] = (new ServicioSolicitudConstancia())->pendientes();

        Respuesta::exito(
            $r,
            'Cobrado. La constancia ' . $r['folio'] . ' ya se puede imprimir y entregar.'
        );
    }

    /** Se anula la solicitud que el cliente ya no quiere pagar. */
    public function anularSolicitud(array $parametros = []): void
    {
        Sesion::exigirAutenticado();

        try {
            (new ServicioSolicitudConstancia())->anular(
                (string) $this->post('folio'),
                (string) $this->post('motivo')
            );
        } catch (ConstanciaInvalida $e) {
            Respuesta::error($e->getMessage(), 422);
            return;
        }

        Respuesta::exito([], 'Solicitud anulada. No se cobró nada.');
    }

    /**
     * Las tarjetas CashMan con las que el cliente puede pagar.
     *
     * Solo las nuestras. El arancel no se cobra con tarjeta de otro
     * banco: pasarlo por una red de adquirencia le costaría al banco
     * una comisión del 2% al 3% por cobrar su propio arancel, que en
     * una constancia de $5.00 se lo come entero.
     *
     * @return array<int,array<string,mixed>>
     */
    private function tarjetasDe(int $usuarioId): array
    {
        $tarjetas = (new ServicioConstancias())->tarjetasParaPagar($usuarioId);

        foreach ($tarjetas as &$t) {
            $t['codigo'] = Hashid::codificar((int) $t['id'], 'tarjeta');
            unset($t['id']);
        }

        return $tarjetas;
    }

    /** @return array<int,array<string,mixed>> */
    private function cuentasDe(int $usuarioId): array
    {
        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, c.saldo_cent, c.saldo_retenido_cent,
                    p.nombre AS producto, p.saldo_minimo_cent
               FROM cuentas c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.estado = "activa" AND c.es_tecnica = 0
              ORDER BY c.id',
            [$usuarioId]
        );

        foreach ($cuentas as &$c) {
            $c['codigo'] = Hashid::codificar((int) $c['id'], 'cuenta');

            $c['disponible_cent'] = max(
                0,
                (int) $c['saldo_cent'] - (int) $c['saldo_retenido_cent'] - (int) $c['saldo_minimo_cent']
            );

            unset($c['id']);
        }

        return $cuentas;
    }

    /** @return array<int,array<string,mixed>> */
    private function creditosDe(int $usuarioId): array
    {
        $creditos = BaseDatos::todas(
            'SELECT c.id, c.folio, c.saldo_capital_cent, c.estado, p.nombre AS producto
               FROM creditos c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ?
              ORDER BY c.id DESC',
            [$usuarioId]
        );

        foreach ($creditos as &$c) {
            $c['codigo'] = Hashid::codificar((int) $c['id'], 'credito');
            unset($c['id']);
        }

        return $creditos;
    }
}
