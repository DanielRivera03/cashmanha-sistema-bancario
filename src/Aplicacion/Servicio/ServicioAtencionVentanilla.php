<?php

declare(strict_types=1);

namespace CashMan\Aplicacion\Servicio;

use CashMan\Dominio\Comun\Monto;
use CashMan\Infraestructura\Persistencia\BaseDatos;
use CashMan\Infraestructura\Seguridad\Hashid;

/**
 * Lo que un cajero necesita ver de un cliente que llega a la ventanilla.
 *
 * El módulo de caja estaba organizado por OPERACIÓN: una pantalla para
 * depositar, otra para retirar, otra para abrir cuenta. Cada una pedía
 * el número de cuenta otra vez. Eso describe lo que hace el sistema,
 * no lo que pasa en una ventanilla.
 *
 * Lo que pasa en una ventanilla es que llega una persona. Trae su DUI,
 * o una libreta, o una tarjeta, o solo su nombre, y dice algo como
 * «vengo a abonar a mi crédito» o «quiero ver cuánto debo». El cajero
 * necesita identificarla con lo que sea que traiga y verla ENTERA:
 * cuentas, tarjetas, créditos, lo que debe hoy y lo que le impide
 * operar. Después actúa, sin volver a teclear nada.
 *
 * Esta clase arma esa ficha. No ejecuta operaciones —de eso siguen
 * encargándose los servicios de siempre— pero reúne en una sola
 * consulta lo que el cajero tendría que ir a buscar a cinco pantallas.
 */
final class ServicioAtencionVentanilla
{
    /**
     * Quién es atendible en una ventanilla.
     *
     * NO ES EL ROL. El rol dice lo que alguien puede HACER dentro del
     * sistema; ser cliente del banco es otra cosa: es tener dinero o
     * deuda aquí.
     *
     * Filtrar por `rol = cliente` dejaba fuera al empleado que tiene su
     * cuenta en su propio banco —en El Salvador es lo normal, la
     * planilla se paga así— y el cajero no podía encontrarlo ni para
     * recibirle un depósito. Que el titular sea además administrador
     * del sistema no cambia que ese dinero es suyo y que viene a la
     * ventanilla como cualquiera.
     *
     * Lo que sí importa es que tenga un PRODUCTO: una cuenta no
     * técnica, una tarjeta o un crédito. Las cuentas técnicas no
     * cuentan —son del banco, no de nadie—, y así los empleados que no
     * son clientes siguen sin aparecer: la ventanilla no se convierte
     * en un directorio del personal.
     */
    private const ES_CLIENTE = '(
           EXISTS (SELECT 1 FROM cuentas  cx WHERE cx.usuario_id = u.id AND cx.es_tecnica = 0)
        OR EXISTS (SELECT 1 FROM tarjetas tx WHERE tx.usuario_id = u.id)
        OR EXISTS (SELECT 1 FROM creditos kx WHERE kx.usuario_id = u.id)
    )';

    /**
     * Busca a quien llegó, con lo que traiga.
     *
     * Acepta DUI, número de cuenta, folio de crédito, últimos cuatro
     * de una tarjeta, usuario o nombre. No se le pide al cajero que
     * diga QUÉ está escribiendo: en una ventanilla con gente
     * esperando, elegir el tipo de búsqueda en un desplegable antes de
     * teclear es una pulsación que no hace falta.
     *
     * El folio del crédito importa más de lo que parece: quien llega a
     * abonar a su préstamo trae el recibo o el plan de pagos en la
     * mano, y ahí lo que está impreso es el folio —no su DUI ni el
     * número de una cuenta que a lo mejor ni tiene.
     *
     * @return list<array<string,mixed>> Coincidencias, con lo justo
     *         para distinguirlas. La ficha completa va aparte.
     */
    public function buscar(string $texto): array
    {
        $texto = trim($texto);

        if (mb_strlen($texto) < 3) {
            return [];
        }

        $soloDigitos = preg_replace('/\D/', '', $texto) ?? '';
        $como        = '%' . $texto . '%';

        /* El folio de un crédito —«CR-2026-6A46BEB»— no es un número,
           así que `$soloDigitos` lo destroza. Se normaliza aparte:
           mayúsculas y sin guiones, porque el cajero lo copia de un
           recibo, de una pantalla o de lo que el cliente le dicta, y
           cada sitio lo escribe de una manera. */
        $folio      = mb_strtoupper(trim($texto));
        $folioPlano = str_replace(['-', ' ', '.'], '', $folio);

        /* Un UNION y no cinco consultas: el cajero escribe una vez y
           espera una vez. El orden pone primero lo exacto —un DUI o un
           número de cuenta completo identifican a una persona— y
           después lo parecido. */
        $filas = BaseDatos::todas(
            'SELECT DISTINCT u.id, u.usuario, u.documento_identidad,
                    CONCAT(u.nombres," ",u.apellidos) AS nombre,
                    u.estado, u.segmento,
                    CASE
                      WHEN u.documento_identidad = ? THEN 1
                      WHEN EXISTS (SELECT 1 FROM cuentas c2
                                    WHERE c2.usuario_id = u.id
                                      AND c2.numero_cuenta = ?) THEN 2
                      WHEN EXISTS (SELECT 1 FROM creditos k2
                                    WHERE k2.usuario_id = u.id
                                      AND UPPER(k2.folio) = ?) THEN 2
                      WHEN u.usuario = ? THEN 3
                      ELSE 4
                    END AS prioridad
               FROM usuarios u
          LEFT JOIN cuentas  c ON c.usuario_id = u.id AND c.es_tecnica = 0
          LEFT JOIN tarjetas t ON t.usuario_id = u.id
              WHERE ' . self::ES_CLIENTE . '
                AND (
                      u.documento_identidad = ?
                   OR REPLACE(u.documento_identidad, "-", "") = ?
                   OR c.numero_cuenta = ?
                   OR t.ultimos4 = ?
                   /* El crédito, por su folio.
                      Va por EXISTS y no por otro LEFT JOIN: con tres
                      uniones sueltas, un cliente con dos cuentas, tres
                      tarjetas y dos créditos produce doce filas iguales
                      y se come el LIMIT de doce resultados él solo.

                      Se acepta entero, sin guiones, y también solo la
                      parte final —que es lo que la gente dicta cuando
                      ya dijo el año—, pero esto último solo a partir de
                      seis caracteres: con menos, media cartera
                      coincide. */
                   OR EXISTS (
                        SELECT 1 FROM creditos k
                         WHERE k.usuario_id = u.id
                           AND (
                                 UPPER(k.folio) = ?
                              OR REPLACE(REPLACE(UPPER(k.folio), "-", ""), " ", "") = ?
                              OR (CHAR_LENGTH(?) >= 6
                                  AND REPLACE(REPLACE(UPPER(k.folio), "-", ""), " ", "")
                                      LIKE CONCAT("%", ?))
                           )
                      )
                   OR u.usuario LIKE ?
                   OR CONCAT(u.nombres," ",u.apellidos) LIKE ?
                )
              ORDER BY prioridad, nombre
              LIMIT 12',
            [
                // el CASE de prioridad
                $texto, $soloDigitos, $folio, $texto,
                // el WHERE
                $texto, $soloDigitos, $soloDigitos,
                $soloDigitos !== '' ? substr($soloDigitos, -4) : '----',
                $folio, $folioPlano, $folioPlano, $folioPlano,
                $como, $como,
            ]
        );

        $out = [];

        foreach ($filas as $f) {
            $out[] = [
                'codigo'    => Hashid::codificar((int) $f['id'], 'usuario'),
                'nombre'    => (string) $f['nombre'],
                'usuario'   => (string) $f['usuario'],
                'documento' => (string) ($f['documento_identidad'] ?? ''),
                'estado'    => (string) $f['estado'],
                'segmento'  => (string) $f['segmento'],
            ];
        }

        return $out;
    }

    /**
     * La ficha completa del cliente.
     *
     * Todo lo que el cajero puede necesitar en el mostrador, en el
     * orden en que lo necesita: primero lo que le impide operar, luego
     * su dinero, luego lo que debe.
     *
     * @return array<string,mixed>|null
     */
    public function ficha(int $usuarioId): ?array
    {
        $u = BaseDatos::unaFila(
            'SELECT u.id, u.usuario, u.correo, u.telefono, u.documento_identidad,
                    CONCAT(u.nombres," ",u.apellidos) AS nombre,
                    u.estado, u.segmento, u.creado_en, u.foto_perfil
               FROM usuarios u
              WHERE u.id = ? AND ' . self::ES_CLIENTE,
            [$usuarioId]
        );

        if ($u === null) {
            return null;
        }

        $cuentas = BaseDatos::todas(
            'SELECT c.id, c.numero_cuenta, c.saldo_cent, c.saldo_retenido_cent,
                    c.estado, c.moneda, p.nombre AS producto, p.saldo_minimo_cent
               FROM cuentas c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? AND c.es_tecnica = 0
              ORDER BY c.id',
            [$usuarioId]
        );

        foreach ($cuentas as &$c) {
            $c['codigo']      = Hashid::codificar((int) $c['id'], 'cuenta');
            $c['disponible']  = max(0, (int) $c['saldo_cent']
                                      - (int) $c['saldo_retenido_cent']
                                      - (int) $c['saldo_minimo_cent']);
            $c['operable']    = $c['estado'] === 'activa' && $u['estado'] === 'activo';
            unset($c['id']);
        }
        unset($c);

        $tarjetas = BaseDatos::todas(
            'SELECT t.id, t.ultimos4, t.marca, t.tipo, t.estado,
                    t.limite_cent, t.saldo_usado_cent, p.nombre AS producto
               FROM tarjetas t
          LEFT JOIN productos p ON p.id = t.producto_id
              WHERE t.usuario_id = ? AND t.estado <> "cancelada"
              ORDER BY t.id',
            [$usuarioId]
        );

        foreach ($tarjetas as &$t) {
            $t['codigo'] = Hashid::codificar((int) $t['id'], 'tarjeta');
            unset($t['id']);
        }
        unset($t);

        /* Los créditos, con lo que de verdad le preguntan en ventanilla:
           cuánto debe HOY. No el saldo total, que no es lo que viene a
           pagar, sino la cuota próxima y lo vencido. */
        /* Los créditos que TODAVÍA DEBEN, no solo los vigentes.
         *
         * Filtrar por `estado = "vigente"` escondía los castigados. Un
         * crédito castigado es el que el banco dio por perdido en su
         * contabilidad, pero la deuda sigue existiendo y es justo la
         * que más interesa cobrar.
         *
         * El resultado era absurdo en la ventanilla: el cajero buscaba
         * por el folio que el cliente traía en la mano, encontraba a la
         * persona, y en su ficha no aparecía ese crédito por ningún
         * lado. Quien llega a pagar una deuda castigada no se puede ir
         * sin pagarla porque la pantalla no la enseñe.
         *
         * Los cancelados y los rechazados sí se quedan fuera: ahí no
         * hay nada que cobrar. */
        $creditos = BaseDatos::todas(
            'SELECT c.id, c.folio, c.estado, c.saldo_capital_cent, c.cuota_cent, c.tasa_bp,
                    p.nombre AS producto,
                    (SELECT COUNT(*) FROM cuotas q
                      WHERE q.credito_id = c.id AND q.estado = "vencida") AS vencidas,
                    (SELECT COALESCE(SUM(q.total_cent - q.pagado_cent),0) FROM cuotas q
                      WHERE q.credito_id = c.id
                        AND q.estado IN ("vencida","parcial")) AS vencido_cent,
                    (SELECT MIN(q.fecha_vencimiento) FROM cuotas q
                      WHERE q.credito_id = c.id
                        AND q.estado NOT IN ("pagada","condonada")) AS proxima
               FROM creditos c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ?
                AND c.estado IN ("desembolsado", "vigente", "castigado")
              ORDER BY FIELD(c.estado, "castigado") DESC, c.id',
            [$usuarioId]
        );

        foreach ($creditos as &$cr) {
            $cr['codigo'] = Hashid::codificar((int) $cr['id'], 'credito');
            unset($cr['id']);
        }
        unset($cr);

        /* Lo que le impide operar, junto y arriba. Un cajero que
           descubre a mitad de una operación que el cliente está
           bloqueado ya le hizo contar el dinero. */
        $avisos = [];

        if ($u['estado'] !== 'activo') {
            $avisos[] = [
                'nivel' => 'alto',
                'texto' => 'El titular está en estado «' . $u['estado']
                         . '»: no puede operar hasta regularizarlo.',
            ];
        }

        foreach ($cuentas as $c) {
            if ($c['estado'] !== 'activa') {
                $avisos[] = [
                    'nivel' => 'alto',
                    'texto' => 'La cuenta ' . $c['numero_cuenta']
                             . ' está ' . $c['estado'] . '.',
                ];
            }
        }

        $vencidoTotal = 0;

        foreach ($creditos as $cr) {
            $vencidoTotal += (int) $cr['vencido_cent'];
        }

        if ($vencidoTotal > 0) {
            $avisos[] = [
                'nivel' => 'medio',
                'texto' => 'Tiene ' . Monto::deCentavos($vencidoTotal)->formateado()
                         . ' en cuotas vencidas. Ofrézcale ponerse al día.',
            ];
        }

        foreach ($tarjetas as $t) {
            if ($t['estado'] === 'bloqueada') {
                $avisos[] = [
                    'nivel' => 'medio',
                    'texto' => 'Su tarjeta ····' . $t['ultimos4'] . ' está bloqueada.',
                ];
            }
        }

        $seg = (new ServicioSegmento())->cacheado($usuarioId);

        return [
            'codigo'    => Hashid::codificar($usuarioId, 'usuario'),
            'nombre'    => (string) $u['nombre'],
            'usuario'   => (string) $u['usuario'],
            'documento' => (string) ($u['documento_identidad'] ?? ''),
            'telefono'  => (string) ($u['telefono'] ?? ''),
            'correo'    => (string) $u['correo'],
            'estado'    => (string) $u['estado'],
            'segmento'  => $seg,
            'cliente_desde' => (string) $u['creado_en'],
            'cuentas'   => $cuentas,
            'tarjetas'  => $tarjetas,
            'creditos'  => $creditos,
            'avisos'    => $avisos,
            'totales'   => [
                'depositos_cent' => array_sum(array_column($cuentas, 'saldo_cent')),
                'vencido_cent'   => $vencidoTotal,
            ],
        ];
    }
}
