<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Pasarela;

use CashMan\Infraestructura\Config;

/**
 * Cliente de la API de Stripe.
 *
 * Escrito contra la API HTTP directamente, sin la librería oficial, por
 * la misma razón que el resto de este sistema no arrastra dependencias:
 * son tres llamadas y una verificación de firma, y a cambio no hay que
 * confiar en un árbol de paquetes para mover dinero.
 *
 * Solo cubre lo que hace falta aquí: crear un intento de cobro,
 * consultarlo y validar los avisos del webhook.
 *
 * Nota sobre los importes: Stripe trabaja en la unidad mínima de la
 * moneda, igual que este sistema. Para USD eso son centavos, así que
 * los enteros pasan tal cual sin convertir nada — que es justo lo que
 * evita los errores de redondeo.
 */
final class Stripe
{
    private const BASE = 'https://api.stripe.com/v1/';

    /** Stripe fija la versión por cuenta; declararla evita sorpresas. */
    private const VERSION_API = '2024-06-20';

    private string $secreta;

    public function __construct(?string $secreta = null)
    {
        $this->secreta = $secreta ?? (string) Config::obtener('STRIPE_CLAVE_SECRETA', '');
    }

    public static function habilitado(): bool
    {
        return Config::obtener('PASARELA', 'simulada') === 'stripe'
            && (string) Config::obtener('STRIPE_CLAVE_SECRETA', '') !== '';
    }

    /** ¿Estamos en el entorno de pruebas? Se sabe por el prefijo. */
    public static function esPrueba(): bool
    {
        return str_starts_with((string) Config::obtener('STRIPE_CLAVE_SECRETA', ''), 'sk_test_');
    }

    public static function clavePublica(): string
    {
        return (string) Config::obtener('STRIPE_CLAVE_PUBLICA', '');
    }

    /**
     * Crea un intento de cobro.
     *
     * @param int    $centavos  Importe en la unidad mínima (centavos).
     * @param string $idempotencia Clave propia: si la petición se repite
     *        —reintento por red, doble clic— Stripe devuelve el mismo
     *        intento en lugar de crear otro y cobrar dos veces.
     * @param array<string,string> $metadatos Se guardan en Stripe y
     *        vuelven en el webhook: así se reconoce a qué pago interno
     *        corresponde sin confiar en el orden de llegada.
     *
     * @return array{id:string, client_secret:string, status:string}
     */
    public function crearIntento(
        int $centavos,
        string $descripcion,
        string $idempotencia,
        array $metadatos = [],
        string $moneda = 'usd'
    ): array {
        if ($centavos < 50) {
            // Stripe rechaza importes menores a 0.50 USD.
            throw new PasarelaError('El importe mínimo que admite la pasarela es $0.50.');
        }

        $datos = [
            'amount'      => $centavos,
            'currency'    => strtolower($moneda),
            'description' => mb_substr($descripcion, 0, 350),
            // Sin métodos automáticos: solo tarjeta, que es lo que el
            // sistema sabe contabilizar.
            'payment_method_types' => ['card'],
        ];

        foreach ($metadatos as $k => $v) {
            $datos['metadata'][$k] = (string) $v;
        }

        $r = $this->peticion('POST', 'payment_intents', $datos, $idempotencia);

        return [
            'id'            => (string) ($r['id'] ?? ''),
            'client_secret' => (string) ($r['client_secret'] ?? ''),
            'status'        => (string) ($r['status'] ?? 'desconocido'),
        ];
    }

    /** Consulta el estado de un intento. */
    public function consultarIntento(string $id): array
    {
        if (preg_match('/^pi_[A-Za-z0-9_]+$/', $id) !== 1) {
            throw new PasarelaError('Identificador de cobro no válido.');
        }

        return $this->peticion('GET', 'payment_intents/' . $id);
    }

    /** Cancela un intento que aún no se cobró. */
    public function cancelarIntento(string $id): array
    {
        if (preg_match('/^pi_[A-Za-z0-9_]+$/', $id) !== 1) {
            throw new PasarelaError('Identificador de cobro no válido.');
        }

        return $this->peticion('POST', 'payment_intents/' . $id . '/cancel');
    }

    /**
     * Comprueba la firma de un webhook y devuelve el evento.
     *
     * Esto es lo que separa un aviso de Stripe de uno que cualquiera
     * puede enviar a la misma URL. Sin verificar, bastaría con hacer un
     * POST diciendo "cobro confirmado" para que el sistema abonara
     * dinero que nadie pagó.
     *
     * El algoritmo es el que publica Stripe: la firma es
     * HMAC-SHA256(timestamp + "." + cuerpo) con el secreto del webhook.
     *
     * @throws PasarelaError si la firma no cuadra o el aviso es viejo
     */
    public function verificarWebhook(string $cuerpo, string $cabeceraFirma): array
    {
        $secreto = (string) Config::obtener('STRIPE_WEBHOOK_SECRETO', '');

        if ($secreto === '') {
            throw new PasarelaError(
                'Falta STRIPE_WEBHOOK_SECRETO: sin él no se puede comprobar '
                . 'que el aviso venga de Stripe, así que se rechaza.'
            );
        }

        // La cabecera llega como: t=1699999999,v1=abc...,v0=def...
        $partes = [];
        foreach (explode(',', $cabeceraFirma) as $trozo) {
            $kv = explode('=', trim($trozo), 2);
            if (count($kv) === 2) {
                $partes[$kv[0]][] = $kv[1];
            }
        }

        $marca = (int) ($partes['t'][0] ?? 0);
        $firmas = $partes['v1'] ?? [];

        if ($marca === 0 || $firmas === []) {
            throw new PasarelaError('La firma del aviso no tiene el formato esperado.');
        }

        // Cinco minutos de tolerancia: suficiente para un reintento
        // legítimo y poco para reutilizar un aviso capturado.
        if (abs(time() - $marca) > 300) {
            throw new PasarelaError(
                'El aviso llegó con ' . abs(time() - $marca) . ' segundos de desfase: se descarta.'
            );
        }

        $esperada = hash_hmac('sha256', $marca . '.' . $cuerpo, $secreto);

        $valida = false;
        foreach ($firmas as $f) {
            // hash_equals y no ==: la comparación tiene que tardar lo
            // mismo acierte o falle.
            if (hash_equals($esperada, $f)) {
                $valida = true;
                break;
            }
        }

        if (!$valida) {
            throw new PasarelaError('La firma del aviso no coincide: no viene de Stripe.');
        }

        $evento = json_decode($cuerpo, true);

        if (!is_array($evento) || !isset($evento['id'], $evento['type'])) {
            throw new PasarelaError('El cuerpo del aviso no es un evento válido.');
        }

        return $evento;
    }

    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    private function peticion(
        string $metodo,
        string $ruta,
        array $datos = [],
        ?string $idempotencia = null
    ): array {
        if ($this->secreta === '') {
            throw new PasarelaError('La pasarela no está configurada: falta la clave secreta.');
        }

        $cabeceras = [
            'Authorization: Bearer ' . $this->secreta,
            'Stripe-Version: ' . self::VERSION_API,
            'Content-Type: application/x-www-form-urlencoded',
        ];

        if ($idempotencia !== null) {
            $cabeceras[] = 'Idempotency-Key: ' . $idempotencia;
        }

        $url = self::BASE . $ruta;
        $cuerpo = $datos === [] ? '' : http_build_query($datos);

        if ($metodo === 'GET' && $cuerpo !== '') {
            $url .= '?' . $cuerpo;
            $cuerpo = '';
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => $cabeceras,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            // El certificado se verifica siempre: esto lleva una clave
            // con la que se mueve dinero.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($cuerpo !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        }

        $respuesta = curl_exec($ch);
        $codigo    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorRed  = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false) {
            throw new PasarelaError('No se pudo contactar con la pasarela: ' . $errorRed);
        }

        $j = json_decode((string) $respuesta, true);

        if (!is_array($j)) {
            throw new PasarelaError('La pasarela respondió algo que no es JSON.');
        }

        if ($codigo >= 400) {
            $e = $j['error'] ?? [];

            // Stripe explica el motivo en su idioma de API; se traslada
            // tal cual porque es lo que hace falta para depurar, pero
            // con un texto propio delante para quien lo lea.
            throw new PasarelaError(
                'La pasarela rechazó la operación: '
                . (string) ($e['message'] ?? 'sin detalle')
                . (isset($e['code']) ? ' [' . $e['code'] . ']' : ''),
                $codigo
            );
        }

        return $j;
    }
}
