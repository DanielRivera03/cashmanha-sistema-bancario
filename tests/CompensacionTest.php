<?php

declare(strict_types=1);

use CashMan\Aplicacion\Servicio\ServicioCompensacion;
use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Parametros;
use CashMan\Infraestructura\Persistencia\BaseDatos;

/**
 * La compensación automática.
 *
 * Es la pieza que toma dinero de la cuenta de alguien sin que lo pida.
 * Está pactada en el contrato y le conviene al deudor —el recargo deja
 * de correr— pero precisamente por eso tiene que ser imposible que se
 * pase de la raya. Lo que se prueba aquí es cada una de las rayas:
 *
 *   - el mínimo intocable, que nunca se toca;
 *   - el porcentaje pactado, que no se excede;
 *   - la autorización, sin la cual no se mueve nada;
 *   - el orden por antigüedad, porque la cuota vieja es la que más
 *     recargo acumula;
 *   - que un abono parcial BAJE la deuda en vez de evaporarse;
 *   - que el saldo nunca quede negativo;
 *   - y que pasarlo dos veces no cobre dos veces.
 *
 * Todo corre dentro de una transacción que se revierte al terminar, de
 * modo que la base queda exactamente como estaba. Se crean un usuario,
 * una cuenta y un crédito propios: tocar los de verdad para probar es
 * la forma más rápida de romperle la cuenta a alguien.
 */

Pruebas::grupo('Compensación · sobre un escenario propio');

/**
 * Monta un caso y lo deshace al salir.
 *
 * @param callable(array<string,mixed>):void $queHacer
 */
function compensacionEnEscenario(array $opciones, callable $queHacer): void
{
    /* La excepción es la forma de revertir: `enTransaccion` confirma
       si la función termina bien, así que para deshacer hay que salir
       por la puerta de las excepciones. */
    $revertir = new class extends \RuntimeException {};

    try {
        BaseDatos::enTransaccion(function () use ($opciones, $queHacer, $revertir): void {
            $rol = (int) BaseDatos::unValor('SELECT id FROM roles WHERE nombre = "Cliente" LIMIT 1');

            $sufijo = substr(bin2hex(random_bytes(5)), 0, 9);

            BaseDatos::ejecutar(
                'INSERT INTO usuarios
                   (uuid, usuario, correo, nombres, apellidos, password_hash,
                    password_algoritmo, password_actualizado_en, requiere_cambio_password,
                    rol_id, estado, perfil_completo, segmento, creado_en, actualizado_en)
                 VALUES (UUID(),?,?,"Prueba","Compensacion","x","argon2id",NOW(),0,
                         ?,"activo",0,"estandar",NOW(),NOW())',
                ['pr.comp.' . $sufijo, 'pr.comp.' . $sufijo . '@example.test', $rol]
            );

            $usuarioId = BaseDatos::ultimoId();

            $producto = (int) BaseDatos::unValor(
                'SELECT id FROM productos WHERE tipo = "ahorro" ORDER BY id LIMIT 1'
            );

            BaseDatos::ejecutar(
                'INSERT INTO cuentas
                   (uuid, numero_cuenta, usuario_id, producto_id, moneda, saldo_cent,
                    estado, origen, apertura_en, compensacion_autorizada,
                    compensacion_bp, compensacion_minimo_cent)
                 VALUES (UUID(),?,?,?,"USD",?,"activa","en_linea",NOW(),?,?,?)',
                [
                    '9' . $sufijo,
                    $usuarioId,
                    $producto,
                    $opciones['saldo'],
                    $opciones['autorizada'] ?? 1,
                    $opciones['bp'] ?? 10000,
                    $opciones['minimo'] ?? 0,
                ]
            );

            $cuentaId = BaseDatos::ultimoId();

            /* El crédito y sus cuotas vencidas.
               Los productos de crédito viven en `productos` con
               `tipo = "credito"`; no hay tabla aparte. */
            $productoCr = (int) BaseDatos::unValor(
                'SELECT id FROM productos WHERE tipo = "credito" ORDER BY id LIMIT 1'
            );

            BaseDatos::ejecutar(
                'INSERT INTO creditos
                   (uuid, folio, usuario_id, producto_id, monto_cent, plazo_meses,
                    tasa_bp, cuota_cent, saldo_capital_cent, estado, creado_en)
                 VALUES (UUID(),?,?,?,?,12,1200,?,?,"vigente",NOW())',
                [
                    'PR-' . $sufijo,
                    $usuarioId,
                    $productoCr,
                    1000000,
                    100000,
                    1000000,
                ]
            );

            $creditoId = BaseDatos::ultimoId();

            foreach ($opciones['cuotas'] as $i => $c) {
                BaseDatos::ejecutar(
                    'INSERT INTO cuotas
                       (credito_id, numero, capital_cent, interes_cent,
                        seguro_vida_cent, seguro_empleo_cent, mora_cent,
                        total_cent, saldo_posterior_cent, pagado_cent,
                        fecha_vencimiento, estado, dias_mora)
                     VALUES (?,?,?,?,0,0,0,?,0,?,DATE_SUB(CURDATE(), INTERVAL ? DAY),
                             "vencida",?)',
                    [
                        $creditoId,
                        $i + 1,
                        $c['total'],
                        0,
                        $c['total'],
                        $c['pagado'] ?? 0,
                        $c['dias'],
                        $c['dias'],
                    ]
                );
            }

            $queHacer([
                'usuario_id' => $usuarioId,
                'cuenta_id'  => $cuentaId,
                'credito_id' => $creditoId,
            ]);

            throw $revertir;
        });
    } catch (\RuntimeException $e) {
        if ($e !== $revertir) { throw $e; }
    }
}

/* ------------------------------------------------------------------
   El mínimo intocable
   ------------------------------------------------------------------ */

compensacionEnEscenario(
    ['saldo' => 2500, 'minimo' => 100, 'cuotas' => [['total' => 50000, 'dias' => 30]]],
    static function (array $e): void {
        (new ServicioCompensacion())->aplicar($e['cuenta_id'], 'manual');

        $saldo = (int) BaseDatos::unValor(
            'SELECT saldo_cent FROM cuentas WHERE id = ?', [$e['cuenta_id']]
        );

        Pruebas::igual(100, $saldo, 'deja intacto el mínimo pactado y no un centavo menos');
    }
);

compensacionEnEscenario(
    ['saldo' => 2500, 'minimo' => 2500, 'cuotas' => [['total' => 50000, 'dias' => 30]]],
    static function (array $e): void {
        $r = (new ServicioCompensacion())->aplicar($e['cuenta_id'], 'manual');

        Pruebas::igual(
            0,
            (int) $r['aplicado_cent'],
            'con el mínimo igual al saldo no toma nada'
        );
    }
);

/* ------------------------------------------------------------------
   El porcentaje pactado
   ------------------------------------------------------------------ */

compensacionEnEscenario(
    ['saldo' => 10000, 'minimo' => 0, 'bp' => 5000,
     'cuotas' => [['total' => 50000, 'dias' => 30]]],
    static function (array $e): void {
        $r = (new ServicioCompensacion())->aplicar($e['cuenta_id'], 'manual');

        /* Saldo 100.00, menos el dólar de mínimo del producto, da
           99.00 disponible; la mitad son 49.50. El mínimo del
           producto es un suelo: pactar cero no lo baja. */
        Pruebas::igual(
            4950,
            (int) $r['aplicado_cent'],
            'con el 50 % pactado toma la mitad de lo disponible, no el total'
        );
    }
);

/* ------------------------------------------------------------------
   Sin autorización no se toca nada
   ------------------------------------------------------------------ */

compensacionEnEscenario(
    ['saldo' => 10000, 'minimo' => 0, 'autorizada' => 0,
     'cuotas' => [['total' => 50000, 'dias' => 30]]],
    static function (array $e): void {
        $r = (new ServicioCompensacion())->aplicar($e['cuenta_id'], 'manual');

        Pruebas::igual(0, (int) $r['aplicado_cent'], 'sin autorización no toma nada');

        Pruebas::igual(
            10000,
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$e['cuenta_id']]),
            'y el saldo queda igual');
    }
);

/* ------------------------------------------------------------------
   Primero la cuota más vieja
   ------------------------------------------------------------------ */

compensacionEnEscenario(
    ['saldo' => 100000, 'minimo' => 0,
     'cuotas' => [
         ['total' => 10000, 'dias' => 10],    // la nueva
         ['total' => 10000, 'dias' => 200],   // la vieja
     ]],
    static function (array $e): void {
        (new ServicioCompensacion())->aplicar($e['cuenta_id'], 'manual');

        $vieja = BaseDatos::unaFila(
            'SELECT pagado_cent, estado FROM cuotas
              WHERE credito_id = ? ORDER BY fecha_vencimiento LIMIT 1',
            [$e['credito_id']]
        );

        Pruebas::cierto(
            (int) $vieja['pagado_cent'] > 0,
            'empieza por la cuota más antigua, que es la que más recargo acumula'
        );
    }
);

/* ------------------------------------------------------------------
   El abono parcial BAJA la deuda
   ------------------------------------------------------------------ */

compensacionEnEscenario(
    ['saldo' => 2400, 'minimo' => 0,
     'cuotas' => [['total' => 2000000, 'dias' => 400]]],   // mora enorme
    static function (array $e): void {
        $r = (new ServicioCompensacion())->aplicar($e['cuenta_id'], 'manual');

        $q = BaseDatos::unaFila(
            'SELECT pagado_cent, estado FROM cuotas WHERE credito_id = ?',
            [$e['credito_id']]
        );

        Pruebas::igual(
            2300,
            (int) $r['aplicado_cent'],
            'toma lo que hay —salvo el mínimo— cuando no alcanza para la cuota entera'
        );

        /* Lo que importa: el abono tiene que verse en la deuda. Con la
           mora por delante se iba entero en recargo y la cuota quedaba
           «parcial» con su pagado en cero, que es decir dos cosas que
           se contradicen. */
        Pruebas::igual(
            2300,
            (int) $q['pagado_cent'],
            'el abono parcial baja la deuda en vez de evaporarse en recargo'
        );

        Pruebas::igual('parcial', (string) $q['estado'], 'y la cuota queda parcial');
    }
);

/* ------------------------------------------------------------------
   El saldo nunca queda negativo
   ------------------------------------------------------------------ */

compensacionEnEscenario(
    ['saldo' => 500, 'minimo' => 0,
     'cuotas' => [
         ['total' => 100000, 'dias' => 90],
         ['total' => 100000, 'dias' => 60],
         ['total' => 100000, 'dias' => 30],
     ]],
    static function (array $e): void {
        (new ServicioCompensacion())->aplicar($e['cuenta_id'], 'manual');

        $saldo = (int) BaseDatos::unValor(
            'SELECT saldo_cent FROM cuentas WHERE id = ?', [$e['cuenta_id']]
        );

        Pruebas::cierto(
            $saldo >= 0,
            'con tres cuotas vencidas y poco saldo, la cuenta no queda en descubierto'
        );
    }
);

/* ------------------------------------------------------------------
   Pasarlo dos veces no cobra dos veces
   ------------------------------------------------------------------ */

compensacionEnEscenario(
    ['saldo' => 10000, 'minimo' => 0,
     'cuotas' => [['total' => 500000, 'dias' => 45]]],
    static function (array $e): void {
        $svc = new ServicioCompensacion();

        $uno = $svc->aplicar($e['cuenta_id'], 'manual');
        $dos = $svc->aplicar($e['cuenta_id'], 'manual');

        Pruebas::igual(9900, (int) $uno['aplicado_cent'], 'la primera pasada aplica');
        Pruebas::igual(0, (int) $dos['aplicado_cent'], 'la segunda ya no encuentra nada que tomar');

        Pruebas::igual(
            100,
            (int) BaseDatos::unValor('SELECT saldo_cent FROM cuentas WHERE id = ?', [$e['cuenta_id']]),
            'y el saldo queda en el mínimo del producto, no bajó dos veces'
        );
    }
);

/* ------------------------------------------------------------------
   Nada que deber, nada que tomar
   ------------------------------------------------------------------ */

compensacionEnEscenario(
    ['saldo' => 50000, 'minimo' => 0, 'cuotas' => []],
    static function (array $e): void {
        $r = (new ServicioCompensacion())->aplicar($e['cuenta_id'], 'manual');

        Pruebas::igual(0, (int) $r['aplicado_cent'], 'sin cuotas vencidas no toma nada');
    }
);

Pruebas::grupo('Compensación · el mínimo del producto es un suelo');

/* Lo descubrió la prueba de arriba: aunque la cuenta pacte cero de
   mínimo intocable, el producto impone el suyo y manda el mayor de
   los dos. Conviene que quede escrito como regla y no como sorpresa. */
compensacionEnEscenario(
    ['saldo' => 10000, 'minimo' => 0,
     'cuotas' => [['total' => 500000, 'dias' => 45]]],
    static function (array $e): void {
        (new ServicioCompensacion())->aplicar($e['cuenta_id'], 'manual');

        $saldo = (int) BaseDatos::unValor(
            'SELECT saldo_cent FROM cuentas WHERE id = ?', [$e['cuenta_id']]
        );

        $delProducto = (int) BaseDatos::unValor(
            'SELECT COALESCE(p.saldo_minimo_cent, 0)
               FROM cuentas c JOIN productos p ON p.id = c.producto_id
              WHERE c.id = ?',
            [$e['cuenta_id']]
        );

        Pruebas::igual(
            $delProducto,
            $saldo,
            'pactar cero no baja el mínimo del producto: manda el mayor de los dos'
        );
    }
);

Pruebas::grupo('Compensación · el origen se valida');

Pruebas::lanza(
    InvalidArgumentException::class,
    static fn() => (new ServicioCompensacion())->aplicar(1, 'inventado'),
    'un origen que el ENUM no admite se rechaza al entrar, no al insertar'
);
