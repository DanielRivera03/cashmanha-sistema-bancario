<?php

declare(strict_types=1);

/**
 * Constancias, solvencias y referencias bancarias.
 *
 * QUÉ COMPRUEBA
 *
 *   · que el texto que sale impreso dice lo que de verdad hay
 *   · que los datos se CONGELAN al emitir y no se recalculan después
 *   · que el código de verificación es único y no se puede adivinar
 *   · que la página pública confirma el documento sin ser un buscador
 *     de clientes
 *   · que el arancel se cobra con su asiento
 *   · que una constancia anulada lo dice al verificarla
 *
 * POR QUÉ LO DE CONGELAR
 *
 * Una constancia de saldo dice lo que el saldo era ESE día. Si al
 * verificarla se recalculara, diría otra cosa, y la embajada que la
 * recibió pensaría que el cliente le mintió.
 *
 * Todo ocurre dentro de una transacción que se deshace.
 */

use CashMan\Aplicacion\Servicio\ConstanciaInvalida;
use CashMan\Aplicacion\Servicio\ServicioConstancias;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Sesion;

Pruebas::grupo('Constancias · lo que el banco certifica');

$aranceles = BaseDatos::todas('SELECT * FROM constancia_aranceles ORDER BY tipo');

Pruebas::cierto(
    count($aranceles) === 5,
    'hay cinco tipos de constancia definidos'
);

$noAdeudo = null;

foreach ($aranceles as $a) {
    if ($a['tipo'] === 'no_adeudo') {
        $noAdeudo = $a;
    }
}

Pruebas::cierto(
    $noAdeudo !== null && (int) $noAdeudo['comision_cent'] === 0,
    'la de no adeudo va gratis: cobrar por certificar que ya no debe nada es de mal gusto'
);

/* Lo que YA habia antes de la prueba. */
$constanciasAntes = (int) BaseDatos::unValor('SELECT COUNT(*) FROM constancias');

$cliente = BaseDatos::unaFila(
    'SELECT u.id, u.usuario, u.rol_id, CONCAT(u.nombres," ",u.apellidos) AS nombre,
            c.id AS cuenta_id, c.numero_cuenta, c.saldo_cent
       FROM usuarios u
       JOIN cuentas c ON c.usuario_id = u.id AND c.es_tecnica = 0 AND c.estado = "activa"
      WHERE u.estado = "activo" AND c.saldo_cent > 5000
      ORDER BY c.saldo_cent DESC LIMIT 1'
);

if ($cliente === null) {
    Pruebas::cierto(false, 'hay un cliente con saldo para probar');
    return;
}

try {
    BaseDatos::enTransaccion(static function () use ($cliente): void {
        $huella = new ReflectionMethod(Sesion::class, 'huellaCliente');
        $huella->setAccessible(true);

        $_SESSION['usuario_id']       = (int) $cliente['id'];
        $_SESSION['rol_id']           = (int) $cliente['rol_id'];
        $_SESSION['usuario']          = (string) $cliente['usuario'];
        $_SESSION['creada_en']        = time();
        $_SESSION['ultima_actividad'] = time();
        $_SESSION['huella']           = $huella->invoke(null);

        $servicio = new ServicioConstancias();
        $cuentaId = (int) $cliente['cuenta_id'];
        $saldo    = (int) $cliente['saldo_cent'];

        // -------------------------------------------------------------
        // 1. Constancia de saldo
        // -------------------------------------------------------------
        $antes = (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId]);

        $r = $servicio->emitir([
            'tipo'          => 'saldo',
            'objeto_id'     => $cuentaId,
            'dirigida_a'    => 'Embajada de los Estados Unidos de América',
            'proposito'     => 'trámite de visa',
            'cuenta_cargo'  => $cuentaId,
        ]);

        Pruebas::cierto(
            strlen((string) $r['codigo']) === 12,
            'la constancia sale con un código de verificación de doce caracteres'
        );

        Pruebas::falso(
            (bool) preg_match('/[O0I1]/', (string) $r['codigo']),
            'sin letras ni números que se confundan al dictarlos por teléfono'
        );

        Pruebas::cierto(
            str_contains((string) $r['texto'], (string) $cliente['numero_cuenta']),
            'el texto nombra la cuenta que certifica'
        );

        Pruebas::cierto(
            str_contains((string) $r['texto'], 'Embajada de los Estados Unidos'),
            'y va dirigida a quien la pidió, no «a quien interese»'
        );

        Pruebas::cierto(
            str_contains((string) $r['texto'], 'trámite de visa'),
            'con el propósito para el que se extiende'
        );

        // --- El arancel se cobró, con su asiento --------------------
        Pruebas::cierto(
            $antes - (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$cuentaId])
                === (int) $r['comision_cent'],
            'el arancel se cobra de la cuenta del cliente'
        );

        Pruebas::cierto(
            $r['asiento_id'] !== null,
            'y queda asentado en el libro mayor, no como un número suelto'
        );

        // -------------------------------------------------------------
        // 2. Los datos se congelan
        // -------------------------------------------------------------
        $saldoCertificado = (int) $r['datos']['saldo_cent'];

        /* Se mueve el saldo DESPUÉS de emitir. La constancia tiene que
           seguir diciendo lo que decía. */
        BaseDatos::ejecutar(
            'UPDATE cuentas SET saldo_cent = saldo_cent + 50000 WHERE id = ?',
            [$cuentaId]
        );

        $v = $servicio->verificar((string) $r['codigo']);

        Pruebas::cierto(
            $v !== null && $v['texto'] === $r['texto'],
            'al verificarla dice EXACTAMENTE lo mismo que el papel'
        );

        Pruebas::cierto(
            str_contains((string) $v['texto'], number_format($saldoCertificado / 100, 2)),
            'con el saldo del día en que se emitió, aunque después haya cambiado'
        );

        // -------------------------------------------------------------
        // 3. La verificación no es un buscador de clientes
        // -------------------------------------------------------------
        Pruebas::cierto(
            $servicio->verificar('CODIGOFALSO1') === null,
            'un código inventado no devuelve nada'
        );

        Pruebas::cierto(
            $servicio->verificar('ABC') === null,
            'y uno corto tampoco: hace falta el código exacto'
        );

        Pruebas::cierto(
            !str_contains(json_encode($v, JSON_UNESCAPED_UNICODE) ?: '', 'saldo_cent'),
            'la verificación no expone los datos internos, solo el texto del documento'
        );

        Pruebas::cierto(
            str_contains((string) $v['documento'], '•'),
            'y el documento del cliente va enmascarado'
        );

        Pruebas::cierto(
            (int) $v['veces_verificada'] === 1,
            'se cuenta cuántas veces se ha verificado'
        );

        // -------------------------------------------------------------
        // 4. Las otras constancias
        // -------------------------------------------------------------
        $ref = $servicio->emitir([
            'tipo'         => 'referencia_bancaria',
            'cuenta_cargo' => $cuentaId,
        ]);

        Pruebas::cierto(
            str_contains((string) $ref['texto'], 'es cliente de esta institución desde'),
            'la referencia bancaria dice desde cuándo es cliente'
        );

        Pruebas::cierto(
            str_contains((string) $ref['texto'], 'atraso')
            || str_contains((string) $ref['texto'], 'puntual'),
            'y cómo se ha comportado pagando, que es lo que de verdad se pregunta'
        );

        $na = $servicio->emitir(['tipo' => 'no_adeudo']);

        Pruebas::cierto(
            (int) $na['comision_cent'] === 0,
            'la de no adeudo no cobra arancel'
        );

        Pruebas::cierto(
            str_contains((string) $na['texto'], 'NO REGISTRA')
            || str_contains((string) $na['texto'], 'registra obligaciones'),
            'y dice con claridad si debe o no debe'
        );

        // -------------------------------------------------------------
        // 5. La de ingresos NO es de autoservicio
        // -------------------------------------------------------------
        $bloqueada = false;

        try {
            $servicio->emitir(['tipo' => 'ingresos', 'cuenta_cargo' => $cuentaId]);
        } catch (ConstanciaInvalida $e) {
            $bloqueada = str_contains($e->getMessage(), 'oficial');
        }

        Pruebas::cierto(
            $bloqueada,
            'la constancia de ingresos no se emite sola: la revisa un oficial'
        );

        // -------------------------------------------------------------
        // 6. Anular
        // -------------------------------------------------------------
        /* Anular exige permiso, que un cliente no tiene. Se comprueba
           el camino de datos poniéndola a mano, que es lo que esta
           prueba sí puede verificar. */
        BaseDatos::ejecutar(
            'UPDATE constancias SET estado = "anulada",
                    motivo_anulacion = "Se emitió con el propósito equivocado."
              WHERE folio = ?',
            [$r['folio']]
        );

        $v2 = $servicio->verificar((string) $r['codigo']);

        Pruebas::cierto(
            $v2 !== null && (bool) $v2['anulada'] === true,
            'una constancia anulada lo dice al verificarla, en vez de desaparecer'
        );

        Pruebas::cierto(
            str_contains((string) $v2['motivo_anulacion'], 'propósito equivocado'),
            'y dice por qué se anuló: quien la tiene en la mano merece saberlo'
        );

        // -------------------------------------------------------------
        // 7. La partida doble
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

/* Se compara contra lo que HABÍA, no contra cero: el sistema tiene
   constancias de verdad emitidas por clientes, y exigir una tabla
   vacía haría fallar esta prueba por existir datos legítimos. */
Pruebas::cierto(
    (int) BaseDatos::unValor('SELECT COUNT(*) FROM constancias') === $constanciasAntes,
    'la prueba no deja constancias emitidas'
);
