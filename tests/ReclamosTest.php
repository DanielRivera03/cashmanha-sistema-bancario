<?php

declare(strict_types=1);

/**
 * Reclamos formales.
 *
 * QUÉ COMPRUEBA
 *
 *   · que el plazo se cuenta en DÍAS HÁBILES, como manda la LPC
 *   · que pedir información SUSPENDE el reloj, y que al contestar se
 *     reanuda descontando lo que el cliente tardó
 *   · que la prórroga es una sola y exige motivo
 *   · que una resolución exige explicación de verdad
 *   · que no se puede resolver en contra y devolver dinero a la vez
 *   · que la devolución se asienta en el libro mayor
 *   · que la partida doble cuadra
 *
 * POR QUÉ LO DE LOS DÍAS HÁBILES
 *
 * Contarlo corrido daría una fecha límite posterior a la real, y en un
 * plazo legal ése es el error que NO conviene: haría creer que queda
 * tiempo cuando ya se venció.
 *
 * Todo ocurre dentro de una transacción que se deshace.
 */

use CashMan\Aplicacion\Servicio\ReclamoInvalido;
use CashMan\Aplicacion\Servicio\ServicioReclamos;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Sesion;

Pruebas::grupo('Reclamos · plazos y resolución');

$cliente = BaseDatos::unaFila(
    'SELECT u.id, u.usuario, c.id AS cuenta_id
       FROM usuarios u
       JOIN cuentas c ON c.usuario_id = u.id AND c.es_tecnica = 0 AND c.estado = "activa"
      WHERE u.estado = "activo" ORDER BY u.id LIMIT 1'
);

if ($cliente === null) {
    Pruebas::cierto(false, 'hay un cliente con cuenta para probar');
    return;
}

try {
    BaseDatos::enTransaccion(static function () use ($cliente): void {
        $huella = new ReflectionMethod(Sesion::class, 'huellaCliente');
        $huella->setAccessible(true);

        $uid = (int) $cliente['id'];

        $_SESSION['usuario_id']       = $uid;
        $_SESSION['rol_id']           = 1; // administrador: puede atender y resolver
        $_SESSION['usuario']          = (string) $cliente['usuario'];
        $_SESSION['creada_en']        = time();
        $_SESSION['ultima_actividad'] = time();
        $_SESSION['huella']           = $huella->invoke(null);

        \CashMan\Infraestructura\Seguridad\Autorizacion::limpiarCache();

        $s = new ServicioReclamos();

        // -------------------------------------------------------------
        // 1. Presentar
        // -------------------------------------------------------------
        $corto = false;

        try {
            $s->presentar([
                'asunto' => 'Me cobraron de más',
                'relato' => 'Me cobraron mal.',
            ]);
        } catch (ReclamoInvalido $e) {
            $corto = str_contains($e->getMessage(), 'detalle');
        }

        Pruebas::cierto(
            $corto,
            'un relato de cuatro palabras no sostiene un reclamo: se pide detalle'
        );

        $r = $s->presentar([
            'asunto'      => 'Cobro de comisión que no corresponde',
            'relato'      => 'El 3 de noviembre me cobraron una comisión de doce dólares '
                           . 'por manejo de cuenta, pero mi cuenta es de las que no cobran '
                           . 'esa comisión según el contrato que firmé.',
            'categoria'   => 'comisiones',
            'monto'       => '12.00',
            'objeto_tipo' => 'cuenta',
            'objeto_id'   => (int) $cliente['cuenta_id'],
        ]);

        Pruebas::cierto(
            $r['folio'] !== '' && (int) $r['dias'] === 30,
            'el reclamo se registra con el plazo configurado'
        );

        // --- El plazo va en días HÁBILES ---------------------------
        $vence = new DateTimeImmutable((string) $r['vence_en']);

        Pruebas::cierto(
            (int) $vence->format('N') <= 5,
            'la fecha límite cae en día hábil, nunca en fin de semana'
        );

        /* Treinta días hábiles son más de treinta corridos: si fueran
           iguales, el cómputo estaría mal. */
        $corridos = (int) (new DateTimeImmutable(date('Y-m-d')))->diff($vence)->days;

        Pruebas::cierto(
            $corridos > 30,
            'treinta días hábiles son más de treinta corridos: el cómputo es el de la ley'
        );

        // --- El relato queda como primer mensaje -------------------
        $msgs = $s->mensajes((int) $r['id'], false);

        Pruebas::cierto(
            count($msgs) === 1 && $msgs[0]['de'] === 'cliente',
            'el relato queda como primer mensaje: la conversación se lee de corrido'
        );

        // -------------------------------------------------------------
        // 2. Pedir información suspende el reloj
        // -------------------------------------------------------------
        $s->pedirInformacion(
            (string) $r['folio'],
            'Necesitamos copia del contrato que menciona, para cotejar la condición.'
        );

        $tras = BaseDatos::unaFila(
            'SELECT estado, suspendido_desde, vence_en FROM reclamos WHERE folio = ?',
            [$r['folio']]
        );

        Pruebas::cierto(
            $tras['estado'] === 'espera_cliente' && $tras['suspendido_desde'] !== null,
            'pedir información deja el reclamo esperando y marca desde cuándo'
        );

        $dosVeces = false;

        try {
            $s->pedirInformacion((string) $r['folio'], 'Otra cosa más.');
        } catch (ReclamoInvalido $e) {
            $dosVeces = true;
        }

        Pruebas::cierto(
            $dosVeces,
            'no se puede pedir información dos veces seguidas sin que conteste'
        );

        /* Se simula que el cliente tardó una semana. */
        BaseDatos::ejecutar(
            'UPDATE reclamos SET suspendido_desde = DATE_SUB(CURDATE(), INTERVAL 7 DAY)
              WHERE folio = ?',
            [$r['folio']]
        );

        $venceAntes = (string) BaseDatos::unValor(
            'SELECT vence_en FROM reclamos WHERE folio = ?', [$r['folio']]
        );

        $s->aportar((string) $r['folio'], 'Adjunto el contrato que me dieron al abrir.', $uid);

        $venceDespues = (string) BaseDatos::unValor(
            'SELECT vence_en FROM reclamos WHERE folio = ?', [$r['folio']]
        );

        Pruebas::cierto(
            $venceDespues > $venceAntes,
            'al contestar, el plazo se corre: el reloj no corrió mientras esperábamos'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor(
                'SELECT dias_suspendidos FROM reclamos WHERE folio = ?', [$r['folio']]
            ) === 5,
            'y se anotan los 5 días hábiles que tardó, no los 7 corridos'
        );

        Pruebas::cierto(
            (string) BaseDatos::unValor(
                'SELECT estado FROM reclamos WHERE folio = ?', [$r['folio']]
            ) === 'en_analisis',
            'el reclamo vuelve a estar en análisis'
        );

        // -------------------------------------------------------------
        // 3. La prórroga, una sola vez y con motivo
        // -------------------------------------------------------------
        $sinMotivo = false;

        try {
            $s->prorrogar((string) $r['folio'], 'porque sí');
        } catch (ReclamoInvalido $e) {
            $sinMotivo = str_contains($e->getMessage(), 'por qué');
        }

        Pruebas::cierto($sinMotivo, 'una prórroga sin motivo no pasa: el cliente lo va a leer');

        $s->prorrogar(
            (string) $r['folio'],
            'Hay que pedir el expediente físico a la agencia donde se abrió la cuenta.'
        );

        $segunda = false;

        try {
            $s->prorrogar((string) $r['folio'], 'Y ahora necesitamos todavía más tiempo.');
        } catch (ReclamoInvalido $e) {
            $segunda = str_contains($e->getMessage(), 'ya se prorrogó');
        }

        Pruebas::cierto($segunda, 'y solo se prorroga una vez');

        // -------------------------------------------------------------
        // 4. Resolver
        // -------------------------------------------------------------
        $pobre = false;

        try {
            $s->resolver((string) $r['folio'], true, 'Tiene razón.', 1200);
        } catch (ReclamoInvalido $e) {
            $pobre = str_contains($e->getMessage(), 'detalle');
        }

        Pruebas::cierto(
            $pobre,
            'una resolución de tres palabras no sostiene nada ante la Defensoría'
        );

        $contradictoria = false;

        try {
            $s->resolver(
                (string) $r['folio'],
                false,
                'Se revisó el contrato y la comisión corresponde según la cláusula octava '
                . 'del contrato de cuenta que el cliente firmó.',
                1200
            );
        } catch (ReclamoInvalido $e) {
            $contradictoria = str_contains($e->getMessage(), 'en contra');
        }

        Pruebas::cierto(
            $contradictoria,
            'no se puede resolver en contra y devolver dinero: si se devuelve, procedió'
        );

        $cuentaId = (int) $cliente['cuenta_id'];

        $antes = (int) BaseDatos::unValor(
            'SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]
        );

        $res = $s->resolver(
            (string) $r['folio'],
            true,
            'Se revisó el contrato aportado y, en efecto, la cuenta pertenece a la '
            . 'modalidad exenta de comisión por manejo. El cobro no correspondía y se '
            . 'devuelve íntegro.',
            1200,
            $cuentaId
        );

        Pruebas::cierto(
            $res['estado'] === 'resuelto_favorable',
            'el reclamo queda resuelto a favor'
        );

        Pruebas::cierto(
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId])
                - $antes === 1200,
            'y los $12.00 vuelven a la cuenta del cliente'
        );

        Pruebas::cierto(
            $res['asiento_id'] !== null,
            'con su asiento en el libro mayor, no como un número suelto'
        );

        // -------------------------------------------------------------
        // 5. La partida doble
        // -------------------------------------------------------------
        Pruebas::cierto(
            (int) BaseDatos::unValor(
                'SELECT COUNT(*) FROM (
                     SELECT asiento_id FROM movimientos
                      GROUP BY asiento_id HAVING SUM(importe_cent) <> 0
                 ) x'
            ) === 0,
            'después de todo esto, no hay un solo asiento que no sume cero'
        );

        throw new \RuntimeException('deshacer la prueba');
    });
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== 'deshacer la prueba') {
        throw $e;
    }
}

Pruebas::cierto(
    (int) BaseDatos::unValor('SELECT COUNT(*) FROM reclamos') === 0,
    'la prueba no deja reclamos'
);
